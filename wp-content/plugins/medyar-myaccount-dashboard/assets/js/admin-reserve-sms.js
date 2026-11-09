(function ($) {
	'use strict';

	function reindex($wrap) {
		$wrap.find('.mmad-sms-repeater-item').each(function (i) {
			var n = i + 1;
			$(this).attr('data-index', n);
			$(this).find('.mmad-sms-repeater-index').text(n);
		});
	}

	$(document).on('click', '.mmad-sms-section-head', function () {
		$(this).closest('.mmad-sms-section').toggleClass('expanded')
			.find('.mmad-sms-section-body').slideToggle(150);
	});

	$(document).on('click', '.mmad-sms-repeater-add', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.mmad-sms-repeater-wrap');
		var tpl = $('#tmpl-mmad-phone-item').html();
		if (!tpl) {
			return;
		}
		var index = $wrap.find('.mmad-sms-repeater-item').length + 1;
		$wrap.find('.mmad-sms-repeater-add').closest('p').before(
			tpl.replace(/\{\{index\}\}/g, String(index))
		);
		reindex($wrap);
	});

	$(document).on('click', '.mmad-sms-repeater-remove', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.mmad-sms-repeater-wrap');
		var $item = $(this).closest('.mmad-sms-repeater-item');
		if ($wrap.find('.mmad-sms-repeater-item').length <= 1) {
			return;
		}
		$item.fadeOut(120, function () {
			$(this).remove();
			reindex($wrap);
		});
	});
})(jQuery);
