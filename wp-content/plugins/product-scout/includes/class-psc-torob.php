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

		$out = self::normalize( json_decode( $res['body'], true ), $out );
		if ( ! $out['ok'] ) {
			return $out;
		}

		set_transient( $cache_key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Build a seller block from data the admin's own browser fetched on torob.com
	 * (bookmarklet). The payload is untrusted and fully sanitized here.
	 *
	 * @param string $page_url Torob page the admin was on.
	 * @param mixed  $api      Decoded sellers API answer, or null.
	 * @param mixed  $next     Decoded __NEXT_DATA__ of the page, or null.
	 * @param int    $status   HTTP status the browser got from the API.
	 */
	public static function from_browser( $page_url, $api, $next, $status ) {
		$out = array(
			'ok'      => false,
			'error'   => '',
			'diag'    => array( 'via' => 'browser', 'api_status' => (int) $status ),
			'sellers' => array(),
			'prk'     => self::prk_from_url( $page_url ),
			'url'     => self::is_torob_url( $page_url ) ? esc_url_raw( $page_url ) : '',
		);

		$out = self::normalize( $api, $out );
		if ( ! $out['ok'] && null !== $next ) {
			// Fallback: the page itself embeds (part of) the seller list.
			$out['error'] = '';
			$out          = self::normalize( $next, $out );
			$out['diag']['via'] = 'browser-page-data';
		}
		return $out;
	}

	/** Find the seller rows anywhere in a decoded Torob JSON document. */
	private static function normalize( $json, array $out ) {
		$rows = self::find_seller_rows( $json, 0 );
		if ( ! $rows ) {
			$out['error'] = __( 'Torob answered, but not with the expected seller list (see diagnostics).', 'product-scout' );
			return $out;
		}

		$out['diag']['sample'] = $rows[0];
		$out['diag']['count']  = count( $rows );

		$seen = array();
		foreach ( $rows as $row ) {
			// Promoted rows repeat an organic offer with the same prk.
			$offer = isset( $row['prk'] ) && is_scalar( $row['prk'] ) ? (string) $row['prk'] : '';
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
				'shop'      => sanitize_text_field( self::scalar( $row['shop_name'] ?? '' ) ),
				'city'      => sanitize_text_field( self::scalar( $row['shop_name2'] ?? '' ) ),
				'shop_id'   => (int) self::scalar( $row['shop_id'] ?? 0 ),
				'price'     => sanitize_text_field( self::scalar( $row['price_text'] ?? '' ) ),
				'available' => ! empty( $row['availability'] ),
				'link'      => $link,
			);
		}

		$out['ok'] = true;
		return $out;
	}

	/** Largest list of objects that carry a shop_name, searched depth-first. */
	private static function find_seller_rows( $node, $depth ) {
		if ( ! is_array( $node ) || $depth > 12 ) {
			return array();
		}
		$best = array();
		if ( isset( $node[0] ) && is_array( $node[0] ) && isset( $node[0]['shop_name'] ) ) {
			$best = array_values( array_filter( $node, function ( $r ) {
				return is_array( $r ) && isset( $r['shop_name'] );
			} ) );
		}
		foreach ( $node as $child ) {
			$found = self::find_seller_rows( $child, $depth + 1 );
			if ( count( $found ) > count( $best ) ) {
				$best = $found;
			}
		}
		return $best;
	}

	private static function scalar( $v ) {
		return is_scalar( $v ) ? (string) $v : '';
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
