/**
 * Invoice Details — front.js (v1.1.0), classic checkout only.
 * Shows the invoice fields when «Τιμολόγιο» is chosen and the Greek ones
 * (ΔΟΥ, activity) for a Greek billing address. With the AADE lookup set up
 * (window.ivdAade), asks the shop for the company details of a Greek VAT
 * number once per number. No storage.
 */
( function () {
	'use strict';
	if ( window.ivdFront ) {
		return;
	}
	window.ivdFront = true;

	function update() {
		var box = document.getElementById( 'ivd-box' );
		if ( ! box ) {
			return;
		}
		var chosen = box.querySelector( 'input[name="ivd_type"]:checked' );
		var invoice = !! chosen && 'invoice' === chosen.value;
		var country = document.getElementById( 'billing_country' );
		var greek = ! country || ! country.value || 'GR' === country.value;
		var fields = box.querySelector( '.ivd-fields' );
		if ( fields ) {
			fields.style.display = invoice ? '' : 'none';
		}
		box.querySelectorAll( '.ivd-gr' ).forEach( function ( row ) {
			row.style.display = greek ? '' : 'none';
		} );
		var company = document.getElementById( 'billing_company_field' );
		if ( company ) {
			company.classList.toggle( 'validate-required', invoice );
		}
	}

	document.addEventListener( 'change', function ( e ) {
		if ( e.target && ( 'ivd_type' === e.target.name || 'billing_country' === e.target.id ) ) {
			update();
		}
	} );
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout country_to_state_changed', update );
		window.jQuery( '#billing_country' ).on( 'change', update );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', update );
	} else {
		update();
	}

	/*
	 * 1.1.0: company details from AADE for a Greek VAT number (only when the
	 * shop set it up: window.ivdAade). Fills only empty fields, or fields this
	 * script filled before; the customer can change everything.
	 */
	var aade = window.ivdAade;
	if ( ! aade || ! window.fetch || ! window.FormData ) {
		return;
	}
	var asked = '';
	function field( id ) {
		return document.getElementById( id );
	}
	function put( el, value ) {
		if ( ! el || ! value ) {
			return;
		}
		if ( '' === el.value || el.getAttribute( 'data-ivd-aade' ) === el.value ) {
			el.value = value;
			el.setAttribute( 'data-ivd-aade', value );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}
	function say( text ) {
		var vat = field( 'ivd_vat_field' );
		if ( ! vat ) {
			return;
		}
		var msg = vat.querySelector( '.ivd-aade-msg' );
		if ( ! msg ) {
			msg = document.createElement( 'small' );
			msg.className = 'ivd-aade-msg';
			msg.setAttribute( 'aria-live', 'polite' );
			msg.style.display = 'block';
			vat.appendChild( msg );
		}
		msg.textContent = text || '';
	}
	function lookup() {
		var vat = field( 'ivd_vat' );
		var country = field( 'billing_country' );
		if ( ! vat ) {
			return;
		}
		var raw = String( vat.value || '' ).toUpperCase().replace( /[\s.\-\/]/g, '' );
		var greek = /^(EL|GR)?\d{9}$/.test( raw ) && ( /^(EL|GR)/.test( raw ) || ! country || ! country.value || 'GR' === country.value );
		if ( ! greek || raw === asked ) {
			return;
		}
		asked = raw;
		var body = new FormData();
		body.append( 'action', 'ivd_aade' );
		body.append( 'nonce', aade.nonce );
		body.append( 'vat', raw );
		fetch( aade.url, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( ! r || ! r.success || ! r.data ) {
					say( '' );
					return;
				}
				put( field( aade.own ? 'ivd_company' : 'billing_company' ), r.data.name );
				put( field( 'ivd_tax_office' ), r.data.tax_office );
				put( field( 'ivd_activity' ), r.data.activity );
				put( field( 'ivd_hq' ), r.data.hq );
				say( 'inactive' === r.data.status ? aade.msg.inactive : aade.msg.found );
			} )
			.catch( function () {
				say( '' );
			} );
	}
	document.addEventListener( 'change', function ( e ) {
		if ( e.target && 'ivd_vat' === e.target.id ) {
			lookup();
		}
	} );
}() );
