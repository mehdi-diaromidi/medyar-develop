<?php
/**
 * Self-test: invoices and payments (M5). Supervisor-written.
 *
 * Uses the order fixtures from MNS_Selftest_Orders; all data is mns_selftest_* and purged in finally.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Selftest_Money {

	public static function register_all(): void {
		MNS_Selftest::register( 'AC-M5-1', 'Invoice needs an amount; issue is idempotent', array( __CLASS__, 'ac_m5_1' ) );
		MNS_Selftest::register( 'AC-M5-2', 'Payment recorded once (double pay rejected)', array( __CLASS__, 'ac_m5_2' ) );
		MNS_Selftest::register( 'AC-M5-3', 'Invoice visible only to its account', array( __CLASS__, 'ac_m5_3' ) );
		MNS_Selftest::register( 'AC-M4-1', 'Nurse step saving: state, ownership, whitelist', array( __CLASS__, 'ac_m4_1' ) );
	}

	/**
	 * Create a green order for the fixture patient and move it to service_ended (fixture state, not the machine under test).
	 *
	 * @param array<string,mixed> $ctx Context from MNS_Selftest_Orders::seed_green().
	 * @return array<string,mixed> Order row.
	 */
	private static function ended_order( array $ctx ): array {
		MNS_Settings::set( 'assignment_mode', 'auto' );
		$res = MNS_Selftest_Orders::rest_json( 'POST', '/orders', (int) $ctx['patient_uid'], MNS_Selftest_Orders::order_body( $ctx ), array( 'Idempotency-Key' => 'm5-' . wp_generate_password( 8, false ) ) );
		$code = (string) ( $res['data']['data']['order']['code'] ?? '' );
		$row  = '' !== $code ? MNS_Selftest_Orders::order_by_code( $code ) : null;
		if ( ! $row ) {
			throw new \RuntimeException( 'order_not_created:' . wp_json_encode( $res['data']['error'] ?? null ) );
		}
		global $wpdb;
		$wpdb->update( MNS_DB::table( 'orders' ), array( 'status' => 'service_ended' ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
		$fresh = MNS_Selftest_Orders::order_by_code( $code );
		return is_array( $fresh ) ? $fresh : $row;
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m5_1(): array {
		$ctx = null;
		try {
			$ctx   = MNS_Selftest_Orders::seed_green();
			$order = self::ended_order( $ctx );
			$oid   = (int) $order['id'];

			// 1) No amount ⇒ validation error, and no invoice row.
			try {
				MNS_Invoices::issue_for_order( $oid, 1 );
				return array( 'ok' => false, 'detail' => 'zero_amount_accepted' );
			} catch ( MNS_Error $e ) {
				if ( 'amount' !== (string) $e->field() ) {
					return array( 'ok' => false, 'detail' => 'wrong_error_field=' . (string) $e->field() );
				}
			}
			global $wpdb;
			$count0 = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE order_id = %d', $oid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 0 !== $count0 ) {
				return array( 'ok' => false, 'detail' => 'invoice_row_after_reject=' . $count0 );
			}

			// 2) With an amount ⇒ unpaid invoice with a share.
			$inv = MNS_Invoices::issue_for_order( $oid, 1, 500000 );
			if ( 'unpaid' !== (string) ( $inv['status'] ?? '' ) || 500000 !== (int) ( $inv['amount'] ?? 0 ) || (int) ( $inv['nurse_share'] ?? 0 ) <= 0 ) {
				return array( 'ok' => false, 'detail' => 'bad_invoice=' . wp_json_encode( array( $inv['status'] ?? null, $inv['amount'] ?? null, $inv['nurse_share'] ?? null ) ) );
			}

			// 3) Issuing again (even with another amount) returns the same invoice and creates no second one.
			$again = MNS_Invoices::issue_for_order( $oid, 1, 999999 );
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE order_id = %d', $oid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( (int) $again['id'] !== (int) $inv['id'] || 1 !== $count || 500000 !== (int) $again['amount'] ) {
				return array( 'ok' => false, 'detail' => 'not_idempotent count=' . $count );
			}
			$after = MNS_Selftest_Orders::order_by_code( (string) $order['code'] );
			if ( 'invoiced' !== (string) ( $after['status'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'order_status_after_issue=' . (string) ( $after['status'] ?? '' ) );
			}

			// An order that has not finished the visit cannot be invoiced.
			$ctx2 = null;
			try {
				$ctx2 = MNS_Selftest_Orders::seed_green();
				MNS_Settings::set( 'assignment_mode', 'auto' );
				$res2 = MNS_Selftest_Orders::rest_json( 'POST', '/orders', (int) $ctx2['patient_uid'], MNS_Selftest_Orders::order_body( $ctx2 ), array( 'Idempotency-Key' => 'm5e-' . wp_generate_password( 8, false ) ) );
				$row2 = MNS_Selftest_Orders::order_by_code( (string) ( $res2['data']['data']['order']['code'] ?? '' ) );
				if ( ! $row2 ) {
					return array( 'ok' => false, 'detail' => 'early_order_missing' );
				}
				$early_rejected = false;
				try {
					MNS_Invoices::issue_for_order( (int) $row2['id'], 1, 100000 );
				} catch ( MNS_Error $e ) {
					$early_rejected = true;
				}
				if ( ! $early_rejected ) {
					return array( 'ok' => false, 'detail' => 'unfinished_order_invoiced' );
				}
			} finally {
				MNS_Selftest_Orders::cleanup_ctx( $ctx2 );
			}
			return array( 'ok' => true, 'detail' => 'amount_required;idempotent;order_invoiced;early_rejected;invoice=' . $inv['number'] );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			MNS_Selftest_Orders::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m5_2(): array {
		$ctx = null;
		try {
			$ctx   = MNS_Selftest_Orders::seed_green();
			$order = self::ended_order( $ctx );
			$oid   = (int) $order['id'];
			$inv   = MNS_Invoices::issue_for_order( $oid, 1, 300000 );
			$iid   = (int) $inv['id'];

			$paid = MNS_Invoices::record_payment( $iid, 'cash', 'selftest', 1 );
			if ( 'paid' !== (string) ( $paid['status'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'first_pay_status=' . (string) ( $paid['status'] ?? '' ) );
			}

			$rejected = false;
			try {
				MNS_Invoices::record_payment( $iid, 'cash', 'selftest-again', 1 );
			} catch ( MNS_Error $e ) {
				$rejected = true;
			}
			if ( ! $rejected ) {
				return array( 'ok' => false, 'detail' => 'second_payment_accepted' );
			}

			global $wpdb;
			$pays = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MNS_DB::table( 'payments' ) . ' WHERE invoice_id = %d', $iid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$amt  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT amount FROM ' . MNS_DB::table( 'payments' ) . ' WHERE invoice_id = %d LIMIT 1', $iid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 1 !== $pays || 300000 !== $amt ) {
				return array( 'ok' => false, 'detail' => 'payments=' . $pays . ';amount=' . $amt );
			}
			$after = MNS_Selftest_Orders::order_by_code( (string) $order['code'] );
			if ( 'paid' !== (string) ( $after['status'] ?? '' ) ) {
				return array( 'ok' => false, 'detail' => 'order_not_paid=' . (string) ( $after['status'] ?? '' ) );
			}
			return array( 'ok' => true, 'detail' => 'one_payment;order=paid' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			// payments/invoices rows belong to the test account; remove them explicitly.
			self::purge_money( $ctx );
			MNS_Selftest_Orders::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m5_3(): array {
		$ctx = null;
		try {
			$ctx   = MNS_Selftest_Orders::seed_green();
			$order = self::ended_order( $ctx );
			$inv   = MNS_Invoices::issue_for_order( (int) $order['id'], 1, 250000 );

			$mine = MNS_Invoices::list_for_account( (int) $ctx['patient_uid'] );
			$ids  = array_map(
				static function ( $r ) {
					return (int) $r['id'];
				},
				$mine
			);
			if ( ! in_array( (int) $inv['id'], $ids, true ) ) {
				return array( 'ok' => false, 'detail' => 'owner_cannot_see' );
			}

			$other = wp_insert_user(
				array(
					'user_login' => 'mns_selftest_o_' . wp_generate_password( 6, false ),
					'user_pass'  => wp_generate_password( 24, true, true ),
					'user_email' => 'mns_selftest_o_' . wp_generate_password( 6, false ) . '@selftest.invalid',
				)
			);
			if ( is_wp_error( $other ) ) {
				return array( 'ok' => false, 'detail' => 'user_create_failed' );
			}
			$ctx['uids'][] = (int) $other;
			$theirs = MNS_Invoices::list_for_account( (int) $other );
			foreach ( $theirs as $r ) {
				if ( (int) $r['id'] === (int) $inv['id'] ) {
					return array( 'ok' => false, 'detail' => 'other_account_sees_invoice' );
				}
			}
			return array( 'ok' => true, 'detail' => 'isolated' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			self::purge_money( $ctx );
			MNS_Selftest_Orders::cleanup_ctx( $ctx );
		}
	}

	/**
	 * @return array{ok:bool,detail:string}
	 */
	public static function ac_m4_1(): array {
		$ctx = null;
		try {
			$ctx   = MNS_Selftest_Orders::seed_green();
			$order = self::ended_order( $ctx );
			$oid   = (int) $order['id'];
			$nurse = (int) $ctx['nurse_uid'];
			global $wpdb;
			$set_status = static function ( string $status ) use ( $wpdb, $oid ) {
				$wpdb->update( MNS_DB::table( 'orders' ), array( 'status' => $status ), array( 'id' => $oid ), array( '%s' ), array( '%d' ) );
			};
			$expect = static function ( callable $fn, string $label ) {
				try {
					$fn();
				} catch ( MNS_Error $e ) {
					return null;
				}
				return $label;
			};

			// Visit not started yet ⇒ rejected.
			$set_status( 'assigned' );
			$bad = $expect(
				static function () use ( $oid, $nurse ) {
					MNS_Execution::save_step( $oid, 'checklist', array( 'state' => 'done' ), $nurse, 'nurse' );
				},
				'saved_before_start'
			);
			if ( $bad ) {
				return array( 'ok' => false, 'detail' => $bad );
			}

			// In progress: checklist is open; junk keys and a forged state are ignored.
			$set_status( 'in_progress' );
			$steps = MNS_Execution::save_step( $oid, 'checklist', array( 'state' => 'approved', 'evil' => 'x', 'items' => array( 'a' => '<b>ok</b>' ) ), $nurse, 'nurse' );
			if ( isset( $steps['checklist']['evil'] ) || 'approved' === ( $steps['checklist']['state'] ?? '' ) || empty( $steps['checklist']['items'] ) ) {
				return array( 'ok' => false, 'detail' => 'payload_not_whitelisted' );
			}
			if ( false !== strpos( wp_json_encode( $steps['checklist']['items'] ), '<b>' ) ) {
				return array( 'ok' => false, 'detail' => 'html_not_sanitized' );
			}

			// The report step stays locked until the visit has ended.
			$bad = $expect(
				static function () use ( $oid, $nurse ) {
					MNS_Execution::save_step( $oid, 'report', array( 'state' => 'draft' ), $nurse, 'nurse' );
				},
				'report_open_too_early'
			);
			if ( $bad ) {
				return array( 'ok' => false, 'detail' => $bad );
			}

			// Another nurse can never write to this order.
			$bad = $expect(
				static function () use ( $oid, $nurse ) {
					MNS_Execution::save_step( $oid, 'checklist', array( 'state' => 'done' ), $nurse + 99999, 'nurse' );
				},
				'foreign_nurse_wrote'
			);
			if ( $bad ) {
				return array( 'ok' => false, 'detail' => $bad );
			}

			// Oversized payload is refused.
			$bad = $expect(
				static function () use ( $oid, $nurse ) {
					MNS_Execution::save_step( $oid, 'checklist', array( 'items' => array( str_repeat( 'x', 30000 ) ) ), $nurse, 'nurse' );
				},
				'oversized_accepted'
			);
			if ( $bad ) {
				return array( 'ok' => false, 'detail' => $bad );
			}

			// After completion nothing can be edited any more.
			$set_status( 'completed' );
			$bad = $expect(
				static function () use ( $oid, $nurse ) {
					MNS_Execution::save_step( $oid, 'report', array( 'state' => 'done' ), $nurse, 'nurse' );
				},
				'edited_after_completion'
			);
			if ( $bad ) {
				return array( 'ok' => false, 'detail' => $bad );
			}
			return array( 'ok' => true, 'detail' => 'state_owner_whitelist_size_ok' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'detail' => get_class( $e ) . ':' . $e->getMessage() );
		} finally {
			MNS_Selftest_Orders::cleanup_ctx( $ctx );
		}
	}

	/**
	 * Delete invoices/payments created for the test patient.
	 *
	 * @param array<string,mixed>|null $ctx Context.
	 */
	private static function purge_money( $ctx ): void {
		if ( ! is_array( $ctx ) || empty( $ctx['patient_uid'] ) ) {
			return;
		}
		global $wpdb;
		$inv_t = MNS_DB::table( 'invoices' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$inv_t} WHERE account_user_id = %d", (int) $ctx['patient_uid'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $ids as $id ) {
			$wpdb->delete( MNS_DB::table( 'payments' ), array( 'invoice_id' => (int) $id ), array( '%d' ) );
			$wpdb->delete( $inv_t, array( 'id' => (int) $id ), array( '%d' ) );
		}
	}
}
