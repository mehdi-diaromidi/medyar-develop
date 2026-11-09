<?php

/**
 * Transactions List Page — AJAX filters + CSV
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-tx-modal-data.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';
require_once WALLET_PLUGIN_PATH . 'admin/class-mtw-admin-ui.php';

$args = wwp_admin_list_parse_args($_GET);
$args['per_page'] = 50;
$result = wwp_admin_query_transactions($args, 'all');
$transactions = $result['rows'];
$total_transactions = $result['total'];
$total_pages = max(1, (int) ceil($total_transactions / $args['per_page']));
$page = (int) $args['paged'];

$silver_price = 0;
$tx_js_data     = wwp_build_transaction_modal_js_data($transactions);
$wwp_modal_redirect_page    = 'medyar-wallet-transactions';
$wwp_modal_redirect_user_id = 0;
?>
<div class="wrap wallet-admin-wrap">
    <h1 class="wp-heading-inline">تراکنش‌ها</h1>
    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-recent-transactions')); ?>" class="page-title-action">تراکنش‌های اخیر</a>
    <hr class="wp-header-end" />
    <?php MTW_Admin_Ui::notices_from_query(); ?>
    <p class="mtw-lead">لیست کامل تراکنش‌های کیف پول.</p>

    <?php
    wwp_admin_filter_bar_open('transactions', 'medyar-wallet-transactions', [
        'user_q', 'status', 'operation_type', 'date_from', 'date_to', 'amount_min', 'amount_max', 'tx_q',
    ], [
        'title' => 'فیلتر معاملات',
        'export' => true,
        'per_page' => 50,
    ]);
    ?>

    <div class="wallet-card">
        <h2>معاملات <span data-wwp-list-meta-title>(<?php echo number_format_i18n($total_transactions); ?>)</span></h2>
        <div class="wwp-table-scroll">
            <table class="wallet-table wp-list-table widefat striped">
                <thead>
                    <tr>
                        <?php
                        wwp_admin_sortable_th('شناسه', 'id', 'شناسه یکتای تراکنش');
                        wwp_th_smart('کاربر', 'نام کاربر صاحب تراکنش');
                        wwp_th_smart('عملیات', 'نوع عملیات انجام‌شده');
                        wwp_admin_sortable_th('میزان', 'amount', 'مقدار تغییر موجودی');
                        wwp_admin_sortable_th('وضعیت', 'status', 'وضعیت فعلی تراکنش');
                        wwp_th_smart('درگاه', 'روش یا درگاه پرداخت');
                        wwp_th_smart('کد مرجع', 'کد رهگیری یکتا');
                        wwp_th_smart('توضیحات', 'یادداشت‌های تراکنش', true);
                        wwp_admin_sortable_th('تاریخ', 'created_at', 'تاریخ و ساعت ثبت');
                        wwp_th_smart('عملیات ادمین', 'مشاهده جزئیات تراکنش');
                        ?>
                    </tr>
                </thead>
                <tbody data-wwp-list-body>
                    <?php
                    echo wwp_admin_render_tx_rows_html($transactions, [
                        'show_view' => true,
                        'redirect_page' => 'medyar-wallet-transactions',
                    ]);
                    ?>
                </tbody>
            </table>
        </div>
        <div data-wwp-list-pager>
            <?php echo wwp_admin_render_pagination_html($total_transactions, $page, (int) $args['per_page'], 'transactions'); ?>
        </div>
    </div>
</div>

<?php require WALLET_PLUGIN_PATH . 'admin/partials/wwp-transaction-modal.php'; ?>
