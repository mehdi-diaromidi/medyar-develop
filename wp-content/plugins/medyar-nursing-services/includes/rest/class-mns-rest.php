<?php
/**
 * REST base: envelope, permissions, rate limit, idempotency (T-0.4 / 04 §1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_REST {

	public const NS = 'mns/v1';

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => array( __CLASS__, 'permission_logged_in' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function ping( $request ) {
		return self::success(
			array(
				'time' => gmdate( 'Y-m-d H:i:s' ),
				'ts'   => time(),
			),
			$request
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function permission_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'ERR_AUTH', 'برای ادامه وارد شوید.', array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function permission_patient() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'ERR_AUTH', 'برای ادامه وارد شوید.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( MNS_Roles::CAP_PATIENT_PANEL ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ERR_FORBIDDEN', 'اجازهٔ این کار را ندارید.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function permission_nurse() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'ERR_AUTH', 'برای ادامه وارد شوید.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( MNS_Roles::CAP_NURSE_PANEL ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ERR_FORBIDDEN', 'اجازهٔ این کار را ندارید.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function permission_admin() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'ERR_AUTH', 'برای ادامه وارد شوید.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( MNS_Roles::CAP_MANAGE ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ERR_FORBIDDEN', 'اجازهٔ این کار را ندارید.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Convenience aliases used by later cards.
	 *
	 * @return bool|WP_Error
	 */
	public static function patient() {
		return self::permission_patient();
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function nurse() {
		return self::permission_nurse();
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function admin() {
		return self::permission_admin();
	}

	/**
	 * @param mixed                $data    Payload.
	 * @param WP_REST_Request|null $request Request (for envelope).
	 * @param array<string,mixed>  $meta    Meta.
	 * @param int                  $status  HTTP.
	 * @return WP_REST_Response
	 */
	public static function success( $data, $request = null, array $meta = array(), int $status = 200 ) {
		$body = array(
			'ok'   => true,
			'data' => $data,
		);
		if ( ! empty( $meta ) ) {
			$body['meta'] = $meta;
		}
		return self::respond( $body, $status, $request );
	}

	/**
	 * @param MNS_Error|WP_Error   $error   Error.
	 * @param WP_REST_Request|null $request Request.
	 * @return WP_REST_Response
	 */
	public static function fail( $error, $request = null ) {
		if ( $error instanceof MNS_Error ) {
			$status = $error->http();
			$body   = array(
				'ok'    => false,
				'error' => $error->to_array(),
			);
			return self::respond( $body, $status, $request );
		}

		if ( $error instanceof WP_Error ) {
			$data   = $error->get_error_data();
			$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 400;
			$code   = $error->get_error_code();
			if ( ! is_string( $code ) || ! str_starts_with( $code, 'ERR_' ) ) {
				$code = 'ERR_SERVER';
			}
			$body = array(
				'ok'    => false,
				'error' => array(
					'code'    => $code,
					'message' => $error->get_error_message(),
				),
			);
			return self::respond( $body, $status, $request );
		}

		return self::respond(
			array(
				'ok'    => false,
				'error' => array(
					'code'    => 'ERR_SERVER',
					'message' => 'خطای داخلی؛ لطفاً دوباره تلاش کنید.',
				),
			),
			500,
			$request
		);
	}

	/**
	 * Rate limit by key. Returns true if allowed.
	 *
	 * @param string $bucket Unique bucket (e.g. a13:user:123).
	 * @param int    $max    Max hits.
	 * @param int    $window Seconds.
	 */
	public static function rate_limit( string $bucket, int $max, int $window = 60 ): bool {
		$key  = 'mns_rl_' . md5( $bucket );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return false;
		}
		set_transient( $key, $hits + 1, $window );
		return true;
	}

	/**
	 * Idempotency store/recall (24h).
	 *
	 * @param string     $key   Idempotency-Key header.
	 * @param mixed|null $store If non-null, store this payload.
	 * @return mixed|null Stored payload or null.
	 */
	public static function idempotency( string $key, $store = null ) {
		$key = sanitize_text_field( $key );
		if ( '' === $key ) {
			return null;
		}
		$tkey = 'mns_idem_' . md5( $key );
		if ( null !== $store ) {
			set_transient( $tkey, $store, DAY_IN_SECONDS );
			return $store;
		}
		$existing = get_transient( $tkey );
		return ( false === $existing ) ? null : $existing;
	}

	/**
	 * Read Idempotency-Key from request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function idempotency_key( $request ): string {
		$key = $request->get_header( 'Idempotency-Key' );
		if ( ! is_string( $key ) || '' === $key ) {
			$key = $request->get_header( 'idempotency-key' );
		}
		return is_string( $key ) ? sanitize_text_field( $key ) : '';
	}

	/**
	 * @param array<string,mixed>  $body    Body.
	 * @param int                  $status  HTTP.
	 * @param WP_REST_Request|null $request Request.
	 * @return WP_REST_Response
	 */
	private static function respond( array $body, int $status, $request = null ) {
		$envelope = false;
		if ( $request instanceof WP_REST_Request ) {
			$flag = $request->get_param( '_envelope' );
			$envelope = ( '1' === (string) $flag || 1 === $flag || true === $flag );
		} elseif ( isset( $_GET['_envelope'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$envelope = ( '1' === (string) wp_unslash( $_GET['_envelope'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$http = $envelope ? 200 : $status;
		$res  = new WP_REST_Response( $body, $http );
		$res->header( 'Cache-Control', 'private, no-store' );
		$res->header( 'X-MNS-Status', (string) $status );
		return $res;
	}
}
