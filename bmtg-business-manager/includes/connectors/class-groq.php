<?php
namespace BMTG\Connectors;

use BMTG\Integrations\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Groq extends Abstract_Connector {
	public function id(): string { return 'groq'; }
	public function label(): string { return 'Groq'; }

	public function fields(): array {
		return array(
			array(
				'key'      => 'api_key',
				'label'    => 'API Key',
				'secret'   => true,
				'required' => true,
				'help'     => 'Groq API key (gsk_...).',
			),
		);
	}

	public function test( string $environment, array $resolved_values, Http_Client $http ): array {
		$key = $resolved_values['api_key'] ?? '';
		if ( ! $key ) {
			return $this->fail( 'API key is missing.' );
		}
		$res = $http->request( 'GET', 'https://api.groq.com/openai/v1/models', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 20,
		) );
		if ( $res['status'] >= 200 && $res['status'] < 300 ) {
			return $this->ok( 'Groq connection successful.' );
		}
		return $this->fail( $res['error'] ?: 'Groq test failed.' );
	}
}
