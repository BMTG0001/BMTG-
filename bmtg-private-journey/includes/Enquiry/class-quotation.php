<?php
namespace BMTG\PrivateJourney\Enquiry;

use BMTG\PrivateJourney\Audit\Audit;
use BMTG\PrivateJourney\Core\Schema;
use BMTG\PrivateJourney\Core\Settings;
use BMTG\PrivateJourney\Email\Notifier;
use BMTG\PrivateJourney\Pricing\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned proposals and quotation totals.
 * Acceptance records client intent; it does not confirm supplier availability.
 */
class Quotation {
	private static ?Quotation $instance = null;

	public static function instance(): Quotation {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::quotation_versions() . " WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['totals'] = Schema::decode( $row['totals'] ?? '', array() );
		$row['items']  = $this->items( $id );
		return $row;
	}

	public function items( int $quotation_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . Schema::quotation_items() . " WHERE quotation_id = %d ORDER BY sort_order, id", $quotation_id ), ARRAY_A );
	}

	public function latest_for_enquiry( int $enquiry_id ): ?array {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::quotation_versions() . " WHERE enquiry_id = %d AND status != 'draft' ORDER BY version DESC LIMIT 1", $enquiry_id ) );
		return $id ? $this->get( $id ) : null;
	}

	public function latest_issued( int $enquiry_id ): ?array {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::quotation_versions() . " WHERE enquiry_id = %d AND status = 'issued' ORDER BY version DESC LIMIT 1", $enquiry_id ) );
		return $id ? $this->get( $id ) : null;
	}

	/**
	 * Create a new draft version.
	 *
	 * @param array<int,array{description?:string,quantity?:int,unit_cost?:float|null,unit_price?:float|null,item_type?:string,item_id?:int}> $lines
	 * @param array{discount_type?:string,discount?:float,tax_rate?:float,currency?:string,notes?:string,expires_at?:string} $opts
	 */
	public function create( int $enquiry_id, array $lines, array $opts = array() ): array {
		global $wpdb;
		$enquiry = Enquiry::instance()->get( $enquiry_id );
		if ( ! $enquiry ) {
			return array( 'ok' => false, 'error' => __( 'Enquiry not found.', 'bmtg-private-journey' ) );
		}

		$opts = array_merge(
			array(
				'discount_type' => 'none',
				'discount'      => 0,
				'tax_rate'      => 0,
				'currency'      => $enquiry['currency'] ?: (string) Settings::get( 'general', 'currency', 'EUR' ),
				'notes'         => '',
				'expires_at'    => gmdate( 'Y-m-d', strtotime( '+' . (int) Settings::get( 'general', 'quote_valid_days', 14 ) . ' days' ) ),
			),
			$opts
		);

		$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(version),0) FROM " . Schema::quotation_versions() . " WHERE enquiry_id = %d", $enquiry_id ) );
		$totals  = Engine::totals( $lines, $opts );

		$wpdb->insert(
			Schema::quotation_versions(),
			array(
				'enquiry_id' => $enquiry_id,
				'version'    => $version,
				'status'     => 'draft',
				'totals'     => Schema::json( $totals ),
				'notes'      => Schema::sanitize_textarea( $opts['notes'], 5000 ),
				'expires_at' => Schema::sanitize_date( $opts['expires_at'] ),
				'created_by' => get_current_user_id() ?: null,
				'created_at' => Schema::now(),
				'updated_at' => Schema::now(),
			)
		);
		$quote_id = (int) $wpdb->insert_id;

		$order = 0;
		foreach ( $lines as $line ) {
			$order++;
			$qty    = max( 1, (int) ( $line['quantity'] ?? 1 ) );
			$price  = isset( $line['unit_price'] ) && null !== $line['unit_price'] ? (float) $line['unit_price'] : null;
			$cost   = isset( $line['unit_cost'] ) && null !== $line['unit_cost'] ? (float) $line['unit_cost'] : null;
			$wpdb->insert(
				Schema::quotation_items(),
				array(
					'quotation_id' => $quote_id,
					'item_type'    => substr( (string) ( $line['item_type'] ?? '' ), 0, 30 ),
					'item_id'      => (int) ( $line['item_id'] ?? 0 ) ?: null,
					'description'  => Schema::sanitize_textarea( $line['description'] ?? '', 1000 ),
					'quantity'     => $qty,
					'unit_cost'    => $cost,
					'unit_price'   => $price,
					'line_total'   => null === $price ? 0 : (float) Engine::line_total( $price, $qty ),
					'meta'         => Schema::json( isset( $line['meta'] ) && is_array( $line['meta'] ) ? $line['meta'] : array() ),
					'sort_order'   => $order,
				)
			);
		}

		Enquiry::instance()->save_pricing( $enquiry_id, $totals, $opts['currency'] );
		Audit::instance()->log( 'quotation.create', 'quotation', $quote_id, array( 'enquiry_id' => $enquiry_id, 'version' => $version ) );

		return array( 'ok' => true, 'id' => $quote_id, 'version' => $version, 'totals' => $totals );
	}

	public function issue( int $id ): array {
		global $wpdb;
		$quote = $this->get( $id );
		if ( ! $quote ) {
			return array( 'ok' => false, 'error' => __( 'Quotation not found.', 'bmtg-private-journey' ) );
		}
		if ( 'issued' === $quote['status'] ) {
			return array( 'ok' => true, 'id' => $id );
		}

		$wpdb->update(
			Schema::quotation_versions(),
			array( 'status' => 'superseded', 'updated_at' => Schema::now() ),
			array( 'enquiry_id' => (int) $quote['enquiry_id'], 'status' => 'issued' )
		);

		$now = Schema::now();
		$wpdb->update(
			Schema::quotation_versions(),
			array(
				'status'    => 'issued',
				'issued_at' => $now,
				'updated_at' => $now,
			),
			array( 'id' => $id )
		);

		Enquiry::instance()->set_status( (int) $quote['enquiry_id'], 'proposal_issued' );
		Audit::instance()->log( 'quotation.issue', 'quotation', $id, array( 'version' => (int) $quote['version'] ) );
		Notifier::instance()->notify_quotation_issued( $id );

		return array( 'ok' => true, 'id' => $id );
	}

	/**
	 * Client response. Accepted quotations start a journey in
	 * pending_supplier_confirmation, never a confirmed reservation.
	 */
	public function respond( int $id, string $response, string $notes = '' ): array {
		global $wpdb;
		$quote = $this->get( $id );
		if ( ! $quote ) {
			return array( 'ok' => false, 'error' => __( 'Quotation not found.', 'bmtg-private-journey' ) );
		}
		if ( 'issued' !== $quote['status'] ) {
			return array( 'ok' => false, 'error' => __( 'This quotation is not awaiting a response.', 'bmtg-private-journey' ) );
		}
		if ( $quote['expires_at'] && $quote['expires_at'] < Schema::today() ) {
			$wpdb->update( Schema::quotation_versions(), array( 'status' => 'expired', 'updated_at' => Schema::now() ), array( 'id' => $id ) );
			return array( 'ok' => false, 'error' => __( 'This quotation has expired. Please contact your designer for a refreshed proposal.', 'bmtg-private-journey' ) );
		}

		if ( ! in_array( $response, array( 'accepted', 'declined', 'modifications' ), true ) ) {
			return array( 'ok' => false, 'error' => __( 'Invalid response.', 'bmtg-private-journey' ) );
		}

		$status = 'accepted' === $response ? 'accepted' : ( 'declined' === $response ? 'declined' : 'issued' );
		$wpdb->update(
			Schema::quotation_versions(),
			array(
				'status'          => $status,
				'client_response' => $response,
				'accepted_at'     => 'accepted' === $response ? Schema::now() : null,
				'updated_at'      => Schema::now(),
			),
			array( 'id' => $id )
		);

		$enquiry_status = 'accepted' === $response ? 'accepted' : ( 'declined' === $response ? 'declined' : 'client_response' );
		Enquiry::instance()->set_status( (int) $quote['enquiry_id'], $enquiry_status, $notes );

		if ( 'accepted' === $response ) {
			$this->start_pending_journey( (int) $quote['enquiry_id'], $id );
		}

		Audit::instance()->log( 'quotation.response', 'quotation', $id, array( 'response' => $response ) );

		return array( 'ok' => true, 'response' => $response );
	}

	private function start_pending_journey( int $enquiry_id, int $quotation_id ): void {
		global $wpdb;
		$table = Schema::confirmed_journeys();
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE enquiry_id = %d", $enquiry_id ) );
		if ( $exists ) {
			$wpdb->update( $table, array( 'quotation_id' => $quotation_id, 'updated_at' => Schema::now() ), array( 'id' => (int) $exists ) );
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'enquiry_id'  => $enquiry_id,
				'quotation_id' => $quotation_id,
				'reference'   => $this->journey_reference(),
				'status'      => 'pending_supplier_confirmation',
				'meta'        => Schema::json( array( 'note' => 'Client accepted the proposal. Supplier confirmation is required.' ) ),
				'created_at'  => Schema::now(),
				'updated_at'  => Schema::now(),
			)
		);
	}

	private function journey_reference(): string {
		global $wpdb;
		$table = Schema::sequences();
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET seq = seq + 1, updated_at = %s WHERE name = %s", Schema::now(), 'journey' ) );
		$seq = (int) $wpdb->get_var( $wpdb->prepare( "SELECT seq FROM $table WHERE name = %s", 'journey' ) );
		return 'BMTG-CJ-' . gmdate( 'ym' ) . '-' . str_pad( (string) $seq, 4, '0', STR_PAD_LEFT );
	}

	public function set_status( int $id, string $status ): bool {
		if ( ! in_array( $status, Schema::quote_statuses(), true ) ) {
			return false;
		}
		global $wpdb;
		return false !== $wpdb->update( Schema::quotation_versions(), array( 'status' => $status, 'updated_at' => Schema::now() ), array( 'id' => $id ) );
	}

	public function attach_pdf( int $id, string $relative_path ): void {
		global $wpdb;
		$wpdb->update( Schema::quotation_versions(), array( 'pdf_path' => substr( $relative_path, 0, 255 ) ), array( 'id' => $id ) );
	}
}