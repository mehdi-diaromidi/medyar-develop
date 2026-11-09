/* Affiliate Referral — admin screens (no dependencies). */
( function () {
	'use strict';

	var FA = '۰۱۲۳۴۵۶۷۸۹';

	function toEn( value ) {
		return String( value ).replace( /[۰-۹]/g, function ( d ) {
			return FA.indexOf( d );
		} ).replace( /[٠-٩]/g, function ( d ) {
			return '٠١٢٣٤٥٦٧٨٩'.indexOf( d );
		} );
	}

	function toFa( value ) {
		return String( value ).replace( /\d/g, function ( d ) {
			return FA[ d ];
		} );
	}

	function group( digits ) {
		return digits.replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}

	function each( selector, fn ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), fn );
	}

	/* ------------------------------------------------------------ page widgets */

	/* Referral edit: amount is only needed when the chosen status creates a commission. */
	function initStatusForms() {
		each( '.arp-status-form', function ( form ) {
			var select = form.querySelector( '#status_id' );
			var field = form.querySelector( '.arp-amount-field' );
			var input = field && field.querySelector( 'input' );
			if ( ! select || ! field || ! input ) {
				return;
			}
			function sync() {
				var opt = select.options[ select.selectedIndex ];
				var needed = !! opt && '1' === opt.getAttribute( 'data-commission' );
				field.hidden = ! needed;
				input.required = needed;
				input.disabled = ! needed;
			}
			select.addEventListener( 'change', sync );
			sync();
		} );
	}

	/* SMS template segment counter. */
	function initSmsCounters() {
		each( '.arp-sms-counter', function ( out ) {
			var area = document.getElementById( out.getAttribute( 'data-for' ) );
			if ( ! area ) {
				return;
			}
			function sync() {
				var text = area.value;
				var unicode = /[^\x00-\x7F]/.test( text );
				var single = unicode ? 70 : 160;
				var multi = unicode ? 67 : 153;
				var len = text.length;
				var parts = len <= single ? ( len ? 1 : 0 ) : Math.ceil( len / multi );
				out.textContent = toFa( len ) + ' کاراکتر — ' + toFa( parts ) + ' پیامک (پیش از جایگزینی متغیرها)';
			}
			area.addEventListener( 'input', sync );
			sync();
		} );
	}

	/* Service form: live "price × percent" commission preview. */
	function initCommissionPreviews() {
		each( '[data-arp-commission-preview]', function ( box ) {
			var price = box.querySelector( '[data-arp-price]' );
			var pct = box.querySelector( '[data-arp-percent]' );
			var out = box.querySelector( '[data-arp-commission-out]' );
			if ( ! price || ! pct || ! out ) {
				return;
			}
			function sync() {
				var p = parseFloat( toEn( price.value ).replace( /[^\d.]/g, '' ) ) || 0;
				var r = parseFloat( toEn( pct.value ) ) || 0;
				out.textContent = p > 0 && r > 0 ? toFa( group( String( Math.round( p * r / 100 ) ) ) ) + ' تومان' : '—';
			}
			price.addEventListener( 'input', sync );
			pct.addEventListener( 'input', sync );
			sync();
		} );
	}

	/* ------------------------------------------------------------ delegated widgets */

	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest && e.target.closest( '[data-confirm]' );
		if ( el && ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	/* Money inputs: Persian digits allowed, ASCII thousands separators while typing. */
	document.addEventListener( 'input', function ( e ) {
		var input = e.target;
		if ( ! input.matches || ! input.matches( '.arp-money' ) ) {
			return;
		}
		var raw = input.value;
		var caret = typeof input.selectionStart === 'number' ? input.selectionStart : raw.length;
		var digitsBefore = toEn( raw.slice( 0, caret ) ).replace( /\D/g, '' ).length;
		var digits = toEn( raw ).replace( /\D/g, '' ).replace( /^0+(?=\d)/, '' );
		var formatted = digits ? group( digits ) : '';
		if ( formatted === raw ) {
			return;
		}
		input.value = formatted;
		if ( typeof input.setSelectionRange !== 'function' ) {
			return;
		}
		var pos = 0;
		var seen = 0;
		while ( pos < formatted.length && seen < digitsBefore ) {
			if ( /\d/.test( formatted.charAt( pos ) ) ) {
				seen++;
			}
			pos++;
		}
		try {
			input.setSelectionRange( pos, pos );
		} catch ( err ) { /* ignore */ }
	} );

	document.addEventListener( 'change', function ( e ) {
		var select = e.target;
		if ( ! select.matches || ! select.matches( '.arp-autosubmit' ) || ! select.form ) {
			return;
		}
		if ( select.form.requestSubmit ) {
			select.form.requestSubmit();
		} else {
			select.form.submit();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var copy = e.target.closest && e.target.closest( '.arp-copy' );
		if ( copy ) {
			if ( ! navigator.clipboard ) {
				return;
			}
			navigator.clipboard.writeText( copy.getAttribute( 'data-copy' ) || '' ).then( function () {
				var label = copy.textContent;
				copy.textContent = 'کپی شد';
				setTimeout( function () {
					copy.textContent = label;
				}, 1500 );
			} );
			return;
		}

		/* SMS template: click-to-insert variables. */
		var btn = e.target.closest && e.target.closest( '.arp-insert-var' );
		if ( btn ) {
			var area = document.getElementById( btn.getAttribute( 'data-target' ) );
			var token = btn.getAttribute( 'data-var' );
			if ( ! area ) {
				return;
			}
			var start = area.selectionStart || area.value.length;
			var end = area.selectionEnd || start;
			area.value = area.value.slice( 0, start ) + token + area.value.slice( end );
			area.focus();
			area.selectionStart = area.selectionEnd = start + token.length;
			area.dispatchEvent( new Event( 'input' ) );
		}
	} );

	/* Native submit: block double clicks on the button that was pressed. */
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( e.defaultPrevented || ! form.closest || ! form.closest( '.arp-wrap' ) || form.querySelector( '[name="arp_export"]' ) ) {
			return;
		}
		var submitter = e.submitter;
		if ( submitter && 'post' === ( form.getAttribute( 'method' ) || 'get' ).toLowerCase() ) {
			setTimeout( function () {
				submitter.disabled = true;
			}, 0 );
		}
	} );

	window.addEventListener( 'pageshow', function ( e ) {
		if ( e.persisted ) {
			each( '.arp-wrap [type="submit"]:disabled', function ( btn ) {
				btn.disabled = false;
			} );
		}
	} );

	initCommissionPreviews();
	initStatusForms();
	initSmsCounters();
} )();
