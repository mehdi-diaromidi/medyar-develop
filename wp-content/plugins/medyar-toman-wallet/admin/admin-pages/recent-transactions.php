<?php

/**
 * Recent (other) wallet transactions — AJAX filters + CSV
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
$result = wwp_admin_query_transactions($args, 'recent');
$transactions = $result['rows'];
$total_transactions = $result['total'];
$page = (int) $args['paged'];

$silver_price = 0;
$tx_js_data     = wwp_build_transaction_modal_js_data($transactions);
$wwp_modal_redirect_page    = 'medyar-wallet-recent-transactions';
$wwp_modal_redirect_user_id = 0;
?>
<div class="wrap wallet-admin-wrap">
    <h1 class="wp-heading-inline">تراکنش‌های اخیر</h1>
    <a href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-deposit')); ?>" class="page-title-action">واریز دستی</a>
    <hr class="wp-header-end" />
    <?php MTW_Admin_Ui::notices_from_query(); ?>
    <p class="mtw-lead">تراکنش‌های کیف پول تومان (به‌جز برداشت‌های صف‌شده در صفحه برداشت).</p>
    <div class="mtw-summary">
        <div class="mtw-summary__item">
            <span class="mtw-muted">نتیجه فیلتر</span>
            <strong><?php echo esc_html(number_format_i18n($total_transactions)); ?></strong>
        </div>
    </div>

    <?php
    wwp_admin_filter_bar_open('recent_transactions', 'medyar-wallet-recent-transactions', [
        'user_q', 'status', 'date_from', 'date_to', 'amount_min', 'amount_max', 'tx_q',
        'operation_type',
    ], [
        'title' => 'فیلتر تراکنش‌ها',
        'export' => true,
        'per_page' => 50,
    ]);
    ?>

    <div class="wallet-card">
        <h2>تراکنش‌ها (<?php echo number_format_i18n($total_transactions); ?>)</h2>
        <div class="wwp-table-scroll">
            <table class="wallet-table wp-list-table widefat striped">
                <thead>
                    <tr>
                        <?php
                        wwp_admin_sortable_th('شناسه', 'id');
                        wwp_th_smart('کاربر', 'نام کاربر صاحب تراکنش');
                        wwp_th_smart('عملیات', 'نوع عملیات انجام‌شده');
                        wwp_admin_sortable_th('میزان', 'amount');
                        wwp_admin_sortable_th('وضعیت', 'status');
                        wwp_th_smart('درگاه', 'روش یا درگاه پرداخت');
                        wwp_th_smart('کد مرجع', 'کد رهگیری یکتا');
                        wwp_th_smart('توضیحات', 'یادداشت‌های تراکنش', true);
                        wwp_admin_sortable_th('تاریخ', 'created_at');
                        wwp_th_smart('عملیات ادمین', 'مشاهده جزئیات تراکنش');
                        ?>
                    </tr>
                </thead>
                <tbody data-wwp-list-body>
                    <?php
                    echo wwp_admin_render_tx_rows_html($transactions, [
                        'show_view' => true,
                        'redirect_page' => 'medyar-wallet-recent-transactions',
                    ]);
                    ?>
                </tbody>
            </table>
        </div>
        <div data-wwp-list-pager>
            <?php echo wwp_admin_render_pagination_html($total_transactions, $page, (int) $args['per_page'], 'recent_transactions'); ?>
        </div>
    </div>
</div>

<?php
require WALLET_PLUGIN_PATH . 'admin/partials/wwp-transaction-modal.php';
