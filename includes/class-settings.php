<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_Settings {

	private static $instance = null;

	private static $widget_keys = array(
		'subtitle', 'accent_color', 'proactive_message', 'proactive_delay',
		'theme', 'language', 'width', 'height', 'audio_beep', 'side',
		'offset_x', 'offset_y', 'z_index',
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
	}

	// ── Save settings ───────────────────────────────────────────────────────
	//
	// Local-only settings (chat_status + widget appearance) are always saved.
	// Settings that require an API sync (feed, order_info) are saved locally
	// and synced only when the OAuth connection is valid.
	// If the token is expired the handler attempts a refresh first; if that
	// also fails it saves locally and returns a partial-success response so
	// the UI can inform the user which sections were skipped.

	public function ajax_save() {
		check_ajax_referer( 'ovebotai_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebot-ai-chatbot-sales-agent' ) ) );
		}

		// ── Always: chat on/off + widget appearance ──────────────────────────

		update_option( 'ovebotai_chat_status', ! empty( $_POST['chat_status'] ) ? '1' : '0', false );

		// Order-lookup and product-recommendation master switches. Both live in
		// WooCommerce-gated fieldsets, so the fields only exist on the form when WC
		// is active - and an unchecked checkbox is indistinguishable from an absent
		// field. Read them only when WC is active, otherwise a save on a WC-inactive
		// site would wrongly flip both off (and keep them off after WC returns).
		// Saved locally (they gate the /orders and /feed endpoints) and mirrored into
		// the account's order_info.enabled / products.enabled by the resync_setup()
		// push below, which reads these options via build_setup_payload().
		if ( Ovebotai::woocommerce_active() ) {
			update_option( 'ovebotai_order_api_enabled', ! empty( $_POST['order_api_status'] ) ? '1' : '0', false );
			update_option( 'ovebotai_products_enabled', ! empty( $_POST['products_enabled'] ) ? '1' : '0', false );
			// "Add to cart" button in the chat - mirrored as products.add_to_cart.
			update_option( 'ovebotai_add_to_cart', ! empty( $_POST['add_to_cart'] ) ? '1' : '0', false );
		}

		$widget = array();
		foreach ( self::$widget_keys as $key ) {
			if ( isset( $_POST[ 'widget_' . $key ] ) ) {
				$widget[ $key ] = sanitize_text_field( wp_unslash( $_POST[ 'widget_' . $key ] ) );
			}
		}
		update_option( 'ovebotai_widget', $widget, false );

		// Product source: built-in automatic feed vs. "I'll manage products on
		// Ovebot.ai myself", now a toggle like the two switches above — same
		// WC-gating reason applies (an unchecked checkbox is indistinguishable
		// from an absent field). Persisted locally always (it also gates the
		// local feed endpoint); the API push below reflects it — when "own" it
		// omits the products section entirely so Ovebot.ai's own copy stays
		// untouched (item 10, see Ovebotai::build_setup_payload()).
		if ( Ovebotai::woocommerce_active() ) {
			update_option( 'ovebotai_products_source', ! empty( $_POST['products_source_builtin'] ) ? 'auto' : 'own', false );
		}

		// ── Check OAuth before touching API-dependent settings ───────────────

		$oauth     = Ovebotai_OAuth::instance();
		$connected = $oauth->is_connected();

		if ( ! $connected ) {
			wp_send_json_success( array(
				'message'     => __( 'Chat settings saved. Reconnect to Ovebot.ai to sync feed and order settings.', 'ovebot-ai-chatbot-sales-agent' ),
				'partial'     => true,
				'needs_reconnect' => true,
			) );
		}

		// Knowledge base pages are only synced from the setup wizard — this form
		// never touches _ovebotai_kb_id mappings or triggers a KB sync.

		// ── Sync everything else to Ovebot API ────────────────────────────────

		$api_error  = $this->sync_to_api();
		$all_errors = $api_error ? array( $api_error ) : array();

		// Local save always succeeded at this point — a remote sync failure is
		// reported as a warning alongside the success message, not as a reason
		// to call the save itself unsuccessful.
		wp_send_json_success( array(
			'message'  => __( 'Settings saved.', 'ovebot-ai-chatbot-sales-agent' ),
			'warnings' => $all_errors,
		) );
	}

	private function sync_to_api(): string {
		// The widget option was already persisted by the caller before this
		// runs, so build_setup_payload() (which reads that option) reflects
		// it — single source of truth shared with the (re)activation resync
		// in Ovebotai::resync_setup().
		if ( Ovebotai::resync_setup() ) {
			return '';
		}

		return __( 'Settings saved locally but could not sync with Ovebot.ai.', 'ovebot-ai-chatbot-sales-agent' );
	}

	// ── Regenerate feed hash ─────────────────────────────────────────────────

	public function ajax_regen_hash() {
		check_ajax_referer( 'ovebotai_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebot-ai-chatbot-sales-agent' ) ) );
		}

		$hash     = wp_generate_password( 32, false );
		$feed_url = add_query_arg( 'hash', $hash, rest_url( 'ovebotai/v1/feed' ) );

		// Sync the new feed URL to Ovebot.ai first — only persist locally once we
		// have confirmation it took, so the old (still working) hash never gets
		// clobbered by one that Ovebot.ai never actually received.
		$synced = $this->put_setup( array( 'products' => array( 'feed_url' => $feed_url, 'enabled' => true ) ) );

		if ( ! $synced ) {
			wp_send_json_error( array(
				'message' => __( 'Could not sync with Ovebot.ai — feed hash left unchanged.', 'ovebot-ai-chatbot-sales-agent' ),
			) );
		}

		update_option( 'ovebotai_feed_hash', $hash, false );

		wp_send_json_success( array(
			'hash'    => $hash,
			'url'     => $feed_url,
			'message' => __( 'Feed URL regenerated and synced with Ovebot.ai.', 'ovebot-ai-chatbot-sales-agent' ),
		) );
	}

	// ── Regenerate order API credentials ────────────────────────────────────

	public function ajax_regen_creds() {
		check_ajax_referer( 'ovebotai_settings', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ovebot-ai-chatbot-sales-agent' ) ) );
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', preg_replace( '/^www\./i', '', $host ) ) );
		$user = trim( $slug, '_' ) . '_' . substr( wp_generate_password( 8, false ), 0, 8 );
		$pass = wp_generate_password( 24, false );

		// Sync the new credentials to Ovebot.ai first — only persist locally once
		// we have confirmation it took, so the old (still working) credentials
		// never get clobbered by ones that Ovebot.ai never actually received.
		$synced = $this->put_setup( array( 'order_info' => array( 'api_user' => $user, 'api_password' => $pass, 'enabled' => true ) ) );

		if ( ! $synced ) {
			wp_send_json_error( array(
				'message' => __( 'Could not sync with Ovebot.ai — credentials left unchanged.', 'ovebot-ai-chatbot-sales-agent' ),
			) );
		}

		update_option( 'ovebotai_order_user', $user, false );
		update_option( 'ovebotai_order_pass', $pass, false );

		wp_send_json_success( array(
			'user'    => $user,
			'pass'    => $pass,
			'message' => __( 'Credentials regenerated and synced with Ovebot.ai.', 'ovebot-ai-chatbot-sales-agent' ),
		) );
	}

	// ── Helper: PUT a partial setup payload, returns true on 2xx ────────────

	private function put_setup( array $payload ): bool {
		$oauth  = Ovebotai_OAuth::instance();
		$result = $oauth->api_request( 'PUT', $oauth->setup_api_path(), $payload );
		$status = $result['status'] ?? 0;
		return $status >= 200 && $status < 300;
	}

	// ── Static helpers for views ─────────────────────────────────────────────

	public static function get_widget(): array {
		return (array) get_option( 'ovebotai_widget', array() );
	}
}
