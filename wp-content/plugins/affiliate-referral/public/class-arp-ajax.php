<?php
/**
 * admin-ajax endpoints for the affiliate panel.
 *
 * Every endpoint verifies a nonce and the caller's role before touching data,
 * and answers with wp_send_json_* ({ success, data: { message, field? } }).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Ajax {

	public const LIST_NONCE = 'arp_panel_list';

	/** AJAX action => [method, nonce action]. */
	private const ACTIONS = array(
		'arp_submit_referral' => array( 'submit_referral', 'arp_submit_referral' ),
		'arp_save_profile'    => array( 'save_profile', 'arp_save_profile' ),
		'arp_upload_avatar'   => array( 'upload_avatar', 'arp_save_profile' ),
		'arp_remove_avatar'   => array( 'remove_avatar', 'arp_save_profile' ),
		'arp_panel_list'      => array( 'panel_list', self::LIST_NONCE ),
	);

	public static function init(): void {
		foreach ( array_keys( self::ACTIONS ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'dispatch' ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, 'logged_out' ) );
		}
	}

	public static function logged_out(): void {
		wp_send_json_error(
			array(
				'message'  => 'نشست شما به پایان رسیده است. لطفاً دوباره وارد شوید.',
				'redirect' => Arp_Affiliate_Access::login_url( Arp_Pages::dashboard_url() ),
			),
			401
		);
	}

	public static function dispatch(): void {
		$action = current_action();
		$action = substr( $action, strlen( 'wp_ajax_' ) );
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 400 );
		}
		list( $method, $nonce_action ) = self::ACTIONS[ $action ];

		$nonce_key = self::LIST_NONCE === $nonce_action ? 'nonce' : '_wpnonce';
		if ( ! check_ajax_referer( $nonce_action, $nonce_key, false ) ) {
			wp_send_json_error( array( 'message' => 'اعتبار صفحه به پایان رسیده است. لطفاً صفحه را دوباره بارگذاری کنید.' ), 403 );
		}

		self::$method();
	}

	private static function require_affiliate(): int {
		if ( ! Arp_Roles::user_is_affiliate() ) {
			wp_send_json_error( array( 'message' => 'دسترسی به پنل طرح همکاران ندارید.' ), 403 );
		}
		return get_current_user_id();
	}

	/**
	 * Validation failures go out as HTTP 200 + success:false (core's convention): the CDN in
	 * front of the site replaces 4xx bodies with its own page, which would drop message/field.
	 */
	private static function fail( WP_Error $error ): void {
		$data = (array) $error->get_error_data();
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'field'   => (string) ( $data['field'] ?? '' ),
			)
		);
	}

	/* ---------------------------------------------------------------- handlers */

	private static function submit_referral(): void {
		$uid = self::require_affiliate();
		if ( ! current_user_can( Arp_Roles::CAP_SUBMIT ) ) {
			wp_send_json_error( array( 'message' => 'اجازه ثبت معرفی ندارید.' ), 403 );
		}

		$result = Arp_Panel_Actions::submit_referral( $uid, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch(); sanitized in the action.
		if ( is_wp_error( $result ) ) {
			self::fail( $result );
		}
		delete_transient( Arp_Public::old_input_key( $uid ) );

		wp_send_json_success(
			array(
				'message' => Arp_Panel_Actions::message( 'referral', 'ok' ),
				'link'    => array(
					'url'   => Arp_Pages::page_url( 'referrals' ),
					'label' => 'مشاهده معرفی‌های من',
				),
			)
		);
	}

	private static function save_profile(): void {
		$uid    = self::require_affiliate();
		$result = Arp_Panel_Actions::save_profile( $uid, $_POST, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch(); sanitized in the action.
		if ( is_wp_error( $result ) ) {
			self::fail( $result );
		}
		wp_send_json_success(
			array(
				'message' => Arp_Panel_Actions::message( 'profile', 'ok' ),
				'profile' => Arp_Panel_Actions::profile_snapshot( $uid ),
			)
		);
	}

	private static function upload_avatar(): void {
		$uid    = self::require_affiliate();
		$result = Arp_Panel_Actions::upload_avatar( $uid, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dispatch().
		if ( is_wp_error( $result ) ) {
			self::fail( $result );
		}
		self::send_avatar( 'avatar_ok', $uid );
	}

	private static function remove_avatar(): void {
		$uid = self::require_affiliate();
		if ( ! Arp_Panel_Actions::remove_avatar( $uid ) ) {
			self::fail( new WP_Error( 'avatar_none', Arp_Panel_Actions::message( 'profile', 'avatar_none' ) ) );
		}
		self::send_avatar( 'avatar_del', $uid );
	}

	private static function send_avatar( string $code, int $uid ): void {
		$profile = Arp_Panel_Actions::profile_snapshot( $uid );
		wp_send_json_success(
			array(
				'message' => Arp_Panel_Actions::message( 'profile', $code ),
				'profile' => array(
					'has_avatar'  => $profile['has_avatar'],
					'avatar_hero' => $profile['avatar_hero'],
					'avatar_side' => $profile['avatar_side'],
				),
			)
		);
	}

	private static function panel_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified in dispatch().
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$ctx  = isset( $_GET['ctx'] ) ? sanitize_key( wp_unslash( $_GET['ctx'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $view, Arp_Panel_Lists::VIEWS, true ) ) {
			wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 400 );
		}

		if ( 'invited_commissions' === $view ) {
			$uid = get_current_user_id();
			if ( ! Arp_Inviter::is_special_inviter( $uid ) ) {
				wp_send_json_error( array( 'message' => 'دسترسی مجاز نیست.' ), 403 );
			}
			Arp_Pages::set_invited_in_account( 'account' === $ctx );
			$vars = Arp_Panel_Lists::invited_commissions( $uid );
		} elseif ( 'referrals' === $view ) {
			$uid  = self::require_affiliate();
			$vars = Arp_Panel_Lists::referrals( $uid );
		} else {
			$uid  = self::require_affiliate();
			$vars = Arp_Panel_Lists::commissions( $uid );
		}
		wp_send_json_success(
			array(
				'html'  => Arp_Panel_Lists::render( $view, $vars ),
				'total' => (int) $vars['total'],
			)
		);
	}
}
