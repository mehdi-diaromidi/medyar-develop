<?php
/**
 * Multi-service order line items (RUN-G).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Order_Items {

	/**
	 * @param array<int,array{service_id:int,is_extra?:int|bool}> $items Items.
	 * @return array{primary:array<string,mixed>,rows:array<int,array<string,mixed>>,requires_rx:bool,manual_assign:bool}
	 */
	public static function validate_items( array $items ): array {
		if ( count( $items ) < 1 || count( $items ) > 10 ) {
			throw MNS_Error::validation( 'تعداد خدمات باید بین ۱ تا ۱۰ باشد.', 'items' );
		}
		$primary_cat = '';
		$rows        = array();
		$requires_rx = false;
		$manual      = false;
		$seen        = array();
		foreach ( $items as $raw ) {
			if ( ! is_array( $raw ) ) {
				throw MNS_Error::validation( 'آیتم نامعتبر است.', 'items' );
			}
			$sid   = isset( $raw['service_id'] ) ? (int) $raw['service_id'] : 0;
			$extra = ! empty( $raw['is_extra'] );
			$svc   = MNS_Services::get( $sid );
			if ( ! $svc || empty( $svc['active'] ) ) {
				throw MNS_Error::validation( 'خدمت نامعتبر یا غیرفعال است.', 'items' );
			}
			$key = $sid . ':' . ( $extra ? '1' : '0' );
			if ( isset( $seen[ $key ] ) ) {
				throw MNS_Error::validation( 'خدمت تکراری است.', 'items' );
			}
			$seen[ $key ] = true;
			if ( ! $extra ) {
				if ( '' === $primary_cat ) {
					$primary_cat = (string) $svc['category'];
				} elseif ( $primary_cat !== (string) $svc['category'] ) {
					throw MNS_Error::validation( 'زیرخدمت‌های اصلی باید از یک دسته باشند.', 'items' );
				}
			}
			if ( ! empty( $svc['requires_rx'] ) ) {
				$requires_rx = true;
			}
			if ( ! empty( $svc['manual_assign'] ) ) {
				$manual = true;
			}
			$rows[] = array(
				'service'   => $svc,
				'is_extra'  => $extra ? 1 : 0,
				'price_from'=> (int) $svc['price_from'],
				'price_to'  => (int) $svc['price_to'],
			);
		}
		$primary = null;
		foreach ( $rows as $r ) {
			if ( 0 === (int) $r['is_extra'] ) {
				$primary = $r['service'];
				break;
			}
		}
		if ( ! $primary ) {
			// All extras — treat first as primary for orders.service_id compatibility.
			$primary = $rows[0]['service'];
			$rows[0]['is_extra'] = 0;
		}
		return array(
			'primary'       => $primary,
			'rows'          => $rows,
			'requires_rx'   => $requires_rx,
			'manual_assign' => $manual,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows From validate_items.
	 */
	public static function insert_for_order( int $order_id, array $rows ): void {
		global $wpdb;
		$now = MNS_Time::now_utc();
		foreach ( $rows as $r ) {
			$wpdb->insert(
				MNS_DB::table( 'order_items' ),
				array(
					'order_id'   => $order_id,
					'service_id' => (int) $r['service']['id'],
					'is_extra'   => (int) $r['is_extra'],
					'price_from' => (int) $r['price_from'],
					'price_to'   => (int) $r['price_to'],
					'created_at' => $now,
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'order_items' ) . ' WHERE order_id = %d ORDER BY is_extra ASC, id ASC', // phpcs:ignore WordPress.DB.PreparedSQL
				$order_id
			),
			ARRAY_A
		);
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$svc = MNS_Services::get( (int) $row['service_id'] );
			$out[] = array(
				'id'         => (int) $row['id'],
				'service_id' => (int) $row['service_id'],
				'is_extra'   => ! empty( $row['is_extra'] ),
				'price_from' => (int) $row['price_from'],
				'price_to'   => (int) $row['price_to'],
				'name'       => $svc ? (string) $svc['name'] : '',
				'slug'       => $svc ? (string) $svc['slug'] : '',
				'checklist'  => $svc && is_array( $svc['checklist'] ) ? $svc['checklist'] : array(),
			);
		}
		return $out;
	}

	/**
	 * Merged checklist grouped by subservice for nurse execution UI.
	 *
	 * @return array<int,array{service_id:int,name:string,items:array<int,string>}>
	 */
	public static function checklist_groups( int $order_id ): array {
		$groups = array();
		foreach ( self::for_order( $order_id ) as $item ) {
			$groups[] = array(
				'service_id' => (int) $item['service_id'],
				'name'       => (string) $item['name'],
				'is_extra'   => ! empty( $item['is_extra'] ),
				'items'      => array_values( (array) $item['checklist'] ),
			);
		}
		if ( empty( $groups ) ) {
			// Legacy orders: single service_id on order row.
			global $wpdb;
			$sid = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT service_id FROM ' . MNS_DB::table( 'orders' ) . ' WHERE id = %d', $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
			$svc = $sid ? MNS_Services::get( $sid ) : null;
			if ( $svc ) {
				$groups[] = array(
					'service_id' => (int) $svc['id'],
					'name'       => (string) $svc['name'],
					'is_extra'   => false,
					'items'      => array_values( (array) ( $svc['checklist'] ?? array() ) ),
				);
			}
		}
		return $groups;
	}
}
