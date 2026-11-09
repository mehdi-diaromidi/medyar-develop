<?php
/**
 * Medyar My Account Dashboard (plugin template)
 *
 * Survives theme updates via woocommerce_locate_template override.
 *
 * @package MedyarMyAccountDashboard
 * @version 1.0.0
 */

use DrPlus\Components\Button;
use DrPlus\Model\Booking as ModelBooking;
use DrPlus\Utils;
use DrPlus\Utils\Formatters;
use DrPlus\Utils\Notifications;
use DrPlus\Utils\Options;
use DrPlus\Utils\WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user       = wp_get_current_user();
$menu_items = wc_get_account_menu_items();
$nav_icons = class_exists( 'DrPlus\\Utils\\WC' ) ? WC::my_account_menu_link_icons() : [];

$options = class_exists( 'DrPlus\\Utils\\Options' )
	? Options::get_options( [
		'show-notif-count' => true,
		'enable-booking'   => true,
	] )
	: [
		'show-notif-count' => true,
		'enable-booking'   => true,
	];

// تعداد نوبت‌ها
$appointment_count = 0;
if ( class_exists( 'DrPlus\\Model\\Booking' ) ) {
	$appointment_count = (int) ModelBooking::query()
		->where( 'customer_id', $user->ID )
		->whereNot( 'order_status', null )
		->count();
}

// تعداد سفارش‌ها
$orders = wc_get_orders( [
	'customer' => $user->ID,
	'limit'    => -1,
	'return'   => 'ids',
	'paginate' => false,
] );
$order_count = is_array( $orders ) ? count( $orders ) : 0;

// اطلاعیه‌های خوانده‌نشده
$notif_count = 0;
if ( class_exists( 'DrPlus\\Utils' ) && Utils::to_bool( $options['show-notif-count'] ) && class_exists( 'DrPlus\\Utils\\Notifications' ) ) {
	$notif_count = (int) Notifications::count_user_unread();
}

// موجودی کیف پول
$wallet_balance_text = '۰ تومان';
if ( class_exists( 'Wallet_Manager' ) && class_exists( 'DrPlus\\Utils\\Formatters' ) ) {
	$balance             = (int) Wallet_Manager::get_instance()->get_balance( $user->ID );
	$wallet_balance_text = Formatters::price( $balance ) . ' تومان';
} elseif ( class_exists( 'SheydaWalletUtils' ) && class_exists( 'Sheyda\\Wallet\\Utils\\Settings' ) && class_exists( 'DrPlus\\Utils\\Formatters' ) ) {
	$wallet_settings = \Sheyda\Wallet\Utils\Settings::get_settings();
	if ( ! empty( $wallet_settings['enable'] ) ) {
		$user_balance        = \SheydaWalletUtils::get_user_balance();
		$wallet_balance_text = Formatters::price( absint( intval( $user_balance->balance ) - intval( $user_balance->locked ) ) ) . ' ' . get_woocommerce_currency_symbol();
	}
}

$specialists_url = get_post_type_archive_link( 'specialist' );
if ( empty( $specialists_url ) ) {
	$specialists_url = home_url( '/specialists/' );
}

