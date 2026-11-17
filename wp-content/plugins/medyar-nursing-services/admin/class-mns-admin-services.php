<?php
/**
 * Admin services catalog (T-3.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Services {

	public const SLUG = 'mns-services';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_post_mns_service_save', array( __CLASS__, 'handle_save' ) );
		MNS_Services_Seed::ensure_slice1();
	}

	public static function register_menu(): void {
		add_submenu_page(
			MNS_Admin_Menu::SLUG_DASHBOARD,
			__( 'خدمات', 'medyar-nursing-services' ),
			__( 'خدمات', 'medyar-nursing-services' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_save(): void {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				throw MNS_Error::forbidden();
			}
			check_admin_referer( 'mns_service_save' );
			$id = isset( $_POST['service_id'] ) ? (int) $_POST['service_id'] : 0;
			MNS_Services::update_admin(
				$id,
				array(
					'name'          => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
					'active'        => ! empty( $_POST['active'] ),
					'price_from'    => isset( $_POST['price_from'] ) ? (int) $_POST['price_from'] : 0,
					'price_to'      => isset( $_POST['price_to'] ) ? (int) $_POST['price_to'] : 0,
					'has_drug_step' => ! empty( $_POST['has_drug_step'] ),
					'config'        => isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '{}',
				)
			);
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'mns_saved' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		} catch ( MNS_Error $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => $e->http() ) );
		} catch ( \Throwable $e ) {
			MNS_Log::error( 'admin_service_save: ' . $e->getMessage() );
			wp_die( esc_html__( 'خطای داخلی.', 'medyar-nursing-services' ), '', array( 'response' => 500 ) );
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازه ندارید.', 'medyar-nursing-services' ) );
		}
		MNS_Services_Seed::ensure_slice1();
		$list = MNS_Services::list_all();
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit = $edit_id ? MNS_Services::get( $edit_id ) : null;
		MNS_Admin_Ui::header( __( 'خدمات', 'medyar-nursing-services' ) );
		if ( isset( $_GET['mns_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success inline is-dismissible"><p>' . esc_html__( 'ذخیره شد.', 'medyar-nursing-services' ) . '</p></div>';
		}
		echo '<table class="widefat striped mns-compact"><thead><tr><th>ID</th><th>' . esc_html__( 'نام', 'medyar-nursing-services' ) . '</th><th>slug</th><th>' . esc_html__( 'فعال', 'medyar-nursing-services' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $list as $row ) {
			$url = add_query_arg( array( 'page' => self::SLUG, 'edit' => $row['id'] ), admin_url( 'admin.php' ) );
			echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['slug'] ) . '</td><td>' . ( $row['active'] ? '✓' : '—' ) . '</td><td><a href="' . esc_url( $url ) . '">' . esc_html__( 'ویرایش', 'medyar-nursing-services' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
		if ( $edit ) {
			$cfg = wp_json_encode( $edit['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			echo '<h2>' . esc_html__( 'ویرایش', 'medyar-nursing-services' ) . ': ' . esc_html( $edit['name'] ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mns_service_save' );
			echo '<input type="hidden" name="action" value="mns_service_save" />';
			echo '<input type="hidden" name="service_id" value="' . (int) $edit['id'] . '" />';
			echo '<p><label>' . esc_html__( 'نام', 'medyar-nursing-services' ) . ' <input name="name" class="regular-text" value="' . esc_attr( $edit['name'] ) . '" /></label></p>';
			echo '<p><label><input type="checkbox" name="active" value="1" ' . checked( $edit['active'], true, false ) . ' /> ' . esc_html__( 'فعال', 'medyar-nursing-services' ) . '</label></p>';
			echo '<p><label>' . esc_html__( 'قیمت از', 'medyar-nursing-services' ) . ' <input name="price_from" type="number" value="' . (int) $edit['price_from'] . '" /></label></p>';
			echo '<p><label>' . esc_html__( 'قیمت تا', 'medyar-nursing-services' ) . ' <input name="price_to" type="number" value="' . (int) $edit['price_to'] . '" /></label></p>';
			echo '<p><label><input type="checkbox" name="has_drug_step" value="1" ' . checked( $edit['has_drug_step'], true, false ) . ' /> has_drug_step</label></p>';
			echo '<p><label>config JSON<br /><textarea name="config" rows="12" class="large-text code">' . esc_textarea( (string) $cfg ) . '</textarea></label></p>';
			submit_button( __( 'ذخیره', 'medyar-nursing-services' ) );
			echo '</form>';
		}
		MNS_Admin_Ui::footer();
	}
}
