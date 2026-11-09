<?php
/**
 * WooCommerce My Account wallet UI (Medyar visual skin over shared classnames, MTW logic).
 * All mutating user actions go through authenticated, rate-limited AJAX.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('MTW_MyAccount_UI')) {
    return;
}

class MTW_MyAccount_UI
{
    public const PREFIX = 'sheyda_wallet_';
    public const NONCE_ACTION = 'mtw_wallet_ajax';

    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);
        add_action('wp_enqueue_scripts', [$this, 'dequeue_conflicting_theme_wallet_assets'], 100);

        add_action('wp_ajax_mtw_wallet_topup', [$this, 'ajax_topup']);
        add_action('wp_ajax_mtw_wallet_withdraw', [$this, 'ajax_withdraw']);
        add_action('wp_ajax_mtw_wallet_save_financial', [$this, 'ajax_save_financial']);

        // Theme specialist "financial" form → Medyar bank cards (plugin-only; no theme edits).
        add_action('template_redirect', [$this, 'redirect_specialist_financial_to_bank_cards'], 1);
        add_action('wp', [$this, 'redirect_specialist_financial_to_bank_cards'], 1);
        add_filter('woocommerce_get_endpoint_url', [$this, 'rewrite_specialist_financial_endpoint_url'], 20, 4);
    }

    /**
     * Direct visits to /my-account/specialist-dashboard/financial/ go to wallet bank cards.
     */
    public function redirect_specialist_financial_to_bank_cards(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!empty($GLOBALS['mtw_specialist_financial_redirect_done'])) {
            return;
        }
        if (!$this->is_specialist_financial_request()) {
            return;
        }

        $target = self::section_url('financial');
        $GLOBALS['mtw_specialist_financial_redirect_done'] = true;
        nocache_headers();
        wp_safe_redirect($target, 302);
        exit;
    }

    /**
     * Rewrite generated specialist financial menu/links to wallet bank cards.
     *
     * @param string $url
     * @param string $endpoint
     * @param string $value
     * @param string $permalink
     */
    public function rewrite_specialist_financial_endpoint_url($url, $endpoint, $value, $permalink)
    {
        $endpoint = (string) $endpoint;
        $value    = (string) $value;

        $is_financial = ($endpoint === 'specialist-dashboard/financial')
            || ($endpoint === 'specialist-dashboard' && preg_match('#^financial(?:/|$)#', $value));

        if (!$is_financial) {
            return $url;
        }

        return self::section_url('financial');
    }

    /**
     * Detect theme specialist-dashboard/financial endpoint (query var or URI).
     */
    private function is_specialist_financial_request(): bool
    {
        global $wp;

        $qv = '';
        if (is_object($wp) && isset($wp->query_vars['specialist-dashboard'])) {
            $qv = (string) $wp->query_vars['specialist-dashboard'];
        }
        if ($qv !== '' && preg_match('#(^|/)financial(?:/|$)#', $qv)) {
            return true;
        }

        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '');
        $path = rawurldecode($path);

        if ($path !== '' && preg_match('#(?:^|/)specialist-dashboard/financial(?:/|$)#i', $path)) {
            return true;
        }

        // Fallback: full URI (some hosts rewrite PATH_INFO oddly).
        if ($uri !== '' && preg_match('#specialist-dashboard/financial(?:/|\?|$)#i', $uri)) {
            return true;
        }

        return false;
    }

    public static function sections(): array
    {
        return [
            'dashboard'    => 'پیشخوان',
            'topup'        => 'شارژ کیف پول',
            'withdrawal'   => 'برداشت',
            'transactions' => 'تراکنش‌ها',
            'financial'    => 'کارت‌های بانکی',
        ];
    }

    public static function get_active_section(): string
    {
        $sections = array_keys(self::sections());
        $section  = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        if (!in_array($section, $sections, true)) {
            return 'dashboard';
        }
        return $section;
    }

    public static function base_url(): string
    {
        if (function_exists('wc_get_account_endpoint_url')) {
            return wc_get_account_endpoint_url('wallet');
        }
        return home_url('/my-account/wallet/');
    }

    public static function section_url(string $section, array $args = []): string
    {
        $args['section'] = $section;
        return add_query_arg($args, self::base_url());
    }

    public static function format_price($amount, bool $with_symbol = true): string
    {
        $formatted = number_format_i18n((float) $amount);
        return $with_symbol ? ($formatted . ' تومان') : $formatted;
    }

    /**
     * Format a timestamp/datetime as Jalali for user-facing UI.
     *
     * @param int|string $time Unix timestamp or parseable datetime
     */
    public static function format_jalali($time, string $format = 'Y/m/d H:i'): string
    {
        if (is_numeric($time)) {
            $ts = (int) $time;
        } elseif (is_string($time) && trim($time) !== '') {
            $ts = strtotime($time);
        } else {
            $ts = (int) current_time('timestamp');
        }
        if (!$ts) {
            return '';
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

    public static function operation_labels(): array
    {
        return [
            'charge'       => 'شارژ',
            'withdraw'     => 'برداشت',
            'spend'        => 'خرید',
            'purchase'     => 'خرید',
            'payment'      => 'خرید',
            'admin_add'    => 'واریز دستی',
            'refund'                  => 'بازگشت وجه',
            'refund_order'            => 'بازگشت وجه',
            'booking_payout'          => 'درآمد نوبت',
            'booking_payout_reversal' => 'برگشت درآمد نوبت',
        ];
    }

    /**
     * User-facing operation title for a wallet transaction row.
     *
     * @param object|array $tx
     */
    public static function label_operation_for_tx($tx): string
    {
        if (function_exists('wwp_label_operation_for_tx')) {
            $label = wwp_label_operation_for_tx($tx);
            // Align admin helper wording with FE copy for generic charge.
            if ($label === 'واریز') {
                return 'شارژ';
            }
            return $label;
        }

        $op = is_object($tx)
            ? (string) ($tx->operation_type ?? '')
            : (string) ($tx['operation_type'] ?? '');
        $labels = self::operation_labels();
        return $labels[$op] ?? $op;
    }

    public static function status_labels(): array
    {
        return [
            'pending'    => 'در انتظار',
            'processing' => 'در حال پردازش',
            'completed'  => 'تکمیل‌شده',
            'canceled'   => 'لغو شده',
            'rejected'   => 'رد شده',
        ];
    }

    /**
     * Internal/admin audit lines stored in the same description field.
     * Kept for admin UI; stripped from user-facing wallet pages.
     */
    private static function is_internal_description_line(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return true;
        }

        // SMS delivery logs (Wallet_SMS::format_sms_success_log).
        if (strpos($line, '[لاگ سیستم]') !== false) {
            return true;
        }
        if (preg_match('/پیامک/u', $line) && preg_match('/ارسال\s*شد/u', $line)) {
            return true;
        }
        if (preg_match('/^\[.+\]\s*پیامک/u', $line)) {
            return true;
        }

        // Admin action stamps / test cleanup — never show to end users.
        if (strpos($line, 'انجام‌شده توسط ادمین:') !== false) {
            return true;
        }
        if (strpos($line, 'شناسه ادمین:') !== false) {
            return true;
        }
        if (strpos($line, 'پاک‌سازی تست ادمین') !== false) {
            return true;
        }
        if (preg_match('/توسط\s+(?:ادمین|پشتیبان)\b/u', $line)
            && preg_match('/^(?:تأیید|تایید|لغو|رد|تغییر وضعیت|ویرایش مبلغ)/u', $line)) {
            return true;
        }

        // Ops-only failure notes.
        if (preg_match('/نیازمند بررسی دستی/u', $line)) {
            return true;
        }
        if (preg_match('/خطا در واریز شارژ/u', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Soften internal wording and mask sensitive numbers for end users.
     */
    private static function polish_user_description(string $desc): string
    {
        $desc = self::strip_admin_identity($desc);

        // 16-digit card → 6219 **** **** 9090
        $desc = (string) preg_replace_callback(
            '/\b(\d{4})(\d{8})(\d{4})\b/',
            static function (array $m): string {
                return $m[1] . ' **** **** ' . $m[3];
            },
            $desc
        );

        // Already-spaced 16-digit pans → keep groups, mask middle.
        $desc = (string) preg_replace_callback(
            '/\b(\d{4})\s+(\d{4})\s+(\d{4})\s+(\d{4})\b/',
            static function (array $m): string {
                return $m[1] . ' **** **** ' . $m[4];
            },
            $desc
        );

        // Compact "شبا: IR...." groups.
        $desc = (string) preg_replace_callback(
            '/\bIR\s*([0-9]{24})\b/i',
            static function (array $m): string {
                $digits = $m[1];
                $masked = substr($digits, 0, 4) . str_repeat('*', 16) . substr($digits, -4);
                return 'IR ' . trim(chunk_split($masked, 4, ' '));
            },
            $desc
        );

        // Drop leftover glued SMS fragments if description was flattened earlier.
        $desc = (string) preg_replace(
            '/(?:^|\s*[—\-]\s*)?(?:\[[^\]]*\]\s*)?پیامک[^.«»]*?(?:«[^»]*»)?[^.]*ارسال\s*شد\.?/u',
            '',
            $desc
        );

        $desc = preg_replace('/\s*[—\-]\s*(?=[—\-]|$)/u', '', $desc) ?: $desc;
        $desc = preg_replace('/\s{2,}/u', ' ', $desc) ?: $desc;
        $desc = self::convert_gregorian_dates_to_jalali($desc);

        return trim($desc, " \t\n\r\0\x0B—\-");
    }

    /**
     * Replace Gregorian Y/m/d[+time] runs inside free text with Jalali (leave 13xx/14xx alone).
     */
    private static function convert_gregorian_dates_to_jalali(string $text): string
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

                if (class_exists('\Morilog\Jalali\Jalalian')) {
                    try {
                        $j = \Morilog\Jalali\Jalalian::fromDateTime($g);
                        return $has_time ? $j->format('Y/m/d H:i') : $j->format('Y/m/d');
                    } catch (\Throwable $e) {
                        return $m[0];
                    }
                }

                $ts = strtotime($g);
                return $ts ? date_i18n($has_time ? 'Y/m/d H:i' : 'Y/m/d', $ts) : $m[0];
            },
            $text
        );
    }

    /**
     * Remove any admin/support person identity from user-facing copy.
     */
    private static function strip_admin_identity(string $desc): string
    {
        // "تأیید توسط ادمین فلان از مودال" / bulk variants
        $desc = (string) preg_replace(
            '/(?:تأیید|تایید|لغو|رد|تغییر وضعیت|ویرایش مبلغ)(?:\s+گروهی)?\s+توسط\s+(?:ادمین|پشتیبان)\b[^—\n]*/u',
            '',
            $desc
        );
        $desc = (string) preg_replace('/واریز\s*دستی\s*توسط\s*(?:ادمین|پشتیبان)/u', 'واریز دستی', $desc);
        $desc = (string) preg_replace('/\s*(?:ادمین|پشتیبان)\s*:\s*[^—\n]*/u', '', $desc);
        $desc = (string) preg_replace('/\s*توسط\s+(?:ادمین|پشتیبان)\b[^—\n]*/u', '', $desc);
        // Strip bare role words, but keep «پشتیبانی» (support desk) intact.
        $desc = (string) preg_replace('/(?:ادمین|پشتیبان)(?!ی)/u', '', $desc);

        return $desc;
    }

    /**
     * HTML for FE details: same sanitization + LTR isolates for card/IBAN groups.
     *
     * @param object|array|null $context
     */
    public static function format_tx_description_html(string $description, int $max_len = 0, $context = null): string
    {
        $desc = self::format_tx_description($description, $max_len, $context);
        if ($desc === '') {
            return '';
        }

        $escaped = esc_html($desc);
        // Spaced card pans (incl. masked) and IBAN groups.
        $escaped = (string) preg_replace_callback(
            '/(?:IR\s*)?(?:\d{4}|\*{4})(?:\s+(?:\d{4}|\*{4})){2,}/u',
            static function (array $m): string {
                return '<span class="mtw-ltr-num" dir="ltr">' . $m[0] . '</span>';
            },
            $escaped
        );

        return $escaped;
    }

    /**
     * When every line was internal (e.g. SMS-only), give a short user-safe label.
     *
     * @param object|array|null $context Transaction row or ['operation_type'=>..., 'status'=>...]
     */
    private static function fallback_user_description($context): string
    {
        if ($context === null) {
            return '';
        }
        $op = is_object($context)
            ? (string) ($context->operation_type ?? '')
            : (string) ($context['operation_type'] ?? '');
        $status = is_object($context)
            ? (string) ($context->status ?? '')
            : (string) ($context['status'] ?? '');

        $labels = [
            'charge'       => 'شارژ کیف پول',
            'withdraw'     => 'درخواست برداشت',
            'purchase'     => 'پرداخت سفارش',
            'payment'      => 'پرداخت سفارش',
            'spend'        => 'پرداخت از کیف پول',
            'refund'       => 'بازگشت وجه',
            'refund_order' => 'بازگشت وجه سفارش',
            'admin_add'    => 'افزایش موجودی',
            'admin_reduce' => 'کاهش موجودی',
        ];
        if (is_object($context) || is_array($context)) {
            $type = function_exists('wwp_tx_p_info_type') ? wwp_tx_p_info_type($context) : '';
            $desc = is_object($context)
                ? (string) ($context->description ?? '')
                : (string) ($context['description'] ?? '');
            if ($op === 'admin_add'
                && ($type === 'affiliate_commission' || preg_match('/واریز\s*پورسانت/u', $desc))
            ) {
                $labels['admin_add'] = 'واریز پورسانت';
            } elseif ($op === 'admin_reduce'
                && ($type === 'affiliate_commission_reversal' || preg_match('/برگشت\s*پورسانت/u', $desc))
            ) {
                $labels['admin_reduce'] = 'برگشت پورسانت';
            } elseif ($op === 'charge'
                && ($type === 'admin_manual_deposit' || preg_match('/واریز\s*دستی/u', $desc))
            ) {
                $labels['charge'] = 'واریز دستی';
            }
        }
        $base = $labels[$op] ?? '';
        if ($base === '') {
            return '';
        }
        if ($status === 'completed') {
            return $base . ' — تکمیل‌شده';
        }
        if ($status === 'canceled' || $status === 'rejected') {
            return $base . ' — لغو شده';
        }
        if ($status === 'processing' || $status === 'pending') {
            return $base . ' — در حال بررسی';
        }
        return $base;
    }

    /**
     * True when description lost Persian (??? / U+FFFD / classic mojibake).
     */
    private static function description_looks_broken(string $desc): bool
    {
        if ($desc === '') {
            return false;
        }
        if (strpos($desc, "\xEF\xBF\xBD") !== false) {
            return true;
        }
        if (preg_match('/Ø|Ù|Ã|Â|â€/u', $desc)) {
            return true;
        }
        $q_count = preg_match_all('/\?/u', $desc);
        $arabic  = preg_match_all('/\p{Arabic}/u', $desc);
        // Source file corruption replaced Persian with ASCII "?".
        if ($q_count >= 6 && $arabic < 3) {
            return true;
        }
        if ($q_count >= 3 && $arabic === 0 && preg_match('/\d/u', $desc)) {
            return true;
        }
        return false;
    }

    /**
     * @param object|array|null $context
     * @return array<string,mixed>
     */
    private static function tx_p_info($context): array
    {
        if ($context === null) {
            return [];
        }
        $raw = is_object($context)
            ? ($context->p_info ?? '')
            : ($context['p_info'] ?? '');
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Rebuild a readable description when DB/source text is corrupted.
     *
     * @param object|array|null $context
     */
    private static function rebuild_user_description_from_context($context): string
    {
        if ($context === null) {
            return '';
        }

        $op = is_object($context)
            ? (string) ($context->operation_type ?? '')
            : (string) ($context['operation_type'] ?? '');
        $status = is_object($context)
            ? (string) ($context->status ?? '')
            : (string) ($context['status'] ?? '');
        $amount = abs((float) (is_object($context)
            ? ($context->amount ?? 0)
            : ($context['amount'] ?? 0)));

        if ($op === 'withdraw') {
            $parts = [sprintf('درخواست برداشت %s تومان', number_format($amount, 0))];
            $info  = self::tx_p_info($context);
            $card  = preg_replace('/\D+/', '', (string) ($info['card_number'] ?? ''));
            if (is_string($card) && strlen($card) === 16) {
                $parts[] = 'شماره کارت: ' . $card;
            }
            $iban = strtoupper((string) preg_replace('/\s+/', '', (string) ($info['iban'] ?? '')));
            if ($iban !== '' && preg_match('/^IR\d{24}$/', $iban)) {
                $parts[] = 'شبا: ' . $iban;
            }
            if (in_array($status, ['canceled', 'rejected'], true)) {
                $parts[] = 'لغو شده';
            } elseif ($status === 'completed') {
                $parts[] = 'تأیید و ارسال به بانک';
            } else {
                $parts[] = 'در انتظار بررسی';
            }
            return implode(' — ', $parts);
        }

        return self::fallback_user_description($context);
    }

    /**
     * Clean transaction descriptions for frontend users.
     * Admin UI keeps the raw description (incl. SMS / audit lines).
     *
     * @param int               $max_len  Max characters; 0 = no truncation.
     * @param object|array|null $context  Optional tx row for fallback label.
     */
    public static function format_tx_description(string $description, int $max_len = 96, $context = null): string
    {
        $desc = trim(wp_strip_all_tags($description));
        if ($desc === '') {
            return self::fallback_user_description($context);
        }

        if (self::description_looks_broken($desc)) {
            // Classic double-encoded UTF-8 (Ø/Ù…) — try recover first.
            if (preg_match('/Ø|Ù|Ã|Â|â€/u', $desc)) {
                $candidates = [];
                $iso = @mb_convert_encoding($desc, 'ISO-8859-1', 'UTF-8');
                if (is_string($iso) && $iso !== '') {
                    $candidates[] = $iso;
                }
                if (function_exists('iconv')) {
                    $win = @iconv('UTF-8', 'Windows-1252//IGNORE', $desc);
                    if (is_string($win) && $win !== '') {
                        $candidates[] = $win;
                    }
                }
                $fixed = '';
                foreach ($candidates as $candidate) {
                    if (mb_check_encoding($candidate, 'UTF-8')
                        && preg_match('/\p{Arabic}/u', $candidate)
                        && !self::description_looks_broken($candidate)
                    ) {
                        $fixed = $candidate;
                        break;
                    }
                }
                if ($fixed !== '') {
                    $desc = $fixed;
                } else {
                    $rebuilt = self::rebuild_user_description_from_context($context);
                    return $rebuilt !== '' ? self::polish_user_description($rebuilt) : self::fallback_user_description($context);
                }
            } else {
                // ASCII "?" / U+FFFD corruption — rebuild from p_info + op/status.
                $rebuilt = self::rebuild_user_description_from_context($context);
                if ($rebuilt !== '') {
                    $desc = $rebuilt;
                } else {
                    return self::fallback_user_description($context);
                }
            }
        }

        $lines = preg_split('/\R/u', $desc) ?: [$desc];
        $kept  = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || self::is_internal_description_line($line)) {
                continue;
            }
            $kept[] = $line;
        }

        $desc = implode(' — ', $kept);
        $desc = self::polish_user_description($desc);

        if ($desc === '' || self::description_looks_broken($desc) || !preg_match('/\p{Arabic}/u', $desc)) {
            $rebuilt = self::rebuild_user_description_from_context($context);
            if ($rebuilt !== '') {
                $desc = self::polish_user_description($rebuilt);
            } else {
                return self::fallback_user_description($context);
            }
        }

        if ($max_len > 0) {
            if (function_exists('mb_strlen') && mb_strlen($desc) > $max_len) {
                $desc = mb_substr($desc, 0, max(0, $max_len - 3)) . '…';
            } elseif (strlen($desc) > $max_len) {
                $desc = substr($desc, 0, max(0, $max_len - 3)) . '…';
            }
        }

        return $desc;
    }

    public static function predefined_topup_amounts(): array
    {
        $defaults = [100000, 500000, 1000000, 2000000, 5000000];
        $amounts  = apply_filters('mtw_topup_predefined_amounts', $defaults);
        $clean    = [];
        foreach ((array) $amounts as $amount) {
            $amount = (int) $amount;
            if ($amount > 0) {
                $clean[] = $amount;
            }
        }
        return $clean ?: $defaults;
    }

    public static function min_withdrawal(): int
    {
        return (int) apply_filters('mtw_min_withdrawal', 10000);
    }

    public function enqueue_assets()
    {
        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        if (!$this->is_wallet_request()) {
            return;
        }

        $ver_iconly = (string) @filemtime(MTW_PATH . 'assets/frontend/css/iconly.min.css');
        $ver_wallet = (string) @filemtime(MTW_PATH . 'assets/frontend/css/wallet.min.css');
        $ver_wc     = (string) @filemtime(MTW_PATH . 'assets/frontend/css/wc-wallet.min.css');
        $ver_utils  = (string) @filemtime(MTW_PATH . 'assets/frontend/js/sheyda-utils.js');
        $ver_base   = (string) @filemtime(MTW_PATH . 'assets/frontend/js/sheyda-wallet.js');
        $ver_ui     = (string) @filemtime(MTW_PATH . 'assets/frontend/js/mtw-wc-wallet.js');

        wp_enqueue_style('mtw-wallet-icons', MTW_URL . 'assets/frontend/css/iconly.min.css', [], $ver_iconly ?: MTW_VERSION);
        wp_enqueue_style('mtw-wallet', MTW_URL . 'assets/frontend/css/wallet.min.css', ['mtw-wallet-icons'], $ver_wallet ?: MTW_VERSION);
        wp_enqueue_style('mtw-wallet-wc', MTW_URL . 'assets/frontend/css/wc-wallet.min.css', ['mtw-wallet'], $ver_wc ?: MTW_VERSION);

        $ver_skin = (string) @filemtime(MTW_PATH . 'assets/frontend/css/mtw-medyar-skin.css');
        wp_enqueue_style(
            'mtw-medyar-skin',
            MTW_URL . 'assets/frontend/css/mtw-medyar-skin.css',
            ['mtw-wallet-wc'],
            $ver_skin ?: MTW_VERSION
        );

        wp_enqueue_script('mtw-wallet-utils', MTW_URL . 'assets/frontend/js/sheyda-utils.js', ['jquery'], $ver_utils ?: MTW_VERSION, true);
        wp_enqueue_script('mtw-wallet', MTW_URL . 'assets/frontend/js/sheyda-wallet.js', ['jquery', 'mtw-wallet-utils'], $ver_base ?: MTW_VERSION, true);

        $section = self::get_active_section();
        if (in_array($section, ['financial', 'withdrawal'], true)) {
            wp_enqueue_style(
                'mtw-wallet-select2',
                MTW_URL . 'assets/frontend/libs/select2/select2.min.css',
                [],
                (string) (@filemtime(MTW_PATH . 'assets/frontend/libs/select2/select2.min.css') ?: MTW_VERSION)
            );
            wp_enqueue_script(
                'mtw-wallet-select2',
                MTW_URL . 'assets/frontend/libs/select2/select2.min.js',
                ['jquery'],
                (string) (@filemtime(MTW_PATH . 'assets/frontend/libs/select2/select2.min.js') ?: MTW_VERSION),
                true
            );
        }
        if ($section === 'financial' && class_exists('Medyar_Bank_Cards')) {
            Medyar_Bank_Cards::enqueue_assets();
        } elseif (in_array($section, ['topup', 'withdrawal'], true) && class_exists('Medyar_Bank_Cards')) {
            Medyar_Bank_Cards::enqueue_logo_styles();
        }

        $deps = ['jquery', 'mtw-wallet'];
        if (in_array($section, ['financial', 'withdrawal'], true)) {
            $deps[] = 'mtw-wallet-select2';
        }

        wp_enqueue_script('mtw-wallet-wc', MTW_URL . 'assets/frontend/js/mtw-wc-wallet.js', $deps, $ver_ui ?: MTW_VERSION, true);

        $user_id = get_current_user_id();
        $balance = $user_id ? (int) Wallet_Manager::get_instance()->get_balance($user_id) : 0;
        $min     = self::min_withdrawal();
        $max_w   = function_exists('medyar_wallet_get_max_withdraw') ? medyar_wallet_get_max_withdraw() : 0;
        $max_d   = function_exists('medyar_wallet_get_max_deposit') ? medyar_wallet_get_max_deposit() : 0;

        wp_localize_script('mtw-wallet-wc', 'mtwWalletAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'actions' => [
                'topup'     => 'mtw_wallet_topup',
                'withdraw'  => 'mtw_wallet_withdraw',
                'financial' => 'mtw_wallet_save_financial',
            ],
            'limits'  => [
                'minWithdraw' => $min,
                'maxWithdraw' => $max_w,
                'maxDeposit'  => $max_d,
                'balance'     => $balance,
            ],
            'urls'    => [
                'withdrawal' => self::section_url('withdrawal'),
                'financial'  => self::section_url('financial'),
                'topup'      => self::section_url('topup'),
            ],
            'i18n'    => [
                'genericError'         => 'خطایی رخ داد. دوباره تلاش کنید.',
                'networkError'         => 'خطا در ارتباط با سرور.',
                'securityError'        => 'خطای امنیتی. صفحه را رفرش کنید.',
                'rateLimited'          => 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.',
                'maxWithdrawalError'   => 'مبلغ درخواستی شما بیشتر از موجودی کیف پولتان است.',
                'minWithdrawalError'   => 'مبلغ درخواستی شما کمتر از حداقل مبلغ مجاز برای برداشت است.',
                'withdrawSuccess'      => 'درخواست برداشت با موفقیت ثبت شد.',
                'financialSuccess'     => 'اطلاعات مالی ذخیره شد.',
                'invalidAmount'        => 'مبلغ نامعتبر است.',
                'selectCard'           => 'یک کارت بانکی انتخاب کنید.',
                'saving'               => 'در حال ذخیره…',
            ],
        ]);

        // Backward-compatible aliases used by copied Sheyda helpers.
        wp_localize_script('mtw-wallet-wc', 'sheydaWalletVars', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);
        wp_localize_script('mtw-wallet-wc', 'walletWC', [
            'i18n' => [
                'maxWithdrawalError' => 'مبلغ درخواستی شما بیشتر از موجودی کیف پولتان است.',
                'minWithdrawalError' => 'مبلغ درخواستی شما کمتر از حداقل مبلغ مجاز برای برداشت است.',
            ],
        ]);
    }

    public function render()
    {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('برای مشاهده کیف پول وارد شوید.', 'medyar-toman-wallet') . '</p>';
            return;
        }

        // Safety net if wp_enqueue_scripts ran before endpoint detection.
        $this->enqueue_assets();

        $sections       = self::sections();
        $active_section = self::get_active_section();
        $base_url       = self::base_url();
        $prefix         = self::PREFIX;
        $pay_notice     = self::gateway_return_notice();

        include MTW_PATH . 'templates/myaccount/wallet.php';
    }

    /**
     * پیام نتیجه بازگشت از زرین‌پال (?mtw_pay=ok|fail).
     *
     * @return array{type:string,text:string}|null
     */
    public static function gateway_return_notice(): ?array
    {
        if (!isset($_GET['mtw_pay'])) {
            return null;
        }
        $flag = sanitize_text_field(wp_unslash((string) $_GET['mtw_pay']));
        if ($flag === 'ok') {
            return [
                'type' => 'ok',
                'text' => 'پرداخت با موفقیت تأیید شد و موجودی کیف پول شما شارژ شد.',
            ];
        }
        if ($flag === 'fail') {
            return [
                'type' => 'fail',
                'text' => 'پرداخت تأیید نشد یا لغو شد. در صورت کسر وجه از حساب بانکی، تا ۲۰ دقیقه دیگر وضعیت را در تراکنش‌ها بررسی کنید.',
            ];
        }
        return null;
    }

    private function is_wallet_request(): bool
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('wallet')) {
            return true;
        }

        global $wp;
        if (isset($wp->query_vars['wallet'])) {
            return true;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return (bool) preg_match('#/my-account/wallet(?:/|\?|$)#', $uri);
    }

    public function dequeue_conflicting_theme_wallet_assets(): void
    {
        if (!$this->is_wallet_request()) {
            return;
        }

        // Prevent Sheyda theme wallet JS/CSS from fighting MTW Medyar skin.
        foreach ([
            'sheyda-wallet-wc',
            'sheyda-wallet',
            'sheyda-wallet-utils',
            'sheyda-wallet-select2',
        ] as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }

        foreach ([
            'sheyda-wallet',
            'sheyda-wallet-wc',
            'sheyda-wallet-icons',
        ] as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    /**
     * @return int User ID on success (never returns on failure).
     */
    private function assert_ajax_access(string $rate_bucket, int $max_hits = 8, int $window_seconds = 60): int
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'ورود الزامی است.', 'code' => 'auth'], 401);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'خطای امنیتی. صفحه را رفرش کنید.', 'code' => 'nonce'], 403);
        }

        $user_id = get_current_user_id();
        if (!$this->consume_rate_limit($user_id, $rate_bucket, $max_hits, $window_seconds)) {
            wp_send_json_error(['message' => 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.', 'code' => 'rate_limited'], 429);
        }

        return $user_id;
    }

    private function consume_rate_limit(int $user_id, string $bucket, int $max_hits, int $window_seconds): bool
    {
        $key   = 'mtw_rl_' . $bucket . '_' . $user_id;
        $state = get_transient($key);
        if (!is_array($state) || empty($state['count']) || empty($state['start'])) {
            set_transient($key, ['count' => 1, 'start' => time()], $window_seconds);
            return true;
        }

        $elapsed = time() - (int) $state['start'];
        if ($elapsed >= $window_seconds) {
            set_transient($key, ['count' => 1, 'start' => time()], $window_seconds);
            return true;
        }

        $count = (int) $state['count'];
        if ($count >= $max_hits) {
            return false;
        }

        $state['count'] = $count + 1;
        set_transient($key, $state, max(1, $window_seconds - $elapsed));
        return true;
    }

    public function ajax_topup()
    {
        $user_id = $this->assert_ajax_access('topup', 5, 60);

        $raw_amount = isset($_POST['amount']) ? (string) wp_unslash($_POST['amount']) : '';
        $amount     = (int) preg_replace('/[^\d]/', '', $this->normalize_digits($raw_amount));

        if ($amount < 1000) {
            wp_send_json_error(['message' => 'مبلغ شارژ نامعتبر است.', 'code' => 'invalid_amount']);
        }

        $max = function_exists('medyar_wallet_get_max_deposit') ? medyar_wallet_get_max_deposit() : 500000000;
        if ($amount > $max) {
            $msg = function_exists('medyar_wallet_max_deposit_error_message')
                ? medyar_wallet_max_deposit_error_message($max)
                : 'مبلغ از سقف مجاز بیشتر است.';
            wp_send_json_error(['message' => $msg, 'code' => 'max_exceeded']);
        }

        $card_number = isset($_POST['card_number'])
            ? preg_replace('/\D/', '', (string) wp_unslash($_POST['card_number']))
            : '';

        $user   = get_userdata($user_id);
        $mobile = $this->resolve_user_mobile($user_id);

        $result = Wallet_Manager::get_instance()->initiate_charge_via_gateway($user_id, $amount, [
            'mobile'      => $mobile,
            'email'       => $user ? (string) $user->user_email : '',
            'card_number' => $card_number,
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }

        $redirect = isset($result['redirect_url']) ? esc_url_raw((string) $result['redirect_url']) : '';
        if ($redirect === '') {
            wp_send_json_error(['message' => 'آدرس درگاه در دسترس نیست.', 'code' => 'gateway']);
        }

        wp_send_json_success([
            'checkoutUrl' => $redirect,
            'txId'        => isset($result['tx_id']) ? (int) $result['tx_id'] : 0,
        ]);
    }

    public function ajax_withdraw()
    {
        $user_id = $this->assert_ajax_access('withdraw', 4, 120);

        $raw_amount = isset($_POST['amount']) ? (string) wp_unslash($_POST['amount']) : '';
        $amount     = (int) preg_replace('/[^\d]/', '', $this->normalize_digits($raw_amount));
        $card_key   = isset($_POST['card_number'])
            ? preg_replace('/\D/', '', (string) wp_unslash($_POST['card_number']))
            : '';

        $min = self::min_withdrawal();
        if ($amount < $min) {
            wp_send_json_error([
                'message' => sprintf('حداقل مبلغ درخواست برداشت %s است.', self::format_price($min)),
                'code'    => 'min',
            ]);
        }

        if (strlen($card_key) !== 16) {
            wp_send_json_error(['message' => 'کارت بانکی معتبر انتخاب نشده است.', 'code' => 'card']);
        }

        $cards   = medyar_get_user_bank_cards($user_id);
        $matched = null;
        foreach ($cards as $key => $data) {
            if (preg_replace('/\D/', '', (string) $key) === $card_key) {
                $matched = is_array($data) ? $data : [];
                break;
            }
        }

        if ($matched === null) {
            wp_send_json_error(['message' => 'کارت بانکی معتبر انتخاب نشده است.', 'code' => 'card']);
        }

        $iban  = strtoupper(preg_replace('/\s+/', '', (string) ($matched['iban'] ?? '')));
        $owner = sanitize_text_field((string) ($matched['owner'] ?? ''));
        $bank  = is_array($matched['bank'] ?? null) ? $matched['bank'] : [];

        $result = Wallet_Manager::get_instance()->request_withdrawal(
            $user_id,
            $amount,
            $card_key,
            $iban,
            [
                'owner' => $owner,
                'bank'  => $bank,
            ]
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()]);
        }

        $new_balance = (int) Wallet_Manager::get_instance()->get_balance($user_id);
        $tx_id       = is_array($result) ? (int) ($result['sti'] ?? 0) : 0;
        $max_w       = function_exists('medyar_wallet_get_max_withdraw') ? (int) medyar_wallet_get_max_withdraw() : 0;
        $max_now     = $max_w > 0 ? min($new_balance, $max_w) : $new_balance;
        $now         = current_time('timestamp');
        $status      = 'processing';
        $labels      = self::status_labels();
        $desc_text   = '';
        $desc_html   = '';
        if ($tx_id > 0) {
            $tx_row = Wallet_Transaction_Manager::get_instance()->get_transaction($tx_id);
            if ($tx_row) {
                $raw_desc  = (string) ($tx_row->description ?? '');
                $desc_text = self::format_tx_description($raw_desc, 0, $tx_row);
                $desc_html = self::format_tx_description_html($raw_desc, 0, $tx_row);
            }
        }

        wp_send_json_success([
            'message'          => 'درخواست برداشت با موفقیت ثبت شد.',
            'balance'          => $new_balance,
            'balanceText'      => self::format_price($new_balance),
            'maxAmount'        => $max_now,
            'txId'             => $tx_id,
            'amount'           => $amount,
            'amountText'       => self::format_price($amount),
            'status'           => $status,
            'statusText'       => $labels[$status] ?? $status,
            'opLabel'          => 'برداشت',
            'dateText'         => self::format_jalali($now, 'j F Y'),
            'timeText'         => self::format_jalali($now, 'H:i'),
            'descriptionText'  => $desc_text,
            'descriptionHtml'  => $desc_html,
        ]);
    }

    public function ajax_save_financial()
    {
        $user_id = $this->assert_ajax_access('financial', 6, 60);

        $incoming = [];
        if (isset($_POST['accounts']) && is_array($_POST['accounts'])) {
            $incoming = wp_unslash($_POST['accounts']);
        } elseif (isset($_POST['mtw_financial_account']) && is_array($_POST['mtw_financial_account'])) {
            $incoming = wp_unslash($_POST['mtw_financial_account']);
        }

        $old_cards = medyar_get_user_bank_cards($user_id);
        $new_cards = [];
        $max       = function_exists('medyar_bank_card_max_count') ? medyar_bank_card_max_count() : 3;
        $errors    = [];

        foreach ($incoming as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (count($new_cards) >= $max) {
                break;
            }

            $number = $this->normalize_digits((string) ($row['number'] ?? ''));
            $owner  = sanitize_text_field((string) ($row['owner'] ?? ''));
            $card   = preg_replace('/\D/', '', $number);

            if ($card === '') {
                continue;
            }
            if (strlen($card) !== 16) {
                $errors[] = 'شماره کارت باید ۱۶ رقم باشد.';
                continue;
            }
            if (isset($new_cards[$card])) {
                continue;
            }

            // فقط طول ۱۶ رقم؛ BIN ناشناخته با برچسب خنثی پذیرفته می‌شود.
            $bank = function_exists('medyar_resolve_bank_info_by_card_number')
                ? medyar_resolve_bank_info_by_card_number($card)
                : (medyar_get_bank_info_by_card_number($card) ?: ['fa' => 'کارت بانکی', 'en' => 'bank-logo-default-ws']);

            if ($owner === '') {
                $user  = get_userdata($user_id);
                $owner = $user ? trim((string) $user->display_name) : '';
            }

            $existing_iban = '';
            foreach ($old_cards as $k => $d) {
                if (preg_replace('/\D/', '', (string) $k) === $card) {
                    $existing_iban = sanitize_text_field((string) ($d['iban'] ?? ''));
                    break;
                }
            }

            $new_cards[$card] = [
                'iban'  => $existing_iban,
                'bank'  => [
                    'fa' => sanitize_text_field((string) ($bank['fa'] ?? 'کارت بانکی')),
                    'en' => sanitize_text_field((string) ($bank['en'] ?? 'bank-logo-default-ws')),
                ],
                'owner' => $owner,
            ];
        }

        if (empty($new_cards) && !empty($incoming) && !empty($errors)) {
            wp_send_json_error([
                'message' => $errors[0],
                'code'    => 'validation',
                'errors'  => array_values(array_unique($errors)),
            ]);
        }

        delete_user_meta($user_id, Medyar_Bank_Cards::META_KEY);
        if (!empty($new_cards)) {
            update_user_meta($user_id, Medyar_Bank_Cards::META_KEY, $new_cards);
        }

        $cards_out = [];
        foreach ($new_cards as $num => $data) {
            $digits = preg_replace('/\D/', '', (string) $num);
            $cards_out[] = [
                'number'   => $digits,
                'owner'    => (string) ($data['owner'] ?? ''),
                'bank'     => (string) ($data['bank']['fa'] ?? ''),
                'label'    => trim(chunk_split($digits, 4, '-'), '-'),
            ];
        }

        wp_send_json_success([
            'message' => 'اطلاعات مالی ذخیره شد.',
            'cards'   => $cards_out,
            'count'   => count($cards_out),
        ]);
    }

    private function resolve_user_mobile(int $user_id): string
    {
        foreach (['billing_phone', 'digits_phone', 'mobile'] as $key) {
            $val = (string) get_user_meta($user_id, $key, true);
            if ($val !== '') {
                return $val;
            }
        }
        return '';
    }

    private function normalize_digits(string $value): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
        return strtr($value, $map);
    }
}
