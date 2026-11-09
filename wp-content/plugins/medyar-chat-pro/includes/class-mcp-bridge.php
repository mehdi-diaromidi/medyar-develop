<?php
defined( 'ABSPATH' ) || exit;

/**
 * Hardens and extends the theme's chat REST API (drplus_chat/v1) without touching theme files:
 * - rejects messages/uploads on closed or not-yet-open sessions
 * - per-hour message budget, upload validation/scanning, file_url ownership check
 * - idempotent sends (client_uid) and reply_to storage
 * - appends chat-pro meta (reply, reactions, file facts, session state) to responses
 */
final class MCP_Bridge {

	private const ROUTE_PREFIX = '/drplus_chat/v1/conversations/';

	public const SENT_HOOK = 'drplus/chat/message_sent';

	/** @var array<int,array{row_id:int,reply_to:int,session_id:int,user_id:int}> keyed by spl_object_id(request) */
	private static array $sends = array();

	/** @var array<int,array<int,array<string,mixed>>>|null Listeners of SENT_HOOK held back until the response is flushed. */
	private static ?array $held = null;

	/** @var array<int,array<int,mixed>> Recorded SENT_HOOK calls to replay. */
	private static array $calls = array();

	public static function init(): void {
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'dispatch' ), 10, 4 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'after' ), 10, 3 );
		add_action( 'drplus/chat/message_sent', array( __CLASS__, 'on_message_sent' ), 20, 2 );
		add_action( 'admin_init', array( __CLASS__, 'block_legacy_ajax' ), 0 );
	}

	private static function kind( WP_REST_Request $request ): string {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, self::ROUTE_PREFIX ) ) {
			return '';
		}
		$method = $request->get_method();
		if ( 'POST' === $method && preg_match( '#/conversations/\d+/messages/?$#', $route ) ) {
			return 'send';
		}
		if ( 'POST' === $method && preg_match( '#/conversations/\d+/upload/?$#', $route ) ) {
			return 'upload';
		}
		if ( 'GET' === $method && preg_match( '#/conversations/\d+/messages/?$#', $route ) ) {
			return 'list';
		}
		return '';
	}

	/**
	 * Runs after the theme's permission_callback (participant check) and before its callback.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function dispatch( $result, $request, $route, $handler ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		$kind = self::kind( $request );
		if ( 'send' !== $kind && 'upload' !== $kind ) {
			return $result;
		}

		$session_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();
		$session    = MCP_Theme::get_session( $session_id );
		if ( ! $session || '' === MCP_Theme::role_in_session( $session, $user_id ) ) {
			return $result;
		}

		if ( ! MCP_Theme::accepts_messages( $session ) ) {
			MCP_Audit::log( 'message_rejected', $session_id, array( 'reason' => 'closed', 'kind' => $kind ) );
			return new WP_Error(
				'mcp_chat_closed',
				MCP_Theme::is_closed( $session )
					? __( 'این مکالمه بسته شده است و امکان ارسال پیام وجود ندارد.', 'medyar-chat-pro' )
					: __( 'این مکالمه هنوز باز نشده است.', 'medyar-chat-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( 'upload' === $kind ) {
			$files = $request->get_file_params();
			if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
				return $result;
			}
			$valid = MCP_Files::validate_upload( $files['file'], $user_id );
			return is_wp_error( $valid ) ? $valid : $result;
		}

		$client_uid = MCP_Messages::sanitize_uid( $request->get_param( 'client_uid' ) );
		$claim      = array();

		if ( '' !== $client_uid ) {
			$claim = MCP_Messages::reserve( $session_id, $user_id, $client_uid, 0 );
			if ( 'done' === $claim['state'] ) {
				return self::existing_response( (int) $claim['message_id'], $session_id, $user_id );
			}
			if ( 'pending' === $claim['state'] ) {
				return new WP_Error( 'mcp_in_flight', __( 'این پیام در حال ارسال است.', 'medyar-chat-pro' ), array( 'status' => 409 ) );
			}
		}

		$release = static function () use ( &$claim ) {
			if ( isset( $claim['row_id'] ) ) {
				MCP_Messages::release( (int) $claim['row_id'] );
			}
		};

		if ( ! MCP_Rate_Limiter::hit( 'send_hourly', $user_id, (int) MCP_Settings::get( 'messages_per_hour' ), HOUR_IN_SECONDS ) ) {
			$release();
			MCP_Audit::log( 'rate_limited', $session_id, array( 'bucket' => 'send_hourly' ) );
			return MCP_Rate_Limiter::error();
		}

		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( '' !== $type && 'text' !== $type ) {
			$valid = MCP_Files::validate_message_file_url( (string) $request->get_param( 'file_url' ), $user_id );
			if ( is_wp_error( $valid ) ) {
				$release();
				MCP_Audit::log( 'message_rejected', $session_id, array( 'reason' => 'file_url' ) );
				return $valid;
			}
		}

		$reply_to = absint( $request->get_param( 'reply_to' ) );
		if ( $reply_to && ! MCP_Messages::belongs_to( $reply_to, $session_id ) ) {
			$reply_to = 0;
		}

		if ( $reply_to && isset( $claim['row_id'] ) ) {
			global $wpdb;
			$wpdb->update( MCP_Install::table( 'message_meta' ), array( 'reply_to' => $reply_to ), array( 'id' => (int) $claim['row_id'] ) );
		}

		self::defer_notifications();

		self::$sends[ spl_object_id( $request ) ] = array(
			'row_id'     => (int) ( $claim['row_id'] ?? 0 ),
			'reply_to'   => $reply_to,
			'session_id' => $session_id,
			'user_id'    => $user_id,
		);
		return $result;
	}

	/**
	 * @param mixed $response
	 * @return mixed
	 */
	public static function after( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$kind = self::kind( $request );
		if ( 'send' === $kind ) {
			return self::after_send( $response, $request );
		}
		if ( 'list' === $kind ) {
			return self::after_list( $response, $request );
		}
		return $response;
	}

	/** @param mixed $response */
	private static function after_send( $response, WP_REST_Request $request ) {
		$key  = spl_object_id( $request );
		$send = self::$sends[ $key ] ?? null;
		unset( self::$sends[ $key ] );

		$data       = $response instanceof WP_REST_Response ? $response->get_data() : null;
		$message_id = is_array( $data ) ? (int) ( $data['data']['id'] ?? 0 ) : 0;

		if ( $send ) {
			if ( $send['row_id'] ) {
				if ( $message_id ) {
					MCP_Messages::attach( $send['row_id'], $message_id );
				} else {
					MCP_Messages::release( $send['row_id'] );
				}
			} elseif ( $message_id && $send['reply_to'] ) {
				MCP_Messages::record( $message_id, $send['session_id'], $send['user_id'], $send['reply_to'] );
			}
		}

		if ( $message_id && $send ) {
			$meta                     = MCP_Messages::map( $send['session_id'], $send['user_id'], array( $message_id ) );
			$data['data']['mcp']      = (object) ( $meta[ $message_id ] ?? array() );
			$data['data']['mcp_state'] = MCP_Sessions::state( $send['session_id'] );
			$response->set_data( $data );
		}
		return $response;
	}

	/** @param mixed $response */
	private static function after_list( $response, WP_REST_Request $request ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['data'] ) || ! isset( $data['data']['messages'] ) ) {
			return $response;
		}
		$session_id = (int) $request->get_param( 'id' );
		$user_id    = get_current_user_id();
		if ( ! MCP_Access::is_participant( $session_id, $user_id ) ) {
			return $response;
		}
		$ids  = array_map( static fn( $m ) => (int) ( $m['id'] ?? 0 ), (array) $data['data']['messages'] );
		$meta = $ids ? MCP_Messages::map( $session_id, $user_id, $ids ) : array();
		foreach ( $data['data']['messages'] as $i => $m ) {
			$data['data']['messages'][ $i ]['mcp'] = (object) ( $meta[ (int) $m['id'] ] ?? array() );
		}
		$data['data']['mcp_state']  = MCP_Sessions::state( $session_id );
		$data['data']['mcp_typing'] = MCP_Sessions::other_typing( $session_id, $user_id );
		$response->set_data( $data );
		return $response;
	}

	/** Same shape as the theme's send response, for idempotent replays. */
	public static function existing_response( int $message_id, int $session_id, int $user_id ): WP_REST_Response {
		$m    = MCP_Theme::get_message( $message_id );
		$meta = MCP_Messages::map( $session_id, $user_id, array( $message_id ) );
		return rest_ensure_response(
			array(
				'success'   => true,
				'duplicate' => true,
				'data'      => array(
					'id'         => $message_id,
					'session_id' => (int) ( $m['session_id'] ?? $session_id ),
					'sender_id'  => (int) ( $m['sender_id'] ?? $user_id ),
					'message'    => wp_kses_post( (string) ( $m['message'] ?? '' ) ),
					'type'       => sanitize_text_field( (string) ( $m['type'] ?? 'text' ) ),
					'file_url'   => ! empty( $m['file_url'] ) ? sanitize_url( (string) $m['file_url'] ) : null,
					'is_seen'    => ! empty( $m['is_seen'] ),
					'created_at' => (string) ( $m['created_at'] ?? '' ),
					'mcp'        => (object) ( $meta[ $message_id ] ?? array() ),
				),
			)
		);
	}

	/**
	 * SMS notification listeners on SENT_HOOK make an outbound HTTP call inside the send request.
	 * They are detached for this request and replayed on shutdown after the response is flushed,
	 * so the sender gets the result immediately. Chat-pro's own listener keeps running inline.
	 */
	public static function defer_notifications(): void {
		global $wp_filter;
		if ( null !== self::$held || empty( $wp_filter[ self::SENT_HOOK ] ) || ! apply_filters( 'mcp_defer_chat_notifications', true ) ) {
			return;
		}
		$hook       = $wp_filter[ self::SENT_HOOK ];
		self::$held = array();
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( array( __CLASS__, 'on_message_sent' ) === $cb['function'] ) {
					continue;
				}
				self::$held[ $priority ][] = $cb;
				$hook->remove_filter( self::SENT_HOOK, $cb['function'], $priority );
			}
		}
		add_action( self::SENT_HOOK, array( __CLASS__, 'record_call' ), PHP_INT_MIN, 3 );
		add_action( 'shutdown', array( __CLASS__, 'replay_notifications' ), 1 );
	}

	public static function record_call( ...$args ): void {
		self::$calls[] = $args;
	}

	public static function replay_notifications(): void {
		if ( null === self::$held ) {
			return;
		}
		$held       = self::$held;
		self::$held = null;
		remove_action( self::SENT_HOOK, array( __CLASS__, 'record_call' ), PHP_INT_MIN );
		remove_action( self::SENT_HOOK, array( __CLASS__, 'on_message_sent' ), 20 );
		foreach ( $held as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				add_action( self::SENT_HOOK, $cb['function'], $priority, $cb['accepted_args'] );
			}
		}
		if ( ! self::$calls ) {
			return;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} elseif ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} else {
			flush();
		}
		ignore_user_abort( true );

		foreach ( self::$calls as $args ) {
			do_action( self::SENT_HOOK, ...$args );
		}
		self::$calls = array();
	}

	/** @param mixed $session_id */
	public static function on_message_sent( $session_id, $user_id ): void {
		MCP_Sessions::on_message( (int) $session_id, (int) $user_id );
	}

	/**
	 * The theme still registers admin-ajax chat writers that bypass every REST check above
	 * (and trust a client "sender_type"). Its own JS uses REST only, so they are closed.
	 */
	public static function block_legacy_ajax(): void {
		if ( ! wp_doing_ajax() || ! apply_filters( 'mcp_block_legacy_chat_ajax', true ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, array( 'drplus_chat_send_message', 'drplus_chat_upload_file' ), true ) ) {
			wp_send_json_error(
				array(
					'code'    => 'mcp_use_rest',
					'message' => __( 'این مسیر غیرفعال است.', 'medyar-chat-pro' ),
				),
				410
			);
		}
	}
}
