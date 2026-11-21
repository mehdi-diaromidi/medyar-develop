<?php
/**
 * Develop-only E2E sandbox users (T-D.1). Prefixed mns_e2e_* — not purged by self-test.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_E2E {

	public const OPT_UNTIL   = 'mns_e2e_enabled_until';
	public const CRON        = 'mns_e2e_hourly_cleanup';
	public const META_FLAG   = 'mns_e2e_user';
	public const TOKEN_TTL   = 86400; // 24 hours (develop-only test tool; each link is still single-use).
	public const ENABLE_TTL  = 7200; // 2 hours.

	/** @var array<string,array{login:string,role:string,phone:string}> */
	private static $specs = array(
		'patient' => array(
			'login' => 'mns_e2e_patient',
			'role'  => 'patient',
			'phone' => '09120000011',
		),
		'nurse_f' => array(
			'login' => 'mns_e2e_nurse_f',
			'role'  => 'nurse',
			'phone' => '09120000012',
		),
		'nurse_m' => array(
			'login' => 'mns_e2e_nurse_m',
			'role'  => 'nurse',
			'phone' => '09120000013',
		),
	);

	public static function host_allowed(): bool {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		return false !== strpos( $host, 'develop.' );
	}

	public static function is_enabled(): bool {
		$until = (int) get_option( self::OPT_UNTIL, 0 );
		return $until > time();
	}

	/** Far-future timestamp used for "no time limit" (develop-only; the host gate still applies). */
	public const FOREVER = 4102444800;

	public static function enable_forever(): void {
		update_option( self::OPT_UNTIL, self::FOREVER, false );
		self::schedule_cron();
	}

	public static function enable_two_hours(): void {
		update_option( self::OPT_UNTIL, time() + self::ENABLE_TTL, false );
		self::schedule_cron();
	}

	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	public static function unschedule_cron(): void {
		$ts = wp_next_scheduled( self::CRON );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
		}
	}

	public static function boot_hooks(): void {
		add_action( self::CRON, array( __CLASS__, 'cron_cleanup' ) );
		add_action( 'admin_post_mns_e2e_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_nopriv_mns_e2e_login', array( __CLASS__, 'handle_login' ) );
	}

	public static function cron_cleanup(): void {
		if ( self::is_enabled() ) {
			return;
		}
		self::purge_all();
	}

	/**
	 * @return array{users:array<string,array{user_id:int,login:string,role:string,login_url:string}>,enabled_until:int}
	 */
	public static function seed(): array {
		if ( ! self::is_enabled() ) {
			throw MNS_Error::forbidden( 'جعبهٔ ماسه E2E فعال نیست.' );
		}
		MNS_Services_Seed::ensure_slice1();
		$primary = MNS_Services::get( MNS_Services::default_active_slug() );
		if ( ! $primary ) {
			$primary = MNS_Services::get( 'serum' );
		}
		if ( ! $primary ) {
			throw MNS_Error::server( 'خدمت فعال کاتالوگ آماده نیست.' );
		}
		$thr = MNS_Locations::city_id( 'THR', 'تهران' );
		if ( ! $thr ) {
			throw MNS_Error::server( 'شهر تهران یافت نشد.' );
		}

		$out = array();
		foreach ( self::$specs as $key => $spec ) {
			$uid = self::ensure_user( $spec['login'], $spec['phone'], $spec['role'] );
			if ( 'nurse_f' === $key ) {
				// Empty service list ⇒ default all (RUN-G); pass [] intentionally.
				self::setup_nurse( $uid, 'female', array(), (int) $thr, range( 10, 22 ) );
			} elseif ( 'nurse_m' === $key ) {
				self::setup_nurse( $uid, 'male', array(), (int) $thr, range( 1, 3 ) );
			} else {
				MNS_Patients::ensure_self( $uid );
			}
			$token = self::mint_login_token( $uid );
			$out[ $key ] = array(
				'user_id'   => $uid,
				'login'     => $spec['login'],
				'role'      => $spec['role'],
				'login_url' => self::login_url( $token ),
			);
		}
		return array(
			'users'         => $out,
			'enabled_until' => (int) get_option( self::OPT_UNTIL, 0 ),
		);
	}

	/**
	 * @return array<string,array{user_id:int,login:string,role:string,login_url:string}>
	 */
	public static function mint_links_for_existing(): array {
		if ( ! self::is_enabled() ) {
			return array();
		}
		$out = array();
		foreach ( self::$specs as $key => $spec ) {
			$user = get_user_by( 'login', $spec['login'] );
			if ( ! $user || ! get_user_meta( (int) $user->ID, self::META_FLAG, true ) ) {
				continue;
			}
			$token = self::mint_login_token( (int) $user->ID );
			$out[ $key ] = array(
				'user_id'   => (int) $user->ID,
				'login'     => $spec['login'],
				'role'      => $spec['role'],
				'login_url' => self::login_url( $token ),
			);
		}
		return $out;
	}

	/**
	 * Build two assigned sample orders for browser AC (T-E.5).
	 *
	 * @return array{orders:array<int,array{code:string,nurse:string,district:int}>}
	 */
	public static function seed_assigned_orders(): array {
		if ( ! self::is_enabled() ) {
			throw MNS_Error::forbidden( 'جعبهٔ ماسه E2E فعال نیست.' );
		}
		$patient = get_user_by( 'login', self::$specs['patient']['login'] );
		$nurse_f = get_user_by( 'login', self::$specs['nurse_f']['login'] );
		$nurse_m = get_user_by( 'login', self::$specs['nurse_m']['login'] );
		if ( ! $patient || ! $nurse_f || ! $nurse_m ) {
			throw MNS_Error::validation( 'ابتدا «ساخت دادهٔ E2E» را بزنید.' );
		}
		if ( ! get_user_meta( (int) $patient->ID, self::META_FLAG, true )
			|| ! get_user_meta( (int) $nurse_f->ID, self::META_FLAG, true )
			|| ! get_user_meta( (int) $nurse_m->ID, self::META_FLAG, true ) ) {
			throw MNS_Error::forbidden( 'کاربران E2E معتبر نیستند.' );
		}

		MNS_Services_Seed::ensure_slice1();
		$svc = MNS_Services::get( MNS_Services::default_active_slug() );
		if ( ! $svc ) {
			$svc = MNS_Services::get( 'serum' );
		}
		$thr = MNS_Locations::city_id( 'THR', 'تهران' );
		if ( ! $svc || ! $thr ) {
			throw MNS_Error::server( 'خدمت فعال یا شهر تهران آماده نیست.' );
		}
		$ess = array( 'allergy' => 'no' );
		$fields = isset( $svc['config']['order_fields'] ) && is_array( $svc['config']['order_fields'] ) ? $svc['config']['order_fields'] : array();
		if ( ! empty( $fields['serum_types'] ) && is_array( $fields['serum_types'] ) ) {
			$ess['serum_type'] = (string) $fields['serum_types'][0];
		}

		$pat = MNS_Patients::ensure_self( (int) $patient->ID );
		$tz  = new DateTimeZone( MNS_Time::TZ );
		$day = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+1 day' )->format( 'Y-m-d' );
		$phone = (string) ( get_user_meta( (int) $patient->ID, 'mobile', true ) ?: self::$specs['patient']['phone'] );

		$prev_mode = MNS_Settings::get( 'assignment_mode' );
		MNS_Settings::set( 'assignment_mode', 'manual' );

		$specs = array(
			array(
				'nurse_uid' => (int) $nurse_f->ID,
				'nurse_key' => 'nurse_f',
				'district'  => 10,
				'gender'    => 'female',
				'slot'      => '8-12',
				'address'   => 'تهران منطقه ۱۰ — سفارش نمونه E2E زن',
			),
			array(
				'nurse_uid' => (int) $nurse_m->ID,
				'nurse_key' => 'nurse_m',
				'district'  => 1,
				'gender'    => 'male',
				'slot'      => '12-16',
				'address'   => 'تهران منطقه ۱ — سفارش نمونه E2E مرد',
			),
		);

		$created = array();
		try {
			foreach ( $specs as $spec ) {
				$result = MNS_Orders::create_from_request(
					(int) $patient->ID,
					array(
						'patient_id'    => (int) $pat['id'],
						'service_id'    => (int) $svc['id'],
						'items'         => array( array( 'service_id' => (int) $svc['id'], 'is_extra' => 0 ) ),
						'prov'          => 'THR',
						'city_id'       => (int) $thr,
						'district'      => (int) $spec['district'],
						'address'       => (string) $spec['address'],
						'contact_phone' => $phone,
						'date'          => $day,
						'slot'          => (string) $spec['slot'],
						'gender'        => (string) $spec['gender'],
						'essentials'    => $ess,
						'notes'         => 'mns_e2e_assigned',
					)
				);
				$order = isset( $result['order'] ) && is_array( $result['order'] ) ? $result['order'] : $result;
				$code  = (string) ( $order['code'] ?? '' );
				// The public summary has no numeric id; look the order up by its code.
				global $wpdb;
				$oid = (int) ( $order['id'] ?? 0 );
				if ( $oid <= 0 && '' !== $code ) {
					$oid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MNS_DB::table( 'orders' ) . ' WHERE code = %s LIMIT 1', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
				if ( $oid <= 0 ) {
					throw MNS_Error::server( 'سفارش ساخته نشد.' );
				}
				$fresh = MNS_Assignment::admin_set_nurse( $oid, (int) $spec['nurse_uid'], max( 1, get_current_user_id() ), true );
				$created[] = array(
					'code'     => (string) ( $fresh['code'] ?? $code ),
					'nurse'    => (string) $spec['nurse_key'],
					'district' => (int) $spec['district'],
					'status'   => (string) ( $fresh['status'] ?? 'assigned' ),
				);
			}
		} finally {
			MNS_Settings::set( 'assignment_mode', $prev_mode );
		}

		return array( 'orders' => $created );
	}

	public static function purge_all(): void {
		$users = get_users(
			array(
				'search'         => 'mns_e2e_*',
				'search_columns' => array( 'user_login' ),
				'fields'         => array( 'ID', 'user_login' ),
				'number'         => 100,
			)
		);
		foreach ( $users as $u ) {
			$login = is_object( $u ) ? (string) $u->user_login : '';
			if ( 0 !== strpos( $login, 'mns_e2e_' ) ) {
				continue;
			}
			$uid = (int) ( is_object( $u ) ? $u->ID : 0 );
			if ( $uid <= 0 ) {
				continue;
			}
			MNS_Selftest::purge_user_rows( $uid );
			wp_delete_user( $uid );
		}
		delete_option( self::OPT_UNTIL );
	}

	public static function handle_login(): void {
		if ( ! self::host_allowed() || ! self::is_enabled() ) {
			wp_die( esc_html__( 'ابزار E2E غیرفعال است.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 48 !== strlen( $token ) || ! preg_match( '/^[a-f0-9]+$/i', $token ) ) {
			wp_die( esc_html__( 'لینک ورود نامعتبر است.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		$key = 'mns_e2e_login_' . hash( 'sha256', $token );
		$uid = (int) get_transient( $key );
		delete_transient( $key );
		if ( $uid <= 0 ) {
			wp_die( esc_html__( 'لینک ورود منقضی یا مصرف‌شده است.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		if ( ! get_user_meta( $uid, self::META_FLAG, true ) ) {
			wp_die( esc_html__( 'کاربر E2E نیست.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			wp_die( esc_html__( 'کاربر یافت نشد.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true );
		$is_nurse = in_array( MNS_Roles::ROLE_NURSE, (array) $user->roles, true );
		$dest     = $is_nurse
			? home_url( '/nursing-nurse-panel/' )
			: home_url( '/nursing-patient-panel/' );
		wp_safe_redirect( $dest );
		exit;
	}

	private static function ensure_user( string $login, string $phone, string $role ): int {
		$existing = get_user_by( 'login', $login );
		if ( $existing ) {
			$uid = (int) $existing->ID;
		} else {
			$uid = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'user_email'   => $login . '@e2e.invalid',
					'display_name' => $login,
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $uid ) ) {
				throw MNS_Error::server( $uid->get_error_message() );
			}
			$uid = (int) $uid;
		}
		update_user_meta( $uid, self::META_FLAG, '1' );
		update_user_meta( $uid, 'mobile', $phone );
		update_user_meta( $uid, 'billing_phone', $phone );
		$user = new WP_User( $uid );
		if ( 'nurse' === $role ) {
			$user->add_role( MNS_Roles::ROLE_NURSE );
		} else {
			$user->add_cap( MNS_Roles::CAP_PATIENT_PANEL );
		}
		return $uid;
	}

	/**
	 * @param array<int,int> $service_ids Service ids.
	 * @param array<int,int> $districts   District numbers.
	 */
	private static function setup_nurse( int $uid, string $gender, array $service_ids, int $city_id, array $districts ): void {
		global $wpdb;
		$wpdb->replace(
			MNS_DB::table( 'nurses' ),
			array(
				'user_id'     => $uid,
				'status'      => 'approved',
				'gender'      => $gender,
				'national_id' => self::fake_national_id( $uid ),
				'accepting'   => 1,
				'applied_at'  => MNS_Time::now_utc(),
			)
		);
		$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		foreach ( $service_ids as $sid ) {
			$wpdb->replace(
				MNS_DB::table( 'nurse_services' ),
				array(
					'nurse_id'   => $uid,
					'service_id' => (int) $sid,
				)
			);
		}
		$wpdb->delete( MNS_DB::table( 'nurse_regions' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		foreach ( $districts as $d ) {
			$wpdb->insert(
				MNS_DB::table( 'nurse_regions' ),
				array(
					'nurse_id'      => $uid,
					'province_code' => 'THR',
					'city_id'       => $city_id,
					'district_no'   => (int) $d,
				)
			);
		}
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
		$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		$wpdb->delete( MNS_DB::table( 'nurse_day_slots' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		for ( $wd = 0; $wd <= 6; $wd++ ) {
			foreach ( $open as $slot ) {
				$wpdb->replace(
					MNS_DB::table( 'nurse_week' ),
					array(
						'nurse_id' => $uid,
						'weekday'  => $wd,
						'slot_id'  => $slot,
					)
				);
			}
		}
	}

	/** Iranian national-id checksum so profile PATCH validation accepts seeded nurses. */
	private static function fake_national_id( int $seed ): string {
		$base = str_pad( (string) abs( $seed % 1000000000 ), 9, '0', STR_PAD_LEFT );
		$sum  = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $base[ $i ] * ( 10 - $i );
		}
		$r = $sum % 11;
		$c = $r < 2 ? $r : 11 - $r;
		return $base . (string) $c;
	}

	/**
	 * One-time login link for a demo user (Test Center). Only users carrying the demo marker qualify.
	 */
	public static function login_link_for_demo( int $uid ): string {
		if ( ! self::host_allowed() ) {
			throw MNS_Error::forbidden( 'ابزار تست فقط روی develop کار می‌کند.' );
		}
		if ( 'nurse' !== (string) get_user_meta( $uid, MNS_Demo_Data::META, true ) && 'patient' !== (string) get_user_meta( $uid, MNS_Demo_Data::META, true ) ) {
			throw MNS_Error::validation( 'این کاربر نمایشی نیست.' );
		}
		if ( ! self::is_enabled() ) {
			self::enable_forever();
		}
		update_user_meta( $uid, self::META_FLAG, '1' );
		return self::login_url( self::mint_login_token( $uid ) );
	}

	private static function mint_login_token( int $uid ): string {
		$token = bin2hex( random_bytes( 24 ) ); // 48 hex chars.
		set_transient( 'mns_e2e_login_' . hash( 'sha256', $token ), $uid, self::TOKEN_TTL );
		return $token;
	}

	private static function login_url( string $token ): string {
		return add_query_arg(
			array(
				'action' => 'mns_e2e_login',
				't'      => $token,
			),
			admin_url( 'admin-post.php' )
		);
	}
}
