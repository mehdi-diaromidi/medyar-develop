<?php
/**
 * Front-end page renderers (used by page templates).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Pages {

	public const TEMPLATE_DASHBOARD = 'arp-dashboard.php';
	public const TEMPLATE_SIGNUP    = 'arp-signup.php';

	/**
	 * @return array<string,string> Relative template file => label.
	 */
	public static function templates(): array {
		return array(
			self::TEMPLATE_DASHBOARD => 'پنل طرح همکاران (مدیار)',
			self::TEMPLATE_SIGNUP    => 'ثبت‌نام طرح همکاران (مدیار)',
		);
	}

	public static function is_arp_template( ?string $template = null ): bool {
		if ( null === $template ) {
			if ( ! is_singular( 'page' ) ) {
				return false;
			}
			$template = (string) get_page_template_slug();
		}
		return isset( self::templates()[ $template ] );
	}

	public static function is_dashboard_template( ?string $template = null ): bool {
		if ( null === $template ) {
			$template = is_singular( 'page' ) ? (string) get_page_template_slug() : '';
		}
		return self::TEMPLATE_DASHBOARD === $template;
	}

	public static function is_signup_template( ?string $template = null ): bool {
		if ( null === $template ) {
			$template = is_singular( 'page' ) ? (string) get_page_template_slug() : '';
		}
		return self::TEMPLATE_SIGNUP === $template;
	}

	public static function render_signup(): void {
		if ( ! is_user_logged_in() ) {
			echo '<p>در حال انتقال به ورود…</p>';
			return;
		}

		$user = wp_get_current_user();

		if ( Arp_Roles::user_is_affiliate( $user->ID ) ) {
			include ARP_PLUGIN_DIR . 'public/views/signup-already.php';
			return;
		}
		if ( Arp_Affiliate_Applications::is_pending( (int) $user->ID ) ) {
			include ARP_PLUGIN_DIR . 'public/views/signup-pending.php';
			return;
		}
		if ( Arp_Affiliate_Applications::is_rejected( (int) $user->ID ) ) {
			include ARP_PLUGIN_DIR . 'public/views/signup-rejected.php';
			// Allow re-application via the same form below.
		}

		self::signup_form( $user );
	}

	/**
	 * Onboarding form; submit queues the application for admin review.
	 */
	private static function signup_form( WP_User $user ): void {
		$first = (string) get_user_meta( $user->ID, 'first_name', true );
		$last  = (string) get_user_meta( $user->ID, 'last_name', true );
		$name  = trim( $first . ' ' . $last );
		// OTP signups get the login or a masked mobile ("0912***4567") as display name; only prefill real names.
		if ( '' === $name && $user->display_name !== $user->user_login && preg_match( '/\p{L}/u', $user->display_name ) ) {
			$name = $user->display_name;
		}
		$mobile = (string) get_user_meta( $user->ID, 'mobile', true );
		if ( '' === $mobile ) {
			$mobile = (string) get_user_meta( $user->ID, 'billing_phone', true );
		}
		$job_types      = Arp_Job_Types_Repo::all( true );
		$msg            = isset( $_GET['arp_msg'] ) ? sanitize_key( $_GET['arp_msg'] ) : ''; // phpcs:ignore
		$inviter_code   = Arp_Inviter::cookie_code();
		$inviter_locked = '' !== $inviter_code;
		$job_type_id    = 0;

		$old = Arp_Public::pull_old_signup_input( (int) $user->ID );
		if ( $old ) {
			if ( isset( $old['full_name'] ) && is_string( $old['full_name'] ) && '' !== $old['full_name'] ) {
				$name = $old['full_name'];
			}
			if ( isset( $old['mobile'] ) && is_string( $old['mobile'] ) && '' !== $old['mobile'] ) {
				$mobile = $old['mobile'];
			}
			if ( isset( $old['job_type_id'] ) ) {
				$job_type_id = absint( $old['job_type_id'] );
			}
			if ( ! $inviter_locked && isset( $old['inviter_code'] ) && is_string( $old['inviter_code'] ) ) {
				$inviter_code = $old['inviter_code'];
			}
		}

		include ARP_PLUGIN_DIR . 'public/views/signup.php';
	}

	public static function render_dashboard(): void {
		if ( ! is_user_logged_in() ) {
			$url = Arp_Affiliate_Access::login_url( self::current_url() );
			echo '<p><a href="' . esc_url( $url ) . '">برای مشاهده پنل طرح همکاران وارد شوید.</a></p>';
			return;
		}
		$user_id = get_current_user_id();
		if ( ! Arp_Roles::user_is_affiliate( $user_id ) ) {
			if ( Arp_Affiliate_Applications::is_pending( $user_id ) ) {
				include ARP_PLUGIN_DIR . 'public/views/signup-pending.php';
				return;
			}
			if ( Arp_Affiliate_Applications::is_rejected( $user_id ) ) {
				include ARP_PLUGIN_DIR . 'public/views/signup-rejected.php';
			}
			self::signup_form( wp_get_current_user() );
			return;
		}

		$page    = isset( $_GET['arp_page'] ) ? sanitize_key( $_GET['arp_page'] ) : 'dashboard'; // phpcs:ignore
		$allowed = array( 'dashboard', 'submit', 'referrals', 'commissions', 'profile', 'invited' );
		if ( ! in_array( $page, $allowed, true ) ) {
			$page = 'dashboard';
		}
		Arp_Inviter::ensure_code( $user_id );

		$user = wp_get_current_user();
		Arp_Roles::ensure_affiliate_meta( (int) $user->ID );
		$msg = isset( $_GET['arp_msg'] ) ? sanitize_key( $_GET['arp_msg'] ) : ''; // phpcs:ignore

		include ARP_PLUGIN_DIR . 'public/views/dashboard/layout.php';
	}

	public static function current_url(): string {
		return ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' );
	}

	/**
	 * Clean panel URL (no leftover filters/messages from the current request).
	 *
	 * @param array<string,scalar> $args Extra query args.
	 */
	public static function page_url( string $page, array $args = array() ): string {
		if ( 'invited' === $page && self::$invited_in_account ) {
			return add_query_arg( $args, Arp_MyAccount_Invited::url() );
		}
		return add_query_arg( array_merge( array( 'arp_page' => $page ), $args ), self::dashboard_url() );
	}

	/** @var bool Invited-colleagues view is being rendered inside Woo My Account. */
	private static $invited_in_account = false;

	public static function set_invited_in_account( bool $on ): void {
		self::$invited_in_account = $on;
	}

	public static function invited_in_account(): bool {
		return self::$invited_in_account;
	}

	/** Whether a published partner panel page exists (template or slug `partner`). */
	public static function has_panel_page(): bool {
		if ( self::template_page_id( self::TEMPLATE_DASHBOARD ) > 0 ) {
			return true;
		}
		$by_slug = get_page_by_path( 'partner' );
		return $by_slug instanceof WP_Post && 'publish' === $by_slug->post_status;
	}

	/**
	 * Permalink of the partner panel page.
	 * Looks up the dashboard template first, then a published page with slug "partner".
	 */
	public static function dashboard_url(): string {
		$id = self::template_page_id( self::TEMPLATE_DASHBOARD );
		if ( $id ) {
			return (string) get_permalink( $id );
		}

		$by_slug = get_page_by_path( 'partner' );
		if ( $by_slug instanceof WP_Post && 'publish' === $by_slug->post_status ) {
			return (string) get_permalink( $by_slug );
		}

		/**
		 * Fallback panel URL when no template/slug page is found.
		 *
		 * @param string $url Default /partner/.
		 */
		return (string) apply_filters( 'arp_dashboard_url', home_url( '/partner/' ) );
	}

	private static function template_page_id( string $template ): int {
		static $ids = array();
		if ( isset( $ids[ $template ] ) ) {
			return $ids[ $template ];
		}

		if ( did_action( 'wp' ) && is_singular( 'page' ) && get_page_template_slug() === $template ) {
			$ids[ $template ] = (int) get_queried_object_id();
			return $ids[ $template ];
		}

		$found = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'numberposts'            => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'             => $template, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$ids[ $template ] = $found ? (int) $found[0] : 0;
		return $ids[ $template ];
	}
}
