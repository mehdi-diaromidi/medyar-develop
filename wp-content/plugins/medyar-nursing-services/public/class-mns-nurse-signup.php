<?php
/**
 * Nurse signup page + apply handler + auth role switch (T-1.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Signup {

	public static function boot(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_gate' ) );
		add_action( 'admin_post_mns_nurse_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_nopriv_mns_nurse_apply', array( __CLASS__, 'handle_apply_guest' ) );
		add_action( 'drplus/auth/form/start', array( __CLASS__, 'auth_role_switch' ) );
	}

	public static function maybe_gate(): void {
		if ( ! is_page() ) {
			return;
		}
		$tpl = get_page_template_slug();
		if ( 'mns-nurse-signup.php' !== $tpl && false === strpos( (string) $tpl, 'mns-nurse-signup' ) ) {
			// Also match by page slug /nursing-nurse-signup/
			global $post;
			if ( ! $post || 'nursing-nurse-signup' !== $post->post_name ) {
				return;
			}
		}
		MNS_Nurse_Access::gate_signup();
	}

	public static function handle_apply_guest(): void {
		wp_safe_redirect( MNS_Nurse_Access::login_url( home_url( '/nursing-nurse-signup/' ) ) );
		exit;
	}

	public static function handle_apply(): void {
		if ( ! is_user_logged_in() ) {
			self::handle_apply_guest();
		}
		check_admin_referer( 'mns_nurse_apply' );

		$user_id = get_current_user_id();
		$data    = array(
			'first_name'       => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
			'last_name'        => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
			'gender'           => isset( $_POST['gender'] ) ? sanitize_key( wp_unslash( $_POST['gender'] ) ) : '',
			'national_id'      => isset( $_POST['national_id'] ) ? sanitize_text_field( wp_unslash( $_POST['national_id'] ) ) : '',
			'license_no'       => isset( $_POST['license_no'] ) ? sanitize_text_field( wp_unslash( $_POST['license_no'] ) ) : '',
			'qualification'    => isset( $_POST['qualification'] ) ? sanitize_text_field( wp_unslash( $_POST['qualification'] ) ) : '',
			'experience'       => isset( $_POST['experience'] ) ? sanitize_text_field( wp_unslash( $_POST['experience'] ) ) : '',
			'workplace'        => isset( $_POST['workplace'] ) ? sanitize_text_field( wp_unslash( $_POST['workplace'] ) ) : '',
			'about'            => isset( $_POST['about'] ) ? sanitize_text_field( wp_unslash( $_POST['about'] ) ) : '',
			'accept_contract'  => ! empty( $_POST['accept_contract'] ) ? 1 : 0,
		);

		$files = array();
		foreach ( array( 'national_card', 'military_card', 'license_card', 'profile_photo' ) as $kind ) {
			if ( ! empty( $_FILES[ $kind ] ) && is_array( $_FILES[ $kind ] ) ) {
				$files[ $kind ] = $_FILES[ $kind ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in MNS_Files.
			}
		}

		try {
			MNS_Nurse_Applications::apply( $user_id, $data, $files );
			wp_safe_redirect( add_query_arg( 'mns_state', 'pending', home_url( '/nursing-nurse-signup/' ) ) );
			exit;
		} catch ( MNS_Error $e ) {
			$args = array(
				'mns_err'   => $e->error_code(),
				'mns_field' => $e->field() ? $e->field() : '',
			);
			// Keep posted scalars in transient briefly.
			set_transient( 'mns_nurse_apply_form_' . $user_id, $data, 300 );
			wp_safe_redirect( add_query_arg( $args, home_url( '/nursing-nurse-signup/' ) ) );
			exit;
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'nurse_apply_failed', array( 'err' => $e->getMessage() ) );
			wp_safe_redirect( add_query_arg( 'mns_err', 'ERR_SERVER', home_url( '/nursing-nurse-signup/' ) ) );
			exit;
		}
	}

	/**
	 * Auth role switch on Doctor Plus login form.
	 */
	public static function auth_role_switch(): void {
		if ( empty( $_GET['login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$patient = home_url( '/nursing-patient-panel/' );
		$nurse   = home_url( '/nursing-nurse-signup/' );
		echo '<div class="mns-auth-switch" style="display:flex;gap:12px;margin:12px 0;justify-content:center;">';
		echo '<a class="mns-auth-switch__link" href="' . esc_url( add_query_arg( array( 'login' => '1', 'redirect_to' => $patient ), home_url( '/' ) ) ) . '">' . esc_html__( 'ورود کاربر', 'medyar-nursing-services' ) . '</a>';
		echo '<a class="mns-auth-switch__link" href="' . esc_url( add_query_arg( array( 'login' => '1', 'redirect_to' => $nurse ), home_url( '/' ) ) ) . '">' . esc_html__( 'ورود پرستار', 'medyar-nursing-services' ) . '</a>';
		echo '</div>';
	}
}
