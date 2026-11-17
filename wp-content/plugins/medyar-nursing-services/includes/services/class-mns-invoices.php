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
	public static function issue_for_order( int $order_id, int $actor = 0 ): array {
		$order = MNS_Order_Machine::get_order( $order_id );
		if ( ! $order ) {
			throw MNS_Error::not_found();
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
		$svc   = MNS_Services::get( (int) $order['service_id'] );
		$amount = 0;
		if ( $svc && isset( $svc['price'] ) ) {
			$amount = (int) $svc['price'];
		}
		$share_pct = (float) MNS_Settings::get( 'nurse_share_percent' );
		if ( $share_pct <= 0 ) {
			$share_pct = 90;
		}
		$share = (int) round( $amount * ( $share_pct / 100 ) );
		$now   = MNS_Time::now_utc();
		$number = 'INV-' . (string) $order['code'];
		global $wpdb;
		$existing = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE order_id = %d LIMIT 1', $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( is_array( $existing ) ) {
			return $existing;
		}
		$wpdb->insert(
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
		$wpdb->insert(
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
		$wpdb->update(
			MNS_DB::table( 'invoices' ),
			array(
				'status'     => 'paid',
				'paid_at'    => $now,
				'updated_at' => $now,
			),
			array( 'id' => $invoice_id )
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
