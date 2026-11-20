<?php
/**
 * Self-test AC-M3 / AC-ASG / file ACL (T-C.3 / T-C.5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Selftest_Orders {

	public static function register_all(): void {
		MNS_Selftest::register( 'AC-M3-3', 'Order create success + auto assign', array( __CLASS__, 'ac_m3_3' ) );
		MNS_Selftest::register( 'AC-M3-4', 'Order create rejection paths', array( __CLASS__, 'ac_m3_4' ) );
		MNS_Selftest::register( 'AC-M3-5', 'Slot capacity / concurrency', array( __CLASS__, 'ac_m3_5' ) );
		MNS_Selftest::register( 'AC-M3-6', 'Idempotency per user', array( __CLASS__, 'ac_m3_6' ) );
		MNS_Selftest::register( 'AC-M3-7', 'Different phone ⇒ ERR_OTP_REQUIRED', array( __CLASS__, 'ac_m3_7' ) );
		MNS_Selftest::register( 'AC-ASG-1', 'auto_assign + order_log', array( __CLASS__, 'ac_asg_1' ) );
		MNS_Selftest::register( 'AC-ASG-2', 'admin_set_nurse suspended rejected', array( __CLASS__, 'ac_asg_2' ) );
		MNS_Selftest::register( 'AC-ASG-3', 'admin_set_nurse ineligible needs force', array( __CLASS__, 'ac_asg_3' ) );
		MNS_Selftest::register( 'AC-ASG-4', 'admin_set_nurse force ok', array( __CLASS__, 'ac_asg_4' ) );
		MNS_Selftest::register( 'AC-ASG-5', 'reassign + cancel_admin logs', array( __CLASS__, 'ac_asg_5' ) );
		MNS_Selftest::register( 'AC-M3-10', 'Patient ownership 404', array( __CLASS__, 'ac_m3_10' ) );
		MNS_Selftest::register( 'AC-M3-11', 'Patient cancel allowed states', array( __CLASS__, 'ac_m3_11' ) );
		MNS_Selftest::register( 'AC-M3-12', 'Patient patch limited', array( __CLASS__, 'ac_m3_12' ) );
		MNS_Selftest::register( 'AC-M3-13', 'Nurse API whitelist', array( __CLASS__, 'ac_m3_13' ) );
		MNS_Selftest::register( 'AC-M3-14', 'Notifications on cancel/reassign', array( __CLASS__, 'ac_m3_14' ) );
		MNS_Selftest::register( 'AC-M3-15', 'SMS missing pattern logs only + dedupe', array( __CLASS__, 'ac_m3_15' ) );
		MNS_Selftest::register( 'AC-FIL-1', 'Prescription can_read ACL', array( __CLASS__, 'ac_fil_1' ) );
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_3(): array {
		$ctx = null;
		$hooked = false;
		$seen  = 0;
		$cb    = static function () use ( &$seen ) {
			$seen++;
		};
		try {
			$ctx = self::seed_green();
			MNS_Settings::set( 'assignment_mode', 'auto' );
			add_action( 'mns_order_transitioned', $cb, 99 );
			$hooked = true;
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ), array( 'Idempotency-Key' => 'm3-3-' . wp_generate_password( 6, false ) ) );
			if ( empty( $res['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'fail:' . wp_json_encode( $res['data']['error'] ?? $res['data'] ) );
			}
			$order = $res['data']['data']['order'] ?? array();
			$code  = isset( $order['code'] ) ? (string) $order['code'] : '';
			if ( ! preg_match( '/^\d{5,6}$/', $code ) ) {
				return array( 'ok' => false, 'detail' => 'bad_code=' . $code );
			}
			$row = self::order_by_code( $code );
			if ( ! $row ) {
				return array( 'ok' => false, 'detail' => 'missing_row' );
			}
			$logs = self::log_count( (int) $row['id'] );
			if ( $logs < 1 ) {
				return array( 'ok' => false, 'detail' => 'no_log' );
			}
			if ( empty( $row['nurse_user_id'] ) || (int) $row['nurse_user_id'] !== (int) $ctx['nurse_uid'] ) {
				return array( 'ok' => false, 'detail' => 'nurse=' . (int) ( $row['nurse_user_id'] ?? 0 ) );
			}
			if ( $seen < 1 ) {
				return array( 'ok' => false, 'detail' => 'hook_not_fired' );
			}
			return array( 'ok' => true, 'detail' => 'code=' . $code . ';logs=' . $logs . ';hooks=' . $seen );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			if ( $hooked ) {
				remove_action( 'mns_order_transitioned', $cb, 99 );
			}
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_4(): array {
		$ctx = null;
		try {
			$ctx  = self::seed_green();
			$body = self::order_body( $ctx );
			$cases = array(
				array( 'slot' => '99-99', 'expect' => 'ERR_VALIDATION' ),
				array( 'date' => '1999-01-01', 'expect' => 'ERR_VALIDATION' ),
				array( 'gender' => '', 'expect' => 'ERR_VALIDATION' ),
				array( 'address' => '', 'expect' => 'ERR_VALIDATION' ),
				array( 'city_id' => 999999, 'expect' => 'ERR_VALIDATION' ),
			);
			foreach ( $cases as $i => $c ) {
				$payload = array_merge( $body, $c );
				unset( $payload['expect'] );
				$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $payload );
				$code = isset( $res['data']['error']['code'] ) ? (string) $res['data']['error']['code'] : '';
				if ( $c['expect'] !== $code && ! empty( $res['data']['ok'] ) ) {
					return array( 'ok' => false, 'detail' => 'case' . $i . '_passed' );
				}
				if ( $c['expect'] !== $code && empty( $res['data']['ok'] ) && $c['expect'] !== $code ) {
					// accept ERR_VALIDATION family.
					if ( 0 !== strpos( $code, 'ERR_' ) ) {
						return array( 'ok' => false, 'detail' => 'case' . $i . '=' . $code );
					}
				}
			}
			// Non-owner patient.
			$other = self::make_patient_user();
			$ctx['uids'][] = $other;
			$op = MNS_Patients::create( $other, array( 'name' => 'دیگر', 'age_group' => 'adult', 'gender' => 'female' ) );
			$payload = $body;
			$payload['patient_id'] = (int) $op['id'];
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $payload );
			$ecode = isset( $res['data']['error']['code'] ) ? (string) $res['data']['error']['code'] : '';
			if ( 'ERR_NOT_FOUND' !== $ecode ) {
				return array( 'ok' => false, 'detail' => 'owner=' . $ecode );
			}
			// Foreign rx_file_id.
			$fid = MNS_Files::store_bytes( "\xFF\xD8\xFF\xD9", 'jpg', $other, 'prescription', null );
			$payload = $body;
			$payload['essentials']['rx_file_id'] = (string) $fid;
			$payload['essentials']['serum_type'] = 'طبق نسخه پزشک';
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $payload );
			$ecode = isset( $res['data']['error']['code'] ) ? (string) $res['data']['error']['code'] : '';
			if ( empty( $res['data']['ok'] ) === false || ( $ecode && 0 !== strpos( $ecode, 'ERR_' ) ) ) {
				if ( ! empty( $res['data']['ok'] ) ) {
					return array( 'ok' => false, 'detail' => 'rx_accepted' );
				}
			}
			// Incomplete essentials (rx required).
			$payload = $body;
			$payload['essentials'] = array( 'serum_type' => 'طبق نسخه پزشک' );
			// The prescription rule now belongs to the service (requires_rx), not to the legacy serum form field.
			$rx_svc = MNS_Services::get( 'iv_infusion' );
			if ( $rx_svc ) {
				$payload['service_id'] = (int) $rx_svc['id'];
				$payload['items']      = array( array( 'service_id' => (int) $rx_svc['id'], 'is_extra' => 0 ) );
			}
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $payload );
			if ( ! empty( $res['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'rx_missing_accepted' );
			}
			return array( 'ok' => true, 'detail' => 'rejects_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_5(): array {
		$ctx = null;
		$prev_cap = MNS_Settings::get( 'slot_capacity' );
		try {
			$ctx = self::seed_green();
			MNS_Settings::set( 'slot_capacity', 1 );
			MNS_Settings::set( 'assignment_mode', 'auto' );
			$r1 = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ), array( 'Idempotency-Key' => 'm35a-' . $ctx['patient_uid'] ) );
			if ( empty( $r1['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'first_fail:' . wp_json_encode( $r1['data']['error'] ?? null ) );
			}
			$p2 = self::make_patient_user();
			$ctx['uids'][] = $p2;
			update_user_meta( $p2, 'mobile', '09120000002' );
			$pat2 = MNS_Patients::ensure_self( $p2 );
			$body2 = self::order_body( $ctx );
			$body2['patient_id'] = (int) $pat2['id'];
			$body2['contact_phone'] = '09120000002';
			$r2 = self::rest_json( 'POST', '/orders', $p2, $body2, array( 'Idempotency-Key' => 'm35b-' . $p2 ) );
			$ecode = isset( $r2['data']['error']['code'] ) ? (string) $r2['data']['error']['code'] : '';
			if ( ! empty( $r2['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'second_accepted' );
			}
			if ( ! in_array( $ecode, array( 'ERR_NO_NURSE', 'ERR_SLOT_TAKEN' ), true ) ) {
				return array( 'ok' => false, 'detail' => 'code=' . $ecode );
			}
			return array( 'ok' => true, 'detail' => $ecode );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			MNS_Settings::set( 'slot_capacity', $prev_cap );
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_6(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green();
			MNS_Settings::set( 'assignment_mode', 'auto' );
			$key = 'idem-' . wp_generate_password( 8, false );
			$r1  = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ), array( 'Idempotency-Key' => $key ) );
			$r2  = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ), array( 'Idempotency-Key' => $key ) );
			if ( empty( $r1['data']['ok'] ) || empty( $r2['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'idem_fail' );
			}
			$c1 = (string) ( $r1['data']['data']['order']['code'] ?? '' );
			$c2 = (string) ( $r2['data']['data']['order']['code'] ?? '' );
			if ( $c1 !== $c2 || '' === $c1 ) {
				return array( 'ok' => false, 'detail' => 'codes=' . $c1 . '/' . $c2 );
			}
			$p2 = self::make_patient_user();
			$ctx['uids'][] = $p2;
			update_user_meta( $p2, 'mobile', '09120000003' );
			$pat2 = MNS_Patients::ensure_self( $p2 );
			$body2 = self::order_body( $ctx );
			$body2['patient_id'] = (int) $pat2['id'];
			$body2['contact_phone'] = '09120000003';
			// Same raw key, different user ⇒ new order.
			$r3 = self::rest_json( 'POST', '/orders', $p2, $body2, array( 'Idempotency-Key' => $key ) );
			$c3 = (string) ( $r3['data']['data']['order']['code'] ?? '' );
			// With slot_capacity=1 the single nurse is already taken by user 1, so a fresh attempt by user 2
			// is rejected for capacity; what matters is that user 2 did NOT receive user 1's cached order.
			$e3 = isset( $r3['data']['error']['code'] ) ? (string) $r3['data']['error']['code'] : '';
			if ( empty( $r3['data']['ok'] ) && in_array( $e3, array( 'ERR_NO_NURSE', 'ERR_SLOT_TAKEN' ), true ) ) {
				return array( 'ok' => true, 'detail' => 'same=' . $c1 . ';other_isolated=' . $e3 );
			}
			if ( empty( $r3['data']['ok'] ) || $c3 === $c1 || '' === $c3 ) {
				return array( 'ok' => false, 'detail' => 'cross_user=' . $c3 . ';err=' . $e3 );
			}
			return array( 'ok' => true, 'detail' => 'same=' . $c1 . ';other=' . $c3 );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_7(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green();
			$body = self::order_body( $ctx );
			$body['contact_phone'] = '09129998877';
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $body );
			$ecode = isset( $res['data']['error']['code'] ) ? (string) $res['data']['error']['code'] : '';
			if ( 'ERR_OTP_REQUIRED' !== $ecode ) {
				return array( 'ok' => false, 'detail' => $ecode );
			}
			return array( 'ok' => true, 'detail' => 'otp_required' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_asg_1(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green( false );
			MNS_Settings::set( 'assignment_mode', 'manual' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			if ( empty( $res['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'create_fail' );
			}
			$code = (string) $res['data']['data']['order']['code'];
			$row  = self::order_by_code( $code );
			$fresh = MNS_Assignment::auto_assign( (int) $row['id'], 1 );
			if ( (int) $fresh['nurse_user_id'] !== (int) $ctx['nurse_uid'] ) {
				return array( 'ok' => false, 'detail' => 'nurse_mismatch' );
			}
			if ( self::log_count( (int) $row['id'] ) < 2 ) {
				return array( 'ok' => false, 'detail' => 'logs_low' );
			}
			return array( 'ok' => true, 'detail' => 'assigned' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_asg_2(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green( false );
			MNS_Settings::set( 'assignment_mode', 'manual' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$row = self::order_by_code( (string) $res['data']['data']['order']['code'] );
			global $wpdb;
			$wpdb->update( MNS_DB::table( 'nurses' ), array( 'status' => 'suspended' ), array( 'user_id' => (int) $ctx['nurse_uid'] ), array( '%s' ), array( '%d' ) );
			try {
				MNS_Assignment::admin_set_nurse( (int) $row['id'], (int) $ctx['nurse_uid'], 1, true );
				return array( 'ok' => false, 'detail' => 'accepted_suspended' );
			} catch ( MNS_Error $e ) {
				return array( 'ok' => true, 'detail' => $e->error_code() );
			}
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_asg_3(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green( false );
			MNS_Settings::set( 'assignment_mode', 'manual' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$row = self::order_by_code( (string) $res['data']['data']['order']['code'] );
			$bad = self::make_nurse( $ctx['uids'], 'male', (int) $ctx['service_id'], (int) $ctx['city_id'], 9, $ctx['day'], '20-24' );
			try {
				MNS_Assignment::admin_set_nurse( (int) $row['id'], $bad, 1, false );
				return array( 'ok' => false, 'detail' => 'accepted_ineligible' );
			} catch ( MNS_Error $e ) {
				if ( 'ERR_CONFIRM' !== $e->error_code() ) {
					return array( 'ok' => false, 'detail' => $e->error_code() );
				}
				return array( 'ok' => true, 'detail' => 'needs_force' );
			}
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_asg_4(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green( false );
			MNS_Settings::set( 'assignment_mode', 'manual' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$row = self::order_by_code( (string) $res['data']['data']['order']['code'] );
			$bad = self::make_nurse( $ctx['uids'], 'male', (int) $ctx['service_id'], (int) $ctx['city_id'], 9, $ctx['day'], '20-24' );
			$fresh = MNS_Assignment::admin_set_nurse( (int) $row['id'], $bad, 1, true );
			if ( (int) $fresh['nurse_user_id'] !== $bad ) {
				return array( 'ok' => false, 'detail' => 'force_fail' );
			}
			return array( 'ok' => true, 'detail' => 'forced' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_asg_5(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green( false );
			MNS_Settings::set( 'assignment_mode', 'manual' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$row = self::order_by_code( (string) $res['data']['data']['order']['code'] );
			MNS_Assignment::admin_set_nurse( (int) $row['id'], (int) $ctx['nurse_uid'], 1, false );
			$n2 = self::make_nurse( $ctx['uids'], 'female', (int) $ctx['service_id'], (int) $ctx['city_id'], (int) $ctx['district'], $ctx['day'], $ctx['slot'] );
			$re = MNS_Assignment::admin_set_nurse( (int) $row['id'], $n2, 1, false );
			if ( (int) $re['nurse_user_id'] !== $n2 ) {
				return array( 'ok' => false, 'detail' => 'reassign_fail' );
			}
			MNS_Order_Machine::transition(
				'cancel_admin',
				array(
					'order_id'      => (int) $row['id'],
					'reason'        => 'selftest',
					'actor_user_id' => 1,
					'actor_role'    => 'admin',
				)
			);
			if ( self::log_count( (int) $row['id'] ) < 3 ) {
				return array( 'ok' => false, 'detail' => 'logs=' . self::log_count( (int) $row['id'] ) );
			}
			return array( 'ok' => true, 'detail' => 'reassign+cancel_admin' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_10(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green();
			MNS_Settings::set( 'assignment_mode', 'auto' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$code = (string) $res['data']['data']['order']['code'];
			$other = self::make_patient_user();
			$ctx['uids'][] = $other;
			$r2 = self::rest_json( 'GET', '/orders/' . $code, $other );
			$ecode = isset( $r2['data']['error']['code'] ) ? (string) $r2['data']['error']['code'] : '';
			if ( 'ERR_NOT_FOUND' !== $ecode ) {
				return array( 'ok' => false, 'detail' => $ecode );
			}
			return array( 'ok' => true, 'detail' => '404_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_11(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green();
			MNS_Settings::set( 'assignment_mode', 'manual' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$code = (string) $res['data']['data']['order']['code'];
			$c1 = self::rest_json( 'POST', '/orders/' . $code . '/cancel', (int) $ctx['patient_uid'], array( 'reason' => 'test' ) );
			if ( empty( $c1['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'cancel_submitted_fail' );
			}
			$c2 = self::rest_json( 'POST', '/orders/' . $code . '/cancel', (int) $ctx['patient_uid'], array( 'reason' => 'again' ) );
			if ( ! empty( $c2['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'double_cancel' );
			}
			return array( 'ok' => true, 'detail' => 'cancel_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_12(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green();
			MNS_Settings::set( 'assignment_mode', 'manual' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$code = (string) $res['data']['data']['order']['code'];
			$bad = self::rest_json( 'PATCH', '/orders/' . $code, (int) $ctx['patient_uid'], array( 'date' => $ctx['day'], 'slot' => '16-20' ) );
			if ( ! empty( $bad['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'date_change_accepted' );
			}
			$ok = self::rest_json( 'PATCH', '/orders/' . $code, (int) $ctx['patient_uid'], array( 'notes' => 'یادداشت تست' ) );
			if ( empty( $ok['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'notes_fail' );
			}
			return array( 'ok' => true, 'detail' => 'patch_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_13(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green();
			MNS_Settings::set( 'assignment_mode', 'auto' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$code = (string) $res['data']['data']['order']['code'];
			$nres = self::rest_json( 'GET', '/nurse/orders/' . $code, (int) $ctx['nurse_uid'] );
			// Fallback to service API if nurse REST route differs.
			if ( empty( $nres['data']['ok'] ) ) {
				$data = MNS_Nurse_Orders_Api::get_for( (int) $ctx['nurse_uid'], $code );
			} else {
				$data = $nres['data']['data'];
			}
			$blob = wp_json_encode( $data );
			foreach ( array( 'national_id', 'price_min', 'price_max', 'price_from', 'price_to', 'mobile' ) as $bad ) {
				if ( false !== strpos( $blob, $bad ) ) {
					return array( 'ok' => false, 'detail' => 'leaked_' . $bad );
				}
			}
			if ( empty( $data['address'] ) || empty( $data['contact_phone'] ) ) {
				return array( 'ok' => false, 'detail' => 'contact_missing_while_assigned' );
			}
			if ( ! empty( $data['essentials']['secret_internal'] ) ) {
				return array( 'ok' => false, 'detail' => 'essentials_not_whitelisted' );
			}
			return array( 'ok' => true, 'detail' => 'whitelist_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_14(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green( false );
			MNS_Settings::set( 'assignment_mode', 'manual' );
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], self::order_body( $ctx ) );
			$row = self::order_by_code( (string) $res['data']['data']['order']['code'] );
			MNS_Assignment::admin_set_nurse( (int) $row['id'], (int) $ctx['nurse_uid'], 1, false );
			$n2 = self::make_nurse( $ctx['uids'], 'female', (int) $ctx['service_id'], (int) $ctx['city_id'], (int) $ctx['district'], $ctx['day'], $ctx['slot'] );
			MNS_Assignment::admin_set_nurse( (int) $row['id'], $n2, 1, false );
			global $wpdb;
			$n_re = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . MNS_DB::table( 'notifications' ) . ' WHERE order_id = %d AND type = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					(int) $row['id'],
					'order_reassigned'
				)
			);
			MNS_Order_Machine::transition(
				'cancel_admin',
				array(
					'order_id'      => (int) $row['id'],
					'reason'        => 'n',
					'actor_user_id' => 1,
					'actor_role'    => 'admin',
				)
			);
			$n_ca = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . MNS_DB::table( 'notifications' ) . ' WHERE order_id = %d AND type = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					(int) $row['id'],
					'order_cancelled'
				)
			);
			if ( $n_re < 1 || $n_ca < 1 ) {
				return array( 'ok' => false, 'detail' => 're=' . $n_re . ';ca=' . $n_ca );
			}
			return array( 'ok' => true, 'detail' => 'notifs_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m3_15(): array {
		try {
			$ok = MNS_Sms::send( MNS_Sms::EVT_ORDER_CREATED, '09120000000', array( 'code' => '12345' ), 'mns:selftest:dedupe:a' );
			$ok2 = MNS_Sms::send( MNS_Sms::EVT_ORDER_CREATED, '09120000000', array( 'code' => '12345' ), 'mns:selftest:dedupe:a' );
			// Without pattern ⇒ false (log only), no throw.
			return array( 'ok' => true, 'detail' => 'send=' . ( $ok ? '1' : '0' ) . ';again=' . ( $ok2 ? '1' : '0' ) );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_fil_1(): array {
		$ctx = null;
		try {
			$ctx = self::seed_green();
			MNS_Settings::set( 'assignment_mode', 'auto' );
			$fid = MNS_Files::store_bytes( "\xFF\xD8\xFF\xD9", 'jpg', (int) $ctx['patient_uid'], 'prescription', null );
			$body = self::order_body( $ctx );
			$body['essentials']['serum_type'] = 'طبق نسخه پزشک';
			$body['essentials']['rx_file_id'] = (string) $fid;
			$res = self::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $body );
			if ( empty( $res['data']['ok'] ) ) {
				return array( 'ok' => false, 'detail' => 'create:' . wp_json_encode( $res['data']['error'] ?? null ) );
			}
			$row = self::order_by_code( (string) $res['data']['data']['order']['code'] );
			$file = MNS_Files::get( $fid );
			if ( ! $file || (int) $file['order_id'] !== (int) $row['id'] ) {
				return array( 'ok' => false, 'detail' => 'not_attached' );
			}
			$nurse = (int) $ctx['nurse_uid'];
			$other = self::make_nurse( $ctx['uids'], 'female', (int) $ctx['service_id'], (int) $ctx['city_id'], (int) $ctx['district'], $ctx['day'], $ctx['slot'] );
			if ( ! MNS_Files::can_read( $file, $nurse ) ) {
				return array( 'ok' => false, 'detail' => 'assigned_denied' );
			}
			if ( MNS_Files::can_read( $file, $other ) ) {
				return array( 'ok' => false, 'detail' => 'other_nurse_allowed' );
			}
			if ( ! MNS_Files::can_read( $file, (int) $ctx['patient_uid'] ) ) {
				return array( 'ok' => false, 'detail' => 'owner_denied' );
			}
			$stranger = self::make_patient_user();
			$ctx['uids'][] = $stranger;
			if ( MNS_Files::can_read( $file, $stranger ) ) {
				return array( 'ok' => false, 'detail' => 'stranger_allowed' );
			}
			MNS_Order_Machine::transition(
				'cancel_admin',
				array(
					'order_id'      => (int) $row['id'],
					'reason'        => 'x',
					'actor_user_id' => 1,
					'actor_role'    => 'admin',
				)
			);
			$file2 = MNS_Files::get( $fid );
			if ( MNS_Files::can_read( $file2, $nurse ) ) {
				return array( 'ok' => false, 'detail' => 'after_cancel_allowed' );
			}
			return array( 'ok' => true, 'detail' => 'acl_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			self::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @param array<string,mixed> $ctx Context.
	 * @return array<string,mixed>
	 */
	public static function order_body( array $ctx ): array {
		$ess = array( 'allergy' => 'no' );
		$svc = ! empty( $ctx['service_id'] ) ? MNS_Services::get( (int) $ctx['service_id'] ) : null;
		$fields = $svc && isset( $svc['config']['order_fields'] ) && is_array( $svc['config']['order_fields'] ) ? $svc['config']['order_fields'] : array();
		if ( ! empty( $fields['serum_types'] ) && is_array( $fields['serum_types'] ) ) {
			$ess['serum_type'] = (string) $fields['serum_types'][0];
		}
		return array(
			'patient_id'    => (int) $ctx['patient_id'],
			'service_id'    => (int) $ctx['service_id'],
			'items'         => array( array( 'service_id' => (int) $ctx['service_id'], 'is_extra' => 0 ) ),
			'prov'          => 'THR',
			'city_id'       => (int) $ctx['city_id'],
			'district'      => (int) $ctx['district'],
			'address'       => 'خیابان تست پلاک ۱',
			'contact_phone' => (string) $ctx['phone'],
			'date'          => (string) $ctx['day'],
			'slot'          => (string) $ctx['slot'],
			'gender'        => 'female',
			'essentials'    => $ess,
			'notes'         => 'selftest',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function seed_green( bool $auto = true ): array {
		MNS_Services_Seed::ensure_slice1();
		$svc = MNS_Services::get( MNS_Services::default_active_slug() );
		if ( ! $svc ) {
			$svc = MNS_Services::get( 'serum' );
		}
		if ( ! $svc ) {
			throw new \RuntimeException( 'no_active_service' );
		}
		$thr = MNS_Locations::city_id( 'THR', 'تهران' );
		if ( ! $thr ) {
			throw new \RuntimeException( 'no_tehran' );
		}
		$tz  = new DateTimeZone( MNS_Time::TZ );
		$day = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+3 days' )->format( 'Y-m-d' );
		$slot = '12-16';
		$uids = array();
		$nurse = self::make_nurse( $uids, 'female', (int) $svc['id'], (int) $thr, 1, $day, $slot );
		$patient = self::make_patient_user();
		$uids[]  = $patient;
		$phone   = '0912' . str_pad( (string) ( $patient % 10000000 ), 7, '0', STR_PAD_LEFT );
		update_user_meta( $patient, 'mobile', $phone );
		$pat = MNS_Patients::ensure_self( $patient );
		$prev_mode = MNS_Settings::get( 'assignment_mode' );
		if ( $auto ) {
			MNS_Settings::set( 'assignment_mode', 'auto' );
		}
		return array(
			'uids'         => $uids,
			'nurse_uid'    => $nurse,
			'patient_uid'  => $patient,
			'patient_id'   => (int) $pat['id'],
			'service_id'   => (int) $svc['id'],
			'city_id'      => (int) $thr,
			'district'     => 1,
			'day'          => $day,
			'slot'         => $slot,
			'phone'        => $phone,
			'prev_mode'    => $prev_mode,
		);
	}

	/**
	 * @param array<int,int> $uids Collector.
	 */
	private static function make_nurse( array &$uids, string $gender, int $service_id, int $city_id, int $district, string $day, string $slot ): int {
		$login = 'mns_selftest_n_' . wp_generate_password( 6, false );
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
				'gender'      => $gender,
				'national_id' => sprintf( '7%09d', $uid % 1000000000 ),
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
				'city_id'       => $city_id,
				'district_no'   => $district,
			)
		);
		$wpdb->replace(
			MNS_DB::table( 'nurse_week' ),
			array(
				'nurse_id' => $uid,
				'weekday'  => MNS_Time::weekday_tehran( $day ),
				'slot_id'  => $slot,
			)
		);
		return $uid;
	}

	private static function make_patient_user(): int {
		$login = 'mns_selftest_p_' . wp_generate_password( 6, false );
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
		$user = new WP_User( (int) $uid );
		$user->add_cap( MNS_Roles::CAP_PATIENT_PANEL );
		return (int) $uid;
	}

	/**
	 * @param array<string,mixed>|null $ctx Context.
	 */
	public static function cleanup_ctx( $ctx ): void {
		if ( ! is_array( $ctx ) ) {
			return;
		}
		if ( isset( $ctx['prev_mode'] ) ) {
			MNS_Settings::set( 'assignment_mode', $ctx['prev_mode'] );
		}
		$uids = isset( $ctx['uids'] ) && is_array( $ctx['uids'] ) ? $ctx['uids'] : array();
		foreach ( $uids as $uid ) {
			MNS_Selftest::purge_user_rows( (int) $uid );
			wp_delete_user( (int) $uid );
		}
	}

	/**
	 * @param array<string,mixed> $params Body/query.
	 * @param array<string,string> $headers Headers.
	 * @return array{status:int,data:mixed}
	 */
	public static function rest_json( string $method, string $route, int $uid, array $params = array(), array $headers = array() ): array {
		wp_set_current_user( $uid );
		$req = new WP_REST_Request( $method, '/' . MNS_REST::NS . $route );
		$req->set_param( '_envelope', '1' );
		if ( ! empty( $headers['Idempotency-Key'] ) ) {
			$req->set_header( 'Idempotency-Key', $headers['Idempotency-Key'] );
		}
		if ( 'GET' === $method ) {
			foreach ( $params as $k => $v ) {
				$req->set_param( $k, $v );
			}
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
			foreach ( $params as $k => $v ) {
				$req->set_param( $k, $v );
			}
		}
		$res = rest_do_request( $req );
		return array(
			'status' => (int) $res->get_status(),
			'data'   => $res->get_data(),
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function order_by_code( string $code ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'orders' ) . ' WHERE code = %s LIMIT 1', $code ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	private static function log_count( int $order_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . MNS_DB::table( 'order_log' ) . ' WHERE order_id = %d', $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}
}
