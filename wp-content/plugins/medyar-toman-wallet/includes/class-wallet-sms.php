<?php

/**
 * Wallet SMS Notifications
 *
 * ارسال پیامک از طریق پلاگین «پیامک حرفه‌ای ووکامرس» (PWooSMS / PWSMS)
 *
 * ─── نحوه کار پلاگین ───────────────────────────────────────────────────
 *  تابع PWooSMS() یک instance از PW\PWSMS\Helper برمی‌گرداند.
 *  متد اصلی: send_sms($data)
 *    $data['mobile']  → شماره موبایل (string یا array)
 *    $data['message'] → متن پیام
 *    $data['post_id'] → اختیاری
 *    $data['type']    → نوع پیام (0=normal)
 *
 *  متد SendSMS() deprecated است اما send_sms() را فراخوانی می‌کند.
 *
 * ─── تشخیص شماره موبایل ───────────────────────────────────────────────
 *  اولویت:
 *   1. billing_phone (WooCommerce meta)
 *   2. user_login (اگر فرمت موبایل ایرانی داشته باشد)
 *   3. user_phone (meta سفارشی)
 *
 * ─── اعتبارسنجی موبایل ────────────────────────────────────────────────
 *  پلاگین PWSMS از modify_mobile() استفاده می‌کند که:
 *   - اعداد فارسی/عربی را به انگلیسی تبدیل می‌کند
 *   - فرمت‌های مختلف (09xxx، 9xxx، +989xxx) را normalize می‌کند
 *  ما قبل از ارسال با validate_mobile() پلاگین validate می‌کنیم.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_SMS')) { return; }

class Wallet_SMS
{
    /**
     * Queue context for the SMS currently being built (event slug, priority, dedupe base).
     *
     * @var array{event?:string,priority?:int,dedupe?:string}
     */
    private static $msq_ctx = [];

    /** Medyar SMS Queue plugin is active and its table exists. */
    private static function msq_ready(): bool
    {
        return class_exists('MSQ_Client') && MSQ_Client::is_ready();
    }

    /**
     * @param array{event?:string,priority?:int,dedupe?:string} $ctx
     */
    private static function with_msq_context(array $ctx, callable $fn): void
    {
        $prev          = self::$msq_ctx;
        self::$msq_ctx = $ctx;
        try {
            $fn();
        } finally {
            self::$msq_ctx = $prev;
        }
    }

    /**
     * بررسی اینکه آیا ارسال پیامک فعال است
     * 
     * @return bool
     */
    public static function is_sms_enabled(): bool
    {
        // MTW settings checkbox is authoritative for wallet SMS.
        return (int) get_option('wallet_sms_enabled', 1) === 1;
    }

    /**
     * Active SMS transport label for admin diagnostics.
     *
     * @return array{ready:bool,label:string,detail:string}
     */
    public static function transport_status(): array
    {
        if (self::msq_ready()) {
            return [
                'ready'  => true,
                'label'  => 'صف پیامک Medyar (Faraz، با تلاش مجدد)',
                'detail' => 'MSQ_Client',
            ];
        }
        if (class_exists('Arp_Faraz_Sms_Gateway')) {
            return [
                'ready'  => true,
                'label'  => 'Faraz SMS (affiliate-referral)',
                'detail' => 'Arp_Faraz_Sms_Gateway',
            ];
        }
        if (function_exists('farazsms_is_ready') && farazsms_is_ready() && function_exists('farazsms_send_sms')) {
            return [
                'ready'  => true,
                'label'  => 'Faraz SMS (پلاگین مستقیم)',
                'detail' => 'farazsms_send_sms',
            ];
        }
        if (has_filter('mtw_send_sms')) {
            return [
                'ready'  => true,
                'label'  => 'فیلتر mtw_send_sms',
                'detail' => 'custom filter',
            ];
        }
        if (function_exists('PWSMS')) {
            return [
                'ready'  => true,
                'label'  => 'PWSMS / پیامک ووکامرس',
                'detail' => 'PWSMS()',
            ];
        }
        return [
            'ready'  => false,
            'label'  => 'یافت نشد',
            'detail' => 'هیچ کانال ارسال (Faraz/ARP/PWSMS/filter) فعال نیست',
        ];
    }

    /**
     * Send a one-off test SMS from admin settings (bypasses enabled gate for diagnosis).
     *
     * @return array{ok:bool,message:string,transport:string,mobile:string}
     */
    public static function send_test_sms(string $mobile, string $message = ''): array
    {
        $mobile = trim($mobile);
        $mobile = str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $mobile
        );
        $digits = preg_replace('/\D/', '', $mobile);
        if (!preg_match('/^(0?9\d{9}|989\d{9})$/', (string) $digits)) {
            return [
                'ok'        => false,
                'message'   => 'شماره موبایل معتبر نیست (مثال: 09121234567).',
                'transport' => '',
                'mobile'    => $mobile,
            ];
        }
        if (strpos($digits, '98') === 0 && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (strpos($digits, '9') === 0 && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }
        $mobile = $digits;

        $message = trim($message);
        if ($message === '') {
            $message = sprintf(
                'تست پیامک کیف پول مدیار — %s',
                self::now_jalali_stamp()
            );
        }

        $transport = self::transport_status();
        if (!$transport['ready']) {
            return [
                'ok'        => false,
                'message'   => $transport['detail'],
                'transport' => $transport['label'],
                'mobile'    => $mobile,
            ];
        }

        // Bypass is_sms_enabled so admins can diagnose even when the master switch is off.
        $ok = self::send_raw($mobile, $message);

        return [
            'ok'        => $ok,
            'message'   => $ok
                ? 'پیامک تست با موفقیت ارسال شد.'
                : 'ارسال ناموفق بود. لاگ سرور ([WalletSMS]) و تنظیمات Faraz/PWSMS را بررسی کنید.',
            'transport' => $transport['label'],
            'mobile'    => $mobile,
        ];
    }

    /**
     * Low-level send used by tests — does not check wallet_sms_enabled and does not append tx logs.
     */
    private static function send_raw(string $mobile, string $message): bool
    {
        error_log("[WalletSMS] TEST send attempt: mobile=$mobile msg_len=" . mb_strlen($message));

        if (class_exists('Arp_Faraz_Sms_Gateway')) {
            $ok = (new Arp_Faraz_Sms_Gateway())->send($mobile, $message);
            error_log('[WalletSMS] TEST Faraz(ARP) ' . ($ok ? 'OK' : 'FAIL') . " mobile=$mobile");
            return (bool) $ok;
        }

        if (function_exists('farazsms_is_ready') && farazsms_is_ready() && function_exists('farazsms_send_sms')) {
            $result = farazsms_send_sms($mobile, $message);
            $ok     = is_array($result) ? !empty($result['ok']) : (bool) $result;
            error_log('[WalletSMS] TEST Faraz(direct) ' . ($ok ? 'OK' : 'FAIL') . " mobile=$mobile");
            return $ok;
        }

        $filtered = apply_filters('mtw_send_sms', null, $mobile, $message, 0);
        if (is_bool($filtered)) {
            error_log('[WalletSMS] TEST filter ' . ($filtered ? 'OK' : 'FAIL') . " mobile=$mobile");
            return $filtered;
        }

        if (!function_exists('PWSMS')) {
            error_log("[WalletSMS] TEST FAILED: no transport");
            return false;
        }

        $sms = PWSMS();
        if (!is_object($sms) || !method_exists($sms, 'send_sms')) {
            error_log('[WalletSMS] TEST FAILED: PWSMS unavailable');
            return false;
        }
        if (method_exists($sms, 'validate_mobile') && !$sms->validate_mobile($mobile)) {
            error_log("[WalletSMS] TEST SKIPPED: invalid mobile for PWSMS ($mobile)");
            return false;
        }

        $ok = (bool) $sms->send_sms([
            'post_id' => 0,
            'type'    => 0,
            'mobile'  => $mobile,
            'message' => $message,
        ]);
        error_log('[WalletSMS] TEST PWSMS ' . ($ok ? 'OK' : 'FAIL') . " mobile=$mobile");
        return $ok;
    }

    /** @return array<string,string> */
    public static function default_templates(): array
    {
        return [
            'deposit_completed'  => '{name} عزیز، کیف پول شما به مبلغ {amount} تومان شارژ شد.',
            'deposit_canceled'   => '{name} عزیز، درخواست واریز شما لغو شد. در صورت کسر وجه، تا ۷۲ ساعت به کارت بانکی برگشت می‌خورد.',
            'withdraw_registered'=> '{name} عزیز، درخواست برداشت شما به مبلغ {amount} تومان ثبت شد و در صف بررسی قرار گرفت.',
            'withdraw_completed' => '{name} عزیز، درخواست برداشت شما به مبلغ {amount} تومان تایید شد و به بانک ارسال گردید.',
            'withdraw_canceled'  => '{name} عزیز، درخواست برداشت شما به مبلغ {amount} تومان لغو شد و مبلغ به کیف پول عودت داده شد.',
        ];
    }

    private static function template_for(string $key): string
    {
        $defaults = self::default_templates();
        $default  = $defaults[$key] ?? '';
        $option   = 'arp_wallet_sms_' . $key;
        $tpl      = (string) get_option($option, $default);
        return $tpl !== '' ? $tpl : $default;
    }

    /** Optional Faraz pattern code for a wallet SMS template key (empty = free-text). */
    private static function pattern_for(string $key): string
    {
        return trim((string) get_option('arp_wallet_sms_' . $key . '_pattern', ''));
    }

    /** @param array<string,string> $vars */
    private static function render_template(string $template, array $vars): string
    {
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }
        return trim(strtr($template, $replace));
    }

    /** Sanitize Faraz pattern code from settings. */
    public static function sanitize_pattern_option($raw): string
    {
        if (class_exists('Arp_Faraz_Sms_Gateway') && method_exists('Arp_Faraz_Sms_Gateway', 'sanitize_pattern_option')) {
            return Arp_Faraz_Sms_Gateway::sanitize_pattern_option($raw);
        }
        $code = sanitize_text_field((string) $raw);
        $code = preg_replace('/[^a-zA-Z0-9_\-]/', '', $code);
        return is_string($code) ? $code : '';
    }

    private static function deposit_sms_allowed(): bool
    {
        if (!self::is_sms_enabled()) {
            return false;
        }
        return '1' === (string) get_option('arp_wallet_deposit_sms_enabled', '1');
    }

    private static function withdraw_sms_allowed(): bool
    {
        if (!self::is_sms_enabled()) {
            return false;
        }
        return '1' === (string) get_option('arp_wallet_withdraw_sms_enabled', '1');
    }

    /**
     * لاگ تشخیصی Match/Notify — هم error_log و هم فایل:
     * wp-content/trade-match-notify.log
     */
    public static function debug_log(string $message): void
    {
        $line = '[TradeMatchNotify] ' . $message;
        error_log($line);

        $dir  = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : dirname(__DIR__, 3);
        $file = rtrim(str_replace('\\', '/', (string) $dir), '/') . '/trade-match-notify.log';
        $stamp = gmdate('Y-m-d H:i:s') . ' UTC';
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @file_put_contents($file, $stamp . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function asset_title(string $asset_key): string
    {
        if (function_exists('wwp_label_balance_type')) {
            return wwp_label_balance_type($asset_key);
        }
        if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)
            && !empty(medyar_STATIC_ASSETS[$asset_key]['title_fa'])) {
            return (string) medyar_STATIC_ASSETS[$asset_key]['title_fa'];
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($asset_key);
            if ($asset) {
                return (string) ($asset['title_fa'] ?: $asset['title_en'] ?: $asset_key);
            }
        }
        return $asset_key;
    }

    private static function asset_unit(string $asset_key): string
    {
        if (function_exists('wwp_asset_unit_label')) {
            return wwp_asset_unit_label($asset_key);
        }
        if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)
            && !empty(medyar_STATIC_ASSETS[$asset_key]['unit_label'])) {
            return (string) medyar_STATIC_ASSETS[$asset_key]['unit_label'];
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($asset_key);
            if ($asset) {
                return (string) ($asset['unit_label'] ?? '');
            }
        }
        return '';
    }

    private static function asset_is_decimal(string $asset_key): bool
    {
        if (function_exists('wwp_asset_is_decimal')) {
            return wwp_asset_is_decimal($asset_key);
        }
        if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)
            && isset(medyar_STATIC_ASSETS[$asset_key]['is_decimal'])) {
            return (bool) medyar_STATIC_ASSETS[$asset_key]['is_decimal'];
        }
        if (class_exists('Asset_Settings_Manager')) {
            $asset = Asset_Settings_Manager::get_instance()->get_asset($asset_key);
            if ($asset) {
                return !empty($asset['is_decimal']);
            }
        }
        return $asset_key !== 'bullion';
    }

    private static function format_asset_amount(float $amount, string $asset_key): string
    {
        $num = self::format_asset_number($amount, $asset_key);
        $unit = self::asset_unit($asset_key);
        return trim($num . ' ' . $unit);
    }

    /** عدد دارایی بدون واحد (اعشار طبق تنظیمات دارایی). */
    private static function format_asset_number(float $amount, string $asset_key): string
    {
        return self::asset_is_decimal($asset_key)
            ? number_format($amount, 3)
            : number_format($amount, 0);
    }

    /**
     * تراکنش‌های ساخته‌شده از صفحه تست عملیات (wallet-test-actions).
     */
    private static function is_admin_test_action_tx(object $tx): bool
    {
        $op = sanitize_key((string) ($tx->operation_type ?? ''));
        if ($op === 'admin_add' || $op === 'admin_reduce') {
            return true;
        }
        $desc = (string) ($tx->description ?? '');
        return $desc !== '' && strpos($desc, 'انجام‌شده توسط ادمین:') !== false;
    }
    // ═══════════════════════════════════════════════════════════════════
    // Bootstrap
    // ═══════════════════════════════════════════════════════════════════

    public static function init(): void
    {
        add_action('wallet_transaction_created',        [__CLASS__, 'on_created'],        10, 5);
        add_action('wallet_transaction_status_changed', [__CLASS__, 'on_status_changed'], 10, 5);
        add_action('msq_job_sent_wallet',               [__CLASS__, 'on_queue_sent'],     10, 1);
    }

    /**
     * Side effects that used to run right after a synchronous send (tx description log, sent hook).
     *
     * @param array<string,mixed> $job Decoded MSQ job.
     */
    public static function on_queue_sent(array $job): void
    {
        $meta    = isset($job['meta']) && is_array($job['meta']) ? $job['meta'] : [];
        $ref     = (int) ($meta['ref'] ?? 0);
        $user_id = (int) ($meta['user_id'] ?? 0);
        $message = (string) ($meta['log_message'] ?? ($job['body'] ?? ''));
        $mobile  = (string) ($job['phone'] ?? '');
        if (!empty($meta['append_log']) && $ref > 0) {
            self::log_sms_in_transaction($ref, $mobile, $message);
        }
        if ($user_id > 0) {
            do_action('medyar_wallet_sms_sent', $user_id, (string) ($job['body'] ?? ''), (string) ($meta['notif_type'] ?? ''), $ref);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Hooks
    // ═══════════════════════════════════════════════════════════════════

    /**
     * هنگام ثبت تراکنش جدید
     * برداشت مستقیماً با processing شروع می‌کند → اینجا پیامک می‌زنیم
     */
    public static function on_created(
        int    $tx_id,
        string $tx_code,
        string $status,
        string $prev_status,
        array  $row
    ): void {
        $op = $row['operation_type'] ?? '';

        error_log(sprintf(
            '[WalletSMS] on_created: tx=%d op=%s status=%s',
            $tx_id,
            $op,
            $status
        ));

        if ($op === 'withdraw' && $status === 'processing') {
            $tx     = (object) $row;
            $tx->id = $tx_id;
            // Defer Faraz so wallet AJAX never waits on SMS API timeouts.
            self::defer_sms(static function () use ($tx): void {
                self::sms_withdraw_registered($tx);
            });
        }

        // شارژ / افزودن موجودی که مستقیماً با status=completed ثبت می‌شود
        // (تست عملیات ادمین، واریز دستی، add_balance با completed) — بدون transition pending→completed
        // Defer to shutdown so SMS runs after outer DB COMMIT in Wallet_Manager::add_balance.
        if (($op === 'charge' || $op === 'admin_add') && $status === 'completed'
            && floatval($row['amount'] ?? 0) > 0) {
            $tx     = (object) $row;
            $tx->id = $tx_id;
            self::defer_sms(static function () use ($tx): void {
                self::sms_charge_completed($tx);
            });
        }
    }

    /**
     * Queue SMS after the HTTP response so AJAX never blocks on Faraz/PWSMS.
     * Host is LiteSpeed — must call litespeed_finish_request (fastcgi_* is unavailable).
     *
     * @param callable():void $send
     */
    private static function defer_sms(callable $send): void
    {
        // Queue insert is cheap and joins the open DB transaction; the worker spawns at shutdown after COMMIT.
        if (self::msq_ready()) {
            $send();
            return;
        }
        // Do NOT call litespeed_finish_request before Faraz — it aborts the worker mid-send.
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (did_action('shutdown')) {
            $send();
            return;
        }
        add_action('shutdown', static function () use ($send): void {
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
            $send();
        }, 5);
    }

    private static function finish_http_response(): void
    {
        // Kept for BC; intentionally a no-op so LiteSpeed does not kill SMS work.
    }

    /**
     * هنگام تغییر وضعیت هر تراکنش
     */
    public static function on_status_changed(
        int    $tx_id,
        string $tx_code,
        string $new_status,
        string $prev_status,
        object $tx
    ): void {
        $op  = $tx->operation_type ?? '';
        $bal = $tx->balance_type   ?? '';

        error_log(sprintf(
            '[WalletSMS] on_status_changed: tx=%d op=%s bal=%s %s→%s',
            $tx_id,
            $op,
            $bal,
            $prev_status,
            $new_status
        ));

        // ── واریز / شارژ (تومان و سایر دارایی‌ها) ───────────────────────
        if (($op === 'charge' || $op === 'admin_add') && $prev_status === 'pending' && $new_status === 'completed'
            && floatval($tx->amount ?? 0) > 0) {
            self::sms_charge_completed($tx);
        }
        if ($op === 'charge' && $bal === 'toman' && $prev_status === 'completed' && $new_status === 'canceled') {
            self::sms_charge_canceled($tx);
        }

        // ── برداشت ───────────────────────────────────────────────────
        // Accept pending|processing → completed|canceled|rejected (admin bulk also allows pending).
        if ($op === 'withdraw') {
            $from_open = in_array($prev_status, ['pending', 'processing'], true);
            if ($from_open && $new_status === 'completed') {
                self::sms_withdraw_completed($tx);
            }
            if ($from_open && in_array($new_status, ['canceled', 'rejected'], true)) {
                self::sms_withdraw_canceled($tx);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // پیام‌های هر حالت
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Dual notify: SMS always via Wallet_SMS (optional Faraz pattern); in-app best-effort.
     *
     * @param array<string,string> $pattern_vars
     * @return bool true وقتی پیامک واقعاً ارسال شد
     */
    private static function dual_notify(
        int $user_id,
        string $type,
        string $title,
        string $inapp,
        string $sms,
        int $ref_id = 0,
        string $pattern = '',
        array $pattern_vars = []
    ): bool {
        $user_id = absint($user_id);
        $sms     = trim($sms);
        $pattern = trim($pattern);
        if ($user_id <= 0 || ($sms === '' && $pattern === '')) {
            return false;
        }

        $sms_ok = self::send_user_message_only($user_id, $sms, $ref_id, $pattern, $pattern_vars);

        if (function_exists('medyar_notify_user')) {
            medyar_notify_user(
                $user_id,
                $type,
                $title,
                $inapp !== '' ? $inapp : $sms,
                $sms_ok ? '' : $sms,
                $ref_id
            );
        }

        return $sms_ok;
    }

    /**
     * Deposit/charge notify — SMS always via Wallet_SMS (do not depend on theme bridge).
     * In-app is best-effort via medyar_notify_user without re-sending SMS.
     *
     * @param array<string,string> $pattern_vars
     */
    private static function notify_deposit_user(
        int $user_id,
        string $title,
        string $inapp,
        string $sms,
        int $ref_id = 0,
        string $pattern = '',
        array $pattern_vars = []
    ): bool {
        $user_id = absint($user_id);
        $sms     = trim($sms);
        $pattern = trim($pattern);
        if ($user_id <= 0 || ($sms === '' && $pattern === '')) {
            return false;
        }

        $sms_ok = self::send_user_message_only($user_id, $sms, $ref_id, $pattern, $pattern_vars);

        if (function_exists('medyar_notify_user')) {
            medyar_notify_user(
                $user_id,
                'deposit',
                $title,
                $inapp !== '' ? $inapp : $sms,
                $sms_ok ? '' : $sms,
                $ref_id
            );
        }

        error_log(sprintf(
            '[WalletSMS] deposit_notify user=%d ref=%d sms=%s pattern=%s',
            $user_id,
            $ref_id,
            $sms_ok ? 'ok' : 'fail',
            $pattern !== '' ? $pattern : '-'
        ));

        return $sms_ok;
    }

    /**
     * خط لاگ یکسان برای توضیحات تراکنش / معامله پس از ارسال موفق پیامک.
     */
    private static function format_sms_success_log(string $message, string $mobile = ''): string
    {
        $stamp = self::now_jalali_stamp();
        $msg = trim(preg_replace("/\s+/u", ' ', $message));
        if ($msg !== '') {
            if (function_exists('mb_strlen') && mb_strlen($msg) > 280) {
                $msg = mb_substr($msg, 0, 277) . '…';
            } elseif (strlen($msg) > 280) {
                $msg = substr($msg, 0, 277) . '…';
            }
            return sprintf(
                "\n[%s] [لاگ سیستم] پیامک «%s» با موفقیت به کاربر ارسال شد.",
                $stamp,
                $msg
            );
        }
        if ($mobile !== '') {
            return sprintf(
                "\n[%s] [لاگ سیستم] پیامک با موفقیت به شماره %s ارسال شد.",
                $stamp,
                $mobile
            );
        }
        return sprintf(
            "\n[%s] [لاگ سیستم] پیامک با موفقیت به کاربر ارسال شد.",
            $stamp
        );
    }

    /** Jalali stamp for description/SMS logs. */
    private static function now_jalali_stamp(): string
    {
        if (class_exists('MTW_MyAccount_UI')) {
            $s = MTW_MyAccount_UI::format_jalali(current_time('timestamp'), 'Y/m/d H:i');
            if ($s !== '') {
                return $s;
            }
        }
        if (class_exists('\Morilog\Jalali\Jalalian')) {
            try {
                return \Morilog\Jalali\Jalalian::forge((int) current_time('timestamp'))->format('Y/m/d H:i');
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return current_time('Y/m/d H:i');
    }

    private static function account_url(string $endpoint, string $query = ''): string
    {
        if (function_exists('medyar_notify_account_url')) {
            return medyar_notify_account_url($endpoint, $query);
        }
        if (function_exists('wc_get_account_endpoint_url')) {
            $url = wc_get_account_endpoint_url($endpoint);
            return $query !== '' ? add_query_arg(wp_parse_args($query), $url) : $url;
        }
        $url = home_url('/my-account/' . trailingslashit($endpoint));
        return $query !== '' ? $url . '?' . ltrim($query, '?') : $url;
    }

    /** واریز/شارژ: completed (از status change یا create مستقیم) */
    private static function sms_charge_completed(object $tx): void
    {
        if (!self::deposit_sms_allowed()) {
            return;
        }
        $tx_id = intval($tx->id ?? 0);
        static $sent = [];
        if ($tx_id > 0) {
            if (isset($sent[$tx_id])) {
                return;
            }
            $sent[$tx_id] = true;
        }
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $bal  = sanitize_key((string) ($tx->balance_type ?? 'toman'));
        $amount = abs(floatval($tx->amount ?? 0));
        $title = 'شارژ دارایی';

        $vars = [
            'name'   => $name,
            'amount' => '',
            'status' => 'تکمیل‌شده',
        ];
        if ($bal === '' || $bal === 'toman') {
            $vars['amount'] = number_format($amount);
            $body           = self::render_template(self::template_for('deposit_completed'), $vars);
        } else {
            $asset          = self::asset_title($bal);
            $num            = self::format_asset_number($amount, $bal);
            $unit           = self::asset_unit($bal);
            $qty            = trim($num . ($unit !== '' ? ' ' . $unit : ''));
            $vars['amount'] = $qty;
            $vars['asset']  = $asset;
            $body           = "{$name} عزیز، کیف پول شما به میزان {$qty} {$asset} شارژ شد.";
        }

        if (self::is_admin_test_action_tx($tx)) {
            $inapp = $body;
        } else {
            $link  = self::account_url('wallet', 'section=transactions');
            $inapp = $body . "\nمشاهده تراکنش‌ها: {$link}";
        }
        self::with_msq_context(
            ['event' => 'deposit_completed', 'dedupe' => $tx_id > 0 ? 'wallet:deposit_completed:tx' . $tx_id : ''],
            static function () use ($uid, $title, $inapp, $body, $tx_id, $vars): void {
                self::notify_deposit_user($uid, $title, $inapp, $body, $tx_id, self::pattern_for('deposit_completed'), $vars);
            }
        );
    }

    /** واریز: completed → canceled */
    private static function sms_charge_canceled(object $tx): void
    {
        if (!self::deposit_sms_allowed()) {
            return;
        }
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $vars = [
            'name'   => $name,
            'amount' => number_format(abs(floatval($tx->amount ?? 0))),
            'status' => 'لغو‌شده',
        ];
        $body  = self::render_template(self::template_for('deposit_canceled'), $vars);
        $link  = self::account_url('wallet', 'section=transactions');
        $tx_id = intval($tx->id ?? 0);
        self::with_msq_context(
            ['event' => 'deposit_canceled', 'dedupe' => $tx_id > 0 ? 'wallet:deposit_canceled:tx' . $tx_id : ''],
            static function () use ($uid, $body, $link, $tx_id, $vars): void {
                self::notify_deposit_user($uid, 'شارژ دارایی', $body . "\nمشاهده تراکنش‌ها: {$link}", $body, $tx_id, self::pattern_for('deposit_canceled'), $vars);
            }
        );
    }

    /**
     * Notify user about a withdrawal event.
     * SMS is always sent by Wallet_SMS (status-change must not depend on theme bridge).
     * In-app notification is best-effort via medyar_notify_user without re-sending SMS.
     *
     * @param array<string,string> $pattern_vars
     */
    private static function notify_withdraw_user(
        int $user_id,
        string $title,
        string $inapp,
        string $sms,
        int $ref_id = 0,
        string $pattern = '',
        array $pattern_vars = []
    ): bool {
        $user_id = absint($user_id);
        $sms     = trim($sms);
        $pattern = trim($pattern);
        if ($user_id <= 0 || ($sms === '' && $pattern === '')) {
            return false;
        }

        $sms_ok = self::send_user_message_only($user_id, $sms, $ref_id, $pattern, $pattern_vars);

        if (function_exists('medyar_notify_user')) {
            // Pass empty SMS when already sent to avoid duplicate messages.
            medyar_notify_user(
                $user_id,
                'withdraw',
                $title,
                $inapp !== '' ? $inapp : $sms,
                $sms_ok ? '' : $sms,
                $ref_id
            );
        }

        error_log(sprintf(
            '[WalletSMS] withdraw_notify user=%d ref=%d sms=%s pattern=%s',
            $user_id,
            $ref_id,
            $sms_ok ? 'ok' : 'fail',
            $pattern !== '' ? $pattern : '-'
        ));

        return $sms_ok;
    }

    /** برداشت: ثبت (processing) */
    private static function sms_withdraw_registered(object $tx): void
    {
        if (!self::withdraw_sms_allowed()) {
            return;
        }
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $amount = number_format(abs(floatval($tx->amount)));
        $link = self::account_url('withdrawal', 'scroll=tx');
        $title = 'برداشت از کیف پول';
        $vars = [
            'name'   => $name,
            'amount' => $amount,
            'status' => 'در صف بررسی',
        ];
        $body  = self::render_template(self::template_for('withdraw_registered'), $vars);
        $tx_id = intval($tx->id ?? 0);
        // Staff first: their SMS gets queue priority and is enqueued before the user's.
        self::with_msq_context(
            ['event' => 'withdraw_admin', 'priority' => 10, 'dedupe' => 'wallet:withdraw_admin:tx' . $tx_id],
            static function () use ($tx, $name, $amount): void {
                self::notify_withdraw_admins($tx, $name, $amount);
            }
        );
        self::with_msq_context(
            ['event' => 'withdraw_registered', 'dedupe' => 'wallet:withdraw_registered:tx' . $tx_id],
            static function () use ($uid, $title, $body, $link, $tx_id, $vars): void {
                self::notify_withdraw_user($uid, $title, $body . "\nمشاهده درخواست‌های برداشت: {$link}", $body, $tx_id, self::pattern_for('withdraw_registered'), $vars);
            }
        );
    }

    /**
     * SMS (+ optional in-app) to selected deposit/withdrawal admins when a withdraw is registered.
     */
    private static function notify_withdraw_admins(object $tx, string $user_name, string $amount): void
    {
        $admin_ids = get_option('medyar_deposit_withdrawal_admin_ids', []);
        if (!is_array($admin_ids)) {
            $admin_ids = [];
        }
        $admin_ids = array_values(array_unique(array_filter(array_map('absint', $admin_ids))));
        if ($admin_ids === []) {
            error_log('[WalletSMS] withdraw_admin_notify skipped: no admin ids selected');
            return;
        }

        $ref        = intval($tx->id ?? 0);
        $admin_link = admin_url('admin.php?page=medyar-wallet-withdrawals');
        $sms        = sprintf(
            'درخواست برداشت جدید به مبلغ %s تومان از %s ثبت شد و در انتظار بررسی است.%s',
            $amount,
            $user_name !== '' ? $user_name : 'کاربر',
            $admin_link !== '' ? ("\n" . $admin_link) : ''
        );
        $pattern = self::pattern_for('withdraw_admin');
        $vars    = [
            'name'   => $user_name !== '' ? $user_name : 'کاربر',
            'amount' => $amount,
            'url'    => $admin_link,
        ];

        $sent = 0;
        foreach ($admin_ids as $admin_id) {
            // ref_id=0: do not append admin SMS logs onto the user transaction description.
            if (self::send_user_message_only($admin_id, $sms, 0, $pattern, $vars)) {
                $sent++;
            }
        }

        if (function_exists('medyar_notify_admins')) {
            medyar_notify_admins($admin_ids, $sms, $ref);
        }

        error_log(sprintf(
            '[WalletSMS] withdraw_admin_notify tx=%d admins=%d sms_ok=%d pattern=%s',
            $ref,
            count($admin_ids),
            $sent,
            $pattern !== '' ? $pattern : '-'
        ));
    }

    /** برداشت: pending/processing → completed (= ارسال به بانک) */
    private static function sms_withdraw_completed(object $tx): void
    {
        if (!self::withdraw_sms_allowed()) {
            return;
        }
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $amount = number_format(abs(floatval($tx->amount)));
        $link = self::account_url('withdrawal', 'scroll=tx');
        $title = 'برداشت از کیف پول';
        $vars = [
            'name'   => $name,
            'amount' => $amount,
            'status' => 'تایید‌شده',
        ];
        $body  = self::render_template(self::template_for('withdraw_completed'), $vars);
        $tx_id = intval($tx->id ?? 0);
        self::with_msq_context(
            ['event' => 'withdraw_completed', 'dedupe' => 'wallet:withdraw_completed:tx' . $tx_id],
            static function () use ($uid, $title, $body, $link, $tx_id, $vars): void {
                self::notify_withdraw_user($uid, $title, $body . "\nمشاهده درخواست‌های برداشت: {$link}", $body, $tx_id, self::pattern_for('withdraw_completed'), $vars);
            }
        );
    }

    /** برداشت: pending/processing → canceled|rejected */
    private static function sms_withdraw_canceled(object $tx): void
    {
        if (!self::withdraw_sms_allowed()) {
            return;
        }
        $uid = intval($tx->user_id);
        $name = function_exists('medyar_notify_user_display_name') ? medyar_notify_user_display_name($uid) : (self::get_user_data($uid)['name'] ?? 'کاربر');
        $amount = number_format(abs(floatval($tx->amount)));
        $link = self::account_url('withdrawal', 'scroll=tx');
        $title = 'برداشت از کیف پول';
        $vars = [
            'name'   => $name,
            'amount' => $amount,
            'status' => 'لغو‌شده',
        ];
        $body  = self::render_template(self::template_for('withdraw_canceled'), $vars);
        $tx_id = intval($tx->id ?? 0);
        self::with_msq_context(
            ['event' => 'withdraw_canceled', 'dedupe' => 'wallet:withdraw_canceled:tx' . $tx_id],
            static function () use ($uid, $title, $body, $link, $tx_id, $vars): void {
                self::notify_withdraw_user($uid, $title, $body . "\nمشاهده درخواست‌های برداشت: {$link}", $body, $tx_id, self::pattern_for('withdraw_canceled'), $vars);
            }
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // اطلاعات کاربر
    // ═══════════════════════════════════════════════════════════════════

    /**
     * اولویت تشخیص موبایل:
     *  1. billing_phone (WooCommerce)
     *  2. user_login (اگر فرمت موبایل ایرانی داشته باشد)
     *  3. user_phone (meta سفارشی)
     *
     * @return array{name:string,mobile:string}|null
     */
    private static function get_user_data(int $user_id): ?array
    {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log("[WalletSMS] user NOT FOUND: user_id=$user_id");
            return null;
        }

        $name = trim($user->first_name . ' ' . $user->last_name);
        if (empty($name)) {
            $name = $user->display_name ?: 'کاربر';
        }

        // ① billing_phone  ② mobile  ③ user_login  ④ user_phone
        $candidates = [
            (string) get_user_meta($user_id, 'billing_phone', true),
            (string) get_user_meta($user_id, 'mobile', true),
            (string) $user->user_login,
            (string) get_user_meta($user_id, 'user_phone', true),
        ];
        $mobile = '';
        foreach ($candidates as $candidate) {
            $normalized = self::normalize_mobile($candidate);
            if ($normalized !== '') {
                $mobile = $normalized;
                break;
            }
        }

        if ($mobile === '') {
            error_log("[WalletSMS] mobile NOT FOUND for user_id=$user_id login={$user->user_login}");
            return null;
        }

        error_log("[WalletSMS] user_id=$user_id name=$name mobile=$mobile");
        return ['name' => $name, 'mobile' => $mobile];
    }

    /**
     * شماره موبایل کاربر (همان منطق get_user_data) — برای معاملات و سایر مسیرها
     */
    private static function get_user_mobile(int $user_id): string
    {
        $data = self::get_user_data($user_id);
        if (!$data || empty($data['mobile'])) {
            return '';
        }
        return (string) $data['mobile'];
    }

    /**
     * شماره موبایل کامل کاربر برای نمایش در پنل ادمین (بدون کاراکترهای جایگزین مثل *)
     */
    public static function get_resolved_mobile(int $user_id): string
    {
        return self::get_user_mobile($user_id);
    }

    /** Public wrapper for admin notify helpers. */
    public static function get_user_mobile_public(int $user_id): string
    {
        return self::get_user_mobile($user_id);
    }

    /**
     * ارسال پیامک متنی به کاربر (و در صورت موفقیت، نوتیفیکیشن داخل‌پنل).
     */
    public static function send_user_message(int $user_id, string $message, string $notif_type = 'document', int $ref_id = 0): bool
    {
        $user_id = absint($user_id);
        $message = trim($message);
        if ($user_id <= 0 || $message === '') {
            return false;
        }

        $mobile = self::get_user_mobile($user_id);
        if ($mobile === '') {
            return false;
        }

        return self::send($mobile, $message, $ref_id, false, $user_id, sanitize_key($notif_type));
    }

    /**
     * SMS only — does not emit medyar_wallet_sms_sent (in-app is separate via medyar_notify_user).
     *
     * @param array<string,string> $pattern_vars
     */
    public static function send_user_message_only(
        int $user_id,
        string $message,
        int $ref_id = 0,
        string $pattern = '',
        array $pattern_vars = []
    ): bool {
        $user_id = absint($user_id);
        $message = trim($message);
        $pattern = trim($pattern);
        if ($user_id <= 0 || ($message === '' && $pattern === '')) {
            return false;
        }
        $mobile = self::get_user_mobile($user_id);
        if ($mobile === '') {
            return false;
        }
        // If ref_id is a wallet transaction id, append success note to its description.
        return self::send($mobile, $message, $ref_id, $ref_id > 0, 0, '', $pattern, $pattern_vars);
    }

    /**
     * ارسال پیامک به یک شماره (بدون نوتیفیکیشن داخل‌پنل مگر user_id داده شود).
     */
    public static function send_to_mobile(string $mobile, string $message, int $ref_id = 0, int $user_id = 0, string $notif_type = ''): bool
    {
        $mobile  = trim($mobile);
        $message = trim($message);
        if ($mobile === '' || $message === '') {
            return false;
        }

        return self::send($mobile, $message, $ref_id, false, absint($user_id), sanitize_key($notif_type));
    }

    /** SMS-only to a raw mobile (no in-app bridge). */
    public static function send_to_mobile_only(string $mobile, string $message, int $ref_id = 0): bool
    {
        return self::send_to_mobile($mobile, $message, $ref_id, 0, '');
    }

    /**
     * Canonical Iranian mobile 09XXXXXXXXX, or '' when invalid.
     * Converts Persian/Arabic digits first — raw FA billing_phone must not reach Faraz.
     */
    private static function normalize_mobile(string $value): string
    {
        $value = str_replace(['*', '★', '•', '✱'], '', $value);
        if ($value === '') {
            return '';
        }
        if (class_exists('Arp_Format') && method_exists('Arp_Format', 'iran_mobile')) {
            return Arp_Format::iran_mobile($value);
        }
        $v = str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );
        $digits = preg_replace('/\D/', '', $v);
        if (strpos($digits, '0098') === 0) {
            $digits = '0' . substr($digits, 4);
        } elseif (strpos($digits, '98') === 0 && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
            $digits = '0' . $digits;
        }
        return preg_match('/^09\d{9}$/', $digits) ? $digits : '';
    }

    /**
     * بررسی ساده اینکه آیا رشته شبیه شماره موبایل ایرانی است
     * (قبل از اینکه PWSMS normalize کند)
     */
    private static function looks_like_mobile(string $value): bool
    {
        return self::normalize_mobile($value) !== '';
    }

    // ═══════════════════════════════════════════════════════════════════
    // مبالغ
    // ═══════════════════════════════════════════════════════════════════

    private static function resolve_buy_refund_amount(object $tx): int
    {
        $tm = Wallet_Transaction_Manager::get_instance();

        if (!empty($tx->p_info)) {
            $p_info = json_decode($tx->p_info, true);

            if (!empty($p_info['type']) && $p_info['type'] === 'Pay_gateway' && !empty($p_info['id'])) {
                $pay = $tm->get_payment_record(intval($p_info['id']));
                if ($pay) return intval($pay->amount);
            }

            if (!empty($p_info['type']) && $p_info['type'] === 'Wallet_gateway' && !empty($p_info['id'])) {
                $linked = $tm->get_transaction(intval($p_info['id']));
                if ($linked) return abs(intval($linked->amount));
            }
        }

        if (!empty($tx->linked_tx_id)) {
            $linked = $tm->get_transaction(intval($tx->linked_tx_id));
            if ($linked && $linked->balance_type === 'toman') {
                return abs(intval($linked->amount));
            }
        }

        return 0;
    }

    // ═══════════════════════════════════════════════════════════════════
    // ارسال پیامک
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ارسال پیامک از طریق PWSMS (پیامک حرفه‌ای ووکامرس)
     *
     * API:
     *  PWSMS()->send_sms($data)
     *   - mobile  : شماره یا آرایه شماره‌ها
     *   - message : متن (پلاگین esc_textarea اعمال می‌کند)
     *   - post_id : اختیاری (برای آرشیو)
     *   - type    : اختیاری (0=normal)
     *
     * پلاگین خودش:
     *  - اعداد فارسی/عربی را normalize می‌کند
     *  - شماره را به فرمت استاندارد تبدیل می‌کند
     *  - نتیجه را در آرشیو ثبت می‌کند
     *
     * @param string $mobile   شماره موبایل
     * @param string $message  متن پیامک
     * @param int    $tx_id    شناسه تراکنش (برای لاگ)
     */
    /**
     * @param int                  $post_id_or_tx_id شناسه تراکنش کیف پول (برای لاگ) یا post_id برای PWSMS؛ برای معامله trade_id
     * @param bool                 $append_wallet_tx_log اگر false باشد، فقط ارسال می‌شود و در wp_wallet_transactions لاگ نمی‌شود (معاملات با log_sms_in_trade)
     * @param array<string,string> $pattern_vars
     */
    private static function send(
        string $mobile,
        string $message,
        int $post_id_or_tx_id = 0,
        bool $append_wallet_tx_log = true,
        int $user_id = 0,
        string $notif_type = '',
        string $pattern = '',
        array $pattern_vars = []
    ): bool {
        $ref     = $post_id_or_tx_id;
        $pattern = trim($pattern);
        error_log("[WalletSMS] send attempt: ref=$ref mobile=$mobile msg_len=" . mb_strlen($message) . ' pattern=' . ($pattern !== '' ? $pattern : '-'));
        if ($notif_type === 'trade' || strpos($message, 'Match شد') !== false || strpos($message, 'با موفقیت ثبت شد') !== false) {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
            $frames = array();
            foreach ($bt as $i => $f) {
                if ($i === 0) {
                    continue;
                }
                $frames[] = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '@' . basename($f['file'] ?? '') . ':' . ($f['line'] ?? 0);
            }
            self::debug_log(sprintf(
                'SEND_GATE type=%s user=%d ref=%d mobile=%s msg=%s stack=%s',
                $notif_type !== '' ? $notif_type : '(empty)',
                $user_id,
                $ref,
                $mobile,
                str_replace(array("\r", "\n"), array('', ' | '), $message),
                implode(' <- ', $frames)
            ));
        }

        if (!self::is_sms_enabled()) {
            error_log("[WalletSMS] DISABLED: SMS sending is disabled in settings. ref=$ref");
            return false;
        }

        if (empty($mobile) || (empty($message) && $pattern === '')) {
            error_log("[WalletSMS] SKIPPED: empty mobile or message. ref=$ref");
            return false;
        }

        // Deposit/withdraw flows run inside a queue context → durable queue with retry; logs run on msq_job_sent_wallet.
        if (self::$msq_ctx && self::msq_ready()) {
            $ctx = self::$msq_ctx;
            $id  = MSQ_Client::enqueue([
                'channel'    => 'wallet',
                'event'      => (string) ($ctx['event'] ?? ($notif_type !== '' ? $notif_type : 'message')),
                'phone'      => $mobile,
                'body'       => $message,
                'pattern'    => $pattern,
                'vars'       => $pattern_vars,
                'priority'   => (int) ($ctx['priority'] ?? MSQ_Client::PRIORITY_NORMAL),
                'dedupe_key' => !empty($ctx['dedupe']) ? $ctx['dedupe'] . ':' . $mobile : '',
                'meta'       => [
                    'ref'         => $post_id_or_tx_id,
                    'append_log'  => $append_wallet_tx_log && $post_id_or_tx_id > 0,
                    'user_id'     => $user_id,
                    'notif_type'  => $notif_type,
                    'log_message' => $message !== '' ? $message : ('pattern:' . $pattern),
                ],
            ]);
            error_log('[WalletSMS] queued ' . ($id > 0 ? 'job=' . $id : 'FAIL') . " ref=$ref mobile=$mobile event=" . ($ctx['event'] ?? '-'));
            if ($id > 0) {
                return true;
            }
        }

        // Prefer Faraz SMS via affiliate-referral when that plugin is present (pattern or free-text).
        if (class_exists('Arp_Faraz_Sms_Gateway') && method_exists('Arp_Faraz_Sms_Gateway', 'deliver')) {
            $ok = (new Arp_Faraz_Sms_Gateway())->deliver($mobile, $message, $pattern, $pattern_vars);
            error_log('[WalletSMS] Faraz(ARP) deliver ' . ($ok ? 'OK' : 'FAIL') . " ref=$ref mobile=$mobile pattern=" . ($pattern !== '' ? $pattern : '-'));
            if ($ok) {
                if ($append_wallet_tx_log && $post_id_or_tx_id > 0) {
                    self::log_sms_in_transaction($post_id_or_tx_id, $mobile, $message !== '' ? $message : ('pattern:' . $pattern));
                }
                if ($user_id > 0) {
                    do_action('medyar_wallet_sms_sent', $user_id, $message, $notif_type, $post_id_or_tx_id);
                }
            }
            return $ok;
        }

        // Direct Faraz pattern (without ARP helper).
        if ($pattern !== '' && function_exists('farazsms_send_pattern')) {
            $attrs = class_exists('Arp_Faraz_Sms_Gateway')
                ? Arp_Faraz_Sms_Gateway::pattern_attrs($pattern_vars)
                : $pattern_vars;
            $result = farazsms_send_pattern($mobile, $pattern, $attrs);
            $ok     = is_array($result) ? !empty($result['ok']) : (bool) $result;
            error_log('[WalletSMS] Faraz(direct) pattern ' . ($ok ? 'OK' : 'FAIL') . " ref=$ref mobile=$mobile pattern=$pattern");
            if ($ok) {
                if ($append_wallet_tx_log && $post_id_or_tx_id > 0) {
                    self::log_sms_in_transaction($post_id_or_tx_id, $mobile, $message !== '' ? $message : ('pattern:' . $pattern));
                }
                if ($user_id > 0) {
                    do_action('medyar_wallet_sms_sent', $user_id, $message, $notif_type, $post_id_or_tx_id);
                }
            }
            return $ok;
        }

        // Direct Faraz SMS plugin — works without affiliate-referral.
        if (function_exists('farazsms_is_ready') && farazsms_is_ready() && function_exists('farazsms_send_sms')) {
            $result = farazsms_send_sms($mobile, $message);
            $ok     = is_array($result) ? !empty($result['ok']) : (bool) $result;
            error_log('[WalletSMS] Faraz(direct) send ' . ($ok ? 'OK' : 'FAIL') . " ref=$ref mobile=$mobile");
            if ($ok) {
                if ($append_wallet_tx_log && $post_id_or_tx_id > 0) {
                    self::log_sms_in_transaction($post_id_or_tx_id, $mobile, $message);
                }
                if ($user_id > 0) {
                    do_action('medyar_wallet_sms_sent', $user_id, $message, $notif_type, $post_id_or_tx_id);
                }
            }
            return $ok;
        }

        // Optional site adapter (no core edit required on another install).
        $filtered = apply_filters('mtw_send_sms', null, $mobile, $message, $post_id_or_tx_id);
        if (is_bool($filtered)) {
            if ($filtered && $append_wallet_tx_log && $post_id_or_tx_id > 0) {
                self::log_sms_in_transaction($post_id_or_tx_id, $mobile, $message);
            }
            if ($filtered && $user_id > 0) {
                do_action('medyar_wallet_sms_sent', $user_id, $message, $notif_type, $post_id_or_tx_id);
            }
            return $filtered;
        }

        // Fallback: PWSMS
        if (!function_exists('PWSMS')) {
            error_log("[WalletSMS] FAILED: no SMS transport (Faraz/ARP/PWSMS/filter). ref=$ref");
            return false;
        }

        $sms = PWSMS();

        if (!is_object($sms)) {
            error_log("[WalletSMS] FAILED: PWSMS() returned " . gettype($sms) . " instead of object. ref=$ref");
            return false;
        }

        if (!method_exists($sms, 'send_sms')) {
            error_log("[WalletSMS] FAILED: send_sms() method not found on " . get_class($sms) . ". ref=$ref");
            return false;
        }

        // بررسی gateway تنظیم شده
        if (method_exists($sms, 'get_sms_gateway')) {
            $gateway = $sms->get_sms_gateway();
            error_log("[WalletSMS] gateway: " . (is_object($gateway) ? get_class($gateway) : gettype($gateway)) . " ref=$ref");
        }

        // بررسی اینکه آیا شماره valid است (با استفاده از متد خود پلاگین)
        if (method_exists($sms, 'validate_mobile') && !$sms->validate_mobile($mobile)) {
            error_log("[WalletSMS] SKIPPED: mobile='$mobile' failed PWSMS validate_mobile(). ref=$ref");
            return false;
        }

        $data = [
            'post_id' => $post_id_or_tx_id,
            'type'    => 0,
            'mobile'  => $mobile,
            'message' => $message,
        ];

        error_log("[WalletSMS] calling send_sms: ref=$ref mobile=$mobile");

        try {
            $result = $sms->send_sms($data);
            if ($result === true) {
                error_log("[WalletSMS] SUCCESS ref=$ref mobile=$mobile");

                if ($append_wallet_tx_log && $post_id_or_tx_id > 0) {
                    self::log_sms_in_transaction($post_id_or_tx_id, $mobile, $message);
                }

                if ($user_id > 0) {
                    do_action('medyar_wallet_sms_sent', $user_id, $message, $notif_type, $post_id_or_tx_id);
                }

                return true;
            }
            error_log("[WalletSMS] RESULT (not true): ref=$ref result=" . print_r($result, true));
        } catch (Throwable $e) {
            error_log("[WalletSMS] EXCEPTION: ref=$ref error=" . $e->getMessage());
        }
        return false;
    }

    /**
     * ثبت لاگ ارسال پیامک در توضیحات تراکنش
     */
    private static function log_sms_in_transaction(int $tx_id, string $mobile, string $message = ''): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_transactions';

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $tx_id));
        // Not a wallet transaction row (e.g. trade_id passed as ref) — skip silently.
        if ($exists === null) {
            return;
        }

        $current_desc = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT description FROM {$table} WHERE id = %d",
            $tx_id
        ));

        $new_desc = $current_desc . self::format_sms_success_log($message, $mobile);

        $wpdb->update(
            $table,
            ['description' => $new_desc],
            ['id' => $tx_id],
            ['%s'],
            ['%d']
        );
    }
}

add_action('plugins_loaded', ['Wallet_SMS', 'init'], 30);
