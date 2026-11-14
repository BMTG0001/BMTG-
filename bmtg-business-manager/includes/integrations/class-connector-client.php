<?php
namespace BMTG\Integrations;

use BMTG\Connectors\Connector_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feature-facing client. Never exposes raw secrets/tokens.
 */
class Connector_Client {
	private Connector_Interface $connector;
	private string $environment;
	private Credential_Store $credential_store;
	private Token_Store $token_store;
	private Http_Client $http;
	private Manager $manager;

	public function __construct( Connector_Interface $connector, string $environment, Credential_Store $credential_store, Token_Store $token_store, Http_Client $http, Manager $manager ) {
		$this->connector       = $connector;
		$this->environment     = $environment;
		$this->credential_store = $credential_store;
		$this->token_store     = $token_store;
		$this->http            = $http;
		$this->manager         = $manager;
	}

	public function id(): string { return $this->connector->id(); }
	public function connector(): Connector_Interface { return $this->connector; }
	public function environment(): string { return $this->environment; }

	/** @return array<string,mixed> */
	public function request( string $method, string $url, array $args = array() ): array {
		$headers = $args['headers'] ?? array();
		if ( $this->connector->auth_type() === 'api_key' ) {
			$headers = $this->inject_api_key_auth( $headers );
		} else {
			$token = $this->token_store->get_access_token( $this->connector->id(), $this->environment );
			if ( $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
		}
		$args['headers'] = $headers;
		$result = $this->http->request( $method, $url, $args );
		if ( $result['status'] === 401 || $result['status'] === 403 ) {
			$this->token_store->update_status( $this->connector->id(), $this->environment, $this->connector->auth_type() === 'oauth' ? Token_Store::STATUS_EXPIRED : Token_Store::STATUS_ERROR );
			$this->manager->audit()->log( $this->connector->id(), 'request', null, 'error', 'auth_failure_http_' . $result['status'] );
		} elseif ( $result['error'] !== null || $result['status'] >= 500 ) {
			$this->token_store->update_status( $this->connector->id(), $this->environment, Token_Store::STATUS_ERROR );
			$this->manager->audit()->log( $this->connector->id(), 'request', null, 'error', $result['error'] ?? 'http_' . $result['status'] );
		}
		return $result;
	}

	/** @param array<string,string> $headers */
	private function inject_api_key_auth( array $headers ): array {
		// Connector-specific header conventions handled by convenience methods or here for known ones.
		$id = $this->connector->id();
		if ( $id === 'groq' || $id === 'openrouter' ) {
			$key = $this->credential_store->get_plaintext( $id, 'api_key', $this->environment );
			if ( $key ) $headers['Authorization'] = 'Bearer ' . $key;
		} elseif ( $id === 'unsplash' ) {
			$key = $this->credential_store->get_plaintext( $id, 'access_key', $this->environment );
			if ( $key ) $headers['Authorization'] = 'Client-ID ' . $key;
		}
		return $headers;
	}

	// Convenience methods for features.
	public function get( string $url, array $args = array() ): array { return $this->request( 'GET', $url, $args ); }
	public function post( string $url, array $args = array() ): array { return $this->request( 'POST', $url, $args ); }

	/** Unsplash: search photos */
	public function search_photos( string $query, int $page = 1, int $per_page = 10 ): array {
		if ( $this->connector->id() !== 'unsplash' ) throw new Misconfigured_Exception( 'Not an Unsplash client.' );
		$url = add_query_arg( array( 'query' => $query, 'page' => $page, 'per_page' => $per_page ), 'https://api.unsplash.com/search/photos' );
		return $this->get( $url );
	}

	/** Groq: simple chat completion */
	public function chat_completion( array $payload ): array {
		if ( $this->connector->id() !== 'groq' ) throw new Misconfigured_Exception( 'Not a Groq client.' );
		return $this->post( 'https://api.groq.com/openai/v1/chat/completions', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );
	}

	/** OpenRouter: chat completion */
	public function openrouter_chat_completion( array $payload ): array {
		if ( $this->connector->id() !== 'openrouter' ) throw new Misconfigured_Exception( 'Not an OpenRouter client.' );
		return $this->post( 'https://openrouter.ai/api/v1/chat/completions', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );
	}

	/** Canva: minimal design-create (end-to-end proof) */
	public function create_design( array $payload ): array {
		if ( $this->connector->id() !== 'canva' ) throw new Misconfigured_Exception( 'Not a Canva client.' );
		return $this->post( 'https://api.canva.com/rest/v1/designs', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );
	}

	public function canva_request( string $method, string $path, array $args = array() ): array {
		if ( $this->connector->id() !== 'canva' ) throw new Misconfigured_Exception( 'Not a Canva client.' );
		$url = 'https://api.canva.com/rest/v1' . $path;
		return $this->request( $method, $url, $args );
	}

	/** Canva: list brand templates (requires brandtemplate:meta:read scope). */
	public function canva_list_brand_templates( string $query = '', int $limit = 20, string $continuation = '' ): array {
		if ( $this->connector->id() !== 'canva' ) throw new Misconfigured_Exception( 'Not a Canva client.' );
		$args = array( 'limit' => max(1, min(50, $limit)) );
		if ( $query !== '' ) $args['query'] = $query;
		if ( $continuation !== '' ) $args['continuation'] = $continuation;
		$url = add_query_arg( $args, 'https://api.canva.com/rest/v1/brand-templates' );
		return $this->get( $url );
	}

	/** Canva: create autofill design from brand template (may require autofill/dataset support). */
	public function canva_create_autofill( string $brand_template_id, array $data = array(), string $title = '' ): array {
		if ( $this->connector->id() !== 'canva' ) throw new Misconfigured_Exception( 'Not a Canva client.' );
		$payload = array( 'brand_template_id' => $brand_template_id );
		if ( ! empty( $data ) ) $payload['data'] = $data;
		if ( $title !== '' ) $payload['title'] = $title;
		return $this->post( 'https://api.canva.com/rest/v1/autofills', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );
	}

	/** Canva: get autofill job */
	public function canva_get_autofill( string $job_id ): array {
		if ( $this->connector->id() !== 'canva' ) throw new Misconfigured_Exception( 'Not a Canva client.' );
		return $this->get( 'https://api.canva.com/rest/v1/autofills/' . rawurlencode( $job_id ) );
	}
}
