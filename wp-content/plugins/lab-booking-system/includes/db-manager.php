<?php
if (!defined('ABSPATH')) exit;

class Lab_DB_Manager {
    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_tests = $wpdb->prefix . 'lab_tests';
        $table_orders = $wpdb->prefix . 'lab_orders';

        $sql1 = "CREATE TABLE $table_tests (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            category varchar(100) NOT NULL,
            description text,
            price decimal(15,0) NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE $table_orders (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            fullname varchar(255),
            mobile varchar(20),
            province varchar(100),
            city varchar(100),
            address text,
            national_code varchar(20),
            insurance varchar(100),
            sup_insurance varchar(100),
            pres_type varchar(20),
            pres_data text, 
            items longtext,
            status varchar(50) DEFAULT 'pending',
            admin_price decimal(15,0) DEFAULT 0,
            admin_note text,
            result_files longtext,
            payment_ref varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        
        // ساخت پوشه آپلود امن
        $upload = wp_upload_dir();
        $dir = $upload['basedir'] . '/lab_secure/';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir . '.htaccess', 'Options -Indexes');
            file_put_contents($dir . 'index.php', '<?php // Silence');
        }
    }
}