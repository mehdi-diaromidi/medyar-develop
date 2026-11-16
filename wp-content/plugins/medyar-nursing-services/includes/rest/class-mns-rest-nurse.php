<?php
/**
 * Nurse profile REST (T-1.4 / API-NRS-2/3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_REST_Nurse {

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			MNS_REST::NS,
			'/nurse/me',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_me' ),
					'permission_callback' => array( __CLASS__, 'perm_approved' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'patch_me' ),
					'permission_callback' => array( __CLASS__, 'perm_approved' ),
				),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/me/photo',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_photo' ),
					'permission_callback' => array( __CLASS__, 'perm_approved' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_photo' ),
					'permission_callback' => array( __CLASS__, 'perm_approved' ),
				),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function perm_approved() {
		$base = MNS_REST::permission_nurse();
		if ( true !== $base ) {
			return $base;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( ! MNS_Nurse_Access::can_use_panel() ) {
			return new WP_Error( 'ERR_FORBIDDEN', 'حساب پرستار فعال نیست.', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_me( $request ) {
		$uid = get_current_user_id();
		$row = MNS_Nurse_Access::nurse_row( $uid );
		$user = get_userdata( $uid );
		$mobile = '';
		if ( class_exists( '\DrPlus\Utils\User' ) && is_callable( array( '\DrPlus\Utils\User', 'get_phone' ) ) ) {
			$mobile = (string) \DrPlus\Utils\User::get_phone( $uid );
		}
		$avatar = null;
		if ( $row && ! empty( $row['profile_photo_file_id'] ) && ! empty( $row['photo_approved'] ) ) {
			$avatar = rest_url( MNS_REST::NS . '/files/' . (int) $row['profile_photo_file_id'] );
		}
		$data = array(
			'status'        => $row ? (string) $row['status'] : 'none',
			'display_name'  => $user ? $user->display_name : '',
			'first_name'    => $user ? $user->first_name : '',
			'last_name'     => $user ? $user->last_name : '',
			'email'         => $user ? $user->user_email : '',
			'mobile'        => $mobile,
			'birthday'      => (string) get_user_meta( $uid, 'birthday', true ),
			'national_id'   => $row ? (string) $row['national_id'] : '',
			'gender'        => $row ? (string) $row['gender'] : '',
			'qualification' => $row ? (string) $row['qualification'] : '',
			'license_no'    => $row ? (string) $row['license_no'] : '',
			'experience'    => $row ? (string) $row['experience'] : '',
			'workplace'     => $row ? (string) $row['workplace'] : '',
			'about'         => $row ? (string) $row['about'] : '',
			'avatar_url'    => $avatar,
			'accepting'     => $row ? (bool) $row['accepting'] : false,
			'contract'      => array(
				'version'     => $row && ! empty( $row['contract_version_id'] ) ? (int) $row['contract_version_id'] : 0,
				'accepted_at' => $row && ! empty( $row['contract_accepted_at'] ) ? (string) $row['contract_accepted_at'] : '',
			),
		);
		return MNS_REST::success( $data, $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function patch_me( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		if ( array_key_exists( 'mobile', $params ) || array_key_exists( 'gender', $params ) ) {
			return MNS_REST::fail( MNS_Error::forbidden( 'شماره موبایل و جنسیت قابل ویرایش نیستند.' ), $request );
		}

		$uid = get_current_user_id();
		$user_update = array( 'ID' => $uid );
		if ( isset( $params['display_name'] ) ) {
			$user_update['display_name'] = sanitize_text_field( (string) $params['display_name'] );
		}
		if ( isset( $params['first_name'] ) ) {
			$user_update['first_name'] = sanitize_text_field( (string) $params['first_name'] );
		}
		if ( isset( $params['last_name'] ) ) {
			$user_update['last_name'] = sanitize_text_field( (string) $params['last_name'] );
		}
		if ( isset( $params['email'] ) ) {
			$user_update['user_email'] = sanitize_email( (string) $params['email'] );
		}
		if ( count( $user_update ) > 1 ) {
			$r = wp_update_user( $user_update );
			if ( is_wp_error( $r ) ) {
				return MNS_REST::fail( MNS_Error::validation( $r->get_error_message() ), $request );
			}
		}
		if ( isset( $params['birthday'] ) ) {
			update_user_meta( $uid, 'birthday', sanitize_text_field( (string) $params['birthday'] ) );
		}

		$nurse_fields = array();
		foreach ( array( 'national_id', 'qualification', 'license_no', 'experience', 'workplace', 'about' ) as $f ) {
			if ( isset( $params[ $f ] ) ) {
				$val = sanitize_text_field( (string) $params[ $f ] );
				if ( 'national_id' === $f ) {
						$val = (string) preg_replace( '/\D+/', '', $val );
						if ( ! MNS_Nurse_Applications::valid_national_id( $val ) ) {
							return MNS_REST::fail( MNS_Error::validation( 'کد ملی نامعتبر است.', 'national_id' ), $request );
						}
						global $wpdb;
						$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . MNS_DB::table( 'nurses' ) . ' WHERE national_id = %s AND user_id <> %d LIMIT 1', $val, $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						if ( null !== $dup ) {
							return MNS_REST::fail( MNS_Error::conflict( 'ERR_CONFLICT', 'این کد ملی قبلاً ثبت شده است.', array( 'field' => 'national_id' ) ), $request );
						}
					}
					if ( 'about' === $f && mb_strlen( $val ) > 200 ) {
					return MNS_REST::fail( MNS_Error::validation( 'حداکثر ۲۰۰ نویسه.', 'about' ), $request );
				}
				$nurse_fields[ $f ] = $val;
			}
		}
		if ( ! empty( $nurse_fields ) ) {
			global $wpdb;
			if ( false === $wpdb->update( MNS_DB::table( 'nurses' ), $nurse_fields, array( 'user_id' => $uid ) ) ) {
				return MNS_REST::fail( MNS_Error::server( 'ذخیره ممکن نشد.' ), $request );
			}
		}

		return self::get_me( $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function post_photo( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['photo'] ) ) {
			return MNS_REST::fail( MNS_Error::validation( 'فایل الزامی است.', 'photo' ), $request );
		}
		try {
			$uid = get_current_user_id();
			$fid = MNS_Files::store_upload( $files['photo'], $uid, 'profile_photo', null, true );
			global $wpdb;
			$wpdb->update(
				MNS_DB::table( 'nurses' ),
				array(
					'profile_photo_file_id' => $fid,
					'photo_approved'        => 0,
				),
				array( 'user_id' => $uid ),
				array( '%d', '%d' ),
				array( '%d' )
			);
			return self::get_me( $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_photo( $request ) {
		$uid = get_current_user_id();
		global $wpdb;
		$wpdb->update(
			MNS_DB::table( 'nurses' ),
			array(
				'profile_photo_file_id' => null,
				'photo_approved'        => 0,
			),
			array( 'user_id' => $uid )
		);
		return self::get_me( $request );
	}
}
