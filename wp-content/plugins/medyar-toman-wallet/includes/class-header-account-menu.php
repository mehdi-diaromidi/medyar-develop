<?php
/**
 * Inject wallet link into theme header account dropdown.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('MTW_Header_Account_Menu')) {
    return;
}

class MTW_Header_Account_Menu
{
    public static function init(): void
    {
        add_filter('drplus/account_menu_items', [__CLASS__, 'inject_wallet_item'], 20, 2);
    }

    /**
     * @param array<int|string,array<string,string>> $items
     * @return array<int|string,array<string,string>>
     */
    public static function inject_wallet_item(array $items, bool $logged_in): array
    {
        if (!$logged_in || !function_exists('wc_get_account_endpoint_url')) {
            return $items;
        }

        $wallet_url = esc_url(wc_get_account_endpoint_url('wallet'));
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $link = (string) ($item['link'] ?? '');
            if ($link !== '' && strpos($link, '/wallet') !== false) {
                return $items;
            }
        }

        $wallet = [
            'label' => 'کیف پول',
            'link'  => $wallet_url,
            'icon'  => 'drplus-icon-wallet',
        ];

        if (!$items) {
            return ['wallet' => $wallet];
        }

        $out = [];
        $i   = 0;
        foreach ($items as $key => $item) {
            $out[$key] = $item;
            $i++;
            if ($i === 1) {
                $out['wallet'] = $wallet;
            }
        }
        return $out;
    }
}
