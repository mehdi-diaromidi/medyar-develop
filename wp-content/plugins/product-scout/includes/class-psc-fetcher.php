<?php
defined( 'ABSPATH' ) || exit;

/**
 * Safe, cached HTTP fetcher. Uses wp_safe_remote_get so private/internal
 * addresses are rejected (SSRF protection).
 */
class PSC_Fetcher {

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	const MAX_BYTES = 3 * MB_IN_BYTES;

	/**
	 * @return array{ok:bool,html:string,error:string,final_url:string}
	 */
	public static function get( $url ) {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return self::fail( __( 'Invalid URL.', 'product-scout' ) );
		}

		$key    = 'psc_f_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
				'headers'             => array(
					'Accept'          => 'text/html,application/xhtml+xml',
					'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.7',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			return self::fail( sprintf( __( 'HTTP status %d.', 'product-scout' ), $code ) );
		}

		$result = array(
			'ok'        => true,
			'html'      => (string) wp_remote_retrieve_body( $response ),
			'error'     => '',
			'final_url' => $url,
		);
		set_transient( $key, $result, self::CACHE_TTL );
		return $result;
	}

	private static function fail( $message ) {
		return array(
			'ok'        => false,
			'html'      => '',
			'error'     => $message,
			'final_url' => '',
		);
	}
}
