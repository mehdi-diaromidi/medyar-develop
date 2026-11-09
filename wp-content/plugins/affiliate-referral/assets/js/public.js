(function () {
	function toFaDigits(value) {
		return String(value).replace(/\d/g, function (d) {
			return '۰۱۲۳۴۵۶۷۸۹'[d];
		});
	}

	function bindCounter(inputId, countId, max) {
		var input = document.getElementById(inputId);
		var count = document.getElementById(countId);

		if (!input || !count) return;
		var update = function () {
			var len = input.value.length;
			if (len > max) {
				input.value = input.value.slice(0, max);
				len = max;
			}
			count.textContent = toFaDigits(len);
		};
		input.addEventListener('input', update);
		update();
	}

	bindCounter('arp-desc', 'arp-desc-count', 400);
	bindCounter('arp-about', 'arp-about-count', 200);

	var panel = window.arpPanel || null;

	/** Auth failures keep their HTTP status, but a proxy may replace the JSON body. */
	function statusError(status) {
		var err = new Error(panel.i18n.generic);
		err.data = {};
		if (status === 401) {
			err.message = panel.i18n.expired;
			err.data.redirect = panel.loginUrl;
		} else if (status === 403) {
			err.message = panel.i18n.forbidden;
		}
		return err;
	}

	/** Resolve a WP JSON response ({success, data}) or reject with a user-facing message. */
	function parseJson(response) {
		return response.json().catch(function () {
			throw statusError(response.status);
		}).then(function (json) {
			if (!json || !json.success) {
				var data = (json && json.data) || {};
				var err = new Error(data.message || panel.i18n.generic);
				err.data = data;
				throw err;
			}
			return json.data || {};
		});
	}

	function request(url, options) {
		return fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, options || {}))
			.catch(function (e) {
				if (e && e.name === 'AbortError') throw e;
				throw new Error(panel.i18n.network);
			})
			.then(parseJson);
	}

	/**
	 * Show a notice inside a form's live region. `link` ({url,label}) is appended as a real anchor.
	 */
	function showNotice(box, type, message, link) {
		if (!box) return;
		box.className = 'arp-form-notice arp-notice' + (type === 'error' ? ' arp-notice--error' : '');
		box.setAttribute('role', type === 'error' ? 'alert' : 'status');
		box.textContent = message;
		if (link && link.url) {
			var a = document.createElement('a');
			a.href = link.url;
			a.className = 'arp-notice__link';
			a.textContent = link.label;
			box.appendChild(document.createTextNode(' '));
			box.appendChild(a);
		}
		box.hidden = false;
		var rect = box.getBoundingClientRect();
		if (rect.top < 80 || rect.bottom > window.innerHeight) {
			box.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
		box.focus({ preventScroll: true });
	}

	function clearFieldErrors(form) {
		form.querySelectorAll('.is-invalid').forEach(function (el) {
			el.classList.remove('is-invalid');
		});
		form.querySelectorAll('[aria-invalid="true"]').forEach(function (el) {
			el.removeAttribute('aria-invalid');
		});
	}

	function markFieldError(form, name) {
		if (!name) return;
		var field = form.elements[name];
		if (!field || !field.nodeType) return;
		field.setAttribute('aria-invalid', 'true');
		var block = field.closest('.arp-field-block') || field.closest('p');
		if (block) block.classList.add('is-invalid');
		if (field.type === 'hidden' || field.type === 'file') {
			var visible = block && block.querySelector('input:not([type="hidden"]), .arp-select__trigger');
			if (visible) visible.focus({ preventScroll: true });
			return;
		}
		var trigger = field.hasAttribute('data-arp-select') && block ? block.querySelector('.arp-select__trigger') : null;
		(trigger || field).focus({ preventScroll: true });
	}

	function setBusy(form, busy) {
		var btn = form.querySelector('[type="submit"]');
		form.classList.toggle('is-busy', busy);
		form.setAttribute('aria-busy', busy ? 'true' : 'false');
		if (!btn) return;
		btn.disabled = busy;
		btn.classList.toggle('is-loading', busy);
	}

	function resetForm(form) {
		Array.prototype.forEach.call(form.elements, function (el) {
			if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return;
			if (el.tagName === 'SELECT') {
				el.selectedIndex = 0;
				el.dispatchEvent(new Event('change', { bubbles: true }));
			} else {
				el.value = '';
				el.dispatchEvent(new Event('input', { bubbles: true }));
			}
		});
	}

	function replaceAvatar(slot, html) {
		var holder = document.querySelector('[data-arp-avatar="' + slot + '"]');
		if (!holder || !html) return;
		var tpl = document.createElement('template');
		tpl.innerHTML = html.trim();
		var next = tpl.content.querySelector('img');
		var current = holder.querySelector('img');
		if (next && current) current.replaceWith(next);
	}

	function applyProfile(profile) {
		Object.keys(profile).forEach(function (key) {
			document.querySelectorAll('[data-arp-bind="' + key + '"]').forEach(function (el) {
				el.textContent = profile[key];
				el.hidden = profile[key] === '';
			});
		});
		replaceAvatar('hero', profile.avatar_hero);
		replaceAvatar('side', profile.avatar_side);
		if (Object.prototype.hasOwnProperty.call(profile, 'has_avatar')) {
			document.querySelectorAll('[data-arp-avatar-remove]').forEach(function (btn) {
				btn.hidden = !profile.has_avatar;
			});
		}
	}

	/** Classic multipart signup: native POST (no AJAX) with loading + double-submit guard. */
	function initClassicSignupForm() {
		document.querySelectorAll('form.arp-signup-form:not([data-arp-ajax])').forEach(function (form) {
			form.addEventListener('submit', function (e) {
				if (form.classList.contains('is-busy')) {
					e.preventDefault();
					return;
				}
				form.classList.add('is-busy');
				form.setAttribute('aria-busy', 'true');
				var btn = form.querySelector('[type="submit"]');
				if (btn) {
					btn.classList.add('is-loading');
					setTimeout(function () {
						btn.disabled = true;
					}, 0);
				}
			});
		});
	}

	/** Panel forms: POST to admin-ajax; the same form still works without JS via its arp_action fallback. */
	function initAjaxForms() {
		if (!panel || !window.fetch || !window.FormData) return;

		document.querySelectorAll('form[data-arp-ajax]').forEach(function (form) {
			var notice = form.querySelector('.arp-form-notice');

			form.addEventListener('input', function (e) {
				var block = e.target.closest && e.target.closest('.is-invalid');
				if (block) {
					block.classList.remove('is-invalid');
					e.target.removeAttribute('aria-invalid');
				}
			});

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				if (form.classList.contains('is-busy')) return;

				var body = new FormData(form);
				body.delete('arp_action');
				body.set('action', form.getAttribute('data-arp-ajax'));

				clearFieldErrors(form);
				setBusy(form, true);

				request(panel.ajaxUrl, { method: 'POST', body: body })
					.then(function (data) {
						if (form.hasAttribute('data-arp-reset')) resetForm(form);
						if (data.profile) {
							applyProfile(data.profile);
							var file = form.querySelector('input[type="file"]');
							if (file) file.value = '';
						}
						showNotice(notice, 'success', data.message, data.link);
						if (data.redirect) {
							setTimeout(function () {
								window.location.assign(data.redirect);
							}, 1200);
						}
					})
					.catch(function (err) {
						var data = err.data || {};
						showNotice(notice, 'error', err.message);
						markFieldError(form, data.field);
						if (data.redirect) {
							setTimeout(function () {
								window.location.assign(data.redirect);
							}, 1800);
						}
					})
					.then(function () {
						setBusy(form, false);
					});
			});
		});
	}

	/** Referrals / commissions: filters, search and pager refresh the results block in place. */
	function initAjaxLists() {
		if (!panel || !window.fetch || !window.URLSearchParams || !window.history.pushState) return;

		document.querySelectorAll('[data-arp-results]').forEach(function (box) {
			var view = box.getAttribute('data-arp-results');
			var form = document.querySelector('form[data-arp-list="' + view + '"]');
			var controller = null;
			var syncing = false;

			function formUrl() {
				var params = new URLSearchParams();
				new FormData(form).forEach(function (value, key) {
					if (String(value).trim() !== '') params.append(key, value);
				});
				var url = new URL(form.action || window.location.href, window.location.href);
				url.search = params.toString();
				return url;
			}

			function syncForm(url) {
				if (!form) return;
				var params = new URL(url, window.location.href).searchParams;
				syncing = true;
				try {
					Array.prototype.forEach.call(form.elements, function (el) {
						if (!el.name || el.type === 'hidden' || el.type === 'submit') return;
						var value = params.get(el.name) || '';
						if (el.value === value) return;
						el.value = value;
						if (el.tagName === 'SELECT') el.dispatchEvent(new Event('change', { bubbles: true }));
						if (el.hasAttribute('data-arp-jdate')) setPickerDate(el, value);
					});
				} finally {
					syncing = false;
				}
			}

			var reset = form ? form.querySelector('[data-arp-filters-reset]') : null;

			function syncReset(url) {
				if (!reset) return;
				var params = new URL(url, window.location.href).searchParams;
				var active = Array.prototype.some.call(form.elements, function (el) {
					return el.name && el.type !== 'hidden' && el.type !== 'submit' && (params.get(el.name) || '').trim() !== '';
				});
				reset.hidden = !active;
			}

			if (reset) {
				reset.addEventListener('click', function (e) {
					var link = e.target.closest('a');
					if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
					e.preventDefault();
					syncForm(link.href);
					load(link.href, true);
				});
			}

			function load(url, push) {
				var target = new URL(url, window.location.href);
				var params = new URLSearchParams(target.search);
				params.set('action', 'arp_panel_list');
				params.set('view', view);
				params.set('nonce', panel.listNonce);
				if (box.hasAttribute('data-arp-context')) params.set('ctx', box.getAttribute('data-arp-context'));

				if (controller) controller.abort();
				controller = window.AbortController ? new AbortController() : null;
				box.classList.add('is-loading');
				box.setAttribute('aria-busy', 'true');

				request(panel.ajaxUrl + '?' + params.toString(), { signal: controller ? controller.signal : undefined })
					.then(function (data) {
						box.innerHTML = data.html;
						syncReset(target.href);
						if (push) window.history.pushState({ arpList: view }, '', target.href);
						if (box.getBoundingClientRect().top < 0) {
							box.scrollIntoView({ behavior: 'smooth', block: 'start' });
						}
					})
					.catch(function (err) {
						if (err && err.name === 'AbortError') return;
						var msg = document.createElement('div');
						msg.className = 'arp-notice arp-notice--error arp-results-error';
						msg.setAttribute('role', 'alert');
						msg.textContent = err.message;
						var old = box.querySelector('.arp-results-error');
						if (old) old.remove();
						box.prepend(msg);
					})
					.then(function () {
						box.classList.remove('is-loading');
						box.setAttribute('aria-busy', 'false');
					});
			}

			box.addEventListener('click', function (e) {
				var link = e.target.closest('.arp-refs-pager a');
				if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
				e.preventDefault();
				load(link.href, true);
			});

			if (form) {
				form.addEventListener('submit', function (e) {
					e.preventDefault();
					if (syncing) return;
					load(formUrl().href, true);
				});
			}

			window.addEventListener('popstate', function () {
				syncForm(window.location.href);
				load(window.location.href, false);
			});
		});
	}

	var JDATE_RE = /^\d{4}\/\d{1,2}\/\d{1,2}$/;

	function pickerOf(input) {
		var $ = window.jQuery;
		return $ && $.fn.persianDatepicker ? $(input).data('persianDatepicker') : null;
	}

	/** Point an existing picker at `value` (Jalali Y/m/d) or back to today when empty. */
	function setPickerDate(input, value) {
		var picker = pickerOf(input);
		if (!picker) return;
		var valid = JDATE_RE.test(value);
		picker.options.selectedDate = valid ? value : null;
		picker.persianDate = valid ? picker.now().parse(value) : picker.now();
		picker.persianDate.formatDate = picker.options.formatDate;
		picker.render();
	}

	function currentJalaliYear() {
		try {
			var year = parseInt(new Intl.DateTimeFormat('en-u-ca-persian', { year: 'numeric' }).format(new Date()), 10);
			if (year > 1300) return year;
		} catch (e) {}
		return new Date().getFullYear() - 621;
	}

	/** Persian WooCommerce's Jalali datepicker on [data-arp-jdate]; plain typed input when it isn't loaded. */
	function initJalaliPickers() {
		var inputs = document.querySelectorAll('input[data-arp-jdate]');
		if (!inputs.length) return;

		var $ = window.jQuery;
		if (!$ || !$.fn.persianDatepicker) {
			inputs.forEach(function (input) {
				input.readOnly = false;
			});
			return;
		}

		var thisYear = currentJalaliYear();
		var arrow = function (d) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="' + d + '"/></svg>';
		};

		inputs.forEach(function (input) {
			var options = {
				theme: 'arp',
				formatDate: 'YYYY/0M/0D',
				cellWidth: 38,
				cellHeight: 34,
				fontSize: 13,
				closeOnBlur: false,
				calendarPosition: { x: 0, y: 6 },
				prevArrow: arrow('m9 6 6 6-6 6'),
				nextArrow: arrow('m15 6-6 6 6 6'),
				onShow: function (calendar) {
					// Right-align with the field (RTL) and keep the calendar inside the viewport.
					var rect = input.getBoundingClientRect();
					var width = calendar.outerWidth();
					var maxLeft = document.documentElement.clientWidth - width - 8;
					var left = Math.max(8, Math.min(rect.right - width, maxLeft));
					this.calendarPosition.x = left - rect.left;
				},
				onSelect: function () {
					this.selectedDate = input.value;
					input.dispatchEvent(new Event('change', { bubbles: true }));
				},
			};
			if (input.getAttribute('data-arp-jdate') === 'birthday') {
				options.selectableYears = [];
				for (var y = thisYear; y >= 1300; y--) options.selectableYears.push(y);
			}
			if (JDATE_RE.test(input.value)) options.selectedDate = input.value;

			$(input).persianDatepicker(options);

			input.addEventListener('keydown', function (e) {
				if ((e.key === 'Backspace' || e.key === 'Delete') && input.value) {
					e.preventDefault();
					input.value = '';
					setPickerDate(input, '');
					input.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});
		});
	}

	function submitForm(form) {
		if (form.requestSubmit) {
			form.requestSubmit();
		} else {
			form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })) && form.submit();
		}
	}

	// Profile avatar: live preview + client-side type/size checks (5MB, images only).
	(function initProfileAvatar() {
		var input = document.getElementById('arp-avatar-input');
		if (!input) return;

		var maxBytes = 5 * 1024 * 1024;
		var allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

		var form = input.form;
		var notice = form && form.querySelector('.arp-form-notice');
		var holder = document.querySelector('[data-arp-avatar="hero"]');

		var removeBtn = document.querySelector('[data-arp-avatar-remove]');
		var canAjax = !!(panel && window.fetch && form && form.elements._wpnonce);

		function reject(message) {
			input.value = '';
			if (notice) {
				showNotice(notice, 'error', message);
			} else {
				window.alert(message);
			}
		}

		function setBusyAvatar(busy) {
			if (holder) {
				holder.classList.toggle('is-loading', busy);
				holder.setAttribute('aria-busy', busy ? 'true' : 'false');
			}
			if (removeBtn) removeBtn.disabled = busy;
		}

		/**
		 * Downscale to AVATAR_EDGE px JPEG before upload: phone photos are several MB, an avatar
		 * renders at most 300px. Resolves with the original file whenever shrinking isn't a win.
		 */
		var AVATAR_EDGE = 640;
		function shrinkImage(file) {
			return new Promise(function (resolve) {
				if (file.type === 'image/gif' || !window.URL || !document.createElement('canvas').toBlob) {
					resolve(file);
					return;
				}
				var url = URL.createObjectURL(file);
				var img = new Image();
				img.onload = function () {
					var scale = Math.min(1, AVATAR_EDGE / Math.max(img.naturalWidth, img.naturalHeight));
					var canvas = document.createElement('canvas');
					canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
					canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
					var ctx = canvas.getContext('2d');
					ctx.fillStyle = '#fff';
					ctx.fillRect(0, 0, canvas.width, canvas.height);
					ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
					URL.revokeObjectURL(url);
					canvas.toBlob(function (blob) {
						resolve(blob && blob.size < file.size ? blob : file);
					}, 'image/jpeg', 0.88);
				};
				img.onerror = function () {
					URL.revokeObjectURL(url);
					resolve(file);
				};
				img.src = url;
			});
		}

		input.addEventListener('change', function () {
			var file = input.files && input.files[0];
			if (!file) return;
			if (allowed.indexOf(file.type) === -1) {
				reject('فقط فایل تصویری (JPG، PNG، WEBP، GIF) مجاز است.');
				return;
			}
			// Without AJAX the original file is sent with the profile form on save.
			if (!canAjax) {
				if (file.size > maxBytes) reject('حجم تصویر نباید بیشتر از ۵ مگابایت باشد.');
				return;
			}

			var img = document.querySelector('.arp-profile-hero__avatar img');
			var previous = img ? img.getAttribute('src') : '';
			var previousSrcset = img ? img.getAttribute('srcset') : null;
			if (img) {
				img.src = URL.createObjectURL(file);
				img.removeAttribute('srcset');
			}
			setBusyAvatar(true);

			shrinkImage(file)
				.then(function (upload) {
					if (upload.size > maxBytes) {
						throw new Error('حجم تصویر نباید بیشتر از ۵ مگابایت باشد.');
					}
					var name = upload === file ? file.name : (file.name.replace(/\.[^.]+$/, '') || 'avatar') + '.jpg';
					var body = new FormData();
					body.set('action', 'arp_upload_avatar');
					body.set('_wpnonce', form.elements._wpnonce.value);
					body.set('arp_avatar', upload, name);
					return request(panel.ajaxUrl, { method: 'POST', body: body });
				})
				.then(function (data) {
					applyProfile(data.profile);
					showNotice(notice, 'success', data.message);
				})
				.catch(function (err) {
					if (img && previous) {
						img.src = previous;
						if (previousSrcset) img.setAttribute('srcset', previousSrcset);
					}
					showNotice(notice, 'error', err.message);
				})
				.then(function () {
					input.value = '';
					setBusyAvatar(false);
				});
		});

		if (removeBtn && canAjax) {
			removeBtn.addEventListener('click', function () {
				if (!window.confirm('تصویر پروفایل حذف شود؟')) return;
				var body = new FormData();
				body.set('action', 'arp_remove_avatar');
				body.set('_wpnonce', form.elements._wpnonce.value);
				setBusyAvatar(true);
				request(panel.ajaxUrl, { method: 'POST', body: body })
					.then(function (data) {
						applyProfile(data.profile);
						showNotice(notice, 'success', data.message);
					})
					.catch(function (err) {
						showNotice(notice, 'error', err.message);
					})
					.then(function () {
						setBusyAvatar(false);
					});
			});
		}
	})();

	// Desktop sticky sidebar: offset below the theme's fixed (Elementor sticky) header, whose height can change on scroll.
	(function initStickySidebar() {
		var sidebar = document.querySelector('.arp-dash[data-arp-sticky] .arp-dash__sidebar');
		if (!sidebar || !window.matchMedia || !window.requestAnimationFrame) return;
		var desktop = window.matchMedia('(min-width: 1024px)');
		var main = document.querySelector('.arp-dash__main');
		var headerSelector = '.elementor-location-header .elementor-sticky, #masthead .elementor-sticky';
		var queued = false;
		var last = '';

		function update() {
			queued = false;
			if (!desktop.matches) {
				sidebar.classList.remove('is-sticky');
				return;
			}
			// Fixed bars stack from the top (admin bar, then the sticky header below it); chain them in order.
			var rects = [];
			var adminBar = document.getElementById('wpadminbar');
			if (adminBar && getComputedStyle(adminBar).position === 'fixed') rects.push(adminBar.getBoundingClientRect());
			document.querySelectorAll(headerSelector).forEach(function (el) {
				if (!el.classList.contains('elementor-sticky--active') || getComputedStyle(el).position !== 'fixed') return;
				rects.push(el.getBoundingClientRect());
			});
			rects.sort(function (a, b) { return a.top - b.top; });
			var bottom = 0;
			rects.forEach(function (r) {
				if (r.height > 0 && r.top <= bottom + 24 && r.bottom > bottom) bottom = r.bottom;
			});
			var offset = Math.round(bottom + (bottom ? 16 : 24));
			var next = offset + 'px';
			if (next !== last) {
				last = next;
				sidebar.style.setProperty('--arp-sticky-top', next);
			}
			// Sticking is only useful when the whole menu fits under the header and the content is taller than the menu.
			var height = sidebar.offsetHeight;
			var fits = offset + height + 16 <= window.innerHeight && (!main || main.offsetHeight > height);
			sidebar.classList.toggle('is-sticky', fits);
		}

		function schedule() {
			if (queued) return;
			queued = true;
			window.requestAnimationFrame(update);
		}

		window.addEventListener('scroll', schedule, { passive: true });
		window.addEventListener('resize', schedule);
		if (window.ResizeObserver) {
			var observer = new ResizeObserver(schedule);
			observer.observe(sidebar);
			if (main) observer.observe(main);
			document.querySelectorAll(headerSelector).forEach(function (el) { observer.observe(el); });
		}
		document.querySelectorAll(headerSelector).forEach(function (el) {
			el.addEventListener('transitionend', schedule);
		});
		update();
	})();

	// Mobile sidebar drawer — same pattern as my-account.
	(function initSidebarMobile() {
		var sidebar = document.querySelector('.arp-dash__sidebar');
		var expand = document.querySelector('.arp-dash__sidebar-mobile-expand');
		var closeBtn = document.querySelector('.arp-dash__sidebar-close');
		if (!sidebar || !expand) return;

		// Reparent to body so position:fixed is never trapped by a transformed ancestor.
		if (expand.parentElement !== document.body) {
			document.body.appendChild(expand);
		}

		// Own backdrop: the theme's overlay toggles display, which can't be transitioned.
		var backdrop = document.createElement('div');
		backdrop.className = 'arp-dash-backdrop';
		backdrop.setAttribute('aria-hidden', 'true');
		document.body.appendChild(backdrop);

		var root = document.documentElement;
		var gutterSupported = !!(window.CSS && CSS.supports && CSS.supports('scrollbar-gutter', 'stable'));

		function isOpen() {
			return sidebar.classList.contains('mobile-expanded');
		}

		function lockScroll(lock) {
			if (!lock) {
				root.classList.remove('arp-drawer-open');
				document.body.style.removeProperty('padding-inline-end');
				return;
			}
			var scrollbar = window.innerWidth - root.clientWidth;
			root.classList.add('arp-drawer-open');
			if (!gutterSupported && scrollbar > 0) {
				document.body.style.setProperty('padding-inline-end', scrollbar + 'px');
			}
		}

		function openSidebar() {
			if (isOpen()) return;
			sidebar.classList.add('mobile-expanded');
			backdrop.classList.add('is-visible');
			lockScroll(true);
			expand.setAttribute('aria-expanded', 'true');
			if (closeBtn) closeBtn.focus({ preventScroll: true });
		}

		function closeSidebar() {
			if (!isOpen()) return;
			sidebar.classList.remove('mobile-expanded');
			backdrop.classList.remove('is-visible');
			lockScroll(false);
			expand.setAttribute('aria-expanded', 'false');
			if (sidebar.contains(document.activeElement)) expand.focus({ preventScroll: true });
		}

		expand.setAttribute('aria-expanded', 'false');
		expand.addEventListener('click', openSidebar);
		backdrop.addEventListener('click', closeSidebar);
		if (closeBtn) {
			closeBtn.addEventListener('click', closeSidebar);
		}
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') closeSidebar();
		});
		// Leaving the mobile layout with the drawer open must not keep the page scroll-locked.
		if (window.matchMedia) {
			var mobile = window.matchMedia('(max-width: 767px)');
			var onChange = function (e) {
				if (!e.matches) closeSidebar();
			};
			if (mobile.addEventListener) {
				mobile.addEventListener('change', onChange);
			} else if (mobile.addListener) {
				mobile.addListener(onChange);
			}
		}
	})();

	// Iranian mobile: strip 98/0098 country code, auto-prepend 0; max 11 digits.
	(function initIranMobileInputs() {
		function toEnDigits(value) {
			return String(value).replace(/[۰-۹]/g, function (d) {
				return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);
			}).replace(/[٠-٩]/g, function (d) {
				return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);
			});
		}

		function normalize(value) {
			var digits = toEnDigits(value).replace(/\D/g, '');
			// No Iranian mobile starts with 98, so a leading 98 is always the country code.
			if (digits.indexOf('0098') === 0) {
				digits = '0' + digits.slice(4);
			} else if (digits.indexOf('98') === 0) {
				digits = '0' + digits.slice(2);
			} else if (digits.length >= 2 && digits.charAt(0) !== '0') {
				digits = '0' + digits;
			}
			if (digits.length > 11) {
				digits = digits.slice(0, 11);
			}
			return digits;
		}

		document.querySelectorAll('input[data-arp-iran-mobile], input[name="patient_phone"]').forEach(function (input) {
			// Room for pasted "+98 912 345 6789"; normalize() trims to 11 digits.
			input.setAttribute('maxlength', '20');
			input.addEventListener('input', function () {
				var next = normalize(input.value);
				if (input.value !== next) {
					input.value = next;
				}
			});
		});
	})();

	initJalaliPickers();

	// Filter dates submit their form once picked or cleared.
	document.querySelectorAll('.arp-refs-filters input[data-arp-jdate]').forEach(function (input) {
		input.addEventListener('change', function () {
			if (input.form) submitForm(input.form);
		});
	});

	initClassicSignupForm();
	initAjaxForms();
	initAjaxLists();
	initRowToggles();

	/** Expandable table rows: the chevron (or a click anywhere on the row) shows the details row below it. */
	function initRowToggles() {
		document.addEventListener('click', function (e) {
			var row = e.target.closest('.arp-table--expandable .arp-table__row');
			if (!row) return;
			var btn = row.querySelector('.arp-row-toggle');
			if (!btn) return;
			if (!e.target.closest('.arp-row-toggle')) {
				if (e.target.closest('a, button, input, select, textarea')) return;
				if (window.getSelection && String(window.getSelection()).trim() !== '') return;
			}
			var details = document.getElementById(btn.getAttribute('aria-controls'));
			if (!details) return;
			var open = btn.getAttribute('aria-expanded') !== 'true';
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			btn.setAttribute('aria-label', open ? 'بستن توضیحات' : 'نمایش توضیحات');
			row.classList.toggle('is-open', open);
			details.hidden = !open;
		});
	}

	function closeAllSelects(except) {
		document.querySelectorAll('.arp-select__menu.is-open').forEach(function (menu) {
			if (except && menu === except) return;
			menu.classList.remove('is-open');
			var wrap = menu.closest('.arp-field-block__control--select');
			if (wrap) wrap.classList.remove('is-open');
			var trigger = menu.parentElement && menu.parentElement.querySelector('.arp-select__trigger');
			if (trigger) trigger.setAttribute('aria-expanded', 'false');
		});
	}

	function initCustomSelects() {
		document.querySelectorAll('select[data-arp-select]').forEach(function (native) {
			if (native.dataset.arpEnhanced === '1') return;
			native.dataset.arpEnhanced = '1';

			var control = native.closest('.arp-field-block__control--select');
			if (!control) return;

			var shell = document.createElement('div');
			shell.className = 'arp-select';

			var trigger = document.createElement('button');
			trigger.type = 'button';
			trigger.className = 'arp-select__trigger';
			trigger.setAttribute('aria-haspopup', 'listbox');
			trigger.setAttribute('aria-expanded', 'false');

			var menu = document.createElement('ul');
			menu.className = 'arp-select__menu';
			menu.setAttribute('role', 'listbox');

			function syncFilled() {
				if (native.value) {
					control.classList.add('is-filled');
				} else {
					control.classList.remove('is-filled');
				}
			}

			function syncLabel() {
				var selected = native.options[native.selectedIndex];
				var text = selected ? selected.textContent.trim() : '';
				trigger.textContent = text || 'انتخاب کنید';
				trigger.classList.toggle('is-placeholder', !native.value);
				syncFilled();
			}

			var searchable = native.hasAttribute('data-arp-select-search');
			var searchItem = null;
			var searchInput = null;
			var emptyItem = null;

			if (searchable) {
				searchItem = document.createElement('li');
				searchItem.className = 'arp-select__search';
				searchItem.setAttribute('role', 'presentation');
				searchInput = document.createElement('input');
				searchInput.type = 'search';
				searchInput.className = 'arp-select__search-input';
				searchInput.placeholder = 'جستجو…';
				searchInput.autocomplete = 'off';
				searchInput.setAttribute('aria-label', 'جستجو در گزینه‌ها');
				searchItem.appendChild(searchInput);
				searchItem.addEventListener('click', function (e) {
					e.preventDefault();
					e.stopPropagation();
					searchInput.focus();
				});

				emptyItem = document.createElement('li');
				emptyItem.className = 'arp-select__empty';
				emptyItem.setAttribute('role', 'presentation');
				emptyItem.textContent = 'موردی یافت نشد';
				emptyItem.hidden = true;

				searchInput.addEventListener('input', filterOptions);
				searchInput.addEventListener('keydown', function (e) {
					if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
						e.preventDefault();
						moveActive(e.key === 'ArrowDown' ? 1 : -1);
					} else if (e.key === 'Enter') {
						e.preventDefault();
						var active = menu.querySelector('.arp-select__option.is-active') || visibleOptions()[0];
						if (active) active.click();
					} else if (e.key === 'Escape') {
						closeAllSelects();
						trigger.focus();
					}
				});
			}

			function normalize(text) {
				return String(text || '')
					.replace(/[\u064A\u0649]/g, '\u06CC')
					.replace(/\u0643/g, '\u06A9')
					.replace(/[\u200C\u200F\u200E\u064B-\u0652]/g, '')
					.replace(/\s+/g, ' ')
					.trim()
					.toLowerCase();
			}

			function visibleOptions() {
				return Array.prototype.filter.call(menu.querySelectorAll('.arp-select__option'), function (li) {
					return !li.hidden && !li.classList.contains('is-disabled');
				});
			}

			function setActive(li) {
				menu.querySelectorAll('.arp-select__option.is-active').forEach(function (el) {
					el.classList.remove('is-active');
				});
				if (!li) return;
				li.classList.add('is-active');
				li.scrollIntoView({ block: 'nearest' });
			}

			function moveActive(step) {
				var items = visibleOptions();
				if (!items.length) return;
				var current = items.indexOf(menu.querySelector('.arp-select__option.is-active'));
				var next = current < 0 ? (step > 0 ? 0 : items.length - 1) : (current + step + items.length) % items.length;
				setActive(items[next]);
			}

			function filterOptions() {
				var query = normalize(searchInput.value);
				var matches = 0;
				menu.querySelectorAll('.arp-select__option').forEach(function (li) {
					var isPlaceholder = li.dataset.value === '';
					var show = query === '' || (!isPlaceholder && normalize(li.textContent).indexOf(query) !== -1);
					li.hidden = !show;
					if (show && !isPlaceholder) matches++;
				});
				emptyItem.hidden = matches > 0;
				setActive(query ? visibleOptions()[0] : null);
			}

			function buildOptions() {
				menu.innerHTML = '';
				if (searchItem) {
					searchInput.value = '';
					menu.appendChild(searchItem);
				}
				Array.prototype.forEach.call(native.options, function (opt, index) {
					var li = document.createElement('li');
					li.className = 'arp-select__option';
					li.setAttribute('role', 'option');
					li.dataset.value = opt.value;
					li.dataset.index = String(index);
					li.textContent = opt.textContent.trim();
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
						if (li.classList.contains('is-disabled')) return;
						native.selectedIndex = index;
						native.dispatchEvent(new Event('change', { bubbles: true }));
						syncLabel();
						closeAllSelects();
					});
					menu.appendChild(li);
				});
				if (emptyItem) {
					emptyItem.hidden = true;
					menu.appendChild(emptyItem);
				}
			}

			function openMenu() {
				closeAllSelects(menu);
				buildOptions();
				menu.classList.add('is-open');
				control.classList.add('is-open');
				trigger.setAttribute('aria-expanded', 'true');
				if (searchInput) {
					menu.scrollTop = 0;
					searchInput.focus({ preventScroll: true });
				}
			}

			function toggleMenu(e) {
				e.preventDefault();
				e.stopPropagation();
				if (menu.classList.contains('is-open')) {
					closeAllSelects();
				} else {
					openMenu();
				}
			}

			trigger.addEventListener('click', toggleMenu);
			control.addEventListener('click', function (e) {
				if (e.target.closest('.arp-select__menu')) return;
				if (e.target.closest('.arp-select__trigger')) return;
				toggleMenu(e);
			});

			native.addEventListener('change', function () {
				syncLabel();
				if (native.hasAttribute('data-arp-autosubmit') && native.form) {
					submitForm(native.form);
				}
			});

			shell.appendChild(trigger);
			native.insertAdjacentElement('afterend', shell);
			control.appendChild(menu);
			buildOptions();
			syncLabel();
		});

		document.addEventListener('click', function () {
			closeAllSelects();
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') closeAllSelects();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initCustomSelects);
	} else {
		initCustomSelects();
	}

	function inviteActionRoot(btn) {
		return btn ? btn.closest('.arp-invite-card__action') : null;
	}

	function showInviteTip(btn, message) {
		var root = inviteActionRoot(btn);
		var tip = root ? root.querySelector('.arp-invite-card__tip') : null;
		if (!tip) return;
		tip.textContent = message;
		tip.hidden = false;
		tip.classList.add('is-visible');
		btn.classList.add('is-copied');
		clearTimeout(tip._arpTipTimer);
		tip._arpTipTimer = setTimeout(function () {
			tip.classList.remove('is-visible');
			tip.hidden = true;
			tip.textContent = '';
			btn.classList.remove('is-copied');
		}, 1800);
	}

	function copyText(text, btn, message) {
		if (!text) return;
		var done = function () {
			if (btn) showInviteTip(btn, message || 'کپی شد');
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done).catch(function () {
				fallbackCopy(text, done);
			});
			return;
		}
		fallbackCopy(text, done);
	}

	function fallbackCopy(text, done) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try {
			document.execCommand('copy');
			if (typeof done === 'function') done();
		} catch (err) { /* ignore */ }
		document.body.removeChild(ta);
	}

	function sharePayload(btn) {
		return {
			url: btn.getAttribute('data-share-url') || '',
			title: btn.getAttribute('data-share-title') || '',
			text: btn.getAttribute('data-share-text') || ''
		};
	}

	function nativeShareData(payload) {
		var full = {
			title: payload.title || '',
			text: payload.text || '',
			url: payload.url || ''
		};
		if (typeof navigator.canShare === 'function') {
			if (navigator.canShare(full)) return full;
			if (navigator.canShare({ url: full.url, title: full.title })) {
				return { url: full.url, title: full.title };
			}
			if (navigator.canShare({ url: full.url })) {
				return { url: full.url };
			}
			if (navigator.canShare({ text: (full.text + ' ' + full.url).trim() })) {
				return { text: (full.text + ' ' + full.url).trim() };
			}
		}
		return full;
	}

	document.querySelectorAll('.arp-copy').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (btn.classList.contains('arp-invite-card__btn')) {
				copyText(btn.getAttribute('data-copy') || '', btn, 'کپی شد');
				return;
			}
			var text = btn.getAttribute('data-copy') || '';
			if (!text) return;
			var done = function () {
				btn.classList.add('is-copied');
				var prevLabel = btn.getAttribute('aria-label');
				btn.setAttribute('aria-label', 'کپی شد');
				if (!btn.querySelector('svg')) {
					var prev = btn.textContent;
					btn.textContent = 'کپی شد';
					setTimeout(function () {
						btn.textContent = prev;
						btn.classList.remove('is-copied');
						if (prevLabel) btn.setAttribute('aria-label', prevLabel);
					}, 1400);
					return;
				}
				setTimeout(function () {
					btn.classList.remove('is-copied');
					if (prevLabel) btn.setAttribute('aria-label', prevLabel);
				}, 1400);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done).catch(function () {
					fallbackCopy(text, done);
				});
			} else {
				fallbackCopy(text, done);
			}
		});
	});

	document.querySelectorAll('.arp-invite-card__btn.arp-share').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var payload = sharePayload(btn);
			if (!payload.url) return;

			if (typeof navigator.share !== 'function') {
				showInviteTip(btn, 'در این دستگاه پشتیبانی نمی‌شود');
				return;
			}

			var data = nativeShareData(payload);
			navigator.share(data).then(function () {
				showInviteTip(btn, 'اشتراک‌گذاری شد');
			}).catch(function (err) {
				if (err && err.name === 'AbortError') return;
				showInviteTip(btn, 'اشتراک‌گذاری انجام نشد');
			});
		});
	});

	function getMainFont() {
		var root = window.getComputedStyle(document.documentElement).getPropertyValue('--main-font');
		if (root && root.trim()) {
			return root.trim().replace(/^["']|["']$/g, '');
		}
		var fromDash = document.querySelector('.arp-dash, .arp-dash-body, .arp-wrap');
		if (fromDash) {
			var computed = window.getComputedStyle(fromDash).fontFamily;
			if (computed && computed !== 'inherit') return computed;
		}
		return 'IRANYekanXFANum';
	}

	/** Integer axis with `intervals` equal steps from a 1/2/5×10^n series, covering `peak`. */
	function niceAxis(peak, intervals) {
		var step = 1;
		var bases = [1, 2, 5];
		for (var mag = 1; step * intervals < peak; mag *= 10) {
			for (var i = 0; i < bases.length; i++) {
				step = bases[i] * mag;
				if (step * intervals >= peak) break;
			}
		}
		return { step: step, max: step * intervals };
	}

	function initCharts() {
		var dataEl = document.getElementById('arp-dash-chart-data');
		if (!dataEl || typeof Chart === 'undefined') return;

		var data;
		try {
			data = JSON.parse(dataEl.textContent);
		} catch (e) {
			return;
		}

		var brand = '#0272b1';
		var chartFont = getMainFont();
		Chart.defaults.font.family = chartFont;

		var tooltipFont = {
			titleFont: { family: chartFont, size: 13, weight: '600' },
			bodyFont: { family: chartFont, size: 13, weight: '500' },
			footerFont: { family: chartFont, size: 12 },
		};

		var statusCanvas = document.getElementById('arp-chart-status');
		if (statusCanvas) {
			var statusTotal = data.status.values.reduce(function (sum, v) {
				return sum + Number(v);
			}, 0);
			var statusEmpty = statusTotal === 0;
			new Chart(statusCanvas, {
				type: 'doughnut',
				data: {
					labels: statusEmpty ? [''] : data.status.labels,
					datasets: [
						{
							data: statusEmpty ? [1] : data.status.values,
							backgroundColor: statusEmpty ? ['#E2E8F0'] : data.status.colors,
							borderWidth: 0,
							hoverOffset: statusEmpty ? 0 : 2,
						},
					],
				},
				options: {
					responsive: false,
					cutout: '70%',
					plugins: {
						legend: { display: false },
						tooltip: {
							enabled: !statusEmpty,
							rtl: true,
							titleFont: tooltipFont.titleFont,
							bodyFont: tooltipFont.bodyFont,
							footerFont: tooltipFont.footerFont,
							callbacks: {
								label: function (ctx) {
									return toFaDigits(ctx.raw);
								},
							},
						},
					},
				},
			});
		}

		var trendCanvas = document.getElementById('arp-chart-trend');
		if (trendCanvas) {
			var trendAxis = niceAxis(Math.max.apply(null, data.trend.values.concat([0])), 4);
			new Chart(trendCanvas, {
				type: 'line',
				data: {
					labels: data.trend.labels,
					datasets: [
						{
							data: data.trend.values,
							borderColor: brand,
							backgroundColor: function (context) {
								var chart = context.chart;
								var c = chart.ctx;
								var area = chart.chartArea;
								if (!area) return 'rgba(2, 114, 177, 0.12)';
								var g = c.createLinearGradient(0, area.top, 0, area.bottom);
								g.addColorStop(0, 'rgba(2, 114, 177, 0.28)');
								g.addColorStop(1, 'rgba(2, 114, 177, 0.02)');
								return g;
							},
							fill: true,
							tension: 0,
							cubicInterpolationMode: 'default',
							borderJoinStyle: 'miter',
							pointRadius: 5,
							pointHoverRadius: 6,
							pointBackgroundColor: brand,
							pointBorderColor: '#fff',
							pointBorderWidth: 2,
							borderWidth: 2.5,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { display: false },
						tooltip: {
							rtl: true,
							titleFont: tooltipFont.titleFont,
							bodyFont: tooltipFont.bodyFont,
							footerFont: tooltipFont.footerFont,
							callbacks: {
								label: function (ctx) {
									return toFaDigits(ctx.raw);
								},
							},
						},
					},
					scales: {
						x: {
							reverse: true,
							grid: { display: false, drawBorder: false },
							ticks: {
								font: { family: chartFont, size: 12 },
								color: '#a6a6a6',
							},
						},
						y: {
							min: 0,
							max: trendAxis.max,
							ticks: {
								stepSize: trendAxis.step,
								font: { family: chartFont, size: 12 },
								color: '#a6a6a6',
								callback: function (v) {
									return toFaDigits(v);
								},
							},
							grid: {
								color: 'rgba(226, 232, 240, 0.7)',
								drawBorder: false,
							},
						},
					},
				},
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initCharts);
	} else {
		initCharts();
	}
})();
