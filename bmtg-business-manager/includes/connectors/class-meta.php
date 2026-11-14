<?php
namespace BMTG\Connectors;

use BMTG\Integrations\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta extends Abstract_Connector {
	public function id(): string { return 'meta'; }
	public function label(): string { return 'Meta (Facebook / Instagram / WhatsApp)'; }
	public function auth_type(): string { return 'oauth'; }

	public function fields(): array {
		return array(
			array(
				'key'      => 'client_id',
				'label'    => 'Meta App ID (Client ID)',
				'secret'   => false,
				'required' => true,
				'help'     => 'From Meta App Dashboard. Required for simple Connect (OAuth).',
			),
			array(
				'key'      => 'client_secret',
				'label'    => 'Meta App Secret (Client Secret)',
				'secret'   => true,
				'required' => true,
				'help'     => 'From Meta App Dashboard. Used for OAuth and webhook signature validation.',
			),
			array(
				'key'      => 'page_access_token',
				'label'    => 'Facebook Page Access Token',
				'secret'   => true,
				'required' => false,
				'help'     => 'Optional manual/advanced: long-lived Page access token for Messenger auto-replies (if not using OAuth token).',
			),
			array(
				'key'      => 'page_id',
				'label'    => 'Facebook Page ID',
				'secret'   => false,
				'required' => false,
				'help'     => 'Facebook Page ID for Messenger/Instagram.',
			),
			array(
				'key'      => 'instagram_user_id',
				'label'    => 'Instagram User ID',
				'secret'   => false,
				'required' => false,
				'help'     => 'Instagram Business/Creator account ID (for auto-posting). Often resolved from connected Page.',
			),
			array(
				'key'      => 'fb_verify_token',
				'label'    => 'Facebook Webhook Verify Token',
				'secret'   => true,
				'required' => false,
				'help'     => 'Token you set in Meta App webhook verification for Messenger.',
			),
			array(
				'key'      => 'whatsapp_access_token',
				'label'    => 'WhatsApp Access Token',
				'secret'   => true,
				'required' => false,
				'help'     => 'WhatsApp Cloud API access token for auto-replies (if separate from OAuth).',
			),
			array(
				'key'      => 'whatsapp_phone_number_id',
				'label'    => 'WhatsApp Phone Number ID',
				'secret'   => false,
				'required' => false,
				'help'     => 'WhatsApp Business phone number ID (Cloud API).',
			),
			array(
				'key'      => 'whatsapp_verify_token',
				'label'    => 'WhatsApp Webhook Verify Token',
				'secret'   => true,
				'required' => false,
				'help'     => 'Token you set in Meta webhook verification for WhatsApp.',
			),
		);
	}

	public function scopes(): array {
		return array(
			'public_profile',
			'pages_show_list',
			'pages_read_engagement',
			'pages_messaging',
			'pages_manage_metadata',
			'instagram_basic',
			'instagram_content_publish',
			'whatsapp_business_management',
			'whatsapp_business_messaging',
			'business_management',
		);
	}

	public function authorize_url( string $environment, array $params ): string {
		// Facebook Login dialog (works for FB Pages + IG + WA permissions as granted).
		return 'https://www.facebook.com/v20.0/dialog/oauth?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	public function token_url( string $environment ): string {
		return 'https://graph.facebook.com/v20.0/oauth/access_token';
	}

	public function exchange_code( string $code, string $redirect_uri, string $code_verifier, string $environment, array $resolved_values, Http_Client $http ): array {
		$client_id = $resolved_values['client_id'] ?? '';
		$client_secret = $resolved_values['client_secret'] ?? '';
		if ( ! $client_id || ! $client_secret ) {
			return array( 'ok' => false, 'error' => 'Meta App ID and App Secret are required.' );
		}
		$res = $http->request( 'GET', $this->token_url( $environment ), array(
			'query' => array(
				'client_id' => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri' => $redirect_uri,
				'code' => $code,
			),
			'timeout' => 30,
		) );
		if ( $res['status'] < 200 || $res['status'] >= 300 || empty( $res['body']['access_token'] ) ) {
			return array( 'ok' => false, 'error' => $res['error'] ?? 'Token exchange failed.' );
		}
		// Try to exchange for long-lived token
		$token = $res['body']['access_token'];
		$ll = $http->request( 'GET', $this->token_url( $environment ), array(
			'query' => array(
				'grant_type' => 'fb_exchange_token',
				'client_id' => $client_id,
				'client_secret' => $client_secret,
				'fb_exchange_token' => $token,
			),
			'timeout' => 30,
		) );
		if ( $ll['status'] >= 200 && $ll['status'] < 300 && ! empty( $ll['body']['access_token'] ) ) {
			$token = $ll['body']['access_token'];
			if ( ! empty( $ll['body']['expires_in'] ) ) $res['body']['expires_in'] = $ll['body']['expires_in'];
		}
		return array(
			'ok' => true,
			'access_token' => $token,
			'token_type' => $res['body']['token_type'] ?? 'bearer',
			'expires_in' => $res['body']['expires_in'] ?? null,
		);
	}

	public function refresh( string $refresh_token, string $environment, array $resolved_values, Http_Client $http ): array {
		// Facebook long-lived user tokens can be refreshed by exchanging again; attempt exchange.
		$client_id = $resolved_values['client_id'] ?? '';
		$client_secret = $resolved_values['client_secret'] ?? '';
		if ( ! $client_id || ! $client_secret || ! $refresh_token ) {
			return array( 'ok' => false, 'error' => 'Missing credentials to refresh Meta token.' );
		}
		$ll = $http->request( 'GET', $this->token_url( $environment ), array(
			'query' => array(
				'grant_type' => 'fb_exchange_token',
				'client_id' => $client_id,
				'client_secret' => $client_secret,
				'fb_exchange_token' => $refresh_token,
			),
			'timeout' => 30,
		) );
		if ( $ll['status'] >= 200 && $ll['status'] < 300 && ! empty( $ll['body']['access_token'] ) ) {
			return array( 'ok' => true, 'access_token' => $ll['body']['access_token'], 'expires_in' => $ll['body']['expires_in'] ?? null, 'refresh_token' => $refresh_token );
		}
		return array( 'ok' => false, 'error' => $ll['error'] ?? 'Meta token refresh failed. Reconnect may be required.' );
	}

	public function test( string $environment, array $resolved_values, Http_Client $http ): array {
		$token = $resolved_values['access_token'] ?? ($resolved_values['page_access_token'] ?? '');
		$fb = $resolved_values['page_access_token'] ?? $token;
		$wa = $resolved_values['whatsapp_access_token'] ?? $token;
		$wa_id = $resolved_values['whatsapp_phone_number_id'] ?? '';
		$ok = false; $msgs = array();
		if ( $fb ) {
			$res = $http->request( 'GET', 'https://graph.facebook.com/v20.0/me?fields=id,name', array(
				'headers' => array( 'Authorization' => 'Bearer ' . $fb ),
				'timeout' => 20,
			) );
			if ( $res['status'] >= 200 && $res['status'] < 300 ) { $ok = true; $msgs[] = 'Facebook/Meta token valid'; }
			else { $msgs[] = 'Facebook check: ' . ($res['error'] ?: 'failed'); }
		}
		if ( $wa && $wa_id ) {
			$res = $http->request( 'GET', 'https://graph.facebook.com/v20.0/' . rawurlencode( $wa_id ), array(
				'headers' => array( 'Authorization' => 'Bearer ' . $wa ),
				'timeout' => 20,
			) );
			if ( $res['status'] >= 200 && $res['status'] < 300 ) { $ok = true; $msgs[] = 'WhatsApp valid'; }
			else { $msgs[] = 'WhatsApp check: ' . ($res['error'] ?: 'failed'); }
		}
		if ( $ok ) return $this->ok( implode('; ', $msgs) ?: 'Meta connection successful.' );
		if ( ! $fb && ! ($wa && $wa_id) ) return $this->fail( 'Provide/connect a Meta token or configure Page/WhatsApp credentials to test.' );
		return $this->fail( implode('; ', $msgs) ?: 'Meta connection test failed.' );
	}
}
