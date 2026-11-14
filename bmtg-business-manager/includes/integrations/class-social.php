<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Best-effort daily Instagram auto-post from Google Trends topics.
 * Note: "trending audio" selection/attachment for Reels is not reliably automatable via the Instagram Graph API;
 * this module publishes image posts with trend-based captions and hashtags when configured.
 */
class Social {
	public const OPTION_KEY = 'bmtg_social_settings';
	public const CRON_HOOK = 'bmtg_daily_instagram_post';
	public const LOG_OPTION = 'bmtg_social_last_log';

	public static function register_hooks(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_post' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_bmtg_social_run_now', array( __CLASS__, 'handle_run_now' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 06:30 UTC' ), 'daily', self::CRON_HOOK );
		}
	}

	public static function defaults(): array {
		return array(
			'enabled' => false,
			'geo' => 'IN',
			'category' => '0',
			'ig_user_id' => '',
			'ig_access_token' => '',
			'hashtags_base' => '#BMTravelGroup #LuxuryTravel #BespokeTravel #India #Nepal #Bhutan #SriLanka #Travel',
			'hashtag_count' => 12,
			'post_template' => "Discover: {topic}\n\n{caption}\n\n{hashtags}\n\nPlan your journey with BM Travel Group: https://bmtravelgroup.com",
			'use_ai_caption' => true,
			'use_unsplash_image' => true,
			'fallback_image_url' => '',
			'last_topic' => '',
			'last_post_id' => '',
			'last_run_at' => '',
		);
	}

	public static function settings(): array {
		$opts = get_option( self::OPTION_KEY, array() );
		return is_array( $opts ) ? array_merge( self::defaults(), $opts ) : self::defaults();
	}

