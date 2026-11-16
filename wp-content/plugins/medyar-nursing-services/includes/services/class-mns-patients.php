<?php
/**
 * Patient profiles for an account (R-PAT-1 / T-3.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Patients {

	/**
	 * Ensure "خودم" row exists for owner (R-PAT-1).
	 *
	 * @return array<string,mixed>
	 */
	public static function ensure_self( int $owner_user_id ): array {
		global $wpdb;
		$table = MNS_DB::table( 'patients' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_user_id = %d AND kind = 'self' AND deleted_at IS NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_user_id
			),
			ARRAY_A
		);
		if ( is_array( $row ) ) {
			return self::normalize( $row );
		}
		$user = get_userdata( $owner_user_id );
		$name = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
		if ( '' === $name && $user ) {
			$name = $user->display_name;
		}
		if ( '' === $name ) {
			$name = 'خودم';
		}
		$ins = $wpdb->insert(
			$table,
			array(
				'owner_user_id' => $owner_user_id,
				'kind'          => 'self',
				'name'          => $name,
				'age_group'     => 'adult',
				'gender'        => '',
				'created_at'    => MNS_Time::now_utc(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ins ) {
			throw MNS_Error::server( 'ساخت پروفایل خودم ناموفق بود.' );
		}
		return self::get_owned( (int) $wpdb->insert_id, $owner_user_id );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_for( int $owner_user_id ): array {
		self::ensure_self( $owner_user_id );
		global $wpdb;
		$table = MNS_DB::table( 'patients' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_user_id = %d AND deleted_at IS NULL ORDER BY kind = 'self' DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner_user_id
			),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[] = self::normalize( $row );
			}
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 * @throws MNS_Error not_found for non-owner / missing.
	 */
	public static function get_owned( int $id, int $owner_user_id ): array {
		global $wpdb;
		$table = MNS_DB::table( 'patients' );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) $row['owner_user_id'] !== $owner_user_id ) {
			throw MNS_Error::not_found( 'بیمار یافت نشد.' );
		}
		return self::normalize( $row );
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @return array<string,mixed>
	 */
	public static function create( int $owner_user_id, array $data ): array {
		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		if ( '' === $name ) {
			throw MNS_Error::validation( 'نام بیمار الزامی است.', 'name' );
		}
		$age = isset( $data['age_group'] ) ? sanitize_key( (string) $data['age_group'] ) : 'adult';
		if ( ! in_array( $age, array( 'adult', 'child', 'infant' ), true ) ) {
			throw MNS_Error::validation( 'گروه سنی نامعتبر است.', 'age_group' );
		}
		$gender = isset( $data['gender'] ) ? sanitize_key( (string) $data['gender'] ) : '';
		if ( $gender && ! in_array( $gender, array( 'female', 'male' ), true ) ) {
			throw MNS_Error::validation( 'جنسیت نامعتبر است.', 'gender' );
		}
		global $wpdb;
		$ins = $wpdb->insert(
			MNS_DB::table( 'patients' ),
			array(
				'owner_user_id' => $owner_user_id,
				'kind'          => 'other',
				'name'          => $name,
				'age_group'     => $age,
				'gender'        => $gender,
				'created_at'    => MNS_Time::now_utc(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ins ) {
			throw MNS_Error::server( 'ثبت بیمار ناموفق بود.' );
		}
		return self::get_owned( (int) $wpdb->insert_id, $owner_user_id );
	}

	/**
	 * @param array<string,mixed> $data Data.
	 * @return array<string,mixed>
	 */
	public static function update( int $id, int $owner_user_id, array $data ): array {
		$current = self::get_owned( $id, $owner_user_id );
		$fields  = array();
		$formats = array();
		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' === $name ) {
				throw MNS_Error::validation( 'نام بیمار الزامی است.', 'name' );
			}
			$fields['name'] = $name;
			$formats[]      = '%s';
		}
		if ( isset( $data['age_group'] ) ) {
			$age = sanitize_key( (string) $data['age_group'] );
			if ( ! in_array( $age, array( 'adult', 'child', 'infant' ), true ) ) {
				throw MNS_Error::validation( 'گروه سنی نامعتبر است.', 'age_group' );
			}
			$fields['age_group'] = $age;
			$formats[]           = '%s';
		}
		if ( isset( $data['gender'] ) ) {
			$gender = sanitize_key( (string) $data['gender'] );
			if ( $gender && ! in_array( $gender, array( 'female', 'male' ), true ) ) {
				throw MNS_Error::validation( 'جنسیت نامعتبر است.', 'gender' );
			}
			$fields['gender'] = $gender;
			$formats[]        = '%s';
		}
		if ( empty( $fields ) ) {
			return $current;
		}
		global $wpdb;
		$r = $wpdb->update( MNS_DB::table( 'patients' ), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $r ) {
			throw MNS_Error::server( 'ویرایش بیمار ناموفق بود.' );
		}
		return self::get_owned( $id, $owner_user_id );
	}

	public static function soft_delete( int $id, int $owner_user_id ): void {
		$row = self::get_owned( $id, $owner_user_id );
		if ( 'self' === $row['kind'] ) {
			throw MNS_Error::validation( 'پروفایل «خودم» حذف نمی‌شود.' );
		}
		global $wpdb;
		$r = $wpdb->update(
			MNS_DB::table( 'patients' ),
			array( 'deleted_at' => MNS_Time::now_utc() ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $r ) {
			throw MNS_Error::server( 'حذف بیمار ناموفق بود.' );
		}
	}

	/**
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private static function normalize( array $row ): array {
		$kind  = (string) $row['kind'];
		$name  = (string) $row['name'];
		$label = ( 'self' === $kind ) ? 'خودم' : $name;
		return array(
			'id'        => (int) $row['id'],
			'kind'      => $kind,
			'name'      => $name,
			'label'     => $label,
			'meta'      => $name,
			'age_group' => (string) $row['age_group'],
			'gender'    => (string) $row['gender'],
		);
	}
}
