<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Integration_Exception extends \RuntimeException {
	protected string $connector_id = '';

	public function __construct( string $message = '', string $connector_id = '', int $code = 0, ?\Throwable $previous = null ) {
		$this->connector_id = $connector_id;
		parent::__construct( $message, $code, $previous );
	}

	public function get_connector_id(): string {
		return $this->connector_id;
	}

	public function get_user_message(): string {
		return $this->getMessage();
	}
}

class Not_Configured_Exception extends Integration_Exception {
	public function get_user_message(): string {
		$label = $this->connector_id ? ucfirst( $this->connector_id ) : 'This service';
		return sprintf( 'Connect %s in Integrations to continue.', $label );
	}
}

class Disabled_Exception extends Integration_Exception {
	public function get_user_message(): string {
		$label = $this->connector_id ? ucfirst( $this->connector_id ) : 'This service';
		return sprintf( '%s is disabled in Integrations.', $label );
	}
}

class Expired_Exception extends Integration_Exception {
	public function get_user_message(): string {
		$label = $this->connector_id ? ucfirst( $this->connector_id ) : 'This service';
		return sprintf( '%s connection expired. Reconnect in Integrations.', $label );
	}
}

class Error_Exception extends Integration_Exception {
	public function get_user_message(): string {
		$label = $this->connector_id ? ucfirst( $this->connector_id ) : 'Integration';
		$msg   = $this->getMessage();
		return $msg ? sprintf( '%s error: %s', $label, $msg ) : sprintf( '%s is in an error state. Check Integrations.', $label );
	}
}

class Misconfigured_Exception extends Integration_Exception {
	public function get_user_message(): string {
		return $this->getMessage() ?: 'Integration is misconfigured.';
	}
}
