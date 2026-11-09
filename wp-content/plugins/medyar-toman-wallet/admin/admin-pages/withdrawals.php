<?php

/**
 * Withdraw Requests Page — pending/history tabs, AJAX, bulk, CSV
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';
require_once WALLET_PLUGIN_PATH . 'admin/class-mtw-admin-ui.php';

$args = wwp_admin_list_parse_args($_GET);
if (empty($args['list_mode']) || $args['list_mode'] === 'all') {
    $args['list_mode'] = 'pending';
}
$args['per_page'] = 50;
$result = wwp_admin_query_transactions($args, 'withdraw');
$rows = $result['rows'];
$total = $result['total'];
$page = (int) $args['paged'];
$pending_mode = ($args['list_mode'] === 'pending');
?>
<div class="wrap wallet-admin-wrap">
    <h1 class="wp-heading-inline">درخواست‌های برداشت</h1>
    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-deposit')); ?>" class="page-title-action">واریز دستی</a>
    <hr class="wp-header-end" />
    <?php MTW_Admin_Ui::notices_from_query(); ?>

    <?php
    require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-tx-modal-data.php';
    $silver_price = 0;
    $tx_js_data = wwp_build_transaction_modal_js_data($rows);
    $wwp_modal_redirect_page = 'medyar-wallet-withdrawals';
    $wwp_modal_redirect_user_id = 0;

    $toolbar = '<button type="button" class="button button-primary" data-wwp-bulk="withdraw-approve">تایید انتخاب‌شده‌ها</button>'
        . '<button type="button" class="button wwp-btn-danger" data-wwp-bulk="withdraw-cancel">لغو انتخاب‌شده‌ها</button>';
    wwp_admin_filter_bar_open('withdrawals', 'medyar-wallet-withdrawals', [
        'list_mode', 'user_q', 'status', 'date_from', 'date_to', 'amount_min', 'amount_max', 'card_iban', 'tx_q',
    ], [
        'title' => 'فیلتر برداشت‌ها',
        'export' => true,
        'per_page' => 50,
        'storage_suffix' => 'withdraw',
        'toolbar_html' => '<span class="wwp-bulk-toolbar">' . $toolbar . '</span>',
    ]);
    ?>

    <div class="wallet-card">
        <h2><?php echo $pending_mode ? 'برداشت‌های در انتظار' : 'تاریخچه برداشت‌ها'; ?> (<?php echo number_format_i18n($total); ?>)</h2>
        <?php if ($pending_mode) : ?>
        <p class="wwp-bulk-toolbar">
            <label><input type="checkbox" class="wwp-check-all"> انتخاب همه</label>
        </p>
        <?php endif; ?>
        <div class="wwp-table-scroll">
            <table class="wallet-table wp-list-table widefat striped">
                <thead>
                    <tr>
                        <?php if ($pending_mode) : ?><th></th><?php endif; ?>
                        <?php
                        wwp_admin_sortable_th('شناسه', 'id');
                        echo '<th>کاربر</th>';
                        wwp_admin_sortable_th('میزان', 'amount');
                        echo '<th>شماره کارت</th><th>شماره شبا</th>';
                        wwp_admin_sortable_th('وضعیت', 'status');
                        echo '<th>کد مرجع</th>';
                        echo '<th class="col-description">توضیحات</th>';
                        wwp_admin_sortable_th('تاریخ', 'created_at');
                        echo '<th>عملیات</th>';
                        ?>
                    </tr>
                </thead>
                <tbody data-wwp-list-body>
                    <?php
                    echo wwp_admin_render_tx_rows_html($rows, [
                        'show_checkbox' => $pending_mode,
                        'show_view' => true,
                        'show_approve_cancel' => $pending_mode,
                        'withdraw_mode' => true,
                        'redirect_page' => 'medyar-wallet-withdrawals',
                    ]);
                    ?>
                </tbody>
            </table>
        </div>
        <div data-wwp-list-pager>
            <?php echo wwp_admin_render_pagination_html($total, $page, (int) $args['per_page'], 'withdrawals'); ?>
        </div>
    </div>
</div>
<?php require WALLET_PLUGIN_PATH . 'admin/partials/wwp-transaction-modal.php'; ?>
