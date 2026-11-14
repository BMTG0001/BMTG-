<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schema {
	public static function customers(): string { global $wpdb; return $wpdb->prefix . 'bmtg_crm_customers'; }
	public static function preferences(): string { global $wpdb; return $wpdb->prefix . 'bmtg_crm_customer_preferences'; }
	public static function leads(): string { global $wpdb; return $wpdb->prefix . 'bmtg_crm_leads'; }
	public static function status_history(): string { global $wpdb; return $wpdb->prefix . 'bmtg_crm_lead_status_history'; }
	public static function activities(): string { global $wpdb; return $wpdb->prefix . 'bmtg_crm_lead_activities'; }
	public static function reminders(): string { global $wpdb; return $wpdb->prefix . 'bmtg_crm_reminders'; }
	public static function sequences(): string { global $wpdb; return $wpdb->prefix . 'bmtg_crm_sequences'; }

	public static function lead_statuses(): array {
		return array( 'new', 'contacted', 'qualified', 'proposal', 'negotiating', 'won', 'lost', 'on_hold' );
	}

	public static function reminder_statuses(): array {
		return array( 'pending', 'done', 'snoozed', 'cancelled' );
	}

	public static function now_gmt(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function normalize_email( $email ): string {
		$email = is_string( $email ) ? trim( strtolower( $email ) ) : '';
		$email = sanitize_email( $email );
		return $email ? strtolower( $email ) : '';
	}

	public static function normalize_phone( $phone ): string {
		if ( ! is_string( $phone ) && ! is_numeric( $phone ) ) {
			return '';
		}
		$phone = trim( (string) $phone );
		if ( $phone === '' ) {
			return '';
		}
		$plus = strpos( $phone, '+' ) === 0 ? '+' : '';
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( $digits === '' ) {
			return '';
		}
		return $plus . $digits;
	}

	public static function sanitize_text( $value, int $max = 255 ): string {
		$value = is_string( $value ) ? sanitize_text_field( $value ) : '';
		return mb_substr( trim( $value ), 0, $max );
	}

	public static function sanitize_optional_date( $value ): ?string {
		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			return null;
		}
		$value = trim( $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		return $value;
	}

	public static function sanitize_datetime( $value ): ?string {
		if ( ! is_string( $value ) || trim( $value ) === '' ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( $ts === false ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
