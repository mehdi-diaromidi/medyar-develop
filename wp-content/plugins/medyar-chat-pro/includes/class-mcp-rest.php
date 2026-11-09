<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API: /wp-json/medyar-chat/v1/
 * Every route authorizes on the server (MCP_Access); the UI only mirrors these rules.
 */
final class MCP_REST {

	public const NS = 'medyar-chat/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$id = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/context',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'context' ),
				'permission_callback' => array( __CLASS__, 'perm_read_session' ),
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/meta',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'meta' ),
				'permission_callback' => array( __CLASS__, 'perm_participant' ),
				'args'                => array_merge(
					$id,
					array(
						'ids' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/typing',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'typing' ),
				'permission_callback' => array( __CLASS__, 'perm_participant' ),
				'args'                => array_merge( $id, array( 'typing' => array( 'type' => 'boolean', 'default' => true ) ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/attachments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'attachment' ),
				'permission_callback' => array( __CLASS__, 'perm_participant' ),
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_status' ),
				'permission_callback' => array( __CLASS__, 'perm_manage_session' ),
				'args'                => array_merge(
					$id,
					array(
						'status' => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => array_keys( MCP_Sessions::labels() ),
							'sanitize_callback' => 'sanitize_key',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/follow-up',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'set_follow_up' ),
				'permission_callback' => array( __CLASS__, 'perm_manage_session' ),
				'args'                => array_merge(
					$id,
					array(
						'at'   => array(
							'type'              => array( 'string', 'null' ),
							'default'           => null,
							'sanitize_callback' => static fn( $v ) => null === $v || '' === $v ? null : sanitize_text_field( (string) $v ),
						),
						'note' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'date' => array(
							'description'       => 'Jalali Y/m/d (Persian or ASCII digits); takes precedence over "at".',
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'time' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/timing/extend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'timing_extend' ),
				'permission_callback' => array( __CLASS__, 'perm_session_specialist' ),
				'args'                => array_merge(
					$id,
					array(
						'minutes' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/timing/decline',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'timing_decline' ),
				'permission_callback' => array( __CLASS__, 'perm_session_specialist' ),
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/timing/ack',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'timing_ack' ),
				'permission_callback' => array( __CLASS__, 'perm_participant' ),
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/profile',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'profile' ),
				'permission_callback' => array( __CLASS__, 'perm_profile' ),
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'notes' ),
					'permission_callback' => array( __CLASS__, 'perm_read_notes' ),
					'args'                => $id,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_note' ),
					'permission_callback' => array( __CLASS__, 'perm_write_notes' ),
					'args'                => array_merge( $id, array( 'body' => array( 'type' => 'string', 'required' => true ) ) ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/notes/(?P<note_id>\d+)',
			array(
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( __CLASS__, 'update_note' ),
					'permission_callback' => array( __CLASS__, 'perm_write_notes' ),
					'args'                => array_merge( $id, array( 'body' => array( 'type' => 'string', 'required' => true ) ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_note' ),
					'permission_callback' => array( __CLASS__, 'perm_write_notes' ),
					'args'                => $id,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/(?P<id>\d+)/referrals',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_referral' ),
				'permission_callback' => array( __CLASS__, 'perm_session_specialist' ),
				'args'                => array_merge(
					$id,
					array(
						'to_user_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'reason'     => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/specialists',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'specialists' ),
				'permission_callback' => array( __CLASS__, 'perm_specialist_user' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/messages/(?P<id>\d+)/reactions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'react' ),
				'permission_callback' => array( __CLASS__, 'perm_message_participant' ),
				'args'                => array_merge(
					$id,
					array(
						'reaction' => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NS,
			'/quick-replies',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'quick_replies' ),
					'permission_callback' => array( __CLASS__, 'perm_specialist_user' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'add_quick_reply' ),
					'permission_callback' => array( __CLASS__, 'perm_specialist_user' ),
					'args'                => array( 'text' => array( 'type' => 'string', 'required' => true ) ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/quick-replies/order',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'order_quick_replies' ),
				'permission_callback' => array( __CLASS__, 'perm_specialist_user' ),
				'args'                => array( 'ids' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'string' ) ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/quick-replies/(?P<qid>[a-f0-9]{6,32})',
			array(
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( __CLASS__, 'update_quick_reply' ),
					'permission_callback' => array( __CLASS__, 'perm_specialist_user' ),
					'args'                => array( 'text' => array( 'type' => 'string', 'required' => true ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_quick_reply' ),
					'permission_callback' => array( __CLASS__, 'perm_specialist_user' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/sessions/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'summary' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'ids' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permission callbacks
	 * ------------------------------------------------------------------- */

	private static function sid( WP_REST_Request $request ): int {
		return absint( $request->get_param( 'id' ) );
	}

	/** @return true|WP_Error */
	private static function allow( bool $ok ) {
		return $ok ? true : MCP_Access::forbidden();
	}

	public static function perm_participant( WP_REST_Request $request ) {
		return self::allow( is_user_logged_in() && MCP_Access::is_participant( self::sid( $request ) ) );
	}

	public static function perm_read_session( WP_REST_Request $request ) {
		return self::allow( is_user_logged_in() && MCP_Access::can_read_session( self::sid( $request ) ) );
	}

	public static function perm_manage_session( WP_REST_Request $request ) {
		return self::allow( is_user_logged_in() && MCP_Access::can_manage_session( self::sid( $request ) ) );
	}

	public static function perm_session_specialist( WP_REST_Request $request ) {
		return self::allow( is_user_logged_in() && MCP_Access::is_session_specialist( self::sid( $request ) ) );
	}

	public static function perm_profile( WP_REST_Request $request ) {
		return self::allow( is_user_logged_in() && MCP_Access::can_view_patient_profile( self::sid( $request ) ) );
	}

	public static function perm_read_notes( WP_REST_Request $request ) {
		return self::allow( is_user_logged_in() && MCP_Access::can_read_notes( self::sid( $request ) ) );
	}

	public static function perm_write_notes( WP_REST_Request $request ) {
		return self::allow( is_user_logged_in() && MCP_Access::can_write_notes( self::sid( $request ) ) );
	}

	public static function perm_specialist_user() {
		return self::allow( is_user_logged_in() && MCP_Access::is_specialist_user() );
	}

	public static function perm_message_participant( WP_REST_Request $request ) {
		$message = MCP_Theme::get_message( self::sid( $request ) );
		return self::allow( is_user_logged_in() && $message && MCP_Access::is_participant( (int) $message['session_id'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Callbacks
	 * ------------------------------------------------------------------- */

	public static function context( WP_REST_Request $request ): WP_REST_Response {
		$session_id = self::sid( $request );
		$user_id    = get_current_user_id();
		$role       = MCP_Access::role( $session_id, $user_id );
		$meta       = MCP_Sessions::ensure_meta( $session_id );
		// A supervisor chatting as the patient gets the patient UI; supervision happens in wp-admin.
		$is_patient = 'patient' === $role;
		$manage     = ! $is_patient && MCP_Access::can_manage_session( $session_id );

		$statuses = MCP_Sessions::labels();
		if ( ! MCP_Access::can_manage() && MCP_Sessions::CLOSED === MCP_Sessions::state( $session_id )['status'] ) {
			$statuses = array( MCP_Sessions::CLOSED => $statuses[ MCP_Sessions::CLOSED ] );
		}

		$data = array(
			'role'      => $role,
			'state'     => MCP_Sessions::state( $session_id ),
			'can'       => array(
				'manage'   => $manage,
				'profile'  => ! $is_patient && MCP_Access::can_view_patient_profile( $session_id ),
				'notes'    => MCP_Access::can_write_notes( $session_id ),
				'refer'    => MCP_Access::is_session_specialist( $session_id ),
				'quick'    => 'specialist' === $role,
				'react'    => '' !== $role && MCP_Settings::reactions_enabled(),
				'extend'   => MCP_Access::is_session_specialist( $session_id ),
			),
			'statuses'  => $manage ? $statuses : array(),
			'follow_up' => $manage ? array(
				'at'   => $meta['follow_up_at'] ?? null,
				'note' => $meta['follow_up_note'] ?? '',
			) : null,
		);
		if ( 'specialist' === $role ) {
			$data['quick_replies'] = MCP_Quick_Replies::all( $user_id );
		}
		if ( '' !== $role && $request->get_param( 'with_meta' ) ) {
			$data['meta'] = (object) MCP_Messages::map( $session_id, $user_id );
		}
		return rest_ensure_response( $data );
	}

	public static function meta( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'meta', 40 );
		if ( $guard ) {
			return $guard;
		}
		$session_id = self::sid( $request );
		$ids        = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		return rest_ensure_response(
			array(
				'state'  => MCP_Sessions::state( $session_id ),
				'typing' => MCP_Sessions::other_typing( $session_id, get_current_user_id() ),
				'meta'   => (object) MCP_Messages::map( $session_id, get_current_user_id(), array_slice( $ids, 0, 500 ) ),
			)
		);
	}

	public static function typing( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'typing', 40 );
		if ( $guard ) {
			return $guard;
		}
		$session_id = self::sid( $request );
		$user_id    = get_current_user_id();
		$session    = MCP_Theme::get_session( $session_id );
		MCP_Sessions::set_typing( $session_id, $user_id, (bool) $request->get_param( 'typing' ) && $session && MCP_Theme::accepts_messages( $session ) );
		return rest_ensure_response( array( 'typing' => MCP_Sessions::other_typing( $session_id, $user_id ) ) );
	}

	/**
	 * Upload + message in one round trip (the theme needs two). Same checks as the theme routes
	 * plus chat-pro validation; the message is created through the theme so notifications still fire.
	 */
	public static function attachment( WP_REST_Request $request ) {
		$session_id = self::sid( $request );
		$user_id    = get_current_user_id();
		$session    = MCP_Theme::get_session( $session_id );
		$class      = MCP_Theme::chat_class();
		if ( ! $session || '' === $class || ! method_exists( $class, 'upload_file' ) ) {
			return new WP_Error( 'mcp_unavailable', __( 'ارسال فایل در دسترس نیست.', 'medyar-chat-pro' ), array( 'status' => 500 ) );
		}
		if ( ! MCP_Theme::accepts_messages( $session ) ) {
			return new WP_Error( 'mcp_chat_closed', __( 'این مکالمه بسته شده است و امکان ارسال پیام وجود ندارد.', 'medyar-chat-pro' ), array( 'status' => 403 ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'mcp_no_file', __( 'فایلی دریافت نشد.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$file = $files['file'];
		$type = 'voice' === $request->get_param( 'type' ) ? 'voice' : 'file';

		$client_uid = MCP_Messages::sanitize_uid( $request->get_param( 'client_uid' ) );
		$claim      = array();
		if ( '' !== $client_uid ) {
			$claim = MCP_Messages::reserve( $session_id, $user_id, $client_uid );
			if ( 'done' === $claim['state'] ) {
				return MCP_Bridge::existing_response( (int) $claim['message_id'], $session_id, $user_id );
			}
			if ( 'pending' === $claim['state'] ) {
				return new WP_Error( 'mcp_in_flight', __( 'این پیام در حال ارسال است.', 'medyar-chat-pro' ), array( 'status' => 409 ) );
			}
		}
		$fail = static function ( WP_Error $error ) use ( $claim ) {
			if ( ! empty( $claim['row_id'] ) ) {
				MCP_Messages::release( (int) $claim['row_id'] );
			}
			return $error;
		};

		if ( class_exists( '\DrPlus\REST\RateLimiter' ) ) {
			$limited = \DrPlus\REST\RateLimiter::check( $user_id, 'chat_upload_file', 5 );
			if ( is_wp_error( $limited ) ) {
				return $fail( $limited );
			}
		}
		if ( ! MCP_Rate_Limiter::hit( 'send_hourly', $user_id, (int) MCP_Settings::get( 'messages_per_hour' ), HOUR_IN_SECONDS ) ) {
			return $fail( MCP_Rate_Limiter::error() );
		}

		$valid = MCP_Files::validate_upload( $file, $user_id );
		if ( is_wp_error( $valid ) ) {
			return $fail( $valid );
		}

		$file_url = call_user_func( array( $class, 'upload_file' ), $file, $user_id );
		if ( is_wp_error( $file_url ) || ! is_string( $file_url ) || '' === $file_url ) {
			$error = is_wp_error( $file_url ) ? $file_url : new WP_Error( 'mcp_upload_failed', __( 'آپلود فایل ناموفق بود.', 'medyar-chat-pro' ) );
			$error->add_data( array( 'status' => 400 ) );
			return $fail( $error );
		}

		$reply_to = absint( $request->get_param( 'reply_to' ) );
		if ( $reply_to && ! MCP_Messages::belongs_to( $reply_to, $session_id ) ) {
			$reply_to = 0;
		}

		MCP_Bridge::defer_notifications();
		$name       = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		$message_id = MCP_Theme::send_message( $session_id, $user_id, $name, $type, $file_url );
		if ( ! $message_id ) {
			return $fail( new WP_Error( 'mcp_send_failed', __( 'ارسال پیام ناموفق بود.', 'medyar-chat-pro' ), array( 'status' => 500 ) ) );
		}

		if ( ! empty( $claim['row_id'] ) ) {
			MCP_Messages::attach( (int) $claim['row_id'], $message_id );
			if ( $reply_to ) {
				global $wpdb;
				$wpdb->update( MCP_Install::table( 'message_meta' ), array( 'reply_to' => $reply_to ), array( 'id' => (int) $claim['row_id'] ) );
			}
		} elseif ( $reply_to ) {
			MCP_Messages::record( $message_id, $session_id, $user_id, $reply_to );
		}

		$response = MCP_Bridge::existing_response( $message_id, $session_id, $user_id );
		$data     = $response->get_data();
		unset( $data['duplicate'] );
		$data['data']['mcp_state'] = MCP_Sessions::state( $session_id );
		$response->set_data( $data );
		return $response;
	}

	public static function set_status( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'status', 20 );
		if ( $guard ) {
			return $guard;
		}
		$session_id = self::sid( $request );
		$result     = MCP_Sessions::set_status( $session_id, (string) $request->get_param( 'status' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'state' => MCP_Sessions::state( $session_id ) ) );
	}

	public static function timing_extend( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'timing_extend', 10 );
		if ( $guard ) {
			return $guard;
		}
		$session_id = self::sid( $request );
		$result     = MCP_Timing::extend( $session_id, (int) $request->get_param( 'minutes' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Hold stays active through state rebuild so sync cannot close the just-extended slot.
		$session = MCP_Theme::get_session( $session_id, true );
		if ( $session && MCP_Theme::is_closed( $session ) && ( $result['phase'] ?? '' ) !== 'ended' ) {
			MCP_Theme::reopen_session( $session_id );
			global $wpdb;
			$wpdb->update(
				MCP_Install::table( 'session_meta' ),
				array(
					'status'     => MCP_Sessions::OPEN,
					'updated_at' => MCP_Theme::now(),
				),
				array( 'session_id' => $session_id )
			);
			MCP_Audit::log( 'session_reopened_after_extend', $session_id, array() );
		}
		$state = MCP_Sessions::state( $session_id );
		MCP_Timing::release_close_hold( $session_id );
		$state['timing'] = $result;
		if ( ( $result['phase'] ?? '' ) !== 'ended' ) {
			$state['accepting']    = true;
			$state['status']       = MCP_Sessions::CLOSED === ( $state['status'] ?? '' ) ? MCP_Sessions::OPEN : $state['status'];
			$state['status_label'] = MCP_Sessions::labels()[ $state['status'] ] ?? $state['status_label'];
		}
		return rest_ensure_response(
			array(
				'timing' => $result,
				'state'  => $state,
			)
		);
	}

	public static function timing_decline( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'timing_decline', 10 );
		if ( $guard ) {
			return $guard;
		}
		$session_id = self::sid( $request );
		$result     = MCP_Timing::decline( $session_id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'timing' => $result,
				'state'  => MCP_Sessions::state( $session_id ),
			)
		);
	}

	public static function timing_ack( WP_REST_Request $request ) {
		$session_id = self::sid( $request );
		return rest_ensure_response(
			array(
				'timing' => MCP_Timing::ack_patient_notice( $session_id ),
				'state'  => MCP_Sessions::state( $session_id ),
			)
		);
	}

	public static function set_follow_up( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'follow_up', 20 );
		if ( $guard ) {
			return $guard;
		}
		$session_id = self::sid( $request );
		$at         = $request->get_param( 'at' );
		$jalali     = (string) $request->get_param( 'date' );
		if ( '' !== $jalali ) {
			$date = MCP_Jalali::to_gregorian( $jalali );
			if ( '' === $date ) {
				return new WP_Error( 'mcp_invalid_date', __( 'تاریخ پیگیری معتبر نیست.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
			}
			$time = MCP_Jalali::en( (string) $request->get_param( 'time' ) );
			$time = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '09:00';
			$at   = $date . ' ' . $time . ':00';
		}
		$result     = MCP_Sessions::set_follow_up( $session_id, null === $at ? null : (string) $at, (string) $request->get_param( 'note' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$meta = MCP_Sessions::get_meta( $session_id );
		return rest_ensure_response(
			array(
				'follow_up' => array(
					'at'   => $meta['follow_up_at'] ?? null,
					'note' => $meta['follow_up_note'] ?? '',
				),
				'state'     => MCP_Sessions::state( $session_id ),
			)
		);
	}

	public static function profile( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'profile', 20 );
		if ( $guard ) {
			return $guard;
		}
		$profile = MCP_Patient_Profile::build( self::sid( $request ), get_current_user_id() );
		return is_wp_error( $profile ) ? $profile : rest_ensure_response( $profile );
	}

	public static function notes( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( MCP_Notes::for_session( self::sid( $request ), get_current_user_id(), MCP_Access::can_view_medical() ) );
	}

	public static function create_note( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'notes', 20 );
		if ( $guard ) {
			return $guard;
		}
		$note = MCP_Notes::create( self::sid( $request ), get_current_user_id(), (string) $request->get_param( 'body' ) );
		return is_wp_error( $note ) ? $note : rest_ensure_response( $note );
	}

	public static function update_note( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'notes', 20 );
		if ( $guard ) {
			return $guard;
		}
		$note = MCP_Notes::update( absint( $request->get_param( 'note_id' ) ), get_current_user_id(), (string) $request->get_param( 'body' ), self::sid( $request ) );
		return is_wp_error( $note ) ? $note : rest_ensure_response( $note );
	}

	public static function delete_note( WP_REST_Request $request ) {
		$result = MCP_Notes::delete( absint( $request->get_param( 'note_id' ) ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function create_referral( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'referral', 5 );
		if ( $guard ) {
			return $guard;
		}
		$result = MCP_Referrals::create( self::sid( $request ), get_current_user_id(), absint( $request->get_param( 'to_user_id' ) ), (string) $request->get_param( 'reason' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function specialists( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'specialists', 30 );
		if ( $guard ) {
			return $guard;
		}
		return rest_ensure_response( MCP_Referrals::search_specialists( (string) $request->get_param( 'search' ), get_current_user_id() ) );
	}

	public static function react( WP_REST_Request $request ) {
		if ( ! MCP_Settings::reactions_enabled() ) {
			return MCP_Access::forbidden();
		}
		$guard = MCP_Rate_Limiter::guard( 'react', 40 );
		if ( $guard ) {
			return $guard;
		}
		$message_id = self::sid( $request );
		$message    = MCP_Theme::get_message( $message_id );
		$reaction   = MCP_Settings::sanitize_reaction( $request->get_param( 'reaction' ) );
		if ( '' !== $reaction && ! in_array( $reaction, (array) MCP_Settings::get( 'reactions' ), true ) ) {
			return new WP_Error( 'mcp_invalid_reaction', __( 'این واکنش مجاز نیست.', 'medyar-chat-pro' ), array( 'status' => 400 ) );
		}
		$session_id = (int) $message['session_id'];
		$session    = MCP_Theme::get_session( $session_id );
		if ( ! $session || MCP_Theme::is_closed( $session ) ) {
			return new WP_Error( 'mcp_chat_closed', __( 'این مکالمه بسته شده است.', 'medyar-chat-pro' ), array( 'status' => 403 ) );
		}
		MCP_Messages::react( $session_id, $message_id, get_current_user_id(), $reaction );
		$meta = MCP_Messages::map( $session_id, get_current_user_id(), array( $message_id ) );
		return rest_ensure_response( array( 'reactions' => $meta[ $message_id ]['reactions'] ?? array() ) );
	}

	public static function quick_replies(): WP_REST_Response {
		return rest_ensure_response( MCP_Quick_Replies::all( get_current_user_id() ) );
	}

	public static function add_quick_reply( WP_REST_Request $request ) {
		$guard = MCP_Rate_Limiter::guard( 'quick', 30 );
		if ( $guard ) {
			return $guard;
		}
		$item = MCP_Quick_Replies::add( get_current_user_id(), (string) $request->get_param( 'text' ) );
		return is_wp_error( $item ) ? $item : rest_ensure_response( $item );
	}

	public static function update_quick_reply( WP_REST_Request $request ) {
		$item = MCP_Quick_Replies::update( get_current_user_id(), (string) $request->get_param( 'qid' ), (string) $request->get_param( 'text' ) );
		return is_wp_error( $item ) ? $item : rest_ensure_response( $item );
	}

	public static function delete_quick_reply( WP_REST_Request $request ) {
		$ok = MCP_Quick_Replies::delete( get_current_user_id(), (string) $request->get_param( 'qid' ) );
		return $ok ? rest_ensure_response( array( 'deleted' => true ) ) : new WP_Error( 'mcp_not_found', __( 'پاسخ آماده پیدا نشد.', 'medyar-chat-pro' ), array( 'status' => 404 ) );
	}

	public static function order_quick_replies( WP_REST_Request $request ): WP_REST_Response {
		MCP_Quick_Replies::reorder( get_current_user_id(), array_map( 'sanitize_key', (array) $request->get_param( 'ids' ) ) );
		return rest_ensure_response( MCP_Quick_Replies::all( get_current_user_id() ) );
	}

	public static function summary( WP_REST_Request $request ): WP_REST_Response {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		return rest_ensure_response( (object) MCP_Sessions::summaries_for_user( $ids, get_current_user_id() ) );
	}
}
