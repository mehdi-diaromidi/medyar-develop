<?php
/**
 * Seed serum + injection services when catalog empty (T-1.3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Services_Seed {

	public static function ensure_slice1(): void {
		global $wpdb;
		$table = MNS_DB::table( 'services' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > 0 ) {
			return;
		}

		$now = MNS_Time::now_utc();
		$rows = array(
			array(
				'slug'          => 'serum',
				'category'      => 'serum',
				'name'          => 'سرم‌تراپی',
				'active'        => 1,
				'price_from'    => 350000,
				'price_to'      => 850000,
				'has_drug_step' => 1,
				'sort'          => 10,
				'config'        => wp_json_encode(
					array(
						'order_fields' => array(),
						'tokens'       => array(),
						'consumables'  => array(),
					)
				),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array(
				'slug'          => 'injection',
				'category'      => 'injection',
				'name'          => 'تزریقات',
				'active'        => 1,
				'price_from'    => 200000,
				'price_to'      => 500000,
				'has_drug_step' => 0,
				'sort'          => 20,
				'config'        => wp_json_encode(
					array(
						'order_fields' => array(),
						'tokens'       => array(),
						'consumables'  => array(),
					)
				),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
		);

		foreach ( $rows as $row ) {
			$wpdb->insert( $table, $row );
		}
	}
}
