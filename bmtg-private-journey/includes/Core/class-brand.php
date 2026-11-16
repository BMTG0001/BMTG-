<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Branding service. Single source of truth for every module.
 * Nothing here is hardcoded; administrators control every value.
 */
class Brand {
	private static ?array $cache = null;

	public static function all(): array {
		if ( null === self::$cache ) {
			self::$cache = Settings::all();
		}
		return self::$cache;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	public static function company( ?string $key = null ) {
		$company = self::all()['company'];
		if ( null === $key ) {
			return $company;
		}
		return $company[ $key ] ?? '';
	}

	public static function brand( ?string $key = null ) {
		$brand = self::all()['brand'];
		if ( null === $key ) {
			return $brand;
		}
		return $brand[ $key ] ?? '';
	}

	public static function color( string $key, string $fallback = '#292929' ): string {
		$value = (string) self::brand( $key );
		return $value ? $value : $fallback;
	}

	public static function logo_url( string $variant = 'default' ): string {
		$id = (int) self::company( 'logo_id' );
		if ( 'light' === $variant ) {
			$id = (int) self::company( 'logo_light_id' ) ?: $id;
		} elseif ( 'dark' === $variant ) {
			$id = (int) self::company( 'logo_dark_id' ) ?: $id;
		}
		if ( ! $id ) {
			return '';
		}
		return (string) wp_get_attachment_image_url( $id, 'full' );
	}

	public static function logo_width(): int {
		return max( 24, min( 800, (int) self::company( 'logo_width' ) ) );
	}

	public static function company_name(): string {
		return (string) self::company( 'name' );
	}

	public static function whatsapp_url(): string {
		if ( ! (int) self::get_social( 'whatsapp_enable' ) ) {
			return '';
		}
		$number = preg_replace( '/\D+/', '', (string) self::get_social( 'whatsapp_number' ) );
		if ( '' === $number ) {
			return '';
		}
		$message = (string) self::get_social( 'whatsapp_message' );
		return 'https://wa.me/' . rawurlencode( $number ) . '?text=' . rawurlencode( $message );
	}

	public static function social_links(): array {
		$links = array();
		if ( (int) self::get_social( 'instagram_enable' ) ) {
			$url = esc_url_raw( (string) self::get_social( 'instagram_url' ) );
			if ( $url ) {
				$links['instagram'] = $url;
			}
		}
		if ( (int) self::get_social( 'facebook_enable' ) ) {
			$url = esc_url_raw( (string) self::get_social( 'facebook_url' ) );
			if ( $url ) {
				$links['facebook'] = $url;
			}
		}
		$wa = self::whatsapp_url();
		if ( $wa ) {
			$links['whatsapp'] = $wa;
		}
		if ( (int) self::get_social( 'contact_email_enable' ) ) {
			$email = sanitize_email( (string) self::get_social( 'contact_email' ) );
			if ( is_email( $email ) ) {
				$links['email'] = 'mailto:' . $email;
			}
		}
		if ( (int) self::get_social( 'contact_phone_enable' ) ) {
			$phone = (string) self::get_social( 'contact_phone' );
			if ( '' !== $phone ) {
				$links['phone'] = 'tel:' . preg_replace( '/[^\d+]/', '', $phone );
			}
		}
		return $links;
	}

	public static function get_social( string $key ) {
		$social = self::all()['social'];
		return $social[ $key ] ?? '';
	}

	public static function contact_email(): string {
		$email = sanitize_email( (string) self::company( 'email' ) );
		if ( ! is_email( $email ) ) {
			$email = sanitize_email( (string) self::get_social( 'contact_email' ) );
		}
		return is_email( $email ) ? $email : '';
	}

	public static function contact_phone(): string {
		return (string) self::company( 'phone' ) ?: (string) self::get_social( 'contact_phone' );
	}

	/** CSS custom properties for frontend + email wrappers. */
	public static function css_variables(): string {
		$b = self::all()['brand'];
		$vars = array(
			'--pj-primary'    => self::color( 'primary', '#17251F' ),
			'--pj-secondary'  => self::color( 'secondary', '#292929' ),
			'--pj-accent'     => self::color( 'accent', '#C6A75E' ),
			'--pj-bg'         => self::color( 'background', '#F9F7F2' ),
			'--pj-text'       => self::color( 'text', '#292929' ),
			'--pj-muted'      => self::color( 'text_muted', '#6B6B6B' ),
			'--pj-heading-font' => (string) ( $b['heading_font'] ?? 'Georgia, serif' ),
			'--pj-body-font'    => (string) ( $b['body_font'] ?? 'Arial, sans-serif' ),
		);
		$out = array();
		foreach ( $vars as $name => $value ) {
			$out[] = $name . ':' . $value;
		}
		return implode( ';', $out );
	}

	public static function social_icons(): array {
		return array(
			'instagram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.2 2.2.4.6.2 1 .5 1.4.9.4.4.7.8.9 1.4.2.4.3 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.2 1.8-.4 2.2-.2.6-.5 1-.9 1.4-.4.4-.8.7-1.4.9-.4.2-1 .3-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.2-2.2-.4-.6-.2-1-.5-1.4-.9-.4-.4-.7-.8-.9-1.4-.2-.4-.3-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c.1-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4.4-.4.8-.7 1.4-.9.4-.2 1-.3 2.2-.4C8.4 2.2 8.8 2.2 12 2.2m0 1.8c-3.1 0-3.5 0-4.7.1-1.1.1-1.5.2-1.8.3-.4.2-.7.3-1 .6-.3.3-.5.6-.6 1-.1.3-.2.7-.3 1.8-.1 1.2-.1 1.6-.1 4.7s0 3.5.1 4.7c.1 1.1.2 1.5.3 1.8.1.4.3.7.6 1 .3.3.6.5 1 .6.3.1.7.2 1.8.3 1.2.1 1.6.1 4.7.1s3.5 0 4.7-.1c1.1-.1 1.5-.2 1.8-.3.4-.1.7-.3 1-.6.3-.3.5-.6.6-1 .1-.3.2-.7.3-1.8.1-1.2.1-1.6.1-4.7s0-3.5-.1-4.7c-.1-1.1-.2-1.5-.3-1.8-.1-.4-.3-.7-.6-1-.3-.3-.6-.5-1-.6-.3-.1-.7-.2-1.8-.3-1.2-.1-1.6-.1-4.7-.1m0 3.1a4.9 4.9 0 1 1 0 9.8 4.9 4.9 0 0 1 0-9.8m0 1.8a3.1 3.1 0 1 0 0 6.2 3.1 3.1 0 0 0 0-6.2m5-2.1a1.1 1.1 0 1 1 0 2.2 1.1 1.1 0 0 1 0-2.2"/></svg>',
			'facebook'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.5 21v-8.2h2.8l.4-3.2h-3.2V7.5c0-.9.3-1.5 1.6-1.5h1.7V3.1c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.3H7.3v3.2h2.8V21z"/></svg>',
			'whatsapp'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.1-1.3A10 10 0 1 0 12 2m0 1.8a8.2 8.2 0 0 1 6.9 12.6l-.3.5.8 2.9-3-.8-.5.3A8.2 8.2 0 1 1 12 3.8m-3.1 4c-.2 0-.4.1-.6.3-.2.2-.7.7-.7 1.7s.7 2 .8 2.1c.1.1 1.4 2.2 3.4 3.1 1.7.7 2.1.6 2.5.6.5-.1 1.4-.6 1.6-1.1.2-.6.2-1 .1-1.1-.1-.1-.3-.2-.6-.3l-1.3-.6c-.2-.1-.3-.1-.5.1l-.6.7c-.1.2-.3.2-.5.1-.3-.1-1.1-.4-2-1.3-.8-.7-1.3-1.6-1.5-1.9-.1-.2 0-.3.1-.4l.4-.5c.1-.2.2-.3.2-.5 0-.1 0-.3-.1-.5l-.6-1.4c-.1-.4-.3-.4-.5-.4z"/></svg>',
			'email'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v14H3z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m3 6 9 6 9-6" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>',
			'phone'     => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 3l3 3-2 2c1 2 2.4 3.4 4.4 4.4l2-2 3 3-1.5 2.2c-.5.7-1.3 1.1-2.2.9C8.6 15.6 4.4 11.4 3.5 6.7c-.2-.9.2-1.7.9-2.2z"/></svg>',
		);
	}
}