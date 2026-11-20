<?php
/**
 * Self-test: online Zarinpal payment flow with Fake gateway (RUN-F / T-F.6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Selftest_Pay {

	public static function register_all(): void {
		MNS_Selftest::register( 'AC-M6-1', 'Pay start: amount from invoice; ownership; reuse pending', array( __CLASS__, 'ac_m6_1' ) );
		MNS_Selftest::register( 'AC-M6-2', 'Callback OK ⇒ invoice+order paid, one zarinpal payment', array( __CLASS__, 'ac_m6_2' ) );
		MNS_Selftest::register( 'AC-M6-3', 'Callback replay / 101 ⇒ still one payment', array( __CLASS__, 'ac_m6_3' ) );
		MNS_Selftest::register( 'AC-M6-4', 'Status=NOK ⇒ attempt failed, invoice unpaid', array( __CLASS__, 'ac_m6_4' ) );
		MNS_Selftest::register( 'AC-M6-5', 'Verify amount mismatch ⇒ amount_mismatch', array( __CLASS__, 'ac_m6_5' ) );
		MNS_Selftest::register( 'AC-M6-6', 'Manual pay then callback ⇒ paid_duplicate', array( __CLASS__, 'ac_m6_6' ) );
		MNS_Selftest::register( 'AC-M6-7', 'Unknown authority ⇒ generic redirect, no change', array( __CLASS__, 'ac_m6_7' ) );
		MNS_Selftest::register( 'AC-M6-8', 'Sweep: old pending + failed inquiry ⇒ expired', array( __CLASS__, 'ac_m6_8' ) );
	}

	/**
	 * @param array<string,mixed> $ctx Context.
	 * @return array{order:array<string,mixed>,invoice:array<string,mixed>}
	 */
	private static function unpaid_invoice( array $ctx, int $amount = 400000 ): array {
		MNS_Settings::set( 'assignment_mode', 'auto' );
		$res  = MNS_Selftest_Orders::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], MNS_Selftest_Orders::order_body( $ctx ), array( 'Idempotency-Key' => 'm6-' . wp_generate_password( 8, false ) ) );
		$code = (string) ( $res['data']['data']['order']['code'] ?? '' );
		$row  = '' !== $code ? MNS_Selftest_Orders::order_by_code( $code ) : null;
		if ( ! $row ) {
			throw new \RuntimeException( 'order_missing:' . wp_json_encode( $res['data']['error'] ?? null ) );
		}
		global $wpdb;
		$wpdb->update( MNS_DB::table( 'orders' ), array( 'status' => 'service_ended' ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
		$inv = MNS_Invoices::issue_for_order( (int) $row['id'], 1, $amount );
		$ord = MNS_Selftest_Orders::order_by_code( $code );
		return array(
			'order'   => is_array( $ord ) ? $ord : $row,
			'invoice' => $inv,
		);
	}

	/**
	 * @param array<string,mixed>|null $ctx Context.
	 */
	private static function purge( $ctx ): void {
		if ( ! is_array( $ctx ) ) {
			return;
		}
		global $wpdb;
		$uid = (int) ( $ctx['patient_uid'] ?? 0 );
		if ( $uid > 0 ) {
			$iids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE account_user_id = %d', $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( is_array( $iids ) ) {
				foreach ( $iids as $iid ) {
					$wpdb->delete( MNS_DB::table( 'payment_attempts' ), array( 'invoice_id' => (int) $iid ), array( '%d' ) );
					$wpdb->delete( MNS_DB::table( 'payments' ), array( 'invoice_id' => (int) $iid ), array( '%d' ) );
					$wpdb->delete( MNS_DB::table( 'invoices' ), array( 'id' => (int) $iid ), array( '%d' ) );
				}
			}
			$wpdb->delete( MNS_DB::table( 'payment_attempts' ), array( 'account_user_id' => $uid ), array( '%d' ) );
		}
		MNS_Selftest_Orders::cleanup_ctx( $ctx );
		MNS_Payment_Gateway_Fake::reset();
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m6_1(): array {
		$ctx = null;
		try {
			MNS_Payment_Gateway_Fake::reset();
			$ctx  = MNS_Selftest_Orders::seed_green();
			$pack = self::unpaid_invoice( $ctx, 555000 );
			$num  = (string) $pack['invoice']['number'];
			$uid  = (int) $ctx['patient_uid'];

			// Browser cannot change amount — start_pay ignores body.
			$start = MNS_Payments::start_pay( $num, $uid );
			global $wpdb;
			$att = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'payment_attempts' ) . ' WHERE id = %d', (int) $start['attempt_id'] ), // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
			if ( ! is_array( $att ) || 555000 !== (int) $att['amount'] ) {
				return array( 'ok' => false, 'detail' => 'amount_not_from_invoice=' . ( $att['amount'] ?? 'null' ) );
			}

			$other = self::make_other_patient();
			$not_owner = false;
			try {
				MNS_Payments::start_pay( $num, (int) $other );
			} catch ( MNS_Error $e ) {
				$not_owner = ( 404 === $e->http() );
			}
			wp_delete_user( (int) $other );
			if ( ! $not_owner ) {
				return array( 'ok' => false, 'detail' => 'non_owner_not_404' );
			}

			MNS_Invoices::record_payment( (int) $pack['invoice']['id'], 'cash', 'prepay', 1 );
			$bad_state = false;
			try {
				MNS_Payments::start_pay( $num, $uid );
			} catch ( MNS_Error $e ) {
				$bad_state = ( 'ERR_BAD_STATE' === $e->error_code() );
			}
			if ( ! $bad_state ) {
				return array( 'ok' => false, 'detail' => 'paid_invoice_accepted' );
			}

			// Fresh unpaid for reuse test.
			$ctx2  = MNS_Selftest_Orders::seed_green();
			$pack2 = self::unpaid_invoice( $ctx2, 120000 );
			$a1    = MNS_Payments::start_pay( (string) $pack2['invoice']['number'], (int) $ctx2['patient_uid'] );
			$a2    = MNS_Payments::start_pay( (string) $pack2['invoice']['number'], (int) $ctx2['patient_uid'] );
			self::purge( $ctx2 );
			if ( empty( $a2['reused'] ) || (string) $a1['authority'] !== (string) $a2['authority'] ) {
				return array( 'ok' => false, 'detail' => 'pending_not_reused' );
			}

			return array( 'ok' => true, 'detail' => 'amount_locked;owner_404;bad_state;reuse_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge( $ctx );
		}
	}

	private static function make_other_patient(): int {
		$login = 'mns_selftest_other_' . wp_generate_password( 6, false );
		$uid   = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 16 ),
				'user_email' => $login . '@example.test',
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $uid ) ) {
			throw new \RuntimeException( 'other_user' );
		}
		$user = new WP_User( (int) $uid );
		$user->add_cap( MNS_Roles::CAP_PATIENT_PANEL );
		return (int) $uid;
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m6_2(): array {
		$ctx = null;
		try {
			MNS_Payment_Gateway_Fake::reset();
			$ctx  = MNS_Selftest_Orders::seed_green();
			$pack = self::unpaid_invoice( $ctx, 310000 );
			$uid  = (int) $ctx['patient_uid'];
			$num  = (string) $pack['invoice']['number'];
			$st   = MNS_Payments::start_pay( $num, $uid );
			$url  = MNS_Payments::handle_return( (string) $st['authority'], 'OK' );
			if ( false === strpos( $url, 'mns_result=success' ) ) {
				return array( 'ok' => false, 'detail' => 'redirect=' . $url );
			}
			global $wpdb;
			$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', (int) $pack['invoice']['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$ord = MNS_Selftest_Orders::order_by_code( (string) $pack['order']['code'] );
			$pays = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'payments' ) . ' WHERE invoice_id = %d', (int) $pack['invoice']['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 'paid' !== (string) ( $inv['status'] ?? '' ) || 'paid' !== (string) ( $ord['status'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'statuses inv=' . ( $inv['status'] ?? '' ) . ' ord=' . ( $ord['status'] ?? '' ) );
			}
			if ( 1 !== count( (array) $pays ) || 'zarinpal' !== (string) ( $pays[0]['method'] ?? '' ) || '' === (string) ( $pays[0]['tracking'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'payments=' . wp_json_encode( $pays ) );
			}
			return array( 'ok' => true, 'detail' => 'paid;tracking=' . $pays[0]['tracking'] );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m6_3(): array {
		$ctx = null;
		try {
			MNS_Payment_Gateway_Fake::reset();
			$ctx  = MNS_Selftest_Orders::seed_green();
			$pack = self::unpaid_invoice( $ctx, 220000 );
			$st   = MNS_Payments::start_pay( (string) $pack['invoice']['number'], (int) $ctx['patient_uid'] );
			MNS_Payments::handle_return( (string) $st['authority'], 'OK' );
			MNS_Payment_Gateway_Fake::$verify_code = 101;
			MNS_Payments::handle_return( (string) $st['authority'], 'OK' );
			global $wpdb;
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MNS_DB::table( 'payments' ) . ' WHERE invoice_id = %d', (int) $pack['invoice']['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			return ( 1 === $n )
				? array( 'ok' => true, 'detail' => 'one_payment_after_replay' )
				: array( 'ok' => false, 'detail' => 'payments=' . $n );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m6_4(): array {
		$ctx = null;
		try {
			MNS_Payment_Gateway_Fake::reset();
			$ctx  = MNS_Selftest_Orders::seed_green();
			$pack = self::unpaid_invoice( $ctx, 180000 );
			$st   = MNS_Payments::start_pay( (string) $pack['invoice']['number'], (int) $ctx['patient_uid'] );
			MNS_Payments::handle_return( (string) $st['authority'], 'NOK' );
			global $wpdb;
			$att = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . MNS_DB::table( 'payment_attempts' ) . ' WHERE id = %d', (int) $st['attempt_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', (int) $pack['invoice']['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 'failed' !== (string) ( $att['status'] ?? '' ) || 'unpaid' !== (string) ( $inv['status'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'att=' . ( $att['status'] ?? '' ) . ';inv=' . ( $inv['status'] ?? '' ) );
			}
			return array( 'ok' => true, 'detail' => 'failed_unpaid' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m6_5(): array {
		$ctx = null;
		try {
			MNS_Payment_Gateway_Fake::reset();
			MNS_Payment_Gateway_Fake::$force_amount_mismatch = true;
			$ctx  = MNS_Selftest_Orders::seed_green();
			$pack = self::unpaid_invoice( $ctx, 190000 );
			$st   = MNS_Payments::start_pay( (string) $pack['invoice']['number'], (int) $ctx['patient_uid'] );
			MNS_Payments::handle_return( (string) $st['authority'], 'OK' );
			global $wpdb;
			$att = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . MNS_DB::table( 'payment_attempts' ) . ' WHERE id = %d', (int) $st['attempt_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', (int) $pack['invoice']['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 'amount_mismatch' !== (string) ( $att['status'] ?? '' ) || 'unpaid' !== (string) ( $inv['status'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'att=' . ( $att['status'] ?? '' ) . ';inv=' . ( $inv['status'] ?? '' ) );
			}
			return array( 'ok' => true, 'detail' => 'amount_mismatch' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m6_6(): array {
		$ctx = null;
		try {
			MNS_Payment_Gateway_Fake::reset();
			$ctx  = MNS_Selftest_Orders::seed_green();
			$pack = self::unpaid_invoice( $ctx, 260000 );
			$st   = MNS_Payments::start_pay( (string) $pack['invoice']['number'], (int) $ctx['patient_uid'] );
			MNS_Invoices::record_payment( (int) $pack['invoice']['id'], 'cash', 'manual-first', 1 );
			MNS_Payments::handle_return( (string) $st['authority'], 'OK' );
			global $wpdb;
			$att = $wpdb->get_row( $wpdb->prepare( 'SELECT status, ref_id FROM ' . MNS_DB::table( 'payment_attempts' ) . ' WHERE id = %d', (int) $st['attempt_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MNS_DB::table( 'payments' ) . ' WHERE invoice_id = %d', (int) $pack['invoice']['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 'paid_duplicate' !== (string) ( $att['status'] ?? '' ) || 1 !== $n ) {
				return array( 'ok' => false, 'detail' => 'att=' . ( $att['status'] ?? '' ) . ';pays=' . $n );
			}
			return array( 'ok' => true, 'detail' => 'paid_duplicate;ref=' . ( $att['ref_id'] ?? '' ) );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m6_7(): array {
		$ctx = null;
		try {
			$ctx  = MNS_Selftest_Orders::seed_green();
			$pack = self::unpaid_invoice( $ctx, 100000 );
			$before = (int) $pack['invoice']['amount'];
			$url = MNS_Payments::handle_return( 'UNKNOWN-AUTH-XYZ', 'OK' );
			global $wpdb;
			$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', (int) $pack['invoice']['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MNS_DB::table( 'payments' ) . ' WHERE invoice_id = %d', (int) $pack['invoice']['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 'unpaid' !== (string) ( $inv['status'] ?? '' ) || 0 !== $n || false === strpos( $url, 'mns_pay=error' ) ) {
				return array( 'ok' => false, 'detail' => 'url=' . $url . ';inv=' . ( $inv['status'] ?? '' ) . ';pays=' . $n . ';amt=' . $before );
			}
			return array( 'ok' => true, 'detail' => 'generic_error_redirect' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m6_8(): array {
		$ctx = null;
		try {
			MNS_Payment_Gateway_Fake::reset();
			MNS_Payment_Gateway_Fake::$inquiry_status = 'NOK';
			$ctx  = MNS_Selftest_Orders::seed_green();
			$pack = self::unpaid_invoice( $ctx, 140000 );
			$st   = MNS_Payments::start_pay( (string) $pack['invoice']['number'], (int) $ctx['patient_uid'] );
			global $wpdb;
			$old = gmdate( 'Y-m-d H:i:s', time() - 3600 );
			$wpdb->update(
				MNS_DB::table( 'payment_attempts' ),
				array( 'created_at' => $old, 'updated_at' => $old ),
				array( 'id' => (int) $st['attempt_id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			MNS_Payments::sweep_pending();
			$att = $wpdb->get_row( $wpdb->prepare( 'SELECT status FROM ' . MNS_DB::table( 'payment_attempts' ) . ' WHERE id = %d', (int) $st['attempt_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 'expired' !== (string) ( $att['status'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'status=' . ( $att['status'] ?? '' ) );
			}
			return array( 'ok' => true, 'detail' => 'expired' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge( $ctx );
		}
	}
}
