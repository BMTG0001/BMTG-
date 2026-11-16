<?php
namespace BMTG\PrivateJourney\Documents;

use BMTG\PrivateJourney\Clients\Clients;
use BMTG\PrivateJourney\Core\Capabilities;
use BMTG\PrivateJourney\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protected document storage outside the public uploads directory.
 * Downloads are authenticated and capability/ownership checked.
 */
class Documents {
	private static ?Documents $instance = null;

	public static function instance(): Documents {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'admin_post_bmtg_pj_document', array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_bmtg_pj_document', array( $this, 'handle_download' ) );
	}

	public function private_dir(): string {
		return wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'bmtg-private' );
	}

	private function ensure_private_dir(): bool {
		$dir = $this->private_dir();
		if ( is_dir( $dir ) ) {
			return true;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return true;
	}

	public function store( int $client_id, string $title, array $file, int $enquiry_id = 0 ): int {
		if ( ! $this->ensure_private_dir() ) {
			return 0;
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return 0;
		}
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 || $size > 25 * MB_IN_BYTES ) {
			return 0;
		}

		$allowed = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp', 'zip' );
		$ext     = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return 0;
		}

		$stored = 'pj-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false, false ) . '.' . $ext;
		$path   = trailingslashit( $this->private_dir() ) . $stored;

		if ( ! @move_uploaded_file( $file['tmp_name'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return 0;
		}
		@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		global $wpdb;
		$wpdb->insert(
			Schema::documents(),
			array(
				'client_id'  => $client_id ?: null,
				'enquiry_id' => $enquiry_id ?: null,
				'title'      => substr( sanitize_text_field( $title ?: ( $file['name'] ?? 'Document' ) ), 0, 191 ),
				'file_path'  => $stored,
				'file_name'  => substr( sanitize_file_name( (string) ( $file['name'] ?? $stored ) ), 0, 255 ),
				'mime'       => substr( (string) ( $file['type'] ?? '' ), 0, 120 ),
				'size'       => $size,
				'status'     => 'active',
				'created_by' => get_current_user_id() ?: null,
				'created_at' => Schema::now(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::documents() . " WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function for_client( int $client_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . Schema::documents() . " WHERE client_id = %d AND status = 'active' ORDER BY id DESC", $client_id ), ARRAY_A );
	}

	public function for_enquiry( int $enquiry_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . Schema::documents() . " WHERE enquiry_id = %d AND status = 'active' ORDER BY id DESC", $enquiry_id ), ARRAY_A );
	}

	public function download_url( int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'bmtg_pj_document',
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'bmtg_pj_document_' . $id
		);
	}

	public function can_access( array $doc ): bool {
		if ( Capabilities::can_manage_docs() ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$client = Clients::instance()->get_by_user( get_current_user_id() );
		return $client && (int) $client['id'] === (int) $doc['client_id'];
	}

	public function handle_download(): void {
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $id || ! wp_verify_nonce( $nonce, 'bmtg_pj_document_' . $id ) ) {
			wp_die( esc_html__( 'This document link is not valid.', 'bmtg-private-journey' ), '', array( 'response' => 403 ) );
		}

		$doc = $this->get( $id );
		if ( ! $doc || ! $this->can_access( $doc ) ) {
			wp_die( esc_html__( 'You do not have access to this document.', 'bmtg-private-journey' ), '', array( 'response' => 403 ) );
		}

		$path = trailingslashit( $this->private_dir() ) . basename( (string) $doc['file_path'] );
		if ( ! is_readable( $path ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'This document is not available.', 'bmtg-private-journey' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $doc['mime'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $doc['file_name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public function delete( int $id ): bool {
		global $wpdb;
		$doc = $this->get( $id );
		if ( ! $doc ) {
			return false;
		}
		$path = trailingslashit( $this->private_dir() ) . basename( (string) $doc['file_path'] );
		if ( is_readable( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$wpdb->update( Schema::documents(), array( 'status' => 'deleted' ), array( 'id' => $id ) );
		return true;
	}
}