<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database schema installer/upgrader. Additive and idempotent (dbDelta).
 * Data is preserved on update and on deactivation.
 */
class Migrations {
	private string $version;

	public function __construct( ?string $version = null ) {
		$this->version = $version ?? BMTG_PJ_DB_VERSION;
	}

	public function migrate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$items   = Schema::catalogue_items();
		$cats    = Schema::catalogue_categories();
		$options = Schema::service_options();
		$clients = Schema::clients();
		$prefs   = Schema::client_preferences();
		$sups    = Schema::suppliers();
		$drafts  = Schema::journey_drafts();
		$jitems  = Schema::journey_items();
		$enq     = Schema::enquiries();
		$enqit   = Schema::enquiry_items();
		$qver    = Schema::quotation_versions();
		$qitem   = Schema::quotation_items();
		$conf    = Schema::confirmed_journeys();
		$resv    = Schema::reservations();
		$docs    = Schema::documents();
		$notif   = Schema::notifications();
		$audit   = Schema::audit_logs();
		$seq     = Schema::sequences();

		$sql = array();

		$sql[] = "CREATE TABLE $clients (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint unsigned NULL,
			email varchar(191) NOT NULL DEFAULT '',
			email_normalized varchar(191) NOT NULL DEFAULT '',
			first_name varchar(191) NOT NULL DEFAULT '',
			last_name varchar(191) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			phone_verified tinyint(1) NOT NULL DEFAULT 0,
			preferences longtext NULL,
			privacy_consent_at datetime NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email_normalized (email_normalized),
			KEY wp_user_id (wp_user_id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE $prefs (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint unsigned NOT NULL,
			pref_key varchar(100) NOT NULL,
			pref_value longtext NULL,
			source varchar(40) NOT NULL DEFAULT 'client',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY client_key (client_id,pref_key)
		) $charset;";

		$sql[] = "CREATE TABLE $cats (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			slug varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			sort_order int NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'published',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) $charset;";

		$sql[] = "CREATE TABLE $items (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(191) NOT NULL DEFAULT '',
			item_type varchar(30) NOT NULL DEFAULT 'experience',
			title varchar(255) NOT NULL DEFAULT '',
			summary text NULL,
			description longtext NULL,
			country varchar(120) NOT NULL DEFAULT '',
			destination varchar(191) NOT NULL DEFAULT '',
			location varchar(191) NOT NULL DEFAULT '',
			category_slug varchar(191) NOT NULL DEFAULT '',
			tags text NULL,
			facilities longtext NULL,
			highlights longtext NULL,
			inclusions longtext NULL,
			exclusions longtext NULL,
			conditions longtext NULL,
			duration varchar(60) NOT NULL DEFAULT '',
			price_type varchar(20) NOT NULL DEFAULT 'poa',
			price decimal(12,2) NULL,
			currency char(3) NOT NULL DEFAULT 'EUR',
			cover_image_id bigint unsigned NULL,
			gallery longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			featured tinyint(1) NOT NULL DEFAULT 0,
			public_visibility tinyint(1) NOT NULL DEFAULT 1,
			search_meta text NULL,
			sort_order int NOT NULL DEFAULT 0,
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY item_type (item_type),
			KEY status (status),
			KEY category_slug (category_slug),
			KEY featured (featured),
			KEY public_visibility (public_visibility)
		) $charset;";

