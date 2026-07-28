<?php
defined( 'ABSPATH' ) || exit;

$ovebotai_oauth = Ovebotai_OAuth::instance();
$ovebotai_admin = Ovebotai_Admin::instance();

$pages         = $ovebotai_admin->get_pages_for_kb();
// Live check (not just local state) - this view can be reached directly
// (bookmark) without ever making its own API call otherwise, so a lapsed
// refresh token would never get discovered until some later action, and
// the "Connect with an existing account" step would stay hidden.
$ovebotai_is_connected  = $ovebotai_oauth->is_connected_live();
$ovebotai_wc_active     = Ovebotai::woocommerce_active();

// Steps present in this flow - Products KB only exists when WooCommerce is active.
// Checked live on every load, never cached.
$ovebotai_steps_seq = $ovebotai_wc_active ? array( 1, 2, 3, 4 ) : array( 1, 2, 4 );

// Entry step comes from connection state alone, never from the URL - a
// finished setup has already been routed to the dashboard by render_page(),
// so the only two entry points left are "connect" and "pick pages".
$ovebotai_initial_step = $ovebotai_is_connected ? 2 : 1;
// Read-only error message display, already sanitized - no state change.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ovebotai_oauth_error  = isset( $_GET['oauth_error'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_error'] ) ) : '';

