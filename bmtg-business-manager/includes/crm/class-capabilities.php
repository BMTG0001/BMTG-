<?php
namespace BMTG\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {
	public const VIEW_LEADS       = 'bmtg_crm_view_leads';
	public const MANAGE_LEADS     = 'bmtg_crm_manage_leads';
	public const ASSIGN_LEADS     = 'bmtg_crm_assign_leads';
	public const VIEW_ALL_LEADS   = 'bmtg_crm_view_all_leads';
	public const VIEW_CUSTOMERS   = 'bmtg_crm_view_customers';
	public const MANAGE_CUSTOMERS = 'bmtg_crm_manage_customers';
	public const MANAGE_REMINDERS = 'bmtg_crm_manage_reminders';

	public static function all(): array {
		return array(
			self::VIEW_LEADS,
			self::MANAGE_LEADS,
			self::ASSIGN_LEADS,
			self::VIEW_ALL_LEADS,
			self::VIEW_CUSTOMERS,
			self::MANAGE_CUSTOMERS,
			self::MANAGE_REMINDERS,
		);
	}

	public static function add_caps(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( self::all() as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function can_view_leads(): bool {
		return current_user_can( self::VIEW_LEADS ) || current_user_can( self::VIEW_ALL_LEADS ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_leads(): bool {
		return current_user_can( self::MANAGE_LEADS ) || current_user_can( 'manage_options' );
	}

	public static function can_assign_leads(): bool {
		return current_user_can( self::ASSIGN_LEADS ) || current_user_can( 'manage_options' );
	}

	public static function can_view_all_leads(): bool {
		return current_user_can( self::VIEW_ALL_LEADS ) || current_user_can( 'manage_options' );
	}

	public static function can_view_customers(): bool {
		return current_user_can( self::VIEW_CUSTOMERS ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_customers(): bool {
		return current_user_can( self::MANAGE_CUSTOMERS ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_reminders(): bool {
		return current_user_can( self::MANAGE_REMINDERS ) || current_user_can( 'manage_options' );
	}
}
