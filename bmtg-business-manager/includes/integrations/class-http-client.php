<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Http_Client {
	/** @var array<string,mixed> */
	private array $defaults = array(
		'timeout'     => 20,
		'redirection' => 3,
		'blocking'    => true,
	);

	/**
	 * @param array<string,mixed> $args
	 * @return array{status:int,body:mixed,headers:array<string,mixed>,raw:string,error:?string}
	 */
	public function request( string $method, string $url, array $args = array(), array $context = array() ): array {
		$retries  = max( 0, (int) ( $args['retries'] ?? 2 ) );
		$attempts = 0;
		$last     = null;
		$timeout  = (int) ( $args['timeout'] ?? $this->defaults['timeout'] );
		$timeout  = min( 210, max( 1, $timeout ) ); // <= 3.5 min hard cap policy alignment

		unset( $args['retries'] );
		$args['timeout']     = $timeout;
		$args['method']      = strtoupper( $method );
		$args['redirection'] = $args['redirection'] ?? $this->defaults['redirection'];
		$args['headers']     = $args['headers'] ?? array();

		while ( $attempts <= $retries ) {
			$attempts++;
			$response = wp_remote_request( $url, $args );
			$last     = $this->normalize_response( $response );
			if ( $last['error'] === null && $last['status'] >= 200 && $last['status'] < 300 ) {
				return $last;
			}
			if ( ! $this->should_retry( $last, $attempts, $retries ) ) {
				return $last;
			}
			usleep( (int) ( min( 1000000, 200000 * ( 2 ** ( $attempts - 1 ) ) ) ) );
		}
		return $last ?: array( 'status' => 0, 'body' => null, 'headers' => array(), 'raw' => '', 'error' => 'Request failed.' );
	}

	/**
	 * @param array<string,mixed>|mixed $response
	 * @return array{status:int,body:mixed,headers:array<string,mixed>,raw:string,error:?string}
	 */
	private function normalize_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'status' => 0, 'body' => null, 'headers' => array(), 'raw' => '', 'error' => $response->get_error_message() );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		$body    = json_decode( $raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$body = $raw;
		}
		$error = null;
		if ( $status >= 400 ) {
			$error = $this->extract_error_message( $body, $status );
		}
		return array( 'status' => $status, 'body' => $body, 'headers' => $headers, 'raw' => $raw, 'error' => $error );
	}

	private function extract_error_message( $body, int $status ): string {
		if ( is_array( $body ) ) {
			foreach ( array( 'error_description', 'message', 'error', 'detail' ) as $key ) {
				if ( ! empty( $body[ $key ] ) ) {
					$val = $body[ $key ];
					if ( is_array( $val ) ) {
						$val = $val['message'] ?? wp_json_encode( $val );
					}
					return $this->sanitize_provider_message( (string) $val );
				}
			}
		}
		return 'HTTP ' . $status;
	}

	/** @param array{status:int,error:?string} $result */
	private function should_retry( array $result, int $attempts, int $retries ): bool {
		if ( $attempts > $retries ) {
			return false;
		}
		if ( $result['status'] === 0 ) {
			return true; // network/timeout
		}
		if ( $result['status'] === 429 || $result['status'] >= 500 ) {
			return true;
		}
		return false; // do not retry 4xx auth/client errors
	}

	public function sanitize_provider_message( string $message ): string {
		$message = wp_strip_all_tags( $message );
		$message = preg_replace( '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i', '$1[redacted]', $message );
		$message = preg_replace( '/(api[_-]?key|access[_-]?token|refresh[_-]?token|secret|authorization)\s*[:=]\s*\S+/i', '$1=[redacted]', $message );
		$message = preg_replace( '/\b(sk|rk|or|gsk|AIza)[A-Za-z0-9_\-]{8,}\b/', '[redacted]', $message );
		return mb_substr( trim( (string) $message ), 0, 300 );
	}

	/**
	 * @param array<string,mixed> $headers
	 * @return array<string,mixed>
	 */
	public function redact_headers( array $headers ): array {
		$redacted = array();
		foreach ( $headers as $k => $v ) {
			if ( preg_match( '/authorization|token|secret|api[-_]?key/i', (string) $k ) ) {
				$redacted[ $k ] = '[redacted]';
			} else {
				$redacted[ $k ] = $v;
			}
		}
		return $redacted;
	}
}
