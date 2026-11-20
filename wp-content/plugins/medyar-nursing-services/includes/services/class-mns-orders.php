<?php
/**
 * Patient order create orchestration (T-3.4): slot lock + validation + machine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Orders {

	/**
	 * Create order from API body.
	 *
	 * @param array<string,mixed> $params Params.
	 * @return array<string,mixed> {order, next}
	 */
	public static function create_from_request( int $account_user_id, array $params ): array {
		$patient_id = isset( $params['patient_id'] ) ? (int) $params['patient_id'] : 0;
		$service_id = isset( $params['service_id'] ) ? (int) $params['service_id'] : 0;
		if ( ! $service_id && ! empty( $params['service'] ) ) {
			$svc = MNS_Services::get( (string) $params['service'] );
			$service_id = $svc ? (int) $svc['id'] : 0;
		}
		$raw_items = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
		if ( empty( $raw_items ) && $service_id > 0 ) {
			$raw_items = array( array( 'service_id' => $service_id, 'is_extra' => 0 ) );
		}
		$pack = MNS_Order_Items::validate_items( $raw_items );
		$svc  = $pack['primary'];
		$service_id = (int) $svc['id'];
		$service_ids = array();
		foreach ( $pack['rows'] as $r ) {
			$service_ids[] = (int) $r['service']['id'];
		}
		$manual_assign = ! empty( $pack['manual_assign'] );

		$prov     = isset( $params['prov'] ) ? strtoupper( sanitize_text_field( (string) $params['prov'] ) ) : '';
		$city_id  = isset( $params['city_id'] ) ? (int) $params['city_id'] : 0;
		$district = isset( $params['district'] ) ? (int) $params['district'] : ( isset( $params['district_no'] ) ? (int) $params['district_no'] : 0 );
		$address  = isset( $params['address'] ) ? sanitize_textarea_field( (string) $params['address'] ) : '';
		$address  = function_exists( 'mb_substr' ) ? mb_substr( $address, 0, 500 ) : substr( $address, 0, 500 );
		$phone    = isset( $params['contact_phone'] ) ? sanitize_text_field( (string) $params['contact_phone'] ) : '';
		$date     = isset( $params['date'] ) ? sanitize_text_field( (string) $params['date'] ) : ( isset( $params['service_date'] ) ? sanitize_text_field( (string) $params['service_date'] ) : '' );
		$slot     = isset( $params['slot'] ) ? sanitize_text_field( (string) $params['slot'] ) : ( isset( $params['slot_id'] ) ? sanitize_text_field( (string) $params['slot_id'] ) : '' );
		$gender   = isset( $params['gender'] ) ? sanitize_key( (string) $params['gender'] ) : ( isset( $params['gender_pref'] ) ? sanitize_key( (string) $params['gender_pref'] ) : '' );
		$essentials = isset( $params['essentials'] ) && is_array( $params['essentials'] ) ? $params['essentials'] : array();
		$notes    = isset( $params['notes'] ) ? sanitize_textarea_field( (string) $params['notes'] ) : '';
		$notes    = function_exists( 'mb_substr' ) ? mb_substr( $notes, 0, 500 ) : substr( $notes, 0, 500 );

		MNS_Patients::get_owned( $patient_id, $account_user_id );
		if ( ! $svc || empty( $svc['active'] ) ) {
			throw MNS_Error::validation( 'خدمت نامعتبر است.', 'service_id' );
		}

		// Time validation (same as A-13 API layer). Manual-assign: date/slot optional; placeholders for DB.
		$today   = MNS_Time::today_tehran();
		$horizon = (int) MNS_Settings::get( 'order_horizon' );
		if ( $horizon < 1 ) {
			$horizon = 30;
		}
		$tz = new DateTimeZone( MNS_Time::TZ );
		if ( $manual_assign ) {
			if ( '' === $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$date = $today;
			}
			if ( '' === $slot || ! MNS_Time::slot_known( $slot ) ) {
				$slots_cfg = MNS_Settings::get( 'slots' );
				if ( ! is_array( $slots_cfg ) ) {
					$slots_cfg = MNS_Settings::default_slots();
				}
				$slot = ! empty( $slots_cfg[0]['id'] ) ? (string) $slots_cfg[0]['id'] : '8-12';
			}
			if ( ! in_array( $gender, array( 'female', 'male' ), true ) ) {
				$gender = 'female';
			}
			$shift_note = isset( $params['shift_note'] ) ? sanitize_textarea_field( (string) $params['shift_note'] ) : '';
			$shift_note = function_exists( 'mb_substr' ) ? mb_substr( $shift_note, 0, 500 ) : substr( $shift_note, 0, 500 );
			if ( '' !== $shift_note ) {
				$notes = trim( $notes . "\n" . 'مدت/شیفت: ' . $shift_note );
				$notes = function_exists( 'mb_substr' ) ? mb_substr( $notes, 0, 500 ) : substr( $notes, 0, 500 );
			}
		} else {
			$d = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $tz );
			$t = DateTimeImmutable::createFromFormat( 'Y-m-d', $today, $tz );
			if ( ! $d || ! $t || $d->format( 'Y-m-d' ) !== $date || $d < $t || $d > $t->modify( '+' . $horizon . ' days' ) ) {
				throw MNS_Error::validation( 'تاریخ خارج از بازهٔ مجاز است.', 'date' );
			}
			if ( ! MNS_Time::slot_known( $slot ) ) {
				throw MNS_Error::validation( 'بازهٔ زمانی نامعتبر است.', 'slot' );
			}
			if ( ! in_array( $gender, array( 'female', 'male' ), true ) ) {
				throw MNS_Error::validation( 'جنسیت پرستار را انتخاب کنید.', 'gender' );
			}
			if ( MNS_Time::slot_closed( $slot ) ) {
				throw MNS_Error::validation( 'این بازه بسته است.', 'slot' );
			}
			if ( $date === $today && MNS_Time::slot_start_ts( $date, $slot ) < time() ) {
				throw MNS_Error::validation( 'شروع این بازه گذشته است.', 'slot' );
			}
		}
		// Supervisor fixes: reject unknown cities, require an address, never trust client essentials.
		if ( ! preg_match( '/^[A-Z]{3}$/', $prov ) || $city_id <= 0 || ! MNS_Locations::is_valid_city( $prov, $city_id ) ) {
			throw MNS_Error::validation( 'شهر نامعتبر است.', 'city_id' );
		}
		if ( '' === trim( $address ) ) {
			throw MNS_Error::validation( 'نشانی الزامی است.', 'address' );
		}
		$essentials = self::clean_essentials( $essentials, $account_user_id );

		$account_phone = '';
		if ( class_exists( '\DrPlus\Utils\User' ) && is_callable( array( '\DrPlus\Utils\User', 'get_phone' ) ) ) {
			$account_phone = (string) \DrPlus\Utils\User::get_phone( $account_user_id );
		}
		$norm = static function ( string $p ): string {
			$p = preg_replace( '/\D+/', '', $p );
			if ( ! is_string( $p ) ) {
				return '';
			}
			if ( 0 === strpos( $p, '98' ) && strlen( $p ) >= 12 ) {
				$p = '0' . substr( $p, 2 );
			}
			return $p;
		};
		if ( '' !== $phone ) {
			$phone = $norm( $phone );
			if ( ! preg_match( '/^09\d{9}$/', $phone ) ) {
				throw MNS_Error::validation( 'شمارهٔ موبایل نامعتبر است.', 'contact_phone' );
			}
		}
		if ( '' !== $phone && '' !== $account_phone && $norm( $phone ) !== $norm( $account_phone ) ) {
			throw MNS_Error::conflict( 'ERR_OTP_REQUIRED', 'برای شمارهٔ تماس متفاوت تأیید پیامکی لازم است.' );
		}
		if ( '' === $phone ) {
			$phone = $account_phone;
		}

		self::validate_essentials( $svc, $essentials );
		if ( ! empty( $pack['requires_rx'] ) && empty( $essentials['rx_file_id'] ) ) {
			throw MNS_Error::validation( 'تصویر نسخه الزامی است.', 'rx' );
		}

		$lock_name = 'mns_slot_' . $date . '_' . $slot;
		$lock_ok   = false;
		global $wpdb;
		try {
			$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
			$lock_ok = ( '1' === (string) $got || 1 === (int) $got );
			if ( ! $lock_ok ) {
				throw MNS_Error::conflict( 'ERR_SLOT_TAKEN', 'همین حالا این زمان پر شد.' );
			}

			$scope = array(
				'service_id'    => (int) $svc['id'],
				'service_ids'   => $service_ids,
				'province_code' => $prov,
				'city_id'       => $city_id,
				'district_no'   => $district,
				'service_date'  => $date,
				'slot_id'       => $slot,
				'gender_pref'   => $gender,
			);
			if ( ! $manual_assign ) {
				$check = MNS_Availability::check( $scope );
				if ( 'green' !== $check['state'] ) {
					if ( 'red' === $check['state'] ) {
						throw MNS_Error::conflict( 'ERR_NO_NURSE', 'برای این زمان پرستار موجود نیست.' );
					}
					throw MNS_Error::conflict( 'ERR_NO_NURSE', 'همین حالا این زمان پر شد یا پرستاری موجود نیست.' );
				}
			}

			$order = MNS_Order_Machine::transition(
				'create',
				array(
					'account_user_id' => $account_user_id,
					'patient_id'      => $patient_id,
					'service_id'      => (int) $svc['id'],
					'province_code'   => $prov,
					'city_id'         => $city_id,
					'district_no'     => $district,
					'address'         => $address,
					'contact_phone'   => $phone,
					'service_date'    => $date,
					'slot_id'         => $slot,
					'gender_pref'     => $gender,
					'essentials'      => $essentials,
					'notes'           => $notes,
					'actor_user_id'   => $account_user_id,
					'actor_role'      => 'patient',
					'source'          => 'site',
					// Supervisor fix: manual-assign services (hourly/shift/other) are coordinated by support, so the
					// machine must not re-run A-13 for them (it demanded a green nurse and rejected the order).
					'skip_a13'        => $manual_assign,
				)
			);

			MNS_Order_Items::insert_for_order( (int) $order['id'], $pack['rows'] );

			$mode = (string) MNS_Settings::get( 'assignment_mode' );
			if ( ! $manual_assign && 'auto' === $mode ) {
				try {
					$order = MNS_Assignment::auto_assign( (int) $order['id'], $account_user_id );
				} catch ( MNS_Error $e ) {
					if ( 'ERR_SLOT_TAKEN' === $e->error_code() || 'ERR_NO_NURSE' === $e->error_code() ) {
						// keep submitted
					} else {
						throw $e;
					}
				}
			}

			// T-C.2: link prescription file so orphan cron will not delete it.
			if ( ! empty( $essentials['rx_file_id'] ) ) {
				MNS_Files::attach_order( (int) $essentials['rx_file_id'], (int) $order['id'], $account_user_id );
			}

			return array(
				'order' => self::public_order_summary( $order ),
				'next'  => array(
					'track_url' => add_query_arg( 'code', $order['code'], MNS_Pages::panel_url( 'patient', 'order-view' ) ),
				),
			);
		} finally {
			if ( $lock_ok ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}

	/**
	 * Sanitize client essentials (flat scalars only, size capped) and verify the prescription file belongs to the account.
	 *
	 * @param array<string,mixed> $essentials Raw essentials.
	 * @return array<string,mixed>
	 */
	private static function clean_essentials( array $essentials, int $account_user_id ): array {
		$out = array();
		foreach ( $essentials as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( '' === $key || ! is_scalar( $v ) || count( $out ) >= 20 ) {
				continue;
			}
			$out[ $key ] = mb_substr( sanitize_text_field( (string) $v ), 0, 200 );
		}
		if ( ! empty( $out['rx_file_id'] ) ) {
			$file = MNS_Files::get( (int) $out['rx_file_id'] );
			if ( ! $file || (int) $file['owner_user_id'] !== $account_user_id ) {
				throw MNS_Error::validation( 'فایل نسخه نامعتبر است.', 'rx' );
			}
			$out['rx_file_id'] = (string) (int) $file['id'];
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $svc        Service.
	 * @param array<string,mixed> $essentials Essentials.
	 */
	public static function validate_essentials( array $svc, array $essentials ): void {
		$fields = isset( $svc['config']['order_fields'] ) && is_array( $svc['config']['order_fields'] ) ? $svc['config']['order_fields'] : array();
		if ( empty( $fields ) ) {
			return;
		}
		$serum_types = isset( $fields['serum_types'] ) && is_array( $fields['serum_types'] ) ? $fields['serum_types'] : array();
		$rx_types    = isset( $fields['rx_types'] ) && is_array( $fields['rx_types'] ) ? $fields['rx_types'] : array();
		if ( ! empty( $serum_types ) ) {
			$type = isset( $essentials['serum_type'] ) ? sanitize_text_field( (string) $essentials['serum_type'] ) : '';
			if ( '' === $type || ! in_array( $type, $serum_types, true ) ) {
				throw MNS_Error::validation( 'نوع سرم را انتخاب کنید.', 'serum_type' );
			}
			if ( in_array( $type, $rx_types, true ) && empty( $essentials['rx_file_id'] ) ) {
				throw MNS_Error::validation( 'تصویر نسخه الزامی است.', 'rx' );
			}
		}
		$allergy = isset( $essentials['allergy'] ) ? sanitize_key( (string) $essentials['allergy'] ) : '';
		if ( 'yes' === $allergy ) {
			$name = isset( $essentials['allergy_name'] ) ? sanitize_text_field( (string) $essentials['allergy_name'] ) : '';
			if ( '' === $name ) {
				throw MNS_Error::validation( 'نام ماده حساسیت‌زا را بنویسید.', 'allergy_name' );
			}
		}
	}

	/**
	 * @param array<string,mixed> $order Order row.
	 * @return array<string,mixed>
	 */
	public static function public_order_summary( array $order ): array {
		$svc = MNS_Services::get( (int) $order['service_id'] );
		$pat = null;
		try {
			$pat = MNS_Patients::get_owned( (int) $order['patient_id'], (int) $order['account_user_id'] );
		} catch ( MNS_Error $e ) {
			$pat = null;
		}
		$nurse = null;
		if ( ! empty( $order['nurse_user_id'] ) ) {
			$u = get_userdata( (int) $order['nurse_user_id'] );
			$nurse = $u ? array( 'name' => $u->display_name ) : null;
		}
		$items     = class_exists( 'MNS_Order_Items', true ) ? MNS_Order_Items::for_order( (int) $order['id'] ) : array();
		$names     = array();
		$price_min = 0;
		$price_max = 0;
		foreach ( $items as $it ) {
			$names[]    = (string) $it['name'] . ( ! empty( $it['is_extra'] ) ? ' (اضافه)' : '' );
			$price_min += (int) $it['price_from'];
			$price_max += (int) $it['price_to'];
		}
		if ( empty( $names ) && $svc ) {
			$names[]    = (string) $svc['name'];
			$price_min  = (int) $svc['price_from'];
			$price_max  = (int) $svc['price_to'];
		}
		return array(
			'code'          => (string) $order['code'],
			'status'        => (string) $order['status'],
			'service'       => $svc ? $svc['name'] : '',
			'services'      => $names,
			'items'         => $items,
			'patient'       => $pat ? $pat['name'] : '',
			'when'          => (string) $order['service_date'] . ' ' . (string) $order['slot_id'],
			'address_short' => mb_substr( (string) $order['address'], 0, 40 ),
			'price_min'     => $price_min,
			'price_max'     => $price_max,
			'nurse'         => $nurse,
		);
	}
}
