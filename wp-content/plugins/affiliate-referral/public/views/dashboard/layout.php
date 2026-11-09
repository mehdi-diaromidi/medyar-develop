<?php
/**
 * Affiliate shell: site theme header stays; only sidebar + content.
 *
 * @var WP_User $user
 * @var string  $page
 * @var string  $msg
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settlement_url = class_exists( 'MTW_MyAccount_UI' )
	? MTW_MyAccount_UI::section_url( 'withdrawal' )
	: home_url( '/my-account/wallet/?section=withdrawal' );
$account_url    = function_exists( 'wc_get_page_permalink' )
	? (string) wc_get_page_permalink( 'myaccount' )
	: home_url( '/my-account/' );
if ( '' === $account_url || false === $account_url ) {
	$account_url = home_url( '/my-account/' );
}

$menu = array(
	'dashboard'   => array(
		'label' => 'داشبورد',
		'icon'  => 'home',
	),
	'submit'      => array(
		'label' => 'ثبت معرفی بیمار',
		'icon'  => 'user-plus',
	),
	'referrals'   => array(
		'label' => 'معرفی‌های من',
		'icon'  => 'users',
	),
	'commissions' => array(
		'label' => 'پورسانت من',
		'icon'  => 'wallet',
	),
	'settlement'  => array(
		'label' => 'برداشت کیف پول',
		'icon'  => 'banknote',
		'url'   => $settlement_url,
	),
	'profile'     => array(
		'label' => 'پروفایل من',
		'icon'  => 'user',
	),
	'account'     => array(
		'label' => 'پنل مدیار',
		'icon'  => 'grid',
		'url'   => $account_url,
	),
);

/* Every affiliate has an inviter code; keep invited tab for all panel users. */
Arp_Inviter::ensure_code( (int) $user->ID );
$inviter_menu = array(
	'invited' => array(
		'label'       => 'همکاران معرفی‌شده',
		'icon_drplus' => 'drplus-icon-user-group',
	),
);
$menu_slice = array();
foreach ( $menu as $slug => $item ) {
	$menu_slice[ $slug ] = $item;
	if ( 'referrals' === $slug ) {
		foreach ( $inviter_menu as $inv_slug => $inv_item ) {
			$menu_slice[ $inv_slug ] = $inv_item;
		}
	}
}
$menu = $menu_slice;

$messages = array(
	'referral_ok' => 'معرفی بیمار ثبت شد.',
	'invalid'     => 'لطفاً فیلدهای الزامی را پر کنید.',
	'profile_ok'  => 'پروفایل ذخیره شد.',
	'no_status'   => 'وضعیت پیش‌فرض تعریف نشده است.',
);

/**
 * Inline SVG icon helper.
 *
 * @param string $name Icon key.
 */
$arp_icon = static function ( string $name ): string {
	$icons = array(
		'home'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5z"/></svg>',
		'user-plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>',
		'users'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
		'wallet'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M2 10h20M16 14h2"/></svg>',
		'banknote'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></svg>',
		'grid'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
		'user'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		'logout'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l-5-5 5-5M5 12h11"/></svg>',
	);
	return $icons[ $name ] ?? '';
};

/**
 * Sidebar nav icon markup (SVG set or DrPlus icon font).
 *
 * @param array<string,mixed> $item Menu item.
 */
$arp_nav_icon = static function ( array $item ) use ( $arp_icon ): string {
	if ( ! empty( $item['icon_drplus'] ) && is_string( $item['icon_drplus'] ) ) {
		$class = sanitize_html_class( $item['icon_drplus'] );
		return '<span class="arp-dash__nav-icon arp-dash__nav-icon--drplus" aria-hidden="true"><i class="' . esc_attr( $class ) . '" aria-hidden="true"></i></span>';
	}
	$icon_key = isset( $item['icon'] ) ? (string) $item['icon'] : '';
	return '<span class="arp-dash__nav-icon" aria-hidden="true">' . $arp_icon( $icon_key ) . '</span>';
};

$arrow_icon_class = is_rtl() ? 'drplus-icon-square-arrow-right' : 'drplus-icon-square-arrow-left';

$display_name = (string) $user->display_name;
$phone_raw    = (string) get_user_meta( $user->ID, 'mobile', true );
if ( '' === $phone_raw ) {
	$phone_raw = (string) get_user_meta( $user->ID, 'billing_phone', true );
}
if ( '' === $phone_raw ) {
	$phone_raw = (string) get_user_meta( $user->ID, 'shipping_phone', true );
}
if ( '' === $phone_raw && class_exists( '\DrPlus\Utils\User' ) ) {
	$phone_raw = (string) \DrPlus\Utils\User::get_phone( $user->ID );
}
/* Fallback: Iranian mobile stored as login or email local-part. */
if ( '' === $phone_raw ) {
	$login_digits = preg_replace( '/\D+/', '', (string) $user->user_login );
	if ( is_string( $login_digits ) && preg_match( '/^09\d{9}$/', $login_digits ) ) {
		$phone_raw = $login_digits;
	}
}
if ( '' === $phone_raw && ! empty( $user->user_email ) ) {
	$local        = (string) strstr( (string) $user->user_email, '@', true );
	$email_digits = preg_replace( '/\D+/', '', $local );
	if ( is_string( $email_digits ) && preg_match( '/^09\d{9}$/', $email_digits ) ) {
		$phone_raw = $email_digits;
	}
}
$phone_display = $phone_raw;
if ( $phone_raw && class_exists( '\DrPlus\Utils\Formatters' ) ) {
	$phone_display = \DrPlus\Utils\Formatters::phone( $phone_raw );
}

