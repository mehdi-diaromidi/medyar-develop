<?php
/**
 * Seed serum + injection services when catalog empty (T-1.3 / T-3.1).
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
			self::backfill_empty_configs();
			return;
		}

		$now  = MNS_Time::now_utc();
		$rows = array(
			array(
				'slug'          => 'serum',
				'category'      => 'serum',
				'name'          => 'سرم‌تراپی',
				'active'        => 1,
				'price_from'    => 850000,
				'price_to'      => 1400000,
				'has_drug_step' => 1,
				'sort'          => 10,
				'config'        => wp_json_encode( MNS_Services::default_config_for_slug( 'serum' ) ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array(
				'slug'          => 'injection',
				'category'      => 'injection',
				'name'          => 'تزریقات',
				'active'        => 1,
				'price_from'    => 350000,
				'price_to'      => 650000,
				'has_drug_step' => 0,
				'sort'          => 20,
				'config'        => wp_json_encode( MNS_Services::default_config_for_slug( 'injection' ) ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
		);

		foreach ( $rows as $row ) {
			$r = $wpdb->insert( $table, $row );
			if ( false === $r ) {
				MNS_Log::error( 'services_seed_insert_failed', array( 'err' => MNS_DB::last_error() ) );
			}
		}
	}

	/**
	 * If existing serum/injection have empty config, fill defaults (idempotent).
	 */
	private static function backfill_empty_configs(): void {
		global $wpdb;
		$table = MNS_DB::table( 'services' );
		foreach ( array( 'serum', 'injection' ) as $slug ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, config FROM {$table} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cfg = json_decode( (string) ( $row['config'] ?? '' ), true );
			$empty = ! is_array( $cfg ) || empty( $cfg ) || empty( $cfg['order_fields'] );
			if ( ! $empty ) {
				continue;
			}
			$wpdb->update(
				$table,
				array(
					'config'     => wp_json_encode( MNS_Services::default_config_for_slug( $slug ) ),
					'updated_at' => MNS_Time::now_utc(),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}
}
