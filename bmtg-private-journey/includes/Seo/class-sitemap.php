<?php
namespace BMTG\PrivateJourney\Seo;

use BMTG\PrivateJourney\Catalogue\Catalogue;
use BMTG\PrivateJourney\Core\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds published, public catalogue items to the WordPress core sitemap.
 */
class Sitemap {
	public function register(): void {
		add_action( 'init', array( $this, 'register_provider' ) );
	}

	public function register_provider(): void {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) ) {
			return;
		}
		wp_register_sitemap_provider( 'bmtg-pj', new Sitemap_Provider() );
	}
}

class Sitemap_Provider implements \WP_Sitemaps_Provider {
	private const PER_PAGE = 200;

	public function __construct() {
		// No state required.
	}

	public function get_url_list( $page_num, $subtype = '' ) {
		global $wpdb;
		$table = \BMTG\PrivateJourney\Core\Schema::catalogue_items();
		$base  = Pages::url( 'item_detail' );
		if ( ! $base ) {
			return array();
		}
		$offset = max( 0, ( (int) $page_num - 1 ) ) * self::PER_PAGE;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT slug FROM $table WHERE status = 'published' AND public_visibility = 1 ORDER BY id ASC LIMIT %d OFFSET %d", self::PER_PAGE, $offset ) );

		$urls = array();
		foreach ( (array) $rows as $row ) {
			$urls[] = array(
				'loc'     => add_query_arg( array( 'pj_item' => $row['slug'] ), $base ),
				'lastmod' => null,
			);
		}
		return $urls;
	}

	public function get_max_num_pages( $subtype = '' ) {
		global $wpdb;
		$table = \BMTG\PrivateJourney\Core\Schema::catalogue_items();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'published' AND public_visibility = 1" );
		return (int) max( 1, ceil( $total / self::PER_PAGE ) );
	}
}