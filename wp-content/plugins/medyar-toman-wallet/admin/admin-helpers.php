<?php

/**
 * Admin Display Helpers
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wwp_static_assets_config')) {
    /**
     * Raw asset definitions from medyar_STATIC_ASSETS (wp-config).
     *
     * @return array<string, array<string, mixed>>
     */
    function wwp_static_assets_config(): array
    {
        if (!defined('medyar_STATIC_ASSETS') || !is_array(medyar_STATIC_ASSETS)) {
            return [];
        }
        $out = [];
        foreach (medyar_STATIC_ASSETS as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $asset_key = sanitize_key(is_string($key) ? $key : (string) ($row['asset_key'] ?? ''));
            if ($asset_key === '') {
                continue;
            }
            $out[$asset_key] = $row;
        }
        return $out;
    }
}

if (!function_exists('wwp_static_asset_keys')) {
    /**
     * @return string[]
     */
    function wwp_static_asset_keys(bool $active_only = true): array
    {
        $keys = [];
        foreach (wwp_static_assets_config() as $asset_key => $row) {
            if ($active_only && isset($row['is_active']) && !(int) $row['is_active']) {
                continue;
            }
            $keys[] = $asset_key;
        }
        if (!empty($keys)) {
            return $keys;
        }
        if (class_exists('Asset_Settings_Manager')) {
            foreach (Asset_Settings_Manager::get_instance()->get_assets($active_only) as $asset) {
                $key = sanitize_key((string) ($asset['asset_key'] ?? ''));
                if ($key !== '' && $key !== 'toman') {
                    $keys[] = $key;
                }
            }
        }
        return array_values(array_unique($keys));
    }
}

if (!function_exists('wwp_default_asset_key')) {
    function wwp_default_asset_key(): string
    {
        $fallback = '';
        foreach (wwp_static_assets_config() as $asset_key => $row) {
            if (isset($row['is_active']) && !(int) $row['is_active']) {
                continue;
            }
            if ($fallback === '') {
                $fallback = $asset_key;
            }
            if (!empty($row['is_default'])) {
                return $asset_key;
            }
        }
        if ($fallback !== '') {
            return $fallback;
        }
        if (class_exists('Asset_Settings_Manager')) {
            foreach (Asset_Settings_Manager::get_instance()->get_assets(true) as $asset) {
                $key = sanitize_key((string) ($asset['asset_key'] ?? ''));
                if ($key === '' || $key === 'toman') {
                    continue;
                }
                if (!empty($asset['is_default'])) {
                    return $key;
                }
                if ($fallback === '') {
                    $fallback = $key;
                }
            }
        }
        return $fallback !== '' ? $fallback : 'silver';
    }
}

if (!function_exists('wwp_asset_icon_file')) {
    function wwp_asset_icon_file(string $type): string
    {
        $type = sanitize_key($type);
        if ($type === 'toman') {
            return 'coin.svg';
        }
        $static = wwp_static_assets_config();
        if (!empty($static[$type]['icon'])) {
            return sanitize_file_name(basename((string) $static[$type]['icon']));
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($type);
            if ($asset && !empty($asset['icon'])) {
                return sanitize_file_name(basename((string) $asset['icon']));
            }
        }
        return 'cube.svg';
    }
}

if (!function_exists('wwp_trade_operation_label')) {
    /**
     * Human label for buy_silver / sell_bullion / etc. from static asset titles.
     */
    function wwp_trade_operation_label(string $operation_type): string
    {
        $operation_type = sanitize_key($operation_type);
        if (preg_match('/^(buy|sell)_(.+)$/', $operation_type, $m)) {
            $verb  = $m[1] === 'buy' ? 'خرید' : 'فروش';
            $asset = sanitize_key($m[2]);
            return $verb . ' ' . wwp_label_balance_type($asset);
        }
        return $operation_type;
    }
}

if (!function_exists('wwp_label_balance_type')) {
    function wwp_label_balance_type(string $type): string
    {
        if ($type === 'toman') {
            return 'تومان';
        }
        $static = wwp_static_assets_config();
        if (isset($static[$type])) {
            $row = $static[$type];
            $title = (string) ($row['title_fa'] ?? $row['title_en'] ?? '');
            if ($title !== '') {
                return $title;
            }
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($type);
            if ($asset) {
                return $asset['title_fa'] ?: $asset['title_en'];
            }
        }
        return esc_html($type);
    }
}

