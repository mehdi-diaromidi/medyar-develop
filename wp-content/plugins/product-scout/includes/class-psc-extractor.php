<?php
defined( 'ABSPATH' ) || exit;

/**
 * Generic product data extractor: JSON-LD (schema.org/Product) first,
 * then OpenGraph / meta tags. No AI, no text rewriting.
 *
 * Site-specific adapters can later hook the `psc_extract` filter.
 */
class PSC_Extractor {

	/**
	 * @return array Normalized product fields.
	 */
	public static function extract( $html, $url ) {
		$data = self::empty_result( $url );

		if ( '' === trim( $html ) ) {
			return $data;
		}

		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xpath = new DOMXPath( $dom );

		$product = self::find_json_ld_product( $xpath );
		if ( $product ) {
			$data = self::from_json_ld( $product, $data );
		}

		$data = self::fill_from_meta( $xpath, $data );

		/**
		 * Filter to let site-specific adapters refine the result.
		 *
		 * @param array  $data Normalized fields.
		 * @param string $url  Source URL.
		 * @param string $html Raw HTML.
		 */
		return apply_filters( 'psc_extract', $data, $url, $html );
	}

	private static function empty_result( $url ) {
		return array(
			'url'         => $url,
			'host'        => (string) wp_parse_url( $url, PHP_URL_HOST ),
			'name'        => '',
			'brand'       => '',
			'description' => '',
			'sku'         => '',
			'gtin'        => '',
			'price'       => '',
			'currency'    => '',
			'images'      => array(),
			'attributes'  => array(),
			'source'      => '',
		);
	}

	private static function find_json_ld_product( DOMXPath $xpath ) {
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$json = json_decode( trim( $node->textContent ), true );
			if ( null === $json ) {
				continue;
			}
			$found = self::walk_for_product( $json );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	private static function walk_for_product( $node ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		if ( isset( $node['@type'] ) ) {
			$types = (array) $node['@type'];
			if ( in_array( 'Product', $types, true ) || in_array( 'ProductGroup', $types, true ) ) {
				return $node;
			}
		}
		foreach ( $node as $child ) {
			$found = self::walk_for_product( $child );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	private static function from_json_ld( array $p, array $data ) {
		$data['source']      = 'json-ld';
		$data['name']        = self::text( $p['name'] ?? '' );
		$data['description'] = self::text( $p['description'] ?? '' );
		$data['sku']         = self::text( $p['sku'] ?? '' );

		foreach ( array( 'gtin', 'gtin13', 'gtin12', 'gtin14', 'gtin8' ) as $k ) {
			if ( ! empty( $p[ $k ] ) ) {
				$data['gtin'] = self::text( $p[ $k ] );
				break;
			}
		}

		$brand = $p['brand'] ?? '';
		if ( is_array( $brand ) ) {
			$brand = $brand['name'] ?? '';
		}
		$data['brand'] = self::text( $brand );

		$images = $p['image'] ?? array();
		if ( ! is_array( $images ) ) {
			$images = array( $images );
		}
		foreach ( $images as $img ) {
			if ( is_array( $img ) ) {
				$img = $img['url'] ?? '';
			}
			$img = esc_url_raw( (string) $img );
			if ( $img ) {
				$data['images'][] = $img;
			}
		}

		$offers = $p['offers'] ?? array();
		if ( isset( $offers['@type'] ) || isset( $offers['price'] ) || isset( $offers['lowPrice'] ) ) {
			$offers = array( $offers );
		}
		if ( ! empty( $offers[0] ) && is_array( $offers[0] ) ) {
			$data['price']    = self::text( $offers[0]['price'] ?? ( $offers[0]['lowPrice'] ?? '' ) );
			$data['currency'] = self::text( $offers[0]['priceCurrency'] ?? '' );
		}

		foreach ( (array) ( $p['additionalProperty'] ?? array() ) as $prop ) {
			if ( is_array( $prop ) && ! empty( $prop['name'] ) ) {
				$data['attributes'][ self::text( $prop['name'] ) ] = self::text( $prop['value'] ?? '' );
			}
		}

		return $data;
	}

	private static function fill_from_meta( DOMXPath $xpath, array $data ) {
		$meta = function ( $query ) use ( $xpath ) {
			$n = $xpath->query( $query )->item( 0 );
			return $n ? self::text( $n->getAttribute( 'content' ) ) : '';
		};

		$used = false;
		if ( '' === $data['name'] ) {
			$data['name'] = $meta( '//meta[@property="og:title"]' );
			if ( '' === $data['name'] ) {
				$t = $xpath->query( '//title' )->item( 0 );
				$data['name'] = $t ? self::text( $t->textContent ) : '';
			}
			$used = '' !== $data['name'];
		}
		if ( '' === $data['description'] ) {
			$d = $meta( '//meta[@property="og:description"]' );
			if ( '' === $d ) {
				$d = $meta( '//meta[@name="description"]' );
			}
			$data['description'] = $d;
			$used = $used || '' !== $d;
		}
		if ( ! $data['images'] ) {
			$img = esc_url_raw( $meta( '//meta[@property="og:image"]' ) );
			if ( $img ) {
				$data['images'][] = $img;
				$used = true;
			}
		}
		if ( $used && '' === $data['source'] ) {
			$data['source'] = 'meta';
		}
		return $data;
	}

	private static function text( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( array( __CLASS__, 'text' ), $value ) );
		}
		return trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
