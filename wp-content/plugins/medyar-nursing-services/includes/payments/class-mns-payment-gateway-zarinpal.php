<?php
/**
 * Zarinpal v4 gateway — logical copy of MTW Wallet_Zarinpal_Gateway (no card_pan).
 * Amounts are IRT (toman), same as MTW initiate_payment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Payment_Gateway_Zarinpal implements MNS_Payment_Gateway {

	public const REQUEST_URL  = 'https://payment.zarinpal.com/pg/v4/payment/request.json';
	public const VERIFY_URL   = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';
	public const INQUIRY_URL  = 'https://payment.zarinpal.com/pg/v4/payment/inquiry.json';
	public const STARTPAY_URL = 'https://payment.zarinpal.com/pg/StartPay/';
	public const HTTP_TIMEOUT = 30;

	/** @var string */
	private $merchant_id;

	public function __construct() {
		$mtw = sanitize_text_field( (string) get_option( 'mtw_zarinpal_merchant_id', '' ) );
		if ( '' !== $mtw ) {
			$this->merchant_id = $mtw;
			return;
		}
		$settings = get_option( 'woocommerce_WC_ZPal_settings', array() );
		$this->merchant_id = ( is_array( $settings ) && ! empty( $settings['merchantcode'] ) )
			? sanitize_text_field( (string) $settings['merchantcode'] )
			: '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function initiate( array $attempt ): array {
		if ( '' === $this->merchant_id ) {
			return array(
				'ok'    => false,
				'error' => 'درگاه پرداخت پیکربندی نشده',
			);
		}
		$amount = (int) ( $attempt['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return array(
				'ok'    => false,
				'error' => 'مبلغ نامعتبر است.',
			);
		}
		$callback = (string) ( $attempt['callback_url'] ?? '' );
		if ( '' === $callback ) {
			return array(
				'ok'    => false,
				'error' => 'آدرس بازگشت تنظیم نشده است.',
			);
		}
		$description = (string) ( $attempt['description'] ?? 'پرداخت فاکتور خدمات پرستاری مدیار' );
		if ( function_exists( 'mb_substr' ) ) {
			$description = mb_substr( $description, 0, 500 );
		} else {
			$description = substr( $description, 0, 500 );
		}

		$metadata = array();
		if ( ! empty( $attempt['mobile'] ) ) {
			$metadata['mobile'] = sanitize_text_field( (string) $attempt['mobile'] );
		}
		if ( ! empty( $attempt['order_id'] ) ) {
			$metadata['order_id'] = sanitize_text_field( (string) $attempt['order_id'] );
		}

		$payload = array(
			'merchant_id'  => $this->merchant_id,
			'amount'       => $amount,
			'currency'     => 'IRT',
			'callback_url' => $callback,
			'description'  => $description,
		);
		if ( $metadata ) {
			$payload['metadata'] = $metadata;
		}

		$response = $this->post_json( self::REQUEST_URL, $payload );
		if ( is_wp_error( $response ) ) {
			MNS_Log::error(
				'zp_request_failed',
				array( 'err' => $response->get_error_message() )
			);
			return array(
				'ok'    => false,
				'error' => 'خطا در اتصال به زرین‌پال.',
			);
		}

		$code      = isset( $response['data']['code'] ) ? (int) $response['data']['code'] : 0;
		$authority = isset( $response['data']['authority'] ) ? (string) $response['data']['authority'] : '';
		if ( 100 !== $code || '' === $authority ) {
			$err_code = $this->extract_error_code( $response );
			MNS_Log::warn(
				'zp_request_error',
				array( 'code' => $err_code )
			);
			return array(
				'ok'    => false,
				'error' => $this->map_error_message( $err_code ),
				'code'  => $err_code,
			);
		}

		return array(
			'ok'           => true,
			'authority'    => $authority,
			'redirect_url' => self::STARTPAY_URL . $authority,
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
		if ( '' === $this->merchant_id ) {
			return array(
				'ok'    => false,
				'error' => 'درگاه پرداخت پیکربندی نشده',
			);
		}
		if ( '' === $authority || $amount <= 0 ) {
			return array(
				'ok'    => false,
				'error' => 'اطلاعات بازگشتی از درگاه ناقص است.',
			);
		}

		$response = $this->post_json(
			self::VERIFY_URL,
			array(
				'merchant_id' => $this->merchant_id,
				'amount'      => $amount,
				'authority'   => $authority,
			)
		);
		if ( is_wp_error( $response ) ) {
			MNS_Log::error( 'zp_verify_failed', array( 'err' => $response->get_error_message() ) );
			return array(
				'ok'    => false,
				'error' => 'خطا در تأیید پرداخت با زرین‌پال.',
			);
		}

		$code = isset( $response['data']['code'] ) ? (int) $response['data']['code'] : $this->extract_error_code( $response );
		if ( 100 === $code || 101 === $code ) {
			$ref = isset( $response['data']['ref_id'] ) ? (string) $response['data']['ref_id'] : '';
			return array(
				'ok'               => true,
				'code'             => $code,
				'ref_id'           => $ref,
				'already_verified' => ( 101 === $code ),
				'amount'           => $amount,
			);
		}

		return array(
			'ok'    => false,
			'error' => $this->map_error_message( $code ),
			'code'  => $code,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function inquiry( string $authority ): array {
		$authority = sanitize_text_field( $authority );
		if ( '' === $this->merchant_id || '' === $authority ) {
			return array(
				'ok'    => false,
				'error' => 'اطلاعات استعلام ناقص است.',
			);
		}
		$response = $this->post_json(
			self::INQUIRY_URL,
			array(
				'merchant_id' => $this->merchant_id,
				'authority'   => $authority,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => $response->get_error_message(),
			);
		}
		$code = isset( $response['data']['code'] ) ? (int) $response['data']['code'] : $this->extract_error_code( $response );
		if ( 100 !== $code ) {
			return array(
				'ok'    => false,
				'code'  => $code,
				'error' => $this->map_error_message( $code ),
			);
		}
		$status  = isset( $response['data']['status'] ) ? strtoupper( (string) $response['data']['status'] ) : '';
		$payable = in_array( $status, array( 'PAID', 'VERIFIED', 'OK' ), true );
		return array(
			'ok'      => true,
			'status'  => $status,
			'code'    => $code,
			'payable' => $payable,
		);
	}

	/**
	 * @param array<string,mixed> $payload Payload.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function post_json( string $url, array $payload ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => self::HTTP_TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json', 'پاسخ نامعتبر از زرین‌پال دریافت شد.' );
		}
		return $data;
	}

	/**
	 * @param array<string,mixed> $data Response.
	 * @return int|string
	 */
	private function extract_error_code( array $data ) {
		if ( isset( $data['errors']['code'] ) ) {
			return is_numeric( $data['errors']['code'] ) ? (int) $data['errors']['code'] : $data['errors']['code'];
		}
		if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
			foreach ( $data['errors'] as $value ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $item ) {
						if ( is_numeric( $item ) || ( is_string( $item ) && preg_match( '/^-?\d+$/', $item ) ) ) {
							return (int) $item;
						}
					}
				} elseif ( is_numeric( $value ) ) {
					return (int) $value;
				}
			}
		}
		if ( isset( $data['data']['code'] ) ) {
			return (int) $data['data']['code'];
		}
		return 'نامشخص';
	}

	/**
	 * @param int|string $code Error code.
	 */
	private function map_error_message( $code ): string {
		$code = is_numeric( $code ) ? (int) $code : $code;
		$messages = array(
			-9  => 'خطای اعتبارسنجی درخواست پرداخت.',
			-10 => 'آی‌پی یا مرچنت‌کد پذیرنده صحیح نیست.',
			-11 => 'مرچنت‌کد فعال نیست.',
			-12 => 'تلاش بیش از حد؛ لطفاً کمی بعد دوباره تلاش کنید.',
			-13 => 'محدودیت تراکنش ترمینال؛ مدارک پذیرنده را تکمیل کنید.',
			-14 => 'آدرس بازگشت با دامنه ثبت‌شده درگاه مغایرت دارد. روی محیط توسعه ممکن است مرچنت فقط برای دامنهٔ اصلی ثبت شده باشد.',
			-15 => 'درگاه پرداخت تعلیق شده است.',
			-16 => 'سطح تأیید پذیرنده کافی نیست.',
			-17 => 'محدودیت پذیرنده در سطح فعلی.',
			-18 => 'آدرس ارجاع با دامنه ثبت‌شده مطابقت ندارد. روی محیط توسعه ممکن است مرچنت فقط برای دامنهٔ اصلی ثبت شده باشد.',
			-19 => 'امکان ایجاد تراکنش برای این ترمینال وجود ندارد.',
			-41 => 'حداکثر مبلغ پرداختی ۱۰۰ میلیون تومان است.',
			-50 => 'مبلغ verify با مبلغ پرداخت‌شده یکسان نیست.',
			-51 => 'پرداخت ناموفق است.',
			-52 => 'خطای غیرمنتظره؛ با پشتیبانی زرین‌پال تماس بگیرید.',
			-53 => 'پرداخت متعلق به این مرچنت‌کد نیست.',
			-54 => 'اتوریتی نامعتبر است.',
			-55 => 'تراکنش مورد نظر یافت نشد.',
			101 => 'این تراکنش قبلاً تأیید شده است.',
		);
		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ] . ' (کد ' . $code . ')';
		}
		return 'خطای زرین‌پال (کد ' . $code . ')';
	}
}
