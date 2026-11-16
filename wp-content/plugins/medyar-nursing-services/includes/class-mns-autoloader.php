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
		'MNS_DB'               => 'includes/db/class-mns-db.php',
		'MNS_Schema'           => 'includes/db/class-mns-schema.php',
		'MNS_Migrations'       => 'includes/db/class-mns-migrations.php',
		'MNS_Admin'            => 'admin/class-mns-admin.php',
		'MNS_Admin_Menu'       => 'admin/class-mns-admin-menu.php',
		'MNS_Admin_Ui'         => 'admin/class-mns-admin-ui.php',
		'MNS_Admin_Db_Status'  => 'admin/class-mns-admin-db-status.php',
		'MNS_Public'           => 'public/class-mns-public.php',
		'MNS_Assets'           => 'public/class-mns-assets.php',
		'MNS_Rewrite'          => 'public/class-mns-rewrite.php',
		'MNS_Pages'            => 'public/class-mns-pages.php',
		'MNS_Page_Templates'   => 'public/class-mns-page-templates.php',
		'MNS_Static_Fixtures'  => 'public/class-mns-static-fixtures.php',
		'MNS_Format'           => 'public/class-mns-format.php',
		'MNS_Copy'             => 'public/class-mns-copy.php',
		'MNS_Dev'              => 'public/class-mns-dev.php',
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
