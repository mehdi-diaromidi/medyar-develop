/**
 * Medyar Chat Pro — chat page client.
 *
 * Replaces the Dr Plus theme script (same handle "drplus-chat", same DOM, same drplusChat data)
 * and adds: reply, reactions, waveform audio player, file previews (image lightbox / PDF),
 * upload manager (preview, progress, cancel, retry), offline outbox with idempotent retries,
 * conversation status, patient profile, private notes, quick replies, referral and follow-up.
 */
(function ($) {
	'use strict';

	$(function () {
		if (typeof drplusChat === 'undefined' || typeof mcpChat === 'undefined') {
			return;
		}

		var D = drplusChat;
		var C = mcpChat;
		var T = C.i18n || {};
		var sessionId = parseInt(D.chatID, 10) || 0;
		var userId = parseInt(D.userID, 10) || 0;
		var $root = $('.chat-content').first();
		var $list = $root.find('.chat-messages');

		if (!$root.length || !sessionId) {
			return;
		}
		$root.addClass('mcp-enhanced');

		var chatOpen = D.chatStatus === 'open';
		var otherName = $.trim($root.find('.chat-header-other-user-name').first().text()) || T.otherSide;
		var $composer = $root.find('.chat-send-container');
		var $input = $('#chat-send-input');
		var themeBase = (window.chatAPI && chatAPI.getBaseUrl ? chatAPI.getBaseUrl() : '/wp-json/drplus_chat/v1/').replace(/\/?$/, '/');

		var state = {
			lastId: parseInt(D.lastMessageID, 10) || 0,
			known: {},
			meta: {},
			ctx: null,
			replyTo: null,
			polling: null,
			pollCount: 0,
			fetching: false,
			newCount: 0,
			separatorRemoved: false
		};

		/* ------------------------------------------------------------------
		 * Utilities
		 * ---------------------------------------------------------------- */

		function esc(s) {
			return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		function decodeEntities(s) {
			var t = document.createElement('textarea');
			t.innerHTML = String(s || '');
			return t.value;
		}

		function textToHtml(s) {
			var plain = decodeEntities(String(s || '').replace(/<br\s*\/?>/gi, '\n').replace(/<\/?p>/gi, '\n')).replace(/^\n+|\n+$/g, '');
			return esc(plain).replace(/\r\n|\r|\n/g, '<br>');
		}

		function uid() {
			if (window.crypto && crypto.randomUUID) {
				return crypto.randomUUID().replace(/-/g, '');
			}
			return (Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).slice(0, 32);
		}

		function pad(n) {
			return (n < 10 ? '0' : '') + n;
		}

		function nowHM() {
			var d = new Date();
			return pad(d.getHours()) + ':' + pad(d.getMinutes());
		}

		function hmFrom(value) {
			if (!value) {
				return nowHM();
			}
			if (/^\d{1,2}:\d{2}$/.test(value)) {
				return value;
			}
			var m = String(value).match(/(\d{2}):(\d{2})(:\d{2})?$/);
			return m ? m[1] + ':' + m[2] : nowHM();
		}

		function fmtClock(sec) {
			sec = Math.max(0, Math.floor(sec || 0));
			return pad(Math.floor(sec / 60)) + ':' + pad(sec % 60);
		}

		function fmtBytes(n) {
			n = Number(n) || 0;
			if (n < 1024) {
				return n + ' B';
			}
			if (n < 1048576) {
				return (n / 1024).toFixed(0) + ' KB';
			}
			return (n / 1048576).toFixed(1) + ' MB';
		}

		function fmtDate(value) {
			if (!value) {
				return '—';
			}
			var d = new Date(String(value).replace(' ', 'T'));
			if (isNaN(d.getTime())) {
				return value;
			}
			try {
				return d.toLocaleDateString('fa-IR', { year: 'numeric', month: 'long', day: 'numeric' }) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
			} catch (e) {
				return value;
			}
		}

		function extOf(name) {
			var m = String(name || '').toLowerCase().match(/\.([a-z0-9]+)$/);
			return m ? m[1] : '';
		}

		function kindIcon(kind) {
			return {
				image: 'drplus-icon-document',
				pdf: 'drplus-icon-document-text',
				audio: 'drplus-icon-voice-square',
				video: 'drplus-icon-video',
				archive: 'drplus-icon-archive-book'
			}[kind] || 'drplus-icon-paperclip';
		}

		function kindFromFile(file) {
			var t = file.type || '';
			var e = extOf(file.name);
			if (/^image\/(jpeg|png|gif|webp)$/.test(t) || /^(jpe?g|png|gif|webp)$/.test(e)) {
				return 'image';
			}
			if (t === 'application/pdf' || e === 'pdf') {
				return 'pdf';
			}
			if (/^audio\//.test(t)) {
				return 'audio';
			}
			if (/^video\//.test(t)) {
				return 'video';
			}
			if (/^(zip|rar|gz)$/.test(e)) {
				return 'archive';
			}
			return 'other';
		}

		var ICONS = {
			play: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5.5v13a1 1 0 0 0 1.52.85l10.4-6.5a1 1 0 0 0 0-1.7L9.52 4.65A1 1 0 0 0 8 5.5z" fill="currentColor"/></svg>',
			pause: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="6.5" y="5" width="4" height="14" rx="1.2" fill="currentColor"/><rect x="13.5" y="5" width="4" height="14" rx="1.2" fill="currentColor"/></svg>',
			more: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="5" r="1.8" fill="currentColor"/><circle cx="12" cy="12" r="1.8" fill="currentColor"/><circle cx="12" cy="19" r="1.8" fill="currentColor"/></svg>',
			zoomIn: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M11 8v6M8 11h6M16 16l4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
			zoomOut: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 11h6M16 16l4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
		};

		/* ------------------------------------------------------------------
		 * Toasts & dialogs
		 * ---------------------------------------------------------------- */

		// Theme fullscreen mode hides everything outside the chat section, so overlays live inside it.
		var $layer = $('<div class="mcp-layer"></div>').appendTo($root);
		var $toasts = $('<div class="mcp-toasts" role="status" aria-live="polite"></div>').appendTo($layer);

		function toast(message, type) {
			var $t = $('<div class="mcp-toast"></div>').addClass(type ? 'mcp-toast--' + type : '').text(message).appendTo($toasts);
			setTimeout(function () {
				$t.addClass('is-leaving');
				setTimeout(function () { $t.remove(); }, 250);
			}, 3500);
		}

		var lastFocus = null;

		function openDialog(title, bodyHtml, opts) {
			opts = opts || {};
			closeDialog();
			lastFocus = document.activeElement;
			var id = 'mcp-dlg-' + uid().slice(0, 8);
			var $d = $(
				'<div class="mcp-overlay" data-mcp-dialog>' +
					'<div class="mcp-dialog' + (opts.wide ? ' mcp-dialog--wide' : '') + '" role="dialog" aria-modal="true" aria-labelledby="' + id + '">' +
						'<div class="mcp-dialog-head"><h3 id="' + id + '">' + esc(title) + '</h3>' +
						'<button type="button" class="mcp-icon-btn mcp-dialog-close" aria-label="' + esc(T.close) + '"><i class="drplus-icon-close" aria-hidden="true"></i></button></div>' +
						'<div class="mcp-dialog-body"></div>' +
					'</div>' +
				'</div>'
			);
			$d.find('.mcp-dialog-body').html(bodyHtml);
			$layer.append($d);
			$('body').addClass('mcp-modal-open');
			requestAnimationFrame(function () { $d.addClass('is-open'); });
			$d.on('click', function (e) {
				if (e.target === this) {
					closeDialog();
				}
			});
			$d.find('.mcp-dialog-close').on('click', closeDialog);
			var $focus = $d.find('[autofocus], input, textarea, select, button').filter(':visible').not('.mcp-dialog-close').first();
			setTimeout(function () { ($focus.length ? $focus : $d.find('.mcp-dialog-close')).trigger('focus'); }, 30);
			return $d;
		}

		function closeDialog() {
			var $d = $('[data-mcp-dialog]');
			if (!$d.length) {
				return;
			}
			$d.remove();
			$layer.find('.mcp-pdp').remove();
			$(document).off('.mcppdp');
			if (!$('.mcp-drawer.is-open, .mcp-lightbox').length) {
				$('body').removeClass('mcp-modal-open');
			}
			if (lastFocus && document.body.contains(lastFocus)) {
				lastFocus.focus();
			}
		}

		function trapFocus(e, $scope) {
			if (e.key !== 'Tab') {
				return;
			}
			var $f = $scope.find('a[href], button:not([disabled]), input:not([disabled]), textarea, select, [tabindex]:not([tabindex="-1"])').filter(':visible');
			if (!$f.length) {
				return;
			}
			var first = $f[0];
			var last = $f[$f.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}

		$(document).on('keydown.mcp', function (e) {
			var $dlg = $('[data-mcp-dialog]');
			if ($dlg.length) {
				if (e.key === 'Escape') {
					closeDialog();
				}
				trapFocus(e, $dlg);
				return;
			}
			if ($('.mcp-lightbox').length) {
				return;
			}
			if (e.key === 'Escape') {
				closeMenus();
				if ($('.mcp-drawer.is-open').length) {
					closeDrawer();
				}
			}
		});

		/* ------------------------------------------------------------------
		 * API
		 * ---------------------------------------------------------------- */

		/**
		 * The site CDN swaps every 4xx/5xx body for its own HTML page, so REST calls use
		 * WordPress' `_envelope` mode (always HTTP 200, real status + body inside).
		 */
		function withEnvelope(url) {
			return url + (url.indexOf('?') > -1 ? '&' : '?') + '_envelope=1';
		}

		function unwrap(httpStatus, txt) {
			var json = null;
			try {
				json = txt ? JSON.parse(txt) : null;
			} catch (e) {
				json = null;
			}
			var status = httpStatus;
			var data = json;
			if (json && typeof json === 'object' && 'body' in json && 'status' in json) {
				status = parseInt(json.status, 10) || httpStatus;
				data = json.body;
			}
			if (status < 200 || status >= 300) {
				throw {
					status: status,
					code: (data && data.code) || 'error',
					message: (data && data.message) || (status === 429 ? T.rateLimited : T.networkError)
				};
			}
			return data;
		}

		function request(url, method, body, nonce) {
			var opts = {
				method: method || 'GET',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce }
			};
			if (body !== undefined && method !== 'GET') {
				opts.headers['Content-Type'] = 'application/json';
				opts.body = JSON.stringify(body);
			}
			return fetch(withEnvelope(url), opts).then(function (res) {
				return res.text().then(function (txt) {
					return unwrap(res.status, txt);
				});
			}, function () {
				throw { status: 0, code: 'network_error', message: T.networkError };
			});
		}

		function api(path, method, body) {
			return request(C.restBase + path, method, body, C.nonce);
		}

		function themeApi(path, method, body) {
			return request(themeBase + path, method, body, D.restNonce);
		}

		/* ------------------------------------------------------------------
		 * Layout: emergency notice, status pill, header tools, composer extras
		 * ---------------------------------------------------------------- */

		var $header = $root.find('.chat-header').first();

		if (C.emergency) {
			var emgKey = 'mcp_emg_' + sessionId;
			var collapsed = false;
			try { collapsed = localStorage.getItem(emgKey) === '1'; } catch (e) { /* storage blocked */ }
			var $emg = $(
				'<div class="mcp-emergency" role="note">' +
					'<i class="drplus-icon-warning mcp-emergency-icon" aria-hidden="true"></i>' +
					'<p class="mcp-emergency-text"><strong>' + esc(T.emergencyTitle) + ':</strong> ' + esc(C.emergency) + '</p>' +
					'<button type="button" class="mcp-icon-btn mcp-emergency-toggle" aria-expanded="true" aria-label="' + esc(T.hide) + '"><i class="drplus-icon-arrow-up" aria-hidden="true"></i></button>' +
				'</div>'
			);
			$header.after($emg);
			$root.addClass('mcp-has-notice');
			var setCollapsed = function (v) {
				$emg.toggleClass('is-collapsed', v);
				$emg.find('.mcp-emergency-toggle').attr('aria-expanded', v ? 'false' : 'true')
					.find('i').attr('class', v ? 'drplus-icon-arrow-down' : 'drplus-icon-arrow-up');
				try { localStorage.setItem(emgKey, v ? '1' : '0'); } catch (e) { /* ignore */ }
			};
			setCollapsed(collapsed);
			$emg.on('click', '.mcp-emergency-toggle', function () {
				setCollapsed(!$emg.hasClass('is-collapsed'));
			});
		}

		var $status = $('<span class="mcp-status" hidden></span>');
		var $typing = $('<span class="mcp-typing" role="status" aria-live="polite" hidden></span>');
		$typing.append(document.createTextNode(String(T.typing).replace(/[.…]+$/, '')), '<span class="mcp-typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>');

		var $timing = $('<div class="mcp-timing" role="alert" hidden></div>');
		$header.after($timing);
		var timingBusy = false;
		var timingPromptShown = false;

		function fmtExtendLabel(mins) {
			return String(T.timingExtendMins || '%d دقیقه').replace('%d', String(mins));
		}

		function renderTiming(st) {
			var t = st && st.timing;
			if (!t || !t.enabled) {
				$timing.prop('hidden', true).empty();
				timingPromptShown = false;
				$root.toggleClass('mcp-has-timing', false);
				return;
			}
			// Only reload when the session is actually closed — never on a transient accepting flag after extend.
			if (chatOpen && st.status === 'closed' && st.accepting === false) {
				chatOpen = false;
				window.location.reload();
				return;
			}
			var role = state.ctx && state.ctx.role;
			var html = '';
			var show = false;

			if (role === 'patient' && t.patient_notice_mins > 0) {
				show = true;
				html =
					'<i class="drplus-icon-tick-circle mcp-timing-icon" aria-hidden="true"></i>' +
					'<p class="mcp-timing-text">' + esc(String(T.timingPatientExt || '').replace('%d', String(t.patient_notice_mins))) + '</p>' +
					'<button type="button" class="mcp-btn mcp-btn--small mcp-timing-ack">' + esc(T.timingDismiss) + '</button>';
				$timing.attr('data-kind', 'extended');
			} else if (t.phase === 'warn') {
				if (role === 'patient') {
					show = true;
					html =
						'<i class="drplus-icon-warning mcp-timing-icon" aria-hidden="true"></i>' +
						'<p class="mcp-timing-text">' + esc(T.timingPatientWarn) + '</p>';
					$timing.attr('data-kind', 'warn');
				} else if (role === 'specialist' && t.can_extend && state.ctx && state.ctx.can && state.ctx.can.extend) {
					show = true;
					var opts = (t.extend_options || [5, 10, 15, 20]).map(function (m) {
						return '<button type="button" class="mcp-btn mcp-btn--small mcp-timing-extend" data-mins="' + esc(m) + '">' + esc(fmtExtendLabel(m)) + '</button>';
					}).join('');
					html =
						'<i class="drplus-icon-clock mcp-timing-icon" aria-hidden="true"></i>' +
						'<div class="mcp-timing-body">' +
							'<p class="mcp-timing-text">' + esc(T.timingSpecWarn) + '</p>' +
							'<div class="mcp-timing-actions">' + opts +
								'<button type="button" class="mcp-btn mcp-btn--ghost mcp-btn--small mcp-timing-decline">' + esc(T.timingDecline) + '</button>' +
							'</div>' +
						'</div>';
					$timing.attr('data-kind', 'extend');
					if (!timingPromptShown) {
						timingPromptShown = true;
						try {
							if (navigator.vibrate) {
								navigator.vibrate([80, 40, 80]);
							}
						} catch (e) { /* ignore */ }
					}
				} else if (role === 'specialist' && t.decision === 'declined') {
					show = false;
					timingPromptShown = false;
				}
			} else {
				timingPromptShown = false;
			}

			$timing.html(html).prop('hidden', !show);
			$root.toggleClass('mcp-has-timing', show);
		}

		$timing.on('click', '.mcp-timing-extend', function () {
			if (timingBusy) {
				return;
			}
			var mins = parseInt($(this).attr('data-mins'), 10);
			if (!mins) {
				return;
			}
			timingBusy = true;
			$timing.addClass('is-busy');
			api('sessions/' + sessionId + '/timing/extend', 'POST', { minutes: mins }).then(function (res) {
				timingBusy = false;
				$timing.removeClass('is-busy');
				if (res && res.state) {
					// Keep composer open after a successful extend even if a stale flag arrives.
					if (res.state.timing && res.state.timing.phase !== 'ended' && res.state.status !== 'closed') {
						res.state.accepting = true;
						chatOpen = true;
					}
					renderStatus(res.state);
				}
				toast(String(T.timingExtended || '').replace('%d', String(mins)), 'success');
				poll(true);
			}).catch(function (err) {
				timingBusy = false;
				$timing.removeClass('is-busy');
				toast(err.message || T.actionFailed, 'error');
			});
		});
		$timing.on('click', '.mcp-timing-decline', function () {
			if (timingBusy) {
				return;
			}
			timingBusy = true;
			api('sessions/' + sessionId + '/timing/decline', 'POST', {}).then(function (res) {
				timingBusy = false;
				if (res && res.state) {
					renderStatus(res.state);
				}
			}).catch(function (err) {
				timingBusy = false;
				toast(err.message || T.actionFailed, 'error');
			});
		});
		$timing.on('click', '.mcp-timing-ack', function () {
			api('sessions/' + sessionId + '/timing/ack', 'POST', {}).then(function (res) {
				if (res && res.state) {
					renderStatus(res.state);
				}
			});
		});
		var $info = $header.find('.chat-header-other-user-info');
		var $nameEl = $info.find('.chat-header-other-user-name').first();
		if ($nameEl.length) {
			// Status pill sits on the far (left) side of the user-name row.
			$nameEl.wrap('<div class="mcp-name-row"></div>').after($status);
			$info.append($typing);
		} else {
			$info.append($typing, $status);
		}

		var typingTimer = null;
		function showTyping(on) {
			clearTimeout(typingTimer);
			$typing.prop('hidden', !on);
			$header.toggleClass('mcp-is-typing', !!on);
			if (on) {
				typingTimer = setTimeout(function () { showTyping(false); }, 12000);
			}
		}

		var $extras = $('<div class="mcp-composer-extras"></div>');
		var $tail = $root.find('.chat-send-container, .chat-closed-wrap').first();
		if ($tail.length) {
			$tail.before($extras);
		} else {
			$root.append($extras);
		}

		var $offline = $('<div class="mcp-offline" role="status" hidden><i class="drplus-icon-error" aria-hidden="true"></i><span></span></div>');
		$offline.find('span').text(T.offline);
		$extras.append($offline);
		function updateOnline() {
			$offline.prop('hidden', navigator.onLine !== false);
		}
		window.addEventListener('online', function () {
			updateOnline();
			flushOutbox();
			poll(true);
		});
		window.addEventListener('offline', updateOnline);
		updateOnline();

		function renderStatus(st) {
			if (!st || !st.status) {
				return;
			}
			var canManage = state.ctx && state.ctx.can && state.ctx.can.manage && st.status !== 'closed';
			var html = '<i class="mcp-status-dot" aria-hidden="true"></i><span>' + esc(st.status_label) + '</span>';
			if (st.follow_up_at && canManage) {
				html += '<i class="drplus-icon-clock mcp-status-fu' + (st.follow_up_due ? ' is-due' : '') + '" title="' + esc((st.follow_up_due ? T.followUpDue : T.followUp) + ': ' + fmtDate(st.follow_up_at)) + '" aria-hidden="true"></i>';
			}
			if (canManage) {
				var $btn = $('<button type="button" class="mcp-status mcp-status--btn" aria-haspopup="menu" aria-expanded="false"></button>');
				$btn.attr('aria-label', T.changeStatus + ': ' + st.status_label).attr('data-status', st.status).html(html + '<i class="drplus-icon-arrow-down mcp-status-caret" aria-hidden="true"></i>');
				$status.replaceWith($btn);
				$status = $btn;
			} else {
				var $span = $('<span class="mcp-status"></span>').attr('data-status', st.status).attr('title', T.status).html(html);
				$status.replaceWith($span);
				$status = $span;
			}
			$status.prop('hidden', false);
			renderTiming(st);
		}

		/* ------------------------------------------------------------------
		 * Header menu (theme dropdown) + specialist tools
		 * ---------------------------------------------------------------- */

		var $actionWrap = $header.find('.chat-header-action-wrap');
		var $actionIcon = $actionWrap.find('.chat-header-action-icon');
		var $actionList = $actionWrap.find('.chat-header-action-list');

		$actionIcon.attr({ 'aria-haspopup': 'menu', 'aria-expanded': 'false' });
		$actionIcon.on('click keydown', function (e) {
			if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
				return;
			}
			e.preventDefault();
			var open = !$actionWrap.hasClass('open');
			closeMenus();
			$actionWrap.toggleClass('open', open);
			$actionIcon.attr('aria-expanded', open ? 'true' : 'false');
			if (open) {
				$actionList.find('a, button').first().trigger('focus');
			}
		});

		function closeMenus() {
			$actionWrap.removeClass('open');
			$actionIcon.attr('aria-expanded', 'false');
			$('.mcp-popover').remove();
			$('.mcp-status--btn').attr('aria-expanded', 'false');
		}

		$(document).on('click.mcp', function (e) {
			if (!$(e.target).closest('.chat-header-action-wrap, .mcp-popover, .mcp-status--btn, .mcp-msg-more').length) {
				closeMenus();
			}
		});

		$actionList.on('click', '.chat-close-session', function (e) {
			if (!window.confirm(T.closeConfirm)) {
				e.preventDefault();
			}
		});

		function addMenuItem(icon, label, handler) {
			var $item = $('<button type="button" class="chat-header-action-item mcp-menu-item" role="menuitem"></button>');
			$item.html('<i class="' + icon + ' chat-header-action-item-icon" aria-hidden="true"></i>' + esc(label));
			$item.on('click', function () {
				closeMenus();
				handler();
			});
			var $close = $actionList.find('.chat-close-session');
			if ($close.length) {
				$close.before($item);
			} else {
				$actionList.append($item);
			}
		}

		function addHeaderTool(icon, label, handler) {
			var $b = $('<button type="button" class="mcp-icon-btn mcp-header-tool"></button>').attr({ 'aria-label': label, title: label });
			$b.html('<i class="' + icon + '" aria-hidden="true"></i>').on('click', handler);
			$actionIcon.before($b);
		}

		function setupRoleTools(ctx) {
			var can = ctx.can || {};
			if (can.profile) {
				addHeaderTool('drplus-icon-personalcard', T.patientProfile, function () { openDrawer('profile'); });
				addMenuItem('drplus-icon-personalcard', T.patientProfile, function () { openDrawer('profile'); });
			}
			if (can.notes) {
				addMenuItem('drplus-icon-edit', T.privateNotes, function () { openDrawer('notes'); });
			}
			if (can.refer && chatOpen) {
				addMenuItem('drplus-icon-routing', T.refer, openReferral);
			}
			if (can.manage) {
				addMenuItem('drplus-icon-clock', T.followUpSet, openFollowUp);
			}
			if (can.quick) {
				addMenuItem('drplus-icon-flash', T.manageQuick, openQuickManager);
				if (chatOpen) {
					setupQuickReplies(ctx.quick_replies || []);
				}
			}
		}

		$header.on('click', '.mcp-status--btn', function (e) {
			e.stopPropagation();
			var $btn = $(this);
			if ($('.mcp-popover--status').length) {
				closeMenus();
				return;
			}
			closeMenus();
			var current = $btn.attr('data-status');
			var items = '';
			$.each(state.ctx.statuses || {}, function (key, label) {
				items += '<button type="button" role="menuitemradio" class="mcp-popover-item" data-status="' + esc(key) + '" aria-checked="' + (key === current ? 'true' : 'false') + '">' +
					'<i class="mcp-status-dot" data-status="' + esc(key) + '" aria-hidden="true"></i>' + esc(label) + '</button>';
			});
			var $pop = $('<div class="mcp-popover mcp-popover--status" role="menu"></div>').attr('aria-label', T.changeStatus).html(items);
			positionPopover($pop, $btn);
			$btn.attr('aria-expanded', 'true');
			$pop.find('[aria-checked="true"]').trigger('focus');
		});

		$(document).on('click', '.mcp-popover--status .mcp-popover-item', function () {
			var next = $(this).data('status');
			closeMenus();
			if (next === 'closed' && !window.confirm(T.closeConfirm)) {
				return;
			}
			api('sessions/' + sessionId + '/status', 'POST', { status: next }).then(function (res) {
				renderStatus(res.state);
				toast(T.saved, 'success');
				if (next === 'closed' || (res.state && res.state.accepting !== chatOpen)) {
					window.location.reload();
				}
			}).catch(function (err) {
				toast(err.message || T.actionFailed, 'error');
			});
		});

		function positionPopover($pop, $anchor) {
			$pop.appendTo($layer);
			var r = $anchor[0].getBoundingClientRect();
			var w = $pop.outerWidth();
			var h = $pop.outerHeight();
			var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
			var top = r.bottom + 6;
			if (top + h > window.innerHeight - 8) {
				top = Math.max(8, r.top - h - 6);
			}
			$pop.css({ left: left + 'px', top: top + 'px' });
		}

		/* ------------------------------------------------------------------
		 * Message rendering & enhancement
		 * ---------------------------------------------------------------- */

		function messageType($el) {
			if ($el.find('.mcp-audio, .mcp-audio-slot, audio.chat-message-audio').length) {
				return 'voice';
			}
			if ($el.find('.chat-message-file, .mcp-file').length) {
				return 'file';
			}
			return 'text';
		}

		function buildMessage(m, opts) {
			opts = opts || {};
			var mine = parseInt(m.sender_id, 10) === userId;
			var type = m.type === 'voice' ? 'voice' : (m.type === 'text' ? 'text' : 'file');
			var body;
			if (type === 'text') {
				body = '<div class="chat-message-text" role="document">' + (opts.rawHtml ? m.message : textToHtml(m.message)) + '</div>';
			} else if (type === 'voice') {
				body = '<div class="mcp-audio-slot"></div>';
			} else {
				body = '<div class="chat-message-file" role="group"><i class="drplus-icon-paperclip chat-message-file-icon" aria-hidden="true"></i><span class="chat-message-file-name line-clamp line-clamp-1">' + esc(m.message) + '</span></div>';
			}
			var $el = $(
				'<div class="chat-message ' + (mine ? 'current-user-message' : 'other-user-message') + '" role="listitem">' +
					'<div class="chat-message-content">' + body + '<div class="chat-message-date" role="note">' + esc(hmFrom(m.created_at)) + '</div></div>' +
				'</div>'
			);
			$el.attr('data-message-id', m.id);
			$el.attr('aria-label', mine ? T.you : T.otherSide);
			return $el;
		}

		var URL_RE = /(\bhttps?:\/\/[^\s<>"']+|\bwww\.[^\s<>"']+)/gi;

		/** Turns plain-text URLs inside a message into safe links (text nodes only, never innerHTML). */
		function linkify(root) {
			if (!root) {
				return;
			}
			var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
				acceptNode: function (node) {
					return $(node.parentNode).closest('a, .mcp-quote').length ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
				}
			});
			var nodes = [];
			while (walker.nextNode()) {
				URL_RE.lastIndex = 0;
				if (URL_RE.test(walker.currentNode.nodeValue)) {
					nodes.push(walker.currentNode);
				}
			}
			nodes.forEach(function (node) {
				var text = node.nodeValue;
				var frag = document.createDocumentFragment();
				var last = 0;
				text.replace(URL_RE, function (match, _g, offset) {
					var url = match.replace(/[.,!?؛،:;)\]}»]+$/, '');
					var href = /^www\./i.test(url) ? 'https://' + url : url;
					frag.appendChild(document.createTextNode(text.slice(last, offset)));
					var a = document.createElement('a');
					a.href = href;
					a.textContent = url;
					a.target = '_blank';
					a.rel = 'noopener noreferrer nofollow ugc';
					a.className = 'mcp-link';
					a.dir = 'ltr';
					frag.appendChild(a);
					last = offset + url.length;
					return match;
				});
				frag.appendChild(document.createTextNode(text.slice(last)));
				node.parentNode.replaceChild(frag, node);
			});
		}

		function enhance($el, meta, local) {
			var id = parseInt($el.attr('data-message-id'), 10);
			meta = meta || {};
			local = local || {};
			linkify($el.find('.chat-message-text')[0]);
			if (id) {
				state.meta[id] = meta;
				state.known[id] = true;
			}
			var $content = $el.children('.chat-message-content');
			var type = messageType($el);

			$el.attr('data-mcp-type', type);

			// Reply quote.
			$content.children('.mcp-quote').remove();
			if (meta.reply_to) {
				var q = meta.reply_to;
				var who = parseInt(q.sender_id, 10) === userId ? T.you : otherName;
				$content.prepend(
					'<button type="button" class="mcp-quote" data-target="' + esc(q.id) + '" aria-label="' + esc(T.replyingTo + ' ' + who) + '">' +
						'<span class="mcp-quote-who">' + esc(who) + '</span><span class="mcp-quote-text">' + esc(q.text) + '</span></button>'
				);
			}

			// Referral card.
			if (meta.kind === 'referral' && meta.referral && !$content.find('.mcp-referral').length) {
				var r = meta.referral;
				var card = '<div class="mcp-referral">' +
					'<div class="mcp-referral-head"><i class="drplus-icon-routing" aria-hidden="true"></i>' + esc(T.referralCard) + '</div>' +
					'<div class="mcp-referral-body"><img src="' + esc(r.avatar) + '" alt="" loading="lazy">' +
					'<div><strong>' + esc(r.name) + '</strong><span>' + esc(r.subtitle) + '</span></div></div>' +
					(r.reason ? '<p class="mcp-referral-reason">' + esc(r.reason) + '</p>' : '') +
					(r.url ? '<a class="mcp-btn mcp-btn--small" href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(T.viewProfile) + '</a>' : '') +
					'</div>';
				$content.find('.chat-message-text').addClass('mcp-visually-hidden').after(card);
			}

			// File preview.
			if (type === 'file' && meta.file && !$el.find('.mcp-file').length) {
				$content.find('.chat-message-file').replaceWith(fileView(id, meta.file));
				if (local.url && meta.file.kind === 'image') {
					// Just-sent image: keep the in-memory copy instead of downloading it again.
					$content.find('.mcp-image-btn img').attr('src', local.url);
				}
			}

			// Voice player.
			if (type === 'voice' && !$el.find('.mcp-audio').length) {
				var src = local.url || (meta.file && meta.file.url);
				var $legacy = $el.find('audio.chat-message-audio');
				if (!src && $legacy.length) {
					src = $legacy.find('source').attr('src') || $legacy.attr('src');
				}
				if (src) {
					var knownDur = (local.duration > 0 ? local.duration : 0) || (meta.file && meta.file.duration > 0 ? meta.file.duration : 0);
					var $player = audioPlayer(src, meta.file ? meta.file.size : 0, knownDur);
					if ($legacy.length) {
						$legacy.replaceWith($player);
					} else {
						$el.find('.mcp-audio-slot').replaceWith($player);
					}
				}
			}

			renderReactions($el, meta.reactions || []);

			if (!$el.children('.mcp-msg-more').length && id) {
				$('<button type="button" class="mcp-msg-more" aria-haspopup="menu"></button>')
					.attr('aria-label', (C.reactions && C.reactions.length ? T.react + ' / ' : '') + T.reply)
					.html(ICONS.more)
					.appendTo($el);
			}
		}

		function renderReactions($el, reactions) {
			var $content = $el.children('.chat-message-content');
			$content.children('.mcp-reactions').remove();
			if (!reactions || !reactions.length || !(C.reactions && C.reactions.length)) {
				return;
			}
			var html = '';
			reactions.forEach(function (r) {
				html += '<button type="button" class="mcp-reaction' + (r.mine ? ' is-mine' : '') + '" data-r="' + esc(r.r) + '" aria-pressed="' + (r.mine ? 'true' : 'false') + '">' +
					'<span aria-hidden="true">' + esc(r.r) + '</span>' + (r.count > 1 ? '<span class="mcp-reaction-count">' + r.count + '</span>' : '') +
					'<span class="mcp-visually-hidden">' + esc(r.r + ' ' + r.count) + '</span></button>';
			});
			$('<div class="mcp-reactions"></div>').html(html).insertBefore($content.children('.chat-message-date'));
		}

		function fileView(id, f) {
			if (f.missing) {
				return '<div class="mcp-file mcp-file--missing"><i class="drplus-icon-error mcp-file-icon" aria-hidden="true"></i><div class="mcp-file-info"><span class="mcp-file-name">' + esc(f.name) + '</span><span class="mcp-file-sub">' + esc(T.fileMissing) + '</span></div></div>';
			}
			if (f.kind === 'image') {
				return '<div class="mcp-file mcp-file--image">' +
					'<button type="button" class="mcp-image-btn" data-full="' + esc(f.url) + '" data-name="' + esc(f.name) + '" data-download="' + esc(f.download) + '" aria-label="' + esc(T.preview + ': ' + f.name) + '">' +
					'<img src="' + esc(f.url) + '" alt="' + esc(f.name) + '" loading="lazy" decoding="async"></button></div>';
			}
			if (f.kind === 'video') {
				return '<div class="mcp-file mcp-file--video"><video controls preload="metadata" playsinline src="' + esc(f.url) + '"></video>' +
					'<div class="mcp-file-bar"><span class="mcp-file-name">' + esc(f.name) + '</span><a class="mcp-icon-btn" href="' + esc(f.download) + '" aria-label="' + esc(T.download) + '"><i class="drplus-icon-download" aria-hidden="true"></i></a></div></div>';
			}
			var sub = fmtBytes(f.size) + ' · ' + esc((f.ext || '').toUpperCase());
			var actions = '';
			if (f.kind === 'pdf') {
				actions += '<button type="button" class="mcp-icon-btn mcp-pdf-btn" data-url="' + esc(f.url) + '" data-name="' + esc(f.name) + '" data-download="' + esc(f.download) + '" aria-label="' + esc(T.preview) + '" title="' + esc(T.preview) + '"><i class="drplus-icon-eye" aria-hidden="true"></i></button>';
			}
			actions += '<a class="mcp-icon-btn" href="' + esc(f.download) + '" aria-label="' + esc(T.download + ': ' + f.name) + '" title="' + esc(T.download) + '"><i class="drplus-icon-download" aria-hidden="true"></i></a>';
			return '<div class="mcp-file mcp-file--' + esc(f.kind) + '">' +
				'<i class="' + kindIcon(f.kind) + ' mcp-file-icon" aria-hidden="true"></i>' +
				'<div class="mcp-file-info"><span class="mcp-file-name" title="' + esc(f.name) + '">' + esc(f.name) + '</span><span class="mcp-file-sub">' + sub + '</span></div>' +
				'<div class="mcp-file-actions">' + actions + '</div></div>';
		}

		function appendMessage(m, scrollDown) {
			var id = parseInt(m.id, 10);
			if (state.known[id]) {
				return;
			}
			if (parseInt(m.sender_id, 10) === userId && m.type === 'text') {
				var target = decodeEntities(String(m.message || '').replace(/<br\s*\/?>/gi, '\n')).trim();
				var $pending = $list.children('.mcp-pending').filter(function () {
					return $(this).data('mcpText') === target;
				}).first();
				if ($pending.length) {
					settlePending($pending, m);
					return;
				}
			}
			var $el = buildMessage(m);
			var $firstPending = $list.children('.mcp-pending, .mcp-upload').first();
			if ($firstPending.length) {
				$firstPending.before($el);
			} else {
				$list.append($el);
			}
			enhance($el, m.mcp || {});
			state.lastId = Math.max(state.lastId, id);
		}

		/* ------------------------------------------------------------------
		 * Message actions: reply, react, copy
		 * ---------------------------------------------------------------- */

		$list.on('click', '.mcp-msg-more', function (e) {
			e.stopPropagation();
			openMessageMenu($(this).closest('.chat-message'), $(this));
		});

		var pressTimer = null;
		$list.on('touchstart', '.chat-message-content', function (e) {
			var $msg = $(this).closest('.chat-message');
			if ($(e.target).closest('button, a, video, .mcp-audio').length) {
				return;
			}
			pressTimer = setTimeout(function () {
				pressTimer = null;
				if (navigator.vibrate) {
					navigator.vibrate(15);
				}
				openMessageMenu($msg, $msg.children('.chat-message-content'));
			}, 450);
		});
		$list.on('touchend touchmove touchcancel', '.chat-message-content', function () {
			if (pressTimer) {
				clearTimeout(pressTimer);
				pressTimer = null;
			}
		});

		function openMessageMenu($msg, $anchor) {
			closeMenus();
			var id = parseInt($msg.attr('data-message-id'), 10);
			if (!id || $msg.hasClass('mcp-pending')) {
				return;
			}
			var can = (state.ctx && state.ctx.can) || {};
			var html = '';
			if (can.react && chatOpen && C.reactions && C.reactions.length) {
				var mine = ((state.meta[id] || {}).reactions || []).filter(function (r) { return r.mine; }).map(function (r) { return r.r; });
				html += '<div class="mcp-react-row" role="group" aria-label="' + esc(T.react) + '">';
				C.reactions.forEach(function (r) {
					html += '<button type="button" class="mcp-react-pick' + (mine.indexOf(r) > -1 ? ' is-mine' : '') + '" data-r="' + esc(r) + '" aria-pressed="' + (mine.indexOf(r) > -1 ? 'true' : 'false') + '">' + esc(r) + '</button>';
				});
				html += '</div>';
			}
			if (chatOpen && $composer.length) {
				html += '<button type="button" class="mcp-popover-item" data-act="reply" role="menuitem"><i class="drplus-icon-undo" aria-hidden="true"></i>' + esc(T.reply) + '</button>';
			}
			if ($msg.attr('data-mcp-type') === 'text') {
				html += '<button type="button" class="mcp-popover-item" data-act="copy" role="menuitem"><i class="drplus-icon-copy" aria-hidden="true"></i>' + esc(T.copy) + '</button>';
			}
			if (!html) {
				return;
			}
			var $pop = $('<div class="mcp-popover mcp-popover--msg" role="menu"></div>').html(html).data('msg', id);
			positionPopover($pop, $anchor);
			$pop.find('button').first().trigger('focus');
		}

		$(document).on('click', '.mcp-popover--msg .mcp-react-pick', function () {
			var id = $(this).closest('.mcp-popover').data('msg');
			var r = String($(this).data('r'));
			closeMenus();
			react(id, r);
		});

		$list.on('click', '.mcp-reaction', function () {
			if (!chatOpen || !(state.ctx && state.ctx.can && state.ctx.can.react)) {
				return;
			}
			react(parseInt($(this).closest('.chat-message').attr('data-message-id'), 10), String($(this).data('r')));
		});

		function react(id, r) {
			var $msg = $list.children('.chat-message[data-message-id="' + id + '"]');
			var meta = state.meta[id] || {};
			var prev = (meta.reactions || []).slice();
			var mine = prev.filter(function (x) { return x.mine; }).map(function (x) { return x.r; })[0];
			var next = [];
			prev.forEach(function (x) {
				var c = { r: x.r, count: x.count, mine: false };
				if (x.mine) {
					c.count--;
				}
				if (c.count > 0) {
					next.push(c);
				}
			});
			if (mine !== r) {
				var hit = next.filter(function (x) { return x.r === r; })[0];
				if (hit) {
					hit.count++;
					hit.mine = true;
				} else {
					next.push({ r: r, count: 1, mine: true });
				}
			}
			meta.reactions = next;
			state.meta[id] = meta;
			renderReactions($msg, next);
			api('messages/' + id + '/reactions', 'POST', { reaction: r }).then(function (res) {
				meta.reactions = res.reactions || [];
				renderReactions($msg, meta.reactions);
			}).catch(function (err) {
				meta.reactions = prev;
				renderReactions($msg, prev);
				toast(err.message || T.actionFailed, 'error');
			});
		}

		$(document).on('click', '.mcp-popover--msg [data-act]', function () {
			var id = $(this).closest('.mcp-popover').data('msg');
			var act = $(this).data('act');
			closeMenus();
			var $msg = $list.children('.chat-message[data-message-id="' + id + '"]');
			if (act === 'reply') {
				startReply($msg);
			} else if (act === 'copy') {
				var text = $msg.find('.chat-message-text').first().text().trim();
				if (navigator.clipboard) {
					navigator.clipboard.writeText(text).then(function () { toast(T.copied); });
				}
			}
		});

		$list.on('click', '.mcp-quote', function () {
			var $target = $list.children('.chat-message[data-message-id="' + $(this).data('target') + '"]');
			if ($target.length) {
				$target[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
				$target.addClass('mcp-flash');
				setTimeout(function () { $target.removeClass('mcp-flash'); }, 1600);
			}
		});

		var $replyBar = $('<div class="mcp-reply-bar" hidden><i class="drplus-icon-undo" aria-hidden="true"></i><div class="mcp-reply-body"><span class="mcp-reply-who"></span><span class="mcp-reply-text"></span></div><button type="button" class="mcp-icon-btn mcp-reply-cancel"><i class="drplus-icon-close" aria-hidden="true"></i></button></div>');
		$replyBar.find('.mcp-reply-cancel').attr('aria-label', T.cancelReply);
		$extras.append($replyBar);

		function startReply($msg) {
			var id = parseInt($msg.attr('data-message-id'), 10);
			var type = $msg.attr('data-mcp-type');
			var text = type === 'voice' ? T.voice : (type === 'file' ? '📎 ' + ($msg.find('.mcp-file-name, .chat-message-file-name, img').first().attr('alt') || $msg.find('.mcp-file-name, .chat-message-file-name').first().text()) : $msg.find('.chat-message-text').first().text().trim());
			var who = $msg.hasClass('current-user-message') ? T.you : otherName;
			state.replyTo = { id: id, text: text.slice(0, 140), who: who, sender: $msg.hasClass('current-user-message') ? userId : 0 };
			$replyBar.find('.mcp-reply-who').text(T.replyingTo + ' ' + who);
			$replyBar.find('.mcp-reply-text').text(state.replyTo.text);
			$replyBar.prop('hidden', false);
			$input.trigger('focus');
		}

		function cancelReply() {
			state.replyTo = null;
			$replyBar.prop('hidden', true);
		}
		$replyBar.on('click', '.mcp-reply-cancel', cancelReply);

		/* ------------------------------------------------------------------
		 * Audio player (waveform, speed, seek)
		 * ---------------------------------------------------------------- */

		var BARS = 40;
		var playing = null;
		var audioCtx = null;

		function audioPlayer(src, size, knownDuration) {
			var bars = '';
			for (var i = 0; i < BARS; i++) {
				bars += '<span class="mcp-bar" style="--h:' + (30 + ((i * 37) % 50)) + '%"></span>';
			}
			var $p = $(
				'<div class="mcp-audio" dir="ltr">' +
					'<button type="button" class="mcp-audio-play">' + ICONS.play + '</button>' +
					'<div class="mcp-audio-main">' +
						'<div class="mcp-wave is-loading" role="slider" tabindex="0" aria-valuemin="0" aria-valuemax="0" aria-valuenow="0">' + bars + '</div>' +
						'<div class="mcp-audio-meta"><span class="mcp-audio-time">00:00</span>' +
						'<button type="button" class="mcp-audio-speed">1x</button></div>' +
					'</div>' +
				'</div>'
			);
			$p.find('.mcp-audio-play').attr('aria-label', T.play);
			$p.find('.mcp-wave').attr('aria-label', T.seek);
			$p.find('.mcp-audio-speed').attr('aria-label', T.speed + ': 1x');

			var audio = new Audio();
			audio.preload = 'metadata';
			audio.src = src;
			var duration = 0;
			// MediaRecorder WebM often lacks duration metadata. Chrome then reports Infinity
			// and grows the value second-by-second while playing — lock once we know it.
			var durationLocked = false;
			var $bars = $p.find('.mcp-bar');
			var $wave = $p.find('.mcp-wave');
			var $time = $p.find('.mcp-audio-time');
			var $play = $p.find('.mcp-audio-play');

			function setDuration(d, lock) {
				if (!isFinite(d) || d <= 0) {
					return;
				}
				if (durationLocked && !lock) {
					return;
				}
				duration = d;
				if (lock) {
					durationLocked = true;
				}
				$wave.attr('aria-valuemax', Math.round(d));
				if (audio.paused && !audio.currentTime) {
					$time.text(fmtClock(d));
				}
			}

			function paint() {
				var ratio = duration ? audio.currentTime / duration : 0;
				var upto = Math.round(ratio * BARS);
				$bars.each(function (idx) {
					this.classList.toggle('is-played', idx < upto);
				});
				var cur = fmtClock(audio.currentTime);
				var total = duration ? fmtClock(duration) : '--:--';
				$wave.attr({ 'aria-valuenow': Math.round(audio.currentTime), 'aria-valuetext': cur + ' / ' + total });
				$time.text(audio.paused && !audio.currentTime ? (duration ? total : '00:00') : cur + ' / ' + total);
			}

			if (knownDuration > 0) {
				setDuration(knownDuration, true);
				paint();
			}

			audio.addEventListener('loadedmetadata', function () {
				// Do NOT seek to 1e101 — that Chrome Infinity hack causes the growing seconds UI.
				if (isFinite(audio.duration) && audio.duration > 0) {
					setDuration(audio.duration);
					paint();
				}
			});
			audio.addEventListener('durationchange', function () {
				// Chrome WebM without Duration metadata grows audio.duration during play — ignore those.
				if (durationLocked || !isFinite(audio.duration) || audio.duration <= 0) {
					return;
				}
				if (!audio.paused || audio.currentTime > 0) {
					return;
				}
				setDuration(audio.duration);
				paint();
			});
			audio.addEventListener('timeupdate', paint);
			audio.addEventListener('play', function () {
				if (playing && playing !== audio) {
					playing.pause();
				}
				playing = audio;
				$p.addClass('is-playing');
				$play.html(ICONS.pause).attr('aria-label', T.pause);
			});
			audio.addEventListener('pause', function () {
				$p.removeClass('is-playing');
				$play.html(ICONS.play).attr('aria-label', T.play);
			});
			audio.addEventListener('ended', function () {
				audio.currentTime = 0;
				paint();
			});
			audio.addEventListener('error', function () {
				$p.addClass('is-error');
				$time.text(T.audioError);
			});

			$play.on('click', function () {
				if (audio.paused) {
					audio.play().catch(function () {
						$p.addClass('is-error');
						$time.text(T.audioError);
					});
				} else {
					audio.pause();
				}
			});

			var speeds = [1, 1.5, 2];
			$p.find('.mcp-audio-speed').on('click', function () {
				var next = speeds[(speeds.indexOf(audio.playbackRate) + 1) % speeds.length] || 1;
				audio.playbackRate = next;
				$(this).text(next + 'x').attr('aria-label', T.speed + ': ' + next + 'x');
			});

			function seekTo(clientX) {
				var r = $wave[0].getBoundingClientRect();
				var ratio = Math.min(1, Math.max(0, (clientX - r.left) / r.width));
				if (duration) {
					audio.currentTime = ratio * duration;
					paint();
				}
			}
			var dragging = false;
			$wave.on('pointerdown', function (e) {
				dragging = true;
				this.setPointerCapture && this.setPointerCapture(e.pointerId);
				seekTo(e.clientX);
			}).on('pointermove', function (e) {
				if (dragging) {
					seekTo(e.clientX);
				}
			}).on('pointerup pointercancel', function () {
				dragging = false;
			}).on('keydown', function (e) {
				var step = { ArrowRight: 5, ArrowLeft: -5, ArrowUp: 5, ArrowDown: -5 }[e.key];
				if (step && duration) {
					e.preventDefault();
					audio.currentTime = Math.min(duration, Math.max(0, audio.currentTime + step));
					paint();
				} else if (e.key === 'Home' || e.key === 'End') {
					e.preventDefault();
					audio.currentTime = e.key === 'Home' ? 0 : Math.max(0, duration - 0.1);
					paint();
				} else if (e.key === ' ' || e.key === 'Enter') {
					e.preventDefault();
					$play.trigger('click');
				}
			});

			// Real waveform + authoritative duration via decodeAudioData (fixes WebM missing Duration).
			var waveStarted = false;
			var drawWave = function () {
				if (waveStarted) {
					return;
				}
				waveStarted = true;
				if (size && size > 6 * 1048576) {
					$wave.removeClass('is-loading');
					return;
				}
				var Ctx = window.AudioContext || window.webkitAudioContext;
				if (!Ctx || !window.fetch) {
					$wave.removeClass('is-loading');
					return;
				}
				fetch(src, { credentials: 'same-origin' }).then(function (r) {
					if (!r.ok) {
						throw new Error('http');
					}
					return r.arrayBuffer();
				}).then(function (buf) {
					audioCtx = audioCtx || new Ctx();
					return new Promise(function (resolve, reject) {
						var pr = audioCtx.decodeAudioData(buf, resolve, reject);
						if (pr && pr.then) {
							pr.then(resolve, reject);
						}
					});
				}).then(function (decoded) {
					setDuration(decoded.duration, true);
					var data = decoded.getChannelData(0);
					var block = Math.floor(data.length / BARS) || 1;
					var peaks = [];
					var max = 0;
					for (var b = 0; b < BARS; b++) {
						var sum = 0;
						for (var j = 0; j < block; j++) {
							sum += Math.abs(data[b * block + j] || 0);
						}
						peaks.push(sum / block);
						max = Math.max(max, sum / block);
					}
					$bars.each(function (idx) {
						var h = max ? Math.max(12, Math.round((peaks[idx] / max) * 100)) : 30;
						this.style.setProperty('--h', h + '%');
					});
					paint();
				}).catch(function () { /* keep placeholder bars */ }).then(function () {
					$wave.removeClass('is-loading');
				});
			};
			// Decode ASAP so WebM duration locks before the user hits play.
			if ('IntersectionObserver' in window) {
				var io = new IntersectionObserver(function (entries) {
					if (entries[0].isIntersecting) {
						io.disconnect();
						drawWave();
					}
				}, { rootMargin: '200px' });
				setTimeout(function () { io.observe($p[0]); }, 0);
			} else {
				drawWave();
			}
			if (!knownDuration) {
				setTimeout(drawWave, 0);
			}

			return $p;
		}

		/* ------------------------------------------------------------------
		 * Lightbox (images) and PDF viewer
		 * ---------------------------------------------------------------- */

		function openViewer(kind, url, name, download) {
			closeMenus();
			lastFocus = document.activeElement;
			var $lb = $(
				'<div class="mcp-lightbox" role="dialog" aria-modal="true">' +
					'<div class="mcp-lb-bar">' +
						'<span class="mcp-lb-name"></span>' +
						(kind === 'image' ? '<button type="button" class="mcp-icon-btn mcp-lb-out">' + ICONS.zoomOut + '</button><button type="button" class="mcp-icon-btn mcp-lb-in">' + ICONS.zoomIn + '</button>' : '') +
						'<a class="mcp-icon-btn mcp-lb-dl"><i class="drplus-icon-download" aria-hidden="true"></i></a>' +
						'<button type="button" class="mcp-icon-btn mcp-lb-close"><i class="drplus-icon-close" aria-hidden="true"></i></button>' +
					'</div>' +
					'<div class="mcp-lb-stage"></div>' +
				'</div>'
			);
			$lb.attr('aria-label', name);
			$lb.find('.mcp-lb-name').text(name);
			$lb.find('.mcp-lb-dl').attr({ href: download, 'aria-label': T.download, title: T.download });
			$lb.find('.mcp-lb-close').attr({ 'aria-label': T.close, title: T.close });
			$lb.find('.mcp-lb-in').attr({ 'aria-label': T.zoomIn, title: T.zoomIn });
			$lb.find('.mcp-lb-out').attr({ 'aria-label': T.zoomOut, title: T.zoomOut });
			var $stage = $lb.find('.mcp-lb-stage');

			var close = function () {
				$lb.remove();
				$(document).off('keydown.mcplb');
				if (!$('.mcp-drawer.is-open, [data-mcp-dialog]').length) {
					$('body').removeClass('mcp-modal-open');
				}
				if (lastFocus && document.body.contains(lastFocus)) {
					lastFocus.focus();
				}
			};

			if (kind === 'pdf') {
				$stage.addClass('mcp-lb-stage--pdf').append($('<iframe></iframe>').attr({ src: url, title: name }));
			} else {
				var $img = $('<img alt="">').attr({ src: url, alt: name, draggable: 'false' });
				$stage.append($img);
				var scale = 1;
				var tx = 0;
				var ty = 0;
				var apply = function () {
					$img.css('transform', 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')');
					$stage.toggleClass('is-zoomed', scale > 1);
				};
				var zoom = function (f) {
					scale = Math.min(5, Math.max(1, scale * f));
					if (scale === 1) {
						tx = 0;
						ty = 0;
					}
					apply();
				};
				$lb.find('.mcp-lb-in').on('click', function () { zoom(1.5); });
				$lb.find('.mcp-lb-out').on('click', function () { zoom(1 / 1.5); });
				$img.on('dblclick', function () {
					if (scale > 1) {
						scale = 1;
						tx = 0;
						ty = 0;
						apply();
					} else {
						zoom(2.5);
					}
				});
				$stage.on('wheel', function (e) {
					e.preventDefault();
					zoom(e.originalEvent.deltaY < 0 ? 1.15 : 1 / 1.15);
				});
				var pointers = {};
				var startDist = 0;
				var startScale = 1;
				var last = null;
				$stage.on('pointerdown', function (e) {
					pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
					this.setPointerCapture && this.setPointerCapture(e.pointerId);
					var ids = Object.keys(pointers);
					if (ids.length === 2) {
						var a = pointers[ids[0]];
						var b = pointers[ids[1]];
						startDist = Math.hypot(a.x - b.x, a.y - b.y);
						startScale = scale;
					}
					last = { x: e.clientX, y: e.clientY };
				}).on('pointermove', function (e) {
					if (!pointers[e.pointerId]) {
						return;
					}
					pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
					var ids = Object.keys(pointers);
					if (ids.length === 2 && startDist) {
						var a = pointers[ids[0]];
						var b = pointers[ids[1]];
						scale = Math.min(5, Math.max(1, startScale * Math.hypot(a.x - b.x, a.y - b.y) / startDist));
						apply();
					} else if (scale > 1 && last) {
						tx += e.clientX - last.x;
						ty += e.clientY - last.y;
						last = { x: e.clientX, y: e.clientY };
						apply();
					}
				}).on('pointerup pointercancel', function (e) {
					delete pointers[e.pointerId];
					startDist = 0;
					last = null;
				});
			}

			$lb.on('click', function (e) {
				if (e.target === this || $(e.target).is('.mcp-lb-stage')) {
					close();
				}
			});
			$lb.find('.mcp-lb-close').on('click', close);
			$(document).on('keydown.mcplb', function (e) {
				if (e.key === 'Escape') {
					close();
				}
				trapFocus(e, $lb);
			});
			$layer.append($lb);
			$('body').addClass('mcp-modal-open');
			$lb.find('.mcp-lb-close').trigger('focus');
		}

		$list.on('click', '.mcp-image-btn', function () {
			openViewer('image', $(this).data('full'), $(this).data('name'), $(this).data('download'));
		});

		$list.on('click', '.mcp-pdf-btn', function () {
			var url = $(this).data('url');
			if (navigator.pdfViewerEnabled === false) {
				window.open(url, '_blank', 'noopener');
				return;
			}
			openViewer('pdf', url, $(this).data('name'), $(this).data('download'));
		});

		// Messages rendered by the theme before meta arrives still open through the theme file route.
		$list.on('click', '.chat-message-file', function (e) {
			var url = $(this).data('url');
			if (!url) {
				return;
			}
			e.preventDefault();
			window.open(D.siteUrl + '?chat_file=' + encodeURIComponent(url), '_blank', 'noopener');
		});

		/* ------------------------------------------------------------------
		 * Sending text: outbox + idempotent retries
		 * ---------------------------------------------------------------- */

		var outboxKey = 'mcp_outbox_' + sessionId + '_' + userId;

		function loadOutbox() {
			try {
				return JSON.parse(localStorage.getItem(outboxKey) || '[]') || [];
			} catch (e) {
				return [];
			}
		}

		function saveOutbox(items) {
			try {
				localStorage.setItem(outboxKey, JSON.stringify(items.slice(-30)));
			} catch (e) { /* storage full or blocked */ }
		}

		function outboxRemove(cuid) {
			saveOutbox(loadOutbox().filter(function (i) { return i.uid !== cuid; }));
		}

		function pendingBubble(item) {
			var $el = buildMessage({ id: 'p-' + item.uid, sender_id: userId, type: 'text', message: item.text, created_at: '' });
			$el.addClass('mcp-pending').attr('data-client-uid', item.uid).data('mcpText', item.text.trim()).data('mcpItem', item);
			if (item.quote) {
				$el.children('.chat-message-content').prepend(
					'<span class="mcp-quote"><span class="mcp-quote-who">' + esc(item.quote.who) + '</span><span class="mcp-quote-text">' + esc(item.quote.text) + '</span></span>'
				);
			}
			$el.children('.chat-message-content').append('<div class="mcp-send-state" role="status"><span class="mcp-send-label">' + esc(T.sending) + '</span></div>');
			$list.append($el);
			scrollBottom();
			return $el;
		}

		function setPendingState($el, kind, message) {
			var $s = $el.find('.mcp-send-state');
			$el.toggleClass('is-failed', kind === 'failed').toggleClass('is-waiting', kind === 'waiting');
			$s.find('.mcp-send-label').text(message || (kind === 'waiting' ? T.pending : kind === 'failed' ? T.sendFailed : T.sending));
			$s.find('.mcp-send-retry, .mcp-send-remove').remove();
			if (kind === 'failed') {
				$s.append('<button type="button" class="mcp-link-btn mcp-send-retry">' + esc(T.retry) + '</button><button type="button" class="mcp-link-btn mcp-send-remove">' + esc(T.remove) + '</button>');
			}
		}

		function settlePending($el, m) {
			var id = parseInt(m.id, 10);
			if (state.known[id] && $list.children('.chat-message[data-message-id="' + id + '"]').length) {
				outboxRemove($el.data('mcpItem') ? $el.data('mcpItem').uid : '');
				$el.remove();
				return;
			}
			$el.removeClass('mcp-pending is-failed is-waiting').removeAttr('data-client-uid').attr('data-message-id', id);
			$el.find('.mcp-send-state').remove();
			$el.children('.chat-message-content').children('span.mcp-quote').remove();
			$el.find('.chat-message-date').text(hmFrom(m.created_at));
			outboxRemove($el.data('mcpItem') ? $el.data('mcpItem').uid : '');
			state.lastId = Math.max(state.lastId, id);
			enhance($el, m.mcp || {});
			if (m.mcp_state) {
				renderStatus(m.mcp_state);
			}
		}

		var retryTimers = {};

		function attemptSend($el, attempt) {
			var item = $el.data('mcpItem');
			if (!item) {
				return;
			}
			attempt = attempt || 0;
			clearTimeout(retryTimers[item.uid]);
			if (navigator.onLine === false) {
				setPendingState($el, 'waiting');
				return;
			}
			setPendingState($el, 'sending');
			var body = { message: item.text, type: 'text', client_uid: item.uid };
			if (item.reply_to) {
				body.reply_to = item.reply_to;
			}
			themeApi('conversations/' + sessionId + '/messages', 'POST', body).then(function (res) {
				if (res && res.success && res.data) {
					settlePending($el, res.data);
					markSeen();
				} else {
					throw { status: 500, message: T.sendFailed };
				}
			}).catch(function (err) {
				if (err.status === 409 && err.code === 'mcp_in_flight') {
					retryTimers[item.uid] = setTimeout(function () { attemptSend($el, attempt + 1); }, 2500);
					return;
				}
				if (err.status === 0 || err.status >= 500) {
					setPendingState($el, navigator.onLine === false ? 'waiting' : 'failed');
					var delay = [3000, 8000, 20000, 45000][Math.min(attempt, 3)];
					retryTimers[item.uid] = setTimeout(function () { attemptSend($el, attempt + 1); }, delay);
					return;
				}
				if (err.status === 429) {
					setPendingState($el, 'failed', T.rateLimited);
					retryTimers[item.uid] = setTimeout(function () { attemptSend($el, attempt + 1); }, 20000);
					return;
				}
				outboxRemove(item.uid);
				setPendingState($el, 'failed', err.message || T.sendFailed);
				$el.find('.mcp-send-retry').remove();
				if (err.code === 'mcp_chat_closed') {
					toast(T.chatClosed, 'error');
				}
			});
		}

		function flushOutbox() {
			$list.children('.mcp-pending').each(function () {
				attemptSend($(this));
			});
			$list.children('.mcp-upload.is-failed').each(function () {
				$(this).find('.mcp-up-retry').trigger('click');
			});
		}

		$list.on('click', '.mcp-send-retry', function () {
			attemptSend($(this).closest('.chat-message'), 0);
		});
		$list.on('click', '.mcp-send-remove', function () {
			var $el = $(this).closest('.chat-message');
			var item = $el.data('mcpItem');
			if (item) {
				outboxRemove(item.uid);
				clearTimeout(retryTimers[item.uid]);
			}
			$el.remove();
		});

		function sendText() {
			var text = String($input.val() || '').trim();
			if (!text) {
				return;
			}
			removeSeparator();
			var item = { uid: uid(), text: text, ts: Date.now() };
			if (state.replyTo) {
				item.reply_to = state.replyTo.id;
				item.quote = { who: state.replyTo.who, text: state.replyTo.text };
			}
			var items = loadOutbox();
			items.push(item);
			saveOutbox(items);
			cancelReply();
			$input.val('').trigger('input');
			attemptSend(pendingBubble(item), 0);
		}

		/* ------------------------------------------------------------------
		 * Upload manager (preview → progress → cancel/retry → message)
		 * ---------------------------------------------------------------- */

		function validateFile(file) {
			var ext = extOf(file.name);
			if ((C.allowedExt || []).indexOf(ext) === -1) {
				return T.fileTypeInvalid;
			}
			if (file.type && D.allowedFileTypes && D.allowedFileTypes.length && D.allowedFileTypes.indexOf(file.type) === -1 && ['heic', 'heif', 'webm', 'ogg', 'm4a', 'mp3', 'wav'].indexOf(ext) === -1) {
				return T.fileTypeInvalid;
			}
			if (C.maxBytes && file.size > C.maxBytes) {
				return T.fileTooLarge + ' (' + fmtBytes(C.maxBytes) + ')';
			}
			if (!file.size) {
				return T.uploadFailed;
			}
			return '';
		}

		function openUploadSheet(file) {
			var error = validateFile(file);
			var kind = kindFromFile(file);
			var preview;
			var objectUrl = '';
			if (kind === 'image' && !error) {
				objectUrl = URL.createObjectURL(file);
				preview = '<img class="mcp-up-preview-img" src="' + esc(objectUrl) + '" alt="">';
			} else {
				preview = '<i class="' + kindIcon(kind) + ' mcp-up-preview-icon" aria-hidden="true"></i>';
			}
			var html = '<div class="mcp-up-sheet">' +
				'<div class="mcp-up-preview">' + preview + '</div>' +
				'<div class="mcp-up-meta"><strong class="mcp-up-name"></strong><span>' + esc(fmtBytes(file.size)) + ' · ' + esc(extOf(file.name).toUpperCase()) + '</span></div>' +
				(error ? '<p class="mcp-error" role="alert">' + esc(error) + '</p>' : '') +
				'<div class="mcp-dialog-actions">' +
					'<button type="button" class="mcp-btn mcp-btn--ghost" data-act="cancel">' + esc(T.cancel) + '</button>' +
					'<button type="button" class="mcp-btn" data-act="send"' + (error ? ' disabled' : '') + '>' + esc(T.send) + '</button>' +
				'</div></div>';
			var $d = openDialog(T.sendFileTitle, html);
			$d.find('.mcp-up-name').text(file.name);
			var cleanup = function () {
				if (objectUrl) {
					URL.revokeObjectURL(objectUrl);
				}
			};
			$d.on('click', '[data-act="cancel"]', function () {
				cleanup();
				closeDialog();
			});
			$d.on('click', '[data-act="send"]', function () {
				cleanup();
				closeDialog();
				startUpload(file, 'file');
			});
		}

		function uploadBubble(file, type, localUrl) {
			var kind = type === 'voice' ? 'audio' : kindFromFile(file);
			var thumb = kind === 'image' && localUrl
				? '<img class="mcp-up-thumb" src="' + esc(localUrl) + '" alt="">'
				: '<i class="' + kindIcon(kind) + ' mcp-file-icon" aria-hidden="true"></i>';
			var $el = $(
				'<div class="chat-message current-user-message mcp-upload" role="listitem">' +
					'<div class="chat-message-content">' +
						'<div class="mcp-file mcp-file--uploading">' +
							thumb +
							'<div class="mcp-file-info"><span class="mcp-file-name"></span>' +
							'<div class="mcp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="mcp-progress-bar"></span></div>' +
							'<span class="mcp-file-sub"><span class="mcp-up-status"></span></span></div>' +
							'<div class="mcp-file-actions">' +
								'<button type="button" class="mcp-icon-btn mcp-up-cancel"><i class="drplus-icon-close" aria-hidden="true"></i></button>' +
							'</div>' +
						'</div>' +
						'<div class="chat-message-date" role="note">' + esc(nowHM()) + '</div>' +
					'</div>' +
				'</div>'
			);
			$el.find('.mcp-file-name').text(type === 'voice' ? T.voice : file.name);
			$el.find('.mcp-up-cancel').attr('aria-label', T.cancel);
			$list.append($el);
			scrollBottom();
			return $el;
		}

		function setUploadState($el, kind, text, percent) {
			$el.removeClass('is-failed is-cancelled is-done').addClass(kind ? 'is-' + kind : '');
			$el.find('.mcp-up-status').text(text || '');
			if (percent !== undefined) {
				$el.find('.mcp-progress').attr('aria-valuenow', percent).find('.mcp-progress-bar').css('width', percent + '%');
			}
			var $actions = $el.find('.mcp-file-actions').empty();
			if (kind === 'failed' || kind === 'cancelled') {
				$actions.append($('<button type="button" class="mcp-icon-btn mcp-up-retry"><i class="drplus-icon-redo" aria-hidden="true"></i></button>').attr({ 'aria-label': T.retry, title: T.retry }));
				$actions.append($('<button type="button" class="mcp-icon-btn mcp-up-remove"><i class="drplus-icon-trash" aria-hidden="true"></i></button>').attr({ 'aria-label': T.remove, title: T.remove }));
			} else if (!kind) {
				$actions.append($('<button type="button" class="mcp-icon-btn mcp-up-cancel"><i class="drplus-icon-close" aria-hidden="true"></i></button>').attr({ 'aria-label': T.cancel, title: T.cancel }));
			}
		}

		/**
		 * Large photos are downscaled (max 1920px, JPEG 85%) before upload: several times smaller,
		 * much faster on mobile networks, and EXIF/GPS metadata is dropped.
		 */
		function prepareFile(file, type) {
			if (type !== 'file' || !/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024 || !window.createImageBitmap) {
				return Promise.resolve(file);
			}
			return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () {
				return createImageBitmap(file);
			}).then(function (bmp) {
				var scale = Math.min(1, 1920 / Math.max(bmp.width, bmp.height));
				var canvas = document.createElement('canvas');
				canvas.width = Math.round(bmp.width * scale);
				canvas.height = Math.round(bmp.height * scale);
				var ctx = canvas.getContext('2d');
				ctx.fillStyle = '#fff';
				ctx.fillRect(0, 0, canvas.width, canvas.height);
				ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
				if (bmp.close) {
					bmp.close();
				}
				return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.85); });
			}).then(function (blob) {
				if (!blob || blob.size >= file.size) {
					return file;
				}
				return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
			}).catch(function () {
				return file;
			});
		}

		function startUpload(file, type, $existing, durationSecs) {
			removeSeparator();
			var localUrl = '';
			if (!$existing && (type === 'voice' || kindFromFile(file) === 'image') && window.URL) {
				localUrl = URL.createObjectURL(file);
			}
			var $el = $existing || uploadBubble(file, type, localUrl);
			var job = $el.data('mcpJob') || { file: file, type: type, uid: uid(), reply: state.replyTo ? state.replyTo.id : 0, localUrl: localUrl, prepared: null, duration: 0 };
			if (durationSecs > 0) {
				job.duration = durationSecs;
			}
			$el.data('mcpJob', job);
			if (!$existing) {
				cancelReply();
			}
			$el.attr('data-stage', 'upload');
			setUploadState($el, '', T.preparing, 0);

			(job.prepared ? Promise.resolve(job.prepared) : prepareFile(file, type)).then(function (ready) {
				job.prepared = ready;
				sendAttachment($el, job, ready);
			});
		}

		function sendAttachment($el, job, file) {
			setUploadState($el, '', T.uploading + ' 0%', 0);
			var fd = new FormData();
			fd.append('file', file, file.name);
			fd.append('type', job.type);
			fd.append('client_uid', job.uid);
			if (job.reply) {
				fd.append('reply_to', job.reply);
			}
			var xhr = new XMLHttpRequest();
			job.xhr = xhr;
			xhr.open('POST', withEnvelope(C.restBase + 'sessions/' + sessionId + '/attachments'));
			xhr.setRequestHeader('X-WP-Nonce', C.nonce);
			xhr.upload.onprogress = function (e) {
				if (e.lengthComputable) {
					var p = Math.min(99, Math.round((e.loaded / e.total) * 100));
					setUploadState($el, '', p >= 99 ? T.sending : T.uploading + ' ' + p + '%', p);
				}
			};
			xhr.onload = function () {
				job.xhr = null;
				var json = null;
				var status = xhr.status;
				try {
					json = unwrap(xhr.status, xhr.responseText);
				} catch (err) {
					status = err.status;
					json = { message: err.status >= 500 || err.status === 0 ? T.uploadFailed : err.message };
				}
				if (status === 409) {
					setUploadState($el, '', T.sending, 100);
					setTimeout(function () { sendAttachment($el, job, file); }, 2500);
					return;
				}
				var ok = status >= 200 && status < 300 && json && json.success && json.data && json.data.id;
				if (!ok) {
					var msg = (json && (json.message || (json.data && json.data.message))) || (status === 429 ? T.rateLimited : T.uploadFailed);
					setUploadState($el, 'failed', msg);
					if (status === 400 || status === 403 || status === 413) {
						$el.find('.mcp-up-retry').remove();
					}
					return;
				}
				var m = json.data;
				var $msg = buildMessage(m);
				$el.replaceWith($msg);
				enhance($msg, m.mcp || {}, { url: job.localUrl, duration: job.duration || 0 });
				state.lastId = Math.max(state.lastId, parseInt(m.id, 10));
				if (m.mcp_state) {
					renderStatus(m.mcp_state);
				}
				scrollBottom();
			};
			xhr.onerror = function () {
				job.xhr = null;
				setUploadState($el, 'failed', navigator.onLine === false ? T.offline : T.networkError);
			};
			xhr.onabort = function () {
				job.xhr = null;
				setUploadState($el, 'cancelled', T.uploadCancelled);
			};
			xhr.send(fd);
		}

		$list.on('click', '.mcp-up-cancel', function () {
			var job = $(this).closest('.mcp-upload').data('mcpJob');
			if (job && job.xhr) {
				job.xhr.abort();
			}
		});
		$list.on('click', '.mcp-up-retry', function () {
			var $el = $(this).closest('.mcp-upload');
			var job = $el.data('mcpJob');
			if (job) {
				startUpload(job.file, job.type, $el);
			}
		});
		$list.on('click', '.mcp-up-remove', function () {
			$(this).closest('.mcp-upload').remove();
		});

		/* ------------------------------------------------------------------
		 * Voice recording
		 * ---------------------------------------------------------------- */

		// state: idle → starting (waiting for mic permission) → recording → idle.
		var rec = { state: 'idle', recorder: null, stream: null, chunks: [], cancelled: false, token: 0 };
		var recTimer = null;
		var recStart = 0;

		function recorderMime() {
			if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) {
				return '';
			}
			var list = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
			for (var i = 0; i < list.length; i++) {
				if (MediaRecorder.isTypeSupported(list[i])) {
					return list[i];
				}
			}
			return '';
		}

		if (D.recordVoice) {
			if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
				$('#chat-record-voice-btn').hide();
			} else if (navigator.mediaDevices.enumerateDevices) {
				navigator.mediaDevices.enumerateDevices().then(function (devices) {
					if (!devices.some(function (d) { return d.kind === 'audioinput'; })) {
						$('#chat-record-voice-btn').hide();
					}
				});
			}
		}

		function stopTimer() {
			clearInterval(recTimer);
			recTimer = null;
			$('.chat-send-recording-voice-time').text('');
		}

		function releaseMic() {
			if (rec.stream) {
				rec.stream.getTracks().forEach(function (t) { t.stop(); });
				rec.stream = null;
			}
		}

		function resetRecording() {
			rec.state = 'idle';
			rec.recorder = null;
			rec.chunks = [];
			releaseMic();
			stopTimer();
			$composer.removeClass('voice');
		}

		function isActivation(e) {
			return e.type !== 'keydown' || e.key === 'Enter' || e.key === ' ';
		}

		$root.on('click keydown', '#chat-record-voice-btn', function (e) {
			if (!isActivation(e)) {
				return;
			}
			e.preventDefault();
			removeSeparator();
			if (rec.state !== 'idle') {
				return;
			}
			rec.state = 'starting';
			rec.cancelled = false;
			rec.chunks = [];
			var token = ++rec.token;
			$composer.addClass('voice');
			$('.chat-send-recording-voice-time').text('00:00');

			navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
				// Trash pressed while the permission prompt was open (or a newer attempt replaced this one).
				if (token !== rec.token || rec.cancelled || rec.state !== 'starting') {
					stream.getTracks().forEach(function (t) { t.stop(); });
					return;
				}
				var mime = recorderMime();
				var recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
				rec.stream = stream;
				rec.recorder = recorder;
				recorder.ondataavailable = function (ev) {
					if (ev.data && ev.data.size && !rec.cancelled) {
						rec.chunks.push(ev.data);
					}
				};
				recorder.start();
				rec.state = 'recording';
				recStart = Date.now();
				recTimer = setInterval(function () {
					$('.chat-send-recording-voice-time').text(fmtClock((Date.now() - recStart) / 1000));
				}, 1000);
			}).catch(function () {
				if (token !== rec.token) {
					return;
				}
				resetRecording();
				toast(D.i18n && D.i18n.micPermissionDenied ? D.i18n.micPermissionDenied : T.actionFailed, 'error');
			});
		});

		// Discard: stops the mic immediately and drops everything recorded so far.
		$root.on('click keydown', '#chat-cancel-record-voice-btn', function (e) {
			if (!isActivation(e)) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			rec.cancelled = true;
			rec.token++;
			var recorder = rec.recorder;
			if (recorder) {
				recorder.ondataavailable = null;
				recorder.onstop = null;
				if (recorder.state !== 'inactive') {
					try {
						recorder.stop();
					} catch (err) { /* already stopped */ }
				}
			}
			resetRecording();
		});

		// While recording, the whole attachment circle acts as the trash button (bigger touch target).
		$root.on('click', '.chat-send-attachment-wrap', function (e) {
			if (rec.state !== 'idle' && !$(e.target).closest('#chat-cancel-record-voice-btn').length) {
				$('#chat-cancel-record-voice-btn').trigger('click');
			}
		});

		$root.on('click keydown', '#chat-send-voice-btn', function (e) {
			if (!isActivation(e)) {
				return;
			}
			e.preventDefault();
			var recorder = rec.recorder;
			if (rec.state !== 'recording' || !recorder) {
				return;
			}
			rec.state = 'stopping';
			var recordedSecs = Math.max(1, Math.round((Date.now() - recStart) / 1000));
			recorder.onstop = function () {
				var chunks = rec.chunks;
				var type = (recorder.mimeType || 'audio/webm').split(';')[0];
				var cancelled = rec.cancelled;
				resetRecording();
				var ext = /mp4/.test(type) ? 'mp4' : (/ogg/.test(type) ? 'ogg' : 'webm');
				var fileType = ext === 'mp4' ? 'video/mp4' : (ext === 'ogg' ? 'audio/ogg' : 'audio/webm');
				var blob = new Blob(chunks, { type: fileType });
				if (cancelled || !blob.size) {
					return;
				}
				startUpload(new File([blob], 'voice_' + Date.now() + '.' + ext, { type: fileType }), 'voice', null, recordedSecs);
			};
			try {
				recorder.stop();
			} catch (err) {
				resetRecording();
			}
		});

		/* ------------------------------------------------------------------
		 * Composer wiring
		 * ---------------------------------------------------------------- */

		function removeSeparator() {
			if (state.separatorRemoved) {
				return;
			}
			state.separatorRemoved = true;
			$list.find('.chat-new-messages-separator').fadeOut(200, function () { $(this).remove(); });
		}

		function scrollBottom() {
			if ($list[0]) {
				$list.scrollTop($list[0].scrollHeight);
			}
		}

		if ($composer.length) {
			$root.on('click keydown', '#chat-send-btn', function (e) {
				if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
					return;
				}
				e.preventDefault();
				sendText();
			});

			// Enter sends, Shift+Enter inserts a new line (IME composition is left alone).
			$input.on('keydown', function (e) {
				var oe = e.originalEvent || e;
				if (e.key === 'Enter' && !e.shiftKey && !oe.isComposing && oe.keyCode !== 229) {
					e.preventDefault();
					sendText();
				}
			});

			var lastTypingPing = 0;
			$input.on('input keyup change', function (e) {
				var el = this;
				var lines = el.value.split('\n').length;
				$(el).attr('rows', Math.min(4, Math.max(1, lines)));
				$composer.toggleClass('text', !!el.value);
				filterQuick(el.value);
				if (e.type === 'input' && el.value.trim() && el.value.charAt(0) !== '/' && Date.now() - lastTypingPing > 4000) {
					lastTypingPing = Date.now();
					sendTyping(true);
				}
			});

			function sendTyping(on) {
				api('sessions/' + sessionId + '/typing', 'POST', { typing: on }).then(function (res) {
					if (res && typeof res.typing === 'boolean') {
						showTyping(res.typing);
					}
				}).catch(function () { /* indicator is best-effort */ });
			}

			$root.on('click keydown', '#chat-send-attachment-btn', function (e) {
				if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
					return;
				}
				e.preventDefault();
				removeSeparator();
				$('#chat-send-attachment').val('').trigger('click');
			});

			$('#chat-send-attachment').removeAttr('accept').attr('accept', (C.allowedExt || []).map(function (x) { return '.' + x; }).join(',') + ',' + (D.allowedFileTypes || []).join(','));
			$('#chat-send-attachment').on('change', function (e) {
				var file = e.target.files && e.target.files[0];
				if (file) {
					openUploadSheet(file);
				}
			});

			// Paste images straight from the clipboard.
			$input.on('paste', function (e) {
				var items = (e.originalEvent.clipboardData || {}).files;
				if (items && items.length) {
					e.preventDefault();
					openUploadSheet(items[0]);
				}
			});
		}

		/* ------------------------------------------------------------------
		 * Quick replies (specialist)
		 * ---------------------------------------------------------------- */

		var quick = [];
		var $quickPanel = null;
		var $quickBtn = null;

		function setupQuickReplies(items) {
			quick = items || [];
			if (!$composer.length) {
				return;
			}
			$quickBtn = $('<button type="button" class="mcp-icon-btn mcp-quick-btn" aria-expanded="false"></button>')
				.attr({ 'aria-label': T.quickReplies, title: T.quickReplies, 'aria-controls': 'mcp-quick-panel' })
				.html('<i class="drplus-icon-flash" aria-hidden="true"></i>');
			$root.find('.chat-send-input-wrap').prepend($quickBtn);
			$quickPanel = $('<div class="mcp-quick-panel" id="mcp-quick-panel" hidden></div>');
			$extras.append($quickPanel);
			renderQuick('');
			$quickBtn.on('click', function () {
				toggleQuick(!!$quickPanel.prop('hidden'));
			});
		}

		function toggleQuick(open) {
			if (!$quickPanel) {
				return;
			}
			$quickPanel.prop('hidden', !open);
			$quickBtn.attr('aria-expanded', open ? 'true' : 'false');
		}

		function renderQuick(filter) {
			if (!$quickPanel) {
				return;
			}
			var f = (filter || '').toLowerCase();
			var html = '<div class="mcp-quick-head"><span>' + esc(T.quickReplies) + '</span><button type="button" class="mcp-link-btn mcp-quick-manage">' + esc(T.manageQuick) + '</button></div><div class="mcp-quick-list" role="list">';
			var shown = 0;
			quick.forEach(function (q) {
				if (f && q.text.toLowerCase().indexOf(f) === -1) {
					return;
				}
				shown++;
				html += '<button type="button" role="listitem" class="mcp-quick-item" data-id="' + esc(q.id) + '">' + esc(q.text) + '</button>';
			});
			if (!shown) {
				html += '<span class="mcp-muted">' + esc(T.empty) + '</span>';
			}
			$quickPanel.html(html + '</div>');
		}

		function filterQuick(value) {
			if (!$quickPanel) {
				return;
			}
			if (/^\//.test(value)) {
				renderQuick(value.slice(1));
				toggleQuick(true);
			} else if (!$quickPanel.prop('hidden') && value === '') {
				renderQuick('');
			}
		}

		$extras.on('click', '.mcp-quick-item', function () {
			var id = $(this).data('id');
			var q = quick.filter(function (x) { return x.id === id; })[0];
			if (!q) {
				return;
			}
			var el = $input[0];
			var cur = el.value;
			if (/^\//.test(cur) || !cur.trim()) {
				el.value = q.text;
			} else {
				var s = el.selectionStart || cur.length;
				el.value = cur.slice(0, s) + q.text + cur.slice(el.selectionEnd || s);
			}
			$input.trigger('input').trigger('focus');
			renderQuick('');
			toggleQuick(false);
		});
		$extras.on('click', '.mcp-quick-manage', openQuickManager);

		function openQuickManager() {
			var render = function ($d) {
				var html = '<ul class="mcp-qm-list">';
				quick.forEach(function (q) {
					html += '<li data-id="' + esc(q.id) + '"><p class="mcp-qm-text">' + esc(q.text) + '</p>' +
						'<div class="mcp-qm-actions"><button type="button" class="mcp-link-btn" data-act="edit">' + esc(T.edit) + '</button>' +
						'<button type="button" class="mcp-link-btn mcp-danger" data-act="del">' + esc(T.delete) + '</button></div></li>';
				});
				if (!quick.length) {
					html += '<li class="mcp-muted">' + esc(T.empty) + '</li>';
				}
				$d.find('.mcp-qm-wrap').html(html + '</ul>');
				renderQuick('');
			};
			var body = '<div class="mcp-qm-wrap"></div>' +
				'<form class="mcp-qm-add"><label class="mcp-label" for="mcp-qm-new">' + esc(T.addQuick) + '</label>' +
				'<textarea id="mcp-qm-new" rows="2" maxlength="1000" placeholder="' + esc(T.quickPlaceholder) + '"></textarea>' +
				'<div class="mcp-dialog-actions"><button type="submit" class="mcp-btn">' + esc(T.addQuick) + '</button></div></form>';
			var $d = openDialog(T.manageQuick, body, { wide: true });
			render($d);
			$d.on('submit', '.mcp-qm-add', function (e) {
				e.preventDefault();
				var $t = $d.find('#mcp-qm-new');
				var text = $t.val().trim();
				if (!text) {
					return;
				}
				api('quick-replies', 'POST', { text: text }).then(function (item) {
					quick.push(item);
					$t.val('');
					render($d);
				}).catch(function (err) { toast(err.message || T.actionFailed, 'error'); });
			});
			$d.on('click', '[data-act="del"]', function () {
				var id = $(this).closest('li').data('id');
				if (!window.confirm(T.confirmDelete)) {
					return;
				}
				api('quick-replies/' + id, 'DELETE').then(function () {
					quick = quick.filter(function (q) { return q.id !== id; });
					render($d);
				}).catch(function (err) { toast(err.message || T.actionFailed, 'error'); });
			});
			$d.on('click', '[data-act="edit"]', function () {
				var $li = $(this).closest('li');
				var id = $li.data('id');
				var q = quick.filter(function (x) { return x.id === id; })[0];
				$li.html('<textarea rows="2" maxlength="1000"></textarea><div class="mcp-qm-actions"><button type="button" class="mcp-btn mcp-btn--small" data-act="save">' + esc(T.save) + '</button><button type="button" class="mcp-link-btn" data-act="cancel-edit">' + esc(T.cancel) + '</button></div>');
				$li.find('textarea').val(q ? q.text : '').trigger('focus');
			});
			$d.on('click', '[data-act="cancel-edit"]', function () { render($d); });
			$d.on('click', '[data-act="save"]', function () {
				var $li = $(this).closest('li');
				var id = $li.data('id');
				var text = $li.find('textarea').val().trim();
				if (!text) {
					return;
				}
				api('quick-replies/' + id, 'PUT', { text: text }).then(function (item) {
					quick = quick.map(function (q) { return q.id === id ? item : q; });
					render($d);
				}).catch(function (err) { toast(err.message || T.actionFailed, 'error'); });
			});
		}

		/* ------------------------------------------------------------------
		 * Patient drawer: profile, history, documents, medical, notes
		 * ---------------------------------------------------------------- */

		var $drawer = null;
		var profileCache = null;

		function closeDrawer() {
			if (!$drawer) {
				return;
			}
			$drawer.removeClass('is-open').attr('aria-hidden', 'true');
			$('.mcp-drawer-backdrop').removeClass('is-open');
			if (!$('[data-mcp-dialog], .mcp-lightbox').length) {
				$('body').removeClass('mcp-modal-open');
			}
			if (lastFocus && document.body.contains(lastFocus)) {
				lastFocus.focus();
			}
		}

		function openDrawer(tab) {
			closeMenus();
			lastFocus = document.activeElement;
			if (!$drawer) {
				var can = state.ctx.can || {};
				var tabs = [['profile', T.tabProfile], ['history', T.tabHistory], ['docs', T.tabDocs], ['medical', T.tabMedical]];
				if (can.notes || (state.ctx.role !== 'specialist' && can.profile)) {
					tabs.push(['notes', T.tabNotes]);
				}
				var tabHtml = tabs.map(function (t) {
					return '<button type="button" role="tab" class="mcp-tab" id="mcp-tab-' + t[0] + '" aria-controls="mcp-pane-' + t[0] + '" data-tab="' + t[0] + '" aria-selected="false">' + esc(t[1]) + '</button>';
				}).join('');
				var panes = tabs.map(function (t) {
					return '<div class="mcp-pane" role="tabpanel" id="mcp-pane-' + t[0] + '" aria-labelledby="mcp-tab-' + t[0] + '" hidden></div>';
				}).join('');
				$layer.append('<div class="mcp-drawer-backdrop"></div>');
				$drawer = $(
					'<aside class="mcp-drawer" role="dialog" aria-modal="true" aria-labelledby="mcp-drawer-title" aria-hidden="true">' +
						'<div class="mcp-drawer-head"><h3 id="mcp-drawer-title">' + esc(T.patientProfile) + '</h3>' +
						'<button type="button" class="mcp-icon-btn mcp-drawer-close"><i class="drplus-icon-close" aria-hidden="true"></i></button></div>' +
						'<div class="mcp-tabs" role="tablist">' + tabHtml + '</div>' +
						'<div class="mcp-drawer-body">' + panes + '</div>' +
					'</aside>'
				).appendTo($layer);
				$drawer.find('.mcp-drawer-close').attr('aria-label', T.close).on('click', closeDrawer);
				$('.mcp-drawer-backdrop').on('click', closeDrawer);
				$drawer.on('click', '.mcp-tab', function () { selectTab($(this).data('tab')); });
				$drawer.on('keydown', function (e) {
					trapFocus(e, $drawer);
					if ($(e.target).is('.mcp-tab') && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
						var $t = $drawer.find('.mcp-tab');
						var i = $t.index(e.target) + ((e.key === 'ArrowLeft') === (document.dir === 'rtl' || $('html').attr('dir') === 'rtl') ? 1 : -1);
						$t.eq((i + $t.length) % $t.length).trigger('focus').trigger('click');
					}
				});
				bindNotes();
			}
			$('.mcp-drawer-backdrop').addClass('is-open');
			$drawer.addClass('is-open').attr('aria-hidden', 'false');
			$('body').addClass('mcp-modal-open');
			selectTab(tab && $drawer.find('[data-tab="' + tab + '"]').length ? tab : 'profile');
		}

		function selectTab(tab) {
			$drawer.find('.mcp-tab').each(function () {
				var on = $(this).data('tab') === tab;
				$(this).attr({ 'aria-selected': on ? 'true' : 'false', tabindex: on ? '0' : '-1' }).toggleClass('is-active', on);
			});
			$drawer.find('.mcp-pane').prop('hidden', true);
			$drawer.find('#mcp-pane-' + tab).prop('hidden', false);
			$drawer.find('#mcp-tab-' + tab).trigger('focus');
			if (tab === 'notes') {
				loadNotes();
			} else if (!profileCache) {
				loadProfile();
			}
		}

		function loading($pane) {
			$pane.html('<div class="mcp-loading" role="status"><span class="mcp-spinner" aria-hidden="true"></span>' + esc(T.loading) + '</div>');
		}

		function loadProfile() {
			var $panes = $drawer.find('#mcp-pane-profile, #mcp-pane-history, #mcp-pane-docs, #mcp-pane-medical');
			$panes.each(function () { loading($(this)); });
			api('sessions/' + sessionId + '/profile').then(function (p) {
				profileCache = p;
				renderProfile(p);
			}).catch(function (err) {
				$panes.html('<p class="mcp-error" role="alert">' + esc(err.message || T.loadFailed) + '</p>');
			});
		}

		function kv(label, value) {
			if (value === null || value === undefined || value === '') {
				return '';
			}
			return '<div class="mcp-kv-row"><dt>' + esc(label) + '</dt><dd>' + value + '</dd></div>';
		}

		function renderProfile(p) {
			var pt = p.patient || {};
			var cur = p.current || {};
			var rel = p.relationship || {};
			var html = '<div class="mcp-pt-head"><img src="' + esc(pt.avatar) + '" alt=""><div><strong>' + esc(pt.name) + '</strong>' +
				(pt.age !== null && pt.age !== undefined ? '<span>' + esc(T.age) + ': ' + esc(pt.age) + ' ' + esc(T.years) + '</span>' : '') + '</div></div>';
			html += '<h4 class="mcp-sub">' + esc(T.relationship) + '</h4><div class="mcp-mini-stats">' +
				'<div><strong>' + esc(rel.sessions || 0) + '</strong><span>' + esc(T.sessions) + '</span></div>' +
				'<div><strong>' + esc(rel.sessions_closed || 0) + '</strong><span>' + esc(T.closedSessions) + '</span></div>' +
				'<div><strong>' + esc(rel.bookings || 0) + '</strong><span>' + esc(T.bookings) + '</span></div>' +
				'<div><strong>' + esc(rel.bookings_cancel || 0) + '</strong><span>' + esc(T.cancelled) + '</span></div>' +
				'</div>' + (rel.first_contact ? '<p class="mcp-muted">' + esc(T.firstContact) + ': ' + esc(fmtDate(rel.first_contact)) + '</p>' : '');
			html += '<h4 class="mcp-sub">' + esc(T.thisSession) + '</h4><dl class="mcp-kv">' +
				kv(T.reason, cur.reason ? esc(cur.reason) : '') +
				kv(T.consultType, esc(cur.type)) +
				kv(T.status, cur.state ? esc(cur.state.status_label) : '') +
				kv(T.memberSince, pt.member_since ? esc(fmtDate(pt.member_since)) : '') +
				(pt.contact ? kv(T.phone, '<span dir="ltr">' + esc(pt.contact.phone) + '</span>') + kv(T.email, '<span dir="ltr">' + esc(pt.contact.email) + '</span>') : '') +
				'</dl>';
			if (p.referrals && p.referrals.length) {
				html += '<h4 class="mcp-sub">' + esc(T.referralCard) + '</h4><ul class="mcp-plain-list">';
				p.referrals.forEach(function (r) {
					html += '<li><strong>' + esc(r.name) + '</strong> <span class="mcp-muted">' + esc(r.subtitle) + '</span>' + (r.reason ? '<p>' + esc(r.reason) + '</p>' : '') + '</li>';
				});
				html += '</ul>';
			}
			$drawer.find('#mcp-pane-profile').html(html);

			var hist = '<ul class="mcp-history">';
			(p.history || []).forEach(function (h) {
				hist += '<li class="' + (h.current ? 'is-current' : '') + '"><div class="mcp-history-main">' +
					'<strong>#' + esc(h.session_id) + (h.current ? ' · ' + esc(T.thisSession) : '') + '</strong>' +
					(h.specialist ? '<span>' + esc(h.specialist) + '</span>' : '') +
					'<span class="mcp-muted">' + esc(fmtDate(h.created_at)) + ' · ' + esc(h.messages) + ' ' + esc(T.messages) + '</span></div>' +
					'<span class="mcp-status" data-status="' + esc(h.status) + '"><i class="mcp-status-dot" aria-hidden="true"></i>' + esc(h.status_label) + '</span>' +
					(!h.current && h.url ? '<a class="mcp-link-btn" href="' + esc(h.url) + '">' + esc(T.open) + '</a>' : '') + '</li>';
			});
			hist += '</ul>';
			$drawer.find('#mcp-pane-history').html((p.history || []).length ? hist : '<p class="mcp-muted">' + esc(T.empty) + '</p>');

			var docs = '<ul class="mcp-docs">';
			(p.documents || []).forEach(function (d) {
				var open = d.kind === 'image'
					? '<button type="button" class="mcp-icon-btn mcp-doc-view" data-kind="image" data-url="' + esc(d.url) + '" data-name="' + esc(d.name) + '" data-download="' + esc(d.download) + '" aria-label="' + esc(T.preview) + '"><i class="drplus-icon-eye" aria-hidden="true"></i></button>'
					: (d.kind === 'pdf' ? '<button type="button" class="mcp-icon-btn mcp-doc-view" data-kind="pdf" data-url="' + esc(d.url) + '" data-name="' + esc(d.name) + '" data-download="' + esc(d.download) + '" aria-label="' + esc(T.preview) + '"><i class="drplus-icon-eye" aria-hidden="true"></i></button>' : '');
				docs += '<li>' + (d.kind === 'image' ? '<img src="' + esc(d.url) + '" alt="" loading="lazy">' : '<i class="' + kindIcon(d.kind) + ' mcp-file-icon" aria-hidden="true"></i>') +
					'<div class="mcp-file-info"><span class="mcp-file-name">' + esc(d.name) + '</span><span class="mcp-file-sub">' + esc(fmtDate(d.date)) + ' · ' + esc(d.from === 'patient' ? T.fromPatient : T.fromSpecialist) + ' · #' + esc(d.session_id) + '</span></div>' +
					'<div class="mcp-file-actions">' + open + '<a class="mcp-icon-btn" href="' + esc(d.download) + '" aria-label="' + esc(T.download) + '"><i class="drplus-icon-download" aria-hidden="true"></i></a></div></li>';
			});
			docs += '</ul>';
			$drawer.find('#mcp-pane-docs').html((p.documents || []).length ? docs : '<p class="mcp-muted">' + esc(T.empty) + '</p>');

			var med = p.medical;
			if (!med) {
				$drawer.find('#mcp-pane-medical').html('<p class="mcp-muted">' + esc(T.noMedical) + '</p>');
			} else {
				var mh = '<dl class="mcp-kv">' + kv(T.bloodGroup, esc(med.blood_group)) + kv(T.age, med.birth_date ? esc(med.birth_date) : '') + '</dl>';
				['allergies', 'drugs', 'chronic_diseases', 'surgeries', 'vaccinations'].forEach(function (k) {
					var items = (med.lists || {})[k] || [];
					if (items.length) {
						mh += '<h4 class="mcp-sub">' + esc(T[k]) + '</h4><div class="mcp-chips">' + items.map(function (i) { return '<span class="mcp-chip">' + esc(i) + '</span>'; }).join('') + '</div>';
					}
				});
				if ((med.records || []).length) {
					mh += '<h4 class="mcp-sub">' + esc(T.records) + '</h4><ul class="mcp-plain-list">';
					med.records.forEach(function (r) {
						mh += '<li><strong>' + esc(r.date) + '</strong>' + (r.doctor ? ' · ' + esc(r.doctor) : '') + (r.reason ? '<p>' + esc(r.reason) + '</p>' : '') + (r.diagnosis ? '<p class="mcp-muted">' + esc(r.diagnosis) + '</p>' : '') + '</li>';
					});
					mh += '</ul>';
				}
				$drawer.find('#mcp-pane-medical').html(mh);
			}
		}

		$(document).on('click', '.mcp-doc-view', function () {
			openViewer($(this).data('kind'), $(this).data('url'), $(this).data('name'), $(this).data('download'));
		});

		function loadNotes() {
			var $pane = $drawer.find('#mcp-pane-notes');
			if (!$pane.length) {
				return;
			}
			loading($pane);
			api('sessions/' + sessionId + '/notes').then(function (notes) {
				renderNotes(notes || []);
			}).catch(function (err) {
				$pane.html('<p class="mcp-error" role="alert">' + esc(err.message || T.loadFailed) + '</p>');
			});
		}

		function renderNotes(notes) {
			var can = state.ctx.can || {};
			var html = '<p class="mcp-hint"><i class="drplus-icon-shield-tick" aria-hidden="true"></i>' + esc(T.notesHint) + '</p>';
			if (can.notes) {
				html += '<form class="mcp-note-form"><label class="mcp-visually-hidden" for="mcp-note-new">' + esc(T.addNote) + '</label>' +
					'<textarea id="mcp-note-new" rows="3" maxlength="5000" placeholder="' + esc(T.notePlaceholder) + '"></textarea>' +
					'<div class="mcp-dialog-actions"><button type="submit" class="mcp-btn mcp-btn--small">' + esc(T.addNote) + '</button></div></form>';
			}
			html += '<ul class="mcp-notes-list">';
			notes.forEach(function (n) {
				html += '<li data-id="' + esc(n.id) + '"><div class="mcp-note-meta"><span>' + esc(fmtDate(n.updated_at)) + '</span>' +
					(!n.this_session ? '<span class="mcp-chip">' + esc(T.otherSessionNote) + ' #' + esc(n.session_id) + '</span>' : '') +
					(n.editable ? '<span class="mcp-note-actions"><button type="button" class="mcp-link-btn" data-act="edit-note">' + esc(T.edit) + '</button><button type="button" class="mcp-link-btn mcp-danger" data-act="del-note">' + esc(T.delete) + '</button></span>' : '<span>' + esc(n.author) + '</span>') +
					'</div><p class="mcp-note-body"></p></li>';
			});
			html += '</ul>';
			if (!notes.length) {
				html += '<p class="mcp-muted">' + esc(T.empty) + '</p>';
			}
			var $pane = $drawer.find('#mcp-pane-notes').html(html);
			notes.forEach(function (n) {
				$pane.find('li[data-id="' + n.id + '"] .mcp-note-body').text(n.body).data('body', n.body);
			});
		}

		function bindNotes() {
			$drawer.on('submit', '.mcp-note-form', function (e) {
				e.preventDefault();
				var $t = $(this).find('textarea');
				var body = $t.val().trim();
				if (!body) {
					return;
				}
				var $b = $(this).find('button').prop('disabled', true);
				api('sessions/' + sessionId + '/notes', 'POST', { body: body }).then(function () {
					toast(T.saved, 'success');
					loadNotes();
				}).catch(function (err) {
					toast(err.message || T.actionFailed, 'error');
					$b.prop('disabled', false);
				});
			});
			$drawer.on('click', '[data-act="del-note"]', function () {
				var id = $(this).closest('li').data('id');
				if (!window.confirm(T.confirmDelete)) {
					return;
				}
				api('sessions/' + sessionId + '/notes/' + id, 'DELETE').then(loadNotes).catch(function (err) { toast(err.message || T.actionFailed, 'error'); });
			});
			$drawer.on('click', '[data-act="edit-note"]', function () {
				var $li = $(this).closest('li');
				var body = $li.find('.mcp-note-body').data('body') || $li.find('.mcp-note-body').text();
				$li.find('.mcp-note-body').replaceWith('<div class="mcp-note-edit"><textarea rows="3" maxlength="5000"></textarea><div class="mcp-dialog-actions"><button type="button" class="mcp-link-btn" data-act="cancel-note">' + esc(T.cancel) + '</button><button type="button" class="mcp-btn mcp-btn--small" data-act="save-note">' + esc(T.save) + '</button></div></div>');
				$li.find('textarea').val(body).trigger('focus');
			});
			$drawer.on('click', '[data-act="cancel-note"]', loadNotes);
			$drawer.on('click', '[data-act="save-note"]', function () {
				var $li = $(this).closest('li');
				var body = $li.find('textarea').val().trim();
				if (!body) {
					return;
				}
				api('sessions/' + sessionId + '/notes/' + $li.data('id'), 'PUT', { body: body }).then(function () {
					toast(T.saved, 'success');
					loadNotes();
				}).catch(function (err) { toast(err.message || T.actionFailed, 'error'); });
			});
		}

		/* ------------------------------------------------------------------
		 * Referral & follow-up dialogs
		 * ---------------------------------------------------------------- */

		function openReferral() {
			var body = '<form class="mcp-ref-form">' +
				'<label class="mcp-label" for="mcp-ref-q">' + esc(T.searchSpecialist) + '</label>' +
				'<input type="search" id="mcp-ref-q" autocomplete="off" autofocus>' +
				'<div class="mcp-ref-results" role="radiogroup" aria-label="' + esc(T.refer) + '"></div>' +
				'<label class="mcp-label" for="mcp-ref-reason">' + esc(T.referReason) + '</label>' +
				'<textarea id="mcp-ref-reason" rows="3" maxlength="1000"></textarea>' +
				'<div class="mcp-dialog-actions"><button type="submit" class="mcp-btn" disabled>' + esc(T.referSubmit) + '</button></div></form>';
			var $d = openDialog(T.refer, body, { wide: true });
			var timer = null;
			var selected = 0;
			$d.on('input', '#mcp-ref-q', function () {
				var q = $(this).val().trim();
				clearTimeout(timer);
				if (q.length < 2) {
					$d.find('.mcp-ref-results').empty();
					return;
				}
				timer = setTimeout(function () {
					$d.find('.mcp-ref-results').html('<div class="mcp-loading"><span class="mcp-spinner" aria-hidden="true"></span>' + esc(T.loading) + '</div>');
					api('specialists?search=' + encodeURIComponent(q)).then(function (rows) {
						if (!rows || !rows.length) {
							$d.find('.mcp-ref-results').html('<p class="mcp-muted">' + esc(T.noResults) + '</p>');
							return;
						}
						$d.find('.mcp-ref-results').html(rows.map(function (r) {
							return '<label class="mcp-ref-item"><input type="radio" name="mcp_ref_to" value="' + esc(r.user_id) + '">' +
								'<img src="' + esc(r.avatar) + '" alt=""><span><strong>' + esc(r.name) + '</strong><small>' + esc(r.subtitle) + '</small></span></label>';
						}).join(''));
					}).catch(function (err) {
						$d.find('.mcp-ref-results').html('<p class="mcp-error">' + esc(err.message || T.loadFailed) + '</p>');
					});
				}, 300);
			});
			$d.on('change', 'input[name="mcp_ref_to"]', function () {
				selected = parseInt($(this).val(), 10);
				$d.find('button[type="submit"]').prop('disabled', !selected);
			});
			$d.on('submit', '.mcp-ref-form', function (e) {
				e.preventDefault();
				if (!selected) {
					return;
				}
				var $b = $d.find('button[type="submit"]').prop('disabled', true);
				api('sessions/' + sessionId + '/referrals', 'POST', { to_user_id: selected, reason: $d.find('#mcp-ref-reason').val() }).then(function () {
					closeDialog();
					toast(T.referDone, 'success');
					profileCache = null;
					poll(true);
				}).catch(function (err) {
					toast(err.message || T.actionFailed, 'error');
					$b.prop('disabled', false);
				});
			});
		}

		var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';

		function faDigits(s) {
			return String(s).replace(/\d/g, function (d) { return FA_DIGITS.charAt(+d); });
		}

		/** Gregorian "Y-m-d[ H:i]" (site-local) to Jalali "۱۴۰۵/۰۸/۱۵". */
		function toJalali(value) {
			var m = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
			if (!m) {
				return '';
			}
			try {
				var parts = new Intl.DateTimeFormat('en-u-ca-persian-nu-latn', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'UTC' })
					.formatToParts(new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])));
				var get = function (t) { return (parts.filter(function (p) { return p.type === t; })[0] || {}).value || ''; };
				return faDigits(get('year').replace(/\D/g, '') + '/' + get('month') + '/' + get('day'));
			} catch (e) {
				return '';
			}
		}

		/**
		 * Persian WooCommerce Jalali datepicker (same as affiliate-referral filters). The calendar is moved into
		 * the chat layer (theme fullscreen hides body-level nodes) and positioned against the viewport.
		 */
		function jalaliPicker($input) {
			if (!$.fn.persianDatepicker) {
				$input.prop('readonly', false);
				return;
			}
			var input = $input[0];
			var arrow = function (d) {
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="' + d + '"/></svg>';
			};
			var ascii = String(input.value).replace(/[۰-۹]/g, function (c) { return String(FA_DIGITS.indexOf(c)); });
			var current = MCP_JDATE_RE.test(ascii) ? ascii : null;
			$input.persianDatepicker({
				theme: 'mcp',
				formatDate: 'YYYY/0M/0D',
				cellWidth: 38,
				cellHeight: 34,
				fontSize: 13,
				closeOnBlur: false,
				selectedDate: current,
				calendarPosition: { x: 0, y: 6 },
				prevArrow: arrow('m9 6 6 6-6 6'),
				nextArrow: arrow('m15 6-6 6 6 6'),
				onShow: function (calendar) {
					var rect = input.getBoundingClientRect();
					var off = $input.offset();
					var w = calendar.outerWidth();
					var h = calendar.outerHeight();
					var left = Math.max(8, Math.min(rect.right - w, document.documentElement.clientWidth - w - 8));
					var top = rect.bottom + 6;
					if (top + h > window.innerHeight - 8) {
						top = Math.max(8, rect.top - h - 6);
					}
					this.calendarPosition.x = left - off.left;
					this.calendarPosition.y = top - off.top - $input.outerHeight();
				},
				onSelect: function () {
					input.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});
			var picker = $input.data('persianDatepicker');
			if (picker && picker.calendar) {
				picker.calendar.addClass('mcp-pdp').appendTo($layer);
				$(document).on('mousedown.mcppdp touchstart.mcppdp', function (e) {
					if (picker.calendar.is(':visible') && !$(e.target).closest(picker.calendar).length && e.target !== input) {
						picker.hide();
					}
				});
			}
		}

		var MCP_JDATE_RE = /^\d{4}\/\d{1,2}\/\d{1,2}$/;

		function openFollowUp() {
			var fu = (state.ctx && state.ctx.follow_up) || {};
			var dateVal = fu.at ? toJalali(fu.at) : '';
			var timeVal = fu.at ? String(fu.at).slice(11, 16) : '09:00';
			var body = '<form class="mcp-fu-form">' +
				'<div class="mcp-fu-row">' +
					'<div><label class="mcp-label" for="mcp-fu-date">' + esc(T.followUpDate) + '</label>' +
					'<input type="text" id="mcp-fu-date" class="mcp-jdate" value="' + esc(dateVal) + '" placeholder="' + esc(toJalali(new Date().toISOString().slice(0, 10)) || '۱۴۰۵/۰۱/۰۱') + '" readonly autocomplete="off" inputmode="numeric" dir="ltr" required></div>' +
					'<div><label class="mcp-label" for="mcp-fu-time">' + esc(T.followUpTime) + '</label>' +
					'<input type="time" id="mcp-fu-time" value="' + esc(timeVal) + '" dir="ltr"></div>' +
				'</div>' +
				'<label class="mcp-label" for="mcp-fu-note">' + esc(T.followUpNote) + '</label>' +
				'<input type="text" id="mcp-fu-note" maxlength="255" value="' + esc(fu.note || '') + '">' +
				'<div class="mcp-dialog-actions">' + (fu.at ? '<button type="button" class="mcp-btn mcp-btn--ghost" data-act="clear">' + esc(T.followUpClear) + '</button>' : '') +
				'<button type="submit" class="mcp-btn">' + esc(T.save) + '</button></div></form>';
			var $d = openDialog(T.followUpSet, body);
			jalaliPicker($d.find('#mcp-fu-date'));
			var save = function (clear) {
				var payload = { note: $d.find('#mcp-fu-note').val() };
				if (clear) {
					payload.at = null;
				} else {
					payload.date = $d.find('#mcp-fu-date').val();
					payload.time = $d.find('#mcp-fu-time').val();
					if (!payload.date) {
						$d.find('#mcp-fu-date').trigger('focus').trigger('click');
						return;
					}
				}
				api('sessions/' + sessionId + '/follow-up', 'POST', payload).then(function (res) {
					state.ctx.follow_up = res.follow_up;
					renderStatus(res.state);
					closeDialog();
					toast(T.saved, 'success');
				}).catch(function (err) { toast(err.message || T.actionFailed, 'error'); });
			};
			$d.on('submit', '.mcp-fu-form', function (e) {
				e.preventDefault();
				save(false);
			});
			$d.on('click', '[data-act="clear"]', function () { save(true); });
		}

		/* ------------------------------------------------------------------
		 * Polling, seen state, hydration
		 * ---------------------------------------------------------------- */

		function markSeen() {
			if (document.hidden) {
				return;
			}
			themeApi('conversations/' + sessionId + '/messages/mark-seen', 'POST').catch(function () { /* non-critical */ });
		}

		function atBottom() {
			var el = $list[0];
			return el.scrollTop + el.clientHeight >= el.scrollHeight - 60;
		}

		function poll(force) {
			if (state.fetching || (!force && document.hidden)) {
				return;
			}
			state.fetching = true;
			var wasBottom = atBottom();
			themeApi('conversations/' + sessionId + '/messages?after_id=' + state.lastId, 'GET').then(function (res) {
				var msgs = (res && res.data && res.data.messages) || [];
				var added = 0;
				msgs.forEach(function (m) {
					if (!state.known[parseInt(m.id, 10)]) {
						appendMessage(m);
						if (parseInt(m.sender_id, 10) !== userId) {
							added++;
						}
					}
				});
				if (res && res.data && res.data.mcp_state) {
					renderStatus(res.data.mcp_state);
				}
				if (res && res.data && typeof res.data.mcp_typing === 'boolean') {
					showTyping(res.data.mcp_typing && !added);
				} else if (added) {
					showTyping(false);
				}
				if (msgs.length) {
					markSeen();
					if (wasBottom) {
						scrollBottom();
					} else if (added) {
						state.newCount += added;
						$('.chat-new-message-count').text(state.newCount);
						$('.chat-new-messages-notif').css('display', 'flex');
					}
				}
			}).catch(function () { /* next tick retries */ }).then(function () {
				state.fetching = false;
			});

			state.pollCount++;
			if (state.pollCount % 2 === 0 || force) {
				refreshMeta();
			}
		}

		function refreshMeta() {
			var ids = [];
			$list.children('.chat-message[data-message-id]').slice(-80).each(function () {
				var id = parseInt($(this).attr('data-message-id'), 10);
				if (id) {
					ids.push(id);
				}
			});
			if (!ids.length) {
				return;
			}
			api('sessions/' + sessionId + '/meta?ids=' + ids.join(','), 'GET').then(function (res) {
				var meta = (res && res.meta) || {};
				ids.forEach(function (id) {
					var next = meta[id] || {};
					var prev = state.meta[id] || {};
					if (JSON.stringify(next.reactions || []) !== JSON.stringify(prev.reactions || [])) {
						prev.reactions = next.reactions || [];
						state.meta[id] = prev;
						renderReactions($list.children('.chat-message[data-message-id="' + id + '"]'), prev.reactions);
					}
				});
				if (res && res.state) {
					renderStatus(res.state);
				}
				if (res && typeof res.typing === 'boolean') {
					showTyping(res.typing);
				}
			}).catch(function () { /* ignore */ });
		}

		function schedulePoll() {
			clearTimeout(state.polling);
			if (!chatOpen || !D.ajaxCheckMessage) {
				return;
			}
			var interval = Math.max(2, parseInt(D.ajaxCheckMessageInterval, 10) || 5) * 1000;
			state.polling = setTimeout(function () {
				poll(false);
				schedulePoll();
			}, interval);
		}

		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) {
				poll(true);
				schedulePoll();
			}
		});

		$list.on('scroll', function () {
			if (atBottom()) {
				$('.chat-new-messages-notif').fadeOut(150);
				state.newCount = 0;
			}
		});
		$root.on('click', '.chat-new-messages-notif', function () {
			scrollBottom();
			$(this).fadeOut(150);
			state.newCount = 0;
		});

		// Hydrate server-rendered messages, then start.
		$list.children('.chat-message[data-message-id]').each(function () {
			var id = parseInt($(this).attr('data-message-id'), 10);
			if (id) {
				state.known[id] = true;
				state.lastId = Math.max(state.lastId, id);
			}
		});

		api('sessions/' + sessionId + '/context?with_meta=1').catch(function () { return null; }).then(function (ctx) {
			state.ctx = ctx || { can: {}, role: '' };
			if (ctx) {
				renderStatus(ctx.state);
				setupRoleTools(ctx);
			}
			var meta = (ctx && ctx.meta) || {};
			$list.children('.chat-message[data-message-id]').each(function () {
				var $el = $(this);
				enhance($el, meta[parseInt($el.attr('data-message-id'), 10)] || {});
			});
		});

		// Restore unsent messages from a previous visit/offline period.
		if (chatOpen && $composer.length) {
			loadOutbox().forEach(function (item) {
				attemptSend(pendingBubble(item), 0);
			});
		}

		scrollBottom();
		if (chatOpen) {
			markSeen();
			schedulePoll();
		}
	});
}(jQuery));
