<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Customer_Service {
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::customers() . " WHERE id = %d AND deleted_at IS NULL", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function list( array $args, bool $view_all, int $current_user ): array {
		global $wpdb;
		$table = Schema::customers();
		$where = array( 'deleted_at IS NULL' );
		$params = array();
		if ( ! $view_all ) {
			$where[] = 'assigned_to = %d';
			$params[] = $current_user;
		}
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( Schema::sanitize_text( $args['search'], 100 ) ) . '%';
			$where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push( $params, $like, $like, $like, $like );
		}
		$sql_where = 'WHERE ' . implode( ' AND ', $where );
		$per = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset = ( $page - 1 ) * $per;
		$count_sql = "SELECT COUNT(*) FROM {$table} {$sql_where}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql = "SELECT * FROM {$table} {$sql_where} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		return array( 'items' => $rows ?: array(), 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	public function create( array $data ): array {
		global $wpdb;
		$now = Schema::now_gmt();
		$email = Schema::normalize_email( $data['email'] ?? '' );
		$phone = Schema::normalize_phone( $data['phone'] ?? '' );
		$existing = $this->find_by_contact( $email, $phone );
		if ( $existing ) {
			return $this->update( (int) $existing['id'], $data, true );
		}
		$row = array(
			'first_name' => Schema::sanitize_text( $data['first_name'] ?? '', 100 ),
			'last_name'  => Schema::sanitize_text( $data['last_name'] ?? '', 100 ),
			'email'      => Schema::sanitize_text( $data['email'] ?? '', 190 ),
			'email_normalized' => $email,
			'phone'      => Schema::sanitize_text( $data['phone'] ?? '', 50 ),
			'phone_normalized' => $phone,
			'country'    => Schema::sanitize_text( $data['country'] ?? '', 100 ),
			'wp_user_id' => ! empty( $data['wp_user_id'] ) ? (int) $data['wp_user_id'] : null,
			'source'     => Schema::sanitize_text( $data['source'] ?? '', 64 ),
			'assigned_to'=> ! empty( $data['assigned_to'] ) ? (int) $data['assigned_to'] : null,
			'status'     => Schema::sanitize_text( $data['status'] ?? 'active', 32 ),
			'notes_internal' => isset( $data['notes_internal'] ) ? sanitize_textarea_field( (string) $data['notes_internal'] ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$wpdb->insert( Schema::customers(), $row );
		return $this->get( (int) $wpdb->insert_id );
	}

	public function update( int $id, array $data, bool $merge_empty = false ): ?array {
		global $wpdb;
		$existing = $this->get( $id );
		if ( ! $existing ) {
			return null;
		}
		$fields = array();
		foreach ( array( 'first_name','last_name','country','source','status' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$fields[ $k ] = Schema::sanitize_text( $data[ $k ], $k === 'country' ? 100 : ( $k === 'source' || $k === 'status' ? 64 : 100 ) );
			}
		}
		if ( array_key_exists( 'email', $data ) ) {
			$fields['email'] = Schema::sanitize_text( $data['email'], 190 );
			$fields['email_normalized'] = Schema::normalize_email( $data['email'] );
		}
		if ( array_key_exists( 'phone', $data ) ) {
			$fields['phone'] = Schema::sanitize_text( $data['phone'], 50 );
			$fields['phone_normalized'] = Schema::normalize_phone( $data['phone'] );
		}
		if ( array_key_exists( 'assigned_to', $data ) ) {
			$fields['assigned_to'] = $data['assigned_to'] ? (int) $data['assigned_to'] : null;
		}
		if ( array_key_exists( 'wp_user_id', $data ) ) {
			$fields['wp_user_id'] = $data['wp_user_id'] ? (int) $data['wp_user_id'] : null;
		}
		if ( array_key_exists( 'notes_internal', $data ) ) {
			$fields['notes_internal'] = sanitize_textarea_field( (string) $data['notes_internal'] );
		}
		if ( $merge_empty ) {
			foreach ( array( 'first_name','last_name','email','email_normalized','phone','phone_normalized','country','source' ) as $k ) {
				if ( isset( $fields[ $k ] ) && $fields[ $k ] === '' && ! empty( $existing[ $k ] ) ) {
					unset( $fields[ $k ] );
				}
			}
		}
		if ( empty( $fields ) ) {
			return $existing;
		}
		$fields['updated_at'] = Schema::now_gmt();
		$wpdb->update( Schema::customers(), $fields, array( 'id' => $id ) );
		return $this->get( $id );
	}

	public function find_by_contact( string $email_normalized, string $phone_normalized ): ?array {
		global $wpdb;
		$table = Schema::customers();
		if ( $email_normalized !== '' ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_normalized = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", $email_normalized ), ARRAY_A );
			if ( $row ) return $row;
		}
		if ( $phone_normalized !== '' ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE phone_normalized = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", $phone_normalized ), ARRAY_A );
			if ( $row ) return $row;
		}
		return null;
	}

	public function find_or_create_from_contact( array $contact, string $source ): array {
		$email = Schema::normalize_email( $contact['email'] ?? '' );
		$phone = Schema::normalize_phone( $contact['phone'] ?? '' );
		$existing = $this->find_by_contact( $email, $phone );
		$name = Schema::sanitize_text( $contact['name'] ?? '', 200 );
		$first = $name; $last = '';
		if ( strpos( $name, ' ' ) !== false ) {
			$parts = preg_split( '/\s+/', $name, 2 );
			$first = $parts[0] ?? '';
			$last = $parts[1] ?? '';
		}
		$data = array(
			'first_name' => $first,
			'last_name'  => $last,
			'email'      => $contact['email'] ?? '',
			'phone'      => $contact['phone'] ?? '',
			'source'     => $source,
			'status'     => 'active',
		);
		if ( $existing ) {
			return $this->update( (int) $existing['id'], $data, true );
		}
		return $this->create( $data );
	}

	public function get_preferences( int $customer_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . Schema::preferences() . " WHERE customer_id = %d ORDER BY updated_at DESC", $customer_id ), ARRAY_A );
		return $rows ?: array();
	}

	public function upsert_preference( int $customer_id, string $key, $value, string $source = '' ): void {
		global $wpdb;
		$table = Schema::preferences();
		$key = Schema::sanitize_text( $key, 100 );
		if ( $key === '' ) return;
		$now = Schema::now_gmt();
		$val = is_scalar( $value ) || $value === null ? (string) $value : wp_json_encode( $value );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE customer_id = %d AND pref_key = %s", $customer_id, $key ) );
		$row = array( 'customer_id' => $customer_id, 'pref_key' => $key, 'pref_value' => $val, 'source' => Schema::sanitize_text( $source, 64 ), 'updated_at' => $now );
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) );
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row );
		}
	}
}
