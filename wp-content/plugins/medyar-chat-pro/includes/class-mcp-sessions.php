<?php
defined( 'ABSPATH' ) || exit;

/**
 * Conversation workflow status, response-time metrics and follow-up flags.
 */
final class MCP_Sessions {

	public const OPEN               = 'open';
	public const WAITING_SPECIALIST = 'waiting_specialist';
	public const WAITING_PATIENT    = 'waiting_patient';
	public const ANSWERED           = 'answered';
	public const CLOSED             = 'closed';

	/** @return array<string,string> */
	public static function labels(): array {
		return array(
			self::OPEN               => __( 'باز', 'medyar-chat-pro' ),
			self::WAITING_SPECIALIST => __( 'در انتظار پاسخ متخصص', 'medyar-chat-pro' ),
			self::WAITING_PATIENT    => __( 'در انتظار پاسخ بیمار', 'medyar-chat-pro' ),
			self::ANSWERED           => __( 'پاسخ داده‌شده', 'medyar-chat-pro' ),
			self::CLOSED             => __( 'بسته‌شده', 'medyar-chat-pro' ),
		);
	}

	public static function is_valid( string $status ): bool {
		return array_key_exists( $status, self::labels() );
	}

	private static function table(): string {
		return MCP_Install::table( 'session_meta' );
	}

	/** @return array<string,mixed>|null */
	public static function get_meta( int $session_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE session_id = %d', $session_id ), ARRAY_A );
		return $row ?: null;
	}

	/** @return array<string,mixed> */
	public static function ensure_meta( int $session_id ): array {
		$meta = self::get_meta( $session_id );
		if ( $meta ) {
			return $meta;
		}
		self::recompute( $session_id, true );
		return self::get_meta( $session_id ) ?? array(
			'session_id' => $session_id,
			'status'     => self::OPEN,
		);
	}

	/**
	 * The theme's is_closed flag always wins; a stale "closed" meta on a reopened session reads as open.
	 *
	 * @param array<string,mixed>      $session
	 * @param array<string,mixed>|null $meta
	 */
	public static function effective_status( array $session, ?array $meta ): string {
		if ( MCP_Theme::is_closed( $session ) ) {
			return self::CLOSED;
		}
		$status = (string) ( $meta['status'] ?? self::OPEN );
		if ( self::CLOSED === $status || ! self::is_valid( $status ) ) {
			return self::OPEN;
		}
		return $status;
	}

