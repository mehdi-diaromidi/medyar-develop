<?php
/**
 * Plugin Name:       Product Scout
 * Description:       Paste product page links (Torob, Basalam, shops...) and get a side-by-side report of name, description, specs, GTIN and images. Pure PHP, no AI.
 * Version:           0.3.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Text Domain:       product-scout
 */

defined( 'ABSPATH' ) || exit;

define( 'PSC_VERSION', '0.3.0' );
define( 'PSC_DIR', plugin_dir_path( __FILE__ ) );

require_once PSC_DIR . 'includes/class-psc-fetcher.php';
require_once PSC_DIR . 'includes/class-psc-extractor.php';
require_once PSC_DIR . 'includes/class-psc-torob.php';
require_once PSC_DIR . 'includes/class-psc-admin.php';

add_action( 'plugins_loaded', array( 'PSC_Admin', 'init' ) );
