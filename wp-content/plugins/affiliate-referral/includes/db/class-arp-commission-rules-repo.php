<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Commission_Rules_Repo {

	public static function get_active_for_service( int $service_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Arp_Db::commission_rules() . ' WHERE service_id = %d AND superseded_at IS NULL ORDER BY id DESC LIMIT 1',
				$service_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function all_active(): array {
		global $wpdb;
		$sql = 'SELECT r.*, s.name AS service_name
			FROM ' . Arp_Db::commission_rules() . ' r
			LEFT JOIN ' . Arp_Db::services() . ' s ON s.id = r.service_id
			WHERE r.superseded_at IS NULL
			ORDER BY s.name ASC';
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Upsert active rule: supersede previous active row for service, insert new.
	 */
	public static function set_rule( int $service_id, float $percentage, ?float $default_amount ): int {
		global $wpdb;
		$table = Arp_Db::commission_rules();
		$now   = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET superseded_at = %s WHERE service_id = %d AND superseded_at IS NULL",
				$now,
				$service_id
			)
		);

		$data = array(
			'service_id' => $service_id,
			'percentage' => $percentage,
			'updated_at' => $now,
		);
		$format = array( '%d', '%f', '%s' );
		if ( null !== $default_amount ) {
			$data['default_amount'] = $default_amount;
			$format[]               = '%f';
		}
		$wpdb->insert( $table, $data, $format );

		return (int) $wpdb->insert_id;
	}
}
