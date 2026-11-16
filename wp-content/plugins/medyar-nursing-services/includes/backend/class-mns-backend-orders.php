<?php
/**
 * Backend module: patients/orders/assignment/notifications/REST (T-3.2..T-3.8).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Orders {

	public static function boot(): void {
		class_exists( 'MNS_Services', true );
		class_exists( 'MNS_Patients', true );
		class_exists( 'MNS_Orders', true );
		class_exists( 'MNS_Assignment', true );
		class_exists( 'MNS_Patient_Orders_Api', true );
		class_exists( 'MNS_Nurse_Orders_Api', true );
		class_exists( 'MNS_Notifications', true );
		class_exists( 'MNS_REST_Patient', true );
		class_exists( 'MNS_Admin_Orders', true );
		MNS_REST_Patient::boot();
		MNS_Notifications::boot();
		MNS_Admin_Orders::boot();
		MNS_Services_Seed::ensure_slice1();
	}
}
