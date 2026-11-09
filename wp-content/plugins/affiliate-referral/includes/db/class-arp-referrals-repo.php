<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Referrals_Repo {

	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			Arp_Db::referrals(),
			array(
				'affiliate_user_id' => (int) $data['affiliate_user_id'],
				'patient_name'      => $data['patient_name'],
				'patient_phone'     => $data['patient_phone'],
				'service_id'        => (int) $data['service_id'],
				'description'       => $data['description'] ?? '',
				'status_id'         => (int) $data['status_id'],
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Arp_Db::referrals() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Same affiliate + phone + service within the last $seconds (double-submit guard).
	 */
	public static function exists_recent( int $user_id, string $phone, int $service_id, int $seconds ): bool {
		global $wpdb;
		$since = wp_date( 'Y-m-d H:i:s', time() - $seconds );
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . Arp_Db::referrals() . ' WHERE affiliate_user_id = %d AND created_at >= %s AND patient_phone = %s AND service_id = %d LIMIT 1',
				$user_id,
				$since,
				$phone,
				$service_id
			)
		);
	}

	/**
	 * @return int[]
	 */
	public static function ids_by_status( int $status_id ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Arp_Db::referrals() . ' WHERE status_id = %d ORDER BY id ASC', $status_id ) ) // phpcs:ignore
		);
	}

	public static function update_status( int $id, int $status_id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			Arp_Db::referrals(),
			array(
				'status_id'  => $status_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Shared WHERE clause for query()/count().
	 *
	 * Supported keys: id, affiliate_user_id, status_id, service_id,
	 * date_from / date_to (Gregorian Y-m-d, site-local), search (name, phone or description).
	 *
	 * @param array<string,mixed> $args Filters.
	 * @return array{0:string,1:array<int,mixed>} [sql, params]
	 */
	private static function build_where( array $args ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'id', 'affiliate_user_id', 'status_id', 'service_id' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[]  = "r.{$col} = %d";
				$params[] = (int) $args[ $col ];
			}
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'r.created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'r.created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		if ( isset( $args['search'] ) && '' !== $args['search'] ) {
			$where[]  = '(r.patient_name LIKE %s OR r.patient_phone LIKE %s OR r.description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			array_push( $params, $like, $like, $like );
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * @param array<string,mixed> $args Filters (see build_where) + limit, offset, order (asc|desc by created_at).
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$rf = Arp_Db::referrals();
		$sv = Arp_Db::services();
		$st = Arp_Db::referral_statuses();
		$cm = Arp_Db::commissions();

		list( $where_sql, $params ) = self::build_where( $args );

		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$order  = ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		$sql = "SELECT r.*, s.name AS service_name, st.name AS status_name, st.slug AS status_slug, st.color AS status_color,
			st.triggers_commission, st.is_terminal,
			(SELECT c.amount FROM {$cm} c WHERE c.referral_id = r.id AND c.state != 'reversed' ORDER BY c.id DESC LIMIT 1) AS commission_amount,
			(SELECT c.state FROM {$cm} c WHERE c.referral_id = r.id AND c.state != 'reversed' ORDER BY c.id DESC LIMIT 1) AS commission_state
			FROM {$rf} r
			LEFT JOIN {$sv} s ON s.id = r.service_id
			LEFT JOIN {$st} st ON st.id = r.status_id
			WHERE {$where_sql}
			ORDER BY r.created_at {$order}, r.id {$order}
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
		$rf = Arp_Db::referrals();

		list( $where_sql, $params ) = self::build_where( $args );

		$sql = "SELECT COUNT(*) FROM {$rf} r WHERE {$where_sql}";
		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/**
	 * Site-wide counts.
	 *
	 * @return array<int,int> status_id => count
	 */
	public static function count_by_status(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status_id, COUNT(*) AS cnt FROM ' . Arp_Db::referrals() . ' GROUP BY status_id', ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['status_id'] ] = (int) $row['cnt'];
		}
		return $out;
	}

	/**
	 * @return array<int,int> service_id => count
	 */
	public static function count_by_service(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT service_id, COUNT(*) AS cnt FROM ' . Arp_Db::referrals() . ' GROUP BY service_id', ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['service_id'] ] = (int) $row['cnt'];
		}
		return $out;
	}

	/**
	 * Users that have submitted at least one referral.
	 *
	 * @return int[]
	 */
	public static function affiliate_ids(): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT affiliate_user_id FROM ' . Arp_Db::referrals() ) ); // phpcs:ignore
	}

	/**
	 * @param int[] $user_ids Users.
	 * @return array<int,int> user_id => referral count
	 */
	public static function count_by_affiliates( array $user_ids ): array {
		global $wpdb;
		$user_ids = array_filter( array_map( 'intval', $user_ids ) );
		if ( ! $user_ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT affiliate_user_id, COUNT(*) AS cnt FROM ' . Arp_Db::referrals() . " WHERE affiliate_user_id IN ({$in}) GROUP BY affiliate_user_id", $user_ids ), // phpcs:ignore
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['affiliate_user_id'] ] = (int) $row['cnt'];
		}
		return $out;
	}

	/**
	 * Most active affiliates since a site-local date.
	 *
	 * @return array<int,int> user_id => count (descending)
	 */
	public static function top_affiliates_since( string $date_from, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT affiliate_user_id, COUNT(*) AS cnt FROM ' . Arp_Db::referrals() . '
				WHERE created_at >= %s GROUP BY affiliate_user_id ORDER BY cnt DESC LIMIT %d',
				$date_from . ' 00:00:00',
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['affiliate_user_id'] ] = (int) $row['cnt'];
		}
		return $out;
	}

	/**
	 * @return array<int,int> status_id => count
	 */
	public static function count_by_status_for_user( int $user_id ): array {
		global $wpdb;
		$rf   = Arp_Db::referrals();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status_id, COUNT(*) AS cnt FROM {$rf} WHERE affiliate_user_id = %d GROUP BY status_id", $user_id ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['status_id'] ] = (int) $row['cnt'];
		}
		return $out;
	}

	/**
	 * Per-day referral counts since a site-local date.
	 *
	 * @return array<string,int> Y-m-d => count
	 */
	public static function daily_counts_since( int $user_id, string $date_from ): array {
		global $wpdb;
		$rf   = Arp_Db::referrals();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, COUNT(*) AS cnt FROM {$rf}
				WHERE affiliate_user_id = %d AND created_at >= %s
				GROUP BY d",
				$user_id,
				$date_from . ' 00:00:00'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['d'] ] = (int) $row['cnt'];
		}
		return $out;
	}
}
