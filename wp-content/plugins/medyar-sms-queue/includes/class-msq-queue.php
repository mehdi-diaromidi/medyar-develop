<?php
defined( 'ABSPATH' ) || exit;

/**
 * Persistence for SMS jobs (one row = one SMS to one phone).
 *
 * Statuses: pending (due or waiting for retry), reserved (claimed by a worker),
 * sent, dead (attempts exhausted / permanent error), canceled.
 */
final class MSQ_Queue {

	public const STATUS_PENDING  = 'pending';
	public const STATUS_RESERVED = 'reserved';
	public const STATUS_SENT     = 'sent';
	public const STATUS_DEAD     = 'dead';
	public const STATUS_CANCELED = 'canceled';

	/** Seconds a reserved row may stay locked before it is considered abandoned. */
	public const STALE_LOCK_SECONDS = 300;

	/** Delay before attempt N+1 (index = attempts already made − 1). */
	private const BACKOFF = array( 30, 120, 600, 1800 );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'msq_jobs';
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array<string,mixed> $row Column => value.
	 */
	public static function insert( array $row ): int {
		global $wpdb;
		$now      = self::now();
		$defaults = array(
			'channel'      => '',
			'event'        => '',
			'transport'    => 'faraz',
			'priority'     => 20,
			'phone'        => '',
			'body'         => '',
			'pattern_code' => '',
			'payload'      => null,
			'meta'         => null,
			'dedupe_key'   => null,
			'status'       => self::STATUS_PENDING,
			'attempts'     => 0,
			'max_attempts' => 3,
			'available_at' => $now,
			'created_at'   => $now,
		);
		$row = array_merge( $defaults, array_intersect_key( $row, $defaults ) );
		$ok  = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Latest row with this dedupe key that still blocks a duplicate (pending/reserved/sent). */
	public static function find_active_by_dedupe( string $key ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dedupe_key = %s AND status IN ('pending','reserved','sent') ORDER BY id DESC LIMIT 1", $key ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Replace the content of a still-pending job (last-wins coalescing).
	 *
	 * @param array<string,mixed> $fields
	 */
	public static function update_pending( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'body', 'pattern_code', 'payload', 'meta', 'priority', 'available_at', 'phone' ) ) );
		if ( ! $allowed ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( self::table(), $allowed, array( 'id' => $id, 'status' => self::STATUS_PENDING ) );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Due job IDs, highest priority (lowest number) first.
	 *
	 * @return int[]
	 */
	public static function due_ids( int $limit = 10 ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' AND available_at <= %s ORDER BY priority ASC, id ASC LIMIT %d", self::now(), $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	public static function has_due(): bool {
		return (bool) self::due_ids( 1 );
	}

	/** Atomic claim: only one worker can move a row from pending to reserved. */
	public static function claim( int $id, string $worker ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'reserved', locked_at = %s, locked_by = %s, attempts = attempts + 1 WHERE id = %d AND status = 'pending' AND available_at <= %s", self::now(), $worker, $id, self::now() ) );
		return 1 === (int) $n;
	}

	public static function mark_sent( int $id, string $detail = '' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'status'     => self::STATUS_SENT,
				'sent_at'    => self::now(),
				'locked_at'  => null,
				'locked_by'  => null,
				'last_error' => '' !== $detail ? $detail : null,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Record a failed attempt; schedules a retry or marks the job dead.
	 *
	 * @return string New status (pending|dead).
	 */
	public static function mark_failed( int $id, string $error, bool $retry ): string {
		global $wpdb;
		$job = self::get( $id );
		if ( ! $job ) {
			return self::STATUS_DEAD;
		}
		$attempts = (int) $job['attempts'];
		$dead     = ! $retry || $attempts >= (int) $job['max_attempts'];
		$delay    = self::BACKOFF[ min( max( $attempts - 1, 0 ), count( self::BACKOFF ) - 1 ) ];
		$status   = $dead ? self::STATUS_DEAD : self::STATUS_PENDING;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			self::table(),
			array(
				'status'       => $status,
				'available_at' => $dead ? $job['available_at'] : gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_at'    => null,
				'locked_by'    => null,
				'last_error'   => mb_substr( $error, 0, 1000 ),
			),
			array( 'id' => $id )
		);
		return $status;
	}

	/** Re-open rows whose worker died mid-send (bounded by max_attempts). */
	public static function release_stale(): int {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_LOCK_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dead = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'dead', locked_at = NULL, locked_by = NULL, last_error = 'stale_lock' WHERE status = 'reserved' AND locked_at < %s AND attempts >= max_attempts", $cutoff ) );
		$open = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', locked_at = NULL, locked_by = NULL, last_error = 'stale_lock' WHERE status = 'reserved' AND locked_at < %s", $cutoff ) );
		// phpcs:enable
		return $dead + $open;
	}

	/** Manually re-queue a dead/canceled/pending job for one more attempt now. */
	public static function retry( int $id ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', available_at = %s, max_attempts = GREATEST(max_attempts, attempts + 1) WHERE id = %d AND status IN ('dead','canceled','pending')", self::now(), $id ) );
		return (int) $n > 0;
	}

	public static function retry_all_dead(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', available_at = %s, max_attempts = GREATEST(max_attempts, attempts + 1) WHERE status = 'dead'", self::now() ) );
	}

	public static function cancel( int $id ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'canceled', locked_at = NULL, locked_by = NULL WHERE id = %d AND status = 'pending'", $id ) );
		return (int) $n > 0;
	}

	/** Housekeeping: sent rows after 60 days, dead/canceled after 120 days. */
	public static function purge(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'sent' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status IN ('dead','canceled') AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}

	/** @return array<string,int> status => count */
	public static function counts(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$out  = array(
			self::STATUS_PENDING  => 0,
			self::STATUS_RESERVED => 0,
			self::STATUS_SENT     => 0,
			self::STATUS_DEAD     => 0,
			self::STATUS_CANCELED => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** @return string[] */
	public static function channels(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT channel FROM {$table} WHERE channel <> '' ORDER BY channel" ) );
	}

	/**
	 * @param array{status?:string,channel?:string,search?:string} $filters
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function search( array $filters, int $page = 1, int $per_page = 30 ): array {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $filters['status'];
		}
		if ( ! empty( $filters['channel'] ) ) {
			$where[]  = 'channel = %s';
			$params[] = (string) $filters['channel'];
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(phone LIKE %s OR event LIKE %s OR body LIKE %s OR last_error LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$sql_where = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}
}
