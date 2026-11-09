<?php
/**
 * Admin bootstrap: assets, form handlers, legacy URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Admin {

	/** Pre-1.1 screen slug => [page, tab]. */
	private const LEGACY_PAGES = array(
		'arp-commissions'         => array( 'arp-finance', 'commissions' ),
		'arp-settlements'         => array( 'arp-finance', 'wallet' ),
		'arp-commission-settings' => array( 'arp-finance', 'rules' ),
		'arp-services'            => array( 'arp-settings', 'services' ),
		'arp-statuses'            => array( 'arp-settings', 'statuses' ),
		'arp-job-types'           => array( 'arp-settings', 'job-types' ),
		'arp-reports'             => array( 'arp-settings', 'tools' ),
	);

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function can_manage(): bool {
		return current_user_can( Arp_Roles::CAP_MANAGE ) || current_user_can( 'manage_options' );
	}

	public function init(): void {
		Arp_Admin_Menu::instance()->init();
		Arp_Dashboard_Widget::init();
		add_action( 'admin_page_access_denied', array( $this, 'redirect_legacy_pages' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_init', array( 'Arp_Export_Csv', 'maybe_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );

		foreach ( Arp_Admin_Menu::PER_PAGE_OPTIONS as $option ) {
			add_filter( 'set_screen_option_' . $option, array( $this, 'save_per_page' ), 10, 3 );
		}
	}

	/**
	 * @param mixed  $status Default (false).
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 */
	public function save_per_page( $status, $option, $value ): int {
		return max( 1, min( 200, (int) $value ) );
	}

	/**
	 * One-off notice args: core strips them from the address bar after the page loads.
	 *
	 * @param string[] $args Removable query args.
	 * @return string[]
	 */
	public function removable_query_args( array $args ): array {
		return array_merge( $args, array( 'arp_notice', 'arp_count' ) );
	}

	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, Arp_Admin_Menu::instance()->hooks(), true ) ) {
			return;
		}
		wp_enqueue_style( 'arp-admin', ARP_PLUGIN_URL . 'assets/css/admin.css', array(), self::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'arp-admin', ARP_PLUGIN_URL . 'assets/js/admin.js', array(), self::asset_version( 'assets/js/admin.js' ), true );
	}

	private static function asset_version( string $relative ): string {
		$path = ARP_PLUGIN_DIR . $relative;
		return file_exists( $path ) ? (string) filemtime( $path ) : ARP_VERSION;
	}

	public function redirect_legacy_pages(): void {
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'admin.php' !== $pagenow || ! isset( self::LEGACY_PAGES[ $page ] ) ) {
			return;
		}
		list( $target, $tab ) = self::LEGACY_PAGES[ $page ];
		$args = array( 'tab' => $tab );
		if ( ! empty( $_GET['affiliate_user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['affiliate_user_id'] = absint( $_GET['affiliate_user_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		wp_safe_redirect( Arp_Admin_Ui::url( $target, $args ) );
		exit;
	}

	public function handle_actions(): void {
		if ( empty( $_POST ) && empty( $_GET['arp_toggle'] ) && empty( $_GET['arp_delete_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! self::can_manage() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- each branch verifies its own nonce via check_admin_referer().
		if ( ! empty( $_GET['arp_toggle'] ) ) {
			$this->toggle_active();
		} elseif ( ! empty( $_GET['arp_delete_status'] ) ) {
			$this->delete_status();
		} elseif ( isset( $_POST['arp_save_job_type'] ) && check_admin_referer( 'arp_save_job_type' ) ) {
			$this->save_named_item( 'job-types', array( 'Arp_Job_Types_Repo', 'insert' ), array( 'Arp_Job_Types_Repo', 'update' ) );
		} elseif ( isset( $_POST['arp_save_service'] ) && check_admin_referer( 'arp_save_service' ) ) {
			$this->save_service();
		} elseif ( isset( $_POST['arp_save_status'] ) && check_admin_referer( 'arp_save_status' ) ) {
			$this->save_status();
		} elseif ( isset( $_POST['arp_save_rule'] ) && check_admin_referer( 'arp_save_rule' ) ) {
			$this->save_rule();
		} elseif ( isset( $_POST['arp_update_referral'] ) && check_admin_referer( 'arp_update_referral' ) ) {
			$this->update_referral();
		} elseif ( isset( $_POST['arp_migrate_approved_wallet'] ) && check_admin_referer( 'arp_migrate_approved_wallet' ) ) {
			$this->migrate_approved_wallet();
		} elseif ( isset( $_POST['arp_save_settings'] ) && check_admin_referer( 'arp_save_settings' ) ) {
			update_option( 'arp_patient_sms_template', isset( $_POST['arp_patient_sms_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['arp_patient_sms_template'] ) ) : '' );
			update_option( 'arp_patient_sms_enabled', isset( $_POST['arp_patient_sms_enabled'] ) ? '1' : '0' );
			update_option( 'arp_affiliate_sms_template', isset( $_POST['arp_affiliate_sms_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['arp_affiliate_sms_template'] ) ) : '' );
			update_option( 'arp_affiliate_sms_enabled', isset( $_POST['arp_affiliate_sms_enabled'] ) ? '1' : '0' );
			update_option( 'arp_commission_sms_enabled', isset( $_POST['arp_commission_sms_enabled'] ) ? '1' : '0' );
			$commission_tpl = isset( $_POST['arp_commission_sms_template'] )
				? sanitize_textarea_field( wp_unslash( $_POST['arp_commission_sms_template'] ) )
				: '';
			if ( '' === trim( $commission_tpl ) ) {
				$commission_tpl = Arp_Faraz_Sms_Gateway::COMMISSION_SMS_TEMPLATE;
			}
			update_option( 'arp_commission_sms_template', $commission_tpl );
			update_option( 'arp_admin_sms_enabled', isset( $_POST['arp_admin_sms_enabled'] ) ? '1' : '0' );
			$admin_ids = array();
			if ( isset( $_POST['arp_admin_sms_recipient_ids'] ) && is_array( $_POST['arp_admin_sms_recipient_ids'] ) ) {
				foreach ( wp_unslash( $_POST['arp_admin_sms_recipient_ids'] ) as $raw_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$uid = absint( $raw_id );
					if ( ! $uid ) {
						continue;
					}
					$user = get_userdata( $uid );
					if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
						$admin_ids[] = $uid;
					}
				}
			}
			update_option( 'arp_admin_sms_recipient_ids', array_values( array_unique( $admin_ids ) ) );
			$special_ids = array();
			if ( isset( $_POST['arp_special_inviter_ids'] ) && is_array( $_POST['arp_special_inviter_ids'] ) ) {
				foreach ( wp_unslash( $_POST['arp_special_inviter_ids'] ) as $raw_id ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$uid = absint( $raw_id );
					if ( $uid > 0 && get_userdata( $uid ) ) {
						$special_ids[] = $uid;
					}
				}
			}
			update_option( Arp_Inviter::OPTION_SPECIAL_IDS, array_values( array_unique( $special_ids ) ) );
			update_option( 'arp_admin_digest_sms_enabled', isset( $_POST['arp_admin_digest_sms_enabled'] ) ? '1' : '0' );
			$digest_tpl = isset( $_POST['arp_admin_digest_sms_template'] )
				? sanitize_textarea_field( wp_unslash( $_POST['arp_admin_digest_sms_template'] ) )
				: '';
			if ( '' === trim( $digest_tpl ) ) {
				$digest_tpl = Arp_Admin_Pending_Digest::DEFAULT_TEMPLATE;
			}
			update_option( 'arp_admin_digest_sms_template', $digest_tpl );
			$pattern_keys = array(
				'arp_patient_sms_pattern',
				'arp_affiliate_sms_pattern',
				'arp_commission_sms_pattern',
				'arp_admin_signup_sms_pattern',
				'arp_admin_referral_sms_pattern',
				'arp_applicant_pending_sms_pattern',
				'arp_applicant_approved_sms_pattern',
				'arp_applicant_rejected_sms_pattern',
				'arp_admin_digest_sms_pattern',
			);
			foreach ( $pattern_keys as $pkey ) {
				$raw = isset( $_POST[ $pkey ] ) ? wp_unslash( $_POST[ $pkey ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				update_option( $pkey, Arp_Faraz_Sms_Gateway::sanitize_pattern_option( $raw ) );
			}
			Arp_Admin_Ui::redirect( 'arp-settings', 'saved', array( 'tab' => 'general' ) );
		} elseif ( isset( $_POST['arp_test_sms'] ) && check_admin_referer( 'arp_test_sms' ) ) {
			$this->send_test_sms();
		} elseif ( isset( $_POST['arp_send_digest_now'] ) && check_admin_referer( 'arp_send_digest_now' ) ) {
			$result = Arp_Admin_Pending_Digest::send_digest();
			$code   = ! empty( $result['skipped_empty'] )
				? 'digest_empty'
				: ( ! empty( $result['sent'] ) ? 'digest_sent' : 'digest_failed' );
			Arp_Admin_Ui::redirect( 'arp-settings', $code, array( 'tab' => 'general' ) );
		}
		// phpcs:enable
	}

	/**
	 * Parse an admin-entered amount (Persian digits, thousands separators); null when empty.
	 */
	private static function amount_from_post( string $key ): ?float {
		$raw = isset( $_POST[ $key ] ) ? Arp_Format::en( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = preg_replace( '/[^\d.]/', '', $raw );
		return '' === $raw ? null : (float) $raw;
	}

	private function toggle_active(): void {
		$type = sanitize_key( wp_unslash( $_GET['arp_toggle'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'service'  => array( 'Arp_Services_Repo', 'services' ),
			'job_type' => array( 'Arp_Job_Types_Repo', 'job-types' ),
		);
		if ( ! isset( $map[ $type ] ) || ! $id ) {
			return;
		}
		check_admin_referer( 'arp_toggle_' . $type . '_' . $id );

		list( $repo, $tab ) = $map[ $type ];
		$item = $repo::get( $id );
		if ( $item ) {
			$repo::set_active( $id, empty( $item['is_active'] ) ? 1 : 0 );
		}
		Arp_Admin_Ui::redirect( 'arp-settings', 'saved', array( 'tab' => $tab ) );
	}

	/**
	 * @param callable $insert fn(string $name, int $active).
	 * @param callable $update fn(int $id, string $name, int $active).
	 */
	private function save_named_item( string $tab, callable $insert, callable $update ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by caller.
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$active = isset( $_POST['is_active'] ) ? 1 : 0;
		// phpcs:enable
		if ( '' === $name ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'missing_fields', array( 'tab' => $tab ) );
		}
		if ( $id ) {
			$update( $id, $name, $active );
		} else {
			$insert( $name, $active );
		}
		Arp_Admin_Ui::redirect( 'arp-settings', 'saved', array( 'tab' => $tab ) );
	}

	private function save_service(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by caller.
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$active = isset( $_POST['is_active'] ) ? 1 : 0;
		// phpcs:enable
		$price      = self::amount_from_post( 'price' );
		$percentage = self::amount_from_post( 'percentage' );
		$back       = array( 'tab' => 'services' );

		if ( '' === $name ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'missing_fields', $back + ( $id ? array( 'edit' => $id ) : array() ) );
		}
		if ( null !== $price && $price <= 0 ) {
			$price = null;
		}
		if ( $id ) {
			Arp_Services_Repo::update( $id, $name, $active, $price );
		} else {
			$id = Arp_Services_Repo::insert( $name, $active, $price );
		}

		$rule = $id ? Arp_Commission_Rules_Repo::get_active_for_service( $id ) : null;
		if ( $id && ( null !== $percentage || $rule ) ) {
			$percentage = min( 100.0, max( 0.0, (float) $percentage ) );
			if ( ! $rule || abs( (float) $rule['percentage'] - $percentage ) > 0.001 ) {
				Arp_Commission_Rules_Repo::set_rule( $id, $percentage, $rule && null !== $rule['default_amount'] ? (float) $rule['default_amount'] : null );
			}
		}
		Arp_Admin_Ui::redirect( 'arp-settings', 'saved', $back );
	}

	private function save_status(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by caller.
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$data = array(
			'name'                => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'slug'                => isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '',
			'sort_order'          => isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 0,
			'is_default'          => isset( $_POST['is_default'] ) ? 1 : 0,
			'triggers_commission' => isset( $_POST['triggers_commission'] ) ? 1 : 0,
			'is_terminal'         => isset( $_POST['is_terminal'] ) ? 1 : 0,
			'color'               => isset( $_POST['color'] ) ? Arp_Ui::sanitize_color( sanitize_text_field( wp_unslash( $_POST['color'] ) ) ) : '',
			'dashboard_group'     => isset( $_POST['dashboard_group'] ) ? sanitize_key( wp_unslash( $_POST['dashboard_group'] ) ) : '',
		);
		// phpcs:enable
		if ( ! isset( Arp_Ui::STATUS_PALETTE[ $data['color'] ] ) ) {
			$data['color'] = Arp_Ui::default_status_color( $data['slug'], count( Arp_Statuses_Repo::all() ) );
		}
		$back = array( 'tab' => 'statuses' );
		if ( '' === $data['name'] || '' === $data['slug'] ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'missing_fields', $back + ( $id ? array( 'edit' => $id ) : array() ) );
		}
		foreach ( Arp_Statuses_Repo::all() as $st ) {
			if ( $st['slug'] === $data['slug'] && (int) $st['id'] !== $id ) {
				Arp_Admin_Ui::redirect( 'arp-settings', 'slug_taken', $back + ( $id ? array( 'edit' => $id ) : array() ) );
			}
		}
		if ( $id ) {
			Arp_Statuses_Repo::update( $id, $data );
		} else {
			Arp_Statuses_Repo::insert( $data );
		}
		Arp_Admin_Ui::redirect( 'arp-settings', 'saved', $back );
	}

	/**
	 * The default and fallback statuses can't be deleted; referrals of a deleted status move to the fallback.
	 */
	private function delete_status(): void {
		$id   = absint( $_GET['arp_delete_status'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$back = array( 'tab' => 'statuses' );
		check_admin_referer( 'arp_delete_status_' . $id );

		$status = Arp_Statuses_Repo::get( $id );
		if ( ! $status ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'arp_bad_status', $back );
		}
		if ( ! empty( $status['is_default'] ) ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'status_is_default', $back );
		}
		$fallback = Arp_Statuses_Repo::get_by_slug( Arp_Statuses_Repo::FALLBACK_SLUG );
		if ( $fallback && (int) $fallback['id'] === $id ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'status_is_fallback', $back );
		}

		$referral_ids = Arp_Referrals_Repo::ids_by_status( $id );
		if ( $referral_ids && ! $fallback ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'status_no_fallback', $back );
		}

		$note  = sprintf( 'انتقال خودکار: وضعیت «%s» حذف شد.', $status['name'] );
		$moved = 0;
		// A bulk housekeeping move, not a real follow-up: affiliates get no status SMS for it.
		$sms = has_action( 'arp_referral_status_changed', array( 'Arp_Faraz_Sms_Gateway', 'queue_affiliate_status_sms' ) );
		if ( false !== $sms ) {
			remove_action( 'arp_referral_status_changed', array( 'Arp_Faraz_Sms_Gateway', 'queue_affiliate_status_sms' ), $sms );
		}
		foreach ( $referral_ids as $referral_id ) {
			if ( true === Arp_Commission_Engine::on_status_change( $referral_id, (int) $fallback['id'], null, $note ) ) {
				++$moved;
			}
		}
		if ( false !== $sms ) {
			add_action( 'arp_referral_status_changed', array( 'Arp_Faraz_Sms_Gateway', 'queue_affiliate_status_sms' ), $sms, 2 );
		}

		if ( $moved < count( $referral_ids ) ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'status_in_use', $back + array( 'arp_count' => count( $referral_ids ) - $moved ) );
		}
		Arp_Statuses_Repo::delete( $id );
		Arp_Admin_Ui::redirect( 'arp-settings', $moved ? 'status_deleted_moved' : 'status_deleted', $back + ( $moved ? array( 'arp_count' => $moved ) : array() ) );
	}

	private function save_rule(): void {
		$service_id = isset( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$percentage = self::amount_from_post( 'percentage' );
		if ( $service_id ) {
			Arp_Commission_Rules_Repo::set_rule( $service_id, min( 100.0, max( 0.0, (float) $percentage ) ), self::amount_from_post( 'default_amount' ) );
		}
		Arp_Admin_Ui::redirect( 'arp-finance', 'saved', array( 'tab' => 'rules' ) );
	}

	private function update_referral(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by caller.
		$referral_id = isset( $_POST['referral_id'] ) ? absint( $_POST['referral_id'] ) : 0;
		$status_id   = isset( $_POST['status_id'] ) ? absint( $_POST['status_id'] ) : 0;
		$note        = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		// phpcs:enable
		$before = Arp_Referrals_Repo::get( $referral_id );
		$args   = array(
			'action' => 'edit',
			'id'     => $referral_id,
		);

		$result = Arp_Commission_Engine::on_status_change( $referral_id, $status_id, self::amount_from_post( 'commission_amount' ), $note );
		if ( is_wp_error( $result ) ) {
			Arp_Admin_Ui::redirect( 'arp-referrals', $result->get_error_code(), $args );
		}

		if ( $before && (int) $before['status_id'] !== $status_id ) {
			$notice = 'status_updated';
		} else {
			$notice = '' !== $note ? 'note_added' : 'saved';
		}
		Arp_Admin_Ui::redirect( 'arp-referrals', $notice, $args );
	}

	private function migrate_approved_wallet(): void {
		if ( ! Arp_Wallet_Bridge::available() ) {
			Arp_Admin_Ui::redirect( 'arp-finance', 'arp_wallet_unavailable', array( 'tab' => 'wallet' ) );
		}
		$result = Arp_Wallet_Bridge::migrate_all_approved();
		Arp_Admin_Ui::redirect(
			'arp-finance',
			'wallet_migrated',
			array(
				'tab'       => 'wallet',
				'arp_count' => (int) ( $result['ok'] ?? 0 ),
			)
		);
	}

	private function send_test_sms(): void {
		$raw   = isset( $_POST['test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$phone = Arp_Format::iran_mobile( $raw );
		$back  = array( 'tab' => 'general' );
		if ( '' === $phone ) {
			Arp_Admin_Ui::redirect( 'arp-settings', 'bad_phone', $back );
		}
		$which   = isset( $_POST['test_template'] ) ? sanitize_key( wp_unslash( $_POST['test_template'] ) ) : 'patient'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$gateway = new Arp_Faraz_Sms_Gateway();
		$sent    = false;

		if ( 'affiliate' === $which ) {
			$vars    = array(
				'affiliate_name' => 'همکار نمونه',
				'patient_name'   => 'بیمار نمونه',
				'service'        => 'خدمت نمونه',
				'status'         => 'تکمیل‌شده',
			);
			$sent = $gateway->deliver(
				$phone,
				Arp_Faraz_Sms_Gateway::affiliate_status_message( $vars ),
				Arp_Faraz_Sms_Gateway::pattern_code( 'arp_affiliate_sms_pattern' ),
				$vars
			);
		} elseif ( 'commission' === $which ) {
			$vars = array(
				'affiliate_name' => 'همکار نمونه',
				'patient_name'   => 'بیمار نمونه',
				'service'        => 'خدمت نمونه',
				'amount'         => '250000',
				'referral_id'    => '1',
				'commission_id'  => '1',
			);
			$sent = $gateway->deliver(
				$phone,
				Arp_Faraz_Sms_Gateway::commission_message( $vars ),
				Arp_Faraz_Sms_Gateway::pattern_code( 'arp_commission_sms_pattern' ),
				$vars
			);
		} elseif ( 'admin_referral' === $which ) {
			$vars = array(
				'affiliate_name' => 'همکار نمونه',
				'affiliate_code' => 'ABC123',
				'patient_name'   => 'بیمار نمونه',
				'patient_phone'  => '09123456789',
				'service'        => 'خدمت نمونه',
				'referral_id'    => '1',
			);
			$sent = $gateway->deliver(
				$phone,
				'تست پترن معرفی جدید',
				Arp_Faraz_Sms_Gateway::pattern_code( 'arp_admin_referral_sms_pattern' ),
				$vars
			);
		} elseif ( 'admin_signup' === $which ) {
			$vars = array(
				'name'   => 'متقاضی نمونه',
				'mobile' => '09123456789',
				'job'    => 'پزشک',
				'docs'   => '1 فایل',
				'url'    => admin_url( 'admin.php?page=arp-applications' ),
			);
			$sent = $gateway->deliver(
				$phone,
				'تست پترن ثبت‌نام همکار',
				Arp_Faraz_Sms_Gateway::pattern_code( 'arp_admin_signup_sms_pattern' ),
				$vars
			);
		} elseif ( 'applicant_pending' === $which ) {
			$sent = $gateway->deliver(
				$phone,
				'تست پترن صف بررسی',
				Arp_Faraz_Sms_Gateway::pattern_code( 'arp_applicant_pending_sms_pattern' ),
				array( 'name' => 'متقاضی نمونه' )
			);
		} elseif ( 'applicant_approved' === $which ) {
			$sent = $gateway->deliver(
				$phone,
				'تست پترن تأیید',
				Arp_Faraz_Sms_Gateway::pattern_code( 'arp_applicant_approved_sms_pattern' ),
				array( 'name' => 'متقاضی نمونه' )
			);
		} elseif ( 'applicant_rejected' === $which ) {
			$sent = $gateway->deliver(
				$phone,
				'تست پترن رد',
				Arp_Faraz_Sms_Gateway::pattern_code( 'arp_applicant_rejected_sms_pattern' ),
				array(
					'name' => 'متقاضی نمونه',
					'note' => 'مدارک ناقص',
				)
			);
		} else {
			$sent = Arp_Faraz_Sms_Gateway::send_patient_referral_sms( $phone, 'بیمار نمونه', 'ABC123', true );
		}

		$meta = Arp_Faraz_Sms_Gateway::last_deliver_meta();
		set_transient(
			'arp_last_test_sms_meta_' . get_current_user_id(),
			array(
				'which'   => $which,
				'sent'    => $sent,
				'mode'    => (string) ( $meta['mode'] ?? '' ),
				'pattern' => (string) ( $meta['pattern'] ?? '' ),
				'detail'  => (string) ( $meta['detail'] ?? '' ),
			),
			120
		);

		$code = 'sms_failed';
		if ( $sent ) {
			$code = ( 'pattern' === ( $meta['mode'] ?? '' ) && '' !== ( $meta['pattern'] ?? '' ) )
				? 'sms_sent_pattern'
				: 'sms_sent';
		} elseif ( 'pattern' === ( $meta['mode'] ?? '' ) && '' !== ( $meta['pattern'] ?? '' ) ) {
			$code = 'sms_pattern_failed';
		}
		Arp_Admin_Ui::redirect( 'arp-settings', $code, $back );
	}
}
