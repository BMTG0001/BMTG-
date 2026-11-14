<?php
namespace BMTG\Integrations;

use BMTG\Connectors\Connector_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {
	private Registry $registry;
	private Config $config;
	private Credential_Store $credential_store;
	private Token_Store $token_store;
	private OAuth_Flow $oauth;
	private Manager $manager;
	private Health $health;
	private Audit $audit;

	public function __construct( Registry $registry, Config $config, Credential_Store $credential_store, Token_Store $token_store, OAuth_Flow $oauth, Manager $manager, Health $health, Audit $audit ) {
		$this->registry         = $registry;
		$this->config           = $config;
		$this->credential_store = $credential_store;
		$this->token_store      = $token_store;
		$this->oauth            = $oauth;
		$this->manager          = $manager;
		$this->health           = $health;
		$this->audit            = $audit;
	}

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$ns = 'bmtg/v1';

		register_rest_route( $ns, '/integrations', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_connectors' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );

		register_rest_route( $ns, '/integrations/(?P<id>[a-z0-9_-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_connector' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => $this->common_args(),
		) );

		register_rest_route( $ns, '/integrations/(?P<id>[a-z0-9_-]+)/save', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_fields' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => $this->common_args(),
		) );

		register_rest_route( $ns, '/integrations/(?P<id>[a-z0-9_-]+)/test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'test_connector' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => $this->common_args(),
		) );

		register_rest_route( $ns, '/integrations/(?P<id>[a-z0-9_-]+)/toggle', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'toggle_connector' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array_merge( $this->common_args(), array(
				'disabled' => array( 'required' => true, 'type' => 'boolean' ),
			) ),
		) );

		register_rest_route( $ns, '/integrations/(?P<id>[a-z0-9_-]+)/remove', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'remove_field' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array_merge( $this->common_args(), array(
				'field' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			) ),
		) );

		register_rest_route( $ns, '/integrations/(?P<id>[a-z0-9_-]+)/connect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'oauth_connect' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => $this->common_args(),
		) );

		register_rest_route( $ns, '/integrations/(?P<id>[a-z0-9_-]+)/callback', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'oauth_callback' ),
			'permission_callback' => '__return_true', // Provider redirect: state validated in handler.
			'args'                => array(
				'state' => array( 'required' => false, 'type' => 'string' ),
				'code'  => array( 'required' => false, 'type' => 'string' ),
				'error' => array( 'required' => false, 'type' => 'string' ),
				'error_description' => array( 'required' => false, 'type' => 'string' ),
			),
		) );

		register_rest_route( $ns, '/integrations/key-rotation/start', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'key_rotation_start' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				'dry_run' => array( 'required' => false, 'type' => 'boolean', 'default' => false ),
			),
		) );

		register_rest_route( $ns, '/integrations/key-rotation/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'key_rotation_status' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
	}

	private function common_args(): array {
		return array(
			'environment' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => 'live',
				'enum'              => array( 'live', 'sandbox' ),
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	public function can_manage( \WP_REST_Request $request = null ): bool {
		return current_user_can( Activator::CAPABILITY );
	}

	public function can_manage_callback( \WP_REST_Request $request = null ): bool {
		// OAuth callbacks arrive as external redirects; strict state validation is enforced in OAuth_Flow::handle_callback().
		return true;
	}

	private function response( $data, int $status = 200 ): \WP_REST_Response {
		$res = new \WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$res->header( 'Pragma', 'no-cache' );
		return $res;
	}

	private function get_connector_or_error( string $id ) {
		$connector = $this->registry->get( sanitize_key( $id ) );
		if ( ! $connector ) {
			return $this->response( array( 'error' => 'Unknown connector.' ), 404 );
		}
		return $connector;
	}

	public function list_connectors( \WP_REST_Request $request ): \WP_REST_Response {
		$env = $this->token_store->normalize_env( (string) $request->get_param( 'environment' ) ?: 'live' );
		$out = array();
		foreach ( $this->registry->all() as $connector ) {
			$out[] = $this->manager->connector_summary( $connector, $env );
		}
		return $this->response( array(
			'connectors'   => $out,
			'environment'  => $env,
			'key_configured' => $this->config->is_key_configured(),
			'key_error'    => $this->config->is_key_configured() ? null : $this->config->key_error_message(),
		) );
	}

	public function get_connector( \WP_REST_Request $request ): \WP_REST_Response {
		$connector = $this->get_connector_or_error( (string) $request['id'] );
		if ( $connector instanceof \WP_REST_Response ) return $connector;
		$env = $this->token_store->normalize_env( (string) $request->get_param( 'environment' ) ?: 'live' );
		return $this->response( $this->manager->connector_summary( $connector, $env ) );
	}

	public function save_fields( \WP_REST_Request $request ): \WP_REST_Response {
		$connector = $this->get_connector_or_error( (string) $request['id'] );
		if ( $connector instanceof \WP_REST_Response ) return $connector;
		$env    = $this->token_store->normalize_env( (string) $request->get_param( 'environment' ) ?: 'live' );
		$fields = $request->get_json_params();
		if ( ! is_array( $fields ) ) {
			$fields = $request->get_params();
		}
		$input_fields = isset( $fields['fields'] ) && is_array( $fields['fields'] ) ? $fields['fields'] : array();
		if ( empty( $input_fields ) ) {
			return $this->response( array( 'error' => 'No fields provided.' ), 400 );
		}

		$allowed = array();
		foreach ( $connector->fields() as $f ) { $allowed[ $f['key'] ] = $f; }
		$saved = 0;
		foreach ( $input_fields as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $value ) || $value === '' ) {
				continue;
			}
			if ( strlen( $value ) > 8192 ) {
				return $this->response( array( 'error' => 'Field value too long.' ), 400 );
			}
			try {
				$this->credential_store->save( $connector->id(), $key, $value, $env );
				$saved++;
			} catch ( Integration_Exception $e ) {
				return $this->response( array( 'error' => $e->get_user_message() ), 400 );
			}
		}

		$this->token_store->update_status( $connector->id(), $env, Token_Store::STATUS_CONFIGURED );
		$this->audit->log( $connector->id(), 'save', null, 'ok', (string) $saved );

		// Auto-test on save when required fields present.
		$test = null;
		if ( $this->manager->has_required_fields( $connector, $env ) ) {
			$test = $this->manager->run_test( $connector, $env );
		}
		return $this->response( array(
			'saved'     => $saved,
			'connector' => $this->manager->connector_summary( $connector, $env ),
			'test'      => $test,
		) );
	}

	public function test_connector( \WP_REST_Request $request ): \WP_REST_Response {
		$connector = $this->get_connector_or_error( (string) $request['id'] );
		if ( $connector instanceof \WP_REST_Response ) return $connector;
		$env = $this->token_store->normalize_env( (string) $request->get_param( 'environment' ) ?: 'live' );
		$test = $this->manager->run_test( $connector, $env );
		return $this->response( array(
			'test'      => $test,
			'connector' => $this->manager->connector_summary( $connector, $env ),
		) );
	}

	public function toggle_connector( \WP_REST_Request $request ): \WP_REST_Response {
		$connector = $this->get_connector_or_error( (string) $request['id'] );
		if ( $connector instanceof \WP_REST_Response ) return $connector;
		$env      = $this->token_store->normalize_env( (string) $request->get_param( 'environment' ) ?: 'live' );
		$disabled = (bool) $request->get_param( 'disabled' );
		$this->manager->set_disabled( $connector->id(), $env, $disabled );
		return $this->response( array( 'connector' => $this->manager->connector_summary( $connector, $env ) ) );
	}

	public function remove_field( \WP_REST_Request $request ): \WP_REST_Response {
		$connector = $this->get_connector_or_error( (string) $request['id'] );
		if ( $connector instanceof \WP_REST_Response ) return $connector;
		$env   = $this->token_store->normalize_env( (string) $request->get_param( 'environment' ) ?: 'live' );
		$field = sanitize_key( (string) $request->get_param( 'field' ) );
		try {
			$this->credential_store->remove( $connector->id(), $field, $env );
		} catch ( Integration_Exception $e ) {
			return $this->response( array( 'error' => $e->get_user_message() ), 400 );
		}
		// Re-evaluate status.
		$this->manager->set_disabled( $connector->id(), $env, false );
		return $this->response( array( 'connector' => $this->manager->connector_summary( $connector, $env ) ) );
	}

	public function oauth_connect( \WP_REST_Request $request ): \WP_REST_Response {
		$connector = $this->get_connector_or_error( (string) $request['id'] );
		if ( $connector instanceof \WP_REST_Response ) return $connector;
		if ( $connector->auth_type() !== 'oauth' ) {
			return $this->response( array( 'error' => 'Connector does not use OAuth.' ), 400 );
		}
		$env = $this->token_store->normalize_env( (string) $request->get_param( 'environment' ) ?: 'live' );
		$res = $this->oauth->start( $connector, $env );
		if ( ! empty( $res['error'] ) ) {
			return $this->response( array( 'error' => $res['error'] ), 400 );
		}
		return $this->response( $res );
	}

	public function oauth_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$connector = $this->get_connector_or_error( (string) $request['id'] );
		if ( $connector instanceof \WP_REST_Response ) return $connector;
		$state = (string) $request->get_param( 'state' );
		$code  = (string) $request->get_param( 'code' );
		$error = (string) $request->get_param( 'error' );
		$desc  = (string) $request->get_param( 'error_description' );
		$is_browser = isset( $_SERVER['HTTP_ACCEPT'] ) && strpos( (string) $_SERVER['HTTP_ACCEPT'], 'text/html' ) !== false;
		$admin_url_ok  = admin_url( 'options-general.php?page=bmtg-integrations&oauth=' . rawurlencode( $connector->id() ) . '&result=ok' );
		$admin_url_err = admin_url( 'options-general.php?page=bmtg-integrations&oauth=' . rawurlencode( $connector->id() ) . '&result=error' );

		if ( $error ) {
			$msg = wp_strip_all_tags( $desc ?: $error );
			$this->audit->log( $connector->id(), 'oauth_callback', null, 'error', $error );
			if ( $is_browser ) { wp_safe_redirect( $admin_url_err ); exit; }
			return $this->response( array( 'ok' => false, 'error' => $msg ), 400 );
		}
		if ( $state === '' || $code === '' ) {
			if ( $is_browser ) { wp_safe_redirect( $admin_url_err ); exit; }
			return $this->response( array( 'ok' => false, 'error' => 'Missing authorization state or code.' ), 400 );
		}
		$result = $this->oauth->handle_callback( $connector, $state, $code );
		$status = ! empty( $result['ok'] ) ? 200 : 400;
		$admin_url = ! empty( $result['ok'] ) ? $admin_url_ok : $admin_url_err;
		if ( $is_browser ) {
			wp_safe_redirect( $admin_url );
			exit;
		}
		return $this->response( array_merge( $result, array( 'redirect' => $admin_url ) ), $status );
	}

	public function key_rotation_start( \WP_REST_Request $request ): \WP_REST_Response {
		$dry = (bool) $request->get_param( 'dry_run' );
		$res = Hub::instance()->key_rotation()->start( $dry );
		$code = $res['status'] === 'error' ? 400 : 200;
		return $this->response( $res, $code );
	}

	public function key_rotation_status( \WP_REST_Request $request ): \WP_REST_Response {
		$state = Hub::instance()->key_rotation()->get_state();
		return $this->response( array( 'state' => $state ?: null ) );
	}
}
