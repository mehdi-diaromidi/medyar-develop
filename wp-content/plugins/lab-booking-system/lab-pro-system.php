<?php																																								
/*
Plugin Name: سیستم جامع آزمایشگاه (Lab Pro)
Description: پلاگین اختصاصی رزرو، نسخه خوانی و پرداخت آزمایشگاه.
Version: 1.5
Author: Sajjad Dehghanian
*/

if (!defined('ABSPATH')) exit;

// تعریف مسیرها
define('LAB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LAB_PLUGIN_URL', plugin_dir_url(__FILE__));

// اینکلود کردن فایل‌های ضروری
require_once LAB_PLUGIN_DIR . 'includes/db-manager.php';
require_once LAB_PLUGIN_DIR . 'includes/payment-sms.php';
require_once LAB_PLUGIN_DIR . 'includes/admin-page.php';
require_once LAB_PLUGIN_DIR . 'includes/admin-sms-settings.php';
require_once LAB_PLUGIN_DIR . 'includes/shortcode-handler.php';

// هوک‌های فعال‌سازی
register_activation_hook(__FILE__, ['Lab_DB_Manager', 'install']);

// بارگذاری استایل و اسکریپت
function lab_enqueue_assets() {
    $version = time(); // جلوگیری از کش

    // استایل اصلی پلاگین
    wp_enqueue_style('lab-style', LAB_PLUGIN_URL . 'assets/style.css', [], $version);
    
    // اسکریپت اصلی
    wp_enqueue_script('lab-script', LAB_PLUGIN_URL . 'assets/script.js', ['jquery'], $version, true);
    
    // ارسال متغیرها به JS
    wp_localize_script('lab-script', 'lab_ajax', [
        'url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lab_nonce'),
        'assets_url' => LAB_PLUGIN_URL . 'assets/'
    ]);
}
add_action('wp_enqueue_scripts', 'lab_enqueue_assets');
add_action('admin_enqueue_scripts', 'lab_enqueue_assets');