<?php
defined( 'ABSPATH' ) || exit;

$ovebotai_oauth        = Ovebotai_OAuth::instance();
// Live check, not just local state — this is what actually invalidates the
// tokens in the DB (via api_request()'s proactive refresh) when the 30-day
// refresh token has lapsed server-side. Doing this before anything else
// renders means a lapsed connection shows the reconnect panel on this same
// load, instead of a dashboard full of cards/KB entries that no longer work.
$ovebotai_is_connected = $ovebotai_oauth->is_connected_live();
$ovebotai_workspace    = $ovebotai_oauth->get_workspace();

// Single memoized integration fetch (task 8): the account's live status for this
// agent - the indexed product count, whether product recommendation is on, and
// whether order lookup is on. Ovebotai::sync_settings() (called by the controller
// just before this view renders) already reconciled the local order-API switch
// from this same response, so no extra call is spent here.
$ovebotai_wc_active   = Ovebotai::woocommerce_active();
$ovebotai_integration = $ovebotai_is_connected ? $ovebotai_oauth->get_integration() : null;

$ovebotai_products_count = (int) ( $ovebotai_integration['counts']['products'] ?? 0 );

// Default to "on" whenever the flag is missing or the status call failed, so a
// transient error never flashes a scary "recommendation is off" / "order tracking
// is off" warning at the merchant (tasks 9 + 10).
$ovebotai_recommend_on = ! is_array( $ovebotai_integration )
	|| ! array_key_exists( 'products', $ovebotai_integration )
	|| ! empty( $ovebotai_integration['products'] );
$ovebotai_order_api_on = ! is_array( $ovebotai_integration )
	|| ! array_key_exists( 'order_info', $ovebotai_integration )
	|| ! empty( $ovebotai_integration['order_info'] );

$ovebotai_account_url  = $ovebotai_workspace ? 'https://' . $ovebotai_workspace . '.ovebot.ai' : '';
$ovebotai_products_url = $ovebotai_workspace ? 'https://' . $ovebotai_workspace . '.ovebot.ai/products' : '';
$ovebotai_kb_create_url = $ovebotai_workspace ? 'https://' . $ovebotai_workspace . '.ovebot.ai/knowledge-base/create' : '';
$ovebotai_chat_url    = add_query_arg( 'ocw-fab-open', 'true', home_url( '/' ) );
$ovebotai_settings_url = add_query_arg( 'view', 'settings', admin_url( 'admin.php?page=ovebotai' ) );
// The connected agent's setup page on Ovebot.ai - target for the "advanced
// settings" panel (task 2) and the two status warnings (tasks 9 + 10).
$ovebotai_agent_settings_url = $ovebotai_oauth->get_agent_settings_url();

// Pulled live from Ovebot.ai — this is the actual state of the agent's
// knowledge base, not just what this site has attempted to sync.
$ovebotai_kb_entries = array();
$ovebotai_kb_error   = '';

