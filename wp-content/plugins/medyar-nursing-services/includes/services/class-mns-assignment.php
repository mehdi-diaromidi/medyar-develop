<?php
/**
 * Assignment helpers (T-3.5 / R-ASG-*).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Assignment {

	/**
	 * Auto-pick best eligible nurse and assign.
	 *
	 * @return array<string,mixed> Order row.
	 */
	public static function auto_assign( int $order_id, int $actor_user_id = 0 ): array {
		$order = MNS_Order_Machine::get_order( $order_id );
		if ( ! $order ) {
			throw MNS_Error::not_found();
		}
		if ( MNS_Order_Status::SUBMITTED !== $order['status'] ) {
			throw MNS_Error::bad_state();
		}
		$scope = self::scope_from_order( $order );
		$list  = MNS_Eligibility::nurses_for( $scope );
		if ( empty( $list ) ) {
			throw MNS_Error::conflict( 'ERR_NO_NURSE', 'پرستار واجد یافت نشد.' );
		}
		$ranked = self::rank( $list, $order );
		$best   = (int) $ranked[0]['user_id'];
		return MNS_Order_Machine::transition(
			'assign',
			array(
				'order_id'      => $order_id,
				'nurse_user_id' => $best,
				'mode'          => 'auto',
				'actor_user_id' => $actor_user_id,
				'actor_role'    => 'system',
			)
		);
	}

	/**
	 * Up to 5 suggestions with reasons.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function suggestions( int $order_id ): array {
		$order = MNS_Order_Machine::get_order( $order_id );
		if ( ! $order ) {
			return array();
		}
		$list = MNS_Eligibility::nurses_for( self::scope_from_order( $order ) );
		$ranked = self::rank( $list, $order );
		$out = array();
		foreach ( array_slice( $ranked, 0, 5 ) as $i => $n ) {
			$u = get_userdata( (int) $n['user_id'] );
			$out[] = array(
				'user_id' => (int) $n['user_id'],
				'name'    => $u ? $u->display_name : ( '#' . $n['user_id'] ),
				'reason'  => 0 === $i ? 'بهترین رتبه' : 'نامزد جایگزین',
				'score'   => isset( $n['_score'] ) ? $n['_score'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Admin assign / reassign with R-ASG-4 guards.
	 *
	 * @param array<string,mixed> $args force=1 to allow ineligible (not suspended).
	 * @return array<string,mixed>
	 */
	public static function admin_set_nurse( int $order_id, int $nurse_id, int $actor, bool $force = false ): array {
		$nurse = MNS_Nurse_Access::nurse_row( $nurse_id );
		if ( ! $nurse || 'approved' !== (string) $nurse['status'] ) {
			if ( $nurse && 'suspended' === (string) $nurse['status'] ) {
				throw MNS_Error::forbidden( 'پرستار تعلیق‌شده قابل تخصیص نیست.' );
			}
			throw MNS_Error::validation( 'پرستار تأییدشده نیست.' );
		}
		$order = MNS_Order_Machine::get_order( $order_id );
		if ( ! $order ) {
			throw MNS_Error::not_found();
		}
		$scope = self::scope_from_order( $order );
		$eligible_ids = array_map(
			static function ( $r ) {
				return (int) $r['user_id'];
			},
			MNS_Eligibility::nurses_for( $scope )
		);
		$eligible = in_array( $nurse_id, $eligible_ids, true );
		if ( ! $eligible && ! $force ) {
			throw MNS_Error::conflict( 'ERR_CONFIRM', 'پرستار واجد شرایط نیست؛ برای ادامه تأیید صریح لازم است.', array( 'needs_force' => true ) );
		}

		if ( MNS_Order_Status::SUBMITTED === $order['status'] ) {
			return MNS_Order_Machine::transition(
				'assign',
				array(
					'order_id'          => $order_id,
					'nurse_user_id'     => $nurse_id,
					'mode'              => 'manual',
					'actor_user_id'     => $actor,
					'actor_role'        => 'admin',
					'skip_eligibility'  => ( $force && ! $eligible ),
				)
			);
		}
		if ( MNS_Order_Status::ASSIGNED === $order['status'] ) {
			return MNS_Order_Machine::transition(
				'reassign',
				array(
					'order_id'      => $order_id,
					'nurse_user_id' => $nurse_id,
					'actor_user_id' => $actor,
					'actor_role'    => 'admin',
				)
			);
		}
		throw MNS_Error::bad_state();
	}

	/**
	 * Unassigned submitted orders.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function unassigned( int $limit = 50 ): array {
		global $wpdb;
		$table = MNS_DB::table( 'orders' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND (nurse_user_id IS NULL OR nurse_user_id = 0) ORDER BY created_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				MNS_Order_Status::SUBMITTED,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string,mixed> $order Order.
	 * @return array<string,mixed>
	 */
	private static function scope_from_order( array $order ): array {
		return array(
			'service_id'    => (int) $order['service_id'],
			'province_code' => (string) $order['province_code'],
			'city_id'       => (int) $order['city_id'],
			'district_no'   => (int) $order['district_no'],
			'service_date'  => (string) $order['service_date'],
			'slot_id'       => (string) $order['slot_id'],
			'gender_pref'   => (string) $order['gender_pref'],
		);
	}

	/**
	 * Simple ranking: fewer active assigned today first (fairness), then user_id.
	 *
	 * @param array<int,array<string,mixed>> $list  Nurses.
	 * @param array<string,mixed>            $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rank( array $list, array $order ): array {
		global $wpdb;
		$ot = MNS_DB::table( 'orders' );
		foreach ( $list as &$n ) {
			$uid = (int) $n['user_id'];
			$load = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$ot} WHERE nurse_user_id = %d AND status IN ('assigned','in_progress')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$uid
				)
			);
			$n['_score'] = 1000 - $load;
		}
		unset( $n );
		usort(
			$list,
			static function ( $a, $b ) {
				$sa = isset( $a['_score'] ) ? (int) $a['_score'] : 0;
				$sb = isset( $b['_score'] ) ? (int) $b['_score'] : 0;
				if ( $sa === $sb ) {
					return (int) $a['user_id'] <=> (int) $b['user_id'];
				}
				return $sb <=> $sa;
			}
		);
		return $list;
	}
}
