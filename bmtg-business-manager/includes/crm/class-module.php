<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Module {
	private static bool $booted = false;
	private ?Customer_Service $customers = null;
	private ?Lead_Service $leads = null;
	private ?Reminder_Service $reminders = null;
	private ?Rest $rest = null;

	public static function boot(): void {
		if ( self::$booted ) return;
		self::$booted = true;
		Capabilities::add_caps();
		( new Migrations() )->maybe_migrate();
		$module = new self();
		$module->init();
	}

	private function init(): void {
		$this->customers = new Customer_Service();
		$this->leads = new Lead_Service( $this->customers );
		$this->reminders = new Reminder_Service();
		$this->rest = new Rest( $this->customers, $this->leads, $this->reminders );
		$this->rest->register_hooks();

		add_filter( 'bmtg_create_or_link_crm_lead', array( $this->leads, 'create_or_link_from_chatbot' ), 10, 3 );
		add_action( 'bmtg_unified_inbox_event', array( $this->leads, 'handle_inbox_event' ), 10, 1 );
	}
}
