<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central schema definition: table names, statuses and shared helpers.
 */
class Schema {
	public static function clients(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_clients';
	}

	public static function client_preferences(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_client_preferences';
	}

	public static function catalogue_categories(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_catalogue_categories';
	}

	public static function catalogue_items(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_catalogue_items';
	}

	public static function service_options(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_service_options';
	}

	public static function suppliers(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_suppliers';
	}

	public static function journey_drafts(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_journey_drafts';
	}

	public static function journey_items(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_journey_items';
	}

	public static function enquiries(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_enquiries';
	}

	public static function enquiry_items(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_enquiry_items';
	}

	public static function quotation_versions(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_quotation_versions';
	}

	public static function quotation_items(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_quotation_items';
	}

	public static function confirmed_journeys(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_confirmed_journeys';
	}

	public static function reservations(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_reservations';
	}

	public static function documents(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_documents';
	}

	public static function notifications(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_notifications';
	}

	public static function audit_logs(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_audit_logs';
	}

	public static function sequences(): string {
		global $wpdb;
		return $wpdb->prefix . 'bmtg_pj_sequences';
	}

	/** Catalogue item types (also used by the journey basket). */
	public static function item_types(): array {
		return array(
			'journey',
			'package',
			'hotel',
			'restaurant',
			'transport',
			'train',
			'airport',
			'safari',
			'experience',
			'wellness',
			'heritage',
			'exclusive',
		);
	}

	public static function item_statuses(): array {
		return array( 'draft', 'published', 'archived' );
	}

	public static function enquiry_statuses(): array {
		return array(
			'new',
			'review',
			'supplier_check',
			'pricing',
			'internal_approval',
			'proposal_issued',
			'client_response',
			'accepted',
			'declined',
			'reservation',
			'confirmed',
			'closed',
		);
	}

	public static function quote_statuses(): array {
		return array( 'draft', 'issued', 'accepted', 'declined', 'superseded', 'expired' );
	}

	public static function journey_statuses(): array {
		return array( 'pending_supplier_confirmation', 'in_reservation', 'confirmed', 'completed', 'cancelled' );
	}

	/** Date/time helpers. All stored values are UTC. */
	public static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function today(): string {
		return gmdate( 'Y-m-d' );
	}

	public static function normalize_email( $email ): string {
		if ( ! is_string( $email ) ) {
			return '';
		}
		return strtolower( sanitize_email( trim( $email ) ) );
	}

	public static function sanitize_text( $value, int $max = 255 ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( (string) $value );
		return substr( trim( $value ), 0, $max );
	}

	public static function sanitize_textarea( $value, int $max = 20000 ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return substr( trim( sanitize_textarea_field( $value ) ), 0, $max );
	}

	public static function sanitize_date( $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$d = \DateTime::createFromFormat( '!Y-m-d', $value );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $value ) {
			return null;
		}
		return $value;
	}

	public static function json( $data ): string {
		return wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function decode( $value, $fallback = array() ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return $fallback;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : $fallback;
	}
}