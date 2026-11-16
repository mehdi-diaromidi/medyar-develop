<?php
/**
 * REST GET /files/{id} (T-0.7 / API-FIL-1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_REST_Files {

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			MNS_REST::NS,
			'/files/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'stream' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => static function ( $v ) {
							return absint( $v ) > 0;
						},
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permission( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'ERR_AUTH', 'برای ادامه وارد شوید.', array( 'status' => 401 ) );
		}
		$id  = absint( $request['id'] );
		$row = MNS_Files::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'ERR_NOT_FOUND', 'یافت نشد.', array( 'status' => 404 ) );
		}
		if ( ! MNS_Files::can_read( $row, get_current_user_id() ) ) {
			return new WP_Error( 'ERR_NOT_FOUND', 'یافت نشد.', array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stream( $request ) {
		$id  = absint( $request['id'] );
		$row = MNS_Files::get( $id );
		if ( ! $row ) {
			return MNS_REST::fail( MNS_Error::not_found(), $request );
		}
		$path = MNS_Files::abs_path( $row );
		if ( ! $path ) {
			return MNS_REST::fail( MNS_Error::not_found(), $request );
		}

		$mime = ! empty( $row['mime'] ) ? (string) $row['mime'] : 'application/octet-stream';
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}
}
