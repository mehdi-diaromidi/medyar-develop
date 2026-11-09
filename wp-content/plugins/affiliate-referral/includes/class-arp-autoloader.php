<?php
/**
 * Simple PSR-4-style autoloader for Arp_* classes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Autoloader {

	/**
	 * Map of class => relative path under includes/ or admin/ or public/.
	 *
	 * @var array<string,string>
	 */
	private static $map = array(
		'Arp_Plugin'                => 'includes/class-arp-plugin.php',
		'Arp_Activator'             => 'includes/class-arp-activator.php',
		'Arp_Roles'                 => 'includes/class-arp-roles.php',
		'Arp_Referral_Code'         => 'includes/class-arp-referral-code.php',
		'Arp_Avatars'               => 'includes/class-arp-avatars.php',
		'Arp_Commission_Engine'     => 'includes/class-arp-commission-engine.php',
		'Arp_Wallet_Bridge'         => 'includes/class-arp-wallet-bridge.php',
		'Arp_Format'                => 'includes/class-arp-format.php',
		'Arp_Affiliate_Stats'       => 'includes/class-arp-affiliate-stats.php',
		'Arp_Affiliate_Applications'=> 'includes/class-arp-affiliate-applications.php',
		'Arp_Inviter'               => 'includes/class-arp-inviter.php',
		'Arp_MyAccount_Invited'     => 'public/class-arp-myaccount-invited.php',
		'Arp_Ui'                    => 'public/class-arp-ui.php',
		'Arp_Applications_Table'    => 'admin/list-tables/class-arp-applications-table.php',
		'Arp_Invited_Affiliates_Table' => 'admin/list-tables/class-arp-invited-affiliates-table.php',
		'Arp_Db'                    => 'includes/db/class-arp-db.php',
		'Arp_Job_Types_Repo'        => 'includes/db/class-arp-job-types-repo.php',
		'Arp_Services_Repo'         => 'includes/db/class-arp-services-repo.php',
		'Arp_Statuses_Repo'         => 'includes/db/class-arp-statuses-repo.php',
		'Arp_Commission_Rules_Repo' => 'includes/db/class-arp-commission-rules-repo.php',
		'Arp_Referrals_Repo'        => 'includes/db/class-arp-referrals-repo.php',
		'Arp_Commissions_Repo'      => 'includes/db/class-arp-commissions-repo.php',
		'Arp_Settlements_Repo'      => 'includes/db/class-arp-settlements-repo.php',
		'Arp_Referral_Logs_Repo'    => 'includes/db/class-arp-referral-logs-repo.php',
		'Arp_Sms_Gateway'           => 'includes/sms/interface-arp-sms-gateway.php',
		'Arp_Faraz_Sms_Gateway'     => 'includes/sms/class-arp-faraz-sms-gateway.php',
		'Arp_Admin_Pending_Digest'  => 'includes/class-arp-admin-pending-digest.php',
		'Arp_Admin'                 => 'admin/class-arp-admin.php',
		'Arp_Admin_Menu'            => 'admin/class-arp-admin-menu.php',
		'Arp_Export_Csv'            => 'admin/class-arp-export-csv.php',
		'Arp_Admin_Ui'              => 'admin/class-arp-admin-ui.php',
		'Arp_Dashboard_Widget'      => 'admin/class-arp-dashboard-widget.php',
		'Arp_Referrals_Table'       => 'admin/list-tables/class-arp-referrals-table.php',
		'Arp_Affiliates_Table'      => 'admin/list-tables/class-arp-affiliates-table.php',
		'Arp_Commissions_Table'     => 'admin/list-tables/class-arp-commissions-table.php',
		'Arp_Page_Templates'        => 'public/class-arp-page-templates.php',
		'Arp_Pages'                 => 'public/class-arp-pages.php',
		'Arp_Public'                => 'public/class-arp-public.php',
		'Arp_Ajax'                  => 'public/class-arp-ajax.php',
		'Arp_Panel_Actions'         => 'public/class-arp-panel-actions.php',
		'Arp_Panel_Lists'           => 'public/class-arp-panel-lists.php',
		'Arp_Affiliate_Access'      => 'public/class-arp-affiliate-access.php',
	);

	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * @param string $class Class name.
	 */
	public static function load( string $class ): void {
		if ( ! isset( self::$map[ $class ] ) ) {
			return;
		}
		$file = ARP_PLUGIN_DIR . self::$map[ $class ];
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