if ( $ovebotai_is_connected && $ovebotai_workspace ) {
	$ovebotai_result = $ovebotai_oauth->api_request( 'GET', $ovebotai_oauth->kb_api_path() . '?per_page=100' );
	$ovebotai_status = $ovebotai_result['status'] ?? 0;

	if ( $ovebotai_status >= 200 && $ovebotai_status < 300 ) {
		foreach ( (array) ( $ovebotai_result['body']['entries'] ?? array() ) as $ovebotai_entry ) {
			if ( empty( $ovebotai_entry['id'] ) ) continue;

			$ovebotai_kb_entries[] = array(
				'title'     => (string) ( $ovebotai_entry['title'] ?? '' ),
				'is_active' => ! empty( $ovebotai_entry['is_active'] ),
				'edit_url'  => 'https://' . $ovebotai_workspace . '.ovebot.ai/knowledge-base/' . (int) $ovebotai_entry['id'] . '/edit',
			);
		}
	} else {
		$ovebotai_kb_error = __( 'Could not load knowledge base entries from Ovebot.ai.', 'ovebotai' );
	}
}
?>
<hr class="wp-header-end">
<div class="wrap ovebotai-wrap">

	<div class="ovebotai-settings-header">
		<div class="ovebotai-logo">
			<a href="https://ovebot.ai" target="_blank" rel="noopener noreferrer">
				<img src="<?php echo esc_url( OVEBOTAI_URL . 'admin/img/logo.png' ); ?>" alt="Ovebot.ai" height="32">
			</a>
			<h1><?php esc_html_e( 'Dashboard', 'ovebotai' ); ?></h1>
			<?php require OVEBOTAI_DIR . 'admin/views/partials/version-badge.php'; ?>
		</div>
		<?php require OVEBOTAI_DIR . 'admin/views/partials/connection-badge.php'; ?>
	</div>

	<?php if ( ! $ovebotai_is_connected ) : ?>

	<div class="ovebotai-setup-card">
		<div class="ovebotai-panels">
			<div class="ovebotai-panel" data-panel="1">
				<h2><?php esc_html_e( 'Reconnect your store to Ovebot.ai', 'ovebotai' ); ?></h2>
				<p class="ovebotai-lead">
					<?php esc_html_e( 'Your connection to Ovebot.ai has expired or was revoked. Your AI chat agent, product feed and order tracking may stop updating until you reconnect.', 'ovebotai' ); ?>
				</p>
				<div class="ovebotai-connect-box">
					<div class="ovebotai-connect-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ovebotai_connect">
							<?php wp_nonce_field( 'ovebotai_connect' ); ?>
							<button type="submit" class="button ovebotai-btn-connect">
								<?php esc_html_e( 'Connect with an existing account →', 'ovebotai' ); ?>
							</button>
						</form>
						<a href="<?php echo esc_url( Ovebotai_OAuth::get_register_url() ); ?>" target="_blank" rel="noopener noreferrer" class="button ovebotai-btn-trial">
							<?php esc_html_e( 'Start Free →', 'ovebotai' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php else : ?>

	<div class="ovebotai-dashboard-cards">
		<?php if ( $ovebotai_account_url ) : ?>
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $ovebotai_account_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="ovebotai-dash-card-icon dashicons dashicons-external" aria-hidden="true"></span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Ovebot.ai account', 'ovebotai' ); ?></span>
		</a>
		<?php endif; ?>
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $ovebotai_settings_url ); ?>">
			<span class="ovebotai-dash-card-icon dashicons dashicons-admin-generic" aria-hidden="true"></span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Settings', 'ovebotai' ); ?></span>
		</a>
		<?php if ( get_option( 'ovebotai_chat_status' ) === '1' ) : ?>
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $ovebotai_chat_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="ovebotai-dash-card-icon dashicons dashicons-format-chat" aria-hidden="true"></span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Chat with the AI agent', 'ovebotai' ); ?></span>
		</a>
		<?php else : ?>
		<!-- Chat widget is turned off (item 11): the card links to Settings with a
		     highlight deep-link pointing at the chat on/off switch, and is styled
		     distinctly (dashed/muted) so it doesn't read as a normal live action. -->
		<a class="ovebotai-dash-card is-disabled" href="<?php echo esc_url( add_query_arg( 'highlight', 'chat_status', $ovebotai_settings_url ) ); ?>">
			<span class="ovebotai-dash-card-icon dashicons dashicons-format-chat" aria-hidden="true"></span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Chat is disabled, enable it in settings', 'ovebotai' ); ?> <span aria-hidden="true">&rarr;</span></span>
		</a>
		<?php endif; ?>
	</div>

	<?php if ( $ovebotai_agent_settings_url ) : ?>
	<!-- Task 2: prominent nudge toward the account's advanced settings, right
	     under the three action cards. -->
	<a class="ovebotai-recommend-panel" href="<?php echo esc_url( $ovebotai_agent_settings_url ); ?>" target="_blank" rel="noopener noreferrer">
		<span class="ovebotai-recommend-panel-icon dashicons dashicons-admin-settings" aria-hidden="true"></span>
		<span class="ovebotai-recommend-panel-body">
			<span class="ovebotai-recommend-panel-title"><?php esc_html_e( 'Looking for advanced settings?', 'ovebotai' ); ?></span>
			<span class="ovebotai-recommend-panel-desc"><?php esc_html_e( 'Fine-tune your AI agent right from your Ovebot.ai account - that\'s where the advanced options and customizations live.', 'ovebotai' ); ?></span>
		</span>
		<span class="ovebotai-recommend-panel-cta"><?php esc_html_e( 'Open account settings', 'ovebotai' ); ?> <span aria-hidden="true">&rarr;</span></span>
	</a>
	<?php endif; ?>

	<?php if ( $ovebotai_wc_active ) : ?>

	<?php if ( $ovebotai_recommend_on ) : ?>
	<div class="ovebotai-dash-card-wide">
		<span class="ovebotai-dash-card-wide-label">
			<span class="dashicons dashicons-cart" aria-hidden="true"></span>
			<?php esc_html_e( 'Products indexed by the AI agent', 'ovebotai' ); ?>
		</span>
		<?php $ovebotai_count_classes = 'ovebotai-dash-card-wide-count ' . ( $ovebotai_products_count > 0 ? 'is-positive' : 'is-zero' ); ?>
		<?php if ( $ovebotai_products_url ) : ?>
		<a class="<?php echo esc_attr( $ovebotai_count_classes ); ?>" href="<?php echo esc_url( $ovebotai_products_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo esc_html( number_format_i18n( $ovebotai_products_count ) ); ?>
		</a>
		<?php else : ?>
		<span class="<?php echo esc_attr( $ovebotai_count_classes ); ?>"><?php echo esc_html( number_format_i18n( $ovebotai_products_count ) ); ?></span>
		<?php endif; ?>
	</div>
	<?php else : ?>
	<!-- Task 9: product recommendation switched off in the Ovebot.ai account -
	     the count card is replaced by a warning pointing at where to re-enable it. -->
	<div class="ovebotai-status-warning">
		<span class="dashicons dashicons-warning ovebotai-status-warning-icon" aria-hidden="true"></span>
		<span class="ovebotai-status-warning-body">
			<span class="ovebotai-status-warning-title"><?php esc_html_e( 'Product recommendations are turned off', 'ovebotai' ); ?></span>
			<span class="ovebotai-status-warning-desc">
				<?php
				printf(
					/* translators: %s: "here" link to this site's local Ovebot.ai settings page */
					wp_kses_post( __( 'Your AI agent is not recommending products right now. You can change the status %s.', 'ovebotai' ) ),
					sprintf( '<a href="%1$s">%2$s</a>', esc_url( add_query_arg( 'highlight', 'products_enabled', $ovebotai_settings_url ) ), esc_html__( 'here', 'ovebotai' ) )
				);
				?>
			</span>
		</span>
	</div>
	<?php endif; ?>

	<?php if ( ! $ovebotai_order_api_on ) : ?>
	<!-- Task 10: order lookup switched off in the account - a warning under the
	     products area, shown only when it's actually off. -->
	<div class="ovebotai-status-warning">
		<span class="dashicons dashicons-warning ovebotai-status-warning-icon" aria-hidden="true"></span>
		<span class="ovebotai-status-warning-body">
			<span class="ovebotai-status-warning-title"><?php esc_html_e( 'Order tracking is turned off', 'ovebotai' ); ?></span>
			<span class="ovebotai-status-warning-desc">
				<?php
				printf(
					/* translators: %s: "here" link to this site's local Ovebot.ai settings page */
					wp_kses_post( __( 'Your AI agent can\'t answer order-status questions while order tracking is off. You can change the status %s.', 'ovebotai' ) ),
					sprintf( '<a href="%1$s">%2$s</a>', esc_url( add_query_arg( 'highlight', 'order_api_status', $ovebotai_settings_url ) ), esc_html__( 'here', 'ovebotai' ) )
				);
				?>
			</span>
		</span>
	</div>
	<?php endif; ?>

	<?php endif; ?>

	<div class="ovebotai-fieldset">
		<div class="ovebotai-fieldset-legend">
			<span class="dashicons dashicons-book" aria-hidden="true"></span>
			<?php esc_html_e( 'Knowledge Bases', 'ovebotai' ); ?>
		</div>
		<div class="ovebotai-fieldset-body">

			<p class="description ovebotai-fieldset-intro"><?php esc_html_e( 'These are the resources your AI agent uses to answer customer questions in the chat. Editing one of these pages in WordPress automatically updates its entry here within a few minutes - or use "Edit on Ovebot.ai" below to change it directly.', 'ovebotai' ); ?></p>

			<?php if ( $ovebotai_kb_error ) : ?>
			<div class="ovebotai-notice ovebotai-notice-warning"><p><?php echo esc_html( $ovebotai_kb_error ); ?></p></div>
			<?php elseif ( empty( $ovebotai_kb_entries ) ) : ?>
			<p class="ovebotai-muted">
				<?php
				printf(
					/* translators: %s: "add one here" link to Ovebot.ai's knowledge-base creation page */
					wp_kses_post( __( 'No knowledge base entries yet - %s.', 'ovebotai' ) ),
					sprintf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
						esc_url( $ovebotai_kb_create_url ),
						esc_html__( 'add one here', 'ovebotai' )
					)
				);
				?>
			</p>
			<?php else : ?>
			<?php
			$ovebotai_kb_active_count = count( array_filter( $ovebotai_kb_entries, function( $e ) { return $e['is_active']; } ) );
			?>
			<p class="ovebotai-muted ovebotai-kb-summary">
				<?php
				printf(
					/* translators: 1: active entries, 2: total entries */
					esc_html__( '%1$d of %2$d entries active', 'ovebotai' ),
					(int) $ovebotai_kb_active_count,
					count( $ovebotai_kb_entries )
				);
				?>
			</p>
			<div class="ovebotai-pages-list ovebotai-kb-list">
				<?php foreach ( $ovebotai_kb_entries as $ovebotai_entry ) : ?>
				<div class="ovebotai-page-item">
					<div class="ovebotai-page-info">
						<span class="ovebotai-page-title"><?php echo esc_html( $ovebotai_entry['title'] ); ?></span>
					</div>
					<div class="ovebotai-kb-item-actions">
						<?php if ( $ovebotai_entry['is_active'] ) : ?>
						<span class="ovebotai-status-badge is-active" title="<?php esc_attr_e( 'Visible to your AI agent', 'ovebotai' ); ?>"><?php esc_html_e( 'Active', 'ovebotai' ); ?></span>
						<?php else : ?>
						<span class="ovebotai-status-badge is-inactive" title="<?php esc_attr_e( 'Not currently visible to your AI agent', 'ovebotai' ); ?>"><?php esc_html_e( 'Inactive', 'ovebotai' ); ?></span>
						<?php endif; ?>
						<a href="<?php echo esc_url( $ovebotai_entry['edit_url'] ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="ovebotai-page-url">
							<?php esc_html_e( 'Edit on Ovebot.ai', 'ovebotai' ); ?>
							<span class="dashicons dashicons-external ovebotai-ext-icon" aria-hidden="true"></span>
						</a>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

		</div>
	</div>

	<?php endif; ?>

</div><!-- /.wrap -->
