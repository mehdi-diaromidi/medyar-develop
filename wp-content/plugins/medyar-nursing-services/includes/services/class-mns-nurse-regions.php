<?php
/**
 * Nurse working regions save/list (R-REG-1..3 / T-2.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Regions {

	/**
	 * Current regions for API shape.
	 *
	 * @return array<int,array{prov:string,city:string,city_id:int,districts:array<int,int>}>
	 */
	public static function list_for( int $nurse_user_id ): array {
		global $wpdb;
		if ( $nurse_user_id <= 0 ) {
			return array();
		}
		$table = MNS_DB::table( 'nurse_regions' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT province_code, city_id, district_no FROM {$table} WHERE nurse_id = %d ORDER BY province_code, city_id, district_no", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		$grouped = array();
		foreach ( $rows as $row ) {
			$prov    = strtoupper( (string) $row['province_code'] );
			$city_id = (int) $row['city_id'];
			$dno     = (int) $row['district_no'];
			$key     = $prov . '|' . $city_id;
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'prov'      => $prov,
					'city_id'   => $city_id,
					'city'      => MNS_Locations::city_name( $prov, $city_id ),
					'districts' => array(),
				);
			}
			if ( $dno > 0 ) {
				$grouped[ $key ]['districts'][] = $dno;
			}
		}
		return array_values( $grouped );
	}

	/**
	 * Whether nurse has at least one region row (R-REG-3).
	 */
	public static function has_any( int $nurse_user_id ): bool {
		global $wpdb;
		if ( $nurse_user_id <= 0 ) {
			return false;
		}
		$table = MNS_DB::table( 'nurse_regions' );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE nurse_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id
			)
		);
		return ( null !== $found );
	}

	/**
	 * Count of assigned active orders in regions that would be removed.
	 *
	 * @param array<int,array<string,mixed>> $old_regions Previous list_for shape.
	 * @param array<int,array<string,mixed>> $new_regions Incoming validated regions.
	 * @return array{orders:int,keys:array<int,string>}
	 */
	public static function removed_order_stats( int $nurse_user_id, array $old_regions, array $new_regions ): array {
		$new_keys = array();
		foreach ( $new_regions as $r ) {
			$prov    = (string) $r['prov'];
			$city_id = (int) $r['city_id'];
			$dists   = isset( $r['districts'] ) && is_array( $r['districts'] ) ? $r['districts'] : array();
			if ( empty( $dists ) ) {
				$new_keys[ $prov . '|' . $city_id . '|0' ] = true;
			} else {
				foreach ( $dists as $d ) {
					$new_keys[ $prov . '|' . $city_id . '|' . (int) $d ] = true;
				}
			}
		}

		$removed = array();
		foreach ( $old_regions as $r ) {
			$prov    = (string) $r['prov'];
			$city_id = (int) $r['city_id'];
			$dists   = isset( $r['districts'] ) && is_array( $r['districts'] ) ? $r['districts'] : array();
			if ( empty( $dists ) ) {
				$key = $prov . '|' . $city_id . '|0';
				if ( ! isset( $new_keys[ $key ] ) ) {
					$removed[] = array( $prov, $city_id, 0 );
				}
			} else {
				foreach ( $dists as $d ) {
					$key = $prov . '|' . $city_id . '|' . (int) $d;
					if ( ! isset( $new_keys[ $key ] ) ) {
						$removed[] = array( $prov, $city_id, (int) $d );
					}
				}
			}
		}

		$total = 0;
		$keys  = array();
		foreach ( $removed as $tuple ) {
			$n = self::count_orders_in_region( $nurse_user_id, $tuple[0], $tuple[1], $tuple[2] );
			if ( $n > 0 ) {
				$total += $n;
				$city   = MNS_Locations::city_name( $tuple[0], $tuple[1] );
				$keys[] = $tuple[0] . '|' . $city . '|' . $tuple[2];
			}
		}
		return array(
			'orders' => $total,
			'keys'   => $keys,
		);
	}

	/**
	 * Map of region_orders for API: {"THR|تهران|3":2}.
	 *
	 * @return array<string,int>
	 */
	public static function region_orders_map( int $nurse_user_id ): array {
		$regions = self::list_for( $nurse_user_id );
		$out     = array();
		foreach ( $regions as $r ) {
			$prov    = (string) $r['prov'];
			$city    = (string) $r['city'];
			$city_id = (int) $r['city_id'];
			$dists   = $r['districts'];
			if ( empty( $dists ) ) {
				$n = self::count_orders_in_region( $nurse_user_id, $prov, $city_id, 0 );
				if ( $n > 0 ) {
					$out[ $prov . '|' . $city ] = $n;
				}
			} else {
				foreach ( $dists as $d ) {
					$n = self::count_orders_in_region( $nurse_user_id, $prov, $city_id, (int) $d );
					if ( $n > 0 ) {
						$out[ $prov . '|' . $city . '|' . (int) $d ] = $n;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Replace nurse regions (R-REG-1..3). Saves even if warning; returns warning meta.
	 *
	 * Input items: {prov, city_id, districts:int[]}.
	 *
	 * @param array<int,array<string,mixed>> $regions Regions.
	 * @return array{ok:true,warning?:array{orders:int,text:string},regions:array}
	 * @throws MNS_Error
	 */
	public static function save_regions( int $nurse_user_id, array $regions ) {
		if ( $nurse_user_id <= 0 ) {
			throw MNS_Error::validation( 'پرستار نامعتبر است.', 'nurse_id' );
		}

		$normalized = array();
		foreach ( $regions as $item ) {
			if ( ! is_array( $item ) ) {
				throw MNS_Error::validation( 'فرمت منطقه نامعتبر است.', 'regions' );
			}
			$prov = isset( $item['prov'] ) ? strtoupper( sanitize_text_field( (string) $item['prov'] ) ) : '';
			if ( ! preg_match( '/^[A-Z]{3}$/', $prov ) ) {
				throw MNS_Error::validation( 'کد استان نامعتبر است.', 'prov' );
			}
			$city_id = isset( $item['city_id'] ) ? (int) $item['city_id'] : -1;
			if ( $city_id < 0 || ! MNS_Locations::is_valid_city( $prov, $city_id ) ) {
				throw MNS_Error::validation( 'شهر نامعتبر است.', 'city_id' );
			}

			$dists_in = isset( $item['districts'] ) && is_array( $item['districts'] ) ? $item['districts'] : array();
			$dists    = array();
			foreach ( $dists_in as $d ) {
				$d = (int) $d;
				if ( $d > 0 ) {
					$dists[] = $d;
				}
			}
			$dists = array_values( array_unique( $dists ) );
			sort( $dists );

			// R-REG-1: city_id=0 only with district 0 (no district list).
			if ( 0 === $city_id ) {
				if ( ! empty( $dists ) ) {
					throw MNS_Error::validation( 'انتخاب همهٔ شهرهای استان نباید منطقه داشته باشد.', 'districts' );
				}
				$normalized[] = array(
					'prov'      => $prov,
					'city_id'   => 0,
					'city'      => '*',
					'districts' => array(),
				);
				continue;
			}

			$city_name = MNS_Locations::city_name( $prov, $city_id );
			$dist_labels = MNS_Locations::districts_for( $prov, $city_name );
			if ( null !== $dist_labels ) {
				// Districted city: need ≥1 district; no district_no=0 row.
				if ( empty( $dists ) ) {
					throw MNS_Error::validation( 'حداقل یک منطقه انتخاب کنید.', 'districts' );
				}
				$max = count( $dist_labels );
				foreach ( $dists as $d ) {
					if ( $d < 1 || $d > $max ) {
						throw MNS_Error::validation( 'شماره منطقه نامعتبر است.', 'districts' );
					}
				}
			} else {
				// Non-district city: only district_no=0.
				if ( ! empty( $dists ) ) {
					throw MNS_Error::validation( 'این شهر منطقه ندارد.', 'districts' );
				}
			}

			$normalized[] = array(
				'prov'      => $prov,
				'city_id'   => $city_id,
				'city'      => $city_name,
				'districts' => $dists,
			);
		}

		// Deduplicate by prov|city_id (last wins).
		$by_key = array();
		foreach ( $normalized as $r ) {
			$by_key[ $r['prov'] . '|' . $r['city_id'] ] = $r;
		}
		$normalized = array_values( $by_key );

		$old = self::list_for( $nurse_user_id );
		$stats = self::removed_order_stats( $nurse_user_id, $old, $normalized );

		try {
			MNS_DB::tx(
				static function () use ( $nurse_user_id, $normalized ) {
					$locked = MNS_DB::lock_row( MNS_DB::table( 'nurses' ), 'user_id', $nurse_user_id );
					if ( ! is_array( $locked ) ) {
						throw MNS_Error::not_found( 'پرستار یافت نشد.' );
					}
					global $wpdb;
					$table = MNS_DB::table( 'nurse_regions' );
					$del   = $wpdb->delete( $table, array( 'nurse_id' => $nurse_user_id ), array( '%d' ) );
					if ( false === $del ) {
						throw MNS_Error::server( 'ذخیرهٔ مناطق ناموفق بود.' );
					}
					foreach ( $normalized as $r ) {
						$prov    = $r['prov'];
						$city_id = (int) $r['city_id'];
						$dists   = $r['districts'];
						if ( empty( $dists ) ) {
							$ins = $wpdb->insert(
								$table,
								array(
									'nurse_id'      => $nurse_user_id,
									'province_code' => $prov,
									'city_id'       => $city_id,
									'district_no'   => 0,
								),
								array( '%d', '%s', '%d', '%d' )
							);
							if ( false === $ins ) {
								throw MNS_Error::server( 'ذخیرهٔ مناطق ناموفق بود.' );
							}
						} else {
							foreach ( $dists as $d ) {
								$ins = $wpdb->insert(
									$table,
									array(
										'nurse_id'      => $nurse_user_id,
										'province_code' => $prov,
										'city_id'       => $city_id,
										'district_no'   => (int) $d,
									),
									array( '%d', '%s', '%d', '%d' )
								);
								if ( false === $ins ) {
									throw MNS_Error::server( 'ذخیرهٔ مناطق ناموفق بود.' );
								}
							}
						}
					}
					return true;
				}
			);
		} catch ( MNS_Error $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'regions_save: ' . $e->getMessage(), array( 'nurse' => $nurse_user_id ) );
			throw MNS_Error::server();
		}

		$result = array(
			'ok'      => true,
			'regions' => self::list_for( $nurse_user_id ),
		);
		if ( $stats['orders'] > 0 ) {
			$result['warning'] = array(
				'orders' => $stats['orders'],
				'text'   => sprintf(
					/* translators: %d: order count */
					'%d سفارش تخصیص‌یافته در مناطق حذف‌شده دارید. حذف منطقه روی آن‌ها اثری ندارد.',
					$stats['orders']
				),
			);
		}
		return $result;
	}

	/**
	 * @param int $district_no 0 = city-wide / non-district.
	 */
	private static function count_orders_in_region( int $nurse_user_id, string $prov, int $city_id, int $district_no ): int {
		global $wpdb;
		$orders = MNS_DB::table( 'orders' );
		$in     = "'" . implode( "','", array_map( 'esc_sql', MNS_Nurse_Schedule::ORDER_LOCK_STATUSES ) ) . "'";
		if ( 0 === $district_no ) {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$orders} WHERE nurse_user_id = %d AND province_code = %s AND city_id = %d AND status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id,
				$prov,
				$city_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$orders} WHERE nurse_user_id = %d AND province_code = %s AND city_id = %d AND district_no = %d AND status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id,
				$prov,
				$city_id,
				$district_no
			);
		}
		return (int) $wpdb->get_var( $sql );
	}
}
