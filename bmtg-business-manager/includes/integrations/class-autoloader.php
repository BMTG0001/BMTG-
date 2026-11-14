<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {
	/** @var array<string,string> */
	private static array $map = array(
		'BMTG\\Integrations\\Integration_Exception'    => 'includes/integrations/class-exceptions.php',
		'BMTG\\Integrations\\Not_Configured_Exception' => 'includes/integrations/class-exceptions.php',
		'BMTG\\Integrations\\Disabled_Exception'       => 'includes/integrations/class-exceptions.php',
		'BMTG\\Integrations\\Expired_Exception'        => 'includes/integrations/class-exceptions.php',
		'BMTG\\Integrations\\Error_Exception'          => 'includes/integrations/class-exceptions.php',
		'BMTG\\Integrations\\Misconfigured_Exception'  => 'includes/integrations/class-exceptions.php',
		'BMTG\\Connectors\\Connector_Interface'        => 'includes/connectors/interface-connector.php',
	);

	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	public static function autoload( string $class ): void {
		if ( isset( self::$map[ $class ] ) ) {
			$file = BMTG_PLUGIN_DIR . self::$map[ $class ];
			if ( file_exists( $file ) ) {
				require $file;
			}
			return;
		}
		$prefixes = array(
			'BMTG\\Integrations\\' => BMTG_PLUGIN_DIR . 'includes/integrations/',
			'BMTG\\Connectors\\'   => BMTG_PLUGIN_DIR . 'includes/connectors/',
			'BMTG\\Migrations\\'   => BMTG_PLUGIN_DIR . 'includes/migrations/',
			'BMTG\\CRM\\'          => BMTG_PLUGIN_DIR . 'includes/crm/',
			'BMTG\\Shortcodes\\'   => BMTG_PLUGIN_DIR . 'includes/shortcodes/',
		);
		foreach ( $prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( strncmp( $class, $prefix, $len ) !== 0 ) {
				continue;
			}
			$relative = substr( $class, $len );
			$file     = $base_dir . 'class-' . strtolower( str_replace( array( '\\', '_' ), array( '/', '-' ), $relative ) ) . '.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
			return;
		}
	}
}
