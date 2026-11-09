(function ($) {
	'use strict';

	function cfg() {
		return window.mtwWalletAjax || {};
	}

	function i18n(key, fallback) {
		var dict = cfg().i18n || {};
		return dict[key] || fallback || '';
	}

	function convertAmount(raw) {
		var convert = (window.sheydaWallet && sheydaWallet.convertChars)
			? sheydaWallet.convertChars
			: function (v) { return String(v); };
		return parseFloat(convert(String(raw || '')).replace(/[^\d]/g, '')) || 0;
	}

	function parseAjaxResponse(raw) {
		if (raw && typeof raw === 'object') {
			return raw;
		}
		try {
			return JSON.parse(String(raw || '').replace(/^\uFEFF/, '').trim());
		} catch (e) {
			return null;
		}
	}

	function postAjax(action, data) {
		var payload = $.extend({}, data || {}, {
			action: action,
			nonce: cfg().nonce || ''
		});
		return $.ajax({
			url: cfg().ajaxUrl || '',
			type: 'POST',
			dataType: 'text',
			data: payload
		}).then(function (raw) {
			var parsed = parseAjaxResponse(raw);
			if (!parsed) {
				return $.Deferred().reject({ status: 500, responseText: raw }).promise();
			}
			return parsed;
		});
	}

	function showBox($el, message, isError) {
		if (!$el || !$el.length) {
			return;
		}
		$el
			.toggleClass('note-error', !!isError)
			.html(String(message || ''))
			.stop(true, true)
			.slideDown();
	}

	function hideBox($el) {
		if (!$el || !$el.length) {
			return;
		}
		$el.slideUp({
			complete: function () {
				$(this).empty().removeClass('note-error');
			}
		});
	}

	function lockButton($btn, locked) {
		if (!$btn || !$btn.length) {
			return;
		}
		$btn.toggleClass('loading is-loading', !!locked);
		$btn.prop('disabled', !!locked);
		$btn.attr('aria-busy', locked ? 'true' : 'false');
		// Do not toggle theme `.disabled` during loading — theme sets opacity:0.5 on it.
		if (!locked) {
			$btn.removeClass('disabled');
		}
	}

	function errorMessage(res, xhr) {
		if (res && res.data && res.data.message) {
			return res.data.message;
		}
		if (xhr && xhr.status === 429) {
			return i18n('rateLimited', 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.');
		}
		if (xhr && (xhr.status === 403 || xhr.status === 401)) {
			return i18n('securityError', 'خطای امنیتی. صفحه را رفرش کنید.');
		}
		return i18n('networkError', 'خطا در ارتباط با سرور.');
	}

	function closeMtwSelects(except) {
		document.querySelectorAll('.mtw-select__menu.is-open').forEach(function (menu) {
			if (except && menu === except) {
				return;
			}
			menu.classList.remove('is-open');
			var wrap = menu.closest('.mtw-field-control--select');
			if (wrap) {
				wrap.classList.remove('is-open');
			}
			var trigger = menu.parentElement && menu.parentElement.querySelector('.mtw-select__trigger');
			if (trigger) {
				trigger.setAttribute('aria-expanded', 'false');
			}
		});
	}

	function isDefaultBankSlug(slug) {
		slug = String(slug || '').trim();
		return !slug || slug === 'bank-logo-default-ws' || slug === 'default';
	}

	/**
	 * Known banks → sprite class; unknown / default → same bitcoin-card icon as financial vault.
	 * (CSS .bank-logo-default-ws pointed at a missing SVG, so select logos stayed blank.)
	 */
	function bankLogoHtml(bank) {
		var slug = String(bank || '').trim() || 'bank-logo-default-ws';
		if (isDefaultBankSlug(slug)) {
			return '<span class="mtw-select__logo mtw-select__logo--fallback" aria-hidden="true">' +
				'<span class="drplus-simple-icon-wrap icon-has-bg mtw-section-icon mtw-card-fallback-icon">' +
				'<i class="drplus-icon-bitcoin-card drplus-simple-icon" aria-hidden="true"></i></span></span>';
		}
		return '<span class="mtw-select__logo" aria-hidden="true"><span class="bank-logo-img ' + slug + '"></span></span>';
	}

	/**
	 * Keep card PAN digit groups LTR inside RTL UI (spaces otherwise reverse groups).
	 * Bank name stays RTL-isolated; overall chip is laid out LTR: «بانک — 6362 … 6599».
	 */
	function fillSelectLabel(el, text) {
		if (!el) {
			return;
		}
		text = String(text || '').trim() || 'انتخاب کنید';
		var m = text.match(/^(.*?)(\s*[—\-]\s*)(\d{4}(?:[\s\-]+\d{4})+)$/);
		el.textContent = '';
		if (m) {
			var wrap = document.createElement('span');
			wrap.className = 'mtw-select__label-bidi';
			var bank = document.createElement('span');
			bank.className = 'mtw-select__bank';
			bank.setAttribute('dir', 'rtl');
			bank.textContent = m[1];
			var sep = document.createElement('span');
			sep.className = 'mtw-select__sep';
			sep.textContent = m[2];
			var pan = document.createElement('span');
			pan.className = 'mtw-select__pan';
			pan.setAttribute('dir', 'ltr');
			pan.textContent = m[3];
			wrap.appendChild(bank);
			wrap.appendChild(sep);
			wrap.appendChild(pan);
			el.appendChild(wrap);
			return;
		}
		if (/^\d{4}(?:[\s\-]+\d{4})+$/.test(text)) {
			var only = document.createElement('span');
			only.className = 'mtw-select__pan';
			only.setAttribute('dir', 'ltr');
			only.textContent = text;
			el.appendChild(only);
			return;
		}
		el.textContent = text;
	}

	function getStoredDefaultBankCardId() {
		try {
			var id = localStorage.getItem('mtw_default_bank_card_id');
			if (id && /^\d{16}$/.test(id)) {
				return id;
			}
			var last4 = localStorage.getItem('selected_bank_card_last4');
			if (last4 && /^\d{4}$/.test(last4)) {
				return { last4: last4 };
			}
		} catch (e) {
			// ignore
		}
		return null;
	}

	/** Prefer the user's default bank card (localStorage) in topup/withdraw selects. */
	function applyStoredDefaultCardToSelects() {
		var stored = getStoredDefaultBankCardId();
		if (!stored) {
			return;
		}
		document.querySelectorAll('select[data-mtw-select][name="card_number"], #mtw_topup_card, select[name="card_number"].mtw-select-native').forEach(function (native) {
			if (!native || !native.options || native.options.length === 0) {
				return;
			}
			var matchIndex = -1;
			Array.prototype.forEach.call(native.options, function (opt, index) {
				if (matchIndex !== -1 || !opt.value) {
					return;
				}
				var digits = String(opt.value).replace(/\D/g, '');
				if (typeof stored === 'string' && digits === stored) {
					matchIndex = index;
				} else if (stored && stored.last4 && digits.slice(-4) === stored.last4) {
					matchIndex = index;
				}
			});
			if (matchIndex < 0) {
				return;
			}
			native.selectedIndex = matchIndex;
			native.dispatchEvent(new Event('change', { bubbles: true }));
			var trigger = native.closest('.mtw-field-control--select');
			if (trigger) {
				var btn = trigger.querySelector('.mtw-select__trigger .mtw-select__label');
				var opt = native.options[matchIndex];
				if (btn && opt) {
					fillSelectLabel(btn, opt.textContent.trim());
					trigger.classList.add('is-filled');
					if (trigger.querySelector('.mtw-select__trigger')) {
						trigger.querySelector('.mtw-select__trigger').classList.remove('is-placeholder');
					}
				}
			}
		});
	}

	function initMtwSelects() {
		document.querySelectorAll('select[data-mtw-select]').forEach(function (native) {
			if (native.dataset.mtwEnhanced === '1') {
				return;
			}
			native.dataset.mtwEnhanced = '1';

			var control = native.closest('.mtw-field-control--select');
			if (!control) {
				return;
			}

			var shell = document.createElement('div');
			shell.className = 'mtw-select';

			var trigger = document.createElement('button');
			trigger.type = 'button';
			trigger.className = 'mtw-select__trigger';
			trigger.setAttribute('aria-haspopup', 'listbox');
			trigger.setAttribute('aria-expanded', 'false');

			var menu = document.createElement('ul');
			menu.className = 'mtw-select__menu';
			menu.setAttribute('role', 'listbox');

			function optionBank(opt) {
				return (opt && opt.getAttribute('data-bank')) || 'bank-logo-default-ws';
			}

			function syncLabel() {
				var selected = native.options[native.selectedIndex];
				var text = selected ? selected.textContent.trim() : '';
				var bank = selected ? optionBank(selected) : 'bank-logo-default-ws';
				trigger.innerHTML = bankLogoHtml(bank) + '<span class="mtw-select__label"></span>';
				fillSelectLabel(trigger.querySelector('.mtw-select__label'), text || 'انتخاب کنید');
				trigger.classList.toggle('is-placeholder', !native.value);
				control.classList.toggle('is-filled', !!native.value);
			}

			function buildOptions() {
				menu.innerHTML = '';
				Array.prototype.forEach.call(native.options, function (opt, index) {
					var li = document.createElement('li');
					li.className = 'mtw-select__option';
					li.setAttribute('role', 'option');
					li.dataset.value = opt.value;
					li.dataset.index = String(index);
					li.innerHTML = bankLogoHtml(optionBank(opt)) + '<span class="mtw-select__label"></span>';
					fillSelectLabel(li.querySelector('.mtw-select__label'), opt.textContent.trim());
					if (opt.disabled || opt.value === '') {
						li.classList.add('is-disabled');
					}
					if (opt.selected) {
						li.classList.add('is-selected');
						li.setAttribute('aria-selected', 'true');
					}
					li.addEventListener('click', function (e) {
						e.preventDefault();
						e.stopPropagation();
						if (li.classList.contains('is-disabled')) {
							return;
						}
						native.selectedIndex = index;
						native.dispatchEvent(new Event('change', { bubbles: true }));
						syncLabel();
						closeMtwSelects();
					});
					menu.appendChild(li);
				});
			}

			function openMenu() {
				closeMtwSelects(menu);
				buildOptions();
				menu.classList.add('is-open');
				control.classList.add('is-open');
				trigger.setAttribute('aria-expanded', 'true');
			}

			function toggleMenu(e) {
				e.preventDefault();
				e.stopPropagation();
				if (menu.classList.contains('is-open')) {
					closeMtwSelects();
				} else {
					openMenu();
				}
			}

			trigger.addEventListener('click', toggleMenu);
			control.addEventListener('click', function (e) {
				if (e.target.closest('.mtw-select__menu')) {
					return;
				}
				if (e.target.closest('.mtw-select__trigger')) {
					return;
				}
				toggleMenu(e);
			});

			native.addEventListener('change', syncLabel);

			shell.appendChild(trigger);
			native.insertAdjacentElement('afterend', shell);
			control.appendChild(menu);
			buildOptions();
			syncLabel();
		});

		if (!document.documentElement.dataset.mtwSelectBound) {
			document.documentElement.dataset.mtwSelectBound = '1';
			document.addEventListener('click', function () {
				closeMtwSelects();
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') {
					closeMtwSelects();
				}
			});
		}
	}

	$(document).ready(function () {
		var actions = cfg().actions || {};
		var busy = {
			topup: false,
			withdraw: false,
			financial: false
		};

		// Keep the active wallet tab fully visible inside the horizontal scroller (esp. RTL/mobile).
		(function ensureActiveNavVisible() {
			var active = document.querySelector('.sheyda_wallet_nav_item.active');
			var nav = document.querySelector('.sheyda_wallet_nav_items');
			if (!active || !nav || nav.scrollWidth <= nav.clientWidth + 1) {
				return;
			}
			try {
				active.scrollIntoView({ inline: 'nearest', block: 'nearest', behavior: 'instant' });
			} catch (err) {
				active.scrollIntoView(false);
			}
		})();

		function formatTopupAmount(amount) {
			var n = Math.floor(Number(amount) || 0);
			if (!n) {
				return '—';
			}
			try {
				return n.toLocaleString('fa-IR') + ' تومان';
			} catch (err) {
				return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' تومان';
			}
		}

		function syncTopupUi() {
			var $field = $('#sheyda_wallet_topup-amount-field');
			if (!$field.length) {
				return;
			}

			var amount = convertAmount($field.val());
			var $summary = $('#mtwTopupSummaryAmount');
			if ($summary.length) {
				$summary.text(formatTopupAmount(amount));
			}

			$('.sheyda_wallet_topup-predefined-amount-btn').each(function () {
				var chipAmount = convertAmount($(this).attr('data-amount'));
				$(this).toggleClass('is-active', chipAmount > 0 && chipAmount === amount);
			});

			var $btn = $('#sheyda_wallet_topup-submit');
			if ($btn.length && !busy.topup) {
				var $text = $btn.find('.button-text');
				if (amount >= 1000) {
					$text.text('پرداخت ' + formatTopupAmount(amount));
					$btn.removeClass('is-muted');
				} else {
					$text.text('ادامه و پرداخت');
					$btn.addClass('is-muted');
				}
			}
		}

		$('.sheyda_wallet_topup-predefined-amount-btn').on('click', function () {
			$('#sheyda_wallet_topup-amount-field').val($(this).attr('data-amount')).trigger('input');
		});

		$('#sheyda_wallet_topup-amount-field').on('change input', function () {
			hideBox($('.sheyda_wallet_topup-errors'));
			syncTopupUi();
		});

		syncTopupUi();

		$('#sheyda_wallet_topup-submit').on('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			if (busy.topup) {
				return;
			}

			var $btn = $(this);
			var amount = convertAmount($('#sheyda_wallet_topup-amount-field').val());
			var cardNumber = $('#mtw_topup_card').val() || '';
			var $errors = $('.sheyda_wallet_topup-errors');
			var $status = $('#mtwTopupAjaxStatus');

			if (!cfg().ajaxUrl) {
				showBox($errors, i18n('networkError', 'خطا در ارتباط با سرور.'), true);
				return;
			}
			if (!amount || amount < 1000) {
				showBox($errors, i18n('invalidAmount', 'مبلغ نامعتبر است.'), true);
				return;
			}
			if (!cardNumber) {
				showBox($errors, i18n('selectCard', 'یک کارت بانکی انتخاب کنید.'), true);
				return;
			}

			busy.topup = true;
			lockButton($btn, true);
			hideBox($errors);
			if ($status.length) {
				$status
					.prop('hidden', false)
					.removeClass('is-error')
					.text(i18n('redirecting', 'در حال انتقال به درگاه…'));
			}

			postAjax(actions.topup || 'mtw_wallet_topup', {
				amount: amount,
				card_number: cardNumber
			})
				.done(function (res) {
					if (res && res.success && res.data && res.data.checkoutUrl) {
						window.location.replace(res.data.checkoutUrl);
						return;
					}
					if ($status.length) {
						$status.prop('hidden', true).empty();
					}
					showBox($errors, errorMessage(res), true);
					busy.topup = false;
					lockButton($btn, false);
					syncTopupUi();
				})
				.fail(function (xhr) {
					var res = null;
					try {
						res = xhr.responseJSON || parseAjaxResponse(xhr.responseText || '');
					} catch (err) {
						res = null;
					}
					if ($status.length) {
						$status.prop('hidden', true).empty();
					}
					showBox($errors, errorMessage(res, xhr), true);
					busy.topup = false;
					lockButton($btn, false);
					syncTopupUi();
				});
		});

		$('#sheyda_wallet_financial-new-account-btn').on('click', function () {
			if (typeof wp === 'undefined' || !wp.template) {
				return;
			}
			var template = wp.template('sheyda-wallet-financial-account');
			var newItem = template({
				index: $('.sheyda_wallet_financial-account').length
			});
			$('.sheyda_wallet_financial-accounts').append(newItem);

			if ($.fn.select2) {
				$('.sheyda_wallet_financial-account_type').select2({
					width: '100%',
					minimumResultsForSearch: Infinity
				});
			}
		});

		$(document).on('click', '.sheyda_wallet_financial-account-remove', function () {
			$(this).closest('.sheyda_wallet_financial-account').remove();
			$('.sheyda_wallet_financial-account').each(function (index) {
				$(this).find('input, select').each(function () {
					var name = $(this).attr('name');
					if (name) {
						$(this).attr('name', name.replace(/\d+/g, String(index)));
					}
					var id = $(this).attr('id');
					if (id) {
						$(this).attr('id', id.replace(/\d+/g, String(index)));
					}
					var $label = $(this).siblings('label');
					if ($label.length && $label.attr('for')) {
						$label.attr('for', $label.attr('for').replace(/\d+/g, String(index)));
					}
				});
			});
		});

		initMtwSelects();
		applyStoredDefaultCardToSelects();

		if ($.fn.select2) {
			$('.sheyda_wallet_financial-account_type').select2({
				width: '100%',
				minimumResultsForSearch: Infinity
			});
		}

		function formatMoneyLabel(amount) {
			var n = Math.floor(Number(amount) || 0);
			if (!n) {
				return '—';
			}
			try {
				return n.toLocaleString('fa-IR') + ' تومان';
			} catch (err) {
				return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' تومان';
			}
		}

		function setWithdrawStatus(message, isError) {
			var $status = $('#mtw_withdrawal_ajax_status');
			if (!$status.length) {
				return;
			}
			if (!message) {
				$status.prop('hidden', true).empty().removeClass('is-error note-error');
				return;
			}
			$status
				.prop('hidden', false)
				.toggleClass('is-error note-error', !!isError)
				.html(String(message));
		}

		function syncWithdrawUi() {
			var $amountField = $('#sheyda_wallet_withdrawal-amount-field');
			var $submitButton = $('#sheyda_wallet_withdrawal-submit');
			if (!$amountField.length) {
				return false;
			}
			var value = convertAmount($amountField.val());
			var minValue = parseFloat($amountField.attr('data-min'));
			var maxValue = parseFloat($amountField.attr('data-max'));
			var ok = !isNaN(value) && value >= minValue && value <= maxValue;
			var $summary = $('#mtwWithdrawSummary');
			var $checkout = $('#mtwWithdrawCheckout');

			if (value > 0) {
				$summary.prop('hidden', false);
				$('#mtwWithdrawSummaryAmount').text(formatMoneyLabel(value));
				$checkout.removeClass('is-summary-empty');
			} else {
				$summary.prop('hidden', true);
				$('#mtwWithdrawSummaryAmount').text('—');
				$checkout.addClass('is-summary-empty');
			}

			if ($submitButton.length && !busy.withdraw) {
				var $text = $submitButton.find('.button-text');
				if (ok) {
					$text.text('ثبت درخواست ' + formatMoneyLabel(value));
					$submitButton.removeClass('disabled is-muted').prop('disabled', false);
				} else {
					$text.text('ثبت درخواست');
					$submitButton.addClass('disabled is-muted').prop('disabled', true);
				}
			}
			return ok;
		}

		function checkWithdrawalAmount(showErrors) {
			var $amountField = $('#sheyda_wallet_withdrawal-amount-field');
			var $errors = $('.sheyda_wallet_withdrawal-errors');
			if (!$amountField.length) {
				return true;
			}
			var value = convertAmount($amountField.val());
			var minValue = parseFloat($amountField.attr('data-min'));
			var maxValue = parseFloat($amountField.attr('data-max'));
			var ok = syncWithdrawUi();

			if (ok) {
				hideBox($errors);
				return true;
			}

			if (showErrors) {
				var msg = !value
					? i18n('invalidAmount', 'مبلغ نامعتبر است.')
					: (value < minValue
						? i18n('minWithdrawalError', 'مبلغ کمتر از حداقل مجاز است.')
						: i18n('maxWithdrawalError', 'مبلغ بیشتر از موجودی است.'));
				showBox($errors, msg, true);
			}
			return false;
		}

		function escapeHtml(str) {
			return String(str == null ? '' : str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}

		function buildWithdrawalRow(data) {
			var status = String((data && data.status) || 'processing');
			var txId = parseInt((data && data.txId) || 0, 10) || Date.now();
			var detailsId = 'mtw-wd-details-' + txId;
			var desc = String((data && data.descriptionText) || '').trim();
			var descSafe = String((data && data.descriptionHtml) || '').trim();
			var dateHtml = '';
			if (data && data.dateText) {
				dateHtml = '<div class="mtw-tx__meta"><span class="mtw-tx__date">'
					+ escapeHtml(data.dateText)
					+ (data.timeText ? ' <small>' + escapeHtml(data.timeText) + '</small>' : '')
					+ '</span></div>';
			}
			var descHtml = (descSafe || desc)
				? '<p class="mtw-tx__details-text">' + (descSafe || escapeHtml(desc)) + '</p>'
				: '<p class="mtw-tx__details-text is-empty">توضیحی ثبت نشده است.</p>';
			return ''
				+ '<article class="sheyda_wallet_transaction mtw-tx__item mtw-tx__item--'
				+ escapeHtml(status)
				+ '" role="listitem">'
				+ '<div class="mtw-tx__row is-out mtw-tx__row--' + escapeHtml(status) + '">'
				+ '<span class="mtw-tx__kind" aria-hidden="true">'
				+ '<svg class="mtw-tx__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>'
				+ '</span>'
				+ '<div class="mtw-tx__main"><div class="mtw-tx__main-top">'
				+ '<strong class="mtw-tx__op">' + escapeHtml((data && data.opLabel) || 'برداشت') + '</strong>'
				+ '<span class="mtw-tx__badge mtw-tx__badge--' + escapeHtml(status) + '">'
				+ escapeHtml((data && data.statusText) || status)
				+ '</span></div>'
				+ dateHtml
				+ '</div>'
				+ '<div class="mtw-tx__amount"><span class="mtw-tx__amount-value">'
				+ escapeHtml((data && data.amountText) || '')
				+ '</span></div>'
				+ '<div class="mtw-tx__toggle-cell">'
				+ '<button type="button" class="mtw-tx__toggle" aria-expanded="false" aria-controls="'
				+ escapeHtml(detailsId)
				+ '" aria-label="نمایش توضیحات">'
				+ '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
				+ '</button></div></div>'
				+ '<div class="mtw-tx__details" id="' + escapeHtml(detailsId) + '" hidden>'
				+ '<span class="mtw-tx__details-label">توضیحات تراکنش:</span>'
				+ descHtml
				+ '</div></article>';
		}

		function prependWithdrawalRow(data) {
			var $list = $('#mtw_withdrawals_list');
			if (!$list.length) {
				return;
			}
			$list.find('#mtw_withdrawals_empty, .mtw-tx__empty').remove();
			$list.prepend(buildWithdrawalRow(data));

			var $count = $('#mtw_withdrawals_count');
			if ($count.length) {
				var raw = String($count.text() || '');
				if (window.sheydaWallet && sheydaWallet.convertChars) {
					raw = sheydaWallet.convertChars(raw);
				} else {
					raw = raw.replace(/[۰-۹]/g, function (d) {
						return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
					}).replace(/[٠-٩]/g, function (d) {
						return String(d.charCodeAt(0) - 1632);
					});
				}
				var current = parseInt(raw.replace(/[^\d]/g, ''), 10) || 0;
				var next = current + 1;
				var label = (window.sheydaWallet && sheydaWallet.formatNumber)
					? sheydaWallet.formatNumber(next)
					: String(next);
				$count.text(label + ' مورد').prop('hidden', false);
			}
		}

		function applyWithdrawSuccess(data) {
			var $amount = $('#sheyda_wallet_withdrawal-amount-field');
			if (data && data.balanceText) {
				$('#mtwWithdrawBalanceFact, .withdrawable-credit').text(data.balanceText);
				$('#mtwWithdrawLimitNote').text('سقف قابل برداشت فعلی: ' + data.balanceText + '.');
			}
			if ($amount.length && data && typeof data.maxAmount !== 'undefined') {
				$amount.attr('data-max', String(data.maxAmount));
				$('#mtwWithdrawLimitNote').text(
					'سقف قابل برداشت فعلی: ' + (data.balanceText || formatMoneyLabel(data.maxAmount)) + '.'
				);
			}
			if ($amount.length) {
				$amount.val('');
			}
			prependWithdrawalRow(data || {});
			syncWithdrawUi();
		}

		$('#sheyda_wallet_withdrawal-amount-field').on('input change', function () {
			hideBox($('.sheyda_wallet_withdrawal-errors'));
			setWithdrawStatus('');
			syncWithdrawUi();
		});

		syncWithdrawUi();

		$('#sheyda_wallet_withdrawal-request-form').on('submit', function (e) {
			e.preventDefault();
			e.stopPropagation();
			if (busy.withdraw) {
				return;
			}

			var $btn = $('#sheyda_wallet_withdrawal-submit');
			if ($btn.hasClass('is-muted') || $btn.hasClass('disabled') || $btn.prop('disabled')) {
				checkWithdrawalAmount(true);
				return;
			}
			if (!checkWithdrawalAmount(true)) {
				return;
			}

			var $errors = $('.sheyda_wallet_withdrawal-errors');
			var amount = convertAmount($('#sheyda_wallet_withdrawal-amount-field').val());
			var cardNumber = $('#sheyda_wallet_withdrawal-destination-field').val() || '';

			if (!amount || amount <= 0) {
				showBox($errors, i18n('invalidAmount', 'مبلغ نامعتبر است.'), true);
				return;
			}

			if (!cfg().ajaxUrl) {
				showBox($errors, i18n('networkError', 'خطا در ارتباط با سرور.'), true);
				return;
			}

			if (!cardNumber) {
				showBox($errors, i18n('selectCard', 'یک کارت بانکی انتخاب کنید.'), true);
				return;
			}

			busy.withdraw = true;
			lockButton($btn, true);
			hideBox($errors);
			setWithdrawStatus('');

			postAjax(actions.withdraw || 'mtw_wallet_withdraw', {
				amount: amount,
				card_number: cardNumber
			})
				.done(function (res) {
					if (res && res.success) {
						applyWithdrawSuccess(res.data || {});
						setWithdrawStatus(
							(res.data && res.data.message) || i18n('withdrawSuccess', 'درخواست برداشت با موفقیت ثبت شد.'),
							false
						);
						busy.withdraw = false;
						lockButton($btn, false);
						syncWithdrawUi();
						return;
					}
					showBox($errors, errorMessage(res), true);
					busy.withdraw = false;
					lockButton($btn, false);
					syncWithdrawUi();
				})
				.fail(function (xhr) {
					var res = null;
					try {
						res = xhr.responseJSON || parseAjaxResponse(xhr.responseText || '');
					} catch (err) {
						res = null;
					}
					showBox($errors, errorMessage(res, xhr), true);
					busy.withdraw = false;
					lockButton($btn, false);
					syncWithdrawUi();
				});
		});

		$('#sheyda_wallet_withdrawal-submit').on('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var $btn = $(this);
			if ($btn.hasClass('is-muted') || $btn.hasClass('disabled') || $btn.prop('disabled') || busy.withdraw) {
				checkWithdrawalAmount(true);
				return;
			}
			$('#sheyda_wallet_withdrawal-request-form').trigger('submit');
		});

		// Expandable transaction/withdrawal rows (ARP-style chevron).
		document.addEventListener('click', function (e) {
			var item = e.target.closest('.mtw-tx__item');
			if (!item) {
				return;
			}
			var row = item.querySelector('.mtw-tx__row');
			var btn = item.querySelector('.mtw-tx__toggle');
			if (!row || !btn) {
				return;
			}
			if (!e.target.closest('.mtw-tx__toggle') && !e.target.closest('.mtw-tx__row')) {
				return;
			}
			if (!e.target.closest('.mtw-tx__toggle')) {
				if (e.target.closest('a, button, input, select, textarea')) {
					return;
				}
				if (window.getSelection && String(window.getSelection()).trim() !== '') {
					return;
				}
			}
			var details = document.getElementById(btn.getAttribute('aria-controls'));
			if (!details) {
				return;
			}
			var open = btn.getAttribute('aria-expanded') !== 'true';
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			btn.setAttribute('aria-label', open ? 'بستن توضیحات' : 'نمایش توضیحات');
			item.classList.toggle('is-open', open);
			details.hidden = !open;
		});

		// Financial section is bank-cards AJAX UI (Medyar_Bank_Cards); no form submit here.
	});
})(jQuery);
