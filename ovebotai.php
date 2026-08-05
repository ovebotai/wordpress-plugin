<?php
/**
 * Plugin Name:       Ovebot – AI Chatbot, Live Chat & Sales Agent
 * Plugin URI:        https://ovebot.ai
 * Description:       Free AI chatbot and live chat widget, powered by the Ovebot.ai service. Answers visitor questions 24/7 from your own content and, on WooCommerce stores, recommends products and looks up order status.
 * Version:           1.0.4
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Ovesio
 * Author URI:        https://ovesio.com
 * Text Domain:       ovebot-ai-chatbot-sales-agent
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 */

defined( 'ABSPATH' ) || exit;

define( 'OVEBOTAI_VERSION',     '1.0.4' );
define( 'OVEBOTAI_MIN_WP_VER',  '5.8' );
define( 'OVEBOTAI_MIN_PHP_VER', '7.2' );
define( 'OVEBOTAI_FILE',        __FILE__ );
define( 'OVEBOTAI_DIR',         plugin_dir_path( __FILE__ ) );
define( 'OVEBOTAI_URL',         plugin_dir_url( __FILE__ ) );

// The "Requires at least"/"Requires PHP" headers above only block activation
// from the Plugins screen on WP 5.5+ - they're silently ignored on older
// core versions and on any activation path that skips that screen (WP-CLI,
// must-use, etc). This runtime guard is the actual enforcement: it refuses
// to load the plugin's classes at all below the minimum versions, on any
// activation path, on any WP version.
global $wp_version;
if ( version_compare( $wp_version, OVEBOTAI_MIN_WP_VER, '<' ) || version_compare( PHP_VERSION, OVEBOTAI_MIN_PHP_VER, '<' ) ) {
	add_action( 'admin_notices', function () {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: required WP version, 2: required PHP version, 3: current WP version, 4: current PHP version */
				__( 'Ovebot.ai requires WordPress %1$s+ and PHP %2$s+. This site is running WordPress %3$s and PHP %4$s, so the plugin has not been loaded.', 'ovebot-ai-chatbot-sales-agent' ),
				OVEBOTAI_MIN_WP_VER,
				OVEBOTAI_MIN_PHP_VER,
				$GLOBALS['wp_version'],
				PHP_VERSION
			) )
		);
	} );
	return;
}

if ( ! defined( 'OVEBOTAI_ACCOUNT_HOST' ) ) {
	define( 'OVEBOTAI_ACCOUNT_HOST', 'account.ovebot.ai' );
}
if ( ! defined( 'OVEBOTAI_API_HOST' ) ) {
	define( 'OVEBOTAI_API_HOST', 'api.ovebot.ai' );
}

require_once OVEBOTAI_DIR . 'includes/class-ovebotai.php';

register_activation_hook( __FILE__, array( 'Ovebotai', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Ovebotai', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Ovebotai', 'instance' ) );
