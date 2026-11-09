<?php
/**
 * مودال تراکنش + اسکریپت (مشترک)
 *
 * متغیرهای لازم: $tx_js_data, $silver_price
 * اختیاری: $wwp_modal_redirect_page (رشته slug صفحه)، $wwp_modal_redirect_user_id (برای medyar-wallet-user-insights)
 */
if (!defined('ABSPATH')) {
    exit;
}
$wwp_modal_redirect_page     = isset($wwp_modal_redirect_page) ? (string) $wwp_modal_redirect_page : '';
$wwp_modal_redirect_user_id  = isset($wwp_modal_redirect_user_id) ? (int) $wwp_modal_redirect_user_id : 0;
$silver_price                  = isset($silver_price) ? (float) $silver_price : 0.0;
$tx_js_data                    = isset($tx_js_data) ? $tx_js_data : [];
$asset_js_map = [];
if (function_exists('wwp_static_assets_config')) {
    foreach (wwp_static_assets_config() as $asset_key => $_row) {
        $asset_js_map[$asset_key] = [
            'label' => function_exists('wwp_label_balance_type') ? wwp_label_balance_type($asset_key) : $asset_key,
            'unit' => function_exists('wwp_asset_unit_label') ? wwp_asset_unit_label($asset_key) : '',
            'is_decimal' => function_exists('wwp_asset_is_decimal') ? wwp_asset_is_decimal($asset_key) : true,
        ];
    }
}
if (empty($asset_js_map) && class_exists('Asset_Settings_Manager')) {
    foreach (Asset_Settings_Manager::get_instance()->get_assets(false) as $asset) {
        $asset_js_map[$asset['asset_key']] = [
            'label' => ($asset['title_fa'] ?: $asset['title_en']),
            'unit' => $asset['unit_label'],
            'is_decimal' => !empty($asset['is_decimal']),
        ];
    }
}
?>
<style>
#wwp-tx-modal-overlay .wwp-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
}
#wwp-tx-modal-overlay .wwp-badge-warning { background: #ffb900; color: #fff; }
#wwp-tx-modal-overlay .wwp-badge-success { background: #46b450; color: #fff; }
#wwp-tx-modal-overlay .wwp-badge-danger { background: #dc3232; color: #fff; }
#wwp-tx-modal-overlay .wwp-badge-info { background: #00a0d2; color: #fff; }
#wwp-tx-modal-overlay .wwp-tx-row .wwp-info-label { font-size: 10px; }
#wwp-tx-modal-overlay .mono {
    font-family: monospace;
    font-size: 12px;
    word-break: break-all;
    direction: ltr;
    text-align: left;
    display: inline-block;
}
#wwp-tx-modal-overlay .wwp-ref-code {
    font-family: monospace;
    font-size: 12px;
    word-break: break-all;
    direction: ltr;
    text-align: right;
    display: block;
    width: 100%;
}
</style>
<!-- ══ MODAL تراکنش کیف پول ══ -->
<div id="wwp-tx-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="wwp-modal-title">
    <div id="wwp-tx-modal">
        <div class="wwp-modal-header">
            <h2 id="wwp-modal-title">جزئیات تراکنش</h2>
            <div id="wwp-status-header-wrap"></div>
            <button class="wwp-modal-close" id="wwp-modal-close-btn" type="button" aria-label="بستن">&#215;</button>
        </div>
        <div class="wwp-modal-body" id="wwp-modal-body">
            <p style="padding:20px">در حال بارگذاری...</p>
        </div>
        <div class="wwp-modal-footer">
            <button type="button" class="button" id="wwp-modal-cancel-btn">بستن</button>
            <button type="submit" form="wwp-tx-update-form" class="button button-primary">به‌روزرسانی تراکنش</button>
        </div>
    </div>
</div>

