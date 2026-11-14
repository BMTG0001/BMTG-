<?php
/**
 * Plugin Name: BMTG Business Manager
 * Description: Business management features including Integrations Hub for external connectors.
 * Version: 0.1.2
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: BMTG
 * Text Domain: bmtg-business-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMTG_VERSION', '0.1.2' );
define( 'BMTG_PLUGIN_FILE', __FILE__ );
define( 'BMTG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMTG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BMTG_INTEGRATIONS_DB_VERSION', '1' );
define( 'BMTG_CRM_DB_VERSION', '1' );

require_once BMTG_PLUGIN_DIR . 'includes/integrations/class-autoloader.php';
BMTG\Integrations\Autoloader::register();

register_activation_hook( __FILE__, array( 'BMTG\\Integrations\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BMTG\\Integrations\\Activator', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
	BMTG\Integrations\Hub::instance()->boot();
} );
