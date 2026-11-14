<?php
namespace BMTG\Connectors;

use BMTG\Integrations\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenRouter extends Abstract_Connector {
	public function id(): string { return 'openrouter'; }
	public function label(): string { return 'OpenRouter'; }

	public function fields(): array {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => 'API Key',
				'secret'   => true,
				'required' => true,
				'help'     => 'OpenRouter API key.',
			),
		);
	}

	public function test( string $environment, array $resolved_values, Http_Client $http ): array {
		$key = $resolved_values['api_key'] ?? '';
		if ( ! $key ) {
			return $this->fail( 'API key is missing.' );
		}
		$res = $http->request( 'GET', 'https://openrouter.ai/api/v1/models', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 20,
		) );
		if ( $res['status'] >= 200 && $res['status'] < 300 ) {
			return $this->ok( 'OpenRouter connection successful.' );
		}
		return $this->fail( $res['error'] ?: 'OpenRouter test failed.' );
	}
}
