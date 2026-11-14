<?php
namespace BMTG\Connectors;

use BMTG\Integrations\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Canva extends Abstract_Connector {
	public function id(): string { return 'canva'; }
	public function label(): string { return 'Canva Connect'; }
	public function auth_type(): string { return 'oauth'; }

	public function fields(): array {
		return array(
			array(
				'key'      => 'client_id',
				'label'    => 'Client ID',
				'secret'   => false,
				'required' => true,
				'help'     => 'Canva Connect app client ID.',
			),
			array(
				'key'      => 'client_secret',
				'label'    => 'Client Secret',
				'secret'   => true,
				'required' => true,
				'help'     => 'Canva Connect app client secret.',
			),
		);
	}

	public function scopes(): array {
		// Baseline scopes for design creation/read; verify before production as per blueprint open items.
		return array(
			'design:content:read',
			'design:content:write',
			'design:meta:read',
			'profile:read',
		);
	}

	public function authorize_url( string $environment, array $params ): string {
		return 'https://www.canva.com/api/oauth/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	public function token_url( string $environment ): string {
		return 'https://api.canva.com/rest/v1/oauth/token';
	}

	public function exchange_code( string $code, string $redirect_uri, string $code_verifier, string $environment, array $resolved_values, Http_Client $http ): array {
		$client_id     = $resolved_values['client_id'] ?? '';
		$client_secret = $resolved_values['client_secret'] ?? '';
		if ( ! $client_id || ! $client_secret ) {
			return array( 'ok' => false, 'error' => 'Client ID/secret missing.' );
		}
		$res = $http->request( 'POST', $this->token_url( $environment ), array(
			'headers' => array(
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
			),
			'body' => array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $redirect_uri,
				'code_verifier' => $code_verifier,
			),
			'timeout' => 20,
		) );
		if ( $res['status'] >= 200 && $res['status'] < 300 && is_array( $res['body'] ) ) {
			$body = $res['body'];
			return array(
				'ok'            => true,
				'access_token'  => $body['access_token'] ?? null,
				'refresh_token' => $body['refresh_token'] ?? null,
				'expires_in'    => $body['expires_in'] ?? null,
				'scope'         => $body['scope'] ?? null,
			);
		}
		return array( 'ok' => false, 'error' => $res['error'] ?: 'Canva token exchange failed.' );
	}

	public function refresh( string $refresh_token, string $environment, array $resolved_values, Http_Client $http ): array {
		$client_id     = $resolved_values['client_id'] ?? '';
		$client_secret = $resolved_values['client_secret'] ?? '';
		if ( ! $client_id || ! $client_secret ) {
			return array( 'ok' => false, 'error' => 'Client ID/secret missing.' );
		}
		$res = $http->request( 'POST', $this->token_url( $environment ), array(
			'headers' => array(
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
			),
			'body' => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			),
			'timeout' => 20,
		) );
		if ( $res['status'] >= 200 && $res['status'] < 300 && is_array( $res['body'] ) ) {
			$body = $res['body'];
			return array(
				'ok'            => true,
				'access_token'  => $body['access_token'] ?? null,
				'refresh_token' => $body['refresh_token'] ?? ($refresh_token),
				'expires_in'    => $body['expires_in'] ?? null,
				'scope'         => $body['scope'] ?? null,
			);
		}
		return array( 'ok' => false, 'error' => $res['error'] ?: 'Canva token refresh failed.' );
	}

	public function test( string $environment, array $resolved_values, Http_Client $http ): array {
		$token = $resolved_values['access_token'] ?? '';
		if ( ! $token ) {
			return $this->fail( 'Access token missing. Connect Canva first.' );
		}
		// Read-only profile endpoint to prove token works.
		$res = $http->request( 'GET', 'https://api.canva.com/rest/v1/users/me', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 20,
		) );
		if ( $res['status'] >= 200 && $res['status'] < 300 ) {
			return $this->ok( 'Canva connection successful.' );
		}
		return $this->fail( $res['error'] ?: 'Canva test failed.' );
	}
}
