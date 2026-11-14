<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrations {
	public function maybe_migrate(): void {
		$current = get_option( 'bmtg_integrations_db_version', '0' );
		if ( version_compare( (string) $current, BMTG_INTEGRATIONS_DB_VERSION, '<' ) ) {
			$this->migrate();
		}
	}

	public function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$creds           = $wpdb->prefix . 'bmtg_connector_credentials';
		$tokens          = $wpdb->prefix . 'bmtg_connector_tokens';
		$audit           = $wpdb->prefix . 'bmtg_connector_audit';

		$sql = "CREATE TABLE {$creds} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connector_id varchar(64) NOT NULL,
			field_key varchar(64) NOT NULL,
			environment varchar(16) NOT NULL DEFAULT 'live',
			value_enc longtext NOT NULL,
			value_hint varchar(16) NOT NULL DEFAULT '',
			updated_by bigint(20) unsigned NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY connector_field_env (connector_id, field_key, environment),
			KEY connector_env (connector_id, environment)
		) {$charset_collate};

		CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connector_id varchar(64) NOT NULL,
			environment varchar(16) NOT NULL DEFAULT 'live',
			access_enc longtext NULL,
			refresh_enc longtext NULL,
			expires_at datetime NULL,
			scopes text NULL,
			status varchar(32) NOT NULL DEFAULT 'not_configured',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY connector_env (connector_id, environment),
			KEY connector_status (connector_id, status)
		) {$charset_collate};

		CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connector_id varchar(64) NOT NULL,
			action varchar(64) NOT NULL,
			field_key varchar(64) NULL,
			user_id bigint(20) unsigned NULL,
			result varchar(32) NOT NULL DEFAULT 'ok',
			message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY connector_created (connector_id, created_at),
			KEY user_created (user_id, created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'bmtg_integrations_db_version', BMTG_INTEGRATIONS_DB_VERSION, false );
	}

	public static function table_credentials(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_connector_credentials';
	}

	public static function table_tokens(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_connector_tokens';
	}

	public static function table_audit(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_connector_audit';
	}
}
