<?php
namespace BMTG\Integrations;

use BMTG\Connectors\Connector_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manager {
	private Registry $registry;
	private Config $config;
	private Credential_Store $credential_store;
	private Token_Store $token_store;
	private Http_Client $http;
	private Audit $audit;
	private OAuth_Flow $oauth;

	/** @var array<string,object> */
	private static array $clients = array();

	public function __construct( Registry $registry, Config $config, Credential_Store $credential_store, Token_Store $token_store, Http_Client $http, Audit $audit, OAuth_Flow $oauth ) {
		$this->registry         = $registry;
		$this->config           = $config;
		$this->credential_store = $credential_store;
		$this->token_store      = $token_store;
		$this->http             = $http;
		$this->audit            = $audit;
		$this->oauth            = $oauth;
	}

	/**
	 * Returns a ready-to-use connector client for feature code.
	 * @throws Not_Configured_Exception|Disabled_Exception|Expired_Exception|Error_Exception|Misconfigured_Exception
	 */
	public static function client( string $connector_id, string $environment = 'live' ): Connector_Client {
		$hub = Hub::instance();
		return $hub->manager()->get_client( $connector_id, $environment );
	}

	public function get_client( string $connector_id, string $environment = 'live' ): Connector_Client {
		$connector = $this->registry->get( $connector_id );
		if ( ! $connector ) {
			throw new Misconfigured_Exception( 'Unknown connector: ' . $connector_id, $connector_id );
		}
		$status = $this->effective_status( $connector, $environment );
		if ( $status === Token_Store::STATUS_DISABLED ) {
			throw new Disabled_Exception( 'Connector disabled.', $connector_id );
		}
		if ( $status === Token_Store::STATUS_EXPIRED ) {
			throw new Expired_Exception( 'Connector expired.', $connector_id );
		}
		if ( $status === Token_Store::STATUS_NOT_CONFIGURED ) {
			throw new Not_Configured_Exception( 'Connector not configured.', $connector_id );
		}
		if ( $status === Token_Store::STATUS_ERROR ) {
			// Allow client creation but features may get errors; throw to force clear message per blueprint.
			throw new Error_Exception( 'Connector is in error state.', $connector_id );
		}
		if ( $connector->auth_type() === 'oauth' ) {
			$ok = $this->oauth->ensure_valid_token( $connector, $environment );
			if ( empty( $ok['ok'] ) ) {
				$this->token_store->update_status( $connector_id, $environment, Token_Store::STATUS_EXPIRED );
				throw new Expired_Exception( $ok['message'] ?? 'Token expired.', $connector_id );
			}
		}
		return new Connector_Client( $connector, $environment, $this->credential_store, $this->token_store, $this->http, $this );
	}

	public function effective_status( Connector_Interface $connector, string $environment = 'live' ): string {
		$env      = $this->token_store->normalize_env( $environment );
		$disabled = get_option( 'bmtg_connector_disabled_' . $connector->id() . '_' . $env, false );
		if ( $disabled ) {
			return Token_Store::STATUS_DISABLED;
		}
		$row_status = $this->token_store->get_status( $connector->id(), $env );
		if ( $connector->auth_type() === 'api_key' ) {
			$all = $this->has_required_fields( $connector, $env );
			if ( ! $all ) {
				return Token_Store::STATUS_NOT_CONFIGURED;
			}
			if ( in_array( $row_status, array( Token_Store::STATUS_CONNECTED, Token_Store::STATUS_CONFIGURED, Token_Store::STATUS_ERROR ), true ) ) {
				return $row_status;
			}
			return Token_Store::STATUS_CONFIGURED;
		}
		// OAuth
		$has_client = $this->credential_store->has_field( $connector->id(), 'client_id', $env ) && $this->credential_store->has_field( $connector->id(), 'client_secret', $env );
		if ( ! $has_client ) {
			return Token_Store::STATUS_NOT_CONFIGURED;
		}
		$row = $this->token_store->get_row( $connector->id(), $env );
		if ( $row && ! empty( $row['access_enc'] ) ) {
			return $row_status ?: Token_Store::STATUS_CONNECTED;
		}
		return Token_Store::STATUS_CONFIGURED;
	}

	public function has_required_fields( Connector_Interface $connector, string $environment ): bool {
		foreach ( $connector->fields() as $field ) {
			if ( ! empty( $field['required'] ) ) {
				if ( ! $this->credential_store->has_field( $connector->id(), $field['key'], $environment ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** @return array<string,mixed> */
	public function connector_summary( Connector_Interface $connector, string $environment = 'live' ): array {
		$env    = $this->token_store->normalize_env( $environment );
		$status = $this->effective_status( $connector, $env );
		$fields = array();
		foreach ( $connector->fields() as $field ) {
			$key  = $field['key'];
			$meta = $this->credential_store->get_metadata( $connector->id(), $key, $env );
			$fields[] = array(
				'key'        => $key,
				'label'      => $field['label'] ?? $key,
				'secret'     => ! empty( $field['secret'] ),
				'required'   => ! empty( $field['required'] ),
				'help'       => $field['help'] ?? '',
				'configured' => $meta !== null,
				'source'     => $meta['source'] ?? null,
				'hint'       => $meta['hint'] ?? null,
				'updated_at' => $meta['updated_at'] ?? null,
			);
		}
		$last = $this->last_test( $connector->id(), $env );
		$row  = $this->token_store->get_row( $connector->id(), $env );
		return array(
			'id'        => $connector->id(),
			'label'     => $connector->label(),
			'auth_type' => $connector->auth_type(),
			'status'    => $status,
			'fields'    => $fields,
			'environment' => $env,
			'last_checked' => $last['created_at'] ?? null,
			'last_success' => $this->last_success( $connector->id(), $env ),
			'last_error'   => $status === Token_Store::STATUS_ERROR ? ( $last['message'] ?? null ) : ( $status === Token_Store::STATUS_EXPIRED ? 'Refresh failed; reconnect required.' : null ),
			'scopes'       => $row['scopes'] ?? null,
			'redirect_uri' => $connector->auth_type() === 'oauth' ? $this->oauth->redirect_uri( $connector->id() ) : null,
		);
	}

	private function last_test( string $connector_id, string $environment ): ?array {
		global $wpdb;
		$table = Migrations::table_audit();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT created_at, message, result FROM {$table} WHERE connector_id = %s AND action = %s ORDER BY id DESC LIMIT 1",
			$connector_id, 'test'
		), ARRAY_A );
		return $row ?: null;
	}

	private function last_success( string $connector_id, string $environment ): ?string {
		global $wpdb;
		$table = Migrations::table_audit();
		$val = $wpdb->get_var( $wpdb->prepare(
			"SELECT created_at FROM {$table} WHERE connector_id = %s AND action = %s AND result = %s ORDER BY id DESC LIMIT 1",
			$connector_id, 'test', 'ok'
		) );
		return $val ?: null;
	}

	public function run_test( Connector_Interface $connector, string $environment = 'live', bool $bypass_rate_limit = false ): array {
		$env = $this->token_store->normalize_env( $environment );
		if ( ! $bypass_rate_limit ) {
			// Rate limit: 1 test per connector/env per 30s per user.
			$key = 'bmtg_test_rl_' . md5( $connector->id() . $env . get_current_user_id() );
			if ( get_transient( $key ) ) {
				return array( 'ok' => false, 'message' => 'Test rate-limited. Try again shortly.' );
			}
			set_transient( $key, 1, 30 );
		}

		$resolved = array();
		foreach ( $connector->fields() as $field ) {
			$val = $this->credential_store->get_plaintext( $connector->id(), $field['key'], $env );
			if ( $val !== null ) {
				$resolved[ $field['key'] ] = $val;
			}
		}
		if ( $connector->auth_type() === 'oauth' ) {
			$ensure = $this->oauth->ensure_valid_token( $connector, $env );
			if ( empty( $ensure['ok'] ) ) {
				$this->audit->log( $connector->id(), 'test', null, 'error', $ensure['message'] ?? 'token_invalid' );
				return array( 'ok' => false, 'message' => $ensure['message'] ?? 'Token invalid.' );
			}
			$resolved['access_token'] = $this->token_store->get_access_token( $connector->id(), $env );
		}
		try {
			$result = $connector->test( $env, $resolved, $this->http );
		} catch ( \Throwable $e ) {
			$result = array( 'ok' => false, 'message' => $this->http->sanitize_provider_message( $e->getMessage() ) );
		}
		$msg = $this->http->sanitize_provider_message( (string) ( $result['message'] ?? '' ) );
		if ( ! empty( $result['ok'] ) ) {
			if ( $connector->auth_type() === 'api_key' ) {
				$this->token_store->update_status( $connector->id(), $env, Token_Store::STATUS_CONNECTED );
			} elseif ( $this->token_store->get_status( $connector->id(), $env ) !== Token_Store::STATUS_CONNECTED ) {
				$this->token_store->update_status( $connector->id(), $env, Token_Store::STATUS_CONNECTED );
			}
			$this->audit->log( $connector->id(), 'test', null, 'ok', $msg );
		} else {
			$this->token_store->update_status( $connector->id(), $env, Token_Store::STATUS_ERROR );
			$this->audit->log( $connector->id(), 'test', null, 'error', $msg );
		}
		delete_option( 'bmtg_notify_fail_count_' . $connector->id() . '_' . $env );
		return array( 'ok' => ! empty( $result['ok'] ), 'message' => $msg );
	}

	public function set_disabled( string $connector_id, string $environment, bool $disabled ): void {
		$env = $this->token_store->normalize_env( $environment );
		update_option( 'bmtg_connector_disabled_' . $connector_id . '_' . $env, $disabled ? 1 : 0, false );
		if ( $disabled ) {
			$this->token_store->update_status( $connector_id, $env, Token_Store::STATUS_DISABLED );
		} else {
			// Recompute
			$connector = $this->registry->get( $connector_id );
			if ( $connector ) {
				$status = $this->has_required_fields( $connector, $env ) ? Token_Store::STATUS_CONFIGURED : Token_Store::STATUS_NOT_CONFIGURED;
				$this->token_store->update_status( $connector_id, $env, $status );
			}
		}
		$this->audit->log( $connector_id, $disabled ? 'disabled' : 'enabled', null, 'ok' );
	}

	public function http(): Http_Client { return $this->http; }
	public function token_store(): Token_Store { return $this->token_store; }
	public function credential_store(): Credential_Store { return $this->credential_store; }
	public function audit(): Audit { return $this->audit; }
}