$show_wallet = false;
$wallet_text = '';
$balance_num = null;
if ( class_exists( 'Wallet_Manager' ) ) {
	$balance_num = (int) Wallet_Manager::get_instance()->get_balance( (int) $user->ID );
	$show_wallet = true;
} elseif ( class_exists( '\Sheyda\Wallet\Utils\Settings' ) && class_exists( 'SheydaWalletUtils' ) ) {
	$wallet_settings = \Sheyda\Wallet\Utils\Settings::get_settings();
	if ( ! empty( $wallet_settings['enable'] ) ) {
		$user_balance = SheydaWalletUtils::get_user_balance();
		$balance_num  = absint( intval( $user_balance->balance ) - intval( $user_balance->locked ) );
		$show_wallet  = true;
	}
}
if ( $show_wallet && null !== $balance_num ) {
	$balance_label = class_exists( '\DrPlus\Utils\Formatters' )
		? \DrPlus\Utils\Formatters::price( $balance_num )
		: number_format_i18n( $balance_num );
	$currency    = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'تومان';
	$wallet_text = trim( $balance_label . ' ' . $currency );
}

$header_icons = defined( 'DRPLUS_URI' )
	? DRPLUS_URI . '/assets/images/science-group-icons.svg'
	: get_theme_file_uri( 'assets/images/science-group-icons.svg' );
?>
<div class="arp-dash" dir="rtl" data-arp-page="<?php echo esc_attr( $page ); ?>"<?php echo apply_filters( 'arp_dashboard_sticky_sidebar', false ) ? ' data-arp-sticky' : ''; ?>>
	<aside class="arp-dash__sidebar" aria-label="منوی پنل همکار">
		<div class="arp-dash__sidebar-header">
			<button type="button" class="arp-dash__sidebar-close" aria-label="بستن منوی پنل همکار">
				<i class="drplus-icon-close" aria-hidden="true"></i>
			</button>
			<span class="arp-dash__role-badge">پنل همکار</span>
			<div class="arp-dash__user-avatar" data-arp-avatar="side">
				<?php echo get_avatar( $user->ID, 64 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div class="arp-dash__user-name" data-arp-bind="display_name"><?php echo esc_html( $display_name ); ?></div>
			<div class="arp-dash__user-phone" data-arp-bind="mobile_display" <?php echo $phone_display ? '' : 'hidden'; ?>><?php echo esc_html( $phone_display ); ?></div>
			<?php if ( $show_wallet ) : ?>
				<div class="arp-dash__user-wallet">
					<span class="arp-dash__user-wallet-label">موجودی شما:</span>
					<span class="arp-dash__user-wallet-value"><?php echo esc_html( $wallet_text ); ?></span>
				</div>
			<?php endif; ?>
			<div class="arp-dash__sidebar-header-icons">
				<img src="<?php echo esc_url( $header_icons ); ?>" alt="" />
			</div>
		</div>

		<div class="arp-dash__sidebar-body">
			<nav class="arp-dash__nav">
				<ul>
					<?php foreach ( $menu as $slug => $item ) :
						$href       = ! empty( $item['url'] ) ? (string) $item['url'] : Arp_Pages::page_url( $slug );
						$is_active  = empty( $item['url'] ) && $page === $slug;
						$is_external = ! empty( $item['url'] );
						?>
						<li class="<?php echo $is_active ? 'is-active' : ''; ?>">
							<a href="<?php echo esc_url( $href ); ?>"
								<?php echo $is_active ? 'aria-current="page"' : ''; ?>
								<?php echo $is_external ? 'target="_self"' : ''; ?>
								title="<?php echo esc_attr( $item['label'] ); ?>">
								<?php echo $arp_nav_icon( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<span class="arp-dash__nav-label"><?php echo esc_html( $item['label'] ); ?></span>
								<i class="arp-dash__nav-arrow <?php echo esc_attr( $arrow_icon_class ); ?>" aria-hidden="true"></i>
							</a>
						</li>
					<?php endforeach; ?>
					<li class="arp-dash__nav-logout">
						<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" title="خروج از حساب">
							<span class="arp-dash__nav-icon" aria-hidden="true"><?php echo $arp_icon( 'logout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="arp-dash__nav-label">خروج از حساب</span>
							<i class="arp-dash__nav-arrow <?php echo esc_attr( $arrow_icon_class ); ?>" aria-hidden="true"></i>
						</a>
					</li>
				</ul>
			</nav>
		</div>
	</aside>
	<button type="button" class="arp-dash__sidebar-mobile-expand" aria-label="باز کردن منوی پنل همکار">
		<i class="drplus-icon-double-arrow-<?php echo is_rtl() ? 'left' : 'right'; ?>" aria-hidden="true"></i>
	</button>

	<main class="arp-dash__main">
		<?php if ( $msg && isset( $messages[ $msg ] ) ) : ?>
			<div class="arp-notice" role="status"><?php echo esc_html( $messages[ $msg ] ); ?></div>
		<?php endif; ?>

		<div class="arp-dash__body">
			<?php
			$view = ARP_PLUGIN_DIR . 'public/views/dashboard/page-' . $page . '.php';
			if ( is_readable( $view ) ) {
				include $view;
			} else {
				echo '<p>صفحه در دسترس نیست.</p>';
			}
			?>
		</div>
	</main>
</div>
