<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_OAuth {

	private static $instance = null;

	// Per-request memo for GET /v1/integration/status (task 8). false = not yet
	// fetched this request; after the first call it holds the integration array
	// or null (fetched but failed). A single dashboard load reads it several times
	// (product count, recommendation status, order-API status, the live-connection
	// check) - this collapses all of that into one HTTP round-trip.
	private $integration_cache = false;

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
			return array( 'error' => __( 'State mismatch. Please try again.', 'ovebot-ai-chatbot-sales-agent' ) );
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
			return array( 'error' => sprintf( /* translators: %d: HTTP status code */ __( 'Token exchange failed (HTTP %d).', 'ovebot-ai-chatbot-sales-agent' ), $http_code ) );
		}

		// Capture the previously-stored agent BEFORE store_tokens() gets a chance
		// to write a new one, so resolve_agent_after_connect() can tell a genuine
		// agent switch apart from a reconnect to the same agent. Reading it after
		// the write would compare the new value against itself and never detect a
		// change (item 7).
		$previous_agent = (string) get_option( 'ovebotai_agent', '' );

		$this->store_tokens( $body );

		// The token response's `agent` is unreliable (null both for "default
		// agent picked" and "not reported"), so confirm the actually-connected
		// agent via GET /v1/me and react if it differs from before.
		$this->resolve_agent_after_connect( $previous_agent );

		return array( 'success' => true );
	}

	// ── Resolve the connected agent via GET /v1/me, react to an agent switch ──
	//
	// Best-effort: a failed/unexpected lookup must not fail the whole OAuth flow,
	// so any non-2xx / missing-agent case just returns early, leaving whatever
	// store_tokens() already set in place.
	private function resolve_agent_after_connect( string $previous_agent ): void {
		$result = $this->api_request( 'GET', '/v1/me' );
		$status = $result['status'] ?? 0;
		if ( $status < 200 || $status >= 300 ) {
			return;
		}

		$body = $result['body'] ?? array();
		// No `agent` key at all → couldn't determine it; leave things as-is.
		if ( ! is_array( $body ) || ! array_key_exists( 'agent', $body ) ) {
			return;
		}

		$agent = $body['agent'];
		// Object with a public id → that id; anything else (notably null for the
		// default agent) → '' (never the literal "default"; that's a display-only
		// label produced by get_agent()).
		$resolved = is_array( $agent )
			? sanitize_text_field( (string) ( $agent['public_id'] ?? $agent['id'] ?? '' ) )
			: '';

		update_option( 'ovebotai_agent', $resolved, false );

		// A different agent than last time → the local, per-agent state set up
		// for the previous agent is now stale/wrong (item 7).
		if ( $resolved !== $previous_agent ) {
			$this->reset_agent_local_state();
		}
	}

	// Wipes state that only makes sense for one specific agent, so reconnecting
	// with a different agent starts clean instead of showing the previous
	// agent's configuration.
	private function reset_agent_local_state(): void {
		// Re-run the setup wizard for the new agent rather than silently showing
		// a dashboard pointed at another agent's configuration.
		update_option( 'ovebotai_setup_complete', '0', false );

		// The saved page-selection ("only these pages") belongs to the old agent;
		// clearing it resets the wizard to its "everything selected by default"
		// starting point, same as a first-ever connection.
		delete_option( 'ovebotai_kb_page_ids' );

		// Our local "content page id → remote KB entry id" mapping points into
		// the previous agent's knowledge base; those ids are meaningless under a
		// new agent. sync_kb_pages() self-heals a stale id, but clear it outright
		// so nothing lingers.
		delete_post_meta_by_key( '_ovebotai_kb_id' );
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
		// Align the token-response agent to the same '' = default convention the
		// /v1/me resolution uses, so the two write-paths can never disagree
		// (never persist the literal "default"; see resolve_agent_after_connect()).
		if ( array_key_exists( 'agent', $resp ) ) {
			update_option( 'ovebotai_agent', $this->normalize_agent( $resp['agent'] ), false );
		}
	}

	// Object with a public id → that id; the string "default" or anything else
	// non-object → '' (the default agent has no id of its own).
	private function normalize_agent( $agent ): string {
		if ( is_array( $agent ) ) {
			return sanitize_text_field( (string) ( $agent['public_id'] ?? $agent['id'] ?? '' ) );
		}
		$id = sanitize_text_field( (string) $agent );
		return 'default' === $id ? '' : $id;
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

	// Explicit Disconnect. Only the OAuth credentials themselves are cleared.
	// Per-agent state (workspace, agent id, KB id map, page selection,
	// setup_complete) is deliberately kept intact so that:
	//  - reconnecting to the same agent routes straight back to the already
	//    configured dashboard instead of pointlessly re-running the wizard, and
	//  - the next connect can compare the still-stored agent against the freshly
	//    resolved one to detect a real agent switch (see
	//    resolve_agent_after_connect()). Clearing the agent here would make every
	//    reconnect look like an agent change, since there'd be nothing to compare
	//    against.
	// Only an actual agent switch (detected on the next connect) resets that
	// per-agent state.
	public function clear_tokens(): void {
		$this->expire_tokens();
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

		// Reuses the memoized integration fetch so the badge check and the
		// dashboard's own status/count reads share one request instead of each
		// firing their own GET /v1/integration/status.
		$this->get_integration();

		return $this->is_connected();
	}

	// ── Integration status (memoized) ────────────────────────────────────────
	//
	// GET /v1/integration/status for the token's own workspace/agent. Returns the
	// `integration` object - { products: bool, feed_url: string|null,
	// order_info: bool, widget_language: string, counts: { products, knowledge_base } }
	// - or null when the call fails or isn't 2xx. Cached per request (task 8);
	// pass $force to bypass the memo and re-fetch.
	public function get_integration( bool $force = false ): ?array {
		if ( ! $force && false !== $this->integration_cache ) {
			return $this->integration_cache;
		}

		$result = $this->api_request( 'GET', '/v1/integration/status' );
		$status = $result['status'] ?? 0;

		if ( $status < 200 || $status >= 300 || ! is_array( $result['body']['integration'] ?? null ) ) {
			$this->integration_cache = null;
			return null;
		}

		$this->integration_cache = $result['body']['integration'];
		return $this->integration_cache;
	}

	// Re-validated on every read (not just at store_tokens() time) so a value
	// written by an older version of this plugin, or edited directly in the
	// database, can never reach the script-src host concatenation unchecked.
	public function get_workspace(): string {
		$workspace = (string) get_option( 'ovebotai_workspace', '' );
		return preg_match( '/^[a-z0-9-]+$/i', $workspace ) ? $workspace : '';
	}

	// Display/path form: the default agent (stored as '') surfaces as the literal
	// "default", used both in the API path and the connection badge. The '' →
	// "default" translation happens only here, never in the persisted value.
	public function get_agent(): string {
		$agent = (string) get_option( 'ovebotai_agent', '' );
		return '' === $agent ? 'default' : $agent;
	}

	// Raw stored id: '' for the default agent (which has no id of its own),
	// otherwise the agent's public id. Callers that must distinguish "default"
	// from a real agent (storefront config, badge) read this, not get_agent().
	public function get_agent_id(): string {
		return (string) get_option( 'ovebotai_agent', '' );
	}

	// The account's setup page on Ovebot.ai. Empty string when there's no
	// workspace to build a host from. Always the plain /setup path — never
	// agent-specific — regardless of which agent is connected.
	public function get_agent_settings_url(): string {
		$workspace = $this->get_workspace();
		if ( '' === $workspace ) {
			return '';
		}
		return 'https://' . $workspace . '.ovebot.ai/setup';
	}

	// Sign-up link for the "Start Free" button. `plan` carries the platform's
	// freemium slug — account.ovebot.ai checks it is an open plan and falls
	// back to plain registration (with its own notice) when it isn't, so
	// there is nothing to verify on this side.
	public static function get_register_url(): string {
		return 'https://' . OVEBOTAI_ACCOUNT_HOST . '/register?' . http_build_query( array(
			'plan'   => 'wp-freemium',
			'domain' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		) );
	}

	// Extracts a human-readable message from an api_request() result's error
	// shape — { error: { message, fields: { field: [msgs] } } }, a bare
	// { error: "..." } / { message: "..." }, including field-level validation
	// messages — falling back to "HTTP <status>" when nothing usable is present.
	public static function error_message( array $result ): string {
		$status = (int) ( $result['status'] ?? 0 );
		$body   = $result['body'] ?? array();
		$msg    = '';

		if ( is_array( $body ) ) {
			$error = $body['error'] ?? null;
			if ( is_array( $error ) ) {
				$msg = (string) ( $error['message'] ?? '' );

				// Field-level validation messages, if the API included any.
				$fields = $error['fields'] ?? ( $error['errors'] ?? array() );
				if ( is_array( $fields ) ) {
					$parts = array();
					foreach ( $fields as $field_msgs ) {
						foreach ( (array) $field_msgs as $field_msg ) {
							if ( '' !== (string) $field_msg ) {
								$parts[] = (string) $field_msg;
							}
						}
					}
					if ( $parts ) {
						$msg = trim( $msg . ' ' . implode( ' ', $parts ) );
					}
				}
			} elseif ( is_string( $error ) && '' !== $error ) {
				$msg = $error;
			} elseif ( ! empty( $body['message'] ) ) {
				$msg = (string) $body['message'];
			}
		}

		$msg = trim( $msg );
		if ( '' === $msg ) {
			/* translators: %d: HTTP status code */
			$msg = sprintf( __( 'HTTP %d', 'ovebot-ai-chatbot-sales-agent' ), $status );
		}
		return $msg;
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
