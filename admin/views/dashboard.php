<?php
defined( 'ABSPATH' ) || exit;

$oauth        = Ovebotai_OAuth::instance();
$workspace    = $oauth->get_workspace();
$is_connected = $oauth->is_connected();

// Live product count from Ovebot.ai's side (how many products it actually has
// indexed for this agent) — not the local feed_count, which is what we send.
$products_count = 0;
if ( $is_connected ) {
	$status_result = $oauth->api_request( 'GET', '/v1/integration/status' );
	if ( ( $status_result['status'] ?? 0 ) >= 200 && ( $status_result['status'] ?? 0 ) < 300 ) {
		$products_count = (int) ( $status_result['body']['integration']['counts']['products'] ?? 0 );
	}
}

$account_url  = $workspace ? 'https://' . $workspace . '.ovebot.ai' : '';
$products_url = $workspace ? 'https://' . $workspace . '.ovebot.ai/products' : '';
$chat_url    = add_query_arg( 'ocw-fab-open', 'true', home_url( '/' ) );
$settings_url = add_query_arg( 'view', 'settings', admin_url( 'admin.php?page=ovebotai' ) );

// Pulled live from Ovebot.ai — this is the actual state of the agent's
// knowledge base, not just what this site has attempted to sync.
$kb_entries = array();
$kb_error   = '';

if ( $is_connected && $workspace ) {
	$result = $oauth->api_request( 'GET', $oauth->kb_api_path() . '?per_page=100' );
	$status = $result['status'] ?? 0;

	if ( $status >= 200 && $status < 300 ) {
		foreach ( (array) ( $result['body']['entries'] ?? array() ) as $entry ) {
			if ( empty( $entry['id'] ) ) continue;

			$kb_entries[] = array(
				'title'     => (string) ( $entry['title'] ?? '' ),
				'is_active' => ! empty( $entry['is_active'] ),
				'edit_url'  => 'https://' . $workspace . '.ovebot.ai/knowledge-base/' . (int) $entry['id'] . '/edit',
			);
		}
	} else {
		$kb_error = __( 'Could not load knowledge base entries from Ovebot.ai.', 'ovebotai' );
	}
}
?>
<div class="wrap ovebotai-wrap">

	<div class="ovebotai-settings-header">
		<div class="ovebotai-logo">
			<img src="<?php echo esc_url( OVEBOTAI_URL . 'admin/img/logo.png' ); ?>" alt="Ovebot.ai" height="32">
			<h1><?php esc_html_e( 'Ovebot.ai', 'ovebotai' ); ?></h1>
		</div>
		<?php require OVEBOTAI_DIR . 'admin/views/partials/connection-badge.php'; ?>
	</div>

	<div class="ovebotai-dashboard-cards">
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $chat_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="ovebotai-dash-card-icon">💬</span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Chat with the AI agent', 'ovebotai' ); ?></span>
		</a>
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $settings_url ); ?>">
			<span class="ovebotai-dash-card-icon">⚙️</span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Manual settings', 'ovebotai' ); ?></span>
		</a>
		<?php if ( $account_url ) : ?>
		<a class="ovebotai-dash-card" href="<?php echo esc_url( $account_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="ovebotai-dash-card-icon">↗</span>
			<span class="ovebotai-dash-card-title"><?php esc_html_e( 'Ovebot.ai account', 'ovebotai' ); ?></span>
		</a>
		<?php endif; ?>
	</div>

	<div class="ovebotai-dash-card-wide">
		<span class="ovebotai-dash-card-wide-label"><?php esc_html_e( 'Products', 'ovebotai' ); ?></span>
		<?php
		$count_classes = 'ovebotai-dash-card-wide-count ' . ( $products_count > 0 ? 'is-positive' : 'is-zero' );
		$count_html    = esc_html( number_format_i18n( $products_count ) );
		?>
		<?php if ( $products_url ) : ?>
		<a class="<?php echo esc_attr( $count_classes ); ?>" href="<?php echo esc_url( $products_url ); ?>" target="_blank" rel="noopener noreferrer">
			<?php echo $count_html; ?>
		</a>
		<?php else : ?>
		<span class="<?php echo esc_attr( $count_classes ); ?>"><?php echo $count_html; ?></span>
		<?php endif; ?>
	</div>

	<div class="ovebotai-fieldset">
		<div class="ovebotai-fieldset-legend"><?php esc_html_e( 'Knowledge Bases', 'ovebotai' ); ?></div>
		<div class="ovebotai-fieldset-body">

			<?php if ( $kb_error ) : ?>
			<div class="ovebotai-notice ovebotai-notice-warning"><p><?php echo esc_html( $kb_error ); ?></p></div>
			<?php elseif ( empty( $kb_entries ) ) : ?>
			<p class="ovebotai-muted"><?php esc_html_e( 'No knowledge base entries yet.', 'ovebotai' ); ?></p>
			<?php else : ?>
			<div class="ovebotai-pages-list ovebotai-kb-list">
				<?php foreach ( $kb_entries as $entry ) : ?>
				<div class="ovebotai-page-item">
					<div class="ovebotai-page-info">
						<span class="ovebotai-page-title"><?php echo esc_html( $entry['title'] ); ?></span>
						<?php if ( ! $entry['is_active'] ) : ?>
						<span class="ovebotai-lock-badge"><?php esc_html_e( 'Inactive', 'ovebotai' ); ?></span>
						<?php endif; ?>
					</div>
					<a href="<?php echo esc_url( $entry['edit_url'] ); ?>"
						target="_blank"
						rel="noopener noreferrer"
						class="ovebotai-page-url">
						<?php esc_html_e( 'Edit on Ovebot.ai', 'ovebotai' ); ?>
						<span class="ovebotai-ext-icon">↗</span>
					</a>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

		</div>
	</div>

</div><!-- /.wrap -->
