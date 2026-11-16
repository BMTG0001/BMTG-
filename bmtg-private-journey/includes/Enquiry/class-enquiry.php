<?php
namespace BMTG\PrivateJourney\Enquiry;

use BMTG\PrivateJourney\Audit\Audit;
use BMTG\PrivateJourney\Catalogue\Catalogue;
use BMTG\PrivateJourney\Clients\Clients;
use BMTG\PrivateJourney\Core\Schema;
use BMTG\PrivateJourney\Email\Notifier;
use BMTG\PrivateJourney\Journey\Basket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-for-quotation lifecycle.
 */
class Enquiry {
	private static ?Enquiry $instance = null;

	public static function instance(): Enquiry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::enquiries() . " WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function get_by_reference( string $reference ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::enquiries() . " WHERE reference = %s", $reference ), ARRAY_A );
		return $row ?: null;
	}

	public function items( int $enquiry_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . Schema::enquiry_items() . " WHERE enquiry_id = %d ORDER BY sort_order, id", $enquiry_id ), ARRAY_A );
	}

	/**
	 * Submit a complete request for quotation from the current journey basket.
	 *
	 * @param array $data Form fields: name, email, phone, budget, preferences, special_requests, consent.
	 * @param int   $journey_id Optional explicit journey; otherwise the current basket.
	 * @return array{ok:bool,reference?:string,id?:int,error?:string,existing?:bool}
	 */
	public function submit( array $data, ?int $journey_id = null ): array {
		global $wpdb;

		$name  = Schema::sanitize_text( $data['name'] ?? '', 191 );
		$email = Schema::normalize_email( $data['email'] ?? '' );
		$phone = Schema::sanitize_text( $data['phone'] ?? '', 40 );

		if ( '' === $name ) {
			return array( 'ok' => false, 'error' => __( 'Please provide your full name.', 'bmtg-private-journey' ) );
		}
		if ( ! is_email( $email ) ) {
			return array( 'ok' => false, 'error' => __( 'Please provide a valid email address.', 'bmtg-private-journey' ) );
		}
		if ( empty( $data['consent'] ) ) {
			return array( 'ok' => false, 'error' => __( 'Please accept the privacy notice to continue.', 'bmtg-private-journey' ) );
		}

		$journey = null;
		if ( $journey_id ) {
			$journey = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::journey_drafts() . " WHERE id = %d", $journey_id ), ARRAY_A );
		}
		if ( ! $journey && is_user_logged_in() ) {
			$journey = Basket::instance()->current();
		}

		$items = $journey ? $this->basket_lines( (int) $journey['id'] ) : array();
		if ( empty( $items ) ) {
			return array( 'ok' => false, 'error' => __( 'Your journey basket is empty. Add at least one service before submitting.', 'bmtg-private-journey' ) );
		}

		$adults   = isset( $data['adults'] ) && '' !== $data['adults'] ? max( 0, (int) $data['adults'] ) : $this->sum_field( $items, 'adults' );
		$children = isset( $data['children'] ) && '' !== $data['children'] ? max( 0, (int) $data['children'] ) : $this->sum_field( $items, 'children' );

		$arrival   = Schema::sanitize_date( $data['arrival_date'] ?? '' );
		$departure = Schema::sanitize_date( $data['departure_date'] ?? '' );
		if ( $arrival && $departure && $departure < $arrival ) {
			return array( 'ok' => false, 'error' => __( 'The departure date cannot be before the arrival date.', 'bmtg-private-journey' ) );
		}

		$client_id = 0;
		if ( is_user_logged_in() ) {
			$client    = Clients::instance()->ensure_for_user( get_current_user_id() );
			$client_id = $client ? (int) $client['id'] : 0;
			if ( $client ) {
				Clients::instance()->update( (int) $client['id'], array(
					'first_name' => $client['first_name'],
					'last_name'  => $client['last_name'],
					'email'      => $email,
					'phone'      => $phone,
				) );
			}
		} else {
			$existing = Clients::instance()->get_by_email( $email );
			$client_id = $existing ? (int) $existing['id'] : 0;
		}

		$destinations = array();
		foreach ( $items as $line ) {
			if ( ! empty( $line['destination'] ) ) {
				$destinations[ $line['destination'] ] = true;
			}
		}

		$dedupe = sha256( implode( '|', array(
			$email,
			implode( ',', array_map( static function ( $l ) { return (int) $l['item_id'] . ':' . $l['start_date'] . ':' . $l['end_date']; }, $items ) ),
			(string) $adults,
			(string) $children,
			$arrival,
			$departure,
		) ) );

		$recent = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::enquiries() . " WHERE dedupe_key = %s AND created_at > DATE_SUB(%s, INTERVAL 15 MINUTE) LIMIT 1", $dedupe, Schema::now() ) );
		if ( $recent ) {
			$existing = $this->get( (int) $recent );
			return array( 'ok' => true, 'id' => (int) $recent, 'reference' => $existing['reference'] ?? '', 'existing' => true );
		}

		$reference = $this->reference();
		$now       = Schema::now();

		$wpdb->insert(
			Schema::enquiries(),
			array(
				'client_id'        => $client_id ?: null,
				'journey_id'       => $journey ? (int) $journey['id'] : null,
				'reference'        => $reference,
				'dedupe_key'       => $dedupe,
				'status'           => 'new',
				'name'             => $name,
				'email'            => $email,
				'phone'            => $phone,
				'destinations'     => implode( ', ', array_keys( $destinations ) ),
				'arrival_date'     => $arrival,
				'departure_date'   => $departure,
				'adults'           => $adults,
				'children'         => $children,
				'budget'           => Schema::sanitize_text( $data['budget'] ?? '', 80 ),
				'preferences'      => Schema::sanitize_textarea( $data['preferences'] ?? '', 5000 ),
				'special_requests' => Schema::sanitize_textarea( $data['special_requests'] ?? '', 5000 ),
				'currency'         => (string) \BMTG\PrivateJourney\Core\Settings::get( 'general', 'currency', 'EUR' ),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$enquiry_id = (int) $wpdb->insert_id;

		$order = 0;
		foreach ( $items as $line ) {
			$order++;
			$description = $line['title'] ?? __( 'Service', 'bmtg-private-journey' );
			if ( ! empty( $line['option_label'] ) ) {
				$description .= ' — ' . $line['option_label'];
			}
			$price = isset( $line['option_price'] ) && null !== $line['option_price']
				? (float) $line['option_price']
				: ( 'fixed' === ( $line['catalogue_price_type'] ?? 'poa' ) ? (float) $line['catalogue_price'] : null );

			$wpdb->insert(
				Schema::enquiry_items(),
				array(
					'enquiry_id'   => $enquiry_id,
					'item_id'      => (int) ( $line['item_id'] ?? 0 ) ?: null,
					'service_type' => (string) ( $line['service_type'] ?? 'experience' ),
					'description'  => $description,
					'quantity'     => max( 1, (int) $line['quantity'] ),
					'unit_cost'    => null,
					'unit_price'   => $price,
					'line_total'   => null === $price ? 0 : $price * max( 1, (int) $line['quantity'] ),
					'meta'         => Schema::json( array(
						'start_date'    => $line['start_date'] ?? '',
						'end_date'      => $line['end_date'] ?? '',
						'adults'        => $line['adults'] ?? 0,
						'children'      => $line['children'] ?? 0,
						'rooms'         => $line['rooms'] ?? 0,
						'pickup'        => $line['pickup'] ?? '',
						'dropoff'       => $line['dropoff'] ?? '',
						'flight_number' => $line['flight_number'] ?? '',
						'notes'         => $line['notes'] ?? '',
					) ),
					'sort_order'   => $order,
				)
			);
		}

		if ( $journey ) {
			$wpdb->update( Schema::journey_drafts(), array( 'status' => 'submitted', 'updated_at' => $now ), array( 'id' => (int) $journey['id'] ) );
		}

		Audit::instance()->log( 'enquiry.submit', 'enquiry', $enquiry_id, array( 'reference' => $reference, 'items' => count( $items ) ) );

		$mail = Notifier::instance()->notify_new_enquiry( $enquiry_id );

		return array(
			'ok'        => true,
			'id'        => $enquiry_id,
			'reference' => $reference,
			'email'     => $mail,
		);
	}

	private function basket_lines( int $journey_id ): array {
		global $wpdb;
		$ji = Schema::journey_items();
		$ci = Schema::catalogue_items();
		$so = Schema::service_options();
		$sql = "SELECT ji.*, ci.title, ci.item_type, ci.destination, ci.price AS catalogue_price, ci.price_type AS catalogue_price_type,
					so.label AS option_label, so.price AS option_price
				FROM $ji ji
				LEFT JOIN $ci ci ON ci.id = ji.item_id
				LEFT JOIN $so so ON so.id = ji.option_id
				WHERE ji.journey_id = %d
				ORDER BY ji.sort_order, ji.id";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $journey_id ), ARRAY_A );
	}

	private function sum_field( array $items, string $field ): int {
		$sum = 0;
		foreach ( $items as $item ) {
			$sum += (int) ( $item[ $field ] ?? 0 );
		}
		return $sum;
	}

	public function reference(): string {
		global $wpdb;
		$table = Schema::sequences();
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET seq = seq + 1, updated_at = %s WHERE name = %s", Schema::now(), 'enquiry' ) );
		$seq = (int) $wpdb->get_var( $wpdb->prepare( "SELECT seq FROM $table WHERE name = %s", 'enquiry' ) );
		return 'BMTG-PJ-' . gmdate( 'ym' ) . '-' . str_pad( (string) $seq, 4, '0', STR_PAD_LEFT );
	}

	public function list( array $args = array() ): array {
		global $wpdb;
		$table = Schema::enquiries();
		$args  = wp_parse_args( $args, array( 'status' => '', 'search' => '', 'client_id' => 0, 'page' => 1, 'per_page' => 20 ) );
		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( $args['client_id'] ) {
			$where[]  = 'client_id = %d';
			$params[] = (int) $args['client_id'];
		}
		if ( '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(reference LIKE %s OR name LIKE %s OR email LIKE %s OR destinations LIKE %s)';
			array_push( $params, $like, $like, $like, $like );
		}
		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 100, (int) $args['per_page'] ) );
		$page      = max( 1, (int) $args['page'] );
		$offset    = ( $page - 1 ) * $per_page;
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" );
		if ( $params ) {
			$params[] = $per_page;
			$params[] = $offset;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d", ...$params ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT $per_page OFFSET $offset", ARRAY_A );
		}
		return array( 'items' => (array) $rows, 'total' => $total, 'pages' => (int) ceil( $total / $per_page ) );
	}

	public function client_enquiries( int $client_id ): array {
		global $wpdb;
		$table = Schema::enquiries();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE client_id = %d ORDER BY id DESC", $client_id ), ARRAY_A );
	}

	public function set_status( int $id, string $status, string $note = '' ): bool {
		if ( ! in_array( $status, Schema::enquiry_statuses(), true ) ) {
			return false;
		}
		global $wpdb;
		$ok = false !== $wpdb->update( Schema::enquiries(), array( 'status' => $status, 'updated_at' => Schema::now() ), array( 'id' => $id ) );
		if ( $ok ) {
			Audit::instance()->log( 'enquiry.status', 'enquiry', $id, array( 'status' => $status, 'note' => $note ) );
		}
		return $ok;
	}

	public function assign( int $id, ?int $user_id ): bool {
		global $wpdb;
		$ok = false !== $wpdb->update( Schema::enquiries(), array( 'assigned_to' => $user_id ?: null, 'updated_at' => Schema::now() ), array( 'id' => $id ) );
		if ( $ok ) {
			Audit::instance()->log( 'enquiry.assign', 'enquiry', $id, array( 'assigned_to' => $user_id ) );
		}
		return $ok;
	}

	public function save_internal_note( int $id, string $note ): bool {
		global $wpdb;
		$note = Schema::sanitize_textarea( $note, 20000 );
		$ok   = false !== $wpdb->update( Schema::enquiries(), array( 'internal_notes' => $note, 'updated_at' => Schema::now() ), array( 'id' => $id ) );
		if ( $ok ) {
			Audit::instance()->log( 'enquiry.note', 'enquiry', $id );
		}
		return $ok;
	}

	public function save_pricing( int $id, array $totals, string $currency ): void {
		global $wpdb;
		$wpdb->update(
			Schema::enquiries(),
			array(
				'supplier_cost_total' => (float) ( $totals['cost'] ?? 0 ),
				'sell_price'          => (float) ( $totals['subtotal'] ?? 0 ),
				'tax_total'           => (float) ( $totals['tax'] ?? 0 ),
				'discount_total'      => (float) ( $totals['discount'] ?? 0 ),
				'currency'            => substr( $currency, 0, 3 ),
				'updated_at'          => Schema::now(),
			),
			array( 'id' => $id )
		);
	}

	public function to_client_array( array $enquiry ): array {
		unset( $enquiry['dedupe_key'], $enquiry['internal_notes'] );
		return $enquiry;
	}
}