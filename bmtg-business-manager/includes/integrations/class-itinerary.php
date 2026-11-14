<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Itinerary {
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_bmtg_itinerary_pdf', array( __CLASS__, 'render_pdf' ) );
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
		$prompt = "Create a luxury, day-by-day travel itinerary draft for BM Travel Group. Title: {$title}. Destinations: {$dest}. Days: {$days}. Notes: {$notes}. No prices, no supplier rates, no unconfirmed availability. Elegant tone, clear day headings.";
		$text = '';
		try {
			$client = Manager::client('groq','live');
			$res = $client->chat_completion(array('model'=>'llama-3.3-70b-versatile','messages'=>array(array('role'=>'user','content'=>$prompt)),'temperature'=>0.5));
			$text = (string)($res['body']['choices'][0]['message']['content'] ?? '');
		} catch (\Throwable $e) { $text = ''; }
		if ($text === '') { $text = "Day 1: Arrival and private welcome in {$dest}.\n\n(Connect Groq/OpenRouter in Integrations to generate full AI draft.)"; }
		$html = self::render_html($title, $dest, $text);
		$url = add_query_arg(array('action'=>'bmtg_itinerary_pdf','_wpnonce'=>wp_create_nonce('bmtg_itinerary_pdf'),'title'=>rawurlencode($title),'dest'=>rawurlencode($dest),'body'=>rawurlencode(wp_strip_all_tags($text))), admin_url('admin-post.php'));
		return new \WP_REST_Response(array('title'=>$title,'destinations'=>$dest,'body'=>$text,'html'=>$html,'pdf_url'=>$url), 200);
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
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $res['error'] ?? 'Unable to fetch templates', 'status' => $res['status'] ?? 0 ), 200 );
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
					// job may be in progress; return job info
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
			return array( 'ok' => false, 'error' => $res['error'] ?? ($body['message'] ?? 'Canva request failed'), 'status' => $res['status'] ?? 0 );
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
		return '<!doctype html><html><head><meta charset="utf-8"><title>'.esc_html($title).'</title></head><body style="font-family:Georgia,serif;color:#1f2937;margin:40px;background:#fffdf8"><div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #e8e2d6;padding-bottom:16px">'.$logo_html.'<div style="text-align:right;font-size:12px;color:#6b7280"><div>'. $biz .'</div><div>'.$addr.'</div></div></div><h1 style="margin:24px 0 4px">'.esc_html($title).'</h1><div style="color:#6b7280;margin-bottom:18px">'.esc_html($dest).'</div><div style="line-height:1.7">'.$body_html.'</div><div style="margin-top:28px;font-size:12px;color:#6b7280;border-top:1px solid #e8e2d6;padding-top:10px">Indicative itinerary. No public prices or unconfirmed arrangements.</div></body></html>';
	}

	public static function render_pdf(): void {
		if ( ! current_user_can('manage_options') && ! current_user_can(\BMTG\CRM\Capabilities::MANAGE_LEADS) ) wp_die('Forbidden');
		check_admin_referer('bmtg_itinerary_pdf');
		$title = sanitize_text_field( wp_unslash($_GET['title'] ?? 'Itinerary') );
		$dest = sanitize_text_field( wp_unslash($_GET['dest'] ?? '') );
		$body = sanitize_textarea_field( wp_unslash($_GET['body'] ?? '') );
		$html = self::render_html($title,$dest,$body);
		header('Content-Type: text/html; charset=utf-8');
		echo $html . '<script>window.print()</script>';
		exit;
	}
}
