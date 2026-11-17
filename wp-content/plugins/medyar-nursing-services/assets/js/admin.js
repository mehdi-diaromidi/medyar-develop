/* Medyar Nursing Services — admin (ARP-standard, no deps). */
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

	function group( digits ) {
		return digits.replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}

	function each( selector, fn ) {
		Array.prototype.forEach.call( document.querySelectorAll( selector ), fn );
	}

	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest && e.target.closest( '[data-confirm]' );
		if ( el && ! window.confirm( el.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		var input = e.target;
		if ( ! input.matches || ! input.matches( '.mns-money' ) ) {
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
		if ( ! select.matches || ! select.matches( '.mns-autosubmit' ) || ! select.form ) {
			return;
		}
		if ( select.form.requestSubmit ) {
			select.form.requestSubmit();
		} else {
			select.form.submit();
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( e.defaultPrevented || ! form.closest || ! form.closest( '.mns-wrap' ) || form.querySelector( '[name="mns_export"]' ) ) {
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
			each( '.mns-wrap [type="submit"]:disabled', function ( btn ) {
				btn.disabled = false;
			} );
		}
	} );

	window.MNSAdmin = window.MNSAdmin || {};
} )();
