<?php
/**
 * Login gate for affiliate signup page template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arp_Affiliate_Access {

	/** My Account menu key (links to the panel page, not a real endpoint). */
	private const ACCOUNT_ENDPOINT = 'arp-panel';

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		add_action( 'template_redirect', array( $this, 'gate_signup_page' ) );
		add_action( 'template_redirect', array( $this, 'gate_dashboard_page' ) );
		add_filter( 'drplus/account_menu_items', array( $this, 'header_account_item' ), 10, 2 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu_item' ), 20 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'account_menu_url' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'account_menu_styles' ) );
		add_filter( 'elementor/widget/render_content', array( $this, 'header_account_widget' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'header_account_widget_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'header_account_popover_styles' ) );
		add_action( 'drplus/auth/form/start', array( $this, 'auth_role_switch' ) );
		add_action( 'login_head', array( $this, 'auth_role_switch_styles' ) );
	}

	/**
	 * Slightly wider header account popover so longer labels (طرح همکاران) fit.
	 */
	public function header_account_popover_styles(): void {
		$css = 'ul.account-items.header-account-items.header-popover{min-width:310px;max-width:350px}';
		wp_register_style( 'arp-header-account-popover', false, array(), ARP_VERSION );
		wp_enqueue_style( 'arp-header-account-popover' );
		wp_add_inline_style( 'arp-header-account-popover', $css );
	}

	/**
	 * Guest "affiliate login" button next to the theme's account button (shown by CSS in the mobile off-canvas only).
	 *
	 * @param string $content Widget HTML.
	 * @param object $widget  Elementor widget instance.
	 */
	public function header_account_widget( $content, $widget ) {
		if ( is_user_logged_in() || ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'drplus_account_button' !== $widget->get_name() ) {
			return $content;
		}
		return $content . sprintf(
			'<a class="arp-header-affiliate" href="%1$s"><i class="drplus-icon-profile-2user" aria-hidden="true"></i><span>%2$s</span></a>',
			esc_url( self::login_url( Arp_Pages::dashboard_url() ) ),
			esc_html__( 'ورود / عضویت طرح همکاران', 'affiliate-referral' )
		);
	}

	public function header_account_widget_styles(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		// :has(> .guest-user-wrap) keeps the layout guest-only even if Elementor serves cached widget HTML.
		$box = '.e-off-canvas__content .elementor-widget-drplus_account_button .elementor-widget-container:has(> .guest-user-wrap):has(> .arp-header-affiliate)';
		$css = '.arp-header-affiliate{display:none}'
			. $box . '{display:flex;align-items:stretch;gap:8px;position:relative}'
			// Anchor the account popover to the whole row; the narrowed button is smaller than its min-width.
			. $box . '>.guest-user-wrap{flex:0 0 auto;width:auto;position:static}'
			. $box . '>.guest-user-wrap .header-account{width:auto!important;min-width:53px;justify-content:center}'
			. $box . '>.arp-header-affiliate{display:flex;order:-1;flex:1 1 auto;min-width:0;align-items:center;justify-content:center;gap:8px;padding:0 12px;background:#f7f7f7;border:1.25px solid #f7f7f7;border-radius:16px;box-sizing:border-box;color:var(--primary-100,#0272b1);font-size:13px;font-weight:600;line-height:1.4;white-space:nowrap;text-decoration:none;transition:background .15s ease,border-color .15s ease}'
			. $box . '>.arp-header-affiliate:hover,' . $box . '>.arp-header-affiliate:focus-visible{background:#e6f1f7;border-color:var(--primary-100,#0272b1)}'
			. $box . '>.arp-header-affiliate i{font-size:18px;line-height:1}'
			. $box . '>.arp-header-affiliate span{overflow:hidden;text-overflow:ellipsis}';
		wp_register_style( 'arp-header-affiliate', false, array(), ARP_VERSION );
		wp_enqueue_style( 'arp-header-affiliate' );
		wp_add_inline_style( 'arp-header-affiliate', $css );
	}

	/**
	 * Whether the theme's login page (?login=…) will return to the affiliate panel.
	 */
	private static function auth_targets_panel(): bool {
		$raw = isset( $_GET['redirect_to'] ) ? esc_url_raw( urldecode( wp_unslash( $_GET['redirect_to'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by esc_url_raw().
		if ( '' === $raw ) {
			return false;
		}
		$target = wp_parse_url( $raw );
		$panel  = wp_parse_url( Arp_Pages::dashboard_url() );
		return ( $target['host'] ?? '' ) === ( $panel['host'] ?? '' )
			&& untrailingslashit( $target['path'] ?? '' ) === untrailingslashit( $panel['path'] ?? '' );
	}

	/**
	 * "Regular user / affiliate" switch on the theme's login page. The theme's auth.js reads the
	 * redirect field once on load, so each option is a link that reloads with its own redirect_to.
	 */
	public function auth_role_switch(): void {
		$is_panel = self::auth_targets_panel();
		$base     = remove_query_arg( 'redirect_to', Arp_Pages::current_url() );
		$panel    = add_query_arg( 'redirect_to', rawurlencode( Arp_Pages::dashboard_url() ), $base );
		// Without an explicit target the theme falls back to the referer, i.e. this panel-bound page.
		$current  = $is_panel ? add_query_arg( 'redirect_to', rawurlencode( home_url( '/' ) ), $base ) : Arp_Pages::current_url();
		?>
		<div class="arp-auth-role" role="group" aria-label="نوع ورود">
			<a href="<?php echo esc_url( $current ); ?>" class="arp-auth-role__opt<?php echo $is_panel ? '' : ' is-active'; ?>"<?php echo $is_panel ? '' : ' aria-current="true"'; ?>>ورود کاربر</a>
			<a href="<?php echo esc_url( $panel ); ?>" class="arp-auth-role__opt<?php echo $is_panel ? ' is-active' : ''; ?>"<?php echo $is_panel ? ' aria-current="true"' : ''; ?>>ورود طرح همکاران</a>
		</div>
		<?php if ( $is_panel ) : ?>
			<p class="arp-auth-role__hint">پس از ورود یا عضویت، به پنل طرح همکاران منتقل می‌شوید.</p>
		<?php endif; ?>
		<?php
	}

	public function auth_role_switch_styles(): void {
		if ( ! isset( $_GET['login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		?>
		<style id="arp-auth-role-css">
			.arp-auth-role{display:flex;gap:4px;margin:0 0 18px;padding:4px;background:var(--gray-100,#f3f4f6);border-radius:14px}
			.arp-auth-role__opt{flex:1;padding:9px 12px;border-radius:10px;font-size:14px;font-weight:600;line-height:1.6;text-align:center;text-decoration:none!important;color:var(--text-main,#383838)!important;transition:background .15s ease,color .15s ease,box-shadow .15s ease}
			.arp-auth-role__opt:hover{color:var(--primary-100,#0272b1)!important}
			.arp-auth-role__opt.is-active{background:#fff;color:var(--primary-100,#0272b1)!important;box-shadow:0 1px 4px rgba(17,75,95,.12)}
			.arp-auth-role__hint{margin:-8px 0 16px;font-size:12px;color:var(--primary-100,#0272b1)}
			@media (prefers-reduced-motion:reduce){.arp-auth-role__opt{transition:none}}
		</style>
		<?php
	}

	/**
	 * Affiliate entry in the theme's header account popover (guest + logged-in).
	 *
	 * @param mixed $items     Theme items (label/link are printed unescaped by the theme).
	 * @param mixed $logged_in Whether the visitor is logged in.
	 * @return mixed
	 */
	public function header_account_item( $items, $logged_in ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		$panel = Arp_Pages::dashboard_url();
		if ( ! $logged_in ) {
			$items['affiliate'] = array(
				'label' => esc_html__( 'ورود / عضویت طرح همکاران', 'affiliate-referral' ),
				'link'  => esc_url( self::login_url( $panel ) ),
				'icon'  => 'drplus-icon-profile-2user',
			);
			return $items;
		}
		return self::insert_before_logout(
			$items,
			'affiliate',
			array(
				'label' => esc_html( self::panel_label() ),
				'link'  => esc_url( $panel ),
				'icon'  => 'drplus-icon-profile-2user',
			),
			static function ( $item ) {
				return is_array( $item ) && isset( $item['icon'] ) && 'drplus-icon-logout' === $item['icon'];
			}
		);
	}

	/**
	 * "Request / open affiliate panel" in the WooCommerce My Account menu.
	 *
	 * @param mixed $items endpoint => label.
	 * @return mixed
	 */
	public function account_menu_item( $items ) {
		if ( ! is_array( $items ) || ! is_user_logged_in() ) {
			return $items;
		}
		return self::insert_before_logout(
			$items,
			self::ACCOUNT_ENDPOINT,
			self::panel_label(),
			static function ( $item, $key ) {
				return 'customer-logout' === $key;
			}
		);
	}

	/**
	 * Point the menu item at the panel page instead of a My Account endpoint.
	 */
	public function account_menu_url( $url, $endpoint ) {
		return self::ACCOUNT_ENDPOINT === $endpoint ? Arp_Pages::dashboard_url() : $url;
	}

	public function account_menu_styles(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		// The theme has no icon filter for custom items; reuse the profile-2user glyph from its icon font.
		wp_register_style( 'arp-account-menu', false, array(), ARP_VERSION );
		wp_enqueue_style( 'arp-account-menu' );
		wp_add_inline_style( 'arp-account-menu', '.woocommerce-MyAccount-navigation-link--' . self::ACCOUNT_ENDPOINT . ' a > i:first-child::before{content:"\e04b"}' );
	}

	private static function panel_label(): string {
		if ( Arp_Roles::user_is_affiliate() ) {
			return 'ورود به پنل طرح همکاران';
		}
		if ( Arp_Affiliate_Applications::is_pending( get_current_user_id() ) ) {
			return 'وضعیت درخواست طرح همکاران';
		}
		return 'درخواست پنل طرح همکاران';
	}

	/**
	 * Insert $key => $value before the first entry matching $is_logout (or at the end).
	 *
	 * @param array    $items     Menu items.
	 * @param string   $key       New key.
	 * @param mixed    $value     New value.
	 * @param callable $is_logout fn( $item, $key ): bool.
	 */
	private static function insert_before_logout( array $items, string $key, $value, callable $is_logout ): array {
		unset( $items[ $key ] );
		$out      = array();
		$inserted = false;
		foreach ( $items as $k => $item ) {
			if ( ! $inserted && $is_logout( $item, $k ) ) {
				$out[ $key ] = $value;
				$inserted    = true;
			}
			if ( is_int( $k ) ) {
				$out[] = $item;
			} else {
				$out[ $k ] = $item;
			}
		}
		if ( ! $inserted ) {
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Build login URL — filterable for Doctor Plus / custom auth.
	 *
	 * Default: home/?login=1&redirect_to=CURRENT
	 *
	 * @param string $redirect_to Absolute URL to return to after login.
	 */
	public static function login_url( string $redirect_to ): string {
		$default = add_query_arg(
			array(
				'login'       => '1',
				'redirect_to' => rawurlencode( $redirect_to ),
			),
			home_url( '/' )
		);

		/**
		 * Filter affiliate login URL.
		 *
		 * @param string $default     Default Doctor Plus-style URL.
		 * @param string $redirect_to Return URL.
		 */
		return (string) apply_filters( 'arp_login_url', $default, $redirect_to );
	}

	public function gate_signup_page(): void {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return;
		}
		if ( ! Arp_Pages::is_signup_template() ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}

		$current = Arp_Pages::current_url();
		wp_safe_redirect( self::login_url( $current ) );
		exit;
	}

	public function gate_dashboard_page(): void {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return;
		}
		if ( ! Arp_Pages::is_dashboard_template() ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}

		wp_safe_redirect( self::login_url( Arp_Pages::current_url() ) );
		exit;
	}
}
