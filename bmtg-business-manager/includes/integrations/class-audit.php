<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit {
	public function log( string $connector_id, string $action, ?string $field_key, string $result = 'ok', ?string $message = null ): void {
		global $wpdb;
		$user_id = get_current_user_id();
		$wpdb->insert(
			Migrations::table_audit(),
			array(
				'connector_id' => sanitize_key( $connector_id ),
				'action'       => sanitize_key( $action ),
				'field_key'    => $field_key ? sanitize_key( $field_key ) : null,
				'user_id'      => $user_id ?: null,
				'result'       => sanitize_key( $result ),
				'message'      => $message ? wp_strip_all_tags( mb_substr( $message, 0, 1000 ) ) : null,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function recent( string $connector_id, int $limit = 20 ): array {
		global $wpdb;
		$table = Migrations::table_audit();
		$sql   = $wpdb->prepare( "SELECT action, field_key, user_id, result, message, created_at FROM {$table} WHERE connector_id = %s ORDER BY id DESC LIMIT %d", $connector_id, $limit );
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
