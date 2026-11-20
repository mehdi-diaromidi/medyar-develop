<?php
/**
 * Admin services catalog (T-3.1 / RUN-G).
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
					'name'                 => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
					'category_label'       => isset( $_POST['category_label'] ) ? wp_unslash( $_POST['category_label'] ) : '',
					'active'               => ! empty( $_POST['active'] ),
					'price_from'           => isset( $_POST['price_from'] ) ? (int) $_POST['price_from'] : 0,
					'price_to'             => isset( $_POST['price_to'] ) ? (int) $_POST['price_to'] : 0,
					'has_drug_step'        => ! empty( $_POST['has_drug_step'] ),
					'requires_rx'          => ! empty( $_POST['requires_rx'] ),
					'needs_explicit_nurse' => ! empty( $_POST['needs_explicit_nurse'] ),
					'manual_assign'        => ! empty( $_POST['manual_assign'] ),
					'checklist_draft'      => ! empty( $_POST['checklist_draft'] ),
					'checklist'            => isset( $_POST['checklist'] ) ? wp_unslash( $_POST['checklist'] ) : '',
					'config'               => isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '{}',
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
		$list    = MNS_Services::list_all();
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit    = $edit_id ? MNS_Services::get( $edit_id ) : null;
		MNS_Admin_Ui::header( __( 'خدمات', 'medyar-nursing-services' ) );
		if ( isset( $_GET['mns_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success inline is-dismissible"><p>' . esc_html__( 'ذخیره شد.', 'medyar-nursing-services' ) . '</p></div>';
		}

		$by_cat = array();
		foreach ( $list as $row ) {
			$label = (string) ( $row['category_label'] !== '' ? $row['category_label'] : ( $row['category'] !== '' ? $row['category'] : '—' ) );
			$by_cat[ $label ][] = $row;
		}
		foreach ( $by_cat as $label => $rows ) {
			echo '<h2>' . esc_html( $label ) . '</h2>';
			echo '<table class="widefat striped mns-compact"><thead><tr>';
			echo '<th>ID</th><th>' . esc_html__( 'نام', 'medyar-nursing-services' ) . '</th><th>slug</th>';
			echo '<th>' . esc_html__( 'از–تا', 'medyar-nursing-services' ) . '</th>';
			echo '<th>فلگ‌ها</th><th>' . esc_html__( 'فعال', 'medyar-nursing-services' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$url   = add_query_arg( array( 'page' => self::SLUG, 'edit' => $row['id'] ), admin_url( 'admin.php' ) );
				$flags = array();
				if ( ! empty( $row['requires_rx'] ) ) {
					$flags[] = 'rx';
				}
				if ( ! empty( $row['needs_explicit_nurse'] ) ) {
					$flags[] = 'explicit';
				}
				if ( ! empty( $row['manual_assign'] ) ) {
					$flags[] = 'manual';
				}
				if ( ! empty( $row['checklist_draft'] ) ) {
					$flags[] = 'draft-CL';
				}
				echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html( $row['name'] ) . '</td><td><code>' . esc_html( $row['slug'] ) . '</code></td>';
				echo '<td dir="ltr">' . (int) $row['price_from'] . '–' . (int) $row['price_to'] . '</td>';
				echo '<td>' . esc_html( $flags ? implode( ', ', $flags ) : '—' ) . '</td>';
				echo '<td>' . ( $row['active'] ? '✓' : '—' ) . '</td>';
				echo '<td><a href="' . esc_url( $url ) . '">' . esc_html__( 'ویرایش', 'medyar-nursing-services' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( $edit ) {
			$cfg       = wp_json_encode( $edit['config'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			$cl_text   = implode( "\n", array_map( 'strval', (array) $edit['checklist'] ) );
			$draft_lbl = ! empty( $edit['checklist_draft'] ) ? ' <span class="description">(' . esc_html__( 'پیش‌نویس — نیازمند تأیید مالک', 'medyar-nursing-services' ) . ')</span>' : '';
			echo '<h2>' . esc_html__( 'ویرایش', 'medyar-nursing-services' ) . ': ' . esc_html( $edit['name'] ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'mns_service_save' );
			echo '<input type="hidden" name="action" value="mns_service_save" />';
			echo '<input type="hidden" name="service_id" value="' . (int) $edit['id'] . '" />';
			echo '<p><label>' . esc_html__( 'نام', 'medyar-nursing-services' ) . ' <input name="name" class="regular-text" value="' . esc_attr( $edit['name'] ) . '" /></label></p>';
			echo '<p><label>' . esc_html__( 'برچسب دسته', 'medyar-nursing-services' ) . ' <input name="category_label" class="regular-text" value="' . esc_attr( $edit['category_label'] ) . '" /></label></p>';
			echo '<p><label><input type="checkbox" name="active" value="1" ' . checked( $edit['active'], true, false ) . ' /> ' . esc_html__( 'فعال', 'medyar-nursing-services' ) . '</label></p>';
			echo '<p><label>' . esc_html__( 'قیمت از', 'medyar-nursing-services' ) . ' <input name="price_from" type="number" value="' . (int) $edit['price_from'] . '" /></label></p>';
			echo '<p><label>' . esc_html__( 'قیمت تا', 'medyar-nursing-services' ) . ' <input name="price_to" type="number" value="' . (int) $edit['price_to'] . '" /></label></p>';
			echo '<p><label><input type="checkbox" name="has_drug_step" value="1" ' . checked( $edit['has_drug_step'], true, false ) . ' /> has_drug_step</label></p>';
			echo '<p><label><input type="checkbox" name="requires_rx" value="1" ' . checked( ! empty( $edit['requires_rx'] ), true, false ) . ' /> requires_rx</label></p>';
			echo '<p><label><input type="checkbox" name="needs_explicit_nurse" value="1" ' . checked( ! empty( $edit['needs_explicit_nurse'] ), true, false ) . ' /> needs_explicit_nurse</label></p>';
			echo '<p><label><input type="checkbox" name="manual_assign" value="1" ' . checked( ! empty( $edit['manual_assign'] ), true, false ) . ' /> manual_assign</label></p>';
			echo '<p><label><input type="checkbox" name="checklist_draft" value="1" ' . checked( ! empty( $edit['checklist_draft'] ), true, false ) . ' /> checklist_draft' . $draft_lbl . '</label></p>';
			echo '<p><label>' . esc_html__( 'چک‌لیست (هر بند در یک خط)', 'medyar-nursing-services' ) . '<br /><textarea name="checklist" rows="8" class="large-text">' . esc_textarea( $cl_text ) . '</textarea></label></p>';
			echo '<p><label>config JSON<br /><textarea name="config" rows="8" class="large-text code">' . esc_textarea( (string) $cfg ) . '</textarea></label></p>';
			submit_button( __( 'ذخیره', 'medyar-nursing-services' ) );
			echo '</form>';
		}
		MNS_Admin_Ui::footer();
	}
}
