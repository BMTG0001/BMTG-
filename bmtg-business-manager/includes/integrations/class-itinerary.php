<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Itinerary {
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_bmtg_itinerary_pdf', array( __CLASS__, 'render_pdf' ) );
		add_action( 'admin_post_nopriv_bmtg_itinerary_pdf', array( __CLASS__, 'render_pdf_public' ) );
	}

	public static function routes(): void {
		register_rest_route( 'bmtg/v1/itinerary', '/draft', array(
			'methods' => 'POST', 'permission_callback' => function(){ return current_user_can( \BMTG\CRM\Capabilities::MANAGE_LEADS ) || current_user_can('manage_options'); },
			'callback' => array( __CLASS__, 'draft' ),
		) );
		register_rest_route( 'bmtg/v1/itinerary', '/canva', array(
			'methods' => 'POST', 'permission_callback' => function(){ return current_user_can( \BMTG\CRM\Capabilities::MANAGE_LEADS ) || current_user_can('manage_options'); },
			'callback' => array( __CLASS__, 'canva_create' ),
		) );
		register_rest_route( 'bmtg/v1/itinerary', '/canva/templates', array(
			'methods' => 'GET', 'permission_callback' => function(){ return current_user_can( \BMTG\CRM\Capabilities::MANAGE_LEADS ) || current_user_can('manage_options'); },
			'callback' => array( __CLASS__, 'canva_templates' ),
		) );
	}

	public static function draft( \WP_REST_Request $req ): \WP_REST_Response {
		$data = $req->get_json_params(); if(!is_array($data)) $data = $req->get_params();
		$title = sanitize_text_field( $data['title'] ?? 'Bespoke Journey' );
		$dest = sanitize_text_field( $data['destinations'] ?? 'India, Nepal, Bhutan, Sri Lanka' );
		$days = max(1, min(30, (int)($data['days'] ?? 7)));
		$notes = sanitize_textarea_field( $data['notes'] ?? '' );
		$text = self::generate_ai_draft( $title, $dest, $days, $notes );
		$html = self::render_html($title, $dest, $text);
		$url = add_query_arg(array('action'=>'bmtg_itinerary_pdf','_wpnonce'=>wp_create_nonce('bmtg_itinerary_pdf'),'title'=>rawurlencode($title),'dest'=>rawurlencode($dest),'body'=>rawurlencode(wp_strip_all_tags($text))), admin_url('admin-post.php'));
		return new \WP_REST_Response(array('title'=>$title,'destinations'=>$dest,'body'=>$text,'html'=>$html,'pdf_url'=>$url), 200);
	}

	private static function generate_ai_draft( string $title, string $dest, int $days, string $notes ): string {
		$prompt = "Create a luxury, day-by-day travel itinerary draft for BM Travel Group. Title: {$title}. Destinations: {$dest}. Days: {$days}. Traveller notes/request: {$notes}. " .
			"Rules: no public prices, no supplier rates, no invented or unconfirmed availability. Elegant, warm, professional tone. Clear day headings (Day 1, Day 2 ...). " .
			"Keep it practical and inspiring for the destinations named. End with a short note inviting the traveller to connect with the BM Travel Group team to personalise and confirm details.";
		$text = '';
		// Groq primary, OpenRouter fallback (same providers as chatbot)
		try {
			$client = Manager::client('groq','live');
			$settings = get_option('bmtg_chatbot_settings', array());
			$model = ! empty($settings['model_groq']) ? (string)$settings['model_groq'] : 'llama-3.3-70b-versatile';
			$res = $client->chat_completion(array('model'=>$model,'messages'=>array(array('role'=>'user','content'=>$prompt)),'temperature'=>0.5));
			$text = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
		} catch (\Throwable $e) { $text = ''; }
		if ($text === '') {
			try {
				$client = Manager::client('openrouter','live');
				$settings = get_option('bmtg_chatbot_settings', array());
				$model = ! empty($settings['model_openrouter']) ? (string)$settings['model_openrouter'] : 'openai/gpt-4o-mini';
				$res = $client->openrouter_chat_completion(array('model'=>$model,'messages'=>array(array('role'=>'user','content'=>$prompt)),'temperature'=>0.5));
				$text = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
			} catch (\Throwable $e) { $text = ''; }
		}
		if ($text === '') {
			$text = "Day 1: Arrival in {$dest}\nPrivate welcome and time to settle in.\n\nDay 2: Highlights of {$dest}\nA curated day exploring signature experiences at a comfortable pace.\n\nDay 3 onward: Bespoke experiences\nFurther days tailored to your interests, travel style, and preferred pace.\n\nOur team will be happy to personalise this journey and confirm all details for you.";
		}
		return $text;
	}

	public static function generate_chat_pdf( string $request_text, int $conversation_id = 0 ): array {
		$days = 5;
		if ( preg_match('/\b(\d{1,2})\s*(day|days|night|nights)\b/i', $request_text, $m) ) {
			$d = (int) $m[1]; if ($d >= 1 && $d <= 30) $days = $d;
		}
		$dest = 'India, Nepal, Bhutan, Sri Lanka';
		$known = array('India','Nepal','Bhutan','Sri Lanka','Rajasthan','Kerala','Goa','Himalaya','Ladakh','Kashmir','Varanasi','Delhi','Jaipur','Mumbai','Kolkata','Kathmandu','Pokhara','Colombo','Kandy','Galle','Sigiriya','Thimphu','Paro','Punakha','Taj Mahal','Agra');
		$found = array();
		foreach ($known as $k) { if (stripos($request_text, $k) !== false) $found[] = $k; }
		if (!empty($found)) $dest = implode(', ', array_unique($found));
		$title = 'BM Travel Group — Bespoke ' . $days . '-Day Journey';
		if (!empty($found)) $title = 'BM Travel Group — ' . $days . '-Day Journey: ' . $found[0];
		$notes = trim(wp_strip_all_tags($request_text));
		$notes = mb_substr($notes, 0, 800);
		$text = self::generate_ai_draft($title, $dest, $days, $notes);
		$doc = self::store_pdf_doc($title, $dest, $text, $conversation_id);
		if (!$doc) return array('ok'=>false);
		$url = add_query_arg(array('action'=>'bmtg_itinerary_pdf','doc'=>$doc['token']), admin_url('admin-post.php'));
		return array('ok'=>true, 'pdf_url'=>$url, 'title'=>$title);
	}

	private static function store_pdf_doc( string $title, string $dest, string $body, int $conversation_id ): ?array {
		$token = wp_generate_password(24, false, false);
		$key = 'bmtg_itpdf_' . $token;
		$payload = array(
			'title' => $title,
			'dest'  => $dest,
			'body'  => mb_substr($body, 0, 12000),
			'cid'   => $conversation_id,
			'at'    => time(),
		);
		$ok = set_transient($key, $payload, 7 * DAY_IN_SECONDS);
		if (!$ok) return null;
		return array('token'=>$token,'key'=>$key);
	}

	public static function render_pdf(): void {
		if ( ! current_user_can('manage_options') && ! current_user_can(\BMTG\CRM\Capabilities::MANAGE_LEADS) ) wp_die('Forbidden');
		check_admin_referer('bmtg_itinerary_pdf');
		$title = sanitize_text_field( wp_unslash($_GET['title'] ?? 'Itinerary') );
		$dest = sanitize_text_field( wp_unslash($_GET['dest'] ?? '') );
		$body = sanitize_textarea_field( wp_unslash($_GET['body'] ?? '') );
		$html = self::render_html($title,$dest,$body);
		self::output_html_pdf($html, $title);
	}

	public static function render_pdf_public(): void {
		$token = isset($_GET['doc']) ? sanitize_text_field((string) $_GET['doc']) : '';
		if ($token === '' || !preg_match('/^[A-Za-z0-9]+$/', $token)) { wp_die('Invalid itinerary link.', '', array('response'=>400)); }
		$data = get_transient('bmtg_itpdf_' . $token);
		if (!is_array($data)) { wp_die('This itinerary link has expired. Please ask in chat to create it again.', '', array('response'=>404)); }
		$title = sanitize_text_field((string)($data['title'] ?? 'Itinerary'));
		$dest = sanitize_text_field((string)($data['dest'] ?? ''));
		$body = sanitize_textarea_field((string)($data['body'] ?? ''));
		$html = self::render_html($title,$dest,$body);
		self::output_html_pdf($html, $title);
	}

	private static function output_html_pdf( string $html, string $title ): void {
		$safe = sanitize_file_name($title ?: 'BMTG-Itinerary');
		nocache_headers();
		header('Content-Type: text/html; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		// Printable/downloadable: page opens and user can Save as PDF / Print. This works without server PDF libs.
		echo $html . '<script>window.addEventListener("load",function(){try{window.print()}catch(e){}});</script>';
		exit;
	}

	public static function canva_templates( \WP_REST_Request $req ): \WP_REST_Response {
		$query = sanitize_text_field( (string) $req->get_param('query') );
		try {
			$client = Manager::client('canva','live');
			$res = $client->canva_list_brand_templates( $query, 20 );
			if ( ($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300 ) {
				$items = $res['body']['items'] ?? array();
				$out = array();
				foreach ( $items as $it ) {
					if ( ! is_array($it) ) continue;
					$out[] = array(
						'id' => $it['id'] ?? '',
						'title' => $it['title'] ?? ($it['name'] ?? ''),
						'thumbnail' => $it['thumbnail']['url'] ?? ($it['thumbnail_url'] ?? ''),
						'view_url' => $it['view_url'] ?? '',
						'create_url' => $it['create_url'] ?? '',
					);
				}
				return new \WP_REST_Response( array( 'ok' => true, 'templates' => $out, 'continuation' => $res['body']['continuation'] ?? null ), 200 );
			}
			$err = $res['error'] ?? ($res['body']['message'] ?? 'Unable to fetch templates');
			return new \WP_REST_Response( array( 'ok' => false, 'error' => is_string($err)?$err:'Unable to fetch templates', 'status' => $res['status'] ?? 0 ), 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response(array('ok'=>false,'error'=>$e->getMessage()), 200);
		}
	}

	public static function canva_create( \WP_REST_Request $req ): \WP_REST_Response {
		$data = $req->get_json_params(); if(!is_array($data)) $data = $req->get_params();
		$title = sanitize_text_field( $data['title'] ?? 'BMTG Itinerary' );
		$template_id = sanitize_text_field( $data['template_id'] ?? '' );
		$result = self::create_canva_itinerary( $title, sanitize_textarea_field( $data['notes'] ?? '' ), $template_id );
		return new \WP_REST_Response( $result, 200 );
	}

	public static function create_canva_itinerary( string $title, string $notes = '', string $template_id = '' ): array {
		$s = get_option('bmtg_chatbot_settings', array());
		if ( $template_id === '' ) $template_id = (string) ($s['canva_itinerary_template_id'] ?? '');
		$design_type = sanitize_text_field( $s['canva_itinerary_design_type'] ?? 'doc' ) ?: 'doc';
		try {
			$client = Manager::client('canva','live');
			// Try brand template autofill first if template provided (best for "from template")
			if ( $template_id !== '' ) {
				$res = $client->canva_create_autofill( $template_id, array(), $title );
				$body = $res['body'] ?? array();
				$job = $body['job'] ?? $body;
				if ( ($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300 ) {
					$design = $job['result']['design'] ?? ($job['design'] ?? null);
					$url = $design['urls']['edit_url'] ?? ($design['urls']['view_url'] ?? ($job['url'] ?? ($body['url'] ?? '')));
					if ( $url ) {
						return array( 'ok' => true, 'url' => $url, 'mode' => 'template_autofill', 'result' => $body );
					}
					$job_id = $job['id'] ?? null;
					if ( $job_id ) {
						return array( 'ok' => true, 'job_id' => $job_id, 'mode' => 'template_autofill_pending', 'result' => $body, 'notice' => 'Canva itinerary design is being created from the template.' );
					}
				}
				// fall through to blank design
			}
			$res = $client->create_design(array('design_type'=>array('type'=>'preset','name'=>$design_type), 'title'=>$title));
			$body = $res['body'] ?? array();
			if ( ($res['status'] ?? 0) >= 200 && ($res['status'] ?? 0) < 300 ) {
				$design = $body['design'] ?? $body;
				$url = $design['urls']['edit_url'] ?? ($design['urls']['view_url'] ?? ($design['url'] ?? ''));
				return array( 'ok' => true, 'url' => $url, 'mode' => $template_id ? 'fallback_design' : 'design', 'result' => $body );
			}
			$err = $res['error'] ?? ($body['message'] ?? ($body['error']['message'] ?? 'Canva request failed'));
			return array( 'ok' => false, 'error' => is_string($err)?$err:'Canva request failed', 'status' => $res['status'] ?? 0 );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}
	}

	public static function render_html( string $title, string $dest, string $body ): string {
		$s = get_option('bmtg_chatbot_settings', array());
		$biz = esc_html($s['business_name'] ?? 'BM Travel Group');
		$addr = nl2br(esc_html($s['business_address'] ?? ''));
		$logo = esc_url($s['logo_url'] ?? '');
		$body_html = nl2br(esc_html($body));
		$logo_html = $logo ? '<img src="'.$logo.'" alt="'. $biz .'" style="max-height:60px">' : '<div style="font-size:22px;letter-spacing:.5px">'. $biz .'</div>';
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($title).'</title></head><body style="font-family:Georgia,serif;color:#1f2937;margin:40px;background:#fffdf8"><div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #e8e2d6;padding-bottom:16px">'.$logo_html.'<div style="text-align:right;font-size:12px;color:#6b7280"><div>'. $biz .'</div><div>'.$addr.'</div></div></div><h1 style="margin:24px 0 4px">'.esc_html($title).'</h1><div style="color:#6b7280;margin-bottom:18px">'.esc_html($dest).'</div><div style="line-height:1.7">'.$body_html.'</div><div style="margin-top:28px;font-size:12px;color:#6b7280;border-top:1px solid #e8e2d6;padding-top:10px">Indicative itinerary for discussion. No public prices or unconfirmed arrangements. Please contact BM Travel Group to personalise and confirm your journey.</div></body></html>';
	}
}
