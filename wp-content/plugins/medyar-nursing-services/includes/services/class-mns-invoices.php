<?php
/**
 * Invoices + payments (M5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Invoices {

	/**
	 * @return array<string,mixed>
	 */
	public static function issue_for_order( int $order_id, int $actor = 0, int $amount_override = 0 ): array {
		$order = MNS_Order_Machine::get_order( $order_id );
		if ( ! $order ) {
			throw MNS_Error::not_found();
		}
		// Supervisor fix: idempotent first (an existing invoice is returned untouched), and never issue a 0-toman invoice.
		global $wpdb;
		$already = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE order_id = %d LIMIT 1', $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( is_array( $already ) ) {
			return $already;
		}
		$svc_pre = MNS_Services::get( (int) $order['service_id'] );
		$amount_pre = $amount_override > 0 ? $amount_override : ( $svc_pre && isset( $svc_pre['price'] ) ? (int) $svc_pre['price'] : 0 );
		if ( $amount_pre <= 0 ) {
			throw MNS_Error::validation( 'مبلغ نهایی فاکتور را وارد کنید (تومان).', 'amount' );
		}
		$status = (string) $order['status'];
		if ( ! in_array( $status, array( 'completed', 'service_ended', 'needs_review' ), true ) ) {
			if ( 'completed' !== $status ) {
				MNS_Order_Machine::transition(
					'complete',
					array(
						'order_id'      => $order_id,
						'actor_user_id' => $actor,
						'actor_role'    => 'admin',
					)
				);
				$order = MNS_Order_Machine::get_order( $order_id );
			}
		}
		$amount = $amount_pre;
		$share_pct = (float) MNS_Settings::get( 'nurse_share_percent' );
		if ( $share_pct <= 0 ) {
			$share_pct = 90;
		}
		$share = (int) round( $amount * ( $share_pct / 100 ) );
		$now   = MNS_Time::now_utc();
		$number = 'INV-' . (string) $order['code'];
		$inserted = $wpdb->insert(
			MNS_DB::table( 'invoices' ),
			array(
				'order_id'         => $order_id,
				'account_user_id'  => (int) $order['account_user_id'],
				'nurse_user_id'    => ! empty( $order['nurse_user_id'] ) ? (int) $order['nurse_user_id'] : null,
				'number'           => $number,
				'status'           => 'unpaid',
				'amount'           => $amount,
				'nurse_share'      => $share,
				'issued_at'        => $now,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		if ( false === $inserted ) {
			// A concurrent issue (UNIQUE number) may have won; return that invoice, otherwise fail loudly.
			$race = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE order_id = %d LIMIT 1', $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL
				ARRAY_A
			);
			if ( is_array( $race ) ) {
				return $race;
			}
			throw MNS_Error::server( 'صدور فاکتور ممکن نشد.' );
		}
		$id = (int) $wpdb->insert_id;
		try {
			MNS_Order_Machine::transition(
				'invoice',
				array(
					'order_id'      => $order_id,
					'actor_user_id' => $actor,
					'actor_role'    => 'admin',
				)
			);
		} catch ( MNS_Error $e ) {
			// Order may already be invoiced.
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $row ) ? $row : array( 'id' => $id, 'number' => $number );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_unpaid( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . " WHERE status = 'unpaid' ORDER BY issued_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for_account( int $account_user_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE account_user_id = %d ORDER BY issued_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$account_user_id,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function record_payment( int $invoice_id, string $method, string $tracking, int $actor ): array {
		global $wpdb;
		$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $inv ) || 'unpaid' !== (string) $inv['status'] ) {
			throw MNS_Error::bad_state();
		}
		$now = MNS_Time::now_utc();
		// Supervisor fix: atomic + guarded — only the request that flips unpaid→paid records the payment (no double payments).
		MNS_DB::tx(
			static function () use ( $wpdb, $invoice_id, $method, $tracking, $actor, $inv, $now ) {
				$rows = $wpdb->query(
					$wpdb->prepare(
						'UPDATE ' . MNS_DB::table( 'invoices' ) . " SET status = 'paid', paid_at = %s, updated_at = %s WHERE id = %d AND status = 'unpaid'", // phpcs:ignore WordPress.DB.PreparedSQL
						$now,
						$now,
						$invoice_id
					)
				);
				if ( 1 !== (int) $rows ) {
					throw MNS_Error::bad_state();
				}
				$ins = $wpdb->insert(
					MNS_DB::table( 'payments' ),
					array(
						'invoice_id'    => $invoice_id,
						'method'        => sanitize_key( $method ),
						'amount'        => (int) $inv['amount'],
						'tracking'      => sanitize_text_field( $tracking ),
						'actor_user_id' => $actor,
						'created_at'    => $now,
					)
				);
				if ( false === $ins ) {
					throw MNS_Error::server( 'ثبت پرداخت ممکن نشد.' );
				}
				return true;
			}
		);
		try {
			MNS_Order_Machine::transition(
				'pay',
				array(
					'order_id'      => (int) $inv['order_id'],
					'actor_user_id' => $actor,
					'actor_role'    => 'admin',
				)
			);
		} catch ( MNS_Error $e ) {
			// ignore if order not in invoiced.
		}
		if ( class_exists( 'MNS_Loyalty', true ) && ! empty( $inv['account_user_id'] ) ) {
			try {
				MNS_Loyalty::add_stamp( (int) $inv['account_user_id'] );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}
		// Best-effort nurse share → Medyar wallet when available.
		if ( ! empty( $inv['nurse_user_id'] ) && (int) $inv['nurse_share'] > 0 && class_exists( '\MTW_Wallet', false ) ) {
			do_action( 'mns_nurse_share_credited', (int) $inv['nurse_user_id'], (int) $inv['nurse_share'], $invoice_id );
		}
		$fresh = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', $invoice_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return is_array( $fresh ) ? $fresh : $inv;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function nurse_shares( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . " WHERE status = 'paid' AND nurse_share > 0 ORDER BY paid_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
