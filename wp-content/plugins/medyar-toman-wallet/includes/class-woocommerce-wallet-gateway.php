<?php
/**
 * WooCommerce Wallet Payment Gateway
 *
 * Full wallet payment method. Partial/AJAX apply lives in MTW_WC_Checkout_Wallet.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WALLET_PARTIAL_FEE_LABEL')) {
    define('WALLET_PARTIAL_FEE_LABEL', 'پرداخت از کیف پول');
}

function wallet_init_woocommerce_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Wallet_Gateway extends WC_Payment_Gateway
    {
        private $wallet_manager;

        public function __construct()
        {
            $this->id                 = 'wallet';
            $this->icon               = '';
            $this->has_fields         = false;
            $this->method_title       = 'کیف پول';
            $this->method_description = 'پرداخت از طریق موجودی کیف پول کاربر';
            $this->supports           = ['products', 'refunds'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled     = $this->get_option('enabled');

            $this->wallet_manager = Wallet_Manager::get_instance();

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title'   => 'فعال/غیرفعال',
                    'type'    => 'checkbox',
                    'label'   => 'فعال‌سازی پرداخت از کیف پول',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'       => 'عنوان',
                    'type'        => 'text',
                    'description' => 'عنوانی که کاربر در صفحه تسویه‌حساب می‌بیند',
                    'default'     => 'کیف پول',
                    'desc_tip'    => true,
                ],
                'description' => [
                    'title'       => 'توضیحات',
                    'type'        => 'textarea',
                    'description' => 'توضیحاتی که در صفحه تسویه‌حساب نمایش داده می‌شود',
                    'default'     => 'پرداخت از موجودی کیف پول خود',
                ],
                'allow_partial' => [
                    'title'       => 'پرداخت جزئی',
                    'type'        => 'checkbox',
                    'label'       => 'اجازه پرداخت جزئی از کیف پول همراه با درگاه دیگر',
                    'description' => 'اگر موجودی کافی نباشد، امکان پرداخت مابقی از درگاه دیگر',
                    'default'     => 'yes',
                ],
            ];
        }

        public function is_available()
        {
            // Wallet is applied only via the checkout checkbox UI — never as a WC payment radio.
            return false;
        }

        public function payment_fields()
        {
            if ($this->description) {
                echo '<p class="mtw-wc-wallet-gateway-desc">' . wp_kses_post($this->description) . '</p>';
            }
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                wc_add_notice('سفارش یافت نشد.', 'error');
                return ['result' => 'failure'];
            }

            $user_id     = (int) $order->get_user_id();
            $order_total = (float) $order->get_total();

            if ($user_id <= 0 || $user_id !== get_current_user_id()) {
                wc_add_notice('خطا در شناسایی کاربر', 'error');
                return ['result' => 'failure'];
            }

            // Wallet gateway is only valid when payable total is zero (fully covered by fee).
            if ($order_total > 0.00001) {
                wc_add_notice('لطفا برای پرداخت مبلغ باقی‌مانده، یک درگاه پرداخت انتخاب کنید.', 'error');
                return ['result' => 'failure'];
            }

            $helper = class_exists('MTW_WC_Checkout_Wallet') ? MTW_WC_Checkout_Wallet::get_instance() : null;
            $wallet_amount = $helper
                ? (int) round($helper->order_wallet_fee_amount($order))
                : $this->legacy_fee_amount($order);

            if ($wallet_amount <= 0) {
                wc_add_notice('خطا در اطلاعات پرداخت کیف پول', 'error');
                return ['result' => 'failure'];
            }

            // Live balance check before marking paid.
            $balance = (int) $this->wallet_manager->get_balance($user_id);
            if ($balance < $wallet_amount) {
                wc_add_notice('موجودی کیف پول شما کافی نیست. صفحه را رفرش کنید.', 'error');
                return ['result' => 'failure'];
            }

            if ($order->get_meta(MTW_WC_Checkout_Wallet::META_DEDUCTED) !== 'yes') {
                $result = $this->wallet_manager->reduce_balance(
                    $user_id,
                    $wallet_amount,
                    'spend',
                    [
                        'status'           => 'completed',
                        'transaction_code' => 'ORDER_' . $order->get_id() . '_' . wp_generate_password(6, false),
                        'description'      => 'پرداخت سفارش #' . $order->get_id() . ' از کیف پول',
                        'p_info'           => ['type' => 'Wallet_gateway', 'id' => (int) $order->get_id()],
                    ]
                );

                if (is_wp_error($result)) {
                    wc_add_notice($result->get_error_message(), 'error');
                    return ['result' => 'failure'];
                }

                $order->update_meta_data(MTW_WC_Checkout_Wallet::META_DEDUCTED, 'yes');
                $order->update_meta_data(MTW_WC_Checkout_Wallet::META_AMOUNT, $wallet_amount);
                $order->update_meta_data(MTW_WC_Checkout_Wallet::META_PARTIAL, $wallet_amount);
                $order->update_meta_data(MTW_WC_Checkout_Wallet::META_TYPE, 'full_from_wallet');
                $order->add_order_note(sprintf('پرداخت کامل از کیف پول: %s تومان', number_format_i18n($wallet_amount)));
                $order->save();
            }

            $order->payment_complete();

            if (WC()->session) {
                WC()->session->__unset(MTW_WC_Checkout_Wallet::SESSION_AMOUNT);
                WC()->session->__unset(MTW_WC_Checkout_Wallet::SESSION_USER);
                WC()->session->__unset(MTW_WC_Checkout_Wallet::SESSION_ENABLED);
            }
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        private function legacy_fee_amount(WC_Order $order): float
        {
            $total = 0.0;
            foreach ($order->get_fees() as $fee) {
                $fee_total = (float) $fee->get_total();
                if ($fee->get_name() === WALLET_PARTIAL_FEE_LABEL && $fee_total < 0) {
                    $total += abs($fee_total);
                }
            }
            return $total;
        }

        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order   = wc_get_order($order_id);
            $user_id = $order ? (int) $order->get_user_id() : 0;

            if (!$user_id) {
                return new WP_Error('error', 'کاربر یافت نشد');
            }

            if ($order->get_meta(MTW_WC_Checkout_Wallet::META_DEDUCTED) !== 'yes') {
                return new WP_Error('error', 'این سفارش از کیف پول کسر نشده است');
            }

            $wallet_payment  = (float) $order->get_meta(MTW_WC_Checkout_Wallet::META_AMOUNT);
            $partial_payment = (float) $order->get_meta(MTW_WC_Checkout_Wallet::META_PARTIAL);
            $total_wallet    = $wallet_payment > 0 ? $wallet_payment : $partial_payment;

            if ($total_wallet <= 0) {
                return new WP_Error('error', 'این سفارش از کیف پول پرداخت نشده است');
            }

            if ($amount === null) {
                $amount = $total_wallet;
            }
            $amount = (float) $amount;
            if ($amount <= 0 || $amount > $total_wallet + 0.00001) {
                return new WP_Error('error', 'مبلغ بازگشت نامعتبر است');
            }

            $result = $this->wallet_manager->add_balance(
                $user_id,
                $amount,
                'refund_order',
                [
                    'status'           => 'completed',
                    'transaction_code' => 'REFUND_ORDER_' . $order_id . '_' . wp_generate_password(6, false),
                    'description'      => 'بازگشت وجه سفارش #' . $order_id . ($reason ? ' - ' . $reason : ''),
                    'p_info'           => ['type' => 'Wallet_gateway', 'id' => (int) $order_id],
                ]
            );

            if (is_wp_error($result)) {
                return $result;
            }

            $order->add_order_note(sprintf('بازگشت %s تومان به کیف پول کاربر', number_format_i18n($amount)));
            return true;
        }
    }

    function wallet_add_gateway_class($gateways)
    {
        $gateways[] = 'WC_Wallet_Gateway';
        return $gateways;
    }

    add_filter('woocommerce_payment_gateways', 'wallet_add_gateway_class', 10);
}

add_action('plugins_loaded', 'wallet_init_woocommerce_gateway', 20);

add_action('woocommerce_admin_order_data_after_billing_address', 'wallet_display_payment_info_admin');

/**
 * Gross order total (before wallet fee) for admin display.
 */
