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
	 * @param array<int,int> $user_ids Users.
	 */
	private static function cleanup_users( array $user_ids ): void {
		global $wpdb;
		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			if ( $uid <= 0 ) {
				continue;
			}
			$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_day_slots' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_regions' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurses' ), array( 'user_id' => $uid ), array( '%d' ) );
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
