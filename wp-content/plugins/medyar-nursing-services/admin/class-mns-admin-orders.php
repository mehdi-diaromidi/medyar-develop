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
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_ASSIGN, 'assigned' );
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
			MNS_Admin_Ui::redirect( MNS_Admin_Menu::SLUG_ASSIGN, 'cancelled' );
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => $e->http() ) );
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'admin_cancel: ' . $e->getMessage() );
			wp_die( esc_html__( 'خطای داخلی.', 'medyar-nursing-services' ), '', array( 'response' => 500 ) );
		}
	}

	/** @deprecated Phone UI moved to MNS_Admin_Manual; kept for legacy posts. */
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
		$mode       = (string) MNS_Settings::get( 'assignment_mode' );
		MNS_Admin_Ui::header(
			'تخصیص سفارش‌ها',
			array(
				array( 'label' => 'سفارش تلفنی', 'url' => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MANUAL ) ),
				array( 'label' => 'رصد مرکزی', 'url' => MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR ) ),
			)
		);
		echo '<p class="mns-lead">حالت تخصیص فعلی: <strong>' . esc_html( 'auto' === $mode ? 'خودکار' : 'دستی' ) . '</strong>. حداکثر ۵ پیشنهاد با دلیل نمایش داده می‌شود.</p>';
		echo '<div class="postbox mns-box"><div class="postbox-header"><h2>صف بدون پرستار</h2></div><div class="inside">';
		echo '<table class="widefat striped mns-compact"><thead><tr><th>کد</th><th>تاریخ</th><th>بازه</th><th>پیشنهادها</th><th>تخصیص</th></tr></thead><tbody>';
		foreach ( $unassigned as $row ) {
			$sugs = array_slice( MNS_Assignment::suggestions( (int) $row['id'] ), 0, 5 );
			$detail = MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_MONITOR, array( 'action' => 'view', 'id' => (int) $row['id'] ) );
			echo '<tr><td><a class="row-title" href="' . esc_url( $detail ) . '" dir="ltr"><strong>' . esc_html( (string) $row['code'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( (string) $row['service_date'] ) . '</td><td>' . esc_html( (string) $row['slot_id'] ) . '</td><td><ul class="mns-suggest">';
			foreach ( $sugs as $s ) {
				echo '<li><strong>' . esc_html( (string) ( $s['name'] ?? '' ) ) . '</strong> <span class="mns-muted">' . esc_html( (string) ( $s['reason'] ?? '' ) ) . '</span></li>';
			}
			if ( ! $sugs ) {
				echo '<li class="mns-muted">پیشنهادی نیست</li>';
			}
			echo '</ul></td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mns-inline-form">';
			wp_nonce_field( 'mns_order_assign' );
			echo '<input type="hidden" name="action" value="mns_order_assign" />';
			echo '<input type="hidden" name="order_id" value="' . (int) $row['id'] . '" />';
			$first = ! empty( $sugs[0]['user_id'] ) ? (int) $sugs[0]['user_id'] : 0;
			echo '<input type="number" name="nurse_id" value="' . $first . '" class="small-text" placeholder="شناسه پرستار" required />';
			echo '<label><input type="checkbox" name="force" value="1" /> اجبار</label>';
			submit_button( __( 'تخصیص', 'medyar-nursing-services' ), 'primary', 'submit', false );
			echo '</form></td></tr>';
		}
		if ( empty( $unassigned ) ) {
			echo '<tr><td colspan="5"><p class="mns-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> صف خالی است.</p></td></tr>';
		}
		echo '</tbody></table></div></div>';
		MNS_Admin_Ui::footer();
	}

	public static function find_or_create_account_public( string $mobile ): int {
		return self::find_or_create_account( $mobile );
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
