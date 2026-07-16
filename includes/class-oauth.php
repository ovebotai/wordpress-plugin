<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_OAuth {

	private static $instance = null;

	const SCOPES = 'workspaces:read setup:widget:write setup:products:write setup:order-info:write kb:write';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Authorization URL ───────────────────────────────────────────────────

	public function get_auth_url( string $callback_url ): string {
		$verifier  = $this->b64url( random_bytes( 48 ) );
		$challenge = $this->b64url( hash( 'sha256', $verifier, true ) );
		$state     = bin2hex( random_bytes( 8 ) );

		// Keyed by the state itself (not a single fixed slot) so clicking
		// "Connect" again — e.g. after abandoning a prior attempt partway
		// through Ovebot's domain/workspace flow and going back — doesn't
		// invalidate an still-in-flight authorization that later completes
		// with the earlier state, causing a false "state mismatch".
		set_transient( 'ovebotai_pkce_verifier_' . $state, $verifier, 600 );

		return 'https://' . OVEBOTAI_ACCOUNT_HOST . '/oauth/authorize?' . http_build_query( array(
			'site_domain'            => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'callback_url'           => $callback_url,
			'scopes'                 => self::SCOPES,
			'code_challenge'         => $challenge,
			'code_challenge_method'  => 'S256',
			'state'                  => $state,
		) );
	}

	// ── Code exchange ────────────────────────────────────────────────────────

	public function exchange_code( string $code, string $state ): array {
		$verifier = get_transient( 'ovebotai_pkce_verifier_' . $state );
		delete_transient( 'ovebotai_pkce_verifier_' . $state );

		if ( ! $verifier ) {
			return array( 'error' => __( 'State mismatch. Please try again.', 'ovebotai' ) );
		}

		$response = wp_remote_post(
			'https://' . OVEBOTAI_ACCOUNT_HOST . '/oauth/token',
			array(
				'body'    => wp_json_encode( array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'code_verifier' => $verifier,
				) ),
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $http_code || empty( $body['access_token'] ) ) {
			return array( 'error' => sprintf( /* translators: %d: HTTP status code */ __( 'Token exchange failed (HTTP %d).', 'ovebotai' ), $http_code ) );
		}

		$this->store_tokens( $body );
		return array( 'success' => true );
	}

	// ── Token refresh (with rotation) ────────────────────────────────────────

	public function refresh(): bool {
		$refresh_token = (string) get_option( 'ovebotai_refresh_token', '' );
		if ( ! $refresh_token ) {
			return false;
		}

		$response = wp_remote_post(
			'https://' . OVEBOTAI_ACCOUNT_HOST . '/oauth/token',
			array(
				'body'    => wp_json_encode( array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				) ),
				'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $http_code || empty( $body['access_token'] ) ) {
			// Refresh failed or token family revoked. This is NOT the same as an
			// explicit Disconnect: the storefront-facing bits (chat widget,
			// purchase tracking) key off ovebotai_workspace/ovebotai_chat_status
			// alone and never touch the OAuth token, so they must keep working
			// unattended — nobody is going to log back in every time a 30-day
			// refresh token lapses. Only the admin-side API access dies here;
			// is_connected() (which checks the refresh token) will correctly
			// report "disconnected" so Settings prompts for reconnect the next
			// time someone actually opens that screen.
			$this->expire_tokens();
			return false;
		}

		$this->store_tokens( $body );
		return true;
	}

	// ── API requests with auto-refresh ───────────────────────────────────────

	public function api_request( string $method, string $path, ?array $body = null ): array {
		// Proactive refresh: if the 1-hour access token is already expired (or
		// about to be, within this same request), refresh before spending a
		// round-trip on a request we already know will 401. A failed refresh
		// here just falls through to do_request() with the stale token, so the
		// normal reactive-401 path below still catches it.
		$expires = (int) get_option( 'ovebotai_token_expires', 0 );
		if ( $expires && $expires <= time() + 60 * 5 ) { // 5 minutes
			$this->refresh();
		}

		$result = $this->do_request( $method, $path, $body );

		// On 401 attempt a token refresh and retry once.
		if ( 401 === ( $result['status'] ?? 0 ) ) {
			if ( $this->refresh() ) {
				$result = $this->do_request( $method, $path, $body );
			}
		}

		return $result;
	}

	private function do_request( string $method, string $path, ?array $body ): array {
		$access_token = (string) get_option( 'ovebotai_access_token', '' );

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( 'https://' . OVEBOTAI_API_HOST . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'status' => 0, 'body' => array( 'error' => $response->get_error_message() ) );
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$parsed_body = json_decode( wp_remote_retrieve_body( $response ), true );

		return array( 'status' => $status, 'body' => is_array( $parsed_body ) ? $parsed_body : array() );
	}

	// ── Token storage ────────────────────────────────────────────────────────

	private function store_tokens( array $resp ): void {
		update_option( 'ovebotai_access_token',  sanitize_text_field( $resp['access_token'] ),  false );
		update_option( 'ovebotai_token_expires',  time() + (int) ( $resp['expires_in'] ?? 3600 ), false );

		// Always store the rotated refresh token.
		if ( ! empty( $resp['refresh_token'] ) ) {
			update_option( 'ovebotai_refresh_token', sanitize_text_field( $resp['refresh_token'] ), false );
		}

		// Strict slug format only — this value gets concatenated straight into
		// script-src hosts ('https://' . $workspace . '.ovebot.ai/...') in
		// class-frontend.php and admin views. sanitize_text_field() alone
		// wouldn't stop e.g. "evil.com/x", which would put "evil.com" in the
		// host position and load an attacker's script on every page. A slug
		// that fails this check is simply not stored, which leaves is_connected()
		// false rather than persisting something we can't trust as a hostname part.
		if ( ! empty( $resp['workspace']['slug'] ) && preg_match( '/^[a-z0-9-]+$/i', $resp['workspace']['slug'] ) ) {
			update_option( 'ovebotai_workspace', $resp['workspace']['slug'], false );
		}
		if ( isset( $resp['agent'] ) ) {
			update_option( 'ovebotai_agent', sanitize_text_field( $resp['agent'] ), false );
		}
	}

	// Best-effort: revokes the token (and its OAuth family) on Ovebot's side.
	// Must not block the caller if it fails — local tokens are cleared
	// separately via clear_tokens() regardless of the outcome here.
	public function disconnect_remote(): void {
		$access_token = (string) get_option( 'ovebotai_access_token', '' );
		if ( ! $access_token ) return;

		wp_remote_post(
			'https://' . OVEBOTAI_API_HOST . '/v1/disconnect',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 10,
			)
		);
	}

	// Explicit Disconnect only — wipes everything, including the bits the
	// storefront (widget, purchase tracking) reads independently of the OAuth
	// token, since the user is deliberately severing the connection.
	public function clear_tokens(): void {
		$this->expire_tokens();
		delete_option( 'ovebotai_workspace' );
		delete_option( 'ovebotai_agent' );
		delete_option( 'ovebotai_setup_complete' );
	}

	// Implicit expiry (refresh token lapsed/revoked) — clears only the OAuth
	// credentials themselves. Leaves ovebotai_workspace/ovebotai_agent/
	// ovebotai_chat_status alone so the chat widget and purchase-event
	// tracking (class-frontend.php) keep working unattended; only admin-side
	// API calls (KB sync, settings save) start failing until reconnect.
	public function expire_tokens(): void {
		delete_option( 'ovebotai_access_token' );
		delete_option( 'ovebotai_refresh_token' );
		delete_option( 'ovebotai_token_expires' );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	public function is_connected(): bool {
		return (bool) get_option( 'ovebotai_refresh_token' ) && '' !== $this->get_workspace();
	}

	// is_connected() only checks whether a refresh token is stored locally —
	// it has no way to know that token was revoked/expired server-side unless
	// something actually calls the API. Views that render the connection
	// badge without otherwise making an API request (e.g. Settings, reached
	// directly via a bookmark) would keep showing "Connected" from stale
	// local state indefinitely. This forces that one lightweight check (which
	// piggybacks on api_request()'s existing proactive-refresh logic) before
	// answering, so a lapsed connection is caught on the very page load that
	// displays it, not just on the next action that happens to hit the API.
	public function is_connected_live(): bool {
		if ( ! $this->is_connected() ) {
			return false;
		}

		$this->api_request( 'GET', '/v1/integration/status' );

		return $this->is_connected();
	}

	// Re-validated on every read (not just at store_tokens() time) so a value
	// written by an older version of this plugin, or edited directly in the
	// database, can never reach the script-src host concatenation unchecked.
	public function get_workspace(): string {
		$workspace = (string) get_option( 'ovebotai_workspace', '' );
		return preg_match( '/^[a-z0-9-]+$/i', $workspace ) ? $workspace : '';
	}

	public function get_agent(): string {
		return (string) get_option( 'ovebotai_agent', 'default' );
	}

	public function setup_api_path(): string {
		return '/v1/workspaces/' . $this->get_workspace() . '/agents/' . $this->get_agent() . '/setup';
	}

	public function kb_api_path(): string {
		return '/v1/workspaces/' . $this->get_workspace() . '/agents/' . $this->get_agent() . '/knowledge-base';
	}

	private function b64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}
}
