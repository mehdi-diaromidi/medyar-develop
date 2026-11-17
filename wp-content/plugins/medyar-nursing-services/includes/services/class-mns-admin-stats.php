<?php
/**
 * Live admin dashboard / menu counts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Stats {

	/**
	 * @return array{nurses_pending:int,needs_review:int,urgent_open:int,unassigned:int,today:int,in_progress:int,total:int}
	 */
	public static function bubbles(): array {
		global $wpdb;
		$nurses = MNS_DB::table( 'nurses' );
		$orders = MNS_DB::table( 'orders' );
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nurses} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$review  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE status = 'needs_review'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$unassigned = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE status = 'submitted' AND (nurse_user_id IS NULL OR nurse_user_id = 0)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today = MNS_Time::today_tehran();
		$today_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders} WHERE DATE(created_at) = %s OR service_date = %s", $today, $today ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$in_prog = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders} WHERE status = 'in_progress'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'nurses_pending' => $pending,
			'needs_review'   => $review,
			'urgent_open'    => $unassigned, // treat unassigned as urgent ops queue.
			'unassigned'     => $unassigned,
			'today'          => $today_n,
			'in_progress'    => $in_prog,
			'total'          => $pending + $review + $unassigned,
		);
	}

	/**
	 * @param array<string,mixed> $args Filters.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function query_orders( array $args ): array {
		global $wpdb;
		$ot = MNS_DB::table( 'orders' );
		$where = array( '1=1' );
		$params = array();

		$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		if ( $status ) {
			$where[]  = 'o.status = %s';
			$params[] = $status;
		}
		if ( ! empty( $args['unassigned'] ) ) {
			$where[] = "o.status = 'submitted' AND (o.nurse_user_id IS NULL OR o.nurse_user_id = 0)";
		}
		$nurse = isset( $args['nurse_id'] ) ? (int) $args['nurse_id'] : 0;
		if ( $nurse > 0 ) {
			$where[]  = 'o.nurse_user_id = %d';
			$params[] = $nurse;
		}
		$source = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : '';
		if ( $source ) {
			$where[]  = 'o.source = %s';
			$params[] = $source;
		}
		$from = isset( $args['date_from'] ) ? sanitize_text_field( (string) $args['date_from'] ) : '';
		if ( $from ) {
			$where[]  = 'o.service_date >= %s';
			$params[] = $from;
		}
		$to = isset( $args['date_to'] ) ? sanitize_text_field( (string) $args['date_to'] ) : '';
		if ( $to ) {
			$where[]  = 'o.service_date <= %s';
			$params[] = $to;
		}
		$q = isset( $args['s'] ) ? sanitize_text_field( (string) $args['s'] ) : '';
		if ( '' !== $q ) {
			$where[]  = '(o.code LIKE %s OR o.contact_phone LIKE %s OR o.address LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $q ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql_where = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$ot} o WHERE {$sql_where}";
		if ( $params ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per = max( 1, min( 100, isset( $args['per'] ) ? (int) $args['per'] : 20 ) );
		$p   = max( 1, isset( $args['p'] ) ? (int) $args['p'] : 1 );
		$off = ( $p - 1 ) * $per;
		$list_sql = "SELECT o.* FROM {$ot} o WHERE {$sql_where} ORDER BY
			CASE WHEN o.status = 'needs_review' THEN 0 WHEN o.status = 'submitted' AND (o.nurse_user_id IS NULL OR o.nurse_user_id = 0) THEN 1 ELSE 2 END,
			o.created_at DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per, $off ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent_orders( int $limit = 6 ): array {
		$pack = self::query_orders( array( 'per' => $limit, 'p' => 1 ) );
		return $pack['rows'];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function unassigned( int $limit = 6 ): array {
		$pack = self::query_orders( array( 'unassigned' => 1, 'per' => $limit, 'p' => 1 ) );
		return $pack['rows'];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending_nurses( int $limit = 6 ): array {
		global $wpdb;
		$t = MNS_DB::table( 'nurses' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.*, u.display_name FROM {$t} n LEFT JOIN {$wpdb->users} u ON u.ID = n.user_id WHERE n.status = 'pending' ORDER BY n.applied_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string,int>
	 */
	public static function count_by_status(): array {
		global $wpdb;
		$ot = MNS_DB::table( 'orders' );
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$ot} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ (string) $r['status'] ] = (int) $r['c'];
			}
		}
		return $out;
	}
}
