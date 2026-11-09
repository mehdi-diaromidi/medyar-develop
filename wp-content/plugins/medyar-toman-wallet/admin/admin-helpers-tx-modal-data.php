<?php

/**
 * ساخت آرایه wwpTxData برای مودال تراکنش (مشترک بین صفحات ادمین)
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wwp_format_modal_wallet_tx_row')) {
    /**
     * یک ردیف تراکنش کیف پول برای مودال.
     *
     * @param object $tx
     * @return array<string, mixed>
     */
    function wwp_format_modal_wallet_tx_row(object $tx, string $role_note = ''): array
    {
        return [
            'id'               => (int) $tx->id,
            'balance_type'     => (string) $tx->balance_type,
            'balance_label'    => wwp_label_balance_type((string) $tx->balance_type),
            'operation_type'   => (string) $tx->operation_type,
            'operation_label'  => function_exists('wwp_label_operation_for_tx')
                ? wwp_label_operation_for_tx($tx)
                : wwp_label_operation_type((string) $tx->operation_type),
            'amount'           => (float) $tx->amount,
            'status'           => (string) $tx->status,
            'status_label'     => wwp_label_status((string) $tx->status),
            'transaction_code' => (string) ( $tx->transaction_code ?? '' ),
            'created_at'       => wwp_format_datetime_jalali((string) $tx->created_at),
            'description'      => wwp_plain_transaction_description( (string) ( $tx->description ?? '' ) ),
            'role_note'        => $role_note,
        ];
    }
}

