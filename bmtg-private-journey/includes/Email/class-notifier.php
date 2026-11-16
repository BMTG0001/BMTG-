<?php
namespace BMTG\PrivateJourney\Email;

use BMTG\PrivateJourney\Core\Brand;
use BMTG\PrivateJourney\Core\Schema;
use BMTG\PrivateJourney\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal notification email only.
 * No automatic client acknowledgement or copy is ever sent.
 * The client address may be used only as Reply-To.
 */
class Notifier {
	private static ?Notifier $instance = null;

	public static function instance(): Notifier {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function notify_new_enquiry( int $enquiry_id ): bool {
		global $wpdb;
		$enquiry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::enquiries() . " WHERE id = %d", $enquiry_id ), ARRAY_A );
		if ( ! $enquiry ) {
			return false;
		}

		$to = Settings::email_list( 'email', 'internal_recipients' );
		if ( empty( $to ) ) {
			$fallback = Brand::contact_email();
			if ( $fallback ) {
				$to = array( $fallback );
			}
		}
		if ( empty( $to ) ) {
			$this->record( 'enquiry_new', '', __( 'New enquiry', 'bmtg-private-journey' ) . ' ' . $enquiry['reference'], '', 'failed', 'No internal recipient configured in Private Journey settings.' );
			return false;
		}

		$client_email = Schema::normalize_email( $enquiry['email'] );
		$to           = $this->strip_client( $to, $client_email );
		if ( empty( $to ) ) {
			$this->record( 'enquiry_new', '', __( 'New enquiry', 'bmtg-private-journey' ) . ' ' . $enquiry['reference'], '', 'failed', 'All configured recipients matched the client address and were removed.' );
			return false;
		}

		$cc = $this->strip_client( Settings::email_list( 'email', 'internal_cc' ), $client_email );

		$subject = sprintf( __( 'New Private Journey enquiry %s', 'bmtg-private-journey' ), $enquiry['reference'] );
		$body    = $this->enquiry_body( $enquiry );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( is_email( $enquiry['email'] ) && (int) Settings::get( 'email', 'reply_to_client', 1 ) ) {
			$reply_name = sanitize_text_field( $enquiry['name'] );
			$headers[]  = 'Reply-To: ' . $reply_name . ' <' . $enquiry['email'] . '>';
		}
		if ( ! empty( $cc ) ) {
			$headers[] = 'Cc: ' . implode( ',', $cc );
		}
		$headers = $this->apply_from( $headers );

