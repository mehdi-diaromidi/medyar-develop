<?php
/**
 * Shared wallet transaction row (dashboard / transactions / similar lists).
 *
 * Expected vars:
 * @var object $transaction
 * @var string $prefix
 * @var array  $op_labels
 * @var array  $status_labels
 * @var string $details_id_prefix  e.g. mtw-dash-tx-details / mtw-tx-details
 */
if (!defined('ABSPATH')) {
    exit;
}

$op      = (string) ($transaction->operation_type ?? '');
$status  = (string) ($transaction->status ?? '');
$ts      = !empty($transaction->created_at) ? strtotime($transaction->created_at) : 0;
$amt     = (float) ($transaction->amount ?? 0);
$tx_id   = (int) ($transaction->id ?? 0);
$desc    = MTW_MyAccount_UI::format_tx_description((string) ($transaction->description ?? ''), 0, $transaction);
$status_class = sanitize_html_class($status !== '' ? $status : 'unknown');

$is_purchase = in_array($op, ['purchase', 'payment', 'spend'], true);
$is_flow     = in_array($op, ['charge', 'admin_add', 'withdraw', 'refund', 'refund_order'], true);
$is_out      = $amt < 0 || in_array($op, ['withdraw', 'purchase', 'payment', 'spend'], true);

$item_classes = [$prefix . 'transaction', 'mtw-tx__item'];
if ($is_purchase) {
    $item_classes[] = 'mtw-tx__item--purchase';
} elseif ($is_flow) {
    $item_classes[] = 'mtw-tx__item--flow';
    $item_classes[] = 'mtw-tx__item--' . $status_class;
}

$row_classes = ['mtw-tx__row', $is_out ? 'is-out' : 'is-in'];
if ($is_purchase) {
    $row_classes[] = 'mtw-tx__row--purchase';
} elseif ($is_flow) {
    $row_classes[] = 'mtw-tx__row--flow';
    $row_classes[] = 'mtw-tx__row--' . $status_class;
}

$details_id = ($details_id_prefix ?? 'mtw-tx-details') . '-' . $tx_id;
$op_label   = class_exists('MTW_MyAccount_UI')
    ? MTW_MyAccount_UI::label_operation_for_tx($transaction)
    : ($op_labels[$op] ?? $op);
?>
<article class="<?php echo esc_attr(implode(' ', $item_classes)); ?>" role="listitem">
    <div class="<?php echo esc_attr(implode(' ', $row_classes)); ?>">
        <span class="mtw-tx__kind" aria-hidden="true">
            <?php if ($is_purchase) : ?>
                <svg class="mtw-tx__arrow mtw-tx__icon--purchase" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 6h15l-1.5 9h-12z"/>
                    <circle cx="9" cy="20" r="1.4"/>
                    <circle cx="18" cy="20" r="1.4"/>
                    <path d="M6 6 5 3H2"/>
                </svg>
            <?php elseif ($is_out) : ?>
                <svg class="mtw-tx__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
            <?php else : ?>
                <svg class="mtw-tx__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m17 7-10 10"/><path d="M17 17H7V7"/></svg>
            <?php endif; ?>
        </span>

        <div class="mtw-tx__main">
            <div class="mtw-tx__main-top">
                <strong class="mtw-tx__op"><?php echo esc_html($op_label); ?></strong>
                <?php if ($status !== '') : ?>
                    <span class="mtw-tx__badge mtw-tx__badge--<?php echo esc_attr($status_class); ?>">
                        <?php echo esc_html($status_labels[$status] ?? $status); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php if ($ts) : ?>
                <div class="mtw-tx__meta">
                    <span class="mtw-tx__date">
                        <?php echo esc_html(MTW_MyAccount_UI::format_jalali($ts, 'j F Y')); ?>
                        <small><?php echo esc_html(MTW_MyAccount_UI::format_jalali($ts, 'H:i')); ?></small>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <div class="mtw-tx__amount">
            <span class="mtw-tx__amount-value"><?php echo esc_html(MTW_MyAccount_UI::format_price(abs($amt))); ?></span>
        </div>
        <div class="mtw-tx__toggle-cell">
            <button type="button"
                    class="mtw-tx__toggle"
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr($details_id); ?>"
                    aria-label="نمایش توضیحات">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
        </div>
    </div>
    <div class="mtw-tx__details" id="<?php echo esc_attr($details_id); ?>" hidden>
        <span class="mtw-tx__details-label">توضیحات تراکنش:</span>
        <?php if ($desc !== '') : ?>
            <p class="mtw-tx__details-text"><?php echo MTW_MyAccount_UI::format_tx_description_html((string) ($transaction->description ?? ''), 0, $transaction); ?></p>
        <?php else : ?>
            <p class="mtw-tx__details-text is-empty">توضیحی ثبت نشده است.</p>
        <?php endif; ?>
    </div>
</article>
