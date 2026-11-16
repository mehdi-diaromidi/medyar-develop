<?php
/**
 * Tools → MNS E2E sandbox (T-D.1). Only registered on develop.* hosts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_E2E {

	public const PAGE = 'mns-e2e';

	public static function boot(): void {
		if ( ! MNS_E2E::host_allowed() ) {
			return;
		}
		MNS_E2E::boot_hooks();
		MNS_E2E::schedule_cron();
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mns_e2e_enable', array( __CLASS__, 'handle_enable' ) );
		add_action( 'admin_post_mns_e2e_seed', array( __CLASS__, 'handle_seed' ) );
		add_action( 'admin_post_mns_e2e_links', array( __CLASS__, 'handle_links' ) );
		add_action( 'admin_post_mns_e2e_purge', array( __CLASS__, 'handle_purge' ) );
		add_action( 'admin_post_mns_e2e_seed_assigned', array( __CLASS__, 'handle_seed_assigned' ) );
	}

	public static function menu(): void {
		add_management_page(
			'MNS E2E',
			'MNS E2E',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function handle_enable(): void {
		self::guard();
		check_admin_referer( 'mns_e2e_enable' );
		MNS_E2E::enable_two_hours();
		self::redirect( 'enabled' );
	}

	public static function handle_seed(): void {
		self::guard();
		check_admin_referer( 'mns_e2e_seed' );
		if ( ! MNS_E2E::is_enabled() ) {
			self::redirect( 'disabled' );
		}
		try {
			$result = MNS_E2E::seed();
			set_transient( 'mns_e2e_flash_' . get_current_user_id(), $result, 600 );
		} catch ( MNS_Error $e ) {
			set_transient(
				'mns_e2e_flash_' . get_current_user_id(),
				array( 'error' => $e->getMessage() ),
				600
			);
		} catch ( \Throwable $e ) {
			set_transient(
				'mns_e2e_flash_' . get_current_user_id(),
				array( 'error' => $e->getMessage() ),
				600
			);
		}
		self::redirect( 'seeded' );
	}

	public static function handle_links(): void {
		self::guard();
		check_admin_referer( 'mns_e2e_links' );
		if ( ! MNS_E2E::is_enabled() ) {
			self::redirect( 'disabled' );
		}
		$users = MNS_E2E::mint_links_for_existing();
		set_transient(
			'mns_e2e_flash_' . get_current_user_id(),
			array(
				'users'         => $users,
				'enabled_until' => (int) get_option( MNS_E2E::OPT_UNTIL, 0 ),
			),
			600
		);
		self::redirect( 'links' );
	}

	public static function handle_purge(): void {
		self::guard();
		check_admin_referer( 'mns_e2e_purge' );
		MNS_E2E::purge_all();
		delete_transient( 'mns_e2e_flash_' . get_current_user_id() );
		self::redirect( 'purged' );
	}

	public static function handle_seed_assigned(): void {
		self::guard();
		check_admin_referer( 'mns_e2e_seed_assigned' );
		if ( ! MNS_E2E::is_enabled() ) {
			self::redirect( 'disabled' );
		}
		try {
			$result = MNS_E2E::seed_assigned_orders();
			set_transient( 'mns_e2e_flash_' . get_current_user_id(), $result, 600 );
		} catch ( MNS_Error $e ) {
			set_transient(
				'mns_e2e_flash_' . get_current_user_id(),
				array( 'error' => $e->getMessage() ),
				600
			);
		} catch ( \Throwable $e ) {
			set_transient(
				'mns_e2e_flash_' . get_current_user_id(),
				array( 'error' => $e->getMessage() ),
				600
			);
		}
		self::redirect( 'seed_assigned' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ) );
		}
		$until   = (int) get_option( MNS_E2E::OPT_UNTIL, 0 );
		$enabled = MNS_E2E::is_enabled();
		$flash   = get_transient( 'mns_e2e_flash_' . get_current_user_id() );
		if ( false !== $flash ) {
			delete_transient( 'mns_e2e_flash_' . get_current_user_id() );
		}

		echo '<div class="wrap"><h1>MNS E2E sandbox</h1>';
		echo '<p>' . esc_html__( 'فقط میزبان develop · پیش‌فرض خاموش · لینک ورود یکبارمصرف ۳۰ دقیقه‌ای.', 'medyar-nursing-services' ) . '</p>';

		if ( $enabled ) {
			echo '<div class="notice notice-success"><p>فعال تا: <code>' . esc_html( gmdate( 'Y-m-d H:i:s', $until ) ) . ' UTC</code></p></div>';
		} else {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'غیرفعال — ابتدا فعال‌سازی ۲ ساعته را بزنید.', 'medyar-nursing-services' ) . '</p></div>';
		}

		if ( is_array( $flash ) && ! empty( $flash['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) $flash['error'] ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
		wp_nonce_field( 'mns_e2e_enable' );
		echo '<input type="hidden" name="action" value="mns_e2e_enable" />';
		submit_button( 'فعال‌سازی ۲ ساعته', 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
		wp_nonce_field( 'mns_e2e_seed' );
		echo '<input type="hidden" name="action" value="mns_e2e_seed" />';
		submit_button( 'ساخت دادهٔ E2E', $enabled ? 'primary' : 'secondary', 'submit', false, $enabled ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
		wp_nonce_field( 'mns_e2e_links' );
		echo '<input type="hidden" name="action" value="mns_e2e_links" />';
		submit_button( 'لینک‌های تازه', 'secondary', 'submit', false, $enabled ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
		wp_nonce_field( 'mns_e2e_seed_assigned' );
		echo '<input type="hidden" name="action" value="mns_e2e_seed_assigned" />';
		submit_button( 'ساخت سفارش نمونهٔ تخصیص‌یافته', $enabled ? 'primary' : 'secondary', 'submit', false, $enabled ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0;" onsubmit="return confirm(\'پاک‌سازی همهٔ mns_e2e_* ؟\');">';
		wp_nonce_field( 'mns_e2e_purge' );
		echo '<input type="hidden" name="action" value="mns_e2e_purge" />';
		submit_button( 'پاک‌سازی E2E', 'delete', 'submit', false );
		echo '</form>';

		$users = ( is_array( $flash ) && ! empty( $flash['users'] ) && is_array( $flash['users'] ) ) ? $flash['users'] : array();
		if ( ! empty( $users ) ) {
			echo '<h2>' . esc_html__( 'لینک‌های ورود یکبارمصرف', 'medyar-nursing-services' ) . '</h2>';
			echo '<table class="widefat striped" id="mns-e2e-links"><thead><tr><th>کلید</th><th>کاربر</th><th>نقش</th><th>لینک ورود</th></tr></thead><tbody>';
			foreach ( $users as $key => $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $key ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) $row['login'] ) . '</code> (#' . esc_html( (string) $row['user_id'] ) . ')</td>';
				echo '<td>' . esc_html( (string) $row['role'] ) . '</td>';
				echo '<td><input type="text" class="large-text" readonly value="' . esc_attr( (string) $row['login_url'] ) . '" onclick="this.select();" /></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">آمادهٔ ساخت لینک توسط ناظر — هر لینک یکبارمصرف است (۳۰ دقیقه).</p>';
		}

		$orders = ( is_array( $flash ) && ! empty( $flash['orders'] ) && is_array( $flash['orders'] ) ) ? $flash['orders'] : array();
		if ( ! empty( $orders ) ) {
			echo '<h2>' . esc_html__( 'سفارش‌های نمونهٔ تخصیص‌یافته', 'medyar-nursing-services' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th>کد</th><th>پرستار</th><th>منطقه</th><th>وضعیت</th></tr></thead><tbody>';
			foreach ( $orders as $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $row['code'] ?? '' ) ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) ( $row['nurse'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $row['district'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private static function guard(): void {
		if ( ! current_user_can( 'manage_options' ) || ! MNS_E2E::host_allowed() ) {
			wp_die( esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
	}

	private static function redirect( string $flag ): void {
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE . '&mns_e2e=' . rawurlencode( $flag ) ) );
		exit;
	}
}
