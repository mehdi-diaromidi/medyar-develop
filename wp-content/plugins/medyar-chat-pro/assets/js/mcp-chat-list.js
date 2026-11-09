/**
 * Medyar Chat Pro — status / follow-up / waiting badges on the theme's chat list pages.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		if (typeof mcpChatList === 'undefined' || !window.fetch) {
			return;
		}
		var C = mcpChatList;
		var items = {};
		document.querySelectorAll('.chats-content .chat-item').forEach(function (li) {
			var a = li.querySelector('a.chat-item-wrap[href]');
			var m = a && a.getAttribute('href').match(/\/(\d+)\/?(?:[?#].*)?$/);
			if (m) {
				items[m[1]] = li;
			}
		});
		var ids = Object.keys(items);
		if (!ids.length) {
			return;
		}

		function duration(secs) {
			var h = Math.floor(secs / 3600);
			var d = Math.floor(h / 24);
			var fmt = function (n) {
				try {
					return n.toLocaleString('fa-IR');
				} catch (e) {
					return String(n);
				}
			};
			if (d) {
				return fmt(d) + ' ' + C.i18n.days;
			}
			if (h) {
				return fmt(h) + ' ' + C.i18n.hours;
			}
			return fmt(Math.max(1, Math.floor(secs / 60))) + ' ' + C.i18n.minutes;
		}

		fetch(C.restBase + 'sessions/summary?_envelope=1&ids=' + ids.join(','), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce }
		}).then(function (r) {
			return r.ok ? r.json() : {};
		}).then(function (env) {
			var data = env && env.status === 200 ? env.body : {};
			Object.keys(data || {}).forEach(function (id) {
				var s = data[id];
				var li = items[id];
				if (!li || !s) {
					return;
				}
				var wrap = document.createElement('div');
				wrap.className = 'mcp-list-badges';
				var badge = document.createElement('span');
				badge.className = 'mcp-status';
				badge.setAttribute('data-status', s.status);
				badge.innerHTML = '<i class="mcp-status-dot" aria-hidden="true"></i>';
				badge.appendChild(document.createTextNode(s.status_label));
				wrap.appendChild(badge);
				if (s.waiting_secs) {
					var w = document.createElement('span');
					w.className = 'mcp-chip mcp-chip--warn';
					w.textContent = C.i18n.waiting + ': ' + duration(s.waiting_secs);
					wrap.appendChild(w);
				}
				if (s.follow_up_at) {
					var f = document.createElement('span');
					f.className = 'mcp-chip' + (s.follow_up_due ? ' mcp-chip--danger' : '');
					f.textContent = s.follow_up_due ? C.i18n.followUpDue : C.i18n.followUp;
					f.title = s.follow_up_at;
					wrap.appendChild(f);
				}
				var body = li.querySelector('.chat-item-body') || li.querySelector('.chat-item-wrap');
				body.appendChild(wrap);
			});
		}).catch(function () { /* badges are optional */ });
	});
}());
