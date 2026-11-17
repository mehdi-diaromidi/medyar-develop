<?php
/**
 * Map-based autoloader for MNS_* classes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Autoloader {

	/**
	 * Class => relative path from plugin root.
	 *
	 * @var array<string,string>
	 */
	private static $map = array(
		'MNS_Plugin'           => 'includes/class-mns-plugin.php',
		'MNS_Activator'        => 'includes/class-mns-activator.php',
		'MNS_Roles'            => 'includes/class-mns-roles.php',
		'MNS_Config'           => 'includes/class-mns-config.php',
		'MNS_Backend'          => 'includes/class-mns-backend.php',
		'MNS_Backend_Core'     => 'includes/backend/class-mns-backend-core.php',
		'MNS_Backend_Db'       => 'includes/backend/class-mns-backend-db.php',
		'MNS_Backend_Settings' => 'includes/backend/class-mns-backend-settings.php',
		'MNS_Backend_Rest'     => 'includes/backend/class-mns-backend-rest.php',
		'MNS_Backend_Domain'   => 'includes/backend/class-mns-backend-domain.php',
		'MNS_Backend_Selftest' => 'includes/backend/class-mns-backend-selftest.php',
		'MNS_Backend_Files'    => 'includes/backend/class-mns-backend-files.php',
		'MNS_Backend_Nurses'   => 'includes/backend/class-mns-backend-nurses.php',
		'MNS_Backend_Catalog'  => 'includes/backend/class-mns-backend-catalog.php',
		'MNS_Backend_Orders'   => 'includes/backend/class-mns-backend-orders.php',
		'MNS_Selftest'         => 'includes/class-mns-selftest.php',
		'MNS_Selftest_Scenarios' => 'includes/class-mns-selftest-scenarios.php',
		'MNS_Selftest_Orders'    => 'includes/class-mns-selftest-orders.php',
		'MNS_Admin_Selftest'   => 'admin/class-mns-admin-selftest.php',
		'MNS_Admin_E2E'        => 'admin/class-mns-admin-e2e.php',
		'MNS_E2E'              => 'includes/services/class-mns-e2e.php',
		'MNS_Admin_Reset'      => 'admin/class-mns-admin-reset.php',
		'MNS_Admin_Nurses'     => 'admin/class-mns-admin-nurses.php',
		'MNS_Admin_Services'   => 'admin/class-mns-admin-services.php',
		'MNS_Admin_Orders'     => 'admin/class-mns-admin-orders.php',
		'MNS_Admin_Monitor'    => 'admin/class-mns-admin-monitor.php',
		'MNS_Admin_Manual'     => 'admin/class-mns-admin-manual.php',
		'MNS_Admin_Finance'    => 'admin/class-mns-admin-finance.php',
		'MNS_Admin_Stats'      => 'includes/services/class-mns-admin-stats.php',
		'MNS_Orders_Table'     => 'admin/list-tables/class-mns-orders-table.php',
		'MNS_Nurses_Table'     => 'admin/list-tables/class-mns-nurses-table.php',
		'MNS_Invoices'         => 'includes/services/class-mns-invoices.php',
		'MNS_Execution'        => 'includes/services/class-mns-execution.php',
		'MNS_Surveys'          => 'includes/services/class-mns-surveys.php',
		'MNS_Loyalty'          => 'includes/services/class-mns-loyalty.php',
		'MNS_Recurring'        => 'includes/services/class-mns-recurring.php',
		'MNS_Export_Csv'       => 'admin/class-mns-export-csv.php',
		'MNS_Ops_Cron'         => 'includes/services/class-mns-ops-cron.php',
		'MNS_Files'            => 'includes/services/class-mns-files.php',
		'MNS_Contract'         => 'includes/services/class-mns-contract.php',
		'MNS_Nurse_Access'     => 'includes/services/class-mns-nurse-access.php',
		'MNS_Nurse_Applications' => 'includes/services/class-mns-nurse-applications.php',
		'MNS_Services_Seed'    => 'includes/services/class-mns-services-seed.php',
		'MNS_Services'         => 'includes/services/class-mns-services.php',
		'MNS_Patients'         => 'includes/services/class-mns-patients.php',
		'MNS_Orders'           => 'includes/services/class-mns-orders.php',
		'MNS_Assignment'       => 'includes/services/class-mns-assignment.php',
		'MNS_Patient_Orders_Api' => 'includes/services/class-mns-patient-orders-api.php',
		'MNS_Nurse_Orders_Api' => 'includes/services/class-mns-nurse-orders-api.php',
		'MNS_Notifications'    => 'includes/services/class-mns-notifications.php',
		'MNS_Sms'              => 'includes/services/class-mns-sms.php',
		'MNS_REST_Files'       => 'includes/rest/class-mns-rest-files.php',
		'MNS_REST_Nurse'       => 'includes/rest/class-mns-rest-nurse.php',
		'MNS_REST_Patient'     => 'includes/rest/class-mns-rest-patient.php',
		'MNS_Nurse_Signup'     => 'public/class-mns-nurse-signup.php',
		'MNS_Settings'         => 'includes/class-mns-settings.php',
		'MNS_Error'            => 'includes/class-mns-error.php',
		'MNS_Log'              => 'includes/class-mns-log.php',
		'MNS_REST'             => 'includes/rest/class-mns-rest.php',
		'MNS_Time'             => 'includes/domain/class-mns-time.php',
		'MNS_Nurse_Schedule'   => 'includes/domain/class-mns-nurse-schedule.php',
		'MNS_Nurse_Status'     => 'includes/domain/class-mns-nurse-status.php',
		'MNS_Nurse_Regions'    => 'includes/services/class-mns-nurse-regions.php',
		'MNS_Locations'        => 'includes/services/class-mns-locations.php',
		'MNS_Eligibility'      => 'includes/domain/class-mns-eligibility.php',
		'MNS_Availability'     => 'includes/domain/class-mns-availability.php',
		'MNS_Order_Machine'    => 'includes/domain/class-mns-order-machine.php',
		'MNS_DB'               => 'includes/db/class-mns-db.php',
		'MNS_Schema'           => 'includes/db/class-mns-schema.php',
		'MNS_Migrations'       => 'includes/db/class-mns-migrations.php',
		'MNS_Admin'            => 'admin/class-mns-admin.php',
		'MNS_Admin_Menu'       => 'admin/class-mns-admin-menu.php',
		'MNS_Admin_Ui'         => 'admin/class-mns-admin-ui.php',
		'MNS_Admin_Db_Status'  => 'admin/class-mns-admin-db-status.php',
		'MNS_Admin_Settings'   => 'admin/class-mns-admin-settings.php',
		'MNS_Public'           => 'public/class-mns-public.php',
		'MNS_Assets'           => 'public/class-mns-assets.php',
		'MNS_Rewrite'          => 'public/class-mns-rewrite.php',
		'MNS_Pages'            => 'public/class-mns-pages.php',
		'MNS_Page_Templates'   => 'public/class-mns-page-templates.php',
		'MNS_Static_Fixtures'  => 'public/class-mns-static-fixtures.php',
		'MNS_Format'           => 'public/class-mns-format.php',
		'MNS_Copy'             => 'public/class-mns-copy.php',
		'MNS_Dev'              => 'public/class-mns-dev.php',
		'MNS_Role_Switch'      => 'public/class-mns-role-switch.php',
		'MNS_UI'               => 'public/class-mns-ui.php',
		'MNS_Patient_Orders'   => 'public/class-mns-patient-orders.php',
		'MNS_Patient_Invoices' => 'public/class-mns-patient-invoices.php',
		'MNS_Order_Status'     => 'includes/domain/class-mns-order-status.php',
	);

	/**
	 * Fallback scan dirs when class is not in the manual map (T-0.1).
	 *
	 * @var array<int,string>
	 */
	private static $fallback_dirs = array(
		'includes/',
		'includes/backend/',
		'includes/db/',
		'includes/domain/',
		'includes/services/',
		'includes/rest/',
		'admin/',
		'admin/list-tables/',
		'public/',
	);

	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * @param string $class Class name.
	 */
	public static function load( string $class ): void {
		if ( isset( self::$map[ $class ] ) ) {
			$file = MNS_PLUGIN_DIR . self::$map[ $class ];
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			return;
		}

		// Fallback: MNS_Foo_Bar => class-mns-foo-bar.php in known dirs.
		if ( ! str_starts_with( $class, 'MNS_' ) ) {
			return;
		}

		$file_name = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';

		foreach ( self::$fallback_dirs as $dir ) {
			$path = MNS_PLUGIN_DIR . $dir . $file_name;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
}
