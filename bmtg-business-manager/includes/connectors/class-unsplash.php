<?php
namespace BMTG\Connectors;

use BMTG\Integrations\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Unsplash extends Abstract_Connector {
	public function id(): string { return 'unsplash'; }
	public function label(): string { return 'Unsplash'; }

	public function fields(): array {
		return array(
			array(
				'key'      => 'access_key',
				'label'    => 'Access Key',
				'secret'   => true,
				'required' => true,
				'help'     => 'Unsplash application Access Key (Client-ID).',
			),
		);
	}

	public function test( string $environment, array $resolved_values, Http_Client $http ): array {
		$key = $resolved_values['access_key'] ?? '';
		if ( ! $key ) {
			return $this->fail( 'Access key is missing.' );
		}
		// Cheap read-only call: search endpoint with a benign query.
		$url = add_query_arg( array( 'query' => 'travel', 'per_page' => 1 ), 'https://api.unsplash.com/search/photos' );
		$res = $http->request( 'GET', $url, array(
			'headers' => array( 'Authorization' => 'Client-ID ' . $key ),
			'timeout' => 20,
		) );
		if ( $res['status'] >= 200 && $res['status'] < 300 ) {
			return $this->ok( 'Unsplash connection successful.' );
		}
		return $this->fail( $res['error'] ?: 'Unsplash test failed.' );
	}
}
