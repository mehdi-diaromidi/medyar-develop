(function () {
	'use strict';

	var KEY = 'mns_rsw_open';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	ready(function () {
		var root = document.querySelector('[data-mns-rsw]');
		if (!root) return;

		var toggle = root.querySelector('[data-mns-rsw-toggle]');
		var panel = root.querySelector('#mns-rsw-panel');
		if (!toggle || !panel) return;

		function setOpen(open) {
			if (open) {
				panel.removeAttribute('hidden');
				root.setAttribute('data-open', '1');
				toggle.setAttribute('aria-expanded', 'true');
				try { localStorage.setItem(KEY, '1'); } catch (e) {}
			} else {
				panel.setAttribute('hidden', '');
				root.removeAttribute('data-open');
				toggle.setAttribute('aria-expanded', 'false');
				try { localStorage.setItem(KEY, '0'); } catch (e) {}
			}
		}

		var preferOpen = false;
		try { preferOpen = localStorage.getItem(KEY) === '1'; } catch (e) {}
		setOpen(preferOpen);

		toggle.addEventListener('click', function () {
			setOpen(panel.hasAttribute('hidden'));
		});
	});
})();
