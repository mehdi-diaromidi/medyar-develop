<?php
/**
 * A-13 availability check (R-A13-1..3 / T-0.5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Availability {

	/**
	 * Check availability for a booking scope.
	 *
	 * @param array<string,mixed> $scope Same keys as MNS_Eligibility::nurses_for.
	 * @return array{state:string,message:string,alt?:array<int,array<string,string>>}
	 */
	public static function check( array $scope ): array {
		$service_ids = MNS_Eligibility::resolve_service_ids( $scope );
		foreach ( $service_ids as $sid ) {
			$svc = MNS_Services::get( $sid );
			if ( $svc && ! empty( $svc['manual_assign'] ) ) {
				return array(
					'state'   => 'manual',
					'message' => 'برای این خدمت همکاران ما برای هماهنگی با شما تماس می‌گیرند.',
				);
			}
		}

		$mode = (string) MNS_Settings::get( 'assignment_mode' );

		$eligible = MNS_Eligibility::nurses_for( $scope );
		$count    = count( $eligible );

		// R-A13-2: in manual mode, subtract unassigned submitted matching same scope.
		if ( 'manual' === $mode && $count > 0 ) {
			$free = $count - MNS_Eligibility::unassigned_matching_count( $scope );
			if ( $free <= 0 ) {
				$count = 0;
			}
		}

		if ( $count > 0 ) {
			return array(
				'state'   => 'green',
				'message' => 'پرستار برای این زمان موجود است.',
			);
		}

		$alts = self::alternatives( $scope );
		if ( ! empty( $alts ) ) {
			return array(
				'state'   => 'yellow',
				'message' => 'در این بازه پرستار نیست؛ زمان‌های نزدیک را ببینید.',
				'alt'     => $alts,
			);
		}

		// Red: no nurse for region+gender+service across horizon.
		$horizon_scope = $scope;
		unset( $horizon_scope['service_date'], $horizon_scope['slot_id'], $horizon_scope['require_capacity'] );
		if ( self::any_in_horizon( $scope ) ) {
			// Had yellow path empty but somehow horizon has matches without near alts — still yellow-ish; treat as yellow empty alt already handled.
			return array(
				'state'   => 'yellow',
				'message' => 'در این بازه پرستار نیست؛ زمان دیگری را انتخاب کنید.',
				'alt'     => array(),
			);
		}

		return array(
			'state'   => 'red',
			'message' => 'برای این منطقه یا جنسیت پرستار نداریم. با پشتیبانی تماس بگیرید.',
		);
	}

	/**
	 * Up to 3 nearby alternative date/slot chips.
	 *
	 * @param array<string,mixed> $scope Scope.
	 * @return array<int,array{date:string,slot:string,label:string}>
	 */
	private static function alternatives( array $scope ): array {
		$date    = isset( $scope['service_date'] ) ? (string) $scope['service_date'] : MNS_Time::today_tehran();
		$slot_id = isset( $scope['slot_id'] ) ? (string) $scope['slot_id'] : '';
		$horizon = (int) MNS_Settings::get( 'order_horizon' );
		if ( $horizon < 1 ) {
			$horizon = 30;
		}

		$slots = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slots ) ) {
			$slots = MNS_Settings::default_slots();
		}
		$open_slots = array();
		foreach ( $slots as $s ) {
			if ( is_array( $s ) && empty( $s['closed'] ) && ! empty( $s['id'] ) ) {
				$open_slots[] = (string) $s['id'];
			}
		}

		$alts  = array();
		$tz    = new DateTimeZone( MNS_Time::TZ );
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $tz );
		if ( ! $start ) {
			$start = new DateTimeImmutable( 'now', $tz );
		}

		for ( $d = 0; $d <= $horizon && count( $alts ) < 3; $d++ ) {
			$day = $start->modify( '+' . $d . ' day' )->format( 'Y-m-d' );
			foreach ( $open_slots as $sid ) {
				if ( 0 === $d && $sid === $slot_id ) {
					continue;
				}
				$try           = $scope;
				$try['service_date'] = $day;
				$try['slot_id']      = $sid;
				$list          = MNS_Eligibility::nurses_for( $try );
				$mode          = (string) MNS_Settings::get( 'assignment_mode' );
				$ok            = count( $list ) > 0;
				if ( $ok && 'manual' === $mode ) {
					$ok = ( count( $list ) - MNS_Eligibility::unassigned_matching_count( $try ) ) > 0;
				}
				if ( $ok ) {
					$alts[] = array(
						'date'  => $day,
						'slot'  => $sid,
						'label' => $day . ' · ' . $sid,
					);
					if ( count( $alts ) >= 3 ) {
						break 2;
					}
				}
			}
		}

		return $alts;
	}

	/**
	 * Whether any open slot in horizon has an eligible nurse (ignore capacity for red detection).
	 *
	 * @param array<string,mixed> $scope Scope.
	 */
	private static function any_in_horizon( array $scope ): bool {
		$horizon = (int) MNS_Settings::get( 'order_horizon' );
		if ( $horizon < 1 ) {
			$horizon = 30;
		}
		$slots = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slots ) ) {
			$slots = MNS_Settings::default_slots();
		}
		$tz    = new DateTimeZone( MNS_Time::TZ );
		$start = new DateTimeImmutable( 'now', $tz );

		for ( $d = 0; $d <= $horizon; $d++ ) {
			$day = $start->modify( '+' . $d . ' day' )->format( 'Y-m-d' );
			foreach ( $slots as $s ) {
				if ( ! is_array( $s ) || ! empty( $s['closed'] ) || empty( $s['id'] ) ) {
					continue;
				}
				$try = $scope;
				$try['service_date']     = $day;
				$try['slot_id']          = (string) $s['id'];
				$try['require_capacity'] = false;
				if ( ! empty( MNS_Eligibility::nurses_for( $try ) ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
