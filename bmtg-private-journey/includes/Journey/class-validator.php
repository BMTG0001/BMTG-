<?php
namespace BMTG\PrivateJourney\Journey;

use BMTG\PrivateJourney\Catalogue\Validator as LineValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Journey-wide intelligence: overlap, transfer, timing and completeness checks.
 * Warnings never silently remove client selections.
 */
class Validator {
	public static function validate( array $items ): array {
		$errors   = array();
		$warnings = array();

		foreach ( $items as $index => $item ) {
			$label = $item['title'] ?? sprintf( __( 'Item %d', 'bmtg-private-journey' ), $index + 1 );
			$line  = LineValidator::validate_line( $item, $item );

			if ( 'airport' === ( $item['service_type'] ?? '' ) && empty( $item['flight_number'] ) ) {
				$line[] = array(
					'field'   => 'flight_number',
					'message' => __( 'Airport assistance requires a flight number.', 'bmtg-private-journey' ),
				);
			}

			foreach ( $line as $issue ) {
				$entry = array(
					'item'  => $label,
					'field' => $issue['field'] ?? '',
					'msg'   => $issue['message'] ?? '',
				);
				if ( in_array( $issue['field'] ?? '', array( 'flight_number', 'end_date', 'start_date', 'adults', 'rooms', 'pickup', 'dropoff', 'option_id' ), true ) ) {
					$errors[] = $entry;
				} else {
					$warnings[] = $entry;
				}
			}
		}

		// Hotel overlap check.
		$hotels = array();
		foreach ( $items as $item ) {
			if ( 'hotel' === ( $item['service_type'] ?? '' ) && ! empty( $item['start_date'] ) ) {
				$hotels[] = $item;
			}
		}
		usort(
			$hotels,
			static function ( $a, $b ) {
				return strcmp( (string) $a['start_date'], (string) $b['start_date'] );
			}
		);
		$count = count( $hotels );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$a = $hotels[ $i ];
				$b = $hotels[ $j ];
				if ( empty( $a['end_date'] ) || empty( $b['end_date'] ) ) {
					continue;
				}
				if ( $b['start_date'] < $a['end_date'] ) {
					$errors[] = array(
						'item'  => $a['title'] . ' / ' . $b['title'],
						'field' => 'hotel_overlap',
						'msg'   => __( 'Two hotel stays overlap. Please adjust the check-out or check-in dates.', 'bmtg-private-journey' ),
					);
				}
			}
		}

		// Missing transfers when the journey spans multiple destinations.
		$destinations = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['destination'] ) ) {
				$destinations[ (string) $item['destination'] ] = true;
			}
		}
		if ( count( $destinations ) > 1 ) {
			$has_transfer = false;
			foreach ( $items as $item ) {
				if ( in_array( (string) ( $item['service_type'] ?? '' ), array( 'transport', 'train' ), true ) ) {
					$has_transfer = true;
					break;
				}
			}
			if ( ! $has_transfer ) {
				$warnings[] = array(
					'item'  => __( 'Journey', 'bmtg-private-journey' ),
					'field' => 'transfers',
					'msg'   => __( 'This journey spans multiple destinations but has no private transfer or rail segment. Add one or confirm arrangements with your designer.', 'bmtg-private-journey' ),
				);
			}
		}

		// Same-day timing conflicts.
		$by_date = array();
		foreach ( $items as $item ) {
			$time = $item['meta']['time'] ?? '';
			if ( ! empty( $item['start_date'] ) && '' !== $time ) {
				$by_date[ $item['start_date'] . '|' . $time ][] = $item['title'] ?? __( 'Item', 'bmtg-private-journey' );
			}
		}
		foreach ( $by_date as $key => $titles ) {
			if ( count( $titles ) > 1 ) {
				$warnings[] = array(
					'item'  => __( 'Schedule', 'bmtg-private-journey' ),
					'field' => 'timing',
					'msg'   => sprintf( __( 'Multiple services are requested at the same date and time (%s). Please confirm they can run together.', 'bmtg-private-journey' ), implode( ', ', array_map( 'strval', $titles ) ) ),
				);
			}
		}

		return array(
			'errors'     => $errors,
			'warnings'   => $warnings,
			'item_count' => count( $items ),
			'valid'      => empty( $errors ),
		);
	}
}