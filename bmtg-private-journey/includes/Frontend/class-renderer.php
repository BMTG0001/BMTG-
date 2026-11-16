<?php
namespace BMTG\PrivateJourney\Frontend;

use BMTG\PrivateJourney\Auth\Auth;
use BMTG\PrivateJourney\Catalogue\Catalogue;
use BMTG\PrivateJourney\Catalogue\Validator as LineValidator;
use BMTG\PrivateJourney\Clients\Clients;
use BMTG\PrivateJourney\Core\Brand;
use BMTG\PrivateJourney\Core\Pages;
use BMTG\PrivateJourney\Core\Schema;
use BMTG\PrivateJourney\Documents\Documents;
use BMTG\PrivateJourney\Enquiry\Enquiry;
use BMTG\PrivateJourney\Enquiry\Quotation;
use BMTG\PrivateJourney\Journey\Basket;
use BMTG\PrivateJourney\Journey\Validator as JourneyValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend renderer for every [bmtg_pj_*] shortcode.
 * All output is escaped; no business logic runs in Elementor HTML.
 */
class Renderer {
	public function render( string $method, array $atts = array(), string $content = '' ): string {
		$method = preg_replace( '/[^a-z_]/', '', $method );
		if ( ! method_exists( $this, $method ) ) {
			return '';
		}
		return (string) $this->{$method}( $atts );
	}

	/* ---------------------------------------------------------------------
	 * Shared partials
	 * ------------------------------------------------------------------ */

	private function form_open( string $action, string $class = '', array $hidden = array() ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="<?php echo esc_attr( $class ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php wp_nonce_field( $action, $action . '_nonce' ); ?>
			<?php foreach ( $hidden as $name => $value ) : ?>
				<?php if ( is_array( $value ) ) : ?>
					<?php foreach ( $value as $single ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $single ); ?>" />
					<?php endforeach; ?>
				<?php else : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endif; ?>
			<?php endforeach; ?>
		<?php
	}

	private function form_close( string $label = '', string $button_class = 'pj-btn pj-btn--primary' ): void {
		if ( $label ) {
			?>
			<button type="submit" class="<?php echo esc_attr( $button_class ); ?>"><?php echo esc_html( $label ); ?></button>
			<?php
		}
		echo '</form>';
	}

	private function notices(): string {
		$args = array( 'pj_error', 'pj_saved', 'pj_added', 'pj_removed', 'pj_updated', 'pj_reference', 'pj_response', 'pj_warning' );
		$out  = '';
		$map  = array(
			'pj_saved'     => __( 'Your journey has been saved.', 'bmtg-private-journey' ),
			'pj_added'     => __( 'Service added to your journey basket.', 'bmtg-private-journey' ),
			'pj_removed'   => __( 'Service removed from your journey basket.', 'bmtg-private-journey' ),
			'pj_updated'   => __( 'Your journey has been updated.', 'bmtg-private-journey' ),
			'pj_reference' => __( 'Thank you. Your request has been received and our designers will be in touch.', 'bmtg-private-journey' ),
		);
		foreach ( $map as $key => $message ) {
			if ( isset( $_GET[ $key ] ) ) {
				$out .= '<div class="pj-notice pj-notice--success" role="status">' . esc_html( $message ) . '</div>';
			}
		}
		if ( isset( $_GET['pj_error'] ) ) {
			$out .= '<div class="pj-notice pj-notice--error" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['pj_error'] ) ) ) . '</div>';
		}
		if ( isset( $_GET['pj_warning'] ) ) {
			$out .= '<div class="pj-notice pj-notice--warning" role="status">' . esc_html( sprintf( __( 'Some details need attention before this journey can be priced. Please review the notes in your basket.', 'bmtg-private-journey' ) ) ) . '</div>';
		}
		if ( isset( $_GET['pj_response'] ) ) {
			$response = sanitize_key( wp_unslash( $_GET['pj_response'] ) );
			$message  = 'accepted' === $response ? __( 'Thank you. Your acceptance has been recorded; our team will now confirm supplier availability.', 'bmtg-private-journey' ) : __( 'Your response has been recorded.', 'bmtg-private-journey' );
			$out     .= '<div class="pj-notice pj-notice--success" role="status">' . esc_html( $message ) . '</div>';
		}
		return $out;
	}

