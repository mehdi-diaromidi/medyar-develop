<?php
/**
 * Tools → بازنشانی MNS (T-0.6). Only DROP for MNS_DB::table() names.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Reset {

	public const PAGE = 'mns-reset';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_mns_reset', array( __CLASS__, 'handle' ) );
	}

	public static function menu(): void {
		add_management_page(
			__( 'بازنشانی MNS', 'medyar-nursing-services' ),
			__( 'بازنشانی MNS', 'medyar-nursing-services' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mns_reset' );

		$phrase = isset( $_POST['mns_reset_phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['mns_reset_phrase'] ) ) : '';
		if ( 'RESET MNS' !== $phrase ) {
			set_transient( 'mns_reset_flash_' . get_current_user_id(), array( 'ok' => false, 'detail' => 'bad_phrase' ), 60 );
			wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
			exit;
		}

		global $wpdb;
		foreach ( MNS_DB::TABLES as $short ) {
			$table = MNS_DB::table( $short );
			// Only names from MNS_DB::table().
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		delete_option( MNS_Migrations::OPTION_VERSION );
		delete_option( MNS_Migrations::OPTION_LOG );

		self::purge_private_files();

		$run = MNS_Migrations::run();
		set_transient(
			'mns_reset_flash_' . get_current_user_id(),
			array(
				'ok'     => ! empty( $run['ok'] ),
				'detail' => 'migrated_to=' . (int) $run['to'],
				'errors' => $run['errors'],
			),
			60
		);

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
		exit;
	}

	private static function purge_private_files(): void {
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return;
		}
		$dir = trailingslashit( $upload['basedir'] ) . 'mns-private';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		self::rrmdir( $dir );
	}

	private static function rrmdir( string $dir ): void {
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ) );
		}

		$flash = get_transient( 'mns_reset_flash_' . get_current_user_id() );
		if ( false !== $flash ) {
			delete_transient( 'mns_reset_flash_' . get_current_user_id() );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'بازنشانی MNS', 'medyar-nursing-services' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'فقط جدول‌های mns_ و پوشهٔ فایل خصوصی پاک و دوباره ساخته می‌شوند. بکاپ گرفته نمی‌شود.', 'medyar-nursing-services' ) . '</p>';

		if ( is_array( $flash ) ) {
			$class = ! empty( $flash['ok'] ) ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( (string) $flash['detail'] ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mns_reset' );
		echo '<input type="hidden" name="action" value="mns_reset" />';
		echo '<p><label>' . esc_html__( 'برای تأیید عبارت RESET MNS را تایپ کنید:', 'medyar-nursing-services' ) . '</label><br>';
		echo '<input type="text" name="mns_reset_phrase" class="regular-text" autocomplete="off" /></p>';
		submit_button( __( 'بازنشانی MNS', 'medyar-nursing-services' ), 'delete' );
		echo '</form></div>';
	}
}
