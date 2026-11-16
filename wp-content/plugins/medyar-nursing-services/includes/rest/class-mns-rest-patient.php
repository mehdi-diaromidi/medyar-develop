<?php
/**
 * Patient REST: bootstrap, patients CRUD, A-13, orders (T-3.2..T-3.4 / T-3.6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_REST_Patient {

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			MNS_REST::NS,
			'/patient/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'bootstrap' ),
				'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/patient/patients',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_patients' ),
					'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_patient' ),
					'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
				),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/patient/patients/(?P<id>\\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_patient' ),
					'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_patient' ),
					'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
				),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/availability/check',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'a13_check' ),
				'permission_callback' => array( __CLASS__, 'perm_a13' ),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/orders',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_orders' ),
					'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_order' ),
					'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
				),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/orders/(?P<code>[0-9]{5,6})',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_order' ),
					'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( __CLASS__, 'patch_order' ),
					'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
				),
			)
		);
		register_rest_route(
			MNS_REST::NS,
			'/orders/(?P<code>[0-9]{5,6})/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cancel_order' ),
				'permission_callback' => array( 'MNS_REST', 'permission_patient' ),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function perm_a13() {
		$p = MNS_REST::permission_patient();
		if ( true === $p ) {
			return true;
		}
		return MNS_REST::permission_admin();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function bootstrap( $request ) {
		try {
			$uid  = get_current_user_id();
			$slug = sanitize_title( (string) $request->get_param( 'service' ) );
			MNS_Services_Seed::ensure_slice1();
			$services = MNS_Services::list_active();
			$service  = null;
			if ( $slug ) {
				$service = MNS_Services::get( $slug );
				if ( $service && empty( $service['active'] ) ) {
					$service = null;
				}
			}
			if ( ! $service && ! empty( $services ) ) {
				$service = $services[0];
			}
			$phone = '';
			if ( class_exists( '\DrPlus\Utils\User' ) && is_callable( array( '\DrPlus\Utils\User', 'get_phone' ) ) ) {
				$phone = (string) \DrPlus\Utils\User::get_phone( $uid );
			}
			$slots = MNS_Settings::get( 'slots' );
			if ( ! is_array( $slots ) ) {
				$slots = MNS_Settings::default_slots();
			}
			$time_slots = array();
			foreach ( $slots as $s ) {
				if ( ! is_array( $s ) || empty( $s['id'] ) ) {
					continue;
				}
				$sh = (int) ( $s['start_hour'] ?? 0 );
				$eh = (int) ( $s['end_hour'] ?? ( $sh + 4 ) );
				$time_slots[] = array(
					'id'       => (string) $s['id'],
					'label'    => $sh . ' تا ' . $eh,
					'name'     => (string) ( $s['name'] ?? '' ),
					'disabled' => ! empty( $s['closed'] ),
				);
			}
			$svc_out = null;
			$fields  = array();
			if ( $service ) {
				$svc_out = array(
					'id'        => (string) $service['id'],
					'slug'      => $service['slug'],
					'name'      => $service['name'],
					'price_min' => $service['price_from'],
					'price_max' => $service['price_to'],
				);
				$fields = isset( $service['config']['order_fields'] ) && is_array( $service['config']['order_fields'] )
					? $service['config']['order_fields']
					: array();
			}
			$svc_list = array();
			foreach ( $services as $s ) {
				$svc_list[] = array(
					'id'   => $s['id'],
					'slug' => $s['slug'],
					'name' => $s['name'],
				);
			}
			return MNS_REST::success(
				array(
					'default_phone'  => $phone,
					'service'        => $svc_out,
					'services'       => $svc_list,
					'patients'       => MNS_Patients::list_for( $uid ),
					'districts'      => MNS_Locations::districts_map(),
					'time_slots'     => $time_slots,
					'service_fields' => $fields,
				),
				$request
			);
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_patients( $request ) {
		try {
			return MNS_REST::success( array( 'items' => MNS_Patients::list_for( get_current_user_id() ) ), $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function create_patient( $request ) {
		try {
			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = $request->get_params();
			}
			$row = MNS_Patients::create( get_current_user_id(), is_array( $params ) ? $params : array() );
			return MNS_REST::success( $row, $request, array(), 201 );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update_patient( $request ) {
		try {
			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = $request->get_params();
			}
			$row = MNS_Patients::update( (int) $request['id'], get_current_user_id(), is_array( $params ) ? $params : array() );
			return MNS_REST::success( $row, $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_patient( $request ) {
		try {
			MNS_Patients::soft_delete( (int) $request['id'], get_current_user_id() );
			return MNS_REST::success( array( 'deleted' => true ), $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * API-A13-1 with time validation (T-3.3).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function a13_check( $request ) {
		$uid = get_current_user_id();
		$rate = (string) MNS_Settings::get( 'a13_rate' );
		$limit = 30;
		$window = 60;
		if ( preg_match( '/^(\d+)\s*\/\s*min/i', $rate, $m ) ) {
			$limit = max( 1, (int) $m[1] );
		}
		if ( ! MNS_REST::rate_limit( 'a13_' . $uid, $limit, $window ) ) {
			return MNS_REST::fail( MNS_Error::rate_limit(), $request );
		}

		$service_raw = sanitize_text_field( (string) $request->get_param( 'service' ) );
		$prov        = strtoupper( sanitize_text_field( (string) $request->get_param( 'prov' ) ) );
		$city        = (int) $request->get_param( 'city' );
		if ( ! $city ) {
			$city = (int) $request->get_param( 'city_id' );
		}
		$district = (int) $request->get_param( 'district' );
		$date     = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$slot     = sanitize_text_field( (string) $request->get_param( 'slot' ) );
		$gender   = sanitize_key( (string) $request->get_param( 'gender' ) );

		$svc = MNS_Services::get( $service_raw );
		if ( ! $svc || empty( $svc['active'] ) ) {
			return MNS_REST::fail( MNS_Error::validation( 'خدمت نامعتبر است.', 'service' ), $request );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return MNS_REST::fail( MNS_Error::validation( 'تاریخ نامعتبر است.', 'date' ), $request );
		}
		$today = MNS_Time::today_tehran();
		$horizon = (int) MNS_Settings::get( 'order_horizon' );
		if ( $horizon < 1 ) {
			$horizon = 30;
		}
		$tz = new DateTimeZone( MNS_Time::TZ );
		$d  = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $tz );
		$t  = DateTimeImmutable::createFromFormat( 'Y-m-d', $today, $tz );
		if ( ! $d || ! $t || $d < $t || $d > $t->modify( '+' . $horizon . ' days' ) ) {
			return MNS_REST::fail( MNS_Error::validation( 'تاریخ خارج از بازهٔ مجاز است.', 'date' ), $request );
		}
		if ( ! MNS_Time::slot_known( $slot ) || $d->format( 'Y-m-d' ) !== $date ) {
			return MNS_REST::fail( MNS_Error::validation( 'بازهٔ زمانی یا تاریخ نامعتبر است.', 'slot' ), $request );
		}
		if ( MNS_Time::slot_closed( $slot ) ) {
			return MNS_REST::fail( MNS_Error::validation( 'این بازه بسته است.', 'slot' ), $request );
		}
		if ( $date === $today && MNS_Time::slot_start_ts( $date, $slot ) < time() ) {
			return MNS_REST::fail( MNS_Error::validation( 'شروع این بازه گذشته است.', 'slot' ), $request );
		}
		if ( ! in_array( $gender, array( 'female', 'male' ), true ) ) {
			return MNS_REST::fail( MNS_Error::validation( 'جنسیت نامعتبر است.', 'gender' ), $request );
		}

		$check = MNS_Availability::check(
			array(
				'service_id'    => (int) $svc['id'],
				'province_code' => $prov,
				'city_id'       => $city,
				'district_no'   => $district,
				'service_date'  => $date,
				'slot_id'       => $slot,
				'gender_pref'   => $gender,
			)
		);
		// Strip any accidental nurse data — only state/message/alt.
		$out = array(
			'state'   => (string) $check['state'],
			'message' => (string) $check['message'],
		);
		if ( ! empty( $check['alt'] ) && is_array( $check['alt'] ) ) {
			$out['alt'] = array_values( $check['alt'] );
		}
		return MNS_REST::success( $out, $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function create_order( $request ) {
		try {
			$idem = MNS_REST::idempotency_key( $request );
			if ( '' !== $idem ) {
				$cached = MNS_REST::idempotency( $idem );
				if ( is_array( $cached ) ) {
					return MNS_REST::success( $cached, $request, array(), 201 );
				}
			}
			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = $request->get_params();
			}
			$result = MNS_Orders::create_from_request( get_current_user_id(), is_array( $params ) ? $params : array() );
			if ( '' !== $idem ) {
				MNS_REST::idempotency( $idem, $result );
			}
			return MNS_REST::success( $result, $request, array(), 201 );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_orders( $request ) {
		try {
			$data = MNS_Patient_Orders_Api::list_for( get_current_user_id(), $request->get_params() );
			return MNS_REST::success( $data['data'], $request, $data['meta'] ?? array(), 200 );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_order( $request ) {
		try {
			$code = sanitize_text_field( (string) $request['code'] );
			$data = MNS_Patient_Orders_Api::get_for( get_current_user_id(), $code );
			return MNS_REST::success( $data, $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function patch_order( $request ) {
		try {
			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = $request->get_params();
			}
			$data = MNS_Patient_Orders_Api::patch_for( get_current_user_id(), sanitize_text_field( (string) $request['code'] ), is_array( $params ) ? $params : array() );
			return MNS_REST::success( $data, $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function cancel_order( $request ) {
		try {
			$params = $request->get_json_params();
			if ( ! is_array( $params ) ) {
				$params = $request->get_params();
			}
			$reason = isset( $params['reason'] ) ? sanitize_text_field( (string) $params['reason'] ) : '';
			$data   = MNS_Patient_Orders_Api::cancel_for( get_current_user_id(), sanitize_text_field( (string) $request['code'] ), $reason );
			return MNS_REST::success( $data, $request );
		} catch ( MNS_Error $e ) {
			return MNS_REST::fail( $e, $request );
		}
	}
}
