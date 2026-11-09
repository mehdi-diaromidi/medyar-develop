<?php
/**
 * Admin menu + screen routers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Admin_Menu {

	public const PER_PAGE_OPTIONS = array( 'arp_referrals_per_page', 'arp_affiliates_per_page', 'arp_commissions_per_page', 'arp_applications_per_page', 'arp_invited_per_page' );

	public const FINANCE_TABS = array(
		'commissions' => 'پورسانت‌ها',
		'wallet'      => 'کیف پول',
		'rules'       => 'قوانین پورسانت',
	);

	public const SETTINGS_TABS = array(
		'general'   => 'عمومی و پیامک',
		'services'  => 'خدمات',
		'statuses'  => 'وضعیت‌های معرفی',
		'job-types' => 'انواع شغل',
		'tools'     => 'ابزارها و خروجی',
	);

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,string> page slug => hook suffix */
	private $hooks = array();

	/** @var WP_List_Table|null */
	private $table = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
	}

	/**
	 * @return string[] Hook suffixes of the plugin screens.
	 */
	public function hooks(): array {
		return array_values( $this->hooks );
	}

	public function register_menus(): void {
		$cap         = Arp_Roles::CAP_MANAGE;
		$ref_pending = $this->pending_count();
		$app_pending = Arp_Affiliate_Applications::pending_count();
		$ref_bubble  = $ref_pending
			? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $ref_pending, esc_html( Arp_Format::fa( number_format_i18n( $ref_pending ) ) ) )
			: '';
		$app_bubble  = $app_pending
			? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $app_pending, esc_html( Arp_Format::fa( number_format_i18n( $app_pending ) ) ) )
			: '';
		$main_bubble = $app_pending ? $app_bubble : $ref_bubble;

		add_menu_page( 'همکاران مدیار', 'همکاران مدیار' . $main_bubble, $cap, 'arp-dashboard', array( $this, 'render_dashboard' ), 'dashicons-groups', 26 );

		$pages = array(
			'arp-dashboard'    => array( 'پیشخوان', 'پیشخوان', array( $this, 'render_dashboard' ), null ),
			'arp-applications' => array( 'درخواست‌های طرح همکاران', 'درخواست‌ها' . $app_bubble, array( $this, 'render_applications' ), array( $this, 'load_applications' ) ),
			'arp-invited'      => array( 'دعوت‌کننده‌ها و پورسانت دعوت', 'دعوت‌کننده‌ها', array( $this, 'render_invited' ), array( $this, 'load_invited' ) ),
			'arp-referrals'    => array( 'معرفی‌ها', 'معرفی‌ها' . $ref_bubble, array( $this, 'render_referrals' ), array( $this, 'load_referrals' ) ),
			'arp-affiliates'   => array( 'همکاران', 'همکاران', array( $this, 'render_affiliates' ), array( $this, 'load_affiliates' ) ),
			'arp-finance'      => array( 'مالی و پورسانت', 'مالی و پورسانت', array( $this, 'render_finance' ), array( $this, 'load_finance' ) ),
			'arp-settings'     => array( 'تنظیمات همکاران', 'تنظیمات', array( $this, 'render_settings' ), null ),
		);

		foreach ( $pages as $slug => $meta ) {
			$hook = add_submenu_page( 'arp-dashboard', $meta[0], $meta[1], $cap, $slug, $meta[2] );
			if ( $hook ) {
				$this->hooks[ $slug ] = $hook;
				if ( $meta[3] ) {
					add_action( 'load-' . $hook, $meta[3] );
				}
			}
		}
	}

	/**
	 * Referrals still in the default ("waiting for contact") status.
	 */
	public function pending_count(): int {
		static $count = null;
		if ( null === $count ) {
			$default = Arp_Statuses_Repo::get_default();
			$count   = $default ? Arp_Referrals_Repo::count( array( 'status_id' => (int) $default['id'] ) ) : 0;
		}
		return $count;
	}

	/* ---------------------------------------------------------------- load hooks */

	public function load_referrals(): void {
		if ( 'edit' === $this->request_action() ) {
			return;
		}
		$this->prepare_table( new Arp_Referrals_Table(), 'arp_referrals_per_page', 'معرفی در هر صفحه' );
	}

	public function load_affiliates(): void {
		$this->prepare_table( new Arp_Affiliates_Table(), 'arp_affiliates_per_page', 'همکار در هر صفحه' );
	}

	public function load_applications(): void {
		$this->prepare_table( new Arp_Applications_Table(), 'arp_applications_per_page', 'درخواست در هر صفحه' );
	}

	public function load_invited(): void {
		$this->prepare_table( new Arp_Invited_Affiliates_Table(), 'arp_invited_per_page', 'معرف در هر صفحه' );
	}

	public function load_finance(): void {
		if ( 'commissions' === Arp_Admin_Ui::current_tab( self::FINANCE_TABS ) ) {
			$this->prepare_table( new Arp_Commissions_Table(), 'arp_commissions_per_page', 'پورسانت در هر صفحه' );
		}
	}

	/**
	 * Screen option, bulk actions and clean filter URLs (mirrors core list screens).
	 */
	private function prepare_table( WP_List_Table $table, string $option, string $label ): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => $label,
				'default' => 20,
				'option'  => $option,
			)
		);
		$this->table = $table;

		if ( method_exists( $table, 'process_bulk_action' ) ) {
			$table->process_bulk_action();
		}

		if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2', 'filter_action' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			exit;
		}
	}

	/**
	 * Users with the affiliate role, optionally registered on/after a site-local date.
	 */
	public static function affiliate_count( string $since = '' ): int {
		$args = array(
			'role'        => Arp_Roles::ROLE,
			'number'      => 1,
			'fields'      => 'ID',
			'count_total' => true,
		);
		if ( '' !== $since ) {
			$args['date_query'] = array( array( 'after' => $since, 'inclusive' => true ) );
		}
		return (int) ( new WP_User_Query( $args ) )->get_total();
	}

	private function request_action(): string {
		return isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* ---------------------------------------------------------------- renderers */

	/**
	 * @param array<string,mixed> $vars Variables for the view.
	 */
	private function load_view( string $view, array $vars = array() ): void {
		if ( ! Arp_Admin::can_manage() ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'affiliate-referral' ) );
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$path = ARP_PLUGIN_DIR . 'admin/views/' . $view . '.php';
		if ( is_readable( $path ) ) {
			include $path;
		}
	}

	public function render_dashboard(): void {
		$month_start = Arp_Format::jalali_month_start();
		$today       = current_time( 'Y-m-d' );
		$default     = Arp_Statuses_Repo::get_default();

		$this->load_view(
			'dashboard',
			array(
				'affiliate_count'  => self::affiliate_count(),
				'new_affiliates'   => self::affiliate_count( $month_start ),
				'today_count'      => Arp_Referrals_Repo::count( array( 'date_from' => $today ) ),
				'month_count'      => Arp_Referrals_Repo::count( array( 'date_from' => $month_start ) ),
				'total_count'      => Arp_Referrals_Repo::count(),
				'pending_count'    => $this->pending_count(),
				'default_status'   => $default,
				'payable'          => array(),
				'paid_month'       => Arp_Commissions_Repo::sum_paid_since( $month_start ),
				'statuses'         => Arp_Statuses_Repo::all(),
				'status_counts'    => Arp_Referrals_Repo::count_by_status(),
				'waiting'          => $default ? Arp_Referrals_Repo::query( array( 'status_id' => (int) $default['id'], 'order' => 'asc', 'limit' => 6 ) ) : array(),
				'recent'           => Arp_Referrals_Repo::query( array( 'limit' => 6 ) ),
				'top_affiliates'   => Arp_Referrals_Repo::top_affiliates_since( $month_start, 5 ),
			)
		);
	}

	public function render_referrals(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit' === $this->request_action() && $id ) {
			$referral = Arp_Referrals_Repo::query( array( 'id' => $id, 'limit' => 1 ) )[0] ?? null;
			$this->load_view(
				'referral-edit',
				array(
					'referral'    => $referral,
					'statuses'    => Arp_Statuses_Repo::all(),
					'suggestion'  => $referral ? Arp_Services_Repo::commission_suggestion( (int) $referral['service_id'] ) : null,
					'commissions' => $referral ? Arp_Commissions_Repo::list_for_referral( $id ) : array(),
					'logs'        => $referral ? Arp_Referral_Logs_Repo::for_referral( $id ) : array(),
				)
			);
			return;
		}
		$this->load_view( 'referrals', array( 'table' => $this->table ) );
	}

	public function render_affiliates(): void {
		$this->load_view( 'affiliates', array( 'table' => $this->table ) );
	}

	public function render_applications(): void {
		$this->load_view( 'applications', array( 'table' => $this->table ) );
	}

	public function render_invited(): void {
		$this->load_view( 'invited-affiliates', array( 'table' => $this->table ) );
	}

	public function render_finance(): void {
		$raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'settlements' === $raw ) {
			wp_safe_redirect( Arp_Admin_Ui::url( 'arp-finance', array( 'tab' => 'wallet' ) ) );
			exit;
		}
		$tab = Arp_Admin_Ui::current_tab( self::FINANCE_TABS );
		$vars = array(
			'page_slug' => 'arp-finance',
			'title'     => 'مالی و پورسانت',
			'tabs'      => self::FINANCE_TABS,
			'tab'       => $tab,
		);

		if ( 'commissions' === $tab ) {
			$vars['table'] = $this->table;
		} elseif ( 'wallet' !== $tab ) {
			$vars += array(
				'services' => Arp_Services_Repo::all(),
				'rules'    => Arp_Commission_Rules_Repo::all_active(),
			);
		}

		$this->load_view( 'tabbed', $vars + array( 'tab_view' => 'finance/' . $tab ) );
	}

	public function render_settings(): void {
		$tab  = Arp_Admin_Ui::current_tab( self::SETTINGS_TABS );
		$vars = array(
			'page_slug' => 'arp-settings',
			'title'     => 'تنظیمات همکاران',
			'tabs'      => self::SETTINGS_TABS,
			'tab'       => $tab,
		);

		switch ( $tab ) {
			case 'services':
				$vars += array(
					'items'  => Arp_Services_Repo::all(),
					'usage'  => Arp_Referrals_Repo::count_by_service(),
					'rules'  => array_column( Arp_Commission_Rules_Repo::all_active(), null, 'service_id' ),
				);
				break;
			case 'statuses':
				$vars += array(
					'items' => Arp_Statuses_Repo::all(),
					'usage' => Arp_Referrals_Repo::count_by_status(),
				);
				break;
			case 'job-types':
				$vars += array(
					'items' => Arp_Job_Types_Repo::all(),
					'usage' => Arp_Job_Types_Repo::usage_counts(),
				);
				break;
			case 'tools':
				global $wpdb;
				$vars += array(
					'db_version' => (string) get_option( 'arp_db_version', '' ),
					'mysql'      => (string) $wpdb->db_version(),
					'counts'     => array(
						'همکاران'   => self::affiliate_count(),
						'معرفی‌ها'   => Arp_Referrals_Repo::count(),
						'پورسانت‌ها' => Arp_Commissions_Repo::count(),
					),
				);
				break;
			default:
				$admin_ids_raw   = get_option( 'arp_admin_sms_recipient_ids', array() );
				$admin_ids       = is_array( $admin_ids_raw ) ? array_map( 'absint', $admin_ids_raw ) : array();
				$special_ids   = Arp_Inviter::special_inviter_ids();
				$special_users = get_users(
					array(
						'orderby' => 'display_name',
						'order'   => 'ASC',
						'number'  => -1,
					)
				);
				$vars           += array(
					'template'    => (string) get_option( 'arp_patient_sms_template', '' ),
					'sms_enabled' => '1' === (string) get_option( 'arp_patient_sms_enabled', '1' ),
					'aff_template'    => (string) get_option( 'arp_affiliate_sms_template', Arp_Faraz_Sms_Gateway::AFFILIATE_SMS_TEMPLATE ),
					'aff_sms_enabled' => '1' === (string) get_option( 'arp_affiliate_sms_enabled', '1' ),
					'admin_sms_enabled'       => '1' === (string) get_option( 'arp_admin_sms_enabled', '1' ),
					'admin_sms_recipient_ids' => $admin_ids,
					'admin_users'             => get_users(
						array(
							'role'    => 'administrator',
							'orderby' => 'display_name',
							'order'   => 'ASC',
							'number'  => 200,
						)
					),
					'special_inviter_ids'   => $special_ids,
					'special_inviter_users' => $special_users,
					'faraz_ready' => function_exists( 'farazsms_is_ready' ) && farazsms_is_ready(),
					'panel_url'   => Arp_Pages::dashboard_url(),
					'has_panel'   => Arp_Pages::has_panel_page(),
				);
		}

		$this->load_view( 'tabbed', $vars + array( 'tab_view' => 'settings/' . $tab ) );
	}
}
