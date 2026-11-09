<?php
/**
 * Activation: tables, seeds, roles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Activator {

	public static function activate(): void {
		self::create_tables();
		$current = (string) get_option( 'arp_db_version', '' );
		self::seed_statuses();
		self::backfill_status_colors();
		if ( '' === $current || version_compare( $current, '1.0.5', '<' ) ) {
			self::backfill_dashboard_groups();
		}
		self::seed_job_types();
		Arp_Roles::register();
		update_option( 'arp_db_version', ARP_DB_VERSION );

		if ( false === get_option( 'arp_patient_sms_template', false ) ) {
			update_option(
				'arp_patient_sms_template',
				'سلام {patient_name} عزیز، معرفی شما در مدیار ثبت شد. به زودی با شما تماس گرفته می‌شود.'
			);
		}
	}

	public static function deactivate(): void {
		Arp_Roles::remove_caps_on_deactivate();
	}

	public static function maybe_upgrade(): void {
		$current = get_option( 'arp_db_version', '' );
		if ( $current !== ARP_DB_VERSION ) {
			self::create_tables();
			self::seed_statuses();
			self::backfill_status_colors();
			if ( version_compare( (string) $current, '1.0.5', '<' ) ) {
				self::backfill_dashboard_groups();
			}
			self::seed_job_types();
			Arp_Roles::register();
			update_option( 'arp_db_version', ARP_DB_VERSION );
		} else {
			/* Keep job types populated even if version already matches. */
			self::seed_job_types();
		}
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jt      = Arp_Db::job_types();
		$sv      = Arp_Db::services();
		$cr      = Arp_Db::commission_rules();
		$st      = Arp_Db::referral_statuses();
		$rf      = Arp_Db::referrals();
		$cm      = Arp_Db::commissions();
		$se      = Arp_Db::settlements();
		$si      = Arp_Db::settlement_items();
		$lg      = Arp_Db::referral_logs();

		$sql = array();

		$sql[] = "CREATE TABLE {$jt} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_active (is_active)
		) {$charset};";

		$sql[] = "CREATE TABLE {$sv} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			price decimal(12,2) DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY is_active (is_active)
		) {$charset};";

		$sql[] = "CREATE TABLE {$cr} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			percentage decimal(5,2) NOT NULL DEFAULT 0.00,
			default_amount decimal(12,2) DEFAULT NULL,
			superseded_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY service_active (service_id,superseded_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$st} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			slug varchar(64) NOT NULL,
			sort_order int(11) NOT NULL DEFAULT 0,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			triggers_commission tinyint(1) NOT NULL DEFAULT 0,
			is_terminal tinyint(1) NOT NULL DEFAULT 0,
			color varchar(7) NOT NULL DEFAULT '',
			dashboard_group varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY sort_order (sort_order)
		) {$charset};";

		$sql[] = "CREATE TABLE {$rf} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_user_id bigint(20) unsigned NOT NULL,
			patient_name varchar(191) NOT NULL,
			patient_phone varchar(32) NOT NULL,
			service_id bigint(20) unsigned NOT NULL,
			description text NULL,
			status_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY affiliate_user_id (affiliate_user_id),
			KEY service_id (service_id),
			KEY status_id (status_id),
			KEY created_at (created_at),
			KEY affiliate_created (affiliate_user_id,created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$cm} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			referral_id bigint(20) unsigned NOT NULL,
			affiliate_user_id bigint(20) unsigned NOT NULL,
			service_id bigint(20) unsigned NOT NULL,
			percentage_applied decimal(5,2) DEFAULT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			state varchar(20) NOT NULL DEFAULT 'pending',
			tracking_code varchar(64) DEFAULT NULL,
			paid_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY referral_id (referral_id),
			KEY affiliate_user_id (affiliate_user_id),
			KEY state (state),
			KEY affiliate_state (affiliate_user_id,state)
		) {$charset};";

		$sql[] = "CREATE TABLE {$se} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			affiliate_user_id bigint(20) unsigned NOT NULL,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			note text NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY affiliate_user_id (affiliate_user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$si} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			settlement_id bigint(20) unsigned NOT NULL,
			commission_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY commission_id (commission_id),
			KEY settlement_id (settlement_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$lg} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			referral_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			from_status_id bigint(20) unsigned NOT NULL DEFAULT 0,
			to_status_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY referral_id (referral_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	private static function seed_statuses(): void {
		global $wpdb;
		$table = Arp_Db::referral_statuses();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $count > 0 ) {
			return;
		}

		$seeds = array(
			array( 'در انتظار تماس', 'waiting_contact', 10, 1, 0, 0 ),
			array( 'در حال پیگیری', 'in_progress', 20, 0, 0, 0 ),
			array( 'بیمار پذیرفت', 'patient_accepted', 30, 0, 0, 0 ),
			array( 'تکمیل‌شده', 'completed', 40, 0, 1, 1 ),
			array( 'انصراف بیمار', 'patient_withdrawn', 50, 0, 0, 1 ),
			array( 'تماس ناموفق-نیاز به پیگیری', 'failed_contact', 60, 0, 0, 0 ),
		);

		foreach ( $seeds as $row ) {
			$wpdb->insert(
				$table,
				array(
					'name'                => $row[0],
					'slug'                => $row[1],
					'sort_order'          => $row[2],
					'is_default'          => $row[3],
					'triggers_commission' => $row[4],
					'is_terminal'         => $row[5],
				),
				array( '%s', '%s', '%d', '%d', '%d', '%d' )
			);
		}
	}

	/**
	 * Store the previously implicit color on statuses that have none yet.
	 */
	private static function backfill_status_colors(): void {
		global $wpdb;
		$table = Arp_Db::referral_statuses();
		foreach ( Arp_Statuses_Repo::all() as $i => $status ) {
			if ( '' !== Arp_Ui::sanitize_color( (string) ( $status['color'] ?? '' ) ) ) {
				continue;
			}
			$wpdb->update(
				$table,
				array( 'color' => Arp_Ui::default_status_color( (string) $status['slug'], (int) $i ) ),
				array( 'id' => (int) $status['id'] ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Seeded statuses keep the counts the partner dashboard used before groups were configurable.
	 */
	private static function backfill_dashboard_groups(): void {
		global $wpdb;
		$table = Arp_Db::referral_statuses();
		$map   = array(
			'waiting_contact'  => Arp_Statuses_Repo::GROUP_OPEN,
			'in_progress'      => Arp_Statuses_Repo::GROUP_OPEN,
			'patient_accepted' => Arp_Statuses_Repo::GROUP_OPEN,
			'completed'        => Arp_Statuses_Repo::GROUP_SUCCESS,
		);
		foreach ( $map as $slug => $group ) {
			$wpdb->update( $table, array( 'dashboard_group' => $group ), array( 'slug' => $slug, 'dashboard_group' => '' ), array( '%s' ), array( '%s', '%s' ) );
		}
	}

	private static function seed_job_types(): void {
		global $wpdb;
		$table = Arp_Db::job_types();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $count > 0 ) {
			return;
		}

		$seeds = array(
			'پزشک',
			'دندان‌پزشک',
			'پرستار',
			'ماما',
			'فیزیوتراپیست',
			'داروساز',
			'تکنسین آزمایشگاه',
			'تکنسین رادیولوژی',
			'کارشناس تغذیه',
			'سایر',
		);

		foreach ( $seeds as $name ) {
			$wpdb->insert(
				$table,
				array(
					'name'      => $name,
					'is_active' => 1,
				),
				array( '%s', '%d' )
			);
		}
	}
}
