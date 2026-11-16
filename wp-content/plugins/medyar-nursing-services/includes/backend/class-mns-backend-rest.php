<?php
/**
 * Backend module: REST base (T-0.4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Rest {

	public static function boot(): void {
		MNS_REST::boot();
	}
}
