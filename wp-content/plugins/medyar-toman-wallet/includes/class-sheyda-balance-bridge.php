<?php
/**
 * Bidirectional bridge between MTW (user_meta wallet_toman_balance)
 * and Sheyda Wallet tables (sheyda_wallet_balances + sheyda_wallet_ledger).
 *
 * Legacy theme/booking code still credits Sheyda tables via WalletUtils;
 * MTW must read/write the same store so sidebar balance, specialist payouts,
 * and wallet UI stay consistent.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('MTW_Sheyda_Balance_Bridge')) {
    return;
}

class MTW_Sheyda_Balance_Bridge
{
    public static function init(): void
    {
        add_action('init', [__CLASS__, 'maybe_migrate'], 5);
        add_action('wallet_toman_balance_added', [__CLASS__, 'on_mtw_added'], 20, 4);
        add_action('wallet_toman_balance_reduced', [__CLASS__, 'on_mtw_reduced'], 20, 4);
        // Do NOT filter get_user_meta — atomic balance helpers lock usermeta via SQL.
        add_action('shutdown', [__CLASS__, 'sync_sheyda_writers_to_meta'], 50);
    }

    public static function table_balances(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sheyda_wallet_balances';
    }

    public static function table_ledger(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'sheyda_wallet_ledger';
    }

    public static function tables_ready(): bool
    {
        global $wpdb;
        $table = self::table_balances();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $found === $table;
    }

    /**
     * Available balance = balance - locked (same as theme sidebar).
     */
    public static function get_available(int $user_id): ?int
    {
        if ($user_id <= 0 || !self::tables_ready()) {
            return null;
        }
        global $wpdb;
        $table = self::table_balances();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT balance, locked FROM `{$table}` WHERE user_id = %d LIMIT 1",
            $user_id
        ));
        if (!$row) {
            return 0;
        }
        return max(0, (int) $row->balance - (int) $row->locked);
    }

    /**
     * Gross ledger balance (ignores locks).
     */
    public static function get_gross(int $user_id): ?int
    {
        $row = self::get_raw_row($user_id);
        if ($row === null) {
            return self::tables_ready() ? 0 : null;
        }
        return max(0, (int) $row->balance);
    }

    /**
     * Locked amount for user (Sheyda reserve).
     */
    public static function get_locked(int $user_id): int
    {
        $row = self::get_raw_row($user_id);
        if (!$row) {
            return 0;
        }
        return max(0, (int) $row->locked);
    }

    /**
     * Release Sheyda checkout reserves so MTW can debit on payment_complete.
     * Theme PartialPayment locks on place-order; without unlock, available=0.
     *
     * @return int Amount unlocked.
     */
    public static function unlock_amount(int $user_id, int $amount, string $note = ''): int
    {
        if ($user_id <= 0 || $amount <= 0 || !self::tables_ready()) {
            return 0;
        }
        if (!empty($GLOBALS['mtw_sheyda_bridge_busy'])) {
            return 0;
        }

        $GLOBALS['mtw_sheyda_bridge_busy'] = true;
        global $wpdb;
        $balances = self::table_balances();
        $ledger   = self::table_ledger();
        $unlocked = 0;

        $wpdb->query('START TRANSACTION');
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, balance, locked FROM `{$balances}` WHERE user_id = %d LIMIT 1 FOR UPDATE",
                $user_id
            ));
            if (!$row) {
                $wpdb->query('COMMIT');
                $GLOBALS['mtw_sheyda_bridge_busy'] = false;
                return 0;
            }

            $locked    = max(0, (int) $row->locked);
            $unlocked  = min($locked, $amount);
            $new_locked = $locked - $unlocked;
            $balance   = (int) $row->balance;

            if ($unlocked > 0) {
                $wpdb->update(
                    $balances,
                    [
                        'locked'     => $new_locked,
                        'updated_at' => current_time('mysql'),
                    ],
                    ['user_id' => $user_id],
                    ['%d', '%s'],
                    ['%d']
                );

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $ledger_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger));
                if ($ledger_exists === $ledger) {
                    $wpdb->insert(
                        $ledger,
                        [
                            'user_id'       => $user_id,
                            'type'          => 'unlock',
                            'amount'        => $unlocked,
                            'balance_after' => $balance,
                            'created_by'    => get_current_user_id() ?: $user_id,
                            'related_id'    => 0,
                            'meta'          => wp_json_encode([
                                'source' => 'medyar-toman-wallet',
                                'note'   => $note !== '' ? $note : 'Release Sheyda checkout reserve',
                            ]),
                            'created_at'    => current_time('mysql'),
                        ],
                        ['%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s']
                    );
                }

                update_user_meta($user_id, 'wallet_toman_balance', max(0, $balance - $new_locked));
                if (function_exists('wallet_flush_balance_cache')) {
                    wallet_flush_balance_cache($user_id);
                }
            }

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('[MTW SheydaBridge] unlock_amount failed: ' . $e->getMessage());
            $unlocked = 0;
        }

        $GLOBALS['mtw_sheyda_bridge_busy'] = false;
        return $unlocked;
    }

    public static function get_raw_row(int $user_id): ?object
    {
        if ($user_id <= 0 || !self::tables_ready()) {
            return null;
        }
        global $wpdb;
        $table = self::table_balances();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE user_id = %d LIMIT 1",
            $user_id
        ));
        return $row ?: null;
    }

    /**
     * Copy Sheyda available balance into wallet_toman_balance meta (no ledger write).
     * Call before MTW atomic mutations so usermeta matches legacy credits.
     */
    public static function pull_to_meta(int $user_id): void
    {
        if ($user_id <= 0 || !self::tables_ready() || !empty($GLOBALS['mtw_sheyda_bridge_busy'])) {
            return;
        }
        $available = self::get_available($user_id);
        if ($available === null) {
            return;
        }
        $GLOBALS['mtw_sheyda_bridge_busy'] = true;
        update_user_meta($user_id, 'wallet_toman_balance', $available);
        wallet_flush_balance_cache($user_id);
        $GLOBALS['mtw_sheyda_bridge_busy'] = false;
    }

    public static function on_mtw_added($user_id, $amount, $operation_type = '', $transaction_id = 0): void
    {
        self::apply_delta((int) $user_id, abs((float) $amount), (string) $operation_type, (int) $transaction_id, true);
    }

    public static function on_mtw_reduced($user_id, $amount, $operation_type = '', $transaction_id = 0): void
    {
        self::apply_delta((int) $user_id, -abs((float) $amount), (string) $operation_type, (int) $transaction_id, false);
    }

    private static function map_ledger_type(string $operation_type, bool $is_credit): string
    {
        $map = [
            'charge'       => 'topup',
            'admin_add'    => 'adjust_credit',
            'admin_reduce' => 'adjust_debit',
            'withdraw'     => 'withdraw_request',
            'purchase'     => 'purchase',
            'payment'      => 'purchase',
            'refund_order' => 'refund',
            'refund'       => 'refund',
            'booking_payout'          => 'booking_payout',
            'booking_payout_reversal' => 'adjust_debit',
        ];
        if (isset($map[$operation_type])) {
            return $map[$operation_type];
        }
        return $is_credit ? 'adjust_credit' : 'adjust_debit';
    }

    private static function apply_delta(int $user_id, float $signed_amount, string $operation_type, int $transaction_id, bool $is_credit): void
    {
        if ($user_id <= 0 || $signed_amount == 0.0 || !self::tables_ready()) {
            return;
        }
        if (!empty($GLOBALS['mtw_sheyda_bridge_busy'])) {
            return;
        }
        $GLOBALS['mtw_sheyda_bridge_busy'] = true;

        global $wpdb;
        $balances = self::table_balances();
        $ledger   = self::table_ledger();
        $delta    = (int) round($signed_amount);

        $wpdb->query('START TRANSACTION');
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, balance, locked FROM `{$balances}` WHERE user_id = %d LIMIT 1 FOR UPDATE",
                $user_id
            ));

            $balance = $row ? (int) $row->balance : 0;
            $locked  = $row ? (int) $row->locked : 0;
            $new     = max(0, $balance + $delta);

            if ($row) {
                $wpdb->update(
                    $balances,
                    [
                        'balance'    => $new,
                        'updated_at' => current_time('mysql'),
                    ],
                    ['user_id' => $user_id],
                    ['%d', '%s'],
                    ['%d']
                );
            } else {
                $wpdb->insert(
                    $balances,
                    [
                        'user_id'    => $user_id,
                        'balance'    => $new,
                        'locked'     => 0,
                        'updated_at' => current_time('mysql'),
                    ],
                    ['%d', '%d', '%d', '%s']
                );
            }

            $type = self::map_ledger_type($operation_type, $is_credit);
            $meta = wp_json_encode([
                'source'         => 'medyar-toman-wallet',
                'operation_type' => $operation_type,
                'mtw_tx_id'      => $transaction_id,
            ]);

            // Ledger table may exist with slightly different columns; insert best-effort.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $ledger_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ledger));
            if ($ledger_exists === $ledger) {
                $wpdb->insert(
                    $ledger,
                    [
                        'user_id'       => $user_id,
                        'type'          => $type,
                        'amount'        => abs($delta),
                        'balance_after' => $new,
                        'created_by'    => get_current_user_id(),
                        'related_id'    => $transaction_id,
                        'meta'          => $meta,
                        'created_at'    => current_time('mysql'),
                    ],
                    ['%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s']
                );
            }

            $wpdb->query('COMMIT');

            // Keep meta in sync for any code bypassing our filter.
            update_user_meta($user_id, 'wallet_toman_balance', max(0, $new - $locked));
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('[MTW SheydaBridge] apply_delta failed: ' . $e->getMessage());
        }

        $GLOBALS['mtw_sheyda_bridge_busy'] = false;
    }

    /**
     * One-time: align empty sides between Sheyda table and MTW meta.
     */
    public static function maybe_migrate(): void
    {
        if (get_option('mtw_sheyda_balance_migrated', '') === '1') {
            return;
        }
        if (!self::tables_ready()) {
            return;
        }
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        global $wpdb;
        $meta = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
            $user_id,
            'wallet_toman_balance'
        ));
        $available = self::get_available($user_id);
        if ($available === null) {
            return;
        }

        if ($available > 0 && $meta <= 0) {
            update_user_meta($user_id, 'wallet_toman_balance', $available);
        } elseif ($meta > 0 && $available <= 0) {
            self::apply_delta($user_id, $meta, 'charge', 0, true);
        }

        update_option('mtw_sheyda_balance_migrated', '1', false);
    }

    /**
     * If Sheyda was credited outside MTW (booking_payout etc.), pull up into meta.
     * Never overwrite meta with a higher Sheyda value that undoes MTW withdraw/debit.
     * Only sync when Sheyda available is greater than meta (external credit).
     */
    public static function sync_sheyda_writers_to_meta(): void
    {
        if (!is_user_logged_in() || !self::tables_ready()) {
            return;
        }
        if (!empty($GLOBALS['mtw_sheyda_bridge_busy'])) {
            return;
        }
        $user_id = get_current_user_id();
        $available = self::get_available($user_id);
        if ($available === null) {
            return;
        }
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
            $user_id,
            'wallet_toman_balance'
        ));
        $meta = (int) $raw;
        // External credit only: Sheyda has more than meta.
        if ($available > $meta) {
            $GLOBALS['mtw_sheyda_bridge_busy'] = true;
            update_user_meta($user_id, 'wallet_toman_balance', $available);
            if (function_exists('wallet_flush_balance_cache')) {
                wallet_flush_balance_cache($user_id);
            }
            $GLOBALS['mtw_sheyda_bridge_busy'] = false;
        }
    }
}
