<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Token_Store {
	private Config $config;
	private Audit $audit;

	public const STATUS_NOT_CONFIGURED = 'not_configured';
	public const STATUS_CONFIGURED     = 'configured';
	public const STATUS_CONNECTED      = 'connected';
	public const STATUS_ERROR          = 'error';
	public const STATUS_EXPIRED        = 'expired';
	public const STATUS_DISABLED       = 'disabled';

	public function __construct( Config $config, Audit $audit ) {
		$this->config = $config;
		$this->audit  = $audit;
	}

	/** @return array<string,mixed>|null */
	public function get_row( string $connector_id, string $environment = 'live' ): ?array {
		global $wpdb;
		$table = Migrations::table_tokens();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE connector_id = %s AND environment = %s",
			sanitize_key( $connector_id ), $this->normalize_env( $environment )
		), ARRAY_A );
		return $row ?: null;
	}

	public function save_tokens( string $connector_id, string $environment, ?string $access, ?string $refresh, ?int $expires_at_ts, ?string $scopes, string $status ): void {
		$this->config->assert_can_store();
		global $wpdb;
		$table = Migrations::table_tokens();
		$env   = $this->normalize_env( $environment );
		$now   = current_time( 'mysql', true );
		$key   = $this->config->get_key();
		if ( ! $key ) {
			throw new Misconfigured_Exception( $this->config->key_error_message() );
		}
		$cred_helper = new Credential_Store( $this->config, $this->audit );
		$data = array(
			'connector_id' => sanitize_key( $connector_id ),
			'environment'  => $env,
			'access_enc'   => $access !== null && $access !== '' ? $cred_helper->encrypt( $access ) : null,
			'refresh_enc'  => $refresh !== null && $refresh !== '' ? $cred_helper->encrypt( $refresh ) : null,
			'expires_at'   => $expires_at_ts ? gmdate( 'Y-m-d H:i:s', $expires_at_ts ) : null,
			'scopes'       => $scopes,
			'status'       => sanitize_key( $status ),
			'updated_at'   => $now,
		);
		$formats  = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		$existing = $this->get_row( $connector_id, $env );
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ), $formats, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $formats );
		}
	}

	public function update_status( string $connector_id, string $environment, string $status ): void {
		global $wpdb;
		$table    = Migrations::table_tokens();
		$env      = $this->normalize_env( $environment );
		$existing = $this->get_row( $connector_id, $env );
		$data     = array(
			'status'     => sanitize_key( $status ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ), array( '%s', '%s' ), array( '%d' ) );
		} else {
			$data['connector_id'] = sanitize_key( $connector_id );
			$data['environment']  = $env;
			$wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s' ) );
		}
	}

	public function get_status( string $connector_id, string $environment = 'live' ): string {
		$row = $this->get_row( $connector_id, $environment );
		return $row['status'] ?? self::STATUS_NOT_CONFIGURED;
	}

	public function get_access_token( string $connector_id, string $environment = 'live' ): ?string {
		$row = $this->get_row( $connector_id, $environment );
		if ( ! $row || empty( $row['access_enc'] ) ) {
			return null;
		}
		$cred = new Credential_Store( $this->config, $this->audit );
		return $cred->decrypt( $row['access_enc'] );
	}

	public function get_refresh_token( string $connector_id, string $environment = 'live' ): ?string {
		$row = $this->get_row( $connector_id, $environment );
		if ( ! $row || empty( $row['refresh_enc'] ) ) {
			return null;
		}
		$cred = new Credential_Store( $this->config, $this->audit );
		return $cred->decrypt( $row['refresh_enc'] );
	}

	public function needs_refresh( string $connector_id, string $environment = 'live', int $leeway = 120 ): bool {
		$row = $this->get_row( $connector_id, $environment );
		if ( ! $row || empty( $row['expires_at'] ) ) {
			return false;
		}
		$expires = strtotime( $row['expires_at'] . ' UTC' );
		return $expires !== false && $expires <= ( time() + $leeway );
	}

	public function lock_name( string $connector_id, string $environment ): string {
		return 'bmtg_token_' . substr( md5( $connector_id . '_' . $environment ), 0, 40 );
	}

	public function clear_tokens( string $connector_id, string $environment = 'live' ): void {
		global $wpdb;
		$table = Migrations::table_tokens();
		$wpdb->update( $table, array(
			'access_enc'  => null,
			'refresh_enc' => null,
			'expires_at'  => null,
			'scopes'      => null,
			'status'      => self::STATUS_NOT_CONFIGURED,
			'updated_at'  => current_time( 'mysql', true ),
		), array( 'connector_id' => sanitize_key( $connector_id ), 'environment' => $this->normalize_env( $environment ) ), array( '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%s', '%s' ) );
	}

	public function normalize_env( string $environment ): string {
		return in_array( $environment, array( 'live', 'sandbox' ), true ) ? $environment : 'live';
	}

	/** @return array<int,array<string,mixed>> */
	public function all_rows(): array {
		global $wpdb;
		$table = Migrations::table_tokens();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function update_row_enc( int $id, ?string $access_enc, ?string $refresh_enc ): void {
		global $wpdb;
		$wpdb->update( Migrations::table_tokens(), array(
			'access_enc'  => $access_enc,
			'refresh_enc' => $refresh_enc,
			'updated_at'  => current_time( 'mysql', true ),
		), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}
}
