<?php
/**
 * Nurse schedule: effective availability + save_days (R-SCH-2 / T-0.5 / T-2.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Schedule {

	/** Active order statuses that lock a slot on (R-ELG-4 / R-SCH-2). */
	public const ORDER_LOCK_STATUSES = array( 'assigned', 'in_progress', 'service_ended', 'needs_review' );

	/**
	 * Whether nurse is on for $day + $slot.
	 * day_slots override week; missing day_slots ⇒ week row presence.
	 *
	 * @param int    $nurse_user_id Nurse user id.
	 * @param string $day           Y-m-d Tehran.
	 * @param string $slot_id       Slot id.
	 */
	public static function effective( int $nurse_user_id, string $day, string $slot_id ): bool {
		global $wpdb;

		if ( $nurse_user_id <= 0 || '' === $day || '' === $slot_id ) {
			return false;
		}
		if ( MNS_Time::slot_closed( $slot_id ) ) {
			return false;
		}

		$day_table = MNS_DB::table( 'nurse_day_slots' );
		$row       = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT available FROM {$day_table} WHERE nurse_id = %d AND day = %s AND slot_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id,
				$day,
				$slot_id
			),
			ARRAY_A
		);
		if ( is_array( $row ) ) {
			return ( 1 === (int) $row['available'] );
		}

		$week_table = MNS_DB::table( 'nurse_week' );
		$weekday    = MNS_Time::weekday_tehran( $day );
		$found      = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$week_table} WHERE nurse_id = %d AND weekday = %d AND slot_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id,
				$weekday,
				$slot_id
			)
		);
		return ( null !== $found );
	}

	/**
	 * Base week pattern: 7 arrays Sat…Fri of slot ids.
	 *
	 * @return array<int,array<int,string>>
	 */
	public static function week_pattern( int $nurse_user_id ): array {
		global $wpdb;
		$out = array_fill( 0, 7, array() );
		if ( $nurse_user_id <= 0 ) {
			return $out;
		}
		$table = MNS_DB::table( 'nurse_week' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT weekday, slot_id FROM {$table} WHERE nurse_id = %d ORDER BY weekday, slot_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		$order = self::slot_id_order();
		foreach ( $rows as $row ) {
			$wd = (int) $row['weekday'];
			if ( $wd < 0 || $wd > 6 ) {
				continue;
			}
			$out[ $wd ][] = (string) $row['slot_id'];
		}
		foreach ( $out as $i => $list ) {
			$out[ $i ] = self::order_slots( $list, $order );
		}
		return $out;
	}

	/**
	 * Slot ids with an active assigned order for this nurse on $day (R-SCH-2).
	 *
	 * @return array<int,string>
	 */
	public static function order_slots_on_day( int $nurse_user_id, string $day ): array {
		global $wpdb;
		if ( $nurse_user_id <= 0 || '' === $day ) {
			return array();
		}
		$orders = MNS_DB::table( 'orders' );
		$in     = "'" . implode( "','", array_map( 'esc_sql', self::ORDER_LOCK_STATUSES ) ) . "'";
		$rows   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT slot_id FROM {$orders} WHERE nurse_user_id = %d AND service_date = %s AND status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id,
				$day
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return self::order_slots( array_map( 'strval', $rows ), self::slot_id_order() );
	}

	/**
	 * Effective "on" slot list for a day (base week ± day_slots overrides).
	 *
	 * @return array<int,string>
	 */
	public static function on_slots_for_day( int $nurse_user_id, string $day ): array {
		$slots = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slots ) ) {
			$slots = MNS_Settings::default_slots();
		}
		$on = array();
		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) || empty( $slot['id'] ) ) {
				continue;
			}
			$id = (string) $slot['id'];
			if ( ! empty( $slot['closed'] ) ) {
				continue;
			}
			if ( self::effective( $nurse_user_id, $day, $id ) ) {
				$on[] = $id;
			}
		}
		return self::order_slots( $on, self::slot_id_order() );
	}

	/**
	 * Save changed days (R-SCH-2 all-or-nothing + nurse row lock).
	 *
	 * Each day: {iso:Y-m-d, on:[slot_ids]}. Equal to week base ⇒ delete day_slots rows.
	 *
	 * @param int   $nurse_user_id Nurse.
	 * @param array $days          Changed days only.
	 * @return array{ok:true}
	 * @throws MNS_Error ERR_VALIDATION | ERR_SLOT_HAS_ORDER | ERR_SERVER
	 */
	public static function save_days( int $nurse_user_id, array $days ) {
		if ( $nurse_user_id <= 0 ) {
			throw MNS_Error::validation( 'پرستار نامعتبر است.', 'nurse_id' );
		}
		if ( empty( $days ) ) {
			return array( 'ok' => true );
		}

		$horizon = (int) MNS_Settings::get( 'schedule_horizon' );
		if ( $horizon < 1 ) {
			$horizon = 90;
		}
		$today = MNS_Time::today_tehran();
		$tz    = new DateTimeZone( MNS_Time::TZ );
		$today_dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $today, $tz );
		$max_dt   = $today_dt ? $today_dt->modify( '+' . $horizon . ' days' ) : null;

		$known_slots = array();
		$slot_defs   = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slot_defs ) ) {
			$slot_defs = MNS_Settings::default_slots();
		}
		foreach ( $slot_defs as $slot ) {
			if ( is_array( $slot ) && ! empty( $slot['id'] ) ) {
				$known_slots[ (string) $slot['id'] ] = ! empty( $slot['closed'] );
			}
		}

		$normalized = array();
		foreach ( $days as $day ) {
			if ( ! is_array( $day ) ) {
				throw MNS_Error::validation( 'فرمت روز نامعتبر است.', 'days' );
			}
			$iso = isset( $day['iso'] ) ? sanitize_text_field( (string) $day['iso'] ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ) {
				throw MNS_Error::validation( 'تاریخ نامعتبر است.', 'iso' );
			}
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $iso, $tz );
			if ( ! $dt || $dt->format( 'Y-m-d' ) !== $iso ) {
				throw MNS_Error::validation( 'تاریخ نامعتبر است.', 'iso' );
			}
			if ( $today_dt && $dt < $today_dt ) {
				throw MNS_Error::validation( 'روز گذشته قابل ویرایش نیست.', 'iso' );
			}
			if ( $max_dt && $dt > $max_dt ) {
				throw MNS_Error::validation( 'خارج از افق برنامه است.', 'iso' );
			}

			$on_raw = isset( $day['on'] ) && is_array( $day['on'] ) ? $day['on'] : array();
			$on     = array();
			foreach ( $on_raw as $sid ) {
				$sid = sanitize_text_field( (string) $sid );
				if ( '' === $sid || ! isset( $known_slots[ $sid ] ) ) {
					throw MNS_Error::validation( 'بازه نامعتبر است.', 'on' );
				}
				if ( $known_slots[ $sid ] ) {
					throw MNS_Error::validation( 'این بازه بسته است.', 'on' );
				}
				$on[] = $sid;
			}
			$on = self::order_slots( array_values( array_unique( $on ) ), self::slot_id_order() );
			$normalized[ $iso ] = $on;
		}

		try {
			MNS_DB::tx(
				static function () use ( $nurse_user_id, $normalized ) {
					$locked = MNS_DB::lock_row( MNS_DB::table( 'nurses' ), 'user_id', $nurse_user_id );
					if ( ! is_array( $locked ) ) {
						throw MNS_Error::not_found( 'پرستار یافت نشد.' );
					}

					// Validate R-SCH-2 for ALL days before any write (all-or-nothing).
					foreach ( $normalized as $iso => $on ) {
						$locked_slots = self::order_slots_on_day( $nurse_user_id, $iso );
						foreach ( $locked_slots as $slot_id ) {
							if ( ! in_array( $slot_id, $on, true ) ) {
								throw MNS_Error::conflict(
									'ERR_SLOT_HAS_ORDER',
									'این بازه سفارش تخصیص‌یافته دارد و نمی‌تواند خاموش شود.',
									array(
										'iso'  => $iso,
										'slot' => $slot_id,
									)
								);
							}
						}
					}

					$week = self::week_pattern( $nurse_user_id );
					$day_table = MNS_DB::table( 'nurse_day_slots' );
					global $wpdb;

					foreach ( $normalized as $iso => $on ) {
						$weekday = MNS_Time::weekday_tehran( $iso );
						$base    = isset( $week[ $weekday ] ) ? $week[ $weekday ] : array();
						$base    = self::order_slots( $base, self::slot_id_order() );

						// Equal to week base ⇒ wipe day_slots for that day (AC-M2-2).
						if ( self::same_slot_lists( $on, $base ) ) {
							$del = $wpdb->delete(
								$day_table,
								array(
									'nurse_id' => $nurse_user_id,
									'day'      => $iso,
								),
								array( '%d', '%s' )
							);
							if ( false === $del ) {
								throw MNS_Error::server( 'ذخیرهٔ برنامه ناموفق بود.' );
							}
							continue;
						}

						// Remove existing overrides for the day, then write deviations for every known open slot.
						$del = $wpdb->delete(
							$day_table,
							array(
								'nurse_id' => $nurse_user_id,
								'day'      => $iso,
							),
							array( '%d', '%s' )
						);
						if ( false === $del ) {
							throw MNS_Error::server( 'ذخیرهٔ برنامه ناموفق بود.' );
						}

						$all_open = array();
						foreach ( self::slot_id_order() as $sid ) {
							if ( MNS_Time::slot_closed( $sid ) ) {
								continue;
							}
							$all_open[] = $sid;
						}

						foreach ( $all_open as $sid ) {
							$want = in_array( $sid, $on, true );
							$base_on = in_array( $sid, $base, true );
							if ( $want === $base_on ) {
								continue; // no deviation row needed
							}
							$ins = $wpdb->insert(
								$day_table,
								array(
									'nurse_id'  => $nurse_user_id,
									'day'       => $iso,
									'slot_id'   => $sid,
									'available' => $want ? 1 : 0,
								),
								array( '%d', '%s', '%s', '%d' )
							);
							if ( false === $ins ) {
								throw MNS_Error::server( 'ذخیرهٔ برنامه ناموفق بود.' );
							}
						}
					}

					return true;
				}
			);
		} catch ( MNS_Error $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'schedule_save_days: ' . $e->getMessage(), array( 'nurse' => $nurse_user_id ) );
			throw MNS_Error::server();
		}

		return array( 'ok' => true );
	}

	/**
	 * Full availability payload for API-NRS-5 / page config (T-2.2).
	 *
	 * @return array<string,mixed>
	 */
	public static function availability_bundle( int $nurse_user_id ): array {
		$horizon = (int) MNS_Settings::get( 'schedule_horizon' );
		if ( $horizon < 1 ) {
			$horizon = 90;
		}
		$slot_defs = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slot_defs ) ) {
			$slot_defs = MNS_Settings::default_slots();
		}
		$slots = array();
		foreach ( $slot_defs as $slot ) {
			if ( ! is_array( $slot ) || empty( $slot['id'] ) ) {
				continue;
			}
			$start = isset( $slot['start_hour'] ) ? (int) $slot['start_hour'] : 0;
			$end   = isset( $slot['end_hour'] ) ? (int) $slot['end_hour'] : ( $start + 4 );
			$slots[] = array(
				'id'     => (string) $slot['id'],
				'label'  => sprintf( '%d:00 – %d:00', $start, $end ),
				'name'   => (string) ( $slot['name'] ?? $slot['id'] ),
				'closed' => ! empty( $slot['closed'] ),
			);
		}

		$week = self::week_pattern( $nurse_user_id );
		$tz   = new DateTimeZone( MNS_Time::TZ );
		$today = new DateTimeImmutable( 'today', $tz );
		$jt    = class_exists( 'MNS_Format', true ) ? MNS_Format::g2j( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), (int) $today->format( 'j' ) ) : array( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), (int) $today->format( 'j' ) );
		$start = $today->modify( '-' . ( (int) $jt[2] - 1 ) . ' days' );
		$end   = $today->modify( '+' . $horizon . ' days' );

		$days   = array();
		$cursor = $start;
		while ( $cursor <= $end ) {
			$iso = $cursor->format( 'Y-m-d' );
			$dow = MNS_Time::weekday_tehran( $iso );
			$j   = class_exists( 'MNS_Format', true )
				? MNS_Format::g2j( (int) $cursor->format( 'Y' ), (int) $cursor->format( 'n' ), (int) $cursor->format( 'j' ) )
				: array( (int) $cursor->format( 'Y' ), (int) $cursor->format( 'n' ), (int) $cursor->format( 'j' ) );
			$base = isset( $week[ $dow ] ) ? $week[ $dow ] : array();
			$days[] = array(
				'iso'    => $iso,
				'y'      => (int) $j[0],
				'm'      => (int) $j[1],
				'd'      => (int) $j[2],
				'dow'    => $dow,
				'past'   => $cursor < $today,
				'today'  => $iso === $today->format( 'Y-m-d' ),
				'base'   => array_values( $base ),
				'on'     => self::on_slots_for_day( $nurse_user_id, $iso ),
				'orders' => self::order_slots_on_day( $nurse_user_id, $iso ),
			);
			$cursor = $cursor->modify( '+1 day' );
		}

		$row = MNS_Nurse_Access::nurse_row( $nurse_user_id );
		$accepting = $row ? ! empty( $row['accepting'] ) : false;

		return array(
			'status'        => MNS_Nurse_Status::compute( $nurse_user_id ),
			'accepting'     => $accepting,
			'slots'         => $slots,
			'week'          => $week,
			'days'          => $days,
			'horizon_days'  => $horizon,
			'regions'       => MNS_Nurse_Regions::list_for( $nurse_user_id ),
			'region_orders' => MNS_Nurse_Regions::region_orders_map( $nurse_user_id ),
			'districts'     => MNS_Locations::districts_map(),
		);
	}

	/**
	 * @return array<int,string>
	 */
	private static function slot_id_order(): array {
		$slots = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slots ) ) {
			$slots = MNS_Settings::default_slots();
		}
		$ids = array();
		foreach ( $slots as $slot ) {
			if ( is_array( $slot ) && ! empty( $slot['id'] ) ) {
				$ids[] = (string) $slot['id'];
			}
		}
		return $ids;
	}

	/**
	 * @param array<int,string> $list  Slot ids.
	 * @param array<int,string> $order Canonical order.
	 * @return array<int,string>
	 */
	private static function order_slots( array $list, array $order ): array {
		$out = array();
		foreach ( $order as $id ) {
			if ( in_array( $id, $list, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * @param array<int,string> $a List a.
	 * @param array<int,string> $b List b.
	 */
	private static function same_slot_lists( array $a, array $b ): bool {
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( $a as $id ) {
			if ( ! in_array( $id, $b, true ) ) {
				return false;
			}
		}
		return true;
	}
}