		return $this->dispatch( 'enquiry_new', $to, $subject, $body, $headers, 'enquiry:' . $enquiry_id . ':new' );
	}

	public function notify_quotation_issued( int $quotation_id ): bool {
		global $wpdb;
		$quote = $wpdb->get_row( $wpdb->prepare( "SELECT q.*, e.reference, e.name FROM " . Schema::quotation_versions() . " q LEFT JOIN " . Schema::enquiries() . " e ON e.id = q.enquiry_id WHERE q.id = %d", $quotation_id ), ARRAY_A );
		if ( ! $quote ) {
			return false;
		}

		$to = Settings::email_list( 'email', 'internal_recipients' );
		if ( empty( $to ) ) {
			$fallback = Brand::contact_email();
			if ( $fallback ) {
				$to = array( $fallback );
			}
		}
		if ( empty( $to ) ) {
			return false;
		}

		$subject = sprintf( __( 'Quotation issued: %s (v%d)', 'bmtg-private-journey' ), $quote['reference'], (int) $quote['version'] );
		$body    = sprintf(
			__( "Quotation version %1\$d for enquiry %2\$s has been issued.\n\nClient: %3\$s\nStatus: %4\$s\n\nReview: %5\$s", 'bmtg-private-journey' ),
			(int) $quote['version'],
			$quote['reference'],
			$quote['name'],
			$quote['status'],
			admin_url( 'admin.php?page=bmtg-private-journey-quotes&action=edit&id=' . $quotation_id )
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$headers = $this->apply_from( $headers );

		return $this->dispatch( 'quotation_issued', $to, $subject, $body, $headers, 'quote:' . $quotation_id . ':issued' );
	}

	private function enquiry_body( array $enquiry ): string {
		global $wpdb;
		$table = Schema::enquiry_items();
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT description, quantity, service_type FROM $table WHERE enquiry_id = %d ORDER BY sort_order", (int) $enquiry['id'] ), ARRAY_A );

		$lines   = array();
		$lines[] = sprintf( __( 'Reference: %s', 'bmtg-private-journey' ), $enquiry['reference'] );
		$lines[] = sprintf( __( 'Client: %s', 'bmtg-private-journey' ), $enquiry['name'] );
		$lines[] = sprintf( __( 'Email: %s', 'bmtg-private-journey' ), $enquiry['email'] );
		$lines[] = sprintf( __( 'Phone: %s', 'bmtg-private-journey' ), $enquiry['phone'] ?: '-' );
		$lines[] = sprintf( __( 'Destinations: %s', 'bmtg-private-journey' ), $enquiry['destinations'] ?: '-' );
		$lines[] = sprintf( __( 'Dates: %s to %s', 'bmtg-private-journey' ), $enquiry['arrival_date'] ?: '-', $enquiry['departure_date'] ?: '-' );
		$lines[] = sprintf( __( 'Guests: %d adults, %d children', 'bmtg-private-journey' ), (int) $enquiry['adults'], (int) $enquiry['children'] );
		$lines[] = sprintf( __( 'Budget: %s', 'bmtg-private-journey' ), $enquiry['budget'] ?: '-' );
		$lines[] = '';
		$lines[] = __( 'Selected services:', 'bmtg-private-journey' );
		foreach ( (array) $items as $item ) {
			$lines[] = sprintf( '- %s (%s) x%d', $item['description'], $item['service_type'], (int) $item['quantity'] );
		}
		if ( $enquiry['preferences'] ) {
			$lines[] = '';
			$lines[] = __( 'Travel preferences:', 'bmtg-private-journey' );
			$lines[] = $enquiry['preferences'];
		}
		if ( $enquiry['special_requests'] ) {
			$lines[] = '';
			$lines[] = __( 'Special requests:', 'bmtg-private-journey' );
			$lines[] = $enquiry['special_requests'];
		}
		$lines[] = '';
		$lines[] = sprintf( __( 'Review in WordPress: %s', 'bmtg-private-journey' ), admin_url( 'admin.php?page=bmtg-private-journey-requests&action=edit&id=' . (int) $enquiry['id'] ) );

		return implode( "\n", $lines );
	}

	private function apply_from( array $headers ): array {
		$name = (string) Settings::get( 'email', 'from_name', '' );
		$name = $name ? $name : Brand::company_name();
		$email = (string) Settings::get( 'email', 'from_email', '' );
		if ( ! is_email( $email ) ) {
			$email = Brand::contact_email();
		}
		if ( is_email( $email ) ) {
			$headers[] = 'From: ' . $name . ' <' . $email . '>';
		}
		return $headers;
	}

	private function strip_client( array $recipients, string $client_email ): array {
		if ( ! $client_email ) {
			return $recipients;
		}
		$out = array();
		foreach ( $recipients as $recipient ) {
			if ( strtolower( trim( $recipient ) ) !== $client_email ) {
				$out[] = $recipient;
			}
		}
		return $out;
	}

	private function dispatch( string $type, array $to, string $subject, string $body, array $headers, string $dedupe ): bool {
		global $wpdb;
		$table = Schema::notifications();
		$key   = substr( $dedupe, 0, 64 );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE dedupe_key = %s", $key ), ARRAY_A );
		if ( $existing ) {
			return 'sent' === $existing['status'];
		}

		$wpdb->insert(
			$table,
			array(
				'type'       => substr( $type, 0, 40 ),
				'recipient'  => implode( ',', array_map( 'strval', $to ) ),
				'subject'    => substr( $subject, 0, 255 ),
				'body'       => $body,
				'status'     => 'pending',
				'dedupe_key' => $key,
				'created_at' => Schema::now(),
			)
		);

		$sent = wp_mail( $to, $subject, $body, $headers );
		if ( $sent ) {
			$wpdb->update( $table, array( 'status' => 'sent', 'sent_at' => Schema::now(), 'error' => null ), array( 'dedupe_key' => $key ) );
			return true;
		}

		$wpdb->update( $table, array( 'status' => 'failed', 'error' => 'wp_mail returned false' ), array( 'dedupe_key' => $key ) );
		return false;
	}

	private function record( string $type, string $recipient, string $subject, string $body, string $status, string $error ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::notifications(),
			array(
				'type'       => $type,
				'recipient'  => $recipient,
				'subject'    => substr( $subject, 0, 255 ),
				'body'       => $body,
				'status'     => $status,
				'dedupe_key' => substr( 'manual-' . md5( $type . $subject . microtime( true ) ), 0, 64 ),
				'error'      => $error,
				'created_at' => Schema::now(),
			)
		);
	}
}