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
		add_options_page(
			__( 'BMTG Chatbot', 'bmtg-business-manager' ),
			__( 'BMTG Chatbot', 'bmtg-business-manager' ),
			Activator::CAPABILITY,
			'bmtg-chatbot',
			array( $this, 'render' )
		);
	}

	public function register_settings(): void {
		register_setting( 'bmtg_chatbot_group', Chatbot::OPTION_KEY, array( $this, 'sanitize' ) );
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
		$out['whatsapp_number'] = sanitize_text_field( $input['whatsapp_number'] ?? '' );
		$out['whatsapp_prefill'] = sanitize_textarea_field( $input['whatsapp_prefill'] ?? $defaults['whatsapp_prefill'] );
		$out['business_name'] = sanitize_text_field( $input['business_name'] ?? $defaults['business_name'] );
		$out['business_address'] = sanitize_textarea_field( $input['business_address'] ?? '' );
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
		return $out;
	}

	public function render(): void {
		if ( ! current_user_can( Activator::CAPABILITY ) ) wp_die( 'Forbidden' );
		$s = $this->chatbot->settings();
		$excluded = implode( ',', array_map( 'strval', (array) $s['excluded_pages'] ) );
		$knowledge = implode( "\n", array_map( 'strval', (array) $s['knowledge'] ) );
		?>
		<div class="wrap">
			<h1>BMTG Public Website Chatbot</h1>
			<p>Floating, BMTG-branded chatbot for public pages. Uses Groq (primary) and OpenRouter (fallback) via <a href="<?php echo esc_url( admin_url('options-general.php?page=bmtg-integrations') ); ?>">Integrations Hub</a>. Public context is isolated from Private Collection/guest data.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'bmtg_chatbot_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Enabled</th><td><label><input type="checkbox" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[enabled]" value="1" <?php checked($s['enabled']); ?>> Enable chatbot</label></td></tr>
					<tr><th scope="row">Auto inject</th><td><label><input type="checkbox" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[auto_inject]" value="1" <?php checked($s['auto_inject']); ?>> Automatically show widget on public pages (or use shortcode <code>[bmtg_chatbot]</code>)</label></td></tr>
					<tr><th scope="row">Excluded page IDs</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[excluded_pages]" value="<?php echo esc_attr($excluded); ?>"><p class="description">Comma-separated page/post IDs.</p></td></tr>
					<tr><th scope="row">Greeting</th><td><textarea name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[greeting]" rows="3" class="large-text"><?php echo esc_textarea($s['greeting']); ?></textarea></td></tr>
					<tr><th scope="row">Operating hours / guidance</th><td><textarea name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[operating_hours]" rows="2" class="large-text"><?php echo esc_textarea($s['operating_hours']); ?></textarea></td></tr>
					<tr><th scope="row">Fallback message</th><td><textarea name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[fallback_message]" rows="2" class="large-text"><?php echo esc_textarea($s['fallback_message']); ?></textarea></td></tr>
					<tr><th scope="row">Handover message</th><td><textarea name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[handover_message]" rows="2" class="large-text"><?php echo esc_textarea($s['handover_message']); ?></textarea></td></tr>
					<tr><th scope="row">WhatsApp Business number</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[whatsapp_number]" value="<?php echo esc_attr($s['whatsapp_number'] ?? ''); ?>" placeholder="e.g. 919876543210"><p class="description">International format, digits only. Enables "Continue on WhatsApp" (mobile/desktop) and [bmtg_whatsapp] shortcode.</p></td></tr>
					<tr><th scope="row">WhatsApp prefilled message</th><td><textarea name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[whatsapp_prefill]" rows="2" class="large-text"><?php echo esc_textarea($s['whatsapp_prefill'] ?? ''); ?></textarea></td></tr>
					<tr><th scope="row">Business name</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[business_name]" value="<?php echo esc_attr($s['business_name'] ?? ''); ?>"></td></tr>
					<tr><th scope="row">Business address (for itineraries)</th><td><textarea name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[business_address]" rows="3" class="large-text"><?php echo esc_textarea($s['business_address'] ?? ''); ?></textarea></td></tr>
					<tr><th scope="row">Logo URL (for itineraries)</th><td><input type="url" class="regular-text" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[logo_url]" value="<?php echo esc_attr($s['logo_url'] ?? ''); ?>"></td></tr>
					<tr><th scope="row">Groq model</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[model_groq]" value="<?php echo esc_attr($s['model_groq']); ?>"></td></tr>
					<tr><th scope="row">OpenRouter model</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[model_openrouter]" value="<?php echo esc_attr($s['model_openrouter']); ?>"></td></tr>
					<tr><th scope="row">Approved knowledge (one item per line)</th><td><textarea name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[knowledge]" rows="8" class="large-text"><?php echo esc_textarea($knowledge); ?></textarea><p class="description">Published journeys/services/destinations only. Do not include private or pricing data.</p></td></tr>
					<tr><th scope="row">Require explicit consent for contact capture</th><td><label><input type="checkbox" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[require_consent]" value="1" <?php checked($s['require_consent']); ?>> Required</label></td></tr>
					<tr><th scope="row">Retention (days)</th><td><input type="number" min="1" max="3650" name="<?php echo esc_attr(Chatbot::OPTION_KEY); ?>[retention_days]" value="<?php echo esc_attr($s['retention_days']); ?>"></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
