<?php
/**
 * Patient surveys (M6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Surveys {

	/**
	 * @return array{open:array<int,array>,done:array<int,array>}
	 */
	public static function for_account( int $account_user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'surveys' ) . ' WHERE account_user_id = %d ORDER BY created_at DESC LIMIT 50', // phpcs:ignore WordPress.DB.PreparedSQL
				$account_user_id
			),
			ARRAY_A
		);
		$open = array();
		$done = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$mapped = self::map_row( $r );
				if ( 'open' === (string) $r['status'] ) {
					$open[] = $mapped;
				} else {
					$done[] = $mapped;
				}
			}
		}
		return array( 'open' => $open, 'done' => $done );
	}

	/**
	 * @param array<string,mixed> $r DB row.
	 * @return array<string,mixed>
	 */
	private static function map_row( array $r ): array {
		$order = MNS_Order_Machine::get_order( (int) $r['order_id'] );
		$svc   = $order ? MNS_Services::get( (int) $order['service_id'] ) : null;
		$nurse_name = '';
		if ( ! empty( $r['nurse_user_id'] ) ) {
			$u = get_userdata( (int) $r['nurse_user_id'] );
			$nurse_name = $u ? (string) $u->display_name : '';
		}
		return array(
			'id'      => (string) $r['id'],
			'service' => $svc ? (string) $svc['name'] : '',
			'when'    => $order ? ( (string) $order['service_date'] . ' · ' . (string) $order['slot_id'] ) : '',
			'nurse'   => $nurse_name,
			'status'  => (string) $r['status'],
			'comment' => (string) ( $r['comment'] ?? '' ),
		);
	}

	public static function open_for_order( int $order_id, int $account, int $nurse = 0 ): int {
		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . MNS_DB::table( 'surveys' ) . ' WHERE order_id = %d LIMIT 1', $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		if ( $existing ) {
			return (int) $existing;
		}
		$now = MNS_Time::now_utc();
		$wpdb->insert(
			MNS_DB::table( 'surveys' ),
			array(
				'order_id'         => $order_id,
				'account_user_id'  => $account,
				'nurse_user_id'    => $nurse ? $nurse : null,
				'status'           => 'open',
				'created_at'       => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}
}