if (!function_exists('wwp_build_transaction_modal_js_data')) {
    /**
     * @param array<int, object> $transactions ردیف‌های wallet_transactions
     * @return array<int, array<string, mixed>>
     */
    function wwp_build_transaction_modal_js_data(array $transactions): array
    {
        $tm     = Wallet_Transaction_Manager::get_instance();
        global $wpdb;

        $tx_js_data = [];
        foreach ($transactions as $tx) {
            $user    = get_user_by('id', $tx->user_id);
            $p_info  = !empty($tx->p_info) ? json_decode($tx->p_info, true) : null;
            $pay_tbl = $wpdb->prefix . 'wallet_payment_transactions';

            $payment_record = null;
            $linked_tx_obj  = null;
            $skip_validity  = wwp_tx_skips_validity($tx);
            $validity       = ['valid' => null, 'reason' => ''];

            if (!$skip_validity && $tx->operation_type === 'buy_silver' && $p_info) {
                if ($p_info['type'] === 'Pay_gateway') {
                    $payment_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay_tbl} WHERE id=%d LIMIT 1", (int) $p_info['id']));
                    $validity = ($payment_record && $payment_record->status === 'completed')
                        ? ['valid' => true, 'reason' => 'پرداخت از طریق درگاه «' . esc_html($payment_record->gateway) . '» با کد مرجع ' . esc_html($payment_record->reference_id) . ' تأیید شده است.']
                        : ['valid' => false, 'reason' => 'تراکنش پرداخت درگاه هنوز تأیید (completed) نشده است.'];
                } elseif ($p_info['type'] === 'Wallet_gateway') {
                    $linked_tx_obj = $tm->get_transaction((int) $p_info['id']);
                    $validity = ($linked_tx_obj && $linked_tx_obj->status === 'completed')
                        ? ['valid' => true, 'reason' => 'پرداخت از طریق کیف پول (شناسه تراکنش: #' . $linked_tx_obj->id . ') با وضعیت «تکمیل شده» تأیید شده است.']
                        : ['valid' => false, 'reason' => 'تراکنش کیف پول مرتبط هنوز تأیید نشده است.'];
                }
            } elseif (!$skip_validity && $tx->operation_type === 'sell_silver' && !empty($tx->linked_tx_id)) {
                $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                $linked_label = $linked_tx_obj ? wwp_label_balance_type((string) $linked_tx_obj->balance_type) : 'موجودی';
                $validity = ($linked_tx_obj && $linked_tx_obj->status === 'completed')
                    ? ['valid' => true, 'reason' => 'کاهش موجودی ' . $linked_label . ' (شناسه تراکنش: #' . $linked_tx_obj->id . ') با وضعیت «تکمیل شده» تأیید شده است.']
                    : ['valid' => false, 'reason' => 'تراکنش کاهش موجودی مرتبط هنوز تأیید نشده است.'];
            } elseif (!$skip_validity && $tx->operation_type === 'charge' && $p_info && $p_info['type'] === 'Pay_gateway') {
                $payment_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pay_tbl} WHERE id=%d LIMIT 1", (int) $p_info['id']));
                $validity = ($payment_record && $payment_record->status === 'completed')
                    ? ['valid' => true, 'reason' => 'پرداخت از طریق درگاه تأیید شده است. این نوع تراکنش نیازی به تأیید دستی ندارد.']
                    : ['valid' => false, 'reason' => 'پرداخت درگاه هنوز تأیید نشده است.'];
            } elseif (!$skip_validity && $tx->operation_type === 'withdraw') {
                $validity = $tx->status === 'processing'
                    ? ['valid' => null, 'reason' => 'این تراکنش نیازمند تأیید دستی ادمین است.']
                    : ['valid' => true, 'reason' => 'تراکنش برداشت تکمیل شده است.'];
            } elseif (!$skip_validity && $tx->operation_type === 'returned') {
                if (!empty($tx->linked_tx_id)) {
                    $linked_tx_obj = $tm->get_transaction((int) $tx->linked_tx_id);
                }
                if ($linked_tx_obj && $linked_tx_obj->status === 'canceled') {
                    $validity = [
                        'valid'  => true,
                        'reason' => 'بازگشت به تراکنش لغو‌شده #' . (int) $linked_tx_obj->id . ' متصل است.',
                    ];
                } else {
                    $validity = ['valid' => null, 'reason' => 'تراکنش مبدأ لغو مرتبط با این بازگشت یافت نشد.'];
                }
            }

            $related_label = 'تراکنش مرتبط';
            if (in_array($tx->operation_type, ['buy_silver', 'charge'], true)) {
                $related_label = 'تراکنش پرداخت';
            } elseif ($tx->operation_type === 'sell_silver') {
                $related_label = 'تراکنش کسر موجودی';
            } elseif ($tx->operation_type === 'withdraw') {
                $related_label = 'تراکنش کسر موجودی';
            } elseif ($tx->operation_type === 'returned') {
                $related_label = 'تراکنش کسر/پرداخت لغو‌شده';
            }

            $returned_tx_obj = null;
            if ($tx->status === 'canceled') {
                $returned_tx_obj = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wallet_transactions 
                     WHERE operation_type = 'returned' AND linked_tx_id = %d LIMIT 1",
                    $tx->id
                ));
                if (!$returned_tx_obj && !empty($tx->linked_tx_id)) {
                    $returned_tx_obj = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}wallet_transactions 
                         WHERE operation_type = 'returned' AND linked_tx_id = %d LIMIT 1",
                        (int) $tx->linked_tx_id
                    ));
                }
            }

            $trade_tx_code = isset($tx->trade_tx_code) ? (string) $tx->trade_tx_code : '';

            $linked_tx_arr   = $linked_tx_obj ? (array) $linked_tx_obj : null;
            $returned_tx_arr = $returned_tx_obj ? (array) $returned_tx_obj : null;
            if ($linked_tx_arr && isset($linked_tx_arr['description'])) {
                $linked_tx_arr['description'] = wwp_plain_transaction_description((string) $linked_tx_arr['description']);
            }
            if ($returned_tx_arr && isset($returned_tx_arr['description'])) {
                $returned_tx_arr['description'] = wwp_plain_transaction_description((string) $returned_tx_arr['description']);
            }

            $tx_js_data[$tx->id] = [
                'id'                => (int) $tx->id,
                'user_id'           => (int) $tx->user_id,
                'user_name'         => $user ? $user->display_name : 'نامشخص',
                'user_email'        => $user ? $user->user_email : '',
                'balance_type'      => $tx->balance_type,
                'balance_label'     => wwp_label_balance_type($tx->balance_type),
                'operation_type'    => $tx->operation_type,
                'operation_label'   => function_exists('wwp_label_operation_for_tx')
                    ? wwp_label_operation_for_tx($tx)
                    : wwp_label_operation_type((string) $tx->operation_type),
                'amount'            => (float) $tx->amount,
                'status'            => $tx->status,
                'status_label'      => wwp_label_status($tx->status),
                'gateway'           => wwp_format_gateway($tx->p_info),
                'transaction_code'  => $tx->transaction_code,
                'trade_tx_code'     => $trade_tx_code,
                'description'       => wwp_plain_transaction_description((string) ($tx->description ?? '')),
                'created_at'        => wwp_format_datetime_jalali((string) $tx->created_at),
                'linked_tx_id'      => $tx->linked_tx_id,
                's_price'           => isset($tx->s_price) ? (float) $tx->s_price : null,
                'p_info'            => $p_info,
                'payment_record'    => $payment_record ? (array) $payment_record : null,
                'linked_tx'         => $linked_tx_arr,
                'returned_tx'       => $returned_tx_arr,
                'related_label'     => $related_label,
                'validity'          => $validity,
                'skip_validity'     => $skip_validity,
                'nonce'             => wp_create_nonce('wwp_modal_update_' . $tx->id),
                'ajax_nonce'        => wp_create_nonce('wwp_pnl_' . $tx->id),
            ];
        }

        return $tx_js_data;
    }
}
