<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4 style autoloader for the plugin's own namespaces.
 * Class names map to includes/{Namespace}/class-{kebab-case}.php.
 */
class Autoloader {
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	public static function autoload( string $class ): void {
		$prefix = 'BMTG\\PrivateJourney\\';
		$len    = strlen( $prefix );

		if ( strncmp( $class, $prefix, $len ) !== 0 ) {
			return;
		}

		$relative = substr( $class, $len );
		if ( '' === $relative ) {
			return;
		}

		$parts = explode( '\\', $relative );
		$name  = array_pop( $parts );
		$dir   = empty( $parts ) ? 'Core' : implode( '/', $parts );
		$file  = BMTG_PJ_DIR . 'includes/' . $dir . '/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}