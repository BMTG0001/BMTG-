<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Key_Rotation {
	public const HOOK = 'bmtg_integrations_key_rotation_batch';
	public const STATE_OPTION = 'bmtg_key_rotation_state';

	private Config $config;
	private Credential_Store $credential_store;
	private Token_Store $token_store;
	private Audit $audit;

	public function __construct( Config $config, Credential_Store $credential_store, Token_Store $token_store, Audit $audit ) {
		$this->config           = $config;
		$this->credential_store = $credential_store;
		$this->token_store      = $token_store;
		$this->audit            = $audit;
	}

	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'process_batch' ) );
	}

	/**
	 * @return array{status:string,message:string,processed?:int,remaining?:int}
	 */
	public function start( bool $dry_run = false ): array {
		if ( ! $this->config->is_key_configured() ) {
			return array( 'status' => 'error', 'message' => $this->config->key_error_message() );
		}
		$old = $this->config->get_old_key();
		if ( ! $old ) {
			return array( 'status' => 'error', 'message' => 'BMTG_ENCRYPTION_KEY_OLD is required for rotation (base64: 32-byte key).' );
		}
		$state = array(
			'dry_run'    => $dry_run,
			'offset'     => 0,
			'batch'      => 20,
			'started_at' => gmdate( 'c' ),
			'status'     => 'running',
			'processed'  => 0,
		);
		update_option( self::STATE_OPTION, $state, false );
		$this->audit->log( 'system', $dry_run ? 'key_rotation_dry_run_started' : 'key_rotation_started', null, 'ok' );
		$this->schedule_next();
		return array( 'status' => 'scheduled', 'message' => $dry_run ? 'Dry run scheduled.' : 'Key rotation scheduled.' );
	}

	public function process_batch(): void {
		$state = get_option( self::STATE_OPTION );
		if ( ! is_array( $state ) || ( $state['status'] ?? '' ) !== 'running' ) {
			return;
		}
		$dry_run = ! empty( $state['dry_run'] );
		$batch   = max( 1, min( 100, (int) ( $state['batch'] ?? 20 ) ) );
		$offset  = max( 0, (int) ( $state['offset'] ?? 0 ) );
		$old     = $this->config->get_old_key();
		$new     = $this->config->get_key();
		if ( ! $old || ! $new ) {
			$state['status']  = 'error';
			$state['message'] = 'Missing old or new key during rotation.';
			update_option( self::STATE_OPTION, $state, false );
			$this->audit->log( 'system', 'key_rotation_failed', null, 'error', $state['message'] );
			return;
		}

		global $wpdb;
		$creds_table  = Migrations::table_credentials();
		$tokens_table = Migrations::table_tokens();
		$cred_rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, value_enc FROM {$creds_table} ORDER BY id ASC LIMIT %d OFFSET %d", $batch, $offset ), ARRAY_A );
		$token_rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, access_enc, refresh_enc FROM {$tokens_table} ORDER BY id ASC LIMIT %d OFFSET %d", $batch, $offset ), ARRAY_A );
		$cred_rows    = is_array( $cred_rows ) ? $cred_rows : array();
		$token_rows   = is_array( $token_rows ) ? $token_rows : array();

		if ( empty( $cred_rows ) && empty( $token_rows ) ) {
			$state['status'] = 'completed';
			unset( $state['message'] );
			update_option( self::STATE_OPTION, $state, false );
			$this->audit->log( 'system', $dry_run ? 'key_rotation_dry_run_completed' : 'key_rotation_completed', null, 'ok' );
			return;
		}

		$wpdb->query( 'START TRANSACTION' );
		try {
			$processed = 0;
			foreach ( $cred_rows as $row ) {
				$plain = $this->credential_store->decrypt( $row['value_enc'], $old );
				if ( ! $dry_run ) {
					$new_enc = $this->encrypt_with_key( $plain, $new );
					$wpdb->update( $creds_table, array( 'value_enc' => $new_enc, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row['id'] ), array( '%s', '%s' ), array( '%d' ) );
				}
				$processed++;
			}
			foreach ( $token_rows as $row ) {
				$access_new = null;
				$refresh_new = null;
				if ( ! empty( $row['access_enc'] ) ) {
					$plain = $this->credential_store->decrypt( $row['access_enc'], $old );
					$access_new = $dry_run ? $row['access_enc'] : $this->encrypt_with_key( $plain, $new );
				}
				if ( ! empty( $row['refresh_enc'] ) ) {
					$plain = $this->credential_store->decrypt( $row['refresh_enc'], $old );
					$refresh_new = $dry_run ? $row['refresh_enc'] : $this->encrypt_with_key( $plain, $new );
				}
				if ( ! $dry_run && ( $access_new !== null || $refresh_new !== null || ! empty( $row['access_enc'] ) || ! empty( $row['refresh_enc'] ) ) ) {
					$wpdb->update( $tokens_table, array(
						'access_enc'  => $access_new !== null ? $access_new : $row['access_enc'],
						'refresh_enc' => $refresh_new !== null ? $refresh_new : $row['refresh_enc'],
						'updated_at'  => current_time( 'mysql', true ),
					), array( 'id' => (int) $row['id'] ), array( '%s', '%s', '%s' ), array( '%d' ) );
				}
				$processed++;
			}
			$wpdb->query( 'COMMIT' );
			$state['offset']    = $offset + $batch;
			$state['processed'] = (int) ( $state['processed'] ?? 0 ) + $processed;
			update_option( self::STATE_OPTION, $state, false );
			$this->schedule_next();
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$state['status']  = 'error';
			$state['message'] = 'Rotation batch failed and was rolled back: ' . wp_strip_all_tags( $e->getMessage() );
			update_option( self::STATE_OPTION, $state, false );
			$this->audit->log( 'system', 'key_rotation_failed', null, 'error', $state['message'] );
		}
	}

	private function encrypt_with_key( string $plaintext, string $key ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		return base64_encode( $nonce . $ciphertext );
	}

	private function schedule_next(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_next_scheduled_action( self::HOOK ) ) {
				as_enqueue_async_action( self::HOOK, array(), 'bmtg-integrations' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/** @return array<string,mixed>|false */
	public function get_state() {
		return get_option( self::STATE_OPTION, false );
	}
}