	private function brand_header( array $atts = array() ): string {
		$logo = Brand::logo_url();
		$name = Brand::company_name();
		ob_start();
		?>
		<header class="pj-header">
			<div class="pj-header__inner">
				<a class="pj-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( $name ); ?>">
					<?php if ( $logo ) : ?>
						<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $name ); ?>" width="<?php echo esc_attr( Brand::logo_width() ); ?>" />
					<?php else : ?>
						<span class="pj-brand__text"><?php echo esc_html( $name ); ?></span>
					<?php endif; ?>
				</a>
				<?php if ( ! isset( $atts['nav'] ) || '0' !== (string) $atts['nav'] ) : ?>
					<button class="pj-nav-toggle" aria-expanded="false" aria-controls="pj-primary-nav"><?php esc_html_e( 'Menu', 'bmtg-private-journey' ); ?></button>
					<?php echo wp_kses_post( $this->navigation( array() ) ); ?>
				<?php endif; ?>
			</div>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	private function footer( array $atts = array() ): string {
		$logo   = Brand::logo_url();
		$social = Brand::social_links();
		$icons  = Brand::social_icons();
		ob_start();
		?>
		<footer class="pj-footer">
			<div class="pj-footer__inner">
				<div class="pj-footer__brand">
					<?php if ( $logo ) : ?>
						<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( Brand::company_name() ); ?>" width="<?php echo esc_attr( Brand::logo_width() ); ?>" />
					<?php else : ?>
						<span class="pj-brand__text"><?php echo esc_html( Brand::company_name() ); ?></span>
					<?php endif; ?>
					<?php if ( Brand::company( 'address_correspondence' ) ) : ?>
						<p class="pj-footer__address"><?php echo nl2br( esc_html( Brand::company( 'address_correspondence' ) ) ); ?></p>
					<?php endif; ?>
				</div>
				<div class="pj-footer__links">
					<?php foreach ( array( 'catalogue', 'my_enquiries', 'my_quotations', 'concierge' ) as $key ) : ?>
						<?php $meta = Pages::get( $key ); if ( ! $meta['page_id'] ) : continue; endif; ?>
						<a href="<?php echo esc_url( Pages::url( $key ) ); ?>"><?php echo esc_html( $meta['label'] ); ?></a>
					<?php endforeach; ?>
				</div>
				<?php if ( $social ) : ?>
					<div class="pj-footer__social">
						<?php foreach ( $social as $key => $url ) : ?>
							<a href="<?php echo esc_url( $url ); ?>" <?php echo 'email' === $key || 'phone' === $key ? '' : 'target="_blank" rel="noopener"'; ?> aria-label="<?php echo esc_attr( ucfirst( $key ) ); ?>">
								<?php echo isset( $icons[ $key ] ) ? wp_kses( $icons[ $key ], array( 'svg' => array( 'viewBox' => array(), 'aria-hidden' => array() ), 'path' => array( 'd' => array(), 'fill' => array(), 'stroke' => array(), 'stroke-width' => array() ) ) ) : esc_html( ucfirst( $key ) ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="pj-footer__legal">
				<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( Brand::company_name() ); ?></span>
				<?php if ( Brand::company( 'registration' ) ) : ?>
					<span><?php echo esc_html( Brand::company( 'registration' ) ); ?></span>
				<?php endif; ?>
			</div>
		</footer>
		<?php
		return (string) ob_get_clean();
	}

	private function navigation( array $atts ): string {
		$keys = array( 'catalogue', 'basket', 'my_enquiries', 'my_quotations', 'my_journeys', 'profile', 'concierge' );
		$out  = '<nav class="pj-nav" id="pj-primary-nav" aria-label="' . esc_attr__( 'Private Journey', 'bmtg-private-journey' ) . '"><ul>';
		foreach ( $keys as $key ) {
			$meta = Pages::get( $key );
			if ( ! $meta['page_id'] ) {
				continue;
			}
			$class = ( is_page() && get_queried_object_id() === (int) $meta['page_id'] ) ? ' class="is-active"' : '';
			$out  .= '<li' . $class . '><a href="' . esc_url( Pages::url( $key ) ) . '">' . esc_html( $meta['label'] ) . '</a></li>';
		}
		if ( is_user_logged_in() ) {
			$logout = wp_logout_url( home_url( '/' ) );
			$out   .= '<li class="pj-nav__logout"><a href="' . esc_url( $logout ) . '">' . esc_html__( 'Sign out', 'bmtg-private-journey' ) . '</a></li>';
		}
		$out .= '</ul></nav>';
		return $out;
	}

	private function wrap( string $body, string $class = '' ): string {
		return '<div class="pj-shell ' . esc_attr( $class ) . '">' . $this->notices() . $body . '</div>';
	}

	private function status_label( string $status ): string {
		$labels = array(
			'new'                          => __( 'New enquiry', 'bmtg-private-journey' ),
			'review'                       => __( 'In review', 'bmtg-private-journey' ),
			'supplier_check'               => __( 'Supplier availability', 'bmtg-private-journey' ),
			'pricing'                      => __( 'Pricing', 'bmtg-private-journey' ),
			'internal_approval'            => __( 'Internal approval', 'bmtg-private-journey' ),
			'proposal_issued'              => __( 'Proposal issued', 'bmtg-private-journey' ),
			'client_response'              => __( 'Awaiting your response', 'bmtg-private-journey' ),
			'accepted'                     => __( 'Accepted', 'bmtg-private-journey' ),
			'declined'                     => __( 'Declined', 'bmtg-private-journey' ),
			'reservation'                  => __( 'Reservation in progress', 'bmtg-private-journey' ),
			'confirmed'                    => __( 'Confirmed', 'bmtg-private-journey' ),
			'closed'                       => __( 'Closed', 'bmtg-private-journey' ),
			'pending_supplier_confirmation' => __( 'Awaiting supplier confirmation', 'bmtg-private-journey' ),
			'in_reservation'               => __( 'Reservation in progress', 'bmtg-private-journey' ),
			'completed'                    => __( 'Completed', 'bmtg-private-journey' ),
			'cancelled'                    => __( 'Cancelled', 'bmtg-private-journey' ),
		);
		return $labels[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}

	private function service_fields( string $type, array $options, bool $compact = false ): string {
		$labels   = LineValidator::labels();
		$required = LineValidator::fields_for( $type );
		ob_start();
		?>
		<div class="pj-fields">
			<?php if ( $options ) : ?>
				<label class="pj-field">
					<span><?php esc_html_e( 'Service option', 'bmtg-private-journey' ); ?></span>
					<select name="option_id">
						<option value="0"><?php esc_html_e( 'Standard selection', 'bmtg-private-journey' ); ?></option>
						<?php foreach ( $options as $option ) : ?>
							<option value="<?php echo esc_attr( $option['id'] ); ?>">
								<?php echo esc_html( $option['label'] . ( 'fixed' === $option['price_type'] && null !== $option['price'] ? ' — ' . $option['price'] . ' ' . $option['currency'] : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>

			<?php if ( in_array( 'start_date', $required, true ) ) : ?>
				<label class="pj-field">
					<span><?php echo esc_html( $labels['start_date'] ); ?></span>
					<input type="date" name="start_date" required />
				</label>
			<?php endif; ?>

			<?php if ( in_array( 'end_date', $required, true ) ) : ?>
				<label class="pj-field">
					<span><?php echo esc_html( $labels['end_date'] ); ?></span>
					<input type="date" name="end_date" />
				</label>
			<?php endif; ?>

			<?php if ( in_array( 'rooms', $required, true ) ) : ?>
				<label class="pj-field">
					<span><?php echo esc_html( $labels['rooms'] ); ?></span>
					<input type="number" min="1" max="20" name="rooms" value="1" required />
				</label>
			<?php endif; ?>

			<label class="pj-field">
				<span><?php echo esc_html( $labels['adults'] ); ?></span>
				<input type="number" min="1" max="100" name="adults" value="2" required />
			</label>

			<label class="pj-field">
				<span><?php echo esc_html( $labels['children'] ); ?></span>
				<input type="number" min="0" max="50" name="children" value="0" />
			</label>

			<?php if ( in_array( 'pickup', $required, true ) ) : ?>
				<label class="pj-field">
					<span><?php echo esc_html( $labels['pickup'] ); ?></span>
					<input type="text" name="pickup" />
				</label>
			<?php endif; ?>

			<?php if ( in_array( 'dropoff', $required, true ) ) : ?>
				<label class="pj-field">
					<span><?php echo esc_html( $labels['dropoff'] ); ?></span>
					<input type="text" name="dropoff" />
				</label>
			<?php endif; ?>

			<?php if ( in_array( 'flight_number', $required, true ) ) : ?>
				<label class="pj-field">
					<span><?php echo esc_html( $labels['flight_number'] ); ?></span>
					<input type="text" name="flight_number" placeholder="BA 249" />
				</label>
			<?php endif; ?>

			<?php if ( in_array( $type, array( 'restaurant', 'experience', 'train', 'safari', 'heritage', 'wellness' ), true ) ) : ?>
				<label class="pj-field">
					<span><?php esc_html_e( 'Preferred time', 'bmtg-private-journey' ); ?></span>
					<input type="time" name="time" />
				</label>
			<?php endif; ?>

			<?php if ( ! $compact ) : ?>
				<label class="pj-field">
					<span><?php esc_html_e( 'Quantity', 'bmtg-private-journey' ); ?></span>
					<input type="number" min="1" max="20" name="quantity" value="1" />
				</label>
				<label class="pj-field pj-field--wide">
					<span><?php esc_html_e( 'Special requirements', 'bmtg-private-journey' ); ?></span>
					<textarea name="notes" rows="3"></textarea>
				</label>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function card( array $item ): string {
		$link = add_query_arg( array( 'pj_item' => $item['slug'] ), Pages::url( 'item_detail' ) );
		ob_start();
		?>
		<article class="pj-card">
			<a class="pj-card__media" href="<?php echo esc_url( $link ); ?>">
				<?php if ( ! empty( $item['cover_url'] ) ) : ?>
					<img src="<?php echo esc_url( $item['cover_url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" loading="lazy" />
				<?php else : ?>
					<span class="pj-card__placeholder"><?php echo esc_html( $item['title'] ); ?></span>
				<?php endif; ?>
			</a>
			<div class="pj-card__body">
				<p class="pj-eyebrow"><?php echo esc_html( $item['destination'] ?: $item['country'] ?: ucfirst( $item['item_type'] ) ); ?></p>
				<h3><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $item['title'] ); ?></a></h3>
				<?php if ( $item['summary'] ) : ?>
					<p class="pj-card__summary"><?php echo esc_html( wp_trim_words( $item['summary'], 22 ) ); ?></p>
				<?php endif; ?>
				<div class="pj-card__foot">
					<?php echo Catalogue::instance()->price_html( $item['price'], $item['currency'], $item['price_type'] ); ?>
					<a class="pj-link" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Discover', 'bmtg-private-journey' ); ?></a>
				</div>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	private function grid( array $items, string $class = '' ): string {
		if ( ! $items ) {
			return '<div class="pj-empty"><p>' . esc_html__( 'No services have been published in this collection yet.', 'bmtg-private-journey' ) . '</p></div>';
		}
		$out = '<div class="pj-grid ' . esc_attr( $class ) . '">';
		foreach ( $items as $item ) {
			$out .= $this->card( $item );
		}
		return $out . '</div>';
	}

	private function pagination( int $page, int $pages, array $args ): string {
		if ( $pages < 2 ) {
			return '';
		}
		$base = Pages::url( 'catalogue' );
		$out  = '<nav class="pj-pagination" aria-label="' . esc_attr__( 'Pagination', 'bmtg-private-journey' ) . '">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url  = add_query_arg( array_merge( $args, array( 'pj_page' => $i ) ), $base );
			$out .= '<a class="' . ( $i === $page ? 'is-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a>';
		}
		return $out . '</nav>';
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------ */

	private function welcome( array $atts ): string {
		ob_start();
		?>
		<section class="pj-hero">
			<div class="pj-hero__overlay"></div>
			<div class="pj-hero__content">
				<p class="pj-eyebrow pj-eyebrow--light"><?php echo esc_html( Brand::company_name() ); ?></p>
				<h1><?php esc_html_e( 'The Art of Journey', 'bmtg-private-journey' ); ?></h1>
				<p class="pj-hero__intro"><?php esc_html_e( 'A private collection of remarkable places, residences, tables and experiences — composed into one journey, entirely around you.', 'bmtg-private-journey' ); ?></p>
				<div class="pj-hero__actions">
					<a class="pj-btn pj-btn--primary" href="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>"><?php esc_html_e( 'Discover the Private Collection', 'bmtg-private-journey' ); ?></a>
					<?php if ( ! is_user_logged_in() ) : ?>
						<a class="pj-btn pj-btn--ghost" href="<?php echo esc_url( Pages::url( 'login' ) ); ?>"><?php esc_html_e( 'Sign in', 'bmtg-private-journey' ); ?></a>
					<?php else : ?>
						<a class="pj-btn pj-btn--ghost" href="<?php echo esc_url( Pages::url( 'dashboard' ) ); ?>"><?php esc_html_e( 'My Private Journey', 'bmtg-private-journey' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php if ( Brand::company( 'description' ) ) : ?>
			<section class="pj-section pj-welcome__intro">
				<p><?php echo esc_html( Brand::company( 'description' ) ); ?></p>
			</section>
		<?php endif; ?>
		<section class="pj-section">
			<h2><?php esc_html_e( 'Compose your journey', 'bmtg-private-journey' ); ?></h2>
			<div class="pj-steps">
				<div><span>01</span><h3><?php esc_html_e( 'Discover', 'bmtg-private-journey' ); ?></h3><p><?php esc_html_e( 'Explore hotels, restaurants, transport, rail, airport assistance and experiences.', 'bmtg-private-journey' ); ?></p></div>
				<div><span>02</span><h3><?php esc_html_e( 'Compose', 'bmtg-private-journey' ); ?></h3><p><?php esc_html_e( 'Combine services into a journey basket with your dates and requirements.', 'bmtg-private-journey' ); ?></p></div>
				<div><span>03</span><h3><?php esc_html_e( 'Receive', 'bmtg-private-journey' ); ?></h3><p><?php esc_html_e( 'Our designers prepare one considered, personalised quotation.', 'bmtg-private-journey' ); ?></p></div>
			</div>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--welcome' );
	}

	private function login( array $atts ): string {
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
		ob_start();
		?>
		<section class="pj-auth">
			<div class="pj-auth__card">
				<p class="pj-eyebrow"><?php esc_html_e( 'Private Collection', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'Welcome back', 'bmtg-private-journey' ); ?></h1>
				<?php $this->form_open( 'bmtg_pj_login', 'pj-form' ); ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>" />
					<label class="pj-field"><span><?php esc_html_e( 'Email', 'bmtg-private-journey' ); ?></span><input type="email" name="log" autocomplete="email" required /></label>
					<label class="pj-field"><span><?php esc_html_e( 'Password', 'bmtg-private-journey' ); ?></span><input type="password" name="pwd" autocomplete="current-password" required /></label>
					<label class="pj-check"><input type="checkbox" name="remember" value="1" /> <?php esc_html_e( 'Keep me signed in', 'bmtg-private-journey' ); ?></label>
					<?php $this->form_close( __( 'Sign in', 'bmtg-private-journey' ) ); ?>
				<?php $this->google_button(); ?>
				<p class="pj-auth__switch">
					<?php if ( (int) \BMTG\PrivateJourney\Core\Settings::get( 'auth', 'registration_enable', 1 ) ) : ?>
						<a href="<?php echo esc_url( Pages::url( 'registration' ) ); ?>"><?php esc_html_e( 'Create your private profile', 'bmtg-private-journey' ); ?></a>
					<?php endif; ?>
				</p>
				<p class="pj-auth__note"><?php esc_html_e( 'You can explore the public collection without signing in. Sign in to save selections and submit a quotation request.', 'bmtg-private-journey' ); ?></p>
			</div>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--auth' );
	}

	private function registration( array $atts ): string {
		ob_start();
		?>
		<section class="pj-auth">
			<div class="pj-auth__card pj-auth__card--wide">
				<p class="pj-eyebrow"><?php esc_html_e( 'Private Collection', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'Create your profile', 'bmtg-private-journey' ); ?></h1>
				<?php $this->form_open( 'bmtg_pj_register', 'pj-form' ); ?>
					<div class="pj-field-row">
						<label class="pj-field"><span><?php esc_html_e( 'First name', 'bmtg-private-journey' ); ?></span><input type="text" name="first_name" required /></label>
						<label class="pj-field"><span><?php esc_html_e( 'Last name', 'bmtg-private-journey' ); ?></span><input type="text" name="last_name" required /></label>
					</div>
					<label class="pj-field"><span><?php esc_html_e( 'Email', 'bmtg-private-journey' ); ?></span><input type="email" name="email" autocomplete="email" required /></label>
					<label class="pj-field"><span><?php esc_html_e( 'Mobile', 'bmtg-private-journey' ); ?></span><input type="tel" name="phone" autocomplete="tel" /></label>
					<div class="pj-field-row">
						<label class="pj-field"><span><?php esc_html_e( 'Password', 'bmtg-private-journey' ); ?></span><input type="password" name="password" minlength="10" autocomplete="new-password" required /></label>
						<label class="pj-field"><span><?php esc_html_e( 'Confirm password', 'bmtg-private-journey' ); ?></span><input type="password" name="password_confirm" minlength="10" autocomplete="new-password" required /></label>
					</div>
					<label class="pj-check"><input type="checkbox" name="consent" value="1" required /> <?php esc_html_e( 'I agree to the privacy notice and to being contacted about my enquiry.', 'bmtg-private-journey' ); ?></label>
					<?php $this->form_close( __( 'Create profile', 'bmtg-private-journey' ) ); ?>
				<?php $this->google_button(); ?>
				<p class="pj-auth__switch"><a href="<?php echo esc_url( Pages::url( 'login' ) ); ?>"><?php esc_html_e( 'Already registered? Sign in', 'bmtg-private-journey' ); ?></a></p>
			</div>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--auth' );
	}

	private function google_button(): void {
		if ( ! Auth::google_enabled() ) {
			return;
		}
		?>
		<div class="pj-google">
			<div class="pj-google__divider"><span><?php esc_html_e( 'or', 'bmtg-private-journey' ); ?></span></div>
			<div id="bmtg-pj-google-btn" data-pj-google></div>
			<noscript><p class="pj-auth__note"><?php esc_html_e( 'Google sign-in requires JavaScript. Please use email and password.', 'bmtg-private-journey' ); ?></p></noscript>
		</div>
		<?php
	}

	private function first_login( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return $this->wrap( '<div class="pj-empty"><p>' . sprintf( '<a href="%s">%s</a>', esc_url( Pages::url( 'login' ) ), esc_html__( 'Please sign in to continue.', 'bmtg-private-journey' ) ) . '</p></div>' );
		}
		$client = Clients::instance()->ensure_for_user( get_current_user_id() );
		$prefs  = array(
			'destinations' => __( 'Preferred destinations', 'bmtg-private-journey' ),
			'hotels'       => __( 'Hotels & resorts', 'bmtg-private-journey' ),
			'dining'       => __( 'Dining', 'bmtg-private-journey' ),
			'experiences'  => __( 'Experiences', 'bmtg-private-journey' ),
			'rail'         => __( 'Rail journeys', 'bmtg-private-journey' ),
			'wellness'     => __( 'Wellness', 'bmtg-private-journey' ),
		);
		ob_start();
		?>
		<section class="pj-auth">
			<div class="pj-auth__card pj-auth__card--wide">
				<p class="pj-eyebrow"><?php esc_html_e( 'Welcome', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'A few details, and your journey begins.', 'bmtg-private-journey' ); ?></h1>
				<?php $this->form_open( 'bmtg_pj_onboard', 'pj-form' ); ?>
					<div class="pj-field-row">
						<label class="pj-field"><span><?php esc_html_e( 'First name', 'bmtg-private-journey' ); ?></span><input type="text" name="first_name" value="<?php echo esc_attr( $client['first_name'] ?? '' ); ?>" required /></label>
						<label class="pj-field"><span><?php esc_html_e( 'Last name', 'bmtg-private-journey' ); ?></span><input type="text" name="last_name" value="<?php echo esc_attr( $client['last_name'] ?? '' ); ?>" required /></label>
					</div>
					<label class="pj-field"><span><?php esc_html_e( 'Email', 'bmtg-private-journey' ); ?></span><input type="email" name="email" value="<?php echo esc_attr( $client['email'] ?? '' ); ?>" required /></label>
					<label class="pj-field"><span><?php esc_html_e( 'Mobile number', 'bmtg-private-journey' ); ?></span><input type="tel" name="phone" value="<?php echo esc_attr( $client['phone'] ?? '' ); ?>" /></label>
					<fieldset class="pj-fieldset">
						<legend><?php esc_html_e( 'Travel preferences (optional)', 'bmtg-private-journey' ); ?></legend>
						<div class="pj-checks">
							<?php foreach ( $prefs as $key => $label ) : ?>
								<label class="pj-check"><input type="checkbox" name="preferences[<?php echo esc_attr( $key ); ?>]" value="1" /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<label class="pj-check"><input type="checkbox" name="consent" value="1" required /> <?php esc_html_e( 'I have read the privacy notice and agree to be contacted about my journey.', 'bmtg-private-journey' ); ?></label>
					<?php $this->form_close( __( 'Save and continue', 'bmtg-private-journey' ) ); ?>
				<p class="pj-auth__note"><?php esc_html_e( 'We never ask for information you have already provided.', 'bmtg-private-journey' ); ?></p>
			</div>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--auth' );
	}

	private function dashboard( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return $this->wrap(
				'<div class="pj-empty pj-empty--auth"><h2>' . esc_html__( 'Sign in to your private account', 'bmtg-private-journey' ) . '</h2><p>' . esc_html__( 'Your journey basket and quotations are waiting.', 'bmtg-private-journey' ) . '</p><a class="pj-btn pj-btn--primary" href="' . esc_url( Pages::url( 'login' ) ) . '">' . esc_html__( 'Sign in', 'bmtg-private-journey' ) . '</a></div>'
			);
		}
		$client = Clients::instance()->ensure_for_user( get_current_user_id() );
		if ( ! ( new Auth() )->is_onboarded( get_current_user_id() ) ) {
			return $this->first_login( $atts );
		}

		$enquiries = Enquiry::instance()->client_enquiries( (int) ( $client['id'] ?? 0 ) );
		$quotes    = array();
		foreach ( $enquiries as $enquiry ) {
			$q = Quotation::instance()->latest_issued( (int) $enquiry['id'] );
			if ( $q ) {
				$quotes[] = $q;
			}
		}

		ob_start();
		?>
		<section class="pj-dash">
			<div class="pj-dash__head">
				<p class="pj-eyebrow"><?php echo esc_html( gmdate( 'l, j F Y' ) ); ?></p>
				<h1><?php echo esc_html( sprintf( __( 'Welcome, %s.', 'bmtg-private-journey' ), $client['first_name'] ?: __( ' traveller', 'bmtg-private-journey' ) ) ); ?></h1>
				<p><?php esc_html_e( 'Compose, refine and follow every detail of your private journey.', 'bmtg-private-journey' ); ?></p>
			</div>
			<div class="pj-dash__stats">
				<a href="<?php echo esc_url( Pages::url( 'basket' ) ); ?>"><span><?php echo esc_html( Basket::instance()->count() ); ?></span><small><?php esc_html_e( 'Services in your basket', 'bmtg-private-journey' ); ?></small></a>
				<a href="<?php echo esc_url( Pages::url( 'my_enquiries' ) ); ?>"><span><?php echo esc_html( count( $enquiries ) ); ?></span><small><?php esc_html_e( 'Enquiries', 'bmtg-private-journey' ); ?></small></a>
				<a href="<?php echo esc_url( Pages::url( 'my_quotations' ) ); ?>"><span><?php echo esc_html( count( $quotes ) ); ?></span><small><?php esc_html_e( 'Proposals awaiting you', 'bmtg-private-journey' ); ?></small></a>
				<a href="<?php echo esc_url( Pages::url( 'my_journeys' ) ); ?>"><span><?php echo esc_html( $this->count_journeys( (int) ( $client['id'] ?? 0 ) ) ); ?></span><small><?php esc_html_e( 'Confirmed journeys', 'bmtg-private-journey' ); ?></small></a>
			</div>
			<div class="pj-dash__actions">
				<a class="pj-btn pj-btn--primary" href="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>"><?php esc_html_e( 'Discover the collection', 'bmtg-private-journey' ); ?></a>
				<a class="pj-btn pj-btn--ghost" href="<?php echo esc_url( Pages::url( 'journey_builder' ) ); ?>"><?php esc_html_e( 'Open journey builder', 'bmtg-private-journey' ); ?></a>
				<a class="pj-btn pj-btn--ghost" href="<?php echo esc_url( Pages::url( 'concierge' ) ); ?>"><?php esc_html_e( 'Contact your concierge', 'bmtg-private-journey' ); ?></a>
			</div>
			<?php if ( $enquiries ) : ?>
				<div class="pj-dash__section">
					<h2><?php esc_html_e( 'Recent enquiries', 'bmtg-private-journey' ); ?></h2>
					<div class="pj-list">
						<?php foreach ( array_slice( $enquiries, 0, 4 ) as $enquiry ) : ?>
							<a class="pj-list__row" href="<?php echo esc_url( add_query_arg( array( 'reference' => $enquiry['reference'] ), Pages::url( 'my_enquiries' ) ) ); ?>">
								<span class="pj-list__ref"><?php echo esc_html( $enquiry['reference'] ); ?></span>
								<span class="pj-list__title"><?php echo esc_html( $enquiry['destinations'] ?: __( 'Private journey', 'bmtg-private-journey' ) ); ?></span>
								<span class="pj-badge"><?php echo esc_html( $this->status_label( $enquiry['status'] ) ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--dashboard' );
	}

	private function count_journeys( int $client_id ): int {
		if ( ! $client_id ) {
			return 0;
		}
		global $wpdb;
		$table = Schema::confirmed_journeys();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table c INNER JOIN " . Schema::enquiries() . " e ON e.id = c.enquiry_id WHERE e.client_id = %d", $client_id ) );
	}

	private function catalogue( array $atts ): string {
		$type     = isset( $_GET['pj_type'] ) ? sanitize_key( wp_unslash( $_GET['pj_type'] ) ) : sanitize_key( (string) ( $atts['type'] ?? '' ) );
		$category = isset( $_GET['pj_category'] ) ? sanitize_title( wp_unslash( $_GET['pj_category'] ) ) : sanitize_title( (string) ( $atts['category'] ?? '' ) );
		$search   = isset( $_GET['pj_search'] ) ? sanitize_text_field( wp_unslash( $_GET['pj_search'] ) ) : '';
		$page     = isset( $_GET['pj_page'] ) ? max( 1, (int) $_GET['pj_page'] ) : 1;
		$per_page = (int) ( $atts['per_page'] ?? 9 );
		$types    = Schema::item_types();
		$cats     = Catalogue::instance()->categories();

		$result = Catalogue::instance()->query(
			array(
				'type'     => $type,
				'category' => $category,
				'search'   => $search,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		ob_start();
		?>
		<section class="pj-section pj-catalogue">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'Private Collection', 'bmtg-private-journey' ); ?></p>
				<h1><?php echo esc_html( (string) ( $atts['title'] ?? __( 'Discover', 'bmtg-private-journey' ) ) ); ?></h1>
				<p><?php esc_html_e( 'Browse the collection and compose the services that belong in your journey.', 'bmtg-private-journey' ); ?></p>
			</div>
			<form class="pj-filters" method="get" action="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>">
				<label class="pj-field"><span><?php esc_html_e( 'Collection', 'bmtg-private-journey' ); ?></span>
					<select name="pj_type">
						<option value=""><?php esc_html_e( 'All services', 'bmtg-private-journey' ); ?></option>
						<?php foreach ( $types as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="pj-field"><span><?php esc_html_e( 'Destination', 'bmtg-private-journey' ); ?></span>
					<select name="pj_category">
						<option value=""><?php esc_html_e( 'All categories', 'bmtg-private-journey' ); ?></option>
						<?php foreach ( $cats as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat['slug'] ); ?>" <?php selected( $category, $cat['slug'] ); ?>><?php echo esc_html( $cat['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="pj-field pj-field--wide"><span><?php esc_html_e( 'Search', 'bmtg-private-journey' ); ?></span><input type="search" name="pj_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Destination, hotel, experience…', 'bmtg-private-journey' ); ?>" /></label>
				<button class="pj-btn pj-btn--primary" type="submit"><?php esc_html_e( 'Explore', 'bmtg-private-journey' ); ?></button>
			</form>
			<?php if ( $search ) : ?>
				<p class="pj-results-count"><?php echo esc_html( sprintf( __( '%d services for “%s”', 'bmtg-private-journey' ), $result['total'], $search ) ); ?></p>
			<?php endif; ?>
			<?php echo wp_kses_post( $this->grid( $result['items'] ) ); ?>
			<?php echo $this->pagination( $page, $result['pages'], array( 'pj_type' => $type, 'pj_category' => $category, 'pj_search' => $search ) ); ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--catalogue' );
	}

	private function category( array $atts ): string {
		$atts['title'] = $atts['title'] ?? __( 'Collection', 'bmtg-private-journey' );
		return $this->catalogue( $atts );
	}

	private function featured( array $atts ): string {
		$result = Catalogue::instance()->query(
			array(
				'featured' => true,
				'per_page' => (int) ( $atts['limit'] ?? 3 ),
			)
		);
		ob_start();
		?>
		<section class="pj-section">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'Selected for you', 'bmtg-private-journey' ); ?></p>
				<h2><?php echo esc_html( (string) ( $atts['title'] ?? __( 'Featured journeys', 'bmtg-private-journey' ) ) ); ?></h2>
			</div>
			<?php echo wp_kses_post( $this->grid( $result['items'] ) ); ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean() );
	}

	private function item_detail( array $atts ): string {
		$slug = get_query_var( 'pj_item' );
		if ( ! $slug && isset( $_GET['pj_item'] ) ) {
			$slug = sanitize_title( wp_unslash( $_GET['pj_item'] ) );
		}
		if ( ! $slug && ! empty( $atts['slug'] ) ) {
			$slug = sanitize_title( (string) $atts['slug'] );
		}
		$item = $slug ? Catalogue::instance()->get_by_slug( $slug ) : null;

		if ( ! $item || 'published' !== $item['status'] || empty( $item['public_visibility'] ) ) {
			return $this->wrap( '<div class="pj-empty"><h1>' . esc_html__( 'Service not found', 'bmtg-private-journey' ) . '</h1><p>' . esc_html__( 'This service may no longer be available in the public collection.', 'bmtg-private-journey' ) . '</p><a class="pj-btn pj-btn--primary" href="' . esc_url( Pages::url( 'catalogue' ) ) . '">' . esc_html__( 'Return to the collection', 'bmtg-private-journey' ) . '</a></div>' );
		}

		$options  = Catalogue::instance()->options_for( (int) $item['id'] );
		$related  = Catalogue::instance()->query(
			array(
				'category' => $item['category_slug'],
				'per_page' => 4,
			)
		);
		$related_items = array_values( array_filter( $related['items'], static function ( $r ) use ( $item ) {
			return (int) $r['id'] !== (int) $item['id'];
		} ) );

		$gallery = array();
		if ( ! empty( $item['cover_image_id'] ) ) {
			$gallery[] = (int) $item['cover_image_id'];
		}
		foreach ( (array) $item['gallery'] as $id ) {
			if ( ! in_array( (int) $id, $gallery, true ) ) {
				$gallery[] = (int) $id;
			}
		}

		ob_start();
		?>
		<article class="pj-detail">
			<nav class="pj-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'bmtg-private-journey' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'bmtg-private-journey' ); ?></a>
				<span>/</span>
				<a href="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>"><?php esc_html_e( 'Private Collection', 'bmtg-private-journey' ); ?></a>
				<span>/</span>
				<span aria-current="page"><?php echo esc_html( $item['title'] ); ?></span>
			</nav>

			<div class="pj-detail__hero">
				<?php if ( ! empty( $item['cover_url'] ) ) : ?>
					<img src="<?php echo esc_url( $item['cover_url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" />
				<?php endif; ?>
				<div class="pj-detail__hero-body">
					<p class="pj-eyebrow"><?php echo esc_html( trim( $item['country'] . ' ' . $item['destination'] ) ?: ucfirst( $item['item_type'] ) ); ?></p>
					<h1><?php echo esc_html( $item['title'] ); ?></h1>
					<?php if ( $item['duration'] ) : ?>
						<p class="pj-meta"><?php echo esc_html( sprintf( __( 'Duration: %s', 'bmtg-private-journey' ), $item['duration'] ) ); ?></p>
					<?php endif; ?>
					<div class="pj-detail__price"><?php echo Catalogue::instance()->price_html( $item['price'], $item['currency'], $item['price_type'] ); ?></div>
				</div>
			</div>

			<div class="pj-detail__grid">
				<div class="pj-detail__main">
					<?php if ( $item['summary'] ) : ?>
						<p class="pj-lead"><?php echo esc_html( $item['summary'] ); ?></p>
					<?php endif; ?>
					<?php if ( $item['description'] ) : ?>
						<div class="pj-prose"><?php echo wp_kses_post( wpautop( $item['description'] ) ); ?></div>
					<?php endif; ?>

					<?php if ( ! empty( $item['highlights'] ) ) : ?>
						<section class="pj-block">
							<h2><?php esc_html_e( 'Highlights', 'bmtg-private-journey' ); ?></h2>
							<ul class="pj-ticks">
								<?php foreach ( (array) $item['highlights'] as $highlight ) : ?>
									<li><?php echo esc_html( $highlight ); ?></li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endif; ?>

					<?php if ( ! empty( $item['facilities'] ) ) : ?>
						<section class="pj-block">
							<h2><?php esc_html_e( 'Facilities & features', 'bmtg-private-journey' ); ?></h2>
							<ul class="pj-tags">
								<?php foreach ( (array) $item['facilities'] as $facility ) : ?>
									<li><?php echo esc_html( $facility ); ?></li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endif; ?>

					<div class="pj-detail__columns">
						<?php if ( ! empty( $item['inclusions'] ) ) : ?>
							<section class="pj-block">
								<h3><?php esc_html_e( 'Included', 'bmtg-private-journey' ); ?></h3>
								<ul class="pj-ticks"><?php foreach ( (array) $item['inclusions'] as $v ) : ?><li><?php echo esc_html( $v ); ?></li><?php endforeach; ?></ul>
							</section>
						<?php endif; ?>
						<?php if ( ! empty( $item['exclusions'] ) ) : ?>
							<section class="pj-block">
								<h3><?php esc_html_e( 'Not included', 'bmtg-private-journey' ); ?></h3>
								<ul class="pj-ticks pj-ticks--muted"><?php foreach ( (array) $item['exclusions'] as $v ) : ?><li><?php echo esc_html( $v ); ?></li><?php endforeach; ?></ul>
							</section>
						<?php endif; ?>
					</div>

					<?php if ( $item['conditions'] ) : ?>
						<section class="pj-block">
							<h3><?php esc_html_e( 'Conditions', 'bmtg-private-journey' ); ?></h3>
							<p class="pj-muted"><?php echo nl2br( esc_html( $item['conditions'] ) ); ?></p>
						</section>
					<?php endif; ?>
				</div>

				<aside class="pj-detail__aside">
					<div class="pj-add-card">
						<h2><?php esc_html_e( 'Add to your journey', 'bmtg-private-journey' ); ?></h2>
						<?php $this->form_open( 'bmtg_pj_basket_add', 'pj-form' ); ?>
							<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
							<?php echo $this->service_fields( $item['item_type'], $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php $this->form_close( __( 'Add to journey basket', 'bmtg-private-journey' ) ); ?>
						<p class="pj-fineprint"><?php esc_html_e( 'No payment is taken. Our designers confirm every detail before any reservation.', 'bmtg-private-journey' ); ?></p>
					</div>
				</aside>
			</div>

			<?php if ( $gallery ) : ?>
				<section class="pj-block">
					<h2><?php esc_html_e( 'Gallery', 'bmtg-private-journey' ); ?></h2>
					<div class="pj-gallery">
						<?php foreach ( $gallery as $index => $image_id ) : ?>
							<?php $url = wp_get_attachment_image_url( $image_id, 'large' ); if ( ! $url ) : continue; endif; ?>
							<a href="<?php echo esc_url( $url ); ?>" class="pj-gallery__item">
								<img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( sprintf( __( '%1$s — image %2$d', 'bmtg-private-journey' ), $item['title'], $index + 1 ) ); ?>" loading="lazy" />
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $related_items ) : ?>
				<section class="pj-block">
					<h2><?php esc_html_e( 'You may also consider', 'bmtg-private-journey' ); ?></h2>
					<?php echo wp_kses_post( $this->grid( $related_items ) ); ?>
				</section>
			<?php endif; ?>
		</article>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--detail' );
	}

	private function search( array $atts ): string {
		$search = isset( $_GET['pj_search'] ) ? sanitize_text_field( wp_unslash( $_GET['pj_search'] ) ) : '';
		$page   = isset( $_GET['pj_page'] ) ? max( 1, (int) $_GET['pj_page'] ) : 1;
		$result = Catalogue::instance()->query(
			array(
				'search'   => $search,
				'page'     => $page,
				'per_page' => (int) ( $atts['per_page'] ?? 12 ),
			)
		);
		ob_start();
		?>
		<section class="pj-section">
			<h1><?php esc_html_e( 'Search the collection', 'bmtg-private-journey' ); ?></h1>
			<form class="pj-filters" method="get" action="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>">
				<label class="pj-field pj-field--wide"><span><?php esc_html_e( 'Search', 'bmtg-private-journey' ); ?></span><input type="search" name="pj_search" value="<?php echo esc_attr( $search ); ?>" /></label>
				<button class="pj-btn pj-btn--primary" type="submit"><?php esc_html_e( 'Search', 'bmtg-private-journey' ); ?></button>
			</form>
			<p class="pj-results-count"><?php echo esc_html( sprintf( __( '%d results', 'bmtg-private-journey' ), $result['total'] ) ); ?></p>
			<?php echo wp_kses_post( $this->grid( $result['items'] ) ); ?>
			<?php echo $this->pagination( $page, $result['pages'], array( 'pj_search' => $search ) ); ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean() );
	}

	private function basket( array $atts ): string {
		$journey = Basket::instance()->current();
		$items   = Basket::instance()->items( (int) $journey['id'] );
		$check   = JourneyValidator::validate( $items );
		$builder = ! empty( $atts['builder'] );

		ob_start();
		?>
		<section class="pj-section pj-basket">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'Your selection', 'bmtg-private-journey' ); ?></p>
				<h1><?php echo esc_html( $builder ? __( 'Journey builder', 'bmtg-private-journey' ) : __( 'My journey basket', 'bmtg-private-journey' ) ); ?></h1>
				<p><?php esc_html_e( 'Every service you add is held here while you refine dates, guests and details.', 'bmtg-private-journey' ); ?></p>
			</div>

			<?php if ( ! $items ) : ?>
				<div class="pj-empty">
					<h2><?php esc_html_e( 'Your journey is waiting to be composed', 'bmtg-private-journey' ); ?></h2>
					<p><?php esc_html_e( 'Explore the collection and add hotels, restaurants, transport, rail, airport assistance and experiences.', 'bmtg-private-journey' ); ?></p>
					<a class="pj-btn pj-btn--primary" href="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>"><?php esc_html_e( 'Discover the collection', 'bmtg-private-journey' ); ?></a>
				</div>
			<?php else : ?>
				<div class="pj-basket__layout">
					<div class="pj-basket__items">
						<?php foreach ( $items as $index => $item ) : ?>
							<article class="pj-basket__item">
								<div class="pj-basket__head">
									<div>
										<p class="pj-eyebrow"><?php echo esc_html( ucfirst( $item['service_type'] ) . ( $item['destination'] ? ' · ' . $item['destination'] : '' ) ); ?></p>
										<h2><?php echo esc_html( $item['title'] ?: __( 'Service', 'bmtg-private-journey' ) ); ?></h2>
										<?php if ( $item['option_label'] ) : ?><p class="pj-muted"><?php echo esc_html( $item['option_label'] ); ?></p><?php endif; ?>
									</div>
									<div class="pj-basket__price"><?php echo Catalogue::instance()->price_html( isset( $item['display_price']['amount'] ) ? $item['display_price']['amount'] : null, $item['currency'] ?? 'EUR', $item['display_price']['type'] ?? 'poa' ); ?></div>
								</div>

								<details class="pj-edit">
									<summary><?php esc_html_e( 'Edit details', 'bmtg-private-journey' ); ?></summary>
									<?php $this->form_open( 'bmtg_pj_basket_update', 'pj-form pj-form--compact' ); ?>
										<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
										<?php
										echo $this->service_fields(
											$item['service_type'],
											$item['item_id'] ? Catalogue::instance()->options_for( (int) $item['item_id'] ) : array(),
											true
										);
										?>
										<div class="pj-form__actions">
											<button type="submit" class="pj-btn pj-btn--small"><?php esc_html_e( 'Update', 'bmtg-private-journey' ); ?></button>
										</div>
									<?php echo '</form>'; ?>
								</details>

								<div class="pj-basket__meta">
									<span><?php echo esc_html( $item['start_date'] ?: __( 'Date to be confirmed', 'bmtg-private-journey' ) ); ?></span>
									<span><?php echo esc_html( sprintf( __( '%d adults · %d children', 'bmtg-private-journey' ), (int) $item['adults'], (int) $item['children'] ) ); ?></span>
									<?php if ( $item['rooms'] ) : ?><span><?php echo esc_html( sprintf( __( '%d rooms', 'bmtg-private-journey' ), (int) $item['rooms'] ) ); ?></span><?php endif; ?>
								</div>

								<div class="pj-basket__actions">
									<?php if ( $index > 0 ) : ?>
										<?php $this->form_open( 'bmtg_pj_basket_reorder', 'pj-inline-form', array( 'ids' => $this->reordered_ids( $items, (int) $item['id'], -1 ) ) ); ?>
											<button class="pj-link" type="submit"><?php esc_html_e( 'Move up', 'bmtg-private-journey' ); ?></button>
										<?php echo '</form>'; ?>
									<?php endif; ?>
									<?php if ( $index < count( $items ) - 1 ) : ?>
										<?php $this->form_open( 'bmtg_pj_basket_reorder', 'pj-inline-form', array( 'ids' => $this->reordered_ids( $items, (int) $item['id'], 1 ) ) ); ?>
											<button class="pj-link" type="submit"><?php esc_html_e( 'Move down', 'bmtg-private-journey' ); ?></button>
										<?php echo '</form>'; ?>
									<?php endif; ?>
									<?php $this->form_open( 'bmtg_pj_basket_remove', 'pj-inline-form' ); ?>
										<input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>" />
										<button class="pj-link pj-link--muted" type="submit"><?php esc_html_e( 'Remove', 'bmtg-private-journey' ); ?></button>
									<?php echo '</form>'; ?>
								</div>
							</article>
						<?php endforeach; ?>
					</div>

					<aside class="pj-basket__aside">
						<?php if ( $check['errors'] || $check['warnings'] ) : ?>
							<div class="pj-check-panel">
								<h3><?php esc_html_e( 'Journey notes', 'bmtg-private-journey' ); ?></h3>
								<?php foreach ( $check['errors'] as $issue ) : ?>
									<p class="pj-issue pj-issue--error"><strong><?php echo esc_html( $issue['item'] ); ?>:</strong> <?php echo esc_html( $issue['msg'] ); ?></p>
								<?php endforeach; ?>
								<?php foreach ( $check['warnings'] as $issue ) : ?>
									<p class="pj-issue"><strong><?php echo esc_html( $issue['item'] ); ?>:</strong> <?php echo esc_html( $issue['msg'] ); ?></p>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<?php if ( is_user_logged_in() ) : ?>
							<div class="pj-check-panel">
								<h3><?php esc_html_e( 'Save your journey', 'bmtg-private-journey' ); ?></h3>
								<?php $this->form_open( 'bmtg_pj_save_journey', 'pj-form' ); ?>
									<label class="pj-field"><span><?php esc_html_e( 'Journey title', 'bmtg-private-journey' ); ?></span><input type="text" name="title" value="<?php echo esc_attr( $journey['title'] ?? '' ); ?>" /></label>
									<label class="pj-field"><span><?php esc_html_e( 'Notes for your designer', 'bmtg-private-journey' ); ?></span><textarea name="notes" rows="3"><?php echo esc_textarea( $journey['notes'] ?? '' ); ?></textarea></label>
									<button class="pj-btn pj-btn--ghost pj-btn--small" type="submit"><?php esc_html_e( 'Save journey', 'bmtg-private-journey' ); ?></button>
								<?php echo '</form>'; ?>
							</div>
							<a class="pj-btn pj-btn--primary pj-btn--block" href="<?php echo esc_url( Pages::url( 'enquiry' ) ); ?>"><?php esc_html_e( 'Request a private quotation', 'bmtg-private-journey' ); ?></a>
						<?php else : ?>
							<div class="pj-check-panel">
								<h3><?php esc_html_e( 'Sign in to continue', 'bmtg-private-journey' ); ?></h3>
								<p><?php esc_html_e( 'Save this journey and send one complete request for a personalised quotation.', 'bmtg-private-journey' ); ?></p>
								<a class="pj-btn pj-btn--primary pj-btn--block" href="<?php echo esc_url( add_query_arg( 'redirect_to', Pages::url( 'basket' ), Pages::url( 'login' ) ) ); ?>"><?php esc_html_e( 'Sign in', 'bmtg-private-journey' ); ?></a>
								<a class="pj-btn pj-btn--ghost pj-btn--block" href="<?php echo esc_url( Pages::url( 'registration' ) ); ?>"><?php esc_html_e( 'Create a profile', 'bmtg-private-journey' ); ?></a>
							</div>
						<?php endif; ?>
						<a class="pj-link" href="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>"><?php esc_html_e( 'Continue exploring', 'bmtg-private-journey' ); ?></a>
					</aside>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--basket' );
	}

	private function reordered_ids( array $items, int $current_id, int $direction ): array {
		$ids = array_map(
			static function ( $item ) {
				return (int) $item['id'];
			},
			$items
		);
		$index = array_search( $current_id, $ids, true );
		if ( false === $index ) {
			return $ids;
		}
		$target = $index + $direction;
		if ( $target < 0 || $target >= count( $ids ) ) {
			return $ids;
		}
		$tmp            = $ids[ $target ];
		$ids[ $target ] = $ids[ $index ];
		$ids[ $index ]  = $tmp;
		return $ids;
	}

	private function journey_builder( array $atts ): string {
		$atts['builder'] = true;
		return $this->basket( $atts );
	}

	private function enquiry_form( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return $this->wrap(
				'<div class="pj-empty"><h1>' . esc_html__( 'Sign in to request a quotation', 'bmtg-private-journey' ) . '</h1><p>' . esc_html__( 'Your journey basket is ready. Sign in so we can attach your request to your private profile.', 'bmtg-private-journey' ) . '</p><a class="pj-btn pj-btn--primary" href="' . esc_url( add_query_arg( 'redirect_to', Pages::url( 'enquiry' ), Pages::url( 'login' ) ) ) . '">' . esc_html__( 'Sign in', 'bmtg-private-journey' ) . '</a></div>'
			);
		}
		$client  = Clients::instance()->ensure_for_user( get_current_user_id() );
		$journey = Basket::instance()->current();
		$items   = Basket::instance()->items( (int) $journey['id'] );

		ob_start();
		?>
		<section class="pj-section pj-enquiry">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'One request, considered in full', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'Request a private quotation', 'bmtg-private-journey' ); ?></h1>
				<p><?php esc_html_e( 'Share the final details. Our designers review every service, check availability with suppliers and return one personalised proposal.', 'bmtg-private-journey' ); ?></p>
			</div>

			<?php if ( ! $items ) : ?>
				<div class="pj-empty"><p><?php esc_html_e( 'Your journey basket is empty. Add at least one service before requesting a quotation.', 'bmtg-private-journey' ); ?></p><a class="pj-btn pj-btn--primary" href="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>"><?php esc_html_e( 'Discover the collection', 'bmtg-private-journey' ); ?></a></div>
			<?php else : ?>
				<div class="pj-enquiry__layout">
					<?php $this->form_open( 'bmtg_pj_enquiry', 'pj-form' ); ?>
						<input type="hidden" name="journey_id" value="<?php echo esc_attr( $journey['id'] ); ?>" />
						<div class="pj-field-row">
							<label class="pj-field"><span><?php esc_html_e( 'Full name', 'bmtg-private-journey' ); ?></span><input type="text" name="name" value="<?php echo esc_attr( $client['full_name'] ?? '' ); ?>" required /></label>
							<label class="pj-field"><span><?php esc_html_e( 'Mobile', 'bmtg-private-journey' ); ?></span><input type="tel" name="phone" value="<?php echo esc_attr( $client['phone'] ?? '' ); ?>" /></label>
						</div>
						<label class="pj-field"><span><?php esc_html_e( 'Email', 'bmtg-private-journey' ); ?></span><input type="email" name="email" value="<?php echo esc_attr( $client['email'] ?? '' ); ?>" required /></label>
						<label class="pj-field"><span><?php esc_html_e( 'Destinations', 'bmtg-private-journey' ); ?></span><input type="text" name="destinations" placeholder="<?php echo esc_attr__( 'e.g. Italy, Switzerland', 'bmtg-private-journey' ); ?>" /></label>
						<div class="pj-field-row">
							<label class="pj-field"><span><?php esc_html_e( 'Arrival date', 'bmtg-private-journey' ); ?></span><input type="date" name="arrival_date" /></label>
							<label class="pj-field"><span><?php esc_html_e( 'Departure date', 'bmtg-private-journey' ); ?></span><input type="date" name="departure_date" /></label>
						</div>
						<div class="pj-field-row">
							<label class="pj-field"><span><?php esc_html_e( 'Adults', 'bmtg-private-journey' ); ?></span><input type="number" min="1" name="adults" value="<?php echo esc_attr( $client['preferences']['adults'] ?? 2 ); ?>" /></label>
							<label class="pj-field"><span><?php esc_html_e( 'Children', 'bmtg-private-journey' ); ?></span><input type="number" min="0" name="children" value="0" /></label>
						</div>
						<label class="pj-field"><span><?php esc_html_e( 'Indicative budget (optional)', 'bmtg-private-journey' ); ?></span><input type="text" name="budget" /></label>
						<label class="pj-field"><span><?php esc_html_e( 'Travel preferences', 'bmtg-private-journey' ); ?></span><textarea name="preferences" rows="3"></textarea></label>
						<label class="pj-field"><span><?php esc_html_e( 'Special requests', 'bmtg-private-journey' ); ?></span><textarea name="special_requests" rows="4"></textarea></label>
						<label class="pj-check"><input type="checkbox" name="consent" value="1" required /> <?php esc_html_e( 'I agree to be contacted about this request and accept the privacy notice.', 'bmtg-private-journey' ); ?></label>
						<?php $this->form_close( __( 'Send my request', 'bmtg-private-journey' ) ); ?>

					<aside class="pj-enquiry__summary">
						<h2><?php esc_html_e( 'Your journey at a glance', 'bmtg-private-journey' ); ?></h2>
						<ol class="pj-summary">
							<?php foreach ( $items as $item ) : ?>
								<li>
									<span><?php echo esc_html( $item['title'] ); ?></span>
									<small><?php echo esc_html( trim( ( $item['start_date'] ?: '' ) . ' ' . ( $item['option_label'] ?: '' ) ) ); ?></small>
								</li>
							<?php endforeach; ?>
						</ol>
						<p class="pj-fineprint"><?php esc_html_e( 'This is a request for a quotation, not a booking. No payment is taken.', 'bmtg-private-journey' ); ?></p>
					</aside>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean(), 'pj-page--enquiry' );
	}

	private function my_enquiries( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return $this->login_required();
		}
		$client    = Clients::instance()->get_by_user( get_current_user_id() );
		$enquiries = $client ? Enquiry::instance()->client_enquiries( (int) $client['id'] ) : array();

		ob_start();
		?>
		<section class="pj-section">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'Your account', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'My enquiries', 'bmtg-private-journey' ); ?></h1>
			</div>
			<?php if ( ! $enquiries ) : ?>
				<div class="pj-empty"><p><?php esc_html_e( 'You have not submitted an enquiry yet.', 'bmtg-private-journey' ); ?></p><a class="pj-btn pj-btn--primary" href="<?php echo esc_url( Pages::url( 'catalogue' ) ); ?>"><?php esc_html_e( 'Discover the collection', 'bmtg-private-journey' ); ?></a></div>
			<?php else : ?>
				<div class="pj-list pj-list--enquiries">
					<?php foreach ( $enquiries as $enquiry ) : ?>
						<article class="pj-list__card">
							<div class="pj-list__card-head">
								<span class="pj-list__ref"><?php echo esc_html( $enquiry['reference'] ); ?></span>
								<span class="pj-badge"><?php echo esc_html( $this->status_label( $enquiry['status'] ) ); ?></span>
							</div>
							<h2><?php echo esc_html( $enquiry['destinations'] ?: __( 'Private journey', 'bmtg-private-journey' ) ); ?></h2>
							<p class="pj-muted"><?php echo esc_html( sprintf( __( '%1$s to %2$s · %3$d adults, %4$d children', 'bmtg-private-journey' ), $enquiry['arrival_date'] ?: '—', $enquiry['departure_date'] ?: '—', (int) $enquiry['adults'], (int) $enquiry['children'] ) ); ?></p>
							<div class="pj-list__card-foot">
								<span><?php echo esc_html( sprintf( __( 'Submitted %s', 'bmtg-private-journey' ), mysql2date( get_option( 'date_format' ), $enquiry['created_at'] ) ) ); ?></span>
								<?php $quote = Quotation::instance()->latest_for_enquiry( (int) $enquiry['id'] ); ?>
								<?php if ( $quote ) : ?>
									<a class="pj-link" href="<?php echo esc_url( add_query_arg( array( 'quotation' => $quote['id'] ), Pages::url( 'my_quotations' ) ) ); ?>"><?php esc_html_e( 'View proposal', 'bmtg-private-journey' ); ?></a>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean() );
	}

	private function my_quotations( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return $this->login_required();
		}
		$client    = Clients::instance()->get_by_user( get_current_user_id() );
		$enquiries = $client ? Enquiry::instance()->client_enquiries( (int) $client['id'] ) : array();
		$quotes    = array();
		foreach ( $enquiries as $enquiry ) {
			$q = Quotation::instance()->latest_for_enquiry( (int) $enquiry['id'] );
			if ( $q ) {
				$quotes[] = array(
					'quote'   => $q,
					'enquiry' => $enquiry,
				);
			}
		}

		ob_start();
		?>
		<section class="pj-section">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'Your account', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'My quotations', 'bmtg-private-journey' ); ?></h1>
			</div>
			<?php if ( ! $quotes ) : ?>
				<div class="pj-empty"><p><?php esc_html_e( 'Your proposals will appear here once our designers have prepared them.', 'bmtg-private-journey' ); ?></p><a class="pj-btn pj-btn--ghost" href="<?php echo esc_url( Pages::url( 'my_enquiries' ) ); ?>"><?php esc_html_e( 'View my enquiries', 'bmtg-private-journey' ); ?></a></div>
			<?php else : ?>
				<?php foreach ( $quotes as $entry ) : $quote = $entry['quote']; $totals = $quote['totals'] ?: array(); ?>
					<article class="pj-quote" id="quote-<?php echo esc_attr( $quote['id'] ); ?>">
						<div class="pj-quote__head">
							<div>
								<p class="pj-eyebrow"><?php echo esc_html( sprintf( __( 'Proposal v%d · %s', 'bmtg-private-journey' ), (int) $quote['version'], $entry['enquiry']['reference'] ) ); ?></p>
								<h2><?php echo esc_html( $entry['enquiry']['destinations'] ?: __( 'Your private journey', 'bmtg-private-journey' ) ); ?></h2>
							</div>
							<span class="pj-badge"><?php echo esc_html( $this->quote_status_label( $quote['status'] ) ); ?></span>
						</div>

						<div class="pj-quote__items">
							<?php foreach ( $quote['items'] as $line ) : ?>
								<div class="pj-quote__row">
									<span><?php echo esc_html( $line['description'] ); ?></span>
									<span>× <?php echo esc_html( $line['quantity'] ); ?></span>
									<span><?php echo null === $line['unit_price'] ? esc_html__( 'On request', 'bmtg-private-journey' ) : esc_html( $this->money( $line['line_total'], (string) ( $quote['totals']['currency'] ?? 'EUR' ) ) ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>

						<?php if ( $quote['notes'] ) : ?>
							<p class="pj-quote__notes"><?php echo nl2br( esc_html( $quote['notes'] ) ); ?></p>
						<?php endif; ?>

						<div class="pj-quote__totals">
							<div><span><?php esc_html_e( 'Subtotal', 'bmtg-private-journey' ); ?></span><strong><?php echo esc_html( $this->money( $totals['subtotal'] ?? 0, $quote['totals']['currency'] ?? 'EUR' ) ); ?></strong></div>
							<?php if ( ! empty( $totals['discount'] ) ) : ?><div><span><?php esc_html_e( 'Discount', 'bmtg-private-journey' ); ?></span><strong>− <?php echo esc_html( $this->money( $totals['discount'], $quote['totals']['currency'] ?? 'EUR' ) ); ?></strong></div><?php endif; ?>
							<?php if ( ! empty( $totals['tax'] ) ) : ?><div><span><?php esc_html_e( 'Taxes', 'bmtg-private-journey' ); ?></span><strong><?php echo esc_html( $this->money( $totals['tax'], $quote['totals']['currency'] ?? 'EUR' ) ); ?></strong></div><?php endif; ?>
							<div class="pj-quote__total"><span><?php esc_html_e( 'Total', 'bmtg-private-journey' ); ?></span><strong><?php echo esc_html( $this->money( $totals['total'] ?? 0, $quote['totals']['currency'] ?? 'EUR' ) ); ?></strong></div>
						</div>
						<?php if ( ! empty( $totals['has_poa'] ) ) : ?>
							<p class="pj-fineprint"><?php esc_html_e( 'Some items are priced on request and will be confirmed individually. This proposal is not a confirmed booking.', 'bmtg-private-journey' ); ?></p>
						<?php endif; ?>
						<p class="pj-fineprint"><?php echo esc_html( sprintf( __( 'Valid until %s. Acceptance records your intent; supplier availability is confirmed separately.', 'bmtg-private-journey' ), $quote['expires_at'] ?: '—' ) ); ?></p>

						<?php if ( 'issued' === $quote['status'] ) : ?>
							<div class="pj-quote__response">
								<?php $this->form_open( 'bmtg_pj_quote_response', 'pj-form pj-form--compact' ); ?>
									<input type="hidden" name="quotation_id" value="<?php echo esc_attr( $quote['id'] ); ?>" />
									<input type="hidden" name="response" value="accepted" />
									<button type="submit" class="pj-btn pj-btn--primary"><?php esc_html_e( 'Accept proposal', 'bmtg-private-journey' ); ?></button>
								<?php echo '</form>'; ?>
								<?php $this->form_open( 'bmtg_pj_quote_response', 'pj-form pj-form--compact' ); ?>
									<input type="hidden" name="quotation_id" value="<?php echo esc_attr( $quote['id'] ); ?>" />
									<input type="hidden" name="response" value="declined" />
									<button type="submit" class="pj-btn pj-btn--ghost"><?php esc_html_e( 'Decline', 'bmtg-private-journey' ); ?></button>
								<?php echo '</form>'; ?>
								<?php $this->form_open( 'bmtg_pj_quote_response', 'pj-form pj-form--compact' ); ?>
									<input type="hidden" name="quotation_id" value="<?php echo esc_attr( $quote['id'] ); ?>" />
									<input type="hidden" name="response" value="modifications" />
									<label class="pj-field"><span><?php esc_html_e( 'Request a modification', 'bmtg-private-journey' ); ?></span><textarea name="notes" rows="2" required></textarea></label>
									<button type="submit" class="pj-btn pj-btn--ghost"><?php esc_html_e( 'Request changes', 'bmtg-private-journey' ); ?></button>
								<?php echo '</form>'; ?>
							</div>
						<?php else : ?>
							<p class="pj-quote__state"><?php echo esc_html( sprintf( __( 'Status: %s', 'bmtg-private-journey' ), $this->quote_status_label( $quote['status'] ) ) ); ?></p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean() );
	}

	private function quote_status_label( string $status ): string {
		$labels = array(
			'draft'      => __( 'Draft', 'bmtg-private-journey' ),
			'issued'     => __( 'Awaiting your response', 'bmtg-private-journey' ),
			'accepted'   => __( 'Accepted', 'bmtg-private-journey' ),
			'declined'   => __( 'Declined', 'bmtg-private-journey' ),
			'superseded' => __( 'Superseded', 'bmtg-private-journey' ),
			'expired'    => __( 'Expired', 'bmtg-private-journey' ),
		);
		return $labels[ $status ] ?? ucfirst( $status );
	}

	private function money( $amount, string $currency ): string {
		return $currency . ' ' . number_format_i18n( (float) $amount, 2 );
	}

	private function my_journeys( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return $this->login_required();
		}
		global $wpdb;
		$client = Clients::instance()->get_by_user( get_current_user_id() );
		$rows   = array();
		if ( $client ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.* FROM " . Schema::confirmed_journeys() . " c INNER JOIN " . Schema::enquiries() . " e ON e.id = c.enquiry_id WHERE e.client_id = %d ORDER BY c.id DESC",
					(int) $client['id']
				),
				ARRAY_A
			);
		}

		ob_start();
		?>
		<section class="pj-section">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'Your account', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'My confirmed journeys', 'bmtg-private-journey' ); ?></h1>
			</div>
			<?php if ( ! $rows ) : ?>
				<div class="pj-empty"><p><?php esc_html_e( 'Confirmed journeys will appear here once a proposal has been accepted and supplier arrangements are complete.', 'bmtg-private-journey' ); ?></p></div>
			<?php else : ?>
				<div class="pj-list">
					<?php foreach ( $rows as $row ) : $meta = Schema::decode( $row['meta'] ?? '', array() ); ?>
						<article class="pj-list__card">
							<div class="pj-list__card-head">
								<span class="pj-list__ref"><?php echo esc_html( $row['reference'] ); ?></span>
								<span class="pj-badge"><?php echo esc_html( $this->status_label( $row['status'] ) ); ?></span>
							</div>
							<h2><?php esc_html_e( 'Your journey', 'bmtg-private-journey' ); ?></h2>
							<p class="pj-muted"><?php echo esc_html( (string) ( $meta['note'] ?? '' ) ); ?></p>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean() );
	}

	private function documents( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return $this->login_required();
		}
		$client = Clients::instance()->get_by_user( get_current_user_id() );
		$docs   = $client ? Documents::instance()->for_client( (int) $client['id'] ) : array();

		ob_start();
		?>
		<section class="pj-section">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'Your account', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'My documents', 'bmtg-private-journey' ); ?></h1>
				<p><?php esc_html_e( 'Vouchers, itineraries and private documents are available only to you.', 'bmtg-private-journey' ); ?></p>
			</div>
			<?php if ( ! $docs ) : ?>
				<div class="pj-empty"><p><?php esc_html_e( 'You have no documents yet.', 'bmtg-private-journey' ); ?></p></div>
			<?php else : ?>
				<div class="pj-docs">
					<?php foreach ( $docs as $doc ) : ?>
						<div class="pj-doc">
							<div>
								<strong><?php echo esc_html( $doc['title'] ); ?></strong>
								<small><?php echo esc_html( $doc['file_name'] . ' · ' . size_format( (int) $doc['size'] ) ); ?></small>
							</div>
							<a class="pj-btn pj-btn--small pj-btn--ghost" href="<?php echo esc_url( Documents::instance()->download_url( (int) $doc['id'] ) ); ?>"><?php esc_html_e( 'Download', 'bmtg-private-journey' ); ?></a>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean() );
	}

	private function profile( array $atts ): string {
		if ( ! is_user_logged_in() ) {
			return $this->login_required();
		}
		$user   = wp_get_current_user();
		$client = Clients::instance()->ensure_for_user( $user->ID );
		$prefs  = array(
			'destinations' => __( 'Preferred destinations', 'bmtg-private-journey' ),
			'hotels'       => __( 'Hotels & resorts', 'bmtg-private-journey' ),
			'dining'       => __( 'Dining', 'bmtg-private-journey' ),
			'experiences'  => __( 'Experiences', 'bmtg-private-journey' ),
			'rail'         => __( 'Rail journeys', 'bmtg-private-journey' ),
			'wellness'     => __( 'Wellness', 'bmtg-private-journey' ),
		);
		$saved = $client ? Clients::instance()->preferences( (int) $client['id'] ) : array();

		ob_start();
		?>
		<section class="pj-section pj-profile">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'Your account', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'My profile', 'bmtg-private-journey' ); ?></h1>
			</div>
			<?php $this->form_open( 'bmtg_pj_profile', 'pj-form pj-form--profile' ); ?>
				<div class="pj-field-row">
					<label class="pj-field"><span><?php esc_html_e( 'First name', 'bmtg-private-journey' ); ?></span><input type="text" name="first_name" value="<?php echo esc_attr( $client['first_name'] ?? $user->first_name ); ?>" required /></label>
					<label class="pj-field"><span><?php esc_html_e( 'Last name', 'bmtg-private-journey' ); ?></span><input type="text" name="last_name" value="<?php echo esc_attr( $client['last_name'] ?? $user->last_name ); ?>" required /></label>
				</div>
				<label class="pj-field"><span><?php esc_html_e( 'Email', 'bmtg-private-journey' ); ?></span><input type="email" value="<?php echo esc_attr( $client['email'] ?? $user->user_email ); ?>" disabled /></label>
				<label class="pj-field"><span><?php esc_html_e( 'Mobile', 'bmtg-private-journey' ); ?></span><input type="tel" name="phone" value="<?php echo esc_attr( $client['phone'] ?? '' ); ?>" /></label>
				<fieldset class="pj-fieldset">
					<legend><?php esc_html_e( 'Travel preferences', 'bmtg-private-journey' ); ?></legend>
					<div class="pj-checks">
						<?php foreach ( $prefs as $key => $label ) : ?>
							<label class="pj-check"><input type="checkbox" name="preferences[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $saved[ $key ] ) ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<button class="pj-btn pj-btn--primary" type="submit"><?php esc_html_e( 'Save profile', 'bmtg-private-journey' ); ?></button>
			<?php echo '</form>'; ?>
			<p class="pj-fineprint"><?php esc_html_e( 'Your profile is private and is never shared with suppliers without your request.', 'bmtg-private-journey' ); ?></p>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean() );
	}

	private function concierge( array $atts ): string {
		$social = Brand::social_links();
		$phone  = Brand::contact_phone();
		$email  = Brand::contact_email();
		$wa     = $social['whatsapp'] ?? '';
		ob_start();
		?>
		<section class="pj-section pj-concierge">
			<div class="pj-section__head">
				<p class="pj-eyebrow"><?php esc_html_e( 'At your service', 'bmtg-private-journey' ); ?></p>
				<h1><?php esc_html_e( 'Concierge & support', 'bmtg-private-journey' ); ?></h1>
				<p><?php esc_html_e( 'A private point of contact for every question, refinement and request.', 'bmtg-private-journey' ); ?></p>
			</div>
			<div class="pj-contact-grid">
				<?php if ( $phone ) : ?>
					<div class="pj-contact"><h3><?php esc_html_e( 'Telephone', 'bmtg-private-journey' ); ?></h3><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></div>
				<?php endif; ?>
				<?php if ( $email ) : ?>
					<div class="pj-contact"><h3><?php esc_html_e( 'Email', 'bmtg-private-journey' ); ?></h3><a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a></div>
				<?php endif; ?>
				<?php if ( $wa ) : ?>
					<div class="pj-contact"><h3><?php esc_html_e( 'WhatsApp', 'bmtg-private-journey' ); ?></h3><a href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Message our concierge', 'bmtg-private-journey' ); ?></a></div>
				<?php endif; ?>
				<?php foreach ( array( 'instagram', 'facebook' ) as $key ) : if ( empty( $social[ $key ] ) ) : continue; endif; ?>
					<div class="pj-contact"><h3><?php echo esc_html( ucfirst( $key ) ); ?></h3><a href="<?php echo esc_url( $social[ $key ] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( ucfirst( $key ) ); ?></a></div>
				<?php endforeach; ?>
			</div>
			<?php if ( ! $phone && ! $email && ! $wa && empty( $social ) ) : ?>
				<div class="pj-empty"><p><?php esc_html_e( 'Concierge contact details have not been configured yet. Please use the enquiry form and our team will respond.', 'bmtg-private-journey' ); ?></p></div>
			<?php endif; ?>
			<p><a class="pj-btn pj-btn--primary" href="<?php echo esc_url( Pages::url( 'enquiry' ) ); ?>"><?php esc_html_e( 'Send a private request', 'bmtg-private-journey' ); ?></a></p>
		</section>
		<?php
		return $this->wrap( (string) ob_get_clean() );
	}

	private function login_required(): string {
		return $this->wrap(
			'<div class="pj-empty"><h1>' . esc_html__( 'Sign in required', 'bmtg-private-journey' ) . '</h1><p>' . esc_html__( 'This part of your private journey is only visible to you.', 'bmtg-private-journey' ) . '</p><a class="pj-btn pj-btn--primary" href="' . esc_url( add_query_arg( 'redirect_to', Pages::url( 'dashboard' ), Pages::url( 'login' ) ) ) . '">' . esc_html__( 'Sign in', 'bmtg-private-journey' ) . '</a></div>'
		);
	}
}