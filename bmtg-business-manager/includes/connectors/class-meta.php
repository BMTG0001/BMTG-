<?php
namespace BMTG\Connectors;

use BMTG\Integrations\Http_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta extends Abstract_Connector {
	public function id(): string { return 'meta'; }
	public function label(): string { return 'Meta (Facebook / WhatsApp)'; }

	public function fields(): array {
		return array(
			array(
				'key'      => 'app_id',
				'label'    => 'App ID',
				'secret'   => false,
				'required' => false,
				'help'     => 'Meta App ID (optional, for reference/signature context).',
			),
			array(
				'key'      => 'app_secret',
				'label'    => 'App Secret',
				'secret'   => true,
				'required' => false,
				'help'     => 'Meta App Secret used to validate webhook signatures (X-Hub-Signature-256). Recommended.',
			),
			array(
				'key'      => 'page_access_token',
				'label'    => 'Facebook Page Access Token',
				'secret'   => true,
				'required' => false,
				'help'     => 'Long-lived Page access token for Messenger auto-replies.',
			),
			array(
				'key'      => 'page_id',
				'label'    => 'Facebook Page ID',
				'secret'   => false,
				'required' => false,
				'help'     => 'Facebook Page ID for Messenger.',
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
				'help'     => 'WhatsApp Cloud API access token for auto-replies.',
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

	public function test( string $environment, array $resolved_values, Http_Client $http ): array {
		// Prefer testing whichever channel is configured.
		$fb = $resolved_values['page_access_token'] ?? '';
		$wa = $resolved_values['whatsapp_access_token'] ?? '';
		$wa_id = $resolved_values['whatsapp_phone_number_id'] ?? '';
		if ( $fb ) {
			$res = $http->request( 'GET', 'https://graph.facebook.com/v20.0/me?fields=id,name', array(
				'headers' => array( 'Authorization' => 'Bearer ' . $fb ),
				'timeout' => 20,
			) );
			if ( $res['status'] >= 200 && $res['status'] < 300 ) {
				return $this->ok( 'Meta/Facebook connection successful.' );
			}
			return $this->fail( $res['error'] ?: 'Meta/Facebook test failed.' );
		}
		if ( $wa && $wa_id ) {
			$res = $http->request( 'GET', 'https://graph.facebook.com/v20.0/' . rawurlencode( $wa_id ), array(
				'headers' => array( 'Authorization' => 'Bearer ' . $wa ),
				'timeout' => 20,
			) );
			if ( $res['status'] >= 200 && $res['status'] < 300 ) {
				return $this->ok( 'Meta/WhatsApp connection successful.' );
			}
			return $this->fail( $res['error'] ?: 'Meta/WhatsApp test failed.' );
		}
		return $this->fail( 'Provide Facebook Page Access Token or WhatsApp Access Token + Phone Number ID to test.' );
	}
}
