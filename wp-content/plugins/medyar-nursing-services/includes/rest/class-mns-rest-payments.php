<?php
/**
 * REST: invoice pay + Zarinpal return (RUN-F / T-F.2–T-F.3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_REST_Payments {

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			MNS_REST::NS,
			'/invoices/(?P<number>[A-Za-z0-9\-_]+)/pay',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pay' ),
				'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/payments/return',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'gateway_return' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function pay( $request ) {
		$uid = get_current_user_id();
		if ( ! MNS_REST::rate_limit( 'inv_pay_' . $uid, 10, 60 ) ) {
			return MNS_REST::fail( MNS_Error::rate_limit(), $request );
		}
		$number = sanitize_text_field( (string) $request['number'] );
		// Ignore any client-supplied amount — server reads invoice row only.
		try {
			$out = MNS_Payments::start_pay( $number, $uid );
			return MNS_REST::success(
				array(
					'redirect_url' => (string) $out['redirect_url'],
					'reused'       => ! empty( $out['reused'] ),
				),
				$request
			);
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'invoice_pay_exception', array( 'err' => $e->getMessage() ) );
			return MNS_REST::fail( MNS_Error::server(), $request );
		}
	}

	/**
	 * Public gateway callback — redirect only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function gateway_return( $request ) {
		$authority = (string) $request->get_param( 'Authority' );
		if ( '' === $authority ) {
			$authority = (string) $request->get_param( 'authority' );
		}
		$status = (string) $request->get_param( 'Status' );
		if ( '' === $status ) {
			$status = (string) $request->get_param( 'status' );
		}
		$url = MNS_Payments::handle_return( $authority, $status );
		$res = new WP_REST_Response( null, 302 );
		$res->header( 'Location', $url );
		$res->header( 'Cache-Control', 'private, no-store' );
		return $res;
	}
}
