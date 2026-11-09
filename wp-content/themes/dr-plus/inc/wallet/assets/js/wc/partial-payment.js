(function ($) {
	let bindWallet = function () {
		$('#sheyda_wallet_partial_amount').val(sheydaWallet.formatPrice($('#sheyda_wallet_partial_amount').val()));

		let wrap = $('.sheyda-wallet-partial');
		if (!wrap.length || wrap.data('wallet-bound')) {
			return;
		}

		wrap.data('wallet-bound', true);

		let checkbox = wrap.find('input[name="sheyda_wallet_use_wallet"]'),
			amountField = wrap.find('input[name="sheyda_wallet_partial_amount"]'),
			amountFieldWrapper = wrap.find('.sheyda-wallet-partial-field'),
			updateTimer;

		let triggerUpdate = function () {
			clearTimeout(updateTimer);
			updateTimer = setTimeout(function () {
				$(document.body).trigger('update_checkout');
			}, 500);
		};

		let syncState = function () {
			let active = checkbox.prop('checked');
			wrap.toggleClass('is-active', active);
			if (!active) {
				amountField.val('');
				amountFieldWrapper.hide();
			} else {
				amountFieldWrapper.show();
			}
		};

		checkbox.on('change', function () {
			syncState();
			triggerUpdate();
		});

		amountField.on('input', triggerUpdate);

		syncState();
	};

	$(document.body).on('updated_checkout', bindWallet);
	$(bindWallet);

	$(document).on('input', '#sheyda_wallet_partial_amount', function() {
		$(this).val(sheydaWallet.formatPrice($(this).val()));
	})
})(jQuery);
