<?php
/**
 * Custom reserve settings (admin SMS + Medyar income fee) + send on book/cancel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMAD_Admin_Reserve_SMS {

	const OPTION      = 'medyar_admin_reserve_sms';
	const PAGE        = 'medyar-admin-reserve-sms';
	const PAGE_INCOME = 'medyar-admin-reserve-income';

	/** @var string[] Admin page hook suffixes. */
	private $hooks = [];

	const META_FEE_AMOUNT   = '_medyar_booking_fee_amount';
	const META_FEE_PERCENT  = '_medyar_booking_fee_percent';
	const META_FEE_GROSS    = '_medyar_booking_fee_gross';
	const META_FEE_REVERSED = '_medyar_booking_fee_reversed';
	const META_COMMISSION   = '_medyar_booking_commission';

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'redirect_legacy_tab_url' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'woocommerce_order_status_changed', [ $this, 'on_order_status' ], 8, 3 );
		add_action( 'drplus/booking/appointment_canceled', [ $this, 'on_cancel' ], 20, 1 );
		add_action( 'msq_register_transports', [ $this, 'register_queue_transport' ] );

		add_filter( 'mtw_booking_specialist_payout_amount', [ $this, 'filter_mtw_payout_amount' ], 10, 3 );
		add_filter( 'sheyda/wallet/ledger/booking_payout/new', [ $this, 'filter_sheyda_booking_payout' ], 5, 2 );
		add_action( 'admin_post_mmad_income_export', [ $this, 'export_income_csv' ] );
		add_action( 'woocommerce_order_status_completed', [ $this, 'snapshot_booking_commission' ], 20, 1 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'snapshot_booking_commission_on_status' ], 20, 4 );
	}

	/**
	 * @return array<string,string>
	 */
	public static function tabs() {
		return [
			'sms'           => 'پیامک ادمین',
			'medyar-income' => 'درآمد مدیار',
		];
	}

	/**
	 * @return string
	 */
	public static function current_tab() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return $page === self::PAGE_INCOME ? 'medyar-income' : 'sms';
	}

	/**
	 * Admin URL of a section (submenu) page.
	 *
	 * @param string $tab Tab key from tabs().
	 */
	public static function tab_url( string $tab ): string {
		return admin_url( 'admin.php?page=' . ( $tab === 'medyar-income' ? self::PAGE_INCOME : self::PAGE ) );
	}

	/**
	 * Old `?page=medyar-admin-reserve-sms&tab=medyar-income` links → income submenu.
	 */
	public function redirect_legacy_tab_url(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['tab'] ) || sanitize_key( wp_unslash( $_GET['page'] ) ) !== self::PAGE ) {
			return;
		}
		if ( sanitize_key( wp_unslash( $_GET['tab'] ) ) !== 'medyar-income' ) {
			return;
		}
		$args = wp_unslash( $_GET );
		// phpcs:enable
		unset( $args['tab'] );
		$args['page'] = self::PAGE_INCOME;
		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', array_map( 'strval', array_filter( $args, 'is_scalar' ) ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return array
	 */
	public static function defaults() {
		$vars = 'book_id:{book_id};specialist_name:{specialist_name};patient_name:{patient_name};patient_mobile:{patient_mobile};visit_date:{visit_date};visit_time:{visit_time};office:{office}';
		return [
			'enabled'        => 0,
			'phones'         => [ '' ],
			'book'           => [
				'pattern' => '',
				'message' => $vars,
			],
			'book_canceled'  => [
				'pattern' => '',
				'message' => $vars,
			],
			'income_enabled' => 0,
			'income_percent' => 10,
		];
	}

	/**
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$defaults = self::defaults();
		$out      = array_merge( $defaults, $stored );
		$out['book']          = array_merge( $defaults['book'], is_array( $stored['book'] ?? null ) ? $stored['book'] : [] );
		$out['book_canceled'] = array_merge( $defaults['book_canceled'], is_array( $stored['book_canceled'] ?? null ) ? $stored['book_canceled'] : [] );
		$phones               = $stored['phones'] ?? [ '' ];
		if ( ! is_array( $phones ) || empty( $phones ) ) {
			$phones = [ '' ];
		}
		$out['phones']         = array_values( $phones );
		// null = never saved with the admin picker; legacy `phones` stay authoritative.
		$out['admin_ids']      = isset( $stored['admin_ids'] ) && is_array( $stored['admin_ids'] )
			? array_values( array_filter( array_map( 'absint', $stored['admin_ids'] ) ) )
			: null;
		$out['enabled']        = ! empty( $out['enabled'] ) ? 1 : 0;
		$out['income_enabled'] = ! empty( $out['income_enabled'] ) ? 1 : 0;
		$percent               = isset( $out['income_percent'] ) ? (float) $out['income_percent'] : 10.0;
		if ( $percent < 0 ) {
			$percent = 0;
		}
		if ( $percent > 100 ) {
			$percent = 100;
		}
		$out['income_percent'] = $percent;
		return $out;
	}

	public function register_menu() {
		$tabs = self::tabs();

		$this->hooks[] = add_menu_page(
			'تنظیمات سفارشی رزرو',
			'تنظیمات سفارشی رزرو',
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ],
			'dashicons-calendar-alt',
			58
		);
		// Same slug as the parent → replaces the auto-generated first submenu item.
		$this->hooks[] = add_submenu_page(
			self::PAGE,
			$tabs['sms'],
			$tabs['sms'],
			'manage_options',
			self::PAGE,
			[ $this, 'render_page' ]
		);
		$this->hooks[] = add_submenu_page(
			self::PAGE,
			$tabs['medyar-income'],
			$tabs['medyar-income'],
			'manage_options',
			self::PAGE_INCOME,
			[ $this, 'render_page' ]
		);
		$this->hooks = array_values( array_filter( $this->hooks ) );
	}

	public function register_settings() {
		register_setting(
			'medyar_admin_reserve_sms_group',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	/**
	 * @param mixed $input
	 * @return array
	 */
	public function sanitize( $input ) {
		$current = self::get_settings();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$tab = isset( $input['_tab'] ) ? sanitize_key( (string) $input['_tab'] ) : '';

		if ( $tab === 'medyar-income' ) {
			$percent = isset( $input['income_percent'] ) ? (float) $input['income_percent'] : (float) $current['income_percent'];
			if ( $percent < 0 ) {
				$percent = 0;
			}
			if ( $percent > 100 ) {
				$percent = 100;
			}
			$current['income_enabled'] = ! empty( $input['income_enabled'] ) ? 1 : 0;
			$current['income_percent'] = $percent;
			return $current;
		}

		// Default / SMS tab — preserve income keys.
		$admin_ids = [];
		if ( ! empty( $input['admin_ids'] ) && is_array( $input['admin_ids'] ) ) {
			$admin_ids = array_values( array_unique( array_filter( array_map( 'absint', $input['admin_ids'] ) ) ) );
		}

		$defaults = self::defaults();
		$current['enabled']       = ! empty( $input['enabled'] ) ? 1 : 0;
		$current['admin_ids']     = $admin_ids;
		$current['book']          = [
			'pattern' => sanitize_text_field( $input['book']['pattern'] ?? '' ),
			'message' => $this->sanitize_message( $input['book']['message'] ?? $defaults['book']['message'] ),
		];
		$current['book_canceled'] = [
			'pattern' => sanitize_text_field( $input['book_canceled']['pattern'] ?? '' ),
			'message' => $this->sanitize_message( $input['book_canceled']['message'] ?? $defaults['book_canceled']['message'] ),
		];
		return $current;
	}

	/**
	 * Administrators available in the SMS recipient picker.
	 *
	 * @return WP_User[]
	 */
	public static function admin_users(): array {
		static $users = null;
		if ( null === $users ) {
			$users = get_users(
				[
					'role'    => 'administrator',
					'orderby' => 'display_name',
					'order'   => 'ASC',
					'number'  => 200,
				]
			);
		}
		return $users;
	}

	/**
	 * Normalized 09xxxxxxxxx mobile for a user, or ''.
	 *
	 * @param WP_User $user User.
	 */
	public static function user_mobile( WP_User $user ): string {
		$candidates = [
			(string) get_user_meta( $user->ID, 'billing_phone', true ),
			(string) $user->user_login,
			(string) get_user_meta( $user->ID, 'user_phone', true ),
		];
		foreach ( $candidates as $raw ) {
			$digits = preg_replace(
				'/\D/',
				'',
				str_replace(
					[ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ],
					[ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ],
					$raw
				)
			);
			if ( preg_match( '/^(0?9\d{9}|989\d{9})$/', (string) $digits ) ) {
				if ( strpos( $digits, '98' ) === 0 && strlen( $digits ) === 12 ) {
					$digits = '0' . substr( $digits, 2 );
				} elseif ( strpos( $digits, '9' ) === 0 && strlen( $digits ) === 10 ) {
					$digits = '0' . $digits;
				}
				return $digits;
			}
		}
		return '';
	}

	/**
	 * Admin IDs to pre-check in the picker (maps legacy phones on first view).
	 *
	 * @param array $settings Settings.
	 * @return int[]
	 */
	public static function selected_admin_ids( array $settings ): array {
		if ( is_array( $settings['admin_ids'] ?? null ) ) {
			return $settings['admin_ids'];
		}
		$legacy = array_filter( array_map( static fn( $p ) => preg_replace( '/\D+/', '', (string) $p ), (array) ( $settings['phones'] ?? [] ) ) );
		$ids    = [];
		foreach ( self::admin_users() as $user ) {
			$mobile = self::user_mobile( $user );
			if ( $mobile !== '' && in_array( $mobile, $legacy, true ) ) {
				$ids[] = (int) $user->ID;
			}
		}
		return $ids;
	}

	/**
	 * Mobiles that receive admin reserve SMS.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function recipient_phones( array $settings ): array {
		if ( ! is_array( $settings['admin_ids'] ?? null ) ) {
			return array_values( array_filter( array_map( 'strval', (array) ( $settings['phones'] ?? [] ) ) ) );
		}
		$phones = [];
		foreach ( $settings['admin_ids'] as $uid ) {
			$user = get_user_by( 'id', (int) $uid );
			if ( ! $user instanceof WP_User ) {
				continue;
			}
			$mobile = self::user_mobile( $user );
			if ( $mobile !== '' ) {
				$phones[] = $mobile;
			}
		}
		return array_values( array_unique( $phones ) );
	}

	/**
	 * @param string $message
	 * @return string
	 */
	private function sanitize_message( $message ) {
		$message = (string) $message;
		$message = wp_strip_all_tags( $message );
		$message = preg_replace( '/\s+/', '', $message );
		return $message;
	}

	/**
	 * @param string $hook
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}

		$deps = [];
		if ( wp_style_is( 'arp-admin', 'registered' ) || defined( 'ARP_PLUGIN_URL' ) ) {
			$url = defined( 'ARP_PLUGIN_URL' ) ? ARP_PLUGIN_URL . 'assets/css/admin.css' : '';
			if ( $url !== '' && ! wp_style_is( 'arp-admin', 'registered' ) ) {
				wp_register_style( 'arp-admin', $url, [], defined( 'ARP_VERSION' ) ? ARP_VERSION : '1.0' );
			}
			if ( wp_style_is( 'arp-admin', 'registered' ) ) {
				wp_enqueue_style( 'arp-admin' );
				$deps[] = 'arp-admin';
			}
		}

		wp_enqueue_style(
			'mmad-admin-reserve-sms',
			MMAD_PLUGIN_URL . 'assets/css/admin-reserve-sms.css',
			$deps,
			MMAD_VERSION
		);

		$tab = self::current_tab();
		if ( $tab === 'sms' ) {
			wp_enqueue_script(
				'mmad-admin-reserve-sms',
				MMAD_PLUGIN_URL . 'assets/js/admin-reserve-sms.js',
				[ 'jquery' ],
				MMAD_VERSION,
				true
			);
		}
		if ( $tab === 'medyar-income' ) {
			wp_enqueue_script(
				'mmad-admin-reserve-income',
				MMAD_PLUGIN_URL . 'assets/js/admin-reserve-income.js',
				[],
				MMAD_VERSION,
				true
			);
		}
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get_settings();
		$tab      = self::current_tab();
		$tabs     = self::tabs();
		$vars     = class_exists( '\DrPlus\Utils\SMS' )
			? \DrPlus\Utils\SMS::reserve_variables()
			: [
				'book_id'         => 'شناسه رزرو',
				'specialist_name' => 'نام متخصص',
				'patient_name'    => 'نام بیمار',
				'patient_mobile'  => 'موبایل بیمار',
				'visit_date'      => 'تاریخ ویزیت',
				'visit_time'      => 'ساعت ویزیت',
				'office'          => 'مطب',
			];
		$income_report = ( $tab === 'medyar-income' )
			? self::get_income_report( self::parse_income_filters( $_GET ), 20 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: null;
		$admin_users   = ( $tab === 'sms' ) ? self::admin_users() : [];
		include MMAD_PLUGIN_DIR . 'includes/admin-page-reserve-sms.php';
	}

	/**
	 * Apply Medyar fee to a gross specialist payout amount.
	 *
	 * @param int|float      $gross_amount Gross specialist income about to be deposited.
	 * @param WC_Order|null  $order        Order for meta persistence (optional).
	 * @return int Net amount to deposit.
	 */
	public static function apply_income_fee( $gross_amount, $order = null ): int {
		$gross = (int) max( 0, round( (float) $gross_amount ) );
		if ( $gross <= 0 ) {
			return 0;
		}

		if ( $order instanceof WC_Order ) {
			$existing_fee = (int) $order->get_meta( self::META_FEE_AMOUNT );
			$reversed     = (string) $order->get_meta( self::META_FEE_REVERSED ) === '1';
			if ( $existing_fee > 0 && ! $reversed ) {
				$stored_gross = (int) $order->get_meta( self::META_FEE_GROSS );
				if ( $stored_gross > 0 ) {
					return (int) max( 0, $stored_gross - $existing_fee );
				}
				return (int) max( 0, $gross - $existing_fee );
			}
		}

		$settings = self::get_settings();
		if ( empty( $settings['income_enabled'] ) ) {
			return $gross;
		}

		$percent = (float) $settings['income_percent'];
		if ( $percent <= 0 ) {
			return $gross;
		}
		if ( $percent > 100 ) {
			$percent = 100;
		}

		$fee = (int) max( 0, round( $gross * ( $percent / 100 ) ) );
		if ( $fee > $gross ) {
			$fee = $gross;
		}
		$net = (int) max( 0, $gross - $fee );

		if ( $order instanceof WC_Order && $fee > 0 ) {
			$order->update_meta_data( self::META_FEE_AMOUNT, $fee );
			$order->update_meta_data( self::META_FEE_PERCENT, $percent );
			$order->update_meta_data( self::META_FEE_GROSS, $gross );
			$order->update_meta_data( self::META_FEE_REVERSED, '0' );
			$commission = self::resolve_booking_commission( $order );
			if ( $commission > 0 ) {
				$order->update_meta_data( self::META_COMMISSION, $commission );
			}
			$order->save();
		}

		return $net;
	}

	/**
	 * Platform commission charged on the booking (customer fee / platform share).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $book  Optional booking data.
	 */
	public static function resolve_booking_commission( $order, array $book = [] ): int {
		if ( $order instanceof WC_Order ) {
			$stored = (int) $order->get_meta( self::META_COMMISSION );
			if ( $stored > 0 ) {
				return $stored;
			}
			if ( empty( $book ) ) {
				$raw = $order->get_meta( '_booking_data' );
				$book = is_array( $raw ) ? $raw : [];
			}
		}
		if ( isset( $book['commission_value'] ) ) {
			return (int) max( 0, round( (float) $book['commission_value'] ) );
		}
		if ( isset( $book['commission'] ) ) {
			return (int) max( 0, round( (float) $book['commission'] ) );
		}
		return 0;
	}

	/**
	 * @param int               $income
	 * @param WC_Order          $order
	 * @param array<string,mixed> $book_data
	 * @return int
	 */
	public function filter_mtw_payout_amount( $income, $order, $book_data = [] ) {
		unset( $book_data );
		$order_obj = $order instanceof WC_Order ? $order : null;
		return self::apply_income_fee( $income, $order_obj );
	}

	/**
	 * Reduce Sheyda booking_payout ledger amount before balance is increased.
	 *
	 * @param array<string,mixed> $info
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function filter_sheyda_booking_payout( $info, $data ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}
		$amount = isset( $info['amount'] ) ? (float) $info['amount'] : 0.0;
		if ( $amount <= 0 ) {
			return $info;
		}

		$order = self::resolve_order_from_ledger_context( $info, is_array( $data ) ? $data : [] );
		$info['amount'] = self::apply_income_fee( $amount, $order );
		return $info;
	}

	/**
	 * @param array<string,mixed> $info
	 * @param array<string,mixed> $data
	 * @return WC_Order|null
	 */
	private static function resolve_order_from_ledger_context( array $info, array $data ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$candidates = [
			$data['related_id'] ?? null,
			$info['related_id'] ?? null,
			$data['order_id'] ?? null,
			$info['order_id'] ?? null,
		];
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$candidates[] = $data['meta']['order_id'] ?? null;
		}
		if ( isset( $info['meta'] ) && is_array( $info['meta'] ) ) {
			$candidates[] = $info['meta']['order_id'] ?? null;
		}
		foreach ( $candidates as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Build one income report row from an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,mixed>|null
	 */
	public static function build_income_row( WC_Order $order ): ?array {
		if ( (string) $order->get_meta( self::META_FEE_REVERSED ) === '1' ) {
			return null;
		}

		$fee   = (int) $order->get_meta( self::META_FEE_AMOUNT );
		$gross = (int) $order->get_meta( self::META_FEE_GROSS );
		$pct   = (float) $order->get_meta( self::META_FEE_PERCENT );
		$book  = $order->get_meta( '_booking_data' );
		$book  = is_array( $book ) ? $book : [];
		$commission = self::resolve_booking_commission( $order, $book );

		if ( $fee <= 0 && $commission <= 0 ) {
			return null;
		}

		if ( $gross <= 0 && isset( $book['specialist_income'] ) ) {
			$gross = (int) max( 0, round( (float) $book['specialist_income'] ) );
		}
		$net = $fee > 0 ? max( 0, $gross - $fee ) : $gross;
		if ( $net <= 0 && isset( $book['specialist_income'] ) && $fee <= 0 ) {
			$net = (int) max( 0, round( (float) $book['specialist_income'] ) );
		}

		$calc_type = (string) ( $book['commission_calculate_type'] ?? '' );
		$calc_label = '';
		if ( $calc_type === 'add_to_customer_order' ) {
			$calc_label = 'اضافه به سفارش بیمار';
		} elseif ( $calc_type === 'deduction_from_specialist_income' ) {
			$calc_label = 'کسر از درآمد متخصص';
		}

		$visit_price = isset( $book['visit_price'] ) ? (int) round( (float) $book['visit_price'] ) : 0;
		$total_price = isset( $book['total_price'] ) ? (int) round( (float) $book['total_price'] ) : (int) round( (float) $order->get_total() );

		$patient = trim( (string) ( $book['first_name'] ?? '' ) . ' ' . (string) ( $book['last_name'] ?? '' ) );
		if ( $patient === '' ) {
			$patient = trim( $order->get_formatted_billing_full_name() );
		}

		$specialist = (string) ( $book['specialist_name'] ?? '' );
		$office     = (string) ( $book['office_name'] ?? ( $book['office'] ?? '' ) );
		$visit_type = self::resolve_visit_type( $book );
		$visit_date = (string) ( $book['date'] ?? ( $book['visit_date'] ?? '' ) );
		$visit_time = (string) ( $book['time'] ?? ( $book['visit_time'] ?? ( $book['start_time'] ?? '' ) ) );

		$status = $order->get_status();
		$status_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status;

		return [
			'order_id'           => (int) $order->get_id(),
			'order_url'          => admin_url( 'post.php?post=' . (int) $order->get_id() . '&action=edit' ),
			'book_id'            => (int) ( $book['book_id'] ?? 0 ),
			'gross'              => $gross,
			'percent'            => $pct,
			'fee'                => $fee,
			'commission'         => $commission,
			'total_income'       => $fee + $commission,
			'net'                => $net,
			'visit_price'        => $visit_price,
			'total_price'        => $total_price,
			'calc_type'          => $calc_type,
			'calc_label'         => $calc_label,
			'patient_name'       => $patient,
			'patient_mobile'     => (string) ( $book['phone'] ?? $order->get_billing_phone() ),
			'specialist_name'    => $specialist,
			'specialist_subtitle' => (string) ( $book['specialist_subtitle'] ?? '' ),
			'specialist_id'      => (int) ( $book['specialist_id'] ?? 0 ),
			'office'             => $office,
			'visit_type'         => $visit_type['key'],
			'visit_type_label'   => $visit_type['label'],
			'appointment_url'    => (int) ( $book['book_id'] ?? 0 ) > 0
				? admin_url( 'admin.php?page=appointments&tab=view&book_id=' . (int) $book['book_id'] )
				: '',
			'created_ts'         => $order->get_date_created() ? (int) $order->get_date_created()->getTimestamp() : 0,
			'visit_date'         => $visit_date,
			'visit_time'         => $visit_time,
			'order_status'       => $status,
			'order_status_label' => (string) $status_label,
			'payment_method'     => (string) $order->get_payment_method_title(),
			'date'               => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
			'reversed'           => 0,
		];
	}

	/**
	 * Visit type options: in-person + theme consultation offices.
	 *
	 * @return array<string,string>
	 */
	public static function visit_type_options(): array {
		$options = [ 'in_person' => 'ویزیت حضوری' ];
		if ( class_exists( '\DrPlus\Utils\Booking' ) && method_exists( '\DrPlus\Utils\Booking', 'consultation_offices' ) ) {
			foreach ( (array) \DrPlus\Utils\Booking::consultation_offices() as $key => $data ) {
				$options[ (string) $key ] = (string) ( $data['label'] ?? $key );
			}
		}
		return $options;
	}

	/**
	 * @param array $book Booking data.
	 * @return array{key:string,label:string}
	 */
	public static function resolve_visit_type( array $book ): array {
		$options   = self::visit_type_options();
		$office_id = (string) ( $book['office_id'] ?? '' );
		if ( $office_id !== '' && $office_id !== 'in_person' && isset( $options[ $office_id ] ) ) {
			return [
				'key'   => $office_id,
				'label' => $options[ $office_id ],
			];
		}
		return [
			'key'   => 'in_person',
			'label' => $options['in_person'],
		];
	}

	/**
	 * Read income filters from the request.
	 *
	 * @param array $src Raw source (usually $_GET).
	 * @return array{s:string,specialist:int,visit_type:string,status:string,source:string,period:string,paged:int}
	 */
	public static function parse_income_filters( array $src ): array {
		$periods  = array_keys( self::income_period_options() );
		$sources  = [ '', 'commission', 'fee', 'both' ];
		$period   = isset( $src['period'] ) ? sanitize_key( wp_unslash( (string) $src['period'] ) ) : '';
		$source   = isset( $src['source'] ) ? sanitize_key( wp_unslash( (string) $src['source'] ) ) : '';
		$visit    = isset( $src['visit_type'] ) ? sanitize_key( wp_unslash( (string) $src['visit_type'] ) ) : '';
		$status   = isset( $src['order_status'] ) ? sanitize_key( wp_unslash( (string) $src['order_status'] ) ) : '';
		$visit_ok = array_map( 'sanitize_key', array_keys( self::visit_type_options() ) );

		return [
			's'          => isset( $src['s'] ) ? sanitize_text_field( wp_unslash( (string) $src['s'] ) ) : '',
			'specialist' => isset( $src['specialist'] ) ? absint( $src['specialist'] ) : 0,
			'visit_type' => in_array( $visit, $visit_ok, true ) ? $visit : '',
			'status'     => $status,
			'source'     => in_array( $source, $sources, true ) ? $source : '',
			'period'     => in_array( $period, $periods, true ) ? $period : '',
			'paged'      => isset( $src['paged'] ) ? max( 1, absint( $src['paged'] ) ) : 1,
		];
	}

	/**
	 * @return array<string,string>
	 */
	public static function income_period_options(): array {
		return [
			''      => 'همه زمان‌ها',
			'today' => 'امروز',
			'7d'    => '۷ روز اخیر',
			'30d'   => '۳۰ روز اخیر',
			'90d'   => '۹۰ روز اخیر',
			'365d'  => 'یک سال اخیر',
		];
	}

	/**
	 * @param string $period Period key.
	 */
	private static function period_start_ts( string $period ): int {
		$now = current_time( 'timestamp', true );
		switch ( $period ) {
			case 'today':
				$tz    = wp_timezone();
				$start = new DateTimeImmutable( 'today', $tz );
				return $start->getTimestamp();
			case '7d':
				return $now - 7 * DAY_IN_SECONDS;
			case '30d':
				return $now - 30 * DAY_IN_SECONDS;
			case '90d':
				return $now - 90 * DAY_IN_SECONDS;
			case '365d':
				return $now - 365 * DAY_IN_SECONDS;
		}
		return 0;
	}

	/**
	 * @param array $row     Income row.
	 * @param array $filters Parsed filters.
	 */
	private static function row_matches_filters( array $row, array $filters ): bool {
		if ( $filters['specialist'] > 0 && (int) $row['specialist_id'] !== $filters['specialist'] ) {
			return false;
		}
		if ( $filters['visit_type'] !== '' && sanitize_key( (string) $row['visit_type'] ) !== $filters['visit_type'] ) {
			return false;
		}
		if ( $filters['status'] !== '' && (string) $row['order_status'] !== $filters['status'] ) {
			return false;
		}
		switch ( $filters['source'] ) {
			case 'commission':
				if ( (int) $row['commission'] <= 0 ) {
					return false;
				}
				break;
			case 'fee':
				if ( (int) $row['fee'] <= 0 ) {
					return false;
				}
				break;
			case 'both':
				if ( (int) $row['commission'] <= 0 || (int) $row['fee'] <= 0 ) {
					return false;
				}
				break;
		}
		if ( $filters['s'] !== '' ) {
			$needle   = ltrim( $filters['s'], '#' );
			$haystack = implode(
				' ',
				[
					(string) $row['order_id'],
					(string) $row['book_id'],
					(string) $row['patient_name'],
					(string) $row['patient_mobile'],
					(string) $row['specialist_name'],
				]
			);
			if ( function_exists( 'mb_stripos' ) ? mb_stripos( $haystack, $needle ) === false : stripos( $haystack, $needle ) === false ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array $filters  Parsed filters (see parse_income_filters()).
	 * @param int   $per_page Rows per page; 0 returns all rows.
	 * @return array<string,mixed>
	 */
	public static function get_income_report( array $filters = [], int $per_page = 20 ): array {
		$filters = array_merge( self::parse_income_filters( [] ), $filters );

		$out = [
			'total_fee'        => 0,
			'total_commission' => 0,
			'total_income'     => 0,
			'total_net'        => 0,
			'count'            => 0,
			'rows'             => [],
			'paged'            => $filters['paged'],
			'pages'            => 1,
			'per_page'         => $per_page,
			'specialists'      => [],
			'statuses'         => [],
			'filters'          => $filters,
		];

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}

		$query = [
			'limit'      => -1,
			'return'     => 'ids',
			'orderby'    => 'ID',
			'order'      => 'DESC',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				[
					'key'     => self::META_FEE_AMOUNT,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				],
				[
					'key'     => self::META_COMMISSION,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				],
			],
		];
		$start = self::period_start_ts( $filters['period'] );
		if ( $start > 0 ) {
			$query['date_created'] = '>=' . $start;
		}

		$all_ids = wc_get_orders( $query );
		if ( ! is_array( $all_ids ) ) {
			$all_ids = [];
		}

		$matched = [];
		foreach ( $all_ids as $oid ) {
			$order = wc_get_order( (int) $oid );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$book = $order->get_meta( '_booking_data' );
			if ( ! is_array( $book ) || empty( $book ) ) {
				continue;
			}
			$row = self::build_income_row( $order );
			if ( null === $row || ( (int) $row['fee'] <= 0 && (int) $row['commission'] <= 0 ) ) {
				continue;
			}

			// Filter dropdown sources come from the unfiltered (period-scoped) set.
			if ( (int) $row['specialist_id'] > 0 && (string) $row['specialist_name'] !== '' ) {
				$out['specialists'][ (int) $row['specialist_id'] ] = (string) $row['specialist_name'];
			}
			$out['statuses'][ (string) $row['order_status'] ] = (string) $row['order_status_label'];

			if ( ! self::row_matches_filters( $row, $filters ) ) {
				continue;
			}

			$out['total_fee']        += (int) $row['fee'];
			$out['total_commission'] += (int) $row['commission'];
			$out['total_income']     += (int) $row['total_income'];
			$out['total_net']        += (int) $row['net'];
			$matched[]                = $row;
		}

		asort( $out['specialists'], SORT_NATURAL );
		$out['count'] = count( $matched );

		if ( $per_page > 0 ) {
			$out['pages'] = max( 1, (int) ceil( $out['count'] / $per_page ) );
			$out['paged'] = min( $filters['paged'], $out['pages'] );
			$out['rows']  = array_slice( $matched, ( $out['paged'] - 1 ) * $per_page, $per_page );
		} else {
			$out['rows'] = $matched;
		}

		return $out;
	}

	/**
	 * Stream filtered income rows as CSV.
	 */
	public function export_income_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), 403 );
		}
		check_admin_referer( 'mmad_income_export' );

		$filters = self::parse_income_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report  = self::get_income_report( $filters, 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=medyar-income-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv(
			$out,
			[ 'سفارش', 'رزرو', 'نام مراجع', 'موبایل مراجع', 'نام متخصص', 'نوع ویزیت', 'وضعیت', 'کمیسیون بیمار', 'کارمزد واریز', 'درصد کارمزد', 'جمع درآمد مدیار', 'واریز متخصص', 'تاریخ' ]
		);
		foreach ( $report['rows'] as $row ) {
			fputcsv(
				$out,
				[
					$row['order_id'],
					$row['book_id'],
					$row['patient_name'],
					$row['patient_mobile'],
					$row['specialist_name'],
					$row['visit_type_label'],
					$row['order_status_label'],
					$row['commission'],
					$row['fee'],
					$row['percent'],
					$row['total_income'],
					$row['net'],
					$row['date'],
				]
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Persist booking platform commission for Medyar income reporting.
	 *
	 * @param int $order_id Order ID.
	 */
	public function snapshot_booking_commission( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$book = $order->get_meta( '_booking_data' );
		if ( ! is_array( $book ) || empty( $book ) ) {
			return;
		}
		if ( (string) $order->get_meta( self::META_FEE_REVERSED ) === '1' ) {
			return;
		}
		$commission = 0;
		if ( isset( $book['commission_value'] ) ) {
			$commission = (int) max( 0, round( (float) $book['commission_value'] ) );
		} elseif ( isset( $book['commission'] ) ) {
			$commission = (int) max( 0, round( (float) $book['commission'] ) );
		}
		if ( $commission <= 0 ) {
			return;
		}
		$existing = (int) $order->get_meta( self::META_COMMISSION );
		if ( $existing === $commission ) {
			return;
		}
		$order->update_meta_data( self::META_COMMISSION, $commission );
		$order->save();
	}

	/**
	 * @param int    $order_id
	 * @param string $from
	 * @param string $to
	 * @param mixed  $order
	 */
	public function snapshot_booking_commission_on_status( $order_id, $from = '', $to = '', $order = null ): void {
		$to = is_string( $to ) ? str_replace( 'wc-', '', $to ) : '';
		if ( $to !== 'completed' ) {
			return;
		}
		$this->snapshot_booking_commission( $order instanceof WC_Order ? $order->get_id() : $order_id );
	}

	/**
	 * @param int    $order_id
	 * @param string $from
	 * @param string $to
	 */
	public function on_order_status( $order_id, $from = '', $to = '' ) {
		if ( ! class_exists( '\DrPlus\Utils\Booking' ) ) {
			return;
		}
		if ( $to && ! in_array( $to, \DrPlus\Utils\Booking::success_statuses(), true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$book_data = $order->get_meta( '_booking_data' );
		if ( empty( $book_data ) || ! is_array( $book_data ) ) {
			return;
		}
		$lock = 'medyar_admin_book_sms_' . (int) $order_id;
		if ( get_transient( $lock ) || $order->get_meta( '_medyar_admin_book_sms_sent' ) ) {
			return;
		}
		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );
		$this->send_event( 'book', $book_data );
		$order->update_meta_data( '_medyar_admin_book_sms_sent', '1' );
		$order->save();
	}

	/**
	 * @param array $book_data
	 */
	public function on_cancel( $book_data ) {
		if ( empty( $book_data ) || ! is_array( $book_data ) ) {
			return;
		}
		$order_id = (int) ( $book_data['order_id'] ?? 0 );
		if ( $order_id > 0 ) {
			$lock = 'medyar_admin_cancel_sms_' . $order_id;
			if ( get_transient( $lock ) ) {
				return;
			}
			set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );
		}
		$this->send_event( 'book_canceled', $book_data );
	}

	/**
	 * @param string $event book|book_canceled
	 * @param array  $book_data
	 */
	public function send_event( $event, array $book_data ) {
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		if ( $event !== 'book' && $event !== 'book_canceled' ) {
			return;
		}
		$pattern = (string) ( $settings[ $event ]['pattern'] ?? '' );
		$message = (string) ( $settings[ $event ]['message'] ?? '' );
		$phones  = self::recipient_phones( $settings );
		if ( $pattern === '' || $message === '' || empty( $phones ) ) {
			return;
		}

		$vars  = $this->build_vars( $book_data );
		$attrs = $this->build_attrs( $message, $vars );
		if ( empty( $attrs ) ) {
			return;
		}

		if ( class_exists( 'MSQ_Client' ) && MSQ_Client::is_ready() ) {
			$book_id = (string) ( $vars['book_id'] ?? ( $book_data['book_id'] ?? '' ) );
			foreach ( $phones as $phone ) {
				MSQ_Client::enqueue(
					[
						'channel'    => 'booking',
						'event'      => 'reserve.admin.' . $event,
						'transport'  => 'mmad_pattern',
						'phone'      => $phone,
						'body'       => '[admin ' . $event . '] رزرو #' . $book_id,
						'pattern'    => $pattern,
						'data'       => [ 'attrs' => $attrs ],
						'priority'   => MSQ_Client::PRIORITY_ADMIN,
						'dedupe_key' => 'booking:admin:' . $event . ':' . $book_id . ':' . preg_replace( '/\D+/', '', (string) $phone ),
					]
				);
			}
			return;
		}

		$GLOBALS['medyar_reserve_sending_admin'] = true;
		$prev_block = $GLOBALS['medyar_block_sms_http'] ?? null;
		$GLOBALS['medyar_block_sms_http'] = false;

		try {
			foreach ( $phones as $phone ) {
				$phone = preg_replace( '/\D+/', '', $phone );
				if ( $phone === '' ) {
					continue;
				}
				$this->send_pattern( $phone, $pattern, $attrs );
			}
		} finally {
			$GLOBALS['medyar_reserve_sending_admin'] = false;
			if ( $prev_block !== null ) {
				$GLOBALS['medyar_block_sms_http'] = $prev_block;
			} else {
				unset( $GLOBALS['medyar_block_sms_http'] );
			}
		}
	}

	public function register_queue_transport(): void {
		MSQ_Transports::register( 'mmad_pattern', [ $this, 'send_queued_job' ] );
	}

	/**
	 * medyar-sms-queue worker: send one queued admin reserve SMS via the theme Faraz gateway.
	 *
	 * @param array $job Decoded queue job.
	 * @return array{ok:bool,detail:string,retry:bool}
	 */
	public function send_queued_job( array $job ): array {
		$attrs   = isset( $job['payload']['data']['attrs'] ) && is_array( $job['payload']['data']['attrs'] ) ? $job['payload']['data']['attrs'] : [];
		$pattern = (string) ( $job['pattern_code'] ?? '' );
		if ( $pattern === '' || empty( $attrs ) ) {
			return [ 'ok' => false, 'detail' => 'missing_pattern_or_attrs', 'retry' => false ];
		}
		if ( ! class_exists( '\DrPlus\SMS\FarazsmsNew' ) ) {
			return [ 'ok' => false, 'detail' => 'drplus_faraz_gateway_missing', 'retry' => true ];
		}

		$GLOBALS['medyar_reserve_sending_admin'] = true;
		$prev_block = $GLOBALS['medyar_block_sms_http'] ?? null;
		$GLOBALS['medyar_block_sms_http'] = false;
		try {
			$ok = $this->send_pattern( (string) $job['phone'], $pattern, $attrs );
		} finally {
			$GLOBALS['medyar_reserve_sending_admin'] = false;
			if ( $prev_block !== null ) {
				$GLOBALS['medyar_block_sms_http'] = $prev_block;
			} else {
				unset( $GLOBALS['medyar_block_sms_http'] );
			}
		}
		return [ 'ok' => $ok, 'detail' => $ok ? 'success' : 'faraz_pattern_failed', 'retry' => true ];
	}

	/**
	 * @param array $book_data
	 * @return array
	 */
	private function build_vars( array $book_data ) {
		if ( class_exists( '\DrPlus\Utils\Booking' ) && method_exists( '\DrPlus\Utils\Booking', 'prepare_reserve_sms_context' ) ) {
			[ $sms_vars ] = \DrPlus\Utils\Booking::prepare_reserve_sms_context( $book_data, null );
			if ( is_array( $sms_vars ) ) {
				return $sms_vars;
			}
		}
		return [
			'book_id'            => $book_data['book_id'] ?? '',
			'specialist_name'    => $book_data['specialist_name'] ?? '',
			'specialist_mobile'  => $book_data['specialist_mobile'] ?? '',
			'patient_name'       => trim( ( $book_data['first_name'] ?? '' ) . ' ' . ( $book_data['last_name'] ?? '' ) ),
			'patient_first_name' => $book_data['first_name'] ?? '',
			'patient_last_name'  => $book_data['last_name'] ?? '',
			'patient_mobile'     => $book_data['phone'] ?? '',
			'visit_date'         => $book_data['date'] ?? ( $book_data['visit_date'] ?? '' ),
			'visit_time'         => $book_data['time'] ?? ( $book_data['visit_time'] ?? '' ),
			'office'             => $book_data['office'] ?? '',
		];
	}

	/**
	 * @param string $template
	 * @param array  $vars
	 * @return array
	 */
	private function build_attrs( $template, array $vars ) {
		$attrs = [];
		foreach ( explode( ';', (string) $template ) as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			$bits = explode( ':', $part, 2 );
			$key  = trim( $bits[0] );
			$val  = isset( $bits[1] ) ? trim( $bits[1] ) : '';
			$val  = preg_replace_callback(
				'/\{([a-zA-Z0-9_]+)\}/',
				static function ( $m ) use ( $vars ) {
					return isset( $vars[ $m[1] ] ) ? (string) $vars[ $m[1] ] : '';
				},
				$val
			);
			if ( $key !== '' ) {
				$attrs[ $key ] = sanitize_text_field( $val );
			}
		}
		return $attrs;
	}

	/**
	 * @param string $phone
	 * @param string $pattern
	 * @param array  $attrs
	 * @return bool
	 */
	private function send_pattern( $phone, $pattern, array $attrs ) {
		if ( ! class_exists( '\DrPlus\SMS\FarazsmsNew' ) ) {
			return false;
		}
		try {
			$gw = new \DrPlus\SMS\FarazsmsNew();
			if ( class_exists( '\DrPlus\Utils\Sanitizers' ) ) {
				$phone = \DrPlus\Utils\Sanitizers::phone( $phone );
			}
			$gw->set_data( [
				'to'               => [ $phone ],
				'exploded_pattern' => $attrs,
			] );
			$ret = $gw->_send_by_pattern( $pattern );
			if ( is_wp_error( $ret ) ) {
				return false;
			}
			$ref = new \ReflectionObject( $gw );
			while ( $ref && ! $ref->hasProperty( 'wp_error' ) ) {
				$ref = $ref->getParentClass();
			}
			if ( $ref ) {
				$p = $ref->getProperty( 'wp_error' );
				$p->setAccessible( true );
				$err = $p->getValue( $gw );
				if ( is_wp_error( $err ) ) {
					return false;
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
