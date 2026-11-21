<?php
/**
 * Demo data for the develop Test Center: 100 nurses, 10 patients, 200 orders with realistic Iranian data.
 *
 * Develop-only (guarded by MNS_E2E::host_allowed()). Everything it creates is marked with the user meta
 * `mns_demo_user` so it can be removed again without touching real or E2E data. Work is done in small batches
 * so no request runs long enough for the host/CDN to cut it off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Demo_Data {

	public const META       = 'mns_demo_user';
	public const NURSES     = 100;
	public const PATIENTS   = 10;
	public const ORDERS     = 200;
	public const MIN_NURSES = 10;

	/** Demo nurses never take part in availability / auto-assignment, so they cannot steal real test orders. */
	public static function boot(): void {
		add_filter( 'mns_eligibility_candidate_ids', array( __CLASS__, 'hide_from_matching' ), 20, 1 );
	}

	/**
	 * @param mixed $ids Candidate nurse ids.
	 * @return mixed
	 */
	public static function hide_from_matching( $ids ) {
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return $ids;
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( '' === (string) get_user_meta( (int) $id, self::META, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ counts */

	/**
	 * @return array{nurses:int,patients:int,orders:int}
	 */
	public static function counts(): array {
		global $wpdb;
		$nurses   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = 'nurse'", self::META ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$patients = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = 'patient'", self::META ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orders   = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . MNS_DB::table( 'orders' ) . " WHERE account_user_id IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = 'patient')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::META
			)
		);
		return array(
			'nurses'   => $nurses,
			'patients' => $patients,
			'orders'   => $orders,
		);
	}

	/**
	 * @return array<int,int>
	 */
	private static function user_ids( string $kind ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s ORDER BY user_id ASC", self::META, $kind ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * @return array<int,array{id:int,name:string,gender:string}>
	 */
	public static function nurse_list(): array {
		$out = array();
		foreach ( self::user_ids( 'nurse' ) as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) {
				continue;
			}
			$out[] = array(
				'id'   => $uid,
				'name' => (string) $u->display_name . ' (' . (string) $u->user_login . ')',
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ nurses */

	/**
	 * Create up to $batch more demo nurses (until NURSES exist).
	 *
	 * @return array{nurses:int,patients:int,orders:int}
	 */
	public static function create_nurses( int $batch ): array {
		$have = self::counts();
		$todo = min( max( 1, $batch ), self::NURSES - $have['nurses'] );
		if ( $todo <= 0 ) {
			return $have;
		}
		$thr = MNS_Locations::city_id( 'THR', 'تهران' );
		if ( ! $thr ) {
			throw MNS_Error::server( 'شهر تهران یافت نشد.' );
		}
		$taken = self::taken_indexes( 'mns_demo_n_' );
		$made  = 0;
		for ( $idx = 1; $idx <= self::NURSES && $made < $todo; $idx++ ) {
			if ( isset( $taken[ $idx ] ) ) {
				continue;
			}
			self::make_nurse( $idx, (int) $thr );
			$made++;
		}
		return self::counts();
	}

	/**
	 * @return array<int,bool> index => true for logins that already exist.
	 */
	private static function taken_indexes( string $prefix ): array {
		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $login ) {
				$out[ (int) substr( (string) $login, strlen( $prefix ) ) ] = true;
			}
		}
		return $out;
	}

	private static function make_nurse( int $idx, int $city_id ): int {
		global $wpdb;
		mt_srand( 7000 + $idx );
		$female = ( $idx % 5 ) !== 0; // 80% female.
		$first  = self::pick( $female ? self::female_first() : self::male_first() );
		$last   = self::pick( self::last_names() );
		$login  = sprintf( 'mns_demo_n_%03d', $idx );
		$phone  = '0999' . str_pad( (string) ( 1000000 + $idx ), 7, '0', STR_PAD_LEFT );
		$uid    = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'user_email'   => $login . '@demo.invalid',
				'display_name' => $first . ' ' . $last,
				'first_name'   => $first,
				'last_name'    => $last,
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $uid ) ) {
			throw MNS_Error::server( $uid->get_error_message() );
		}
		$uid = (int) $uid;
		update_user_meta( $uid, self::META, 'nurse' );
		update_user_meta( $uid, 'mobile', $phone );
		update_user_meta( $uid, 'billing_phone', $phone );
		$user = new WP_User( $uid );
		$user->add_role( MNS_Roles::ROLE_NURSE );

		$quals = array( 'bsc', 'bsc', 'bsc', 'msc', 'associate' );
		$exps  = array( '1-3', '3-5', '5-10', '10+' );
		$works = array( 'بیمارستان شریعتی', 'بیمارستان امام خمینی', 'بیمارستان لقمان', 'بیمارستان میلاد', 'بیمارستان مدرس', 'بیمارستان رسول اکرم', 'درمانگاه شبانه‌روزی ولیعصر', 'بیمارستان بقیه‌الله', 'مرکز مراقبت در منزل مهر' );
		$wpdb->insert(
			MNS_DB::table( 'nurses' ),
			array(
				'user_id'       => $uid,
				'status'        => 'approved',
				'gender'        => $female ? 'female' : 'male',
				'national_id'   => self::national_id( 800000000 + $idx * 7919 ),
				'license_no'    => (string) ( 100000 + mt_rand( 0, 899999 ) ),
				'qualification' => self::pick( $quals ),
				'experience'    => self::pick( $exps ),
				'workplace'     => self::pick( $works ),
				'about'         => self::pick( array( 'آمادهٔ ارائهٔ خدمات پرستاری در منزل با رعایت کامل اصول بهداشتی.', 'سابقهٔ کار در بخش‌های ویژه و مراقبت از سالمندان.', 'تخصص در پانسمان و مراقبت از زخم.', 'تجربهٔ تزریقات و سرم‌درمانی در منزل.', '' ) ),
				'accepting'     => 1,
				'applied_at'    => gmdate( 'Y-m-d H:i:s', time() - mt_rand( 10, 200 ) * DAY_IN_SECONDS ),
				'reviewed_at'   => gmdate( 'Y-m-d H:i:s', time() - mt_rand( 1, 9 ) * DAY_IN_SECONDS ),
			)
		);

		// Regions: 3-6 districts of Tehran.
		$all = range( 1, 22 );
		shuffle( $all );
		foreach ( array_slice( $all, 0, mt_rand( 3, 6 ) ) as $d ) {
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
		// Weekly availability: 4-7 days, 2-3 open slots each.
		$open = self::open_slots();
		$days = range( 0, 6 );
		shuffle( $days );
		foreach ( array_slice( $days, 0, mt_rand( 4, 7 ) ) as $wd ) {
			$slots = $open;
			shuffle( $slots );
			foreach ( array_slice( $slots, 0, min( count( $slots ), mt_rand( 2, 3 ) ) ) as $slot ) {
				$wpdb->replace(
					MNS_DB::table( 'nurse_week' ),
					array(
						'nurse_id' => $uid,
						'weekday'  => (int) $wd,
						'slot_id'  => (string) $slot,
					)
				);
			}
		}
		return $uid;
	}

	/**
	 * Delete up to $batch demo nurses.
	 *
	 * @return array{nurses:int,patients:int,orders:int}
	 */
	public static function delete_nurses( int $batch ): array {
		global $wpdb;
		self::load_user_api();
		$ids = array_slice( self::user_ids( 'nurse' ), 0, max( 1, $batch ) );
		foreach ( $ids as $uid ) {
			// Orders of real users that were assigned to this nurse go back to the queue; history rows are kept.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . MNS_DB::table( 'orders' ) . " SET nurse_user_id = NULL, assigned_at = NULL, status = 'submitted' WHERE nurse_user_id = %d AND status = 'assigned'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$uid
				)
			);
			$wpdb->delete( MNS_DB::table( 'notifications' ), array( 'user_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_week' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_day_slots' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_regions' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => $uid ), array( '%d' ) );
			$wpdb->delete( MNS_DB::table( 'nurses' ), array( 'user_id' => $uid ), array( '%d' ) );
			wp_delete_user( $uid );
		}
		return self::counts();
	}

	/* ------------------------------------------------------------------ patients + orders */

	/**
	 * Create the demo patients (first call) and then up to $batch orders (until ORDERS exist).
	 *
	 * @return array{nurses:int,patients:int,orders:int}
	 */
	public static function create_orders( int $batch ): array {
		global $wpdb;
		$counts = self::counts();
		if ( $counts['nurses'] < self::MIN_NURSES ) {
			throw MNS_Error::validation( 'اول دکمهٔ «ساخت ۱۰۰ پرستار» را بزن تا سفارش‌ها به پرستارهای تستی وصل شوند.' );
		}
		$patients = self::user_ids( 'patient' );
		if ( count( $patients ) < self::PATIENTS ) {
			self::make_patients( self::PATIENTS - count( $patients ) );
			$patients = self::user_ids( 'patient' );
		}
		$todo = min( max( 1, $batch ), self::ORDERS - $counts['orders'] );
		if ( $todo <= 0 ) {
			return self::counts();
		}

		$services = array();
		foreach ( MNS_Services::list_active() as $s ) {
			$services[ (string) $s['category'] ][] = $s;
		}
		if ( empty( $services ) ) {
			throw MNS_Error::server( 'کاتالوگ خدمات خالی است.' );
		}
		$nurses = array(
			'female' => array(),
			'male'   => array(),
		);
		$rows   = $wpdb->get_results( 'SELECT n.user_id, n.gender FROM ' . MNS_DB::table( 'nurses' ) . " n INNER JOIN {$wpdb->usermeta} m ON m.user_id = n.user_id AND m.meta_key = '" . self::META . "' WHERE n.status = 'approved'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $rows as $r ) {
			$g = 'male' === $r['gender'] ? 'male' : 'female';
			$nurses[ $g ][] = (int) $r['user_id'];
		}
		$thr = (int) MNS_Locations::city_id( 'THR', 'تهران' );

		for ( $n = 0; $n < $todo; $n++ ) {
			$seq = $counts['orders'] + $n;
			self::make_order( $seq, $patients, $services, $nurses, $thr );
		}
		return self::counts();
	}

	private static function make_patients( int $how_many ): void {
		global $wpdb;
		$people = array(
			array( 'اکرم رضایی', 'female', 'adult', 'عباس رضایی' ),
			array( 'حسن کریمی', 'male', 'adult', 'فاطمه کریمی' ),
			array( 'شهین موسوی', 'female', 'adult', 'سعید موسوی' ),
			array( 'ابراهیم نوری', 'male', 'adult', 'مرضیه نوری' ),
			array( 'منصوره احمدی', 'female', 'adult', 'نیما احمدی' ),
			array( 'جلال صادقی', 'male', 'adult', 'سمیرا صادقی' ),
			array( 'فاطمه قاسمی', 'female', 'adult', 'آرین قاسمی' ),
			array( 'محمود جعفری', 'male', 'adult', 'زهرا جعفری' ),
			array( 'پروین حیدری', 'female', 'adult', 'کیان حیدری' ),
			array( 'غلامرضا فتحی', 'male', 'adult', 'لیلا فتحی' ),
		);
		$taken = self::taken_indexes( 'mns_demo_p_' );
		$made  = 0;
		for ( $idx = 1; $idx <= self::PATIENTS && $made < $how_many; $idx++ ) {
			if ( isset( $taken[ $idx ] ) ) {
				continue;
			}
			$p     = $people[ ( $idx - 1 ) % count( $people ) ];
			$login = sprintf( 'mns_demo_p_%02d', $idx );
			$phone = '0998' . str_pad( (string) ( 2000000 + $idx ), 7, '0', STR_PAD_LEFT );
			$uid   = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'user_email'   => $login . '@demo.invalid',
					'display_name' => $p[0],
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $uid ) ) {
				throw MNS_Error::server( $uid->get_error_message() );
			}
			$uid = (int) $uid;
			update_user_meta( $uid, self::META, 'patient' );
			update_user_meta( $uid, 'mobile', $phone );
			update_user_meta( $uid, 'billing_phone', $phone );
			$user = new WP_User( $uid );
			$user->add_cap( MNS_Roles::CAP_PATIENT_PANEL );
			$now = MNS_Time::now_utc();
			$wpdb->insert(
				MNS_DB::table( 'patients' ),
				array(
					'owner_user_id' => $uid,
					'kind'          => 'self',
					'name'          => $p[0],
					'age_group'     => 'adult',
					'gender'        => $p[1],
					'created_at'    => $now,
				)
			);
			$wpdb->insert(
				MNS_DB::table( 'patients' ),
				array(
					'owner_user_id' => $uid,
					'kind'          => 'other',
					'name'          => $p[3],
					'age_group'     => 'adult',
					'gender'        => 'female' === $p[1] ? 'male' : 'female',
					'created_at'    => $now,
				)
			);
			$made++;
		}
	}

	/**
	 * @param array<int,int>                               $patients Demo patient user ids.
	 * @param array<string,array<int,array<string,mixed>>> $services Active services by category.
	 * @param array<string,array<int,int>>                 $nurses   Demo nurse ids by gender.
	 */
	private static function make_order( int $seq, array $patients, array $services, array $nurses, int $thr ): void {
		global $wpdb;
		mt_srand( 91000 + $seq );
		$account = $patients[ $seq % count( $patients ) ];
		$pats    = $wpdb->get_results( $wpdb->prepare( 'SELECT id, gender FROM ' . MNS_DB::table( 'patients' ) . ' WHERE owner_user_id = %d AND deleted_at IS NULL ORDER BY id ASC', $account ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $pats ) ) {
			return;
		}
		$pat = $pats[ mt_rand( 0, 9 ) < 7 ? 0 : count( $pats ) - 1 ];

		// Services: 1-3 sub-services of one category; sometimes one extra from another category.
		$cats  = array_keys( $services );
		$cat   = $cats[ mt_rand( 0, count( $cats ) - 1 ) ];
		$pool  = $services[ $cat ];
		shuffle( $pool );
		$items = array();
		foreach ( array_slice( $pool, 0, mt_rand( 1, min( 3, count( $pool ) ) ) ) as $s ) {
			$items[] = array( 's' => $s, 'extra' => 0 );
		}
		if ( count( $cats ) > 1 && mt_rand( 0, 4 ) === 0 ) {
			$other = $cats[ mt_rand( 0, count( $cats ) - 1 ) ];
			if ( $other !== $cat ) {
				$o       = $services[ $other ][ mt_rand( 0, count( $services[ $other ] ) - 1 ) ];
				$items[] = array( 's' => $o, 'extra' => 1 );
			}
		}
		$primary = $items[0]['s'];
		$manual  = false;
		foreach ( $items as $it ) {
			if ( ! empty( $it['s']['manual_assign'] ) ) {
				$manual = true;
			}
		}

		// Date: from 75 days ago to 14 days ahead.
		$tz    = new DateTimeZone( MNS_Time::TZ );
		$today = new DateTimeImmutable( 'today', $tz );
		$off   = mt_rand( -75, 14 );
		$day   = $today->modify( ( $off >= 0 ? '+' : '' ) . $off . ' days' );
		$date  = $day->format( 'Y-m-d' );
		$open  = self::open_slots();
		$slot  = $open[ mt_rand( 0, count( $open ) - 1 ) ];
		[ $sh, $eh ] = array_map( 'intval', explode( '-', $slot ) + array( 0, 0 ) );
		$gender = 'female' === (string) $pat['gender'] ? ( mt_rand( 0, 9 ) < 8 ? 'female' : 'male' ) : ( mt_rand( 0, 9 ) < 5 ? 'male' : 'female' );
		$district = mt_rand( 1, 22 );

		// Status by timing.
		$status = 'submitted';
		$roll   = mt_rand( 1, 100 );
		if ( $off > 0 ) {
			$status = $roll <= 30 ? 'submitted' : ( $roll <= 92 ? 'assigned' : 'cancelled' );
		} elseif ( 0 === $off ) {
			$status = $roll <= 40 ? 'assigned' : ( $roll <= 85 ? 'in_progress' : 'submitted' );
		} else {
			if ( $roll <= 58 ) {
				$status = 'completed';
			} elseif ( $roll <= 68 ) {
				$status = 'paid';
			} elseif ( $roll <= 80 ) {
				$status = 'invoiced';
			} elseif ( $roll <= 85 ) {
				$status = 'needs_review';
			} elseif ( $roll <= 88 ) {
				$status = 'service_ended';
			} else {
				$status = 'cancelled';
			}
		}
		$cancelled_after_assign = ( 'cancelled' === $status && mt_rand( 0, 1 ) === 1 );
		$needs_nurse            = in_array( $status, array( 'assigned', 'in_progress', 'service_ended', 'invoiced', 'paid', 'completed', 'needs_review' ), true ) || $cancelled_after_assign;
		$nurse_id               = 0;
		if ( $needs_nurse ) {
			$list = ! empty( $nurses[ $gender ] ) ? $nurses[ $gender ] : array_merge( $nurses['female'], $nurses['male'] );
			if ( empty( $list ) ) {
				$status   = 'submitted';
				$nurse_id = 0;
			} else {
				$nurse_id = (int) $list[ mt_rand( 0, count( $list ) - 1 ) ];
			}
		}
		if ( 'submitted' === $status && $manual ) {
			$status = 'submitted';
		}

		$utc = static function ( DateTimeImmutable $d, int $hour, int $min = 0 ): string {
			return $d->setTime( $hour, $min )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		};
		$created = $utc( $day->modify( '-' . mt_rand( 1, 4 ) . ' days' ), mt_rand( 8, 21 ), mt_rand( 0, 59 ) );
		if ( $off > 0 ) {
			$created = $utc( $today->modify( '-' . mt_rand( 0, 2 ) . ' days' ), mt_rand( 8, 21 ), mt_rand( 0, 59 ) );
		}
		$started   = null;
		$ended     = null;
		$duration  = null;
		$completed = null;
		$cancelled = null;
		$steps     = array( 'checklist' => 'none', 'drug' => 'na', 'report' => 'none' );
		if ( in_array( $status, array( 'in_progress', 'service_ended', 'invoiced', 'paid', 'completed', 'needs_review' ), true ) ) {
			$started = $utc( $day, max( 0, $sh ), mt_rand( 0, 50 ) );
			$steps   = array( 'checklist' => 'draft', 'drug' => 'na', 'report' => 'none' );
		}
		if ( in_array( $status, array( 'service_ended', 'invoiced', 'paid', 'completed', 'needs_review' ), true ) ) {
			$duration = mt_rand( 25, 80 );
			$ended    = gmdate( 'Y-m-d H:i:s', strtotime( (string) $started . ' UTC' ) + $duration * 60 );
			$steps    = array( 'checklist' => 'done', 'drug' => 'na', 'report' => 'done' );
		}
		if ( 'completed' === $status ) {
			$completed = gmdate( 'Y-m-d H:i:s', strtotime( (string) $ended . ' UTC' ) + mt_rand( 600, 5400 ) );
		}
		if ( 'cancelled' === $status ) {
			$cancelled = $utc( $day->modify( '-1 day' ), mt_rand( 8, 20 ), mt_rand( 0, 59 ) );
		}
		$assigned_at = $nurse_id > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( $created . ' UTC' ) + mt_rand( 600, 7200 ) ) : null;

		$notes = self::pick( array( '', '', '', 'لطفاً قبل از آمدن تماس بگیرید.', 'زنگ در خراب است، تماس بگیرید.', 'بیمار سالمند است و حرکت محدود دارد.', 'پارکینگ ساختمان طبقهٔ منفی یک است.', 'آسانسور ندارد، طبقهٔ سوم.', 'بیمار به پنی‌سیلین حساسیت دارد.' ) );
		$ok    = $wpdb->insert(
			MNS_DB::table( 'orders' ),
			array(
				'code'                    => self::unique_code(),
				'account_user_id'         => $account,
				'patient_id'              => (int) $pat['id'],
				'service_id'              => (int) $primary['id'],
				'source'                  => mt_rand( 0, 9 ) < 8 ? 'site' : 'admin',
				'status'                  => $status,
				'review_flag'             => 'needs_review' === $status ? 1 : 0,
				'review_reason'           => 'needs_review' === $status ? 'overtime' : null,
				'urgent_flag'             => mt_rand( 0, 11 ) === 0 ? 1 : 0,
				'province_code'           => 'THR',
				'city_id'                 => $thr,
				'district_no'             => $district,
				'address'                 => self::address( $district ),
				'contact_phone'           => (string) get_user_meta( $account, 'mobile', true ),
				'service_date'            => $date,
				'slot_id'                 => $slot,
				'gender_pref'             => $gender,
				'nurse_user_id'           => $nurse_id > 0 ? $nurse_id : null,
				'assigned_at'             => $assigned_at,
				'assignment_mode'         => $nurse_id > 0 ? ( mt_rand( 0, 1 ) ? 'auto' : 'manual' ) : null,
				'essentials'              => wp_json_encode( array( 'allergy' => 'no', 'vein_access' => 'normal' ) ),
				'notes'                   => $notes,
				'started_at'              => $started,
				'ended_at'                => $ended,
				'duration_minutes'        => $duration,
				'completed_at'            => $completed,
				'cancelled_at'            => $cancelled,
				'cancelled_by'            => 'cancelled' === $status ? ( mt_rand( 0, 1 ) ? 'patient' : 'admin' ) : null,
				'cancel_reason'           => 'cancelled' === $status ? self::pick( array( 'تغییر برنامهٔ بیمار', 'بیمار به بیمارستان منتقل شد', 'ثبت تکراری', 'هماهنگی زمان ممکن نشد' ) ) : null,
				'cancel_counts_for_nurse' => 0,
				'steps'                   => wp_json_encode( $steps ),
				'version'                 => 1,
				'created_at'              => $created,
				'updated_at'              => $completed ?: ( $ended ?: ( $cancelled ?: ( $assigned_at ?: $created ) ) ),
			)
		);
		if ( false === $ok ) {
			return;
		}
		$order_id = (int) $wpdb->insert_id;

		// Line items and the amount.
		$total = 0;
		$now   = MNS_Time::now_utc();
		foreach ( $items as $it ) {
			$s = $it['s'];
			$wpdb->insert(
				MNS_DB::table( 'order_items' ),
				array(
					'order_id'   => $order_id,
					'service_id' => (int) $s['id'],
					'is_extra'   => (int) $it['extra'],
					'price_from' => (int) $s['price_from'],
					'price_to'   => (int) $s['price_to'],
					'created_at' => $created,
				)
			);
			$total += (int) round( ( (int) $s['price_from'] + (int) $s['price_to'] ) / 2 / 10000 ) * 10000;
		}

		// Timeline.
		$log = array( array( 'create', '', 'submitted', $created ) );
		if ( $nurse_id > 0 ) {
			$log[] = array( 'assign', 'submitted', 'assigned', (string) $assigned_at );
		}
		if ( null !== $started ) {
			$log[] = array( 'start', 'assigned', 'in_progress', $started );
		}
		if ( null !== $ended ) {
			$log[] = array( 'end', 'in_progress', 'service_ended', $ended );
		}
		if ( 'cancelled' === $status ) {
			$log[] = array( 'cancel', $nurse_id > 0 ? 'assigned' : 'submitted', 'cancelled', (string) $cancelled );
		}

		// Invoice + payment.
		$invoice_id = 0;
		if ( in_array( $status, array( 'invoiced', 'paid', 'completed' ), true ) && $total > 0 ) {
			$share_pct = (float) MNS_Settings::get( 'nurse_share_percent' );
			if ( $share_pct <= 0 ) {
				$share_pct = 90;
			}
			$issued = gmdate( 'Y-m-d H:i:s', strtotime( (string) $ended . ' UTC' ) + 900 );
			$paid   = in_array( $status, array( 'paid', 'completed' ), true );
			$meta   = array( 'items' => array() );
			foreach ( $items as $it ) {
				$meta['items'][] = array(
					'service_id' => (int) $it['s']['id'],
					'name'       => (string) $it['s']['name'],
					'is_extra'   => ! empty( $it['extra'] ),
					'price_from' => (int) $it['s']['price_from'],
					'price_to'   => (int) $it['s']['price_to'],
				);
			}
			$wpdb->insert(
				MNS_DB::table( 'invoices' ),
				array(
					'order_id'        => $order_id,
					'account_user_id' => $account,
					'nurse_user_id'   => $nurse_id > 0 ? $nurse_id : null,
					'number'          => 'INV-' . self::order_code( $order_id ),
					'status'          => $paid ? 'paid' : 'unpaid',
					'amount'          => $total,
					'nurse_share'     => (int) round( $total * ( $share_pct / 100 ) ),
					'issued_at'       => $issued,
					'paid_at'         => $paid ? gmdate( 'Y-m-d H:i:s', strtotime( $issued . ' UTC' ) + mt_rand( 300, 86400 ) ) : null,
					'meta'            => wp_json_encode( $meta ),
					'created_at'      => $issued,
					'updated_at'      => $issued,
				)
			);
			$invoice_id = (int) $wpdb->insert_id;
			$log[]      = array( 'invoice', 'service_ended', 'invoiced', $issued );
			if ( $paid && $invoice_id > 0 ) {
				$method = mt_rand( 0, 9 ) < 7 ? 'zarinpal' : 'manual';
				$wpdb->insert(
					MNS_DB::table( 'payments' ),
					array(
						'invoice_id'    => $invoice_id,
						'method'        => $method,
						'amount'        => $total,
						'tracking'      => 'zarinpal' === $method ? (string) mt_rand( 100000000, 999999999 ) : 'DEMO-' . $order_id,
						'actor_user_id' => 0,
						'created_at'    => $issued,
					)
				);
				$log[] = array( 'pay', 'invoiced', 'paid', $issued );
			}
			if ( 'completed' === $status ) {
				$log[] = array( 'complete', 'paid', 'completed', (string) $completed );
			}
		}
		foreach ( $log as $l ) {
			$wpdb->insert(
				MNS_DB::table( 'order_log' ),
				array(
					'order_id'      => $order_id,
					'actor_user_id' => 0,
					'actor_role'    => 'system',
					'action'        => $l[0],
					'from_status'   => '' === $l[1] ? null : $l[1],
					'to_status'     => $l[2],
					'payload'       => wp_json_encode( array( 'demo' => 1 ) ),
					'created_at'    => $l[3],
				)
			);
		}
		unset( $eh, $now );
	}

	/**
	 * Delete the demo patients with their orders, invoices and payments.
	 *
	 * @return array{nurses:int,patients:int,orders:int}
	 */
	public static function delete_orders( int $batch ): array {
		global $wpdb;
		self::load_user_api();
		$ids = array_slice( self::user_ids( 'patient' ), 0, max( 1, min( $batch, 3 ) ) );
		foreach ( $ids as $uid ) {
			$oids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MNS_DB::table( 'orders' ) . ' WHERE account_user_id = %d', $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $oids ) ) {
				foreach ( $oids as $oid ) {
					$wpdb->delete( MNS_DB::table( 'order_items' ), array( 'order_id' => (int) $oid ), array( '%d' ) );
				}
			}
			MNS_Selftest::purge_user_rows( (int) $uid );
			wp_delete_user( (int) $uid );
		}
		return self::counts();
	}

	/* ------------------------------------------------------------------ helpers */

	private static function load_user_api(): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
	}

	/** @return array<int,string> */
	private static function open_slots(): array {
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
		return empty( $open ) ? array( '8-12' ) : $open;
	}

	private static function order_code( int $order_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT code FROM ' . MNS_DB::table( 'orders' ) . ' WHERE id = %d', $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function unique_code(): string {
		global $wpdb;
		$digits = (int) MNS_Settings::get( 'order_code_digits' );
		if ( $digits < 5 ) {
			$digits = 5;
		}
		$min = (int) str_pad( '1', $digits, '0' );
		$max = (int) str_repeat( '9', $digits );
		for ( $i = 0; $i < 30; $i++ ) {
			$code = (string) random_int( $min, $max );
			$hit  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MNS_DB::table( 'orders' ) . ' WHERE code = %s LIMIT 1', $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $hit ) {
				return $code;
			}
		}
		throw MNS_Error::server( 'کد سفارش یکتا ساخته نشد.' );
	}

	/** @param array<int,string> $list List. */
	private static function pick( array $list ): string {
		return (string) $list[ mt_rand( 0, count( $list ) - 1 ) ];
	}

	private static function national_id( int $seed ): string {
		$base = str_pad( (string) abs( $seed % 1000000000 ), 9, '0', STR_PAD_LEFT );
		$sum  = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $base[ $i ] * ( 10 - $i );
		}
		$r = $sum % 11;
		return $base . (string) ( $r < 2 ? $r : 11 - $r );
	}

	private static function address( int $district ): string {
		$streets = array( 'ولیعصر', 'شریعتی', 'مطهری', 'پاسداران', 'گاندی', 'سعادت‌آباد', 'ستارخان', 'انقلاب', 'کریمخان', 'جردن', 'دماوند', 'آزادی', 'نیاوران', 'فرمانیه', 'شهرک غرب', 'امام‌حسین', 'قیطریه', 'سید خندان', 'تهرانپارس', 'ایران‌زمین' );
		$alleys  = array( 'شهید احمدی', 'گلها', 'نسترن', 'بهار', 'یاس', 'شقایق', 'ارغوان', 'مهر', 'سپیدار', 'کوثر' );
		return sprintf(
			'تهران، منطقه %s، خیابان %s، کوچهٔ %s، پلاک %s، واحد %s',
			self::fa( $district ),
			self::pick( $streets ),
			self::pick( $alleys ),
			self::fa( mt_rand( 2, 120 ) ),
			self::fa( mt_rand( 1, 12 ) )
		);
	}

	private static function fa( int $n ): string {
		return strtr( (string) $n, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}

	/** @return array<int,string> */
	private static function female_first(): array {
		return array( 'زهرا', 'فاطمه', 'مریم', 'سمیه', 'سارا', 'نرگس', 'الهام', 'مینا', 'زینب', 'لیلا', 'پروین', 'شیرین', 'فرزانه', 'مهسا', 'نازنین', 'آزاده', 'سمیرا', 'معصومه', 'رقیه', 'عاطفه', 'مرضیه', 'فریبا', 'ناهید', 'شبنم', 'پریسا', 'شیدا', 'یاسمن', 'المیرا', 'سپیده', 'هانیه', 'مهشید', 'طاهره', 'نسرین', 'آیدا', 'بهاره', 'الناز', 'ندا', 'سحر', 'کتایون', 'محبوبه' );
	}

	/** @return array<int,string> */
	private static function male_first(): array {
		return array( 'علی', 'محمد', 'حسین', 'رضا', 'مهدی', 'امیر', 'حمید', 'سعید', 'مجتبی', 'پوریا', 'کامران', 'بهروز', 'احسان', 'مسعود', 'وحید', 'ناصر', 'آرش', 'سینا', 'کیان', 'بابک' );
	}

	/** @return array<int,string> */
	private static function last_names(): array {
		return array( 'محمدی', 'حسینی', 'رضایی', 'احمدی', 'کریمی', 'موسوی', 'جعفری', 'صادقی', 'حیدری', 'قاسمی', 'نوری', 'رحیمی', 'کاظمی', 'عباسی', 'علوی', 'فتحی', 'یزدانی', 'مرادی', 'اکبری', 'بابایی', 'شریفی', 'زارعی', 'طاهری', 'ملکی', 'نجفی', 'رستمی', 'خسروی', 'سلطانی', 'امینی', 'پناهی', 'هاشمی', 'میرزایی', 'قنبری', 'دهقان', 'توکلی', 'ابراهیمی', 'غلامی', 'سلیمانی', 'رضوی', 'تقوی' );
	}
}
