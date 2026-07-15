<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// No load_plugin_textdomain() call needed - WordPress has auto-loaded
		// translations for any plugin with a proper Text Domain header since 4.6.
		$this->includes();
		add_action( 'init', array( $this, 'init' ) );
	}

	private function includes() {
		require_once OVEBOTAI_DIR . 'includes/class-oauth.php';
		require_once OVEBOTAI_DIR . 'includes/class-frontend.php';
		require_once OVEBOTAI_DIR . 'includes/class-feed.php';
		require_once OVEBOTAI_DIR . 'includes/class-orders.php';
		// Loaded unconditionally (not gated by is_admin()) because its
		// save_post_page hook must also fire on the block editor's REST API
		// saves (PUT /wp-json/wp/v2/pages/{id}), which WordPress does not
		// treat as an admin request - is_admin() is false there.
		require_once OVEBOTAI_DIR . 'includes/class-setup.php';
		if ( is_admin() ) {
			require_once OVEBOTAI_DIR . 'includes/class-admin.php';
			require_once OVEBOTAI_DIR . 'includes/class-settings.php';
		}
	}

	public function init() {
		Ovebotai_Feed::instance()->register_routes();
		Ovebotai_Orders::instance()->register_routes();
		Ovebotai_Frontend::instance();
		Ovebotai_Setup::instance();
		if ( is_admin() ) {
			Ovebotai_Admin::instance();
			Ovebotai_Settings::instance();
		}
	}

	public static function activate() {
		add_option( 'ovebotai_activation_redirect', '1' );

		if ( ! get_option( 'ovebotai_feed_hash' ) ) {
			update_option( 'ovebotai_feed_hash', wp_generate_password( 32, false ), false );
		}
		if ( ! get_option( 'ovebotai_order_user' ) ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', preg_replace( '/^www\./i', '', $host ) ) );
			update_option( 'ovebotai_order_user', trim( $slug, '_' ) . '_' . substr( wp_generate_password( 8, false ), 0, 8 ), false );
		}
		if ( ! get_option( 'ovebotai_order_pass' ) ) {
			update_option( 'ovebotai_order_pass', wp_generate_password( 24, false ), false );
		}
		if ( ! get_option( 'ovebotai_cache_version' ) ) {
			update_option( 'ovebotai_cache_version', 1, false );
		}

		// Safety net for deactivate → reactivate: nothing else re-pushes our
		// config to Ovebot.ai just because the plugin came back online, so if
		// their copy of the feed/order/widget setup had gone stale for any
		// reason while we were inactive (or the REST routes were briefly
		// unreachable), reactivating silently leaves it stale otherwise. Only
		// meaningful if we were already connected before this activation -
		// a brand-new install goes through the setup wizard instead.
		if ( self::is_setup_complete() ) {
			require_once OVEBOTAI_DIR . 'includes/class-oauth.php';
			self::resync_setup();
		}
	}

	public static function deactivate() {}

	public static function is_setup_complete() {
		return get_option( 'ovebotai_setup_complete' ) === '1'
			&& (bool) get_option( 'ovebotai_refresh_token' )
			&& (bool) get_option( 'ovebotai_workspace' );
	}

	public static function woocommerce_active() {
		// Testing hook: define( 'OVEBOTAI_FORCE_WC_INACTIVE', true ) in wp-config.php
		// to simulate WooCommerce being absent without actually deactivating it.
		if ( defined( 'OVEBOTAI_FORCE_WC_INACTIVE' ) && OVEBOTAI_FORCE_WC_INACTIVE ) {
			return false;
		}
		return class_exists( 'WooCommerce' );
	}

	// ISO-4217 code, e.g. "RON" - required by .tasks/oauth-api.md §5 alongside
	// products.enabled/feed_url when writing the products section of /setup.
	public static function store_currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'RON';
	}

	// Builds the same PUT /setup payload shape used by both the settings-save
	// flow and the (re)activation resync below - single source of truth so
	// the two never drift apart.
	public static function build_setup_payload(): array {
		$widget         = (array) get_option( 'ovebotai_widget', array() );
		$widget_payload = array_filter( $widget, function( $v ) { return $v !== ''; } );

		$payload = array(
			'widget'     => $widget_payload ?: (object) array(),
			'order_info' => array(
				'enabled'       => true,
				'api_url'       => home_url( '/wp-json/ovebotai/v1/orders' ),
				'api_user'      => (string) get_option( 'ovebotai_order_user', '' ),
				'api_password'  => (string) get_option( 'ovebotai_order_pass', '' ),
				'lookup_method' => 'email',
			),
		);

		// Checked live - never cached - so this always matches whether
		// WooCommerce is actually installed right now.
		if ( self::woocommerce_active() ) {
			$feed_hash = (string) get_option( 'ovebotai_feed_hash', '' );
			$payload['products'] = array(
				'enabled'  => true,
				'feed_url' => add_query_arg( 'hash', $feed_hash, home_url( '/wp-json/ovebotai/v1/feed' ) ),
				'currency' => self::store_currency(),
			);
		}

		return $payload;
	}

	// Pushes the current local config to Ovebot.ai. Returns true on success.
	public static function resync_setup(): bool {
		$oauth  = Ovebotai_OAuth::instance();
		$result = $oauth->api_request( 'PUT', $oauth->setup_api_path(), self::build_setup_payload() );

		$status = $result['status'] ?? 0;
		return $status >= 200 && $status < 300;
	}
}
