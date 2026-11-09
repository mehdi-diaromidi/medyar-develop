<?php
/**
 * Plugin Name: Affiliate Referral (Medyar)
 * Description: سیستم همکاری در معرفی بیمار — Affiliate / Patient Referral
 * Version: 1.2.0
 * Author: M.Mehdi Diaromidi
 * Text Domain: affiliate-referral
 * Requires at least: 5.8
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ARP_VERSION', '1.2.0' );
define( 'ARP_DB_VERSION', '1.0.5' );
define( 'ARP_PLUGIN_FILE', __FILE__ );
define( 'ARP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ARP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once ARP_PLUGIN_DIR . 'includes/class-arp-autoloader.php';
Arp_Autoloader::register();

register_activation_hook( __FILE__, array( 'Arp_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Arp_Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Arp_Plugin::instance()->init();
	}
);
