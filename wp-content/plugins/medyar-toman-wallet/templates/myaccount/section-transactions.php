<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix     = MTW_MyAccount_UI::PREFIX;
$user_id    = get_current_user_id();
$page       = isset($_GET['t-page']) ? max(1, absint($_GET['t-page'])) : 1;
$ppp        = 10;
$tx_manager = Wallet_Transaction_Manager::get_instance();
$filters    = ['balance_type' => 'toman'];
$total      = $tx_manager->count_user_transactions($user_id, $filters);
$transactions = $tx_manager->get_user_transactions($user_id, array_merge($filters, [
    'limit'  => $ppp,
    'offset' => ($page - 1) * $ppp,
]));
$op_labels = MTW_MyAccount_UI::operation_labels();
$status_labels = [
    'pending'    => 'در انتظار',
    'processing' => 'در حال پردازش',
    'completed'  => 'موفق',
    'canceled'   => 'لغو شده',
    'failed'     => 'ناموفق',
];
?>
<div class="<?php echo esc_attr($prefix); ?>transactions <?php echo esc_attr($prefix); ?>section mtw-tx">
    <div class="mtw-tx__head">
        <div class="mtw-tx__titles">
            <div class="mtw-tx__title-row">
                <?php MTW_Icons::echo('documentmoney'); ?>
                <h2 class="mtw-tx__title">تراکنش‌های کیف پول</h2>
            </div>
            <p class="mtw-tx__lead">تاریخچه شارژ، برداشت و پرداخت‌های تومانی شما.</p>
        </div>
        <?php if ($total > 0) : ?>
            <span class="mtw-tx__count"><?php echo esc_html(number_format_i18n($total)); ?> مورد</span>
        <?php endif; ?>
    </div>

    <?php if (!empty($transactions)) : ?>
        <div class="<?php echo esc_attr($prefix); ?>transactions-items mtw-tx__list" role="list">
            <?php foreach ($transactions as $transaction) :
                $details_id_prefix = 'mtw-tx-details';
                require MTW_PATH . 'templates/myaccount/partials/tx-item.php';
            endforeach; ?>
        </div>
        <?php
        require_once MTW_PATH . 'templates/myaccount/pagination.php';
        mtw_myaccount_pagination([
            'query_arg_name' => 't-page',
            'max_num_pages'  => (int) ceil($total / $ppp),
            'paged'          => $page,
        ]);
        ?>
    <?php else : ?>
        <div class="<?php echo esc_attr($prefix); ?>empty-section mtw-tx__empty">
            <span class="<?php echo esc_attr($prefix); ?>empty-section-text">هیچ تراکنشی یافت نشد.</span>
        </div>
    <?php endif; ?>
</div>
