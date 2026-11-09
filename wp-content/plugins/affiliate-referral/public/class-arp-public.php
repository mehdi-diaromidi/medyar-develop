<?php
/**
 * Front-end assets and form handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Public {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_global' ), 999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_theme_datepicker' ), 1000 );
		add_action( 'init', array( $this, 'handle_post' ) );
	}

	/**
	 * The theme loads its babakhani picker site-wide; it redefines the global `persianDate`
	 * that Persian WooCommerce's picker depends on, so the panel pages must not load both.
	 */
	public function dequeue_theme_datepicker(): void {
		if ( ! Arp_Pages::is_arp_template() || ! wp_script_is( 'pw-datepicker-js', 'enqueued' ) ) {
			return;
		}
		wp_dequeue_script( 'drplus-pdp' );
		wp_dequeue_script( 'drplus-pd' );
		wp_dequeue_style( 'drplus-pdp' );
	}

	/**
	 * Site-wide front-end tweaks (all pages).
	 */
	public function enqueue_global(): void {
		$handle = 'arp-global';
		wp_register_style( $handle, false, array(), ARP_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style(
			$handle,
			'html,body,a,button,input,textarea,select,label,summary,*[role="button"]{-webkit-tap-highlight-color:transparent;}'
			. 'a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible,'
			. '.input-wrap:has(input:focus-visible),.select2-selection--single:focus-visible,'
			. '.checkbox-wrap:focus-within,.radio-wrap:focus-within,.outline:focus{'
			. 'outline:none!important;outline-offset:0!important;box-shadow:none!important;}'
		);
	}

	public function maybe_enqueue(): void {
		if ( ! Arp_Pages::is_arp_template() ) {
			return;
		}

		$css_path = ARP_PLUGIN_DIR . 'assets/css/public.css';
		$js_path  = ARP_PLUGIN_DIR . 'assets/js/public.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : ARP_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : ARP_VERSION;

		wp_enqueue_style(
			'arp-public',
			ARP_PLUGIN_URL . 'assets/css/public.css',
			array(),
			$css_ver
		);
		$deps = array( 'jquery' );

		// Persian WooCommerce's Jalali datepicker (themed in public.css); without it date fields accept typed input.
		if ( function_exists( 'PW' ) && defined( 'PW_VERSION' ) ) {
			wp_enqueue_script( 'pw-datepicker-js', PW()->plugin_url( 'assets/js/persian-datepicker.min.js' ), array( 'jquery' ), PW_VERSION, true );
			$deps[] = 'pw-datepicker-js';
		}

		if ( Arp_Pages::is_dashboard_template() ) {
			wp_enqueue_script(
				'chartjs',
				'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
				array(),
				'4.4.1',
				true
			);
			$deps[] = 'chartjs';
		}
		wp_enqueue_script( 'arp-public', ARP_PLUGIN_URL . 'assets/js/public.js', $deps, $js_ver, true );

		if ( is_user_logged_in() ) {
			wp_add_inline_script(
				'arp-public',
				'window.arpPanel = ' . wp_json_encode(
					array(
						'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
						'listNonce' => wp_create_nonce( Arp_Ajax::LIST_NONCE ),
						'loginUrl'  => Arp_Affiliate_Access::login_url( Arp_Pages::dashboard_url() ),
						'i18n'      => array(
							'network'   => 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.',
							'generic'   => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.',
							'saving'    => 'در حال ارسال…',
							'expired'   => 'نشست شما به پایان رسیده است. لطفاً دوباره وارد شوید.',
							'forbidden' => 'اعتبار صفحه به پایان رسیده است. لطفاً صفحه را دوباره بارگذاری کنید.',
						),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * No-JS fallback for the panel forms (the AJAX endpoints live in Arp_Ajax).
	 */
	public function handle_post(): void {
		if ( wp_doing_ajax() || empty( $_POST['arp_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['arp_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'signup' === $action ) {
			$this->handle_signup();
		} elseif ( 'submit_referral' === $action ) {
			$this->handle_submit_referral();
		} elseif ( 'save_profile' === $action ) {
			$this->handle_save_profile();
		}
	}

	private static function nonce_ok( string $action ): bool {
		return isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $action );
	}

	private function handle_signup(): void {
		if ( ! is_user_logged_in() || ! self::nonce_ok( 'arp_signup' ) ) {
			return;
		}
		$uid    = get_current_user_id();
		$back   = wp_get_referer() ?: Arp_Pages::dashboard_url();
		$back   = remove_query_arg( array( 'arp_msg', 'arp_err', 'arp_field' ), $back );
		$result = Arp_Panel_Actions::signup( wp_get_current_user(), $_POST, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above; sanitized in the action.
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( in_array( $code, array( 'already', 'pending' ), true ) ) {
				delete_transient( self::old_signup_input_key( $uid ) );
				wp_safe_redirect( add_query_arg( 'arp_msg', $code, Arp_Pages::dashboard_url() ) );
				exit;
			}
			$data = (array) $result->get_error_data();
			set_transient(
				self::old_signup_input_key( $uid ),
				array(
					'full_name'    => isset( $_POST['full_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['full_name'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'mobile'       => isset( $_POST['mobile'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mobile'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'job_type_id'  => isset( $_POST['job_type_id'] ) ? absint( $_POST['job_type_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
					'inviter_code' => isset( $_POST['inviter_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['inviter_code'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				),
				5 * MINUTE_IN_SECONDS
			);
			$args = array( 'arp_err' => $code );
			if ( ! empty( $data['field'] ) && is_string( $data['field'] ) ) {
				$args['arp_field'] = sanitize_key( $data['field'] );
			}
			wp_safe_redirect( add_query_arg( $args, $back ) );
			exit;
		}
		delete_transient( self::old_signup_input_key( $uid ) );
		wp_safe_redirect( add_query_arg( 'arp_msg', 'registered', Arp_Pages::dashboard_url() ) );
		exit;
	}

	public static function old_signup_input_key( int $user_id ): string {
		return 'arp_signup_old_' . $user_id;
	}

	/**
	 * One-shot retrieval of the last rejected signup form values.
	 *
	 * @return array<string,mixed>
	 */
	public static function pull_old_signup_input( int $user_id ): array {
		$key = self::old_signup_input_key( $user_id );
		$old = get_transient( $key );
		if ( false !== $old ) {
			delete_transient( $key );
		}
		return is_array( $old ) ? $old : array();
	}

	private function handle_submit_referral(): void {
		if ( ! is_user_logged_in() || ! current_user_can( Arp_Roles::CAP_SUBMIT ) || ! Arp_Roles::user_is_affiliate() || ! self::nonce_ok( 'arp_submit_referral' ) ) {
			return;
		}
		$uid    = get_current_user_id();
		$result = Arp_Panel_Actions::submit_referral( $uid, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above; sanitized in the action.
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			set_transient( self::old_input_key( $uid ), $data['old'] ?? array(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( Arp_Pages::page_url( 'submit', array( 'arp_err' => $result->get_error_code() ) ) );
			exit;
		}
		delete_transient( self::old_input_key( $uid ) );
		wp_safe_redirect( Arp_Pages::page_url( 'referrals', array( 'arp_msg' => 'referral_ok' ) ) );
		exit;
	}

	public static function old_input_key( int $user_id ): string {
		return 'arp_referral_old_' . $user_id;
	}

	/**
	 * One-shot retrieval of the last rejected referral form values.
	 *
	 * @return array<string,mixed>
	 */
	public static function pull_old_referral_input( int $user_id ): array {
		$key = self::old_input_key( $user_id );
		$old = get_transient( $key );
		if ( false !== $old ) {
			delete_transient( $key );
		}
		return is_array( $old ) ? $old : array();
	}

	private function handle_save_profile(): void {
		if ( ! is_user_logged_in() || ! Arp_Roles::user_is_affiliate() || ! self::nonce_ok( 'arp_save_profile' ) ) {
			return;
		}
		$result = Arp_Panel_Actions::save_profile( get_current_user_id(), $_POST, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above; sanitized in the action.
		$args   = is_wp_error( $result ) ? array( 'arp_err' => $result->get_error_code() ) : array( 'arp_msg' => 'profile_ok' );
		wp_safe_redirect( Arp_Pages::page_url( 'profile', $args ) );
		exit;
	}
}