	/** Public, role-agnostic state used by the chat UI. */
	public static function state( int $session_id ): array {
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session ) {
			return array();
		}
		$meta   = self::ensure_meta( $session_id );
		$status = self::effective_status( $session, $meta );
		// Fresh read after timing sync (may auto-close when booking end_time passed).
		$timing  = MCP_Timing::for_session( $session_id );
		$session = MCP_Theme::get_session( $session_id, true ) ?? $session;
		$meta    = self::get_meta( $session_id ) ?? $meta;
		$status  = self::effective_status( $session, $meta );
		return array(
			'status'        => $status,
			'status_label'  => self::labels()[ $status ],
			'follow_up_at'  => $meta['follow_up_at'] ?? null,
			'follow_up_due' => ! empty( $meta['follow_up_at'] ) && $meta['follow_up_at'] <= MCP_Theme::now(),
			'accepting'     => MCP_Theme::accepts_messages( $session ),
			'timing'        => $timing,
		);
	}

	private const TYPING_TTL = 10;

	public static function set_typing( int $session_id, int $user_id, bool $typing ): void {
		$key = 'mcp_typing_' . $session_id . '_' . $user_id;
		if ( $typing ) {
			set_transient( $key, time(), self::TYPING_TTL );
		} else {
			delete_transient( $key );
		}
	}

	/** Whether the other participant of the session is typing right now. */
	public static function other_typing( int $session_id, int $user_id ): bool {
		$session = MCP_Theme::get_session( $session_id );
		$role    = MCP_Theme::role_in_session( $session, $user_id );
		if ( '' === $role ) {
			return false;
		}
		$other = (int) ( 'patient' === $role ? $session['user_1_id'] : $session['user_2_id'] );
		$at    = (int) get_transient( 'mcp_typing_' . $session_id . '_' . $other );
		return $at > 0 && time() - $at < self::TYPING_TTL;
	}

	/** Called for every successfully stored message (theme hook drplus/chat/message_sent). */
	public static function on_message( int $session_id, int $sender_id ): void {
		global $wpdb;
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session ) {
			return;
		}
		$role = MCP_Theme::role_in_session( $session, $sender_id );
		if ( '' === $role ) {
			return;
		}
		self::set_typing( $session_id, $sender_id, false );
		$meta = self::ensure_meta( $session_id );
		$now  = MCP_Theme::now();
		$data = array( 'updated_at' => $now );

		if ( 'patient' === $role ) {
			$data['last_patient_at'] = $now;
			if ( empty( $meta['awaiting_since'] ) ) {
				$data['awaiting_since'] = $now;
			}
			if ( self::CLOSED !== $meta['status'] ) {
				$data['status'] = self::WAITING_SPECIALIST;
			}
		} else {
			$data['last_specialist_at'] = $now;
			if ( ! empty( $meta['awaiting_since'] ) ) {
				$secs                        = max( 0, strtotime( $now ) - strtotime( (string) $meta['awaiting_since'] ) );
				$data['awaiting_since']      = null;
				$data['response_count']      = (int) $meta['response_count'] + 1;
				$data['response_total_secs'] = (int) $meta['response_total_secs'] + $secs;
				if ( null === $meta['first_response_secs'] ) {
					$data['first_response_secs'] = $secs;
				}
			}
			if ( in_array( $meta['status'], array( self::OPEN, self::WAITING_SPECIALIST ), true ) ) {
				$data['status'] = self::ANSWERED;
			}
		}

		$wpdb->update( self::table(), $data, array( 'session_id' => $session_id ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function set_status( int $session_id, string $status, int $actor_id ) {
		global $wpdb;
		if ( ! self::is_valid( $status ) ) {
			return new WP_Error( 'mcp_invalid_status', __( 'وضعیت انتخاب‌شده معتبر نیست.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$session = MCP_Theme::get_session( $session_id, true );
		if ( ! $session ) {
			return new WP_Error( 'mcp_not_found', __( 'مکالمه پیدا نشد.', 'medyar-chat-pro' ), array( 'status' => 404 ) );
		}
		$meta = self::ensure_meta( $session_id );
		$from = self::effective_status( $session, $meta );
		if ( $from === $status ) {
			return true;
		}

		if ( self::CLOSED === $from && ! MCP_Access::can_manage() ) {
			return new WP_Error( 'mcp_reopen_forbidden', __( 'بازگشایی مکالمه بسته‌شده فقط توسط مدیر سیستم امکان‌پذیر است.', 'medyar-chat-pro' ), array( 'status' => 403 ) );
		}

		if ( self::CLOSED === $status ) {
			MCP_Theme::close_session( $session_id );
			MCP_Audit::log( 'session_closed', $session_id, array( 'from' => $from ) );
		} elseif ( self::CLOSED === $from ) {
			MCP_Theme::reopen_session( $session_id );
			MCP_Audit::log( 'session_reopened', $session_id, array( 'to' => $status ) );
		}

		$wpdb->update(
			self::table(),
			array(
				'status'            => $status,
				'status_changed_at' => MCP_Theme::now(),
				'status_changed_by' => $actor_id,
				'updated_at'        => MCP_Theme::now(),
			),
			array( 'session_id' => $session_id )
		);
		MCP_Audit::log( 'status_changed', $session_id, array( 'from' => $from, 'to' => $status ) );
		do_action( 'mcp_session_status_changed', $session_id, $from, $status, $actor_id );
		return true;
	}

	/** @return true|WP_Error */
	public static function set_follow_up( int $session_id, ?string $at, string $note ) {
		global $wpdb;
		self::ensure_meta( $session_id );
		if ( null !== $at ) {
			$ts = strtotime( $at );
			if ( ! $ts ) {
				return new WP_Error( 'mcp_invalid_date', __( 'تاریخ پیگیری معتبر نیست.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
			}
			$at = gmdate( 'Y-m-d H:i:s', $ts );
		}
		$note = mb_substr( sanitize_text_field( $note ), 0, 255 );
		$wpdb->update(
			self::table(),
			array(
				'follow_up_at'   => $at,
				'follow_up_note' => null === $at ? null : $note,
				'updated_at'     => MCP_Theme::now(),
			),
			array( 'session_id' => $session_id )
		);
		MCP_Audit::log( null === $at ? 'follow_up_cleared' : 'follow_up_set', $session_id, null === $at ? array() : array( 'at' => $at ) );
		return true;
	}

	/**
	 * Rebuilds metrics from the theme's message table.
	 *
	 * @param bool $derive_status Also derive the workflow status (first install / missing row).
	 */
	public static function recompute( int $session_id, bool $derive_status = false ): void {
		global $wpdb;
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session ) {
			return;
		}
		$specialist = (int) $session['user_1_id'];
		$patient    = (int) $session['user_2_id'];
		$messages   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT sender_id, created_at FROM ' . MCP_Theme::messages_table() . ' WHERE session_id = %d ORDER BY id ASC', $session_id ),
			ARRAY_A
		);

		$awaiting = null;
		$count    = 0;
		$total    = 0;
		$first    = null;
		$last_p   = null;
		$last_s   = null;
		foreach ( (array) $messages as $m ) {
			$sender = (int) $m['sender_id'];
			if ( $sender === $patient ) {
				$last_p   = $m['created_at'];
				$awaiting = $awaiting ?? $m['created_at'];
			} elseif ( $sender === $specialist ) {
				$last_s = $m['created_at'];
				if ( null !== $awaiting ) {
					$secs   = max( 0, strtotime( $m['created_at'] ) - strtotime( $awaiting ) );
					$total += $secs;
					++$count;
					$first    = $first ?? $secs;
					$awaiting = null;
				}
			}
		}

		$data = array(
			'awaiting_since'      => $awaiting,
			'last_patient_at'     => $last_p,
			'last_specialist_at'  => $last_s,
			'first_response_secs' => $first,
			'response_count'      => $count,
			'response_total_secs' => $total,
			'updated_at'          => MCP_Theme::now(),
		);

		$existing = self::get_meta( $session_id );
		if ( ! $existing || $derive_status ) {
			if ( MCP_Theme::is_closed( $session ) ) {
				$status = self::CLOSED;
			} elseif ( null !== $awaiting ) {
				$status = self::WAITING_SPECIALIST;
			} elseif ( null !== $last_s ) {
				$status = self::ANSWERED;
			} else {
				$status = self::OPEN;
			}
			$data['status'] = $status;
		}

		if ( $existing ) {
			$wpdb->update( self::table(), $data, array( 'session_id' => $session_id ) );
		} else {
			$data['session_id'] = $session_id;
			$wpdb->insert( self::table(), $data );
		}
	}

	public static function backfill(): int {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT id FROM ' . MCP_Theme::sessions_table() . ' ORDER BY id ASC' );
		foreach ( (array) $ids as $id ) {
			self::recompute( (int) $id, true );
		}
		return count( (array) $ids );
	}

	/** Rebuilds metrics for every session, keeping manually chosen statuses. */
	public static function backfill_metrics_only(): int {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT id FROM ' . MCP_Theme::sessions_table() . ' ORDER BY id ASC' );
		foreach ( (array) $ids as $id ) {
			self::recompute( (int) $id );
		}
		return count( (array) $ids );
	}

	/**
	 * Lightweight state for list pages (only sessions the current user participates in).
	 *
	 * @param int[] $ids
	 * @return array<int,array<string,mixed>>
	 */
	public static function summaries_for_user( array $ids, int $user_id ): array {
		$out = array();
		foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 50 ) as $id ) {
			$session = MCP_Theme::get_session( $id );
			$role    = MCP_Theme::role_in_session( $session, $user_id );
			if ( '' === $role ) {
				continue;
			}
			$meta  = self::ensure_meta( $id );
			$state = self::state( $id );
			$item  = array(
				'status'       => $state['status'],
				'status_label' => $state['status_label'],
			);
			if ( 'specialist' === $role ) {
				$item['follow_up_at']   = $state['follow_up_at'];
				$item['follow_up_due']  = $state['follow_up_due'];
				$item['waiting_secs']   = empty( $meta['awaiting_since'] ) || self::CLOSED === $state['status']
					? 0
					: max( 0, strtotime( MCP_Theme::now() ) - strtotime( (string) $meta['awaiting_since'] ) );
			}
			$out[ $id ] = $item;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Reporting
	 * ------------------------------------------------------------------- */

	/**
	 * Base SQL joining theme sessions with chat-pro meta; effective status resolved in SQL.
	 */
	private static function status_sql(): string {
		$closed = self::CLOSED;
		$open   = self::OPEN;
		return "CASE WHEN s.is_closed = 1 THEN '{$closed}' WHEN m.status IS NULL OR m.status = '{$closed}' THEN '{$open}' ELSE m.status END";
	}

	/**
	 * @param array<string,mixed> $filters status, specialist, patient, from, to, search, follow_up.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function where( array $filters ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) && self::is_valid( (string) $filters['status'] ) ) {
			$where[] = self::status_sql() . ' = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( ! empty( $filters['specialist'] ) ) {
			$where[] = 's.user_1_id = %d';
			$args[]  = (int) $filters['specialist'];
		}
		if ( ! empty( $filters['patient'] ) ) {
			$where[] = 's.user_2_id = %d';
			$args[]  = (int) $filters['patient'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[] = 's.created_at >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[] = 's.created_at <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
		}
		if ( ! empty( $filters['follow_up'] ) ) {
			$where[] = 'm.follow_up_at IS NOT NULL AND m.follow_up_at <= %s';
			$args[]  = MCP_Theme::now();
		}
		if ( ! empty( $filters['search'] ) ) {
			global $wpdb;
			$like    = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[] = '(s.id = %d OR s.user_1_id IN (SELECT ID FROM ' . $wpdb->users . ' WHERE display_name LIKE %s) OR s.user_2_id IN (SELECT ID FROM ' . $wpdb->users . ' WHERE display_name LIKE %s))';
			$args[]  = (int) $filters['search'];
			$args[]  = $like;
			$args[]  = $like;
		}
		return array( implode( ' AND ', $where ), $args );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array{rows: array<int,array<string,mixed>>, total: int}
	 */
	public static function search( array $filters, string $orderby = 'last_activity', string $order = 'DESC', int $page = 1, int $per_page = 30 ): array {
		global $wpdb;
		$sessions = MCP_Theme::sessions_table();
		$messages = MCP_Theme::messages_table();
		$meta     = self::table();
		[ $where, $args ] = self::where( $filters );

		$now      = esc_sql( MCP_Theme::now() );
		$columns  = array(
			'id'            => 's.id',
			'created_at'    => 's.created_at',
			'last_activity' => 'last_activity',
			'waiting'       => 'waiting_secs',
			'avg_response'  => 'avg_response_secs',
			'status'        => 'eff_status',
			'messages'      => 'message_count',
		);
		$order_col = $columns[ $orderby ] ?? 'last_activity';
		$order     = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		$select = 'SELECT s.*, ' . self::status_sql() . " AS eff_status,
				m.follow_up_at, m.follow_up_note, m.awaiting_since, m.response_count, m.first_response_secs,
				IF(m.response_count > 0, ROUND(m.response_total_secs / m.response_count), NULL) AS avg_response_secs,
				IF(s.is_closed = 0 AND m.awaiting_since IS NOT NULL, TIMESTAMPDIFF(SECOND, m.awaiting_since, '{$now}'), 0) AS waiting_secs,
				(SELECT COUNT(*) FROM {$messages} x WHERE x.session_id = s.id) AS message_count,
				COALESCE((SELECT MAX(x.created_at) FROM {$messages} x WHERE x.session_id = s.id), s.created_at) AS last_activity
			FROM {$sessions} s LEFT JOIN {$meta} m ON m.session_id = s.id
			WHERE {$where}";

		$total_sql = "SELECT COUNT(*) FROM {$sessions} s LEFT JOIN {$meta} m ON m.session_id = s.id WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $total_sql, $args ) ) : $wpdb->get_var( $total_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = $wpdb->get_results(
			$wpdb->prepare( "{$select} ORDER BY {$order_col} {$order}, s.id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<string,int|float|null>
	 */
	public static function overview( array $filters = array() ): array {
		global $wpdb;
		$sessions = MCP_Theme::sessions_table();
		$meta     = self::table();
		unset( $filters['status'] );
		[ $where, $args ] = self::where( $filters );
		$status = self::status_sql();
		$now    = esc_sql( MCP_Theme::now() );

		$sql = "SELECT COUNT(*) AS total,
				SUM({$status} = 'open') AS open_count,
				SUM({$status} = 'waiting_specialist') AS waiting_specialist,
				SUM({$status} = 'waiting_patient') AS waiting_patient,
				SUM({$status} = 'answered') AS answered,
				SUM({$status} = 'closed') AS closed,
				SUM(m.follow_up_at IS NOT NULL AND m.follow_up_at <= '{$now}' AND s.is_closed = 0) AS follow_up_due,
				SUM(m.response_total_secs) / NULLIF(SUM(m.response_count), 0) AS avg_response_secs,
				AVG(m.first_response_secs) AS avg_first_response_secs,
				MAX(IF(s.is_closed = 0 AND m.awaiting_since IS NOT NULL, TIMESTAMPDIFF(SECOND, m.awaiting_since, '{$now}'), NULL)) AS max_waiting_secs
			FROM {$sessions} s LEFT JOIN {$meta} m ON m.session_id = s.id WHERE {$where}";

		$row = $args ? $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( static fn( $v ) => null === $v ? null : ( is_numeric( $v ) ? $v + 0 : $v ), (array) $row );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public static function per_specialist( array $filters = array() ): array {
		global $wpdb;
		$sessions = MCP_Theme::sessions_table();
		$meta     = self::table();
		unset( $filters['specialist'], $filters['status'] );
		[ $where, $args ] = self::where( $filters );
		$status = self::status_sql();
		$now    = esc_sql( MCP_Theme::now() );

		$sql = "SELECT s.user_1_id AS specialist_id, COUNT(*) AS total,
				SUM({$status} <> 'closed') AS active,
				SUM({$status} = 'closed') AS closed,
				SUM({$status} = 'waiting_specialist') AS waiting_specialist,
				SUM(m.response_total_secs) / NULLIF(SUM(m.response_count), 0) AS avg_response_secs,
				MAX(IF(s.is_closed = 0 AND m.awaiting_since IS NOT NULL, TIMESTAMPDIFF(SECOND, m.awaiting_since, '{$now}'), NULL)) AS max_waiting_secs
			FROM {$sessions} s LEFT JOIN {$meta} m ON m.session_id = s.id
			WHERE {$where} GROUP BY s.user_1_id ORDER BY total DESC";

		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $rows ?: array();
	}
}
