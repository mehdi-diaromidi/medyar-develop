<?php
if (!defined('ABSPATH')) { exit; }

if (!function_exists('medyar_user_can_request_withdrawal')) {
    function medyar_user_can_request_withdrawal($user_id) {
        return (bool) apply_filters('medyar_user_can_request_withdrawal', true, (int) $user_id);
    }
}
if (!function_exists('wwp_display_user_info')) {
    function wwp_display_user_info($user_id) {
        $u = get_userdata((int) $user_id);
        return $u ? esc_html($u->display_name . ' (#' . (int) $user_id . ')') : '#' . (int) $user_id;
    }
}
if (!function_exists('wwp_format_price')) {
    function wwp_format_price($amount) {
        return esc_html(number_format_i18n((float) $amount)) . ' تومان';
    }
}
