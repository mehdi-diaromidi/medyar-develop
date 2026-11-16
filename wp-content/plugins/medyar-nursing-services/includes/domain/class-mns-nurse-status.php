<?php
/**
 * Live nurse status for availability UI (API-NRS-4 / T-2.1).
 *
 * Codes: available | off_hours | away | no_plan | paused
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Status {

	/**
	 * @param int $nurse_user_id Nurse WP user id.
	 * @return array{tone:string,text:string,sub:string,code:string}
	 */
	public static function compute( int $nurse_user_id ): array {
		$row = null;
		if ( class_exists( 'MNS_Nurse_Access', false ) || class_exists( 'MNS_Nurse_Access', true ) ) {
			$row = MNS_Nurse_Access::nurse_row( $nurse_user_id );
		}
		$accepting = $row ? ! empty( $row['accepting'] ) : false;

		if ( ! $accepting ) {
			return array(
				'tone' => 'warn',
				'text' => 'پذیرش سفارش خاموش است',
				'sub'  => 'تا روشن نکنید سفارش جدیدی به شما داده نمی‌شود.',
				'code' => 'paused',
			);
		}

		$has_region = MNS_Nurse_Regions::has_any( $nurse_user_id );
		$week       = MNS_Nurse_Schedule::week_pattern( $nurse_user_id );
		$has_week   = false;
		foreach ( $week as $slots ) {
			if ( ! empty( $slots ) ) {
				$has_week = true;
				break;
			}
		}
		// Also count day overrides as having a plan.
		$has_day = self::has_any_day_slot( $nurse_user_id );
		if ( ! $has_region || ( ! $has_week && ! $has_day ) ) {
			return array(
				'tone' => 'warn',
				'text' => 'برنامه یا منطقه ندارید',
				'sub'  => 'برای دریافت سفارش، برنامه و مناطق فعالیت را کامل کنید.',
				'code' => 'no_plan',
			);
		}

		$today = MNS_Time::today_tehran();
		$on    = MNS_Nurse_Schedule::on_slots_for_day( $nurse_user_id, $today );
		if ( empty( $on ) ) {
			return array(
				'tone' => 'warn',
				'text' => 'امروز در دسترس نیستید',
				'sub'  => 'هیچ بازه‌ای برای امروز روشن نیست.',
				'code' => 'away',
			);
		}

		$now_ts = time();
		$in_slot = false;
		foreach ( $on as $slot_id ) {
			$start = MNS_Time::slot_start_ts( $today, $slot_id );
			$slots = MNS_Settings::get( 'slots' );
			if ( ! is_array( $slots ) ) {
				$slots = MNS_Settings::default_slots();
			}
			$end_hour = $start;
			foreach ( $slots as $slot ) {
				if ( is_array( $slot ) && isset( $slot['id'] ) && (string) $slot['id'] === $slot_id ) {
					$end_h = isset( $slot['end_hour'] ) ? (int) $slot['end_hour'] : 0;
					$tz    = new DateTimeZone( MNS_Time::TZ );
					$end_dt = DateTimeImmutable::createFromFormat(
						'Y-m-d H:i:s',
						$today . ' ' . sprintf( '%02d:00:00', min( 23, max( 0, $end_h === 24 ? 23 : $end_h ) ) ),
						$tz
					);
					if ( $end_dt && 24 === $end_h ) {
						$end_dt = $end_dt->modify( '+1 hour' );
					}
					$end_hour = $end_dt ? $end_dt->getTimestamp() : ( $start + 4 * HOUR_IN_SECONDS );
					break;
				}
			}
			if ( $now_ts >= $start && $now_ts < $end_hour ) {
				$in_slot = true;
				break;
			}
		}

		if ( ! $in_slot ) {
			return array(
				'tone' => 'warn',
				'text' => 'خارج از بازهٔ کاری',
				'sub'  => 'الان در بازهٔ روشن شما نیست؛ بازه‌های بعدی امروز فعال می‌مانند.',
				'code' => 'off_hours',
			);
		}

		return array(
			'tone' => 'ok',
			'text' => 'آمادهٔ پذیرش',
			'sub'  => 'در بازهٔ روشن امروز هستید و مناطق ثبت شده‌اند.',
			'code' => 'available',
		);
	}

	private static function has_any_day_slot( int $nurse_user_id ): bool {
		global $wpdb;
		$table = MNS_DB::table( 'nurse_day_slots' );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE nurse_id = %d AND available = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id
			)
		);
		return ( null !== $found );
	}
}
