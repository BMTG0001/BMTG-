<?php
namespace BMTG\PrivateJourney\Core;

use BMTG\PrivateJourney\Admin\Admin;
use BMTG\PrivateJourney\Auth\Auth;
use BMTG\PrivateJourney\Documents\Documents;
use BMTG\PrivateJourney\Frontend\Assets;
use BMTG\PrivateJourney\Frontend\Forms;
use BMTG\PrivateJourney\Frontend\Renderer;
use BMTG\PrivateJourney\Rest\Rest;
use BMTG\PrivateJourney\Seo\Meta;
use BMTG\PrivateJourney\Seo\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap and service container.
 */
final class Plugin {
	private static ?Plugin $instance = null;
	private bool $booted = false;

	private ?Assets $assets = null;
	private ?Shortcodes $shortcodes = null;
	private ?Forms $forms = null;
	private ?Auth $auth = null;
	private ?Documents $documents = null;
	private ?Rest $rest = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this, 'register_query_vars' ), 2 );
		add_filter( 'plugin_action_links_' . BMTG_PJ_BASENAME, array( $this, 'plugin_action_links' ) );

		$this->maybe_upgrade();

		$this->assets = new Assets();
		$this->assets->register();

		$this->shortcodes = new Shortcodes( new Renderer() );
		$this->shortcodes->register();

		$this->auth = new Auth();
		$this->auth->register();

		\BMTG\PrivateJourney\Journey\Basket::instance()->register();

		$this->forms = new Forms();
		$this->forms->register();

		$this->documents = new Documents();
		$this->documents->register();

		$this->rest = new Rest();
		$this->rest->register();

		( new Meta() )->register();
		( new Sitemap() )->register();

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'inline_brand_css' ), 99 );
	}

	private function maybe_upgrade(): void {
		$installed = (string) get_option( 'bmtg_pj_db_version', '' );
		if ( BMTG_PJ_DB_VERSION === $installed ) {
			return;
		}
		( new Migrations() )->migrate();
		Capabilities::install();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'bmtg-private-journey', false, dirname( BMTG_PJ_BASENAME ) . '/languages' );
	}

	public function register_query_vars(): void {
		add_filter(
			'query_vars',
			static function ( $vars ) {
				$vars[] = 'pj_item';
				$vars[] = 'pj_category';
				return $vars;
			}
		);
	}

	public function inline_brand_css(): void {
		if ( is_admin() ) {
			return;
		}
		$css = ':root{' . Brand::css_variables() . '}';
		wp_register_style( 'bmtg-pj-brand-inline', false, array(), BMTG_PJ_VERSION );
		wp_enqueue_style( 'bmtg-pj-brand-inline' );
		wp_add_inline_style( 'bmtg-pj-brand-inline', $css );
	}

	public function plugin_action_links( array $links ): array {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=bmtg-private-journey-settings' ) ) . '">' . esc_html__( 'Settings', 'bmtg-private-journey' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	public function renderer(): Renderer {
		if ( ! $this->shortcodes ) {
			return new Renderer();
		}
		return $this->shortcodes->renderer();
	}
}