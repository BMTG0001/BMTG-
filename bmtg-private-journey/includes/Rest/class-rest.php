<?php
namespace BMTG\PrivateJourney\Rest;

use BMTG\PrivateJourney\Auth\Auth;
use BMTG\PrivateJourney\Catalogue\Catalogue;
use BMTG\PrivateJourney\Clients\Clients;
use BMTG\PrivateJourney\Enquiry\Enquiry;
use BMTG\PrivateJourney\Enquiry\Quotation;
use BMTG\PrivateJourney\Journey\Basket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small, permission-checked REST surface used by the frontend.
 * Private endpoints never expose another client's data.
 */
class Rest {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	private function response( $data, int $status = 200 ) {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	public function routes(): void {
		$ns = 'bmtg/v1/pj';

		register_rest_route(
			$ns,
			'/catalogue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'catalogue' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/auth/google',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'google_auth' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/basket',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'basket' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);

		register_rest_route(
			$ns,
			'/enquiries',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'enquiries' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);

		register_rest_route(
			$ns,
			'/quotations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'quotations' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);
	}

	public function require_login(): bool {
		return is_user_logged_in();
	}

	public function catalogue( \WP_REST_Request $request ) {
		$result = Catalogue::instance()->query(
			array(
				'type'     => sanitize_key( (string) $request->get_param( 'type' ) ),
				'category' => sanitize_title( (string) $request->get_param( 'category' ) ),
				'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'page'     => max( 1, (int) $request->get_param( 'page' ) ),
				'per_page' => (int) $request->get_param( 'per_page' ) ?: 9,
			)
		);

		$items = array();
		foreach ( $result['items'] as $item ) {
			$items[] = array(
				'id'          => (int) $item['id'],
				'slug'        => $item['slug'],
				'title'       => $item['title'],
				'summary'     => $item['summary'],
				'type'        => $item['item_type'],
				'destination' => $item['destination'],
				'country'     => $item['country'],
				'price'       => $item['price'],
				'price_type'  => $item['price_type'],
				'currency'    => $item['currency'],
				'image'       => $item['cover_url'],
				'url'         => add_query_arg( array( 'pj_item' => $item['slug'] ), \BMTG\PrivateJourney\Core\Pages::url( 'item_detail' ) ),
			);
		}

		return $this->response(
			array(
				'items' => $items,
				'total' => $result['total'],
				'pages' => $result['pages'],
				'page'  => $result['page'],
			)
		);
	}

	public function google_auth( \WP_REST_Request $request ) {
		$body       = $request->get_json_params();
		$credential = '';
		if ( is_array( $body ) && ! empty( $body['credential'] ) ) {
			$credential = (string) $body['credential'];
		}
		if ( ! $credential ) {
			return $this->response( array( 'ok' => false, 'error' => __( 'Missing Google credential.', 'bmtg-private-journey' ) ), 400 );
		}

		$result = Auth::login_with_google( $credential );
		if ( empty( $result['ok'] ) ) {
			return $this->response( array( 'ok' => false, 'error' => $result['error'] ?? __( 'Google sign-in failed.', 'bmtg-private-journey' ) ), 401 );
		}
		return $this->response( array( 'ok' => true, 'redirect' => $result['redirect'] ?? '' ) );
	}

	public function basket( \WP_REST_Request $request ) {
		$journey = Basket::instance()->current();
		return $this->response( array( 'items' => Basket::instance()->items( (int) $journey['id'] ) ) );
	}

	public function enquiries( \WP_REST_Request $request ) {
		$client = Clients::instance()->get_by_user( get_current_user_id() );
		if ( ! $client ) {
			return $this->response( array( 'items' => array() ) );
		}
		$rows = Enquiry::instance()->client_enquiries( (int) $client['id'] );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'        => (int) $row['id'],
				'reference' => $row['reference'],
				'status'    => $row['status'],
				'dates'     => $row['arrival_date'] . ' / ' . $row['departure_date'],
			);
		}
		return $this->response( array( 'items' => $out ) );
	}

	public function quotations( \WP_REST_Request $request ) {
		$client    = Clients::instance()->get_by_user( get_current_user_id() );
		$enquiries = $client ? Enquiry::instance()->client_enquiries( (int) $client['id'] ) : array();
		$out       = array();
		foreach ( $enquiries as $enquiry ) {
			$quote = Quotation::instance()->latest_for_enquiry( (int) $enquiry['id'] );
			if ( ! $quote ) {
				continue;
			}
			$out[] = array(
				'id'      => (int) $quote['id'],
				'version' => (int) $quote['version'],
				'status'  => $quote['status'],
				'totals'  => $quote['totals'],
				'expires' => $quote['expires_at'],
			);
		}
		return $this->response( array( 'items' => $out ) );
	}
}