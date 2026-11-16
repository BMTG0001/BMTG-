<?php
namespace BMTG\PrivateJourney\Auth;

use BMTG\PrivateJourney\Clients\Clients;
use BMTG\PrivateJourney\Core\Capabilities;
use BMTG\PrivateJourney\Core\Pages;
use BMTG\PrivateJourney\Core\Schema;
use BMTG\PrivateJourney\Core\Settings;
use BMTG\PrivateJourney\Journey\Basket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client authentication: email/password, optional Google Sign-In,
 * onboarding and secure WordPress cookie sessions.
 */
class Auth {
	public function register(): void {
		add_action( 'admin_post_bmtg_pj_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_nopriv_bmtg_pj_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_bmtg_pj_register', array( $this, 'handle_register' ) );
		add_action( 'admin_post_nopriv_bmtg_pj_register', array( $this, 'handle_register' ) );
		add_action( 'admin_post_bmtg_pj_onboard', array( $this, 'handle_onboard' ) );
		add_action( 'admin_post_bmtg_pj_logout', array( $this, 'handle_logout' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_authenticated' ), 20 );
		add_filter( 'auth_cookie_expiration', array( $this, 'cookie_expiration' ), 10, 3 );

		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
	}

	public function cookie_expiration( $length, $user_id, $remember ) {
		return $remember ? 14 * DAY_IN_SECONDS : 2 * DAY_IN_SECONDS;
	}

	public function on_login( $user_login, $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$client = Clients::instance()->ensure_for_user( (int) $user->ID );
		if ( $client ) {
			Basket::instance()->merge_guest_into_client( (int) $client['id'], (int) $user->ID );
		}
	}

	public function is_onboarded( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'bmtg_pj_onboarded', true );
	}

	public function mark_onboarded( int $user_id ): void {
		update_user_meta( $user_id, 'bmtg_pj_onboarded', 1 );
	}

	public function login_redirect( ?string $requested = null ): string {
		if ( $requested ) {
			$validated = wp_validate_redirect( $requested, '' );
			if ( $validated ) {
				return $validated;
			}
		}
		if ( ! $this->is_onboarded( get_current_user_id() ) ) {
			return Pages::url( 'dashboard' );
		}
		$mode = (string) Settings::get( 'auth', 'login_redirect', 'dashboard' );
		if ( 'dashboard' === $mode ) {
			return Pages::url( 'dashboard' );
		}
		if ( 'catalogue' === $mode ) {
			return Pages::url( 'catalogue' );
		}
		return home_url( '/' );
	}

	public function logout_redirect(): string {
		$mode = (string) Settings::get( 'auth', 'logout_redirect', 'home' );
		if ( 'login' === $mode ) {
			return Pages::url( 'login' );
		}
		return home_url( '/' );
	}

	public function handle_login(): void {
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$nonce    = isset( $_POST['bmtg_pj_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bmtg_pj_login_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'bmtg_pj_login' ) ) {
			wp_safe_redirect( $this->back_with_error( 'login', __( 'Your session expired. Please try again.', 'bmtg-private-journey' ), $redirect ) );
			exit;
		}

		$credentials = array(
			'user_login'    => isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '',
			'user_password' => isset( $_POST['pwd'] ) && is_string( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '',
			'remember'      => ! empty( $_POST['remember'] ),
		);

		if ( '' === $credentials['user_login'] || '' === $credentials['user_password'] ) {
			wp_safe_redirect( $this->back_with_error( 'login', __( 'Please enter your email and password.', 'bmtg-private-journey' ), $redirect ) );
			exit;
		}

		$user = wp_signon( $credentials, is_ssl() );
		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( $this->back_with_error( 'login', $user->get_error_message(), $redirect ) );
			exit;
		}

		wp_set_current_user( $user->ID );
		$this->on_login( $user->user_login, $user );

		wp_safe_redirect( $this->login_redirect( $redirect ) );
		exit;
	}

	public function handle_register(): void {
		$nonce = isset( $_POST['bmtg_pj_register_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bmtg_pj_register_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bmtg_pj_register' ) ) {
			wp_safe_redirect( $this->back_with_error( 'registration', __( 'Your session expired. Please try again.', 'bmtg-private-journey' ) ) );
			exit;
		}
		if ( ! (int) Settings::get( 'auth', 'registration_enable', 1 ) ) {
			wp_safe_redirect( $this->back_with_error( 'registration', __( 'Registration is currently disabled.', 'bmtg-private-journey' ) ) );
			exit;
		}

		$first    = isset( $_POST['first_name'] ) ? Schema::sanitize_text( wp_unslash( $_POST['first_name'] ), 191 ) : '';
		$last     = isset( $_POST['last_name'] ) ? Schema::sanitize_text( wp_unslash( $_POST['last_name'] ), 191 ) : '';
		$email    = isset( $_POST['email'] ) ? Schema::normalize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone    = isset( $_POST['phone'] ) ? Schema::sanitize_text( wp_unslash( $_POST['phone'] ), 40 ) : '';
		$password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$confirm  = isset( $_POST['password_confirm'] ) && is_string( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';
		$consent  = empty( $_POST['consent'] ) ? 0 : 1;
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( '' === $first || '' === $last ) {
			wp_safe_redirect( $this->back_with_error( 'registration', __( 'Please provide your full name.', 'bmtg-private-journey' ), $redirect ) );
			exit;
		}
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( $this->back_with_error( 'registration', __( 'Please provide a valid email address.', 'bmtg-private-journey' ), $redirect ) );
			exit;
		}
		if ( email_exists( $email ) ) {
			wp_safe_redirect( $this->back_with_error( 'registration', __( 'An account with this email already exists. Please sign in.', 'bmtg-private-journey' ), $redirect ) );
			exit;
		}
		if ( strlen( $password ) < 10 ) {
			wp_safe_redirect( $this->back_with_error( 'registration', __( 'Your password must be at least 10 characters.', 'bmtg-private-journey' ), $redirect ) );
			exit;
		}
		if ( $password !== $confirm ) {
			wp_safe_redirect( $this->back_with_error( 'registration', __( 'The passwords do not match.', 'bmtg-private-journey' ), $redirect ) );
			exit;
		}
		if ( ! $consent ) {
			wp_safe_redirect( $this->back_with_error( 'registration', __( 'Please accept the privacy notice.', 'bmtg-private-journey' ), $redirect ) );
			exit;
		}

		$user_id = wp_create_user( $email, $email, $password );
		if ( is_wp_error( $user_id ) ) {
			wp_safe_redirect( $this->back_with_error( 'registration', $user_id->get_error_message(), $redirect ) );
			exit;
		}

		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => $first,
				'last_name'  => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'       => Capabilities::ROLE_CLIENT,
			)
		);

		Clients::instance()->create(
			array(
				'email'      => $email,
				'first_name' => $first,
				'last_name'  => $last,
				'phone'      => $phone,
				'consent'    => $consent,
			),
			(int) $user_id
		);

		wp_set_auth_cookie( (int) $user_id, true, is_ssl() );
		wp_set_current_user( (int) $user_id );
		$this->on_login( $email, get_userdata( $user_id ) );

		\BMTG\PrivateJourney\Audit\Audit::instance()->log( 'client.register', 'client', (int) $user_id, array( 'email' => $email ) );

		wp_safe_redirect( Pages::url( 'dashboard' ) );
		exit;
	}

