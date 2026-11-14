<?php
namespace BMTG\Integrations;

use BMTG\Connectors\Connector_Interface;
use BMTG\Connectors\Groq;
use BMTG\Connectors\OpenRouter;
use BMTG\Connectors\Unsplash;
use BMTG\Connectors\Canva;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Registry {
	/** @var array<string, Connector_Interface> */
	private array $connectors = array();

	public function register_defaults(): void {
		$defaults = array(
			new Groq(),
			new OpenRouter(),
			new Unsplash(),
			new Canva(),
		);
		/** @var Connector_Interface[] $connectors */
		$connectors = apply_filters( 'bmtg_connectors', $defaults );
		foreach ( $connectors as $connector ) {
			if ( $connector instanceof Connector_Interface ) {
				$this->connectors[ $connector->id() ] = $connector;
			}
		}
	}

	public function get( string $id ): ?Connector_Interface {
		return $this->connectors[ $id ] ?? null;
	}

	/** @return Connector_Interface[] */
	public function all(): array {
		return array_values( $this->connectors );
	}
}
