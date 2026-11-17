<?php
/**
 * Admin settings page (T-0.3). Replaces stub under خدمات پرستاری → تنظیمات.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MNS_Admin_Settings {

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'replace_menu' ), 999 );
		add_action( 'admin_post_mns_settings_save', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Swap the stub settings submenu for the real form.
	 */
	public static function replace_menu(): void {
		remove_submenu_page( MNS_Admin_Menu::SLUG_DASHBOARD, MNS_Admin_Menu::SLUG_SETTINGS );
		// Supervisor fix: also drop the old skeleton page callback, or the page renders twice.
		remove_all_actions( get_plugin_page_hookname( MNS_Admin_Menu::SLUG_SETTINGS, MNS_Admin_Menu::SLUG_DASHBOARD ) );
		add_submenu_page(
			MNS_Admin_Menu::SLUG_DASHBOARD,
			__( 'تنظیمات', 'medyar-nursing-services' ),
			__( 'تنظیمات', 'medyar-nursing-services' ),
			'manage_options',
			MNS_Admin_Menu::SLUG_SETTINGS,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( 'mns_settings_save' );

		$defs    = MNS_Settings::definitions();
		$saved   = 0;
		$skipped = 0;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated per-key below.
		$raw = isset( $_POST['mns'] ) ? wp_unslash( $_POST['mns'] ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		foreach ( $defs as $key => $def ) {
			$type = $def['type'];

			if ( 'bool' === $type ) {
				$val = isset( $raw[ $key ] ) ? $raw[ $key ] : '0';
				if ( MNS_Settings::set( $key, $val ) ) {
					$saved++;
				} else {
					$skipped++;
				}
				continue;
			}

			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			$value = $raw[ $key ];

			if ( in_array( $type, array( 'json', 'assoc_int', 'assoc_list', 'slots' ), true ) && is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				$value   = ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $value;
			}

			if ( 'list' === $type && is_string( $value ) ) {
				// keep string; validate() splits
			}

			if ( MNS_Settings::set( $key, $value ) ) {
				$saved++;
			} else {
				$skipped++;
			}
		}

		set_transient(
			'mns_settings_flash_' . get_current_user_id(),
			array(
				'saved'   => $saved,
				'skipped' => $skipped,
			),
			60
		);

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['tab'] ) ) : '';
		$args = array( 'mns_notice' => 'saved' );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		wp_safe_redirect( MNS_Admin_Ui::url( MNS_Admin_Menu::SLUG_SETTINGS, $args ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ این کار را ندارید.', 'medyar-nursing-services' ) );
		}

		$flash = get_transient( 'mns_settings_flash_' . get_current_user_id() );
		if ( false !== $flash ) {
			delete_transient( 'mns_settings_flash_' . get_current_user_id() );
		}

		$groups = MNS_Settings::groups();
		$defs   = MNS_Settings::definitions();
		$by_g   = array();
		foreach ( $defs as $key => $def ) {
			$g = isset( $def['group'] ) ? $def['group'] : 'general';
			if ( ! isset( $by_g[ $g ] ) ) {
				$by_g[ $g ] = array();
			}
			$by_g[ $g ][ $key ] = $def;
		}

		$tabs = array();
		foreach ( $groups as $gid => $glabel ) {
			if ( ! empty( $by_g[ $gid ] ) ) {
				$tabs[ $gid ] = $glabel;
			}
		}
		$tab = MNS_Admin_Ui::current_tab( $tabs );

		MNS_Admin_Ui::header( __( 'تنظیمات', 'medyar-nursing-services' ) );
		if ( is_array( $flash ) ) {
			echo '<div class="notice notice-success inline is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: saved count 2: skipped */
					__( 'ذخیره شد: %1$d · رد شده (نامعتبر): %2$d', 'medyar-nursing-services' ),
					(int) $flash['saved'],
					(int) $flash['skipped']
				)
			);
			echo '</p></div>';
		}
		MNS_Admin_Ui::tabs( MNS_Admin_Menu::SLUG_SETTINGS, $tabs, $tab );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mns-tab-body">';
		wp_nonce_field( 'mns_settings_save' );
		echo '<input type="hidden" name="action" value="mns_settings_save" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';

		if ( ! empty( $by_g[ $tab ] ) ) {
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $by_g[ $tab ] as $key => $def ) {
				$val   = MNS_Settings::get( $key );
				$label = isset( $def['label'] ) ? (string) $def['label'] : $key;
				$type  = $def['type'];
				$name  = 'mns[' . $key . ']';

				echo '<tr><th scope="row"><label for="' . esc_attr( 'mns-' . $key ) . '">';
				echo esc_html( $label );
				echo '</label><br><code class="mns-muted">' . esc_html( $key ) . '</code></th><td>';

				if ( 'bool' === $type ) {
					echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" id="' . esc_attr( 'mns-' . $key ) . '" value="1" ' . checked( (bool) $val, true, false ) . ' /> ';
					echo esc_html__( 'فعال', 'medyar-nursing-services' ) . '</label>';
				} elseif ( 'enum' === $type ) {
					echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( 'mns-' . $key ) . '">';
					foreach ( $def['enum'] as $opt ) {
						echo '<option value="' . esc_attr( $opt ) . '" ' . selected( (string) $val, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
					}
					echo '</select>';
				} elseif ( in_array( $type, array( 'int', 'float', 'string' ), true ) ) {
					echo '<input class="regular-text" type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( 'mns-' . $key ) . '" value="' . esc_attr( is_scalar( $val ) ? (string) $val : '' ) . '" />';
				} elseif ( 'list' === $type ) {
					$joined = is_array( $val ) ? implode( ', ', $val ) : '';
					echo '<input class="large-text" type="text" name="' . esc_attr( $name ) . '" id="' . esc_attr( 'mns-' . $key ) . '" value="' . esc_attr( $joined ) . '" />';
					echo '<p class="description">' . esc_html__( 'با ویرگول جدا کنید', 'medyar-nursing-services' ) . '</p>';
				} else {
					$json = wp_json_encode( $val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
					echo '<textarea class="large-text code" rows="6" name="' . esc_attr( $name ) . '" id="' . esc_attr( 'mns-' . $key ) . '">';
					echo esc_textarea( is_string( $json ) ? $json : '' );
					echo '</textarea>';
					echo '<p class="description">JSON</p>';
				}

				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		submit_button( __( 'ذخیره تنظیمات', 'medyar-nursing-services' ) );
		echo '</form>';
		MNS_Admin_Ui::footer();
	}
}
