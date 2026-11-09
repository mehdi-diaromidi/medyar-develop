<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small UI helpers for wallet admin (ARP-style).
 */
class MTW_Admin_Ui
{
    const STATUS_COLORS = [
        'pending'    => '#b26200',
        'processing' => '#b26200',
        'completed'  => '#2271b1',
        'canceled'   => '#d63638',
        'minor'      => '#646970',
    ];

    public static function url(string $page, array $args = []): string
    {
        $args['page'] = $page;
        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * @param array<string,string> $tabs slug => label
     */
    public static function tabs(string $page, array $tabs, string $current): void
    {
        echo '<nav class="nav-tab-wrapper wp-clearfix mtw-tabs" aria-label="منوی فرعی تنظیمات">';
        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
                esc_url(self::url($page, ['tab' => $slug])),
                $slug === $current ? ' nav-tab-active' : '',
                $slug === $current ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
    }

    /**
     * @param array<string,string> $tabs
     */
    public static function current_tab(array $tabs): string
    {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : '';
        return isset($tabs[$tab]) ? $tab : (string) array_key_first($tabs);
    }

    public static function money($amount, bool $with_unit = true): string
    {
        $n = number_format_i18n(abs((float) $amount));
        return $with_unit ? ($n . ' تومان') : $n;
    }

    public static function badge(string $label, string $color = '#64748b'): string
    {
        return sprintf(
            '<span class="mtw-badge" style="--mtw-c:%s">%s</span>',
            esc_attr($color),
            esc_html($label)
        );
    }

    public static function status_badge(string $status): string
    {
        $label = function_exists('wwp_label_status') ? wwp_label_status($status) : $status;
        $color = self::STATUS_COLORS[$status] ?? '#64748b';
        return self::badge(wp_strip_all_tags((string) $label), $color);
    }

    public static function notices_from_query(): void
    {
        echo '<div class="mtw-notices" aria-live="polite">';
        if (isset($_GET['updated']) || isset($_GET['wwp_updated'])) {
            $msg = is_string($_GET['wwp_updated'] ?? null) && $_GET['wwp_updated'] !== '1'
                ? urldecode((string) $_GET['wwp_updated'])
                : 'با موفقیت ذخیره شد.';
            echo '<div class="notice notice-success inline is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        if (isset($_GET['wwp_error'])) {
            echo '<div class="notice notice-error inline is-dismissible"><p>' . esc_html(urldecode((string) $_GET['wwp_error'])) . '</p></div>';
        }
        if (class_exists('Wallet_SMS') && !Wallet_SMS::is_sms_enabled() && (($_GET['page'] ?? '') === 'medyar-wallet')) {
            echo '<div class="notice notice-warning inline"><p><strong>توجه:</strong> ارسال پیامک کیف پول غیرفعال است. ';
            echo '<a href="' . esc_url(self::url('medyar-wallet-settings', ['tab' => 'sms'])) . '">تنظیمات پیامک</a></p></div>';
        }
        echo '</div>';
    }

    /**
     * @param array{icon:string,label:string,value:string,meta?:string,url?:string,tone?:string,unit?:string} $kpi
     */
    public static function render_kpi(array $kpi): void
    {
        $tone = $kpi['tone'] ?? 'blue';
        $tag  = !empty($kpi['url']) ? 'a' : 'div';
        $href = !empty($kpi['url']) ? ' href="' . esc_url($kpi['url']) . '"' : '';
        printf(
            '<%1$s class="mtw-kpi mtw-kpi--%2$s"%3$s>',
            $tag,
            esc_attr($tone),
            $href
        );
        echo '<span class="mtw-kpi__icon dashicons ' . esc_attr($kpi['icon']) . '" aria-hidden="true"></span>';
        echo '<span class="mtw-kpi__body">';
        echo '<span class="mtw-kpi__label">' . esc_html($kpi['label']) . '</span>';
        echo '<span class="mtw-kpi__value">' . esc_html($kpi['value']);
        if (!empty($kpi['unit'])) {
            echo ' <small>' . esc_html($kpi['unit']) . '</small>';
        }
        echo '</span>';
        if (!empty($kpi['meta'])) {
            echo '<span class="mtw-kpi__meta">' . esc_html($kpi['meta']) . '</span>';
        }
        echo '</span></' . $tag . '>';
    }
}
