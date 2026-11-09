<?php
/**
 * پنل خلاصه موجودی تومان و تراکنش‌های یک کاربر.
 */
if (!defined('ABSPATH')) {
    exit;
}

require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-helpers-tx-modal-data.php';
require_once WALLET_PLUGIN_PATH . 'admin/admin-list-query.php';
require_once WALLET_PLUGIN_PATH . 'admin/class-mtw-admin-ui.php';

global $wpdb;

$panel_uid = isset($_GET['user_id']) ? max(0, intval($_GET['user_id'])) : 0;
$users_url = admin_url('users.php');

if ($panel_uid <= 0) {
    wp_safe_redirect($users_url);
    exit;
}

$panel_user = get_user_by('id', $panel_uid);
if (!$panel_user) {
    echo '<div class="wrap wallet-admin-wrap"><div class="notice notice-error inline"><p>کاربر یافت نشد.</p></div>';
    echo '<p><a class="button" href="' . esc_url($users_url) . '">بازگشت به کاربران</a></p></div>';
    return;
}

$wallet_mgr = Wallet_Manager::get_instance();
$toman_bal  = $wallet_mgr->get_balance($panel_uid);

$tx_per_page = 50;
$tx_page     = max(1, intval($_GET['tx_paged'] ?? 1));
$tx_offset   = ($tx_page - 1) * $tx_per_page;
$tx_where    = $wpdb->prepare('user_id = %d', $panel_uid);
$transactions = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}wallet_transactions
     WHERE {$tx_where}
     ORDER BY created_at DESC
     LIMIT {$tx_per_page} OFFSET {$tx_offset}"
);
$total_tx = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}wallet_transactions WHERE {$tx_where}"
);

$tx_js_data                 = wwp_build_transaction_modal_js_data($transactions);
$wwp_modal_redirect_page    = 'medyar-wallet-user-insights';
$wwp_modal_redirect_user_id = $panel_uid;

$cards = function_exists('medyar_get_user_bank_cards') ? medyar_get_user_bank_cards($panel_uid) : [];
?>
<div class="wrap wallet-admin-wrap">
    <h1 class="wp-heading-inline">پنل کیف پول — <?php echo esc_html($panel_user->display_name); ?></h1>
    <a class="page-title-action" href="<?php echo esc_url($users_url); ?>">بازگشت به کاربران</a>
    <a class="page-title-action" href="<?php echo esc_url(MTW_Admin_Ui::url('medyar-wallet-deposit', ['user_q' => (string) $panel_uid])); ?>">واریز به این کاربر</a>
    <hr class="wp-header-end" />
    <?php MTW_Admin_Ui::notices_from_query(); ?>

    <div class="mtw-kpis">
        <?php
        MTW_Admin_Ui::render_kpi([
            'icon'  => 'dashicons-money-alt',
            'label' => 'موجودی تومان',
            'value' => number_format_i18n((float) $toman_bal),
            'unit'  => 'تومان',
            'meta'  => 'کاربر #' . (int) $panel_uid,
            'tone'  => 'teal',
        ]);
        MTW_Admin_Ui::render_kpi([
            'icon'  => 'dashicons-id',
            'label' => 'کارت‌های بانکی',
            'value' => (string) count($cards),
            'meta'  => count($cards) ? 'کارت ثبت‌شده' : 'کارتی ثبت نشده',
            'tone'  => count($cards) ? 'blue' : 'orange',
        ]);
        MTW_Admin_Ui::render_kpi([
            'icon'  => 'dashicons-list-view',
            'label' => 'تراکنش‌ها',
            'value' => number_format_i18n($total_tx),
            'meta'  => 'کل رکوردهای این کاربر',
            'tone'  => 'purple',
        ]);
        ?>
    </div>

    <details class="wwp-user-panel-details" open id="wwp-user-panel-txs">
        <summary>
            <span>تراکنش‌ها — <?php echo number_format_i18n($total_tx); ?> مورد</span>
        </summary>
        <div class="wwp-details-body">
            <div class="wwp-details-inner wwp-admin-page">
                <?php
                wwp_admin_filter_bar_open('panel_txs', 'medyar-wallet-user-insights', [
                    'status', 'operation_type', 'date_from', 'date_to',
                ], [
                    'title' => 'فیلتر تراکنش‌های این کاربر',
                    'per_page' => 50,
                    'storage_suffix' => 'panel-tx-' . $panel_uid,
                    'extra_fields_html' => '<input type="hidden" name="user_id" value="' . (int) $panel_uid . '">',
                ]);
                ?>
                <div class="wwp-table-scroll">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <?php
                                wwp_th_smart('شناسه', 'شناسه یکتای تراکنش');
                                wwp_th_smart('عملیات', 'نوع عملیات انجام‌شده');
                                wwp_th_smart('میزان', 'مقدار تغییر موجودی');
                                wwp_th_smart('وضعیت', 'وضعیت فعلی تراکنش');
                                wwp_th_smart('درگاه', 'روش یا درگاه پرداخت');
                                wwp_th_smart('کد مرجع', 'کد رهگیری یکتا');
                                wwp_th_smart('توضیحات', 'یادداشت‌های تراکنش', true);
                                wwp_th_smart('تاریخ', 'تاریخ و ساعت ثبت');
                                wwp_th_smart('عملیات ادمین', 'مشاهده جزئیات تراکنش');
                                ?>
                            </tr>
                        </thead>
                        <tbody data-wwp-list-body>
                            <?php
                            echo wwp_admin_render_tx_rows_html($transactions, [
                                'show_view' => true,
                                'hide_user' => true,
                                'redirect_page' => 'medyar-wallet-user-insights',
                            ]);
                            ?>
                        </tbody>
                    </table>
                </div>
                <div data-wwp-list-pager>
                    <?php echo wwp_admin_render_pagination_html($total_tx, $tx_page, $tx_per_page, 'panel_txs'); ?>
                </div>
            </div>
        </div>
    </details>
</div>
<?php
require WALLET_PLUGIN_PATH . 'admin/partials/wwp-transaction-modal.php';
