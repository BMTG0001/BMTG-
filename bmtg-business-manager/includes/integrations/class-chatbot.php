<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public Website Chatbot module (integrated with Integrations Hub).
 * Shares approved knowledge/AI providers with Private Collection Ask BMTG but enforces separate auth and privacy.
 */
class Chatbot {
	public const OPTION_KEY = 'bmtg_chatbot_settings';
	public const CONVERSATION_CPT = 'bmtg_chat_conv';
	public const CRON_RETENTION = 'bmtg_chatbot_retention_cleanup';

	private Hub $hub;

	public function __construct( Hub $hub ) {
		$this->hub = $hub;
	}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_storage' ) );
		add_shortcode( 'bmtg_chatbot', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'maybe_auto_inject' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::CRON_RETENTION, array( $this, 'run_retention_cleanup' ) );
		if ( ! wp_next_scheduled( self::CRON_RETENTION ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_RETENTION );
		}
	}

	public function register_storage(): void {
		register_post_type( self::CONVERSATION_CPT, array(
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'supports'     => array( 'title' ),
			'label'        => 'BMTG Chat Conversations',
		) );
	}

	public function defaults(): array {
		return array(
			'enabled'              => false,
			'auto_inject'          => false,
			'excluded_pages'       => array(),
			'greeting'             => 'Welcome to BM Travel Group. How may I assist with your journey through India, Nepal, Bhutan, or Sri Lanka?',
			'operating_hours'      => '',
			'fallback_message'     => 'I am unable to assist with that right now. I can connect you with our team if you would like.',
			'handover_message'     => 'A member of our team will assist you shortly.',
			'model_groq'           => 'llama-3.3-70b-versatile',
			'model_openrouter'     => 'openai/gpt-4o-mini',
			'knowledge'            => array(),
			'retention_days'       => 90,
			'require_consent'      => true,
		);
	}

	public function settings(): array {
		$opts = get_option( self::OPTION_KEY, array() );
		return is_array( $opts ) ? array_merge( $this->defaults(), $opts ) : $this->defaults();
	}

	public function register_assets(): void {
		wp_register_style( 'bmtg-chatbot-widget', BMTG_PLUGIN_URL . 'admin/integrations/css/chatbot-widget.css', array(), BMTG_VERSION );
		wp_register_script( 'bmtg-chatbot-widget', BMTG_PLUGIN_URL . 'admin/integrations/js/chatbot-widget.js', array(), BMTG_VERSION, true );
	}

	private function should_render(): bool {
		if ( is_admin() ) return false;
		$s = $this->settings();
		if ( empty( $s['enabled'] ) ) return false;
		if ( is_singular() ) {
			$id = (string) get_queried_object_id();
			if ( in_array( $id, array_map( 'strval', (array) $s['excluded_pages'] ), true ) ) return false;
		}
		return true;
	}

	public function enqueue_widget(): void {
		if ( ! $this->should_render() ) return;
		wp_enqueue_style( 'bmtg-chatbot-widget' );
		wp_enqueue_script( 'bmtg-chatbot-widget' );
		wp_localize_script( 'bmtg-chatbot-widget', 'BMTGChatbot', array(
			'restUrl'          => esc_url_raw( rest_url( 'bmtg/v1/chatbot' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'greeting'         => $this->settings()['greeting'],
			'operatingHours'   => $this->settings()['operating_hours'],
			'handoverMessage'  => $this->settings()['handover_message'],
			'requireConsent'   => ! empty( $this->settings()['require_consent'] ),
		) );
	}

	public function shortcode( $atts = array() ): string {
		if ( ! $this->should_render() ) return '';
		$this->enqueue_widget();
		return '<div id="bmtg-chatbot-mount" data-bmtg-chatbot="1"></div>';
	}

	public function maybe_auto_inject(): void {
		$s = $this->settings();
		if ( empty( $s['enabled'] ) || empty( $s['auto_inject'] ) ) return;
		if ( ! $this->should_render() ) return;
		$this->enqueue_widget();
		echo '<div id="bmtg-chatbot-mount" data-bmtg-chatbot="1" data-auto="1"></div>';
	}

	public function register_routes(): void {
		$ns = 'bmtg/v1/chatbot';
		register_rest_route( $ns, '/session', array(
			'methods' => 'POST', 'callback' => array( $this, 'rest_session' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/message', array(
			'methods' => 'POST', 'callback' => array( $this, 'rest_message' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/lead', array(
			'methods' => 'POST', 'callback' => array( $this, 'rest_lead' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/handover', array(
			'methods' => 'POST', 'callback' => array( $this, 'rest_handover' ), 'permission_callback' => '__return_true',
		) );
		// Staff/admin inbox actions (separate auth).
		register_rest_route( $ns, '/inbox/(?P<id>\d+)/reply', array(
			'methods' => 'POST', 'callback' => array( $this, 'rest_staff_reply' ), 'permission_callback' => array( $this, 'can_staff' ),
		) );
		register_rest_route( $ns, '/inbox/(?P<id>\d+)/assign', array(
			'methods' => 'POST', 'callback' => array( $this, 'rest_staff_assign' ), 'permission_callback' => array( $this, 'can_staff' ),
		) );
		register_rest_route( $ns, '/inbox/(?P<id>\d+)/return-ai', array(
			'methods' => 'POST', 'callback' => array( $this, 'rest_return_ai' ), 'permission_callback' => array( $this, 'can_staff' ),
		) );
	}

	public function can_staff(): bool { return current_user_can( 'edit_posts' ) || current_user_can( Activator::CAPABILITY ); }

	private function private_response( $data, int $status = 200 ): \WP_REST_Response {
		$res = new \WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store, private, max-age=0' );
		return $res;
	}

	private function rate_limit_ok( string $key, int $limit = 20, int $window = 60 ): bool {
		$k = 'bmtg_chat_rl_' . md5( $key );
		$v = (int) get_transient( $k );
		if ( $v >= $limit ) return false;
		set_transient( $k, $v + 1, $window );
		return true;
	}

	public function rest_session( \WP_REST_Request $req ): \WP_REST_Response {
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		if ( ! $this->rate_limit_ok( 'session_' . $ip, 30, 300 ) ) {
			return $this->private_response( array( 'error' => 'Too many requests.' ), 429 );
		}
		$token = wp_generate_password( 32, false, false );
		$post_id = wp_insert_post( array(
			'post_type' => self::CONVERSATION_CPT, 'post_status' => 'private', 'post_title' => 'Chat ' . gmdate( 'c' ),
		), true );
		if ( is_wp_error( $post_id ) ) return $this->private_response( array( 'error' => 'Unable to start session.' ), 500 );
		update_post_meta( $post_id, '_bmtg_token_hash', wp_hash_password( $token ) );
		update_post_meta( $post_id, '_bmtg_messages', wp_json_encode( array() ) );
		update_post_meta( $post_id, '_bmtg_status', 'ai' );
		update_post_meta( $post_id, '_bmtg_context', 'public_website' );
		update_post_meta( $post_id, '_bmtg_consent', false );
		update_post_meta( $post_id, '_bmtg_created_at', gmdate( 'c' ) );
		$this->dispatch_inbox_event( $post_id, 'conversation_started', array() );
		return $this->private_response( array( 'session_id' => $post_id, 'session_token' => $token ) );
	}

	private function get_conversation( \WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'session_id' );
		$token = (string) $req->get_param( 'session_token' );
		if ( $id <= 0 || $token === '' ) return null;
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== self::CONVERSATION_CPT ) return null;
		$hash = (string) get_post_meta( $id, '_bmtg_token_hash', true );
		if ( ! $hash || ! wp_check_password( $token, $hash ) ) return null;
		if ( (string) get_post_meta( $id, '_bmtg_context', true ) !== 'public_website' ) return null; // strict separation
		return $post;
	}

	public function rest_message( \WP_REST_Request $req ): \WP_REST_Response {
		$post = $this->get_conversation( $req );
		if ( ! $post ) return $this->private_response( array( 'error' => 'Invalid session.' ), 403 );
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		if ( ! $this->rate_limit_ok( 'msg_' . $ip . '_' . $post->ID, 20, 60 ) ) {
			return $this->private_response( array( 'error' => 'Too many messages. Please wait.' ), 429 );
		}
		$text = trim( wp_strip_all_tags( (string) $req->get_param( 'message' ) ) );
		if ( $text === '' || mb_strlen( $text ) > 2000 ) return $this->private_response( array( 'error' => 'Invalid message.' ), 400 );
		// Basic spam guard.
		if ( preg_match('/https?:\/\//i', $text) && substr_count($text, 'http') > 2 ) {
			return $this->private_response( array( 'error' => 'Message rejected.' ), 400 );
		}

		$messages = json_decode( (string) get_post_meta( $post->ID, '_bmtg_messages', true ), true );
		if ( ! is_array( $messages ) ) $messages = array();
		$messages[] = array( 'role' => 'user', 'content' => $text, 'at' => gmdate( 'c' ) );
		update_post_meta( $post->ID, '_bmtg_messages', wp_json_encode( $messages ) );
		$this->dispatch_inbox_event( $post->ID, 'message', array( 'role' => 'user', 'content' => $text ) );

		$status = (string) get_post_meta( $post->ID, '_bmtg_status', true );
		if ( $status === 'staff' ) {
			return $this->private_response( array( 'reply' => null, 'status' => 'staff', 'notice' => 'A team member will reply here.' ) );
		}

		$reply = $this->generate_reply( $messages, $post->ID );
		$messages[] = array( 'role' => 'assistant', 'content' => $reply, 'at' => gmdate( 'c' ) );
		update_post_meta( $post->ID, '_bmtg_messages', wp_json_encode( $messages ) );
		$this->dispatch_inbox_event( $post->ID, 'message', array( 'role' => 'assistant', 'content' => $reply ) );
		return $this->private_response( array( 'reply' => $reply, 'status' => 'ai' ) );
	}

	private function generate_reply( array $messages, int $conversation_id ): string {
		$s = $this->settings();
		$system = $this->system_prompt( $s );
		$payload_messages = array_merge( array( array( 'role' => 'system', 'content' => $system ) ), array_map(function($m){
			return array( 'role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) ($m['content'] ?? '') );
		}, array_slice($messages, -20) ) );

		$reply = null;
		try {
			$client = Manager::client( 'groq', 'live' );
			$res = $client->chat_completion( array( 'model' => $s['model_groq'] ?: 'llama-3.3-70b-versatile', 'messages' => $payload_messages, 'temperature' => 0.4 ) );
			if ( ! empty( $res['body']['choices'][0]['message']['content'] ) ) {
				$reply = (string) $res['body']['choices'][0]['message']['content'];
			}
		} catch ( \Throwable $e ) { $reply = null; }
		if ( $reply === null ) {
			try {
				$client = Manager::client( 'openrouter', 'live' );
				$res = $client->openrouter_chat_completion( array( 'model' => $s['model_openrouter'] ?: 'openai/gpt-4o-mini', 'messages' => $payload_messages, 'temperature' => 0.4 ) );
				if ( ! empty( $res['body']['choices'][0]['message']['content'] ) ) {
					$reply = (string) $res['body']['choices'][0]['message']['content'];
				}
			} catch ( \Throwable $e ) { $reply = null; }
		}
		if ( $reply === null || trim( $reply ) === '' ) {
			return (string) ($s['fallback_message'] ?: 'I can connect you with our team if you would like.');
		}
		return $this->apply_safety_filters( $reply );
	}

	private function system_prompt( array $s ): string {
		$knowledge = '';
		if ( ! empty( $s['knowledge'] ) && is_array( $s['knowledge'] ) ) {
			$knowledge = "\nApproved knowledge:\n- " . implode("\n- ", array_map('strval', $s['knowledge']));
		}
		return "You are the BM Travel Group (BMTG) public website assistant for bmtravelgroup.com. Be warm, refined, concise, and helpful. " .
			"Use only approved BMTG knowledge, destination information, services and published journeys. Help visitors discover journeys in India, Nepal, Bhutan and Sri Lanka. " .
			"Ask one question at a time and avoid repeating information. " .
			"Never disclose public prices, supplier rates, invented availability, or unconfirmed arrangements. " .
			"Capture contact/travel requirements only when the visitor requests assistance and explicitly consents. " .
			"Do not expose private guest conversations, proposals, itineraries or documents. " .
			"If information is unavailable, offer human handover.\nOperating hours/fallback guidance: " . (string) $s['operating_hours'] . $knowledge;
	}

	private function apply_safety_filters( string $reply ): string {
		// Remove likely price/rate disclosures (currency amounts) to enforce policy conservatively.
		$reply = preg_replace('/(?:USD|EUR|GBP|INR|NPR|BTN|LKR|Rs\.?|₹|\$|€|£)\s?\d[\d,]*(?:\.\d+)?/i', '[pricing available on request]', $reply);
		return trim( $reply );
	}

	public function rest_lead( \WP_REST_Request $req ): \WP_REST_Response {
		$post = $this->get_conversation( $req );
		if ( ! $post ) return $this->private_response( array( 'error' => 'Invalid session.' ), 403 );
		$consent = (bool) $req->get_param( 'consent' );
		if ( ! $consent ) return $this->private_response( array( 'error' => 'Explicit consent is required.' ), 400 );
		update_post_meta( $post->ID, '_bmtg_consent', true );
		$contact = array(
			'name'        => sanitize_text_field( (string) $req->get_param( 'name' ) ),
			'email'       => sanitize_email( (string) $req->get_param( 'email' ) ),
			'phone'       => sanitize_text_field( (string) $req->get_param( 'phone' ) ),
			'requirements' => sanitize_textarea_field( (string) $req->get_param( 'requirements' ) ),
			'submitted_at'=> gmdate( 'c' ),
		);
		if ( empty( $contact['email'] ) && empty( $contact['phone'] ) ) {
			return $this->private_response( array( 'error' => 'Contact details required.' ), 400 );
		}
		update_post_meta( $post->ID, '_bmtg_contact', wp_json_encode( $contact ) );
		// Create/link CRM lead only after successful submission.
		$lead_id = apply_filters( 'bmtg_create_or_link_crm_lead', null, $contact, $post->ID );
		if ( $lead_id ) update_post_meta( $post->ID, '_bmtg_lead_id', $lead_id );
		$this->dispatch_inbox_event( $post->ID, 'lead_submitted', $contact );
		do_action( 'bmtg_chatbot_lead_submitted', $contact, $post->ID, $lead_id );
		return $this->private_response( array( 'ok' => true, 'lead_id' => $lead_id ) );
	}

	public function rest_handover( \WP_REST_Request $req ): \WP_REST_Response {
		$post = $this->get_conversation( $req );
		if ( ! $post ) return $this->private_response( array( 'error' => 'Invalid session.' ), 403 );
		update_post_meta( $post->ID, '_bmtg_status', 'handover' );
		$this->dispatch_inbox_event( $post->ID, 'handover_requested', array() );
		do_action( 'bmtg_chatbot_handover_requested', $post->ID );
		return $this->private_response( array( 'ok' => true, 'message' => $this->settings()['handover_message'] ) );
	}

	public function rest_staff_reply( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$text = sanitize_textarea_field( (string) $req->get_param( 'message' ) );
		if ( $text === '' ) return $this->private_response( array( 'error' => 'Message required.' ), 400 );
		$messages = json_decode( (string) get_post_meta( $id, '_bmtg_messages', true ), true ) ?: array();
		$messages[] = array( 'role' => 'staff', 'content' => $text, 'at' => gmdate( 'c' ), 'user_id' => get_current_user_id() );
		update_post_meta( $id, '_bmtg_messages', wp_json_encode( $messages ) );
		update_post_meta( $id, '_bmtg_status', 'staff' );
		$this->dispatch_inbox_event( $id, 'staff_reply', array( 'content' => $text ) );
		return $this->private_response( array( 'ok' => true ) );
	}

	public function rest_staff_assign( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		$assignee = (int) $req->get_param( 'assignee' );
		update_post_meta( $id, '_bmtg_assignee', $assignee );
		$this->dispatch_inbox_event( $id, 'assigned', array( 'assignee' => $assignee ) );
		return $this->private_response( array( 'ok' => true ) );
	}

	public function rest_return_ai( \WP_REST_Request $req ): \WP_REST_Response {
		$id = (int) $req['id'];
		update_post_meta( $id, '_bmtg_status', 'ai' );
		$this->dispatch_inbox_event( $id, 'returned_to_ai', array() );
		return $this->private_response( array( 'ok' => true ) );
	}

	public function dispatch_inbox_event( int $conversation_id, string $event, array $payload ): void {
		$messages = json_decode( (string) get_post_meta( $conversation_id, '_bmtg_messages', true ), true ) ?: array();
		$record = array(
			'conversation_id' => $conversation_id,
			'event'           => $event,
			'payload'         => $payload,
			'messages'        => $messages, // exact history snapshot for unified inbox consumers
			'context'         => 'public_website',
			'at'              => gmdate( 'c' ),
		);
		// Central unified inbox integration point (must receive every conversation/exact history).
		do_action( 'bmtg_unified_inbox_event', $record );
	}

	public function run_retention_cleanup(): void {
		$days = max( 1, (int) $this->settings()['retention_days'] );
		$before = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$q = new \WP_Query( array(
			'post_type' => self::CONVERSATION_CPT,
			'post_status' => 'any',
			'date_query' => array( array( 'before' => $before ) ),
			'fields' => 'ids',
			'posts_per_page' => 100,
		) );
		foreach ( $q->posts as $id ) { wp_delete_post( (int) $id, true ); }
	}
}
