<?php
/**
 * Phone order admin page (P-21).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Manual {

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 56 );
		add_action( 'admin_post_mns_manual_order', array( __CLASS__, 'handle' ) );
	}

	public static function register_menu(): void {
		remove_submenu_page( MNS_Admin_Menu::SLUG_DASHBOARD, MNS_Admin_Menu::SLUG_MANUAL );
		remove_all_actions( get_plugin_page_hookname( MNS_Admin_Menu::SLUG_MANUAL, MNS_Admin_Menu::SLUG_DASHBOARD ) );
		add_submenu_page(
			MNS_Admin_Menu::SLUG_DASHBOARD,
			__( 'سفارش تلفنی', 'medyar-nursing-services' ),
			__( 'سفارش تلفنی', 'medyar-nursing-services' ),
			'manage_options',
			MNS_Admin_Menu::SLUG_MANUAL,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ) );
		}
		$thr = class_exists( 'MNS_Locations', true ) ? (int) MNS_Locations::city_id( 'THR', 'تهران' ) : 0;
		MNS_Admin_Ui::header( 'سفارش تلفنی' );
		echo '<p class="mns-lead">جستجو/ساخت حساب با موبایل، ثبت خدمت و زمان، تخصیص اختیاری پرستار.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mns-wrap">';
		wp_nonce_field( 'mns_manual_order' );
		echo '<input type="hidden" name="action" value="mns_manual_order" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="mobile">موبایل بیمار</label></th><td><input class="regular-text" dir="ltr" name="mobile" id="mobile" required placeholder="0912…" /></td></tr>';
		echo '<tr><th><label for="prov">استان</label></th><td><input name="prov" id="prov" value="THR" class="small-text" /></td></tr>';
		echo '<tr><th><label for="city_id">شناسه شهر</label></th><td><input type="number" name="city_id" id="city_id" value="' . esc_attr( (string) $thr ) . '" class="small-text" /></td></tr>';
		echo '<tr><th><label for="district">منطقه</label></th><td><input type="number" name="district" id="district" value="1" class="small-text" min="1" /></td></tr>';
		echo '<tr><th><label for="date">تاریخ خدمت</label></th><td><input name="date" id="date" value="' . esc_attr( MNS_Time::today_tehran() ) . '" class="regular-text" dir="ltr" /></td></tr>';
		echo '<tr><th><label for="slot">بازه</label></th><td><select name="slot" id="slot">';
		foreach ( array( '8-12', '12-16', '16-20', '20-24' ) as $slot ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $slot ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="gender">جنسیت پرستار</label></th><td><select name="gender" id="gender"><option value="female">زن</option><option value="male">مرد</option></select></td></tr>';
		echo '<tr><th><label for="address">نشانی</label></th><td><input class="large-text" name="address" id="address" value="سفارش تلفنی" required /></td></tr>';
		echo '<tr><th><label for="nurse_id">پرستار (اختیاری)</label></th><td><input type="number" name="nurse_id" id="nurse_id" class="small-text" placeholder="user_id" /> <span class="description">خالی = فقط ثبت submitted</span></td></tr>';
		echo '</tbody></table>';
		submit_button( 'ثبت سفارش تلفنی' );
		echo '</form>';
		MNS_Admin_Ui::footer();
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'medyar-nursing-services' ) );
		}
		check_admin_referer( 'mns_manual_order' );
		try {
			$mobile = isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mobile'] ) ) : '';
			$mobile = preg_replace( '/\D+/', '', $mobile );
			if ( ! is_string( $mobile ) || strlen( $mobile ) < 10 ) {
				throw MNS_Error::validation( 'موبایل نامعتبر است.', 'mobile' );
			}
			$user_id = MNS_Admin_Orders::find_or_create_account_public( $mobile );
			$patient = MNS_Patients::ensure_self( $user_id );
			MNS_Services_Seed::ensure_slice1();
			$svc = MNS_Services::get( MNS_Services::default_active_slug() );
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
			$nurse = isset( $_POST['nurse_id'] ) ? (int) $_POST['nurse_id'] : 0;
			if ( $nurse > 0 && ! empty( $order['id'] ) ) {
				MNS_Assignment::admin_set_nurse( (int) $order['id'], $nurse, get_current_user_id(), true );
			}
			MNS_Admin_Ui::redirect(
				MNS_Admin_Menu::SLUG_MONITOR,
				'created',
				array(
					'action' => 'view',
					'id'     => (int) ( $order['id'] ?? 0 ),
				)
			);
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => $e->http() ) );
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'manual_order: ' . $e->getMessage() );
			wp_die( esc_html__( 'خطای داخلی.', 'medyar-nursing-services' ), '', array( 'response' => 500 ) );
		}
	}
}