$quick_access_map = [
	'appointments'         => [
		'desc'  => 'مشاهده و مدیریت نوبت‌های پزشکی شما',
		'count' => $appointment_count,
		'icon'  => 'edit',
	],
	'orders'               => [
		'desc'  => 'پیگیری خریدها و سفارش‌های شما',
		'count' => $order_count,
		'icon'  => 'bag-1',
	],
	'notifications'        => [
		'desc'  => 'مشاهده اطلاعیه‌ها و پیام‌های سیستم',
		'count' => $notif_count,
		'icon'  => 'notification',
	],
	'chats'                => [
		'desc' => 'مشاوره‌ها و گفت‌وگوهای پزشکی شما',
		'icon' => 'messages-2',
	],
	'wallet'               => [
		'desc' => 'مدیریت موجودی و تراکنش‌های کیف پول',
		'icon' => 'wallet',
	],
	'invited-colleagues'   => [
		'desc' => 'دعوت همکاران به طرح همکاران مدیار',
		'icon' => 'user-group',
	],
	'sheyda-wallet'        => [
		'desc' => 'مدیریت موجودی و تراکنش‌های کیف پول',
		'icon' => 'wallet',
	],
	'payment-methods'      => [
		'desc' => 'مدیریت شیوه‌ها و کارت‌های پرداخت',
		'icon' => 'coin',
	],
	'edit-address'         => [
		'desc' => 'مدیریت نشانی‌های ارسال و صورتحساب',
		'icon' => 'location',
	],
	'edit-account'         => [
		'desc' => 'ویرایش اطلاعات شخصی و رمز عبور',
		'icon' => 'message-edit',
	],
	'wishlist'             => [
		'desc' => 'محصولات و موارد مورد علاقه شما',
		'icon' => 'heart',
	],
	'downloads'            => [
		'desc' => 'فایل‌ها و اسناد قابل دانلود شما',
		'icon' => 'download',
	],
	'specialist-dashboard' => [
		'desc' => 'درخواست یا مدیریت پنل متخصص',
		'icon' => 'stethoscope',
	],
	'faraz-panel'          => [
		'desc' => 'ورود به پنل کاربری اختصاصی',
		'icon' => 'element-3',
	],
];

$quick_access_items = [];
foreach ( $quick_access_map as $endpoint => $item ) {
	if ( ! isset( $menu_items[ $endpoint ] ) ) {
		continue;
	}
	if ( 'sheyda-wallet' === $endpoint && isset( $menu_items['wallet'] ) ) {
		continue;
	}
	$item['endpoint'] = $endpoint;
	$item['label']    = $menu_items[ $endpoint ];
	// Theme icons are full classes (drplus-icon-…); map fallbacks are bare names.
	$nav_icon = isset( $nav_icons[ $endpoint ] ) ? (string) $nav_icons[ $endpoint ] : '';
	if ( '' !== $nav_icon && 0 === strpos( $nav_icon, 'drplus-icon-' ) ) {
		$item['icon'] = substr( $nav_icon, strlen( 'drplus-icon-' ) );
	} elseif ( '' !== $nav_icon ) {
		$item['icon'] = $nav_icon;
	} else {
		$item['icon'] = $item['icon'] ?? 'document';
	}
	$quick_access_items[] = $item;
}

$header_banner_url = function_exists( 'mmad_get_header_banner_url' ) ? mmad_get_header_banner_url() : '';
?>

