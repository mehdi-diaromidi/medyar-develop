<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Commissions_Repo {

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arp_Db::commissions() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function get_active_for_referral( int $referral_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Arp_Db::commissions() . " WHERE referral_id = %d AND state != 'reversed' ORDER BY id DESC LIMIT 1",
				$referral_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function list_for_referral( int $referral_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Arp_Db::commissions() . ' WHERE referral_id = %d ORDER BY id DESC',
				$referral_id
			),
			ARRAY_A
		);
	}

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			Arp_Db::commissions(),
			array(
				'referral_id'         => (int) $data['referral_id'],
				'affiliate_user_id'   => (int) $data['affiliate_user_id'],
				'service_id'          => (int) $data['service_id'],
				'percentage_applied'  => $data['percentage_applied'] ?? null,
				'amount'              => $data['amount'],
				'state'               => $data['state'] ?? 'approved',
				'tracking_code'       => $data['tracking_code'] ?? null,
				'paid_at'             => $data['paid_at'] ?? null,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%d', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function update_state( int $id, string $state, array $extra = array() ): bool {
		global $wpdb;
		$data = array_merge(
			array(
				'state'      => $state,
				'updated_at' => current_time( 'mysql' ),
			),
			$extra
		);
		$formats = array();
		foreach ( $data as $key => $val ) {
			if ( in_array( $key, array( 'amount', 'percentage_applied' ), true ) ) {
				$formats[] = '%f';
			} elseif ( in_array( $key, array( 'id' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return false !== $wpdb->update(
			Arp_Db::commissions(),
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Shared WHERE clause for query()/count().
	 *
	 * Supported keys: affiliate_user_id, state, service_id,
	 * date_from / date_to (Gregorian Y-m-d, site-local).
	 *
	 * @param array<string,mixed> $args Filters.
	 * @return array{0:string,1:array<int,mixed>} [sql, params]
	 */
	private static function build_where( array $args ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['affiliate_user_id'] ) ) {
			$where[]  = 'c.affiliate_user_id = %d';
			$params[] = (int) $args['affiliate_user_id'];
		}
		if ( ! empty( $args['affiliate_user_ids'] ) && is_array( $args['affiliate_user_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $args['affiliate_user_ids'] ) ) );
			if ( $ids ) {
				$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$where[] = "c.affiliate_user_id IN ({$in})";
				foreach ( $ids as $id ) {
					$params[] = $id;
				}
			} else {
				$where[] = '1=0';
			}
		}
		if ( ! empty( $args['state'] ) ) {
			$where[]  = 'c.state = %s';
			$params[] = (string) $args['state'];
		}
		if ( ! empty( $args['states'] ) && is_array( $args['states'] ) ) {
			$states = array_values( array_filter( array_map( 'strval', $args['states'] ) ) );
			if ( $states ) {
				$in      = implode( ',', array_fill( 0, count( $states ), '%s' ) );
				$where[] = "c.state IN ({$in})";
				foreach ( $states as $state ) {
					$params[] = $state;
				}
			}
		}
		if ( ! empty( $args['service_id'] ) ) {
			$where[]  = 'c.service_id = %d';
			$params[] = (int) $args['service_id'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'c.created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'c.created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * @param array<string,mixed> $args Filters (see build_where) + limit, offset.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$cm = Arp_Db::commissions();
		$rf = Arp_Db::referrals();
		$sv = Arp_Db::services();

		list( $where_sql, $params ) = self::build_where( $args );

		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = "SELECT c.*, r.patient_name, r.created_at AS referral_created_at, s.name AS service_name
			FROM {$cm} c
			LEFT JOIN {$rf} r ON r.id = c.referral_id
			LEFT JOIN {$sv} s ON s.id = c.service_id
			WHERE {$where_sql}
			ORDER BY c.created_at DESC, c.id DESC
			LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * @param array<string,mixed> $args Filters (see build_where).
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;
		$cm = Arp_Db::commissions();

		list( $where_sql, $params ) = self::build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$cm} c WHERE {$where_sql}";
		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/**
	 * @param array<string,mixed> $args Filters (see build_where).
	 */
	public static function sum_amount( array $args = array() ): float {
		global $wpdb;
		$cm = Arp_Db::commissions();

		list( $where_sql, $params ) = self::build_where( $args );

		$sql = "SELECT COALESCE(SUM(c.amount),0) FROM {$cm} c WHERE {$where_sql}";
		if ( $params ) {
			return (float) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
		}
		return (float) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/**
	 * Count and total per state for the given filters (the `state` filter is ignored).
	 *
	 * @param array<string,mixed> $args Filters (see build_where).
	 * @return array<string,array{count:int,total:float}>
	 */
	public static function summary_by_state( array $args = array() ): array {
		global $wpdb;
		unset( $args['state'] );
		list( $where_sql, $params ) = self::build_where( $args );

		$sql = 'SELECT c.state, COUNT(*) AS cnt, COALESCE(SUM(c.amount),0) AS total FROM ' . Arp_Db::commissions() . " c WHERE {$where_sql} GROUP BY c.state";
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['state'] ] = array(
				'count' => (int) $row['cnt'],
				'total' => (float) $row['total'],
			);
		}
		return $out;
	}

	/**
	 * @param int[] $user_ids Users.
	 * @return array<int,array{payable:float,paid:float}>
	 */
	public static function totals_by_affiliates( array $user_ids ): array {
		global $wpdb;
		$user_ids = array_filter( array_map( 'intval', $user_ids ) );
		if ( ! $user_ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT affiliate_user_id,
					COALESCE(SUM(CASE WHEN state = 'approved' THEN amount END),0) AS payable,
					COALESCE(SUM(CASE WHEN state = 'paid' THEN amount END),0) AS paid
				FROM " . Arp_Db::commissions() . " WHERE affiliate_user_id IN ({$in}) GROUP BY affiliate_user_id", // phpcs:ignore
				$user_ids
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['affiliate_user_id'] ] = array(
				'payable' => (float) $row['payable'],
				'paid'    => (float) $row['paid'],
			);
		}
		return $out;
	}

	/**
	 * Affiliates with an approved, unpaid balance (largest first).
	 *
	 * @return array<int,array{total:float,count:int}> user_id => balance
	 */
	public static function payable_affiliates(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT affiliate_user_id, COUNT(*) AS cnt, SUM(amount) AS total FROM ' . Arp_Db::commissions() . "
			WHERE state = 'approved' GROUP BY affiliate_user_id ORDER BY total DESC", // phpcs:ignore
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['affiliate_user_id'] ] = array(
				'total' => (float) $row['total'],
				'count' => (int) $row['cnt'],
			);
		}
		return $out;
	}

	/**
	 * Total paid out on/after a site-local date.
	 */
	public static function sum_paid_since( string $date_from ): float {
		global $wpdb;
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount),0) FROM ' . Arp_Db::commissions() . " WHERE state = 'paid' AND paid_at >= %s",
				$date_from . ' 00:00:00'
			)
		);
	}

	/**
	 * @return int[]
	 */
	public static function affiliate_ids(): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT affiliate_user_id FROM ' . Arp_Db::commissions() ) ); // phpcs:ignore
	}

	public static function sum_approved_unpaid( int $user_id ): float {
		global $wpdb;
		$cm = Arp_Db::commissions();
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$cm} WHERE affiliate_user_id = %d AND state = 'approved'",
				$user_id
			)
		);
		return (float) $val;
	}

	/**
	 * Earned (approved + paid) commission created on/after a site-local date.
	 */
	public static function sum_earned_since( int $user_id, string $date_from ): float {
		global $wpdb;
		$cm  = Arp_Db::commissions();
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$cm}
				WHERE affiliate_user_id = %d
				AND state IN ('approved','paid')
				AND created_at >= %s",
				$user_id,
				$date_from . ' 00:00:00'
			)
		);
		return (float) $val;
	}

	public static function get_approved_unpaid_for_user( int $user_id ): array {
		global $wpdb;
		$cm = Arp_Db::commissions();
		$rf = Arp_Db::referrals();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, r.patient_name FROM {$cm} c
				LEFT JOIN {$rf} r ON r.id = c.referral_id
				WHERE c.affiliate_user_id = %d AND c.state = 'approved'
				ORDER BY c.created_at ASC",
				$user_id
			),
			ARRAY_A
		);
	}
}
