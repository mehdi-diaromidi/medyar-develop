<?php
/**
 * Admin orders: unassigned queue, assign/reassign/cancel, phone order (T-3.5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Orders {

	public const SLUG = 'mns-orders';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 55 );
		add_action( 'admin_post_mns_order_assign', array( __CLASS__, 'handle_assign' ) );
		add_action( 'admin_post_mns_order_cancel', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_mns_phone_order', array( __CLASS__, 'handle_phone_order' ) );
	}

	public static function register_menu(): void {
		remove_submenu_page( MNS_Admin_Menu::SLUG_DASHBOARD, MNS_Admin_Menu::SLUG_ASSIGN );
		// Supervisor fix: also drop the old skeleton page callback, or the page renders twice.
		remove_all_actions( get_plugin_page_hookname( MNS_Admin_Menu::SLUG_ASSIGN, MNS_Admin_Menu::SLUG_DASHBOARD ) );
		add_submenu_page(
			MNS_Admin_Menu::SLUG_DASHBOARD,
			__( 'تخصیص / سفارش‌ها', 'medyar-nursing-services' ),
			__( 'تخصیص', 'medyar-nursing-services' ),
			'manage_options',
			MNS_Admin_Menu::SLUG_ASSIGN,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_assign(): void {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw MNS_Error::forbidden();
			}
			check_admin_referer( 'mns_order_assign' );
			$oid   = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
			$nurse = isset( $_POST['nurse_id'] ) ? (int) $_POST['nurse_id'] : 0;
			$force = ! empty( $_POST['force'] );
			MNS_Assignment::admin_set_nurse( $oid, $nurse, get_current_user_id(), $force );
			wp_safe_redirect( add_query_arg( array( 'page' => MNS_Admin_Menu::SLUG_ASSIGN, 'mns_ok' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => $e->http() ) );
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'admin_assign: ' . $e->getMessage() );
			wp_die( esc_html__( 'خطای داخلی.', 'medyar-nursing-services' ), '', array( 'response' => 500 ) );
		}
	}

	public static function handle_cancel(): void {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw MNS_Error::forbidden();
			}
			check_admin_referer( 'mns_order_cancel' );
			$oid = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
			$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
			MNS_Order_Machine::transition(
				'cancel_admin',
				array(
					'order_id'      => $oid,
					'reason'        => $reason,
					'actor_user_id' => get_current_user_id(),
					'actor_role'    => 'admin',
				)
			);
			wp_safe_redirect( add_query_arg( array( 'page' => MNS_Admin_Menu::SLUG_ASSIGN, 'mns_ok' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => $e->http() ) );
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'admin_cancel: ' . $e->getMessage() );
			wp_die( esc_html__( 'خطای داخلی.', 'medyar-nursing-services' ), '', array( 'response' => 500 ) );
		}
	}

	public static function handle_phone_order(): void {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw MNS_Error::forbidden();
			}
			check_admin_referer( 'mns_phone_order' );
			$mobile = isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mobile'] ) ) : '';
			$mobile = preg_replace( '/\D+/', '', $mobile );
			if ( ! is_string( $mobile ) || strlen( $mobile ) < 10 ) {
				throw MNS_Error::validation( 'موبایل نامعتبر است.', 'mobile' );
			}
			$user_id = self::find_or_create_account( $mobile );
			$patient = MNS_Patients::ensure_self( $user_id );
			MNS_Services_Seed::ensure_slice1();
			$svc = MNS_Services::get( 'serum' );
			if ( ! $svc ) {
				throw MNS_Error::server( 'خدمتی تعریف نشده.' );
			}
			$order = MNS_Order_Machine::transition(
				'create',
				array(
					'account_user_id' => $user_id,
					'patient_id'      => (int) $patient['id'],
					'service_id'      => (int) $svc['id'],
					'province_code'   => isset( $_POST['prov'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['prov'] ) ) ) : 'THR',
					'city_id'         => isset( $_POST['city_id'] ) ? (int) $_POST['city_id'] : 0,
					'district_no'     => isset( $_POST['district'] ) ? (int) $_POST['district'] : 0,
					'address'         => isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['address'] ) ) : 'تلفنی',
					'contact_phone'   => $mobile,
					'service_date'    => isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date'] ) ) : MNS_Time::today_tehran(),
					'slot_id'         => isset( $_POST['slot'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['slot'] ) ) : '8-12',
					'gender_pref'     => isset( $_POST['gender'] ) ? sanitize_key( wp_unslash( (string) $_POST['gender'] ) ) : 'female',
					'actor_user_id'   => get_current_user_id(),
					'actor_role'      => 'admin',
					'source'          => 'phone',
					'skip_a13'        => true,
				)
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'     => MNS_Admin_Menu::SLUG_ASSIGN,
						'mns_ok'   => '1',
						'code'     => $order['code'],
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => $e->http() ) );
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'phone_order: ' . $e->getMessage() );
			wp_die( esc_html__( 'خطای داخلی.', 'medyar-nursing-services' ), '', array( 'response' => 500 ) );
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازه ندارید.', 'medyar-nursing-services' ) );
		}
		$unassigned = MNS_Assignment::unassigned( 30 );
		echo '<div class="wrap"><h1>' . esc_html__( 'تخصیص و سفارش‌ها', 'medyar-nursing-services' ) . '</h1>';
		if ( isset( $_GET['mns_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'انجام شد.', 'medyar-nursing-services' ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'بدون پرستار', 'medyar-nursing-services' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>کد</th><th>تاریخ</th><th>بازه</th><th>پیشنهاد</th><th>تخصیص</th></tr></thead><tbody>';
		foreach ( $unassigned as $row ) {
			$sugs = MNS_Assignment::suggestions( (int) $row['id'] );
			$sug_txt = array();
			foreach ( $sugs as $s ) {
				$sug_txt[] = esc_html( $s['name'] . ' (' . $s['reason'] . ')' );
			}
			echo '<tr><td>' . esc_html( (string) $row['code'] ) . '</td><td>' . esc_html( (string) $row['service_date'] ) . '</td><td>' . esc_html( (string) $row['slot_id'] ) . '</td><td>' . implode( '<br>', $sug_txt ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:6px;align-items:center">';
			wp_nonce_field( 'mns_order_assign' );
			echo '<input type="hidden" name="action" value="mns_order_assign" />';
			echo '<input type="hidden" name="order_id" value="' . (int) $row['id'] . '" />';
			$first = ! empty( $sugs[0]['user_id'] ) ? (int) $sugs[0]['user_id'] : 0;
			echo '<input type="number" name="nurse_id" value="' . $first . '" placeholder="nurse user_id" />';
			echo '<label><input type="checkbox" name="force" value="1" /> force</label>';
			submit_button( __( 'تخصیص', 'medyar-nursing-services' ), 'secondary', 'submit', false );
			echo '</form></td></tr>';
		}
		if ( empty( $unassigned ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'صف خالی است.', 'medyar-nursing-services' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'سفارش تلفنی', 'medyar-nursing-services' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mns_phone_order' );
		echo '<input type="hidden" name="action" value="mns_phone_order" />';
		echo '<p><label>موبایل <input name="mobile" required /></label></p>';
		echo '<p><label>استان <input name="prov" value="THR" /></label></p>';
		echo '<p><label>city_id <input name="city_id" type="number" value="0" /></label></p>';
		echo '<p><label>تاریخ <input name="date" value="' . esc_attr( MNS_Time::today_tehran() ) . '" /></label></p>';
		echo '<p><label>بازه <input name="slot" value="8-12" /></label></p>';
		echo '<p><label>جنسیت <select name="gender"><option value="female">female</option><option value="male">male</option></select></label></p>';
		echo '<p><label>آدرس <input name="address" class="regular-text" value="تلفنی" /></label></p>';
		submit_button( __( 'ثبت سفارش تلفنی', 'medyar-nursing-services' ) );
		echo '</form></div>';
	}

	private static function find_or_create_account( string $mobile ): int {
		// Prefer DrPlus phone lookup if available.
		if ( class_exists( '\DrPlus\Utils\User' ) && is_callable( array( '\DrPlus\Utils\User', 'get_user_by_phone' ) ) ) {
			$uid = (int) \DrPlus\Utils\User::get_user_by_phone( $mobile );
			if ( $uid > 0 ) {
				return $uid;
			}
		}
		$login = 'mns_p_' . substr( $mobile, -10 );
		$existing = get_user_by( 'login', $login );
		if ( $existing ) {
			return (int) $existing->ID;
		}
		$uid = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 16 ),
				'user_email' => $login . '@medyar.invalid',
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $uid ) ) {
			throw MNS_Error::server( $uid->get_error_message() );
		}
		$uid = (int) $uid;
		if ( class_exists( '\DrPlus\Utils\User' ) && is_callable( array( '\DrPlus\Utils\User', 'set_phone' ) ) ) {
			\DrPlus\Utils\User::set_phone( $uid, $mobile );
		} else {
			update_user_meta( $uid, 'billing_phone', $mobile );
		}
		return $uid;
	}
}
