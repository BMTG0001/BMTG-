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
			'greeting'             => "Hi! 👋 Welcome to **BM Travel Group**.\n\nHow can I help with your journey in **India, Nepal, Bhutan, or Sri Lanka** today?",
			'operating_hours'      => '',
			'fallback_message'     => "Sorry, I couldn’t get that just now. 🙏\n\nWould you like me to connect you with our team?",
			'handover_message'     => "Sure thing! A member of our **BM Travel Group** team will assist you shortly.\n\nAnything else you’d like to share meanwhile?",
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
			// Appearance / layout
			'ui_header_bg'         => '#075e54',
			'ui_header_accent'     => '#128c7e',
			'ui_chat_bg'           => '#ece5dd',
			'ui_incoming_bg'       => '#ffffff',
			'ui_outgoing_bg'       => '#d9fdd3',
			'ui_accent'            => '#25d366',
			'ui_text_color'        => '#111b21',
			'ui_muted_color'       => '#667781',
			'ui_tick_color'        => '#53bdeb',
			'ui_surface_bg'        => '#f0f2f5',
			'ui_header_title'      => 'BM Travel Group',
			'ui_header_tagline'    => 'Concierge · bmtravelgroup.com',
			'ui_launcher_label'    => 'Chat with us',
			'ui_icon_url'          => '',
			'ui_font_family'       => 'inherit',
			'ui_font_size'         => '14.5',
			'ui_headline_size'     => '15',
			'ui_tagline_size'      => '12',
			'ui_timestamp_size'    => '11',
			'ui_button_bg'         => '#128c7e',
			'ui_button_text'       => '#ffffff',
			'ui_button_font_size'  => '13',
			'ui_button_radius'     => '18',
			// Canva itinerary
			'canva_itinerary_enabled'      => false,
			'canva_itinerary_template_id'  => '',
			'canva_itinerary_design_type'  => 'doc',
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
		$ui = $this->ui_settings( $s );
		$ui['headerTitle'] = 'BM Travel Group Chat';
		$ui['headerTagline'] = '';
		$inline = $this->ui_inline_css( $ui );
		if ( $inline ) {
			wp_add_inline_style( 'bmtg-chatbot-widget', $inline );
		}
		wp_localize_script( 'bmtg-chatbot-widget', 'BMTGChatbot', array(
			'restUrl'          => esc_url_raw( rest_url( 'bmtg/v1/chatbot' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'greeting'         => $s['greeting'],
			'operatingHours'   => $s['operating_hours'],
			'handoverMessage'  => $s['handover_message'],
			'requireConsent'   => ! empty( $s['require_consent'] ),
			'whatsappUrl'      => $wa_url,
			'ui'               => $ui,
			'canvaItinerary'   => ! empty( $s['canva_itinerary_enabled'] ),
		) );
	}

	public function ui_settings( array $s ): array {
		return array(
			'headerBg'       => $s['ui_header_bg'] ?? '#075e54',
			'headerAccent'   => $s['ui_header_accent'] ?? '#128c7e',
			'chatBg'         => $s['ui_chat_bg'] ?? '#ece5dd',
			'incomingBg'     => $s['ui_incoming_bg'] ?? '#ffffff',
			'outgoingBg'     => $s['ui_outgoing_bg'] ?? '#d9fdd3',
			'accent'         => $s['ui_accent'] ?? '#25d366',
			'textColor'      => $s['ui_text_color'] ?? '#111b21',
			'mutedColor'     => $s['ui_muted_color'] ?? '#667781',
			'tickColor'      => $s['ui_tick_color'] ?? '#53bdeb',
			'surfaceBg'      => $s['ui_surface_bg'] ?? '#f0f2f5',
			'headerTitle'    => $s['ui_header_title'] ?? 'BM Travel Group',
			'headerTagline'  => $s['ui_header_tagline'] ?? 'Concierge · bmtravelgroup.com',
			'launcherLabel'  => $s['ui_launcher_label'] ?? 'Chat with us',
			'iconUrl'        => $s['ui_icon_url'] ?? '',
			'fontFamily'     => $s['ui_font_family'] ?? 'inherit',
			'fontSize'       => $s['ui_font_size'] ?? '14.5',
			'headlineSize'   => $s['ui_headline_size'] ?? '15',
			'taglineSize'    => $s['ui_tagline_size'] ?? '12',
			'timestampSize'  => $s['ui_timestamp_size'] ?? '11',
			'buttonBg'       => $s['ui_button_bg'] ?? '#128c7e',
			'buttonText'     => $s['ui_button_text'] ?? '#ffffff',
			'buttonFontSize' => $s['ui_button_font_size'] ?? '13',
			'buttonRadius'   => $s['ui_button_radius'] ?? '18',
		);
	}

	private function ui_inline_css( array $ui ): string {
		$font = $ui['fontFamily'] === 'inherit' ? 'inherit' : $ui['fontFamily'];
		$vars = array(
			'--bmtg-header'         => $ui['headerBg'],
			'--bmtg-header-accent'  => $ui['headerAccent'],
			'--bmtg-chat-bg'        => $ui['chatBg'],
			'--bmtg-in'             => $ui['incomingBg'],
			'--bmtg-out'            => $ui['outgoingBg'],
			'--bmtg-accent'         => $ui['accent'],
			'--bmtg-text'           => $ui['textColor'],
			'--bmtg-muted'          => $ui['mutedColor'],
			'--bmtg-tick'           => $ui['tickColor'],
			'--bmtg-surface'        => $ui['surfaceBg'],
			'--bmtg-font-family'    => $font,
			'--bmtg-font-size'      => $ui['fontSize'] . 'px',
			'--bmtg-headline-size'  => $ui['headlineSize'] . 'px',
			'--bmtg-tagline-size'   => $ui['taglineSize'] . 'px',
			'--bmtg-timestamp-size' => $ui['timestampSize'] . 'px',
			'--bmtg-button-bg'      => $ui['buttonBg'],
			'--bmtg-button-text'    => $ui['buttonText'],
			'--bmtg-button-font-size'=> $ui['buttonFontSize'] . 'px',
			'--bmtg-button-radius'  => $ui['buttonRadius'] . 'px',
		);
		$decl = '';
		foreach ( $vars as $k => $v ) {
			$decl .= $k . ':' . $v . ';';
		}
		$css = '.bmtg-chat-launcher,.bmtg-chat-panel{' . $decl . '}';
		$css .= '.bmtg-chat-panel{font-family:var(--bmtg-font-family,inherit);font-size:var(--bmtg-font-size,14.5px);}';
		$css .= '.bmtg-chat-launcher{font-family:var(--bmtg-font-family,inherit);}';
		$css .= '.bmtg-chat-header .bmtg-title{font-size:var(--bmtg-headline-size,15px);}';
		$css .= '.bmtg-chat-header .bmtg-sub{display:none !important;}';
		$css .= '.bmtg-msg .bmtg-meta{font-size:var(--bmtg-timestamp-size,11px);}';
		$css .= '.bmtg-icon-btn,.bmtg-lead-form .bmtg-submit,.bmtg-nudge-btn.primary{background:var(--bmtg-button-bg,#128c7e);color:var(--bmtg-button-text,#fff);font-size:var(--bmtg-button-font-size,13px);border-radius:var(--bmtg-button-radius,18px);}';
		$css .= '.bmtg-icon-btn{border-radius:50%;}';
		$css .= '.bmtg-chat-header{background:var(--bmtg-header,#075e54);}';
		$css .= '.bmtg-chat-messages{background-color:var(--bmtg-chat-bg,#ece5dd);}';
		$css .= '.bmtg-msg.assistant{background:var(--bmtg-in,#fff);}';
		$css .= '.bmtg-msg.user{background:var(--bmtg-out,#d9fdd3);}';
		$css .= '.bmtg-msg .bmtg-ticks{color:var(--bmtg-tick,#53bdeb);}';
		$css .= '.bmtg-chat-actions,.bmtg-chat-input{background:var(--bmtg-surface,#f0f2f5);}';
		return $css;
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
		register_rest_route( $ns, '/itinerary-pdf', array(
			'methods' => 'POST', 'callback' => array( $this, 'rest_itinerary_pdf' ), 'permission_callback' => '__return_true',
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
		// Meta (Facebook Messenger / WhatsApp Cloud) webhooks for auto-answer.
		register_rest_route( $ns, '/webhook/facebook', array(
			'methods' => array( 'GET', 'POST' ), 'callback' => array( $this, 'rest_webhook_facebook' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/webhook/whatsapp', array(
			'methods' => array( 'GET', 'POST' ), 'callback' => array( $this, 'rest_webhook_whatsapp' ), 'permission_callback' => '__return_true',
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
		$extra = array();
		$canva = $this->maybe_create_canva_itinerary( $text, $reply, $messages );
		if ( $canva ) {
			if ( ! empty( $canva['url'] ) ) {
				$reply = trim( $reply . "\n\n**Itinerary design (Canva):** " . $canva['url'] . "\nWould you like me to adjust the destinations or duration?" );
			} elseif ( ! empty( $canva['notice'] ) ) {
				$reply = trim( $reply . "\n\n" . $canva['notice'] );
			}
			$extra['canva'] = $canva;
		}
		$pdf_url = $this->maybe_create_itinerary_pdf( $text, $messages, $post->ID );
		if ( $pdf_url ) {
			if ( stripos( $reply, $pdf_url ) === false && stripos( $reply, 'Download itinerary' ) === false ) {
				$reply = trim( $reply . "\n\n**Itinerary PDF (download/print):** " . $pdf_url );
			}
		}
		$messages[] = array( 'role' => 'assistant', 'content' => $reply, 'at' => gmdate( 'c' ) );
		if ( $image ) {
			$messages[] = array( 'role' => 'assistant', 'content' => '[image]', 'at' => gmdate( 'c' ), 'image' => $image );
			$this->dispatch_inbox_event( $post->ID, 'inspiration_image', array( 'image' => $image, 'query' => $text ) );
		}
		update_post_meta( $post->ID, '_bmtg_messages', wp_json_encode( $messages ) );
		$this->dispatch_inbox_event( $post->ID, 'message', array( 'role' => 'assistant', 'content' => $reply ) );
		$out = array( 'reply' => $reply, 'status' => 'ai', 'image' => $image );
		if ( $canva ) $out['canva'] = $canva;
		if ( $pdf_url ) $out['pdf_url'] = $pdf_url;
		return $this->private_response( $out );
	}

	private function maybe_create_canva_itinerary( string $user_text, string $reply, array $messages ): ?array {
		$s = $this->settings();
		if ( empty( $s['canva_itinerary_enabled'] ) ) return null;
		$text = trim( (string) $user_text );
		$t = mb_strtolower( $text );
		// Only when user explicitly asks to create/make/generate/design a Canva itinerary/plan/design.
		$explicit = (bool) preg_match('/\b(canva|create|make|generate|design|draft|prepare|build)\b.{0,40}\b(itinerary|plan|day\s*by\s*day|trip\s*plan|travel\s*plan|design)\b|\b(itinerary|plan|day\s*by\s*day|trip\s*plan|travel\s*plan)\b.{0,40}\b(in|on|with)\s+canva\b/u', $t);
		if ( ! $explicit ) return null;
		if ( mb_strlen( $text ) < 10 ) return null;
		$has_dest = (bool) preg_match('/\b(india|nepal|bhutan|sri lanka|taj|rajasthan|kerala|goa|himalaya|ladakh|kashmir|varanasi|delhi|jaipur|mumbai|kolkata|kathmandu|pokhara|colombo|kandy|galle|sigiriya|thimphu|paro|punakha)\b/i', $t);
		$has_days = (bool) preg_match('/\b\d+\s*(day|days|night|nights)\b/i', $t);
		if ( ! $has_dest && ! $has_days ) return null;
		try {
			if ( class_exists( '\\BMTG\\Integrations\\Itinerary' ) ) {
				$title = 'BM Travel Group Journey';
				if ( preg_match('/\b(india|nepal|bhutan|sri lanka|taj|rajasthan|kerala|himalaya|kathmandu|colombo)\b/i', $text, $m) ) {
					$title = 'BM Travel Group - ' . ucwords(strtolower($m[1])) . ' Journey';
				}
				$result = \BMTG\Integrations\Itinerary::create_canva_itinerary( $title, $text, $s['canva_itinerary_template_id'] ?? '' );
				// Keep response useful even on Canva API failure (best-effort: don't hide a helpful outcome).
				if ( is_array($result) && empty($result['ok']) && empty($result['notice']) ) {
					$result['notice'] = 'I can prepare the itinerary details here. The Canva design link is not available right now, but I can share an itinerary PDF if you would like.';
				}
				return $result;
			}
		} catch ( \Throwable $e ) { return array( 'ok' => false, 'notice' => 'I can prepare the itinerary details here. The Canva design link is not available right now, but I can share an itinerary PDF if you would like.' ); }
		return null;
	}

	private function user_wants_pdf( string $text ): bool {
		$t = mb_strtolower( $text );
		return (bool) preg_match('/\b(pdf|download|print|printable|save.*itinerary|itinerary.*(pdf|download|print))\b/u', $t);
	}

	private function maybe_create_itinerary_pdf( string $user_text, array $messages, int $conversation_id ): ?string {
		$t = mb_strtolower( (string) $user_text );
		// Create when user explicitly asks for pdf/download/print of an itinerary/plan, or asks to create/generate an itinerary.
		$wants_pdf = $this->user_wants_pdf( $user_text ) && preg_match('/\b(itinerary|plan|trip|journey|tour)\b/u', $t);
		$wants_create_itinerary = (bool) preg_match('/\b(create|make|generate|draft|prepare|build|share|give|send)\b.{0,40}\b(itinerary|plan|day\s*by\s*day|trip\s*plan|travel\s*plan)\b|\b(itinerary|plan|day\s*by\s*day|trip\s*plan|travel\s*plan)\b/u', $t);
		if ( ! $wants_pdf && ! $wants_create_itinerary ) return null;
		if ( ! preg_match('/\b\d+\s*(day|days|night|nights)\b|\b(india|nepal|bhutan|sri lanka|taj|rajasthan|kerala|goa|himalaya|ladakh|kashmir|varanasi|delhi|jaipur|mumbai|kolkata|kathmandu|pokhara|colombo|kandy|galle|sigiriya|thimphu|paro|punakha)\b/i', $user_text) ) return null;
		try {
			if ( class_exists( '\\BMTG\\Integrations\\Itinerary' ) ) {
				$pdf = \BMTG\Integrations\Itinerary::generate_chat_pdf( $user_text, $conversation_id );
				if ( is_array($pdf) && ! empty($pdf['pdf_url']) ) return (string) $pdf['pdf_url'];
			}
		} catch ( \Throwable $e ) { return null; }
		return null;
	}

	public function rest_itinerary_pdf( \WP_REST_Request $req ): \WP_REST_Response {
		$post = $this->get_conversation( $req );
		if ( ! $post ) return $this->private_response( array( 'error' => 'Invalid session.' ), 403 );
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		if ( ! $this->rate_limit_ok( 'pdf_' . $ip . '_' . $post->ID, 6, 300 ) ) {
			return $this->private_response( array( 'error' => 'Please wait before requesting another itinerary.' ), 429 );
		}
		$text = trim( wp_strip_all_tags( (string) $req->get_param( 'message' ) ) );
		if ( $text === '' ) $text = 'BM Travel Group itinerary';
		try {
			if ( ! class_exists( '\\BMTG\\Integrations\\Itinerary' ) ) return $this->private_response( array( 'error' => 'Itinerary module unavailable.' ), 500 );
			$pdf = \BMTG\Integrations\Itinerary::generate_chat_pdf( $text, $post->ID );
			if ( is_array($pdf) && ! empty($pdf['pdf_url']) ) {
				return $this->private_response( array( 'ok' => true, 'pdf_url' => $pdf['pdf_url'], 'title' => $pdf['title'] ?? '' ) );
			}
		} catch ( \Throwable $e ) {
			return $this->private_response( array( 'error' => 'Unable to create itinerary right now.' ), 500 );
		}
		return $this->private_response( array( 'error' => 'Unable to create itinerary right now.' ), 500 );
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
		return "You are the official BM Travel Group (BMTG) concierge for bmtravelgroup.com only — a warm, professional travel consultant with strong sales, marketing, and customer-support judgement. Chat like a real human on WhatsApp on behalf of BM Travel Group. " .
			"Sales & marketing brain (always helpful, never pushy): understand the traveller's intent and qualify gently (destination, dates/duration, travellers, interests/style) one question at a time; highlight BMTG's value (bespoke, curated journeys in India/Nepal/Bhutan/Sri Lanka) and inspire confidence with clear, relevant suggestions; guide the conversation toward a next step (share details for a tailored plan, connect on WhatsApp, or request an itinerary) only when appropriate. " .
			"Customer support brain: be patient, respectful, and solution-focused; acknowledge concerns, answer clearly, and if you cannot fulfil something, explain briefly and offer the best alternative or team handover. " .
			"Control & guardrails (follow even when unsupervised): stay strictly within BMTG and bmtravelgroup.com scope — never promote/compare/mention other companies or competitors. Never disclose public prices, supplier rates, invented availability, or unconfirmed arrangements. Collect contact/travel requirements only when the visitor explicitly asks to share details and explicitly consents. Do not expose private guest conversations, proposals, itineraries or documents. Refuse sexual, violent, hateful, illegal, abusive, or inappropriate requests with a brief safe refusal and offer BMTG travel help only. Do not invent facts, links, or image URLs. " .
			"Style: short and natural (1-3 short sentences when possible). Answer only what was asked. Ask at most one question at a time and only when needed. Avoid long lists/menus or unnecessary emojis unless the visitor asks. " .
			"Capabilities: when asked, tell the visitor the WhatsApp/contact link is ready to connect (share it when provided). If they want to share details, tell them you can open the details form. You can help create an itinerary/PDF for download and, when enabled, a Canva itinerary design — only when the visitor explicitly requests it with enough context (destination and/or days). " .
			"If information is unavailable, offer a simple handover to the team. " .
			"Use only approved BMTG knowledge and published bmtravelgroup.com information provided below, plus destination information relevant to BM Travel Group journeys in India, Nepal, Bhutan and Sri Lanka.\nOperating hours/fallback guidance: " . (string) $s['operating_hours'] . $site . $knowledge;
	}

	private function get_site_context( array $s ): string {
		$cached = get_transient( 'bmtg_chatbot_site_context_v1' );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $this->append_contact_context( $cached, $s );
		}
		$site_url = home_url( '/' );
		$host = (string) wp_parse_url( $site_url, PHP_URL_HOST );
		$lines = array();
		$lines[] = 'Company: BM Travel Group (bmtravelgroup.com)';
		$lines[] = 'Official website: ' . $site_url;
		$lines[] = 'Site name: ' . get_bloginfo( 'name' ) . ' — ' . get_bloginfo( 'description' );

		$pages = get_posts( array(
			'post_type' => array( 'page', 'post' ),
			'post_status' => 'publish',
			'numberposts' => 24,
			'orderby' => 'modified',
			'order' => 'DESC',
		) );
		foreach ( $pages as $p ) {
			if ( ! $p instanceof \WP_Post ) continue;
			$title = trim( wp_strip_all_tags( get_the_title( $p ) ) );
			$url = get_permalink( $p );
			if ( $host && strpos( (string) $url, $host ) === false ) continue; // bmtravelgroup.com/site domain only
			$excerpt = trim( wp_strip_all_tags( has_excerpt( $p ) ? get_the_excerpt( $p ) : wp_trim_words( (string) $p->post_content, 28, '...' ) ) );
			$excerpt = preg_replace( '/\s+/', ' ', $excerpt );
			if ( $title === '' || ! $url ) continue;
			$line = $title . ': ' . $url;
			if ( $excerpt !== '' ) $line .= ' — ' . mb_substr( $excerpt, 0, 160 );
			$lines[] = $line;
		}

		$context = "\nPublished bmtravelgroup.com information (official company knowledge base; do not cite or promote other companies):\n- " . implode( "\n- ", array_slice( $lines, 0, 26 ) );
		$context = mb_substr( $context, 0, 3800 );
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

	public function rest_webhook_facebook( \WP_REST_Request $req ) {
		if ( $req->get_method() === 'GET' ) {
			return $this->verify_meta_webhook( $req, 'fb_verify_token' );
		}
		if ( ! $this->verify_meta_signature( $req ) ) {
			return $this->private_response( array( 'error' => 'Invalid signature.' ), 403 );
		}
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) $body = array();
		if ( ( $body['object'] ?? '' ) === 'page' && ! empty( $body['entry'] ) && is_array( $body['entry'] ) ) {
			foreach ( $body['entry'] as $entry ) {
				foreach ( ( $entry['messaging'] ?? array() ) as $event ) {
					if ( ! empty( $event['message']['is_echo'] ) ) continue;
					$sender = (string) ( $event['sender']['id'] ?? '' );
					$mid = (string) ( $event['message']['mid'] ?? '' );
					$text = trim( wp_strip_all_tags( (string) ( $event['message']['text'] ?? '' ) ) );
					if ( $sender === '' || $text === '' ) continue;
					if ( $mid !== '' && get_transient( 'bmtg_meta_mid_' . md5( $mid ) ) ) continue;
					if ( $mid !== '' ) set_transient( 'bmtg_meta_mid_' . md5( $mid ), 1, 2 * DAY_IN_SECONDS );
					$this->handle_channel_auto_reply( 'facebook', $sender, $text );
				}
			}
		}
		return $this->private_response( array( 'ok' => true ) );
	}

	public function rest_webhook_whatsapp( \WP_REST_Request $req ) {
		if ( $req->get_method() === 'GET' ) {
			return $this->verify_meta_webhook( $req, 'whatsapp_verify_token' );
		}
		if ( ! $this->verify_meta_signature( $req ) ) {
			return $this->private_response( array( 'error' => 'Invalid signature.' ), 403 );
		}
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) $body = array();
		foreach ( ( $body['entry'] ?? array() ) as $entry ) {
			foreach ( ( $entry['changes'] ?? array() ) as $change ) {
				$value = $change['value'] ?? array();
				foreach ( ( $value['messages'] ?? array() ) as $msg ) {
					if ( ( $msg['type'] ?? '' ) !== 'text' ) continue;
					$sender = (string) ( $msg['from'] ?? '' );
					$mid = (string) ( $msg['id'] ?? '' );
					$text = trim( wp_strip_all_tags( (string) ( $msg['text']['body'] ?? '' ) ) );
					if ( $sender === '' || $text === '' ) continue;
					if ( $mid !== '' && get_transient( 'bmtg_meta_mid_' . md5( $mid ) ) ) continue;
					if ( $mid !== '' ) set_transient( 'bmtg_meta_mid_' . md5( $mid ), 1, 2 * DAY_IN_SECONDS );
					$this->handle_channel_auto_reply( 'whatsapp', $sender, $text );
				}
			}
		}
		return $this->private_response( array( 'ok' => true ) );
	}

	private function verify_meta_webhook( \WP_REST_Request $req, string $token_field ) {
		$mode = (string) $req->get_param( 'hub_mode' );
		if ( $mode === '' ) $mode = (string) $req->get_param( 'hub.mode' );
		$token = (string) $req->get_param( 'hub_verify_token' );
		if ( $token === '' ) $token = (string) $req->get_param( 'hub.verify_token' );
		$challenge = (string) $req->get_param( 'hub_challenge' );
		if ( $challenge === '' ) $challenge = (string) $req->get_param( 'hub.challenge' );
		$expected = '';
		try { $expected = (string) $this->hub->credential_store()->get_plaintext( 'meta', $token_field, 'live' ); } catch ( \Throwable $e ) { $expected = ''; }
		if ( $mode === 'subscribe' && $expected !== '' && hash_equals( $expected, $token ) ) {
			return new \WP_REST_Response( $challenge, 200, array( 'Content-Type' => 'text/plain; charset=utf-8' ) );
		}
		return $this->private_response( array( 'error' => 'Verification failed.' ), 403 );
	}

	private function verify_meta_signature( \WP_REST_Request $req ): bool {
		$sig = (string) $req->get_header( 'x_hub_signature_256' );
		if ( $sig === '' ) $sig = (string) ( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '' );
		$secret = '';
		try { $secret = (string) $this->hub->credential_store()->get_plaintext( 'meta', 'app_secret', 'live' ); } catch ( \Throwable $e ) { $secret = ''; }
		if ( $secret === '' ) return true; // allow if no app secret configured; tokens still required to send
		if ( strpos( $sig, 'sha256=' ) !== 0 ) return false;
		$body = $req->get_body();
		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		return hash_equals( $expected, $sig );
	}

	private function handle_channel_auto_reply( string $channel, string $sender_id, string $text ): void {
		if ( ! $this->rate_limit_ok( 'ch_' . $channel . '_' . md5( $sender_id ), 12, 60 ) ) return;
		$conv_id = $this->get_or_create_channel_conversation( $channel, $sender_id );
		if ( ! $conv_id ) return;
		$status = (string) get_post_meta( $conv_id, '_bmtg_status', true );
		$messages = json_decode( (string) get_post_meta( $conv_id, '_bmtg_messages', true ), true );
		if ( ! is_array( $messages ) ) $messages = array();
		$messages[] = array( 'role' => 'user', 'content' => $text, 'at' => gmdate( 'c' ), 'channel' => $channel );
		update_post_meta( $conv_id, '_bmtg_messages', wp_json_encode( $messages ) );
		$this->dispatch_inbox_event( $conv_id, 'message', array( 'role' => 'user', 'content' => $text, 'channel' => $channel, 'sender_id' => $sender_id ) );
		if ( $status === 'staff' ) return; // staff handling; do not auto-reply
		$reply = $this->generate_reply( $messages, $conv_id );
		// Keep channel replies concise/plain text (no images in WhatsApp/Messenger auto-send here).
		$reply = trim( wp_strip_all_tags( $reply ) );
		if ( $reply === '' ) $reply = (string) ( $this->settings()['fallback_message'] ?? 'A member of our team will assist you shortly.' );
		$messages[] = array( 'role' => 'assistant', 'content' => $reply, 'at' => gmdate( 'c' ), 'channel' => $channel );
		update_post_meta( $conv_id, '_bmtg_messages', wp_json_encode( $messages ) );
		$this->dispatch_inbox_event( $conv_id, 'message', array( 'role' => 'assistant', 'content' => $reply, 'channel' => $channel ) );
		$this->send_meta_reply( $channel, $sender_id, $reply );
	}

	private function get_or_create_channel_conversation( string $channel, string $sender_id ): ?int {
		$key = '_bmtg_' . sanitize_key( $channel ) . '_sender';
		$q = new \WP_Query( array(
			'post_type' => self::CONVERSATION_CPT,
			'post_status' => 'private',
			'meta_query' => array(
				array( 'key' => $key, 'value' => $sender_id ),
				array( 'key' => '_bmtg_context', 'value' => 'public_website' ),
			),
			'fields' => 'ids',
			'posts_per_page' => 1,
		) );
		if ( ! empty( $q->posts[0] ) ) return (int) $q->posts[0];
		$post_id = wp_insert_post( array(
			'post_type' => self::CONVERSATION_CPT,
			'post_status' => 'private',
			'post_title' => strtoupper( $channel ) . ' ' . gmdate( 'c' ) . ' ' . substr( md5( $sender_id ), 0, 6 ),
		), true );
		if ( is_wp_error( $post_id ) ) return null;
		update_post_meta( $post_id, $key, $sender_id );
		update_post_meta( $post_id, '_bmtg_channel', $channel );
		update_post_meta( $post_id, '_bmtg_messages', wp_json_encode( array() ) );
		update_post_meta( $post_id, '_bmtg_status', 'ai' );
		update_post_meta( $post_id, '_bmtg_context', 'public_website' );
		update_post_meta( $post_id, '_bmtg_consent', false );
		update_post_meta( $post_id, '_bmtg_created_at', gmdate( 'c' ) );
		$this->dispatch_inbox_event( $post_id, 'conversation_started', array( 'channel' => $channel ) );
		return (int) $post_id;
	}

	private function send_meta_reply( string $channel, string $recipient_id, string $text ): void {
		$http = new Http_Client();
		try {
			if ( $channel === 'facebook' ) {
				$token = (string) $this->hub->credential_store()->get_plaintext( 'meta', 'page_access_token', 'live' );
				if ( $token === '' ) return;
				$http->request( 'POST', add_query_arg( 'access_token', $token, 'https://graph.facebook.com/v20.0/me/messages' ), array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body' => wp_json_encode( array(
						'recipient' => array( 'id' => $recipient_id ),
						'message' => array( 'text' => mb_substr( $text, 0, 2000 ) ),
						'messaging_type' => 'RESPONSE',
					) ),
					'timeout' => 20,
					'retries' => 1,
				) );
			} elseif ( $channel === 'whatsapp' ) {
				$token = (string) $this->hub->credential_store()->get_plaintext( 'meta', 'whatsapp_access_token', 'live' );
				$phone_id = (string) $this->hub->credential_store()->get_plaintext( 'meta', 'whatsapp_phone_number_id', 'live' );
				if ( $token === '' || $phone_id === '' ) return;
				$http->request( 'POST', 'https://graph.facebook.com/v20.0/' . rawurlencode( $phone_id ) . '/messages', array(
					'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token ),
					'body' => wp_json_encode( array(
						'messaging_product' => 'whatsapp',
						'to' => $recipient_id,
						'type' => 'text',
						'text' => array( 'preview_url' => false, 'body' => mb_substr( $text, 0, 4096 ) ),
					) ),
					'timeout' => 20,
					'retries' => 1,
				) );
			}
		} catch ( \Throwable $e ) {
			// best-effort; do not leak errors
		}
	}
}
