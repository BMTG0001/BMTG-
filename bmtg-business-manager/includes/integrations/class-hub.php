<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub {
	private static ?Hub $instance = null;
	private ?Registry $registry = null;
	private ?Config $config = null;
	private ?Credential_Store $credential_store = null;
	private ?Token_Store $token_store = null;
	private ?OAuth_Flow $oauth_flow = null;
	private ?Http_Client $http_client = null;
	private ?Health $health = null;
	private ?Audit $audit = null;
	private ?Manager $manager = null;
	private ?Rest $rest = null;
	private ?Key_Rotation $key_rotation = null;

	public static function instance(): Hub {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		Activator::add_capability();
		$migrations = new Migrations();
		$migrations->maybe_migrate();

		$this->audit            = new Audit();
		$this->config           = new Config();
		$this->credential_store = new Credential_Store( $this->config, $this->audit );
		$this->token_store      = new Token_Store( $this->config, $this->audit );
		$this->http_client      = new Http_Client();
		$this->registry         = new Registry();
		$this->oauth_flow       = new OAuth_Flow( $this->config, $this->credential_store, $this->token_store, $this->http_client, $this->audit );
		$this->manager          = new Manager( $this->registry, $this->config, $this->credential_store, $this->token_store, $this->http_client, $this->audit, $this->oauth_flow );
		$this->health           = new Health( $this->registry, $this->manager, $this->audit );
		$this->rest             = new Rest( $this->registry, $this->config, $this->credential_store, $this->token_store, $this->oauth_flow, $this->manager, $this->health, $this->audit );
		$this->key_rotation     = new Key_Rotation( $this->config, $this->credential_store, $this->token_store, $this->audit );

		$this->registry->register_defaults();
		$this->rest->register_hooks();
		$this->health->register_hooks();
		$this->key_rotation->register_hooks();

		// Public website chatbot (does not replace existing bmtg-chat plugin; integrates via hooks).
		if ( ! defined( 'BMTG_CHATBOT_DISABLE' ) ) {
			$chatbot = new Chatbot( $this );
			$chatbot->register_hooks();
		}

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public function register_admin_menu(): void {
		add_options_page(
			__( 'BMTG Integrations', 'bmtg-business-manager' ),
			__( 'BMTG Integrations', 'bmtg-business-manager' ),
			Activator::CAPABILITY,
			'bmtg-integrations',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_bmtg-integrations' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'bmtg-integrations-admin', BMTG_PLUGIN_URL . 'admin/integrations/css/admin.css', array(), BMTG_VERSION );
		wp_enqueue_script( 'bmtg-integrations-admin', BMTG_PLUGIN_URL . 'admin/integrations/js/admin.js', array(), BMTG_VERSION, true );
		wp_localize_script( 'bmtg-integrations-admin', 'BMTGIntegrations', array(
			'restUrl'   => esc_url_raw( rest_url( 'bmtg/v1/integrations' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'canManage' => current_user_can( Activator::CAPABILITY ),
		) );
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( Activator::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'bmtg-business-manager' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'BMTG Integrations', 'bmtg-business-manager' ) . '</h1>';
		echo '<div id="bmtg-integrations-root" aria-live="polite"></div></div>';
	}

	public function admin_notices(): void {
		if ( ! current_user_can( Activator::CAPABILITY ) ) {
			return;
		}
		if ( ! $this->config || ! $this->config->is_key_configured() ) {
			$message = $this->config ? $this->config->key_error_message() : __( 'Encryption key is not configured.', 'bmtg-business-manager' );
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'BMTG Integrations:', 'bmtg-business-manager' ) . '</strong> ' . esc_html( $message ) . '</p></div>';
		}
	}

	public function registry(): Registry { return $this->registry; }
	public function config(): Config { return $this->config; }
	public function credential_store(): Credential_Store { return $this->credential_store; }
	public function token_store(): Token_Store { return $this->token_store; }
	public function manager(): Manager { return $this->manager; }
	public function health(): Health { return $this->health; }
	public function audit(): Audit { return $this->audit; }
	public function key_rotation(): Key_Rotation { return $this->key_rotation; }
}
