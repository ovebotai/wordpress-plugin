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
		// Order-lookup API is on by default (task 5). Only seed it when the option
		// has never been set - a stored '0' (merchant turned it off) must survive a
		// deactivate/reactivate cycle.
		if ( false === get_option( 'ovebotai_order_api_enabled', false ) ) {
			update_option( 'ovebotai_order_api_enabled', '1', false );
		}
		// Product recommendation is on by default too; same "only seed when never
		// set" rule so a stored '0' survives a deactivate/reactivate cycle.
		if ( false === get_option( 'ovebotai_products_enabled', false ) ) {
			update_option( 'ovebotai_products_enabled', '1', false );
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

	// Plugin version. Single source of truth is the OVEBOTAI_VERSION constant,
	// which is itself derived from the "Version:" header in ovebotai.php - exposed
	// as a method so the admin views have one call to render it in their header,
	// instead of touching the constant (or hardcoding a number) in each template.
	public static function getModuleVersion(): string {
		return OVEBOTAI_VERSION;
	}

	// On-site chat widget master switch. When off, the widget isn't injected
	// (see Ovebotai_Frontend) and - per task 3 - the product feed and the
	// order-lookup endpoint are also served as forbidden, since neither has any
	// purpose without a live chat to feed.
	public static function chat_enabled(): bool {
		return '1' === get_option( 'ovebotai_chat_status' );
	}

	// Order-lookup API master switch (task 5). Stored locally, mirrored into the
	// remote order_info.enabled on every setup push, and reconciled back from the
	// account on each dashboard load (see sync_settings()). Defaults to enabled.
	public static function order_api_enabled(): bool {
		return '1' === get_option( 'ovebotai_order_api_enabled', '1' );
	}

	// "Recommend products" master switch, paired with the Product Feed card in
	// Settings. Stored locally, mirrored into the remote products.enabled on every
	// setup push, and reconciled back from the account on each dashboard load (see
	// sync_settings()). Defaults to enabled.
	public static function products_enabled(): bool {
		return '1' === get_option( 'ovebotai_products_enabled', '1' );
	}

	// Pulls the account's current integration status and reconciles the local
	// order-API switch to it (task 8). The merchant may have toggled order lookup
	// directly in their Ovebot.ai account since the last local save; the account
	// wins on read, so a change made there must not be silently overwritten by a
	// stale local value. Local is still what we push on Save.
	public static function sync_settings(): void {
		// Order lookup only carries a meaningful "the user chose this" state while
		// WooCommerce is active. When it's inactive we ourselves push
		// order_info=false (there's nothing to look up), so reading that back and
		// storing it locally would flip the switch off behind the user's back and
		// keep it off even after WooCommerce returns. Skip the reconcile in that case.
		if ( ! self::woocommerce_active() ) {
			return;
		}

		$integration = Ovebotai_OAuth::instance()->get_integration();
		if ( ! is_array( $integration ) ) {
			return;
		}

		// Only reconcile each flag when it's actually present in the response.
		if ( array_key_exists( 'order_info', $integration ) ) {
			update_option( 'ovebotai_order_api_enabled', $integration['order_info'] ? '1' : '0', false );
		}
		if ( array_key_exists( 'products', $integration ) ) {
			update_option( 'ovebotai_products_enabled', $integration['products'] ? '1' : '0', false );
		}
	}

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

	// Generates an order-lookup user/password pair if none is stored yet.
	// activate() already does this on every (re)activation, so in practice
	// this only ever fires for the rare case of the option being missing
	// without a deactivate/reactivate cycle in between (e.g. edited directly
	// in the DB). Never persists by itself - the caller only saves these once
	// Ovebot.ai has actually confirmed receiving them, so a failed sync can
	// never leave the two sides holding different credentials.
	private static function generate_order_credentials(): array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', preg_replace( '/^www\./i', '', $host ) ) );
		return array(
			trim( $slug, '_' ) . '_' . substr( wp_generate_password( 8, false ), 0, 8 ),
			wp_generate_password( 24, false ),
		);
	}

	// Builds the same PUT /setup payload shape used by both the settings-save
	// flow and the (re)activation resync below - single source of truth so
	// the two never drift apart.
	//
	// $order_user/$order_pass let the caller pass in freshly generated
	// credentials before they're persisted locally (see resync_setup()); when
	// omitted, the currently stored ones are used as-is.
	public static function build_setup_payload( $order_user = null, $order_pass = null ): array {
		$widget         = (array) get_option( 'ovebotai_widget', array() );
		$widget_payload = array_filter( $widget, function( $v ) { return $v !== ''; } );

		// Checked live - never cached - so this always matches whether
		// WooCommerce is actually installed right now. Order lookup and the
		// product feed both hard-depend on it (class-orders.php returns 503,
		// and there simply is no feed to serve), so both sections are
		// explicitly disabled rather than omitted when it's inactive - PUT
		// /setup is a partial update, and omitting a section entirely would
		// leave Ovebot.ai's copy stuck on whatever it was last set to.
		$wc_active = self::woocommerce_active();

		// When the merchant opts to manage products directly in their Ovebot.ai
		// account ("I'll provide my own feed"), we must not touch the products
		// section of the remote config at all - not even to send an explicit
		// "disabled", which would overwrite the feed URL/currency/etc. they set
		// up on Ovebot.ai's side. The whole section is omitted from the push so
		// the API's own copy is left completely untouched (item 10).
		$own_feed = self::products_use_own_feed();

		$payload = array(
			'widget' => $widget_payload ?: (object) array(),
		);

		if ( $wc_active && self::order_api_enabled() ) {
			$payload['order_info'] = array(
				'enabled'       => true,
				'api_url'       => home_url( '/wp-json/ovebotai/v1/orders' ),
				'api_user'      => $order_user ?? (string) get_option( 'ovebotai_order_user', '' ),
				'api_password'  => $order_pass ?? (string) get_option( 'ovebotai_order_pass', '' ),
				'lookup_method' => 'email',
			);
		} else {
			// WooCommerce inactive, or the merchant turned order lookup off via the
			// settings switch (task 5). Either way report it explicitly disabled
			// rather than omitting the section - PUT /setup is a partial update, so
			// omitting it would leave the account's copy stuck on whatever it was
			// last set to. No api_url/api_user/api_password: nothing meaningful to
			// send, and this also skips generating order credentials for nothing
			// (see resync_setup()).
			$payload['order_info'] = array( 'enabled' => false );
		}

		// "Recommend products" master switch. When off, report it disabled to the
		// account no matter the feed source - an explicit "don't recommend" must
		// reach Ovebot even for merchants who manage their own feed there, so this
		// takes precedence over the own-feed omission below.
		if ( ! self::products_enabled() ) {
			$payload['products'] = array( 'enabled' => false );
			return $payload;
		}

		if ( $own_feed ) {
			// Section omitted entirely - see comment above.
			return $payload;
		}

		if ( $wc_active ) {
			$feed_hash = (string) get_option( 'ovebotai_feed_hash', '' );
			$payload['products'] = array(
				'enabled'  => true,
				'feed_url' => add_query_arg( 'hash', $feed_hash, home_url( '/wp-json/ovebotai/v1/feed' ) ),
				'currency' => self::store_currency(),
			);
		} else {
			$payload['products'] = array( 'enabled' => false );
		}

		return $payload;
	}

	// Product-source choice (wizard step "Products" + Settings): the built-in
	// automatic feed this module serves, or the merchant's own products managed
	// directly on Ovebot.ai. Defaults to the automatic feed. When true, the
	// products section is omitted from the API push and the local feed endpoint
	// is served as forbidden (see build_setup_payload() and Ovebotai_Feed).
	public static function products_use_own_feed(): bool {
		return 'own' === get_option( 'ovebotai_products_source', 'auto' );
	}

	// Pushes the current local config to Ovebot.ai. Returns true on success.
	public static function resync_setup(): bool {
		$oauth = Ovebotai_OAuth::instance();

		$order_user = null;
		$order_pass = null;
		$generated  = false;

		// Only bother with order credentials when we're actually going to send an
		// enabled order_info section - i.e. WooCommerce is active AND the merchant
		// hasn't switched order lookup off (task 5). Otherwise build_setup_payload()
		// sends { enabled: false } with no credentials, so generating (and later
		// persisting) a fresh pair would be for nothing.
		if ( self::woocommerce_active() && self::order_api_enabled() ) {
			$order_user = (string) get_option( 'ovebotai_order_user', '' );
			$order_pass = (string) get_option( 'ovebotai_order_pass', '' );
			$generated  = '' === $order_user || '' === $order_pass;
			if ( $generated ) {
				list( $order_user, $order_pass ) = self::generate_order_credentials();
			}
		}

		$result = $oauth->api_request( 'PUT', $oauth->setup_api_path(), self::build_setup_payload( $order_user, $order_pass ) );

		$status = $result['status'] ?? 0;
		$ok     = $status >= 200 && $status < 300;

		if ( $ok ) {
			if ( $generated ) {
				update_option( 'ovebotai_order_user', $order_user, false );
				update_option( 'ovebotai_order_pass', $order_pass, false );
			}

			// Records whether this specific sync included WooCommerce
			// (order_info/products enabled) or not, so admin_notices in
			// class-admin.php can tell "WooCommerce is active right now" apart
			// from "...and we've actually told Ovebot.ai about it yet" -
			// installing/activating WooCommerce after setup was already done
			// is otherwise silent until someone happens to open Settings and
			// hit Save.
			update_option( 'ovebotai_synced_wc_active', self::woocommerce_active() ? '1' : '0', false );
		}

		return $ok;
	}

	// True once WooCommerce is active locally but the last successful sync to
	// Ovebot.ai predates that (or never had WooCommerce at all) - i.e. the
	// remote order_info/products are still sitting on { enabled: false }.
	public static function needs_woocommerce_resync(): bool {
		return self::is_setup_complete()
			&& self::woocommerce_active()
			&& '1' !== get_option( 'ovebotai_synced_wc_active', '0' );
	}
}
