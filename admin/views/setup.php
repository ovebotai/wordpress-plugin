<?php
defined( 'ABSPATH' ) || exit;

$oauth = Ovebotai_OAuth::instance();
$admin = Ovebotai_Admin::instance();

$pages         = $admin->get_pages_for_kb();
$is_connected  = $oauth->is_connected();
$wc_active     = Ovebotai::woocommerce_active();

// Steps present in this flow — Products KB only exists when WooCommerce is active.
// Checked live on every load, never cached.
$steps_seq = $wc_active ? array( 1, 2, 3, 4 ) : array( 1, 2, 4 );

// Detect initial step, clamped to one that actually exists in this flow.
$initial_step = isset( $_GET['step'] ) ? (int) $_GET['step'] : ( $is_connected ? 2 : 1 );
if ( ! in_array( $initial_step, $steps_seq, true ) ) {
	$initial_step = $steps_seq[0];
}
$oauth_error  = isset( $_GET['oauth_error'] ) ? sanitize_text_field( wp_unslash( $_GET['oauth_error'] ) ) : '';
?>
<div class="wrap ovebotai-wrap">
	<div class="ovebotai-setup-card">

		<!-- Header -->
		<div class="ovebotai-setup-header">
			<div class="ovebotai-logo">
				<img src="<?php echo esc_url( OVEBOTAI_URL . 'admin/img/logo.png' ); ?>" alt="Ovebot.ai" height="36">
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
			$step_labels = array(
				1 => __( 'Connect', 'ovebotai' ),
				2 => __( 'General Knowledge Base', 'ovebotai' ),
				3 => __( 'Products Knowledge Base', 'ovebotai' ),
				4 => __( 'Finish', 'ovebotai' ),
			);
			$current_pos = array_search( $initial_step, $steps_seq, true );
			foreach ( $steps_seq as $pos => $num ) : ?>
			<div class="ovebotai-step-dot<?php echo $num === $initial_step ? ' is-active' : ''; ?><?php echo $pos < $current_pos ? ' is-done' : ''; ?>" data-step="<?php echo esc_attr( $num ); ?>">
				<div class="ovebotai-dot-circle">
					<span class="dot-num"><?php echo esc_html( $pos + 1 ); ?></span>
					<span class="dot-check">✓</span>
				</div>
				<span class="ovebotai-dot-label"><?php echo esc_html( $step_labels[ $num ] ); ?></span>
			</div>
			<?php endforeach; ?>
			</div>
		</div>

		<!-- Step panels -->
		<div class="ovebotai-panels">

			<!-- Step 1: Connect -->
			<div class="ovebotai-panel" data-panel="1" <?php echo 1 !== $initial_step ? 'style="display:none"' : ''; ?>>
				<h2><?php esc_html_e( 'Connect your store to Ovebot.ai', 'ovebotai' ); ?></h2>
				<p class="ovebotai-lead">
					<?php esc_html_e( 'Log in to your Ovebot.ai account and grant access to this store. You will be redirected to ovebot.ai and back.', 'ovebotai' ); ?>
				</p>

				<?php if ( $oauth_error ) : ?>
				<div class="ovebotai-notice ovebotai-notice-error">
					<p><?php echo esc_html( $oauth_error ); ?></p>
				</div>
				<?php endif; ?>

				<div class="ovebotai-connect-box">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ovebotai_connect">
						<?php wp_nonce_field( 'ovebotai_connect' ); ?>
						<button type="submit" class="button ovebotai-btn-connect">
							<?php esc_html_e( 'Connect with Ovebot.ai →', 'ovebotai' ); ?>
						</button>
					</form>
					<div class="ovebotai-register-hint">
						<?php esc_html_e( "Don't have an account?", 'ovebotai' ); ?>
						<a href="https://account.ovebot.ai/ro/register" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Create an account →', 'ovebotai' ); ?>
						</a>
					</div>
				</div>
			</div>

			<!-- Step 2: Knowledge Base (pages) -->
			<div class="ovebotai-panel" data-panel="2" <?php echo 2 !== $initial_step ? 'style="display:none"' : ''; ?>>
				<h2><?php esc_html_e( 'General knowledge base', 'ovebotai' ); ?></h2>
				<p class="ovebotai-lead">
					<?php esc_html_e( 'The following pages will be used by the chat to provide accurate information to your customers. Select the ones you want to include.', 'ovebotai' ); ?>
				</p>

				<?php if ( empty( $pages ) ) : ?>
				<p class="ovebotai-muted"><?php esc_html_e( 'No published pages found.', 'ovebotai' ); ?></p>
				<?php else : ?>
				<div class="ovebotai-pages-list">
					<?php foreach ( $pages as $page ) : ?>
					<label class="ovebotai-page-item">
						<input type="checkbox"
							name="kb_pages[]"
							value="<?php echo esc_attr( $page['id'] ); ?>"
							<?php checked( $page['checked'] ); ?>>
						<span class="ovebotai-checkbox-mark" aria-hidden="true"></span>
						<div class="ovebotai-page-info">
							<span class="ovebotai-page-title"><?php echo esc_html( $page['title'] ); ?></span>
							<a href="<?php echo esc_url( get_permalink( $page['id'] ) ); ?>"
								target="_blank"
								rel="noopener noreferrer"
								class="ovebotai-page-url"
								onclick="event.stopPropagation()">
								<?php echo esc_html( str_replace( home_url(), '', get_permalink( $page['id'] ) ) ); ?>
								<span class="ovebotai-ext-icon">↗</span>
							</a>
						</div>
					</label>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( $wc_active ) : ?>
			<!-- Step 3: Products (only reachable when WooCommerce is active) -->
			<div class="ovebotai-panel" data-panel="3" <?php echo 3 !== $initial_step ? 'style="display:none"' : ''; ?>>
				<h2><?php esc_html_e( 'Products knowledge base', 'ovebotai' ); ?></h2>
				<p class="ovebotai-lead" id="oveProductMsg">
					<?php esc_html_e( 'Loading product counts…', 'ovebotai' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<!-- Step 4: Sync / Done -->
			<div class="ovebotai-panel" data-panel="4" <?php echo 4 !== $initial_step ? 'style="display:none"' : ''; ?>>
				<div class="ovebotai-sync-idle" id="oveSyncIdle">
					<h2><?php esc_html_e( 'Ready to sync', 'ovebotai' ); ?></h2>
					<p class="ovebotai-lead">
						<?php esc_html_e( 'Everything is set. Click the button below to send your pages and products to Ovebot.ai.', 'ovebotai' ); ?>
					</p>
				</div>
				<div class="ovebotai-sync-loading" id="oveSyncLoading" style="display:none">
					<div class="ovebotai-spinner-wrap">
						<span class="ovebotai-spinner"></span>
						<p id="oveSyncStatus"><?php esc_html_e( 'Syncing with Ovebot.ai…', 'ovebotai' ); ?></p>
					</div>
				</div>
				<div class="ovebotai-sync-done" id="oveSyncDone" style="display:none">
					<div class="ovebotai-done-icon">✓</div>
					<h2><?php esc_html_e( 'All done!', 'ovebotai' ); ?></h2>
					<p class="ovebotai-lead">
						<?php esc_html_e( 'Your store is now connected to Ovebot.ai.', 'ovebotai' ); ?>
						<?php esc_html_e( 'Your AI assistant is now live on your website, ready to help your customers.', 'ovebotai' ); ?>
					</p>
					<div class="ovebotai-done-actions">
						<a href="<?php echo esc_url( add_query_arg( 'view', 'settings', admin_url( 'admin.php?page=ovebotai' ) ) ); ?>" class="button ovebotai-btn-muted">
							<?php esc_html_e( 'More settings', 'ovebotai' ); ?>
						</a>
						<a href="<?php echo esc_url( add_query_arg( 'ocw-fab-open', 'true', home_url( '/' ) ) ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Chat with the AI agent →', 'ovebotai' ); ?>
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
				<?php esc_html_e( '← Previous', 'ovebotai' ); ?>
			</button>
			<?php $next_hidden = 1 === $initial_step && ! $is_connected; ?>
			<button type="button" class="button button-primary" id="oveNextBtn" <?php echo $next_hidden ? 'style="display:none"' : ''; ?>>
				<?php esc_html_e( 'Next →', 'ovebotai' ); ?>
			</button>
		</div>

	</div><!-- /.ovebotai-setup-card -->
</div><!-- /.wrap -->
