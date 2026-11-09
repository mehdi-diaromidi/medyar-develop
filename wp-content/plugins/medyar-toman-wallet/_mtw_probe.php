<?php
header("Content-Type: text/plain; charset=utf-8");
header("Cache-Control: no-store");
echo "PROBE_OK\n";
$wp = dirname(__FILE__, 4) . "/wp-load.php";
if (!is_readable($wp)) { echo "NO_WP_LOAD\n"; exit; }
require_once $wp;
echo "WP_LOADED\n";
echo "HAS_MTW=" . (defined("MTW_VERSION") ? MTW_VERSION : "no") . "\n";
echo "HAS_WPH=" . (class_exists("Wallet_Payment_Handler") ? "yes" : "no") . "\n";
if (class_exists("Wallet_Payment_Handler")) {
  $m = get_class_methods("Wallet_Payment_Handler");
  echo "METHODS=" . implode(",", array_slice($m, 0, 15)) . "\n";
  echo "HAS_AJAX_HOOK=" . (has_action("wp_ajax_nopriv_mtw_zarinpal_return") ? "yes" : "no") . "\n";
  echo "HAS_CB_URL=" . (method_exists("Wallet_Payment_Handler", "callback_url") ? "yes" : "no") . "\n";
  echo "HAS_EXPIRE=" . (method_exists("Wallet_Payment_Handler", "schedule_expire") ? "yes" : "no") . "\n";
}