if (!function_exists('wwp_asset_unit_label')) {
    function wwp_asset_unit_label(string $asset_type): string
    {
        $asset_type = sanitize_key($asset_type);
        if ($asset_type === 'toman') {
            return 'تومان';
        }
        $static = wwp_static_assets_config();
        if (isset($static[$asset_type]['unit_label']) && (string) $static[$asset_type]['unit_label'] !== '') {
            return (string) $static[$asset_type]['unit_label'];
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($asset_type);
            if ($asset && !empty($asset['unit_label'])) {
                return (string) $asset['unit_label'];
            }
        }
        return '';
    }
}

if (!function_exists('wwp_asset_is_decimal')) {
    function wwp_asset_is_decimal(string $asset_type): bool
    {
        $asset_type = sanitize_key($asset_type);
        if ($asset_type === 'toman') {
            return false;
        }
        $static = wwp_static_assets_config();
        if (isset($static[$asset_type]['is_decimal'])) {
            return (bool) $static[$asset_type]['is_decimal'];
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($asset_type);
            if ($asset) {
                return !empty($asset['is_decimal']);
            }
        }
        return true;
    }
}

if (!function_exists('wwp_humanize_transaction_description')) {
    function wwp_humanize_transaction_description(string $desc): string
    {
        return $desc;
    }
}

