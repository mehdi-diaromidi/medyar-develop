<?php
/**
 * Self-test: catalog multi-service eligibility / orders / checklist / snapshot (RUN-G).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Selftest_Catalog {

	public static function register_all(): void {
		MNS_Selftest::register( 'AC-G-1', 'Default nurse covers all except needs_explicit', array( __CLASS__, 'ac_g_1' ) );
		MNS_Selftest::register( 'AC-G-2', 'Explicit nurse_services restricts coverage', array( __CLASS__, 'ac_g_2' ) );
		MNS_Selftest::register( 'AC-G-3', 'manual_assign ⇒ A-13 state=manual; order stays submitted', array( __CLASS__, 'ac_g_3' ) );
		MNS_Selftest::register( 'AC-G-4', 'Multi-item order same category + extras', array( __CLASS__, 'ac_g_4' ) );
		MNS_Selftest::register( 'AC-G-5', 'Checklist groups from order_items', array( __CLASS__, 'ac_g_5' ) );
		MNS_Selftest::register( 'AC-G-6', 'Snapshot has only booleans / no nurse PII', array( __CLASS__, 'ac_g_6' ) );
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_g_1(): array {
		$ctx = null;
		try {
			$ctx = MNS_Selftest_Orders::seed_green();
			// Clear nurse_services ⇒ default all.
			global $wpdb;
			$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => (int) $ctx['nurse_uid'] ), array( '%d' ) );
			$im = MNS_Services::get( 'im_injection' );
			$su = MNS_Services::get( 'suction' );
			if ( ! $im ) {
				return array( 'ok' => false, 'detail' => 'missing_im_injection' );
			}
			$scope = self::scope( $ctx, array( (int) $im['id'] ) );
			$list  = MNS_Eligibility::nurses_for( $scope );
			if ( empty( $list ) ) {
				return array( 'ok' => false, 'detail' => 'default_should_cover_im' );
			}
			if ( $su && ! empty( $su['needs_explicit_nurse'] ) ) {
				$scope2 = self::scope( $ctx, array( (int) $su['id'] ) );
				$list2  = MNS_Eligibility::nurses_for( $scope2 );
				if ( ! empty( $list2 ) ) {
					return array( 'ok' => false, 'detail' => 'default_must_not_cover_explicit' );
				}
			}
			return array( 'ok' => true, 'detail' => 'default_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			if ( $ctx ) {
				MNS_Selftest_Orders::cleanup_ctx( $ctx );
			}
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_g_2(): array {
		$ctx = null;
		try {
			$ctx = MNS_Selftest_Orders::seed_green();
			$im  = MNS_Services::get( 'im_injection' );
			$sc  = MNS_Services::get( 'sc_injection' );
			if ( ! $im || ! $sc ) {
				return array( 'ok' => false, 'detail' => 'missing_services' );
			}
			global $wpdb;
			$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => (int) $ctx['nurse_uid'] ), array( '%d' ) );
			$wpdb->insert(
				MNS_DB::table( 'nurse_services' ),
				array(
					'nurse_id'   => (int) $ctx['nurse_uid'],
					'service_id' => (int) $im['id'],
				)
			);
			$ok_one = MNS_Eligibility::nurses_for( self::scope( $ctx, array( (int) $im['id'] ) ) );
			$bad    = MNS_Eligibility::nurses_for( self::scope( $ctx, array( (int) $im['id'], (int) $sc['id'] ) ) );
			if ( empty( $ok_one ) || ! empty( $bad ) ) {
				return array( 'ok' => false, 'detail' => 'explicit_rule_fail' );
			}
			return array( 'ok' => true, 'detail' => 'explicit_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			if ( $ctx ) {
				MNS_Selftest_Orders::cleanup_ctx( $ctx );
			}
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_g_3(): array {
		$ctx = null;
		try {
			$ctx = MNS_Selftest_Orders::seed_green();
			$man = MNS_Services::get( 'hourly_elder' );
			if ( ! $man || empty( $man['manual_assign'] ) ) {
				return array( 'ok' => false, 'detail' => 'missing_manual_service' );
			}
			$check = MNS_Availability::check( self::scope( $ctx, array( (int) $man['id'] ) ) );
			if ( 'manual' !== ( $check['state'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'state=' . ( $check['state'] ?? '' ) );
			}
			$body = MNS_Selftest_Orders::order_body( $ctx );
			$body['items'] = array( array( 'service_id' => (int) $man['id'], 'is_extra' => 0 ) );
			unset( $body['service_id'] );
			$res = MNS_Selftest_Orders::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $body, array( 'Idempotency-Key' => 'g3-' . wp_generate_password( 6, false ) ) );
			$code = (string) ( $res['data']['data']['order']['code'] ?? '' );
			$row  = $code ? MNS_Selftest_Orders::order_by_code( $code ) : null;
			if ( ! $row || 'submitted' !== (string) $row['status'] || ! empty( $row['nurse_user_id'] ) ) {
				return array( 'ok' => false, 'detail' => 'order=' . wp_json_encode( $row ? array( 'status' => $row['status'], 'nurse' => $row['nurse_user_id'] ) : null ) . ';err=' . wp_json_encode( $res['data']['error'] ?? null, JSON_UNESCAPED_UNICODE ) );
			}
			return array( 'ok' => true, 'detail' => 'manual_submitted' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			if ( $ctx ) {
				MNS_Selftest_Orders::cleanup_ctx( $ctx );
			}
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_g_4(): array {
		$ctx = null;
		try {
			$ctx = MNS_Selftest_Orders::seed_green();
			global $wpdb;
			$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => (int) $ctx['nurse_uid'] ), array( '%d' ) );
			$im = MNS_Services::get( 'im_injection' );
			$sc = MNS_Services::get( 'sc_injection' );
			$bp = MNS_Services::get( 'bp_check' );
			if ( ! $im || ! $sc || ! $bp ) {
				return array( 'ok' => false, 'detail' => 'missing_svcs' );
			}
			$body = MNS_Selftest_Orders::order_body( $ctx );
			$body['items'] = array(
				array( 'service_id' => (int) $im['id'], 'is_extra' => 0 ),
				array( 'service_id' => (int) $sc['id'], 'is_extra' => 0 ),
				array( 'service_id' => (int) $bp['id'], 'is_extra' => 1 ),
			);
			unset( $body['service_id'] );
			$res  = MNS_Selftest_Orders::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $body, array( 'Idempotency-Key' => 'g4-' . wp_generate_password( 6, false ) ) );
			$code = (string) ( $res['data']['data']['order']['code'] ?? '' );
			$row  = $code ? MNS_Selftest_Orders::order_by_code( $code ) : null;
			if ( ! $row ) {
				return array( 'ok' => false, 'detail' => 'create_fail:' . wp_json_encode( $res['data']['error'] ?? null ) );
			}
			$items = MNS_Order_Items::for_order( (int) $row['id'] );
			if ( 3 !== count( $items ) ) {
				return array( 'ok' => false, 'detail' => 'items=' . count( $items ) );
			}
			$extras = 0;
			foreach ( $items as $it ) {
				if ( ! empty( $it['is_extra'] ) ) {
					++$extras;
				}
			}
			if ( 1 !== $extras ) {
				return array( 'ok' => false, 'detail' => 'extras=' . $extras );
			}
			return array( 'ok' => true, 'detail' => 'multi_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			if ( $ctx ) {
				MNS_Selftest_Orders::cleanup_ctx( $ctx );
			}
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_g_5(): array {
		$ctx = null;
		try {
			$ctx = MNS_Selftest_Orders::seed_green();
			global $wpdb;
			$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => (int) $ctx['nurse_uid'] ), array( '%d' ) );
			$im = MNS_Services::get( 'im_injection' );
			$sc = MNS_Services::get( 'sc_injection' );
			$body = MNS_Selftest_Orders::order_body( $ctx );
			$body['items'] = array(
				array( 'service_id' => (int) $im['id'], 'is_extra' => 0 ),
				array( 'service_id' => (int) $sc['id'], 'is_extra' => 0 ),
			);
			unset( $body['service_id'] );
			$res  = MNS_Selftest_Orders::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], $body, array( 'Idempotency-Key' => 'g5-' . wp_generate_password( 6, false ) ) );
			$code = (string) ( $res['data']['data']['order']['code'] ?? '' );
			$row  = $code ? MNS_Selftest_Orders::order_by_code( $code ) : null;
			if ( ! $row ) {
				return array( 'ok' => false, 'detail' => 'no_order' );
			}
			$groups = MNS_Order_Items::checklist_groups( (int) $row['id'] );
			if ( count( $groups ) < 2 || empty( $groups[0]['items'] ) ) {
				return array( 'ok' => false, 'detail' => 'groups=' . wp_json_encode( $groups ) );
			}
			return array( 'ok' => true, 'detail' => 'groups=' . count( $groups ) );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		} finally {
			if ( $ctx ) {
				MNS_Selftest_Orders::cleanup_ctx( $ctx );
			}
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_g_6(): array {
		try {
			if ( ! class_exists( 'MNS_Availability_Snapshot', true ) ) {
				return array( 'ok' => false, 'detail' => 'no_snapshot_class' );
			}
			$data = MNS_Availability_Snapshot::build();
			$json = wp_json_encode( $data );
			if ( preg_match( '/nurse|user_id|display_name|\"count\"/i', (string) $json ) ) {
				return array( 'ok' => false, 'detail' => 'pii_leak' );
			}
			if ( ! isset( $data['regions'] ) || ! is_array( $data['regions'] ) ) {
				return array( 'ok' => false, 'detail' => 'missing_regions' );
			}
			// Absent region key ⇒ no nurses; bits only present when mask>0 (empty combo omitted = bit zero).
			foreach ( $data['regions'] as $gmap ) {
				if ( ! is_array( $gmap ) ) {
					continue;
				}
				foreach ( $gmap as $days ) {
					if ( ! is_array( $days ) ) {
						continue;
					}
					foreach ( $days as $mask ) {
						if ( (int) $mask <= 0 ) {
							return array( 'ok' => false, 'detail' => 'zero_mask_should_be_omitted' );
						}
					}
				}
			}
			return array( 'ok' => true, 'detail' => 'snapshot_ok;regions=' . count( $data['regions'] ) );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => $e->getMessage() );
		}
	}

	/**
	 * @param array<string,mixed> $ctx Context.
	 * @param array<int,int>      $ids Service ids.
	 * @return array<string,mixed>
	 */
	private static function scope( array $ctx, array $ids ): array {
		$body = MNS_Selftest_Orders::order_body( $ctx );
		return array(
			'service_ids'   => $ids,
			'service_id'    => (int) $ids[0],
			'province_code' => (string) ( $body['prov'] ?? 'THR' ),
			'city_id'       => (int) ( $body['city_id'] ?? 0 ),
			'district_no'   => (int) ( $body['district'] ?? 0 ),
			'service_date'  => (string) ( $body['date'] ?? '' ),
			'slot_id'       => (string) ( $body['slot'] ?? '' ),
			'gender_pref'   => (string) ( $body['gender'] ?? 'female' ),
		);
	}
}