// Product-source choice (wizard step 3) + the connected agent's settings page
// on Ovebot.ai (linked from the "own feed" option and the final success screen).
$ovebotai_products_source     = get_option( 'ovebotai_products_source', 'auto' );
$ovebotai_agent_settings_url  = $ovebotai_oauth->get_agent_settings_url();
?>
<hr class="wp-header-end">
<div class="wrap ovebotai-wrap">
	<div class="ovebotai-setup-card">

		<!-- Header -->
		<div class="ovebotai-setup-header">
			<div class="ovebotai-logo">
				<a href="https://ovebot.ai" target="_blank" rel="noopener noreferrer">
					<img src="<?php echo esc_url( OVEBOTAI_URL . 'admin/img/logo.png' ); ?>" alt="Ovebot.ai" height="36">
				</a>
				<?php require OVEBOTAI_DIR . 'admin/views/partials/version-badge.php'; ?>
			</div>
		</div>

		<!-- Progress bar -->
		<div class="ovebotai-progress-track">
			<div class="ovebotai-progress-bar" id="oveProgressBar"></div>
		</div>

		<!-- Step indicators -->
		<div class="ovebotai-steps-nav">
			<div class="ovebotai-steps-nav-inner">
			<?php
			$ovebotai_step_labels = array(
				1 => __( 'Connect account', 'ovebot-ai-chatbot-sales-agent' ),
				2 => __( 'Website pages', 'ovebot-ai-chatbot-sales-agent' ),
				3 => __( 'Products', 'ovebot-ai-chatbot-sales-agent' ),
				4 => __( 'Go live 🎉', 'ovebot-ai-chatbot-sales-agent' ),
			);
			$ovebotai_current_pos = array_search( $ovebotai_initial_step, $ovebotai_steps_seq, true );
			foreach ( $ovebotai_steps_seq as $ovebotai_pos => $ovebotai_num ) : ?>
			<div class="ovebotai-step-dot<?php echo $ovebotai_num === $ovebotai_initial_step ? ' is-active' : ''; ?><?php echo $ovebotai_pos < $ovebotai_current_pos ? ' is-done' : ''; ?>" data-step="<?php echo esc_attr( $ovebotai_num ); ?>">
				<div class="ovebotai-dot-circle">
					<span class="dot-num"><?php echo esc_html( $ovebotai_pos + 1 ); ?></span>
					<span class="dot-check dashicons dashicons-yes-alt" aria-hidden="true"></span>
				</div>
				<span class="ovebotai-dot-label"><?php echo esc_html( $ovebotai_step_labels[ $ovebotai_num ] ); ?></span>
			</div>
			<?php endforeach; ?>
			</div>
		</div>

		<!-- Step panels -->
		<div class="ovebotai-panels">

			<!-- Step 1: Connect -->
			<div class="ovebotai-panel" data-panel="1" <?php echo 1 !== $ovebotai_initial_step ? 'style="display:none"' : ''; ?>>
				<h2><?php esc_html_e( 'Connect your store to Ovebot.ai', 'ovebot-ai-chatbot-sales-agent' ); ?></h2>
				<p class="ovebotai-lead">
					<?php esc_html_e( 'Your AI agent learns your store and answers customers 24/7, in any language.', 'ovebot-ai-chatbot-sales-agent' ); ?><br>
					<strong><?php esc_html_e( 'Free plan for the first 200 stores. No credit card.', 'ovebot-ai-chatbot-sales-agent' ); ?></strong>
				</p>

				<?php if ( $ovebotai_oauth_error ) : ?>
				<div class="ovebotai-notice ovebotai-notice-error">
					<p><?php echo esc_html( $ovebotai_oauth_error ); ?></p>
				</div>
				<?php endif; ?>

				<div class="ovebotai-connect-box">
					<div class="ovebotai-connect-actions">
						<a href="<?php echo esc_url( Ovebotai_OAuth::get_register_url() ); ?>" target="_blank" rel="noopener noreferrer" class="button ovebotai-btn-trial">
							<?php esc_html_e( 'Start Free', 'ovebot-ai-chatbot-sales-agent' ); ?> <span aria-hidden="true">&rarr;</span>
						</a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ovebotai_connect">
							<?php wp_nonce_field( 'ovebotai_connect' ); ?>
							<button type="submit" class="ovebotai-btn-signin">
								<?php esc_html_e( 'I already have an account', 'ovebot-ai-chatbot-sales-agent' ); ?> <span aria-hidden="true">&rarr;</span>
							</button>
						</form>
					</div>
					<p class="ovebotai-connect-hint">
						<?php esc_html_e( 'You\'ll sign in on ovebot.ai and return here to finish setup.', 'ovebot-ai-chatbot-sales-agent' ); ?>
					</p>
				</div>
			</div>

			<!-- Step 2: Knowledge Base (pages) -->
			<div class="ovebotai-panel" data-panel="2" <?php echo 2 !== $ovebotai_initial_step ? 'style="display:none"' : ''; ?>>
				<h2><?php esc_html_e( 'Teach the AI chat agent about your website', 'ovebot-ai-chatbot-sales-agent' ); ?></h2>
				<p class="ovebotai-lead">
					<?php esc_html_e( 'Select the pages below (e.g. About, FAQ, Shipping & Returns). Your AI agent will read them and use that information to answer your customers\' questions accurately, live in the on-site chat.', 'ovebot-ai-chatbot-sales-agent' ); ?>
				</p>

				<!-- Summary notice for a KB sync attempt - e.g. the API's own
				     "knowledge base limit reached" message (item 8/9). -->
				<div class="ovebotai-notice ovebotai-notice-warning" id="oveKbNotice" style="display:none"></div>

				<?php if ( empty( $pages ) ) : ?>
				<p class="ovebotai-muted"><?php esc_html_e( 'No published pages found.', 'ovebot-ai-chatbot-sales-agent' ); ?></p>
				<?php else : ?>
				<div class="ovebotai-pages-list">
					<?php foreach ( $pages as $page ) : ?>
					<label class="ovebotai-page-item" data-page-id="<?php echo esc_attr( $page['id'] ); ?>">
						<input type="checkbox"
							name="kb_pages[]"
							value="<?php echo esc_attr( $page['id'] ); ?>"
							<?php checked( $page['checked'] ); ?>>
						<span class="ovebotai-checkbox-mark" aria-hidden="true"></span>
						<!-- Inline per-page sync result (item 10): filled in by setup.js
						     after a KB sync - red on failure/quota, green ("Updated") on
						     success within an otherwise-imperfect attempt. -->
						<span class="ovebotai-page-msg" aria-hidden="true"></span>
						<div class="ovebotai-page-info">
							<span class="ovebotai-page-title"><?php echo esc_html( $page['title'] ); ?></span>
							<a href="<?php echo esc_url( get_permalink( $page['id'] ) ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								class="ovebotai-page-url"
								onclick="event.stopPropagation()">
								<?php echo esc_html( str_replace( home_url(), '', get_permalink( $page['id'] ) ) ); ?>
								<span class="dashicons dashicons-external ovebotai-ext-icon" aria-hidden="true"></span>
							</a>
						</div>
					</label>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( $ovebotai_wc_active ) : ?>
			<!-- Step 3: Products (only reachable when WooCommerce is active) -->
			<div class="ovebotai-panel" data-panel="3" <?php echo 3 !== $ovebotai_initial_step ? 'style="display:none"' : ''; ?>>
				<h2><?php esc_html_e( 'Teach the AI chat agent about your products', 'ovebot-ai-chatbot-sales-agent' ); ?></h2>
				<p class="ovebotai-lead" id="oveProductMsg">
					<?php esc_html_e( 'Checking how many products can be sent to your AI agent…', 'ovebot-ai-chatbot-sales-agent' ); ?>
				</p>

				<div class="ovebotai-radio-list">
					<label class="ovebotai-radio-item">
						<input type="radio" name="products_source" value="auto" <?php checked( 'own' !== $ovebotai_products_source ); ?>>
						<span class="ovebotai-radio-mark" aria-hidden="true"></span>
						<div class="ovebotai-radio-info">
							<span class="ovebotai-radio-title"><?php esc_html_e( 'Use the built-in feed', 'ovebot-ai-chatbot-sales-agent' ); ?></span>
							<p class="description">
								<?php esc_html_e( 'Only products currently in stock are sent to Ovebot.ai.', 'ovebot-ai-chatbot-sales-agent' ); ?>
							</p>
						</div>
					</label>

					<label class="ovebotai-radio-item">
						<input type="radio" name="products_source" value="own" <?php checked( 'own' === $ovebotai_products_source ); ?>>
						<span class="ovebotai-radio-mark" aria-hidden="true"></span>
						<div class="ovebotai-radio-info">
							<span class="ovebotai-radio-title"><?php esc_html_e( 'I\'ll provide my own feed', 'ovebot-ai-chatbot-sales-agent' ); ?></span>
							<p class="description">
								<?php
								if ( $ovebotai_agent_settings_url ) {
									printf(
										/* translators: %s: "Ovebot.ai account" link to the agent's product settings on Ovebot.ai */
										wp_kses_post( __( 'Set up a compatible feed URL (e.g. Google Merchant) directly in your %s.', 'ovebot-ai-chatbot-sales-agent' ) ),
										sprintf(
											'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
											esc_url( $ovebotai_agent_settings_url ),
											esc_html__( 'Ovebot.ai account', 'ovebot-ai-chatbot-sales-agent' )
										)
									);
								} else {
									esc_html_e( 'Set up a compatible feed URL (e.g. Google Merchant) directly in your Ovebot.ai account.', 'ovebot-ai-chatbot-sales-agent' );
								}
								?>
							</p>
						</div>
					</label>
				</div>
			</div>
			<?php endif; ?>

			<!-- Step 4: Sync / Done -->
			<div class="ovebotai-panel" data-panel="4" <?php echo 4 !== $ovebotai_initial_step ? 'style="display:none"' : ''; ?>>
				<div class="ovebotai-sync-idle" id="oveSyncIdle">
					<h2><?php esc_html_e( 'Ready to go live', 'ovebot-ai-chatbot-sales-agent' ); ?></h2>
					<p class="ovebotai-lead">
						<?php esc_html_e( 'Everything is set. Click below to send the selected pages and products to Ovebot.ai - your AI chat agent will start using them right away.', 'ovebot-ai-chatbot-sales-agent' ); ?>
					</p>
				</div>
				<div class="ovebotai-sync-loading" id="oveSyncLoading" style="display:none">
					<div class="ovebotai-spinner-wrap">
						<span class="ovebotai-spinner"></span>
						<p id="oveSyncStatus"><?php esc_html_e( 'Syncing with Ovebot.ai…', 'ovebot-ai-chatbot-sales-agent' ); ?></p>
					</div>
				</div>
				<div class="ovebotai-sync-done" id="oveSyncDone" style="display:none">
					<div class="ovebotai-done-icon"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span></div>
					<h2><?php esc_html_e( 'All done!', 'ovebot-ai-chatbot-sales-agent' ); ?></h2>
					<p class="ovebotai-lead">
						<?php esc_html_e( 'Your store is now connected to Ovebot.ai. Your AI agent is live on your website right now, ready to chat with customers, recommend products and answer order-status questions.', 'ovebot-ai-chatbot-sales-agent' ); ?>
					</p>
					<p class="ovebotai-lead ovebotai-done-tip">
						<?php
						if ( $ovebotai_agent_settings_url ) {
							printf(
								/* translators: %s: "here" link to the agent's settings on Ovebot.ai */
								wp_kses_post( __( 'Setup finished successfully - we still recommend checking the settings in your Ovebot.ai account for the selected agent, available %s. You\'ll also find more settings and customizations there that may be useful.', 'ovebot-ai-chatbot-sales-agent' ) ),
								sprintf(
									'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
									esc_url( $ovebotai_agent_settings_url ),
									esc_html__( 'here', 'ovebot-ai-chatbot-sales-agent' )
								)
							);
						} else {
							esc_html_e( 'Setup finished successfully - we still recommend checking the settings in your Ovebot.ai account for the selected agent. You\'ll also find more settings and customizations there that may be useful.', 'ovebot-ai-chatbot-sales-agent' );
						}
						?>
					</p>
					<div class="ovebotai-notice ovebotai-notice-warning" id="oveSyncWarnings" style="display:none"></div>
					<div class="ovebotai-done-actions">
						<a href="<?php echo esc_url( add_query_arg( 'view', 'settings', admin_url( 'admin.php?page=ovebotai' ) ) ); ?>" class="button ovebotai-btn-muted">
							<?php esc_html_e( 'Go to settings', 'ovebot-ai-chatbot-sales-agent' ); ?>
						</a>
						<a href="<?php echo esc_url( add_query_arg( 'ocw-fab-open', 'true', home_url( '/' ) ) ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Chat with the AI agent', 'ovebot-ai-chatbot-sales-agent' ); ?> <span aria-hidden="true">&rarr;</span>
						</a>
					</div>
				</div>
				<div class="ovebotai-sync-error" id="oveSyncError" style="display:none">
					<div class="ovebotai-notice ovebotai-notice-error" id="oveSyncErrorMsg"></div>
				</div>
			</div>

		</div><!-- /.ovebotai-panels -->

		<!-- Navigation -->
		<div class="ovebotai-setup-nav" id="oveSetupNav">
			<button type="button" class="button" id="ovePrevBtn" style="display:none">
				<span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Previous', 'ovebot-ai-chatbot-sales-agent' ); ?>
			</button>
			<?php $ovebotai_next_hidden = 1 === $ovebotai_initial_step && ! $ovebotai_is_connected; ?>
			<!-- Label (and its trailing arrow) is set by setup.js on render; this
			     is just the pre-JS fallback. -->
			<button type="button" class="button button-primary" id="oveNextBtn" <?php echo $ovebotai_next_hidden ? 'style="display:none"' : ''; ?>>
				<?php esc_html_e( 'Next', 'ovebot-ai-chatbot-sales-agent' ); ?> <span aria-hidden="true">&rarr;</span>
			</button>
		</div>

	</div><!-- /.ovebotai-setup-card -->
</div><!-- /.wrap -->
