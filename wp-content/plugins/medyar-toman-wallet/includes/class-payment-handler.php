<?php
/**
 * Zarinpal return handler (success/fail) + pending charge expiry.
 *
 * Docs: https://www.zarinpal.com/docs/paymentGateway/
 *
 * Callback receives Authority + Status from Zarinpal, verifies via verify.json,
 * then credits wallet only on code 100 (or idempotent 101).
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('MTW_Payment_Handler')) {
    return;
}

class MTW_Payment_Handler
{
    private static $instance = null;

    /** @deprecated Keep for in-flight callbacks that still use the old path. */
    public const CALLBACK_SLUG = 'compelete-transactions';

    /** Legacy homepage query flag (may be skipped by full-page cache). */
    public const CALLBACK_QUERY = 'mtw_zarinpal_return';

    public const TRANSIENT_PREFIX = 'wallet_payment_pending_';
    public const TRANSIENT_EXPIRY = 3600;

    public const EXPIRE_HOOK = 'mtw_expire_pending_charge';
    public const SWEEP_HOOK  = 'mtw_sweep_stale_pending_charges';
    public const EXPIRE_AFTER = 1200; // 20 minutes

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Canonical Zarinpal callback_url (Status + Authority appended by gateway).
     * Uses admin-ajax so WCDN/full-page cache cannot skip PHP.
     * @see https://www.zarinpal.com/docs/paymentGateway/
     */
    public static function callback_url(): string
    {
        return admin_url('admin-ajax.php?action=mtw_zarinpal_return');
    }

    /**
     * Legacy result URL under My Account (still registered as WC endpoint).
     */
    public static function result_page_url(): string
    {
        if (function_exists('wc_get_account_endpoint_url')) {
            $url = wc_get_account_endpoint_url(self::CALLBACK_SLUG);
            if (is_string($url) && $url !== '') {
                return trailingslashit($url);
            }
        }
        if (function_exists('wc_get_page_permalink')) {
            $base = wc_get_page_permalink('myaccount');
            if (is_string($base) && $base !== '') {
                return trailingslashit($base) . self::CALLBACK_SLUG . '/';
            }
        }
        return home_url('/my-account/' . self::CALLBACK_SLUG . '/');
    }

    public static function wallet_result_url(string $status, int $tx_id = 0): string
    {
        $base = class_exists('MTW_MyAccount_UI')
            ? MTW_MyAccount_UI::section_url('topup')
            : (function_exists('wc_get_account_endpoint_url')
                ? wc_get_account_endpoint_url('wallet')
                : home_url('/my-account/wallet/'));

        $args = ['mtw_pay' => $status === 'ok' ? 'ok' : 'fail'];
        if ($tx_id > 0) {
            $args['mtw_tx'] = $tx_id;
        }
        return add_query_arg($args, $base);
    }

    public static function store_pending(string $authority, array $data): void
    {
        set_transient(
            self::TRANSIENT_PREFIX . $authority,
            array_merge($data, ['created_at' => time()]),
            self::TRANSIENT_EXPIRY
        );
    }

    public static function schedule_expire(int $tx_id): void
    {
        if ($tx_id <= 0) {
            return;
        }
        $args = [$tx_id];
        $ts   = wp_next_scheduled(self::EXPIRE_HOOK, $args);
        if ($ts) {
            wp_unschedule_event($ts, self::EXPIRE_HOOK, $args);
        }
        wp_schedule_single_event(time() + self::EXPIRE_AFTER, self::EXPIRE_HOOK, $args);
    }

    public static function clear_expire_schedule(int $tx_id): void
    {
        if ($tx_id <= 0) {
            return;
        }
        $args = [$tx_id];
        $ts   = wp_next_scheduled(self::EXPIRE_HOOK, $args);
        if ($ts) {
            wp_unschedule_event($ts, self::EXPIRE_HOOK, $args);
        }
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_endpoint'], 5);
        add_action('template_redirect', [$this, 'handle_gateway_return'], 1);
        add_action('wp_ajax_mtw_zarinpal_return', [$this, 'handle_ajax_return']);
        add_action('wp_ajax_nopriv_mtw_zarinpal_return', [$this, 'handle_ajax_return']);
        add_action('woocommerce_account_' . self::CALLBACK_SLUG . '_endpoint', [$this, 'render_legacy_result']);
        add_filter('query_vars', [$this, 'add_query_vars'], 0);
        add_filter('woocommerce_account_menu_items', [$this, 'hide_callback_menu_item'], 40);

        add_action(self::EXPIRE_HOOK, [$this, 'expire_pending_charge'], 10, 1);
        add_action(self::SWEEP_HOOK, [$this, 'sweep_stale_pending_charges']);
        add_action('init', [$this, 'ensure_sweep_schedule'], 30);
    }

    public function register_endpoint(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }
        add_rewrite_endpoint(self::CALLBACK_SLUG, EP_ROOT | EP_PAGES);
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public function add_query_vars(array $vars): array
    {
        $vars[] = self::CALLBACK_SLUG;
        return $vars;
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public function hide_callback_menu_item(array $items): array
    {
        unset($items[self::CALLBACK_SLUG]);
        return $items;
    }

    public function ensure_sweep_schedule(): void
    {
        if (!wp_next_scheduled(self::SWEEP_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::SWEEP_HOOK);
        }

        if (get_option('mtw_payment_handler_v') !== '2') {
            if (class_exists('WooCommerce')) {
                add_rewrite_endpoint(self::CALLBACK_SLUG, EP_ROOT | EP_PAGES);
                flush_rewrite_rules(false);
            }
            update_option('mtw_payment_handler_v', '2', false);
        }
    }

    /**
     * Primary callback via admin-ajax (logged-in or guest after bank redirect).
     */
    public function handle_ajax_return(): void
    {
        $authority = isset($_REQUEST['Authority']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['Authority'])) : '';
        $status    = isset($_REQUEST['Status']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['Status'])) : '';

        if ($authority === '') {
            wp_safe_redirect(self::wallet_result_url('fail'), 302);
            exit;
        }

        nocache_headers();
        $outcome = $this->process_gateway_authority($authority, $status);
        $tx_id   = (int) ($outcome['tx_id'] ?? 0);
        $ok      = !empty($outcome['ok']);

        wp_safe_redirect(self::wallet_result_url($ok ? 'ok' : 'fail', $tx_id), 302);
        exit;
    }

    /**
     * Legacy: home ?mtw_zarinpal_return=1 and /compelete-transactions/
     */
    public function handle_gateway_return(): void
    {
        $authority = isset($_GET['Authority']) ? sanitize_text_field(wp_unslash((string) $_GET['Authority'])) : '';
        $status    = isset($_GET['Status']) ? sanitize_text_field(wp_unslash((string) $_GET['Status'])) : '';

        $via_query = isset($_GET[self::CALLBACK_QUERY]) && (string) $_GET[self::CALLBACK_QUERY] === '1';
        $via_path  = $this->request_has_callback_slug();

        if ($via_path && $authority === '' && !empty($_GET['unq'])) {
            return;
        }

        if ($authority === '' || (!$via_query && !$via_path)) {
            return;
        }

        nocache_headers();
        $outcome = $this->process_gateway_authority($authority, $status);
        $tx_id   = (int) ($outcome['tx_id'] ?? 0);
        $ok      = !empty($outcome['ok']);

        wp_safe_redirect(self::wallet_result_url($ok ? 'ok' : 'fail', $tx_id), 302);
        exit;
    }

    /**
     * Legacy /my-account/compelete-transactions/?unq=…
     */
    public function render_legacy_result(): void
    {
        $unq = isset($_GET['unq']) ? sanitize_text_field(wp_unslash((string) $_GET['unq'])) : '';
        $tx_id = 0;
        if ($unq !== '' && preg_match('/^(\d+)/', $unq, $m)) {
            $tx_id = (int) $m[1];
        }

        $tx = $tx_id > 0 ? Wallet_Transaction_Manager::get_instance()->get_transaction($tx_id) : null;
        $ok = $tx && (string) $tx->status === 'completed';

        echo '<div class="mtw-pay-result ' . ($ok ? 'is-ok' : 'is-fail') . '">';
        echo '<h2>' . esc_html($ok ? 'پرداخت موفق' : 'پرداخت ناموفق') . '</h2>';
        if ($ok) {
            echo '<p>موجودی کیف پول شما با موفقیت شارژ شد.</p>';
        } else {
            echo '<p>پرداخت تأیید نشد یا لغو شده است. در صورت کسر وجه از حساب بانکی، تا ۲۰ دقیقه دیگر وضعیت را بررسی کنید یا با پشتیبانی تماس بگیرید.</p>';
        }
        echo '<p><a class="button" href="' . esc_url(self::wallet_result_url($ok ? 'ok' : 'fail', $tx_id)) . '">بازگشت به کیف پول</a></p>';
        echo '</div>';
    }

    /**
     * @param int $tx_id
     */
    public function expire_pending_charge($tx_id): void
    {
        $tx_id = (int) $tx_id;
        if ($tx_id <= 0) {
            return;
        }
        $this->settle_or_cancel_pending_charge($tx_id, 'expire');
    }

    public function sweep_stale_pending_charges(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_transactions';
        $cutoff_local = date('Y-m-d H:i:s', current_time('timestamp') - self::EXPIRE_AFTER);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM `{$table}`
             WHERE operation_type = 'charge'
               AND balance_type = 'toman'
               AND status = 'pending'
               AND created_at <= %s
             ORDER BY id ASC
             LIMIT 50",
            $cutoff_local
        ));

        foreach ((array) $ids as $id) {
            $this->settle_or_cancel_pending_charge((int) $id, 'sweep');
        }
    }

    /**
     * @return array{ok:bool,tx_id:int,message?:string}
     */
    private function process_gateway_authority(string $authority, string $status): array
    {
        $tm  = Wallet_Transaction_Manager::get_instance();
        $pay = $tm->get_payment_by_authority($authority);

        $transient_key = self::TRANSIENT_PREFIX . $authority;
        $pending       = get_transient($transient_key);
        if (!is_array($pending)) {
            $pending = [];
        }

        if (!$pay && !empty($pending['pay_id'])) {
            $pay = $tm->get_payment_record((int) $pending['pay_id']);
        }

        if (!$pay) {
            delete_transient($transient_key);
            return ['ok' => false, 'tx_id' => (int) ($pending['tx_id'] ?? 0), 'message' => 'payment_not_found'];
        }

        $tx_id   = (int) $pay->tx_id;
        $pay_id  = (int) $pay->id;
        $user_id = (int) $pay->user_id;
        $amount  = (int) $pay->amount;

        if (in_array((string) $pay->status, ['completed', 'canceled'], true)) {
            delete_transient($transient_key);
            self::clear_expire_schedule($tx_id);
            $tx = $tm->get_transaction($tx_id);
            return [
                'ok'    => $tx && (string) $tx->status === 'completed',
                'tx_id' => $tx_id,
            ];
        }

        if ($amount <= 0 || $user_id <= 0) {
            $this->cancel_pending_pair($pay_id, $tx_id, 'اطلاعات پرداخت ناقص است.');
            delete_transient($transient_key);
            return ['ok' => false, 'tx_id' => $tx_id];
        }

        if (strtoupper($status) !== 'OK') {
            $this->cancel_pending_pair($pay_id, $tx_id, 'پرداخت توسط کاربر لغو شد یا ناموفق بود.');
            delete_transient($transient_key);
            self::clear_expire_schedule($tx_id);
            return ['ok' => false, 'tx_id' => $tx_id];
        }

        $gateway = new Wallet_Zarinpal_Gateway();
        $verify  = $gateway->verify_payment([
            'authority' => $authority,
            'amount'    => $amount,
            'status'    => 'OK',
        ]);

        if (empty($verify['success'])) {
            if ($this->is_soft_verify_failure($verify)) {
                return ['ok' => false, 'tx_id' => $tx_id, 'message' => 'verify_soft_fail'];
            }
            $this->cancel_pending_pair(
                $pay_id,
                $tx_id,
                'تأیید پرداخت ناموفق: ' . (string) ($verify['error'] ?? 'خطای درگاه')
            );
            delete_transient($transient_key);
            self::clear_expire_schedule($tx_id);
            return ['ok' => false, 'tx_id' => $tx_id];
        }

        $ref_id   = (string) ($verify['ref_id'] ?? '');
        $card_pan = (string) ($verify['card_pan'] ?? '');

        $expected_card = preg_replace('/\D/', '', (string) ($pay->card_pan ?? ''));
        if ($expected_card === '' && !empty($pending['card_pan'])) {
            $expected_card = preg_replace('/\D/', '', (string) $pending['card_pan']);
        }

        if (
            $expected_card !== ''
            && !Wallet_Zarinpal_Gateway::masked_card_matches($expected_card, $card_pan)
        ) {
            $tm->update_payment_record($pay_id, [
                'reference_id' => $ref_id,
                'card_pan'     => $card_pan !== '' ? $card_pan : $expected_card,
                'card_hash'    => $verify['card_hash'] ?? '',
            ]);
            $this->cancel_pending_pair($pay_id, $tx_id, 'پرداخت با کارت ثبت‌شده در حساب مطابقت نداشت.');
            delete_transient($transient_key);
            self::clear_expire_schedule($tx_id);
            return ['ok' => false, 'tx_id' => $tx_id];
        }

        if ($ref_id !== '' && $tm->is_reference_id_used($ref_id)) {
            $tx = $tm->get_transaction($tx_id);
            delete_transient($transient_key);
            self::clear_expire_schedule($tx_id);
            return ['ok' => $tx && (string) $tx->status === 'completed', 'tx_id' => $tx_id];
        }

        if (!empty($verify['already_verified'])) {
            $tx = $tm->get_transaction($tx_id);
            if ($tx && (string) $tx->status === 'completed') {
                delete_transient($transient_key);
                self::clear_expire_schedule($tx_id);
                return ['ok' => true, 'tx_id' => $tx_id];
            }
        }

        if (!$tm->claim_payment_for_processing($pay_id)) {
            $pay = $tm->get_payment_record($pay_id);
            $tx  = $tm->get_transaction($tx_id);
            delete_transient($transient_key);
            self::clear_expire_schedule($tx_id);
            return [
                'ok'    => $pay && (string) $pay->status === 'completed' && $tx && (string) $tx->status === 'completed',
                'tx_id' => $tx_id,
            ];
        }

        $ok = $this->complete_charge($tx_id, $pay_id, $user_id, $amount, $ref_id, $card_pan, $verify);
        delete_transient($transient_key);
        self::clear_expire_schedule($tx_id);
        return ['ok' => (bool) $ok, 'tx_id' => $tx_id];
    }

    /**
     * Expire/sweep: inquiry → verify if PAID/VERIFIED, else cancel.
     * @see https://www.zarinpal.com/docs/paymentGateway/otherMethods/Inquiry
     */
    private function settle_or_cancel_pending_charge(int $tx_id, string $source): void
    {
        $tm = Wallet_Transaction_Manager::get_instance();
        $tx = $tm->get_transaction($tx_id);
        if (!$tx || (string) $tx->operation_type !== 'charge' || (string) $tx->status !== 'pending') {
            self::clear_expire_schedule($tx_id);
            return;
        }

        $created_ts = strtotime((string) $tx->created_at);
        if ($created_ts && (current_time('timestamp') - $created_ts) < self::EXPIRE_AFTER && $source === 'sweep') {
            return;
        }

        $pay = $tm->get_payment_by_tx_id($tx_id);
        if (!$pay || !in_array((string) $pay->status, ['pending', 'processing'], true)) {
            if ($pay && (string) $pay->status === 'completed') {
                return;
            }
            $tm->update_status($tx_id, 'canceled');
            $tm->update_transaction($tx_id, [
                'description' => trim((string) $tx->description . "\nلغو به‌خاطر عدم پرداخت در مهلت ۲۰ دقیقه."),
            ]);
            self::clear_expire_schedule($tx_id);
            return;
        }

        $authority = (string) ($pay->authority ?? '');
        if ($authority === '') {
            $this->cancel_pending_pair((int) $pay->id, $tx_id, 'مهلت پرداخت تمام شد (بدون شناسه درگاه).');
            self::clear_expire_schedule($tx_id);
            return;
        }

        $gateway = new Wallet_Zarinpal_Gateway();
        $inquiry = $gateway->inquiry_payment($authority);

        if (empty($inquiry['success'])) {
            $err = (string) ($inquiry['error'] ?? '');
            if ($err !== '' && (
                strpos($err, 'اتصال') !== false
                || stripos($err, 'curl') !== false
                || stripos($err, 'timed out') !== false
            )) {
                return;
            }
            $this->cancel_pending_pair(
                (int) $pay->id,
                $tx_id,
                'مهلت ۲۰ دقیقه پرداخت تمام شد؛ استعلام درگاه ناموفق بود.'
            );
            self::clear_expire_schedule($tx_id);
            return;
        }

        $zp_status = strtoupper((string) ($inquiry['status'] ?? ''));

        if (in_array($zp_status, ['PAID', 'VERIFIED'], true)) {
            $outcome = $this->process_gateway_authority($authority, 'OK');
            if (!empty($outcome['ok'])) {
                return;
            }
            if (($outcome['message'] ?? '') === 'verify_soft_fail') {
                return;
            }
            return;
        }

        if ($zp_status === 'IN_BANK' && $source === 'expire') {
            return;
        }

        $tx  = $tm->get_transaction($tx_id);
        $pay = $tm->get_payment_record((int) $pay->id);
        if ($tx && (string) $tx->status === 'pending' && $pay) {
            $this->cancel_pending_pair(
                (int) $pay->id,
                $tx_id,
                'مهلت ۲۰ دقیقه پرداخت از درگاه تمام شد؛ درخواست لغو شد.'
            );
        }
        self::clear_expire_schedule($tx_id);
    }

    private function cancel_pending_pair(int $pay_id, int $tx_id, string $note): void
    {
        $tm = Wallet_Transaction_Manager::get_instance();
        if ($pay_id > 0) {
            $tm->transition_payment_status($pay_id, 'pending', 'canceled')
                || $tm->transition_payment_status($pay_id, 'processing', 'canceled');
        }
        if ($tx_id > 0) {
            $tx = $tm->get_transaction($tx_id);
            if ($tx && (string) $tx->status === 'pending') {
                $tm->update_status($tx_id, 'canceled');
                if ($note !== '') {
                    $desc = trim((string) ($tx->description ?? ''));
                    $tm->update_transaction($tx_id, [
                        'description' => $desc === '' ? $note : ($desc . "\n" . $note),
                    ]);
                }
            }
        }
    }

    private function complete_charge(
        int $tx_id,
        int $pay_id,
        int $user_id,
        int $toman_amount,
        string $ref_id,
        string $card_pan,
        array $verify
    ): bool {
        $tx_manager  = Wallet_Transaction_Manager::get_instance();
        $new_balance = wallet_atomic_adjust_balance($user_id, 'toman', (float) $toman_amount);

        if (is_wp_error($new_balance)) {
            $tx_manager->update_transaction($tx_id, [
                'status'      => 'pending',
                'description' => 'خطا در واریز شارژ — نیازمند بررسی دستی (ref: ' . $ref_id . ')',
            ]);
            $tx_manager->update_payment_record($pay_id, [
                'reference_id' => $ref_id,
                'card_pan'     => $card_pan,
                'status'       => 'pending',
                'verified_at'  => current_time('mysql'),
            ]);
            $tx_manager->transition_payment_status($pay_id, 'processing', 'pending');
            return false;
        }

        $tx_manager->update_transaction($tx_id, [
            'status'      => 'completed',
            'description' => sprintf(
                'شارژ کیف پول — %s تومان اضافه شد (ref: %s)',
                number_format($toman_amount, 0),
                $ref_id
            ),
        ]);

        $tx_manager->update_payment_record($pay_id, [
            'reference_id' => $ref_id,
            'card_pan'     => $card_pan,
            'card_hash'    => $verify['card_hash'] ?? '',
            'fee'          => $verify['fee'] ?? 0,
            'fee_type'     => $verify['fee_type'] ?? '',
            'status'       => 'completed',
            'verified_at'  => current_time('mysql'),
        ]);

        do_action('wallet_charge_completed', $user_id, $toman_amount, $tx_id, $ref_id);
        do_action('wallet_toman_balance_added', $user_id, $toman_amount, 'charge', $tx_id);
        return true;
    }

    /**
     * @param array<string,mixed> $verify
     */
    private function is_soft_verify_failure(array $verify): bool
    {
        if (!empty($verify['code'])) {
            return false;
        }
        $err = (string) ($verify['error'] ?? '');
        return $err !== '' && (
            strpos($err, 'اتصال') !== false
            || strpos($err, 'تأیید پرداخت با زرین‌پال') !== false
        );
    }

    private function request_has_callback_slug(): bool
    {
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '');
        if ($path !== '' && strpos($path, self::CALLBACK_SLUG) !== false) {
            return true;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(self::CALLBACK_SLUG)) {
            return true;
        }
        return false;
    }
}

// Backward-compatible alias when legacy silver wallet plugin is not loaded.
if (!class_exists('Wallet_Payment_Handler')) {
    class_alias('MTW_Payment_Handler', 'Wallet_Payment_Handler');
}

add_action('plugins_loaded', static function () {
    MTW_Payment_Handler::get_instance();
}, 20);
