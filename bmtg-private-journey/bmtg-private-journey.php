<?php
/**
 * Plugin Name:       BMTG Private Journey
 * Plugin URI:        https://bmtravelgroup.com/private-journey
 * Description:       Premium travel discovery, multi-service journey building and private quotation platform for BM Travel Group.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            BM Travel Group
 * Author URI:        https://bmtravelgroup.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bmtg-private-journey
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMTG_PJ_VERSION', '1.0.0' );
define( 'BMTG_PJ_FILE', __FILE__ );
define( 'BMTG_PJ_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMTG_PJ_URL', plugin_dir_url( __FILE__ ) );
define( 'BMTG_PJ_DB_VERSION', '1' );
define( 'BMTG_PJ_BASENAME', plugin_basename( __FILE__ ) );

require_once BMTG_PJ_DIR . 'includes/Core/class-autoloader.php';
\BMTG\PrivateJourney\Core\Autoloader::register();

register_activation_hook( __FILE__, array( '\BMTG\PrivateJourney\Core\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\BMTG\PrivateJourney\Core\Deactivator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\BMTG\PrivateJourney\Core\Plugin::instance()->boot();
	}
);