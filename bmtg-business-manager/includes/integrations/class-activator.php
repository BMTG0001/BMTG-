<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {
	public const CAPABILITY = 'bmtg_manage_integrations';

	public static function activate(): void {
		self::add_capability();
		$migrations = new Migrations();
		$migrations->migrate();
		if ( ! wp_next_scheduled( Health::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Health::CRON_HOOK );
		}
		// Key rotation background batch hook - no schedule by default.
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Health::CRON_HOOK );
		// Data preserved on deactivation per blueprint.
	}

	public static function add_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}
}
