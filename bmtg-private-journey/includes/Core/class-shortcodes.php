<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers every [bmtg_pj_*] shortcode and dispatches to the renderer.
 */
class Shortcodes {
	private Renderer $renderer;

	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	public function renderer(): Renderer {
		return $this->renderer;
	}

	public function register(): void {
		$map = array(
			'bmtg_pj_welcome'         => 'welcome',
			'bmtg_pj_login'           => 'login',
			'bmtg_pj_registration'    => 'registration',
			'bmtg_pj_first_login'     => 'first_login',
			'bmtg_pj_dashboard'       => 'dashboard',
			'bmtg_pj_navigation'      => 'navigation',
			'bmtg_pj_catalogue'       => 'catalogue',
			'bmtg_pj_category'        => 'category',
			'bmtg_pj_featured'        => 'featured',
			'bmtg_pj_item_detail'     => 'item_detail',
			'bmtg_pj_search'          => 'search',
			'bmtg_pj_journey_builder' => 'journey_builder',
			'bmtg_pj_basket'          => 'basket',
			'bmtg_pj_enquiry_form'    => 'enquiry_form',
			'bmtg_pj_my_enquiries'    => 'my_enquiries',
			'bmtg_pj_my_quotations'   => 'my_quotations',
			'bmtg_pj_my_journeys'     => 'my_journeys',
			'bmtg_pj_documents'       => 'documents',
			'bmtg_pj_profile'         => 'profile',
			'bmtg_pj_concierge'       => 'concierge',
			'bmtg_pj_footer'          => 'footer',
			'bmtg_pj_brand_header'    => 'brand_header',
		);

		foreach ( $map as $tag => $method ) {
			add_shortcode(
				$tag,
				function ( $atts = array(), $content = '' ) use ( $method ) {
					return $this->renderer->render( $method, $atts, (string) $content );
				}
			);
		}
	}
}