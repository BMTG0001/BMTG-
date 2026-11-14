<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrations {
	public function maybe_migrate(): void {
		$current = get_option( 'bmtg_crm_db_version', '0' );
		if ( version_compare( (string) $current, BMTG_CRM_DB_VERSION, '<' ) ) {
			$this->migrate();
		}
	}

	public function migrate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$p = $wpdb->prefix;

		$sql = "CREATE TABLE {$p}bmtg_crm_customers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			email_normalized varchar(190) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			phone_normalized varchar(50) NOT NULL DEFAULT '',
			country varchar(100) NOT NULL DEFAULT '',
			wp_user_id bigint(20) unsigned NULL,
			source varchar(64) NOT NULL DEFAULT '',
			assigned_to bigint(20) unsigned NULL,
			status varchar(32) NOT NULL DEFAULT 'active',
			notes_internal longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deleted_at datetime NULL,
			PRIMARY KEY  (id),
			KEY email_normalized (email_normalized),
			KEY phone_normalized (phone_normalized),
			KEY assigned_to (assigned_to),
			KEY status (status),
			KEY wp_user_id (wp_user_id),
			KEY updated_at (updated_at)
		) {$charset_collate};

		CREATE TABLE {$p}bmtg_crm_customer_preferences (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			pref_key varchar(100) NOT NULL,
			pref_value longtext NULL,
			source varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY customer_id (customer_id),
			KEY customer_key (customer_id, pref_key)
		) {$charset_collate};

		CREATE TABLE {$p}bmtg_crm_leads (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_id bigint(20) unsigned NOT NULL,
			title varchar(190) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'new',
			source varchar(64) NOT NULL DEFAULT '',
			channel varchar(64) NOT NULL DEFAULT '',
			assigned_to bigint(20) unsigned NULL,
			priority varchar(20) NOT NULL DEFAULT 'normal',
			destinations varchar(255) NOT NULL DEFAULT '',
			travel_start date NULL,
			travel_end date NULL,
			pax_adults smallint unsigned NULL,
			pax_children smallint unsigned NULL,
			inbox_conversation_id bigint(20) unsigned NULL,
			external_ref varchar(191) NOT NULL DEFAULT '',
			lost_reason varchar(255) NOT NULL DEFAULT '',
			won_at datetime NULL,
			closed_at datetime NULL,
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY customer_id (customer_id),
			KEY status (status),
			KEY assigned_to (assigned_to),
			KEY inbox_conversation_id (inbox_conversation_id),
			KEY created_at (created_at),
			KEY source (source)
		) {$charset_collate};

		CREATE TABLE {$p}bmtg_crm_lead_status_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			from_status varchar(32) NOT NULL DEFAULT '',
			to_status varchar(32) NOT NULL DEFAULT '',
			changed_by bigint(20) unsigned NULL,
			reason varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$p}bmtg_crm_lead_activities (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NULL,
			customer_id bigint(20) unsigned NULL,
			type varchar(64) NOT NULL,
			body longtext NULL,
			meta longtext NULL,
			actor_id bigint(20) unsigned NULL,
			dedupe_key varchar(191) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY customer_id (customer_id),
			KEY created_at (created_at),
			KEY type (type),
			KEY dedupe_key (dedupe_key)
		) {$charset_collate};

		CREATE TABLE {$p}bmtg_crm_reminders (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			assigned_to bigint(20) unsigned NULL,
			due_at datetime NOT NULL,
			remind_type varchar(64) NOT NULL DEFAULT 'follow_up',
			status varchar(32) NOT NULL DEFAULT 'pending',
			note varchar(255) NOT NULL DEFAULT '',
			completed_at datetime NULL,
			created_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY assigned_to (assigned_to),
			KEY due_at (due_at),
			KEY status (status),
			KEY due_status (due_at, status)
		) {$charset_collate};

		CREATE TABLE {$p}bmtg_crm_sequences (
			name varchar(100) NOT NULL,
			seq bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (name)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'bmtg_crm_db_version', BMTG_CRM_DB_VERSION, false );
	}
}
