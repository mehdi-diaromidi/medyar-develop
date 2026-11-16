<?php
/**
 * Iran provinces/cities from assets + district names from S-REG-DISTRICTS (T-2.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Locations {

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/**
	 * Raw decoded iran-locations.json.
	 *
	 * @return array<string,mixed>
	 */
	public static function raw(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$path = MNS_PLUGIN_DIR . 'assets/data/iran-locations.json';
		if ( ! is_readable( $path ) ) {
			self::$cache = array( 'provinces' => array() );
			return self::$cache;
		}
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		self::$cache = is_array( $data ) ? $data : array( 'provinces' => array() );
		return self::$cache;
	}

	/**
	 * Provinces list: [{code,name,cities:[{id,name}],…}].
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function provinces(): array {
		$raw  = self::raw();
		$list = isset( $raw['provinces'] ) && is_array( $raw['provinces'] ) ? $raw['provinces'] : array();
		$out  = array();
		$active = MNS_Settings::get( 'reg_active' );
		$active = is_array( $active ) ? $active : array();

		foreach ( $list as $prov ) {
			if ( ! is_array( $prov ) || empty( $prov['code'] ) ) {
				continue;
			}
			$code = strtoupper( sanitize_text_field( (string) $prov['code'] ) );
			if ( ! empty( $active ) && ! in_array( $code, $active, true ) ) {
				continue;
			}
			$cities_names = isset( $prov['cities'] ) && is_array( $prov['cities'] ) ? $prov['cities'] : array();
			$cities_ids   = isset( $prov['ids'] ) && is_array( $prov['ids'] ) ? $prov['ids'] : array();
			$cities       = array();
			foreach ( $cities_names as $i => $name ) {
				$cities[] = array(
					'id'   => isset( $cities_ids[ $i ] ) ? (int) $cities_ids[ $i ] : ( $i + 1 ),
					'name' => (string) $name,
				);
			}
			$out[] = array(
				'code'   => $code,
				'name'   => (string) ( $prov['name'] ?? $code ),
				'cities' => $cities,
			);
		}
		return $out;
	}

	/**
	 * Province by code or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function province( string $code ): ?array {
		$code = strtoupper( $code );
		foreach ( self::provinces() as $p ) {
			if ( $p['code'] === $code ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * City name by province + city_id (0 = whole province sentinel).
	 */
	public static function city_name( string $prov, int $city_id ): string {
		if ( 0 === $city_id ) {
			return '*';
		}
		$p = self::province( $prov );
		if ( ! $p ) {
			return '';
		}
		foreach ( $p['cities'] as $c ) {
			if ( (int) $c['id'] === $city_id ) {
				return (string) $c['name'];
			}
		}
		return '';
	}

	/**
	 * City id by province + city name, or null.
	 */
	public static function city_id( string $prov, string $city_name ): ?int {
		$p = self::province( $prov );
		if ( ! $p ) {
			return null;
		}
		foreach ( $p['cities'] as $c ) {
			if ( (string) $c['name'] === $city_name ) {
				return (int) $c['id'];
			}
		}
		return null;
	}

	/**
	 * Whether province+city has districts (S-REG-DISTRICTS).
	 *
	 * @return array<int,string>|null District labels 0-indexed, or null if none.
	 */
	public static function districts_for( string $prov, string $city_name ): ?array {
		$map = MNS_Settings::get( 'reg_districts' );
		if ( ! is_array( $map ) ) {
			return null;
		}
		$key = strtoupper( $prov ) . '|' . $city_name;
		if ( ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) || empty( $map[ $key ] ) ) {
			return null;
		}
		$out = array();
		foreach ( $map[ $key ] as $label ) {
			$out[] = (string) $label;
		}
		return $out;
	}

	/**
	 * Full districts map for API shape: {"THR|تهران":["منطقه 1",…]}.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function districts_map(): array {
		$map = MNS_Settings::get( 'reg_districts' );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$out = array();
		foreach ( $map as $key => $labels ) {
			if ( ! is_array( $labels ) ) {
				continue;
			}
			$out[ (string) $key ] = array_map( 'strval', $labels );
		}
		return $out;
	}

	/**
	 * Validate province/city_id exist (city_id 0 allowed = whole province).
	 */
	public static function is_valid_city( string $prov, int $city_id ): bool {
		$p = self::province( $prov );
		if ( ! $p ) {
			return false;
		}
		if ( 0 === $city_id ) {
			return true;
		}
		foreach ( $p['cities'] as $c ) {
			if ( (int) $c['id'] === $city_id ) {
				return true;
			}
		}
		return false;
	}
}
