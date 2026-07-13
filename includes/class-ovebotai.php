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
		load_plugin_textdomain( 'ovebotai', false, dirname( plugin_basename( OVEBOTAI_FILE ) ) . '/languages' );
		$this->includes();
		add_action( 'init', array( $this, 'init' ) );
	}

	private function includes() {
		require_once OVEBOTAI_DIR . 'includes/class-oauth.php';
		require_once OVEBOTAI_DIR . 'includes/class-frontend.php';
		require_once OVEBOTAI_DIR . 'includes/class-feed.php';
		require_once OVEBOTAI_DIR . 'includes/class-orders.php';
		if ( is_admin() ) {
			require_once OVEBOTAI_DIR . 'includes/class-admin.php';
			require_once OVEBOTAI_DIR . 'includes/class-setup.php';
			require_once OVEBOTAI_DIR . 'includes/class-settings.php';
		}
	}

	public function init() {
		Ovebotai_Feed::instance()->register_routes();
		Ovebotai_Orders::instance()->register_routes();
		Ovebotai_Frontend::instance();
		if ( is_admin() ) {
			Ovebotai_Admin::instance();
			Ovebotai_Setup::instance();
			Ovebotai_Settings::instance();
		}
	}

	public static function activate() {
		add_option( 'ovebotai_activation_redirect', '1' );

		if ( ! get_option( 'ovebotai_feed_hash' ) ) {
			update_option( 'ovebotai_feed_hash', wp_generate_password( 32, false ), false );
		}
		if ( ! get_option( 'ovebotai_order_user' ) ) {
			$host = (string) parse_url( home_url(), PHP_URL_HOST );
			$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', preg_replace( '/^www\./i', '', $host ) ) );
			update_option( 'ovebotai_order_user', trim( $slug, '_' ) . '_' . substr( wp_generate_password( 8, false ), 0, 8 ), false );
		}
		if ( ! get_option( 'ovebotai_order_pass' ) ) {
			update_option( 'ovebotai_order_pass', wp_generate_password( 24, false ), false );
		}
		if ( ! get_option( 'ovebotai_cache_version' ) ) {
			update_option( 'ovebotai_cache_version', 1, false );
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

	// ISO-4217 code, e.g. "RON" — required by .tasks/oauth-api.md §5 alongside
	// products.enabled/feed_url when writing the products section of /setup.
	public static function store_currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'RON';
	}
}
