<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_init',            array( $this, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_init',            array( $this, 'handle_oauth_return' ) );
		add_action( 'admin_init',            array( $this, 'maybe_redirect_settings_view' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_ovebotai_connect',    array( $this, 'action_connect' ) );
		add_action( 'admin_post_ovebotai_disconnect', array( $this, 'action_disconnect' ) );
		add_action( 'admin_notices',          array( $this, 'maybe_show_woocommerce_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OVEBOTAI_FILE ), array( $this, 'plugin_action_links' ) );
	}

	// ── "WooCommerce was installed, needs a resync" notice ──────────────────
	//
	// Shown site-wide (not just on our own settings screen) so it's noticed
	// even if nobody opens Settings on their own initiative - WooCommerce
	// being activated after our setup was already completed is otherwise
	// silent until the next manual Save (see Ovebotai::needs_woocommerce_resync()).

	public function maybe_show_woocommerce_notice() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( ! Ovebotai::needs_woocommerce_resync() ) return;

		$settings_url = add_query_arg( 'view', 'settings', admin_url( 'admin.php?page=ovebotai' ) );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: link to the Ovebot.ai settings page */
					wp_kses_post( __( 'WooCommerce was installed - the Ovebot.ai plugin needs additional configuration. Open its %s and click Save to enable the product feed and order tracking.', 'ovebot-ai-chatbot-sales-agent' ) ),
					sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( $settings_url ),
						esc_html__( 'settings', 'ovebot-ai-chatbot-sales-agent' )
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	// ── Plugins list "Settings" link ─────────────────────────────────────────

	public function plugin_action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=ovebotai' ) ) . '">'
			. esc_html__( 'Settings', 'ovebot-ai-chatbot-sales-agent' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	// ── Redirect after activation ────────────────────────────────────────────

	public function maybe_redirect_after_activation() {
		if ( ! get_option( 'ovebotai_activation_redirect' ) ) return;
		delete_option( 'ovebotai_activation_redirect' );

		// Don't redirect during bulk activation - read-only check of WP core's
		// own bulk-activate flag, no state change, no nonce to verify.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) return;

		wp_safe_redirect( admin_url( 'admin.php?page=ovebotai' ) );
		exit;
	}

	// ── Settings view guard ──────────────────────────────────────────────────
	//
	// The Settings screen assumes a working OAuth connection throughout (feed /
	// order-info push, credential regeneration), so a lapsed connection has to
	// land on the dashboard's reconnect panel instead of a half-usable form.
	// Handled here rather than inside the view: at admin_init nothing has been
	// output yet, so this is a real HTTP redirect - from inside the template the
	// only option left would be printing a <script>location.replace()</script>,
	// which is exactly the kind of inline output that should never be emitted
	// by hand.
	//
	// is_connected_live() costs one API call, but it's memoized on the OAuth
	// singleton for the rest of the request, so the view and the connection
	// badge reuse this one instead of firing their own.

	public function maybe_redirect_settings_view() {
		// Read-only view routing - no state change, no nonce to verify.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || 'ovebotai' !== $_GET['page'] ) return;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['view'] ) || 'settings' !== $_GET['view'] ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;

		// Setup not finished yet - render_page() sends this to the wizard on its
		// own, and the wizard has its own connect panel. Nothing to redirect.
		if ( ! Ovebotai::is_setup_complete() ) return;

		if ( Ovebotai_OAuth::instance()->is_connected_live() ) return;

		wp_safe_redirect( add_query_arg( 'page', 'ovebotai', admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Admin menu ───────────────────────────────────────────────────────────

	public function register_menu() {
		// Under Settings rather than its own top-level menu item - the page
		// itself (slug "ovebotai") is unchanged, so every existing
		// admin.php?page=ovebotai link/redirect keeps working as-is.
		add_options_page(
			__( 'Ovebot.ai', 'ovebot-ai-chatbot-sales-agent' ),
			__( 'Ovebot.ai', 'ovebot-ai-chatbot-sales-agent' ),
			'manage_options',
			'ovebotai',
			array( $this, 'render_page' )
		);
	}

	// ── OAuth: start ────────────────────────────────────────────────────────

	public function action_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ovebot-ai-chatbot-sales-agent' ) );
		}
		check_admin_referer( 'ovebotai_connect' );

		$oauth = Ovebotai_OAuth::instance();
		wp_redirect( $oauth->get_auth_url( $this->callback_url() ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	// ── OAuth: return ────────────────────────────────────────────────────────

	public function handle_oauth_return() {
		// Runs on admin_init to catch the redirect account.ovebot.ai sends the
		// browser back to once the merchant approves the connection.
		//
		// Why there is no wp_verify_nonce()/check_admin_referer() here: this is an
		// inbound redirect from a *different origin* (account.ovebot.ai), not a
		// same-site form post, so there was never a WordPress nonce to round-trip
		// through it. CSRF is instead defended the way the OAuth authorization-code
		// flow is designed to be — with the `state` parameter, validated below: our
		// own get_auth_url() generated a random state and stored a PKCE verifier
		// under it in a 10-minute transient, so a matching transient proves this
		// callback answers a request THIS site started (the OAuth equivalent of a
		// nonce). On top of that we gate on the admin capability and sanitize every
		// value read.

		// Bail unless this is our own admin page carrying an authorization code.
		// Read-only routing check on the current admin URL - not a state change.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || 'ovebotai' !== $_GET['page'] ) return;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['code'] ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

		// Origin validation (the nonce-equivalent described above): reject anything
		// whose state doesn't map to a live PKCE verifier this site issued, before
		// the authorization code is even read. exchange_code() re-reads and consumes
		// that same verifier, so a forged or stale callback stops right here.
		if ( '' === $state || false === get_transient( 'ovebotai_pkce_verifier_' . $state ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

		$result = Ovebotai_OAuth::instance()->exchange_code( $code, $state );

		if ( ! empty( $result['error'] ) ) {
			// Hand the failure to the wizard through a short-lived, per-user
			// transient rather than a query-string parameter, so the next page load
			// has no untrusted $_GET value to read back - the redirect target below
			// carries nothing but ?page=ovebotai.
			set_transient( 'ovebotai_oauth_error_' . get_current_user_id(), $result['error'], MINUTE_IN_SECONDS );
		}

		// No step hint needed: render_page() sends an already-complete setup (a
		// reconnect) straight to the dashboard, and an unfinished one lands on step 2
		// because the tokens now exist.
		wp_safe_redirect( add_query_arg( 'page', 'ovebotai', admin_url( 'admin.php' ) ) );
		exit;
	}

	// One-shot read of the OAuth-return error stashed by handle_oauth_return().
	// Stored as a per-user transient ("flash" message) and cleared on first read,
	// so the wizard shows it exactly once and no error string has to travel back
	// through - and be read from - $_GET on the following request.
	private function consume_oauth_error(): string {
		$key   = 'ovebotai_oauth_error_' . get_current_user_id();
		$error = (string) get_transient( $key );
		if ( '' !== $error ) {
			delete_transient( $key );
		}
		return $error;
	}

	// ── Disconnect ───────────────────────────────────────────────────────────

	public function action_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ovebot-ai-chatbot-sales-agent' ) );
		}
		check_admin_referer( 'ovebotai_disconnect' );

		$oauth = Ovebotai_OAuth::instance();
		$oauth->disconnect_remote();
		$oauth->clear_tokens();

		wp_safe_redirect( add_query_arg( 'page', 'ovebotai', admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Page render ──────────────────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ovebot-ai-chatbot-sales-agent' ) );
		}

		if ( ! Ovebotai::is_setup_complete() ) {
			require OVEBOTAI_DIR . 'admin/views/setup.php';
			return;
		}

		// Read-only view routing - no state change, no nonce to verify.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['view'] ) && 'settings' === $_GET['view'] ) {
			require OVEBOTAI_DIR . 'admin/views/settings.php';
			return;
		}

		// Reconcile the local order-API switch from the account before the
		// dashboard renders (task 8) - the merchant may have toggled it directly on
		// Ovebot.ai. The integration fetch this performs is memoized on the OAuth
		// singleton, so the dashboard view reuses it for its product count and its
		// recommendation / order-tracking status warnings instead of re-requesting.
		Ovebotai::sync_settings();

		require OVEBOTAI_DIR . 'admin/views/dashboard.php';
	}

	// ── Assets ───────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ) {
		// Hook suffix for a page registered under Settings (add_options_page).
		if ( 'settings_page_ovebotai' !== $hook ) return;

		wp_enqueue_style(
			'ovebotai-admin',
			OVEBOTAI_URL . 'admin/css/admin.css',
			array(),
			OVEBOTAI_VERSION
		);

		if ( Ovebotai::is_setup_complete() ) {
			// The dashboard is static (no form, no AJAX) - only the Manual
			// settings view needs settings.js.
			// Read-only view routing - no state change, no nonce to verify.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['view'] ) && 'settings' === $_GET['view'] ) {
				wp_enqueue_script(
					'ovebotai-settings',
					OVEBOTAI_URL . 'admin/js/settings.js',
					array( 'jquery' ),
					OVEBOTAI_VERSION,
					true
				);
				wp_localize_script( 'ovebotai-settings', 'ovebotaiSettings', array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'ovebotai_settings' ),
					'dashboardUrl' => admin_url( 'admin.php?page=ovebotai' ),
					// Generic "highlight this field" deep-link target (item 12) -
					// e.g. the dashboard's chat quick-link points here with
					// highlight=chat_status when chat is disabled.
					// Read-only display parameter, already sanitized - no state change.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'highlight'    => isset( $_GET['highlight'] ) ? sanitize_key( wp_unslash( $_GET['highlight'] ) ) : '',
					'i18n'       => array(
						'saved'             => __( 'Settings saved.', 'ovebot-ai-chatbot-sales-agent' ),
						'saving'            => __( 'Saving…', 'ovebot-ai-chatbot-sales-agent' ),
						'error'             => __( 'An error occurred. Please try again.', 'ovebot-ai-chatbot-sales-agent' ),
						'confirmRegen'      => __( 'Regenerate API credentials? The current credentials will stop working immediately.', 'ovebot-ai-chatbot-sales-agent' ),
						'confirmRegenHash'  => __( 'Regenerate feed hash? The current feed URL will stop working.', 'ovebot-ai-chatbot-sales-agent' ),
						'copied'            => __( 'Copied!', 'ovebot-ai-chatbot-sales-agent' ),
						'copy'              => __( 'Copy', 'ovebot-ai-chatbot-sales-agent' ),
						'enabled'           => __( 'Enabled', 'ovebot-ai-chatbot-sales-agent' ),
						'disabled'          => __( 'Disabled', 'ovebot-ai-chatbot-sales-agent' ),
					),
				) );
			}
		} else {
			$oauth     = Ovebotai_OAuth::instance();
			$wc_active = Ovebotai::woocommerce_active();

			// Steps present in this flow - Products KB only when WooCommerce is active.
			$steps_seq = $wc_active ? array( 1, 2, 3, 4 ) : array( 1, 2, 4 );

			// Entry step is derived from connection state alone - never from the
			// URL. render_page() has already sent finished setups to the
			// dashboard, so reaching this view means setup is unfinished and the
			// only two meaningful entry points are "connect" and "pick pages".
			//
			// Must be the *live* check, matching setup.php: setup.js re-renders
			// this step on load, so a cheaper local-only check here would jump
			// past the connect panel that the view rendered for a lapsed token,
			// leaving no way to reconnect. The API call behind it is memoized,
			// so the view's own call reuses this one.
			$initial_step = $oauth->is_connected_live() ? 2 : 1;

			// Product counts for step 3.
			$product_counts = $this->get_product_counts();

			// Pages list for step 2.
			$pages = $this->get_pages_for_kb();

			wp_enqueue_script(
				'ovebotai-setup',
				OVEBOTAI_URL . 'admin/js/setup.js',
				array( 'jquery' ),
				OVEBOTAI_VERSION,
				true
			);
			wp_localize_script( 'ovebotai-setup', 'ovebotaiSetup', array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'ovebotai_setup' ),
				'initialStep'    => $initial_step,
				'stepsSequence'  => $steps_seq,
				'isConnected'    => $oauth->is_connected() ? 1 : 0,
				'productCounts'  => $product_counts,
				// Set by handle_oauth_return() on a failed connect, read once from a
				// per-user transient here (no $_GET involved). setup.js renders it.
				'oauthError'     => $this->consume_oauth_error(),
				'i18n'           => array(
					// Arrows are appended by setup.js itself (as real Unicode
					// characters, since .text() would render an HTML entity
					// literally) - kept out of the translatable strings (item 1).
					'next'                  => __( 'Next', 'ovebot-ai-chatbot-sales-agent' ),
					'sync'                  => __( 'Finish setup', 'ovebot-ai-chatbot-sales-agent' ),
					'retry'                 => __( 'Retry', 'ovebot-ai-chatbot-sales-agent' ),
					'updated'               => __( 'Updated', 'ovebot-ai-chatbot-sales-agent' ),
					'error'                 => __( 'An error occurred. Please try again.', 'ovebot-ai-chatbot-sales-agent' ),
					'noProducts'            => __( 'No published products found - your AI agent won\'t have any products to recommend yet.', 'ovebot-ai-chatbot-sales-agent' ),
					'productsWillBeIndexed' => __( 'products will be sent to your AI agent so it can recommend them to customers.', 'ovebot-ai-chatbot-sales-agent' ),
				),
			) );
		}
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	public function callback_url(): string {
		return admin_url( 'admin.php?page=ovebotai' );
	}

	private function get_product_counts(): array {
		if ( ! Ovebotai::woocommerce_active() ) {
			return array( 'total' => 0, 'feed_count' => 0 );
		}

		// Direct queries: a DISTINCT+JOIN aggregate count like this isn't
		// expressible through get_posts()/WP_Query without pulling every
		// matching row into PHP just to count them - and it's a live,
		// dashboard-only figure, not something worth caching.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "
			SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
		" );
		// Products that actually go on the feed: in stock or on backorder, with a
		// positive price (matches the meta_query in Ovebotai_Feed::build_feed()).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$feed_count = (int) $wpdb->get_var( $wpdb->prepare( "
			SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id
			JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			  AND pm_stock.meta_key = '_stock_status' AND pm_stock.meta_value IN (%s, %s)
			  AND pm_price.meta_key = '_price' AND CAST(pm_price.meta_value AS DECIMAL(20,4)) > 0
		", 'instock', 'onbackorder' ) );

		return array(
			'total'      => $total,
			'feed_count' => $feed_count,
		);
	}

	public function get_pages_for_kb(): array {
		$saved_ids = (array) get_option( 'ovebotai_kb_page_ids', array() );
		$keywords  = array( 'contact', 'about', 'despre', 'livrare', 'delivery', 'retur', 'return', 'termeni', 'terms', 'faq', 'politica', 'privacy', 'gdpr', 'cookies', 'shipping' );

		$pages = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );

		$result = array();
		foreach ( $pages as $page ) {
			if ( $saved_ids ) {
				$checked = in_array( $page->ID, $saved_ids, true );
			} else {
				$checked = true;
			}
			$result[] = array(
				'id'      => $page->ID,
				// Decode HTML entities so the template's own escaping step doesn't
				// double-encode a title stored as e.g. "About &amp; FAQ" (item 10).
				'title'   => html_entity_decode( $page->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'checked' => $checked,
			);
		}
		return $result;
	}
}
