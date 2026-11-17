<?php
/**
 * Backend module: settings (T-0.3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Settings {

	public static function boot(): void {
		if ( is_admin() ) {
			MNS_Admin_Settings::boot();
		}
	}
}
