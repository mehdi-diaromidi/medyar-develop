<?php
if (!defined('ABSPATH')) {
    exit;
}

$prefix     = MTW_MyAccount_UI::PREFIX;
$user_id    = get_current_user_id();
$balance    = (int) Wallet_Manager::get_instance()->get_balance($user_id);
$min        = MTW_MyAccount_UI::min_withdrawal();
$max        = function_exists('medyar_wallet_get_max_withdraw') ? medyar_wallet_get_max_withdraw() : $balance;
$cards      = medyar_get_user_bank_cards($user_id);
$can_access = $balance >= $min;
$status_labels = MTW_MyAccount_UI::status_labels();
$tx_manager = Wallet_Transaction_Manager::get_instance();

$page   = isset($_GET['w-page']) ? max(1, absint($_GET['w-page'])) : 1;
$ppp    = 10;
$filter = ['balance_type' => 'toman', 'operation_type' => 'withdraw'];
$total  = $tx_manager->count_user_transactions($user_id, $filter);
$withdrawals = $tx_manager->get_user_transactions($user_id, array_merge($filter, [
    'limit'  => $ppp,
    'offset' => ($page - 1) * $ppp,
]));

?>
<?php
$max_now = (int) ($max > 0 ? min($balance, $max) : $balance);
?>
<div class="<?php echo esc_attr($prefix); ?>withdrawal-section <?php echo esc_attr($prefix); ?>section mtw-topup mtw-withdraw">
    <div class="mtw-topup__head">
        <div class="<?php echo esc_attr($prefix); ?>withdrawal-title-wrap <?php echo esc_attr($prefix); ?>section-title-wrap">
            <?php MTW_Icons::echo('wallet-minus'); ?>
            <div class="mtw-topup__titles">
                <span class="<?php echo esc_attr($prefix); ?>withdrawal-title <?php echo esc_attr($prefix); ?>section-title">درخواست برداشت</span>
                <p class="mtw-topup__lead">مبلغ را وارد کنید و به کارت بانکی خود درخواست دهید.</p>
            </div>
        </div>
    </div>

    <div class="mtw-topup__facts" aria-label="اطلاعات برداشت">
        <div class="mtw-topup__fact">
            <span class="mtw-topup__fact-label">موجودی قابل برداشت</span>
            <strong class="mtw-topup__fact-value withdrawable-credit" id="mtwWithdrawBalanceFact"><?php echo esc_html(MTW_MyAccount_UI::format_price($balance)); ?></strong>
        </div>
        <div class="mtw-topup__fact">
            <span class="mtw-topup__fact-label">حداقل برداشت</span>
            <strong class="mtw-topup__fact-value"><?php echo esc_html(MTW_MyAccount_UI::format_price($min)); ?></strong>
        </div>
    </div>

    <?php if (!$can_access) : ?>
        <div class="<?php echo esc_attr($prefix); ?>withdrawal-financial-error mtw-topup__empty-cards note-error">
            شما موجودی کافی برای برداشت ندارید.
            <?php printf(' حداقل مبلغ درخواست برداشت %s است.', esc_html(MTW_MyAccount_UI::format_price($min))); ?>
        </div>
    <?php elseif (empty($cards)) : ?>
        <div class="<?php echo esc_attr($prefix); ?>withdrawal-financial-error mtw-topup__empty-cards">
            <?php
            printf(
                'برای برداشت ابتدا یک کارت بانکی در <a href="%s">کارت‌های بانکی</a> ثبت کنید.',
                esc_url(MTW_MyAccount_UI::section_url('financial'))
            );
            ?>
        </div>
    <?php else : ?>
        <form action="#" method="post" class="<?php echo esc_attr($prefix); ?>withdrawal-request-form mtw-withdraw__form" id="<?php echo esc_attr($prefix); ?>withdrawal-request-form" novalidate>
            <section class="mtw-topup__panel mtw-withdraw__panel" aria-labelledby="mtwWithdrawFormHeading">
                <div class="mtw-topup__panel-head">
                    <h3 class="mtw-topup__panel-title" id="mtwWithdrawFormHeading">جزئیات درخواست</h3>
                    <a class="mtw-topup__panel-link" href="<?php echo esc_url(MTW_MyAccount_UI::section_url('financial')); ?>">مدیریت کارت‌ها</a>
                </div>

                <div class="mtw-withdraw__fields">
                    <div class="<?php echo esc_attr($prefix); ?>withdrawal-field-wrap mtw-topup__field">
                        <label for="<?php echo esc_attr($prefix); ?>withdrawal-destination-field" class="<?php echo esc_attr($prefix); ?>withdrawal-field-label">حساب مقصد</label>
                        <span class="mtw-field-control mtw-field-control--select">
                            <select id="<?php echo esc_attr($prefix); ?>withdrawal-destination-field" name="card_number" class="mtw-select-native" data-mtw-select>
                                <?php foreach ($cards as $num => $data) :
                                    $digits  = preg_replace('/\D/', '', (string) $num);
                                    $label   = trim(chunk_split($digits, 4, ' '), ' ');
                                    $bank_fa = is_array($data['bank'] ?? null) ? (string) ($data['bank']['fa'] ?? '') : '';
                                    $bank_en = is_array($data['bank'] ?? null) ? (string) ($data['bank']['en'] ?? '') : '';
                                    if ($bank_en === '') {
                                        $bank_en = 'bank-logo-default-ws';
                                    }
                                    if ($bank_fa !== '') {
                                        $label = $bank_fa . ' — ' . $label;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($digits); ?>" data-bank="<?php echo esc_attr($bank_en); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="mtw-field-control__chevron" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg></span>
                        </span>
                    </div>

                    <div class="<?php echo esc_attr($prefix); ?>withdrawal-field-wrap mtw-topup__custom">
                        <label class="mtw-topup__custom-label" for="<?php echo esc_attr($prefix); ?>withdrawal-amount-field">مبلغ برداشت</label>
                        <div class="mtw-topup__amount-box">
                            <input type="text"
                                   id="<?php echo esc_attr($prefix); ?>withdrawal-amount-field"
                                   class="sheyda-wallet-price-input ltr mtw-topup__amount-input <?php echo esc_attr($prefix); ?>withdrawal-field"
                                   name="amount"
                                   data-min="<?php echo esc_attr((string) $min); ?>"
                                   data-max="<?php echo esc_attr((string) $max_now); ?>"
                                   placeholder="مثلاً 250000"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   aria-describedby="mtwWithdrawLimitNote">
                            <span class="mtw-topup__amount-unit" aria-hidden="true">تومان</span>
                        </div>
                        <div class="<?php echo esc_attr($prefix); ?>withdrawal-errors" role="alert" aria-live="assertive"></div>
                        <p class="<?php echo esc_attr($prefix); ?>withdrawal-note mtw-topup__limit" id="mtwWithdrawLimitNote">
                            <?php printf('سقف قابل برداشت فعلی: %s.', esc_html(MTW_MyAccount_UI::format_price($max_now))); ?>
                        </p>
                    </div>
                </div>
            </section>

            <div class="mtw-topup__checkout is-summary-empty" id="mtwWithdrawCheckout">
                <div class="mtw-topup__summary" id="mtwWithdrawSummary" hidden>
                    <span class="mtw-topup__summary-label">مبلغ درخواست</span>
                    <strong class="mtw-topup__summary-value" id="mtwWithdrawSummaryAmount">—</strong>
                </div>
                <button id="<?php echo esc_attr($prefix); ?>withdrawal-submit"
                        class="<?php echo esc_attr($prefix); ?>withdrawal-submit sheyda_wallet_button mtw-topup__submit is-muted"
                        type="button"
                        disabled
                        aria-busy="false">
                    <span class="button-text">ثبت درخواست</span>
                    <span class="button-loading" aria-hidden="true"></span>
                </button>
            </div>

            <div class="mtw-topup__status" id="mtw_withdrawal_ajax_status" role="status" aria-live="polite" hidden></div>
        </form>
    <?php endif; ?>
</div>

<div class="<?php echo esc_attr($prefix); ?>withdrawals <?php echo esc_attr($prefix); ?>section mtw-tx" id="mtw_withdrawals">
    <div class="mtw-tx__head">
        <div class="mtw-tx__titles">
            <h2 class="mtw-tx__title">درخواست‌های برداشت</h2>
            <p class="mtw-tx__lead">وضعیت درخواست‌های برداشت تومانی شما.</p>
        </div>
        <span class="mtw-tx__count" id="mtw_withdrawals_count" <?php echo $total > 0 ? '' : 'hidden'; ?>>
            <?php echo esc_html(number_format_i18n($total)); ?> مورد
        </span>
    </div>

    <div class="<?php echo esc_attr($prefix); ?>withdrawals-items mtw-tx__list" id="mtw_withdrawals_list" role="list">
        <?php if (!empty($withdrawals)) : ?>
            <?php foreach ($withdrawals as $withdrawal) :
                $status       = (string) ($withdrawal->status ?? '');
                $status_class = sanitize_html_class($status !== '' ? $status : 'unknown');
                $ts           = !empty($withdrawal->created_at) ? strtotime($withdrawal->created_at) : 0;
                $tx_id        = (int) ($withdrawal->id ?? 0);
                $details_id   = 'mtw-wd-details-' . $tx_id;
                $desc         = MTW_MyAccount_UI::format_tx_description((string) ($withdrawal->description ?? ''), 0, $withdrawal);
                ?>
                <article class="<?php echo esc_attr($prefix); ?>transaction mtw-tx__item mtw-tx__item--<?php echo esc_attr($status_class); ?>" role="listitem">
                    <div class="mtw-tx__row is-out mtw-tx__row--<?php echo esc_attr($status_class); ?>">
                        <span class="mtw-tx__kind" aria-hidden="true">
                            <svg class="mtw-tx__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                        </span>
                        <div class="mtw-tx__main">
                            <div class="mtw-tx__main-top">
                                <strong class="mtw-tx__op">برداشت</strong>
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
                            <span class="mtw-tx__amount-value"><?php echo esc_html(MTW_MyAccount_UI::format_price(abs((float) $withdrawal->amount))); ?></span>
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
                            <p class="mtw-tx__details-text"><?php echo MTW_MyAccount_UI::format_tx_description_html((string) ($withdrawal->description ?? ''), 0, $withdrawal); ?></p>
                        <?php else : ?>
                            <p class="mtw-tx__details-text is-empty">توضیحی ثبت نشده است.</p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="<?php echo esc_attr($prefix); ?>empty-section mtw-tx__empty" id="mtw_withdrawals_empty">
                <span class="<?php echo esc_attr($prefix); ?>empty-section-text">هیچ درخواست برداشتی ثبت نشده است.</span>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($withdrawals)) :
        require_once MTW_PATH . 'templates/myaccount/pagination.php';
        mtw_myaccount_pagination([
            'query_arg_name' => 'w-page',
            'max_num_pages'  => (int) ceil($total / $ppp),
            'paged'          => $page,
        ]);
    endif; ?>
</div>
