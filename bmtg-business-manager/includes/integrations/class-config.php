<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Config {
	private ?string $key_error = null;
	private ?string $decoded_key = null;
	private bool $key_checked = false;

	public function is_key_configured(): bool {
		return $this->get_key() !== null;
	}

	public function key_error_message(): string {
		$this->get_key();
		return $this->key_error ?: __( 'BMTG_ENCRYPTION_KEY is not configured.', 'bmtg-business-manager' );
	}

	public function get_key(): ?string {
		if ( $this->key_checked ) {
			return $this->decoded_key;
		}
		$this->key_checked = true;
		if ( ! defined( 'BMTG_ENCRYPTION_KEY' ) ) {
			$this->key_error = __( 'BMTG_ENCRYPTION_KEY is missing from wp-config.php. Secrets cannot be stored until it is set (32 random bytes, base64 with base64: prefix).', 'bmtg-business-manager' );
			return null;
		}
		$raw = constant( 'BMTG_ENCRYPTION_KEY' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			$this->key_error = __( 'BMTG_ENCRYPTION_KEY is empty or invalid.', 'bmtg-business-manager' );
			return null;
		}
		if ( strpos( $raw, 'base64:' ) === 0 ) {
			$raw = substr( $raw, 7 );
		}
		$decoded = base64_decode( $raw, true );
		if ( $decoded === false || strlen( $decoded ) < 32 ) {
			$this->key_error = __( 'BMTG_ENCRYPTION_KEY must decode to at least 32 bytes (base64).', 'bmtg-business-manager' );
			return null;
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->key_error = __( 'PHP sodium extension is required for encryption.', 'bmtg-business-manager' );
			$this->decoded_key = null;
			return null;
		}
		$this->decoded_key = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		return $this->decoded_key;
	}

	public function get_old_key(): ?string {
		if ( ! defined( 'BMTG_ENCRYPTION_KEY_OLD' ) ) {
			return null;
		}
		$raw = constant( 'BMTG_ENCRYPTION_KEY_OLD' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		if ( strpos( $raw, 'base64:' ) === 0 ) {
			$raw = substr( $raw, 7 );
		}
		$decoded = base64_decode( $raw, true );
		if ( $decoded === false || strlen( $decoded ) < 32 ) {
			return null;
		}
		return substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public function resolve_field_value( string $connector_id, string $field_key, string $environment = 'live' ): ?array {
		$const = $this->constant_name( $connector_id, $field_key );
		if ( defined( $const ) ) {
			$value = constant( $const );
			if ( is_string( $value ) && $value !== '' ) {
				return array(
					'value'  => $value,
					'source' => 'wp-config',
					'hint'   => $this->hint_for( $value ),
				);
			}
		}
		return null;
	}

	public function has_constant( string $connector_id, string $field_key ): bool {
		return defined( $this->constant_name( $connector_id, $field_key ) );
	}

	public function constant_name( string $connector_id, string $field_key ): string {
		$id    = strtoupper( preg_replace( '/[^A-Z0-9]+/i', '_', $connector_id ) );
		$field = strtoupper( preg_replace( '/[^A-Z0-9]+/i', '_', $field_key ) );
		return 'BMTG_' . $id . '_' . $field;
	}

	public function hint_for( string $value ): string {
		$len = strlen( $value );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return '****' . substr( $value, -4 );
	}

	public function assert_can_store(): void {
		if ( ! $this->is_key_configured() ) {
			throw new Misconfigured_Exception( $this->key_error_message() );
		}
	}
}
