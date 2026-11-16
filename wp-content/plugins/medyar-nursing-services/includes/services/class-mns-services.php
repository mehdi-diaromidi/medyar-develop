<?php
/**
 * Services catalog (T-3.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Services {

	/**
	 * @param int|string $id_or_slug Id or slug.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id_or_slug ): ?array {
		global $wpdb;
		$table = MNS_DB::table( 'services' );
		if ( is_numeric( $id_or_slug ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id_or_slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", sanitize_title( (string) $id_or_slug ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return self::normalize( $row );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_active(): array {
		global $wpdb;
		$table = MNS_DB::table( 'services' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 ORDER BY sort ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				// Supervisor fix: self-test fixtures must never be offered to real patients.
				if ( 0 === strpos( (string) $row['slug'], 'mns_selftest' ) ) {
					continue;
				}
				$n = self::normalize( $row );
				if ( $n ) {
					$out[] = $n;
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_all(): array {
		global $wpdb;
		$table = MNS_DB::table( 'services' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort ASC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$n = self::normalize( $row );
				if ( $n ) {
					$out[] = $n;
				}
			}
		}
		return $out;
	}

	/**
	 * Admin update (minimal fields).
	 *
	 * @param int                 $id   Service id.
	 * @param array<string,mixed> $data Fields.
	 * @throws MNS_Error On failure.
	 */
	public static function update_admin( int $id, array $data ): array {
		if ( $id <= 0 ) {
			throw MNS_Error::validation( 'خدمت نامعتبر است.' );
		}
		$existing = self::get( $id );
		if ( ! $existing ) {
			throw MNS_Error::not_found( 'خدمت یافت نشد.' );
		}
		$fields = array();
		$formats = array();
		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' === $name ) {
				throw MNS_Error::validation( 'نام الزامی است.', 'name' );
			}
			$fields['name'] = $name;
			$formats[]      = '%s';
		}
		if ( isset( $data['active'] ) ) {
			$fields['active'] = $data['active'] ? 1 : 0;
			$formats[]        = '%d';
		}
		if ( isset( $data['price_from'] ) ) {
			$fields['price_from'] = max( 0, (int) $data['price_from'] );
			$formats[]            = '%d';
		}
		if ( isset( $data['price_to'] ) ) {
			$fields['price_to'] = max( 0, (int) $data['price_to'] );
			$formats[]          = '%d';
		}
		if ( isset( $data['has_drug_step'] ) ) {
			$fields['has_drug_step'] = $data['has_drug_step'] ? 1 : 0;
			$formats[]              = '%d';
		}
		if ( array_key_exists( 'config', $data ) ) {
			$cfg = $data['config'];
			if ( is_string( $cfg ) ) {
				$decoded = json_decode( $cfg, true );
				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
					throw MNS_Error::validation( 'JSON پیکربندی نامعتبر است.', 'config' );
				}
				$cfg = $decoded;
			}
			if ( ! is_array( $cfg ) ) {
				throw MNS_Error::validation( 'پیکربندی نامعتبر است.', 'config' );
			}
			$fields['config'] = wp_json_encode( $cfg );
			$formats[]        = '%s';
		}
		if ( empty( $fields ) ) {
			return $existing;
		}
		$fields['updated_at'] = MNS_Time::now_utc();
		$formats[]            = '%s';
		global $wpdb;
		$r = $wpdb->update( MNS_DB::table( 'services' ), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $r ) {
			throw MNS_Error::server( 'ذخیرهٔ خدمت ناموفق بود.' );
		}
		$fresh = self::get( $id );
		if ( ! $fresh ) {
			throw MNS_Error::server();
		}
		return $fresh;
	}

	/**
	 * Default order_fields config for slice-1 services.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_config_for_slug( string $slug ): array {
		$base = array(
			'order_fields' => array(
				'serum_types' => array( 'نرمال سالین', 'دکستروز', 'رینگر', 'تقویتی و ویتامین', 'طبق نسخه پزشک' ),
				'rx_types'    => array( 'طبق نسخه پزشک' ),
				'vein_access' => array(
					array( 'value' => 'normal', 'label' => 'معمولی' ),
					array( 'value' => 'hard', 'label' => 'دشوار' ),
					array( 'value' => 'unknown', 'label' => 'نمی‌دانم' ),
				),
				'allergy'     => array(
					array( 'value' => 'no', 'label' => 'ندارد' ),
					array( 'value' => 'yes', 'label' => 'دارد' ),
					array( 'value' => 'unknown', 'label' => 'نمی‌دانم' ),
				),
			),
			'tokens'       => array(),
			'consumables'  => array(),
		);
		if ( 'injection' === $slug ) {
			$base['order_fields']['serum_types'] = array();
			$base['order_fields']['rx_types']    = array();
		}
		return $base;
	}

	/**
	 * @param array<string,mixed>|null $row Raw row.
	 * @return array<string,mixed>|null
	 */
	private static function normalize( $row ): ?array {
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return null;
		}
		$cfg = array();
		if ( ! empty( $row['config'] ) ) {
			$decoded = json_decode( (string) $row['config'], true );
			$cfg     = is_array( $decoded ) ? $decoded : array();
		}
		return array(
			'id'            => (int) $row['id'],
			'slug'          => (string) $row['slug'],
			'category'      => (string) ( $row['category'] ?? '' ),
			'name'          => (string) $row['name'],
			'active'        => ! empty( $row['active'] ),
			'price_from'    => (int) $row['price_from'],
			'price_to'      => (int) $row['price_to'],
			'price_min'     => (int) $row['price_from'],
			'price_max'     => (int) $row['price_to'],
			'has_drug_step' => ! empty( $row['has_drug_step'] ),
			'sort'          => (int) ( $row['sort'] ?? 0 ),
			'config'        => $cfg,
		);
	}
}
