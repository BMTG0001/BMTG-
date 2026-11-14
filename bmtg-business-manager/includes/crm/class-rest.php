<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {
	private Customer_Service $customers;
	private Lead_Service $leads;
	private Reminder_Service $reminders;

	public function __construct( Customer_Service $customers, Lead_Service $leads, Reminder_Service $reminders ) {
		$this->customers = $customers;
		$this->leads = $leads;
		$this->reminders = $reminders;
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	private function ns(): string { return 'bmtg/v1/crm'; }

	public function register_routes(): void {
		$ns = $this->ns();
		register_rest_route( $ns, '/customers', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_customers' ), 'permission_callback' => array( $this, 'perm_view_customers' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_customer' ), 'permission_callback' => array( $this, 'perm_manage_customers' ) ),
		) );
		register_rest_route( $ns, '/customers/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_customer' ), 'permission_callback' => array( $this, 'perm_view_customers' ) ),
			array( 'methods' => 'PUT', 'callback' => array( $this, 'update_customer' ), 'permission_callback' => array( $this, 'perm_manage_customers' ) ),
		) );
		register_rest_route( $ns, '/customers/(?P<id>\d+)/preferences', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_preferences' ), 'permission_callback' => array( $this, 'perm_view_customers' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'upsert_preferences' ), 'permission_callback' => array( $this, 'perm_manage_customers' ) ),
		) );
		register_rest_route( $ns, '/leads', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_leads' ), 'permission_callback' => array( $this, 'perm_view_leads' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_lead' ), 'permission_callback' => array( $this, 'perm_manage_leads' ) ),
		) );
		register_rest_route( $ns, '/leads/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_lead' ), 'permission_callback' => array( $this, 'perm_view_leads' ) ),
			array( 'methods' => 'PUT', 'callback' => array( $this, 'update_lead' ), 'permission_callback' => array( $this, 'perm_manage_leads' ) ),
		) );
		register_rest_route( $ns, '/leads/(?P<id>\d+)/status', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'change_lead_status' ), 'permission_callback' => array( $this, 'perm_manage_leads' ) ),
		) );
		register_rest_route( $ns, '/leads/(?P<id>\d+)/assign', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'assign_lead' ), 'permission_callback' => array( $this, 'perm_assign_leads' ) ),
		) );
		register_rest_route( $ns, '/leads/(?P<id>\d+)/activities', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_activities' ), 'permission_callback' => array( $this, 'perm_view_leads' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_activity' ), 'permission_callback' => array( $this, 'perm_manage_leads' ) ),
		) );
		register_rest_route( $ns, '/reminders', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_reminders' ), 'permission_callback' => array( $this, 'perm_view_leads' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_reminder' ), 'permission_callback' => array( $this, 'perm_manage_reminders' ) ),
		) );
		register_rest_route( $ns, '/reminders/due', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_due_reminders' ), 'permission_callback' => array( $this, 'perm_view_leads' ) ),
		) );
		register_rest_route( $ns, '/reminders/(?P<id>\d+)', array(
			array( 'methods' => 'PUT', 'callback' => array( $this, 'update_reminder' ), 'permission_callback' => array( $this, 'perm_manage_reminders' ) ),
		) );
		register_rest_route( $ns, '/reminders/(?P<id>\d+)/complete', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'complete_reminder' ), 'permission_callback' => array( $this, 'perm_manage_reminders' ) ),
		) );
		register_rest_route( $ns, '/meta', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'meta' ), 'permission_callback' => array( $this, 'perm_view_leads' ) ),
		) );
	}

	private function res( $data, int $status = 200 ) {
		$r = new \WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$r->header( 'Pragma', 'no-cache' );
		return $r;
	}
	private function user(): int { return get_current_user_id(); }

	public function perm_view_leads(): bool { return is_user_logged_in() && Capabilities::can_view_leads(); }
	public function perm_manage_leads(): bool { return is_user_logged_in() && Capabilities::can_manage_leads(); }
	public function perm_assign_leads(): bool { return is_user_logged_in() && ( Capabilities::can_assign_leads() || Capabilities::can_manage_leads() ); }
	public function perm_view_customers(): bool { return is_user_logged_in() && Capabilities::can_view_customers(); }
	public function perm_manage_customers(): bool { return is_user_logged_in() && Capabilities::can_manage_customers(); }
	public function perm_manage_reminders(): bool { return is_user_logged_in() && Capabilities::can_manage_reminders(); }

	public function list_customers( \WP_REST_Request $req ) {
		$data = $this->customers->list( $req->get_params(), Capabilities::can_view_all_leads(), $this->user() );
		return $this->res( $data );
	}
	public function get_customer( \WP_REST_Request $req ) {
		$row = $this->customers->get( (int) $req['id'] );
		if ( ! $row ) return $this->res( array( 'error' => 'Not found' ), 404 );
		return $this->res( $row );
	}
	public function create_customer( \WP_REST_Request $req ) {
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		if ( empty( $data['email'] ) && empty( $data['phone'] ) && empty( $data['first_name'] ) && empty( $data['last_name'] ) ) {
			return $this->res( array( 'error' => 'Name, email or phone required' ), 400 );
		}
		$row = $this->customers->create( $data );
		return $this->res( $row, 201 );
	}
	public function update_customer( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		$row = $this->customers->update( $id, $data );
		if ( ! $row ) return $this->res( array( 'error' => 'Not found' ), 404 );
		return $this->res( $row );
	}
	public function get_preferences( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! $this->customers->get( $id ) ) return $this->res( array( 'error' => 'Not found' ), 404 );
		return $this->res( array( 'items' => $this->customers->get_preferences( $id ) ) );
	}
	public function upsert_preferences( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! $this->customers->get( $id ) ) return $this->res( array( 'error' => 'Not found' ), 404 );
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		$prefs = isset( $data['preferences'] ) && is_array( $data['preferences'] ) ? $data['preferences'] : $data;
		$source = Schema::sanitize_text( $data['source'] ?? 'api', 64 );
		foreach ( $prefs as $k => $v ) {
			if ( is_string( $k ) ) $this->customers->upsert_preference( $id, $k, $v, $source );
		}
		return $this->res( array( 'items' => $this->customers->get_preferences( $id ) ) );
	}

	public function list_leads( \WP_REST_Request $req ) {
		return $this->res( $this->leads->list( $req->get_params(), Capabilities::can_view_all_leads(), $this->user() ) );
	}
	public function get_lead( \WP_REST_Request $req ) {
		$lead = $this->leads->get( (int) $req['id'] );
		if ( ! $lead ) return $this->res( array( 'error' => 'Not found' ), 404 );
		if ( ! $this->leads->can_access_lead( $lead, Capabilities::can_view_all_leads(), $this->user() ) ) {
			return $this->res( array( 'error' => 'Forbidden' ), 403 );
		}
		return $this->res( $lead );
	}
	public function create_lead( \WP_REST_Request $req ) {
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		if ( empty( $data['customer_id'] ) ) return $this->res( array( 'error' => 'customer_id required' ), 400 );
		if ( ! $this->customers->get( (int) $data['customer_id'] ) ) return $this->res( array( 'error' => 'Customer not found' ), 400 );
		$lead = $this->leads->create( $data, $this->user() );
		return $this->res( $lead, 201 );
	}
	public function update_lead( \WP_REST_Request $req ) {
		$lead = $this->leads->get( (int) $req['id'] );
		if ( ! $lead ) return $this->res( array( 'error' => 'Not found' ), 404 );
		if ( ! $this->leads->can_access_lead( $lead, Capabilities::can_view_all_leads(), $this->user() ) ) return $this->res( array( 'error' => 'Forbidden' ), 403 );
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		unset( $data['status'] ); // use status endpoint
		$lead = $this->leads->update( (int) $req['id'], $data, $this->user() );
		return $this->res( $lead );
	}
	public function change_lead_status( \WP_REST_Request $req ) {
		$lead = $this->leads->get( (int) $req['id'] );
		if ( ! $lead ) return $this->res( array( 'error' => 'Not found' ), 404 );
		if ( ! $this->leads->can_access_lead( $lead, Capabilities::can_view_all_leads(), $this->user() ) ) return $this->res( array( 'error' => 'Forbidden' ), 403 );
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		$status = (string) ( $data['status'] ?? '' );
		if ( ! in_array( $status, Schema::lead_statuses(), true ) ) return $this->res( array( 'error' => 'Invalid status' ), 400 );
		$lead = $this->leads->change_status( (int) $req['id'], $status, $this->user(), (string) ( $data['reason'] ?? '' ) );
		return $this->res( $lead );
	}
	public function assign_lead( \WP_REST_Request $req ) {
		$lead = $this->leads->get( (int) $req['id'] );
		if ( ! $lead ) return $this->res( array( 'error' => 'Not found' ), 404 );
		if ( ! $this->leads->can_access_lead( $lead, Capabilities::can_view_all_leads(), $this->user() ) && ! Capabilities::can_view_all_leads() ) return $this->res( array( 'error' => 'Forbidden' ), 403 );
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		$assignee = isset( $data['assigned_to'] ) && $data['assigned_to'] ? (int) $data['assigned_to'] : null;
		$lead = $this->leads->assign( (int) $req['id'], $assignee, $this->user() );
		return $this->res( $lead );
	}
	public function list_activities( \WP_REST_Request $req ) {
		$lead = $this->leads->get( (int) $req['id'] );
		if ( ! $lead ) return $this->res( array( 'error' => 'Not found' ), 404 );
		if ( ! $this->leads->can_access_lead( $lead, Capabilities::can_view_all_leads(), $this->user() ) ) return $this->res( array( 'error' => 'Forbidden' ), 403 );
		return $this->res( $this->leads->list_activities( (int) $req['id'], (int) ( $req->get_param('page') ?: 1 ), (int) ( $req->get_param('per_page') ?: 50 ) ) );
	}
	public function create_activity( \WP_REST_Request $req ) {
		$lead = $this->leads->get( (int) $req['id'] );
		if ( ! $lead ) return $this->res( array( 'error' => 'Not found' ), 404 );
		if ( ! $this->leads->can_access_lead( $lead, Capabilities::can_view_all_leads(), $this->user() ) ) return $this->res( array( 'error' => 'Forbidden' ), 403 );
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		$type = Schema::sanitize_text( $data['type'] ?? 'note', 64 );
		$body = (string) ( $data['body'] ?? '' );
		$id = $this->leads->add_activity( (int) $req['id'], (int) $lead['customer_id'], $type, $body, is_array( $data['meta'] ?? null ) ? $data['meta'] : array(), $this->user(), null );
		return $this->res( array( 'id' => $id ), 201 );
	}

	public function list_reminders( \WP_REST_Request $req ) {
		return $this->res( $this->reminders->list( $req->get_params(), Capabilities::can_view_all_leads(), $this->user() ) );
	}
	public function list_due_reminders( \WP_REST_Request $req ) {
		return $this->res( $this->reminders->list_due( $req->get_params(), Capabilities::can_view_all_leads(), $this->user() ) );
	}
	public function create_reminder( \WP_REST_Request $req ) {
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		$row = $this->reminders->create( $data, $this->user() );
		if ( ! $row ) return $this->res( array( 'error' => 'lead_id and valid due_at required' ), 400 );
		return $this->res( $row, 201 );
	}
	public function update_reminder( \WP_REST_Request $req ) {
		$rem = $this->reminders->get( (int) $req['id'] );
		if ( ! $rem ) return $this->res( array( 'error' => 'Not found' ), 404 );
		if ( ! $this->reminders->can_access( $rem, Capabilities::can_view_all_leads(), $this->user() ) ) return $this->res( array( 'error' => 'Forbidden' ), 403 );
		$data = $req->get_json_params(); if ( ! is_array( $data ) ) $data = $req->get_params();
		return $this->res( $this->reminders->update( (int) $req['id'], $data ) );
	}
	public function complete_reminder( \WP_REST_Request $req ) {
		$rem = $this->reminders->get( (int) $req['id'] );
		if ( ! $rem ) return $this->res( array( 'error' => 'Not found' ), 404 );
		if ( ! $this->reminders->can_access( $rem, Capabilities::can_view_all_leads(), $this->user() ) ) return $this->res( array( 'error' => 'Forbidden' ), 403 );
		return $this->res( $this->reminders->complete( (int) $req['id'] ) );
	}
	public function meta( \WP_REST_Request $req ) {
		return $this->res( array(
			'lead_statuses' => Schema::lead_statuses(),
			'reminder_statuses' => Schema::reminder_statuses(),
		) );
	}
}
