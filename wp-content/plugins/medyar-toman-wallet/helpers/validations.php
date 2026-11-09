<?php

/**
 * Validation helper functions
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validate amount is positive and not zero
 */
function wallet_validate_amount($amount)
{
    if (!is_numeric($amount)) {
        return new WP_Error('invalid_amount', __('مقدار وارد‌شده باید عدد باشد.', 'medyar-toman-wallet'));
    }

    $amount = floatval($amount);

    if ($amount <= 0) {
        return new WP_Error('invalid_amount', __('مقدار باید بزرگ‌تر از صفر باشد.', 'medyar-toman-wallet'));
    }

    if ($amount > 999999999999.99) {
        return new WP_Error('invalid_amount', __('مقدار وارد‌شده بیش از حد مجاز است.', 'medyar-toman-wallet'));
    }

    return true;
}

/**
 * Validate user ID exists
 */
function wallet_validate_user_id($user_id)
{
    $user_id = intval($user_id);

    if ($user_id <= 0) {
        return new WP_Error('invalid_user', __('شناسه کاربر نامعتبر است.', 'medyar-toman-wallet'));
    }

    $user = get_user_by('id', $user_id);

    if (!$user) {
        return new WP_Error('user_not_found', __('کاربر یافت نشد.', 'medyar-toman-wallet'));
    }

    return true;
}

/**
 * Read user free_assets balance for an asset type.
 *
 * @param int    $user_id
 * @param string $balance_type toman|silver|bullion|…
 * @return float
 */
function wallet_get_user_free_balance($user_id, $balance_type)
{
    $user_id = absint($user_id);
    if ($user_id <= 0) {
        return 0.0;
    }

    $balance_type = sanitize_key($balance_type);
    if ($balance_type === '') {
        return 0.0;
    }

    if ($balance_type === 'toman'
        && class_exists('MTW_Sheyda_Balance_Bridge')
        && MTW_Sheyda_Balance_Bridge::tables_ready()
    ) {
        $available = MTW_Sheyda_Balance_Bridge::get_available($user_id);
        if ($available !== null) {
            return (float) $available;
        }
    }

    $balance_key = 'wallet_' . $balance_type . '_balance';
    return (float) get_user_meta($user_id, $balance_key, true);
}

/**
 * مقدار دارایی با واحد فارسی برای پیام‌های کاربر (تومان بدون اعشار، دارایی اعشاری با ۳ رقم).
 *
 * @param float  $amount
 * @param string $balance_type
 * @return string
 */
function wallet_format_balance_for_message($amount, $balance_type)
{
    $balance_type = sanitize_key((string) $balance_type);
    $is_decimal   = ($balance_type !== 'toman')
        && (function_exists('wwp_asset_is_decimal') ? wwp_asset_is_decimal($balance_type) : true);
    $number = number_format((float) $amount, $is_decimal ? 3 : 0);
    $unit   = function_exists('wwp_asset_unit_label')
        ? wwp_asset_unit_label($balance_type)
        : ($balance_type === 'toman' ? 'تومان' : '');

    return trim($number . ' ' . $unit);
}

/**
 * خطای «موجودی کافی نیست» با نام فارسی دارایی.
 *
 * @param string $balance_type
 * @param float  $required
 * @param float  $available
 * @return WP_Error
 */
function wallet_insufficient_balance_error($balance_type, $required, $available)
{
    $label = function_exists('wwp_label_balance_type')
        ? wwp_label_balance_type((string) $balance_type)
        : (string) $balance_type;

    return new WP_Error(
        'insufficient_balance',
        sprintf(
            /* translators: 1: asset label, 2: required amount, 3: available amount */
            __('موجودی %1$s شما کافی نیست. مقدار مورد نیاز: %2$s — موجودی فعلی: %3$s', 'medyar-toman-wallet'),
            $label,
            wallet_format_balance_for_message($required, $balance_type),
            wallet_format_balance_for_message($available, $balance_type)
        )
    );
}

/**
 * Validate sufficient free_assets balance.
 */
function wallet_validate_sufficient_free_balance($user_id, $amount, $balance_type)
{
    $current_balance = wallet_get_user_free_balance($user_id, $balance_type);

    if ($current_balance < (float) $amount) {
        return wallet_insufficient_balance_error($balance_type, (float) $amount, $current_balance);
    }

    return true;
}

/**
 * Validate sufficient balance
 */
function wallet_validate_sufficient_balance($user_id, $amount, $balance_type)
{
    $balance_type = sanitize_key((string) $balance_type);
    if ($balance_type === 'toman'
        && class_exists('MTW_Sheyda_Balance_Bridge')
        && MTW_Sheyda_Balance_Bridge::tables_ready()
    ) {
        $current_balance = (float) MTW_Sheyda_Balance_Bridge::get_available((int) $user_id);
    } else {
        $balance_key = 'wallet_' . $balance_type . '_balance';
        $current_balance = floatval(get_user_meta($user_id, $balance_key, true));
    }

    if ($current_balance < $amount) {
        return wallet_insufficient_balance_error($balance_type, (float) $amount, $current_balance);
    }

    return true;
}

/**
 * Validate balance type
 */
function wallet_validate_balance_type($balance_type)
{
    $allowed_types = array('toman');
    if (function_exists('wwp_static_asset_keys')) {
        $allowed_types = array_merge($allowed_types, wwp_static_asset_keys(true));
    }
    if (class_exists('Asset_Settings_Manager')) {
        $allowed_types = array_merge($allowed_types, Asset_Settings_Manager::get_instance()->get_asset_keys(true));
    }
    if (defined('medyar_STATIC_ASSETS') && is_array(medyar_STATIC_ASSETS)) {
        foreach (medyar_STATIC_ASSETS as $key => $row) {
            if (is_array($row) && isset($row['is_active']) && !(int) $row['is_active']) {
                continue;
            }
            $allowed_types[] = sanitize_key(is_string($key) ? $key : '');
        }
    }
    if (count($allowed_types) === 1) {
        $allowed_types = array_merge($allowed_types, array('silver', 'bullion'));
    }
    $allowed_types = array_values(array_unique(array_filter($allowed_types)));

    if (!in_array($balance_type, $allowed_types, true)) {
        return new WP_Error('invalid_balance_type', __('نوع دارایی نامعتبر است.', 'medyar-toman-wallet'));
    }

    return true;
}

/**
 * Validate operation type
 */
function wallet_validate_operation_type($operation_type)
{
    $allowed_operations = function_exists('wallet_allowed_operation_types')
        ? wallet_allowed_operation_types()
        : array(
            'charge',
            'spend',
            'buy_silver',
            'sell_silver',
            'buy_bullion',
            'sell_bullion',
            'withdraw',
            'admin_add',
            'admin_reduce',
            'returned',
            'refund_order',
            'booking_payout',
            'booking_payout_reversal',
        );

    if (!in_array($operation_type, $allowed_operations, true)) {
        return new WP_Error('invalid_operation', __('نوع عملیات نامعتبر است.', 'medyar-toman-wallet'));
    }

    return true;
}

/**
 * Check if errors exist in validation results
 */
function wallet_check_validations($validations)
{
    foreach ($validations as $validation) {
        if (is_wp_error($validation)) {
            return $validation;
        }
    }

    return true;
}