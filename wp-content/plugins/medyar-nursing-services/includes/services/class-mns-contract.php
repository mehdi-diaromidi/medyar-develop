<?php
/**
 * Contract versions (T-1.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Contract {

	/**
	 * Ensure a temporary active v1 exists.
	 */
	public static function ensure_seed(): void {
		global $wpdb;
		$table = MNS_DB::table( 'contract_versions' );
		$active = $wpdb->get_var( "SELECT id FROM {$table} WHERE active = 1 LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $active ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'version'    => 1,
				'title'      => 'قرارداد موقت',
				'body'       => 'متن قرارداد موقت — بعداً جایگزین می‌شود',
				'active'     => 1,
				'created_by' => 0,
				'created_at' => MNS_Time::now_utc(),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function active(): ?array {
		self::ensure_seed();
		global $wpdb;
		$table = MNS_DB::table( 'contract_versions' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE active = 1 ORDER BY version DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create a new active version (deactivates others).
	 *
	 * @param string $title Title.
	 * @param string $body  Body.
	 * @param int    $by    Admin user id.
	 */
	public static function publish( string $title, string $body, int $by ): int {
		global $wpdb;
		$table = MNS_DB::table( 'contract_versions' );
		$max   = (int) $wpdb->get_var( "SELECT MAX(version) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET active = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert(
			$table,
			array(
				'version'    => $max + 1,
				'title'      => $title,
				'body'       => $body,
				'active'     => 1,
				'created_by' => $by,
				'created_at' => MNS_Time::now_utc(),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}
}
