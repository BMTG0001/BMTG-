<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatbot_Admin {
	private Chatbot $chatbot;

	public function __construct( Chatbot $chatbot ) {
		$this->chatbot = $chatbot;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function menu(): void {
		// Consolidate under BMTG top-level menu when available.
		if ( class_exists( '\\BMTG\\CRM\\Capabilities' ) ) {
			add_submenu_page(
				'bmtg-crm',
				__( 'BMTG Chatbot', 'bmtg-business-manager' ),
				__( 'Chatbot', 'bmtg-business-manager' ),
				Activator::CAPABILITY,
				'bmtg-chatbot',
				array( $this, 'render' )
			);
		} else {
			add_options_page(
				__( 'BMTG Chatbot', 'bmtg-business-manager' ),
				__( 'BMTG Chatbot', 'bmtg-business-manager' ),
				Activator::CAPABILITY,
				'bmtg-chatbot',
				array( $this, 'render' )
			);
		}
	}

	public function register_settings(): void {
		register_setting( 'bmtg_chatbot_group', Chatbot::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	private function sanitize_hex( $val, $default ): string {
		$val = sanitize_text_field( (string) $val );
		if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $val ) ) return $val;
		return $default;
	}
	private function sanitize_size( $val, $default, $min, $max ): string {
		$v = is_numeric( $val ) ? (float) $val : (float) $default;
		$v = max( $min, min( $max, $v ) );
		return rtrim( rtrim( (string) $v, '0' ), '.' );
	}

	public function sanitize( $input ): array {
		$defaults = $this->chatbot->defaults();
		$out = $this->chatbot->settings();
		if ( ! is_array( $input ) ) return $out;
		$out['enabled'] = ! empty( $input['enabled'] );
		$out['auto_inject'] = ! empty( $input['auto_inject'] );
		$out['require_consent'] = ! empty( $input['require_consent'] );
		$out['greeting'] = sanitize_textarea_field( $input['greeting'] ?? $defaults['greeting'] );
		$out['operating_hours'] = sanitize_textarea_field( $input['operating_hours'] ?? '' );
		$out['fallback_message'] = sanitize_textarea_field( $input['fallback_message'] ?? $defaults['fallback_message'] );
		$out['handover_message'] = sanitize_textarea_field( $input['handover_message'] ?? $defaults['handover_message'] );
		$out['model_groq'] = sanitize_text_field( $input['model_groq'] ?? $defaults['model_groq'] );
		$out['model_openrouter'] = sanitize_text_field( $input['model_openrouter'] ?? $defaults['model_openrouter'] );
		$out['whatsapp_number'] = preg_replace( '/\D+/', '', sanitize_text_field( $input['whatsapp_number'] ?? '' ) );
		$out['whatsapp_prefill'] = sanitize_textarea_field( $input['whatsapp_prefill'] ?? $defaults['whatsapp_prefill'] );
		$out['business_name'] = sanitize_text_field( $input['business_name'] ?? $defaults['business_name'] );
		$out['business_address'] = sanitize_textarea_field( $input['business_address'] ?? '' );
		$out['contact_email'] = sanitize_email( $input['contact_email'] ?? '' );
		$out['contact_phone'] = sanitize_text_field( $input['contact_phone'] ?? '' );
		$out['logo_url'] = esc_url_raw( $input['logo_url'] ?? '' );
		$out['retention_days'] = max( 1, min( 3650, (int) ( $input['retention_days'] ?? $defaults['retention_days'] ) ) );
		$excluded = array();
		if ( ! empty( $input['excluded_pages'] ) ) {
			$parts = is_array( $input['excluded_pages'] ) ? $input['excluded_pages'] : explode( ',', (string) $input['excluded_pages'] );
			foreach ( $parts as $p ) { $p = trim( (string) $p ); if ( $p !== '' ) $excluded[] = (string) absint( $p ); }
		}
		$out['excluded_pages'] = array_values( array_filter( $excluded ) );
		$knowledge = array();
		if ( ! empty( $input['knowledge'] ) ) {
			$lines = is_array( $input['knowledge'] ) ? $input['knowledge'] : preg_split( "/\r\n|\n|\r/", (string) $input['knowledge'] );
			foreach ( $lines as $line ) { $line = trim( sanitize_text_field( (string) $line ) ); if ( $line !== '' ) $knowledge[] = $line; }
		}
		$out['knowledge'] = array_slice( $knowledge, 0, 200 );
		$quick = array();
		if ( ! empty( $input['quick_questions'] ) ) {
			$qlines = is_array( $input['quick_questions'] ) ? $input['quick_questions'] : preg_split( "/\r\n|\n|\r/", (string) $input['quick_questions'] );
			foreach ( $qlines as $line ) { $line = trim( sanitize_text_field( (string) $line ) ); if ( $line !== '' ) $quick[] = $line; }
		}
		$out['quick_questions'] = array_slice( $quick, 0, 10 );

		// Appearance
		$out['ui_header_bg'] = $this->sanitize_hex( $input['ui_header_bg'] ?? $defaults['ui_header_bg'], $defaults['ui_header_bg'] );
		$out['ui_header_accent'] = $this->sanitize_hex( $input['ui_header_accent'] ?? $defaults['ui_header_accent'], $defaults['ui_header_accent'] );
		$out['ui_chat_bg'] = $this->sanitize_hex( $input['ui_chat_bg'] ?? $defaults['ui_chat_bg'], $defaults['ui_chat_bg'] );
		$out['ui_incoming_bg'] = $this->sanitize_hex( $input['ui_incoming_bg'] ?? $defaults['ui_incoming_bg'], $defaults['ui_incoming_bg'] );
		$out['ui_outgoing_bg'] = $this->sanitize_hex( $input['ui_outgoing_bg'] ?? $defaults['ui_outgoing_bg'], $defaults['ui_outgoing_bg'] );
		$out['ui_accent'] = $this->sanitize_hex( $input['ui_accent'] ?? $defaults['ui_accent'], $defaults['ui_accent'] );
		$out['ui_text_color'] = $this->sanitize_hex( $input['ui_text_color'] ?? $defaults['ui_text_color'], $defaults['ui_text_color'] );
		$out['ui_muted_color'] = $this->sanitize_hex( $input['ui_muted_color'] ?? $defaults['ui_muted_color'], $defaults['ui_muted_color'] );
		$out['ui_tick_color'] = $this->sanitize_hex( $input['ui_tick_color'] ?? $defaults['ui_tick_color'], $defaults['ui_tick_color'] );
		$out['ui_surface_bg'] = $this->sanitize_hex( $input['ui_surface_bg'] ?? $defaults['ui_surface_bg'], $defaults['ui_surface_bg'] );
		$out['ui_header_title'] = sanitize_text_field( $input['ui_header_title'] ?? $defaults['ui_header_title'] );
		$out['ui_header_tagline'] = sanitize_text_field( $input['ui_header_tagline'] ?? $defaults['ui_header_tagline'] );
		$out['ui_launcher_label'] = sanitize_text_field( $input['ui_launcher_label'] ?? $defaults['ui_launcher_label'] );
		$out['ui_icon_url'] = esc_url_raw( $input['ui_icon_url'] ?? '' );
		$ff = sanitize_text_field( $input['ui_font_family'] ?? $defaults['ui_font_family'] );
		$out['ui_font_family'] = $ff !== '' ? $ff : 'inherit';
		$out['ui_font_size'] = $this->sanitize_size( $input['ui_font_size'] ?? $defaults['ui_font_size'], $defaults['ui_font_size'], 12, 22 );
		$out['ui_headline_size'] = $this->sanitize_size( $input['ui_headline_size'] ?? $defaults['ui_headline_size'], $defaults['ui_headline_size'], 12, 28 );
		$out['ui_tagline_size'] = $this->sanitize_size( $input['ui_tagline_size'] ?? $defaults['ui_tagline_size'], $defaults['ui_tagline_size'], 10, 20 );
		$out['ui_timestamp_size'] = $this->sanitize_size( $input['ui_timestamp_size'] ?? $defaults['ui_timestamp_size'], $defaults['ui_timestamp_size'], 9, 16 );
		$out['ui_button_bg'] = $this->sanitize_hex( $input['ui_button_bg'] ?? $defaults['ui_button_bg'], $defaults['ui_button_bg'] );
		$out['ui_button_text'] = $this->sanitize_hex( $input['ui_button_text'] ?? $defaults['ui_button_text'], $defaults['ui_button_text'] );
		$out['ui_button_font_size'] = $this->sanitize_size( $input['ui_button_font_size'] ?? $defaults['ui_button_font_size'], $defaults['ui_button_font_size'], 10, 20 );
		$out['ui_button_radius'] = $this->sanitize_size( $input['ui_button_radius'] ?? $defaults['ui_button_radius'], $defaults['ui_button_radius'], 0, 30 );

		// Canva
		$out['canva_itinerary_enabled'] = ! empty( $input['canva_itinerary_enabled'] );
		$out['canva_itinerary_template_id'] = sanitize_text_field( $input['canva_itinerary_template_id'] ?? '' );
		$dt = sanitize_text_field( $input['canva_itinerary_design_type'] ?? 'doc' );
		$out['canva_itinerary_design_type'] = $dt ?: 'doc';

		delete_transient( 'bmtg_chatbot_site_context_v1' );
		delete_transient( 'bmtg_chatbot_site_context_v2' );
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( Activator::CAPABILITY ) ) wp_die( 'Forbidden' );
		$s = $this->chatbot->settings();
		$excluded = implode( ',', array_map( 'strval', (array) $s['excluded_pages'] ) );
		$knowledge = implode( "\n", array_map( 'strval', (array) $s['knowledge'] ) );
		$quick = implode( "\n", array_map( 'strval', (array) ( $s['quick_questions'] ?? array() ) ) );
		$opt = Chatbot::OPTION_KEY;
		?>
		<div class="wrap">
			<h1>BMTG Public Website Chatbot</h1>
			<p>Floating, BMTG-branded chatbot for public pages. Uses Groq (primary) and OpenRouter (fallback) via <a href="<?php echo esc_url( admin_url('admin.php?page=bmtg-integrations') ); ?>">Integrations Hub</a>. It answers on behalf of <strong>BM Travel Group (bmtravelgroup.com) only</strong> using published website/contact knowledge and does not promote other companies. Public context is isolated from Private Collection/guest data.</p>
			<p><strong>Meta webhooks (auto-answer):</strong> configure the <em>Meta (Facebook / WhatsApp)</em> connector in Integrations Hub, then set webhook callback URLs to <code><?php echo esc_html( rest_url('bmtg/v1/chatbot/webhook/facebook') ); ?></code> (Messenger) and <code><?php echo esc_html( rest_url('bmtg/v1/chatbot/webhook/whatsapp') ); ?></code> (WhatsApp Cloud) with the matching verify tokens.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'bmtg_chatbot_group' ); ?>

				<h2 class="title">General</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Enabled</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="1" <?php checked($s['enabled']); ?>> Enable chatbot</label></td></tr>
					<tr><th scope="row">Auto inject</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[auto_inject]" value="1" <?php checked($s['auto_inject']); ?>> Automatically show widget on public pages (or use shortcode <code>[bmtg_chatbot]</code>)</label></td></tr>
					<tr><th scope="row">Excluded page IDs</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[excluded_pages]" value="<?php echo esc_attr($excluded); ?>"><p class="description">Comma-separated page/post IDs.</p></td></tr>
					<tr><th scope="row">Greeting</th><td><textarea name="<?php echo esc_attr($opt); ?>[greeting]" rows="3" class="large-text"><?php echo esc_textarea($s['greeting']); ?></textarea></td></tr>
					<tr><th scope="row">Predefined quick questions (one per line)</th><td><textarea name="<?php echo esc_attr($opt); ?>[quick_questions]" rows="5" class="large-text"><?php echo esc_textarea($quick); ?></textarea><p class="description">Shown at chat start for easy one-tap questions (e.g. services, destinations, plan a journey, contact).</p></td></tr>
					<tr><th scope="row">Operating hours (optional, internal)</th><td><textarea name="<?php echo esc_attr($opt); ?>[operating_hours]" rows="2" class="large-text"><?php echo esc_textarea($s['operating_hours']); ?></textarea><p class="description">Not shown to visitors by default; kept for internal guidance.</p></td></tr>
					<tr><th scope="row">Fallback message</th><td><textarea name="<?php echo esc_attr($opt); ?>[fallback_message]" rows="2" class="large-text"><?php echo esc_textarea($s['fallback_message']); ?></textarea></td></tr>
					<tr><th scope="row">Handover message</th><td><textarea name="<?php echo esc_attr($opt); ?>[handover_message]" rows="2" class="large-text"><?php echo esc_textarea($s['handover_message']); ?></textarea></td></tr>
					<tr><th scope="row">Require explicit consent for contact capture</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[require_consent]" value="1" <?php checked($s['require_consent']); ?>> Required</label></td></tr>
					<tr><th scope="row">Retention (days)</th><td><input type="number" min="1" max="3650" name="<?php echo esc_attr($opt); ?>[retention_days]" value="<?php echo esc_attr($s['retention_days']); ?>"></td></tr>
				</table>

				<h2 class="title">Appearance &amp; Layout</h2>
				<p class="description">Predefined design settings for the chat widget (colors, typography, header, icon, buttons). Use <code>inherit</code> for font family to match your Kadence/site theme.</p>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Header background color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_header_bg]" value="<?php echo esc_attr($s['ui_header_bg']); ?>" class="regular-text" placeholder="#075e54"></td></tr>
					<tr><th scope="row">Header accent / button color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_header_accent]" value="<?php echo esc_attr($s['ui_header_accent']); ?>" class="regular-text" placeholder="#128c7e"></td></tr>
					<tr><th scope="row">Chat background color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_chat_bg]" value="<?php echo esc_attr($s['ui_chat_bg']); ?>" class="regular-text" placeholder="#ece5dd"></td></tr>
					<tr><th scope="row">Incoming bubble color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_incoming_bg]" value="<?php echo esc_attr($s['ui_incoming_bg']); ?>" class="regular-text" placeholder="#ffffff"></td></tr>
					<tr><th scope="row">Outgoing bubble color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_outgoing_bg]" value="<?php echo esc_attr($s['ui_outgoing_bg']); ?>" class="regular-text" placeholder="#d9fdd3"></td></tr>
					<tr><th scope="row">Accent color (launcher/ticks)</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_accent]" value="<?php echo esc_attr($s['ui_accent']); ?>" class="regular-text" placeholder="#25d366"></td></tr>
					<tr><th scope="row">Text color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_text_color]" value="<?php echo esc_attr($s['ui_text_color']); ?>" class="regular-text" placeholder="#111b21"></td></tr>
					<tr><th scope="row">Muted / timestamp color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_muted_color]" value="<?php echo esc_attr($s['ui_muted_color']); ?>" class="regular-text" placeholder="#667781"></td></tr>
					<tr><th scope="row">Read tick color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_tick_color]" value="<?php echo esc_attr($s['ui_tick_color']); ?>" class="regular-text" placeholder="#53bdeb"></td></tr>
					<tr><th scope="row">Surface background color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_surface_bg]" value="<?php echo esc_attr($s['ui_surface_bg']); ?>" class="regular-text" placeholder="#f0f2f5"></td></tr>
					<tr><th scope="row">Header title (headline)</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[ui_header_title]" value="<?php echo esc_attr($s['ui_header_title']); ?>"></td></tr>
					<tr><th scope="row">Header tagline</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[ui_header_tagline]" value="<?php echo esc_attr($s['ui_header_tagline']); ?>"></td></tr>
					<tr><th scope="row">Launcher label</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[ui_launcher_label]" value="<?php echo esc_attr($s['ui_launcher_label']); ?>"></td></tr>
					<tr><th scope="row">Icon image URL</th><td><input type="url" class="regular-text" name="<?php echo esc_attr($opt); ?>[ui_icon_url]" value="<?php echo esc_attr($s['ui_icon_url']); ?>" placeholder="https://..."><p class="description">Optional avatar/icon image shown in chat header. Leave blank for default icon.</p></td></tr>
					<tr><th scope="row">Font family</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[ui_font_family]" value="<?php echo esc_attr($s['ui_font_family']); ?>" placeholder="inherit"><p class="description">Use <code>inherit</code> to follow the Kadence/site theme font.</p></td></tr>
					<tr><th scope="row">Base font size (px)</th><td><input type="number" step="0.5" min="12" max="22" name="<?php echo esc_attr($opt); ?>[ui_font_size]" value="<?php echo esc_attr($s['ui_font_size']); ?>"></td></tr>
					<tr><th scope="row">Headline size (px)</th><td><input type="number" step="0.5" min="12" max="28" name="<?php echo esc_attr($opt); ?>[ui_headline_size]" value="<?php echo esc_attr($s['ui_headline_size']); ?>"></td></tr>
					<tr><th scope="row">Tagline size (px)</th><td><input type="number" step="0.5" min="10" max="20" name="<?php echo esc_attr($opt); ?>[ui_tagline_size]" value="<?php echo esc_attr($s['ui_tagline_size']); ?>"></td></tr>
					<tr><th scope="row">Timestamp font size (px)</th><td><input type="number" step="0.5" min="9" max="16" name="<?php echo esc_attr($opt); ?>[ui_timestamp_size]" value="<?php echo esc_attr($s['ui_timestamp_size']); ?>"></td></tr>
					<tr><th scope="row">Button background color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_button_bg]" value="<?php echo esc_attr($s['ui_button_bg']); ?>" class="regular-text" placeholder="#128c7e"></td></tr>
					<tr><th scope="row">Button text color</th><td><input type="text" name="<?php echo esc_attr($opt); ?>[ui_button_text]" value="<?php echo esc_attr($s['ui_button_text']); ?>" class="regular-text" placeholder="#ffffff"></td></tr>
					<tr><th scope="row">Button font size (px)</th><td><input type="number" step="0.5" min="10" max="20" name="<?php echo esc_attr($opt); ?>[ui_button_font_size]" value="<?php echo esc_attr($s['ui_button_font_size']); ?>"></td></tr>
					<tr><th scope="row">Button radius (px)</th><td><input type="number" step="1" min="0" max="30" name="<?php echo esc_attr($opt); ?>[ui_button_radius]" value="<?php echo esc_attr($s['ui_button_radius']); ?>"></td></tr>
				</table>

				<h2 class="title">Contact &amp; WhatsApp</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">WhatsApp Business number</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[whatsapp_number]" value="<?php echo esc_attr($s['whatsapp_number'] ?? ''); ?>" placeholder="e.g. 919876543210"><p class="description">International format, digits only. Enables "Continue on WhatsApp" and [bmtg_whatsapp] shortcode.</p></td></tr>
					<tr><th scope="row">WhatsApp prefilled message</th><td><textarea name="<?php echo esc_attr($opt); ?>[whatsapp_prefill]" rows="2" class="large-text"><?php echo esc_textarea($s['whatsapp_prefill'] ?? ''); ?></textarea></td></tr>
					<tr><th scope="row">Business name</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[business_name]" value="<?php echo esc_attr($s['business_name'] ?? ''); ?>"></td></tr>
					<tr><th scope="row">Contact email</th><td><input type="email" class="regular-text" name="<?php echo esc_attr($opt); ?>[contact_email]" value="<?php echo esc_attr($s['contact_email'] ?? ''); ?>"><p class="description">Shared with the chatbot so it can provide contact details to visitors.</p></td></tr>
					<tr><th scope="row">Contact phone</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[contact_phone]" value="<?php echo esc_attr($s['contact_phone'] ?? ''); ?>"></td></tr>
					<tr><th scope="row">Business address (for itineraries)</th><td><textarea name="<?php echo esc_attr($opt); ?>[business_address]" rows="3" class="large-text"><?php echo esc_textarea($s['business_address'] ?? ''); ?></textarea></td></tr>
					<tr><th scope="row">Logo URL (for itineraries)</th><td><input type="url" class="regular-text" name="<?php echo esc_attr($opt); ?>[logo_url]" value="<?php echo esc_attr($s['logo_url'] ?? ''); ?>"></td></tr>
				</table>

				<h2 class="title">AI &amp; Knowledge</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Groq model</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[model_groq]" value="<?php echo esc_attr($s['model_groq']); ?>"></td></tr>
					<tr><th scope="row">OpenRouter model</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[model_openrouter]" value="<?php echo esc_attr($s['model_openrouter']); ?>"></td></tr>
					<tr><th scope="row">Approved knowledge (one item per line)</th><td><textarea name="<?php echo esc_attr($opt); ?>[knowledge]" rows="8" class="large-text"><?php echo esc_textarea($knowledge); ?></textarea><p class="description">Published journeys/services/destinations only. Do not include private or pricing data.</p></td></tr>
				</table>

				<h2 class="title">Canva Itinerary</h2>
				<p class="description">Connect Canva in <a href="<?php echo esc_url( admin_url('options-general.php?page=bmtg-integrations') ); ?>">Integrations Hub</a>. When enabled, the bot can create a Canva itinerary design (from a brand template if provided) when a visitor asks for an itinerary/plan. Use a free/brand template ID available in your Canva account for best results.</p>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Enable Canva itinerary in chat</th><td><label><input type="checkbox" name="<?php echo esc_attr($opt); ?>[canva_itinerary_enabled]" value="1" <?php checked(!empty($s['canva_itinerary_enabled'])); ?>> Allow bot to create Canva itinerary designs on request</label></td></tr>
					<tr><th scope="row">Canva brand template ID</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[canva_itinerary_template_id]" value="<?php echo esc_attr($s['canva_itinerary_template_id'] ?? ''); ?>" placeholder="e.g. EAF..."><p class="description">Optional brand template ID to create itinerary from (free template in your Canva/brand). Leave blank to create a blank design.</p></td></tr>
					<tr><th scope="row">Fallback design type</th><td><input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[canva_itinerary_design_type]" value="<?php echo esc_attr($s['canva_itinerary_design_type'] ?? 'doc'); ?>" placeholder="doc"><p class="description">Canva preset name when not using a template (e.g. doc, presentation).</p></td></tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
