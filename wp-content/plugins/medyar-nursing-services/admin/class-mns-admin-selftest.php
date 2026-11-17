<?php
/**
 * Tools → MNS Self-test (T-0.6).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Selftest {

	public const PAGE = 'mns-selftest';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mns_selftest_run', array( __CLASS__, 'handle' ) );
	}

	public static function menu(): void {
		add_management_page(
			__( 'MNS Self-test', 'medyar-nursing-services' ),
			__( 'MNS Self-test', 'medyar-nursing-services' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mns_selftest_run' );
		// Supervisor fix: scenarios switch the current user; remember the admin so the report is stored under them.
		$admin_id = get_current_user_id();
		// Supervisor fix: a crashing scenario or invalid UTF-8 in a detail must never silently drop the whole report.
		try {
			$result = MNS_Selftest::run();
		} catch ( \Throwable $e ) {
			$result = array(
				'pass' => 0,
				'fail' => 1,
				'rows' => array(
					array(
						'id'     => 'RUNNER',
						'title'  => 'Self-test runner crashed',
						'ok'     => false,
						'detail' => get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(),
					),
				),
			);
		}
		if ( ! empty( $result['rows'] ) && is_array( $result['rows'] ) ) {
			foreach ( $result['rows'] as $i => $row ) {
				$detail = isset( $row['detail'] ) ? (string) $row['detail'] : '';
				$detail = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $detail, 'UTF-8', 'UTF-8' ) : $detail;
				$result['rows'][ $i ]['detail'] = mb_substr( (string) wp_check_invalid_utf8( $detail, true ), 0, 400 );
			}
		}
		wp_set_current_user( $admin_id );
		set_transient( 'mns_selftest_flash_' . $admin_id, $result, 300 );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ) );
		}

		$flash = get_transient( 'mns_selftest_flash_' . get_current_user_id() );
		if ( false !== $flash ) {
			delete_transient( 'mns_selftest_flash_' . get_current_user_id() );
		}

		$pass = is_array( $flash ) ? (int) $flash['pass'] : 0;
		$fail = is_array( $flash ) ? (int) $flash['fail'] : 0;

		MNS_Admin_Ui::header( __( 'MNS Self-test', 'medyar-nursing-services' ) );

		echo '<div id="mns-selftest-summary" data-pass="' . esc_attr( (string) $pass ) . '" data-fail="' . esc_attr( (string) $fail ) . '">';
		if ( is_array( $flash ) ) {
			echo esc_html( sprintf( 'PASS=%d FAIL=%d', $pass, $fail ) );
		} else {
			echo esc_html__( 'هنوز اجرا نشده', 'medyar-nursing-services' );
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mns_selftest_run' );
		echo '<input type="hidden" name="action" value="mns_selftest_run" />';
		submit_button( __( 'اجرای همه', 'medyar-nursing-services' ) );
		echo '</form>';

		if ( is_array( $flash ) && ! empty( $flash['rows'] ) ) {
			echo '<table class="widefat striped"><thead><tr><th>AC</th><th>عنوان</th><th>نتیجه</th><th>جزئیات</th></tr></thead><tbody>';
			foreach ( $flash['rows'] as $row ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $row['id'] ) . '</code></td>';
				echo '<td>' . esc_html( (string) $row['title'] ) . '</td>';
				echo '<td>' . esc_html( ! empty( $row['ok'] ) ? 'PASS' : 'FAIL' ) . '</td>';
				echo '<td>' . esc_html( (string) $row['detail'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		MNS_Admin_Ui::footer();
	}
}
