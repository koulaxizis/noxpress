/**
 * Checkout Extras — front.js (v1.0.0)
 *
 * Classic checkout:
 *  - the gift message shows only while "This is a gift" is ticked (without
 *    this script it simply stays visible);
 *  - a character counter under the message;
 *  - with a gift fee, ticking the box refreshes the order review
 *    (WooCommerce's own update_checkout), so the fee line appears.
 * Thank-you page:
 *  - the "Copy" button of the coupon code (hidden without this script).
 * No requests of its own, no cookies, no storage.
 */
( function () {
	'use strict';

	function giftSync( box ) {
		var check = box.querySelector( 'input[name="cke_gift"]' );
		var more = box.querySelector( '.cke-gift__more' );
		if ( check && more ) {
			more.hidden = ! check.checked;
		}
	}

	function giftCount( box ) {
		var area = box.querySelector( 'textarea[name="cke_gift_message"]' );
		var used = box.querySelector( '.cke-gift__used' );
		if ( area && used ) {
			used.textContent = String( Array.from( area.value ).length );
		}
	}

	function giftInit() {
		var box = document.getElementById( 'cke-gift' );
		if ( ! box || box.getAttribute( 'data-cke-ready' ) ) {
			return;
		}
		box.setAttribute( 'data-cke-ready', '1' );
		box.classList.add( 'cke-js' );
		giftSync( box );
		giftCount( box );
	}

	document.addEventListener( 'change', function ( ev ) {
		var t = ev.target;
		if ( ! t || 'cke_gift' !== t.name ) {
			return;
		}
		var box = t.closest( '.cke-gift' );
		if ( ! box ) {
			return;
		}
		giftSync( box );
		if ( '1' === box.getAttribute( 'data-fee' ) && window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	} );

	document.addEventListener( 'input', function ( ev ) {
		var t = ev.target;
		if ( t && 'cke_gift_message' === t.name ) {
			var box = t.closest( '.cke-gift' );
			if ( box ) {
				giftCount( box );
			}
		}
	} );

	// The "before submit" position is re-rendered by every checkout refresh.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', giftInit );
	}

	function copyInit() {
		var buttons = document.querySelectorAll( '.cke-coupon__copy' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			if ( ! navigator.clipboard || ! window.isSecureContext ) {
				return;
			}
			btn.hidden = false;
			btn.addEventListener( 'click', function () {
				var label = btn.textContent;
				navigator.clipboard.writeText( btn.getAttribute( 'data-code' ) || '' ).then( function () {
					btn.textContent = btn.getAttribute( 'data-done' ) || label;
					window.setTimeout( function () {
						btn.textContent = label;
					}, 2000 );
				} );
			} );
		} );
	}

	function ready() {
		giftInit();
		copyInit();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
}() );
