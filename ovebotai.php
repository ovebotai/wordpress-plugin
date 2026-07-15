<?php
/**
 * Plugin Name:       Ovebot.ai
 * Plugin URI:        https://ovebot.ai
 * Description:       AI chat assistant for your store: knowledge-base support, product recommendations and order delivery status. Try it for free.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Ovesio
 * Author URI:        https://ovesio.com
 * Text Domain:       ovebotai
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'OVEBOTAI_VERSION', '1.0.0' );
define( 'OVEBOTAI_FILE',    __FILE__ );
define( 'OVEBOTAI_DIR',     plugin_dir_path( __FILE__ ) );
define( 'OVEBOTAI_URL',     plugin_dir_url( __FILE__ ) );

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
