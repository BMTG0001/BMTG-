<?php
namespace BMTG\Connectors;

use BMTG\Integrations\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Connector_Interface {
	public function id(): string;
	public function label(): string;
	public function auth_type(): string; // 'api_key' | 'oauth'

	/** @return array<int,array<string,mixed>> */
	public function fields(): array;

	/** @return array{ok:bool,message:string,details?:array<string,mixed>} */
	public function test( string $environment, array $resolved_values, Http_Client $http ): array;

	// OAuth methods - return empty/default for api_key connectors.
	public function authorize_url( string $environment, array $params ): string;
	public function token_url( string $environment ): string;
	/** @return string[] */
	public function scopes(): array;
	/** @return array<string,mixed> */
	public function exchange_code( string $code, string $redirect_uri, string $code_verifier, string $environment, array $resolved_values, Http_Client $http ): array;
	/** @return array<string,mixed> */
	public function refresh( string $refresh_token, string $environment, array $resolved_values, Http_Client $http ): array;
}
