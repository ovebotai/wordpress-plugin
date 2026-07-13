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

		set_transient( 'ovebotai_pkce_verifier', $verifier, 600 );
		set_transient( 'ovebotai_oauth_state',   $state,    600 );

		return 'https://' . OVEBOTAI_ACCOUNT_HOST . '/oauth/authorize?' . http_build_query( array(
			'site_domain'            => (string) parse_url( home_url(), PHP_URL_HOST ),
			'callback_url'           => $callback_url,
			'scopes'                 => self::SCOPES,
			'code_challenge'         => $challenge,
			'code_challenge_method'  => 'S256',
			'state'                  => $state,
		) );
	}

	// ── Code exchange ────────────────────────────────────────────────────────

	public function exchange_code( string $code, string $state ): array {
		$saved_state = get_transient( 'ovebotai_oauth_state' );
		$verifier    = get_transient( 'ovebotai_pkce_verifier' );

		delete_transient( 'ovebotai_oauth_state' );
		delete_transient( 'ovebotai_pkce_verifier' );

		if ( ! $saved_state || ! hash_equals( (string) $saved_state, $state ) ) {
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
			// Refresh failed or token family revoked — require re-authentication.
			$this->clear_tokens();
			return false;
		}

		$this->store_tokens( $body );
		return true;
	}

	// ── API requests with auto-refresh ───────────────────────────────────────

	public function api_request( string $method, string $path, ?array $body = null ): array {
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

		if ( ! empty( $resp['workspace']['slug'] ) ) {
			update_option( 'ovebotai_workspace', sanitize_text_field( $resp['workspace']['slug'] ), false );
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

	public function clear_tokens(): void {
		delete_option( 'ovebotai_access_token' );
		delete_option( 'ovebotai_refresh_token' );
		delete_option( 'ovebotai_token_expires' );
		delete_option( 'ovebotai_workspace' );
		delete_option( 'ovebotai_agent' );
		delete_option( 'ovebotai_setup_complete' );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	public function is_connected(): bool {
		return (bool) get_option( 'ovebotai_refresh_token' ) && (bool) get_option( 'ovebotai_workspace' );
	}

	public function get_workspace(): string {
		return (string) get_option( 'ovebotai_workspace', '' );
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
