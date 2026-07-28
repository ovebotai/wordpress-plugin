<?php
// Small "vX.Y.Z" chip rendered in the admin header of every screen (task 1).
// Reads the single source of truth via Ovebotai::getModuleVersion().
defined( 'ABSPATH' ) || exit;
?>
<span class="ovebotai-version-badge" title="<?php esc_attr_e( 'Plugin version', 'ovebot-ai-chatbot-sales-agent' ); ?>">
	v<?php echo esc_html( Ovebotai::getModuleVersion() ); ?>
</span>
