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
		class_exists( 'MNS_Invoices', true );
		class_exists( 'MNS_Execution', true );
		class_exists( 'MNS_Surveys', true );
		class_exists( 'MNS_Loyalty', true );
		class_exists( 'MNS_Recurring', true );
		class_exists( 'MNS_Ops_Cron', true );
		class_exists( 'MNS_Payments', true );
		class_exists( 'MNS_REST_Payments', true );
		class_exists( 'MNS_Nurse_Wallet', true );
		MNS_REST_Patient::boot();
		MNS_REST_Payments::boot();
		MNS_Notifications::boot();
		MNS_Ops_Cron::boot();
		MNS_Payments::boot();
		add_filter( 'cron_schedules', array( 'MNS_Payments', 'cron_schedules' ) );

		// Admin UI/list-tables must not boot on the storefront (memory/WAF/admin includes).
		if ( is_admin() ) {
			class_exists( 'MNS_Admin_Orders', true );
			class_exists( 'MNS_Admin_Stats', true );
			class_exists( 'MNS_Admin_Monitor', true );
			class_exists( 'MNS_Admin_Manual', true );
			class_exists( 'MNS_Admin_Finance', true );
			class_exists( 'MNS_Export_Csv', true );
			MNS_Admin_Orders::boot();
			MNS_Admin_Monitor::boot();
			MNS_Admin_Manual::boot();
			MNS_Admin_Finance::boot();
			MNS_Export_Csv::boot();
			if ( class_exists( 'MNS_Migrations', true ) && MNS_Migrations::tables_ready() ) {
				MNS_Services_Seed::ensure_slice1();
			}
		}
	}
}
