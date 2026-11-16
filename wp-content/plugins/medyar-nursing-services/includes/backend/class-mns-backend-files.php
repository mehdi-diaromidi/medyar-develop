<?php
/**
 * Backend module: private files (T-0.7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Files {

	public static function boot(): void {
		MNS_Files::ensure_dir();
		MNS_REST_Files::boot();
	}
}
