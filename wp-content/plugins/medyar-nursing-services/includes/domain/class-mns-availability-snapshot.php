<?php
/**
 * Compact patient availability snapshot (RUN-G / T-G.6). Booleans only — no nurse PII.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Availability_Snapshot {

	public const TRANSIENT = 'mns_avail_snap_v1';
	public const TTL       = 60;

	public static function boot(): void {
		add_action( 'mns_order_transitioned', array( __CLASS__, 'invalidate' ), 20, 0 );
		add_action( 'mns_nurse_schedule_saved', array( __CLASS__, 'invalidate' ), 20, 0 );
		add_action( 'mns_nurse_regions_saved', array( __CLASS__, 'invalidate' ), 20, 0 );
	}

	public static function invalidate(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = self::build();
		set_transient( self::TRANSIENT, $data, self::TTL );
		return $data;
	}

	/**
	 * regions[key][gender][dayOffset] = bitmask of open slots with ≥1 eligible nurse.
	 * svc_restricted: service ids that need_explicit_nurse.
	 *
	 * @return array<string,mixed>
	 */
	public static function build(): array {
		$slots = MNS_Settings::get( 'slots' );
		if ( ! is_array( $slots ) ) {
			$slots = MNS_Settings::default_slots();
		}
		$open = array();
		foreach ( $slots as $s ) {
			if ( is_array( $s ) && empty( $s['closed'] ) && ! empty( $s['id'] ) ) {
				$open[] = (string) $s['id'];
			}
		}
		$horizon = (int) MNS_Settings::get( 'order_horizon' );
		if ( $horizon < 1 ) {
			$horizon = 14;
		}
		$horizon = min( 21, $horizon );

		$restricted = array();
		foreach ( MNS_Services::list_active() as $svc ) {
			if ( ! empty( $svc['needs_explicit_nurse'] ) ) {
				$restricted[] = (int) $svc['id'];
			}
		}

		global $wpdb;
		$reg_rows = $wpdb->get_results(
			'SELECT DISTINCT province_code, city_id, district_no FROM ' . MNS_DB::table( 'nurse_regions' ) . ' LIMIT 200', // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! is_array( $reg_rows ) ) {
			$reg_rows = array();
		}

		$today = MNS_Time::today_tehran();
		$tz    = new DateTimeZone( MNS_Time::TZ );
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d', $today, $tz );
		if ( ! $start ) {
			$start = new DateTimeImmutable( 'now', $tz );
		}

		// Use first non-manual active service as coverage probe for "any nurse free".
		$probe_id = 0;
		foreach ( MNS_Services::list_active() as $svc ) {
			if ( empty( $svc['manual_assign'] ) && empty( $svc['needs_explicit_nurse'] ) ) {
				$probe_id = (int) $svc['id'];
				break;
			}
		}

		$regions = array();
		foreach ( $reg_rows as $reg ) {
			$pkey = (string) $reg['province_code'] . ':' . (int) $reg['city_id'] . ':' . (int) $reg['district_no'];
			foreach ( array( 'female', 'male' ) as $gender ) {
				$days = array();
				for ( $d = 0; $d < $horizon; $d++ ) {
					$day  = $start->modify( '+' . $d . ' day' )->format( 'Y-m-d' );
					$mask = 0;
					foreach ( $open as $i => $slot_id ) {
						if ( $i > 30 ) {
							break;
						}
						if ( $probe_id <= 0 ) {
							continue;
						}
						$list = MNS_Eligibility::nurses_for(
							array(
								'service_id'    => $probe_id,
								'province_code' => (string) $reg['province_code'],
								'city_id'       => (int) $reg['city_id'],
								'district_no'   => (int) $reg['district_no'],
								'service_date'  => $day,
								'slot_id'       => $slot_id,
								'gender_pref'   => $gender,
							)
						);
						if ( ! empty( $list ) ) {
							$mask |= ( 1 << $i );
						}
					}
					if ( $mask > 0 ) {
						$days[ (string) $d ] = $mask;
					}
				}
				if ( ! empty( $days ) ) {
					if ( ! isset( $regions[ $pkey ] ) ) {
						$regions[ $pkey ] = array();
					}
					$regions[ $pkey ][ $gender ] = $days;
				}
			}
		}

		return array(
			'generated'      => time(),
			'slots'          => $open,
			'horizon'        => $horizon,
			'regions'        => $regions,
			'svc_restricted' => $restricted,
		);
	}
}
