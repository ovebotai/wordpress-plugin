<?php
defined( 'ABSPATH' ) || exit;

$ovebotai_oauth        = Ovebotai_OAuth::instance();
$ovebotai_workspace    = $ovebotai_oauth->get_workspace();
$ovebotai_is_connected = $ovebotai_oauth->is_connected();

// Live product count from Ovebot.ai's side (how many products it actually has
// indexed for this agent) — not the local feed_count, which is what we send.
// There's no product feed at all without WooCommerce, so skip entirely.
$ovebotai_wc_active      = Ovebotai::woocommerce_active();
$ovebotai_products_count = 0;
if ( $ovebotai_wc_active && $ovebotai_is_connected ) {
	$ovebotai_status_result = $ovebotai_oauth->api_request( 'GET', '/v1/integration/status' );
	if ( ( $ovebotai_status_result['status'] ?? 0 ) >= 200 && ( $ovebotai_status_result['status'] ?? 0 ) < 300 ) {
		$ovebotai_products_count = (int) ( $ovebotai_status_result['body']['integration']['counts']['products'] ?? 0 );
	}
}

$ovebotai_account_url  = $ovebotai_workspace ? 'https://' . $ovebotai_workspace . '.ovebot.ai' : '';
$ovebotai_products_url = $ovebotai_workspace ? 'https://' . $ovebotai_workspace . '.ovebot.ai/products' : '';
$ovebotai_chat_url    = add_query_arg( 'ocw-fab-open', 'true', home_url( '/' ) );
$ovebotai_settings_url = add_query_arg( 'view', 'settings', admin_url( 'admin.php?page=ovebotai' ) );

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
<div class="wrap ovebotai-wrap">

	<div class="ovebotai-settings-header">
		<div class="ovebotai-logo">
			<a href="https://ovebot.ai" target="_blank" rel="noopener noreferrer">
				<img src="<?php echo esc_url( OVEBOTAI_URL . 'admin/img/logo.png' ); ?>" alt="Ovebot.ai" height="32">
			</a>
			<h1><?php esc_html_e( 'Dashboard', 'ovebotai' ); ?></h1>
		</div>
		<?php require OVEBOTAI_DIR . 'admin/views/partials/connection-badge.php'; ?>
	</div>

	<div class="ovebotai-dashboard-cards">
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $ovebotai_chat_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="ovebotai-dash-card-icon dashicons dashicons-format-chat" aria-hidden="true"></span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Chat with the AI agent', 'ovebotai' ); ?></span>
		</a>
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $ovebotai_settings_url ); ?>">
			<span class="ovebotai-dash-card-icon dashicons dashicons-admin-generic" aria-hidden="true"></span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Settings', 'ovebotai' ); ?></span>
		</a>
		<?php if ( $ovebotai_account_url ) : ?>
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $ovebotai_account_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="ovebotai-dash-card-icon dashicons dashicons-external" aria-hidden="true"></span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Ovebot.ai account', 'ovebotai' ); ?></span>
		</a>
		<?php endif; ?>
	</div>

	<?php if ( $ovebotai_wc_active ) : ?>
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
	<?php endif; ?>

	<div class="ovebotai-fieldset">
		<div class="ovebotai-fieldset-legend">
			<span class="dashicons dashicons-book" aria-hidden="true"></span>
			<?php esc_html_e( 'Knowledge Bases', 'ovebotai' ); ?>
		</div>
		<div class="ovebotai-fieldset-body">

			<p class="description ovebotai-fieldset-intro"><?php esc_html_e( 'These are the pages your AI agent currently reads from to answer customer questions in the chat.', 'ovebotai' ); ?></p>

			<?php if ( $ovebotai_kb_error ) : ?>
			<div class="ovebotai-notice ovebotai-notice-warning"><p><?php echo esc_html( $ovebotai_kb_error ); ?></p></div>
			<?php elseif ( empty( $ovebotai_kb_entries ) ) : ?>
			<p class="ovebotai-muted"><?php esc_html_e( 'No knowledge base entries yet — run the setup wizard to choose website pages for your AI agent.', 'ovebotai' ); ?></p>
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

</div><!-- /.wrap -->