function mtw_order_gross_total(WC_Order $order): float
{
    $payable = (float) $order->get_meta('_wallet_payable_before');
    if ($payable > 0) {
        return $payable;
    }

    $wallet = (float) $order->get_meta('_wallet_payment_amount');
    if ($wallet <= 0) {
        $wallet = (float) $order->get_meta('_wallet_partial_payment');
    }
    if ($wallet <= 0 && class_exists('MTW_WC_Checkout_Wallet')) {
        $wallet = (float) MTW_WC_Checkout_Wallet::get_instance()->order_wallet_fee_amount($order);
    }
    if ($wallet <= 0) {
        foreach ($order->get_items('fee') as $fee) {
            $fee_total = (float) $fee->get_total();
            if ($fee->get_name() === 'پرداخت از کیف پول' && $fee_total < 0) {
                $wallet = abs($fee_total);
                break;
            }
        }
    }

    if ($wallet <= 0) {
        return (float) $order->get_total();
    }

    return (float) $order->get_total() + $wallet;
}

function wallet_display_payment_info_admin($order)
{
    if (!$order instanceof WC_Order) {
        return;
    }

    $wallet_amount = (float) $order->get_meta('_wallet_payment_amount');
    if ($wallet_amount <= 0) {
        $wallet_amount = (float) $order->get_meta('_wallet_partial_payment');
    }
    if ($wallet_amount <= 0) {
        foreach ($order->get_items('fee') as $fee) {
            $fee_total = (float) $fee->get_total();
            if ($fee->get_name() === 'پرداخت از کیف پول' && $fee_total < 0) {
                $wallet_amount = abs($fee_total);
                break;
            }
        }
    }
    if ($wallet_amount <= 0) {
        return;
    }

    $type      = (string) $order->get_meta('_wallet_payment_type');
    $deducted  = (string) $order->get_meta('_wallet_balance_deducted');
    $payable   = mtw_order_gross_total($order);
    $remainder = max(0.0, (float) $order->get_total());

    $type_label = ($type === 'full_from_wallet' || $remainder <= 0.00001)
        ? 'پرداخت کامل از کیف پول'
        : 'پرداخت جزئی از کیف پول';

    if ($deducted === 'yes') {
        $status_label = 'موجودی کسر شده';
        $status_class = 'is-ok';
    } elseif ($deducted === 'refunded') {
        $status_label = 'بازگشت به کیف پول';
        $status_class = 'is-refunded';
    } else {
        $status_label = 'در انتظار نهایی‌شدن';
        $status_class = 'is-pending';
    }

    echo '<div class="mtw-admin-wallet-box">';
    echo '<div class="mtw-admin-wallet-box__head">';
    echo '<h3 class="mtw-admin-wallet-box__title">اطلاعات پرداخت کیف پول</h3>';
    echo '<span class="mtw-admin-wallet-box__status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';
    echo '</div><div class="mtw-admin-wallet-box__grid">';

    echo '<div class="mtw-admin-wallet-box__item"><span class="mtw-admin-wallet-box__label">نوع پرداخت</span>';
    echo '<span class="mtw-admin-wallet-box__value">' . esc_html($type_label) . '</span></div>';

    echo '<div class="mtw-admin-wallet-box__item"><span class="mtw-admin-wallet-box__label">مبلغ کل سفارش</span>';
    echo '<span class="mtw-admin-wallet-box__value is-gross">' . wp_kses_post(wc_price($payable)) . '</span></div>';

    echo '<div class="mtw-admin-wallet-box__item"><span class="mtw-admin-wallet-box__label">پرداخت از کیف پول</span>';
    echo '<span class="mtw-admin-wallet-box__value is-wallet">' . wp_kses_post(wc_price($wallet_amount)) . '</span></div>';

    if ($type !== 'full_from_wallet' && $remainder > 0.00001) {
        echo '<div class="mtw-admin-wallet-box__item"><span class="mtw-admin-wallet-box__label">باقی‌مانده درگاه</span>';
        echo '<span class="mtw-admin-wallet-box__value">' . wp_kses_post(wc_price($remainder)) . '</span></div>';
    }

    echo '</div></div>';
}

add_action('woocommerce_order_details_after_order_table', 'wallet_display_payment_info_customer');
function wallet_display_payment_info_customer($order)
{
    if (!$order instanceof WC_Order) {
        return;
    }

    $wallet_amount = (float) $order->get_meta('_wallet_payment_amount');
    if ($wallet_amount <= 0) {
        $wallet_amount = (float) $order->get_meta('_wallet_partial_payment');
    }
    if ($wallet_amount <= 0) {
        return;
    }

    $type = (string) $order->get_meta('_wallet_payment_type');
    $label = ($type === 'full_from_wallet')
        ? 'پرداخت کامل از کیف پول'
        : 'پرداخت جزئی از کیف پول';

    echo '<section class="woocommerce-wallet-payment-info" style="margin-top:20px;padding:15px;background:#f8f9fa;border-right:4px solid #2271b1;">';
    echo '<h2 style="margin-top:0;">اطلاعات پرداخت از کیف پول</h2>';
    echo '<p>' . esc_html($label) . ': <strong>' . wp_kses_post(wc_price($wallet_amount)) . '</strong></p>';
    echo '</section>';
}