if (!function_exists('wwp_format_datetime_jalali')) {
    /**
     * Display helper: MySQL/WP datetime → Jalali for all admin list/dashboard cells.
     *
     * @param int|string $datetime Unix timestamp or parseable datetime string
     */
    function wwp_format_datetime_jalali($datetime, string $format = 'Y/m/d H:i'): string
    {
        if (class_exists('MTW_MyAccount_UI')) {
            $out = MTW_MyAccount_UI::format_jalali($datetime, $format);
            if ($out !== '') {
                return $out;
            }
        }

        if (is_numeric($datetime)) {
            $ts = (int) $datetime;
        } elseif (is_string($datetime) && trim($datetime) !== '') {
            $ts = strtotime($datetime);
        } else {
            $ts = 0;
        }
        if (!$ts) {
            return is_string($datetime) ? $datetime : '';
        }

        if (class_exists('\Morilog\Jalali\Jalalian')) {
            try {
                return \Morilog\Jalali\Jalalian::forge($ts)->format($format);
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return date_i18n($format, $ts);
    }
}

if (!function_exists('wwp_convert_gregorian_dates_in_text')) {
    /**
     * Replace Gregorian Y/m/d[+time] runs in free text with Jalali (leave 13xx/14xx alone).
     */
    function wwp_convert_gregorian_dates_in_text(string $text): string
    {
        return (string) preg_replace_callback(
            '/\b((?:19|20)\d{2})[\/\-](\d{1,2})[\/\-](\d{1,2})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?\b/',
            static function (array $m): string {
                $y  = (int) $m[1];
                $mo = (int) $m[2];
                $d  = (int) $m[3];
                $h  = isset($m[4]) ? (int) $m[4] : null;
                $i  = isset($m[5]) ? (int) $m[5] : null;

                if (!checkdate($mo, $d, $y)) {
                    return $m[0];
                }

                $has_time = $h !== null;
                $g = sprintf(
                    '%04d-%02d-%02d%s',
                    $y,
                    $mo,
                    $d,
                    $has_time ? sprintf(' %02d:%02d:00', $h, (int) $i) : ' 00:00:00'
                );

                return wwp_format_datetime_jalali($g, $has_time ? 'Y/m/d H:i' : 'Y/m/d');
            },
            $text
        );
    }
}

if (!function_exists('wwp_plain_transaction_description')) {
    function wwp_plain_transaction_description(?string $desc): string
    {
        if ($desc === null || $desc === '') {
            return '';
        }
        $plain = wwp_humanize_transaction_description(wp_strip_all_tags($desc));
        return wwp_convert_gregorian_dates_in_text($plain);
    }
}

if (!function_exists('wwp_asset_registry_map')) {
    function wwp_asset_registry_map(bool $only_active = false): array
    {
        if (!class_exists('Asset_Settings_Manager')) {
            return [];
        }
        $out = [];
        foreach (Asset_Settings_Manager::get_instance()->get_assets($only_active) as $asset) {
            $out[$asset['asset_key']] = $asset;
        }
        return $out;
    }
}

if (!function_exists('wwp_label_operation_type')) {
    function wwp_label_operation_type(string $op): string
    {
        $assets = wwp_asset_registry_map(false);
        $map = [
            'charge'        => 'واریز',
            'spend'         => 'خرج',
            'buy_silver'    => 'خرید از مدیار',
            'sell_silver'   => 'فروش به مدیار',
            'admin_add'     => 'اضافه توسط ادمین',
            'admin_reduce'  => 'کاهش توسط ادمین',
            'withdraw'      => 'درخواست برداشت',
            'returned'      => 'بازگشت',
            'refund_order'  => 'بازگشت سفارش',
            'booking_payout'            => 'درآمد نوبت',
            'booking_payout_reversal'   => 'برگشت درآمد نوبت',
        ];
        foreach ($assets as $asset_key => $asset) {
            $name = $asset['title_fa'] ?: $asset['title_en'] ?: $asset_key;
            $map['buy_' . $asset_key] = 'خرید ' . $name;
            $map['sell_' . $asset_key] = 'فروش ' . $name;
        }
        return $map[$op] ?? esc_html($op);
    }
}

if (!function_exists('wwp_tx_p_info_array')) {
    /**
     * @param object|array|null $tx
     * @return array<string, mixed>
     */
    function wwp_tx_p_info_array($tx): array
    {
        if ($tx === null) {
            return [];
        }
        $raw = is_object($tx) ? ($tx->p_info ?? null) : ($tx['p_info'] ?? null);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}

if (!function_exists('wwp_tx_p_info_type')) {
    /**
     * @param object|array|null $tx
     */
    function wwp_tx_p_info_type($tx): string
    {
        $p = wwp_tx_p_info_array($tx);
        return sanitize_key((string) ($p['type'] ?? ''));
    }
}

if (!function_exists('wwp_label_operation_for_tx')) {
    /**
     * Operation title for a wallet row (commission deposits ≠ admin_add generic label).
     *
     * @param object|array $tx
     */
    function wwp_label_operation_for_tx($tx): string
    {
        $op = is_object($tx)
            ? (string) ($tx->operation_type ?? '')
            : (string) ($tx['operation_type'] ?? '');
        $desc = is_object($tx)
            ? (string) ($tx->description ?? '')
            : (string) ($tx['description'] ?? '');
        $type = wwp_tx_p_info_type($tx);

        if ($op === 'admin_add'
            && ($type === 'affiliate_commission' || preg_match('/واریز\s*پورسانت/u', $desc))
        ) {
            return 'واریز پورسانت';
        }
        if ($op === 'admin_reduce'
            && ($type === 'affiliate_commission_reversal' || preg_match('/برگشت\s*پورسانت/u', $desc))
        ) {
            return 'برگشت پورسانت';
        }
        if ($op === 'charge'
            && ($type === 'admin_manual_deposit' || preg_match('/واریز\s*دستی/u', $desc))
        ) {
            return 'واریز دستی';
        }

        return wwp_label_operation_type($op);
    }
}

if (!function_exists('wwp_label_status')) {
    function wwp_label_status(string $status): string
    {
        $map = [
            'pending'        => 'در حال بررسی',
            'processing'     => 'در انتظار بررسی',
            'completed'      => 'تکمیل شده',
            'canceled'       => 'لغو شده',
            'minor'            => 'خرد شده (جزئی)',
            'minor_canceled'   => 'لغو جزئی',
            'minor_completed'  => 'جزئی تکمیل شده',
        ];
        return $map[$status] ?? esc_html($status);
    }
}

if (!function_exists('wwp_sanitize_status')) {
    function wwp_sanitize_status(string $status): string
    {
        return in_array($status, ['pending', 'processing', 'completed', 'canceled', 'minor', 'minor_canceled', 'minor_completed'], true) ? $status : 'pending';
    }
}

if (!function_exists('wwp_format_amount')) {
    function wwp_format_amount(float $amount, string $balance_type): string
    {
        $abs = abs($amount);
        if ($balance_type === 'toman') {
            $fmt = number_format($abs, 0) . ' تومان';
        } else {
            $is_decimal = ($balance_type !== 'bullion');
            $unit = '';
            if (class_exists('Asset_Settings_Manager')) {
                $asset = Asset_Settings_Manager::get_instance()->get_asset($balance_type);
                if ($asset) {
                    $is_decimal = !empty($asset['is_decimal']);
                    $unit = (string) $asset['unit_label'];
                }
            } else {
                $unit = '';
            }
            $fmt = number_format($abs, $is_decimal ? 3 : 0) . ($unit !== '' ? (' ' . $unit) : '');
        }
        $cls = $amount >= 0 ? 'plus' : 'minus';
        return '<span class="wallet-amount ' . $cls . '">' . $fmt . '</span>';
    }
}

if (!function_exists('wwp_format_gateway')) {
    function wwp_format_gateway($p_info_raw): string
    {
        if (empty($p_info_raw)) return '-';
        $p = is_string($p_info_raw) ? json_decode($p_info_raw, true) : $p_info_raw;
        if (empty($p['type'])) return '-';
        if ($p['type'] === 'Wallet_gateway') return 'کیف پول';
        if ($p['type'] === 'Pay_gateway' && !empty($p['id'])) {
            global $wpdb;
            $gw = $wpdb->get_var($wpdb->prepare(
                "SELECT gateway FROM {$wpdb->prefix}wallet_payment_transactions WHERE id = %d LIMIT 1",
                (int)$p['id']
            ));
            if ($gw) return esc_html($gw);
        }
        return '-';
    }
}

if (!function_exists('wwp_operation_options')) {
    function wwp_operation_options(string $selected = ''): void
    {
        $ops = [
            '' => 'همه عملیات‌ها',
            'charge' => 'واریز / شارژ',
            'spend' => 'خرج',
            'admin_add' => 'اضافه توسط ادمین / واریز پورسانت',
            'admin_reduce' => 'کاهش توسط ادمین / برگشت پورسانت',
            'withdraw' => 'درخواست برداشت',
            'returned' => 'بازگشت',
            'refund_order' => 'بازگشت سفارش',
            'booking_payout'             => 'درآمد نوبت',
            'booking_payout_reversal'    => 'برگشت درآمد نوبت',
        ];
        $assets = function_exists('wwp_asset_registry_map') ? wwp_asset_registry_map(false) : [];
        foreach ($assets as $asset_key => $asset) {
            $asset_key = sanitize_key((string) $asset_key);
            if ($asset_key === '' || $asset_key === 'toman') {
                continue;
            }
            $name = (string) ($asset['title_fa'] ?? $asset['title_en'] ?? $asset_key);
            $ops['buy_' . $asset_key] = 'خرید ' . $name;
            $ops['sell_' . $asset_key] = 'فروش ' . $name;
        }
        foreach ($ops as $v => $l) {
            echo '<option value="' . esc_attr((string) $v) . '"' . selected($selected, (string) $v, false) . '>' . esc_html($l) . '</option>';
        }
    }
}

if (!function_exists('wwp_balance_type_options')) {
    /**
     * Dropdown options for balance_type from medyar_STATIC_ASSETS / asset registry.
     */
    function wwp_balance_type_options(string $selected = '', bool $include_toman = true): void
    {
        echo '<option value=""' . selected($selected, '', false) . '>همه</option>';
        if ($include_toman) {
            echo '<option value="toman"' . selected($selected, 'toman', false) . '>تومان</option>';
        }
        $keys = function_exists('wwp_static_asset_keys') ? wwp_static_asset_keys(true) : [];
        if (empty($keys) && defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)) {
            $keys = array_keys(medyar_STATIC_ASSETS);
        }
        foreach ($keys as $key) {
            $key = sanitize_key((string) $key);
            if ($key === '' || $key === 'toman') {
                continue;
            }
            $label = function_exists('wwp_label_balance_type') ? wwp_label_balance_type($key) : $key;
            echo '<option value="' . esc_attr($key) . '"' . selected($selected, $key, false) . '>' . esc_html($label) . '</option>';
        }
    }
}

if (!function_exists('wwp_status_options')) {
    function wwp_status_options(string $selected = '', bool $include_all = true): void
    {
        $s = $include_all ? ['' => 'همه وضعیت‌ها'] : [];
        $s += [
            'pending'        => 'در حال بررسی',
            'processing'     => 'در انتظار بررسی', 
            'completed'      => 'تکمیل شده', 
            'canceled'       => 'لغو شده',
            'minor'            => 'خرد شده (جزئی)',
            'minor_canceled'   => 'لغو جزئی',
            'minor_completed'  => 'جزئی تکمیل شده',
        ];
        foreach ($s as $v => $l) echo "<option value=\"{$v}\"" . selected($selected, $v, false) . ">{$l}</option>";
    }
}

if (!function_exists('wwp_append_description')) {
    function wwp_append_description(string $existing, string $new_note): string
    {
        if (empty($existing)) return $new_note;
        if (empty($new_note)) return $existing;
        return $existing . "\n" . $new_note;
    }
}

if (!function_exists('wwp_tx_skips_validity')) {
    function wwp_tx_skips_validity(object $tx): bool
    {
        if (in_array((string) $tx->operation_type, ['admin_add', 'admin_reduce'], true)) {
            return true;
        }
        return strpos((string) ($tx->description ?? ''), 'انجام‌شده توسط ادمین:') !== false;
    }
}

if (!function_exists('wwp_col_tip')) {
    function wwp_col_tip(string $text): string
    {
        return '<span class="wwp-col-tip" data-tip="' . esc_attr($text) . '">&#63;</span>';
    }
}

/* ستون‌هایی که نباید tooltip داشته باشند */
if (!function_exists('wwp_th_smart')) {
    function wwp_th_smart(string $label, string $tip = '', bool $desc_class = false): void
    {
        $cls = $desc_class ? ' class="col-description"' : '';
        $no_tip_cols = ['کاربر', 'وضعیت', 'درگاه', 'توضیحات', 'تاریخ', 'عملیات ادمین', 'عملیات', 'میزان', 'کد مرجع'];
        if (!empty($tip) && !in_array($label, $no_tip_cols, true)) {
            echo '<th' . $cls . '>' . $label . wwp_col_tip($tip) . '</th>';
        } else {
            echo '<th' . $cls . '>' . $label . '</th>';
        }
    }
}

/** نمایش توضیحات خطی — هر خط با تاریخ در ابتدا */
if (!function_exists('wwp_format_description')) {
    function wwp_format_description(string $desc, bool $inline = false): string
    {
        if (empty($desc)) return '-';
        $lines = array_filter(array_map('trim', explode("\n", $desc)));
        if ($inline) {
            // برای سلول جدول: فقط اولین خط + نقطه‌چین
            return esc_html(mb_substr($lines[0] ?? '-', 0, 80)) . (count($lines) > 1 ? '…' : '');
        }
        // برای مودال: هر خط در یک ردیف
        $out = '';
        foreach ($lines as $line) {
            $line = wwp_humanize_transaction_description($line);
            // اگر با [تاریخ] شروع می‌شود جدا کن
            if (preg_match('/^\[(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2})\]\s*(.+)$/', $line, $m)) {
                $m[2] = wwp_humanize_transaction_description((string) $m[2]);
                $out .= '<div class="wwp-desc-line"><span class="wwp-desc-time">' . esc_html($m[1]) . '</span><span class="wwp-desc-text">' . esc_html($m[2]) . '</span></div>';
            } else {
                $out .= '<div class="wwp-desc-line"><span class="wwp-desc-text">' . esc_html($line) . '</span></div>';
            }
        }
        return $out;
    }
}

if (!function_exists('wwp_bank_card_bank_label')) {
    /**
     * نام بانک از ساختار کارت (bank می‌تواند آرایه fa/en یا رشته باشد).
     *
     * @param array<string,mixed>|mixed $card_data
     */
    function wwp_bank_card_bank_label($card_data): string
    {
        if (!is_array($card_data)) {
            return '';
        }
        $bank = $card_data['bank'] ?? ($card_data['bank_name'] ?? '');
        if (is_array($bank)) {
            $label = (string) ($bank['fa'] ?? $bank['en'] ?? '');
        } else {
            $label = (string) $bank;
        }
        return sanitize_text_field($label);
    }
}

if (!function_exists('wwp_bank_card_owner_label')) {
    /**
     * @param array<string,mixed>|mixed $card_data
     */
    function wwp_bank_card_owner_label($card_data): string
    {
        if (!is_array($card_data)) {
            return '';
        }
        $owner = $card_data['owner'] ?? ($card_data['owner_name'] ?? '');
        if (is_array($owner)) {
            if (isset($owner['fa'])) {
                $owner = (string) $owner['fa'];
            } elseif (isset($owner['name'])) {
                $owner = (string) $owner['name'];
            } else {
                $first = reset($owner);
                $owner = is_scalar($first) ? (string) $first : '';
            }
        }
        return sanitize_text_field((string) $owner);
    }
}

if (!function_exists('wwp_find_users_by_card_or_iban')) {
    /**
     * Find users whose medyar_bank_cards meta matches card and/or IBAN.
     *
     * @return array<int, array{user_id:int,card_number:string,iban:string,owner:string,bank:string}>
     */
    function wwp_find_users_by_card_or_iban(string $card_number = '', string $iban = ''): array
    {
        $card_number = preg_replace('/\D/', '', $card_number);
        $iban        = strtoupper(preg_replace('/\s+/', '', $iban));

        if ($card_number === '' && $iban === '') {
            return [];
        }

        global $wpdb;
        $where  = ['meta_key = %s'];
        $params = ['medyar_bank_cards'];

        if ($card_number !== '') {
            $where[]  = 'meta_value LIKE %s';
            $params[] = '%' . $wpdb->esc_like($card_number) . '%';
        }
        if ($iban !== '') {
            $where[]  = 'meta_value LIKE %s';
            $params[] = '%' . $wpdb->esc_like($iban) . '%';
        }

        $sql = "SELECT user_id FROM {$wpdb->usermeta} WHERE " . implode(' AND ', $where) . ' LIMIT 100';
        $user_ids = $wpdb->get_col($wpdb->prepare($sql, ...$params));
        if (empty($user_ids)) {
            return [];
        }

        $matches = [];
        foreach ($user_ids as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0 || !function_exists('medyar_get_user_bank_cards')) {
                continue;
            }
            $cards = medyar_get_user_bank_cards($uid);
            if (!is_array($cards) || empty($cards)) {
                continue;
            }
            foreach ($cards as $stored_card => $card_data) {
                if (!is_array($card_data)) {
                    continue;
                }
                $stored_card = preg_replace('/\D/', '', (string) $stored_card);
                $stored_iban = strtoupper(preg_replace('/\s+/', '', (string) ($card_data['iban'] ?? '')));

                if ($card_number !== '') {
                    // ۱۶ رقم یا بیشتر → تطبیق دقیق؛ کوتاه‌تر → جستجوی جزئی (تکه کارت)
                    if (strlen($card_number) >= 16) {
                        if ($stored_card !== $card_number) {
                            continue;
                        }
                    } elseif (strpos($stored_card, $card_number) === false) {
                        continue;
                    }
                }
                if ($iban !== '') {
                    if (strlen($iban) >= 24) {
                        if ($stored_iban !== $iban) {
                            continue;
                        }
                    } elseif (strpos($stored_iban, $iban) === false) {
                        continue;
                    }
                }

                $matches[] = [
                    'user_id'     => $uid,
                    'card_number' => $stored_card,
                    'iban'        => $stored_iban,
                    'owner'       => wwp_bank_card_owner_label($card_data),
                    'bank'        => wwp_bank_card_bank_label($card_data),
                ];
            }
        }

        return $matches;
    }
}

if (!function_exists('wwp_deposit_collect_matches')) {
    /**
     * جمع‌آوری نتایج جستجوی واریز (کارت / شبا / کاربر).
     *
     * @return array<int, array{user_id:int,card_number:string,iban:string,owner:string,bank:string}>
     */
    function wwp_deposit_collect_matches(string $card = '', string $iban = '', string $user_q = ''): array
    {
        $card   = preg_replace('/\D/', '', $card);
        $iban   = strtoupper(preg_replace('/\s+/', '', $iban));
        $user_q = trim($user_q);

        if ($card !== '' || $iban !== '') {
            return wwp_find_users_by_card_or_iban($card, $iban);
        }
        if ($user_q === '') {
            return [];
        }

        $matches = [];
        $uids    = wwp_admin_resolve_user_ids_from_q($user_q, 20);
        foreach ($uids as $uid) {
            $uid   = (int) $uid;
            $cards = function_exists('medyar_get_user_bank_cards') ? medyar_get_user_bank_cards($uid) : [];
            if (is_array($cards) && !empty($cards)) {
                foreach ($cards as $stored_card => $card_data) {
                    if (!is_array($card_data)) {
                        continue;
                    }
                    $matches[] = [
                        'user_id'     => $uid,
                        'card_number' => preg_replace('/\D/', '', (string) $stored_card),
                        'iban'        => strtoupper(preg_replace('/\s+/', '', (string) ($card_data['iban'] ?? ''))),
                        'bank'        => wwp_bank_card_bank_label($card_data),
                        'owner'       => wwp_bank_card_owner_label($card_data),
                    ];
                }
            } else {
                $matches[] = [
                    'user_id'     => $uid,
                    'card_number' => '',
                    'iban'        => '',
                    'bank'        => '',
                    'owner'       => '',
                ];
            }
        }
        return $matches;
    }
}

if (!function_exists('wwp_deposit_enrich_match_for_admin')) {
    /**
     * غنی‌سازی ردیف نتیجه برای UI ادمین / AJAX.
     *
     * @param array{user_id:int,card_number:string,iban:string,owner:string,bank:string} $row
     * @return array<string,mixed>
     */
    function wwp_deposit_enrich_match_for_admin(array $row): array
    {
        $uid  = (int) ($row['user_id'] ?? 0);
        $user = $uid > 0 ? get_user_by('id', $uid) : false;
        $wm   = class_exists('Wallet_Manager') ? Wallet_Manager::get_instance() : null;
        $bal  = ($wm && $uid > 0) ? (float) $wm->get_balance($uid) : 0.0;

        return [
            'user_id'           => $uid,
            'display_name'      => $user ? (string) ($user->display_name ?: $user->user_login) : '',
            'user_email'        => $user ? (string) $user->user_email : '',
            'user_login'        => $user ? (string) $user->user_login : '',
            'balance'           => $bal,
            'balance_formatted' => number_format_i18n($bal),
            'bank'              => (string) ($row['bank'] ?? ''),
            'card_number'       => (string) ($row['card_number'] ?? ''),
            'iban'              => (string) ($row['iban'] ?? ''),
            'owner'             => (string) ($row['owner'] ?? ''),
            'insights_url'      => $uid > 0
                ? admin_url('admin.php?page=medyar-wallet-user-insights&user_id=' . $uid)
                : '',
            'can_deposit'       => (($row['card_number'] ?? '') !== '' || ($row['iban'] ?? '') !== ''),
        ];
    }
}

if (!function_exists('wwp_deposit_render_result_row')) {
    /**
     * ردیف نتیجه جستجوی واریز (SSR).
     *
     * @param array<string,mixed> $row
     */
    function wwp_deposit_render_result_row(array $row, string $search_card = '', string $search_iban = '', string $search_user_q = ''): void
    {
        $uid         = (int) ($row['user_id'] ?? 0);
        $can_deposit = !empty($row['can_deposit']);
        $dash        = '—';
        ?>
        <tr class="wwp-deposit-result-row"
            data-user-id="<?php echo (int) $uid; ?>"
            data-card="<?php echo esc_attr((string) ($row['card_number'] ?? '')); ?>"
            data-iban="<?php echo esc_attr((string) ($row['iban'] ?? '')); ?>"
            data-bank="<?php echo esc_attr((string) ($row['bank'] ?? '')); ?>"
            data-owner="<?php echo esc_attr((string) ($row['owner'] ?? '')); ?>"
            data-name="<?php echo esc_attr((string) ($row['display_name'] ?? '')); ?>"
            data-email="<?php echo esc_attr((string) ($row['user_email'] ?? '')); ?>">
            <td class="col-user">
                <strong>#<?php echo (int) $uid; ?></strong>
                <?php if (!empty($row['display_name'])) : ?>
                    <br><?php echo esc_html((string) $row['display_name']); ?>
                <?php endif; ?>
                <?php if (!empty($row['user_email'])) : ?>
                    <br><small><?php echo esc_html((string) $row['user_email']); ?></small>
                <?php endif; ?>
            </td>
            <td dir="ltr"><?php echo esc_html((string) ($row['balance_formatted'] ?? number_format_i18n((float) ($row['balance'] ?? 0)))); ?></td>
            <td><?php echo esc_html(($row['bank'] ?? '') !== '' ? (string) $row['bank'] : $dash); ?></td>
            <td dir="ltr"><?php echo esc_html(($row['card_number'] ?? '') !== '' ? (string) $row['card_number'] : $dash); ?></td>
            <td dir="ltr"><?php echo esc_html(($row['iban'] ?? '') !== '' ? (string) $row['iban'] : $dash); ?></td>
            <td><?php echo esc_html(($row['owner'] ?? '') !== '' ? (string) $row['owner'] : $dash); ?></td>
            <td>
                <?php if (!$can_deposit) : ?>
                    <p class="description">کارتی ثبت نشده — ابتدا کارت را در پنل کاربر بررسی کنید.</p>
                    <?php if (!empty($row['insights_url'])) : ?>
                        <a class="button button-small" href="<?php echo esc_url((string) $row['insights_url']); ?>">پنل کاربر</a>
                    <?php endif; ?>
                <?php else : ?>
                    <div class="wwp-deposit-row-actions">
                        <input type="number" min="1" step="1" class="medyar-wallet-deposit-amount wwp-deposit-row-amount"
                               placeholder="تومان" dir="ltr" aria-label="مبلغ واریز">
                        <button type="button" class="button button-primary wwp-deposit-btn-now">واریز</button>
                        <button type="button" class="button wwp-deposit-btn-queue">افزودن به صف</button>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wallet-form-inline wwp-deposit-noscript-form">
                            <?php wp_nonce_field('medyar_manual_deposit', 'medyar_manual_deposit_nonce'); ?>
                            <input type="hidden" name="action" value="medyar_manual_deposit">
                            <input type="hidden" name="user_id" value="<?php echo (int) $uid; ?>">
                            <input type="hidden" name="card_number" value="<?php echo esc_attr((string) ($row['card_number'] ?? '')); ?>">
                            <input type="hidden" name="iban" value="<?php echo esc_attr((string) ($row['iban'] ?? '')); ?>">
                            <input type="hidden" name="search_card" value="<?php echo esc_attr($search_card); ?>">
                            <input type="hidden" name="search_iban" value="<?php echo esc_attr($search_iban); ?>">
                            <input type="hidden" name="search_user_q" value="<?php echo esc_attr($search_user_q); ?>">
                            <noscript>
                                <input type="number" name="amount" min="1" step="1" required class="medyar-wallet-deposit-amount" placeholder="تومان" dir="ltr">
                                <?php submit_button('واریز', 'primary', 'submit', false); ?>
                            </noscript>
                        </form>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

if (!function_exists('wwp_admin_get_products_for_select')) {
    /**
     * Products for admin dropdowns (all statuses).
     *
     * @return WP_Post[]
     */
    function wwp_admin_get_products_for_select(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = get_posts([
            'post_type'        => 'product',
            'post_status'      => ['publish', 'private', 'draft', 'pending', 'future'],
            'numberposts'      => -1,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);
        return is_array($cache) ? $cache : [];
    }
}

if (!function_exists('wwp_admin_render_product_select')) {
    /**
     * Single product <select>.
     *
     * @param string $name
     * @param string $id
     * @param int    $selected_id
     */
    function wwp_admin_render_product_select(string $name, string $id, int $selected_id = 0): void
    {
        $products = wwp_admin_get_products_for_select();
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="regular-text" style="width:100%;max-width:400px;">';
        echo '<option value="">' . esc_html('انتخاب محصول...') . '</option>';
        foreach ($products as $product) {
            if (!$product instanceof WP_Post) {
                continue;
            }
            $status_label = ($product->post_status !== 'publish') ? ' (' . $product->post_status . ')' : '';
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr((string) $product->ID),
                selected($selected_id, (int) $product->ID, false),
                esc_html($product->post_title . $status_label . ' [ID: ' . $product->ID . ']')
            );
        }
        echo '</select>';
    }
}

if (!function_exists('wwp_admin_render_product_multiselect')) {
    /**
     * Multi product <select>.
     *
     * @param string     $name
     * @param string     $id
     * @param array<int> $selected_ids
     */
    function wwp_admin_render_product_multiselect(string $name, string $id, array $selected_ids = []): void
    {
        $selected_ids = array_map('absint', $selected_ids);
        $products     = wwp_admin_get_products_for_select();
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" multiple style="width:100%;max-width:400px;height:200px;">';
        foreach ($products as $product) {
            if (!$product instanceof WP_Post) {
                continue;
            }
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr((string) $product->ID),
                selected(in_array((int) $product->ID, $selected_ids, true), true, false),
                esc_html($product->post_title . ' [ID: ' . $product->ID . ']')
            );
        }
        echo '</select>';
    }
}
