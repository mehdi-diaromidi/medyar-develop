/**
 * Harden my-account mobile sidebar toggle against click-through to nav links.
 */
(function ($) {
	'use strict';

	$(function () {
		var $expand = $('.myaccount-sidebar-mobile-expand');
		var $wrap = $('.myaccount-sidebar-wrap');
		var $overlay = $('#drplus-overlay');
		if (!$expand.length || !$wrap.length) {
			return;
		}

		$expand.attr({
			role: 'button',
			tabindex: '0',
			'aria-expanded': 'false',
			'aria-label': $expand.attr('aria-label') || 'باز کردن منوی حساب کاربری'
		});

		// Replace theme handler so we can stop propagation / block ghost clicks.
		$expand.off('click').on('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			if (typeof e.stopImmediatePropagation === 'function') {
				e.stopImmediatePropagation();
			}

			$wrap.addClass('mobile-expanded arp-drawer-opening');
			$expand.attr('aria-expanded', 'true');
			if ($overlay.length) {
				$overlay.stop(true, true).fadeIn();
			}

			window.setTimeout(function () {
				$wrap.removeClass('arp-drawer-opening');
			}, 320);
		});

		$expand.on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$expand.trigger('click');
			}
		});

		$overlay.off('click.mmadSidebar').on('click.mmadSidebar', function () {
			$wrap.removeClass('mobile-expanded arp-drawer-opening');
			$expand.attr('aria-expanded', 'false');
		});
	});
})(jQuery);
