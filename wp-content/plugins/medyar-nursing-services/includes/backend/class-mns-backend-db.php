<?php
/**
 * Backend module: database / migrations (T-0.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Backend_Db {

	/**
	 * Register admin-only migration hooks and status UI.
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_auto_migrate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		MNS_Admin_Db_Status::boot();
	}

	/**
	 * Auto-run pending migrations on admin requests (manage_options only).
	 * Never on frontend.
	 */
	public static function maybe_auto_migrate(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! MNS_Migrations::needs_run() ) {
			return;
		}

		// Avoid running during AJAX/heartbeat noise; still admin-only and safe.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		MNS_Migrations::run();
	}

	/**
	 * Warn admins when schema is behind or last migration failed.
	 */
	public static function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$url = admin_url( 'tools.php?page=mns-db-status' );

		if ( MNS_Migrations::needs_run() ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__(
				'پایگاه دادهٔ MNS نیاز به migration دارد.',
				'medyar-nursing-services'
			);
			echo ' <a href="' . esc_url( $url ) . '">';
			echo esc_html__( 'وضعیت دیتابیس MNS', 'medyar-nursing-services' );
			echo '</a></p></div>';
		}

		$log = MNS_Migrations::log();
		if ( empty( $log ) ) {
			return;
		}

		$last = $log[ count( $log ) - 1 ];
		if ( ! is_array( $last ) || ! empty( $last['ok'] ) ) {
			return;
		}

		$err = isset( $last['error'] ) ? (string) $last['error'] : '';
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'آخرین migration دیتابیس MNS ناموفق بود.', 'medyar-nursing-services' );
		if ( '' !== $err ) {
			echo ' ';
			echo esc_html( $err );
		}
		echo ' <a href="' . esc_url( $url ) . '">';
		echo esc_html__( 'جزئیات', 'medyar-nursing-services' );
		echo '</a></p></div>';
	}
}