		$sql[] = "CREATE TABLE $options (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			item_id bigint unsigned NOT NULL,
			label varchar(191) NOT NULL DEFAULT '',
			description text NULL,
			price_type varchar(20) NOT NULL DEFAULT 'poa',
			price decimal(12,2) NULL,
			meta longtext NULL,
			sort_order int NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'published',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY item_id (item_id)
		) $charset;";

		$sql[] = "CREATE TABLE $sups (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			supplier_type varchar(40) NOT NULL DEFAULT '',
			contact_name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			notes longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY name (name),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE $drafts (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint unsigned NULL,
			wp_user_id bigint unsigned NULL,
			token varchar(64) NOT NULL DEFAULT '',
			title varchar(191) NOT NULL DEFAULT '',
			notes text NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY client_id (client_id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE $jitems (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			journey_id bigint unsigned NOT NULL,
			item_id bigint unsigned NULL,
			service_type varchar(30) NOT NULL DEFAULT 'experience',
			option_id bigint unsigned NULL,
			quantity int NOT NULL DEFAULT 1,
			start_date date NULL,
			end_date date NULL,
			adults int NOT NULL DEFAULT 0,
			children int NOT NULL DEFAULT 0,
			rooms int NOT NULL DEFAULT 0,
			pickup varchar(191) NOT NULL DEFAULT '',
			dropoff varchar(191) NOT NULL DEFAULT '',
			flight_number varchar(40) NOT NULL DEFAULT '',
			notes text NULL,
			meta longtext NULL,
			sort_order int NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY journey_id (journey_id),
			KEY item_id (item_id)
		) $charset;";

		$sql[] = "CREATE TABLE $enq (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint unsigned NULL,
			journey_id bigint unsigned NULL,
			reference varchar(40) NOT NULL DEFAULT '',
			dedupe_key varchar(64) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT 'new',
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			destinations text NULL,
			arrival_date date NULL,
			departure_date date NULL,
			adults int NOT NULL DEFAULT 0,
			children int NOT NULL DEFAULT 0,
			budget varchar(80) NOT NULL DEFAULT '',
			preferences longtext NULL,
			special_requests text NULL,
			supplier_cost_total decimal(12,2) NOT NULL DEFAULT 0,
			sell_price decimal(12,2) NOT NULL DEFAULT 0,
			markup decimal(6,3) NOT NULL DEFAULT 0,
			tax_total decimal(12,2) NOT NULL DEFAULT 0,
			discount_total decimal(12,2) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'EUR',
			fx_date date NULL,
			quote_valid_until date NULL,
			internal_notes longtext NULL,
			assigned_to bigint unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			KEY dedupe_key (dedupe_key),
			KEY client_id (client_id),
			KEY status (status),
			KEY assigned_to (assigned_to)
		) $charset;";

		$sql[] = "CREATE TABLE $enqit (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			enquiry_id bigint unsigned NOT NULL,
			item_id bigint unsigned NULL,
			service_type varchar(30) NOT NULL DEFAULT 'experience',
			description text NULL,
			quantity int NOT NULL DEFAULT 1,
			unit_cost decimal(12,2) NULL,
			unit_price decimal(12,2) NULL,
			line_total decimal(12,2) NOT NULL DEFAULT 0,
			meta longtext NULL,
			sort_order int NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY enquiry_id (enquiry_id)
		) $charset;";

		$sql[] = "CREATE TABLE $qver (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			enquiry_id bigint unsigned NOT NULL,
			version int NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'draft',
			totals longtext NULL,
			notes text NULL,
			pdf_path varchar(255) NOT NULL DEFAULT '',
			issued_at datetime NULL,
			accepted_at datetime NULL,
			expires_at date NULL,
			client_response varchar(30) NOT NULL DEFAULT '',
			created_by bigint unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY enquiry_id (enquiry_id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE $qitem (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			quotation_id bigint unsigned NOT NULL,
			item_type varchar(30) NOT NULL DEFAULT '',
			item_id bigint unsigned NULL,
			description text NULL,
			quantity int NOT NULL DEFAULT 1,
			unit_cost decimal(12,2) NULL,
			unit_price decimal(12,2) NULL,
			line_total decimal(12,2) NOT NULL DEFAULT 0,
			meta longtext NULL,
			sort_order int NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY quotation_id (quotation_id)
		) $charset;";

		$sql[] = "CREATE TABLE $conf (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			enquiry_id bigint unsigned NOT NULL,
			quotation_id bigint unsigned NULL,
			reference varchar(40) NOT NULL DEFAULT '',
			status varchar(40) NOT NULL DEFAULT 'pending_supplier_confirmation',
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY enquiry_id (enquiry_id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE $resv (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			confirmed_id bigint unsigned NOT NULL,
			supplier_id bigint unsigned NULL,
			item_id bigint unsigned NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY confirmed_id (confirmed_id)
		) $charset;";

		$sql[] = "CREATE TABLE $docs (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint unsigned NULL,
			enquiry_id bigint unsigned NULL,
			title varchar(191) NOT NULL DEFAULT '',
			file_path varchar(255) NOT NULL DEFAULT '',
			file_name varchar(255) NOT NULL DEFAULT '',
			mime varchar(120) NOT NULL DEFAULT '',
			size bigint unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_by bigint unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id),
			KEY enquiry_id (enquiry_id)
		) $charset;";

		$sql[] = "CREATE TABLE $notif (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			type varchar(40) NOT NULL DEFAULT '',
			recipient varchar(191) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			dedupe_key varchar(64) NOT NULL DEFAULT '',
			error text NULL,
			created_at datetime NOT NULL,
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY status (status),
			KEY created_at (created_at)
		) $charset;";

		$sql[] = "CREATE TABLE $audit (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint unsigned NULL,
			action varchar(100) NOT NULL DEFAULT '',
			object_type varchar(50) NOT NULL DEFAULT '',
			object_id bigint unsigned NULL,
			meta longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY actor_id (actor_id),
			KEY object (object_type,object_id),
			KEY created_at (created_at)
		) $charset;";

		$sql[] = "CREATE TABLE $seq (
			name varchar(64) NOT NULL,
			seq bigint unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (name)
		) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		$this->seed_categories();
		$this->seed_sequences();

		update_option( 'bmtg_pj_db_version', $this->version, false );
	}

	private function seed_categories(): void {
		global $wpdb;
		$table = Schema::catalogue_categories();
		$now   = Schema::now();

		$defaults = array(
			'signature-journeys' => 'Signature Journeys',
			'private-packages'   => 'Private Packages',
			'hotels-resorts'     => 'Hotels & Resorts',
			'restaurants-dining' => 'Restaurants & Fine Dining',
			'private-transport'  => 'Private Transport',
			'rail-trains'        => 'Luxury Rail & Trains',
			'airport-services'   => 'Airport Meet & Greet',
			'safaris-wildlife'   => 'Safaris & Wildlife',
			'activities'         => 'Activities & Experiences',
			'wellness'           => 'Wellness',
			'heritage'           => 'Heritage & Monuments',
			'exclusive-access'   => 'Exclusive Access',
		);

		$order = 0;
		foreach ( $defaults as $slug => $name ) {
			$order++;
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug = %s", $slug ) );
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'name'       => $name,
					'slug'       => $slug,
					'sort_order' => $order,
					'status'     => 'published',
					'created_at' => $now,
					'updated_at' => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		}
	}

	private function seed_sequences(): void {
		global $wpdb;
		$table = Schema::sequences();
		$names = array( 'enquiry', 'journey' );
		foreach ( $names as $name ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $table WHERE name = %s", $name ) );
			if ( ! $exists ) {
				$wpdb->insert( $table, array( 'name' => $name, 'seq' => 0, 'updated_at' => Schema::now() ), array( '%s', '%d', '%s' ) );
			}
		}
	}
}