<?php
/**
 * Nurse panel gating (R-NRS-3 / T-1.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Nurse_Access {

	/**
	 * @return array<string,mixed>|null Nurse row for current/user.
	 */
	public static function nurse_row( ?int $user_id = null ): ?array {
		$uid = $user_id ? $user_id : get_current_user_id();
		if ( $uid <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = MNS_DB::table( 'nurses' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $uid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Gate nurse panel pages. Returns status code for UI routing.
	 * approved|pending|rejected|suspended|none
	 */
	public static function status( ?int $user_id = null ): string {
		$row = self::nurse_row( $user_id );
		if ( ! $row ) {
			return 'none';
		}
		$st = (string) $row['status'];
		return in_array( $st, array( 'approved', 'pending', 'rejected', 'suspended' ), true ) ? $st : 'none';
	}

	/**
	 * Whether nurse may see operational panel data.
	 */
	public static function can_use_panel( ?int $user_id = null ): bool {
		return 'approved' === self::status( $user_id );
	}

	/**
	 * Login gate for signup page — redirect guests to site OTP login.
	 */
	public static function gate_signup(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		$url = self::login_url( self::current_url() );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Login gate for patient/nurse panel pages (T-D.2): guests must not see fixture content.
	 */
	public static function gate_panels(): void {
		if ( ! is_page() || is_user_logged_in() ) {
			return;
		}
		$tpl = (string) get_page_template_slug();
		$is_panel = false;
		if ( 'mns-patient.php' === $tpl || false !== strpos( $tpl, 'mns-patient' ) ) {
			$is_panel = true;
		} elseif ( ( 'mns-nurse.php' === $tpl || false !== strpos( $tpl, 'mns-nurse' ) ) && false === strpos( $tpl, 'signup' ) ) {
			$is_panel = true;
		} else {
			global $post;
			if ( $post && in_array( (string) $post->post_name, array( 'nursing-patient-panel', 'nursing-nurse-panel' ), true ) ) {
				$is_panel = true;
			}
		}
		if ( ! $is_panel ) {
			return;
		}
		wp_safe_redirect( self::login_url( self::current_url() ) );
		exit;
	}

	public static function login_url( string $redirect_to = '' ): string {
		$base = home_url( '/?login=1' );
		if ( '' !== $redirect_to ) {
			$base = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $base );
		}
		/**
		 * Filter MNS login URL.
		 *
		 * @param string $base Login URL.
		 */
		return (string) apply_filters( 'mns_login_url', $base, $redirect_to );
	}

	private static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return home_url( $uri );
	}
}
