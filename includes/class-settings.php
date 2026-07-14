<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_Settings {

	private static $instance = null;

	private static $widget_keys = array(
		'subtitle', 'accent_color', 'proactive_message', 'proactive_delay',
		'theme', 'language', 'width', 'height', 'audio_beep', 'side',
		'offset_x', 'offset_y', 'z_index',
	);

	private static $delivery_keys = array(
		'days_shipped_min', 'days_shipped_max',
		'days_instock_min', 'days_instock_max',
		'days_oos_min',     'days_oos_max',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		add_action( 'wp_ajax_ovebotai_save_settings',    array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_ovebotai_regen_hash',       array( $this, 'ajax_regen_hash' ) );
		add_action( 'wp_ajax_ovebotai_regen_creds',      array( $this, 'ajax_regen_creds' ) );
		add_action( 'wp_ajax_ovebotai_clear_cache',      array( $this, 'ajax_clear_cache' ) );
	}

	// ── Save settings ───────────────────────────────────────────────────────
	//
	// Local-only settings (chat_status + widget appearance) are always saved.
	// Settings that require an API sync (feed, order_info, delivery days) are
	// saved locally and synced only when the OAuth connection is valid.
	// If the token is expired the handler attempts a refresh first; if that
	// also fails it saves locally and returns a partial-success response so
	// the UI can inform the user which sections were skipped.

	public function ajax_save() {
		check_ajax_referer( 'ovebotai_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebotai' ) ) );
		}

		// ── Always: chat on/off + widget appearance ──────────────────────────

		update_option( 'ovebotai_chat_status', ! empty( $_POST['chat_status'] ) ? '1' : '0', false );

		if ( isset( $_POST['workspace'] ) ) {
			update_option( 'ovebotai_workspace', sanitize_key( wp_unslash( $_POST['workspace'] ) ), false );
		}
		if ( isset( $_POST['agent'] ) ) {
			update_option( 'ovebotai_agent', sanitize_key( wp_unslash( $_POST['agent'] ) ), false );
		}

		$widget = array();
		foreach ( self::$widget_keys as $key ) {
			if ( isset( $_POST[ 'widget_' . $key ] ) ) {
				$widget[ $key ] = sanitize_text_field( wp_unslash( $_POST[ 'widget_' . $key ] ) );
			}
		}
		update_option( 'ovebotai_widget', $widget, false );

		// ── Check OAuth before touching API-dependent settings ───────────────

		$oauth     = Ovebotai_OAuth::instance();
		$connected = $oauth->is_connected();

		if ( ! $connected ) {
			wp_send_json_success( array(
				'message'     => __( 'Chat settings saved. Reconnect to Ovebot.ai to sync feed and order settings.', 'ovebotai' ),
				'partial'     => true,
				'needs_reconnect' => true,
			) );
		}

		// ── Requires OAuth: delivery days ─────────────────────────────────────

		foreach ( self::$delivery_keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( 'ovebotai_' . $key, absint( $_POST[ $key ] ), false );
			}
		}

		// ── Requires OAuth: knowledge base pages ──────────────────────────────
		// Every checked page is created/updated on each save (so edited page
		// content stays in sync, not just brand-new pages). Pages that were
		// checked before and are unchecked now get deactivated — that part
		// has to stay diff-based, there's no other way to know what to unset.

		$kb_checked_ids  = array_map( 'absint', (array) ( $_POST['kb_pages'] ?? array() ) );
		$kb_previous_ids = (array) get_option( 'ovebotai_kb_page_ids', array() );
		$kb_removed_ids  = array_diff( $kb_previous_ids, $kb_checked_ids );

		update_option( 'ovebotai_kb_page_ids', $kb_checked_ids, false );

		$kb_setup    = Ovebotai_Setup::instance();
		$kb_result   = $kb_setup->sync_kb_pages( $kb_checked_ids, true );
		$kb_errors   = $kb_result['errors'];
		$kb_warnings = $kb_result['warnings'];
		if ( $kb_removed_ids ) {
			$kb_removed_result = $kb_setup->sync_kb_pages( $kb_removed_ids, false );
			$kb_errors         = array_merge( $kb_errors, $kb_removed_result['errors'] );
			$kb_warnings       = array_merge( $kb_warnings, $kb_removed_result['warnings'] );
		}

		// ── Sync everything else to Ovebot API ────────────────────────────────

		$api_error  = $this->sync_to_api( $widget );
		$all_errors = $kb_errors;
		if ( $api_error ) {
			$all_errors[] = $api_error;
		}

		// Local save always succeeded at this point — remote sync failures and
		// skipped KB pages are reported as warnings alongside the success
		// message, not as a reason to call the save itself unsuccessful.
		wp_send_json_success( array(
			'message'  => __( 'Settings saved.', 'ovebotai' ),
			'warnings' => array_merge( $all_errors, $kb_warnings ),
		) );
	}

	private function sync_to_api( array $widget ): string {
		$oauth = Ovebotai_OAuth::instance();

		// Widget section — filter empty values so we don't override with blanks.
		$widget_payload = array_filter( $widget, function( $v ) { return $v !== ''; } );
		$order_url      = home_url( '/wp-json/ovebotai/v1/orders' );

		$payload = array(
			'widget'     => $widget_payload ?: (object) array(),
			'order_info' => array(
				'enabled'      => true,
				'api_url'      => $order_url,
				'api_user'     => (string) get_option( 'ovebotai_order_user', '' ),
				'api_password' => (string) get_option( 'ovebotai_order_pass', '' ),
				'lookup_method'=> 'email',
			),
		);

		// Checked live — never cached — so this always matches whether
		// WooCommerce is actually installed right now.
		if ( Ovebotai::woocommerce_active() ) {
			$feed_hash = (string) get_option( 'ovebotai_feed_hash', '' );
			$payload['products'] = array(
				'enabled'  => true,
				'feed_url' => add_query_arg( 'hash', $feed_hash, home_url( '/wp-json/ovebotai/v1/feed' ) ),
				'currency' => Ovebotai::store_currency(),
			);
		}

		$result = $oauth->api_request( 'PUT', $oauth->setup_api_path(), $payload );

		$status = $result['status'] ?? 0;
		if ( $status < 200 || $status >= 300 ) {
			return __( 'Settings saved locally but could not sync with Ovebot.ai.', 'ovebotai' );
		}

		return '';
	}

	// ── Regenerate feed hash ─────────────────────────────────────────────────

	public function ajax_regen_hash() {
		check_ajax_referer( 'ovebotai_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebotai' ) ) );
		}

		$hash     = wp_generate_password( 32, false );
		$feed_url = add_query_arg( 'hash', $hash, home_url( '/wp-json/ovebotai/v1/feed' ) );

		// Sync the new feed URL to Ovebot.ai first — only persist locally once we
		// have confirmation it took, so the old (still working) hash never gets
		// clobbered by one that Ovebot.ai never actually received.
		$synced = $this->put_setup( array( 'products' => array( 'feed_url' => $feed_url, 'enabled' => true ) ) );

		if ( ! $synced ) {
			wp_send_json_error( array(
				'message' => __( 'Could not sync with Ovebot.ai — feed hash left unchanged.', 'ovebotai' ),
			) );
		}

		update_option( 'ovebotai_feed_hash', $hash, false );

		wp_send_json_success( array(
			'hash'    => $hash,
			'url'     => $feed_url,
			'message' => __( 'Feed URL regenerated and synced with Ovebot.ai.', 'ovebotai' ),
		) );
	}

	// ── Regenerate order API credentials ────────────────────────────────────

	public function ajax_regen_creds() {
		check_ajax_referer( 'ovebotai_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebotai' ) ) );
		}

		$host = (string) parse_url( home_url(), PHP_URL_HOST );
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', preg_replace( '/^www\./i', '', $host ) ) );
		$user = trim( $slug, '_' ) . '_' . substr( wp_generate_password( 8, false ), 0, 8 );
		$pass = wp_generate_password( 24, false );

		// Sync the new credentials to Ovebot.ai first — only persist locally once
		// we have confirmation it took, so the old (still working) credentials
		// never get clobbered by ones that Ovebot.ai never actually received.
		$synced = $this->put_setup( array( 'order_info' => array( 'api_user' => $user, 'api_password' => $pass, 'enabled' => true ) ) );

		if ( ! $synced ) {
			wp_send_json_error( array(
				'message' => __( 'Could not sync with Ovebot.ai — credentials left unchanged.', 'ovebotai' ),
			) );
		}

		update_option( 'ovebotai_order_user', $user, false );
		update_option( 'ovebotai_order_pass', $pass, false );

		wp_send_json_success( array(
			'user'    => $user,
			'pass'    => $pass,
			'message' => __( 'Credentials regenerated and synced with Ovebot.ai.', 'ovebotai' ),
		) );
	}

	// ── Helper: PUT a partial setup payload, returns true on 2xx ────────────

	private function put_setup( array $payload ): bool {
		$oauth  = Ovebotai_OAuth::instance();
		$result = $oauth->api_request( 'PUT', $oauth->setup_api_path(), $payload );
		$status = $result['status'] ?? 0;
		return $status >= 200 && $status < 300;
	}

	// ── Clear product feed cache ─────────────────────────────────────────────

	public function ajax_clear_cache() {
		check_ajax_referer( 'ovebotai_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebotai' ) ) );
		}

		Ovebotai_Feed::instance()->invalidate_cache();

		wp_send_json_success( array( 'message' => __( 'Cache cleared.', 'ovebotai' ) ) );
	}

	// ── Static helpers for views ─────────────────────────────────────────────

	public static function get_widget(): array {
		return (array) get_option( 'ovebotai_widget', array() );
	}

	public static function get_delivery( string $key, int $default ): int {
		return (int) get_option( 'ovebotai_' . $key, $default );
	}
}
