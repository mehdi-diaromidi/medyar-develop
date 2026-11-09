<?php
defined( 'ABSPATH' ) || exit;

/**
 * Consultation slot countdown: warn at T-5m, specialist extend/decline, auto-close at end.
 * Authoritative end is drplus_booking.end_time (linked via chat session context_id).
 *
 * All comparisons use real Unix timestamps via DateTimeImmutable + wp_timezone()
 * (never mix strtotime() with current_time( 'timestamp' )).
 */
final class MCP_Timing {

	public const WARN_SECS         = 300;
	public const EXTEND_OPTS       = array( 5, 10, 15, 20 );
	public const DECISION_NONE     = '';
	public const DECISION_DECLINED = 'declined';
	public const CRON_HOOK         = 'mcp_timing_tick';
	/** Unix time the feature went live; slots ending before it are legacy (no countdown, no auto-close). */
	public const SINCE_OPTION      = 'mcp_timing_since';

	/** @var array<int,bool> session ids that must not auto-close in this request (just extended). */
	private static array $hold_close = array();

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
	}

	/** @param array<string,array<string,mixed>> $schedules */
	public static function schedules( array $schedules ): array {
		if ( ! isset( $schedules['mcp_minute'] ) ) {
			$schedules['mcp_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => 'Every minute (Medyar Chat Pro)',
			);
		}
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 30, 'mcp_minute', self::CRON_HOOK );
		}
	}

	public static function cron(): void {
		global $wpdb;
		if ( ! MCP_Theme::is_available() ) {
			return;
		}
		$ids = $wpdb->get_col(
			'SELECT id FROM ' . MCP_Theme::sessions_table() . ' WHERE is_closed = 0 AND context_id > 0 ORDER BY id DESC LIMIT 300'
		);
		foreach ( array_map( 'absint', (array) $ids ) as $session_id ) {
			if ( $session_id > 0 ) {
				self::sync( $session_id );
			}
		}
	}

	/**
	 * Public timing payload for the chat UI (also runs auto-close / warn bookkeeping).
	 *
	 * @return array<string,mixed>
	 */
	public static function for_session( int $session_id ): array {
		$session = MCP_Theme::get_session( $session_id );
		if ( ! $session ) {
			return self::empty_payload();
		}
		self::sync( $session_id );
		$session = MCP_Theme::get_session( $session_id, true );
		$meta    = MCP_Sessions::get_meta( $session_id );
		$ends_at = self::ends_at( $session );
		if ( null === $ends_at ) {
			return self::empty_payload();
		}

		$now       = self::now_ts();
		$remaining = $ends_at - $now;
		if ( self::is_legacy( $ends_at ) ) {
			return self::empty_payload(); // Slot ended before the feature existed: no countdown UI.
		}
		$decision  = sanitize_key( (string) ( $meta['timing_decision'] ?? '' ) );
		$notice    = (int) ( $meta['timing_patient_notice'] ?? 0 );
		$phase     = 'ok';
		if ( MCP_Theme::is_closed( $session ) || $remaining <= 0 ) {
			$phase = 'ended';
		} elseif ( $remaining <= self::WARN_SECS ) {
			$phase = 'warn';
		}

		$can_extend = 'warn' === $phase
			&& self::DECISION_DECLINED !== $decision
			&& ! MCP_Theme::is_closed( $session );

		return array(
			'enabled'             => true,
			'ends_at'             => self::format_local( $ends_at, 'Y-m-d H:i:s' ),
			'ends_at_ts'          => $ends_at,
			'server_now_ts'       => $now,
			'seconds_remaining'   => max( 0, $remaining ),
			'phase'               => $phase,
			'can_extend'          => $can_extend,
			'extend_options'      => self::EXTEND_OPTS,
			'decision'            => $decision,
			'patient_notice_mins' => max( 0, $notice ),
		);
	}

	/** Close when past end; re-arm warn prompt after a prior extend. */
	public static function sync( int $session_id ): void {
		$session = MCP_Theme::get_session( $session_id, true );
		if ( ! $session || MCP_Theme::is_closed( $session ) ) {
			return;
		}
		$ends_at = self::ends_at( $session );
		if ( null === $ends_at ) {
			return;
		}
		$now       = self::now_ts();
		$remaining = $ends_at - $now;
		MCP_Sessions::ensure_meta( $session_id );

		if ( $remaining <= 0 ) {
			if ( ! empty( self::$hold_close[ $session_id ] ) || self::is_legacy( $ends_at ) ) {
				return;
			}
			MCP_Sessions::set_status( $session_id, MCP_Sessions::CLOSED, 0 );
			MCP_Audit::log( 'session_auto_closed', $session_id, array( 'reason' => 'booking_end' ) );
			return;
		}

		if ( $remaining <= self::WARN_SECS ) {
			$meta     = MCP_Sessions::get_meta( $session_id );
			$decision = sanitize_key( (string) ( $meta['timing_decision'] ?? '' ) );
			// After a prior extend, clear the flag so the specialist is prompted again in the new warn window.
			if ( 'extended' === $decision ) {
				self::update_meta(
					$session_id,
					array(
						'timing_decision' => self::DECISION_NONE,
					)
				);
			}
		}
	}

	/**
	 * Specialist extends the booking end_time. Free of charge (no order price change).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function extend( int $session_id, int $minutes, int $actor_id ) {
		$minutes = (int) $minutes;
		if ( ! in_array( $minutes, self::EXTEND_OPTS, true ) ) {
			return new WP_Error( 'mcp_invalid_extend', __( 'مدت تمدید معتبر نیست.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$session = MCP_Theme::get_session( $session_id, true );
		if ( ! $session || MCP_Theme::is_closed( $session ) ) {
			return new WP_Error( 'mcp_chat_closed', __( 'این مکالمه بسته شده است.', 'medyar-chat-pro' ), array( 'status' => 403 ) );
		}
		$ends_at = self::ends_at( $session );
		if ( null === $ends_at ) {
			return new WP_Error( 'mcp_no_slot', __( 'زمان پایان مشاوره برای این مکالمه تعریف نشده است.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$now       = self::now_ts();
		$remaining = $ends_at - $now;
		if ( $remaining > self::WARN_SECS ) {
			return new WP_Error( 'mcp_extend_early', __( 'هنوز بیش از ۵ دقیقه تا پایان مشاوره باقی است.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}

		$book_id = (int) $session['context_id'];
		$booking = MCP_Theme::booking( $book_id );
		if ( ! $booking ) {
			return new WP_Error( 'mcp_no_booking', __( 'نوبت مرتبط پیدا نشد.', 'medyar-chat-pro' ), array( 'status' => 404 ) );
		}

		// Extend from the later of slot end and now, so a late click still adds full minutes.
		$base_ts  = max( $ends_at, $now );
		$new_ts   = $base_ts + ( $minutes * MINUTE_IN_SECONDS );
		$old_date = (string) $booking['date'];
		$new_end  = self::format_local( $new_ts, 'H:i:s' );
		$new_date = self::storage_date( self::format_local( $new_ts, 'Y-m-d' ), $old_date );

		global $wpdb;
		$fields = array( 'end_time' => $new_end );
		$format = array( '%s' );
		if ( $new_date !== $old_date ) {
			$fields['date'] = $new_date;
			$format[]       = '%s';
		}
		$updated = $wpdb->update(
			$wpdb->prefix . 'drplus_booking',
			$fields,
			array( 'book_id' => $book_id ),
			$format,
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'mcp_extend_failed', __( 'ذخیره زمان جدید ناموفق بود.', 'medyar-chat-pro' ), array( 'status' => 500 ) );
		}

		self::patch_booking_meta( $book_id, $new_end, $new_date !== $old_date ? $new_date : null );

		MCP_Sessions::ensure_meta( $session_id );
		self::update_meta(
			$session_id,
			array(
				'timing_decision'       => 'extended',
				'timing_patient_notice' => $minutes,
			)
		);

		$specialist_id = (int) $session['user_1_id'];
		$msg           = sprintf(
			/* translators: %d: minutes extended */
			__( 'مدت زمان مشاوره توسط متخصص %d دقیقه تمدید شد. تمدید هزینه‌ای برای شما ندارد.', 'medyar-chat-pro' ),
			$minutes
		);
		MCP_Theme::insert_message_silently( $session_id, $specialist_id, $msg );
		MCP_Audit::log(
			'session_extended',
			$session_id,
			array(
				'minutes'  => $minutes,
				'end_time' => $new_end,
				'ends_at'  => $new_ts,
				'actor'    => $actor_id,
			)
		);

		// Hold auto-close for the rest of this request (extend + subsequent state() rebuild).
		self::$hold_close[ $session_id ] = true;
		$payload = self::for_session( $session_id );

		// Guarantee payload reflects the new end even if booking read was stale.
		if ( (int) ( $payload['ends_at_ts'] ?? 0 ) < $new_ts ) {
			$payload['ends_at']             = self::format_local( $new_ts, 'Y-m-d H:i:s' );
			$payload['ends_at_ts']          = $new_ts;
			$payload['seconds_remaining']   = max( 0, $new_ts - self::now_ts() );
			$payload['phase']               = $payload['seconds_remaining'] <= self::WARN_SECS ? 'warn' : 'ok';
			$payload['can_extend']          = false;
			$payload['decision']            = 'extended';
			$payload['patient_notice_mins'] = $minutes;
			$payload['enabled']             = true;
		}

		return $payload;
	}

	/** Release a same-request auto-close hold (called after REST finishes building the response). */
	public static function release_close_hold( int $session_id ): void {
		unset( self::$hold_close[ $session_id ] );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function decline( int $session_id, int $actor_id ) {
		$session = MCP_Theme::get_session( $session_id, true );
		if ( ! $session || MCP_Theme::is_closed( $session ) ) {
			return new WP_Error( 'mcp_chat_closed', __( 'این مکالمه بسته شده است.', 'medyar-chat-pro' ), array( 'status' => 403 ) );
		}
		$ends_at = self::ends_at( $session );
		if ( null === $ends_at ) {
			return new WP_Error( 'mcp_no_slot', __( 'زمان پایان مشاوره برای این مکالمه تعریف نشده است.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		MCP_Sessions::ensure_meta( $session_id );
		self::update_meta(
			$session_id,
			array(
				'timing_decision' => self::DECISION_DECLINED,
			)
		);
		MCP_Audit::log( 'session_extend_declined', $session_id, array( 'actor' => $actor_id ) );
		return self::for_session( $session_id );
	}

	/** Patient dismisses the “extended” flash banner. */
	public static function ack_patient_notice( int $session_id ): array {
		MCP_Sessions::ensure_meta( $session_id );
		self::update_meta( $session_id, array( 'timing_patient_notice' => 0 ) );
		return self::for_session( $session_id );
	}

	/** @param array<string,mixed> $session */
	private static function ends_at( array $session ): ?int {
		$book_id = (int) ( $session['context_id'] ?? 0 );
		if ( $book_id <= 0 ) {
			return null;
		}
		$booking = MCP_Theme::booking( $book_id );
		if ( ! $booking ) {
			return null;
		}
		$date = self::gregorian_date( (string) ( $booking['date'] ?? '' ) );
		$end  = trim( (string) ( $booking['end_time'] ?? '' ) );
		if ( '' === $date || '' === $end || '00:00:00' === $end ) {
			return null;
		}
		if ( 5 === strlen( $end ) ) {
			$end .= ':00';
		}
		return self::parse_local( $date . ' ' . $end );
	}

	/** The theme stores booking dates as Jalali "1405-08-11" (Date::maybe_g2j); normalize to Gregorian. */
	private static function gregorian_date( string $date ): string {
		$date = trim( MCP_Jalali::en( $date ) );
		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $m ) ) {
			return '';
		}
		if ( (int) $m[1] < 1700 ) {
			return MCP_Jalali::to_gregorian( $date );
		}
		return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
	}

	private static function is_jalali_date( string $date ): bool {
		return (bool) preg_match( '/^(\d{4})-/', trim( MCP_Jalali::en( $date ) ), $m ) && (int) $m[1] < 1700;
	}

	/** Gregorian "Y-m-d" back to the booking row's storage format. */
	private static function storage_date( string $gregorian, string $original ): string {
		if ( ! self::is_jalali_date( $original ) ) {
			return $gregorian;
		}
		return str_replace( '/', '-', MCP_Jalali::from_gregorian( $gregorian ) );
	}

	private static function is_legacy( int $ends_at ): bool {
		$since = (int) get_option( self::SINCE_OPTION, 0 );
		if ( $since <= 0 ) {
			$since = time();
			update_option( self::SINCE_OPTION, $since, false );
		}
		return $ends_at < $since;
	}

	/** Real Unix timestamp for "now". */
	private static function now_ts(): int {
		return time();
	}

	/** Parse a local (site timezone) datetime string into a real Unix timestamp. */
	private static function parse_local( string $local_mysql ): ?int {
		$dt = date_create_immutable( $local_mysql, wp_timezone() );
		if ( ! $dt ) {
			return null;
		}
		return $dt->getTimestamp();
	}

	/** Format a real Unix timestamp in the site timezone. */
	private static function format_local( int $ts, string $format ): string {
		return wp_date( $format, $ts );
	}

	/** @return array<string,mixed> */
	private static function empty_payload(): array {
		return array(
			'enabled'             => false,
			'ends_at'             => null,
			'ends_at_ts'          => null,
			'server_now_ts'       => self::now_ts(),
			'seconds_remaining'   => null,
			'phase'               => 'ok',
			'can_extend'          => false,
			'extend_options'      => self::EXTEND_OPTS,
			'decision'            => '',
			'patient_notice_mins' => 0,
		);
	}

	/** @param array<string,mixed> $fields */
	private static function update_meta( int $session_id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = MCP_Theme::now();
		$wpdb->update( MCP_Install::table( 'session_meta' ), $fields, array( 'session_id' => $session_id ) );
	}

	private static function patch_booking_meta( int $book_id, string $new_end, ?string $new_date ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order_id = MCP_Theme::booking_order_id( $book_id );
		if ( ! $order_id ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$data = $order->get_meta( '_booking_data' );
		if ( ! is_array( $data ) ) {
			return;
		}
		$data['end_time'] = substr( $new_end, 0, 5 );
		if ( null !== $new_date ) {
			$data['date'] = $new_date;
		}
		$order->update_meta_data( '_booking_data', $data );
		$order->save();
	}
}