<div class="drplus-dashboard-wrap" data-dashboard-ver="mmad-1.0.0">

	<div class="drplus-dashboard-header">
		<div class="drplus-dashboard-header-text">
			<h2>
				<?php
				printf(
					/* translators: %s: user display name */
					esc_html__( 'سلام %s، خوش آمدید', 'medyar-myaccount-dashboard' ),
					esc_html( $user->display_name )
				);
				?>
			</h2>
			<p><?php echo esc_html( 'از این بخش می‌توانید به نوبت‌ها، سفارش‌ها، کیف پول، گفت‌وگوها و تنظیمات حساب کاربری دسترسی داشته باشید.' ); ?></p>
		</div>
		<?php if ( $header_banner_url ) : ?>
			<div class="drplus-dashboard-header-media">
				<img src="<?php echo esc_url( $header_banner_url ); ?>" alt="" width="480" height="280" decoding="async" loading="lazy" />
			</div>
		<?php endif; ?>
	</div>

	<?php
	$stat_wallet_endpoint = isset( $menu_items['wallet'] ) ? 'wallet' : ( isset( $menu_items['sheyda-wallet'] ) ? 'sheyda-wallet' : '' );
	$dashboard_stats      = [
		[
			'endpoint' => 'appointments',
			'icon'     => 'edit',
			'value'    => number_format_i18n( $appointment_count ),
			'label'    => __( 'نوبت‌های شما', 'medyar-myaccount-dashboard' ),
		],
		[
			'endpoint' => 'orders',
			'icon'     => 'bag-1',
			'value'    => number_format_i18n( $order_count ),
			'label'    => __( 'سفارش‌های شما', 'medyar-myaccount-dashboard' ),
		],
		[
			'endpoint' => 'notifications',
			'icon'     => 'notification',
			'value'    => number_format_i18n( $notif_count ),
			'label'    => __( 'اطلاعیه‌های خوانده‌نشده', 'medyar-myaccount-dashboard' ),
		],
		[
			'endpoint' => $stat_wallet_endpoint,
			'icon'     => 'wallet',
			'value'    => $wallet_balance_text,
			'label'    => __( 'موجودی کیف پول', 'medyar-myaccount-dashboard' ),
		],
	];
	?>
	<div class="drplus-dashboard-stats">
		<?php foreach ( $dashboard_stats as $stat ) : ?>
			<?php
			$stat_url = ( ! empty( $stat['endpoint'] ) && isset( $menu_items[ $stat['endpoint'] ] ) )
				? wc_get_account_endpoint_url( $stat['endpoint'] )
				: '';
			$stat_tag = $stat_url ? 'a' : 'div';
			?>
			<<?php echo $stat_tag; ?>
				class="drplus-dashboard-stat"
				<?php if ( $stat_url ) : ?>
					href="<?php echo esc_url( $stat_url ); ?>"
				<?php endif; ?>
			>
				<div class="drplus-dashboard-stat-icon">
					<i class="drplus-icon-<?php echo esc_attr( $stat['icon'] ); ?>" aria-hidden="true"></i>
				</div>
				<span class="drplus-dashboard-stat-value"><?php echo esc_html( $stat['value'] ); ?></span>
				<span class="drplus-dashboard-stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
			</<?php echo $stat_tag; ?>>
		<?php endforeach; ?>
	</div>

	<?php if ( ! empty( $quick_access_items ) ) : ?>
		<h3 class="drplus-dashboard-section-title"><?php esc_html_e( 'دسترسی سریع', 'medyar-myaccount-dashboard' ); ?></h3>

		<div class="drplus-dashboard-grid">
			<?php foreach ( $quick_access_items as $item ) : ?>
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $item['endpoint'] ) ); ?>" class="drplus-dashboard-card">
					<div class="drplus-dashboard-card-head">
						<div class="drplus-dashboard-card-icon">
							<i class="drplus-icon-<?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></i>
						</div>
						<?php if ( isset( $item['count'] ) && (int) $item['count'] > 0 ) : ?>
							<span class="drplus-dashboard-card-count"><?php echo esc_html( number_format_i18n( (int) $item['count'] ) ); ?></span>
						<?php endif; ?>
					</div>
					<div class="drplus-dashboard-card-content">
						<span class="drplus-dashboard-card-title"><?php echo esc_html( $item['label'] ); ?></span>
						<span class="drplus-dashboard-card-desc"><?php echo esc_html( $item['desc'] ); ?></span>
					</div>
					<span class="drplus-dashboard-card-arrow drplus-simple-icon-wrap icon-has-bg">
						<i class="drplus-icon-square-arrow-<?php echo is_rtl() ? 'left' : 'right'; ?> drplus-simple-icon" aria-hidden="true"></i>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="drplus-dashboard-cta">
		<?php
		if ( class_exists( 'DrPlus\\Components\\Button' ) ) {
			Button::view( [
				'link'    => esc_url( $specialists_url ),
				'text'    => esc_html__( 'درخواست نوبت', 'medyar-myaccount-dashboard' ),
				'icon'    => 'drplus-icon-calendar-2',
				'type'    => 'white',
				'style'   => 'rounded',
				'small'   => true,
				'align'   => 'end',
				'classes' => [ 'drplus-dashboard-cta-btn' ],
			] );
		} else {
			printf(
				'<a href="%1$s" class="button button-start rounded button-white small drplus-dashboard-cta-btn">%2$s</a>',
				esc_url( $specialists_url ),
				esc_html__( 'درخواست نوبت', 'medyar-myaccount-dashboard' )
			);
		}
		?>
		<div class="drplus-dashboard-cta-text">
			<strong><?php esc_html_e( 'به نوبت پزشکی نیاز دارید؟', 'medyar-myaccount-dashboard' ); ?></strong>
			<span><?php esc_html_e( 'همین حالا از بین بهترین پزشکان و متخصصان مدیار نوبت بگیرید.', 'medyar-myaccount-dashboard' ); ?></span>
		</div>
	</div>

</div>

<?php
do_action( 'woocommerce_account_dashboard' );
do_action( 'woocommerce_before_my_account' );
do_action( 'woocommerce_after_my_account' );
