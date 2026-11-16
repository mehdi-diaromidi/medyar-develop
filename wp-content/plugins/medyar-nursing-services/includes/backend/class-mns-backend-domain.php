<?php
/**
 * Backend module: domain services (T-0.5). Pure classes; no UI hooks required.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Domain {

	public static function boot(): void {
		// Eager-load domain classes so fatal typos surface in Safe boot, not mid-request.
		class_exists( 'MNS_Time', true );
		class_exists( 'MNS_Nurse_Schedule', true );
		class_exists( 'MNS_Eligibility', true );
		class_exists( 'MNS_Availability', true );
		class_exists( 'MNS_Order_Machine', true );
	}
}
