<?php
namespace BMTG\PrivateJourney\Frontend;

use BMTG\PrivateJourney\Core\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend asset loading. CSS loads only on pages that contain plugin content
 * (shortcode, page mapping or Elementor widget). JS is small and conditional.
 */
class Assets {
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_head', array( $this, 'noindex_private' ), 1 );
	}

	public function enqueue(): void {
		if ( is_admin() || ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'bmtg-pj',
			BMTG_PJ_URL . 'assets/css/private-journey.css',
			array(),
			BMTG_PJ_VERSION
		);

		wp_enqueue_script(
			'bmtg-pj',
			BMTG_PJ_URL . 'assets/js/private-journey.js',
			array(),
			BMTG_PJ_VERSION,
			true
		);

		wp_localize_script(
			'bmtg-pj',
			'BmtgPjConfig',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'bmtg/v1/pj/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'googleId'   => \BMTG\PrivateJourney\Auth\Auth::google_client_id(),
				'googleOn'   => \BMTG\PrivateJourney\Auth\Auth::google_enabled(),
				'loginUrl'   => Pages::url( 'login' ),
				'homeUrl'    => home_url( '/' ),
				'i18n'       => array(
					'added'    => __( 'Added to your journey.', 'bmtg-private-journey' ),
					'removed'  => __( 'Removed from your journey.', 'bmtg-private-journey' ),
					'error'    => __( 'Something went wrong. Please try again.', 'bmtg-private-journey' ),
					'confirm'  => __( 'Are you sure?', 'bmtg-private-journey' ),
					'signing'  => __( 'Signing in…', 'bmtg-private-journey' ),
				),
			)
		);

		if ( \BMTG\PrivateJourney\Auth\Auth::google_enabled() && ( $this->is_auth_page() ) ) {
			wp_enqueue_script( 'bmtg-pj-google', 'https://accounts.google.com/gsi/client', array(), null, true );
		}
	}

	private function is_auth_page(): bool {
		if ( ! is_page() ) {
			return false;
		}
		$id      = get_queried_object_id();
		$welcome = Pages::page_id( 'welcome' );
		$login   = Pages::page_id( 'login' );
		$reg     = Pages::page_id( 'registration' );
		return $id && ( $id === $welcome || $id === $login || $id === $reg );
	}

	private function should_load(): bool {
		if ( is_page() ) {
			$id = get_queried_object_id();
			foreach ( Pages::all() as $meta ) {
				if ( $meta['page_id'] && (int) $meta['page_id'] === (int) $id ) {
					return true;
				}
			}
		}

		$post = get_post();
		if ( $post instanceof \WP_Post ) {
			$content = (string) $post->post_content;
			if ( false !== strpos( $content, 'bmtg_pj_' ) ) {
				return true;
			}
			$elementor = (string) get_post_meta( $post->ID, '_elementor_data', true );
			if ( $elementor && false !== strpos( $elementor, 'bmtg_pj_' ) ) {
				return true;
			}
		}

		return false;
	}

	public function noindex_private(): void {
		if ( is_user_logged_in() ) {
			echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
			return;
		}
		if ( is_page() ) {
			$id = get_queried_object_id();
			foreach ( Pages::all() as $key => $meta ) {
				if ( $meta['page_id'] && (int) $meta['page_id'] === (int) $id && empty( $meta['public'] ) ) {
					echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
					return;
				}
			}
		}
	}
}