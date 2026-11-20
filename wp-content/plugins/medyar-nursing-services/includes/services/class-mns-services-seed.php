<?php
/**
 * Services catalog seed (slice-1 legacy + RUN-G catalog v4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Services_Seed {

	/**
	 * Legacy entry: keep calling site stable; prefer catalog v4 when schema ready.
	 */
	public static function ensure_slice1(): void {
		if ( class_exists( 'MNS_Schema', true ) && (int) MNS_Schema::LATEST >= 4 && class_exists( 'MNS_Migrations', true ) && MNS_Migrations::tables_ready() ) {
			self::ensure_catalog_v4();
			return;
		}
		self::ensure_legacy_serum_injection();
	}

	/**
	 * Idempotent RUN-G catalog: deactivate serum/injection, upsert 44 subservices.
	 */
	public static function ensure_catalog_v4(): void {
		global $wpdb;
		$table = MNS_DB::table( 'services' );
		$now   = MNS_Time::now_utc();

		foreach ( array( 'serum', 'injection' ) as $slug ) {
			$wpdb->update(
				$table,
				array(
					'active'     => 0,
					'updated_at' => $now,
				),
				array( 'slug' => $slug ),
				array( '%d', '%s' ),
				array( '%s' )
			);
		}

		if ( ! class_exists( 'MNS_Catalog_V4', true ) ) {
			return;
		}

		foreach ( MNS_Catalog_V4::definitions() as $def ) {
			$slug = sanitize_title( (string) $def['slug'] );
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			$row = array(
				'slug'                 => $slug,
				'category'             => sanitize_text_field( (string) $def['category'] ),
				'category_label'       => sanitize_text_field( (string) $def['category_label'] ),
				'name'                 => sanitize_text_field( (string) $def['name'] ),
				'active'               => 1,
				'price_from'           => max( 0, (int) $def['price_from'] ),
				'price_to'             => max( 0, (int) $def['price_to'] ),
				'has_drug_step'        => ! empty( $def['has_drug_step'] ) ? 1 : 0,
				'requires_rx'          => ! empty( $def['requires_rx'] ) ? 1 : 0,
				'needs_explicit_nurse' => ! empty( $def['needs_explicit_nurse'] ) ? 1 : 0,
				'manual_assign'        => ! empty( $def['manual_assign'] ) ? 1 : 0,
				'checklist'            => wp_json_encode( array_values( (array) $def['checklist'] ) ),
				'checklist_draft'      => ! empty( $def['checklist_draft'] ) ? 1 : 0,
				'sort'                 => (int) $def['sort'],
				'updated_at'           => $now,
			);
			if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
				$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
			} else {
				$row['config']     = wp_json_encode( array( 'order_fields' => array(), 'tokens' => array(), 'consumables' => array() ) );
				$row['created_at'] = $now;
				$wpdb->insert( $table, $row );
			}
		}
	}

	private static function ensure_legacy_serum_injection(): void {
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

	private static function backfill_empty_configs(): void {
		global $wpdb;
		$table = MNS_DB::table( 'services' );
		foreach ( array( 'serum', 'injection' ) as $slug ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, config FROM {$table} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cfg   = json_decode( (string) ( $row['config'] ?? '' ), true );
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