	public static function register_settings(): void {
		register_setting( 'bmtg_social_group', self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
	}

	public static function sanitize( $input ): array {
		$out = self::settings();
		if ( ! is_array( $input ) ) return $out;
		$out['enabled'] = ! empty( $input['enabled'] );
		$out['geo'] = strtoupper( sanitize_text_field( $input['geo'] ?? 'IN' ) );
		if ( ! preg_match('/^[A-Z]{2}$/', $out['geo']) ) $out['geo'] = 'IN';
		$out['category'] = preg_replace('/[^0-9]/', '', (string)($input['category'] ?? '0'));
		$out['ig_user_id'] = sanitize_text_field( $input['ig_user_id'] ?? '' );
		if ( ! empty( $input['ig_access_token'] ) ) {
			$out['ig_access_token'] = sanitize_text_field( $input['ig_access_token'] );
		}
		$out['hashtags_base'] = sanitize_textarea_field( $input['hashtags_base'] ?? $out['hashtags_base'] );
		$out['hashtag_count'] = max(3, min(30, (int)($input['hashtag_count'] ?? 12)));
		$out['post_template'] = sanitize_textarea_field( $input['post_template'] ?? $out['post_template'] );
		$out['use_ai_caption'] = ! empty( $input['use_ai_caption'] );
		$out['use_unsplash_image'] = ! empty( $input['use_unsplash_image'] );
		$out['fallback_image_url'] = esc_url_raw( $input['fallback_image_url'] ?? '' );
		return $out;
	}

	public static function menu(): void {
		$parent = class_exists( '\\BMTG\\CRM\\Capabilities' ) ? 'bmtg-crm' : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'BMTG Social Auto Post', 'bmtg-business-manager' ),
			__( 'Social Auto Post', 'bmtg-business-manager' ),
			Activator::CAPABILITY,
			'bmtg-social',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Activator::CAPABILITY ) ) wp_die('Forbidden');
		$s = self::settings();
		$log = get_option( self::LOG_OPTION, array() );
		$next = wp_next_scheduled( self::CRON_HOOK );
		$opt = self::OPTION_KEY;
		$run_url = wp_nonce_url( admin_url('admin-post.php?action=bmtg_social_run_now'), 'bmtg_social_run_now' );
		?>
		<div class="wrap">
			<h1>BMTG Social Auto Post (Instagram)</h1>
			<p>Best-effort daily auto post using a topic from Google Trends, with travel-focused caption and hashtags for organic reach. Requires WordPress cron to run, a publicly accessible image URL, and Instagram Graph API access (Instagram Business/Creator account connected to a Facebook Page) with publish permissions.</p>
			<p><strong>Trending audio:</strong> trending audio tracks cannot be reliably selected/attached automatically via the official Instagram Graph API. This module publishes topic-based image feed posts with captions/hashtags; add trending audio manually in the Instagram app for Reels when desired.</p>
			<p><strong>Simple connect:</strong> for Facebook/Instagram/WhatsApp access, use <a href="<?php echo esc_url( admin_url('admin.php?page=bmtg-integrations') ); ?>">Integrations → Meta (Facebook / Instagram / WhatsApp)</a> and click <em>Connect / Reconnect</em> (OAuth) after saving App ID/Secret and registering the redirect URI in your Meta App. You may also provide Instagram User ID and manual tokens as fallback.</p>
			<?php if (!empty($_GET['bmtg_social_msg'])): ?><div class="notice notice-info"><p><?php echo esc_html(sanitize_text_field((string)$_GET['bmtg_social_msg'])); ?></p></div><?php endif; ?>
			<p><strong>Next scheduled run:</strong> <?php echo $next ? esc_html( gmdate('Y-m-d H:i:s', $next) . ' UTC' ) : 'Not scheduled'; ?> &nbsp; <a class="button" href="<?php echo esc_url($run_url); ?>">Run now</a></p>
			<form method="post" action="options.php">
				<?php settings_fields('bmtg_social_group'); ?>
				<table class="form-table" role="presentation">
					<tr><th>Enable daily auto post</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="1" <?php checked($s['enabled']); ?>> Enabled (runs daily via WP-Cron)</label></td></tr>
					<tr><th>Google Trends geo</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[geo]" value="<?php echo esc_attr($s['geo']); ?>" class="small-text" placeholder="IN"><p class="description">Two-letter country code (e.g. IN, US, GB).</p></td></tr>
					<tr><th>Instagram User ID</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ig_user_id]" value="<?php echo esc_attr($s['ig_user_id']); ?>" class="regular-text" placeholder="1784..."><p class="description">Instagram Business/Creator account ID. Leave blank to try Meta connector value.</p></td></tr>
					<tr><th>Instagram Access Token</th><td><input type="password" name="<?php echo esc_attr($opt); ?>[ig_access_token]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['ig_access_token'] ? '•••••• (saved)' : ''; ?>"><p class="description">Long-lived token with publish permissions. Leave blank to keep current or use Meta connector token.</p></td></tr>
					<tr><th>Base hashtags</th><td><textarea name="<?php echo esc_attr($opt); ?>[hashtags_base]" rows="3" class="large-text"><?php echo esc_textarea($s['hashtags_base']); ?></textarea></td></tr>
					<tr><th>Hashtag count</th><td><input type="number" min="3" max="30" name="<?php echo esc_attr($opt); ?>[hashtag_count]" value="<?php echo esc_attr($s['hashtag_count']); ?>"></td></tr>
					<tr><th>Caption template</th><td><textarea name="<?php echo esc_attr($opt); ?>[post_template]" rows="5" class="large-text"><?php echo esc_textarea($s['post_template']); ?></textarea><p class="description">Placeholders: {topic}, {caption}, {hashtags}</p></td></tr>
					<tr><th>AI caption</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[use_ai_caption]" value="1" <?php checked($s['use_ai_caption']); ?>> Generate/refine caption with connected AI (Groq/OpenRouter) when available</label></td></tr>
					<tr><th>Unsplash image</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[use_unsplash_image]" value="1" <?php checked($s['use_unsplash_image']); ?>> Use Unsplash image for topic (requires Unsplash connector); falls back to fallback/logo URL</label></td></tr>
					<tr><th>Fallback image URL</th><td><input type="url" name="<?php echo esc_attr($opt); ?>[fallback_image_url]" value="<?php echo esc_attr($s['fallback_image_url']); ?>" class="large-text" placeholder="https://..."><p class="description">Public image URL used if Unsplash is unavailable (Instagram requires a publicly accessible image URL).</p></td></tr>
				</table>
				<?php submit_button('Save Social Settings'); ?>
			</form>
			<h2>Last run</h2>
			<p><strong>Last run:</strong> <?php echo esc_html($s['last_run_at'] ?: 'Never'); ?> | <strong>Topic:</strong> <?php echo esc_html($s['last_topic'] ?: '-'); ?> | <strong>Post/Container ID:</strong> <?php echo esc_html($s['last_post_id'] ?: '-'); ?></p>
			<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:260px;overflow:auto"><?php echo esc_html(is_array($log)? wp_json_encode($log, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : (string)$log); ?></pre>
		</div>
		<?php
	}

	public static function handle_run_now(): void {
		if ( ! current_user_can( Activator::CAPABILITY ) ) wp_die('Forbidden');
		check_admin_referer('bmtg_social_run_now');
		$res = self::run_daily_post(true);
		$msg = !empty($res['ok']) ? ('Posted. Topic: ' . ($res['topic'] ?? '')) : ('Run completed: ' . ($res['error'] ?? $res['message'] ?? 'see log'));
		wp_safe_redirect( admin_url('admin.php?page=bmtg-social&bmtg_social_msg=' . rawurlencode($msg)) );
		exit;
	}

	private static function fetch_trend_topic( string $geo, string $cat ): string {
		$url = add_query_arg(array('geo'=>$geo ?: 'IN'), 'https://trends.google.com/trending/rss');
		$resp = wp_remote_get($url, array('timeout'=>20, 'headers'=>array('User-Agent'=>'BMTG/1.0')));
		if (is_wp_error($resp)) return '';
		$body = (string) wp_remote_retrieve_body($resp);
		if ($body === '') return '';
		if (preg_match_all('/<title><!\[CDATA\[(.*?)\]\]><\/title>|<title>(.*?)<\/title>/si', $body, $m)) {
			$candidates = array();
			$total = count($m[0]);
			for ($i=0; $i<$total; $i++) {
				$t = trim(html_entity_decode($m[1][$i] ?: ($m[2][$i] ?? ''), ENT_QUOTES, 'UTF-8'));
				if ($t !== '' && stripos($t,'daily search trends') === false && stripos($t,'trending') !== 0) $candidates[] = $t;
			}
			if (!empty($candidates[0])) return $candidates[0];
		}
		if (preg_match_all('/<ht:news_item_title><!\[CDATA\[(.*?)\]\]><\/ht:news_item_title>|<ht:news_item_title>(.*?)<\/ht:news_item_title>/si', $body, $m)) {
			$t = trim(html_entity_decode($m[1][0] ?: ($m[2][0] ?? ''), ENT_QUOTES, 'UTF-8'));
			if ($t !== '') return $t;
		}
		return '';
	}

	private static function build_caption( string $topic, array $s ): string {
		$base = "Exploring what’s inspiring travellers today: {$topic}. At BM Travel Group, we craft bespoke journeys across India, Nepal, Bhutan, and Sri Lanka with care, comfort, and authentic experiences.";
		if (empty($s['use_ai_caption']) || !class_exists('\\BMTG\\Integrations\\Manager')) return $base;
		$prompt = "Write a short, elegant Instagram caption (2-4 sentences, no hashtags) for BM Travel Group (luxury bespoke travel in India, Nepal, Bhutan, Sri Lanka) inspired by this trending topic: \"{$topic}\". Keep it relevant to travel/inspiration where possible, warm and professional, no prices, no false claims.";
		$text = '';
		try {
			$client = Manager::client('groq','live');
			$cs = get_option('bmtg_chatbot_settings', array());
			$model = !empty($cs['model_groq']) ? (string)$cs['model_groq'] : 'llama-3.3-70b-versatile';
			$res = $client->chat_completion(array('model'=>$model,'messages'=>array(array('role'=>'user','content'=>$prompt)),'temperature'=>0.6));
			$text = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
		} catch (\Throwable $e) { $text=''; }
		if ($text==='') {
			try {
				$client = Manager::client('openrouter','live');
				$cs = get_option('bmtg_chatbot_settings', array());
				$model = !empty($cs['model_openrouter']) ? (string)$cs['model_openrouter'] : 'openai/gpt-4o-mini';
				$res = $client->openrouter_chat_completion(array('model'=>$model,'messages'=>array(array('role'=>'user','content'=>$prompt)),'temperature'=>0.6));
				$text = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
			} catch (\Throwable $e) { $text=''; }
		}
		return $text !== '' ? $text : $base;
	}

	private static function build_hashtags( string $topic, array $s ): string {
		$tags = preg_split('/\s+/', (string)($s['hashtags_base'] ?? ''));
		$topic_tags = array();
		foreach (preg_split('/[^A-Za-z0-9]+/', $topic) as $w) { $w = trim($w); if (strlen($w) >= 4) $topic_tags[] = '#' . ucfirst(strtolower($w)); }
		$extra = array('#TravelInspiration','#Wanderlust','#CulturalJourney','#BespokeJourney','#ExploreAsia','#TravelTrends');
		$all = array();
		foreach (array_merge((array)$tags, $topic_tags, $extra) as $t) {
			$t = trim((string)$t);
			if ($t === '') continue;
			if ($t[0] !== '#') $t = '#' . preg_replace('/[^A-Za-z0-9_]/','',$t);
			$t = preg_replace('/[^#A-Za-z0-9_]/','',$t);
			if (strlen($t) > 1) $all[strtolower($t)] = $t;
		}
		$all = array_values($all);
		shuffle($all);
		$max = max(3, min(30, (int)($s['hashtag_count'] ?? 12)));
		return implode(' ', array_slice($all, 0, $max));
	}

	private static function get_image_url( string $topic, array $s ): string {
		if (!empty($s['use_unsplash_image']) && class_exists('\\BMTG\\Integrations\\Manager')) {
			try {
				$client = Manager::client('unsplash','live');
				$q = $topic . ' travel landscape';
				$res = $client->search_photos($q, 1, 1);
				$u = $res['body']['results'][0]['urls']['regular'] ?? ($res['body']['results'][0]['urls']['full'] ?? '');
				if ($u) return (string) $u;
			} catch (\Throwable $e) {}
		}
		$fb = trim((string)($s['fallback_image_url'] ?? ''));
		if ($fb) return $fb;
		$cs = get_option('bmtg_chatbot_settings', array());
		return trim((string)($cs['logo_url'] ?? ''));
	}

	private static function get_instagram_credentials( array $s ): array {
		$ig_user = trim((string)($s['ig_user_id'] ?? ''));
		$token = trim((string)($s['ig_access_token'] ?? ''));
		if (($ig_user === '' || $token === '') && class_exists('\\BMTG\\Integrations\\Hub')) {
			try {
				$hub = Hub::instance();
				$cs = $hub->credential_store();
				$ts = $hub->token_store();
				if ($cs) {
					if ($ig_user === '') { $v = $cs->get_plaintext('meta','instagram_user_id','live'); if ($v) $ig_user = (string)$v; }
					if ($token === '') { $v = $cs->get_plaintext('meta','page_access_token','live'); if ($v) $token = (string)$v; }
				}
				if ($token === '' && $ts) { $v = $ts->get_access_token('meta','live'); if ($v) $token = (string)$v; }
			} catch (\Throwable $e) {}
		}
		return array('ig_user_id'=>$ig_user,'token'=>$token);
	}

	private static function publish_instagram_image( string $ig_user_id, string $token, string $image_url, string $caption ): array {
		$base = 'https://graph.facebook.com/v20.0/' . rawurlencode($ig_user_id);
		$resp = wp_remote_post($base . '/media', array('timeout'=>30,'body'=>array('image_url'=>$image_url,'caption'=>$caption,'access_token'=>$token)));
		if (is_wp_error($resp)) return array('ok'=>false,'error'=>'Instagram create request failed.');
		$body = json_decode((string)wp_remote_retrieve_body($resp), true);
		if (empty($body['id'])) return array('ok'=>false,'error'=>'Instagram container not created','response'=>$body);
		$creation_id = $body['id'];
		$pub = wp_remote_post($base . '/media_publish', array('timeout'=>30,'body'=>array('creation_id'=>$creation_id,'access_token'=>$token)));
		if (is_wp_error($pub)) return array('ok'=>false,'error'=>'Instagram publish request failed','container_id'=>$creation_id);
		$pbody = json_decode((string)wp_remote_retrieve_body($pub), true);
		if (empty($pbody['id'])) return array('ok'=>false,'error'=>'Instagram publish failed','response'=>$pbody,'container_id'=>$creation_id);
		return array('ok'=>true,'post_id'=>$pbody['id'],'container_id'=>$creation_id);
	}

	public static function run_daily_post( bool $force = false ): array {
		$s = self::settings();
		$log = array('at'=>gmdate('c'), 'forced'=>$force);
		if ( ! $force && empty($s['enabled']) ) { $log['message']='Disabled'; update_option(self::LOG_OPTION,$log,false); return $log; }
		try {
			$topic = self::fetch_trend_topic($s['geo'], $s['category'] ?? '0');
			if (!$topic) $topic = 'Luxury journeys in India, Nepal, Bhutan and Sri Lanka';
			$log['topic'] = $topic;

			$caption_body = self::build_caption($topic, $s);
			$hashtags = self::build_hashtags($topic, $s);
			$text = strtr((string)$s['post_template'], array('{topic}'=>$topic,'{caption}'=>$caption_body,'{hashtags}'=>$hashtags));
			$text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
			$text = mb_substr($text, 0, 2200);
			$log['caption_preview'] = mb_substr($text, 0, 280);

			$image_url = self::get_image_url($topic, $s);
			$log['image_url'] = $image_url ? '(set)' : '';

			$creds = self::get_instagram_credentials($s);
			$result = array('ok'=>false,'message'=>'Not posted (missing config or image)');
			if ($image_url && $creds['ig_user_id'] && $creds['token']) {
				$result = self::publish_instagram_image($creds['ig_user_id'], $creds['token'], $image_url, $text);
			} elseif (!$image_url) {
				$result = array('ok'=>false,'error'=>'No public image URL available (configure Unsplash or fallback/logo URL).');
			} else {
				$result = array('ok'=>false,'error'=>'Instagram User ID/access token not configured (set in Social Auto Post or connect Meta and provide Instagram User ID). Trending audio cannot be auto-attached via API; this publishes an image post.');
			}

			$s['last_topic'] = $topic;
			$s['last_run_at'] = gmdate('Y-m-d H:i:s') . ' UTC';
			if (!empty($result['post_id'])) $s['last_post_id'] = (string)$result['post_id'];
			update_option(self::OPTION_KEY, $s, false);
			$log = array_merge($log, $result);
		} catch (\Throwable $e) {
			$log['ok'] = false; $log['error'] = 'Run failed.';
		}
		update_option(self::LOG_OPTION, $log, false);
		return $log;
	}
}
