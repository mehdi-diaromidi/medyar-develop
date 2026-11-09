<?php
defined( 'ABSPATH' ) || exit;

/**
 * Faraz SMS (IPPanel) transport built on the faraz-sms plugin helpers.
 * Pattern code set → pattern API only (never a silent free-text fallback); otherwise free text.
 */
final class MSQ_Transport_Faraz {

	/**
	 * @param array<string,mixed> $job Decoded job (see MSQ_Worker::decode()).
	 * @return array{ok:bool,detail:string,retry:bool}
	 */
	public static function send_job( array $job ): array {
		$vars = isset( $job['payload']['vars'] ) && is_array( $job['payload']['vars'] ) ? $job['payload']['vars'] : array();
		return self::send( (string) $job['phone'], (string) $job['body'], (string) $job['pattern_code'], $vars );
	}

	/**
	 * Synchronous send (worker or admin test).
	 *
	 * @param array<string,mixed> $vars Pattern attributes.
	 * @return array{ok:bool,detail:string,retry:bool}
	 */
	public static function send( string $phone, string $body, string $pattern_code = '', array $vars = array() ): array {
		$phone        = MSQ_Phone::normalize( $phone );
		$pattern_code = trim( $pattern_code );
		$body         = trim( $body );
		if ( '' === $phone ) {
			return self::result( false, 'empty_phone', false );
		}
		if ( '' !== $pattern_code ) {
			return self::send_pattern( $phone, $pattern_code, MSQ_Client::normalize_vars( $vars ) );
		}
		if ( '' === $body ) {
			return self::result( false, 'empty_body', false );
		}
		return self::send_text( $phone, $body );
	}

	/**
	 * Calls Faraz with pristine attribute keys (the plugin's hexsafe rename breaks keys like affiliate_name).
	 *
	 * @param array<string,string> $attrs
	 * @return array{ok:bool,detail:string,retry:bool}
	 */
	private static function send_pattern( string $phone, string $pattern_code, array $attrs ): array {
		if ( function_exists( 'farazsms_sms_request' ) && function_exists( 'farazsms_get_apikey' ) && function_exists( 'farazsms_sending_line' ) ) {
			if ( function_exists( 'farazsms_normalize_phone' ) ) {
				$phone = farazsms_normalize_phone( $phone );
			}
			$apikey = farazsms_get_apikey();
			$sender = farazsms_sending_line( '' );
			if ( '' === $apikey || '' === $sender ) {
				return self::result( false, 'missing_apikey_or_sender', true );
			}
			foreach ( $attrs as $k => $v ) {
				$v = function_exists( 'farazsms_tr_num' ) ? farazsms_tr_num( (string) $v ) : (string) $v;
				if ( function_exists( 'farazsms_attr_placeholder_if_empty' ) ) {
					$v = farazsms_attr_placeholder_if_empty( $v );
				} elseif ( '' === trim( $v ) ) {
					$v = '-';
				}
				$attrs[ $k ] = $v;
			}
			$response = farazsms_sms_request(
				'POST',
				'sms/pattern',
				array(
					'body'    => array(
						'code'          => $pattern_code,
						'recipient'     => $phone,
						'attributes'    => $attrs,
						'line_number'   => $sender,
						'number_format' => 'english',
					),
					'apikey'  => $apikey,
					'timeout' => 15,
				)
			);
			if ( is_wp_error( $response ) ) {
				return self::result( false, 'http_error:' . $response->get_error_code() . ':' . $response->get_error_message(), true );
			}
			$data = function_exists( 'farazsms_sms_decode' ) ? farazsms_sms_decode( $response ) : json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && isset( $data['status'] ) && 'success' === $data['status'] ) {
				return self::result( true, 'success', false );
			}
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : wp_remote_retrieve_body( $response );
			if ( function_exists( 'farazsms_flatten_api_message' ) ) {
				$msg = farazsms_flatten_api_message( $msg );
			}
			return self::result( false, 'api_error:' . ( is_string( $msg ) ? $msg : wp_json_encode( $msg ) ), true );
		}

		if ( ! function_exists( 'farazsms_send_pattern' ) ) {
			return self::result( false, 'farazsms_plugin_missing', true );
		}
		$res = farazsms_send_pattern( $phone, $pattern_code, $attrs );
		$ok  = is_array( $res ) ? ! empty( $res['ok'] ) : (bool) $res;
		return self::result( $ok, is_array( $res ) ? (string) ( $res['message'] ?? '' ) : ( $ok ? 'success' : 'fail' ), true );
	}

	/** @return array{ok:bool,detail:string,retry:bool} */
	private static function send_text( string $phone, string $body ): array {
		if ( ! function_exists( 'farazsms_send_sms' ) ) {
			return self::result( false, 'farazsms_plugin_missing', true );
		}
		$res = farazsms_send_sms( $phone, $body );
		$ok  = is_array( $res ) ? ! empty( $res['ok'] ) : (bool) $res;
		return self::result( $ok, $ok ? 'success' : ( 'text_fail:' . wp_json_encode( $res ) ), true );
	}

	/** @return array{ok:bool,detail:string,retry:bool} */
	private static function result( bool $ok, string $detail, bool $retry ): array {
		// Test-mode allowlist blocks are permanent for this recipient — retrying only burns attempts.
		if ( ! $ok && false !== stripos( $detail, 'allowlist' ) ) {
			$retry = false;
		}
		return array(
			'ok'     => $ok,
			'detail' => $detail,
			'retry'  => $retry,
		);
	}
}
