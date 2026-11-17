<?php
defined( 'ABSPATH' ) || exit;

/**
 * Torob is used only as a "discovery" source: from a Torob product link we
 * get the list of shops selling it, then fetch each shop page ourselves.
 *
 * We call Torob's public JSON API directly (the HTML page is often refused),
 * pace requests and back off when Torob says stop. CAPTCHAs are never solved.
 */
class PSC_Torob {

	const API          = 'https://api.torob.com';
	const MIN_INTERVAL = 3;                    // Seconds between two Torob calls.
	const COOLDOWN     = 30 * MINUTE_IN_SECONDS; // Pause after a block.
	const CACHE_TTL    = 6 * HOUR_IN_SECONDS;
	const BLOCK_CODES  = array( 403, 409, 429, 490, 503 );

	public static function is_torob_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'torob.com' === $host || 'www.torob.com' === $host;
	}

	/** Product id (prk / random_key) from https://torob.com/p/<uuid>/<slug>/ */
	public static function prk_from_url( $url ) {
		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( preg_match( '#/p/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $path, $m ) ) {
			return strtolower( $m[1] );
		}
		return '';
	}

	/**
	 * @return array{ok:bool,error:string,diag:array,sellers:array,prk:string}
	 */
	public static function sellers( $url ) {
		$prk = self::prk_from_url( $url );
		$out = array( 'ok' => false, 'error' => '', 'diag' => array(), 'sellers' => array(), 'prk' => $prk, 'url' => $url );

		if ( '' === $prk ) {
			$out['error'] = __( 'Could not find the product id in this Torob link (expected torob.com/p/<id>/...).', 'product-scout' );
			return $out;
		}

		$cache_key = 'psc_tb_' . md5( $prk );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$res = self::request(
			self::API . '/v4/base-product/sellers/?' . http_build_query(
				array(
					'prk'       => $prk,
					'list_type' => 'products_info',
				)
			)
		);
		$out['diag'] = $res['diag'];

		if ( ! $res['ok'] ) {
			$out['error'] = $res['error'];
			return $out;
		}

		$json = json_decode( $res['body'], true );
		if ( ! is_array( $json ) || ! isset( $json['results'] ) || ! is_array( $json['results'] ) ) {
			$out['error'] = __( 'Torob answered, but not with the expected seller list (see diagnostics).', 'product-scout' );
			return $out;
		}

		$out['diag']['sample'] = isset( $json['results'][0] ) ? $json['results'][0] : null;

		$seen = array();
		foreach ( $json['results'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			// Promoted rows repeat an organic offer with the same prk.
			$offer = isset( $row['prk'] ) ? (string) $row['prk'] : '';
			if ( '' !== $offer && isset( $seen[ $offer ] ) ) {
				continue;
			}
			$seen[ $offer ] = true;

			$link = '';
			foreach ( array( 'page_url', 'more_info_url', 'buy_url', 'url', 'link' ) as $k ) {
				if ( ! empty( $row[ $k ] ) && is_string( $row[ $k ] ) ) {
					$link = self::absolute( $row[ $k ] );
					break;
				}
			}

			$out['sellers'][] = array(
				'shop'      => sanitize_text_field( (string) ( $row['shop_name'] ?? '' ) ),
				'city'      => sanitize_text_field( (string) ( $row['shop_name2'] ?? '' ) ),
				'shop_id'   => (int) ( $row['shop_id'] ?? 0 ),
				'price'     => sanitize_text_field( (string) ( $row['price_text'] ?? '' ) ),
				'available' => ! empty( $row['availability'] ),
				'link'      => $link,
			);
		}

		$out['ok'] = true;
		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Follow Torob's redirect link hop by hop until it leaves torob.com.
	 *
	 * @return array{ok:bool,url:string,error:string}
	 */
	public static function resolve( $link ) {
		$link = self::absolute( $link );
		if ( ! self::is_torob_host( $link ) ) {
			return array( 'ok' => false, 'url' => '', 'error' => __( 'Not a Torob link.', 'product-scout' ) );
		}

		$cache_key = 'psc_tr_' . md5( $link );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return array( 'ok' => true, 'url' => $cached, 'error' => '' );
		}

		$current = $link;
		for ( $hop = 0; $hop < 5; $hop++ ) {
			$res = self::request( $current, false );
			if ( '' !== $res['location'] ) {
				$next = WP_Http::make_absolute_url( $res['location'], $current );
			} elseif ( $res['ok'] ) {
				$next = self::html_redirect( $res['body'], $current );
			} else {
				return array( 'ok' => false, 'url' => '', 'error' => $res['error'] );
			}

			if ( '' === $next ) {
				break;
			}
			if ( ! self::is_torob_host( $next ) ) {
				$next = self::strip_tracking( $next );
				set_transient( $cache_key, $next, DAY_IN_SECONDS );
				return array( 'ok' => true, 'url' => $next, 'error' => '' );
			}
			$current = $next;
		}

		return array( 'ok' => false, 'url' => '', 'error' => __( 'Torob did not redirect to a shop.', 'product-scout' ) );
	}

	/** Seconds left in the cooldown, 0 if Torob may be called. */
	public static function cooldown_left() {
		$until = (int) get_transient( 'psc_tb_block' );
		return max( 0, $until - time() );
	}

	/**
	 * One paced, browser-like request to Torob.
	 *
	 * @return array{ok:bool,body:string,error:string,location:string,diag:array}
	 */
	private static function request( $url, $follow = true ) {
		$out = array( 'ok' => false, 'body' => '', 'error' => '', 'location' => '', 'diag' => array( 'url' => $url ) );

		$left = self::cooldown_left();
		if ( $left > 0 ) {
			/* translators: %d: minutes */
			$out['error'] = sprintf( __( 'Torob asked us to slow down. Paused for %d more minute(s).', 'product-scout' ), (int) ceil( $left / 60 ) );
			return $out;
		}

		self::pace();

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => $follow ? 3 : 0,
				'limit_response_size' => 2 * MB_IN_BYTES,
				'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
				'headers'             => array(
					'Accept'          => 'application/json, text/plain, */*',
					'Accept-Language' => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
					'Origin'          => 'https://torob.com',
					'Referer'         => 'https://torob.com/',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$out['error'] = $response->get_error_message();
			return $out;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		$out['diag'] += array(
			'status'       => $code,
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'server'       => (string) wp_remote_retrieve_header( $response, 'server' ),
			'snippet'      => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) ), 0, 400 ),
		);

		if ( $code >= 300 && $code < 400 ) {
			$out['location'] = (string) wp_remote_retrieve_header( $response, 'location' );
			$out['ok']       = true;
			return $out;
		}

		if ( in_array( $code, self::BLOCK_CODES, true ) ) {
			set_transient( 'psc_tb_block', time() + self::COOLDOWN, self::COOLDOWN );
			/* translators: %d: HTTP status code */
			$out['error'] = sprintf( __( 'Torob refused the request (HTTP %d). Paused for 30 minutes; see diagnostics.', 'product-scout' ), $code );
			return $out;
		}

		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code */
			$out['error'] = sprintf( __( 'Torob returned HTTP %d.', 'product-scout' ), $code );
			return $out;
		}

		$out['ok']   = true;
		$out['body'] = $body;
		return $out;
	}

	/** Keep at least MIN_INTERVAL seconds between Torob calls (site-wide). */
	private static function pace() {
		$last = (float) get_transient( 'psc_tb_last' );
		$wait = ( $last + self::MIN_INTERVAL ) - microtime( true );
		if ( $wait > 0 ) {
			usleep( (int) ( min( $wait, self::MIN_INTERVAL ) * 1000000 ) );
		}
		set_transient( 'psc_tb_last', microtime( true ), MINUTE_IN_SECONDS );
	}

	private static function html_redirect( $html, $base ) {
		if ( preg_match( '#<meta[^>]+http-equiv=["\']?refresh["\']?[^>]*content=["\'][^"\']*url=([^"\'>]+)#i', $html, $m ) ) {
			return WP_Http::make_absolute_url( html_entity_decode( trim( $m[1] ) ), $base );
		}
		if ( preg_match( '#(?:window\.)?location(?:\.href)?\s*=\s*["\']([^"\']+)["\']#i', $html, $m ) ) {
			return WP_Http::make_absolute_url( $m[1], $base );
		}
		return '';
	}

	private static function absolute( $link ) {
		$link = trim( (string) $link );
		if ( '' === $link ) {
			return '';
		}
		if ( 0 === strpos( $link, '/' ) && 0 !== strpos( $link, '//' ) ) {
			$link = 'https://torob.com' . $link;
		}
		return esc_url_raw( $link, array( 'http', 'https' ) );
	}

	private static function is_torob_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'torob.com' === $host || ( strlen( $host ) > 10 && '.torob.com' === substr( $host, -10 ) );
	}

	/** Drop utm_* tracking params from a shop URL. */
	private static function strip_tracking( $url ) {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $query ) {
			return $url;
		}
		parse_str( $query, $params );
		foreach ( array_keys( $params ) as $k ) {
			if ( 0 === stripos( $k, 'utm_' ) ) {
				unset( $params[ $k ] );
			}
		}
		$base = strtok( $url, '?' );
		return $params ? $base . '?' . http_build_query( $params ) : $base;
	}
}
