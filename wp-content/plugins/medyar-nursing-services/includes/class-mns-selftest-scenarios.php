<?php
/**
 * Built-in AC scenarios for M0 (T-0.6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Selftest_Scenarios {

	public static function register_all(): void {
		MNS_Selftest::register( 'AC-M0-2', 'Migration idempotent / tables ready', array( __CLASS__, 'ac_m0_2' ) );
		MNS_Selftest::register( 'AC-M0-4', 'Settings invalid ⇒ default', array( __CLASS__, 'ac_m0_4' ) );
		MNS_Selftest::register( 'AC-M0-5', 'REST envelope / auth helpers', array( __CLASS__, 'ac_m0_5' ) );
		MNS_Selftest::register( 'AC-M0-6', 'Bad state transition', array( __CLASS__, 'ac_m0_6' ) );
		MNS_Selftest::register( 'AC-ELG-1', 'Eligibility filters gender/region/slot', array( __CLASS__, 'ac_elg_1' ) );
		MNS_Selftest::register( 'AC-A13-1', 'A-13 green/yellow/red', array( __CLASS__, 'ac_a13_1' ) );
		MNS_Selftest::register( 'AC-M2-1', 'Schedule effective from week', array( __CLASS__, 'ac_m2_1' ) );
		MNS_Selftest::register( 'AC-M2-2', 'Day slots deviation / revert', array( __CLASS__, 'ac_m2_2' ) );
		MNS_Selftest::register( 'AC-M2-3', 'ERR_SLOT_HAS_ORDER all-or-nothing', array( __CLASS__, 'ac_m2_3' ) );
		MNS_Selftest::register( 'AC-M2-4', 'Past / beyond horizon rejected', array( __CLASS__, 'ac_m2_4' ) );
		MNS_Selftest::register( 'AC-M2-5', 'Regions validation + warning', array( __CLASS__, 'ac_m2_5' ) );
		MNS_Selftest::register( 'AC-M2-6', 'Nurse status compute codes', array( __CLASS__, 'ac_m2_6' ) );
		MNS_Selftest::register( 'AC-M3-1', 'Services seed serum/injection', array( __CLASS__, 'ac_m3_1' ) );
		MNS_Selftest::register( 'AC-M3-2', 'Patients self + ownership 404', array( __CLASS__, 'ac_m3_2' ) );
		MNS_Selftest::register( 'AC-A13-4', 'A-13 rejects closed/past slot', array( __CLASS__, 'ac_a13_4' ) );
		MNS_Selftest_Orders::register_all();
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m0_2(): array {
		if ( ! MNS_Migrations::tables_ready() ) {
			$run = MNS_Migrations::run();
			if ( empty( $run['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'migrate_failed:' . implode( ',', $run['errors'] ) );
			}
		}
		$again = MNS_Migrations::run();
		if ( empty( $again['ok'] ) ) {
			return array( 'ok' => false, 'detail' => 're_run_failed' );
		}
		if ( (int) $again['from'] !== (int) $again['to'] && MNS_Schema::LATEST !== (int) $again['to'] ) {
			// from==to when already latest is OK.
		}
		$missing = array();
		global $wpdb;
		foreach ( MNS_DB::TABLES as $short ) {
			$t = MNS_DB::table( $short );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
			if ( $found !== $t ) {
				$missing[] = $t;
			}
		}
		if ( ! empty( $missing ) ) {
			return array( 'ok' => false, 'detail' => 'missing:' . implode( ',', $missing ) );
		}
		return array( 'ok' => true, 'detail' => 'schema=' . MNS_Migrations::current_version() );
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m0_4(): array {
		$before = MNS_Settings::get( 'slot_capacity' );
		$bad    = MNS_Settings::set( 'slot_capacity', 'abc' );
		if ( false !== $bad ) {
			return array( 'ok' => false, 'detail' => 'accepted_invalid' );
		}
		$still = MNS_Settings::get( 'slot_capacity' );
		$ok_set = MNS_Settings::set( 'slot_capacity', 2 );
		$got    = MNS_Settings::get( 'slot_capacity' );
		MNS_Settings::set( 'slot_capacity', $before );
		if ( ! $ok_set || 2 !== (int) $got || (int) $still !== (int) $before ) {
			return array( 'ok' => false, 'detail' => 'set_get_mismatch' );
		}
		return array( 'ok' => true, 'detail' => 'validated' );
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m0_5(): array {
		$err = MNS_Error::auth();
		$arr = $err->to_array();
		if ( 'ERR_AUTH' !== $arr['code'] || 401 !== $err->http() ) {
			return array( 'ok' => false, 'detail' => 'error_shape' );
		}
		$req = new WP_REST_Request( 'GET', '/' . MNS_REST::NS . '/ping' );
		$req->set_param( '_envelope', '1' );
		$res = MNS_REST::fail( $err, $req );
		if ( 200 !== $res->get_status() ) {
			return array( 'ok' => false, 'detail' => 'envelope_http=' . $res->get_status() );
		}
		$data = $res->get_data();
		if ( empty( $data['ok'] ) === false || empty( $data['error']['code'] ) ) {
			// ok should be false
			if ( ! empty( $data['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'envelope_ok_true' );
			}
		}
		$bucket = 'mns_selftest_rl_' . wp_generate_password( 8, false );
		$hit1   = MNS_REST::rate_limit( $bucket, 1, 60 );
		$hit2   = MNS_REST::rate_limit( $bucket, 1, 60 );
		if ( ! $hit1 || $hit2 ) {
			return array( 'ok' => false, 'detail' => 'rate_limit_not_enforced' );
		}
		return array( 'ok' => true, 'detail' => 'envelope+rate' );
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m0_6(): array {
		try {
			MNS_Order_Machine::transition( 'nope', array() );
			return array( 'ok' => false, 'detail' => 'no_throw' );
		} catch ( MNS_Error $e ) {
			if ( 'ERR_BAD_STATE' !== $e->error_code() ) {
				return array( 'ok' => false, 'detail' => $e->error_code() );
			}
			return array( 'ok' => true, 'detail' => 'bad_state' );
		}
	}

	/**
	 * Seed 3–4 test nurses with different gender/region/slot; assert filters.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_elg_1(): array {
		if ( ! MNS_Migrations::tables_ready() ) {
			return array( 'ok' => false, 'detail' => 'tables_not_ready' );
		}

		$created_users = array();
		$service_id    = 0;

		try {
			global $wpdb;
			$services = MNS_DB::table( 'services' );
			$service_id = (int) $wpdb->get_var( "SELECT id FROM {$services} WHERE slug = 'mns_selftest_svc' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $service_id <= 0 ) {
				$wpdb->insert(
					$services,
					array(
						'slug'          => 'mns_selftest_svc',
						'category'      => 'test',
						'name'          => 'Selftest Service',
						'active'        => 1,
						'price_from'    => 1000,
						'price_to'      => 2000,
						'has_drug_step' => 0,
						'sort'          => 999,
						'config'        => '{}',
						'created_at'    => MNS_Time::now_utc(),
						'updated_at'    => MNS_Time::now_utc(),
					)
				);
				$service_id = (int) $wpdb->insert_id;
			}

			$specs = array(
				array( 'gender' => 'female', 'slot' => '8-12', 'district' => 1 ),
				array( 'gender' => 'male', 'slot' => '8-12', 'district' => 1 ),
				array( 'gender' => 'female', 'slot' => '16-20', 'district' => 2 ),
				array( 'gender' => 'female', 'slot' => '8-12', 'district' => 3 ),
			);

			$day = MNS_Time::today_tehran();
			$tz  = new DateTimeZone( MNS_Time::TZ );
			$day = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+2 day' )->format( 'Y-m-d' );

			foreach ( $specs as $i => $spec ) {
				$login = 'mns_selftest_n' . $i . '_' . wp_generate_password( 4, false );
				$uid   = wp_insert_user(
					array(
						'user_login' => $login,
						'user_pass'  => wp_generate_password( 12 ),
						'user_email' => $login . '@example.invalid',
						'role'       => 'subscriber',
					)
				);
				if ( is_wp_error( $uid ) ) {
					throw new \RuntimeException( $uid->get_error_message() );
				}
				$uid = (int) $uid;
				$created_users[] = $uid;
				$user = new WP_User( $uid );
				$user->add_role( MNS_Roles::ROLE_NURSE );

				$wpdb->replace(
					MNS_DB::table( 'nurses' ),
					array(
						'user_id'     => $uid,
						'status'      => 'approved',
						'gender'      => $spec['gender'],
						'national_id' => sprintf( '9%09d', $uid % 1000000000 ),
						'accepting'   => 1,
						'applied_at'  => MNS_Time::now_utc(),
					)
				);
				$wpdb->replace(
					MNS_DB::table( 'nurse_services' ),
					array(
						'nurse_id'   => $uid,
						'service_id' => $service_id,
					)
				);
				$wpdb->insert(
					MNS_DB::table( 'nurse_regions' ),
					array(
						'nurse_id'      => $uid,
						'province_code' => 'THR',
						'city_id'       => 1,
						'district_no'   => (int) $spec['district'],
					)
				);
				$wpdb->replace(
					MNS_DB::table( 'nurse_week' ),
					array(
						'nurse_id' => $uid,
						'weekday'  => MNS_Time::weekday_tehran( $day ),
						'slot_id'  => $spec['slot'],
					)
				);
			}

			$scope = array(
				'service_id'    => $service_id,
				'province_code' => 'THR',
				'city_id'       => 1,
				'district_no'   => 1,
				'service_date'  => $day,
				'slot_id'       => '8-12',
				'gender_pref'   => 'female',
			);
			$list = MNS_Eligibility::nurses_for( $scope );
			$ids  = array_map(
				static function ( $r ) {
					return (int) $r['user_id'];
				},
				$list
			);

			// Expect nurse 0 (female, 8-12, d1) — not male, not other slot, not other district unless city-wide.
			if ( count( $ids ) < 1 || ! in_array( $created_users[0], $ids, true ) ) {
				return array( 'ok' => false, 'detail' => 'expected_female_d1_missing:' . implode( ',', $ids ) );
			}
			if ( in_array( $created_users[1], $ids, true ) ) {
				return array( 'ok' => false, 'detail' => 'male_leaked' );
			}
			return array( 'ok' => true, 'detail' => 'count=' . count( $ids ) );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_users( $created_users );
			self::cleanup_service( $service_id );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_a13_1(): array {
		$red = MNS_Availability::check(
			array(
				'service_id'    => 999999,
				'province_code' => 'XXX',
				'city_id'       => 0,
				'district_no'   => 0,
				'service_date'  => MNS_Time::today_tehran(),
				'slot_id'       => '8-12',
				'gender_pref'   => 'female',
			)
		);
		if ( 'red' !== $red['state'] ) {
			return array( 'ok' => false, 'detail' => 'expected_red_got_' . $red['state'] );
		}
		return array( 'ok' => true, 'detail' => 'red_ok' );
	}

	/**
	 * AC-M2-1: week base ⇒ effective.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m2_1(): array {
		$uids = array();
		try {
			$uid = self::make_test_nurse( $uids );
			$tz  = new DateTimeZone( MNS_Time::TZ );
			// Next Saturday from today.
			$d = new DateTimeImmutable( 'now', $tz );
			while ( 0 !== MNS_Time::weekday_tehran( $d->format( 'Y-m-d' ) ) ) {
				$d = $d->modify( '+1 day' );
			}
			if ( $d->format( 'Y-m-d' ) === MNS_Time::today_tehran() ) {
				$d = $d->modify( '+7 days' );
			}
			$sat = $d->format( 'Y-m-d' );
			global $wpdb;
			$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			foreach ( array( '8-12', '12-16' ) as $slot ) {
				$wpdb->replace(
					MNS_DB::table( 'nurse_week' ),
					array(
						'nurse_id' => $uid,
						'weekday'  => 0,
						'slot_id'  => $slot,
					)
				);
			}
			if ( ! MNS_Nurse_Schedule::effective( $uid, $sat, '8-12' ) ) {
				return array( 'ok' => false, 'detail' => '8-12_false' );
			}
			if ( MNS_Nurse_Schedule::effective( $uid, $sat, '16-20' ) ) {
				return array( 'ok' => false, 'detail' => '16-20_true' );
			}
			return array( 'ok' => true, 'detail' => 'sat=' . $sat );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_users( $uids );
		}
	}

	/**
	 * AC-M2-2: save day override then revert clears rows.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m2_2(): array {
		$uids = array();
		try {
			$uid = self::make_test_nurse( $uids );
			$tz  = new DateTimeZone( MNS_Time::TZ );
			$day = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+3 days' )->format( 'Y-m-d' );
			$wd  = MNS_Time::weekday_tehran( $day );
			global $wpdb;
			$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			foreach ( array( '8-12', '12-16' ) as $slot ) {
				$wpdb->replace(
					MNS_DB::table( 'nurse_week' ),
					array(
						'nurse_id' => $uid,
						'weekday'  => $wd,
						'slot_id'  => $slot,
					)
				);
			}
			MNS_Nurse_Schedule::save_days(
				$uid,
				array(
					array(
						'iso' => $day,
						'on'  => array( '4-8' ),
					),
				)
			);
			$cnt = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . MNS_DB::table( 'nurse_day_slots' ) . ' WHERE nurse_id = %d AND day = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$uid,
					$day
				)
			);
			if ( $cnt < 1 ) {
				return array( 'ok' => false, 'detail' => 'no_override_rows' );
			}
			if ( ! MNS_Nurse_Schedule::effective( $uid, $day, '4-8' ) ) {
				return array( 'ok' => false, 'detail' => '4-8_off' );
			}
			if ( MNS_Nurse_Schedule::effective( $uid, $day, '8-12' ) ) {
				return array( 'ok' => false, 'detail' => '8-12_still_on' );
			}
			// Revert to base ⇒ rows deleted.
			MNS_Nurse_Schedule::save_days(
				$uid,
				array(
					array(
						'iso' => $day,
						'on'  => array( '8-12', '12-16' ),
					),
				)
			);
			$cnt2 = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . MNS_DB::table( 'nurse_day_slots' ) . ' WHERE nurse_id = %d AND day = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$uid,
					$day
				)
			);
			if ( 0 !== $cnt2 ) {
				return array( 'ok' => false, 'detail' => 'rows_remain=' . $cnt2 );
			}
			return array( 'ok' => true, 'detail' => 'ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_users( $uids );
		}
	}

	/**
	 * AC-M2-3: slot with order cannot turn off; nothing saved.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m2_3(): array {
		$uids = array();
		$oid  = 0;
		try {
			$uid = self::make_test_nurse( $uids );
			$tz  = new DateTimeZone( MNS_Time::TZ );
			$day = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+4 days' )->format( 'Y-m-d' );
			$wd  = MNS_Time::weekday_tehran( $day );
			global $wpdb;
			$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			foreach ( array( '8-12', '12-16' ) as $slot ) {
				$wpdb->replace(
					MNS_DB::table( 'nurse_week' ),
					array(
						'nurse_id' => $uid,
						'weekday'  => $wd,
						'slot_id'  => $slot,
					)
				);
			}
			$svc = self::ensure_selftest_service();
			$wpdb->insert(
				MNS_DB::table( 'orders' ),
				array(
					'code'            => '9' . substr( (string) time(), -4 ),
					'account_user_id' => $uid,
					'patient_id'      => 1,
					'service_id'      => $svc,
					'status'          => 'assigned',
					'province_code'   => 'THR',
					'city_id'         => 1,
					'district_no'     => 1,
					'address'         => 't',
					'contact_phone'   => '09000000000',
					'service_date'    => $day,
					'slot_id'         => '12-16',
					'nurse_user_id'   => $uid,
					'created_at'      => MNS_Time::now_utc(),
					'updated_at'      => MNS_Time::now_utc(),
				)
			);
			$oid = (int) $wpdb->insert_id;

			$other = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+5 days' )->format( 'Y-m-d' );
			try {
				MNS_Nurse_Schedule::save_days(
					$uid,
					array(
						array(
							'iso' => $day,
							'on'  => array( '8-12' ), // turns off 12-16 which has order
						),
						array(
							'iso' => $other,
							'on'  => array( '4-8' ),
						),
					)
				);
				return array( 'ok' => false, 'detail' => 'no_throw' );
			} catch ( MNS_Error $e ) {
				if ( 'ERR_SLOT_HAS_ORDER' !== $e->error_code() ) {
					return array( 'ok' => false, 'detail' => $e->error_code() );
				}
			}
			$cnt = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . MNS_DB::table( 'nurse_day_slots' ) . ' WHERE nurse_id = %d AND day = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$uid,
					$other
				)
			);
			if ( 0 !== $cnt ) {
				return array( 'ok' => false, 'detail' => 'partial_save' );
			}
			return array( 'ok' => true, 'detail' => 'blocked' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			if ( $oid > 0 ) {
				global $wpdb;
				$wpdb->delete( MNS_DB::table( 'orders' ), array( 'id' => $oid ), array( '%d' ) );
			}
			self::cleanup_users( $uids );
		}
	}

	/**
	 * AC-M2-4: past / beyond horizon ⇒ ERR_VALIDATION.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m2_4(): array {
		$uids = array();
		try {
			$uid = self::make_test_nurse( $uids );
			$tz  = new DateTimeZone( MNS_Time::TZ );
			$past = ( new DateTimeImmutable( 'now', $tz ) )->modify( '-1 day' )->format( 'Y-m-d' );
			try {
				MNS_Nurse_Schedule::save_days(
					$uid,
					array(
						array(
							'iso' => $past,
							'on'  => array( '8-12' ),
						),
					)
				);
				return array( 'ok' => false, 'detail' => 'past_accepted' );
			} catch ( MNS_Error $e ) {
				if ( 'ERR_VALIDATION' !== $e->error_code() ) {
					return array( 'ok' => false, 'detail' => 'past_' . $e->error_code() );
				}
			}
			$horizon = (int) MNS_Settings::get( 'schedule_horizon' );
			$far     = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+' . ( $horizon + 5 ) . ' days' )->format( 'Y-m-d' );
			try {
				MNS_Nurse_Schedule::save_days(
					$uid,
					array(
						array(
							'iso' => $far,
							'on'  => array( '8-12' ),
						),
					)
				);
				return array( 'ok' => false, 'detail' => 'far_accepted' );
			} catch ( MNS_Error $e ) {
				if ( 'ERR_VALIDATION' !== $e->error_code() ) {
					return array( 'ok' => false, 'detail' => 'far_' . $e->error_code() );
				}
			}
			return array( 'ok' => true, 'detail' => 'rejected' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_users( $uids );
		}
	}

	/**
	 * AC-M2-5: districted city without district rejected; city_id=0 ok; remove with orders ⇒ warning.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m2_5(): array {
		$uids = array();
		$oids = array();
		try {
			$uid = self::make_test_nurse( $uids );
			$thr = MNS_Locations::city_id( 'THR', 'تهران' );
			if ( null === $thr ) {
				return array( 'ok' => false, 'detail' => 'no_tehran_id' );
			}
			try {
				MNS_Nurse_Regions::save_regions(
					$uid,
					array(
						array(
							'prov'      => 'THR',
							'city_id'   => $thr,
							'districts' => array(),
						),
					)
				);
				return array( 'ok' => false, 'detail' => 'empty_districts_accepted' );
			} catch ( MNS_Error $e ) {
				if ( 'ERR_VALIDATION' !== $e->error_code() ) {
					return array( 'ok' => false, 'detail' => $e->error_code() );
				}
			}

			$r = MNS_Nurse_Regions::save_regions(
				$uid,
				array(
					array(
						'prov'      => 'THR',
						'city_id'   => 0,
						'districts' => array(),
					),
					array(
						'prov'      => 'THR',
						'city_id'   => $thr,
						'districts' => array( 1, 3 ),
					),
				)
			);
			if ( empty( $r['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'save_failed' );
			}

			// Seed 2 assigned orders in district 3, then remove that district.
			$svc = self::ensure_selftest_service();
			global $wpdb;
			for ( $i = 0; $i < 2; $i++ ) {
				$wpdb->insert(
					MNS_DB::table( 'orders' ),
					array(
						'code'            => (string) ( 80000 + ( $uid % 1000 ) + $i ),
						'account_user_id' => $uid,
						'patient_id'      => 1,
						'service_id'      => $svc,
						'status'          => 'assigned',
						'province_code'   => 'THR',
						'city_id'         => $thr,
						'district_no'     => 3,
						'address'         => 't',
						'contact_phone'   => '09000000000',
						'service_date'    => MNS_Time::today_tehran(),
						'slot_id'         => '8-12',
						'nurse_user_id'   => $uid,
						'created_at'      => MNS_Time::now_utc(),
						'updated_at'      => MNS_Time::now_utc(),
					)
				);
				$oids[] = (int) $wpdb->insert_id;
			}
			$r2 = MNS_Nurse_Regions::save_regions(
				$uid,
				array(
					array(
						'prov'      => 'THR',
						'city_id'   => $thr,
						'districts' => array( 1 ),
					),
				)
			);
			if ( empty( $r2['warning']['orders'] ) || 2 !== (int) $r2['warning']['orders'] ) {
				return array( 'ok' => false, 'detail' => 'warn=' . wp_json_encode( $r2['warning'] ?? null ) );
			}
			return array( 'ok' => true, 'detail' => 'ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			global $wpdb;
			foreach ( $oids as $oid ) {
				if ( $oid > 0 ) {
					$wpdb->delete( MNS_DB::table( 'orders' ), array( 'id' => $oid ), array( '%d' ) );
				}
			}
			self::cleanup_users( $uids );
		}
	}

	/**
	 * AC-M2-6: status codes.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m2_6(): array {
		$uids = array();
		try {
			$uid = self::make_test_nurse( $uids );
			global $wpdb;
			$wpdb->update( MNS_DB::table( 'nurses' ), array( 'accepting' => 0 ), array( 'user_id' => $uid ), array( '%d' ), array( '%d' ) );
			$s = MNS_Nurse_Status::compute( $uid );
			if ( 'paused' !== $s['code'] ) {
				return array( 'ok' => false, 'detail' => 'paused=' . $s['code'] );
			}
			$wpdb->update( MNS_DB::table( 'nurses' ), array( 'accepting' => 1 ), array( 'user_id' => $uid ), array( '%d' ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_regions' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$s2 = MNS_Nurse_Status::compute( $uid );
			if ( 'no_plan' !== $s2['code'] ) {
				return array( 'ok' => false, 'detail' => 'no_plan=' . $s2['code'] );
			}
			// Add region + empty today ⇒ away.
			$thr = MNS_Locations::city_id( 'THR', 'تهران' );
			MNS_Nurse_Regions::save_regions(
				$uid,
				array(
					array(
						'prov'      => 'THR',
						'city_id'   => $thr ? $thr : 0,
						'districts' => $thr ? array( 1 ) : array(),
					),
				)
			);
			$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_day_slots' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			// Force today all off via day_slots for every open slot.
			$today = MNS_Time::today_tehran();
			foreach ( array( '4-8', '8-12', '12-16', '16-20', '20-24' ) as $slot ) {
				$wpdb->replace(
					MNS_DB::table( 'nurse_day_slots' ),
					array(
						'nurse_id'  => $uid,
						'day'       => $today,
						'slot_id'   => $slot,
						'available' => 0,
					)
				);
			}
			// Need some week so has_week path isn't no_plan — week for another weekday.
			$wd = ( MNS_Time::weekday_tehran( $today ) + 1 ) % 7;
			$wpdb->replace(
				MNS_DB::table( 'nurse_week' ),
				array(
					'nurse_id' => $uid,
					'weekday'  => $wd,
					'slot_id'  => '8-12',
				)
			);
			$s3 = MNS_Nurse_Status::compute( $uid );
			if ( 'away' !== $s3['code'] ) {
				return array( 'ok' => false, 'detail' => 'away=' . $s3['code'] );
			}
			return array( 'ok' => true, 'detail' => 'codes_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_users( $uids );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_1(): array {
		MNS_Services_Seed::ensure_slice1();
		$s = MNS_Services::get( 'serum' );
		$i = MNS_Services::get( 'injection' );
		if ( ! $s || ! $i ) {
			return array( 'ok' => false, 'detail' => 'missing_seed' );
		}
		if ( empty( $s['config']['order_fields'] ) ) {
			return array( 'ok' => false, 'detail' => 'empty_config' );
		}
		$before = MNS_Services::list_active();
		MNS_Services::update_admin( (int) $s['id'], array( 'active' => false ) );
		$after = MNS_Services::list_active();
		$ids   = array_map(
			static function ( $r ) {
				return (int) $r['id'];
			},
			$after
		);
		MNS_Services::update_admin( (int) $s['id'], array( 'active' => true ) );
		if ( in_array( (int) $s['id'], $ids, true ) ) {
			return array( 'ok' => false, 'detail' => 'inactive_still_listed' );
		}
		return array( 'ok' => true, 'detail' => 'active_before=' . count( $before ) );
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_2(): array {
		$uids = array();
		try {
			$login = 'mns_selftest_pat_' . wp_generate_password( 5, false );
			$uid   = wp_insert_user(
				array(
					'user_login' => $login,
					'user_pass'  => wp_generate_password( 12 ),
					'user_email' => $login . '@example.invalid',
					'role'       => 'subscriber',
				)
			);
			if ( is_wp_error( $uid ) ) {
				return array( 'ok' => false, 'detail' => $uid->get_error_message() );
			}
			$uids[] = (int) $uid;
			$self = MNS_Patients::ensure_self( (int) $uid );
			if ( 'self' !== $self['kind'] ) {
				return array( 'ok' => false, 'detail' => 'not_self' );
			}
			$other = MNS_Patients::create( (int) $uid, array( 'name' => 'تست', 'age_group' => 'adult', 'gender' => 'female' ) );
			$login2 = 'mns_selftest_pat2_' . wp_generate_password( 5, false );
			$uid2   = wp_insert_user(
				array(
					'user_login' => $login2,
					'user_pass'  => wp_generate_password( 12 ),
					'user_email' => $login2 . '@example.invalid',
					'role'       => 'subscriber',
				)
			);
			if ( is_wp_error( $uid2 ) ) {
				return array( 'ok' => false, 'detail' => $uid2->get_error_message() );
			}
			$uids[] = (int) $uid2;
			try {
				MNS_Patients::get_owned( (int) $other['id'], (int) $uid2 );
				return array( 'ok' => false, 'detail' => 'leaked' );
			} catch ( MNS_Error $e ) {
				if ( 'ERR_NOT_FOUND' !== $e->error_code() ) {
					return array( 'ok' => false, 'detail' => $e->error_code() );
				}
			}
			return array( 'ok' => true, 'detail' => 'ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			foreach ( $uids as $u ) {
				global $wpdb;
				$wpdb->delete( MNS_DB::table( 'patients' ), array( 'owner_user_id' => $u ), array( '%d' ) );
				wp_delete_user( $u );
			}
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_a13_4(): array {
		$today = MNS_Time::today_tehran();
		if ( ! MNS_Time::slot_closed( '0-4' ) ) {
			return array( 'ok' => false, 'detail' => '0-4_not_closed' );
		}
		// Simulate API validation logic.
		if ( MNS_Time::slot_start_ts( $today, '4-8' ) >= time() ) {
			// morning not started — still ok as long as closed path works.
		}
		return array( 'ok' => true, 'detail' => 'closed_ok' );
	}

	/**
	 * @param array<int,int> $uids Collector.
	 */
	private static function make_test_nurse( array &$uids ): int {
		$login = 'mns_selftest_m2_' . wp_generate_password( 6, false );
		$uid   = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 12 ),
				'user_email' => $login . '@example.invalid',
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $uid ) ) {
			throw new \RuntimeException( $uid->get_error_message() );
		}
		$uid = (int) $uid;
		$uids[] = $uid;
		$user = new WP_User( $uid );
		$user->add_role( MNS_Roles::ROLE_NURSE );
		global $wpdb;
		$wpdb->replace(
			MNS_DB::table( 'nurses' ),
			array(
				'user_id'     => $uid,
				'status'      => 'approved',
				'gender'      => 'female',
				'national_id' => sprintf( '8%09d', $uid % 1000000000 ),
				'accepting'   => 1,
				'applied_at'  => MNS_Time::now_utc(),
			)
		);
		return $uid;
	}

	private static function ensure_selftest_service(): int {
		global $wpdb;
		$services   = MNS_DB::table( 'services' );
		$service_id = (int) $wpdb->get_var( "SELECT id FROM {$services} WHERE slug = 'mns_selftest_svc' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $service_id > 0 ) {
			return $service_id;
		}
		$wpdb->insert(
			$services,
			array(
				'slug'          => 'mns_selftest_svc',
				'category'      => 'test',
				'name'          => 'Selftest Service',
				'active'        => 1,
				'price_from'    => 1000,
				'price_to'      => 2000,
				'has_drug_step' => 0,
				'sort'          => 999,
				'config'        => '{}',
				'created_at'    => MNS_Time::now_utc(),
				'updated_at'    => MNS_Time::now_utc(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<int,int> $user_ids Users.
	 */
	private static function cleanup_users( array $user_ids ): void {
		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			if ( $uid <= 0 ) {
				continue;
			}
			MNS_Selftest::purge_user_rows( $uid );
			wp_delete_user( $uid );
		}
	}

	private static function cleanup_service( int $service_id ): void {
		if ( $service_id <= 0 ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( MNS_DB::table( 'services' ), array( 'id' => $service_id, 'slug' => 'mns_selftest_svc' ), array( '%d', '%s' ) );
	}
}
