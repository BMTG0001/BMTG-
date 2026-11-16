<?php
namespace BMTG\PrivateJourney\Catalogue;

use BMTG\PrivateJourney\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service-specific requirement rules. No invented availability.
 */
class Validator {
	public static function fields_for( string $type ): array {
		$common = array( 'start_date', 'adults' );
		$map    = array(
			'hotel'       => array( 'start_date', 'end_date', 'rooms', 'adults', 'children' ),
			'restaurant'  => array( 'start_date', 'adults', 'children' ),
			'transport'   => array( 'start_date', 'pickup', 'dropoff', 'adults' ),
			'train'       => array( 'start_date', 'pickup', 'dropoff', 'adults', 'children' ),
			'airport'     => array( 'start_date', 'flight_number', 'adults', 'children' ),
			'experience'  => array( 'start_date', 'adults', 'children' ),
			'safari'      => array( 'start_date', 'adults', 'children' ),
			'wellness'    => array( 'start_date', 'adults' ),
			'heritage'    => array( 'start_date', 'adults', 'children' ),
			'exclusive'   => array( 'start_date', 'adults' ),
			'journey'     => array( 'start_date', 'end_date', 'adults', 'children' ),
			'package'     => array( 'start_date', 'end_date', 'adults', 'children' ),
		);
		return $map[ $type ] ?? $common;
	}

	public static function labels(): array {
		return array(
			'start_date'    => __( 'Start date', 'bmtg-private-journey' ),
			'end_date'      => __( 'End date', 'bmtg-private-journey' ),
			'rooms'         => __( 'Number of rooms', 'bmtg-private-journey' ),
			'adults'        => __( 'Adults', 'bmtg-private-journey' ),
			'children'      => __( 'Children', 'bmtg-private-journey' ),
			'pickup'        => __( 'Pickup / origin', 'bmtg-private-journey' ),
			'dropoff'       => __( 'Drop-off / destination', 'bmtg-private-journey' ),
			'flight_number' => __( 'Flight number', 'bmtg-private-journey' ),
		);
	}

	/**
	 * Validate a basket line for one catalogue item.
	 *
	 * @param array $line  Basket line data.
	 * @param array $item  Catalogue row.
	 * @return array<int,array{field:string,message:string}>
	 */
	public static function validate_line( array $line, array $item ): array {
		$errors = array();
		$type   = (string) ( $item['item_type'] ?? $line['service_type'] ?? 'experience' );

		$start = Schema::sanitize_date( $line['start_date'] ?? '' );
		$end   = Schema::sanitize_date( $line['end_date'] ?? '' );

		$required = self::fields_for( $type );
		foreach ( $required as $field ) {
			$value = $line[ $field ] ?? '';
			if ( in_array( $field, array( 'adults', 'children', 'rooms' ), true ) ) {
				if ( ! is_numeric( $value ) || (int) $value < ( 'adults' === $field ? 1 : 0 ) ) {
					$errors[] = array(
						'field'   => $field,
						'message' => sprintf( __( '%s is required for this service.', 'bmtg-private-journey' ), self::labels()[ $field ] ?? $field ),
					);
				}
				continue;
			}
			if ( 'start_date' === $field || 'end_date' === $field ) {
				if ( ! $value ) {
					$errors[] = array(
						'field'   => $field,
						'message' => sprintf( __( '%s is required for this service.', 'bmtg-private-journey' ), self::labels()[ $field ] ?? $field ),
					);
				}
				continue;
			}
			if ( '' === trim( (string) $value ) ) {
				$errors[] = array(
					'field'   => $field,
					'message' => sprintf( __( '%s is required for this service.', 'bmtg-private-journey' ), self::labels()[ $field ] ?? $field ),
				);
			}
		}

		if ( $start && $end && $end < $start ) {
			$errors[] = array(
				'field'   => 'end_date',
				'message' => __( 'The end date cannot be before the start date.', 'bmtg-private-journey' ),
			);
		}

		if ( $start && $start < Schema::today() ) {
			$errors[] = array(
				'field'   => 'start_date',
				'message' => __( 'The start date cannot be in the past.', 'bmtg-private-journey' ),
			);
		}

		if ( 'hotel' === $type && $end && $start && $end <= $start ) {
			$errors[] = array(
				'field'   => 'end_date',
				'message' => __( 'Hotel check-out must be after check-in.', 'bmtg-private-journey' ),
			);
		}

		if ( ! empty( $line['option_id'] ) ) {
			$option = self::find_option( (int) $line['option_id'], (int) ( $item['id'] ?? 0 ) );
			if ( ! $option ) {
				$errors[] = array(
					'field'   => 'option_id',
					'message' => __( 'The selected service option is no longer available.', 'bmtg-private-journey' ),
				);
			}
		}

		return $errors;
	}

	public static function find_option( int $option_id, int $item_id ): ?array {
		global $wpdb;
		$table = Schema::service_options();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND item_id = %d", $option_id, $item_id ), ARRAY_A );
		return $row ?: null;
	}

	/** Lightweight required-field summary for admin forms. */
	public static function required_labels( string $type ): string {
		$labels = self::labels();
		$out    = array();
		foreach ( self::fields_for( $type ) as $field ) {
			$out[] = $labels[ $field ] ?? $field;
		}
		return implode( ', ', $out );
	}
}