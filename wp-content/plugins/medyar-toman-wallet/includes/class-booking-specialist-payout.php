<?php
/**
 * Credit specialist wallet when a booking visit order reaches completed.
 *
 * Theme ionCube path credits Sheyda ledger only (order note "Booking income…").
 * This class ensures a matching MTW wallet_transactions row exists for the specialist UI.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('MTW_Booking_Specialist_Payout')) {
    return;
}

final class MTW_Booking_Specialist_Payout
{
    public const META_DONE = '_mtw_booking_specialist_payout_tx';
    public const META_PAYOUT_AMOUNT = '_mtw_booking_specialist_payout_amount';
    public const META_REVERSAL = '_mtw_booking_specialist_payout_reversal_tx';
    public const OP_TYPE = 'booking_payout';
    public const OP_REVERSAL = 'booking_payout_reversal';

    public static function init(): void
    {
        // Theme Sheyda payout often skips pending/on-hold → completed (no payment_complete).
        // Cover completed regardless of previous status, plus a late save catch-up.
        add_action('woocommerce_order_status_completed', [__CLASS__, 'maybe_payout'], 99, 1);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'maybe_payout_on_status_change'], 99, 4);
        add_action('woocommerce_payment_complete', [__CLASS__, 'maybe_payout_if_completed'], 40, 1);
        // After admin save / theme meta writes finish.
        add_action('woocommerce_update_order', [__CLASS__, 'maybe_payout_on_order_update'], 110, 1);
        // Claw back specialist income when a completed booking is cancelled.
        // Theme cancel_booking() refuses completed bookings; WC admin completed→cancelled
        // never sets customer_refund_id — so reverse must not depend on that flag alone.
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'maybe_reverse_on_cancelled_booking'], 100, 1);
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'maybe_reverse_on_cancelled_booking'], 100, 1);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'maybe_reverse_on_status_change'], 100, 4);
        // After theme cancel_booking saves refund meta (runs after status hook).
        add_action('woocommerce_update_order', [__CLASS__, 'maybe_reverse_on_cancelled_booking'], 100, 1);
        add_filter('sheyda/wallet/ledger/types', [__CLASS__, 'register_ledger_type']);
    }

    /**
     * @param int    $order_id
     * @param string $from
     * @param string $to
     * @param WC_Order $order
     */
    public static function maybe_payout_on_status_change($order_id, $from = '', $to = '', $order = null): void
    {
        $to = is_string($to) ? str_replace('wc-', '', $to) : '';
        if ($to !== 'completed') {
            return;
        }
        self::maybe_payout($order instanceof WC_Order ? $order : $order_id);
    }

    /**
     * @param int $order_id
     */
    public static function maybe_payout_if_completed($order_id): void
    {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order && $order->get_status() === 'completed') {
            self::maybe_payout($order);
        }
    }

    /**
     * Catch-up when admin moves pending → completed in one save (meta ready only after hooks).
     *
     * @param int $order_id
     */
    public static function maybe_payout_on_order_update($order_id): void
    {
        static $busy = [];
        $order_id = (int) $order_id;
        if ($order_id <= 0 || !empty($busy[$order_id])) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || $order->get_status() !== 'completed') {
            return;
        }
        if ((int) $order->get_meta(self::META_DONE) > 0) {
            return;
        }

        $busy[$order_id] = true;
        try {
            self::maybe_payout($order);
        } finally {
            unset($busy[$order_id]);
        }
    }

    /**
     * @param array<string,string> $types
     * @return array<string,string>
     */
    public static function register_ledger_type(array $types): array
    {
        if (!isset($types['booking_payout'])) {
            $types['booking_payout'] = 'درآمد نوبت';
        }
        if (!isset($types['booking_payout_reversal'])) {
            $types['booking_payout_reversal'] = 'برگشت درآمد نوبت';
        }
        return $types;
    }

    /**
     * @param int    $order_id
     * @param string $from
     * @param string $to
     * @param mixed  $order
     */
    public static function maybe_reverse_on_status_change($order_id, $from = '', $to = '', $order = null): void
    {
        $to = is_string($to) ? str_replace('wc-', '', $to) : '';
        if (!in_array($to, ['cancelled', 'refunded'], true)) {
            return;
        }
        self::maybe_reverse_on_cancelled_booking($order instanceof WC_Order ? $order : $order_id);
    }

    /**
     * Claw back specialist visit income that was credited on completed when the
     * booking/order is cancelled or refunded.
     *
     * Paths:
     * - Theme cancel_booking() (processing etc.): may set customer_refund_id and
     *   reduce specialist_income → reverse = original payout − remaining income.
     * - WC admin completed → cancelled: theme refuses completed cancels, so meta
     *   is unchanged → reverse the full prior payout.
     *
     * @param int|WC_Order $order_id
     */
    public static function maybe_reverse_on_cancelled_booking($order_id): void
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

        // Need a prior MTW (or mirrored) payout to reverse.
        $payout_meta = (int) $order->get_meta(self::META_DONE);
        $raw_book = $order->get_meta('_booking_data');
        if (!is_array($raw_book)) {
            $raw_book = [];
        }
        $book_data = self::resolve_booking_data($order);
        if ($book_data === null && $payout_meta <= 0) {
            return;
        }
        if ($book_data === null) {
            $book_data = $raw_book;
        }

        if ((int) $order->get_meta(self::META_REVERSAL) > 0) {
            return;
        }

        $rev_code = self::reversal_tx_code($book_data, $order);
        if (self::find_tx_id_by_code($rev_code) > 0) {
            return;
        }

        $payout_amount = self::resolve_original_payout_amount($order, $book_data);
        if ($payout_amount <= 0 && $payout_meta <= 0) {
            return;
        }
        if ($payout_amount <= 0) {
            return;
        }

        $lock_key = 'mtw_booking_payout_rev_' . $order_id;
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 90);
        $busy[$order_id] = true;

        try {
            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order || !in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
                return;
            }
            if ((int) $order->get_meta(self::META_REVERSAL) > 0 || self::find_tx_id_by_code($rev_code) > 0) {
                return;
            }

            $raw_book = $order->get_meta('_booking_data');
            if (!is_array($raw_book)) {
                $raw_book = [];
            }
            $book_data = self::resolve_booking_data($order);
            if ($book_data === null) {
                $book_data = $raw_book;
            }

            $user_id = self::resolve_specialist_user_id($order, $book_data);
            if ($user_id <= 0) {
                $order->add_order_note('برگشت درآمد نوبت انجام نشد: کاربر متخصص یافت نشد.');
                $order->save();
                return;
            }

            $payout_amount = self::resolve_original_payout_amount($order, $book_data);
            if ($payout_amount <= 0) {
                return;
            }

            // Theme cancel_booking writes canceled_at / canceled_by / customer_refund_id
            // and the reduced specialist_income. WC-only status change does not.
            // resolve_booking_data() must NOT be used for "remaining" — it rehydrates
            // specialist_income from the booking row and can zero out the reverse.
            $theme_cancel = !empty($raw_book['customer_refund_id'])
                || !empty($raw_book['canceled_at'])
                || !empty($raw_book['canceled_by']);

            if ($theme_cancel && array_key_exists('specialist_income', $raw_book)) {
                $remaining_specialist = (int) max(0, round((float) $raw_book['specialist_income']));
            } else {
                $remaining_specialist = 0;
            }

            $reverse_amount = max(0, $payout_amount - $remaining_specialist);
            if ($reverse_amount <= 0) {
                return;
            }

            if (!class_exists('Wallet_Manager')) {
                return;
            }

            if (class_exists('MTW_Sheyda_Balance_Bridge')) {
                MTW_Sheyda_Balance_Bridge::pull_to_meta($user_id);
            }

            $book_id = (int) ($book_data['book_id'] ?? ($raw_book['book_id'] ?? 0));
            $payout_tx_id = (int) $order->get_meta(self::META_DONE);

            $result = Wallet_Manager::get_instance()->reduce_balance(
                $user_id,
                $reverse_amount,
                self::OP_REVERSAL,
                [
                    'status'           => 'completed',
                    'transaction_code' => $rev_code,
                    'description'      => sprintf(
                        'برگشت درآمد نوبت #%s (لغو سفارش #%s)',
                        $book_id,
                        $order->get_id()
                    ),
                    'p_info'           => [
                        'type'               => 'booking_payout_reversal',
                        'order_id'           => (int) $order->get_id(),
                        'book_id'            => $book_id,
                        'specialist_id'      => (int) ($book_data['specialist_id'] ?? ($raw_book['specialist_id'] ?? 0)),
                        'payout_tx_id'       => $payout_tx_id,
                        'customer_refund_id' => (int) ($raw_book['customer_refund_id'] ?? 0),
                        'theme_cancel'       => $theme_cancel ? 1 : 0,
                    ],
                ]
            );

            if (is_wp_error($result)) {
                $order->add_order_note(
                    'برگشت درآمد نوبت متخصص ناموفق: ' . $result->get_error_message()
                );
                $order->save();
                return;
            }

            $rev_tx_id = self::find_tx_id_by_code($rev_code);
            if ($rev_tx_id > 0) {
                $order->update_meta_data(self::META_REVERSAL, $rev_tx_id);
            }
            // Drop Medyar booking fee from income totals after successful clawback.
            if ((int) $order->get_meta('_medyar_booking_fee_amount') > 0) {
                $order->update_meta_data('_medyar_booking_fee_reversed', '1');
            }
            $order->add_order_note(sprintf(
                'درآمد متخصص بابت لغو نوبت از کیف پول کسر شد: %s تومان (کاربر #%d%s).',
                number_format_i18n($reverse_amount),
                $user_id,
                $rev_tx_id > 0 ? '، تراکنش #' . $rev_tx_id : ''
            ));
            $order->save();
        } finally {
            unset($busy[$order_id]);
            delete_transient($lock_key);
        }
    }

    /**
     * @param int|WC_Order $order_id
     */
    public static function maybe_payout($order_id): void
    {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ($order->get_status() !== 'completed') {
            return;
        }

        $book_data = self::resolve_booking_data($order);
        if ($book_data === null) {
            if (self::book_id_from_order_items($order) > 0) {
                $order->add_order_note('واریز درآمد نوبت انجام نشد: داده نوبت روی سفارش ناقص است.');
                $order->save();
            }
            return;
        }

        $code = self::tx_code($book_data, $order);
        $existing_mtw = self::find_tx_id_by_code($code);
        if ($existing_mtw > 0) {
            if ((int) $order->get_meta(self::META_DONE) !== $existing_mtw) {
                $order->update_meta_data(self::META_DONE, $existing_mtw);
                $order->save();
            }
            return;
        }

        // Stale META_DONE (e.g. Sheyda ledger id written earlier without MTW tx) — ignore.
        $prior_meta = (int) $order->get_meta(self::META_DONE);

        $lock_key = 'mtw_booking_payout_' . $order->get_id();
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 90);

        try {
            // Re-read after lock.
            $order = wc_get_order($order->get_id());
            if (!$order instanceof WC_Order || $order->get_status() !== 'completed') {
                return;
            }
            $book_data = self::resolve_booking_data($order);
            if ($book_data === null) {
                return;
            }
            $code = self::tx_code($book_data, $order);
            $existing_mtw = self::find_tx_id_by_code($code);
            if ($existing_mtw > 0) {
                $order->update_meta_data(self::META_DONE, $existing_mtw);
                $order->save();
                return;
            }

            $income = self::resolve_specialist_income($order, $book_data);
            $income = (int) apply_filters('mtw_booking_specialist_payout_amount', $income, $order, $book_data);
            $user_id = self::resolve_specialist_user_id($order, $book_data);

            if ($income <= 0) {
                $order->add_order_note('واریز درآمد نوبت به کیف پول متخصص انجام نشد: مبلغ درآمد نامعتبر است.');
                $order->save();
                return;
            }
            if ($user_id <= 0) {
                $order->add_order_note('واریز درآمد نوبت به کیف پول متخصص انجام نشد: کاربر متخصص یافت نشد.');
                $order->save();
                return;
            }

            // Theme already credited Sheyda — record MTW tx only (no second balance credit).
            if (!empty($book_data['specialist_payout_id'])) {
                self::mirror_theme_payout_to_mtw($order, $book_data, $user_id, $income, $code);
                return;
            }

            if (!class_exists('Wallet_Manager')) {
                $order->add_order_note('واریز درآمد نوبت ناموفق: Wallet_Manager در دسترس نیست.');
                $order->save();
                return;
            }

            $result = Wallet_Manager::get_instance()->add_balance(
                $user_id,
                $income,
                self::OP_TYPE,
                [
                    'status'           => 'completed',
                    'transaction_code' => $code,
                    'description'      => sprintf('درآمد نوبت #%s (سفارش #%s)', (int) ($book_data['book_id'] ?? 0), $order->get_id()),
                    'p_info'           => [
                        'type'          => 'booking_payout',
                        'order_id'      => (int) $order->get_id(),
                        'book_id'       => (int) ($book_data['book_id'] ?? 0),
                        'specialist_id' => (int) ($book_data['specialist_id'] ?? 0),
                    ],
                ]
            );

            if (is_wp_error($result)) {
                $order->add_order_note('واریز درآمد نوبت ناموفق: ' . $result->get_error_message());
                $order->save();
                return;
            }

            $tx_id = self::find_tx_id_by_code($code);
            if ($tx_id <= 0) {
                $tx_id = $prior_meta > 0 ? $prior_meta : time();
            }

            $book_data['specialist_payout_id'] = $tx_id;
            $order->update_meta_data('_booking_data', $book_data);
            $order->update_meta_data(self::META_DONE, $tx_id);
            $order->update_meta_data(self::META_PAYOUT_AMOUNT, $income);
            $order->add_order_note(sprintf(
                'درآمد متخصص به کیف پول واریز شد: %s تومان (کاربر #%d، تراکنش #%s).',
                number_format_i18n($income),
                $user_id,
                (string) $tx_id
            ));
            $order->save();
        } finally {
            delete_transient($lock_key);
        }
    }

    /**
     * Theme WalletUtils already wrote Sheyda ledger — create matching MTW tx (no second credit).
     *
     * @param array<string,mixed> $book_data
     */
    private static function mirror_theme_payout_to_mtw(
        WC_Order $order,
        array $book_data,
        int $user_id,
        int $income,
        string $code
    ): void {
        if (!class_exists('Wallet_Transaction_Manager')) {
            $order->add_order_note('ثبت تراکنش درآمد نوبت در کیف پول مدیار ناموفق: Transaction Manager در دسترس نیست.');
            $order->save();
            return;
        }

        // Align MTW meta with Sheyda available balance (theme already credited).
        if (class_exists('MTW_Sheyda_Balance_Bridge')) {
            MTW_Sheyda_Balance_Bridge::pull_to_meta($user_id);
        }

        $tx_id = Wallet_Transaction_Manager::get_instance()->create_transaction([
            'user_id'          => $user_id,
            'amount'           => $income,
            'balance_type'     => 'toman',
            'operation_type'   => self::OP_TYPE,
            'status'           => 'completed',
            'transaction_code' => $code,
            'description'      => sprintf('درآمد نوبت #%s (سفارش #%s)', (int) ($book_data['book_id'] ?? 0), $order->get_id()),
            'p_info'           => [
                'type'                => 'booking_payout',
                'order_id'            => (int) $order->get_id(),
                'book_id'             => (int) ($book_data['book_id'] ?? 0),
                'specialist_id'       => (int) ($book_data['specialist_id'] ?? 0),
                'sheyda_ledger_id'    => (int) $book_data['specialist_payout_id'],
                'mirrored_from_theme' => 1,
            ],
        ]);

        if (is_wp_error($tx_id)) {
            $order->add_order_note('ثبت تراکنش درآمد نوبت در کیف پول مدیار ناموفق: ' . $tx_id->get_error_message());
            $order->save();
            return;
        }

        $order->update_meta_data(self::META_DONE, (int) $tx_id);
        $order->update_meta_data(self::META_PAYOUT_AMOUNT, $income);
        $order->add_order_note(sprintf(
            'تراکنش درآمد نوبت در کیف پول مدیار ثبت شد (آینهٔ واریز شیدا): %s تومان — #%s.',
            number_format_i18n($income),
            (string) $tx_id
        ));
        $order->save();
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function resolve_booking_data(WC_Order $order): ?array
    {
        $book_data = $order->get_meta('_booking_data');
        if (!is_array($book_data)) {
            $book_data = [];
        }

        if (empty($book_data['book_id'])) {
            $from_item = self::book_id_from_order_items($order);
            if ($from_item > 0) {
                $book_data['book_id'] = $from_item;
            }
        }

        $row = self::fetch_booking_row($order, (int) ($book_data['book_id'] ?? 0));
        if ($row) {
            if (empty($book_data['book_id']) && !empty($row['book_id'])) {
                $book_data['book_id'] = (int) $row['book_id'];
            }
            if (empty($book_data['specialist_id']) && !empty($row['specialist_id'])) {
                $book_data['specialist_id'] = (int) $row['specialist_id'];
            }
            if (
                (!isset($book_data['specialist_income']) || (float) $book_data['specialist_income'] <= 0)
                && isset($row['specialist_income'])
                && (float) $row['specialist_income'] > 0
            ) {
                $book_data['specialist_income'] = (float) $row['specialist_income'];
            }
            // Derive income when column is empty but total/commission exist.
            if (
                (!isset($book_data['specialist_income']) || (float) $book_data['specialist_income'] <= 0)
                && isset($row['total_price'])
            ) {
                $total = (float) $row['total_price'];
                $commission = isset($row['commission']) ? (float) $row['commission'] : 0.0;
                if ($commission <= 0 && isset($book_data['commission_value'])) {
                    $commission = (float) $book_data['commission_value'];
                }
                $derived = $total - $commission;
                if ($derived > 0) {
                    $book_data['specialist_income'] = $derived;
                }
            }
        }

        // Last resort from order meta fields commonly set at booking creation.
        if (
            (!isset($book_data['specialist_income']) || (float) $book_data['specialist_income'] <= 0)
            && isset($book_data['commission_value'])
        ) {
            $order_total = (float) $order->get_subtotal();
            if ($order_total <= 0) {
                $order_total = (float) $order->get_total() + self::order_wallet_fee_abs($order);
            }
            $derived = $order_total - (float) $book_data['commission_value'];
            if ($derived > 0) {
                $book_data['specialist_income'] = $derived;
            }
        }

        if (empty($book_data) && !$row) {
            return null;
        }

        // Not a booking order if we still have no specialist / book markers.
        if (empty($book_data['specialist_id']) && empty($book_data['book_id']) && !$row) {
            return null;
        }

        return $book_data;
    }

    private static function book_id_from_order_items(WC_Order $order): int
    {
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item) {
                continue;
            }
            foreach (['__book_id', '_book_id', 'book_id'] as $key) {
                $val = $item->get_meta($key, true);
                if (is_numeric($val) && (int) $val > 0) {
                    return (int) $val;
                }
            }
            $val = $item->get_meta('شناسه رزرو', true);
            if (is_numeric($val) && (int) $val > 0) {
                return (int) $val;
            }
        }
        return 0;
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function fetch_booking_row(WC_Order $order, int $book_id = 0): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'drplus_booking';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found !== $table) {
            return null;
        }

        $row = null;
        if ($book_id > 0) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT book_id, specialist_id, specialist_income, total_price, commission, order_id FROM `{$table}` WHERE book_id = %d LIMIT 1",
                    $book_id
                ),
                ARRAY_A
            );
        }
        if (!is_array($row)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT book_id, specialist_id, specialist_income, total_price, commission, order_id FROM `{$table}` WHERE order_id = %d LIMIT 1",
                    $order->get_id()
                ),
                ARRAY_A
            );
        }

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $book_data
     */
    private static function tx_code(array $book_data, WC_Order $order): string
    {
        $book_id = isset($book_data['book_id']) ? (int) $book_data['book_id'] : (int) $order->get_id();
        return 'BOOKING_' . $book_id . '_' . $order->get_id();
    }

    /**
     * @param array<string,mixed> $book_data
     */
    private static function reversal_tx_code(array $book_data, WC_Order $order): string
    {
        $book_id = isset($book_data['book_id']) ? (int) $book_data['book_id'] : (int) $order->get_id();
        return 'BOOKING_REV_' . $book_id . '_' . $order->get_id();
    }

    /**
     * @param array<string,mixed> $book_data
     */
    private static function resolve_original_payout_amount(WC_Order $order, array $book_data): int
    {
        $stored = (int) $order->get_meta(self::META_PAYOUT_AMOUNT);
        if ($stored > 0) {
            return $stored;
        }

        $code = self::tx_code($book_data, $order);
        $tx_amount = self::find_tx_amount_by_code($code);
        if ($tx_amount > 0) {
            return $tx_amount;
        }

        if (!empty($book_data['specialist_payout_id']) && class_exists('MTW_Sheyda_Balance_Bridge')) {
            global $wpdb;
            $ledger = MTW_Sheyda_Balance_Bridge::table_ledger();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger));
            if ($found === $ledger) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $amount = $wpdb->get_var($wpdb->prepare(
                    "SELECT amount FROM `{$ledger}` WHERE id = %d LIMIT 1",
                    (int) $book_data['specialist_payout_id']
                ));
                if ($amount !== null && (float) $amount > 0) {
                    return (int) max(0, round((float) $amount));
                }
            }
        }

        return 0;
    }

    private static function find_tx_amount_by_code(string $code): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_transactions';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found !== $table) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $amount = $wpdb->get_var($wpdb->prepare(
            "SELECT amount FROM `{$table}` WHERE transaction_code = %s AND operation_type = %s ORDER BY id DESC LIMIT 1",
            $code,
            self::OP_TYPE
        ));
        if ($amount === null) {
            return 0;
        }
        return (int) max(0, round(abs((float) $amount)));
    }

    private static function find_tx_id_by_code(string $code): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wallet_transactions';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found !== $table) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE transaction_code = %s ORDER BY id DESC LIMIT 1",
            $code
        ));
    }

    /**
     * @param array<string,mixed> $book_data
     */
    private static function resolve_specialist_income(WC_Order $order, array $book_data): int
    {
        $income = isset($book_data['specialist_income']) ? (float) $book_data['specialist_income'] : 0.0;

        if ($income <= 0) {
            $row = self::fetch_booking_row($order, (int) ($book_data['book_id'] ?? 0));
            if ($row) {
                if (isset($row['specialist_income']) && (float) $row['specialist_income'] > 0) {
                    $income = (float) $row['specialist_income'];
                } elseif (isset($row['total_price'])) {
                    $commission = isset($row['commission']) ? (float) $row['commission'] : 0.0;
                    if ($commission <= 0 && isset($book_data['commission_value'])) {
                        $commission = (float) $book_data['commission_value'];
                    }
                    $income = (float) $row['total_price'] - $commission;
                }
            }
        }

        if ($income <= 0 && isset($book_data['commission_value'])) {
            $base = (float) $order->get_subtotal();
            if ($base <= 0) {
                $base = (float) $order->get_total() + self::order_wallet_fee_abs($order);
            }
            $income = $base - (float) $book_data['commission_value'];
        }

        return (int) max(0, round($income));
    }

    private static function order_wallet_fee_abs(WC_Order $order): float
    {
        $sum = 0.0;
        foreach ($order->get_items('fee') as $item) {
            $name = $item->get_name();
            $total = (float) $item->get_total();
            if ($total >= 0) {
                continue;
            }
            if (
                $name === 'پرداخت از کیف پول'
                || $name === 'پرداخت با کیف پول'
                || (defined('WALLET_PARTIAL_FEE_LABEL') && $name === WALLET_PARTIAL_FEE_LABEL)
            ) {
                $sum += abs($total);
            }
        }
        return $sum;
    }

    /**
     * @param array<string,mixed> $book_data
     */
    private static function resolve_specialist_user_id(WC_Order $order, array $book_data): int
    {
        if (!empty($book_data['specialist_user_id'])) {
            return (int) $book_data['specialist_user_id'];
        }

        $specialist_id = isset($book_data['specialist_id']) ? (int) $book_data['specialist_id'] : 0;
        if ($specialist_id <= 0) {
            $row = self::fetch_booking_row($order, (int) ($book_data['book_id'] ?? 0));
            if ($row && !empty($row['specialist_id'])) {
                $specialist_id = (int) $row['specialist_id'];
            }
        }
        if ($specialist_id <= 0) {
            return 0;
        }

        // Prefer theme helper used in cancel payouts.
        if (class_exists(\DrPlus\Model\Specialists::class)) {
            try {
                $spec = (new \DrPlus\Model\Specialists())->find($specialist_id);
                if ($spec) {
                    if (!empty($spec->user_id)) {
                        return (int) $spec->user_id;
                    }
                    if (isset($spec->user) && is_object($spec->user) && !empty($spec->user->ID)) {
                        return (int) $spec->user->ID;
                    }
                }
            } catch (Throwable $e) {
                // Fall through to SQL.
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'drplus_specialists';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found === $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $uid = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM `{$table}` WHERE id = %d LIMIT 1",
                $specialist_id
            ));
            return (int) $uid;
        }

        return 0;
    }
}
