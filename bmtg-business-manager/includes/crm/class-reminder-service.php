<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reminder_Service {
	public function create( array $data, int $actor_id = 0 ): ?array {
		global $wpdb;
		$lead_id = (int) ( $data['lead_id'] ?? 0 );
		$due_at = Schema::sanitize_datetime( $data['due_at'] ?? '' );
		if ( $lead_id <= 0 || ! $due_at ) return null;
		$now = Schema::now_gmt();
		$row = array(
			'lead_id' => $lead_id,
			'assigned_to' => ! empty( $data['assigned_to'] ) ? (int) $data['assigned_to'] : null,
			'due_at' => $due_at,
			'remind_type' => Schema::sanitize_text( $data['remind_type'] ?? 'follow_up', 64 ),
			'status' => 'pending',
			'note' => Schema::sanitize_text( $data['note'] ?? '', 255 ),
			'created_by' => $actor_id ?: null,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$wpdb->insert( Schema::reminders(), $row );
		return $this->get( (int) $wpdb->insert_id );
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::reminders() . " WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function list( array $args, bool $view_all, int $current_user ): array {
		global $wpdb;
		$table = Schema::reminders();
		$where = array( '1=1' );
		$params = array();
		if ( ! $view_all ) {
			$where[] = '(assigned_to = %d OR created_by = %d)';
			$params[] = $current_user; $params[] = $current_user;
		}
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$params[] = Schema::sanitize_text( $args['status'], 32 );
		}
		if ( ! empty( $args['lead_id'] ) ) {
			$where[] = 'lead_id = %d';
			$params[] = (int) $args['lead_id'];
		}
		$sql_where = 'WHERE ' . implode( ' AND ', $where );
		$per = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset = ( $page - 1 ) * $per;
		$count_sql = "SELECT COUNT(*) FROM {$table} {$sql_where}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql = "SELECT * FROM {$table} {$sql_where} ORDER BY due_at ASC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		return array( 'items' => $rows ?: array(), 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	public function list_due( array $args, bool $view_all, int $current_user ): array {
		global $wpdb;
		$table = Schema::reminders();
		$now = Schema::now_gmt();
		$where = array( "status = 'pending'", 'due_at <= %s' );
		$params = array( $now );
		if ( ! $view_all ) {
			$where[] = '(assigned_to = %d OR created_by = %d)';
			$params[] = $current_user; $params[] = $current_user;
		}
		$sql_where = 'WHERE ' . implode( ' AND ', $where );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$sql_where} ORDER BY due_at ASC LIMIT 100", $params ), ARRAY_A );
		return array( 'items' => $rows ?: array(), 'total' => count( $rows ?: array() ) );
	}

	public function update( int $id, array $data ): ?array {
		global $wpdb;
		$existing = $this->get( $id );
		if ( ! $existing ) return null;
		$fields = array();
		if ( array_key_exists( 'note', $data ) ) $fields['note'] = Schema::sanitize_text( $data['note'], 255 );
		if ( array_key_exists( 'remind_type', $data ) ) $fields['remind_type'] = Schema::sanitize_text( $data['remind_type'], 64 );
		if ( array_key_exists( 'assigned_to', $data ) ) $fields['assigned_to'] = $data['assigned_to'] ? (int) $data['assigned_to'] : null;
		if ( array_key_exists( 'due_at', $data ) ) {
			$due = Schema::sanitize_datetime( $data['due_at'] );
			if ( $due ) $fields['due_at'] = $due;
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = Schema::sanitize_text( $data['status'], 32 );
			if ( in_array( $status, Schema::reminder_statuses(), true ) ) $fields['status'] = $status;
		}
		if ( empty( $fields ) ) return $existing;
		$fields['updated_at'] = Schema::now_gmt();
		$wpdb->update( Schema::reminders(), $fields, array( 'id' => $id ) );
		return $this->get( $id );
	}

	public function complete( int $id ): ?array {
		global $wpdb;
		$existing = $this->get( $id );
		if ( ! $existing ) return null;
		$now = Schema::now_gmt();
		$wpdb->update( Schema::reminders(), array( 'status' => 'done', 'completed_at' => $now, 'updated_at' => $now ), array( 'id' => $id ) );
		return $this->get( $id );
	}

	public function can_access( array $reminder, bool $view_all, int $current_user ): bool {
		if ( $view_all ) return true;
		return (int) ( $reminder['assigned_to'] ?? 0 ) === $current_user || (int) ( $reminder['created_by'] ?? 0 ) === $current_user;
	}
}
