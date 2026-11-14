<?php
namespace BMTG\Integrations;

use BMTG\Connectors\Connector_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OAuth_Flow {
	private Config $config;
	private Credential_Store $credential_store;
	private Token_Store $token_store;
	private Http_Client $http;
	private Audit $audit;

	private const TRANSIENT_PREFIX = 'bmtg_oauth_state_';
	private const STATE_TTL = 600; // 10 minutes

	public function __construct( Config $config, Credential_Store $credential_store, Token_Store $token_store, Http_Client $http, Audit $audit ) {
		$this->config           = $config;
		$this->credential_store = $credential_store;
		$this->token_store      = $token_store;
		$this->http             = $http;
		$this->audit            = $audit;
	}

	/** @return array{url:string,state:string}|array{error:string} */
	public function start( Connector_Interface $connector, string $environment = 'live' ): array {
		if ( $connector->auth_type() !== 'oauth' ) {
			return array( 'error' => 'Connector does not use OAuth.' );
		}
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( Activator::CAPABILITY ) ) {
			return array( 'error' => 'Permission denied.' );
		}
		$state         = wp_generate_password( 32, false, false );
		$verifier      = $this->base64url( random_bytes( 32 ) );
		$challenge     = $this->base64url( hash( 'sha256', $verifier, true ) );
		$redirect_uri  = $this->redirect_uri( $connector->id() );
		if ( strpos( $redirect_uri, 'https://' ) !== 0 && ! $this->allow_insecure_local( $redirect_uri ) ) {
			return array( 'error' => 'OAuth redirect URI must use HTTPS.' );
		}

		$record = array(
			'connector_id' => $connector->id(),
			'environment'  => $this->token_store->normalize_env( $environment ),
			'user_id'      => $user_id,
			'verifier'     => $verifier,
			'created_at'   => time(),
		);
		set_transient( self::TRANSIENT_PREFIX . $state, $record, self::STATE_TTL );

		$params = array(
			'response_type'         => 'code',
			'client_id'             => $this->credential_store->get_plaintext( $connector->id(), 'client_id', $environment ) ?: '',
			'redirect_uri'          => $redirect_uri,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'scope'                 => implode( ' ', $connector->scopes() ),
		);
		$url = $connector->authorize_url( $environment, $params );
		if ( ! $url ) {
			return array( 'error' => 'Connector did not provide an authorize URL.' );
		}
		$this->audit->log( $connector->id(), 'oauth_start', null, 'ok' );
		return array( 'url' => $url, 'state' => $state );
	}

	/** @return array{ok:bool,message:string} */
	public function handle_callback( Connector_Interface $connector, string $state, string $code, string $environment = 'live' ): array {
		if ( $state === '' || $code === '' ) {
			return array( 'ok' => false, 'message' => 'Missing authorization state or code.' );
		}
		$key    = self::TRANSIENT_PREFIX . $state;
		$record = get_transient( $key );
		delete_transient( $key ); // single use
		if ( ! is_array( $record ) ) {
			return array( 'ok' => false, 'message' => 'Invalid or expired OAuth state.' );
		}
		if ( ( $record['connector_id'] ?? '' ) !== $connector->id() ) {
			return array( 'ok' => false, 'message' => 'OAuth state connector mismatch.' );
		}
		$env = $this->token_store->normalize_env( $record['environment'] ?? $environment );
		if ( time() - (int) ( $record['created_at'] ?? 0 ) > self::STATE_TTL ) {
			return array( 'ok' => false, 'message' => 'OAuth state expired.' );
		}
		// State is unguessable, single-use and server-issued to an authorized admin; allow completion even if browser session changed on redirect. If a session is present, ensure it has capability.
		$user_id = get_current_user_id();
		if ( $user_id && ! current_user_can( Activator::CAPABILITY ) ) {
			$this->audit->log( $connector->id(), 'oauth_callback', null, 'error', 'permission_denied' );
			return array( 'ok' => false, 'message' => 'Permission denied.' );
		}

		$redirect_uri = $this->redirect_uri( $connector->id() );
		$resolved     = $this->resolve_fields( $connector->id(), $connector->fields(), $env );
		$token_data   = $connector->exchange_code( $code, $redirect_uri, (string) $record['verifier'], $env, $resolved, $this->http );
		if ( empty( $token_data['ok'] ) ) {
			$msg = $this->http->sanitize_provider_message( (string) ( $token_data['error'] ?? 'Token exchange failed.' ) );
			$this->token_store->update_status( $connector->id(), $env, Token_Store::STATUS_ERROR );
			$this->audit->log( $connector->id(), 'oauth_callback', null, 'error', $msg );
			return array( 'ok' => false, 'message' => $msg );
		}
		$expires_in = isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : null;
		$expires_at = $expires_in ? time() + $expires_in : null;
		$this->token_store->save_tokens(
			$connector->id(),
			$env,
			$token_data['access_token'] ?? null,
			$token_data['refresh_token'] ?? null,
			$expires_at,
			$token_data['scope'] ?? ( is_array( $token_data['scopes'] ?? null ) ? implode( ' ', $token_data['scopes'] ) : null ),
			Token_Store::STATUS_CONNECTED
		);
		$this->audit->log( $connector->id(), 'oauth_connected', null, 'ok' );
		return array( 'ok' => true, 'message' => 'Connected successfully.' );
	}

	/** @return array{ok:bool,message:string} */
	public function ensure_valid_token( Connector_Interface $connector, string $environment = 'live' ): array {
		$env = $this->token_store->normalize_env( $environment );
		if ( ! $this->token_store->needs_refresh( $connector->id(), $env ) ) {
			return array( 'ok' => true, 'message' => 'Token valid.' );
		}
		return $this->refresh_locked( $connector, $env );
	}

	/** @return array{ok:bool,message:string} */
	private function refresh_locked( Connector_Interface $connector, string $environment ): array {
		global $wpdb;
		$lock_name = $this->token_store->lock_name( $connector->id(), $environment );
		$got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 10 ) );
		if ( $got_lock !== 1 ) {
			// Fallback: brief wait then recheck; if still needs refresh, fail gracefully.
			usleep( 300000 );
			if ( ! $this->token_store->needs_refresh( $connector->id(), $environment ) ) {
				return array( 'ok' => true, 'message' => 'Token refreshed by another request.' );
			}
			return array( 'ok' => false, 'message' => 'Could not obtain refresh lock.' );
		}
		try {
			// Re-check inside lock.
			if ( ! $this->token_store->needs_refresh( $connector->id(), $environment ) ) {
				return array( 'ok' => true, 'message' => 'Token already refreshed.' );
			}
			$refresh = $this->token_store->get_refresh_token( $connector->id(), $environment );
			if ( ! $refresh ) {
				$this->token_store->update_status( $connector->id(), $environment, Token_Store::STATUS_EXPIRED );
				$this->audit->log( $connector->id(), 'oauth_refresh', null, 'error', 'missing_refresh_token' );
				return array( 'ok' => false, 'message' => 'No refresh token available. Reconnect required.' );
			}
			$resolved   = $this->resolve_fields( $connector->id(), $connector->fields(), $environment );
			$token_data = $connector->refresh( $refresh, $environment, $resolved, $this->http );
			if ( empty( $token_data['ok'] ) ) {
				$msg = $this->http->sanitize_provider_message( (string) ( $token_data['error'] ?? 'Refresh failed.' ) );
				$this->token_store->update_status( $connector->id(), $environment, Token_Store::STATUS_EXPIRED );
				$this->audit->log( $connector->id(), 'oauth_refresh', null, 'error', $msg );
				return array( 'ok' => false, 'message' => $msg );
			}
			$expires_in    = isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : null;
			$expires_at    = $expires_in ? time() + $expires_in : null;
			$new_refresh   = $token_data['refresh_token'] ?? $refresh; // preserve if not rotated
			$this->token_store->save_tokens(
				$connector->id(),
				$environment,
				$token_data['access_token'] ?? null,
				$new_refresh,
				$expires_at,
				$token_data['scope'] ?? null,
				Token_Store::STATUS_CONNECTED
			);
			$this->audit->log( $connector->id(), 'oauth_refresh', null, 'ok' );
			return array( 'ok' => true, 'message' => 'Token refreshed.' );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	public function redirect_uri( string $connector_id ): string {
		return rest_url( 'bmtg/v1/integrations/' . rawurlencode( $connector_id ) . '/callback' );
	}

	private function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private function allow_insecure_local( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true ) && ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/** @param array<int,array<string,mixed>> $fields */
	private function resolve_fields( string $connector_id, array $fields, string $environment ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$key = $field['key'] ?? '';
			if ( ! $key ) continue;
			$val = $this->credential_store->get_plaintext( $connector_id, $key, $environment );
			if ( $val !== null ) {
				$out[ $key ] = $val;
			}
		}
		return $out;
	}
}
