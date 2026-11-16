<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page registry / mapping between logical screens and WordPress pages.
 */
class Pages {
	public const OPTION = 'bmtg_pj_pages';

	public static function registry(): array {
		return array(
			'welcome'      => array(
				'label'     => 'Welcome',
				'shortcode' => 'bmtg_pj_welcome',
				'slug'      => 'private-journey',
				'public'    => true,
			),
			'login'        => array(
				'label'     => 'Login',
				'shortcode' => 'bmtg_pj_login',
				'slug'      => 'private-journey/login',
				'public'    => false,
			),
			'registration' => array(
				'label'     => 'Registration',
				'shortcode' => 'bmtg_pj_registration',
				'slug'      => 'private-journey/register',
				'public'    => false,
			),
			'dashboard'    => array(
				'label'     => 'Client Dashboard',
				'shortcode' => 'bmtg_pj_dashboard',
				'slug'      => 'private-journey/dashboard',
				'public'    => false,
			),
			'catalogue'    => array(
				'label'     => 'Catalogue',
				'shortcode' => 'bmtg_pj_catalogue',
				'slug'      => 'private-journey/collection',
				'public'    => true,
			),
			'item_detail'  => array(
				'label'     => 'Service Detail',
				'shortcode' => 'bmtg_pj_item_detail',
				'slug'      => 'private-journey/service',
				'public'    => true,
			),
			'journey_builder' => array(
				'label'     => 'Journey Builder',
				'shortcode' => 'bmtg_pj_journey_builder',
				'slug'      => 'private-journey/journey',
				'public'    => false,
			),
			'basket'       => array(
				'label'     => 'My Journey Basket',
				'shortcode' => 'bmtg_pj_basket',
				'slug'      => 'private-journey/basket',
				'public'    => false,
			),
			'enquiry'      => array(
				'label'     => 'Enquiry',
				'shortcode' => 'bmtg_pj_enquiry_form',
				'slug'      => 'private-journey/enquiry',
				'public'    => false,
			),
			'my_enquiries' => array(
				'label'     => 'My Enquiries',
				'shortcode' => 'bmtg_pj_my_enquiries',
				'slug'      => 'private-journey/enquiries',
				'public'    => false,
			),
			'my_quotations' => array(
				'label'     => 'My Quotations',
				'shortcode' => 'bmtg_pj_my_quotations',
				'slug'      => 'private-journey/quotations',
				'public'    => false,
			),
			'my_journeys'  => array(
				'label'     => 'My Confirmed Journeys',
				'shortcode' => 'bmtg_pj_my_journeys',
				'slug'      => 'private-journey/journeys',
				'public'    => false,
			),
			'documents'    => array(
				'label'     => 'My Documents',
				'shortcode' => 'bmtg_pj_documents',
				'slug'      => 'private-journey/documents',
				'public'    => false,
			),
			'profile'      => array(
				'label'     => 'My Profile',
				'shortcode' => 'bmtg_pj_profile',
				'slug'      => 'private-journey/profile',
				'public'    => false,
			),
			'concierge'    => array(
				'label'     => 'Concierge & Support',
				'shortcode' => 'bmtg_pj_concierge',
				'slug'      => 'private-journey/concierge',
				'public'    => false,
			),
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$out = array();
		foreach ( self::registry() as $key => $meta ) {
			$page_id = isset( $saved[ $key ] ) ? (int) $saved[ $key ] : 0;
			if ( $page_id && ! get_post( $page_id ) ) {
				$page_id = 0;
			}
			$out[ $key ] = array_merge( $meta, array( 'page_id' => $page_id ) );
		}
		return $out;
	}

	public static function get( string $key ): array {
		$all = self::all();
		return $all[ $key ] ?? array( 'label' => '', 'shortcode' => '', 'slug' => '', 'public' => false, 'page_id' => 0 );
	}

	public static function page_id( string $key ): int {
		return (int) self::get( $key )['page_id'];
	}

	public static function url( string $key, array $args = array() ): string {
		$id = self::page_id( $key );
		if ( ! $id ) {
			return home_url( '/' );
		}
		$url = get_permalink( $id );
		if ( ! $url ) {
			return home_url( '/' );
		}
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	public static function set( array $mapping ): void {
		$clean = array();
		foreach ( self::registry() as $key => $meta ) {
			$clean[ $key ] = isset( $mapping[ $key ] ) ? (int) $mapping[ $key ] : 0;
		}
		update_option( self::OPTION, $clean, false );
	}

	/** Flat page-key => page_id map from the current saved mapping. */
	public static function flat_map(): array {
		$out = array();
		foreach ( self::all() as $key => $meta ) {
			$out[ $key ] = (int) $meta['page_id'];
		}
		return $out;
	}

	/**
	 * Create only the pages that do not yet exist. Called only after an
	 * explicit administrator action (never on activation).
	 *
	 * @return array{created:int,skipped:int,pages:array<int,array{id:int,title:string,url:string}>}
	 */
	public static function create_missing(): array {
		$result = array( 'created' => 0, 'skipped' => 0, 'pages' => array() );

		foreach ( self::all() as $key => $meta ) {
			if ( $meta['page_id'] ) {
				$result['skipped']++;
				continue;
			}

			$existing = get_page_by_path( $meta['slug'] );
			if ( $existing ) {
				$mapping = self::flat_map();
				$mapping[ $key ] = (int) $existing->ID;
				self::set( $mapping );
				$result['skipped']++;
				$result['pages'][] = array(
					'id'    => (int) $existing->ID,
					'title' => get_the_title( $existing ),
					'url'   => (string) get_permalink( $existing ),
				);
				continue;
			}

			$segments  = explode( '/', trim( $meta['slug'], '/' ) );
			$name      = (string) array_pop( $segments );
			$parent_id = 0;
			if ( $segments ) {
				$parent = get_page_by_path( implode( '/', $segments ) );
				if ( $parent ) {
					$parent_id = (int) $parent->ID;
				}
			}

			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => self::default_title( $key ),
					'post_name'    => sanitize_title( $name ),
					'post_parent'  => $parent_id,
					'post_content' => '[' . $meta['shortcode'] . ']',
					'post_author'  => get_current_user_id(),
					'meta_input'   => array(
						'_pj_system_page'  => 1,
						'_pj_page_key'     => $key,
						'_pj_shortcode'    => $meta['shortcode'],
					),
				),
				true
			);

			if ( is_wp_error( $page_id ) ) {
				continue;
			}

			$mapping = self::flat_map();
			$mapping[ $key ] = (int) $page_id;
			self::set( $mapping );
			$result['created']++;
			$result['pages'][] = array(
				'id'    => (int) $page_id,
				'title' => get_the_title( $page_id ),
				'url'   => (string) get_permalink( $page_id ),
			);
		}

		flush_rewrite_rules( false );
		return $result;
	}

	public static function default_title( string $key ): string {
		$titles = array(
			'welcome'         => 'The Art of Journey',
			'login'           => 'Sign In',
			'registration'    => 'Create Your Profile',
			'dashboard'       => 'My Private Journey',
			'catalogue'       => 'Private Collection',
			'item_detail'     => 'Discover',
			'journey_builder' => 'Journey Builder',
			'basket'          => 'My Journey Basket',
			'enquiry'         => 'Request a Private Quotation',
			'my_enquiries'    => 'My Enquiries',
			'my_quotations'   => 'My Quotations',
			'my_journeys'     => 'My Confirmed Journeys',
			'documents'       => 'My Documents',
			'profile'         => 'My Profile',
			'concierge'       => 'Concierge & Support',
		);
		return $titles[ $key ] ?? 'Private Journey';
	}
}