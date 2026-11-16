<?php
namespace BMTG\PrivateJourney\Journey;

use BMTG\PrivateJourney\Catalogue\Catalogue;
use BMTG\PrivateJourney\Catalogue\Validator as LineValidator;
use BMTG\PrivateJourney\Clients\Clients;
use BMTG\PrivateJourney\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Journey basket/draft management.
 * Guests use a signed random cookie token; logged-in clients use their profile.
 * Saving a journey requires a logged-in client.
 */
class Basket {
	public const COOKIE = 'bmtg_pj_guest';

	private static ?Basket $instance = null;

	public static function instance(): Basket {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'ensure_guest_cookie' ), 5 );
	}

	public function ensure_guest_cookie(): void {
		if ( is_user_logged_in() || defined( 'DOING_CRON' ) && DOING_CRON || 'cli' === PHP_SAPI ) {
			return;
		}
		if ( isset( $_COOKIE[ self::COOKIE ] ) && preg_match( '/^[A-Za-z0-9]{20,64}$/', (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) {
			return;
		}
		$token = wp_generate_password( 40, false, false );
		$_COOKIE[ self::COOKIE ] = $token;
		$this->set_cookie( $token, 0 );
	}

	private function set_cookie( string $token, int $expires ): void {
		$secure   = is_ssl();
		$path     = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain   = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		$httponly = true;
		$samesite = 'Lax';

		if ( headers_sent() ) {
			return;
		}

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( self::COOKIE, $token, array(
				'expires'  => $expires,
				'path'     => $path,
				'domain'   => $domain,
				'secure'   => $secure,
				'httponly' => $httponly,
				'samesite' => $samesite,
			) );
			return;
		}

		$path .= '; SameSite=' . $samesite;
		setcookie( self::COOKIE, $token, $expires, $path . '; Secure' . ( $secure ? '' : '' ) . '; HttpOnly', $domain, $secure, true );
	}

	public function guest_token(): string {
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$token = (string) wp_unslash( $_COOKIE[ self::COOKIE ] );
			if ( preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) ) {
				return $token;
			}
		}
		return '';
	}

	public function clear_guest_cookie(): void {
		unset( $_COOKIE[ self::COOKIE ] );
		if ( ! headers_sent() ) {
			$secure = is_ssl();
			$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
			$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
			if ( PHP_VERSION_ID >= 70300 ) {
				setcookie( self::COOKIE, '', array(
					'expires'  => time() - YEAR_IN_SECONDS,
					'path'     => $path,
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => true,
					'samesite' => 'Lax',
				) );
			} else {
				setcookie( self::COOKIE, '', time() - YEAR_IN_SECONDS, $path, $domain, $secure, true );
			}
		}
	}

	/**
	 * Return the active draft for the current visitor, creating it if needed.
	 */
	public function current(): array {
		global $wpdb;
		$table = Schema::journey_drafts();

		$client_id = 0;
		if ( is_user_logged_in() ) {
			$client    = Clients::instance()->ensure_for_user( get_current_user_id() );
			$client_id = $client ? (int) $client['id'] : 0;
		}

		$row = null;
		if ( $client_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE client_id = %d AND status IN ('draft','saved') ORDER BY updated_at DESC, id DESC LIMIT 1", $client_id ), ARRAY_A );
		} else {
			$token = $this->guest_token();
			if ( $token ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE token = %s AND client_id IS NULL AND status IN ('draft','saved') ORDER BY updated_at DESC, id DESC LIMIT 1", $token ), ARRAY_A );
			}
		}

		if ( $row ) {
			return $row;
		}

		return $this->create_draft( $client_id, is_user_logged_in() ? get_current_user_id() : 0 );
	}

	private function create_draft( int $client_id, int $wp_user_id ): array {
		global $wpdb;
		$table = Schema::journey_drafts();
		$now   = Schema::now();
		$token = wp_generate_password( 40, false, false );

		$wpdb->insert(
			$table,
			array(
				'client_id'  => $client_id ?: null,
				'wp_user_id' => $wp_user_id ?: null,
				'token'      => $token,
				'title'      => '',
				'notes'      => '',
				'status'     => 'draft',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $wpdb->insert_id ), ARRAY_A );
	}

	public function touch( int $journey_id ): void {
		global $wpdb;
		$table = Schema::journey_drafts();
		$wpdb->update( $table, array( 'updated_at' => Schema::now() ), array( 'id' => $journey_id ) );
	}

	public function items( ?int $journey_id = null ): array {
		global $wpdb;
		$journey = $journey_id ?: (int) ( $this->current()['id'] ?? 0 );
		if ( ! $journey ) {
			return array();
		}
		$ji  = Schema::journey_items();
		$ci  = Schema::catalogue_items();
		$so  = Schema::service_options();
		$sql = "SELECT ji.*, ci.title, ci.item_type AS catalogue_type, ci.destination, ci.country, ci.price AS catalogue_price, ci.price_type AS catalogue_price_type, ci.currency, ci.cover_image_id,
					so.label AS option_label, so.price AS option_price
				FROM $ji ji
				LEFT JOIN $ci ci ON ci.id = ji.item_id
				LEFT JOIN $so so ON so.id = ji.option_id
				WHERE ji.journey_id = %d
				ORDER BY ji.sort_order ASC, ji.id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $journey ), ARRAY_A );
		foreach ( (array) $rows as &$row ) {
			$row['meta']      = Schema::decode( $row['meta'] ?? '', array() );
			$row['gallery']   = array();
			$row['cover_url'] = ! empty( $row['cover_image_id'] ) ? (string) wp_get_attachment_image_url( (int) $row['cover_image_id'], 'medium' ) : '';
			$row['display_price'] = $this->line_price( $row );
		}
		return $rows;
	}

	public function line_price( array $row ): array {
		if ( isset( $row['option_price'] ) && null !== $row['option_price'] ) {
			$price = (float) $row['option_price'];
			$type  = 'fixed';
		} elseif ( isset( $row['catalogue_price'] ) && null !== $row['catalogue_price'] && 'fixed' === ( $row['catalogue_price_type'] ?? 'poa' ) ) {
			$price = (float) $row['catalogue_price'];
			$type  = 'fixed';
		} else {
			return array( 'amount' => null, 'type' => 'poa', 'total' => null );
		}
		$qty   = max( 1, (int) ( $row['quantity'] ?? 1 ) );
		return array(
			'amount' => $price,
			'type'   => $type,
			'total'  => $price * $qty,
			'qty'    => $qty,
		);
	}

	public function add_item( array $data ): array {
		global $wpdb;
		$journey = $this->current();
		$item_id = (int) ( $data['item_id'] ?? 0 );
		$item    = Catalogue::instance()->get( $item_id );

		if ( ! $item ) {
			return array( 'ok' => false, 'error' => __( 'The selected service could not be found.', 'bmtg-private-journey' ) );
		}
		if ( 'published' !== ( $item['status'] ?? '' ) || empty( $item['public_visibility'] ) ) {
			return array( 'ok' => false, 'error' => __( 'This service is not available in the public collection.', 'bmtg-private-journey' ) );
		}

		$line = $this->sanitize_line( $data, $item );
		$line['journey_id'] = (int) $journey['id'];

		$max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM " . Schema::journey_items() . " WHERE journey_id = %d", $line['journey_id'] ) );
		$line['sort_order'] = $max + 1;
		$line['created_at'] = Schema::now();
		$line['updated_at'] = Schema::now();

		$wpdb->insert( Schema::journey_items(), $line );
		$id = (int) $wpdb->insert_id;
		$this->touch( (int) $journey['id'] );

		$errors = LineValidator::validate_line( array_merge( $line, array( 'id' => $id ) ), $item );
		return array( 'ok' => true, 'id' => $id, 'warnings' => $errors );
	}

	public function update_item( int $item_id, array $data ): array {
		global $wpdb;
		$journey = $this->current();
		$table   = Schema::journey_items();
		$exists  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d AND journey_id = %d", $item_id, (int) $journey['id'] ) );
		if ( ! $exists ) {
			return array( 'ok' => false, 'error' => __( 'This journey item was not found.', 'bmtg-private-journey' ) );
		}

		$catalogue_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT item_id FROM $table WHERE id = %d", $item_id ) );
		$item         = Catalogue::instance()->get( $catalogue_id );
		$line         = $this->sanitize_line( $data, (array) $item );

		unset( $line['journey_id'] );
		$line['updated_at'] = Schema::now();
		$wpdb->update( $table, $line, array( 'id' => $item_id ) );
		$this->touch( (int) $journey['id'] );

		$errors = LineValidator::validate_line( array_merge( $line, array( 'id' => $item_id ) ), (array) $item );
		return array( 'ok' => true, 'warnings' => $errors );
	}

	public function remove_item( int $item_id ): bool {
		global $wpdb;
		$journey = $this->current();
		$table   = Schema::journey_items();
		$wpdb->delete( $table, array( 'id' => $item_id, 'journey_id' => (int) $journey['id'] ) );
		$this->touch( (int) $journey['id'] );
		return true;
	}

	public function reorder( array $ids ): void {
		global $wpdb;
		$journey = $this->current();
		$table   = Schema::journey_items();
		$order   = 0;
		foreach ( $ids as $id ) {
			$order++;
			$wpdb->update( $table, array( 'sort_order' => $order, 'updated_at' => Schema::now() ), array( 'id' => (int) $id, 'journey_id' => (int) $journey['id'] ) );
		}
		$this->touch( (int) $journey['id'] );
	}

	public function clear(): void {
		global $wpdb;
		$journey = $this->current();
		$wpdb->delete( Schema::journey_items(), array( 'journey_id' => (int) $journey['id'] ) );
		$this->touch( (int) $journey['id'] );
	}

	public function count(): int {
		$journey = $this->current();
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . Schema::journey_items() . " WHERE journey_id = %d", (int) $journey['id'] ) );
	}

	public function has_items(): bool {
		return $this->count() > 0;
	}

	/**
	 * Persist the journey against the logged-in client. Guests are refused.
	 *
	 * @return array{ok:bool,error?:string,journey_id?:int}
	 */
	public function save( ?string $title = null, ?string $notes = null ): array {
		if ( ! is_user_logged_in() ) {
			return array( 'ok' => false, 'error' => __( 'Please sign in to save your journey.', 'bmtg-private-journey' ) );
		}
		global $wpdb;
		$client    = Clients::instance()->ensure_for_user( get_current_user_id() );
		if ( ! $client ) {
			return array( 'ok' => false, 'error' => __( 'Your client profile could not be created.', 'bmtg-private-journey' ) );
		}

		$guest_token = $this->guest_token();
		$journey     = null;

		if ( $guest_token ) {
			$journey = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::journey_drafts() . " WHERE token = %s AND client_id IS NULL ORDER BY id DESC LIMIT 1", $guest_token ), ARRAY_A );
		}
		if ( ! $journey ) {
			$journey = $this->current();
		}

		$table = Schema::journey_drafts();
		$wpdb->update(
			$table,
			array(
				'client_id'  => (int) $client['id'],
				'wp_user_id' => get_current_user_id(),
				'token'      => wp_generate_password( 40, false, false ),
				'status'     => 'saved',
				'title'      => $title ? Schema::sanitize_text( $title, 191 ) : ( $journey['title'] ?: '' ),
				'notes'      => null !== $notes ? Schema::sanitize_textarea( $notes, 5000 ) : ( $journey['notes'] ?? '' ),
				'updated_at' => Schema::now(),
			),
			array( 'id' => (int) $journey['id'] )
		);

		$this->clear_guest_cookie();

		return array( 'ok' => true, 'journey_id' => (int) $journey['id'] );
	}

	/** Move any guest basket into the client account after sign-in. */
	public function merge_guest_into_client( int $client_id, int $wp_user_id = 0 ): void {
		global $wpdb;
		$token = $this->guest_token();
		if ( ! $token ) {
			return;
		}
		$table = Schema::journey_drafts();
		$wpdb->update(
			$table,
			array(
				'client_id'  => $client_id,
				'wp_user_id' => $wp_user_id,
				'token'      => wp_generate_password( 40, false, false ),
				'status'     => 'saved',
				'updated_at' => Schema::now(),
			),
			array( 'token' => $token, 'client_id' => null )
		);
		$this->clear_guest_cookie();
	}

	private function sanitize_line( array $data, array $item ): array {
		$option_id = (int) ( $data['option_id'] ?? 0 );

		$meta = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();
		unset( $meta['internal'], $meta['supplier_cost'] );

		return array(
			'item_id'       => (int) ( $data['item_id'] ?? $item['id'] ?? 0 ),
			'service_type'  => (string) ( $item['item_type'] ?? $data['service_type'] ?? 'experience' ),
			'option_id'     => $option_id ?: null,
			'quantity'      => max( 1, (int) ( $data['quantity'] ?? 1 ) ),
			'start_date'    => Schema::sanitize_date( $data['start_date'] ?? '' ),
			'end_date'      => Schema::sanitize_date( $data['end_date'] ?? '' ),
			'adults'        => max( 0, (int) ( $data['adults'] ?? 0 ) ),
			'children'      => max( 0, (int) ( $data['children'] ?? 0 ) ),
			'rooms'         => max( 0, (int) ( $data['rooms'] ?? 0 ) ),
			'pickup'        => Schema::sanitize_text( $data['pickup'] ?? '', 191 ),
			'dropoff'       => Schema::sanitize_text( $data['dropoff'] ?? '', 191 ),
			'flight_number' => strtoupper( Schema::sanitize_text( $data['flight_number'] ?? '', 40 ) ),
			'time'          => Schema::sanitize_text( $data['time'] ?? '', 10 ),
			'notes'         => Schema::sanitize_textarea( $data['notes'] ?? '', 2000 ),
			'meta'          => Schema::json( $meta + array( 'time' => Schema::sanitize_text( $data['time'] ?? '', 10 ) ) ),
		);
	}
}