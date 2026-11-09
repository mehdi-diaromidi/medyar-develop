<?php
/**
 * Plugin Name: Medyar Chat Pro
 * Description: ارتقای چت بیمار و متخصص قالب Dr Plus — وضعیت مکالمه، پروفایل بیمار، یادداشت خصوصی، پاسخ آماده، ارجاع و پیگیری، پاسخ به پیام، واکنش، پلیر صوتی، پیش‌نمایش و مدیریت آپلود، امنیت فایل‌ها، ثبت رویداد و گزارش مدیریتی.
 * Version: 1.1.0
 * Author: M.Mehdi Diaromidi
 * Text Domain: medyar-chat-pro
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MCP_VERSION', '1.2.0' );
define( 'MCP_DB_VERSION', '2.1' );
define( 'MCP_PLUGIN_FILE', __FILE__ );
define( 'MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MCP_PLUGIN_DIR . 'includes/class-mcp-jalali.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-settings.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-install.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-theme.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-access.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-audit.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-rate-limiter.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-sessions.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-timing.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-messages.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-files.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-notes.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-referrals.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-quick-replies.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-patient-profile.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-bridge.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-rest.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-frontend.php';
require_once MCP_PLUGIN_DIR . 'includes/class-mcp-plugin.php';

if ( is_admin() ) {
	require_once MCP_PLUGIN_DIR . 'includes/admin/class-mcp-admin.php';
	require_once MCP_PLUGIN_DIR . 'includes/admin/class-mcp-export.php';
}

register_activation_hook( __FILE__, array( 'MCP_Install', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( MCP_Plugin::CRON_HOOK );
		wp_clear_scheduled_hook( MCP_Timing::CRON_HOOK );
	}
);

MCP_Plugin::boot();
