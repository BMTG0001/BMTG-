<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {
	public static function activate(): void {
		Capabilities::install();
		( new Migrations() )->migrate();

		if ( false === get_option( 'bmtg_pj_installed_at', false ) ) {
			add_option( 'bmtg_pj_installed_at', Schema::now(), '', false );
		}

		update_option( 'bmtg_pj_needs_pages', 1, false );

		flush_rewrite_rules( false );
	}
}