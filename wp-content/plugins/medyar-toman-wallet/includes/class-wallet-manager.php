<?php

/**
 * Wallet Manager Class
 * 
 * Manages Toman balance operations including charging, spending, and withdrawals
 * This is the core class for Toman wallet functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Wallet_Manager')) { return; }

class Wallet_Manager
{

    private static $instance = null;
    private $transaction_manager;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->transaction_manager = Wallet_Transaction_Manager::get_instance();
    }

    /**
     * Get user Toman balance
     * 
     * @param int $user_id User ID
     * @return float Current Toman balance
     */
    public function get_balance($user_id)
    {
        $user_id = (int) $user_id;
        if (class_exists('MTW_Sheyda_Balance_Bridge') && MTW_Sheyda_Balance_Bridge::tables_ready()) {
            $available = MTW_Sheyda_Balance_Bridge::get_available($user_id);
            if ($available !== null) {
                return $available;
            }
        }
        $balance = get_user_meta($user_id, 'wallet_toman_balance', true);
        return intval($balance);
    }

    /**
     * Add Toman balance to user
     * 
     * @param int $user_id User ID
     * @param float $amount Amount to add
     * @param string $operation_type Operation type
     * @param array $transaction_data Additional transaction data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function add_balance($user_id, $amount, $operation_type = 'charge', $transaction_data = array())
    {
        // Validate inputs
        $validations = array(
            wallet_validate_user_id($user_id),
            wallet_validate_amount($amount),
            wallet_validate_operation_type($operation_type)
        );

        $validation_result = wallet_check_validations($validations);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        if (class_exists('MTW_Sheyda_Balance_Bridge')) {
            MTW_Sheyda_Balance_Bridge::pull_to_meta((int) $user_id);
        }

        global $wpdb;

        $transaction_info = array_merge(
            array(
                'user_id' => $user_id,
                'amount' => $amount,
                'balance_type' => 'toman',
                'operation_type' => $operation_type,
                'status' => 'pending'
            ),
            $transaction_data
        );

        // افزایش موجودی و ثبت تراکنش در یک واحد اتمیک با قفل ردیف موجودی
        $wpdb->query('START TRANSACTION');

        try {
            $current_balance = wallet_locked_read_balance($user_id, 'toman');
            wallet_write_balance($user_id, 'toman', $current_balance + $amount);

            $transaction_id = $this->transaction_manager->create_transaction($transaction_info);

            if (is_wp_error($transaction_id)) {
                throw new Exception($transaction_id->get_error_message());
            }

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            wallet_flush_balance_cache($user_id);

            return new WP_Error('balance_add_failed', $e->getMessage());
        }

        // Fire action hook
        do_action('wallet_toman_balance_added', $user_id, $amount, $operation_type, $transaction_id);

        return true;
    }

    /**
     * Reduce Toman balance from user
     * 
     * @param int $user_id User ID
     * @param float $amount Amount to reduce
     * @param string $operation_type Operation type
     * @param array $transaction_data Additional transaction data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function reduce_balance($user_id, $amount, $operation_type = 'spend', $transaction_data = array())
    {
        if (class_exists('MTW_Sheyda_Balance_Bridge')) {
            MTW_Sheyda_Balance_Bridge::pull_to_meta((int) $user_id);
        }

        // Validate inputs
        $validations = array(
            wallet_validate_user_id($user_id),
            wallet_validate_amount($amount),
            wallet_validate_operation_type($operation_type),
            wallet_validate_sufficient_balance($user_id, $amount, 'toman')
        );

        $validation_result = wallet_check_validations($validations);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }

        global $wpdb;

        $transaction_info = array_merge(
            array(
                'user_id' => $user_id,
                'amount' => -$amount, // Negative for reduction
                'balance_type' => 'toman',
                'operation_type' => $operation_type,
                'status' => 'pending'
            ),
            $transaction_data
        );

        // کسر موجودی و ثبت تراکنش در یک واحد اتمیک با قفل ردیف موجودی
        $wpdb->query('START TRANSACTION');

        try {
            $current_balance = wallet_locked_read_balance($user_id, 'toman');
            $new_balance     = $current_balance - $amount;

            if ($new_balance < 0) {
                throw new Exception(__('موجودی کیف پول شما کافی نیست.', 'medyar-toman-wallet'));
            }

            wallet_write_balance($user_id, 'toman', $new_balance);

            $transaction_id = $this->transaction_manager->create_transaction($transaction_info);

            if (is_wp_error($transaction_id)) {
                throw new Exception($transaction_id->get_error_message());
            }

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            wallet_flush_balance_cache($user_id);

            return new WP_Error('balance_reduce_failed', $e->getMessage());
        }

        // Fire action hook
        do_action('wallet_toman_balance_reduced', $user_id, $amount, $operation_type, $transaction_id);

        return true;
    }

    /**
     * شروع شارژ کیف پول تومانی از طریق درگاه پرداخت (زرین‌پال)
     *
     * فلو:
     *   ① تراکنش charge با status=pending ثبت می‌شود
     *   ② درخواست به زرین‌پال → authority
     *   ③ رکورد پرداخت در wp_wallet_payment_transactions ثبت می‌شود
     *   ④ p_info تراکنش به رکورد پرداخت لینک می‌شود
     *   ⑤ transient ذخیره می‌شود
     *   ⑥ redirect_url برمی‌گردد
     *
     * پس از callback موفق:
     *   تراکنش → completed و تومان فوری به موجودی اضافه می‌شود
     *   (نیازی به تأیید ادمین نیست)
     *
     * @param int    $user_id
     * @param int    $amount      مبلغ به تومان
     * @param array  $user_info   mobile | email | card_number (الزامی)
     * @param string $gateway_id
     * @return array|WP_Error
     */
    public function initiate_charge_via_gateway(
        int    $user_id,
        int    $amount,
        array  $user_info  = [],
        string $gateway_id = 'zarinpal'
    ) {
        $check = wallet_check_validations([
            wallet_validate_user_id($user_id),
            wallet_validate_amount($amount),
        ]);
        if (is_wp_error($check)) {
            return $check;
        }

        $card_pan = Wallet_Zarinpal_Gateway::resolve_user_card_pan(
            $user_id,
            $user_info['card_number'] ?? ($user_info['card_pan'] ?? '')
        );
        if (is_wp_error($card_pan)) {
            return $card_pan;
        }

        $gateway = new Wallet_Zarinpal_Gateway();

        // ① ثبت تراکنش charge با status=pending
        $tx_id = $this->transaction_manager->create_transaction([
            'user_id'        => $user_id,
            'amount'         => $amount,
            'balance_type'   => 'toman',
            'operation_type' => 'charge',
            'status'         => 'pending',
            'description'    => sprintf(
                'شارژ کیف پول تومانی — مبلغ %s تومان — در انتظار پرداخت از درگاه',
                number_format($amount, 0)
            ),
        ]);

        if (is_wp_error($tx_id)) {
            return $tx_id;
        }

        $tx_row  = $this->transaction_manager->get_transaction($tx_id);
        $tx_code = $tx_row->transaction_code;

        // ② درخواست به زرین‌پال
        $user       = get_user_by('id', $user_id);
        $user_email = $user_info['email']  ?? ($user ? $user->user_email : '');
        $user_phone = $user_info['mobile'] ?? '';

        $result = $gateway->initiate_payment($amount, $user_id, [
            'purpose'     => 'charge',
            'description' => sprintf('شارژ کیف پول — %s تومان', number_format($amount, 0)),
            'mobile'      => $user_phone,
            'email'       => $user_email,
            'order_id'    => $tx_code,
            'card_pan'    => $card_pan,
        ]);

        if (!$result['success']) {
            $this->transaction_manager->update_status($tx_id, 'canceled');
            return new WP_Error('gateway_error', $result['error']);
        }

        $authority = $result['authority'];

        // ③ ثبت رکورد پرداخت (card_pan برای تطبیق امن در callback بدون transient)
        $pay_id = $this->transaction_manager->create_payment_record([
            'tx_id'     => $tx_id,
            'user_id'   => $user_id,
            'gateway'   => $gateway_id,
            'amount'    => $amount,
            'authority' => $authority,
            'status'    => 'pending',
            'card_pan'  => $card_pan,
        ]);

        if (is_wp_error($pay_id)) {
            $this->transaction_manager->update_status($tx_id, 'canceled');
            return $pay_id;
        }

        // ④ لینک p_info
        $this->transaction_manager->update_transaction($tx_id, [
            'p_info' => ['type' => 'Pay_gateway', 'id' => $pay_id],
        ]);

        // ⑤ ذخیره در transient (کمک‌کننده؛ منبع حقیقت رکورد پرداخت است)
        MTW_Payment_Handler::store_pending($authority, [
            'purpose'      => 'charge',
            'user_id'      => $user_id,
            'toman_amount' => $amount,
            'tx_id'        => $tx_id,
            'pay_id'       => $pay_id,
            'tx_code'      => $tx_code,
            'gateway_id'   => $gateway_id,
            'card_pan'     => $card_pan,
        ]);

        // ⑥ کرون تک‌رویدادی: ۲۰ دقیقه بعد اگر هنوز pending بود → verify/لغو
        MTW_Payment_Handler::schedule_expire((int) $tx_id);

        return [
            'success'      => true,
            'redirect_url' => $result['redirect_url'],
            'authority'    => $authority,
            'tx_id'        => $tx_id,
            'pay_id'       => $pay_id,
        ];
    }

    /**
     * Request withdrawal to bank account
     *
     * موجودی تومان کاهش می‌یابد و تراکنش در processing می‌ماند
     * تا ادمین آن را تأیید (completed) یا رد (rejected) کند
     *
     * @param int    $user_id
     * @param int    $amount
     * @param string $card_number   شماره کارت ۱۶ رقمی
     * @param string $iban          شماره شبا (IR + ۲۴ رقم)
     * @param array  $bank_info     اطلاعات تکمیلی
     * @return true|WP_Error
     */
    public function request_withdrawal($user_id, $amount, $card_number, $iban = '', $bank_info = [])
    {
        global $wpdb;

        if (class_exists('MTW_Sheyda_Balance_Bridge')) {
            MTW_Sheyda_Balance_Bridge::pull_to_meta((int) $user_id);
        }

        $check = wallet_check_validations([
            wallet_validate_user_id($user_id),
            wallet_validate_amount($amount),
            wallet_validate_sufficient_balance($user_id, $amount, 'toman'),
        ]);
        if (is_wp_error($check)) {
            return $check;
        }

        if (function_exists('medyar_verification_wallet_withdrawal_check')) {
            $verification_check = medyar_verification_wallet_withdrawal_check($user_id);
            if (is_wp_error($verification_check)) {
                return $verification_check;
            }
        }

        $amount = intval($amount);

        if (function_exists('medyar_wallet_get_max_withdraw')) {
            $max_withdraw = medyar_wallet_get_max_withdraw();
            if ($amount > $max_withdraw) {
                $msg = function_exists('medyar_wallet_max_withdraw_error_message')
                    ? medyar_wallet_max_withdraw_error_message($max_withdraw)
                    : sprintf('حداکثر مبلغ برداشت %s تومان است.', number_format_i18n($max_withdraw));
                return new WP_Error('withdraw_max_exceeded', $msg);
            }
        }

        if (empty($card_number) && empty($iban)) {
            return new WP_Error('missing_bank_info', 'شماره کارت یا شماره شبا الزامی است.');
        }

        if (function_exists('medyar_user_can_request_withdrawal') && !medyar_user_can_request_withdrawal($user_id)) {
            return new WP_Error(
                'withdraw_daily_limit',
                'شما می‌توانید حداکثر ۲ درخواست برداشت وجه در هر ۲۴ ساعت ثبت کنید.'
            );
        }

        $card_number = sanitize_text_field($card_number);
        $iban        = strtoupper(sanitize_text_field($iban));

        // اعتبارسنجی شبا: IR + ۲۴ رقم
        if (!empty($iban) && !preg_match('/^IR\d{24}$/', $iban)) {
            return new WP_Error('invalid_iban', 'شماره شبا نامعتبر است. فرمت صحیح: IR + ۲۴ رقم');
        }

        // Initial withdrawal description (without date).
        $desc_parts = [sprintf('درخواست برداشت %s تومان', number_format($amount, 0))];
        if (!empty($card_number)) {
            $desc_parts[] = 'شماره کارت: ' . $card_number;
        }
        if (!empty($iban)) {
            $desc_parts[] = 'شبا: ' . $iban;
        }
        $desc_parts[] = 'در انتظار بررسی';

        $wpdb->query('START TRANSACTION');

        try {
            // ① کاهش موجودی با قفل ردیف (هم‌راستا با helpers/atomic-balance)
            $current = wallet_locked_read_balance((int) $user_id, 'toman');
            $new_bal = $current - $amount;
            if ($new_bal < 0) {
                throw new Exception('موجودی کافی نیست.');
            }
            wallet_write_balance((int) $user_id, 'toman', $new_bal);

            // ② ثبت تراکنش برداشت با status=processing
            $tx_id = $this->transaction_manager->create_transaction([
                'user_id'        => $user_id,
                'amount'         => -$amount,
                'balance_type'   => 'toman',
                'operation_type' => 'withdraw',
                'status'         => 'processing',
                'description'    => implode(' — ', $desc_parts),
                'p_info'         => [
                    'card_number' => $card_number,
                    'iban'        => $iban,
                ],
            ]);

            if (is_wp_error($tx_id)) {
                throw new Exception($tx_id->get_error_message());
            }

            $wpdb->query('COMMIT');

            error_log(sprintf(
                '[WalletWithdraw] user=%d amount=%d card=%s iban=%s tx=%d',
                $user_id,
                $amount,
                $card_number,
                $iban,
                $tx_id
            ));
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wallet_flush_balance_cache((int) $user_id);
            error_log('[WalletWithdraw] FAILED user=' . $user_id . ' error=' . $e->getMessage());
            return new WP_Error('withdraw_failed', $e->getMessage());
        }

        // همگام‌سازی جدول Sheyda (منبع نمایش موجودی در UI)
        do_action('wallet_toman_balance_reduced', $user_id, $amount, 'withdraw', $tx_id);

        // ذخیره اطلاعات بانکی کاربر
        $bank_info['card_number'] = $card_number;
        $bank_info['iban']        = $iban;
        update_user_meta($user_id, '_wallet_last_bank_info', $bank_info);

        $tx_row = $this->transaction_manager->get_transaction($tx_id);

        do_action('wallet_withdrawal_requested', $user_id, $amount, $bank_info, $tx_id);

        return [
            'sti' => $tx_id,
            'stc' => $tx_row->transaction_code,
        ];
    }
}
