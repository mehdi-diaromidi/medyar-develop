<?php
if (!defined('ABSPATH')) exit;

class Phar_DB_Manager {
    
    /**
     * تابع نصب و ایجاد جداول دیتابیس
     * این تابع هنگام فعال‌سازی پلاگین اجرا می‌شود
     */
    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // نام جدول سفارشات با پیشوند اختصاصی phar
        $table_orders = $wpdb->prefix . 'phar_orders';

        /* ساخت جدول دیتابیس برای ذخیره اطلاعات سفارشات، بیمه و نسخه
        */
        $sql = "CREATE TABLE $table_orders (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            fullname varchar(255),
            mobile varchar(20),
            province varchar(100),
            city varchar(100),
            address text,
            national_code varchar(20),
            
            -- اطلاعات بیمه
            insurance_type varchar(100),
            insurance_code varchar(100),
            
            -- اطلاعات نسخه
            pres_type varchar(20),   -- 'paper' یا 'electronic'
            pres_files longtext,     -- ذخیره فایل‌ها به فرمت JSON
            
            -- اطلاعات مالی و اقلام فاکتور
            invoice_items longtext,  -- ذخیره اقلام فاکتور JSON
            total_price decimal(15,0) DEFAULT 0,
            
            -- وضعیت و یادداشت‌ها
            status varchar(50) DEFAULT 'pending',
            usage_notes text,        -- دستور مصرف
            delivery_notes text,     -- وضعیت ارسال
            
            -- اطلاعات پرداخت
            payment_ref varchar(100),
            
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // --- ایجاد یا اصلاح پوشه آپلود تصاویر ---
        $upload = wp_upload_dir();
        $dir = $upload['basedir'] . '/pharmacy_secure/';
        
        // اگر پوشه وجود نداشت، بساز
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        // --- اصلاح فایل امنیتی .htaccess ---
        // این بخش مشکل ارور 403 را حل می‌کند.
        // قبلاً 'deny from all' بود که باعث می‌شد عکس‌ها لود نشوند.
        // الان فقط 'Options -Indexes' است که لیست فایل‌ها را مخفی می‌کند اما اجازه دیدن عکس را می‌دهد.
        $htaccess_content = "Options -Indexes";
        file_put_contents($dir . '.htaccess', $htaccess_content);
        
        // ایجاد فایل ایندکس خالی برای امنیت بیشتر (Silence is Golden)
        if (!file_exists($dir . 'index.php')) {
            file_put_contents($dir . 'index.php', '<?php // Silence is golden');
        }
    }
}