	public function handle_onboard(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( Pages::url( 'login' ) );
			exit;
		}
		$nonce = isset( $_POST['bmtg_pj_onboard_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bmtg_pj_onboard_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bmtg_pj_onboard' ) ) {
			wp_safe_redirect( $this->back_with_error( 'dashboard', __( 'Your session expired. Please try again.', 'bmtg-private-journey' ) ) );
			exit;
		}

		$user_id = get_current_user_id();
		$first   = isset( $_POST['first_name'] ) ? Schema::sanitize_text( wp_unslash( $_POST['first_name'] ), 191 ) : '';
		$last    = isset( $_POST['last_name'] ) ? Schema::sanitize_text( wp_unslash( $_POST['last_name'] ), 191 ) : '';
		$email   = isset( $_POST['email'] ) ? Schema::normalize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? Schema::sanitize_text( wp_unslash( $_POST['phone'] ), 40 ) : '';
		$consent = empty( $_POST['consent'] ) ? 0 : 1;

		if ( '' === $first || ! is_email( $email ) || ! $consent ) {
			wp_safe_redirect( $this->back_with_error( 'dashboard', __( 'Please complete your name, email and privacy consent.', 'bmtg-private-journey' ) ) );
			exit;
		}

		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => $first,
				'last_name'  => $last,
				'user_email' => $email,
				'display_name' => trim( $first . ' ' . $last ),
			)
		);

		$client = Clients::instance()->ensure_for_user( $user_id );
		if ( $client ) {
			Clients::instance()->update(
				(int) $client['id'],
				array(
					'first_name' => $first,
					'last_name'  => $last,
					'email'      => $email,
					'phone'      => $phone,
					'consent'    => $consent,
				)
			);
		}

		$preferences = isset( $_POST['preferences'] ) && is_array( $_POST['preferences'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['preferences'] ) ) : array();
		if ( $client ) {
			foreach ( $preferences as $key => $value ) {
				if ( '' === trim( (string) $value ) ) {
					continue;
				}
				Clients::instance()->set_preference( (int) $client['id'], (string) $key, (string) $value, 'onboarding' );
			}
		}

		$this->mark_onboarded( $user_id );
		\BMTG\PrivateJourney\Audit\Audit::instance()->log( 'client.onboard', 'client', $user_id );

		wp_safe_redirect( Pages::url( 'dashboard' ) );
		exit;
	}

	public function handle_logout(): void {
		$redirect = $this->logout_redirect();
		wp_logout();
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Send authenticated clients straight to their dashboard when they
	 * visit the welcome/login screen.
	 */
	public function maybe_redirect_authenticated(): void {
		if ( ! is_user_logged_in() || is_admin() || wp_doing_ajax() || defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! is_page() ) {
			return;
		}
		$page_id = get_queried_object_id();
		$login_id = Pages::page_id( 'login' );
		$welcome_id = Pages::page_id( 'welcome' );
		if ( $page_id && ( $page_id === $login_id || $page_id === $welcome_id ) ) {
			if ( ! Pages::page_id( 'dashboard' ) ) {
				return;
			}
			wp_safe_redirect( $this->login_redirect() );
			exit;
		}
	}

	private function back_with_error( string $screen, string $message, string $redirect = '' ): string {
		$base = $redirect ? $redirect : Pages::url( $screen );
		return add_query_arg(
			array(
				'pj_error' => $message,
			),
			$base
		);
	}

	public static function google_enabled(): bool {
		return (bool) Settings::get( 'auth', 'google_enable', 0 ) && '' !== trim( (string) Settings::get( 'auth', 'google_client_id', '' ) );
	}

	public static function google_client_id(): string {
		return (string) Settings::get( 'auth', 'google_client_id', '' );
	}

	/**
	 * Verify a Google ID token and sign the matching user in.
	 * Returns a WP_Error or the redirect URL.
	 */
	public static function login_with_google( string $credential ): array {
		$client_id = self::google_client_id();
		if ( ! $client_id ) {
			return array( 'ok' => false, 'error' => __( 'Google sign-in is not configured.', 'bmtg-private-journey' ) );
		}

		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$bucket = 'bmtg_pj_google_' . md5( $ip );
		$hits   = (int) get_transient( $bucket );
		if ( $hits > 20 ) {
			return array( 'ok' => false, 'error' => __( 'Too many attempts. Please try again later.', 'bmtg-private-journey' ) );
		}
		set_transient( $bucket, $hits + 1, MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			add_query_arg( array( 'id_token' => rawurlencode( $credential ) ), 'https://oauth2.googleapis.com/tokeninfo' ),
			array( 'timeout' => 12 )
		);
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => __( 'Google verification is unavailable right now.', 'bmtg-private-journey' ) );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ( $body['aud'] ?? '' ) !== $client_id ) {
			return array( 'ok' => false, 'error' => __( 'Google verification failed.', 'bmtg-private-journey' ) );
		}
		if ( empty( $body['email'] ) || empty( $body['email_verified'] ) || ! filter_var( $body['email_verified'], FILTER_VALIDATE_BOOLEAN ) ) {
			return array( 'ok' => false, 'error' => __( 'Your Google email is not verified.', 'bmtg-private-journey' ) );
		}
		if ( isset( $body['exp'] ) && (int) $body['exp'] < time() ) {
			return array( 'ok' => false, 'error' => __( 'Your Google session expired. Please try again.', 'bmtg-private-journey' ) );
		}

		$email = Schema::normalize_email( $body['email'] );
		$user  = get_user_by( 'email', $email );
		if ( ! $user ) {
			$user_id = wp_create_user( $email, wp_generate_password( 24, false, false ), wp_generate_password( 24, true, true ) );
			if ( is_wp_error( $user_id ) ) {
				return array( 'ok' => false, 'error' => $user_id->get_error_message() );
			}
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => sanitize_text_field( (string) ( $body['name'] ?? $email ) ),
					'role'         => Capabilities::ROLE_CLIENT,
				)
			);
			$user = get_userdata( $user_id );
		}

		if ( ! $user ) {
			return array( 'ok' => false, 'error' => __( 'The account could not be opened.', 'bmtg-private-journey' ) );
		}

		wp_set_auth_cookie( (int) $user->ID, true, is_ssl() );
		wp_set_current_user( (int) $user->ID );

		$client = Clients::instance()->ensure_for_user( (int) $user->ID );
		if ( $client ) {
			Basket::instance()->merge_guest_into_client( (int) $client['id'], (int) $user->ID );
			Clients::instance()->update( (int) $client['id'], array(
				'first_name' => $user->first_name ?: '',
				'last_name'  => $user->last_name ?: '',
				'email'      => $email,
			) );
		}

		$onboarded = (bool) get_user_meta( $user->ID, 'bmtg_pj_onboarded', true );
		return array(
			'ok'        => true,
			'redirect'  => $onboarded ? Pages::url( 'dashboard' ) : Pages::url( 'dashboard' ),
		);
	}
}