<?php
defined( 'ABSPATH' ) || exit;

// Expects $ovebotai_oauth, $ovebotai_workspace, $ovebotai_is_connected from
// the including view — declared here too so this partial degrades safely
// (shows "Not connected") instead of throwing undefined-variable warnings
// if it's ever required without them being set.
$ovebotai_oauth        = $ovebotai_oauth ?? Ovebotai_OAuth::instance();
$ovebotai_workspace    = $ovebotai_workspace ?? $ovebotai_oauth->get_workspace();
$ovebotai_is_connected = $ovebotai_is_connected ?? $ovebotai_oauth->is_connected();

// {workspace}:{agent-id-or-"default"} — get_agent() surfaces the default agent
// (stored as '') as the literal "default" (item 4).
$ovebotai_badge_label = $ovebotai_workspace . ':' . $ovebotai_oauth->get_agent();
?>
<div class="ovebotai-connection-badge <?php echo $ovebotai_is_connected ? 'is-ok' : 'is-warn'; ?>">
	<span class="ovebotai-status-dot <?php echo $ovebotai_is_connected ? 'is-connected' : 'is-disconnected'; ?>"></span>
	<?php if ( $ovebotai_is_connected ) : ?>
		<?php /* translators: %s: workspace and agent, e.g. "acme:default" */ printf( esc_html__( 'Connected: %s', 'ovebotai' ), '<strong>' . esc_html( $ovebotai_badge_label ) . '</strong>' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:12px">
			<input type="hidden" name="action" value="ovebotai_disconnect">
			<?php wp_nonce_field( 'ovebotai_disconnect' ); ?>
			<button type="submit" class="button button-link ovebotai-disconnect-btn"
				onclick="return confirm('<?php echo esc_js( __( 'Disconnect from Ovebot.ai? You will need to reconnect and run setup again.', 'ovebotai' ) ); ?>')">
				<?php esc_html_e( 'Disconnect', 'ovebotai' ); ?>
			</button>
		</form>
	<?php else : ?>
		<?php esc_html_e( 'Not connected', 'ovebotai' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:12px">
			<input type="hidden" name="action" value="ovebotai_connect">
			<?php wp_nonce_field( 'ovebotai_connect' ); ?>
			<button type="submit" class="button button-link ovebotai-reconnect-btn">
				<?php esc_html_e( 'Reconnect', 'ovebotai' ); ?> <span aria-hidden="true">&rarr;</span>
			</button>
		</form>
	<?php endif; ?>
</div>
