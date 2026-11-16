<?php
namespace BMTG\PrivateJourney\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability names and role grants.
 */
class Capabilities {
	public const MANAGE          = 'bmtg_pj_manage';
	public const VIEW_REQUESTS   = 'bmtg_pj_view_requests';
	public const MANAGE_REQUESTS = 'bmtg_pj_manage_requests';
	public const MANAGE_CATALOG  = 'bmtg_pj_manage_catalogue';
	public const MANAGE_SUPPLIERS = 'bmtg_pj_manage_suppliers';
	public const VIEW_PRICING    = 'bmtg_pj_view_pricing';
	public const MANAGE_PRICING  = 'bmtg_pj_manage_pricing';
	public const MANAGE_QUOTES   = 'bmtg_pj_manage_quotations';
	public const ISSUE_QUOTES    = 'bmtg_pj_issue_quotations';
	public const MANAGE_DOCS     = 'bmtg_pj_manage_documents';
	public const VIEW_REPORTS    = 'bmtg_pj_view_reports';
	public const MANAGE_SETTINGS = 'bmtg_pj_manage_settings';
	public const CLIENT          = 'bmtg_pj_client';

	public const ROLE_SALES = 'bmtg_pj_sales';
	public const ROLE_OPS   = 'bmtg_pj_operations';
	public const ROLE_FIN   = 'bmtg_pj_finance';
	public const ROLE_CLIENT = 'bmtg_pj_client';

	public static function all(): array {
		return array(
			self::MANAGE,
			self::VIEW_REQUESTS,
			self::MANAGE_REQUESTS,
			self::MANAGE_CATALOG,
			self::MANAGE_SUPPLIERS,
			self::VIEW_PRICING,
			self::MANAGE_PRICING,
			self::MANAGE_QUOTES,
			self::ISSUE_QUOTES,
			self::MANAGE_DOCS,
			self::VIEW_REPORTS,
			self::MANAGE_SETTINGS,
			self::CLIENT,
		);
	}

	public static function roles(): array {
		return array(
			self::ROLE_SALES => array(
				self::VIEW_REQUESTS,
				self::MANAGE_REQUESTS,
				self::MANAGE_QUOTES,
				self::ISSUE_QUOTES,
			),
			self::ROLE_OPS => array(
				self::VIEW_REQUESTS,
				self::MANAGE_REQUESTS,
				self::MANAGE_DOCS,
			),
			self::ROLE_FIN => array(
				self::VIEW_REQUESTS,
				self::VIEW_PRICING,
				self::MANAGE_PRICING,
				self::VIEW_REPORTS,
			),
			self::ROLE_CLIENT => array(
				self::CLIENT,
			),
		);
	}

	public static function install(): void {
		foreach ( self::roles() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				add_role(
					$role_name,
					ucwords( str_replace( array( 'bmtg_pj_', '_' ), array( '', ' ' ), $role_name ) ),
					array( 'read' => true )
				);
				$role = get_role( $role_name );
			}
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				if ( ! $admin->has_cap( $cap ) ) {
					$admin->add_cap( $cap );
				}
			}
		}
	}

	public static function uninstall(): void {
		foreach ( self::roles() as $role_name => $caps ) {
			remove_role( $role_name );
		}
	}

	public static function can_manage(): bool {
		return current_user_can( self::MANAGE ) || current_user_can( 'manage_options' );
	}

	public static function can_view_requests(): bool {
		return self::can_manage() || current_user_can( self::VIEW_REQUESTS );
	}

	public static function can_manage_requests(): bool {
		return self::can_manage() || current_user_can( self::MANAGE_REQUESTS );
	}

	public static function can_manage_catalog(): bool {
		return self::can_manage() || current_user_can( self::MANAGE_CATALOG );
	}

	public static function can_manage_suppliers(): bool {
		return self::can_manage() || current_user_can( self::MANAGE_SUPPLIERS );
	}

	public static function can_view_pricing(): bool {
		return self::can_manage() || current_user_can( self::VIEW_PRICING ) || current_user_can( self::MANAGE_PRICING );
	}

	public static function can_manage_pricing(): bool {
		return self::can_manage() || current_user_can( self::MANAGE_PRICING );
	}

	public static function can_manage_quotes(): bool {
		return self::can_manage() || current_user_can( self::MANAGE_QUOTES );
	}

	public static function can_issue_quotes(): bool {
		return self::can_manage() || current_user_can( self::ISSUE_QUOTES );
	}

	public static function can_manage_docs(): bool {
		return self::can_manage() || current_user_can( self::MANAGE_DOCS );
	}

	public static function can_view_reports(): bool {
		return self::can_manage() || current_user_can( self::VIEW_REPORTS );
	}

	public static function can_manage_settings(): bool {
		return self::can_manage() || current_user_can( self::MANAGE_SETTINGS );
	}
}