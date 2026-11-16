<?php
namespace BMTG\PrivateJourney\Clients;

use BMTG\PrivateJourney\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client profile persistence, linked to a WordPress user when available.
 */
class Clients {
	private static ?Clients $instance = null;

	public static function instance(): Clients {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::clients();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function get_by_user( int $wp_user_id ): ?array {
		global $wpdb;
		$table = Schema::clients();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE wp_user_id = %d ORDER BY id ASC LIMIT 1", $wp_user_id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function get_by_email( string $email ): ?array {
		global $wpdb;
		$email = Schema::normalize_email( $email );
		if ( ! $email ) {
			return null;
		}
		$table = Schema::clients();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email_normalized = %s ORDER BY id ASC LIMIT 1", $email ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	private function hydrate( array $row ): array {
		$row['preferences']  = Schema::decode( $row['preferences'] ?? '', array() );
		$row['full_name']    = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		$row['phone_verified'] = (int) ( $row['phone_verified'] ?? 0 );
		return $row;
	}

	public function create( array $data, ?int $wp_user_id = null ): int {
		global $wpdb;
		$table = Schema::clients();
		$now   = Schema::now();
		$email = Schema::normalize_email( $data['email'] ?? '' );

		$wpdb->insert(
			$table,
			array(
				'wp_user_id'         => $wp_user_id ?: null,
				'email'              => is_string( $data['email'] ?? '' ) ? trim( $data['email'] ) : '',
				'email_normalized'   => $email,
				'first_name'         => Schema::sanitize_text( $data['first_name'] ?? '', 191 ),
				'last_name'          => Schema::sanitize_text( $data['last_name'] ?? '', 191 ),
				'phone'              => Schema::sanitize_text( $data['phone'] ?? '', 40 ),
				'phone_verified'     => empty( $data['phone_verified'] ) ? 0 : 1,
				'preferences'        => Schema::json( isset( $data['preferences'] ) && is_array( $data['preferences'] ) ? $data['preferences'] : array() ),
				'privacy_consent_at' => ( ! empty( $data['privacy_consent'] ) || ! empty( $data['consent'] ) ) ? $now : null,
				'status'             => 'active',
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;
		if ( ! $this->get( $id ) ) {
			return false;
		}
		$table = Schema::clients();
		$row   = array( 'updated_at' => Schema::now() );

		if ( array_key_exists( 'first_name', $data ) ) {
			$row['first_name'] = Schema::sanitize_text( $data['first_name'], 191 );
		}
		if ( array_key_exists( 'last_name', $data ) ) {
			$row['last_name'] = Schema::sanitize_text( $data['last_name'], 191 );
		}
		if ( array_key_exists( 'phone', $data ) ) {
			$row['phone'] = Schema::sanitize_text( $data['phone'], 40 );
		}
		if ( array_key_exists( 'phone_verified', $data ) ) {
			$row['phone_verified'] = empty( $data['phone_verified'] ) ? 0 : 1;
		}
		if ( array_key_exists( 'email', $data ) ) {
			$email = Schema::normalize_email( $data['email'] );
			if ( $email ) {
				$row['email']            = trim( (string) $data['email'] );
				$row['email_normalized'] = $email;
			}
		}
		if ( array_key_exists( 'preferences', $data ) && is_array( $data['preferences'] ) ) {
			$row['preferences'] = Schema::json( $data['preferences'] );
		}
		if ( ! empty( $data['privacy_consent'] ) || ! empty( $data['consent'] ) ) {
			$row['privacy_consent_at'] = Schema::now();
		}

		return false !== $wpdb->update( $table, $row, array( 'id' => $id ) );
	}

	/** Create or return the client profile for a logged-in user. */
	public function ensure_for_user( int $wp_user_id ): ?array {
		$existing = $this->get_by_user( $wp_user_id );
		if ( $existing ) {
			return $existing;
		}
		$user = get_userdata( $wp_user_id );
		if ( ! $user ) {
			return null;
		}
		$by_email = $this->get_by_email( $user->user_email );
		if ( $by_email ) {
			$this->link_user( (int) $by_email['id'], $wp_user_id );
			return $this->get( (int) $by_email['id'] );
		}
		$first = $user->first_name;
		$last  = $user->last_name;
		if ( ! $first && ! $last ) {
			$parts = explode( ' ', trim( $user->display_name ? $user->display_name : $user->user_login ), 2 );
			$first = $parts[0] ?? '';
			$last  = $parts[1] ?? '';
		}
		$id = $this->create(
			array(
				'email'      => $user->user_email,
				'first_name' => $first,
				'last_name'  => $last,
				'phone'      => '',
			),
			$wp_user_id
		);
		return $this->get( $id );
	}

	public function link_user( int $client_id, int $wp_user_id ): void {
		global $wpdb;
		$table = Schema::clients();
		$wpdb->update( $table, array( 'wp_user_id' => $wp_user_id, 'updated_at' => Schema::now() ), array( 'id' => $client_id ) );
	}

	public function set_preference( int $client_id, string $key, $value, string $source = 'client' ): void {
		global $wpdb;
		$key = substr( sanitize_key( $key ), 0, 100 );
		if ( '' === $key ) {
			return;
		}
		$table = Schema::client_preferences();
		$now   = Schema::now();
		$val   = Schema::json( $value );
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE client_id = %d AND pref_key = %s", $client_id, $key ) );
		if ( $found ) {
			$wpdb->update( $table, array( 'pref_value' => $val, 'source' => $source, 'updated_at' => $now ), array( 'id' => (int) $found ) );
			return;
		}
		$wpdb->insert( $table, array( 'client_id' => $client_id, 'pref_key' => $key, 'pref_value' => $val, 'source' => $source, 'created_at' => $now, 'updated_at' => $now ) );
	}

	public function preferences( int $client_id ): array {
		global $wpdb;
		$table = Schema::client_preferences();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT pref_key, pref_value FROM $table WHERE client_id = %d", $client_id ), ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$value = Schema::decode( $row['pref_value'], null );
			$out[ $row['pref_key'] ] = $value;
		}
		return $out;
	}

	public function list_clients( array $args = array() ): array {
		global $wpdb;
		$table = Schema::clients();
		$args  = wp_parse_args( $args, array( 'search' => '', 'page' => 1, 'per_page' => 20, 'status' => '' ) );
		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
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

		return array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}
}