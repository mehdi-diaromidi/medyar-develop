<?php
/**
 * Backend module: nurse applications + SMS hooks (T-1.1 / T-1.5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Nurses {

	public static function boot(): void {
		if ( MNS_Migrations::tables_ready() ) {
			MNS_Contract::ensure_seed();
		}
		MNS_Sms::boot();
		MNS_Admin_Nurses::boot();
		MNS_Nurse_Signup::boot();
		MNS_REST_Nurse::boot();
	}
}
