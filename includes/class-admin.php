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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_ovebotai_connect',    array( $this, 'action_connect' ) );
		add_action( 'admin_post_ovebotai_disconnect', array( $this, 'action_disconnect' ) );
		add_action( 'admin_notices',          array( $this, 'maybe_show_woocommerce_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OVEBOTAI_FILE ), array( $this, 'plugin_action_links' ) );
	}

	// ── "WooCommerce was installed, needs a resync" notice ──────────────────
	//
	// Shown site-wide (not just on our own settings screen) so it's noticed
	// even if nobody opens Settings on their own initiative — WooCommerce
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
					wp_kses_post( __( 'WooCommerce was installed - the Ovebot.ai plugin needs additional configuration. Open its %s and click Save to enable the product feed and order tracking.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ) ),
					sprintf(
						'<a href="%1$s">%2$s</a>',
						esc_url( $settings_url ),
						esc_html__( 'settings', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' )
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
			. esc_html__( 'Settings', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	// ── Redirect after activation ────────────────────────────────────────────

	public function maybe_redirect_after_activation() {
		if ( ! get_option( 'ovebotai_activation_redirect' ) ) return;
		delete_option( 'ovebotai_activation_redirect' );

		// Don't redirect during bulk activation — read-only check of WP core's
		// own bulk-activate flag, no state change, no nonce to verify.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) return;

		wp_safe_redirect( admin_url( 'admin.php?page=ovebotai' ) );
		exit;
	}

	// ── Admin menu ───────────────────────────────────────────────────────────

	public function register_menu() {
		// Under Settings rather than its own top-level menu item — the page
		// itself (slug "ovebotai") is unchanged, so every existing
		// admin.php?page=ovebotai link/redirect keeps working as-is.
		add_options_page(
			__( 'Ovebot.ai', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
			__( 'Ovebot.ai', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
			'manage_options',
			'ovebotai',
			array( $this, 'render_page' )
		);
	}

	// ── OAuth: start ────────────────────────────────────────────────────────

	public function action_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ) );
		}
		check_admin_referer( 'ovebotai_connect' );

		$oauth = Ovebotai_OAuth::instance();
		wp_redirect( $oauth->get_auth_url( $this->callback_url() ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	// ── OAuth: return ────────────────────────────────────────────────────────

	public function handle_oauth_return() {
		// This is an external OAuth redirect from account.ovebot.ai, not a
		// same-site form submission — a WP nonce can't apply here (Ovebot.ai
		// has no session to generate one from). CSRF protection is the OAuth
		// `state` param itself, validated against our stored PKCE verifier
		// inside exchange_code() below.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['page'] ) || 'ovebotai' !== $_GET['page'] ) return;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['code'] ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

		$result = Ovebotai_OAuth::instance()->exchange_code( $code, $state );

		if ( ! empty( $result['error'] ) ) {
			wp_safe_redirect( add_query_arg( array(
				'page'        => 'ovebotai',
				'oauth_error' => rawurlencode( $result['error'] ),
			), admin_url( 'admin.php' ) ) );
		} else {
			wp_safe_redirect( add_query_arg( array(
				'page' => 'ovebotai',
				'step' => '2',
			), admin_url( 'admin.php' ) ) );
		}
		exit;
	}

	// ── Disconnect ───────────────────────────────────────────────────────────

	public function action_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ) );
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
			wp_die( esc_html__( 'Permission denied.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ) );
		}

		if ( ! Ovebotai::is_setup_complete() ) {
			require OVEBOTAI_DIR . 'admin/views/setup.php';
			return;
		}

		// Read-only view routing — no state change, no nonce to verify.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['view'] ) && 'settings' === $_GET['view'] ) {
			require OVEBOTAI_DIR . 'admin/views/settings.php';
			return;
		}

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
			// The dashboard is static (no form, no AJAX) — only the Manual
			// settings view needs settings.js.
			// Read-only view routing — no state change, no nonce to verify.
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
					'i18n'       => array(
						'saved'             => __( 'Settings saved.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						'saving'            => __( 'Saving…', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						'error'             => __( 'An error occurred. Please try again.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						'confirmRegen'      => __( 'Regenerate API credentials? The current credentials will stop working immediately.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						'confirmRegenHash'  => __( 'Regenerate feed hash? The current feed URL will stop working.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						'confirmClearCache' => __( 'Clear the product feed cache?', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						'copied'            => __( 'Copied!', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
						'copy'              => __( 'Copy', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					),
				) );
			}
		} else {
			$oauth     = Ovebotai_OAuth::instance();
			$wc_active = Ovebotai::woocommerce_active();

			// Steps present in this flow — Products KB only when WooCommerce is active.
			$steps_seq = $wc_active ? array( 1, 2, 3, 4 ) : array( 1, 2, 4 );

			// Read-only view routing — no state change, no nonce to verify.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$initial_step = isset( $_GET['step'] ) ? (int) $_GET['step'] : ( $oauth->is_connected() ? 2 : 1 );
			if ( ! in_array( $initial_step, $steps_seq, true ) ) {
				$initial_step = $steps_seq[0];
			}

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
				// Read-only error message display, already sanitized — no state change.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'oauthError'     => isset( $_GET['oauth_error'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_error'] ) ) : '',
				'i18n'           => array(
					'next'                  => __( 'Next →', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					'sync'                  => __( 'Finish setup →', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					'retry'                 => __( 'Retry', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					'error'                 => __( 'An error occurred. Please try again.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					'noProducts'            => __( 'No published products found — your AI agent won\'t have any products to recommend yet.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
					'productsWillBeIndexed' => __( 'products will be sent to your AI agent so it can recommend them to customers.', 'ovebot-ai-chatbot-live-chat-ai-sales-agent-for-woocommerce' ),
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
		// matching row into PHP just to count them — and it's a live,
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
				'title'   => $page->post_title,
				'checked' => $checked,
			);
		}
		return $result;
	}
}
