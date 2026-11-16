<?php
namespace BMTG\PrivateJourney\Frontend;

use BMTG\PrivateJourney\Auth\Auth;
use BMTG\PrivateJourney\Audit\Audit;
use BMTG\PrivateJourney\Clients\Clients;
use BMTG\PrivateJourney\Core\Pages;
use BMTG\PrivateJourney\Core\Schema;
use BMTG\PrivateJourney\Enquiry\Enquiry;
use BMTG\PrivateJourney\Enquiry\Quotation;
use BMTG\PrivateJourney\Journey\Basket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend POST handlers. All state changes are nonce-checked and redirected.
 */
class Forms {
	public function register(): void {
		add_action( 'admin_post_bmtg_pj_basket_add', array( $this, 'basket_add' ) );
		add_action( 'admin_post_nopriv_bmtg_pj_basket_add', array( $this, 'basket_add' ) );
		add_action( 'admin_post_bmtg_pj_basket_update', array( $this, 'basket_update' ) );
		add_action( 'admin_post_nopriv_bmtg_pj_basket_update', array( $this, 'basket_update' ) );
		add_action( 'admin_post_bmtg_pj_basket_remove', array( $this, 'basket_remove' ) );
		add_action( 'admin_post_nopriv_bmtg_pj_basket_remove', array( $this, 'basket_remove' ) );
		add_action( 'admin_post_bmtg_pj_basket_reorder', array( $this, 'basket_reorder' ) );
		add_action( 'admin_post_nopriv_bmtg_pj_basket_reorder', array( $this, 'basket_reorder' ) );
		add_action( 'admin_post_bmtg_pj_save_journey', array( $this, 'save_journey' ) );
		add_action( 'admin_post_bmtg_pj_enquiry', array( $this, 'submit_enquiry' ) );
		add_action( 'admin_post_bmtg_pj_quote_response', array( $this, 'quote_response' ) );
		add_action( 'admin_post_bmtg_pj_profile', array( $this, 'save_profile' ) );
	}

