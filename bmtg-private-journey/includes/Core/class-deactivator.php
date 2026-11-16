<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {
	public static function deactivate(): void {
		flush_rewrite_rules( false );
		// Data, settings and pages are intentionally preserved.
	}
}