<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu(): void {
		if ( ! Capabilities::can_view_leads() && ! Capabilities::can_view_customers() ) {
			return;
		}
		add_menu_page(
			__( 'BMTG CRM', 'bmtg-business-manager' ),
			__( 'BMTG CRM', 'bmtg-business-manager' ),
			Capabilities::VIEW_LEADS,
			'bmtg-crm',
			array( $this, 'render' ),
			'dashicons-groups',
			26
		);
		add_submenu_page( 'bmtg-crm', __( 'Leads', 'bmtg-business-manager' ), __( 'Leads', 'bmtg-business-manager' ), Capabilities::VIEW_LEADS, 'bmtg-crm', array( $this, 'render' ) );
		add_submenu_page( 'bmtg-crm', __( 'Customers', 'bmtg-business-manager' ), __( 'Customers', 'bmtg-business-manager' ), Capabilities::VIEW_CUSTOMERS, 'bmtg-crm-customers', array( $this, 'render' ) );
		add_submenu_page( 'bmtg-crm', __( 'Reminders', 'bmtg-business-manager' ), __( 'Reminders', 'bmtg-business-manager' ), Capabilities::VIEW_LEADS, 'bmtg-crm-reminders', array( $this, 'render' ) );
	}

	public function assets( string $hook ): void {
		if ( strpos( $hook, 'bmtg-crm' ) === false ) {
			return;
		}
		wp_enqueue_style( 'bmtg-crm-admin', BMTG_PLUGIN_URL . 'admin/crm/css/admin.css', array(), BMTG_VERSION );
		wp_enqueue_script( 'bmtg-crm-admin', BMTG_PLUGIN_URL . 'admin/crm/js/admin.js', array(), BMTG_VERSION, true );
		wp_localize_script( 'bmtg-crm-admin', 'BMTGCRM', array(
			'restUrl' => esc_url_raw( rest_url( 'bmtg/v1/crm' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'page'    => isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : 'bmtg-crm',
			'can'     => array(
				'manageLeads' => Capabilities::can_manage_leads(),
				'assignLeads' => Capabilities::can_assign_leads() || Capabilities::can_manage_leads(),
				'viewAll'     => Capabilities::can_view_all_leads(),
				'manageCustomers' => Capabilities::can_manage_customers(),
				'manageReminders' => Capabilities::can_manage_reminders(),
			),
		) );
	}

	public function render(): void {
		if ( ! Capabilities::can_view_leads() && ! Capabilities::can_view_customers() ) {
			wp_die( esc_html__( 'Permission denied.', 'bmtg-business-manager' ) );
		}
		echo '<div class="wrap"><h1>BMTG CRM</h1><div id="bmtg-crm-root" class="bmtg-crm-root"></div></div>';
	}
}
