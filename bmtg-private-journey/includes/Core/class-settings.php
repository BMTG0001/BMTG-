<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central settings store for BMTG Private Journey.
 * One option array, grouped by section, fully editable by administrators.
 */
class Settings {
	public const OPTION = 'bmtg_pj_settings';

	public static function defaults(): array {
		return array(
			'company' => array(
				'name'                    => 'BM Travel Group',
				'description'             => '',
				'logo_id'                 => 0,
				'logo_light_id'           => 0,
				'logo_dark_id'            => 0,
				'logo_width'              => 220,
				'logo_preset'             => 'medium',
				'address_registered'      => '',
				'address_correspondence'  => '',
				'phone'                   => '',
				'email'                   => '',
				'website'                 => '',
				'registration'            => '',
			),
			'brand' => array(
				'primary'     => '#17251F',
				'secondary'   => '#292929',
				'accent'      => '#C6A75E',
				'background'  => '#F9F7F2',
				'text'        => '#292929',
				'text_muted'  => '#6B6B6B',
				'heading_font' => "'Cormorant Garamond', Georgia, serif",
				'body_font'    => "'Jost', 'Helvetica Neue', Arial, sans-serif",
				'logo_display' => 'light',
			),
			'social' => array(
				'instagram_enable'   => 0,
				'instagram_url'      => '',
				'facebook_enable'    => 0,
				'facebook_url'       => '',
				'whatsapp_enable'    => 0,
				'whatsapp_number'    => '',
				'whatsapp_message'   => 'Hello BM Travel Group, I would like to discuss a private journey.',
				'contact_email_enable' => 0,
				'contact_email'      => '',
				'contact_phone_enable' => 0,
				'contact_phone'      => '',
			),
			'email' => array(
				'internal_recipients' => '',
				'internal_cc'         => '',
				'from_name'           => '',
				'from_email'          => '',
				'reply_to_client'     => 1,
				'disable_client_copy' => 1,
			),
			'auth' => array(
				'google_enable'      => 0,
				'google_client_id'   => '',
				'google_client_secret' => '',
				'login_redirect'     => 'dashboard',
				'logout_redirect'    => 'home',
				'registration_enable' => 1,
			),
			'general' => array(
				'currency'          => 'EUR',
				'default_markup'    => 0.2,
				'default_tax_rate'  => 0,
				'quote_valid_days'  => 14,
				'debug'             => 0,
			),
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_replace_recursive( self::defaults(), $saved );
	}

	public static function get( string $section, string $key, $fallback = '' ) {
		$all = self::all();
		if ( isset( $all[ $section ][ $key ] ) ) {
			return $all[ $section ][ $key ];
		}
		return $fallback;
	}

	public static function update( array $values ): void {
		$current = self::all();
		foreach ( $values as $section => $fields ) {
			if ( ! is_array( $fields ) || ! isset( $current[ $section ] ) ) {
				continue;
			}
			foreach ( $fields as $key => $value ) {
				if ( array_key_exists( $key, $current[ $section ] ) ) {
					$current[ $section ][ $key ] = $value;
				}
			}
		}
		update_option( self::OPTION, $current, false );
	}

	/** Whitelist + sanitise one submitted section. */
	public static function sanitize_section( string $section, array $input ): array {
		$defaults = self::defaults();
		$fields   = $defaults[ $section ] ?? array();
		$clean    = array();

		foreach ( $fields as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				if ( is_int( $default ) && in_array( $default, array( 0, 1 ), true ) ) {
					$clean[ $key ] = 0;
				}
				continue;
			}
			$raw = $input[ $key ];
			$clean[ $key ] = self::sanitize_value( $key, $raw, $default );
		}

		return $clean;
	}

	private static function sanitize_value( string $key, $raw, $default ) {
		$value = is_scalar( $raw ) ? (string) $raw : '';

		if ( in_array( $key, array( 'logo_id', 'logo_light_id', 'logo_dark_id' ), true ) ) {
			return max( 0, (int) $value );
		}

		if ( is_bool( $default ) || in_array( $default, array( 0, 1 ), true ) ) {
			return empty( $raw ) ? 0 : 1;
		}

		if ( 'logo_width' === $key ) {
			return max( 24, min( 800, (int) $value ) );
		}
		if ( 'quote_valid_days' === $key ) {
			return max( 1, min( 365, (int) $value ) );
		}
		if ( 'default_markup' === $key || 'default_tax_rate' === $key ) {
			return max( 0, min( 5, (float) $value ) );
		}
		if ( self::is_url_key( $key ) ) {
			return esc_url_raw( trim( $value ) );
		}
		if ( self::is_email_key( $key ) ) {
			return sanitize_email( trim( $value ) );
		}
		if ( self::is_color_key( $key ) ) {
			$value = sanitize_hex_color( trim( $value ) );
			return $value ? $value : (string) $default;
		}
		if ( 'internal_recipients' === $key || 'internal_cc' === $key ) {
			return self::sanitize_email_list( $value );
		}
		if ( is_int( $default ) ) {
			return (int) $value;
		}
		if ( is_float( $default ) ) {
			return (float) $value;
		}

		return substr( sanitize_text_field( $value ), 0, 2000 );
	}

	private static function is_url_key( string $key ): bool {
		return in_array( $key, array( 'website', 'instagram_url', 'facebook_url' ), true );
	}

	private static function is_email_key( string $key ): bool {
		return in_array( $key, array( 'email', 'contact_email', 'from_email' ), true );
	}

	private static function is_color_key( string $key ): bool {
		return in_array( $key, array( 'primary', 'secondary', 'accent', 'background', 'text', 'text_muted' ), true );
	}

	public static function sanitize_email_list( string $value ): string {
		$parts = preg_split( '/[,;\r\n]+/', $value );
		$clean = array();
		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( trim( $part ) );
			if ( is_email( $email ) ) {
				$clean[] = $email;
			}
		}
		return implode( ',', array_unique( $clean ) );
	}

	public static function email_list( string $section, string $key ): array {
		$value = (string) self::get( $section, $key, '' );
		$parts = preg_split( '/[,;\r\n]+/', $value );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$email = sanitize_email( trim( $part ) );
			if ( is_email( $email ) ) {
				$out[] = $email;
			}
		}
		return array_values( array_unique( $out ) );
	}
}