<script>
    // یک آبجکت مشترک با AJAX (admin-list.js روی window.wwpTxData می‌نویسد)
    window.wwpTxData = Object.assign(window.wwpTxData || {}, <?php echo wp_json_encode($tx_js_data); ?>);
    const wwpTxData = window.wwpTxData;
    const wwpPostUrl = '<?php echo esc_url(admin_url('admin-post.php')); ?>';
    const wwpAjaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
    const wwpSilverPriceLive = <?php echo wp_json_encode($silver_price); ?>;
    const wwpAssets = <?php echo wp_json_encode($asset_js_map); ?> || {};
    const wwpModalRedirectPage = <?php echo wp_json_encode($wwp_modal_redirect_page); ?>;
    const wwpModalRedirectUserId = <?php echo (int) $wwp_modal_redirect_user_id; ?>;
    const wwpStatuses = {
        pending: 'در حال بررسی',
        processing: 'در انتظار بررسی',
        completed: 'تکمیل شده',
        canceled: 'لغو شده'
    };
    const wwpOps = {
        charge: 'واریز',
        spend: 'خرج',
        admin_add: 'اضافه توسط ادمین',
        admin_reduce: 'کاهش توسط ادمین',
        withdraw: 'درخواست برداشت',
        returned: 'بازگشت',
        refund_order: 'بازگشت سفارش'
    };
    Object.keys(wwpAssets).forEach(function(k){
        const label = (assetMeta(k).label || k);
        wwpOps['buy_' + k] = 'خرید ' + label;
        wwpOps['sell_' + k] = 'فروش ' + label;
    });
    function assetMeta(type){ return wwpAssets[type] || {label:type, unit:'', is_decimal:true}; }
    function assetAmount(value, type){
        const a = Math.abs(parseFloat(value || 0));
        const m = assetMeta(type);
        const num = m.is_decimal ? a.toLocaleString('fa-IR',{minimumFractionDigits:0,maximumFractionDigits:3}) : parseInt(a,10).toLocaleString('fa-IR');
        return num + (m.unit ? (' ' + m.unit) : '');
    }

    // delegation: دکمه‌های مشاهده بعد از AJAX فیلتر هم کار کنند
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.wwp-btn-view');
        if (!btn || !btn.dataset.txId) return;
        e.preventDefault();
        openModal(+btn.dataset.txId);
    });
    document.getElementById('wwp-modal-close-btn').addEventListener('click', closeModal);
    document.getElementById('wwp-modal-cancel-btn').addEventListener('click', closeModal);
    document.getElementById('wwp-tx-modal-overlay').addEventListener('click', e => {
        if (e.target.id === 'wwp-tx-modal-overlay') closeModal();
    });

    function openModal(txId) {
        const tx = wwpTxData[txId];
        if (!tx) return;
        document.getElementById('wwp-modal-body').innerHTML = buildBody(tx);
        document.getElementById('wwp-status-header-wrap').innerHTML =
            `<select form="wwp-tx-update-form" name="new_status" class="wwp-status-inline" id="wwp-status-select">${buildStatusOpts(tx.status)}</select>`;
        document.getElementById('wwp-tx-modal-overlay').classList.add('is-open');
        bindEvents(tx);
    }

    function closeModal() {
        document.getElementById('wwp-tx-modal-overlay').classList.remove('is-open');
        document.getElementById('wwp-modal-body').innerHTML = '';
        document.getElementById('wwp-status-header-wrap').innerHTML = '';
    }

    function buildStatusOpts(cur) {
        return Object.entries(wwpStatuses).map(([v, l]) => `<option value="${v}"${v===cur?' selected':''}>${l}</option>`).join('');
    }

    function buildBody(tx) {
        const isBuy = tx.operation_type === 'buy_silver',
            isSell = tx.operation_type === 'sell_silver';
        const amtAbs = Math.abs(tx.amount);
        const amtName = isBuy ? 'oruj_silver' : isSell ? 'oruj_toman' : 'amount';
        const m = assetMeta(tx.balance_type);
        const amtStep = tx.balance_type === 'toman' ? '1' : (m.is_decimal ? '0.001' : '1');
        const amtUnit = tx.balance_type === 'toman' ? 'تومان' : m.unit;
        const redirectHidden = wwpModalRedirectPage
            ? `<input type="hidden" name="redirect_page" value="${esc(wwpModalRedirectPage)}">` : '';
        const redirectUserHidden = wwpModalRedirectUserId > 0
            ? `<input type="hidden" name="redirect_user_id" value="${wwpModalRedirectUserId}">` : '';
        const isWalletPay = !!(tx.p_info && tx.p_info.type === 'Wallet_gateway');
        const silverPriceAtTx = tx.s_price && Number(tx.s_price) > 0
            ? Math.round(Number(tx.s_price)).toLocaleString('fa-IR') + ' تومان'
            : '—';
        const silverMabnaRows = (tx.operation_type === 'buy_silver' || tx.operation_type === 'sell_silver')
            ? `<div class="wwp-info-row"><span class="wwp-info-label">قیمت ${assetMeta('silver').label} در لحظهٔ ${isBuy ? 'خرید' : 'فروش'}</span><span class="wwp-info-value">${silverPriceAtTx}</span></div>
               <div class="wwp-info-row"><span class="wwp-info-label">قیمت لحظه‌ای ${assetMeta('silver').label}</span><span class="wwp-info-value">${Math.round(wwpSilverPriceLive).toLocaleString('fa-IR')} تومان</span></div>`
            : '';
        let html = `<form method="post" action="${wwpPostUrl}" id="wwp-tx-update-form">
        <input type="hidden" name="action" value="mtw_modal_update_transaction">
        <input type="hidden" name="transaction_id" value="${tx.id}">
        <input type="hidden" name="wwp_modal_nonce" value="${tx.nonce}">
        ${redirectHidden}${redirectUserHidden}
        <div class="wwp-modal-section">
            <div class="wwp-modal-section-title">اطلاعات تراکنش #${tx.id}</div>
            <div class="wwp-info-grid">
                <div class="wwp-info-row"><span class="wwp-info-label">کاربر</span><span class="wwp-info-value">${esc(tx.user_name)}</span></div>
                <div class="wwp-info-row"><span class="wwp-info-label">ایمیل</span><span class="wwp-info-value">${esc(tx.user_email)}</span></div>
                <div class="wwp-info-row"><span class="wwp-info-label">نوع موجودی</span><span class="wwp-info-value">${esc(tx.balance_label)}</span></div>
                <div class="wwp-info-row"><span class="wwp-info-label">عملیات</span><span class="wwp-info-value">${esc(tx.operation_label)}</span></div>
                <div class="wwp-info-row"><span class="wwp-info-label">کد مرجع</span><span class="wwp-info-value wwp-ref-code">${esc(tx.transaction_code||'-')}</span></div>
                ${tx.trade_tx_code ? `<div class="wwp-info-row"><span class="wwp-info-label">کد معامله</span><span class="wwp-info-value mono">${esc(tx.trade_tx_code)}</span></div>` : ''}
                <div class="wwp-info-row"><span class="wwp-info-label">درگاه</span><span class="wwp-info-value">${tx.gateway}</span></div>
                <div class="wwp-info-row"><span class="wwp-info-label">تاریخ ثبت</span><span class="wwp-info-value">${esc(tx.created_at)}</span></div>
                ${silverMabnaRows}
                <div class="wwp-info-row" style="grid-column:1/-1"><span class="wwp-info-label">توضیحات</span><div class="wwp-desc-lines">${formatDesc(tx.description)}</div></div>
            </div>
        </div>
        <div class="wwp-modal-section">
            <div class="wwp-modal-section-title">میزان تراکنش</div>
            <div class="wwp-amount-wrap">
                <input type="number" id="wwp-amount-input" name="${amtName}" value="${amtAbs}" step="${amtStep}" min="0" class="regular-text">
                <span class="unit">${amtUnit}</span>
            </div>
        </div>
        <input type="hidden" name="admin_note" id="wwp-admin-note" value="">
    </form>`;
        const hideBuyWalletAudit = isBuy && isWalletPay;
        if (tx.payment_record) html += `<div class="wwp-modal-section">${buildPayment(tx.payment_record,tx.related_label)}</div>`;
        else if (tx.linked_tx) html += `<div class="wwp-modal-section">${buildLinkedTx(tx.linked_tx,tx.related_label)}</div>`;

        if (tx.returned_tx) html += `<div class="wwp-modal-section">${buildLinkedTx(tx.returned_tx, 'نتیجه لغو تراکنش')}</div>`;

        if (!hideBuyWalletAudit && (isBuy || isSell)) html += `<div class="wwp-modal-section">
        <div class="wwp-modal-section-title">${isBuy?'محاسبه سود و زیان خرید ' + (assetMeta('silver').label || 'موجودی'):'محاسبه سود و زیان فروش ' + (assetMeta('silver').label || 'موجودی')}</div>
        <button type="button" id="wwp-pnl-btn" class="button">محاسبه سود و زیان</button>
        <div id="wwp-pnl-result" style="margin-top:12px"></div></div>`;
        return html;
    }

    function buildValidity(tx) {
        const v = tx.validity,
            cls = v.valid === true ? 'valid' : v.valid === false ? 'invalid' : 'needs-confirm';
        const ico = v.valid === true ? 'dashicons-yes-alt' : v.valid === false ? 'dashicons-dismiss' : 'dashicons-warning';
        return `<div class="wwp-modal-section-title">اعتبارسنجی</div>
    <div class="wwp-validity-box ${cls}"><span class="dashicons ${ico}"></span><span>${esc(v.reason||'اطلاعات کافی وجود ندارد.')}</span></div>`;
    }

    function buildPayment(pay, label) {
        return `<div class="wwp-modal-section-title">${esc(label)}</div>
    <div class="wwp-info-grid">
        <div class="wwp-info-row"><span class="wwp-info-label">شناسه</span><span class="wwp-info-value">#${pay.id}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">درگاه</span><span class="wwp-info-value">${esc(pay.gateway||'-')}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">مبلغ پرداختی</span><span class="wwp-info-value">${parseInt(pay.amount).toLocaleString('fa')} تومان</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">وضعیت</span><span class="wwp-info-value">${esc(pay.status||'-')}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">کد مرجع</span><span class="wwp-info-value wwp-ref-code">${esc(pay.reference_id||'-')}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">شماره کارت</span><span class="wwp-info-value mono">${esc(pay.card_pan||'-')}</span></div>
    </div>`;
    }

    function formatLinkedAmount(linked) {
        const a = Math.abs(parseFloat(linked.amount));
        return assetAmount(a, linked.balance_type || 'toman');
    }

    function buildLinkedTx(linked, label) {
        const opMap = wwpOps || {};
        const stMap = {
            pending: 'در حال بررسی',
            processing: 'در انتظار بررسی',
            completed: 'تکمیل شده',
            canceled: 'لغو شده'
        };
        const balMap = { toman: 'تومان' };
        Object.keys(wwpAssets).forEach(function(k){ balMap[k] = assetMeta(k).label; });
        return `<div class="wwp-modal-section-title">${esc(label)}</div>
    <div class="wwp-info-grid">
        <div class="wwp-info-row"><span class="wwp-info-label">شناسه</span><span class="wwp-info-value">#${linked.id}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">عملیات</span><span class="wwp-info-value">${opMap[linked.operation_type]||linked.operation_type}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">نوع موجودی</span><span class="wwp-info-value">${balMap[linked.balance_type]||linked.balance_type}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">میزان</span><span class="wwp-info-value">${formatLinkedAmount(linked)}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">وضعیت</span><span class="wwp-info-value">${stMap[linked.status]||linked.status}</span></div>
        <div class="wwp-info-row"><span class="wwp-info-label">کد مرجع</span><span class="wwp-info-value wwp-ref-code">${esc(linked.transaction_code||'-')}</span></div>
        <div class="wwp-info-row" style="grid-column:1/-1"><span class="wwp-info-label">توضیحات</span><div class="wwp-desc-lines">${formatDesc(linked.description)}</div></div>
    </div>`;
    }

    function bindEvents(tx) {
        const b = document.getElementById('wwp-pnl-btn');
        if (b) b.addEventListener('click', () => calcPnL(tx));
    }

    function calcPnL(tx) {
        const rd = document.getElementById('wwp-pnl-result'),
            btn = document.getElementById('wwp-pnl-btn');
        btn.disabled = true;
        btn.textContent = 'در حال محاسبه...';
        const fd = new FormData();
        fd.append('action', 'wwp_calculate_pnl');
        fd.append('tx_id', tx.id);
        fd.append('nonce', tx.ajax_nonce);
        fetch(wwpAjaxUrl, {
            method: 'POST',
            body: fd
        }).then(r => r.json()).then(res => {
            btn.disabled = false;
            btn.textContent = 'محاسبه سود و زیان';
            if (!res.success) {
                rd.innerHTML = `<div class="wwp-validity-box invalid">${esc(res.data)}</div>`;
                return;
            }
            const d = res.data,
                isBuy = tx.operation_type === 'buy_silver',
                diff = d.diff,
                diffAbs = Math.abs(diff);
            const silverUnit = assetMeta('silver').unit || '';
            const silverLabel = assetMeta('silver').label || 'موجودی';
            let diffText = diff > 0 ? (isBuy ? `اضافه کردن ${diffAbs.toFixed(3)} ${silverUnit} ${silverLabel} به میزان تراکنش` : `اضافه کردن ${parseInt(diffAbs).toLocaleString('fa')} تومان به میزان تراکنش`) : diff < 0 ? (isBuy ? `کاهش ${diffAbs.toFixed(3)} ${silverUnit} ${silverLabel} از میزان تراکنش` : `کاهش ${parseInt(diffAbs).toLocaleString('fa')} تومان از میزان تراکنش`) : 'بدون تفاوت';
            const diffCls = diff > 0 ? 'wwp-pnl-plus' : diff < 0 ? 'wwp-pnl-minus' : '';
            const unitSuffix = silverUnit ? (' ' + silverUnit) : '';
            rd.innerHTML = `<div class="wwp-pnl-box">
            <div class="wwp-pnl-row"><span>قیمت فعلی هر ${silverUnit || 'واحد'}</span><span>${parseInt(d.silver_price).toLocaleString('fa')} تومان</span></div>
            <div class="wwp-pnl-row"><span>مبلغ پرداختی</span><span>${parseInt(d.paid_amount).toLocaleString('fa')} تومان</span></div>
            <div class="wwp-pnl-row"><span>${isBuy?(silverUnit || 'مقدار')+' محاسبه‌شده':'تومان محاسبه‌شده'}</span><strong>${isBuy?d.calculated_value.toFixed(3)+unitSuffix:parseInt(d.calculated_value).toLocaleString('fa')+' تومان'}</strong></div>
            <div class="wwp-pnl-row"><span>${isBuy?(silverUnit || 'مقدار')+' ثبت‌شده':'تومان ثبت‌شده'}</span><span>${isBuy?Math.abs(tx.amount).toFixed(3)+unitSuffix:parseInt(Math.abs(tx.amount)).toLocaleString('fa')+' تومان'}</span></div>
            <div class="wwp-pnl-row highlight"><span>نتیجه</span><span class="${diffCls}">${diffText}</span></div>
        </div>
        <button type="button" id="wwp-apply-pnl" class="button button-primary" style="margin-top:10px" data-val="${d.calculated_value}">اعمال مقدار محاسبه‌شده</button>`;
            document.getElementById('wwp-apply-pnl').addEventListener('click', function() {
                const v = parseFloat(this.dataset.val);
                document.getElementById('wwp-amount-input').value = isBuy ? v.toFixed(3) : v.toFixed(0);
                const noteField = document.getElementById('wwp-admin-note');
                const pnlNote = `محاسبه سود/زیان: ${diffText} (قیمت ${assetMeta('silver').label || 'موجودی'}: ${parseInt(d.silver_price).toLocaleString('fa')} تومان)`;
                noteField.value = noteField.value ? noteField.value + '\n' + pnlNote : pnlNote;
            });
        }).catch(() => {
            btn.disabled = false;
            btn.textContent = 'محاسبه سود و زیان';
            rd.innerHTML = '<div class="wwp-validity-box invalid">خطا در ارتباط با سرور.</div>';
        });
    }

    function formatDesc(desc) {
        if (!desc) return '<span style="color:#8c8f94">-</span>';
        const lines = desc.split('\n').map(l => l.trim()).filter(Boolean);
        return lines.map(line => {
            const m = line.match(/^\[(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2})\]\s*(.+)$/);
            if (m) {
                return `<div class="wwp-desc-line"><span class="wwp-desc-time">${esc(m[1])}</span><span class="wwp-desc-text">${esc(m[2])}</span></div>`;
            }
            return `<div class="wwp-desc-line"><span class="wwp-desc-text">${esc(line)}</span></div>`;
        }).join('');
    }

    function esc(s) {
        return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
</script>
