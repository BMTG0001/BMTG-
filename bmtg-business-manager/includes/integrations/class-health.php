<?php
namespace BMTG\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health {
	public const CRON_HOOK = 'bmtg_integrations_daily_health_check';

	private Registry $registry;
	private Manager $manager;
	private Audit $audit;

	public function __construct( Registry $registry, Manager $manager, Audit $audit ) {
		$this->registry = $registry;
		$this->manager  = $manager;
		$this->audit    = $audit;
	}

	public function register_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_checks' ) );
	}

	public function run_scheduled_checks(): void {
		foreach ( array( 'live', 'sandbox' ) as $env ) {
			foreach ( $this->registry->all() as $connector ) {
				$status = $this->manager->effective_status( $connector, $env );
				if ( in_array( $status, array( Token_Store::STATUS_DISABLED, Token_Store::STATUS_NOT_CONFIGURED ), true ) ) {
					continue;
				}
				$result = $this->manager->run_test( $connector, $env, true );
				if ( empty( $result['ok'] ) ) {
					$this->maybe_notify_failure( $connector->id(), $env, $result['message'] ?? 'Health check failed.' );
				} else {
					delete_option( 'bmtg_notify_fail_count_' . $connector->id() . '_' . $env );
					delete_transient( 'bmtg_notify_sent_' . $connector->id() . '_' . $env );
				}
			}
		}
	}

	public function maybe_notify_failure( string $connector_id, string $environment, string $message ): void {
		$opt   = 'bmtg_notify_fail_count_' . $connector_id . '_' . $environment;
		$sent  = 'bmtg_notify_sent_' . $connector_id . '_' . $environment;
		$count = (int) get_option( $opt, 0 );
		$count++;
		update_option( $opt, $count, false );
		if ( $count < 2 || get_transient( $sent ) ) {
			return; // one notification per repeated failure window
		}
		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			$subject = sprintf( '[%s] Integration issue: %s (%s)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $connector_id, $environment );
			$body    = sprintf(
				"The BMTG Integrations Hub detected a repeated failure.\n\nConnector: %s\nEnvironment: %s\nMessage: %s\n\nCheck Integrations settings in WordPress admin.",
				$connector_id,
				$environment,
				wp_strip_all_tags( $message )
			);
			wp_mail( $admin_email, $subject, $body );
		}
		set_transient( $sent, 1, DAY_IN_SECONDS );
	}
}
