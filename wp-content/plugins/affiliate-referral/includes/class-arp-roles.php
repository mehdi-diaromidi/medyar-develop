<?php
/**
 * Affiliate role and capabilities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Roles {

	public const ROLE = 'affiliate';

	public const CAP_VIEW_OWN   = 'arp_view_own_referrals';
	public const CAP_SUBMIT     = 'arp_submit_referral';
	public const CAP_MANAGE     = 'manage_arp';

	public static function register(): void {
		$caps = array(
			'read'               => true,
			self::CAP_VIEW_OWN   => true,
			self::CAP_SUBMIT     => true,
		);

		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, 'همکار', $caps );
		} else {
			foreach ( $caps as $cap => $grant ) {
				if ( $grant ) {
					$role->add_cap( $cap );
				}
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE );
			$admin->add_cap( self::CAP_VIEW_OWN );
			$admin->add_cap( self::CAP_SUBMIT );
		}
	}

	public static function remove_caps_on_deactivate(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( self::CAP_MANAGE );
		}
		// Keep affiliate role so existing users are not broken.
	}

	/**
	 * Whether the user is treated as an affiliate for front-end panel access.
	 * Administrators (مدیر کل) count as affiliates for testing / dual use.
	 */
	public static function user_is_affiliate( ?int $user_id = null ): bool {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return false;
		}
		if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
			return true;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Ensure affiliate meta exists (e.g. admin opening the panel without signup).
	 */
	public static function ensure_affiliate_meta( int $user_id ): void {
		if ( ! $user_id ) {
			return;
		}
		$code = (string) get_user_meta( $user_id, 'arp_referral_code', true );
		if ( '' === $code ) {
			update_user_meta( $user_id, 'arp_referral_code', Arp_Referral_Code::generate_unique() );
		}
		if ( ! get_user_meta( $user_id, 'arp_registered_at', true ) ) {
			update_user_meta( $user_id, 'arp_registered_at', current_time( 'mysql' ) );
		}
	}
}
