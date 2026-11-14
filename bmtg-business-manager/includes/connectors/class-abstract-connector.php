<?php
namespace BMTG\Connectors;

use BMTG\Integrations\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Abstract_Connector implements Connector_Interface {
	public function auth_type(): string {
		return 'api_key';
	}

	public function authorize_url( string $environment, array $params ): string { return ''; }
	public function token_url( string $environment ): string { return ''; }
	public function scopes(): array { return array(); }
	public function exchange_code( string $code, string $redirect_uri, string $code_verifier, string $environment, array $resolved_values, Http_Client $http ): array { return array(); }
	public function refresh( string $refresh_token, string $environment, array $resolved_values, Http_Client $http ): array { return array(); }

	protected function ok( string $message, array $details = array() ): array {
		return array( 'ok' => true, 'message' => $message, 'details' => $details );
	}

	protected function fail( string $message, array $details = array() ): array {
		return array( 'ok' => false, 'message' => $message, 'details' => $details );
	}
}
