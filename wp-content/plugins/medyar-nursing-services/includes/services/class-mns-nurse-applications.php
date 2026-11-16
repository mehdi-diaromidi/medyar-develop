<?php
/**
 * Nurse membership applications (T-1.1 / R-NRS-1..5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Applications {

	/**
	 * Iranian national ID checksum.
	 */
	public static function valid_national_id( string $nid ): bool {
		if ( ! preg_match( '/^\d{10}$/', $nid ) ) {
			return false;
		}
		if ( preg_match( '/^(\d)\1{9}$/', $nid ) ) {
			return false;
		}
		$check = (int) $nid[9];
		$sum   = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += ( (int) $nid[ $i ] ) * ( 10 - $i );
		}
		$r = $sum % 11;
		return ( $r < 2 && $check === $r ) || ( $r >= 2 && $check === ( 11 - $r ) );
	}

	/**
	 * Submit / resubmit application.
	 *
	 * @param int                 $user_id User.
	 * @param array<string,mixed> $data    Fields.
	 * @param array<string,array> $files   $_FILES subsets keyed by kind.
	 * @return array<string,mixed> Nurse row.
	 * @throws MNS_Error On validation/conflict.
	 */
	public static function apply( int $user_id, array $data, array $files ): array {
		if ( $user_id <= 0 ) {
			throw MNS_Error::auth();
		}

		$existing = MNS_Nurse_Access::nurse_row( $user_id );
		if ( $existing && in_array( (string) $existing['status'], array( 'pending', 'approved', 'suspended' ), true ) ) {
			throw MNS_Error::conflict( 'ERR_CONFLICT', 'درخواست فعال دارید.' );
		}

		$gender = isset( $data['gender'] ) ? sanitize_key( (string) $data['gender'] ) : '';
		if ( ! in_array( $gender, array( 'female', 'male' ), true ) ) {
			throw MNS_Error::validation( 'جنسیت را انتخاب کنید.', 'gender' );
		}

		$nid = isset( $data['national_id'] ) ? preg_replace( '/\D+/', '', (string) $data['national_id'] ) : '';
		$nid = is_string( $nid ) ? $nid : '';
		if ( ! self::valid_national_id( $nid ) ) {
			throw MNS_Error::validation( 'کد ملی نامعتبر است.', 'national_id' );
		}

		// Supervisor fix: the UNIQUE national_id must give a clear conflict, never a silent failed insert.
		global $wpdb;
		$nurses_t = MNS_DB::table( 'nurses' );
		$dup      = $wpdb->get_var(
			$wpdb->prepare( "SELECT user_id FROM {$nurses_t} WHERE national_id = %s AND user_id <> %d LIMIT 1", $nid, $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( null !== $dup ) {
			throw MNS_Error::conflict( 'ERR_CONFLICT', 'برای این کد ملی قبلاً درخواست ثبت شده است.', array( 'field' => 'national_id' ) );
		}

		$required = array( 'license_no', 'qualification', 'experience', 'workplace' );
		foreach ( $required as $f ) {
			if ( empty( $data[ $f ] ) ) {
				throw MNS_Error::validation( 'این فیلد الزامی است.', $f );
			}
		}

		$contract = MNS_Contract::active();
		if ( ! $contract ) {
			throw MNS_Error::server( 'قرارداد فعال نیست.' );
		}
		if ( empty( $data['accept_contract'] ) ) {
			throw new MNS_Error( 'ERR_CONTRACT_REQUIRED', 'پذیرش قرارداد الزامی است.', 422, 'accept_contract' );
		}

		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ip_bin = null;
		if ( $ip && function_exists( 'inet_pton' ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				$ip_bin = $packed;
			}
		}

		$pending_hooks = array();

		$row = MNS_DB::tx(
			static function () use ( $user_id, $data, $files, $gender, $nid, $contract, $ip_bin, $existing, &$pending_hooks ) {
				global $wpdb;

				$file_ids = array();
				$need = array( 'national_card', 'license_card', 'profile_photo' );
				if ( 'male' === $gender ) {
					$need[] = 'military_card';
				}
				try {
				foreach ( $need as $kind ) {
					if ( empty( $files[ $kind ] ) || ! is_array( $files[ $kind ] ) ) {
						throw MNS_Error::validation( 'آپلود این مدرک الزامی است.', $kind );
					}
					$is_photo = ( 'profile_photo' === $kind );
					$file_ids[ $kind ] = MNS_Files::store_upload( $files[ $kind ], $user_id, $kind, null, $is_photo );
				}
				} catch ( \Throwable $e ) {
					self::discard_files( $file_ids );
					throw $e;
				}

				$now = MNS_Time::now_utc();
				$table = MNS_DB::table( 'nurses' );
				$payload = array(
					'user_id'                 => $user_id,
					'status'                  => 'pending',
					'gender'                  => $gender,
					'national_id'             => $nid,
					'license_no'              => sanitize_text_field( (string) $data['license_no'] ),
					'qualification'           => sanitize_text_field( (string) $data['qualification'] ),
					'experience'              => sanitize_text_field( (string) $data['experience'] ),
					'workplace'               => sanitize_text_field( (string) $data['workplace'] ),
					'about'                   => isset( $data['about'] ) ? sanitize_text_field( (string) $data['about'] ) : '',
					'accepting'               => 1,
					'profile_photo_file_id'   => $file_ids['profile_photo'],
					'photo_approved'          => 0,
					'contract_version_id'     => (int) $contract['id'],
					'contract_accepted_at'    => $now,
					'contract_accepted_ip'    => $ip_bin,
					'applied_at'              => $now,
					'reviewed_at'             => null,
					'reviewed_by'             => null,
					'reject_note'             => null,
					'meta'                    => wp_json_encode(
						array(
							'files' => $file_ids,
						)
					),
				);

				if ( $existing ) {
					$saved = $wpdb->update( $table, $payload, array( 'user_id' => $user_id ) );
				} else {
					$saved = $wpdb->insert( $table, $payload );
				}
				if ( false === $saved ) {
					self::discard_files( $file_ids );
					throw MNS_Error::server( 'ثبت درخواست ممکن نشد. دوباره تلاش کنید.' );
				}

				// Sync display name fields on WP user.
				if ( ! empty( $data['first_name'] ) || ! empty( $data['last_name'] ) ) {
					wp_update_user(
						array(
							'ID'         => $user_id,
							'first_name' => isset( $data['first_name'] ) ? sanitize_text_field( (string) $data['first_name'] ) : '',
							'last_name'  => isset( $data['last_name'] ) ? sanitize_text_field( (string) $data['last_name'] ) : '',
						)
					);
				}

				$row = MNS_Nurse_Access::nurse_row( $user_id );
				$pending_hooks[] = array( 'user_id' => $user_id, 'row' => $row );
				return $row ? $row : array();
			}
		);

		foreach ( $pending_hooks as $h ) {
			do_action( 'mns_nurse_applied', $h['user_id'], $h['row'] );
		}

		return $row;
	}

	public static function approve( int $user_id, int $admin_id ): void {
		self::set_status( $user_id, 'approved', $admin_id, '', array( 'pending' ) );
		$user = new WP_User( $user_id );
		$user->add_role( MNS_Roles::ROLE_NURSE );
		do_action( 'mns_nurse_approved', $user_id, $admin_id );
	}

	public static function reject( int $user_id, int $admin_id, string $note ): void {
		if ( '' === trim( $note ) ) {
			throw MNS_Error::validation( 'دلیل رد الزامی است.', 'reject_note' );
		}
		self::set_status( $user_id, 'rejected', $admin_id, $note, array( 'pending' ) );
		do_action( 'mns_nurse_rejected', $user_id, $admin_id, $note );
	}

	public static function suspend( int $user_id, int $admin_id ): void {
		self::set_status( $user_id, 'suspended', $admin_id, '', array( 'approved' ) );
		do_action( 'mns_nurse_suspended', $user_id, $admin_id );
	}

	public static function reinstate( int $user_id, int $admin_id ): void {
		self::set_status( $user_id, 'approved', $admin_id, '', array( 'suspended' ) );
		$user = new WP_User( $user_id );
		$user->add_role( MNS_Roles::ROLE_NURSE );
		do_action( 'mns_nurse_reinstated', $user_id, $admin_id );
	}

	/**
	 * Supervisor fix: guarded status change (only from expected statuses, exactly one row),
	 * so a missing or wrong-state row can never get a status change or the nurse role.
	 *
	 * @param array<int,string> $allowed_from Allowed current statuses.
	 * @throws MNS_Error When the nurse row is missing or in the wrong state.
	 */
	private static function set_status( int $user_id, string $status, int $admin_id, string $note, array $allowed_from ): void {
		global $wpdb;
		$table = MNS_DB::table( 'nurses' );

		$placeholders = implode( ',', array_fill( 0, count( $allowed_from ), '%s' ) );
		$args         = array_merge(
			array( $status, MNS_Time::now_utc(), $admin_id, $note, $user_id ),
			$allowed_from
		);
		$sql          = "UPDATE {$table} SET status = %s, reviewed_at = %s, reviewed_by = %d, reject_note = NULLIF(%s, '') WHERE user_id = %d AND status IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows         = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 !== (int) $rows ) {
			throw MNS_Error::bad_state( 'این درخواست در وضعیت مناسب برای این اقدام نیست.' );
		}
	}

	/**
	 * Remove stored upload files from disk after a failed application (privacy: no orphans).
	 *
	 * @param array<string,int> $file_ids File ids by kind.
	 */
	private static function discard_files( array $file_ids ): void {
		foreach ( $file_ids as $id ) {
			$row = MNS_Files::get( (int) $id );
			if ( is_array( $row ) ) {
				$path = MNS_Files::abs_path( $row );
				if ( $path ) {
					wp_delete_file( $path );
				}
			}
		}
	}
}
