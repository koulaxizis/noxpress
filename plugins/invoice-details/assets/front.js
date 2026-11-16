/**
 * Invoice Details — front.js (v1.0.0), classic checkout only.
 * Shows the invoice fields when «Τιμολόγιο» is chosen and the Greek ones
 * (ΔΟΥ, activity) for a Greek billing address. No request, no storage.
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
}() );
