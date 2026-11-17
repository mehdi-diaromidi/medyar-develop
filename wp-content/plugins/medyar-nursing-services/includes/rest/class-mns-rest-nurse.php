<?php
/**
 * Nurse profile + availability REST (T-1.4 / T-2.2 / API-NRS-2..7).
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
		register_rest_route(
			MNS_REST::NS,
			'/nurse/me/accepting',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'post_accepting' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_availability' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/schedule/days',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'put_schedule_days' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/regions',
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'put_regions' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_orders' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/orders/(?P<code>[0-9]{5,6})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_order' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/orders/(?P<code>[0-9]{5,6})/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start_order' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/orders/(?P<code>[0-9]{5,6})/end',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'end_order' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/nurse/orders/(?P<code>[0-9]{5,6})/steps/(?P<step>checklist|drug|report)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_step' ),
				'permission_callback' => array( __CLASS__, 'perm_approved' ),
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

	/**
	 * API-NRS-4.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function post_accepting( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		if ( ! array_key_exists( 'on', $params ) ) {
			return MNS_REST::fail( MNS_Error::validation( 'مقدار پذیرش الزامی است.', 'on' ), $request );
		}
		$on  = (bool) $params['on'];
		$uid = get_current_user_id();
		global $wpdb;
		$r = $wpdb->update(
			MNS_DB::table( 'nurses' ),
			array( 'accepting' => $on ? 1 : 0 ),
			array( 'user_id' => $uid ),
			array( '%d' ),
			array( '%d' )
		);
		if ( false === $r ) {
			return MNS_REST::fail( MNS_Error::server( 'ذخیره ممکن نشد.' ), $request );
		}
		return MNS_REST::success(
			array(
				'accepting' => $on,
				'status'    => MNS_Nurse_Status::compute( $uid ),
			),
			$request
		);
	}

	/**
	 * API-NRS-5.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_availability( $request ) {
		$uid = get_current_user_id();
		return MNS_REST::success( MNS_Nurse_Schedule::availability_bundle( $uid ), $request );
	}

	/**
	 * API-NRS-6.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function put_schedule_days( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$days = isset( $params['days'] ) && is_array( $params['days'] ) ? $params['days'] : null;
		if ( null === $days ) {
			return MNS_REST::fail( MNS_Error::validation( 'روزها الزامی است.', 'days' ), $request );
		}
		$uid = get_current_user_id();
		try {
			MNS_Nurse_Schedule::save_days( $uid, $days );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
		$bundle = MNS_Nurse_Schedule::availability_bundle( $uid );
		return MNS_REST::success(
			array(
				'status' => $bundle['status'],
				'days'   => $bundle['days'],
			),
			$request
		);
	}

	/**
	 * API-NRS-7.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function put_regions( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$regions = isset( $params['regions'] ) && is_array( $params['regions'] ) ? $params['regions'] : null;
		if ( null === $regions ) {
			return MNS_REST::fail( MNS_Error::validation( 'مناطق الزامی است.', 'regions' ), $request );
		}
		$uid = get_current_user_id();
		try {
			$result = MNS_Nurse_Regions::save_regions( $uid, $regions );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
		$data = array(
			'regions'       => $result['regions'],
			'status'        => MNS_Nurse_Status::compute( $uid ),
			'region_orders' => MNS_Nurse_Regions::region_orders_map( $uid ),
		);
		if ( ! empty( $result['warning'] ) ) {
			$data['warning'] = $result['warning'];
		}
		return MNS_REST::success( $data, $request );
	}

	/**
	 * API-NOR-1.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_orders( $request ) {
		try {
			$res = MNS_Nurse_Orders_Api::list_for( get_current_user_id(), $request->get_params() );
			return MNS_REST::success( $res['data'], $request, $res['meta'] );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * API-NOR-2.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_order( $request ) {
		try {
			$data = MNS_Nurse_Orders_Api::get_for( get_current_user_id(), sanitize_text_field( (string) $request['code'] ) );
			return MNS_REST::success( $data, $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * Start service (assigned → in_progress).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function start_order( $request ) {
		try {
			$code = sanitize_text_field( (string) $request['code'] );
			$row  = self::order_row_for_nurse( $code );
			MNS_Order_Machine::transition(
				'start',
				array(
					'order_id'      => (int) $row['id'],
					'actor_user_id' => get_current_user_id(),
					'actor_role'    => 'nurse',
				)
			);
			$data = MNS_Nurse_Orders_Api::view_model_for( get_current_user_id(), $code );
			return MNS_REST::success( $data, $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * End service (in_progress → service_ended).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function end_order( $request ) {
		try {
			$code = sanitize_text_field( (string) $request['code'] );
			$row  = self::order_row_for_nurse( $code );
			MNS_Order_Machine::transition(
				'end',
				array(
					'order_id'      => (int) $row['id'],
					'actor_user_id' => get_current_user_id(),
					'actor_role'    => 'nurse',
				)
			);
			$data = MNS_Nurse_Orders_Api::view_model_for( get_current_user_id(), $code );
			return MNS_REST::success( $data, $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * Save checklist/drug/report step payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_step( $request ) {
		try {
			$code = sanitize_text_field( (string) $request['code'] );
			$step = sanitize_key( (string) $request['step'] );
			$row  = self::order_row_for_nurse( $code );
			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = $request->get_params();
			}
			$data = isset( $params['data'] ) && is_array( $params['data'] ) ? $params['data'] : $params;
			$steps = MNS_Execution::save_step( (int) $row['id'], $step, $data, get_current_user_id(), 'nurse' );
			return MNS_REST::success( array( 'steps' => $steps ), $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function order_row_for_nurse( string $code ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MNS_DB::table( 'orders' ) . ' WHERE code = %s LIMIT 1', $code ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) $row['nurse_user_id'] !== get_current_user_id() ) {
			throw MNS_Error::not_found( 'سفارش یافت نشد.' );
		}
		return $row;
	}
}
