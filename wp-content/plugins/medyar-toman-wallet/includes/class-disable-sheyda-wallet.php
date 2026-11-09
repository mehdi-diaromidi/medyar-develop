<?php
/**
 * Disable theme Sheyda Wallet (my-account + admin + theme checkout partial)
 * without editing theme/plugin files outside medyar-toman-wallet.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('MTW_Disable_Sheyda_Wallet')) {
    return;
}

class MTW_Disable_Sheyda_Wallet
{
    public static function init(): void
    {
        // Theme reads this before loading WC wallet + PartialPayment.
        add_filter('option_sheyda_wallet_settings_general', [__CLASS__, 'force_disable_settings'], 1);
        add_filter('pre_option_sheyda_wallet_settings_general', [__CLASS__, 'pre_force_disable_settings'], 1);

        add_filter('woocommerce_account_menu_items', [__CLASS__, 'remove_account_endpoint'], 99);
        add_action('admin_menu', [__CLASS__, 'remove_admin_menu'], 999);
        add_action('admin_head', [__CLASS__, 'hide_admin_menu_css'], 99);
        add_action('wp_enqueue_scripts', [__CLASS__, 'dequeue_theme_checkout_assets'], 120);
        add_action('wp_head', [__CLASS__, 'hide_theme_partial_css'], 99);

        // Even if PartialPayment still loads, strip its POST keys so it cannot
        // reserve/lock balance. MTW uses mtw_* field names instead.
        add_action('woocommerce_checkout_process', [__CLASS__, 'strip_theme_wallet_post'], 0);
        add_action('woocommerce_before_checkout_process', [__CLASS__, 'strip_theme_wallet_post'], 0);
        add_action('woocommerce_before_pay_action', [__CLASS__, 'strip_theme_wallet_post'], 0);
        add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'strip_theme_wallet_from_posted_data'], 0);
    }

    /**
     * Remove Sheyda PartialPayment POST keys before theme hooks run.
     */
    public static function strip_theme_wallet_post(): void
    {
        if (!isset($_POST) || !is_array($_POST)) {
            return;
        }
        // Migrate legacy names into MTW keys if needed.
        if (empty($_POST['mtw_use_wallet']) && !empty($_POST['sheyda_wallet_use_wallet'])) {
            $_POST['mtw_use_wallet'] = wp_unslash($_POST['sheyda_wallet_use_wallet']);
        }
        if (
            (empty($_POST['mtw_wallet_amount']) || (is_string($_POST['mtw_wallet_amount']) && trim((string) $_POST['mtw_wallet_amount']) === ''))
            && isset($_POST['sheyda_wallet_partial_amount'])
        ) {
            $_POST['mtw_wallet_amount'] = wp_unslash($_POST['sheyda_wallet_partial_amount']);
        }
        unset(
            $_POST['sheyda_wallet_use_wallet'],
            $_POST['sheyda_wallet_partial_amount'],
            $_POST['sheyda_wallet_partial_nonce']
        );
    }

    /**
     * @param string $posted_data Raw update_order_review body.
     */
    public static function strip_theme_wallet_from_posted_data(string $posted_data): void
    {
        // Ensure theme PartialPayment (if still hooked) does not see wallet flags.
        // MTW sync runs later and reads mtw_* (or migrated values via strip on process).
        unset(
            $_POST['sheyda_wallet_use_wallet'],
            $_POST['sheyda_wallet_partial_amount'],
            $_POST['sheyda_wallet_partial_nonce']
        );
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    public static function force_disable_settings($value): array
    {
        if (!is_array($value)) {
            $value = [];
        }
        // Keep enable=true so theme sidebar + WalletUtils ledger (booking payouts)
        // still use sheyda_wallet_balances. Disable only WC purchase/partial UI.
        $value['enable'] = true;
        $value['enable_wc_purchase'] = false;
        return $value;
    }

    /**
     * @param mixed $pre
     * @return mixed
     */
    public static function pre_force_disable_settings($pre)
    {
        // Let WordPress still load stored option, then option_* filter mutates it.
        return $pre;
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public static function remove_account_endpoint(array $items): array
    {
        unset($items['sheyda-wallet']);
        return $items;
    }

    public static function remove_admin_menu(): void
    {
        remove_menu_page('sheyda-wallet');
        remove_submenu_page('sheyda-wallet', 'sheyda-wallet');
        // Common section slugs used by Sheyda backend.
        foreach (['transactions', 'withdrawals', 'settings', 'wallets'] as $section) {
            remove_submenu_page('sheyda-wallet', 'sheyda-wallet&section=' . $section);
        }
    }

    public static function hide_admin_menu_css(): void
    {
        echo '<style id="mtw-hide-sheyda-admin">#toplevel_page_sheyda-wallet{display:none!important}</style>';
    }

    public static function dequeue_theme_checkout_assets(): void
    {
        wp_dequeue_style('sheyda-wallet-wc-checkout');
        wp_deregister_style('sheyda-wallet-wc-checkout');
        wp_dequeue_script('sheyda-wallet-partial-payment');
        wp_dequeue_script('sheyda-wallet-wc-checkout');
    }

    public static function hide_theme_partial_css(): void
    {
        // Keep MTW checkout row (data-mtw-wallet); hide any theme duplicate.
        echo '<style id="mtw-hide-sheyda-partial">'
            . '.sheyda-wallet-partial:not([data-mtw-wallet]){display:none!important}'
            . '.wc_payment_method.payment_method_wallet,#payment_method_wallet{display:none!important}'
            . '</style>';
    }
}
