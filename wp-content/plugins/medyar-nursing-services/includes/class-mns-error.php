<?php
/**
 * Domain / API error (T-0.4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Error extends \Exception {

	/** @var string */
	protected $error_code;

	/** @var int */
	protected $http;

	/** @var string|null */
	protected $field;

	/** @var array<string,mixed> */
	protected $data;

	/**
	 * @param string               $code       ERR_* code.
	 * @param string               $message_fa Persian user message.
	 * @param int                  $http       HTTP status.
	 * @param string|null          $field      Optional field name.
	 * @param array<string,mixed>  $data       Extra payload.
	 */
	public function __construct( string $code, string $message_fa, int $http = 400, ?string $field = null, array $data = array() ) {
		parent::__construct( $message_fa, $http );
		$this->error_code = $code;
		$this->http       = $http;
		$this->field      = $field;
		$this->data       = $data;
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function http(): int {
		return $this->http;
	}

	public function field(): ?string {
		return $this->field;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Envelope error fragment.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$err = array(
			'code'    => $this->error_code,
			'message' => $this->getMessage(),
		);
		if ( null !== $this->field && '' !== $this->field ) {
			$err['field'] = $this->field;
		}
		if ( ! empty( $this->data ) ) {
			$err['data'] = $this->data;
		}
		return $err;
	}

	public static function auth( string $msg = 'برای ادامه وارد شوید.' ): self {
		return new self( 'ERR_AUTH', $msg, 401 );
	}

	public static function forbidden( string $msg = 'اجازهٔ این کار را ندارید.' ): self {
		return new self( 'ERR_FORBIDDEN', $msg, 403 );
	}

	public static function not_found( string $msg = 'یافت نشد.' ): self {
		return new self( 'ERR_NOT_FOUND', $msg, 404 );
	}

	public static function validation( string $msg, ?string $field = null ): self {
		return new self( 'ERR_VALIDATION', $msg, 422, $field );
	}

	public static function bad_state( string $msg = 'این سفارش دیگر این اقدام را نمی‌پذیرد.' ): self {
		return new self( 'ERR_BAD_STATE', $msg, 409 );
	}

	public static function conflict( string $code, string $msg, array $data = array() ): self {
		return new self( $code, $msg, 409, null, $data );
	}

	public static function rate_limit( string $msg = 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.' ): self {
		return new self( 'ERR_RATE_LIMIT', $msg, 429 );
	}

	public static function server( string $msg = 'خطای داخلی؛ لطفاً دوباره تلاش کنید.' ): self {
		return new self( 'ERR_SERVER', $msg, 500 );
	}
}
