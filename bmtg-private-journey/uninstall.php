<?php
/**
 * Uninstall routine for BMTG Private Journey.
 * Removes plugin data, roles and options. Files in the protected document
 * directory are intentionally left in place; see docs/BACKUP-ROLLBACK.md.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Core/class-autoloader.php';
\BMTG\PrivateJourney\Core\Autoloader::register();

global $wpdb;

// Remove plugin capabilities from roles that may remain.
foreach ( array( 'administrator' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		foreach ( \BMTG\PrivateJourney\Core\Capabilities::all() as $cap ) {
			$role->remove_cap( $cap );
		}
	}
}

\BMTG\PrivateJourney\Core\Capabilities::uninstall();

$tables = array(
	\BMTG\PrivateJourney\Core\Schema::clients(),
	\BMTG\PrivateJourney\Core\Schema::client_preferences(),
	\BMTG\PrivateJourney\Core\Schema::catalogue_categories(),
	\BMTG\PrivateJourney\Core\Schema::catalogue_items(),
	\BMTG\PrivateJourney\Core\Schema::service_options(),
	\BMTG\PrivateJourney\Core\Schema::suppliers(),
	\BMTG\PrivateJourney\Core\Schema::journey_drafts(),
	\BMTG\PrivateJourney\Core\Schema::journey_items(),
	\BMTG\PrivateJourney\Core\Schema::enquiries(),
	\BMTG\PrivateJourney\Core\Schema::enquiry_items(),
	\BMTG\PrivateJourney\Core\Schema::quotation_versions(),
	\BMTG\PrivateJourney\Core\Schema::quotation_items(),
	\BMTG\PrivateJourney\Core\Schema::confirmed_journeys(),
	\BMTG\PrivateJourney\Core\Schema::reservations(),
	\BMTG\PrivateJourney\Core\Schema::documents(),
	\BMTG\PrivateJourney\Core\Schema::notifications(),
	\BMTG\PrivateJourney\Core\Schema::audit_logs(),
	\BMTG\PrivateJourney\Core\Schema::sequences(),
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

foreach ( array( 'bmtg_pj_settings', 'bmtg_pj_pages', 'bmtg_pj_db_version', 'bmtg_pj_installed_at', 'bmtg_pj_needs_pages' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'bmtg_pj_onboarded'" ); // phpcs:ignore WordPress.DB

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bmtg\\_pj\\_%'" ); // phpcs:ignore WordPress.DB