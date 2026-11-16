<?php
/**
 * Effective nurse availability for a day/slot (R-ELG-3 / T-0.5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Schedule {

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
}
