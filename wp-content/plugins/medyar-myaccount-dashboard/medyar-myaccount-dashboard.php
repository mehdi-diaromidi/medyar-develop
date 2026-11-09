<?php
/**
 * Plugin Name: Medyar My Account Dashboard
 * Description: پیشخوان سفارشی حساب کاربری مدیار + تنظیمات سفارشی رزرو (پیامک ادمین و درآمد مدیار) — مستقل از آپدیت قالب.
 * Version: 1.3.2
 * Author: M.Mehdi Diaromidi
 * Text Domain: medyar-myaccount-dashboard
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMAD_VERSION', '1.3.2' );
define( 'MMAD_PLUGIN_FILE', __FILE__ );
define( 'MMAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$mmad_admin_sms = MMAD_PLUGIN_DIR . 'includes/class-admin-reserve-sms.php';
if ( file_exists( $mmad_admin_sms ) ) {
	require_once $mmad_admin_sms;
	MMAD_Admin_Reserve_SMS::instance();
}

/**
 * Force our dashboard template even if the theme ships its own copy.
 *
 * @param string $template      Located template path.
 * @param string $template_name Template name relative to woocommerce/.
 * @param string $template_path Template path.
 * @return string
 */
function mmad_locate_dashboard_template( $template, $template_name, $template_path ) {
	if ( 'myaccount/dashboard.php' !== $template_name ) {
		return $template;
	}

	$plugin_template = MMAD_PLUGIN_DIR . 'templates/myaccount/dashboard.php';
	if ( file_exists( $plugin_template ) ) {
		return $plugin_template;
	}

	return $template;
}
add_filter( 'woocommerce_locate_template', 'mmad_locate_dashboard_template', 999, 3 );

/**
 * Enqueue dashboard styles only on the account dashboard endpoint.
 * Also load mobile sidebar click-through fix on every account page.
 */
function mmad_enqueue_assets() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}

	wp_enqueue_style(
		'mmad-myaccount-sidebar-fix',
		MMAD_PLUGIN_URL . 'assets/css/myaccount-sidebar-fix.css',
		array( 'drplus-wc-my-account' ),
		MMAD_VERSION
	);
	wp_enqueue_script(
		'mmad-myaccount-sidebar-fix',
		MMAD_PLUGIN_URL . 'assets/js/myaccount-sidebar-fix.js',
		array( 'jquery', 'drplus-wc-my-account' ),
		MMAD_VERSION,
		true
	);

	// Dashboard endpoint is empty string in WooCommerce.
	$endpoint = function_exists( 'WC' ) && WC()->query ? WC()->query->get_current_endpoint() : '';
	if ( '' !== $endpoint && 'dashboard' !== $endpoint ) {
		return;
	}

	wp_enqueue_style(
		'mmad-dashboard',
		MMAD_PLUGIN_URL . 'assets/css/dashboard.css',
		[],
		MMAD_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'mmad_enqueue_assets', 30 );

/**
 * Banner URL bundled with this plugin (survives theme/other-plugin updates).
 *
 * @return string
 */
function mmad_get_header_banner_url() {
	$file = MMAD_PLUGIN_DIR . 'assets/img/first-banner.webp';
	if ( ! file_exists( $file ) ) {
		return '';
	}

	return MMAD_PLUGIN_URL . 'assets/img/first-banner.webp';
}

/**
 * Hide Faraz SMS wallet-shortfall admin notice
 * («فراز اس ام اس: N سفارش تخفیفِ کیف پول…»).
 */
function mmad_suppress_faraz_wallet_shortfall_notice() {
	if ( ! is_admin() ) {
		return;
	}

	remove_action( 'admin_notices', 'farazsms_money_shortfall_notice' );
}
add_action( 'admin_init', 'mmad_suppress_faraz_wallet_shortfall_notice', 20 );
add_action( 'admin_notices', 'mmad_suppress_faraz_wallet_shortfall_notice', 0 );
