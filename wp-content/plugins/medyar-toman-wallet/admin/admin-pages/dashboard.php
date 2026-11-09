<?php
/**
 * Admin Dashboard — Toman wallet (ARP-style shell)
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-tx-modal-data.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';
require_once WALLET_PLUGIN_PATH . 'admin/class-mtw-admin-ui.php';

global $wpdb;
$table = $wpdb->prefix . 'wallet_transactions';

$users_with_balance = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
     WHERE meta_key='wallet_toman_balance' AND CAST(meta_value AS DECIMAL(20,3)) > 0"
);
$total_toman = (float) $wpdb->get_var(
    "SELECT SUM(CAST(meta_value AS DECIMAL(20,3))) FROM {$wpdb->usermeta} WHERE meta_key='wallet_toman_balance'"
);

$pending_withdrawals = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$table}
     WHERE balance_type='toman' AND operation_type='withdraw'
       AND status IN ('pending','processing')"
);
$pending_withdraw_sum = (float) $wpdb->get_var(
    "SELECT COALESCE(SUM(amount),0) FROM {$table}
     WHERE balance_type='toman' AND operation_type='withdraw'
       AND status IN ('pending','processing')"
);

$today_start = current_time('Y-m-d 00:00:00');
$today_deposits = (float) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM {$table}
     WHERE balance_type='toman' AND status='completed'
       AND operation_type IN ('charge','admin_manual_deposit','deposit')
       AND created_at >= %s",
    $today_start
));
$today_withdrawals = (float) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(amount),0) FROM {$table}
     WHERE balance_type='toman' AND status='completed' AND operation_type='withdraw'
       AND created_at >= %s",
    $today_start
));

$recent_txs = $wpdb->get_results(
    "SELECT * FROM {$table}
     WHERE balance_type = 'toman'
     ORDER BY created_at DESC
     LIMIT 12"
);

$pending_withdraw_rows = $wpdb->get_results(
    "SELECT * FROM {$table}
     WHERE balance_type='toman' AND operation_type='withdraw'
       AND status IN ('pending','processing')
     ORDER BY created_at ASC
     LIMIT 8"
);

$tx_js_data = wwp_build_transaction_modal_js_data(array_merge(
    (array) $recent_txs,
    (array) $pending_withdraw_rows
));
$wwp_modal_redirect_page = 'medyar-wallet';
$wwp_modal_redirect_user_id = 0;

$fa = static function ($n): string {
    return number_format_i18n((float) $n);
};

$kpis = [
    [
        'icon'  => 'dashicons-groups',
        'label' => 'کاربران دارای موجودی',
        'value' => $fa($users_with_balance),
        'meta'  => 'مجموع موجودی: ' . MTW_Admin_Ui::money($total_toman),
        'url'   => admin_url('users.php'),
        'tone'  => 'purple',
    ],
    [
        'icon'  => 'dashicons-money-alt',
        'label' => 'مجموع موجودی تومان',
        'value' => $fa($total_toman),
        'unit'  => 'تومان',
        'meta'  => 'موجودی فعال در کیف پول کاربران',
        'url'   => MTW_Admin_Ui::url('medyar-wallet-recent-transactions'),
        'tone'  => 'teal',
    ],
    [
        'icon'  => 'dashicons-migrate',
        'label' => 'برداشت‌های در صف',
        'value' => $fa($pending_withdrawals),
        'meta'  => $pending_withdrawals
            ? ('مبلغ صف: ' . MTW_Admin_Ui::money($pending_withdraw_sum))
            : 'صف برداشت خالی است',
        'url'   => MTW_Admin_Ui::url('medyar-wallet-withdrawals', ['list_mode' => 'pending']),
        'tone'  => $pending_withdrawals ? 'orange' : 'green',
    ],
    [
        'icon'  => 'dashicons-chart-area',
        'label' => 'امروز',
        'value' => $fa($today_deposits),
        'unit'  => 'تومان واریز',
        'meta'  => 'برداشت تکمیل‌شده امروز: ' . MTW_Admin_Ui::money($today_withdrawals),
        'url'   => MTW_Admin_Ui::url('medyar-wallet-recent-transactions'),
        'tone'  => 'blue',
    ],
];
?>
<div class="wrap wallet-admin-wrap">
    <h1 class="wp-heading-inline">کیف پول مدیار</h1>
    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-deposit')); ?>" class="page-title-action">واریز دستی</a>
    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-withdrawals', ['list_mode' => 'pending'])); ?>" class="page-title-action">صف برداشت</a>
    <hr class="wp-header-end" />
    <?php MTW_Admin_Ui::notices_from_query(); ?>

    <div class="mtw-kpis">
        <?php foreach ($kpis as $kpi) {
            MTW_Admin_Ui::render_kpi($kpi);
        } ?>
    </div>

    <div class="mtw-quick-actions">
        <a class="button button-primary" href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-deposit')); ?>">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> واریز دستی
        </a>
        <a class="button" href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-withdrawals', ['list_mode' => 'pending'])); ?>">
            <span class="dashicons dashicons-yes" aria-hidden="true"></span> بررسی برداشت‌ها
        </a>
        <a class="button" href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-recent-transactions')); ?>">
            <span class="dashicons dashicons-list-view" aria-hidden="true"></span> تراکنش‌های اخیر
        </a>
        <a class="button" href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-settings')); ?>">
            <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> تنظیمات
        </a>
    </div>

    <div class="postbox mtw-box">
        <div class="postbox-header">
            <h2>برداشت‌های در انتظار <span class="mtw-muted">(قدیمی‌ترین‌ها)</span></h2>
            <a class="mtw-box__link" href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-withdrawals', ['list_mode' => 'pending'])); ?>">مشاهده همه</a>
        </div>
        <div class="inside">
            <?php if (empty($pending_withdraw_rows)) : ?>
                <p class="mtw-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> برداشت معلقی نیست.</p>
            <?php else : ?>
                <table class="widefat striped mtw-compact">
                    <thead>
                        <tr>
                            <th>کاربر</th>
                            <th>مبلغ</th>
                            <th>وضعیت</th>
                            <th>تاریخ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_withdraw_rows as $tx) :
                            $u = get_userdata((int) $tx->user_id);
                            $name = $u ? ($u->display_name ?: $u->user_login) : ('#' . (int) $tx->user_id);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($name); ?></strong>
                                    <span class="mtw-muted">#<?php echo (int) $tx->user_id; ?></span>
                                </td>
                                <td><?php echo esc_html(MTW_Admin_Ui::money($tx->amount)); ?></td>
                                <td><?php echo MTW_Admin_Ui::status_badge((string) $tx->status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                <td class="mtw-muted"><?php echo esc_html(wwp_format_datetime_jalali((string) $tx->created_at)); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-withdrawals', ['list_mode' => 'pending', 'tx_q' => (string) $tx->id])); ?>">بررسی</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="postbox mtw-box" style="margin-top:16px;">
        <div class="postbox-header">
            <h2>تراکنش‌های اخیر</h2>
            <a class="mtw-box__link" href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-recent-transactions')); ?>">مشاهده همه</a>
        </div>
        <div class="inside">
            <div class="wwp-table-scroll">
                <table class="widefat striped mtw-compact">
                    <thead>
                        <tr>
                            <th>شناسه</th>
                            <th>کاربر</th>
                            <th>عملیات</th>
                            <th>میزان</th>
                            <th>وضعیت</th>
                            <th>درگاه</th>
                            <th>کد مرجع</th>
                            <th>توضیحات</th>
                            <th>تاریخ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($recent_txs)) {
                            echo '<tr><td colspan="7"><p class="mtw-empty">تراکنشی ثبت نشده.</p></td></tr>';
                        } else {
                            echo wwp_admin_render_tx_rows_html($recent_txs, [
                                'show_view' => true,
                                'redirect_page' => 'medyar-wallet',
                            ]);
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
require WALLET_PLUGIN_PATH . 'admin/partials/wwp-transaction-modal.php';
