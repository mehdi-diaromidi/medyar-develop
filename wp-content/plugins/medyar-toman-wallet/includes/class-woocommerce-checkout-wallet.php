<?php
/**
 * WooCommerce checkout / order-pay wallet usage.
 *
 * Checkout: Sheyda-compatible UI + cart fee via update_order_review (preview only).
 * Actual wallet debit runs on place-order / pay-order — never via apply AJAX.
 * Failed/cancelled orders refund wallet debit and (when paid) gateway amount to wallet.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WALLET_PARTIAL_FEE_LABEL')) {
    define('WALLET_PARTIAL_FEE_LABEL', 'پرداخت از کیف پول');
}

final class MTW_WC_Checkout_Wallet
{
    public const NONCE_ACTION = 'wallet_partial_payment';
    public const SESSION_AMOUNT = 'wallet_partial_payment';
    public const SESSION_USER = 'wallet_partial_user_id';
    public const SESSION_ENABLED = 'wallet_partial_enabled';
    /** When yes, bump applied amount to current max after other cart fees are known. */
    public const SESSION_USE_MAX = 'wallet_partial_use_max';
    public const META_AMOUNT = '_wallet_payment_amount';
    public const META_PARTIAL = '_wallet_partial_payment';
    public const META_TYPE = '_wallet_payment_type';
    public const META_DEDUCTED = '_wallet_balance_deducted';
    public const META_TX = '_wallet_spend_tx_id';
    /** Gateway portion charged at payment time (order total after wallet fee). */
    public const META_GATEWAY_PAID = '_mtw_gateway_paid_amount';
    /** Gateway portion already credited back to wallet on cancel/refund. */
    public const META_GATEWAY_REFUNDED = '_mtw_gateway_refunded_to_wallet';

    private static $instance = null;

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 30);

        // Sheyda-compatible UI inside order review table (theme CSS targets these classes).
        add_action('woocommerce_review_order_before_order_total', [$this, 'render_checkout_table_row'], 5);
        add_action('woocommerce_pay_order_before_payment', [$this, 'render_order_pay_toggle']);

        // Sync wallet choice from checkout POST (theme JS triggers update_checkout for fee preview).
        add_action('woocommerce_checkout_update_order_review', [$this, 'sync_from_checkout_post'], 5);
        add_action('woocommerce_checkout_process', [$this, 'sync_from_place_order_post'], 5);
        add_action('woocommerce_checkout_process', [$this, 'validate_wallet_at_checkout'], 20);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_wallet_after_checkout'], 20, 2);

        // Order-pay: apply wallet fee from form POST when user clicks pay (debit only after success).
        add_action('woocommerce_before_pay_action', [$this, 'process_order_pay_from_post'], 5);

        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_cart_fee'], 999);
        // Theme Sheyda PartialPayment may still add a parallel fee — strip it after ours.
        add_action('woocommerce_cart_calculate_fees', [$this, 'scrub_foreign_wallet_fees'], 10000);
        // Booking commission (theme) may land after normal fee hooks — bump wallet cover then.
        add_action('woocommerce_after_calculate_totals', [$this, 'sync_wallet_fee_after_totals'], 20);
        add_action('woocommerce_after_calculate_totals', [$this, 'scrub_foreign_wallet_fees'], 25);
        add_filter('woocommerce_cart_needs_payment', [$this, 'filter_needs_payment'], 20);
        add_filter('woocommerce_available_payment_gateways', [$this, 'filter_gateways'], 20);

        add_action('woocommerce_checkout_create_order', [$this, 'stamp_order_from_cart'], 20, 2);
        // Validate only — never debit here (failed gateway would leave balance stuck).
        add_action('woocommerce_checkout_order_processed', [$this, 'validate_at_order_processed'], 5, 3);

        // Debit only when payment succeeds.
        add_action('woocommerce_payment_complete', [$this, 'deduct_on_paid'], 5);
        add_action('woocommerce_order_status_processing', [$this, 'deduct_on_paid'], 5);
        add_action('woocommerce_order_status_completed', [$this, 'deduct_on_paid'], 5);
        // Remember gateway-paid remainder for cancel → wallet credit.
        add_action('woocommerce_payment_complete', [$this, 'stamp_gateway_paid_amount'], 6);
        add_action('woocommerce_order_status_processing', [$this, 'stamp_gateway_paid_amount'], 6);
        add_action('woocommerce_order_status_completed', [$this, 'stamp_gateway_paid_amount'], 6);

        // Safety net for already-deducted wallet on unpaid terminal statuses.
        add_action('woocommerce_order_status_cancelled', [$this, 'refund_wallet_if_unpaid'], 20);
        add_action('woocommerce_order_status_failed', [$this, 'refund_wallet_if_unpaid'], 20);
        add_action('woocommerce_order_status_refunded', [$this, 'refund_wallet_if_unpaid'], 20);
        // Credit gateway-paid amount to wallet on cancel/refund (store credit).
        add_action('woocommerce_order_status_cancelled', [$this, 'refund_gateway_paid_to_wallet'], 25);
        add_action('woocommerce_order_status_refunded', [$this, 'refund_gateway_paid_to_wallet'], 25);
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 20, 4);
        // Theme cancel_booking writes customer_refund_id after status change — catch late save.
        add_action('woocommerce_update_order', [$this, 'refund_gateway_paid_to_wallet'], 120, 1);

        // Admin: full order total in list + wallet panel styles on edit screen.
        add_filter('woocommerce_get_formatted_order_total', [$this, 'admin_list_show_full_order_total'], 25, 4);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_order_assets'], 30);

        // SMS: after full wallet pay, WC order total is 0 — show full payable in PWSMS/Faraz SMS.
        add_filter('pwoosms_order_sms_body_before_replace', [$this, 'sms_body_use_full_wallet_total'], 10, 5);
        add_filter('woocommerce_order_get_total', [$this, 'sms_order_get_total_full_payable'], 20, 2);
    }

    /* ------------------------------------------------------------------ */
    /* Assets                                                              */
    /* ------------------------------------------------------------------ */

    public function enqueue_assets(): void
    {
        if (!$this->is_payment_context()) {
            return;
        }

        $css = MTW_PATH . 'assets/css/checkout-wallet.css';
        $js  = MTW_PATH . 'assets/js/checkout.js';

        if (file_exists($css)) {
            wp_enqueue_style(
                'mtw-checkout-wallet',
                MTW_URL . 'assets/css/checkout-wallet.css',
                [],
                (string) (@filemtime($css) ?: MTW_VERSION)
            );
        }

        wp_enqueue_script(
            'wallet-checkout',
            MTW_URL . 'assets/js/checkout.js',
            ['jquery'],
            (string) (@filemtime($js) ?: MTW_VERSION),
            true
        );

        $user_id = get_current_user_id();
        $balance = $user_id ? (int) Wallet_Manager::get_instance()->get_balance($user_id) : 0;
        $ctx     = $this->resolve_context();

        wp_localize_script('wallet-checkout', 'walletCheckout', [
            'context'            => $ctx['type'],
            'order_id'           => $ctx['order_id'],
            'partial_active'     => $this->session_amount() > 0 || $ctx['order_wallet'] > 0 ? 'yes' : 'no',
            'balance'            => $balance,
            'error_need_gateway' => 'لطفا برای پرداخت مبلغ باقی‌مانده، یک درگاه پرداخت انتخاب کنید.',
        ]);
    }

    public function enqueue_admin_order_assets($hook = ''): void
    {
        if (!is_admin()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $sid    = $screen ? (string) $screen->id : '';
        $ok     = in_array($sid, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true)
            || strpos((string) $hook, 'wc-orders') !== false;
        if (!$ok) {
            return;
        }

        $css = MTW_PATH . 'assets/css/mtw-wc-order-admin.css';
        if (!file_exists($css)) {
            return;
        }
        wp_enqueue_style(
            'mtw-wc-order-admin',
            MTW_URL . 'assets/css/mtw-wc-order-admin.css',
            [],
            (string) (@filemtime($css) ?: MTW_VERSION)
        );
    }

    /**
     * Orders list: show full order total (before wallet fee), not only gateway remainder.
     */
    public function admin_list_show_full_order_total($formatted_total, $order, $tax_display = '', $display_refunded = true)
    {
        if (!is_admin() || !$order instanceof WC_Order) {
            return $formatted_total;
        }
        if (!$this->is_wc_orders_list_screen()) {
            return $formatted_total;
        }

        $full = $this->order_full_payable_amount($order);
        if ($full === null) {
            return $formatted_total;
        }

        $html  = '<span class="mtw-admin-list-total">';
        $html .= '<span class="mtw-admin-list-total__full">' . wc_price($full, ['currency' => $order->get_currency()]) . '</span>';
        $html .= '</span>';

        return $html;
    }

    /**
     * Full order payable when wallet was applied (meta), or null if not a wallet order.
     */
    public function order_full_payable_amount(WC_Order $order): ?float
    {
        $wallet = (float) $this->order_wallet_amount_meta($order);
        if ($wallet <= 0) {
            return null;
        }

        $full = (float) $order->get_meta('_wallet_payable_before');
        if ($full <= 0) {
            // Avoid recursion into sms_order_get_total_full_payable.
            $remainder = (float) $order->get_data()['total'];
            $full      = $remainder + $wallet;
        }

        return $full > 0 ? $full : null;
    }

    /**
     * PWSMS: replace {price} with full payable before str_ireplace overwrites with WC total (often 0).
     *
     * @param string   $content SMS template body.
     * @param array    $keys    Tag keys (unused; PWSMS does not use filtered keys).
     * @param array    $values  Tag values (unused).
     * @param int      $order_id Order ID.
     * @param WC_Order $order   Order object.
     */
    public function sms_body_use_full_wallet_total($content, $keys, $values, $order_id, $order)
    {
        if (!$order instanceof WC_Order) {
            $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        }
        if (!$order instanceof WC_Order) {
            return $content;
        }

        $full = $this->order_full_payable_amount($order);
        if ($full === null) {
            return $content;
        }

        // Keep under Faraz admin pattern attr limit (price length=25 on super_admin patterns).
        $formatted = $this->format_amount_for_sms($full);

        return str_ireplace('{price}', $formatted, (string) $content);
    }

    /**
     * Compact amount for SMS pattern variables (Faraz attr length caps).
     */
    private function format_amount_for_sms(float $amount): string
    {
        $n = (int) round($amount);
        $formatted = number_format_i18n($n) . ' تومان';
        if (function_exists('mb_strlen') && mb_strlen($formatted, 'UTF-8') > 25) {
            $formatted = number_format_i18n($n);
        }
        if (function_exists('mb_strlen') && mb_strlen($formatted, 'UTF-8') > 25) {
            $formatted = (string) $n;
        }
        return $formatted;
    }

    /**
     * Faraz native SMS builds %total% from $order->get_total(). Scope override to SMS builders only.
     *
     * @param string|float $total Order total.
     * @param WC_Order     $order Order.
     */
    public function sms_order_get_total_full_payable($total, $order)
    {
        if (!$order instanceof WC_Order) {
            return $total;
        }

        $full = $this->order_full_payable_amount($order);
        if ($full === null) {
            return $total;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);
        $sms_fns = [
            'farazsms_order_sms_replace_vars',
            'farazsms_order_sms_deferred_send',
            'farazsms_order_sms_handle_status_changed',
            'farazsms_order_sms_handle_new_order',
            'replace_short_codes',
            'send_order_sms',
        ];
        foreach ($trace as $frame) {
            $fn = $frame['function'] ?? '';
            if (in_array($fn, $sms_fns, true)) {
                return $full;
            }
        }

        return $total;
    }

    private function is_wc_orders_list_screen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }
        // List table only (not single edit).
        if ($screen->id === 'edit-shop_order') {
            return true;
        }
        if ($screen->id === 'woocommerce_page_wc-orders') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash((string) $_GET['action'])) : '';
            return $action !== 'edit';
        }
        return false;
    }

    public function order_wallet_amount_meta(WC_Order $order): float
    {
        $amount = (float) $order->get_meta(self::META_AMOUNT);
        if ($amount <= 0) {
            $amount = (float) $order->get_meta(self::META_PARTIAL);
        }
        if ($amount <= 0) {
            $amount = $this->order_wallet_fee_amount($order);
        }
        return max(0.0, $amount);
    }

    /* ------------------------------------------------------------------ */
    /* UI (Sheyda-compatible markup — theme CSS/JS already target these)   */
    /* ------------------------------------------------------------------ */

    public function render_checkout_table_row(): void
    {
        if (!is_user_logged_in() || is_wc_endpoint_url('order-pay')) {
            return;
        }

        $user_id = get_current_user_id();
        $balance = (int) Wallet_Manager::get_instance()->get_balance($user_id);
        $payable = $this->cart_payable_before_wallet();
        $max     = $this->resolve_apply_amount($user_id, $payable);

        if ($max <= 0 && $balance <= 0) {
            return;
        }

        $enabled = $this->session_enabled() && (int) $this->session_user() === $user_id;
        $amount  = $enabled ? (int) $this->session_amount() : 0;
        $this->render_sheyda_row([
            'context'   => 'checkout',
            'order_id'  => 0,
            'balance'   => $balance,
            'max'       => max($max, $balance > 0 ? min($balance, (int) floor($payable)) : 0),
            'enabled'   => $enabled,
            'amount'    => $amount,
            'disabled'  => $balance <= 0 || $payable <= 0,
            'as_table'  => true,
        ]);
    }

    public function render_order_pay_toggle(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $order_id = absint(get_query_var('order-pay'));
        $order    = $order_id ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order) {
            return;
        }
        if ((int) $order->get_user_id() !== get_current_user_id()) {
            return;
        }
        if (!$order->needs_payment()) {
            return;
        }

        $user_id = get_current_user_id();
        $balance = (int) Wallet_Manager::get_instance()->get_balance($user_id);
        $payable = $this->order_payable_before_wallet($order);
        $applied = (int) round($this->order_wallet_fee_amount($order));
        $max     = $this->resolve_apply_amount($user_id, $payable);

        if ($max <= 0 && $applied <= 0 && $balance <= 0) {
            return;
        }

        echo '<div id="order_review-wrap" class="mtw-order-pay-wallet-wrap">';
        echo '<table class="shop_table"><tfoot>';
        $this->render_sheyda_row([
            'context'  => 'order-pay',
            'order_id' => $order->get_id(),
            'balance'  => $balance,
            'max'      => max($max, $applied),
            'enabled'  => $applied > 0,
            'amount'   => $applied,
            'disabled' => $balance <= 0 && $applied <= 0,
            'as_table' => true,
        ]);
        echo '</tfoot></table></div>';
    }

    private function render_sheyda_row(array $vm): void
    {
        $enabled  = !empty($vm['enabled']);
        $disabled = !empty($vm['disabled']);
        $balance  = (int) ($vm['balance'] ?? 0);
        $max      = (int) ($vm['max'] ?? 0);
        $amount   = (int) ($vm['amount'] ?? 0);
        $value    = $amount > 0 ? number_format($amount) : '';
        $row_cls = 'sheyda-wallet-partial mtw-checkout-wallet' . ($enabled ? ' is-active' : '');
        ?>
        <tr class="<?php echo esc_attr($row_cls); ?>"
            data-mtw-wallet="1"
            data-context="<?php echo esc_attr((string) ($vm['context'] ?? 'checkout')); ?>"
            data-order-id="<?php echo esc_attr((string) ($vm['order_id'] ?? 0)); ?>">
            <th>
                <div class="sheyda-wallet-partial-title">استفاده از موجودی کیف پول</div>
            </th>
            <td>
                <div class="sheyda-wallet-partial-form">
                    <div class="sheyda-wallet-partial-balance">
                        <span class="mtw-checkout-wallet__balance-label">در دسترس</span>
                        <strong class="mtw-checkout-wallet__balance-value"><?php echo esc_html(number_format_i18n($balance)); ?> تومان</strong>
                    </div>
                    <label class="sheyda-wallet-partial-toggle mtw-checkout-wallet__toggle">
                        <input type="checkbox"
                               name="mtw_use_wallet"
                               id="mtw_use_wallet"
                               value="1"
                               <?php checked($enabled); ?>
                               <?php disabled($disabled); ?>
                        />
                        <span>استفاده از کیف پول برای این سفارش</span>
                    </label>
                    <div class="sheyda-wallet-partial-field mtw-checkout-wallet__field"<?php echo $enabled ? '' : ' style="display:none"'; ?>>
                        <div class="mtw-checkout-wallet__field-head">
                            <label for="mtw_wallet_amount">مبلغ (تومان)</label>
                            <button type="button"
                                    class="mtw-checkout-wallet__max-btn"
                                    data-mtw-wallet-max="1"
                                    <?php disabled($disabled || $max <= 0); ?>>
                                تمام مبلغ
                            </button>
                        </div>
                        <input type="text"
                               id="mtw_wallet_amount"
                               class="sheyda-wallet-price-input"
                               name="mtw_wallet_amount"
                               inputmode="decimal"
                               value="<?php echo esc_attr($value); ?>"
                               data-max="<?php echo esc_attr((string) $max); ?>"
                               placeholder="مبلغ موردنظر"
                               autocomplete="off"
                               <?php disabled($disabled); ?>
                        />
                    </div>
                </div>
            </td>
        </tr>
        <?php
    }

    public function validate_wallet_at_checkout(): void
    {
        $error = $this->live_wallet_checkout_error();
        if ($error !== '') {
            wc_add_notice($error, 'error');
        }
    }

    /**
     * Hard stop before order creation: re-check live balance at place-order time.
     *
     * @param array    $data   Posted checkout data.
     * @param WP_Error $errors Validation errors bag.
     */
    public function validate_wallet_after_checkout($data, $errors): void
    {
        // Re-sync from the final place-order POST, then validate against live balance.
        $this->sync_from_place_order_post();
        $error = $this->live_wallet_checkout_error();
        if ($error !== '' && $errors instanceof WP_Error) {
            $errors->add('mtw_wallet_balance', $error);
        }
    }

    /**
     * @return string Empty when OK, otherwise a user-facing error.
     */
    private function live_wallet_checkout_error(): string
    {
        if (!$this->session_enabled() || $this->session_amount() <= 0) {
            return '';
        }

        $user_id = get_current_user_id();
        if (!$user_id || (int) $this->session_user() !== $user_id) {
            return 'نشست کیف پول نامعتبر است. صفحه را رفرش کنید.';
        }

        $want    = (int) $this->session_amount();
        $balance = (int) Wallet_Manager::get_instance()->get_balance($user_id);
        $payable = $this->cart_payable_before_wallet();
        $max     = $this->resolve_apply_amount($user_id, $payable);

        if ($want <= 0 || $max <= 0) {
            return 'مبلغ کیف پول نامعتبر است.';
        }
        if ($want > $balance) {
            $this->clear_wallet_session();
            return sprintf(
                'موجودی کیف پول کافی نیست (درخواستی: %s / موجودی: %s تومان).',
                number_format_i18n($want),
                number_format_i18n(max(0, $balance))
            );
        }
        if ($want > $max) {
            $this->clear_wallet_session();
            return sprintf(
                'مبلغ کیف پول از سقف قابل پرداخت این سفارش بیشتر است (حداکثر: %s تومان).',
                number_format_i18n($max)
            );
        }

        return '';
    }

    /* ------------------------------------------------------------------ */
    /* Checkout POST sync (theme JS → update_checkout)                     */
    /* ------------------------------------------------------------------ */

    public function sync_from_checkout_post(string $posted_data): void
    {
        $data = [];
        parse_str($posted_data, $data);
        if (!is_array($data)) {
            $data = [];
        }

        // Prefer MTW field names; fall back to legacy Sheyda names if present.
        if (
            preg_match_all(
                '/(?:^|&)(?:mtw_wallet_amount|sheyda_wallet_partial_amount)=([^&]*)/',
                $posted_data,
                $matches
            )
            && !empty($matches[1])
        ) {
            $picked = '';
            foreach ($matches[1] as $enc) {
                $val = trim(rawurldecode(str_replace('+', ' ', (string) $enc)));
                if ($val !== '') {
                    $picked = $val;
                }
            }
            if ($picked !== '') {
                $data['mtw_wallet_amount'] = $picked;
            }
        }

        $this->apply_request_wallet_choice($data);
    }

    public function sync_from_place_order_post(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC checkout nonce already verified.
        $this->apply_request_wallet_choice(isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : []);
    }

    private function apply_request_wallet_choice(array $data): void
    {
        if (!is_user_logged_in() || !WC()->session) {
            return;
        }

        $user_id = get_current_user_id();
        $use     = !empty($data['mtw_use_wallet'])
            || !empty($data['sheyda_wallet_use_wallet'])
            || !empty($data['use_wallet_partial']);

        if (!$use) {
            $this->clear_wallet_session();
            return;
        }

        $payable = $this->cart_payable_before_wallet();
        $max     = $this->resolve_apply_amount($user_id, $payable);
        if ($max <= 0) {
            $this->clear_wallet_session();
            return;
        }

        $raw = $data['mtw_wallet_amount'] ?? ($data['sheyda_wallet_partial_amount'] ?? '');
        // Duplicate fields can arrive as array; prefer last non-empty value.
        if (is_array($raw)) {
            $picked = '';
            foreach ($raw as $piece) {
                $piece = is_string($piece) ? trim($piece) : '';
                if ($piece !== '') {
                    $picked = $piece;
                }
            }
            $raw = $picked;
        }
        $requested = $this->parse_amount_input((string) $raw);

        // Empty amount OR amount at/above current max → treat as "use maximum available".
        // Needed because other fees (e.g. booking commission) may appear only later in cart_calculate_fees.
        $use_max = ($requested <= 0) || ($requested >= $max && $max > 0);
        $amount  = $use_max ? $max : min($requested, $max);

        if ($amount < $payable && $amount < $max && !$this->partial_allowed()) {
            // Partial disabled and user asked less than full cover.
            if ($max < $payable) {
                $this->clear_wallet_session();
                return;
            }
            $amount  = $max;
            $use_max = true;
        }

        if ($amount < $payable && !$this->partial_allowed() && $max < $payable) {
            $this->clear_wallet_session();
            return;
        }

        WC()->session->set(self::SESSION_ENABLED, 'yes');
        WC()->session->set(self::SESSION_USER, $user_id);
        WC()->session->set(self::SESSION_AMOUNT, $amount);
        WC()->session->set(self::SESSION_USE_MAX, $use_max ? 'yes' : 'no');
    }

    private function clear_wallet_session(): void
    {
        if (!WC()->session) {
            return;
        }
        WC()->session->__unset(self::SESSION_AMOUNT);
        WC()->session->__unset(self::SESSION_USER);
        WC()->session->__unset(self::SESSION_ENABLED);
        WC()->session->__unset(self::SESSION_USE_MAX);
    }

    private function parse_amount_input(string $raw): int
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];
        $raw = strtr($raw, $map);
        return (int) preg_replace('/\D+/', '', $raw);
    }

    /* ------------------------------------------------------------------ */
    /* Order-pay: apply wallet on pay submit (no AJAX debit)               */
    /* ------------------------------------------------------------------ */

    /**
     * @param WC_Order $order
     * @throws Exception
     */
    public function process_order_pay_from_post($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        if (!is_user_logged_in() || (int) $order->get_user_id() !== get_current_user_id()) {
            return;
        }
        if ($order->is_paid()) {
            return;
        }
        // Already reserved/deducted — leave alone.
        if ($order->get_meta(self::META_DEDUCTED) === 'yes') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC pay form nonce already verified.
        $post = isset($_POST) && is_array($_POST) ? wp_unslash($_POST) : [];
        $use  = !empty($post['mtw_use_wallet']) || !empty($post['sheyda_wallet_use_wallet']);

        if (!$use) {
            if ($this->order_wallet_fee_amount($order) > 0) {
                $this->set_order_wallet_fee($order, 0);
                $order->delete_meta_data(self::META_PARTIAL);
                $order->delete_meta_data(self::META_TYPE);
                $order->delete_meta_data(self::META_AMOUNT);
                $order->delete_meta_data('_wallet_payable_before');
                $order->save();
            }
            return;
        }

        $user_id   = get_current_user_id();
        $payable   = $this->order_payable_before_wallet($order);
        $max       = $this->resolve_apply_amount($user_id, $payable);
        $raw_amt   = $post['mtw_wallet_amount'] ?? ($post['sheyda_wallet_partial_amount'] ?? '');
        $requested = $this->parse_amount_input((string) $raw_amt);

        if ($requested <= 0) {
            throw new Exception('لطفاً مبلغ کیف پول را وارد کنید.');
        }

        $amount = min($requested, $max);
        $balance = (int) Wallet_Manager::get_instance()->get_balance($user_id);
        if ($amount <= 0 || $balance < $amount) {
            throw new Exception(sprintf(
                'موجودی کیف پول کافی نیست (نیاز: %s / موجودی: %s تومان).',
                number_format_i18n(max(0, $requested)),
                number_format_i18n(max(0, $balance))
            ));
        }
        if ($amount < $payable && !$this->partial_allowed()) {
            throw new Exception('موجودی برای پرداخت کامل کافی نیست. مبلغ جزئی مجاز نیست.');
        }

        $this->set_order_wallet_fee($order, $amount);
        $this->stamp_wallet_meta($order, $amount);
        // Debit happens only after successful payment (payment_complete / processing).
    }

    /* ------------------------------------------------------------------ */
    /* Cart fee                                                            */
    /* ------------------------------------------------------------------ */

    public function apply_cart_fee($cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || !WC()->session) {
            return;
        }

        $user_id = get_current_user_id();
        $session_user = (int) WC()->session->get(self::SESSION_USER);
        $session_amt  = (float) WC()->session->get(self::SESSION_AMOUNT);
        $enabled      = WC()->session->get(self::SESSION_ENABLED) === 'yes';

        if (!$enabled || $session_amt <= 0 || !$user_id || $session_user !== $user_id) {
            if ($session_user && $session_user !== $user_id) {
                $this->clear_wallet_session();
            }
            return;
        }

        // Live re-cap: never exceed balance/payable.
        // use_max: bump to current max once other fees (commission, etc.) are on the cart.
        $payable = $this->cart_payable_before_wallet($cart);
        $max     = $this->resolve_apply_amount($user_id, $payable);
        $use_max = WC()->session->get(self::SESSION_USE_MAX) === 'yes';
        $amount  = $use_max ? $max : min((int) $session_amt, $max);

        if ($amount <= 0) {
            WC()->session->set(self::SESSION_AMOUNT, 0);
            return;
        }

        if ($amount !== (int) $session_amt) {
            WC()->session->set(self::SESSION_AMOUNT, $amount);
        }

        $cart->add_fee(WALLET_PARTIAL_FEE_LABEL, -$amount, false);
        $this->scrub_foreign_wallet_fees($cart);
    }

    /**
     * Theme Sheyda PartialPayment still hooks cart fees ("پرداخت با کیف پول") even when
     * its UI is hidden — that double-counts wallet debit. Remove any non-MTW wallet fee.
     *
     * @param WC_Cart|null $cart
     */
    public function scrub_foreign_wallet_fees($cart = null): void
    {
        $cart = $cart ?: (WC()->cart ?: null);
        if (!$cart) {
            return;
        }

        $fees = $cart->get_fees();
        if (empty($fees) || !is_array($fees)) {
            return;
        }

        $kept = [];
        $removed_total = 0.0;
        $best_mtw_key = null;
        $best_mtw_abs = -1.0;
        $mtw_candidates = [];

        foreach ($fees as $key => $fee) {
            $name = isset($fee->name) ? (string) $fee->name : '';
            $fee_val = (float) (isset($fee->total) ? $fee->total : ($fee->amount ?? 0));

            if ($name !== '' && $name !== WALLET_PARTIAL_FEE_LABEL && $this->is_foreign_wallet_fee_label($name)) {
                $removed_total += $fee_val;
                continue;
            }

            // Keep a single MTW wallet fee (largest absolute) — duplicates inflate need amount.
            if ($name === WALLET_PARTIAL_FEE_LABEL) {
                $mtw_candidates[$key] = $fee;
                $abs = abs($fee_val);
                if ($abs > $best_mtw_abs) {
                    $best_mtw_abs = $abs;
                    $best_mtw_key = $key;
                }
                continue;
            }

            $kept[$key] = $fee;
        }

        foreach ($mtw_candidates as $key => $fee) {
            if ($key === $best_mtw_key) {
                $kept[$key] = $fee;
                continue;
            }
            $removed_total += (float) (isset($fee->total) ? $fee->total : ($fee->amount ?? 0));
        }

        if (count($kept) === count($fees)) {
            return;
        }

        if (method_exists($cart, 'fees_api') && is_object($cart->fees_api()) && method_exists($cart->fees_api(), 'set_fees')) {
            $cart->fees_api()->set_fees(array_values($kept));
        } else {
            // Legacy cart property.
            $cart->fees = $kept;
        }

        // Fee totals are negative; removing them increases the payable cart total.
        if (abs($removed_total) >= 0.5) {
            $new_total = max(0.0, (float) $cart->get_total('edit') - $removed_total);
            if (method_exists($cart, 'set_total')) {
                $cart->set_total($new_total);
            } else {
                $cart->total = $new_total;
            }
        }
    }

    private function is_foreign_wallet_fee_label(string $name): bool
    {
        $name = trim(wp_strip_all_tags($name));
        if ($name === '' || $name === WALLET_PARTIAL_FEE_LABEL) {
            return false;
        }

        $known = [
            'پرداخت با کیف پول',
            'Payment with wallet',
            'Pay with wallet',
            'Payment by wallet',
            'Wallet payment',
            'Wallet partial payment',
        ];
        if (in_array($name, $known, true)) {
            return true;
        }

        if (function_exists('__')) {
            foreach (['Payment with wallet', 'Pay with wallet', 'Payment by wallet'] as $msgid) {
                $translated = __($msgid, 'sheyda_wallet');
                if (is_string($translated) && $translated !== '' && $translated === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * After all totals/fees settle (incl. theme booking commission), expand wallet
     * cover when user asked for max and balance allows — without a full recalculate loop.
     */
    public function sync_wallet_fee_after_totals($cart): void
    {
        static $guard = false;
        if ($guard || !$cart || !WC()->session) {
            return;
        }
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $user_id = get_current_user_id();
        $enabled = WC()->session->get(self::SESSION_ENABLED) === 'yes';
        $session_user = (int) WC()->session->get(self::SESSION_USER);
        if (!$enabled || !$user_id || $session_user !== $user_id) {
            return;
        }

        $use_max = WC()->session->get(self::SESSION_USE_MAX) === 'yes';
        $session_amt = (int) WC()->session->get(self::SESSION_AMOUNT);
        if (!$use_max && $session_amt <= 0) {
            return;
        }

        $wallet_fee = null;
        $wallet_applied = 0.0;
        foreach ($cart->get_fees() as $fee) {
            if (isset($fee->name) && $fee->name === WALLET_PARTIAL_FEE_LABEL) {
                $wallet_fee = $fee;
                $wallet_applied = abs((float) (isset($fee->total) ? $fee->total : ($fee->amount ?? 0)));
                break;
            }
        }

        $remaining = (float) $cart->get_total('edit');
        $payable   = max(0.0, $remaining + $wallet_applied);
        if ($payable <= 0) {
            $payable = $this->cart_payable_before_wallet($cart);
        }

        $max = $this->resolve_apply_amount($user_id, $payable);
        if ($max <= 0) {
            return;
        }

        $target = $use_max ? $max : min($session_amt, $max);
        if ($target <= 0 || abs($target - $wallet_applied) < 1) {
            if ($target > 0 && $target !== $session_amt) {
                WC()->session->set(self::SESSION_AMOUNT, $target);
            }
            return;
        }

        $guard = true;
        WC()->session->set(self::SESSION_AMOUNT, $target);

        if ($wallet_fee) {
            $wallet_fee->amount = -$target;
            $wallet_fee->total  = -$target;
        } else {
            $cart->add_fee(WALLET_PARTIAL_FEE_LABEL, -$target, false);
        }

        $delta = $target - $wallet_applied;
        $new_total = max(0.0, $remaining - $delta);
        if (method_exists($cart, 'set_total')) {
            $cart->set_total($new_total);
        } else {
            $cart->total = $new_total;
        }

        $this->scrub_foreign_wallet_fees($cart);
        $guard = false;
    }

    public function filter_needs_payment($needs_payment)
    {
        $session_amt = $this->session_amount();
        if ($session_amt <= 0) {
            return $needs_payment;
        }

        $payable = $this->cart_payable_before_wallet();
        // Fully covered by wallet checkbox → no payment-method UI / gateway required.
        if ($payable > 0 && $session_amt >= $payable) {
            return false;
        }

        // Partial wallet: still need another gateway for the remainder.
        return true;
    }

    public function filter_gateways($gateways)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $gateways;
        }

        // Never expose the wallet radio — usage is only via the Sheyda checkbox UI.
        unset($gateways['wallet']);

        $session_amt = $this->session_amount();
        $order_pay   = is_wc_endpoint_url('order-pay');

        // Full cover on checkout: hide all gateways (needs_payment is already false).
        if ($session_amt > 0 && !$order_pay) {
            $payable = $this->cart_payable_before_wallet();
            if ($payable > 0 && $session_amt >= $payable) {
                return [];
            }
        }

        if ($order_pay) {
            $order_id = absint(get_query_var('order-pay'));
            $order    = $order_id ? wc_get_order($order_id) : false;
            if ($order instanceof WC_Order) {
                $fee = $this->order_wallet_fee_amount($order);
                $pay = $this->order_payable_before_wallet($order);
                if ($fee > 0 && $fee >= $pay) {
                    return [];
                }
            }
        }

        return $gateways;
    }

    /* ------------------------------------------------------------------ */
    /* Order stamping & deduction                                          */
    /* ------------------------------------------------------------------ */

    public function stamp_order_from_cart($order, $data): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        $amount = (int) round($this->order_wallet_fee_amount($order));
        if ($amount <= 0) {
            return;
        }

        $this->stamp_wallet_meta($order, $amount);
    }

    /**
     * Persist wallet payment info on the order (admin + notes).
     */
    public function stamp_wallet_meta(WC_Order $order, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $remainder = max(0.0, (float) $order->get_total());
        $payable   = $amount + $remainder;
        $type      = $remainder <= 0.00001 ? 'full_from_wallet' : 'partial';

        $order->update_meta_data(self::META_AMOUNT, $amount);
        $order->update_meta_data(self::META_PARTIAL, $amount);
        $order->update_meta_data(self::META_TYPE, $type);
        $order->update_meta_data('_wallet_payable_before', $payable);

        if ($type === 'full_from_wallet') {
            $order->set_payment_method('wallet');
            $order->set_payment_method_title('کیف پول');
            $order->add_order_note(sprintf(
                'پرداخت کامل سفارش از کیف پول: %s تومان (کل مبلغ سفارش).',
                number_format_i18n($amount)
            ));
        } else {
            $order->add_order_note(sprintf(
                'پرداخت جزئی از کیف پول: %s تومان از مجموع %s تومان. باقی‌مانده قابل پرداخت از درگاه: %s تومان.',
                number_format_i18n($amount),
                number_format_i18n((int) round($payable)),
                number_format_i18n((int) round($remainder))
            ));
        }
    }

    /**
     * At place-order: stamp + live balance check only. No debit yet.
     * Debit runs on successful payment so failed gateway payments never trap balance.
     *
     * Theme Sheyda PartialPayment may already have locked the fee amount (available→0).
     * Release that reserve so validation and later MTW debit see real available balance.
     */
    public function validate_at_order_processed($order_id, $posted_data, $order): void
    {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order instanceof WC_Order) {
            return;
        }

        $amount = (int) round($this->order_wallet_fee_amount($order));
        if ($amount <= 0) {
            return;
        }

        // Cap to session amount when duplicate fee lines inflated the sum.
        if (WC()->session) {
            $session_amt = (int) WC()->session->get(self::SESSION_AMOUNT);
            if ($session_amt > 0 && $amount > $session_amt) {
                $amount = $session_amt;
                $this->set_order_wallet_fee($order, $amount);
            }
        }

        if (!(float) $order->get_meta(self::META_PARTIAL)) {
            $this->stamp_wallet_meta($order, $amount);
            $order->save();
        }

        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) {
            throw new Exception('برای پرداخت از کیف پول باید وارد حساب کاربری شوید.');
        }

        // Release theme checkout locks that zero out "available" before our debit.
        if (class_exists('MTW_Sheyda_Balance_Bridge', false)) {
            $locked = MTW_Sheyda_Balance_Bridge::get_locked($user_id);
            if ($locked > 0) {
                $released = MTW_Sheyda_Balance_Bridge::unlock_amount(
                    $user_id,
                    min($locked, $amount),
                    'Unlock Sheyda reserve for order #' . $order->get_id()
                );
                if ($released > 0) {
                    $order->add_order_note(sprintf(
                        'قفل کیف پول شیدا برای این سفارش آزاد شد: %s تومان (کسر نهایی پس از پرداخت موفق انجام می‌شود).',
                        number_format_i18n($released)
                    ));
                    $order->save();
                }
            }
        }

        $balance = (int) Wallet_Manager::get_instance()->get_balance($user_id);
        $live    = $this->resolve_apply_amount($user_id, $amount);
        if ($balance < $amount || $live < $amount) {
            throw new Exception(sprintf(
                'موجودی کیف پول برای این سفارش کافی نیست (نیاز: %s / موجودی: %s تومان). صفحه را رفرش کنید.',
                number_format_i18n($amount),
                number_format_i18n(max(0, $balance))
            ));
        }
    }

    public function deduct_on_paid($order_id): void
    {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        // Never debit on failed/cancelled/refunded orders.
        if (in_array($order->get_status(), ['failed', 'cancelled', 'refunded', 'trash'], true)) {
            return;
        }

        if ($order->get_meta(self::META_DEDUCTED) === 'yes') {
            return;
        }

        $amount = (int) round($this->order_wallet_fee_amount($order));
        if ($amount <= 0) {
            $amount = (int) round((float) $order->get_meta(self::META_PARTIAL));
        }
        if ($amount <= 0) {
            $amount = (int) round((float) $order->get_meta(self::META_AMOUNT));
        }
        if ($amount <= 0) {
            return;
        }

        $ok = $this->deduct_order_wallet($order, $amount, false);
        if (!$ok) {
            $order->add_order_note('هشدار: پرداخت سفارش ثبت شد ولی کسر کیف پول ممکن نشد. موجودی کاربر را دستی بررسی کنید.');
            $order->save();
        }
    }

    public function on_order_status_changed($order_id, $from, $to, $order = null): void
    {
        $to = (string) $to;
        if (!in_array($to, ['failed', 'cancelled', 'refunded'], true)) {
            return;
        }
        $this->refund_wallet_if_unpaid($order_id);
        if (in_array($to, ['cancelled', 'refunded'], true)) {
            $this->refund_gateway_paid_to_wallet($order instanceof WC_Order ? $order : $order_id);
        }
    }

    /**
     * Persist the amount charged via payment gateway (order total after wallet fee).
     *
     * @param int|WC_Order $order_id
     */
    public function stamp_gateway_paid_amount($order_id): void
    {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        if (in_array($order->get_status(), ['failed', 'cancelled', 'refunded', 'trash', 'pending'], true)) {
            return;
        }
        if ((int) $order->get_meta(self::META_GATEWAY_PAID) > 0) {
            return;
        }

        $method = (string) $order->get_payment_method();
        if ($method === '' || $method === 'wallet') {
            return;
        }

        $amount = (int) round((float) $order->get_total());
        if ($amount <= 0) {
            return;
        }

        $order->update_meta_data(self::META_GATEWAY_PAID, $amount);
        $order->save();
    }

    /**
     * On cancel/refund of a paid order, credit the gateway-paid portion to the user wallet.
     * Wallet-only debit is handled by {@see refund_wallet_if_unpaid()}.
     *
     * @param int|WC_Order $order_id
     */
    public function refund_gateway_paid_to_wallet($order_id): void
    {
        static $busy = [];

        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        $order_id = (int) $order->get_id();
        if ($order_id <= 0 || !empty($busy[$order_id])) {
            return;
        }

        $status = $order->get_status();
        if (!in_array($status, ['cancelled', 'refunded'], true)) {
            return;
        }

        if ($order->get_meta(self::META_GATEWAY_REFUNDED) === 'yes') {
            return;
        }

        if (!$this->order_was_paid_via_gateway($order)) {
            return;
        }

        // Theme cancel_booking may already have credited Sheyda for the full refund.
        if ($this->theme_customer_refund_exists($order)) {
            if (class_exists('MTW_Sheyda_Balance_Bridge', false)) {
                MTW_Sheyda_Balance_Bridge::pull_to_meta((int) $order->get_user_id());
            }
            $order->update_meta_data(self::META_GATEWAY_REFUNDED, 'yes');
            $order->add_order_note('مبلغ درگاه قبلاً از مسیر لغو نوبت (شیدا) به کیف پول برگشته؛ همگام‌سازی موجودی انجام شد.');
            $order->save();
            return;
        }

        $amount = $this->resolve_gateway_paid_amount($order);
        if ($amount <= 0) {
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0 || !class_exists('Wallet_Manager')) {
            return;
        }

        $lock_key = 'mtw_wc_gateway_refund_' . $order_id;
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 60);
        $busy[$order_id] = true;

        try {
            $order = wc_get_order($order_id);
            if (
                !$order instanceof WC_Order
                || !in_array($order->get_status(), ['cancelled', 'refunded'], true)
                || $order->get_meta(self::META_GATEWAY_REFUNDED) === 'yes'
            ) {
                return;
            }
            if ($this->theme_customer_refund_exists($order)) {
                if (class_exists('MTW_Sheyda_Balance_Bridge', false)) {
                    MTW_Sheyda_Balance_Bridge::pull_to_meta((int) $order->get_user_id());
                }
                $order->update_meta_data(self::META_GATEWAY_REFUNDED, 'yes');
                $order->save();
                return;
            }

            $amount = $this->resolve_gateway_paid_amount($order);
            if ($amount <= 0) {
                return;
            }

            $result = Wallet_Manager::get_instance()->add_balance(
                $user_id,
                $amount,
                'refund_order',
                [
                    'status'           => 'completed',
                    'transaction_code' => 'REFUND_GATEWAY_' . $order_id . '_' . wp_generate_password(6, false),
                    'description'      => 'بازگشت مبلغ درگاه به کیف پول بابت لغو سفارش #' . $order_id,
                    'p_info'           => [
                        'type'           => 'Pay_gateway_refund',
                        'id'             => $order_id,
                        'payment_method' => (string) $order->get_payment_method(),
                    ],
                ]
            );

            if (is_wp_error($result)) {
                $order->add_order_note('بازگشت مبلغ درگاه به کیف پول ناموفق: ' . $result->get_error_message());
                $order->save();
                return;
            }

            $order->update_meta_data(self::META_GATEWAY_REFUNDED, 'yes');
            if ((int) $order->get_meta(self::META_GATEWAY_PAID) <= 0) {
                $order->update_meta_data(self::META_GATEWAY_PAID, $amount);
            }
            $order->add_order_note(sprintf(
                'مبلغ پرداختی از درگاه به کیف پول برگشت: %s تومان (وضعیت «%s»).',
                number_format_i18n($amount),
                wc_get_order_status_name($order->get_status())
            ));
            $order->save();
        } finally {
            unset($busy[$order_id]);
            delete_transient($lock_key);
        }
    }

    private function order_was_paid_via_gateway(WC_Order $order): bool
    {
        $method = (string) $order->get_payment_method();
        if ($method === '' || $method === 'wallet') {
            return false;
        }
        if ($order->get_date_paid()) {
            return true;
        }
        if ((string) $order->get_transaction_id() !== '') {
            return true;
        }
        // Paid orders that later become cancelled still keep META_GATEWAY_PAID if stamped.
        if ((int) $order->get_meta(self::META_GATEWAY_PAID) > 0) {
            return true;
        }
        return false;
    }

    private function resolve_gateway_paid_amount(WC_Order $order): int
    {
        $stamped = (int) $order->get_meta(self::META_GATEWAY_PAID);
        if ($stamped > 0) {
            $refunded = (int) round((float) $order->get_total_refunded());
            return max(0, $stamped - $refunded);
        }

        $method = (string) $order->get_payment_method();
        if ($method === '' || $method === 'wallet') {
            return 0;
        }

        $total = (int) round((float) $order->get_total());
        $refunded = (int) round((float) $order->get_total_refunded());
        return max(0, $total - $refunded);
    }

    /**
     * True when theme cancel_booking already wrote a Sheyda customer refund ledger row.
     */
    private function theme_customer_refund_exists(WC_Order $order): bool
    {
        $book = $order->get_meta('_booking_data');
        if (!is_array($book) || empty($book['customer_refund_id'])) {
            return false;
        }
        $refund_id = (int) $book['customer_refund_id'];
        if ($refund_id <= 0 || !class_exists('MTW_Sheyda_Balance_Bridge', false)) {
            return false;
        }
        if (!MTW_Sheyda_Balance_Bridge::tables_ready()) {
            // Id present but cannot verify — treat as theme-handled to avoid double credit.
            return true;
        }

        global $wpdb;
        $ledger = MTW_Sheyda_Balance_Bridge::table_ledger();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger));
        if ($found !== $ledger) {
            return true;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $amount = $wpdb->get_var($wpdb->prepare(
            "SELECT amount FROM `{$ledger}` WHERE id = %d LIMIT 1",
            $refund_id
        ));
        return $amount !== null && (float) $amount > 0;
    }

    /**
     * @return bool True when deducted or already deducted.
     */
    public function deduct_order_wallet(WC_Order $order, int $amount, bool $hard_fail_on_error): bool
    {
        if ($amount <= 0) {
            return true;
        }
        if ($order->get_meta(self::META_DEDUCTED) === 'yes') {
            return true;
        }

        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) {
            return false;
        }

        // If theme still reserved funds, free them before MTW reduce_balance.
        if (class_exists('MTW_Sheyda_Balance_Bridge', false)) {
            $locked = MTW_Sheyda_Balance_Bridge::get_locked($user_id);
            if ($locked > 0) {
                MTW_Sheyda_Balance_Bridge::unlock_amount(
                    $user_id,
                    min($locked, $amount),
                    'Unlock before MTW deduct for order #' . $order->get_id()
                );
            }
        }

        $lock_key = 'mtw_wc_deduct_' . $order->get_id();
        if (get_transient($lock_key)) {
            // Another request in progress; re-check shortly.
            $order = wc_get_order($order->get_id());
            return $order && $order->get_meta(self::META_DEDUCTED) === 'yes';
        }
        set_transient($lock_key, 1, 30);

        $order = wc_get_order($order->get_id());
        if (!$order || $order->get_meta(self::META_DEDUCTED) === 'yes') {
            delete_transient($lock_key);
            return true;
        }

        // Final live cap: never deduct more than current balance or fee.
        $live = $this->resolve_apply_amount($user_id, $amount);
        if ($live < $amount) {
            delete_transient($lock_key);
            $order->add_order_note('کسر کیف پول ناموفق: موجودی کمتر از مبلغ fee است.');
            return false;
        }

        $result = Wallet_Manager::get_instance()->reduce_balance(
            $user_id,
            $amount,
            'spend',
            [
                'status'           => 'completed',
                'transaction_code' => 'ORDER_' . $order->get_id() . '_' . wp_generate_password(6, false),
                'description'      => 'پرداخت سفارش #' . $order->get_id() . ' از کیف پول',
                'p_info'           => ['type' => 'Wallet_gateway', 'id' => (int) $order->get_id()],
            ]
        );

        if (is_wp_error($result)) {
            $order->add_order_note('خطا در کسر کیف پول: ' . $result->get_error_message());
            delete_transient($lock_key);
            return false;
        }

        $order->update_meta_data(self::META_DEDUCTED, 'yes');
        $order->update_meta_data(self::META_AMOUNT, $amount);
        $order->update_meta_data(self::META_PARTIAL, $amount);
        $order->delete_meta_data('_wallet_balance_refunded');
        $order->delete_meta_data('_wallet_pending_noted');
        $type = (float) $order->get_total() <= 0.00001 ? 'full_from_wallet' : 'partial';
        $order->update_meta_data(self::META_TYPE, $type);
        $order->add_order_note(sprintf(
            $type === 'full_from_wallet'
                ? 'موجودی کیف پول کسر شد (پرداخت کامل): %s تومان.'
                : 'موجودی کیف پول کسر شد (پرداخت جزئی): %s تومان. باقی‌مانده از درگاه پرداخت می‌شود.',
            number_format_i18n($amount)
        ));
        $order->save();

        $this->clear_wallet_session();

        delete_transient($lock_key);
        return true;
    }

    /**
     * If wallet was deducted but the order ended unpaid/failed/cancelled, put money back.
     */
    public function refund_wallet_if_unpaid($order_id): void
    {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ((string) $order->get_meta(self::META_DEDUCTED) !== 'yes') {
            return;
        }
        if ($order->get_meta('_wallet_balance_refunded') === 'yes') {
            return;
        }

        $status = $order->get_status();
        // Only restore on terminal unpaid/failed states (not while still awaiting gateway).
        if (!in_array($status, ['failed', 'cancelled', 'refunded', 'trash'], true)) {
            return;
        }

        $amount = (int) round((float) $order->get_meta(self::META_AMOUNT));
        if ($amount <= 0) {
            $amount = (int) round((float) $order->get_meta(self::META_PARTIAL));
        }
        if ($amount <= 0) {
            $amount = (int) round($this->order_wallet_fee_amount($order));
        }
        if ($amount <= 0) {
            $order->add_order_note('بازگشت کیف پول ممکن نشد: مبلغ کسرشده روی سفارش یافت نشد.');
            $order->save();
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0) {
            return;
        }

        $lock_key = 'mtw_wc_refund_' . $order->get_id();
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 30);

        // Re-read after lock.
        $order = wc_get_order($order->get_id());
        if (!$order || (string) $order->get_meta(self::META_DEDUCTED) !== 'yes' || $order->get_meta('_wallet_balance_refunded') === 'yes') {
            delete_transient($lock_key);
            return;
        }

        $result = Wallet_Manager::get_instance()->add_balance(
            $user_id,
            $amount,
            'refund_order',
            [
                'status'           => 'completed',
                'transaction_code' => 'REFUND_ORDER_' . $order->get_id() . '_' . wp_generate_password(6, false),
                'description'      => 'بازگشت وجه کیف پول به‌خاطر لغو/شکست سفارش #' . $order->get_id(),
                'p_info'           => ['type' => 'Wallet_gateway', 'id' => (int) $order->get_id()],
            ]
        );

        if (is_wp_error($result)) {
            $order->add_order_note('بازگشت کیف پول ناموفق: ' . $result->get_error_message());
            $order->save();
            delete_transient($lock_key);
            return;
        }

        $order->update_meta_data('_wallet_balance_refunded', 'yes');
        $order->update_meta_data(self::META_DEDUCTED, 'refunded');
        $order->add_order_note(sprintf(
            'بازگشت %s تومان به کیف پول کاربر به‌خاطر وضعیت «%s».',
            number_format_i18n($amount),
            wc_get_order_status_name($status)
        ));
        $order->save();
        delete_transient($lock_key);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    public function session_amount(): float
    {
        if (!WC()->session) {
            return 0.0;
        }
        return (float) WC()->session->get(self::SESSION_AMOUNT);
    }

    public function session_user(): int
    {
        if (!WC()->session) {
            return 0;
        }
        return (int) WC()->session->get(self::SESSION_USER);
    }

    public function session_enabled(): bool
    {
        if (!WC()->session) {
            return false;
        }
        return WC()->session->get(self::SESSION_ENABLED) === 'yes';
    }

    public function resolve_apply_amount(int $user_id, float $payable): int
    {
        $payable = max(0, (int) floor($payable));
        if ($payable <= 0 || $user_id <= 0) {
            return 0;
        }
        $balance = (int) Wallet_Manager::get_instance()->get_balance($user_id);
        if ($balance <= 0) {
            return 0;
        }
        return (int) min($balance, $payable);
    }

    public function cart_payable_before_wallet($cart = null): float
    {
        $cart = $cart ?: (WC()->cart ?: null);
        if (!$cart) {
            return 0.0;
        }

        // During cart_calculate_fees, get_total() is unreliable; build from parts.
        $total = (float) $cart->get_cart_contents_total()
            + (float) $cart->get_shipping_total()
            + (float) $cart->get_taxes_total(true, false);

        foreach ($cart->get_fees() as $fee) {
            $fee_total = isset($fee->total) ? (float) $fee->total : (float) ($fee->amount ?? 0);
            if (isset($fee->name) && $fee->name === WALLET_PARTIAL_FEE_LABEL && $fee_total < 0) {
                continue; // ignore our own fee if present
            }
            $total += $fee_total;
        }

        return max(0.0, $total);
    }

    public function order_payable_before_wallet(WC_Order $order): float
    {
        $total = (float) $order->get_total();
        $total += $this->order_wallet_fee_amount($order);
        return max(0.0, $total);
    }

    public function order_wallet_fee_amount(WC_Order $order): float
    {
        $sum = 0.0;
        foreach ($order->get_items('fee') as $fee) {
            $fee_total = (float) $fee->get_total();
            if ($fee->get_name() === WALLET_PARTIAL_FEE_LABEL && $fee_total < 0) {
                $sum += abs($fee_total);
            }
        }
        return $sum;
    }

    public function set_order_wallet_fee(WC_Order $order, int $amount): void
    {
        foreach ($order->get_items('fee') as $item_id => $item) {
            if ($item->get_name() === WALLET_PARTIAL_FEE_LABEL) {
                $order->remove_item($item_id);
            }
        }

        if ($amount > 0) {
            $item = new WC_Order_Item_Fee();
            $item->set_name(WALLET_PARTIAL_FEE_LABEL);
            $item->set_amount(-$amount);
            $item->set_total(-$amount);
            $item->set_tax_status('none');
            $order->add_item($item);
        }

        $order->calculate_totals(false);
    }

    private function partial_allowed(): bool
    {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        if (!isset($gateways['wallet'])) {
            return true;
        }
        return $gateways['wallet']->get_option('allow_partial', 'yes') === 'yes';
    }

    private function is_payment_context(): bool
    {
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            return true;
        }
        return false;
    }

    private function resolve_context(): array
    {
        $order_id  = 0;
        $order_key = '';
        $type      = 'checkout';
        $order_wallet = 0.0;

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            $type     = 'order-pay';
            $order_id = absint(get_query_var('order-pay'));
            $order    = $order_id ? wc_get_order($order_id) : false;
            if ($order instanceof WC_Order) {
                $order_key    = $order->get_order_key();
                $order_wallet = $this->order_wallet_fee_amount($order);
            }
        }

        return [
            'type'         => $type,
            'order_id'     => $order_id,
            'order_key'    => $order_key,
            'order_wallet' => $order_wallet,
        ];
    }

    private function context_from_request(): array
    {
        $type = isset($_POST['context']) ? sanitize_key(wp_unslash($_POST['context'])) : 'checkout';
        if ($type !== 'order-pay') {
            $type = 'checkout';
        }
        return [
            'type'      => $type,
            'order_id'  => isset($_POST['order_id']) ? absint($_POST['order_id']) : 0,
            'order_key' => isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '',
        ];
    }

    private function get_authorized_order(int $order_id, string $order_key, int $user_id): ?WC_Order
    {
        if ($order_id <= 0 || $order_key === '') {
            return null;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if (!hash_equals($order->get_order_key(), $order_key)) {
            return null;
        }
        if ((int) $order->get_user_id() !== $user_id) {
            return null;
        }
        if (!$order->needs_payment()) {
            return null;
        }
        return $order;
    }

    private function assert_ajax_user(string $bucket, int $max_hits, int $window): int
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'ورود الزامی است.', 'code' => 'auth'], 401);
        }
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'خطای امنیتی. صفحه را رفرش کنید.', 'code' => 'nonce'], 403);
        }

        $user_id = get_current_user_id();
        if (!$this->rate_limit($user_id, $bucket, $max_hits, $window)) {
            wp_send_json_error(['message' => 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.', 'code' => 'rate_limited'], 429);
        }
        return $user_id;
    }

    private function rate_limit(int $user_id, string $bucket, int $max_hits, int $window): bool
    {
        $key   = 'mtw_wc_' . $bucket . '_' . $user_id;
        $state = get_transient($key);
        if (!is_array($state) || empty($state['count']) || empty($state['start'])) {
            set_transient($key, ['count' => 1, 'start' => time()], $window);
            return true;
        }
        $elapsed = time() - (int) $state['start'];
        if ($elapsed >= $window) {
            set_transient($key, ['count' => 1, 'start' => time()], $window);
            return true;
        }
        $count = (int) $state['count'];
        if ($count >= $max_hits) {
            return false;
        }
        $state['count'] = $count + 1;
        set_transient($key, $state, max(1, $window - $elapsed));
        return true;
    }
}
