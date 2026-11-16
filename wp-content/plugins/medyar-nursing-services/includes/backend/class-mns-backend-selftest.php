<?php
/**
 * Backend module: self-test + reset (T-0.6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Selftest {

	public static function boot(): void {
		MNS_Admin_Selftest::boot();
		MNS_Admin_E2E::boot();
		MNS_Admin_Reset::boot();
	}
}
