<?php
/**
 * Nurse order list/detail whitelist (T-3.7 / API-NOR-1/2 / R-PRV-1/2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Orders_Api {

	/**
	 * @param array<string,mixed> $params Params.
	 * @return array{data:array,meta:array}
	 */
	public static function list_for( int $nurse_user_id, array $params ): array {
		global $wpdb;
		$tab = isset( $params['tab'] ) ? sanitize_key( (string) $params['tab'] ) : 'upcoming';
		$p   = max( 1, isset( $params['p'] ) ? (int) $params['p'] : 1 );
		$per = min( 50, max( 1, isset( $params['per'] ) ? (int) $params['per'] : 10 ) );
		$ot  = MNS_DB::table( 'orders' );

		$where = 'nurse_user_id = %d';
		$args  = array( $nurse_user_id );
		if ( 'upcoming' === $tab || 'pending' === $tab ) {
			$where .= " AND status IN ('assigned','in_progress')";
		} elseif ( 'done' === $tab ) {
			$where .= " AND status IN ('service_ended','needs_review','completed','cancelled')";
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ot} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$offset = ( $p - 1 ) * $per;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$ot} WHERE {$where} ORDER BY service_date ASC, slot_id ASC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$svc = MNS_Services::get( (int) $row['service_id'] );
				$items[] = array(
					'code'    => (string) $row['code'],
					'service' => $svc ? $svc['name'] : '',
					'when'    => (string) $row['service_date'] . ' · ' . (string) $row['slot_id'],
					'status'  => (string) $row['status'],
				);
			}
		}
		return array(
			'data' => array( 'items' => $items ),
			'meta' => array(
				'total' => $total,
				'pages' => (int) ceil( max( 1, $total ) / $per ),
				'per'   => $per,
				'p'     => $p,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_for( int $nurse_user_id, string $code ): array {
		global $wpdb;
		$table = MNS_DB::table( 'orders' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s LIMIT 1", $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $row ) || (int) $row['nurse_user_id'] !== $nurse_user_id ) {
			throw MNS_Error::not_found( 'سفارش یافت نشد.' );
		}
		$status = (string) $row['status'];
		$show_contact = in_array( $status, array( 'assigned', 'in_progress' ), true );
		$svc = MNS_Services::get( (int) $row['service_id'] );
		$out = array(
			'code'    => (string) $row['code'],
			'status'  => $status,
			'service' => $svc ? $svc['name'] : '',
			'when'    => (string) $row['service_date'] . ' · ' . (string) $row['slot_id'],
			'notes'   => (string) ( $row['notes'] ?? '' ),
			'essentials' => json_decode( (string) ( $row['essentials'] ?? '{}' ), true ),
			'province' => (string) $row['province_code'],
			'city_id'  => (int) $row['city_id'],
			'district' => (int) $row['district_no'],
		);
		if ( $show_contact ) {
			$out['address']       = (string) $row['address'];
			$out['contact_phone'] = (string) $row['contact_phone'];
		}
		// Never expose amounts (R-PRV-2).
		return $out;
	}
}
