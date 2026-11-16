<?php
namespace BMTG\PrivateJourney\Audit;

use BMTG\PrivateJourney\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only audit trail for staff and system actions.
 */
class Audit {
	private static ?Audit $instance = null;

	public static function instance(): Audit {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function log( string $action, string $object_type = '', int $object_id = 0, array $meta = array() ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::audit_logs(),
			array(
				'actor_id'    => get_current_user_id() ?: null,
				'action'      => substr( $action, 0, 100 ),
				'object_type' => substr( $object_type, 0, 50 ),
				'object_id'   => $object_id ?: null,
				'meta'        => Schema::json( $meta ),
				'created_at'  => Schema::now(),
			)
		);
	}

	public function list( array $args = array() ): array {
		global $wpdb;
		$table = Schema::audit_logs();
		$args  = wp_parse_args( $args, array( 'page' => 1, 'per_page' => 40, 'object_type' => '', 'object_id' => 0 ) );
		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $args['object_type'] ) {
			$where[]  = 'object_type = %s';
			$params[] = $args['object_type'];
		}
		if ( $args['object_id'] ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $args['object_id'];
		}
		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 100, (int) $args['per_page'] ) );
		$page      = max( 1, (int) $args['page'] );
		$offset    = ( $page - 1 ) * $per_page;
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" );
		if ( $params ) {
			$params[] = $per_page;
			$params[] = $offset;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d", ...$params ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT $per_page OFFSET $offset", ARRAY_A );
		}
		foreach ( (array) $rows as &$row ) {
			$row['meta'] = Schema::decode( $row['meta'] ?? '', array() );
		}
		return array(
			'items' => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}
}