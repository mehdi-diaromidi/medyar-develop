<?php
/**
 * Time helpers: UTC storage, Asia/Tehran service dates (T-0.5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Time {

	public const TZ = 'Asia/Tehran';

	/**
	 * Current UTC datetime string for DB.
	 */
	public static function now_utc(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Today's service date in Asia/Tehran (Y-m-d).
	 */
	public static function today_tehran(): string {
		$tz = new DateTimeZone( self::TZ );
		$dt = new DateTimeImmutable( 'now', $tz );
		return $dt->format( 'Y-m-d' );
	}

	/**
	 * Unix timestamp for slot start on a service date (Tehran wall clock).
	 *
	 * @param string $date    Y-m-d (Tehran calendar day).
	 * @param string $slot_id Slot id from S-SLOTS.
	 */
	public static function slot_start_ts( string $date, string $slot_id ): int {
		$slots = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slots ) ) {
			$slots = MNS_Settings::default_slots();
		}
		$start_hour = 0;
		foreach ( $slots as $slot ) {
			if ( is_array( $slot ) && isset( $slot['id'] ) && (string) $slot['id'] === $slot_id ) {
				$start_hour = isset( $slot['start_hour'] ) ? (int) $slot['start_hour'] : 0;
				break;
			}
		}
		$tz = new DateTimeZone( self::TZ );
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' ' . sprintf( '%02d:00:00', $start_hour ), $tz );
		if ( ! $dt ) {
			return 0;
		}
		return $dt->getTimestamp();
	}

	/**
	 * Weekday index for a Y-m-d in Tehran: 0=Saturday … 6=Friday.
	 */
	public static function weekday_tehran( string $date ): int {
		$tz = new DateTimeZone( self::TZ );
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $tz );
		if ( ! $dt ) {
			return 0;
		}
		// PHP: 0=Sun … 6=Sat. Map to 0=Sat … 6=Fri.
		$php = (int) $dt->format( 'w' );
		return ( $php + 1 ) % 7;
	}

	/**
	 * Whether slot is closed per settings.
	 */
	public static function slot_closed( string $slot_id ): bool {
		$slots = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slots ) ) {
			return ( '0-4' === $slot_id );
		}
		foreach ( $slots as $slot ) {
			if ( is_array( $slot ) && isset( $slot['id'] ) && (string) $slot['id'] === $slot_id ) {
				return ! empty( $slot['closed'] );
			}
		}
		return false;
	}
}
