<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Job_Types_Repo {

	public static function all( bool $active_only = false ): array {
		global $wpdb;
		$table = Arp_Db::job_types();
		$sql   = "SELECT * FROM {$table}";
		if ( $active_only ) {
			$sql .= ' WHERE is_active = 1';
		}
		$sql .= ' ORDER BY name ASC';
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arp_Db::job_types() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function insert( string $name, int $is_active = 1 ): int {
		global $wpdb;
		$wpdb->insert(
			Arp_Db::job_types(),
			array(
				'name'      => $name,
				'is_active' => $is_active,
			),
			array( '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, string $name, int $is_active ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			Arp_Db::job_types(),
			array(
				'name'      => $name,
				'is_active' => $is_active,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * @return array<int,int> job_type_id => number of users
	 */
	public static function usage_counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS id, COUNT(*) AS cnt FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value",
				'arp_job_type_id'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (int) $row['cnt'];
		}
		return $out;
	}

	public static function set_active( int $id, int $is_active ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			Arp_Db::job_types(),
			array( 'is_active' => $is_active ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}
}
