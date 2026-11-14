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
		add_action( 'admin_post_bmtg_itinerary_doc', array( __CLASS__, 'download_doc' ) );
		add_action( 'admin_post_bmtg_itinerary_send_client', array( __CLASS__, 'send_to_client' ) );
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
		$structured = self::generate_structured_draft( $title, $dest, $days, $notes );
		$images = self::fetch_related_images( $structured['destinations'] ?? $dest, 6 );
		$html = self::render_html( $structured, $images );
		$url = add_query_arg(array('action'=>'bmtg_itinerary_pdf','_wpnonce'=>wp_create_nonce('bmtg_itinerary_pdf'),'title'=>rawurlencode($structured['title'] ?? $title),'dest'=>rawurlencode($structured['destinations'] ?? $dest),'body'=>rawurlencode(wp_json_encode($structured))), admin_url('admin-post.php'));
		return new \WP_REST_Response(array('title'=>$structured['title'] ?? $title,'destinations'=>$structured['destinations'] ?? $dest,'body'=>self::structured_to_text($structured),'html'=>$html,'pdf_url'=>$url), 200);
	}

	public static function generate_chat_pdf( string $request_text, int $conversation_id = 0 ): array {
		$days = 5;
		if ( preg_match('/\b(\d{1,2})\s*(day|days|night|nights)\b/i', $request_text, $m) ) {
			$d = (int) $m[1]; if ($d >= 1 && $d <= 30) $days = $d;
		}
		$dest = 'India, Nepal, Bhutan, Sri Lanka';
		$known = array('India','Nepal','Bhutan','Sri Lanka','Rajasthan','Kerala','Goa','Himalaya','Himalayas','Ladakh','Kashmir','Varanasi','Delhi','Jaipur','Mumbai','Kolkata','Kathmandu','Pokhara','Colombo','Kandy','Galle','Sigiriya','Thimphu','Paro','Punakha','Taj Mahal','Agra','Chitwan','Lumbini','Phobjikha','Dochula');
		$found = array();
		foreach ($known as $k) { if (stripos($request_text, $k) !== false) $found[] = $k; }
		if (!empty($found)) $dest = implode(', ', array_unique($found));
		$title = 'BM Travel Group — Bespoke ' . $days . '-Day Journey';
		if (!empty($found)) $title = 'BM Travel Group — ' . $days . '-Day Journey: ' . $found[0];
		$notes = trim(wp_strip_all_tags($request_text));
		$notes = mb_substr($notes, 0, 1200);
		$structured = self::generate_structured_draft($title, $dest, $days, $notes);
		$images = self::fetch_related_images($structured['destinations'] ?? $dest, 6);
		$doc = self::store_pdf_doc($structured, $images, $conversation_id);
		if (!$doc) return array('ok'=>false);
		$url = add_query_arg(array('action'=>'bmtg_itinerary_pdf','doc'=>$doc['token']), admin_url('admin-post.php'));
		$doc_url = add_query_arg(array('action'=>'bmtg_itinerary_doc','doc'=>$doc['token']), admin_url('admin-post.php'));
		return array('ok'=>true, 'pdf_url'=>$url, 'doc_url'=>$doc_url, 'doc_token'=>$doc['token'], 'title'=>$structured['title'] ?? $title, 'structured'=>$structured);
	}

	private static function generate_structured_draft( string $title, string $dest, int $days, string $notes ): array {
		$prompt = "You are a senior travel designer for BM Travel Group (bmtravelgroup.com), a luxury bespoke travel company for India, Nepal, Bhutan, and Sri Lanka. " .
			"Create a polished, accurate, professionally proofread itinerary in a style similar to premium tour documents. " .
			"Return ONLY a valid JSON object (no markdown fences, no commentary) with this schema: " .
			"{\"title\":string,\"subtitle\":string,\"tagline\":\"Curated. Private. Exceptional.\",\"destinations\":string,\"overview\":string,\"facts\":[{\"label\":string,\"value\":string}],\"days\":[{\"day\":int,\"title\":string,\"body\":string}],\"closing\":string,\"notes\":string}. " .
			"Requirements: Title should be elegant (include destination and duration if relevant). Subtitle can be a short route/places line. Overview is 1-3 short paragraphs introducing the destination/journey warmly and factually. " .
			"Facts are 5-10 useful destination facts (e.g. land area, culture, highlights) when relevant; omit if not appropriate. Days must include clear day-by-day plan for {$days} days with concise, inspiring, practical descriptions (Day 1 arrival etc). " .
			"Closing is a short warm invitation to connect with BM Travel Group to personalise and confirm. Notes can include closing days/tips if relevant, else empty string. " .
			"Tone: elegant, warm, professional. Proofread carefully: correct spelling, grammar, punctuation, and consistent formatting. " .
			"STRICT: no public prices, no supplier rates, no invented or unconfirmed availability. Only include information that is reasonable and accurate for the destination; if unsure, keep it general. " .
			"Context - Destinations: {$dest}. Requested days: {$days}. Traveller request/notes: {$notes}. Suggested title: {$title}.";
		$text = self::ai_complete($prompt, 0.4);
		$parsed = self::parse_structured($text, $title, $dest, $days, $notes);
		return self::proofread_structured($parsed);
	}

	private static function ai_complete( string $prompt, float $temp = 0.4 ): string {
		$text = '';
		try {
			$client = Manager::client('groq','live');
			$settings = get_option('bmtg_chatbot_settings', array());
			$model = ! empty($settings['model_groq']) ? (string)$settings['model_groq'] : 'llama-3.3-70b-versatile';
			$res = $client->chat_completion(array('model'=>$model,'messages'=>array(array('role'=>'user','content'=>$prompt)),'temperature'=>$temp));
			$text = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
		} catch (\Throwable $e) { $text = ''; }
		if ($text === '') {
			try {
				$client = Manager::client('openrouter','live');
				$settings = get_option('bmtg_chatbot_settings', array());
				$model = ! empty($settings['model_openrouter']) ? (string)$settings['model_openrouter'] : 'openai/gpt-4o-mini';
				$res = $client->openrouter_chat_completion(array('model'=>$model,'messages'=>array(array('role'=>'user','content'=>$prompt)),'temperature'=>$temp));
				$text = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
			} catch (\Throwable $e) { $text = ''; }
		}
		return $text;
	}

	private static function parse_structured( string $text, string $title, string $dest, int $days, string $notes ): array {
		$base = array(
			'title' => $title,
			'subtitle' => $dest,
			'tagline' => 'Curated. Private. Exceptional.',
			'destinations' => $dest,
			'overview' => '',
			'facts' => array(),
			'days' => array(),
			'closing' => 'We would be delighted to personalise this journey for you. Please connect with the BM Travel Group team to refine and confirm the details.',
			'notes' => '',
		);
		if ($text !== '') {
			$json = $text;
			if (preg_match('/\{[\s\S]*\}/', $text, $m)) $json = $m[0];
			$data = json_decode($json, true);
			if (is_array($data)) {
				foreach (array('title','subtitle','tagline','destinations','overview','closing','notes') as $k) {
					if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') $base[$k] = trim($data[$k]);
				}
				if (!empty($data['facts']) && is_array($data['facts'])) {
					$facts = array();
					foreach ($data['facts'] as $f) {
						if (is_array($f) && !empty($f['label']) && !empty($f['value'])) {
							$facts[] = array('label'=>trim((string)$f['label']), 'value'=>trim((string)$f['value']));
						} elseif (is_string($f) && trim($f) !== '') {
							$facts[] = array('label'=>'', 'value'=>trim($f));
						}
					}
					if ($facts) $base['facts'] = array_slice($facts, 0, 12);
				}
				if (!empty($data['days']) && is_array($data['days'])) {
					$ds = array();
					$i = 1;
					foreach ($data['days'] as $d) {
						if (is_array($d)) {
							$ds[] = array(
								'day' => isset($d['day']) ? (int)$d['day'] : $i,
								'title' => trim((string)($d['title'] ?? ('Day ' . $i))),
								'body' => trim((string)($d['body'] ?? ($d['description'] ?? ''))),
							);
						} elseif (is_string($d) && trim($d) !== '') {
							$ds[] = array('day'=>$i,'title'=>'Day '.$i,'body'=>trim($d));
						}
						$i++;
					}
					if ($ds) $base['days'] = array_slice($ds, 0, 30);
				}
			}
		}
		if (empty($base['days'])) {
			for ($i=1; $i <= $days; $i++) {
				if ($i === 1) $base['days'][] = array('day'=>1,'title'=>'Arrival','body'=>'Warm welcome on arrival in ' . $dest . ' with time to settle in and relax.');
				elseif ($i === $days) $base['days'][] = array('day'=>$i,'title'=>'Departure','body'=>'Leisurely morning before your onward journey, taking with you cherished memories of ' . $dest . '.');
				else $base['days'][] = array('day'=>$i,'title'=>'Discover ' . $dest,'body'=>'A curated day of meaningful experiences, local culture, and unhurried exploration tailored to your interests.');
			}
		}
		if ($base['overview'] === '') {
			$base['overview'] = 'Embark on a thoughtfully curated journey through ' . $dest . ', designed for comfort, authenticity, and memorable experiences. This bespoke itinerary offers a graceful balance of discovery, culture, and time to unwind at your own pace.';
		}
		$base['tagline'] = 'Curated. Private. Exceptional.';
		return $base;
	}

	private static function proofread_structured( array $d ): array {
		$clean = function($s) {
			$s = (string) $s;
			$s = preg_replace('/[ \t]+/', ' ', $s);
			$s = preg_replace('/ *\n */', "\n", $s);
			$s = preg_replace('/\n{3,}/', "\n\n", $s);
			$s = trim($s);
			// remove accidental markdown fences/spurious artifacts
			$s = preg_replace('/^```[a-zA-Z]*\s*|```$/m', '', $s);
			return trim($s);
		};
		foreach (array('title','subtitle','tagline','destinations','overview','closing','notes') as $k) {
			if (isset($d[$k])) $d[$k] = $clean($d[$k]);
		}
		if (!empty($d['facts']) && is_array($d['facts'])) {
			foreach ($d['facts'] as &$f) { $f['label']=$clean($f['label']??''); $f['value']=$clean($f['value']??''); }
			unset($f);
		}
		if (!empty($d['days']) && is_array($d['days'])) {
			foreach ($d['days'] as &$day) { $day['title']=$clean($day['title']??''); $day['body']=$clean($day['body']??''); $day['day']=(int)($day['day']??0); }
			unset($day);
		}
		return $d;
	}

	private static function structured_to_text( array $d ): string {
		$out = array();
		$out[] = ($d['title'] ?? 'Itinerary');
		if (!empty($d['subtitle'])) $out[] = $d['subtitle'];
		if (!empty($d['overview'])) $out[] = "\n" . $d['overview'];
		if (!empty($d['facts'])) {
			$out[] = "\nFacts:";
			foreach ($d['facts'] as $f) $out[] = '- ' . trim(($f['label']??'') . ($f['label']?' : ':'') . ($f['value']??''));
		}
		if (!empty($d['days'])) {
			foreach ($d['days'] as $day) $out[] = "\nDay " . (int)$day['day'] . ": " . ($day['title']??'') . "\n" . ($day['body']??'');
		}
		if (!empty($d['closing'])) $out[] = "\n" . $d['closing'];
		if (!empty($d['notes'])) $out[] = "\nNotes:\n" . $d['notes'];
		return trim(implode("\n", $out));
	}

	private static function fetch_related_images( string $dest, int $count = 6 ): array {
		$images = array();
		$queries = array();
		$parts = array_map('trim', explode(',', $dest));
		foreach ($parts as $p) { if ($p !== '') $queries[] = $p . ' travel landscape'; }
		if (empty($queries)) $queries[] = $dest . ' travel';
		$queries[] = $dest . ' culture nature';
		try {
			$client = Manager::client('unsplash', 'live');
			$seen = array();
			foreach ($queries as $q) {
				if (count($images) >= $count) break;
				$res = $client->search_photos( $q, 1, 3 );
				$results = $res['body']['results'] ?? array();
				if (!is_array($results)) continue;
				foreach ($results as $p) {
					if (count($images) >= $count) break;
					$url = $p['urls']['regular'] ?? ($p['urls']['small'] ?? null);
					if (!$url || isset($seen[$url])) continue;
					$seen[$url] = true;
					$images[] = array(
						'url' => $url,
						'alt' => $p['alt_description'] ?? $dest,
						'credit' => 'Photo: ' . ($p['user']['name'] ?? 'Unsplash') . ' / Unsplash',
					);
				}
			}
		} catch (\Throwable $e) {
			// No Unsplash configured or request failed: render without photos.
		}
		return $images;
	}

	private static function store_pdf_doc( array $structured, array $images, int $conversation_id ): ?array {
		$token = wp_generate_password(24, false, false);
		$key = 'bmtg_itpdf_' . $token;
		$payload = array(
			'v' => 2,
			'data' => $structured,
			'images' => array_slice($images, 0, 8),
			'cid' => $conversation_id,
			'at' => time(),
		);
		$ok = set_transient($key, $payload, 7 * DAY_IN_SECONDS);
		if (!$ok) return null;
		if ( $conversation_id > 0 ) {
			update_post_meta( $conversation_id, '_bmtg_itinerary_token', $token );
			update_post_meta( $conversation_id, '_bmtg_itinerary_at', time() );
		}
		return array('token'=>$token,'key'=>$key);
	}

	public static function get_doc_data( string $token ): ?array {
		if ($token === '' || !preg_match('/^[A-Za-z0-9]+$/', $token)) return null;
		$data = get_transient('bmtg_itpdf_' . $token);
		if (!is_array($data)) return null;
		if (!empty($data['v']) && $data['v'] === 2 && !empty($data['data'])) {
			return array('structured'=>is_array($data['data'])?$data['data']:array(), 'images'=>is_array($data['images']??null)?$data['images']:array(), 'cid'=>(int)($data['cid']??0));
		}
		$structured = array(
			'title' => (string)($data['title'] ?? 'Itinerary'),
			'subtitle' => (string)($data['dest'] ?? ''),
			'tagline' => 'Curated. Private. Exceptional.',
			'destinations' => (string)($data['dest'] ?? ''),
			'overview' => '',
			'facts' => array(),
			'days' => array(array('day'=>1,'title'=>'Itinerary','body'=>(string)($data['body'] ?? ''))),
			'closing' => 'Please contact BM Travel Group to personalise and confirm your journey.',
			'notes' => '',
		);
		return array('structured'=>$structured,'images'=>array(),'cid'=>(int)($data['cid']??0));
	}

	public static function save_doc_data( string $token, array $structured, array $images = array(), int $cid = 0 ): bool {
		if ($token === '' || !preg_match('/^[A-Za-z0-9]+$/', $token)) return false;
		$key = 'bmtg_itpdf_' . $token;
		$existing = get_transient($key);
		if (!is_array($existing)) return false;
		$payload = array('v'=>2,'data'=>$structured,'images'=>array_slice($images,0,8),'cid'=>$cid?: (int)($existing['cid']??0),'at'=>time());
		return (bool) set_transient($key, $payload, 7 * DAY_IN_SECONDS);
	}

	public static function admin_urls_for_token( string $token ): array {
		if ($token === '') return array();
		$base = admin_url('admin-post.php');
		return array(
			'view' => add_query_arg(array('action'=>'bmtg_itinerary_pdf','doc'=>$token), $base),
			'doc'  => add_query_arg(array('action'=>'bmtg_itinerary_doc','doc'=>$token), $base),
			'edit' => add_query_arg(array('page'=>'bmtg-chatbot','bmtg_it_token'=>$token), admin_url('admin.php')),
		);
	}

	public static function render_pdf(): void {
		if ( ! current_user_can('manage_options') && ! current_user_can(\BMTG\CRM\Capabilities::MANAGE_LEADS) ) wp_die('Forbidden');
		check_admin_referer('bmtg_itinerary_pdf');
		$title = sanitize_text_field( wp_unslash($_GET['title'] ?? 'Itinerary') );
		$dest = sanitize_text_field( wp_unslash($_GET['dest'] ?? '') );
		$body = wp_unslash($_GET['body'] ?? '');
		$structured = null;
		$decoded = json_decode((string)$body, true);
		if (is_array($decoded) && !empty($decoded['title'])) $structured = $decoded;
		if (!$structured) {
			$structured = self::parse_structured('', $title, $dest, 5, sanitize_textarea_field((string)$body));
		}
		$html = self::render_html($structured, array());
		self::output_html_pdf($html, $structured['title'] ?? $title);
	}

	public static function render_pdf_public(): void {
		$token = isset($_GET['doc']) ? sanitize_text_field((string) $_GET['doc']) : '';
		if ($token === '' || !preg_match('/^[A-Za-z0-9]+$/', $token)) { wp_die('Invalid itinerary link.', '', array('response'=>400)); }
		$data = get_transient('bmtg_itpdf_' . $token);
		if (!is_array($data)) { wp_die('This itinerary link has expired. Please ask in chat to create it again.', '', array('response'=>404)); }
		if (!empty($data['v']) && $data['v'] === 2 && !empty($data['data'])) {
			$structured = is_array($data['data']) ? $data['data'] : array();
			$images = is_array($data['images'] ?? null) ? $data['images'] : array();
		} else {
			// legacy v1
			$structured = array(
				'title' => (string)($data['title'] ?? 'Itinerary'),
				'subtitle' => (string)($data['dest'] ?? ''),
				'tagline' => 'Curated. Private. Exceptional.',
				'destinations' => (string)($data['dest'] ?? ''),
				'overview' => '',
				'facts' => array(),
				'days' => array(array('day'=>1,'title'=>'Itinerary','body'=>(string)($data['body'] ?? ''))),
				'closing' => 'Please contact BM Travel Group to personalise and confirm your journey.',
				'notes' => '',
			);
			$images = array();
		}
		$html = self::render_html($structured, $images);
		self::output_html_pdf($html, $structured['title'] ?? 'Itinerary');
	}

	private static function output_html_pdf( string $html, string $title ): void {
		nocache_headers();
		header('Content-Type: text/html; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		echo $html;
		exit;
	}

	public static function download_doc(): void {
		if ( ! current_user_can('manage_options') && ! current_user_can(\BMTG\CRM\Capabilities::MANAGE_LEADS) ) wp_die('Forbidden');
		$token = isset($_GET['doc']) ? sanitize_text_field((string) $_GET['doc']) : '';
		if ( $token !== '' ) {
			check_admin_referer('bmtg_itinerary_doc_' . $token);
			$data = self::get_doc_data($token);
			if (!$data) wp_die('This itinerary draft has expired. Please regenerate it from the chat review.');
			$structured = $data['structured'];
			$images = $data['images'];
		} else {
			check_admin_referer('bmtg_itinerary_doc');
			$token = '';
			$structured = self::structured_from_post();
			$images = array();
		}
		$doc = self::render_doc_html($structured, $images);
		$filename = sanitize_file_name(($structured['title'] ?? 'BMTG-Itinerary')) . '.doc';
		nocache_headers();
		header('Content-Type: application/msword; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('X-Content-Type-Options: nosniff');
		echo "\xEF\xBB\xBF" . $doc;
		exit;
	}

	public static function send_to_client(): void {
		if ( ! current_user_can('manage_options') && ! current_user_can(\BMTG\CRM\Capabilities::MANAGE_LEADS) ) wp_die('Forbidden');
		check_admin_referer('bmtg_itinerary_send');
		$token = isset($_POST['doc']) ? sanitize_text_field((string) $_POST['doc']) : '';
		$to = isset($_POST['client_email']) ? sanitize_email((string) $_POST['client_email']) : '';
		$subject = isset($_POST['subject']) ? sanitize_text_field((string) wp_unslash($_POST['subject'])) : '';
		$message = isset($_POST['message']) ? sanitize_textarea_field((string) wp_unslash($_POST['message'])) : '';
		$attach_doc = ! empty($_POST['attach_doc']);
		$back = admin_url('admin.php?page=bmtg-chatbot' . ($token ? '&bmtg_it_token=' . rawurlencode($token) : ''));

		$structured = array(); $images = array(); $cid = 0;
		if ($token !== '') {
			$data = self::get_doc_data($token);
			if ($data) { $structured = $data['structured']; $images = $data['images']; $cid = (int)$data['cid']; }
		}
		if (empty($structured)) $structured = self::structured_from_post();

		if (!$to || !is_email($to)) {
			wp_safe_redirect(add_query_arg(array('bmtg_msg'=>'invalid_email'), $back)); exit;
		}
		$title = $structured['title'] ?? 'Your BM Travel Group Itinerary';
		if ($subject === '') $subject = $title . ' — BM Travel Group';
		if ($message === '') {
			$message = "Dear Traveller,\n\nPlease find your curated itinerary from BM Travel Group below/attached for your review.\n\nWarm regards,\nBM Travel Group";
		}
		$attachments = array();
		$tmp = '';
		if ($attach_doc) {
			$doc_html = self::render_doc_html($structured, $images);
			$fname = sanitize_file_name($title ?: 'BMTG-Itinerary') . '.doc';
			$tmp = wp_tempnam($fname);
			if ($tmp) {
				file_put_contents($tmp, "\xEF\xBB\xBF" . $doc_html);
				// rename to .doc for mail clients
				$doc_path = preg_replace('/\.tmp$/i', '', $tmp) . '-' . $fname;
				if (@rename($tmp, $doc_path)) { $tmp = $doc_path; } else { $doc_path = $tmp; }
				$attachments[] = $doc_path;
			}
		}
		$headers = array('Content-Type: text/plain; charset=UTF-8');
		$sent = wp_mail($to, $subject, $message, $headers, $attachments);
		if ($tmp && file_exists($tmp)) @unlink($tmp);

		if ($sent && $cid > 0) {
			update_post_meta($cid, '_bmtg_itinerary_sent_to', $to);
			update_post_meta($cid, '_bmtg_itinerary_sent_at', time());
		}
		wp_safe_redirect(add_query_arg(array('bmtg_msg'=> $sent ? 'sent' : 'send_failed'), $back));
		exit;
	}

	private static function structured_from_post(): array {
		$get = function($k){ return isset($_POST[$k]) ? wp_unslash($_POST[$k]) : ''; };
		$days = array();
		$titles = isset($_POST['day_title']) && is_array($_POST['day_title']) ? $_POST['day_title'] : array();
		$bodies = isset($_POST['day_body']) && is_array($_POST['day_body']) ? $_POST['day_body'] : array();
		$max = max(count($titles), count($bodies));
		for ($i=0; $i<$max; $i++) {
			$dt = sanitize_text_field((string) wp_unslash($titles[$i] ?? ''));
			$db = sanitize_textarea_field((string) wp_unslash($bodies[$i] ?? ''));
			if ($dt === '' && $db === '') continue;
			$days[] = array('day'=>$i+1,'title'=>$dt ?: ('Day '.($i+1)),'body'=>$db);
		}
		$facts = array();
		$fl = isset($_POST['fact_label']) && is_array($_POST['fact_label']) ? $_POST['fact_label'] : array();
		$fv = isset($_POST['fact_value']) && is_array($_POST['fact_value']) ? $_POST['fact_value'] : array();
		for ($i=0; $i<max(count($fl),count($fv)); $i++) {
			$lab = sanitize_text_field((string) wp_unslash($fl[$i] ?? ''));
			$val = sanitize_text_field((string) wp_unslash($fv[$i] ?? ''));
			if ($lab === '' && $val === '') continue;
			$facts[] = array('label'=>$lab,'value'=>$val);
		}
		return array(
			'title' => sanitize_text_field((string)$get('title')) ?: 'Bespoke Journey',
			'subtitle' => sanitize_text_field((string)$get('subtitle')),
			'tagline' => 'Curated. Private. Exceptional.',
			'destinations' => sanitize_text_field((string)$get('destinations')),
			'overview' => sanitize_textarea_field((string)$get('overview')),
			'facts' => $facts,
			'days' => $days ?: array(array('day'=>1,'title'=>'Day 1','body'=>sanitize_textarea_field((string)$get('body')))),
			'closing' => sanitize_textarea_field((string)$get('closing')),
			'notes' => sanitize_textarea_field((string)$get('notes')),
		);
	}

	public static function render_doc_html( array $structured, array $images = array() ): string {
		$s = get_option('bmtg_chatbot_settings', array());
		$biz = (string)($s['business_name'] ?? 'BM Travel Group');
		$addr = (string)($s['business_address'] ?? '');
		$email = (string)($s['contact_email'] ?? '');
		$phone = (string)($s['contact_phone'] ?? '');
		$wa_num = preg_replace('/\D+/', '', (string)($s['whatsapp_number'] ?? ''));
		$e = function($v){ return esc_html((string)$v); };
		$ep = function($v){ return nl2br(esc_html((string)$v)); };
		$eu = function($v){ return esc_url((string)$v); };
		$title = (string)($structured['title'] ?? 'Bespoke Journey');
		$subtitle = (string)($structured['subtitle'] ?? ($structured['destinations'] ?? ''));
		$tagline = 'Curated. Private. Exceptional.';
		$overview = (string)($structured['overview'] ?? '');
		$facts = is_array($structured['facts'] ?? null) ? $structured['facts'] : array();
		$days = is_array($structured['days'] ?? null) ? $structured['days'] : array();
		$closing = (string)($structured['closing'] ?? '');
		$notes = (string)($structured['notes'] ?? '');
		$logo = (string)($s['logo_url'] ?? '');
		$logo_html = $logo !== '' ? '<img src="'.$eu($logo).'" alt="'.$e($biz).'" height="60" style="max-height:60px">' : '<div style="font-size:24px;font-weight:bold">'.$e($biz).'</div>';
		$hero = !empty($images[0]['url']) ? '<p><img src="'.$eu($images[0]['url']).'" alt="'.$e($title).'" style="width:100%;max-width:680px;height:auto"></p>' : '';
		$contact = array();
		if ($addr) $contact[] = $e(preg_replace('/\s+/',' ',$addr));
		if ($email) $contact[] = $e($email);
		if ($phone) $contact[] = $e($phone);
		if ($wa_num) $contact[] = 'WhatsApp: +' . $e($wa_num);
		$contact_html = implode(' | ', $contact);

		$facts_html = '';
		if ($facts) {
			$facts_html .= '<h2>Facts &amp; Highlights</h2><table border="0" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%">';
			foreach ($facts as $f) {
				$facts_html .= '<tr><td style="font-weight:bold;border-bottom:1px solid #ddd;width:35%">'.$e($f['label']??'').'</td><td style="border-bottom:1px solid #ddd">'.$e($f['value']??'').'</td></tr>';
			}
			$facts_html .= '</table>';
		}
		$days_html = '';
		foreach ($days as $i=>$d) {
			$dn = (int)($d['day'] ?? ($i+1));
			$dt = (string)($d['title'] ?? '');
			$db = (string)($d['body'] ?? '');
			$img = $images[($i+1) % max(1,count($images))]['url'] ?? '';
			$img_html = $img ? '<p><img src="'.$eu($img).'" alt="'.$e($dt).'" style="width:100%;max-width:520px;height:auto"></p>' : '';
			$days_html .= '<h2>Day '.$dn.($dt?': '.$e($dt):'').'</h2>'.$img_html.'<p style="text-align:justify;line-height:1.7">'.$ep($db).'</p>';
		}
		return '<!doctype html><html><head><meta charset="utf-8"><title>'.$e($title).'</title></head><body style="font-family:Calibri,Arial,sans-serif;color:#1f2937;line-height:1.6">'
			.'<div style="text-align:center;border-bottom:1px solid #e8e2d6;padding-bottom:12px;margin-bottom:18px">'.$logo_html.'<div style="letter-spacing:2px;color:#c6a75e;font-weight:bold;margin-top:6px">'.$e($tagline).'</div><h1 style="margin:12px 0 4px">'.$e($title).'</h1><div style="color:#6b7280">'.$e($subtitle).'</div></div>'
			.$hero
			.($overview?'<h2>Overview</h2><p style="text-align:justify;line-height:1.7">'.$ep($overview).'</p>':'')
			.$facts_html
			.$days_html
			.($closing?'<h2>Plan Your Journey</h2><p style="text-align:justify;line-height:1.7">'.$ep($closing).'</p>':'')
			.($notes?'<p style="text-align:justify;line-height:1.7">'.$ep($notes).'</p>':'')
			.'<hr style="border:none;border-top:1px solid #e8e2d6;margin-top:24px"><p style="font-size:12px;color:#4b5563;text-align:center">'.$contact_html.'</p>'
			.'</body></html>';
	}

	public static function render_html( $structured_or_title, $dest_or_images = '', string $body_legacy = '' ): string {
		// Support legacy signature render_html($title,$dest,$body)
		if (is_string($structured_or_title)) {
			$structured = self::parse_structured('', $structured_or_title, (string)$dest_or_images, 5, $body_legacy);
			$images = array();
		} else {
			$structured = is_array($structured_or_title) ? $structured_or_title : array();
			$images = is_array($dest_or_images) ? $dest_or_images : array();
		}
		$s = get_option('bmtg_chatbot_settings', array());
		$biz = (string)($s['business_name'] ?? 'BM Travel Group');
		$addr = (string)($s['business_address'] ?? '');
		$email = (string)($s['contact_email'] ?? '');
		$phone = (string)($s['contact_phone'] ?? '');
		$wa_num = preg_replace('/\D+/', '', (string)($s['whatsapp_number'] ?? ''));
		$wa_link = $wa_num !== '' ? ('https://wa.me/' . rawurlencode($wa_num)) : '';
		$logo = (string)($s['logo_url'] ?? '');

		$title = (string)($structured['title'] ?? 'Bespoke Journey');
		$subtitle = (string)($structured['subtitle'] ?? ($structured['destinations'] ?? ''));
		$tagline = 'Curated. Private. Exceptional.';
		$overview = (string)($structured['overview'] ?? '');
		$facts = is_array($structured['facts'] ?? null) ? $structured['facts'] : array();
		$days = is_array($structured['days'] ?? null) ? $structured['days'] : array();
		$closing = (string)($structured['closing'] ?? '');
		$notes = (string)($structured['notes'] ?? '');

		$e = function($v){ return esc_html((string)$v); };
		$ep = function($v){ return nl2br(esc_html((string)$v)); };
		$eu = function($v){ return esc_url((string)$v); };

		$hero = !empty($images[0]['url']) ? $images[0]['url'] : '';
		$hero_alt = !empty($images[0]['alt']) ? $images[0]['alt'] : $title;

		$logo_html = $logo !== '' ? '<img src="'.$eu($logo).'" alt="'.$e($biz).'" class="brand-logo">' : '<div class="brand-text">'.$e($biz).'</div>';

		$contact_bits = array();
		if ($addr !== '') $contact_bits[] = '<span>'.$e(preg_replace('/\s+/', ' ', $addr)).'</span>';
		if ($email !== '') $contact_bits[] = '<a href="mailto:'.$eu($email).'">'.$e($email).'</a>';
		if ($phone !== '') { $tel = preg_replace('/[^\d+]/', '', $phone); $contact_bits[] = '<a href="tel:'.$e($tel).'">'.$e($phone).'</a>'; }
		if ($wa_link !== '') $contact_bits[] = '<a href="'.$eu($wa_link).'" target="_blank" rel="noopener">WhatsApp</a>';
		$contact_html = implode(' <span class="sep">|</span> ', $contact_bits);
		if ($contact_html === '') $contact_html = '<span>bmtravelgroup.com</span>';

		$hero_html = $hero ? '<div class="hero"><img src="'.$eu($hero).'" alt="'.$e($hero_alt).'"><div class="hero-overlay"></div><div class="hero-caption">'.$e($title).'</div></div>' : '<div class="hero hero-fallback"><div class="hero-caption">'.$e($title).'</div></div>';

		$sections = '';
		$img_idx = 1; $side = 0;
		// Overview section
		if ($overview !== '') {
			$img = $images[$img_idx]['url'] ?? '';
			$alt = $images[$img_idx]['alt'] ?? $dest ?: $title;
			if ($img) $img_idx++;
			$sections .= self::zig_section('Overview', $overview, $img, $alt, $side++ % 2 === 0, $e, $ep, $eu);
		}
		// Facts
		if (!empty($facts)) {
			$rows = '';
			foreach ($facts as $f) {
				$lab = trim((string)($f['label'] ?? ''));
				$val = trim((string)($f['value'] ?? ''));
				if ($lab === '' && $val === '') continue;
				$rows .= '<div class="fact"><span class="fact-l">'.$e($lab).'</span><span class="fact-v">'.$e($val).'</span></div>';
			}
			if ($rows !== '') {
				$img = $images[$img_idx]['url'] ?? '';
				$alt = $images[$img_idx]['alt'] ?? 'Highlights';
				if ($img) $img_idx++;
				$body_html = '<div class="facts">'.$rows.'</div>';
				$sections .= self::zig_section_raw('Facts & Highlights', $body_html, $img, $alt, $side++ % 2 === 0, $e, $eu);
			}
		}
		// Days
		if (!empty($days)) {
			foreach ($days as $d) {
				$dn = (int)($d['day'] ?? 0);
				$dt = trim((string)($d['title'] ?? ''));
				$db = trim((string)($d['body'] ?? ''));
				$heading = ($dn ? 'Day ' . $dn : '') . ($dt ? ($dn ? ': ' : '') . $dt : '');
				$img = $images[$img_idx % max(1,count($images))]['url'] ?? '';
				$alt = $images[$img_idx % max(1,count($images))]['alt'] ?? $heading;
				if (!empty($images)) $img_idx++;
				$sections .= self::zig_section($heading, $db, $img, $alt, $side++ % 2 === 0, $e, $ep, $eu);
			}
		}
		if ($closing !== '' || $notes !== '') {
			$extra = trim($closing . ($notes !== '' ? "\n\n" . $notes : ''));
			$img = $images[$img_idx % max(1,count($images))]['url'] ?? '';
			$alt = $images[$img_idx % max(1,count($images))]['alt'] ?? 'Journey';
			$sections .= self::zig_section('Plan Your Journey', $extra, $img, $alt, $side++ % 2 === 0, $e, $ep, $eu);
		}

		$subtitle_html = $subtitle !== '' ? '<div class="doc-subtitle">'.$e($subtitle).'</div>' : '';

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($title).'</title><style>
:root{--ink:#1f2937;--muted:#6b7280;--gold:#c6a75e;--cream:#fffdf8;--paper:#ffffff;--border:#e8e2d6;--dark:#171717}
*{box-sizing:border-box}body{margin:0;font-family:Georgia,"Times New Roman",serif;color:var(--ink);background:var(--cream);-webkit-print-color-adjust:exact;print-color-adjust:exact}
.toolbar{position:sticky;top:0;z-index:5;background:var(--dark);color:#fff;padding:10px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between}
.toolbar .hint{font:13px/1.4 system-ui,sans-serif;color:#e5e7eb}
.toolbar button{background:var(--gold);color:#171717;border:none;border-radius:999px;padding:8px 14px;font:600 13px system-ui,sans-serif;cursor:pointer}
.page{max-width:980px;margin:0 auto;background:var(--paper);min-height:100vh;box-shadow:0 10px 30px rgba(0,0,0,.06)}
.doc-header{padding:28px 36px 18px;text-align:center;border-bottom:1px solid var(--border);background:linear-gradient(180deg,#fff, #fffaf0)}
.brand-logo{max-height:72px;max-width:260px;object-fit:contain}
.brand-text{font-size:28px;letter-spacing:.5px;color:var(--dark)}
.tagline{margin-top:8px;font:600 13px/1.2 "Georgia",serif;letter-spacing:2.5px;text-transform:uppercase;color:var(--gold)}
.doc-title{margin:18px 0 4px;font-size:30px;font-weight:600;color:var(--dark)}
.doc-subtitle{color:var(--muted);font-size:15px}
.hero{position:relative;height:360px;overflow:hidden;background:#e5e7eb}
.hero img{width:100%;height:100%;object-fit:cover;display:block}
.hero-overlay{position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.05),rgba(0,0,0,.35))}
.hero-fallback{background:linear-gradient(135deg,#f3efe6,#e8e2d6)}
.hero-caption{position:absolute;left:28px;bottom:22px;right:28px;color:#fff;font-size:22px;font-weight:600;text-shadow:0 2px 10px rgba(0,0,0,.35)}
.content{padding:26px 36px 120px}
.section{display:flex;gap:22px;align-items:stretch;margin:0 0 22px;page-break-inside:avoid}
.section.reverse{flex-direction:row-reverse}
.sec-text{flex:1 1 58%;padding:18px 18px 16px;border:1px solid var(--border);border-radius:14px;background:#fff;position:relative}
.sec-text:before{content:"";position:absolute;left:14px;top:14px;width:28px;height:3px;background:var(--gold);border-radius:2px}
.sec-title{margin:6px 0 10px;font-size:20px;color:var(--dark);font-weight:600}
.sec-body{line-height:1.8;text-align:justify;text-justify:inter-word;color:#374151;font-size:15px}
.sec-media{flex:1 1 42%;min-height:220px}
.sec-media .frame{height:100%;min-height:220px;border-radius:14px;overflow:hidden;border:1px solid var(--border);box-shadow:0 6px 16px rgba(0,0,0,.06);position:relative}
.sec-media img{width:100%;height:100%;object-fit:cover;display:block}
.sec-media .placeholder{height:100%;min-height:220px;border-radius:14px;border:1px dashed var(--border);background:linear-gradient(135deg,#faf8f2,#f1ece1);display:flex;align-items:center;justify-content:center;color:var(--muted);font:14px system-ui,sans-serif}
.facts{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;margin-top:4px}
.fact{padding:8px 10px;border-left:3px solid var(--gold);background:#fffdf8;border-radius:6px}
.fact-l{display:block;font:600 12px system-ui,sans-serif;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.fact-v{display:block;font-size:14px;color:var(--ink);margin-top:2px}
.footer{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid var(--border);padding:10px 18px;text-align:center;font:12.5px/1.5 system-ui,sans-serif;color:#4b5563}
.footer a{color:#1f4e3d;text-decoration:none}
.footer .sep{color:#c7c0b3;margin:0 6px}
@media(max-width:800px){.content{padding:18px 16px 130px}.doc-header{padding:22px 16px 14px}.hero{height:240px}.section,.section.reverse{flex-direction:column}.sec-media .frame,.sec-media .placeholder{min-height:200px}.facts{grid-template-columns:1fr}}
@media print{.toolbar{display:none}.page{box-shadow:none;max-width:none}.content{padding-bottom:90px}body{background:#fff}.section{break-inside:avoid}}
</style></head><body>
<div class="toolbar"><div class="hint">View your itinerary below. Use Print or Save as PDF to download a copy.</div><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="page">
  <header class="doc-header">'.$logo_html.'<div class="tagline">'.$e($tagline).'</div><h1 class="doc-title">'.$e($title).'</h1>'.$subtitle_html.'</header>
  '.$hero_html.'
  <main class="content">'.$sections.'</main>
</div>
<footer class="footer">'.$contact_html.'</footer>
</body></html>';
	}

	private static function zig_section( string $heading, string $body, string $img, string $alt, bool $img_left, $e, $ep, $eu ): string {
		$body_html = '<div class="sec-body">'.$ep($body).'</div>';
		return self::zig_section_raw($heading, $body_html, $img, $alt, $img_left, $e, $eu);
	}

	private static function zig_section_raw( string $heading, string $body_html, string $img, string $alt, bool $img_left, $e, $eu ): string {
		$media = $img !== ''
			? '<div class="frame"><img src="'.$eu($img).'" alt="'.$e($alt).'"></div>'
			: '<div class="placeholder">BM Travel Group</div>';
		$cls = $img_left ? 'section' : 'section reverse';
		return '<section class="'.$cls.'"><div class="sec-media">'.$media.'</div><div class="sec-text"><h2 class="sec-title">'.$e($heading).'</h2>'.$body_html.'</div></section>';
	}

	// Canva helpers (unchanged behavior, improved safety)
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
}
