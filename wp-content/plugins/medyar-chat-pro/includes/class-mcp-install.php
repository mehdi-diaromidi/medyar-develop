<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schema, capabilities and upgrades. Never drops data.
 */
final class MCP_Install {

	public const DB_OPTION = 'mcp_db_version';

	public const CAP_MANAGE  = 'mcp_manage_chats';
	public const CAP_MEDICAL = 'mcp_view_medical';

	public static function activate(): void {
		self::install();
	}

	/** Runs on every load; cheap when already current (autoloaded option). */
	public static function maybe_upgrade(): void {
		if ( MCP_DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			self::install();
		}
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$schema = array();

		$schema[] = 'CREATE TABLE ' . self::table( 'session_meta' ) . " (
			session_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'open',
			awaiting_since datetime NULL,
			last_patient_at datetime NULL,
			last_specialist_at datetime NULL,
			first_response_secs int(10) unsigned NULL,
			response_count int(10) unsigned NOT NULL DEFAULT 0,
			response_total_secs bigint(20) unsigned NOT NULL DEFAULT 0,
			follow_up_at datetime NULL,
			follow_up_note varchar(255) NULL,
			status_changed_at datetime NULL,
			status_changed_by bigint(20) unsigned NULL,
			timing_decision varchar(20) NULL,
			timing_patient_notice int(10) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (session_id),
			KEY status (status),
			KEY follow_up_at (follow_up_at)
		) {$charset};";

		$schema[] = 'CREATE TABLE ' . self::table( 'message_meta' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			sender_id bigint(20) unsigned NOT NULL,
			message_id bigint(20) unsigned NULL,
			client_uid varchar(64) NULL,
			reply_to bigint(20) unsigned NULL,
			kind varchar(20) NULL,
			ref_id bigint(20) unsigned NULL,
			file_name varchar(255) NULL,
			file_size bigint(20) unsigned NULL,
			file_mime varchar(100) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY message_id (message_id),
			UNIQUE KEY sender_uid (sender_id,client_uid),
			KEY session_id (session_id)
		) {$charset};";

		$schema[] = 'CREATE TABLE ' . self::table( 'reactions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			message_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			reaction varchar(32) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY message_user (message_id,user_id),
			KEY session_id (session_id)
		) {$charset};";

		$schema[] = 'CREATE TABLE ' . self::table( 'notes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			patient_id bigint(20) unsigned NOT NULL,
			author_id bigint(20) unsigned NOT NULL,
			body text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deleted_at datetime NULL,
			PRIMARY KEY  (id),
			KEY patient_author (patient_id,author_id),
			KEY session_id (session_id)
		) {$charset};";

		$schema[] = 'CREATE TABLE ' . self::table( 'referrals' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			patient_id bigint(20) unsigned NOT NULL,
			from_user_id bigint(20) unsigned NOT NULL,
			to_user_id bigint(20) unsigned NOT NULL,
			reason text NULL,
			message_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY to_user_id (to_user_id),
			KEY from_user_id (from_user_id)
		) {$charset};";

		$schema[] = 'CREATE TABLE ' . self::table( 'audit' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(64) NOT NULL,
			object_type varchar(32) NULL,
			object_id bigint(20) unsigned NULL,
			details longtext NULL,
			ip varchar(45) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY action (action),
			KEY actor_id (actor_id),
			KEY created_at (created_at)
		) {$charset};";

		foreach ( $schema as $sql ) {
			dbDelta( $sql );
		}

		self::ensure_columns();
		self::add_caps();

		$first_install = '' === (string) get_option( self::DB_OPTION, '' );
		update_option( self::DB_OPTION, MCP_DB_VERSION, true );

		if ( $first_install && MCP_Theme::is_available() ) {
			MCP_Sessions::backfill();
		}
	}

	/** dbDelta is unreliable for some additive columns — patch explicitly. */
	private static function ensure_columns(): void {
		global $wpdb;
		$table = self::table( 'session_meta' );
		$cols  = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $cols ) ) {
			return;
		}
		if ( ! in_array( 'timing_decision', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN timing_decision varchar(20) NULL AFTER status_changed_by" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! in_array( 'timing_patient_notice', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN timing_patient_notice int(10) unsigned NOT NULL DEFAULT 0 AFTER timing_decision" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	private static function add_caps(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( array( self::CAP_MANAGE, self::CAP_MEDICAL ) as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}
}
