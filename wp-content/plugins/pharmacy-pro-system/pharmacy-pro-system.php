<?php
/*
Plugin Name: سیستم جامع داروخانه (Pharmacy Pro)
Description: سیستم اختصاصی دریافت نسخه (با بیمه)، قیمت‌گذاری اقلام دارویی و پرداخت آنلاین.
Version: 1.0
Author: Sajjad Dehghanian
*/

if (!defined('ABSPATH')) exit;

// 1. تعریف ثابت‌های مسیر با پیشوند اختصاصی PHAR
define('PHAR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PHAR_PLUGIN_URL', plugin_dir_url(__FILE__));

// 2. فراخوانی فایل‌های کلاس‌ها از پوشه includes
require_once PHAR_PLUGIN_DIR . 'includes/db-manager.php';
require_once PHAR_PLUGIN_DIR . 'includes/payment-sms.php';
require_once PHAR_PLUGIN_DIR . 'includes/admin-page.php';
require_once PHAR_PLUGIN_DIR . 'includes/shortcode-handler.php';

// 3. هوک فعال‌سازی پلاگین (ساخت جدول دیتابیس)
register_activation_hook(__FILE__, ['Phar_DB_Manager', 'install']);

// 4. بارگذاری استایل‌ها و اسکریپت‌ها
function phar_enqueue_assets() {
    $version = time(); // استفاده از زمان فعلی برای جلوگیری از کش شدن فایل‌ها در زمان توسعه

    // فراخوانی استایل اختصاصی
    wp_enqueue_style('phar-style', PHAR_PLUGIN_URL . 'assets/style.css', [], $version);
    
    // فراخوانی اسکریپت اصلی (وابسته به jQuery)
    wp_enqueue_script('phar-script', PHAR_PLUGIN_URL . 'assets/script.js', ['jquery'], $version, true);
    
    // ارسال متغیرهای PHP به فایل جاوااسکریپت
    // نام آبجکت: phar_ajax
    wp_localize_script('phar-script', 'phar_ajax', [
        'url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('phar_nonce'), // ایجاد نانس امنیتی اختصاصی
        'assets_url' => PHAR_PLUGIN_URL . 'assets/'
    ]);
}

// اتصال تابع بالا به هوک‌های فرانت‌اند و پنل ادمین وردپرس
add_action('wp_enqueue_scripts', 'phar_enqueue_assets');
add_action('admin_enqueue_scripts', 'phar_enqueue_assets');

// غیرفعال‌سازی کامل ایموجی وردپرس (سایت + پیشخوان)
add_action( 'init', function () {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    remove_action( 'embed_head', 'print_emoji_detection_script' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

    // حذف پلاگین ایموجی ویرایشگر کلاسیک
    add_filter( 'tiny_mce_plugins', function ( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
    } );
} );

// حذف dns-prefetch به s.w.org
add_filter( 'wp_resource_hints', function ( $urls, $relation_type ) {
    if ( 'dns-prefetch' === $relation_type ) {
        $urls = array_filter( $urls, function ( $url ) {
            $href = is_array( $url ) ? ( $url['href'] ?? '' ) : $url;
            return false === strpos( $href, 's.w.org' );
        } );
    }
    return $urls;
}, 10, 2 );

// جلوگیری از لود SVG ایموجی از s.w.org
add_filter( 'emoji_svg_url', '__return_false' );