<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lead_Service {
	private Customer_Service $customers;

	public function __construct( ?Customer_Service $customers = null ) {
		$this->customers = $customers ?: new Customer_Service();
	}

	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::leads() . " WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public function list( array $args, bool $view_all, int $current_user ): array {
		global $wpdb;
		$table = Schema::leads();
		$where = array( '1=1' );
		$params = array();
		if ( ! $view_all ) {
			$where[] = 'assigned_to = %d';
			$params[] = $current_user;
		}
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$params[] = Schema::sanitize_text( $args['status'], 32 );
		}
		if ( ! empty( $args['assigned_to'] ) ) {
			$where[] = 'assigned_to = %d';
			$params[] = (int) $args['assigned_to'];
		}
		if ( ! empty( $args['customer_id'] ) ) {
			$where[] = 'customer_id = %d';
			$params[] = (int) $args['customer_id'];
		}
		$sql_where = 'WHERE ' . implode( ' AND ', $where );
		$per = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset = ( $page - 1 ) * $per;
		$count_sql = "SELECT COUNT(*) FROM {$table} {$sql_where}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql = "SELECT * FROM {$table} {$sql_where} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		return array( 'items' => $rows ?: array(), 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	public function can_access_lead( array $lead, bool $view_all, int $current_user ): bool {
		if ( $view_all ) return true;
		return (int) ( $lead['assigned_to'] ?? 0 ) === $current_user || (int) ( $lead['created_by'] ?? 0 ) === $current_user;
	}

	public function create( array $data, int $actor_id = 0 ): ?array {
		global $wpdb;
		$customer_id = ! empty( $data['customer_id'] ) ? (int) $data['customer_id'] : 0;
		if ( $customer_id <= 0 ) {
			return null;
		}
		$now = Schema::now_gmt();
		$status = $this->sanitize_status( $data['status'] ?? 'new' );
		$row = array(
			'customer_id' => $customer_id,
			'title'       => Schema::sanitize_text( $data['title'] ?? 'Website enquiry', 190 ),
			'status'      => $status,
			'source'      => Schema::sanitize_text( $data['source'] ?? '', 64 ),
			'channel'     => Schema::sanitize_text( $data['channel'] ?? '', 64 ),
			'assigned_to' => ! empty( $data['assigned_to'] ) ? (int) $data['assigned_to'] : null,
			'priority'    => Schema::sanitize_text( $data['priority'] ?? 'normal', 20 ),
			'destinations'=> Schema::sanitize_text( $data['destinations'] ?? '', 255 ),
			'travel_start'=> Schema::sanitize_optional_date( $data['travel_start'] ?? null ),
			'travel_end'  => Schema::sanitize_optional_date( $data['travel_end'] ?? null ),
			'pax_adults'  => isset( $data['pax_adults'] ) && $data['pax_adults'] !== '' ? (int) $data['pax_adults'] : null,
			'pax_children'=> isset( $data['pax_children'] ) && $data['pax_children'] !== '' ? (int) $data['pax_children'] : null,
			'inbox_conversation_id' => ! empty( $data['inbox_conversation_id'] ) ? (int) $data['inbox_conversation_id'] : null,
			'external_ref'=> Schema::sanitize_text( $data['external_ref'] ?? '', 191 ),
			'lost_reason' => Schema::sanitize_text( $data['lost_reason'] ?? '', 255 ),
			'created_by'  => $actor_id ?: null,
			'created_at'  => $now,
			'updated_at'  => $now,
		);
		$wpdb->insert( Schema::leads(), $row );
		$lead_id = (int) $wpdb->insert_id;
		$this->record_status_history( $lead_id, '', $status, $actor_id, 'Lead created' );
		$this->add_activity( $lead_id, $customer_id, 'lead_created', 'Lead created', array( 'status' => $status ), $actor_id, null );
		return $this->get( $lead_id );
	}

	public function update( int $id, array $data, int $actor_id = 0 ): ?array {
		global $wpdb;
		$lead = $this->get( $id );
		if ( ! $lead ) return null;
		$fields = array();
		foreach ( array( 'title','priority','destinations','external_ref','lost_reason','source','channel' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$fields[ $k ] = Schema::sanitize_text( $data[ $k ], in_array( $k, array( 'destinations','lost_reason' ), true ) ? 255 : ( $k === 'title' || $k === 'external_ref' ? 191 : 64 ) );
			}
		}
		foreach ( array( 'travel_start','travel_end' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) $fields[ $k ] = Schema::sanitize_optional_date( $data[ $k ] );
		}
		foreach ( array( 'pax_adults','pax_children' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) $fields[ $k ] = $data[ $k ] === '' || $data[ $k ] === null ? null : (int) $data[ $k ];
		}
		if ( array_key_exists( 'assigned_to', $data ) ) {
			$new_assignee = $data['assigned_to'] ? (int) $data['assigned_to'] : null;
			if ( (int) ( $lead['assigned_to'] ?? 0 ) !== (int) ( $new_assignee ?? 0 ) ) {
				$this->record_status_history( $id, (string) $lead['status'], (string) $lead['status'], $actor_id, 'Assignment changed' );
				$this->add_activity( $id, (int) $lead['customer_id'], 'assignment_changed', 'Lead assignment changed', array( 'from' => $lead['assigned_to'], 'to' => $new_assignee ), $actor_id, null );
			}
			$fields['assigned_to'] = $new_assignee;
		}
		if ( empty( $fields ) ) return $lead;
		$fields['updated_at'] = Schema::now_gmt();
		$wpdb->update( Schema::leads(), $fields, array( 'id' => $id ) );
		$this->add_activity( $id, (int) $lead['customer_id'], 'lead_updated', 'Lead updated', array( 'fields' => array_keys( $fields ) ), $actor_id, null );
		return $this->get( $id );
	}

	public function change_status( int $id, string $to_status, int $actor_id, string $reason = '' ): ?array {
		global $wpdb;
		$lead = $this->get( $id );
		if ( ! $lead ) return null;
		$from = (string) $lead['status'];
		$to = $this->sanitize_status( $to_status );
		if ( $from === $to ) return $lead;
		$now = Schema::now_gmt();
		$fields = array( 'status' => $to, 'updated_at' => $now );
		if ( $to === 'won' ) { $fields['won_at'] = $now; $fields['closed_at'] = $now; }
		if ( $to === 'lost' ) { $fields['closed_at'] = $now; if ( $reason ) $fields['lost_reason'] = Schema::sanitize_text( $reason, 255 ); }
		$wpdb->update( Schema::leads(), $fields, array( 'id' => $id ) );
		$this->record_status_history( $id, $from, $to, $actor_id, $reason );
		$this->add_activity( $id, (int) $lead['customer_id'], 'status_changed', 'Lead status changed', array( 'from' => $from, 'to' => $to, 'reason' => Schema::sanitize_text( $reason, 255 ) ), $actor_id, null );
		return $this->get( $id );
	}

	public function assign( int $id, ?int $assignee, int $actor_id ): ?array {
		return $this->update( $id, array( 'assigned_to' => $assignee ), $actor_id );
	}

	public function find_open_by_conversation( int $conversation_id ): ?array {
		if ( $conversation_id <= 0 ) return null;
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::leads() . " WHERE inbox_conversation_id = %d AND status NOT IN ('won','lost') ORDER BY id DESC LIMIT 1", $conversation_id ), ARRAY_A );
		if ( $row ) return $row;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::leads() . " WHERE inbox_conversation_id = %d ORDER BY id DESC LIMIT 1", $conversation_id ), ARRAY_A );
		return $row ?: null;
	}

	public function create_or_link_from_chatbot( $lead_id, array $contact, int $conversation_id ) {
		// Preserve consent: chatbot enforces before calling; do not bypass.
		$existing = $this->find_open_by_conversation( $conversation_id );
		if ( $existing ) {
			return (int) $existing['id'];
		}
		$customer = $this->customers->find_or_create_from_contact( $contact, 'chatbot' );
		if ( ! $customer ) return $lead_id;
		$cid = (int) $customer['id'];
		if ( ! empty( $contact['requirements'] ) ) {
			$this->customers->upsert_preference( $cid, 'last_requirements', Schema::sanitize_text( $contact['requirements'], 1000 ), 'chatbot' );
		}
		$lead = $this->create( array(
			'customer_id' => $cid,
			'title'       => 'Website chatbot enquiry',
			'status'      => 'new',
			'source'      => 'chatbot',
			'channel'     => 'website_chat',
			'inbox_conversation_id' => $conversation_id,
			'destinations'=> '',
		), 0 );
		if ( $lead ) {
			$this->add_activity( (int) $lead['id'], $cid, 'chatbot_lead_captured', 'Lead captured from website chatbot', array( 'conversation_id' => $conversation_id ), 0, 'chatbot:lead:' . $conversation_id );
			return (int) $lead['id'];
		}
		return $lead_id;
	}

	public function record_status_history( int $lead_id, string $from, string $to, int $actor_id, string $reason = '' ): void {
		global $wpdb;
		$wpdb->insert( Schema::status_history(), array(
			'lead_id' => $lead_id,
			'from_status' => Schema::sanitize_text( $from, 32 ),
			'to_status' => Schema::sanitize_text( $to, 32 ),
			'changed_by' => $actor_id ?: null,
			'reason' => Schema::sanitize_text( $reason, 255 ),
			'created_at' => Schema::now_gmt(),
		) );
	}

	public function add_activity( ?int $lead_id, ?int $customer_id, string $type, string $body = '', array $meta = array(), int $actor_id = 0, ?string $dedupe_key = null ): ?int {
		global $wpdb;
		$type = Schema::sanitize_text( $type, 64 );
		if ( $type === '' ) return null;
		if ( $dedupe_key ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::activities() . " WHERE dedupe_key = %s LIMIT 1", $dedupe_key ) );
			if ( $exists ) return (int) $exists;
		}
		$wpdb->insert( Schema::activities(), array(
			'lead_id' => $lead_id ?: null,
			'customer_id' => $customer_id ?: null,
			'type' => $type,
			'body' => $body !== '' ? sanitize_textarea_field( $body ) : null,
			'meta' => $meta ? wp_json_encode( $meta ) : null,
			'actor_id' => $actor_id ?: null,
			'dedupe_key' => $dedupe_key ? Schema::sanitize_text( $dedupe_key, 191 ) : null,
			'created_at' => Schema::now_gmt(),
		) );
		return (int) $wpdb->insert_id ?: null;
	}

	public function list_activities( int $lead_id, int $page = 1, int $per = 50 ): array {
		global $wpdb;
		$table = Schema::activities();
		$per = min( 100, max( 1, $per ) );
		$page = max( 1, $page );
		$offset = ( $page - 1 ) * $per;
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE lead_id = %d", $lead_id ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE lead_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $lead_id, $per, $offset ), ARRAY_A );
		return array( 'items' => $rows ?: array(), 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	public function handle_inbox_event( array $record ): void {
		$conv = isset( $record['conversation_id'] ) ? (int) $record['conversation_id'] : 0;
		$event = isset( $record['event'] ) ? (string) $record['event'] : '';
		$at = isset( $record['at'] ) ? (string) $record['at'] : '';
		if ( $conv <= 0 || $event === '' ) return;
		$dedupe = 'inbox:' . hash( 'sha256', $conv . '|' . $event . '|' . $at . '|' . wp_json_encode( $record['payload'] ?? array() ) );
		$lead = $this->find_open_by_conversation( $conv );
		$lead_id = $lead ? (int) $lead['id'] : null;
		$customer_id = $lead ? (int) $lead['customer_id'] : null;
		$this->add_activity( $lead_id, $customer_id, 'inbox_' . Schema::sanitize_text( $event, 40 ), 'Inbox event: ' . $event, array(
			'conversation_id' => $conv,
			'event' => $event,
			'context' => $record['context'] ?? '',
			'at' => $at,
		), 0, $dedupe );
	}

	private function sanitize_status( string $status ): string {
		$status = Schema::sanitize_text( $status, 32 );
		return in_array( $status, Schema::lead_statuses(), true ) ? $status : 'new';
	}
}
