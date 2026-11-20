<?php
/**
 * Credit nurse share to Medyar Toman Wallet (RUN-F / T-F.5).
 *
 * Valid MTW operation_type (from wallet_validate_operation_type / wallet_allowed_operation_types):
 * booking_payout — used for specialist/nurse visit income in MTW.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Wallet {

	public const OP_TYPE = 'booking_payout';

	/**
	 * @param int $nurse_id   Nurse user id.
	 * @param int $share      Amount (toman).
	 * @param int $invoice_id Invoice id.
	 */
	public static function on_share_credited( int $nurse_id, int $share, int $invoice_id ): void {
		if ( $nurse_id <= 0 || $share <= 0 || $invoice_id <= 0 ) {
			return;
		}
		if ( ! class_exists( 'Wallet_Manager', false ) || ! is_callable( array( 'Wallet_Manager', 'get_instance' ) ) ) {
			return;
		}
		if ( function_exists( 'wallet_validate_operation_type' ) ) {
			$check = wallet_validate_operation_type( self::OP_TYPE );
			if ( is_wp_error( $check ) ) {
				MNS_Log::error(
					'nurse_share_bad_op_type',
					array(
						'invoice_id' => $invoice_id,
						'op'         => self::OP_TYPE,
						'err'        => $check->get_error_message(),
					)
				);
				self::flag_needs_followup( $invoice_id, 'bad_op_type' );
				return;
			}
		}

		global $wpdb;
		$inv = $wpdb->get_row(
			$wpdb->prepare( 'SELECT meta FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', $invoice_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! is_array( $inv ) ) {
			return;
		}
		$meta = array();
		if ( ! empty( $inv['meta'] ) ) {
			$decoded = json_decode( (string) $inv['meta'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		if ( ! empty( $meta['credited_at'] ) ) {
			return;
		}

		$meta['credited_at'] = MNS_Time::now_utc();
		$json                = wp_json_encode( $meta );
		// Guarded: only the first writer sets credited_at.
		$rows = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . MNS_DB::table( 'invoices' ) . ' SET meta = %s, updated_at = %s WHERE id = %d AND (meta IS NULL OR meta NOT LIKE %s)', // phpcs:ignore WordPress.DB.PreparedSQL
				$json,
				MNS_Time::now_utc(),
				$invoice_id,
				'%"credited_at"%'
			)
		);
		if ( 1 !== (int) $rows ) {
			return;
		}

		try {
			$wm = Wallet_Manager::get_instance();
			$ok = $wm->add_balance(
				$nurse_id,
				$share,
				self::OP_TYPE,
				array(
					'status'           => 'completed',
					'transaction_code' => 'mns-inv-' . $invoice_id,
					'description'      => 'سهم پرستار فاکتور #' . $invoice_id,
					'meta'             => array(
						'type'       => self::OP_TYPE,
						'invoice_id' => $invoice_id,
						'source'     => 'mns',
					),
				)
			);
			if ( is_wp_error( $ok ) ) {
				MNS_Log::error(
					'nurse_share_credit_failed',
					array(
						'invoice_id' => $invoice_id,
						'nurse_id'   => $nurse_id,
						'err'        => $ok->get_error_message(),
					)
				);
				self::flag_needs_followup( $invoice_id, $ok->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			MNS_Log::error(
				'nurse_share_credit_exception',
				array(
					'invoice_id' => $invoice_id,
					'err'        => $e->getMessage(),
				)
			);
			self::flag_needs_followup( $invoice_id, $e->getMessage() );
		}
	}

	private static function flag_needs_followup( int $invoice_id, string $reason ): void {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT meta FROM ' . MNS_DB::table( 'invoices' ) . ' WHERE id = %d', $invoice_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$meta = array();
		if ( is_array( $row ) && ! empty( $row['meta'] ) ) {
			$decoded = json_decode( (string) $row['meta'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		$meta['wallet_followup'] = array(
			'at'     => MNS_Time::now_utc(),
			'reason' => $reason,
		);
		// Keep credited_at if set so we don't retry endlessly; mark followup for admin.
		$wpdb->update(
			MNS_DB::table( 'invoices' ),
			array(
				'meta'       => wp_json_encode( $meta ),
				'updated_at' => MNS_Time::now_utc(),
			),
			array( 'id' => $invoice_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
