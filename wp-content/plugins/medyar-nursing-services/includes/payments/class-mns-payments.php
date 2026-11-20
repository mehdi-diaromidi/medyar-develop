<?php
/**
 * Online invoice payment orchestration (RUN-F / T-F.2–T-F.3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Payments {

	public const CRON_HOOK     = 'mns_payment_attempts_sweep';
	public const PENDING_TTL   = 900;  // 15 minutes — reuse pending attempt.
	public const EXPIRE_AFTER  = 1800; // 30 minutes — sweep pending → inquiry/expired.

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sweep_pending' ) );
		add_action( 'mns_nurse_share_credited', array( 'MNS_Nurse_Wallet', 'on_share_credited' ), 10, 3 );
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'fifteen_minutes', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Register a custom 15-minute schedule if missing.
	 *
	 * @param array<string,mixed> $schedules Schedules.
	 * @return array<string,mixed>
	 */
	public static function cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['fifteen_minutes'] ) ) {
			$schedules['fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => 'Every 15 minutes (MNS payments)',
			);
		}
		return $schedules;
	}

	/**
	 * Start (or reuse) a payment attempt for an unpaid invoice owned by $user_id.
	 *
	 * @return array{redirect_url:string,authority:string,attempt_id:int}
	 */
	public static function start_pay( string $invoice_number, int $user_id ): array {
		global $wpdb;
		$inv = self::invoice_by_number( $invoice_number );
		if ( ! $inv || (int) $inv['account_user_id'] !== $user_id ) {
			throw MNS_Error::not_found();
		}
		if ( 'dispute' === (string) $inv['status'] ) {
			throw MNS_Error::bad_state( 'این فاکتور در حال بررسی اعتراض است و فعلاً قابل پرداخت نیست.' );
		}
		if ( 'unpaid' !== (string) $inv['status'] ) {
			throw MNS_Error::bad_state( 'این فاکتور قابل پرداخت نیست.' );
		}
		$order = MNS_Order_Machine::get_order( (int) $inv['order_id'] );
		if ( ! $order || 'invoiced' !== (string) $order['status'] ) {
			throw MNS_Error::bad_state( 'وضعیت سفارش برای پرداخت مناسب نیست.' );
		}

		$amount = (int) $inv['amount'];
		if ( $amount <= 0 ) {
			throw MNS_Error::validation( 'مبلغ فاکتور نامعتبر است.', 'amount' );
		}

		$iid = (int) $inv['id'];
		$now = MNS_Time::now_utc();
		$cut = gmdate( 'Y-m-d H:i:s', time() - self::PENDING_TTL );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'payment_attempts' ) . " WHERE invoice_id = %d AND status = 'pending' AND created_at >= %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$iid,
				$cut
			),
			ARRAY_A
		);
		if ( is_array( $existing ) && ! empty( $existing['authority'] ) ) {
			$auth = (string) $existing['authority'];
			$redir = ( 0 === strpos( $auth, 'FAKE' ) )
				? 'https://example.test/mns-fake-pay/' . rawurlencode( $auth )
				: MNS_Payment_Gateway_Zarinpal::STARTPAY_URL . $auth;
			return array(
				'redirect_url' => $redir,
				'authority'    => $auth,
				'attempt_id'   => (int) $existing['id'],
				'reused'       => true,
			);
		}

		$gw = MNS_Payment_Gateways::get();
		// Placeholder authority until gateway returns real one (UNIQUE requires non-empty unique).
		$placeholder = 'PEND-' . wp_generate_password( 24, false, false );
		$ins         = $wpdb->insert(
			MNS_DB::table( 'payment_attempts' ),
			array(
				'invoice_id'      => $iid,
				'account_user_id' => $user_id,
				'authority'       => $placeholder,
				'amount'          => $amount,
				'status'          => 'pending',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( false === $ins ) {
			throw MNS_Error::server( 'ثبت تلاش پرداخت ممکن نشد.' );
		}
		$attempt_id = (int) $wpdb->insert_id;

		$mobile = '';
		foreach ( array( 'mobile', 'drplus_mobile', 'billing_phone' ) as $mk ) {
			$v = get_user_meta( $user_id, $mk, true );
			if ( is_string( $v ) && '' !== $v ) {
				$mobile = $v;
				break;
			}
		}

		$result = $gw->initiate(
			array(
				'id'           => $attempt_id,
				'invoice_id'   => $iid,
				'amount'       => $amount,
				'callback_url' => rest_url( 'mns/v1/payments/return' ),
				'description'  => 'پرداخت فاکتور ' . (string) $inv['number'],
				'mobile'       => $mobile,
				'order_id'     => (string) ( $order['code'] ?? $inv['number'] ),
			)
		);

		if ( empty( $result['ok'] ) || empty( $result['authority'] ) || empty( $result['redirect_url'] ) ) {
			$wpdb->update(
				MNS_DB::table( 'payment_attempts' ),
				array(
					'status'     => 'failed',
					'updated_at' => MNS_Time::now_utc(),
					'meta'       => wp_json_encode( array( 'error' => (string) ( $result['error'] ?? '' ), 'code' => $result['code'] ?? null ) ),
				),
				array( 'id' => $attempt_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			throw MNS_Error::conflict( 'ERR_GATEWAY', (string) ( $result['error'] ?? 'شروع پرداخت ممکن نشد.' ) );
		}

		$authority = sanitize_text_field( (string) $result['authority'] );
		$upd       = $wpdb->update(
			MNS_DB::table( 'payment_attempts' ),
			array(
				'authority'  => $authority,
				'updated_at' => MNS_Time::now_utc(),
			),
			array( 'id' => $attempt_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $upd ) {
			throw MNS_Error::server( 'ذخیرهٔ شناسهٔ درگاه ممکن نشد.' );
		}

		// Fake gateway redirect is not StartPay — use whatever initiate returned.
		$redirect = (string) $result['redirect_url'];
		if ( ! ( $gw instanceof MNS_Payment_Gateway_Fake ) && 0 !== strpos( $redirect, 'http' ) ) {
			$redirect = MNS_Payment_Gateway_Zarinpal::STARTPAY_URL . $authority;
		}

		return array(
			'redirect_url' => $redirect,
			'authority'    => $authority,
			'attempt_id'   => $attempt_id,
			'reused'       => false,
		);
	}

	/**
	 * Public callback from Zarinpal (or Fake). Always redirects; never dumps data.
	 *
	 * @return string Absolute redirect URL.
	 */
	public static function handle_return( string $authority, string $status ): string {
		$authority = sanitize_text_field( $authority );
		$status    = sanitize_text_field( $status );
		$list_url  = MNS_Pages::panel_url( 'patient', 'invoices' );

		if ( '' === $authority ) {
			return add_query_arg( array( 'mns_pay' => 'error' ), $list_url );
		}

		global $wpdb;
		$attempt = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'payment_attempts' ) . ' WHERE authority = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$authority
			),
			ARRAY_A
		);
		if ( ! is_array( $attempt ) ) {
			return add_query_arg( array( 'mns_pay' => 'error' ), $list_url );
		}

		$inv = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', (int) $attempt['invoice_id'] ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$number = is_array( $inv ) ? (string) $inv['number'] : '';
		$view   = $number ? MNS_Patient_Invoices::view_url( $number ) : $list_url;

		if ( 'paid' === (string) $attempt['status'] || 'paid_duplicate' === (string) $attempt['status'] ) {
			return add_query_arg( array( 'mns_result' => 'success' ), $view );
		}

		if ( 'OK' !== strtoupper( $status ) ) {
			self::mark_attempt( (int) $attempt['id'], 'failed', null, array( 'gateway_status' => $status ) );
			return add_query_arg( array( 'mns_result' => 'failed', 'mns_pay' => 'cancelled' ), $view );
		}

		$result = self::finalize_attempt( $attempt, 'OK' );
		if ( 'success' === $result ) {
			return add_query_arg( array( 'mns_result' => 'success' ), $view );
		}
		if ( 'duplicate' === $result ) {
			return add_query_arg( array( 'mns_result' => 'success' ), $view );
		}
		if ( 'skipped' === $result ) {
			// A simultaneous callback finalized it first: show the real outcome, not a false failure.
			$now_status = (string) $wpdb->get_var(
				$wpdb->prepare( 'SELECT status FROM ' . MNS_DB::table( 'payment_attempts' ) . ' WHERE id = %d', (int) $attempt['id'] ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
			if ( in_array( $now_status, array( 'paid', 'paid_duplicate' ), true ) ) {
				return add_query_arg( array( 'mns_result' => 'success' ), $view );
			}
		}
		return add_query_arg( array( 'mns_result' => 'failed' ), $view );
	}

	/**
	 * Finalize a pending attempt after OK callback or successful inquiry.
	 *
	 * @param array<string,mixed> $attempt Attempt row.
	 * @return string success|duplicate|failed|mismatch|skipped
	 */
	public static function finalize_attempt( array $attempt, string $gateway_status = 'OK' ): string {
		$aid = (int) $attempt['id'];
		if ( 'pending' !== (string) $attempt['status'] ) {
			if ( in_array( (string) $attempt['status'], array( 'paid', 'paid_duplicate' ), true ) ) {
				return 'success';
			}
			return 'skipped';
		}

		$amount    = (int) $attempt['amount'];
		$authority = (string) $attempt['authority'];
		$gw        = MNS_Payment_Gateways::get();
		$verify    = $gw->verify( $authority, $amount, $gateway_status );

		if ( empty( $verify['ok'] ) ) {
			$code = $verify['code'] ?? null;
			if ( -50 === (int) $code || ! empty( $verify['amount'] ) && (int) $verify['amount'] !== $amount ) {
				self::mark_attempt( $aid, 'amount_mismatch', null, array( 'verify' => $verify ) );
				MNS_Log::error(
					'payment_amount_mismatch',
					array(
						'attempt_id' => $aid,
						'authority'  => $authority,
						'expected'   => $amount,
					)
				);
				return 'mismatch';
			}
			self::mark_attempt( $aid, 'failed', null, array( 'verify' => $verify ) );
			return 'failed';
		}

		$ref = sanitize_text_field( (string) ( $verify['ref_id'] ?? '' ) );

		$outcome = 'failed';
		try {
			MNS_DB::tx(
				static function () use ( $aid, $attempt, $ref, &$outcome ) {
					global $wpdb;
					$rows = $wpdb->query(
						$wpdb->prepare(
							'UPDATE ' . MNS_DB::table( 'payment_attempts' ) . " SET status = 'paid', ref_id = %s, verified_at = %s, updated_at = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL
							$ref,
							MNS_Time::now_utc(),
							MNS_Time::now_utc(),
							$aid
						)
					);
					if ( 1 !== (int) $rows ) {
						$outcome = 'skipped';
						return true;
					}

					$invoice_id = (int) $attempt['invoice_id'];
					$inv        = $wpdb->get_row(
						$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', $invoice_id ), // phpcs:ignore WordPress.DB.PreparedSQL
						ARRAY_A
					);
					if ( ! is_array( $inv ) || 'unpaid' !== (string) $inv['status'] ) {
						$wpdb->update(
							MNS_DB::table( 'payment_attempts' ),
							array(
								'status'     => 'paid_duplicate',
								'ref_id'     => $ref,
								'updated_at' => MNS_Time::now_utc(),
							),
							array( 'id' => $aid ),
							array( '%s', '%s', '%s' ),
							array( '%d' )
						);
						MNS_Log::warn(
							'payment_paid_duplicate_needs_manual_refund',
							array(
								'attempt_id' => $aid,
								'invoice_id' => $invoice_id,
								'ref_id'     => $ref,
							)
						);
						$outcome = 'duplicate';
						return true;
					}

					// Actor 0 = system (gateway callback).
					MNS_Invoices::record_payment( $invoice_id, 'zarinpal', $ref, 0 );
					$outcome = 'success';
					return true;
				}
			);
		} catch ( MNS_Error $e ) {
			// Concurrent payment may have won — treat as duplicate if invoice already paid.
			global $wpdb;
			$st = $wpdb->get_var(
				$wpdb->prepare( 'SELECT status FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', (int) $attempt['invoice_id'] ) // phpcs:ignore WordPress.DB.PreparedSQL
			);
			if ( 'paid' === (string) $st ) {
				self::mark_attempt( $aid, 'paid_duplicate', $ref, array( 'note' => 'record_payment_race' ) );
				MNS_Log::warn(
					'payment_paid_duplicate_needs_manual_refund',
					array(
						'attempt_id' => $aid,
						'invoice_id' => (int) $attempt['invoice_id'],
						'ref_id'     => $ref,
					)
				);
				return 'duplicate';
			}
			// Supervisor fix: the gateway already verified (the customer HAS paid); never label this plain "failed".
			self::mark_attempt( $aid, 'paid_error', $ref, array( 'error' => $e->getMessage() ) );
			MNS_Log::error( 'payment_verified_but_not_recorded', array( 'attempt_id' => $aid, 'invoice_id' => (int) $attempt['invoice_id'], 'ref_id' => $ref, 'err' => $e->getMessage() ) );
			return 'failed';
		} catch ( \Throwable $e ) {
			self::mark_attempt( $aid, 'paid_error', $ref, array( 'error' => $e->getMessage() ) );
			MNS_Log::error( 'payment_verified_but_not_recorded', array( 'attempt_id' => $aid, 'invoice_id' => (int) $attempt['invoice_id'], 'ref_id' => $ref, 'err' => $e->getMessage() ) );
			return 'failed';
		}

		if ( 'success' === $outcome ) {
			self::sms_payment_ok( (int) $attempt['invoice_id'] );
		}
		return $outcome;
	}

	/**
	 * Cron: expire or finalize old pending attempts.
	 */
	public static function sweep_pending(): void {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - self::EXPIRE_AFTER );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'payment_attempts' ) . " WHERE status = 'pending' AND created_at < %s ORDER BY id ASC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL
				$cut
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}
		$gw = MNS_Payment_Gateways::get();
		foreach ( $rows as $attempt ) {
			$inq = $gw->inquiry( (string) $attempt['authority'] );
			if ( ! empty( $inq['ok'] ) && ! empty( $inq['payable'] ) ) {
				self::finalize_attempt( $attempt, 'OK' );
				continue;
			}
			self::mark_attempt( (int) $attempt['id'], 'expired', null, array( 'inquiry' => $inq ) );
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_attention( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'payment_attempts' ) . " WHERE status IN ('paid_duplicate','amount_mismatch','paid_error') ORDER BY updated_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Latest payment row for an invoice (for admin display).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest_payment_for_invoice( int $invoice_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'payments' ) . ' WHERE invoice_id = %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$invoice_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function invoice_by_number( string $number ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE number = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				$number
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string,mixed>|null $meta Meta.
	 */
	private static function mark_attempt( int $id, string $status, ?string $ref_id, $meta = null ): void {
		global $wpdb;
		$data = array(
			'status'     => $status,
			'updated_at' => MNS_Time::now_utc(),
		);
		$fmt  = array( '%s', '%s' );
		if ( null !== $ref_id && '' !== $ref_id ) {
			$data['ref_id'] = $ref_id;
			$fmt[]          = '%s';
		}
		if ( in_array( $status, array( 'paid', 'paid_duplicate' ), true ) ) {
			$data['verified_at'] = MNS_Time::now_utc();
			$fmt[]               = '%s';
		}
		if ( null !== $meta ) {
			$data['meta'] = wp_json_encode( $meta );
			$fmt[]        = '%s';
		}
		$wpdb->update( MNS_DB::table( 'payment_attempts' ), $data, array( 'id' => $id ), $fmt, array( '%d' ) );
	}

	private static function sms_payment_ok( int $invoice_id ): void {
		global $wpdb;
		$inv = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', $invoice_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! is_array( $inv ) || ! class_exists( 'MNS_Sms', true ) ) {
			return;
		}
		$uid = (int) $inv['account_user_id'];
		$phone = '';
		foreach ( array( 'mobile', 'drplus_mobile', 'billing_phone' ) as $mk ) {
			$v = get_user_meta( $uid, $mk, true );
			if ( is_string( $v ) && '' !== $v ) {
				$phone = $v;
				break;
			}
		}
		if ( '' === $phone ) {
			MNS_Log::info( 'sms_payment_ok_no_phone', array( 'invoice_id' => $invoice_id ) );
			return;
		}
		MNS_Sms::send(
			MNS_Sms::EVT_PAYMENT_OK,
			$phone,
			array(
				'number' => (string) $inv['number'],
				'amount' => (string) (int) $inv['amount'],
			),
			'mns:payment_ok:' . $invoice_id . ':' . $phone
		);
	}
}
