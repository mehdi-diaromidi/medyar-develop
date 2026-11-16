<?php
/**
 * Backend module: services catalog (T-3.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Catalog {

	public static function boot(): void {
		class_exists( 'MNS_Services', true );
		class_exists( 'MNS_Services_Seed', true );
		class_exists( 'MNS_Admin_Services', true );
		MNS_Admin_Services::boot();
	}
}
