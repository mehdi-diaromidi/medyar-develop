<?php
/**
 * Table name helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Db {

	public static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'arp_' . $suffix;
	}

	public static function job_types(): string {
		return self::table( 'job_types' );
	}

	public static function services(): string {
		return self::table( 'services' );
	}

	public static function commission_rules(): string {
		return self::table( 'commission_rules' );
	}

	public static function referral_statuses(): string {
		return self::table( 'referral_statuses' );
	}

	public static function referrals(): string {
		return self::table( 'referrals' );
	}

	public static function commissions(): string {
		return self::table( 'commissions' );
	}

	public static function settlements(): string {
		return self::table( 'settlements' );
	}

	public static function settlement_items(): string {
		return self::table( 'settlement_items' );
	}

	public static function referral_logs(): string {
		return self::table( 'referral_logs' );
	}
}