	private function verify( string $action ): void {
		$nonce = isset( $_POST[ $action . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $action . '_nonce' ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => __( 'Your session expired. Please try again.', 'bmtg-private-journey' ) ) ) );
			exit;
		}
	}

	private function back( array $args = array() ): string {
		$referer = wp_get_referer();
		$base    = $referer ? $referer : Pages::url( 'basket' );
		return add_query_arg( $args, $base );
	}

	private function line_input(): array {
		return array(
			'quantity'      => isset( $_POST['quantity'] ) ? max( 1, (int) $_POST['quantity'] ) : 1,
			'start_date'    => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '',
			'end_date'      => isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '',
			'adults'        => isset( $_POST['adults'] ) ? max( 0, (int) $_POST['adults'] ) : 0,
			'children'      => isset( $_POST['children'] ) ? max( 0, (int) $_POST['children'] ) : 0,
			'rooms'         => isset( $_POST['rooms'] ) ? max( 0, (int) $_POST['rooms'] ) : 0,
			'pickup'        => isset( $_POST['pickup'] ) ? sanitize_text_field( wp_unslash( $_POST['pickup'] ) ) : '',
			'dropoff'       => isset( $_POST['dropoff'] ) ? sanitize_text_field( wp_unslash( $_POST['dropoff'] ) ) : '',
			'flight_number' => isset( $_POST['flight_number'] ) ? sanitize_text_field( wp_unslash( $_POST['flight_number'] ) ) : '',
			'time'          => isset( $_POST['time'] ) ? sanitize_text_field( wp_unslash( $_POST['time'] ) ) : '',
			'notes'         => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			'option_id'     => isset( $_POST['option_id'] ) ? (int) $_POST['option_id'] : 0,
		);
	}

	public function basket_add(): void {
		$this->verify( 'bmtg_pj_basket_add' );
		$item_id = isset( $_POST['item_id'] ) ? (int) $_POST['item_id'] : 0;
		if ( ! $item_id ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => __( 'No service selected.', 'bmtg-private-journey' ) ) ) );
			exit;
		}

		$data   = $this->line_input();
		$data['item_id'] = $item_id;
		$result = Basket::instance()->add_item( $data );

		if ( ! $result['ok'] ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => $result['error'] ) ) );
			exit;
		}

		$args = array( 'pj_added' => $item_id );
		if ( ! empty( $result['warnings'] ) ) {
			$args['pj_warning'] = count( $result['warnings'] );
		}
		wp_safe_redirect( $this->back( $args ) );
		exit;
	}

	public function basket_update(): void {
		$this->verify( 'bmtg_pj_basket_update' );
		$id     = isset( $_POST['item_id'] ) ? (int) $_POST['item_id'] : 0;
		$result = Basket::instance()->update_item( $id, $this->line_input() );
		if ( ! $result['ok'] ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => $result['error'] ) ) );
			exit;
		}
		wp_safe_redirect( $this->back( ! empty( $result['warnings'] ) ? array( 'pj_warning' => count( $result['warnings'] ) ) : array( 'pj_updated' => 1 ) ) );
		exit;
	}

	public function basket_remove(): void {
		$this->verify( 'bmtg_pj_basket_remove' );
		$id = isset( $_POST['item_id'] ) ? (int) $_POST['item_id'] : 0;
		Basket::instance()->remove_item( $id );
		wp_safe_redirect( $this->back( array( 'pj_removed' => 1 ) ) );
		exit;
	}

	public function basket_reorder(): void {
		$this->verify( 'bmtg_pj_basket_reorder' );
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		Basket::instance()->reorder( $ids );
		wp_safe_redirect( $this->back( array( 'pj_updated' => 1 ) ) );
		exit;
	}

	public function save_journey(): void {
		$this->verify( 'bmtg_pj_save_journey' );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => __( 'Please sign in to save your journey.', 'bmtg-private-journey' ) ) ) );
			exit;
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$result = Basket::instance()->save( $title, $notes );
		if ( ! $result['ok'] ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => $result['error'] ) ) );
			exit;
		}
		wp_safe_redirect( $this->back( array( 'pj_saved' => 1 ) ) );
		exit;
	}

	public function submit_enquiry(): void {
		$this->verify( 'bmtg_pj_enquiry' );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => __( 'Please sign in before submitting a quotation request.', 'bmtg-private-journey' ) ) ) );
			exit;
		}
		$data = array(
			'name'             => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'email'            => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'phone'            => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'arrival_date'     => isset( $_POST['arrival_date'] ) ? sanitize_text_field( wp_unslash( $_POST['arrival_date'] ) ) : '',
			'departure_date'   => isset( $_POST['departure_date'] ) ? sanitize_text_field( wp_unslash( $_POST['departure_date'] ) ) : '',
			'adults'           => isset( $_POST['adults'] ) ? (int) $_POST['adults'] : '',
			'children'         => isset( $_POST['children'] ) ? (int) $_POST['children'] : '',
			'budget'           => isset( $_POST['budget'] ) ? sanitize_text_field( wp_unslash( $_POST['budget'] ) ) : '',
			'preferences'      => isset( $_POST['preferences'] ) ? sanitize_textarea_field( wp_unslash( $_POST['preferences'] ) ) : '',
			'special_requests' => isset( $_POST['special_requests'] ) ? sanitize_textarea_field( wp_unslash( $_POST['special_requests'] ) ) : '',
			'consent'          => empty( $_POST['consent'] ) ? 0 : 1,
		);

		$result = Enquiry::instance()->submit( $data );

		if ( ! $result['ok'] ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => $result['error'] ) ) );
			exit;
		}

		$args = array( 'pj_reference' => $result['reference'] );
		if ( ! empty( $result['email'] ) ) {
			$args['pj_email'] = 'sent';
		}
		wp_safe_redirect( add_query_arg( $args, Pages::url( 'my_enquiries' ) ) );
		exit;
	}

	public function quote_response(): void {
		$this->verify( 'bmtg_pj_quote_response' );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => __( 'Please sign in to respond to a quotation.', 'bmtg-private-journey' ) ) ) );
			exit;
		}
		$id       = isset( $_POST['quotation_id'] ) ? (int) $_POST['quotation_id'] : 0;
		$response = isset( $_POST['response'] ) ? sanitize_key( wp_unslash( $_POST['response'] ) ) : '';
		$notes    = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$quote = Quotation::instance()->get( $id );
		if ( ! $quote ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => __( 'Quotation not found.', 'bmtg-private-journey' ) ) ) );
			exit;
		}
		$enquiry = Enquiry::instance()->get( (int) $quote['enquiry_id'] );
		$client  = Clients::instance()->get_by_user( get_current_user_id() );
		if ( ! $enquiry || ! $client || (int) $enquiry['client_id'] !== (int) $client['id'] ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => __( 'You do not have access to this quotation.', 'bmtg-private-journey' ) ) ) );
			exit;
		}

		$result = Quotation::instance()->respond( $id, $response, $notes );
		if ( ! $result['ok'] ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => $result['error'] ) ) );
			exit;
		}
		wp_safe_redirect( $this->back( array( 'pj_response' => $response ) ) );
		exit;
	}

	public function save_profile(): void {
		$this->verify( 'bmtg_pj_profile' );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( $this->back( array( 'pj_error' => __( 'Please sign in.', 'bmtg-private-journey' ) ) ) );
			exit;
		}
		$user_id = get_current_user_id();
		$first   = isset( $_POST['first_name'] ) ? Schema::sanitize_text( wp_unslash( $_POST['first_name'] ), 191 ) : '';
		$last    = isset( $_POST['last_name'] ) ? Schema::sanitize_text( wp_unslash( $_POST['last_name'] ), 191 ) : '';
		$phone   = isset( $_POST['phone'] ) ? Schema::sanitize_text( wp_unslash( $_POST['phone'] ), 40 ) : '';

		wp_update_user( array( 'ID' => $user_id, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ) );

		$client = Clients::instance()->ensure_for_user( $user_id );
		if ( $client ) {
			Clients::instance()->update( (int) $client['id'], array( 'first_name' => $first, 'last_name' => $last, 'phone' => $phone ) );
			$prefs = isset( $_POST['preferences'] ) && is_array( $_POST['preferences'] ) ? wp_unslash( $_POST['preferences'] ) : array();
			foreach ( $prefs as $key => $value ) {
				Clients::instance()->set_preference( (int) $client['id'], sanitize_key( $key ), sanitize_textarea_field( (string) $value ), 'profile' );
			}
		}

		Audit::instance()->log( 'client.profile_update', 'client', $user_id );
		wp_safe_redirect( $this->back( array( 'pj_saved' => 1 ) ) );
		exit;
	}
}