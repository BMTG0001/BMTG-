<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Credential_Store {
	private Config $config;
	private Audit $audit;

	public function __construct( Config $config, Audit $audit ) {
		$this->config = $config;
		$this->audit  = $audit;
	}

	public function encrypt( string $plaintext ): string {
		$key = $this->config->get_key();
		if ( ! $key ) {
			throw new Misconfigured_Exception( $this->config->key_error_message() );
		}
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return base64_encode( $nonce . $ciphertext );
	}

	public function decrypt( string $encoded, ?string $key_override = null ): string {
		$key = $key_override ?: $this->config->get_key();
		if ( ! $key ) {
			throw new Misconfigured_Exception( $this->config->key_error_message() );
		}
		$raw = base64_decode( $encoded, true );
		if ( $raw === false || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new Misconfigured_Exception( 'Stored credential is corrupted or unreadable.' );
		}
		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		if ( $plain === false ) {
			throw new Misconfigured_Exception( 'Unable to decrypt stored credential.' );
		}
		return $plain;
	}

	public function save( string $connector_id, string $field_key, string $value, string $environment = 'live' ): void {
		$this->config->assert_can_store();
		// Never persist values overridden by wp-config constants.
		if ( $this->config->has_constant( $connector_id, $field_key ) ) {
			throw new Misconfigured_Exception( 'This field is managed in wp-config.php and cannot be stored in the database.' );
		}
		global $wpdb;
		$table = Migrations::table_credentials();
		$enc   = $this->encrypt( $value );
		$hint  = $this->config->hint_for( $value );
		$now   = current_time( 'mysql', true );
		$user  = get_current_user_id() ?: null;

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE connector_id = %s AND field_key = %s AND environment = %s",
			$connector_id, $field_key, $environment
		) );

		$data = array(
			'connector_id' => sanitize_key( $connector_id ),
			'field_key'    => sanitize_key( $field_key ),
			'environment'  => $this->normalize_env( $environment ),
			'value_enc'    => $enc,
			'value_hint'   => $hint,
			'updated_by'   => $user,
			'updated_at'   => $now,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing ), $formats, array( '%d' ) );
			$this->audit->log( $connector_id, 'credential_updated', $field_key, 'ok' );
		} else {
			$wpdb->insert( $table, $data, $formats );
			$this->audit->log( $connector_id, 'credential_saved', $field_key, 'ok' );
		}
	}

	public function get_plaintext( string $connector_id, string $field_key, string $environment = 'live' ): ?string {
		$resolved = $this->config->resolve_field_value( $connector_id, $field_key, $environment );
		if ( $resolved ) {
			return $resolved['value'];
		}
		global $wpdb;
		$table = Migrations::table_credentials();
		$enc   = $wpdb->get_var( $wpdb->prepare(
			"SELECT value_enc FROM {$table} WHERE connector_id = %s AND field_key = %s AND environment = %s",
			$connector_id, $field_key, $this->normalize_env( $environment )
		) );
		if ( ! $enc ) {
			return null;
		}
		return $this->decrypt( $enc );
	}

	/** @return array{source:string,hint:string,updated_at:?string}|null */
	public function get_metadata( string $connector_id, string $field_key, string $environment = 'live' ): ?array {
		$resolved = $this->config->resolve_field_value( $connector_id, $field_key, $environment );
		if ( $resolved ) {
			return array( 'source' => 'wp-config', 'hint' => $resolved['hint'], 'updated_at' => null );
		}
		global $wpdb;
		$table = Migrations::table_credentials();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT value_hint, updated_at FROM {$table} WHERE connector_id = %s AND field_key = %s AND environment = %s",
			$connector_id, $field_key, $this->normalize_env( $environment )
		), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		return array( 'source' => 'database', 'hint' => $row['value_hint'], 'updated_at' => $row['updated_at'] );
	}

	public function remove( string $connector_id, string $field_key, string $environment = 'live' ): bool {
		if ( $this->config->has_constant( $connector_id, $field_key ) ) {
			throw new Misconfigured_Exception( 'This field is managed in wp-config.php and cannot be removed from the database here.' );
		}
		global $wpdb;
		$table   = Migrations::table_credentials();
		$deleted = $wpdb->delete( $table, array(
			'connector_id' => sanitize_key( $connector_id ),
			'field_key'    => sanitize_key( $field_key ),
			'environment'  => $this->normalize_env( $environment ),
		), array( '%s', '%s', '%s' ) );
		if ( $deleted ) {
			$this->audit->log( $connector_id, 'credential_removed', $field_key, 'ok' );
		}
		return (bool) $deleted;
	}

	public function has_field( string $connector_id, string $field_key, string $environment = 'live' ): bool {
		return $this->get_metadata( $connector_id, $field_key, $environment ) !== null;
	}

	public function normalize_env( string $environment ): string {
		return in_array( $environment, array( 'live', 'sandbox' ), true ) ? $environment : 'live';
	}

	/** @return array<int,array<string,mixed>> */
	public function all_rows(): array {
		global $wpdb;
		$table = Migrations::table_credentials();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function update_row_enc( int $id, string $value_enc, string $hint ): void {
		global $wpdb;
		$wpdb->update( Migrations::table_credentials(), array(
			'value_enc'  => $value_enc,
			'value_hint' => $hint,
			'updated_at' => current_time( 'mysql', true ),
		), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}
}
