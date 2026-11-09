(function () {
	'use strict';

	function fmt(n) {
		var num = parseInt(n, 10) || 0;
		try {
			return num.toLocaleString('fa-IR');
		} catch (e) {
			return String(num);
		}
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	function money(n) {
		return '<span class="mmad-num">' + fmt(n) + '</span> تومان';
	}

	function cell(label, valueHtml) {
		return (
			'<div class="mmad-info-row">' +
			'<span class="mmad-info-label">' +
			esc(label) +
			'</span>' +
			'<span class="mmad-info-value">' +
			valueHtml +
			'</span>' +
			'</div>'
		);
	}

	function statusClass(status) {
		if (status === 'completed') {
			return 'mmad-badge-success';
		}
		if (status === 'cancelled' || status === 'refunded') {
			return 'mmad-badge-danger';
		}
		if (status === 'processing' || status === 'pending' || status === 'on-hold') {
			return 'mmad-badge-warning';
		}
		return 'mmad-badge-info';
	}

	function visitWhen(row) {
		var parts = [];
		if (row.visit_date) {
			parts.push(String(row.visit_date));
		}
		if (row.visit_time) {
			parts.push(String(row.visit_time));
		}
		return parts.length ? esc(parts.join(' — ')) : '—';
	}

	function buildBody(row) {
		var cls = statusClass(row.order_status);
		var pct = row.percent != null && row.percent !== '' ? String(row.percent) : '0';

		return (
			'<div class="mmad-modal-section">' +
			'<div class="mmad-modal-section-title">خلاصه درآمد</div>' +
			'<div class="mmad-pnl-box">' +
			'<div class="mmad-pnl-row"><span>کمیسیون از بیمار</span><span>' +
			money(row.commission) +
			'</span></div>' +
			'<div class="mmad-pnl-row"><span>کارمزد واریز متخصص (' +
			esc(pct) +
			'٪)</span><span>' +
			money(row.fee) +
			'</span></div>' +
			'<div class="mmad-pnl-row highlight"><span>جمع درآمد مدیار</span><span class="mmad-pnl-plus">' +
			money(row.total_income) +
			'</span></div>' +
			'<div class="mmad-pnl-row"><span>واریز به متخصص</span><span>' +
			money(row.net) +
			'</span></div>' +
			'</div></div>' +
			'<div class="mmad-modal-section">' +
			'<div class="mmad-modal-section-title">مبالغ رزرو</div>' +
			'<div class="mmad-info-grid">' +
			cell('قیمت ویزیت', money(row.visit_price)) +
			cell('مبلغ کل سفارش', money(row.total_price)) +
			cell('ناخالص متخصص', money(row.gross)) +
			cell('نوع محاسبه کمیسیون', esc(row.calc_label || '—')) +
			'</div></div>' +
			'<div class="mmad-modal-section">' +
			'<div class="mmad-modal-section-title">اطلاعات رزرو</div>' +
			'<div class="mmad-info-grid">' +
			cell('سفارش', '<span class="mmad-num">#' + esc(row.order_id) + '</span>') +
			cell('رزرو', row.book_id ? '<span class="mmad-num">#' + esc(row.book_id) + '</span>' : '—') +
			cell('متخصص', esc(row.specialist_name || '—')) +
			cell('بیمار', esc(row.patient_name || '—')) +
			cell('موبایل بیمار', row.patient_mobile ? '<span class="mmad-num">' + esc(row.patient_mobile) + '</span>' : '—') +
			cell('نوع ویزیت', esc(row.visit_type_label || '—')) +
			cell('مطب', esc(row.office || '—')) +
			cell('تاریخ ویزیت', visitWhen(row)) +
			cell('روش پرداخت', esc(row.payment_method || '—')) +
			cell('تاریخ سفارش', esc(row.date || '—')) +
			cell(
				'وضعیت',
				'<span class="mmad-badge ' +
					cls +
					'">' +
					esc(row.order_status_label || row.order_status || '—') +
					'</span>'
			) +
			'</div></div>'
		);
	}

	function openModal(row) {
		var overlay = document.getElementById('mmad-income-modal-overlay');
		var body = document.getElementById('mmad-income-modal-body');
		var statusWrap = document.getElementById('mmad-income-status-wrap');
		var orderLink = document.getElementById('mmad-income-modal-order');
		if (!overlay || !body) {
			return;
		}

		// Avoid being clipped by admin layout stacking contexts.
		if (overlay.parentNode !== document.body) {
			document.body.appendChild(overlay);
		}

		body.innerHTML = buildBody(row);
		if (statusWrap) {
			statusWrap.innerHTML =
				'<span class="mmad-badge ' +
				statusClass(row.order_status) +
				'">' +
				esc(row.order_status_label || row.order_status || '') +
				'</span>';
		}
		if (orderLink) {
			orderLink.href = row.order_url || '#';
		}
		overlay.hidden = false;
		overlay.classList.add('is-open');
		document.body.classList.add('mmad-modal-open');
	}

	function closeModal() {
		var overlay = document.getElementById('mmad-income-modal-overlay');
		if (!overlay) {
			return;
		}
		overlay.classList.remove('is-open');
		overlay.hidden = true;
		document.body.classList.remove('mmad-modal-open');
	}

	function parseRow(el) {
		var raw = el.getAttribute('data-mmad-income');
		if (!raw) {
			return null;
		}
		try {
			return JSON.parse(raw);
		} catch (e) {
			return null;
		}
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.mmad-income-detail-btn');
		if (btn) {
			e.preventDefault();
			var rowEl = btn.closest('.mmad-income-row');
			var data = rowEl ? parseRow(rowEl) : null;
			if (data) {
				openModal(data);
			}
			return;
		}
		if (e.target.id === 'mmad-income-modal-overlay') {
			closeModal();
			return;
		}
		if (e.target.id === 'mmad-income-modal-close' || e.target.closest('#mmad-income-modal-close')) {
			e.preventDefault();
			closeModal();
			return;
		}
		if (e.target.id === 'mmad-income-modal-cancel') {
			e.preventDefault();
			closeModal();
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			closeModal();
		}
	});
})();
