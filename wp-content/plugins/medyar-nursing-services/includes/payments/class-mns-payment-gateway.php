<?php
/**
 * Payment gateway contract (RUN-F / T-F.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface MNS_Payment_Gateway {

	/**
	 * Start a payment. $attempt keys: id, invoice_id, amount (IRT toman), authority?, callback_url, description, mobile, order_id.
	 *
	 * @param array<string,mixed> $attempt Attempt row (+ callback_url etc.).
	 * @return array{ok:bool,authority?:string,redirect_url?:string,error?:string,code?:int|string}
	 */
	public function initiate( array $attempt ): array;

	/**
	 * Verify after callback. Amount MUST be the stored attempt amount (IRT).
	 *
	 * @return array{ok:bool,code?:int,ref_id?:string,already_verified?:bool,error?:string,amount?:int}
	 */
	public function verify( string $authority, int $amount, string $status ): array;

	/**
	 * Inquire without verifying.
	 *
	 * @return array{ok:bool,status?:string,code?:int|string,error?:string,payable?:bool}
	 */
	public function inquiry( string $authority ): array;
}

/**
 * Resolve the active gateway (Fake during self-test via filter).
 */
final class MNS_Payment_Gateways {

	public static function get(): MNS_Payment_Gateway {
		$gw = new MNS_Payment_Gateway_Zarinpal();
		/**
		 * Swap gateway (self-test uses Fake).
		 *
		 * @param MNS_Payment_Gateway $gw Gateway.
		 */
		$filtered = apply_filters( 'mns_payment_gateway', $gw );
		return $filtered instanceof MNS_Payment_Gateway ? $filtered : $gw;
	}
}
