<?php
/**
 * Single eligibility function (R-ELG-1..4 / T-0.5 / RUN-G multi-service).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Eligibility {

	/**
	 * Nurses matching scope. Pure DB + rules; no UI.
	 *
	 * Scope keys: service_id OR service_ids[], province_code, city_id, district_no,
	 * service_date, slot_id, gender_pref. Optional: require_capacity (bool, default true).
	 *
	 * @param array<string,mixed> $scope Scope.
	 * @return array<int,array{user_id:int}>
	 */
	public static function nurses_for( array $scope ): array {
		global $wpdb;

		$service_ids   = self::resolve_service_ids( $scope );
		$province      = isset( $scope['province_code'] ) ? sanitize_text_field( (string) $scope['province_code'] ) : '';
		$city_id       = isset( $scope['city_id'] ) ? (int) $scope['city_id'] : 0;
		$district_no   = isset( $scope['district_no'] ) ? (int) $scope['district_no'] : 0;
		$service_date  = isset( $scope['service_date'] ) ? sanitize_text_field( (string) $scope['service_date'] ) : '';
		$slot_id       = isset( $scope['slot_id'] ) ? sanitize_text_field( (string) $scope['slot_id'] ) : '';
		$gender        = isset( $scope['gender_pref'] ) ? sanitize_text_field( (string) $scope['gender_pref'] ) : '';
		$need_capacity = ! array_key_exists( 'require_capacity', $scope ) || ! empty( $scope['require_capacity'] );

		if ( empty( $service_ids ) || '' === $province || '' === $service_date || '' === $slot_id || '' === $gender ) {
			return array();
		}
		if ( MNS_Time::slot_closed( $slot_id ) ) {
			return array();
		}

		$services = array();
		foreach ( $service_ids as $sid ) {
			$svc = MNS_Services::get( $sid );
			if ( ! $svc || empty( $svc['active'] ) ) {
				return array();
			}
			$services[] = $svc;
		}

		$nurses_t = MNS_DB::table( 'nurses' );
		$reg_t    = MNS_DB::table( 'nurse_regions' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT n.user_id
			FROM {$nurses_t} n
			INNER JOIN {$reg_t} r ON r.nurse_id = n.user_id
				AND r.province_code = %s
				AND ( r.city_id = 0 OR r.city_id = %d )
				AND ( r.district_no = 0 OR r.district_no = %d )
			WHERE n.status = 'approved'
			  AND n.accepting = 1
			  AND n.gender = %s
			GROUP BY n.user_id",
			$province,
			$city_id,
			$district_no,
			$gender
		);

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( is_array( $ids ) ) {
			$ids = apply_filters( 'mns_eligibility_candidate_ids', $ids, $scope );
		}
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return array();
		}

		$cap = (int) MNS_Settings::get( 'slot_capacity' );
		if ( $cap < 1 ) {
			$cap = 1;
		}

		$out = array();
		foreach ( $ids as $uid ) {
			$uid = (int) $uid;
			if ( $uid <= 0 ) {
				continue;
			}
			$user = get_userdata( $uid );
			if ( ! $user || ! $user->exists() ) {
				continue;
			}
			if ( ! self::nurse_covers_services( $uid, $services ) ) {
				continue;
			}
			if ( ! MNS_Nurse_Schedule::effective( $uid, $service_date, $slot_id ) ) {
				continue;
			}
			if ( $need_capacity && self::active_slot_count( $uid, $service_date, $slot_id ) >= $cap ) {
				continue;
			}
			$out[] = array( 'user_id' => $uid );
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $scope Scope.
	 * @return array<int,int>
	 */
	public static function resolve_service_ids( array $scope ): array {
		$ids = array();
		if ( ! empty( $scope['service_ids'] ) && is_array( $scope['service_ids'] ) ) {
			foreach ( $scope['service_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		if ( empty( $ids ) && ! empty( $scope['service_id'] ) ) {
			$ids[] = (int) $scope['service_id'];
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Nurse covers every service: empty nurse_services ⇒ all except needs_explicit_nurse;
	 * otherwise must have an explicit row per service.
	 *
	 * @param array<int,array<string,mixed>> $services Normalized services.
	 */
	public static function nurse_covers_services( int $nurse_id, array $services ): bool {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT service_id FROM ' . MNS_DB::table( 'nurse_services' ) . ' WHERE nurse_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL
				$nurse_id
			)
		);
		$explicit = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $sid ) {
				$explicit[ (int) $sid ] = true;
			}
		}
		$has_any = ! empty( $explicit );
		foreach ( $services as $svc ) {
			$sid = (int) $svc['id'];
			if ( ! $has_any ) {
				if ( ! empty( $svc['needs_explicit_nurse'] ) ) {
					return false;
				}
				continue;
			}
			if ( empty( $explicit[ $sid ] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function active_slot_count( int $nurse_user_id, string $service_date, string $slot_id ): int {
		global $wpdb;
		$orders = MNS_DB::table( 'orders' );
		$count  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$orders}
				WHERE nurse_user_id = %d
				  AND service_date = %s
				  AND slot_id = %s
				  AND status IN ('assigned','in_progress','service_ended','needs_review')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$nurse_user_id,
				$service_date,
				$slot_id
			)
		);
		return (int) $count;
	}

	/**
	 * @param array<string,mixed> $scope Scope.
	 */
	public static function unassigned_matching_count( array $scope ): int {
		global $wpdb;
		$orders       = MNS_DB::table( 'orders' );
		$service_ids  = self::resolve_service_ids( $scope );
		$service_id   = ! empty( $service_ids[0] ) ? (int) $service_ids[0] : 0;
		$province     = isset( $scope['province_code'] ) ? sanitize_text_field( (string) $scope['province_code'] ) : '';
		$city_id      = isset( $scope['city_id'] ) ? (int) $scope['city_id'] : 0;
		$district_no  = isset( $scope['district_no'] ) ? (int) $scope['district_no'] : 0;
		$service_date = isset( $scope['service_date'] ) ? sanitize_text_field( (string) $scope['service_date'] ) : '';
		$slot_id      = isset( $scope['slot_id'] ) ? sanitize_text_field( (string) $scope['slot_id'] ) : '';
		$gender       = isset( $scope['gender_pref'] ) ? sanitize_text_field( (string) $scope['gender_pref'] ) : '';

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$orders}
				WHERE status = 'submitted'
				  AND nurse_user_id IS NULL
				  AND service_id = %d
				  AND province_code = %s
				  AND city_id = %d
				  AND district_no = %d
				  AND service_date = %s
				  AND slot_id = %s
				  AND gender_pref = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$service_id,
				$province,
				$city_id,
				$district_no,
				$service_date,
				$slot_id,
				$gender
			)
		);
		return (int) $count;
	}
}
