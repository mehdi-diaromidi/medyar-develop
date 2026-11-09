<?php
/**
 * WooCommerce My Account endpoint: invited colleagues + invite link for every user.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_MyAccount_Invited {

	public const ENDPOINT = 'invited-colleagues';

	public const FLUSH_OPTION = 'arp_invited_endpoint_flushed';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ), 0 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_wc_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ), 40 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'the_title', array( __CLASS__, 'endpoint_title' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'account_menu_styles' ), 31 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_theme_datepicker' ), 1000 );
	}

	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Register with Woo so is_wc_endpoint_url() and body classes work.
	 *
	 * @param array<string,string> $vars WC query vars.
	 * @return array<string,string>
	 */
	public static function add_wc_query_var( array $vars ): array {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	public static function maybe_flush_rewrites(): void {
		if ( get_option( self::FLUSH_OPTION ) === ARP_VERSION ) {
			return;
		}
		self::add_endpoint();
		flush_rewrite_rules( false );
		update_option( self::FLUSH_OPTION, ARP_VERSION, true );
	}

	/**
	 * Place after the affiliate-panel link (arp-panel).
	 *
	 * @param array<string,string> $items Menu.
	 * @return array<string,string>
	 */
	public static function add_menu_item( array $items ): array {
		if ( ! is_user_logged_in() ) {
			return $items;
		}
		unset( $items[ self::ENDPOINT ] );
		$new      = array();
		$inserted = false;
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'arp-panel' === $key ) {
				$new[ self::ENDPOINT ] = 'همکاران معرفی‌شده';
				$inserted              = true;
			}
		}
		if ( ! $inserted ) {
			$fallback = array();
			foreach ( $new as $key => $label ) {
				if ( ! $inserted && 'customer-logout' === $key ) {
					$fallback[ self::ENDPOINT ] = 'همکاران معرفی‌شده';
					$inserted                   = true;
				}
				$fallback[ $key ] = $label;
			}
			$new = $fallback;
		}
		if ( ! $inserted ) {
			$new[ self::ENDPOINT ] = 'همکاران معرفی‌شده';
		}
		return $new;
	}

	public static function endpoint_title( string $title, $id = 0 ): string {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT ) && in_the_loop() ) {
			return 'همکاران معرفی‌شده';
		}
		return $title;
	}

	public static function url(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( self::ENDPOINT ) : home_url( '/my-account/' . self::ENDPOINT . '/' );
	}

	public static function is_endpoint(): bool {
		global $wp;
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT ) ) {
			return true;
		}
		return isset( $wp->query_vars[ self::ENDPOINT ] );
	}

	public static function enqueue(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! self::is_endpoint() ) {
			return;
		}

		$css_path = ARP_PLUGIN_DIR . 'assets/css/public.css';
		$js_path  = ARP_PLUGIN_DIR . 'assets/js/public.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : ARP_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : ARP_VERSION;

		wp_enqueue_style( 'arp-public', ARP_PLUGIN_URL . 'assets/css/public.css', array(), $css_ver );

		$deps = array( 'jquery' );
		if ( function_exists( 'PW' ) && defined( 'PW_VERSION' ) ) {
			wp_enqueue_script( 'pw-datepicker-js', PW()->plugin_url( 'assets/js/persian-datepicker.min.js' ), array( 'jquery' ), PW_VERSION, true );
			$deps[] = 'pw-datepicker-js';
		}
		wp_enqueue_script( 'arp-public', ARP_PLUGIN_URL . 'assets/js/public.js', $deps, $js_ver, true );
		wp_add_inline_script(
			'arp-public',
			'window.arpPanel = window.arpPanel || ' . wp_json_encode(
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'listNonce' => wp_create_nonce( Arp_Ajax::LIST_NONCE ),
					'loginUrl'  => Arp_Affiliate_Access::login_url( self::url() ),
					'i18n'      => array(
						'network'   => 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.',
						'generic'   => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.',
						'expired'   => 'نشست شما به پایان رسیده است. لطفاً دوباره وارد شوید.',
						'forbidden' => 'اعتبار صفحه به پایان رسیده است. لطفاً صفحه را دوباره بارگذاری کنید.',
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The theme's babakhani picker redefines the global `persianDate` that Persian WooCommerce's picker needs.
	 */
	public static function dequeue_theme_datepicker(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! self::is_endpoint() || ! wp_script_is( 'pw-datepicker-js', 'enqueued' ) ) {
			return;
		}
		wp_dequeue_script( 'drplus-pdp' );
		wp_dequeue_script( 'drplus-pd' );
		wp_dequeue_style( 'drplus-pdp' );
	}

	/**
	 * Theme falls back to documentmoney for unknown endpoints; use user-group.
	 */
	public static function account_menu_styles(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		$handle = 'arp-account-menu-invited';
		wp_register_style( $handle, false, array(), ARP_VERSION );
		wp_enqueue_style( $handle );
		wp_add_inline_style(
			$handle,
			'.woocommerce-MyAccount-navigation-link--' . self::ENDPOINT . ' a > i:first-child::before{content:"\\e80b"}'
		);
	}

	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		include ARP_PLUGIN_DIR . 'public/views/myaccount/invited-colleagues.php';
	}
}
