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
			'whatsapp_number'      => '',
			'whatsapp_prefill'     => 'Hello BM Travel Group, I would like assistance planning a journey.',
			'business_name'        => 'BM Travel Group',
			'business_address'     => '',
			'logo_url'             => '',
			'contact_email'        => '',
			'contact_phone'        => '',
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
		$s = $this->settings();
		$wa_num = preg_replace( '/\D+/', '', (string) ( $s['whatsapp_number'] ?? '' ) );
		$wa_url = '';
		if ( $wa_num !== '' ) {
			$wa_url = 'https://wa.me/' . rawurlencode( $wa_num ) . '?text=' . rawurlencode( (string) ( $s['whatsapp_prefill'] ?? 'Hello BM Travel Group, I would like assistance planning a journey.' ) );
		}
		wp_localize_script( 'bmtg-chatbot-widget', 'BMTGChatbot', array(
			'restUrl'          => esc_url_raw( rest_url( 'bmtg/v1/chatbot' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'greeting'         => $s['greeting'],
			'operatingHours'   => $s['operating_hours'],
			'handoverMessage'  => $s['handover_message'],
			'requireConsent'   => ! empty( $s['require_consent'] ),
			'whatsappUrl'      => $wa_url,
			'whatsappLabel'    => 'Continue on WhatsApp',
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
		$image = $this->maybe_attach_unsplash_image( $text, $messages );
		if ( ! $image && $this->user_wants_image( $text ) ) {
			$note = 'I am unable to display a photo right now. If you would like, I can share travel details or help plan a visit.';
			if ( $reply === '' || stripos( $reply, 'unable to display' ) === false ) {
				$reply = trim( $reply . "\n\n" . $note );
			}
		}
		$messages[] = array( 'role' => 'assistant', 'content' => $reply, 'at' => gmdate( 'c' ) );
		if ( $image ) {
			$messages[] = array( 'role' => 'assistant', 'content' => '[image]', 'at' => gmdate( 'c' ), 'image' => $image );
			$this->dispatch_inbox_event( $post->ID, 'inspiration_image', array( 'image' => $image, 'query' => $text ) );
		}
		update_post_meta( $post->ID, '_bmtg_messages', wp_json_encode( $messages ) );
		$this->dispatch_inbox_event( $post->ID, 'message', array( 'role' => 'assistant', 'content' => $reply ) );
		return $this->private_response( array( 'reply' => $reply, 'status' => 'ai', 'image' => $image ) );
	}

	private function user_wants_image( string $text ): bool {
		$t = mb_strtolower( $text );
		return (bool) preg_match('/\b(photo|photos|picture|pictures|image|images|show|see|look)\b/u', $t);
	}

	private function maybe_attach_unsplash_image( string $text, array $messages = array() ): ?array {
		if ( ! $this->user_wants_image( $text ) ) return null;
		$query = $this->extract_image_query( $text );
		if ( $query === '' ) {
			$query = $this->extract_recent_image_query( $messages );
		}
		if ( $query === '' ) return null;
		try {
			$client = Manager::client( 'unsplash', 'live' );
			$res = $client->search_photos( $query, 1, 1 );
			if ( ! empty( $res['body']['results'][0] ) && is_array( $res['body']['results'][0] ) ) {
				$p = $res['body']['results'][0];
				$url = $p['urls']['small'] ?? ($p['urls']['regular'] ?? null);
				if ( ! $url ) return null;
				$photographer = $p['user']['name'] ?? 'Unsplash';
				$link = $p['links']['html'] ?? 'https://unsplash.com';
				return array(
					'url' => $url,
					'alt' => $p['alt_description'] ?? $query,
					'credit' => 'Photo: ' . $photographer . ' / Unsplash',
					'source' => $link,
					'query' => $query,
				);
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	private function extract_recent_image_query( array $messages ): string {
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$m = $messages[$i];
			if ( ( $m['role'] ?? '' ) !== 'user' ) continue;
			$q = $this->extract_image_query( (string) ( $m['content'] ?? '' ) );
			if ( $q !== '' ) return $q;
		}
		return '';
	}

	private function extract_image_query( string $text ): string {
		$t = trim( $text );
		if ( preg_match('/taj\s*mahal/i', $t) ) return 'Taj Mahal Agra';
		// Simple heuristic: remove common words.
		$clean = preg_replace('/\b(show|me|a|an|the|photo|photos|picture|pictures|image|images|of|please|can|you|see|look|i|want|to|did|didnot|didn\'t|not)\b/i', ' ', $t);
		$clean = trim(preg_replace('/\s+/', ' ', (string)$clean));
		if ($clean === '' || mb_strlen($clean) < 3) return '';
		return mb_substr($clean, 0, 80);
	}

	private function generate_reply( array $messages, int $conversation_id ): string {
		$s = $this->settings();
		$latest = '';
		for ( $i = count($messages)-1; $i >= 0; $i-- ) { if ( ($messages[$i]['role'] ?? '') === 'user' ) { $latest = (string)($messages[$i]['content'] ?? ''); break; } }
		if ( $latest !== '' && $this->is_disallowed_request( $latest ) ) {
			$refusal = 'I can only assist with respectful travel planning for BM Travel Group destinations and services. I cannot help with sexual, illegal, violent, hateful, or otherwise inappropriate requests. If you wish, I can help you plan a journey in India, Nepal, Bhutan, or Sri Lanka.';
			$this->dispatch_inbox_event( $conversation_id, 'safety_refusal', array( 'reason' => 'policy' ) );
			return $refusal;
		}

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

	private function is_disallowed_request( string $text ): bool {
		$t = mb_strtolower( $text );
		$patterns = array( 'sex', 'sexual', 'nude', 'porn', 'escort', 'prostitut', 'rape', 'child abuse', 'minor', 'illegal', 'drugs', 'cocaine', 'heroin', 'weapon', 'bomb', 'kill', 'suicide', 'terror', 'hack', 'fraud', 'smuggl' );
		foreach ( $patterns as $p ) { if ( strpos( $t, $p ) !== false ) return true; }
		return false;
	}

	private function system_prompt( array $s ): string {
		$knowledge = '';
		if ( ! empty( $s['knowledge'] ) && is_array( $s['knowledge'] ) ) {
			$knowledge = "\nApproved knowledge:\n- " . implode("\n- ", array_map('strval', $s['knowledge']));
		}
		$site = $this->get_site_context( $s );
		return "You are the BM Travel Group (BMTG) public website assistant for bmtravelgroup.com. Be warm, refined, concise, and helpful. " .
			"Use only approved BMTG knowledge, published website information provided below, destination information, services and published journeys. Help visitors discover journeys in India, Nepal, Bhutan and Sri Lanka. " .
			"When visitors ask about the company, journeys, destinations, services, or how to get in touch, answer using the website/contact information below when relevant. " .
			"If WhatsApp or other contact details are provided, you may share them clearly so the visitor can connect directly. " .
			"Ask one question at a time and avoid repeating information. " .
			"Never disclose public prices, supplier rates, invented availability, or unconfirmed arrangements. " .
			"Capture contact/travel requirements only when the visitor requests assistance and explicitly consents. " .
			"Do not expose private guest conversations, proposals, itineraries or documents. " .
			"Refuse and do not answer requests that are sexual, violent, hateful, illegal, abusive, or otherwise inappropriate; respond with a brief safe refusal and offer travel assistance only. " .
			"If information is unavailable, offer human handover. " .
			"Never invent or include image URLs or markdown images. If the visitor asks to see a place/photo, describe it briefly; the system will attach an approved photo separately when available. If no photo can be shown, explain that and offer to assist with travel details.\nOperating hours/fallback guidance: " . (string) $s['operating_hours'] . $site . $knowledge;
	}

	private function get_site_context( array $s ): string {
		$cached = get_transient( 'bmtg_chatbot_site_context_v1' );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $this->append_contact_context( $cached, $s );
		}
		$lines = array();
		$lines[] = 'Website: ' . get_bloginfo( 'name' ) . ' — ' . get_bloginfo( 'description' );
		$lines[] = 'Site URL: ' . home_url( '/' );

		$pages = get_posts( array(
			'post_type' => array( 'page', 'post' ),
			'post_status' => 'publish',
			'numberposts' => 20,
			'orderby' => 'modified',
			'order' => 'DESC',
		) );
		foreach ( $pages as $p ) {
			if ( ! $p instanceof \WP_Post ) continue;
			$title = trim( wp_strip_all_tags( get_the_title( $p ) ) );
			$url = get_permalink( $p );
			$excerpt = trim( wp_strip_all_tags( has_excerpt( $p ) ? get_the_excerpt( $p ) : wp_trim_words( (string) $p->post_content, 24, '...' ) ) );
			$excerpt = preg_replace( '/\s+/', ' ', $excerpt );
			if ( $title === '' || ! $url ) continue;
			$line = $title . ': ' . $url;
			if ( $excerpt !== '' ) $line .= ' — ' . mb_substr( $excerpt, 0, 140 );
			$lines[] = $line;
		}

		$context = "\nPublished website information:\n- " . implode( "\n- ", array_slice( $lines, 0, 22 ) );
		$context = mb_substr( $context, 0, 3500 );
		set_transient( 'bmtg_chatbot_site_context_v1', $context, HOUR_IN_SECONDS );
		return $this->append_contact_context( $context, $s );
	}

	private function append_contact_context( string $context, array $s ): string {
		$parts = array();
		$biz = trim( (string) ( $s['business_name'] ?? '' ) );
		$phone = trim( (string) ( $s['contact_phone'] ?? '' ) );
		$email = trim( (string) ( $s['contact_email'] ?? '' ) );
		$addr = trim( (string) ( $s['business_address'] ?? '' ) );
		$wa = preg_replace( '/\D+/', '', (string) ( $s['whatsapp_number'] ?? '' ) );
		if ( $biz !== '' ) $parts[] = 'Business: ' . $biz;
		if ( $phone !== '' ) $parts[] = 'Phone: ' . $phone;
		if ( $email !== '' ) $parts[] = 'Email: ' . $email;
		if ( $addr !== '' ) $parts[] = 'Address: ' . preg_replace( '/\s+/', ' ', $addr );
		if ( $wa !== '' ) $parts[] = 'WhatsApp: https://wa.me/' . rawurlencode( $wa );
		if ( empty( $parts ) ) return $context;
		return $context . "\nContact details:\n- " . implode( "\n- ", $parts );
	}

	private function apply_safety_filters( string $reply ): string {
		// Remove likely price/rate disclosures (currency amounts) to enforce policy conservatively.
		$reply = preg_replace('/(?:USD|EUR|GBP|INR|NPR|BTN|LKR|Rs\.?|₹|\$|€|£)\s?\d[\d,]*(?:\.\d+)?/i', '[pricing available on request]', $reply);
		// Strip markdown/inline images and raw image URLs the model may invent; images are attached by the system only.
		$reply = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $reply);
		$reply = preg_replace('/https?:\/\/\S+\.(?:jpg|jpeg|png|gif|webp)(?:\?\S*)?/i', '', $reply);
		$reply = preg_replace('/https?:\/\/(?:upload\.wikimedia\.org|images\.unsplash\.com|unsplash\.com\/photos)\S*/i', '', $reply);
		$reply = preg_replace('/\n{3,}/', "\n\n", (string) $reply);
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
