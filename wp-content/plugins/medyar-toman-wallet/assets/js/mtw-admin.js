(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-mtw-copy]');
		if (!btn) {
			return;
		}
		e.preventDefault();
		var text = btn.getAttribute('data-mtw-copy') || btn.textContent || '';
		text = String(text).trim();
		if (!text || !navigator.clipboard) {
			return;
		}
		navigator.clipboard.writeText(text).then(function () {
			var prev = btn.getAttribute('data-mtw-label') || btn.textContent;
			btn.setAttribute('data-mtw-label', prev);
			btn.textContent = 'کپی شد';
			setTimeout(function () {
				btn.textContent = btn.getAttribute('data-mtw-label') || prev;
			}, 1200);
		}).catch(function () {});
	});
})();
