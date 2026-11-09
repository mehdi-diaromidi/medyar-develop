<?php
defined( 'ABSPATH' ) || exit;

/**
 * Table schema, upgrades, cron schedules and the system-cron secret.
 */
final class MSQ_Install {

	public const DB_OPTION       = 'msq_db_version';
	public const CRON_KEY_OPTION = 'msq_cron_key';

	public static function activate(): void {
		self::install();
		MSQ_Worker::schedule_events();
	}

	public static function deactivate(): void {
		MSQ_Worker::unschedule_events();
	}

	/** Runs on every load; cheap when already current (autoloaded option). */
	public static function maybe_upgrade(): void {
		if ( MSQ_DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			self::install();
		}
	}

	public static function is_installed(): bool {
		return MSQ_DB_VERSION === (string) get_option( self::DB_OPTION, '' );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = MSQ_Queue::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			channel varchar(32) NOT NULL DEFAULT '',
			event varchar(100) NOT NULL DEFAULT '',
			transport varchar(32) NOT NULL DEFAULT 'faraz',
			priority tinyint(3) unsigned NOT NULL DEFAULT 20,
			phone varchar(20) NOT NULL DEFAULT '',
			body text NULL,
			pattern_code varchar(64) NOT NULL DEFAULT '',
			payload longtext NULL,
			meta longtext NULL,
			dedupe_key varchar(191) NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			max_attempts tinyint(3) unsigned NOT NULL DEFAULT 3,
			available_at datetime NOT NULL,
			locked_at datetime NULL,
			locked_by varchar(40) NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			sent_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status_due (status,available_at,priority),
			KEY dedupe_key (dedupe_key),
			KEY channel_event (channel,event),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );

		if ( '' === (string) get_option( self::CRON_KEY_OPTION, '' ) ) {
			update_option( self::CRON_KEY_OPTION, wp_generate_password( 32, false, false ), false );
		}
		update_option( self::DB_OPTION, MSQ_DB_VERSION, true );
	}

	public static function cron_key(): string {
		$key = (string) get_option( self::CRON_KEY_OPTION, '' );
		if ( '' === $key ) {
			$key = wp_generate_password( 32, false, false );
			update_option( self::CRON_KEY_OPTION, $key, false );
		}
		return $key;
	}

	public static function regenerate_cron_key(): string {
		$key = wp_generate_password( 32, false, false );
		update_option( self::CRON_KEY_OPTION, $key, false );
		return $key;
	}
}
