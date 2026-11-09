<?php
/**
 * Plugin Name: Medyar SMS Queue
 * Description: صف پایدار پیامک Medyar — جدول صف، تلاش مجدد، ورکر پس‌زمینه، کرون سرور و گزارش ارسال برای همهٔ پیامک‌های طرح همکاران، کیف پول و رزرو.
 * Version: 1.0.0
 * Author: M.Mehdi Diaromidi
 * Text Domain: medyar-sms-queue
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MSQ_VERSION', '1.0.0' );
define( 'MSQ_DB_VERSION', '1' );
define( 'MSQ_PLUGIN_FILE', __FILE__ );
define( 'MSQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MSQ_PLUGIN_DIR . 'includes/class-msq-phone.php';
require_once MSQ_PLUGIN_DIR . 'includes/class-msq-install.php';
require_once MSQ_PLUGIN_DIR . 'includes/class-msq-queue.php';
require_once MSQ_PLUGIN_DIR . 'includes/class-msq-transports.php';
require_once MSQ_PLUGIN_DIR . 'includes/class-msq-transport-faraz.php';
require_once MSQ_PLUGIN_DIR . 'includes/class-msq-worker.php';
require_once MSQ_PLUGIN_DIR . 'includes/class-msq-client.php';
require_once MSQ_PLUGIN_DIR . 'includes/integrations/class-msq-integration-drplus.php';
require_once MSQ_PLUGIN_DIR . 'includes/class-msq-plugin.php';

if ( is_admin() ) {
	require_once MSQ_PLUGIN_DIR . 'includes/admin/class-msq-admin.php';
}

register_activation_hook( __FILE__, array( 'MSQ_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MSQ_Install', 'deactivate' ) );

MSQ_Plugin::instance()->boot();
