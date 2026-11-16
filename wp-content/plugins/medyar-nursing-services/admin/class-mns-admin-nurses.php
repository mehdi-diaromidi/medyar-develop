<?php
/**
 * Admin: nurse applications (T-1.3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Nurses {

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'replace_menu' ), 999 );
		add_action( 'admin_post_mns_nurse_approve', array( __CLASS__, 'handle_approve' ) );
		add_action( 'admin_post_mns_nurse_reject', array( __CLASS__, 'handle_reject' ) );
		add_action( 'admin_post_mns_nurse_suspend', array( __CLASS__, 'handle_suspend' ) );
		add_action( 'admin_post_mns_nurse_reinstate', array( __CLASS__, 'handle_reinstate' ) );
		add_action( 'admin_post_mns_nurse_services', array( __CLASS__, 'handle_services' ) );
		add_action( 'admin_post_mns_nurse_photo_approve', array( __CLASS__, 'handle_photo' ) );
	}

	public static function replace_menu(): void {
		remove_submenu_page( MNS_Admin_Menu::SLUG_DASHBOARD, MNS_Admin_Menu::SLUG_NURSES );
		// Supervisor fix: also drop the old skeleton page callback, or the page renders twice.
		remove_all_actions( get_plugin_page_hookname( MNS_Admin_Menu::SLUG_NURSES, MNS_Admin_Menu::SLUG_DASHBOARD ) );
		add_submenu_page(
			MNS_Admin_Menu::SLUG_DASHBOARD,
			__( 'عضویت پرستاران', 'medyar-nursing-services' ),
			__( 'عضویت پرستاران', 'medyar-nursing-services' ),
			MNS_Roles::CAP_MANAGE_NURSES,
			MNS_Admin_Menu::SLUG_NURSES,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Run a moderation action; a wrong-state / validation error shows a clean message, never a white screen.
	 */
	private static function run( callable $fn ): void {
		try {
			$fn();
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 409, 'back_link' => true ) );
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'admin_nurse_action_failed', array( 'err' => $e->getMessage() ) );
			wp_die( esc_html__( 'عملیات ناموفق بود.', 'medyar-nursing-services' ), '', array( 'response' => 500, 'back_link' => true ) );
		}
	}

	private static function guard(): void {
		if ( ! current_user_can( MNS_Roles::CAP_MANAGE_NURSES ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
	}

	public static function handle_approve(): void {
		self::guard();
		check_admin_referer( 'mns_nurse_mod' );
		$uid = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		self::run(
			static function () use ( $uid ) {
				MNS_Nurse_Applications::approve( $uid, get_current_user_id() );
			}
		);
		wp_safe_redirect( admin_url( 'admin.php?page=mns-nurses&mns_nurse=' . $uid ) );
		exit;
	}

	public static function handle_reject(): void {
		self::guard();
		check_admin_referer( 'mns_nurse_mod' );
		$uid  = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$note = isset( $_POST['reject_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reject_note'] ) ) : '';
		self::run(
			static function () use ( $uid, $note ) {
				MNS_Nurse_Applications::reject( $uid, get_current_user_id(), $note );
			}
		);
		wp_safe_redirect( admin_url( 'admin.php?page=mns-nurses&mns_nurse=' . $uid ) );
		exit;
	}

	public static function handle_suspend(): void {
		self::guard();
		check_admin_referer( 'mns_nurse_mod' );
		$uid = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		self::run(
			static function () use ( $uid ) {
				MNS_Nurse_Applications::suspend( $uid, get_current_user_id() );
			}
		);
		wp_safe_redirect( admin_url( 'admin.php?page=mns-nurses&mns_nurse=' . $uid ) );
		exit;
	}

	public static function handle_reinstate(): void {
		self::guard();
		check_admin_referer( 'mns_nurse_mod' );
		$uid = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		self::run(
			static function () use ( $uid ) {
				MNS_Nurse_Applications::reinstate( $uid, get_current_user_id() );
			}
		);
		wp_safe_redirect( admin_url( 'admin.php?page=mns-nurses&mns_nurse=' . $uid ) );
		exit;
	}

	public static function handle_services(): void {
		self::guard();
		check_admin_referer( 'mns_nurse_mod' );
		$uid = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$ids = isset( $_POST['service_ids'] ) && is_array( $_POST['service_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['service_ids'] ) ) : array();
		global $wpdb;
		$wpdb->delete( MNS_DB::table( 'nurse_services' ), array( 'nurse_id' => $uid ), array( '%d' ) );
		foreach ( $ids as $sid ) {
			if ( $sid > 0 ) {
				$wpdb->replace(
					MNS_DB::table( 'nurse_services' ),
					array(
						'nurse_id'   => $uid,
						'service_id' => $sid,
					),
					array( '%d', '%d' )
				);
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mns-nurses&mns_nurse=' . $uid ) );
		exit;
	}

	public static function handle_photo(): void {
		self::guard();
		check_admin_referer( 'mns_nurse_mod' );
		$uid = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		global $wpdb;
		$wpdb->update(
			MNS_DB::table( 'nurses' ),
			array( 'photo_approved' => 1 ),
			array( 'user_id' => $uid ),
			array( '%d' ),
			array( '%d' )
		);
		wp_safe_redirect( admin_url( 'admin.php?page=mns-nurses&mns_nurse=' . $uid ) );
		exit;
	}

	public static function render(): void {
		self::guard();
		if ( ! MNS_Migrations::tables_ready() ) {
			echo '<div class="wrap"><p>' . esc_html__( 'ابتدا migration دیتابیس را اجرا کنید.', 'medyar-nursing-services' ) . '</p></div>';
			return;
		}
		MNS_Services_Seed::ensure_slice1();

		$detail = isset( $_GET['mns_nurse'] ) ? absint( $_GET['mns_nurse'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $detail > 0 ) {
			self::render_detail( $detail );
			return;
		}

		$table = new MNS_Nurses_Table();
		$table->prepare_items();
		echo '<div class="wrap"><h1>' . esc_html__( 'عضویت پرستاران', 'medyar-nursing-services' ) . '</h1>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="mns-nurses" />';
		$status = isset( $_GET['mns_status'] ) ? sanitize_key( wp_unslash( $_GET['mns_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="mns_status"><option value="">' . esc_html__( 'همه وضعیت‌ها', 'medyar-nursing-services' ) . '</option>';
		foreach ( array( 'pending', 'approved', 'rejected', 'suspended' ) as $st ) {
			echo '<option value="' . esc_attr( $st ) . '" ' . selected( $status, $st, false ) . '>' . esc_html( $st ) . '</option>';
		}
		echo '</select> ';
		$table->search_box( __( 'جستجو', 'medyar-nursing-services' ), 'mns-nurse' );
		$table->display();
		echo '</form></div>';
	}

	private static function render_detail( int $user_id ): void {
		$row = MNS_Nurse_Access::nurse_row( $user_id );
		if ( ! $row ) {
			echo '<div class="wrap"><p>' . esc_html__( 'یافت نشد.', 'medyar-nursing-services' ) . '</p></div>';
			return;
		}
		$user = get_userdata( $user_id );
		$meta = array();
		if ( ! empty( $row['meta'] ) ) {
			$decoded = json_decode( (string) $row['meta'], true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}
		$files = isset( $meta['files'] ) && is_array( $meta['files'] ) ? $meta['files'] : array();

		global $wpdb;
		$all_services = $wpdb->get_results( 'SELECT id, name FROM ' . MNS_DB::table( 'services' ) . ' WHERE active = 1 ORDER BY sort', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$mine = $wpdb->get_col( $wpdb->prepare( 'SELECT service_id FROM ' . MNS_DB::table( 'nurse_services' ) . ' WHERE nurse_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$mine = array_map( 'intval', is_array( $mine ) ? $mine : array() );

		echo '<div class="wrap"><h1>' . esc_html__( 'جزئیات درخواست پرستار', 'medyar-nursing-services' ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mns-nurses' ) ) . '">&larr; ' . esc_html__( 'بازگشت', 'medyar-nursing-services' ) . '</a></p>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'نام', 'medyar-nursing-services' ) . '</th><td>' . esc_html( $user ? $user->display_name : '' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'وضعیت', 'medyar-nursing-services' ) . '</th><td>' . esc_html( (string) $row['status'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'جنسیت', 'medyar-nursing-services' ) . '</th><td>' . esc_html( (string) $row['gender'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'کد ملی', 'medyar-nursing-services' ) . '</th><td>' . esc_html( (string) $row['national_id'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'نظام', 'medyar-nursing-services' ) . '</th><td>' . esc_html( (string) $row['license_no'] ) . '</td></tr>';
		if ( ! empty( $row['reject_note'] ) ) {
			echo '<tr><th>' . esc_html__( 'دلیل رد', 'medyar-nursing-services' ) . '</th><td>' . esc_html( (string) $row['reject_note'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'مدارک', 'medyar-nursing-services' ) . '</h2><ul>';
		foreach ( $files as $kind => $fid ) {
			$url = rest_url( MNS_REST::NS . '/files/' . (int) $fid );
			echo '<li>' . esc_html( (string) $kind ) . ' — <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'مشاهده', 'medyar-nursing-services' ) . '</a></li>';
		}
		echo '</ul>';

		echo '<h2>' . esc_html__( 'اقدامات', 'medyar-nursing-services' ) . '</h2>';
		self::action_form( 'mns_nurse_approve', $user_id, __( 'تأیید', 'medyar-nursing-services' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mns_nurse_mod' );
		echo '<input type="hidden" name="action" value="mns_nurse_reject" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '" />';
		echo '<p><textarea name="reject_note" required rows="2" class="large-text" placeholder="' . esc_attr__( 'دلیل رد', 'medyar-nursing-services' ) . '"></textarea></p>';
		submit_button( __( 'رد', 'medyar-nursing-services' ), 'delete', 'submit', false );
		echo '</form>';
		self::action_form( 'mns_nurse_suspend', $user_id, __( 'تعلیق', 'medyar-nursing-services' ) );
		self::action_form( 'mns_nurse_reinstate', $user_id, __( 'بازگردانی', 'medyar-nursing-services' ) );
		self::action_form( 'mns_nurse_photo_approve', $user_id, __( 'تأیید عکس پروفایل', 'medyar-nursing-services' ) );

		echo '<h2>' . esc_html__( 'خدمات مجاز', 'medyar-nursing-services' ) . '</h2>';
		if ( empty( $all_services ) ) {
			echo '<p>' . esc_html__( 'هنوز خدمتی تعریف نشده', 'medyar-nursing-services' ) . '</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mns_nurse_mod' );
			echo '<input type="hidden" name="action" value="mns_nurse_services" />';
			echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '" />';
			foreach ( $all_services as $svc ) {
				$sid = (int) $svc['id'];
				echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="service_ids[]" value="' . esc_attr( (string) $sid ) . '" ' . checked( in_array( $sid, $mine, true ), true, false ) . ' /> ' . esc_html( (string) $svc['name'] ) . '</label>';
			}
			submit_button( __( 'ذخیره خدمات', 'medyar-nursing-services' ) );
			echo '</form>';
		}
		echo '</div>';
	}

	private static function action_form( string $action, int $user_id, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:4px;">';
		wp_nonce_field( 'mns_nurse_mod' );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '" />';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form> ';
	}
}
