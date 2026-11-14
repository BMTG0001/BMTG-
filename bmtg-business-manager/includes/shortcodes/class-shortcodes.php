<?php
namespace BMTG\Shortcodes;

use BMTG\Integrations\Chatbot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcodes {
	public static function register(): void {
		add_shortcode( 'bmtg_chatbot', array( __CLASS__, 'chatbot' ) );
		add_shortcode( 'bmtg_chat_widget', array( __CLASS__, 'chatbot' ) );
		add_shortcode( 'bmtg_lead_form', array( __CLASS__, 'lead_form' ) );
		add_shortcode( 'bmtg_whatsapp', array( __CLASS__, 'whatsapp' ) );
		add_shortcode( 'bmtg_inquiry_form', array( __CLASS__, 'inquiry_form' ) );
	}

	public static function chatbot( $atts = array() ): string {
		$atts = shortcode_atts( array( 'page' => '', 'mode' => 'floating' ), $atts, 'bmtg_chatbot' );
		$settings = get_option( 'bmtg_chatbot_settings', array() );
		if ( empty( $settings['enabled'] ) ) return '';
		// Reuse chatbot render path if available.
		$hub = \BMTG\Integrations\Hub::instance();
		// Chatbot registers its own shortcode; this is a safe alias/mount.
		$cb = new Chatbot( $hub );
		return $cb->shortcode( $atts );
	}

	public static function lead_form( $atts = array() ): string {
		$atts = shortcode_atts( array( 'title' => 'Plan your journey', 'button' => 'Submit enquiry' ), $atts, 'bmtg_lead_form' );
		$nonce = wp_create_nonce( 'wp_rest' );
		$rest = esc_url_raw( rest_url( 'bmtg/v1/chatbot' ) );
		ob_start(); ?>
		<div class="bmtg-inline-lead" data-rest="<?php echo esc_attr($rest); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
			<h3><?php echo esc_html($atts['title']); ?></h3>
			<p class="bmtg-muted">Share your details with consent and our team will assist you. No public prices are displayed.</p>
			<label>Name<input type="text" name="name"></label>
			<label>Email<input type="email" name="email"></label>
			<label>Phone<input type="text" name="phone"></label>
			<label>Travel requirements<textarea name="requirements" rows="4"></textarea></label>
			<label class="bmtg-consent-row"><input type="checkbox" name="consent"> I explicitly consent to be contacted regarding my enquiry.</label>
			<button type="button" class="bmtg-inline-submit"><?php echo esc_html($atts['button']); ?></button>
			<div class="bmtg-inline-msg" aria-live="polite"></div>
		</div>
		<script>(function(){document.querySelectorAll('.bmtg-inline-lead').forEach(function(w){if(w.__b)return;w.__b=true;w.querySelector('.bmtg-inline-submit').onclick=async function(){var msg=w.querySelector('.bmtg-inline-msg');msg.textContent='Submitting...';
		try{var s=await fetch(w.dataset.rest+'/session',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':w.dataset.nonce},credentials:'same-origin',body:'{}'}).then(r=>r.json());
		var payload={session_id:s.session_id,session_token:s.session_token,consent:!!w.querySelector('[name=consent]').checked,name:w.querySelector('[name=name]').value,email:w.querySelector('[name=email]').value,phone:w.querySelector('[name=phone]').value,requirements:w.querySelector('[name=requirements]').value};
		var r=await fetch(w.dataset.rest+'/lead',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':w.dataset.nonce},credentials:'same-origin',body:JSON.stringify(payload)});var d=await r.json(); if(!r.ok) throw new Error(d.error||'Failed'); msg.textContent='Thank you. Our team will assist you shortly.';}catch(e){msg.textContent=e.message||'Unable to submit.';}}});})();</script>
		<style>.bmtg-inline-lead{max-width:520px;padding:16px;border:1px solid #e8e2d6;border-radius:14px;background:#fffdf8}.bmtg-inline-lead label{display:block;margin:8px 0;font-size:14px}.bmtg-inline-lead input,.bmtg-inline-lead textarea{width:100%;padding:8px;border:1px solid #ddd;border-radius:8px}.bmtg-inline-lead .bmtg-inline-submit{background:#1f4e3d;color:#fff;border:none;padding:10px 14px;border-radius:999px;cursor:pointer}.bmtg-muted{color:#666;font-size:13px}</style>
		<?php return (string) ob_get_clean();
	}

	public static function whatsapp( $atts = array() ): string {
		$s = get_option( 'bmtg_chatbot_settings', array() );
		$atts = shortcode_atts( array(
			'number' => $s['whatsapp_number'] ?? '',
			'text'   => $s['whatsapp_prefill'] ?? 'Hello BM Travel Group, I would like assistance planning a journey.',
			'label'  => 'Chat on WhatsApp',
		), $atts, 'bmtg_whatsapp' );
		$num = preg_replace('/\D+/', '', (string)$atts['number']);
		if ($num === '') return '';
		$url = 'https://wa.me/' . rawurlencode($num) . '?text=' . rawurlencode((string)$atts['text']);
		return '<a class="bmtg-wa-btn" href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($atts['label']).'</a><style>.bmtg-wa-btn{display:inline-block;background:#25D366;color:#fff;padding:10px 14px;border-radius:999px;text-decoration:none;font-weight:600}</style>';
	}

	public static function inquiry_form( $atts = array() ): string { return self::lead_form($atts); }
}
