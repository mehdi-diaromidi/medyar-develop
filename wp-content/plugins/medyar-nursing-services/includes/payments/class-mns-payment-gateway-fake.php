<?php
/**
 * Fake payment gateway for self-test only (RUN-F / T-F.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Payment_Gateway_Fake implements MNS_Payment_Gateway {

	/** @var int Verify response code (100 or 101). */
	public static $verify_code = 100;

	/** @var bool Force amount mismatch on verify. */
	public static $force_amount_mismatch = false;

	/** @var string Inquiry status: PAID|VERIFIED|NOK|EXPIRED… */
	public static $inquiry_status = 'NOK';

	/** @var bool Initiate failure. */
	public static $fail_initiate = false;

	public static function reset(): void {
		self::$verify_code            = 100;
		self::$force_amount_mismatch  = false;
		self::$inquiry_status         = 'NOK';
		self::$fail_initiate          = false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function initiate( array $attempt ): array {
		if ( self::$fail_initiate ) {
			return array(
				'ok'    => false,
				'error' => 'درگاه آزمایشی: شروع پرداخت ناموفق.',
				'code'  => -1,
			);
		}
		$auth = 'FAKE' . strtoupper( wp_generate_password( 20, false, false ) );
		return array(
			'ok'           => true,
			'authority'    => $auth,
			'redirect_url' => 'https://example.test/mns-fake-pay/' . rawurlencode( $auth ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify( string $authority, int $amount, string $status ): array {
		if ( 'OK' !== strtoupper( $status ) ) {
			return array(
				'ok'    => false,
				'error' => 'پرداخت توسط کاربر لغو شد یا ناموفق بود.',
			);
		}
		if ( self::$force_amount_mismatch ) {
			return array(
				'ok'     => false,
				'code'   => -50,
				'error'  => 'مبلغ verify با مبلغ پرداخت‌شده یکسان نیست.',
				'amount' => max( 0, $amount - 1 ),
			);
		}
		$code = (int) self::$verify_code;
		return array(
			'ok'               => true,
			'code'             => $code,
			'ref_id'           => 'FREF-' . substr( preg_replace( '/[^A-Z0-9]/i', '', $authority ), -10 ),
			'already_verified' => ( 101 === $code ),
			'amount'           => $amount,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function inquiry( string $authority ): array {
		$status = strtoupper( (string) self::$inquiry_status );
		$payable = in_array( $status, array( 'PAID', 'VERIFIED', 'OK' ), true );
		return array(
			'ok'      => true,
			'status'  => $status,
			'code'    => 100,
			'payable' => $payable,
		);
	}
}
