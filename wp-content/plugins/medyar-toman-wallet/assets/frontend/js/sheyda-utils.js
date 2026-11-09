sheydaWallet = {
	convertChars: function(string) {
		string = string.toString();
		let replaces = {
			'۰'	: '0',
			'۱'	: '1',
			'۲'	: '2',
			'۳'	: '3',
			'۴'	: '4',
			'۵'	: '5',
			'۶'	: '6',
			'۷'	: '7',
			'۸'	: '8',
			'۹'	: '9',
			'٪'	: '%',
			'÷'	: '/',
			'×'	: '*',
			'-'	: '-',
			'ـ'	: '_',
			'ي'	: 'ی',
			'ك'	: 'ک',
		}
		return string.replace(/[۰۱۲۳۴۵۶۷۸۹٪÷×ـيك]/g, match => replaces[match]);
	},
	/**
	 * Format a typed amount with ASCII thousands commas.
	 * Must NOT use bare toLocaleString(): on fa-IR mobiles it inserts Persian
	 * separator U+066C (٬). Stripping only "," then parseFloat("3٬000") → 3,
	 * which resets the field while the user is still typing.
	 */
	formatPrice: function(price, decimals = null) {
		price = String(price == null ? '' : price);
		const converted = sheydaWallet.convertChars(price);
		const addLastCharDot = converted.slice(-1) === '.' && converted.indexOf('.') !== -1;
		// Drop ASCII/Persian grouping marks & spaces before parsing digits.
		const original = converted.replace(/[\s,\u066C\u202F\u00A0'’]/g, '');

		const groupInt = function(digits) {
			digits = String(digits || '').replace(/\D/g, '').replace(/^0+(?=\d)/, '');
			if (digits === '') {
				return '';
			}
			return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		};

		if (decimals !== null && (original.indexOf('0.0') !== -1 || original.indexOf('.') !== -1)) {
			const priceParts = original.split('.');
			let frac = priceParts[1] || '';
			if (frac.length > decimals) {
				frac = frac.slice(0, decimals);
			}
			const whole = groupInt(priceParts[0]);
			if (whole === '' && frac === '') {
				return '';
			}
			return whole + (frac !== '' || addLastCharDot ? '.' + frac : '');
		}

		const formatted = groupInt(original.split('.')[0]);
		if (formatted === '') {
			return '';
		}
		return addLastCharDot ? formatted + '.' : formatted;
	},
	/**
	 * Apply formatPrice to a live input while keeping the caret on the same digit.
	 */
	applyPriceInput: function(el) {
		if (!el || typeof el.value !== 'string') {
			return;
		}
		const raw = el.value;
		const caret = typeof el.selectionStart === 'number' ? el.selectionStart : raw.length;
		const digitsBefore = sheydaWallet.convertChars(raw.slice(0, caret)).replace(/[^\d]/g, '').length;
		const formatted = sheydaWallet.formatPrice(raw);
		if (formatted === raw) {
			return;
		}
		el.value = formatted;
		if (typeof el.setSelectionRange !== 'function') {
			return;
		}
		let pos = 0;
		let seen = 0;
		while (pos < formatted.length && seen < digitsBefore) {
			if (/\d/.test(formatted.charAt(pos))) {
				seen++;
			}
			pos++;
		}
		try {
			el.setSelectionRange(pos, pos);
		} catch (e) { /* ignore */ }
	},
	initSelect2: function() {
		if(jQuery('.sheyda-wallet-select2:not([data-select2-id])').length) {
			jQuery('.sheyda-wallet-select2:not([data-select2-id])').select2({
				width: '25em',
			});
		}
	},
}