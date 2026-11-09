<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix     = MTW_MyAccount_UI::PREFIX;
$user_id    = get_current_user_id();
$balance    = (int) Wallet_Manager::get_instance()->get_balance($user_id);
$tx_manager = Wallet_Transaction_Manager::get_instance();
$transactions = $tx_manager->get_user_transactions($user_id, [
    'balance_type' => 'toman',
    'limit'        => 5,
    'offset'       => 0,
]);
$op_labels = MTW_MyAccount_UI::operation_labels();
$status_labels = [
    'pending'    => 'در انتظار',
    'processing' => 'در حال پردازش',
    'completed'  => 'موفق',
    'canceled'   => 'لغو شده',
    'failed'     => 'ناموفق',
];
?>
<div class="<?php echo esc_attr($prefix); ?>dashboard <?php echo esc_attr($prefix); ?>section mtw-dash">
    <div class="<?php echo esc_attr($prefix); ?>dashboard-info mtw-dash__hero-row">
        <div class="<?php echo esc_attr($prefix); ?>dashboard-info-inner mtw-dash__balance">
            <div class="mtw-dash__balance-copy">
                <span class="<?php echo esc_attr($prefix); ?>dashboard-balance-label">موجودی کیف پول شما</span>
                <span class="<?php echo esc_attr($prefix); ?>dashboard-balance-value"><?php echo esc_html(MTW_MyAccount_UI::format_price($balance)); ?></span>
            </div>
            <?php MTW_Icons::echo('wallet-addmoney', 'mtw-dash__balance-icon-wrap'); ?>
        </div>
        <div class="<?php echo esc_attr($prefix); ?>dashboard-quick-access-wrap mtw-dash__actions">
            <a href="<?php echo esc_url(MTW_MyAccount_UI::section_url('topup')); ?>" class="<?php echo esc_attr($prefix); ?>dashboard-quick-access-btn mtw-dash__action mtw-dash__action--topup">
                <?php MTW_Icons::echo('wallet-add'); ?>
                <span class="mtw-dash__action-label">
                    <strong>افزایش موجودی</strong>
                    <em>شارژ سریع کیف پول</em>
                </span>
            </a>
            <a href="<?php echo esc_url(MTW_MyAccount_UI::section_url('withdrawal')); ?>" class="<?php echo esc_attr($prefix); ?>dashboard-quick-access-btn mtw-dash__action mtw-dash__action--withdraw">
                <?php MTW_Icons::echo('wallet-minus'); ?>
                <span class="mtw-dash__action-label">
                    <strong>درخواست برداشت</strong>
                    <em>انتقال به کارت بانکی</em>
                </span>
            </a>
        </div>
    </div>

    <div class="<?php echo esc_attr($prefix); ?>dashboard-transactions-list mtw-dash__recent">
        <div class="<?php echo esc_attr($prefix); ?>dashboard-transactions-head">
            <span class="<?php echo esc_attr($prefix); ?>dashboard-transactions-title">آخرین تراکنش‌ها</span>
            <a href="<?php echo esc_url(MTW_MyAccount_UI::section_url('transactions')); ?>" class="<?php echo esc_attr($prefix); ?>dashboard-all-transactions-link">
                <span>مشاهده همه</span>
            </a>
        </div>

        <?php if (!empty($transactions)) : ?>
            <div class="<?php echo esc_attr($prefix); ?>transactions-items mtw-tx__list mtw-dash__tx-list" role="list">
                <?php foreach ($transactions as $transaction) :
                    $details_id_prefix = 'mtw-dash-tx-details';
                    require MTW_PATH . 'templates/myaccount/partials/tx-item.php';
                endforeach; ?>
            </div>
        <?php else : ?>
            <div class="<?php echo esc_attr($prefix); ?>empty-section mtw-dash__empty">
                <span class="<?php echo esc_attr($prefix); ?>empty-section-text">هنوز تراکنشی ثبت نشده است.</span>
            </div>
        <?php endif; ?>
    </div>
</div>
