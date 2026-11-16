/**
 * Invoice Details — block.js (v1.1.0), Checkout block only, loaded only when
 * the AADE lookup is set up (window.ivdAade). When the customer types a
 * Greek VAT number in the invoice fields, asks the shop once per number for
 * the company details and fills the empty fields (or the ones it filled
 * before) through the checkout data store. No storage.
 */
( function () {
	'use strict';
	var aade = window.ivdAade;
	var wpData = window.wp && window.wp.data;
	if ( ! aade || ! wpData || ! window.fetch || ! window.FormData || window.ivdBlock ) {
		return;
	}
	window.ivdBlock = true;

	var NS = 'invoice-details/';
	var asked = '';
	var timer = 0;
	var mine = {};

	function checkout() {
		return wpData.select( 'wc/store/checkout' );
	}
	function country() {
		try {
			var c = wpData.select( 'wc/store/cart' ).getCustomerData();
			return c && c.billingAddress ? String( c.billingAddress.country || '' ) : '';
		} catch ( e ) {
			return '';
		}
	}
	function free( key, now ) {
		return '' === String( now || '' ) || mine[ key ] === now;
	}
	function notice( text ) {
		var n = wpData.dispatch( 'core/notices' );
		if ( ! n ) {
			return;
		}
		n.removeNotice( 'ivd-aade', 'wc/checkout' );
		if ( text ) {
			n.createNotice( 'info', text, { id: 'ivd-aade', context: 'wc/checkout', isDismissible: true } );
		}
	}
	function fill( d ) {
		var fields = checkout().getAdditionalFields() || {};
		var next = {};
		var map = {
			'tax-office': d.tax_office,
			activity: d.activity,
			hq: d.hq
		};
		if ( aade.own ) {
			map.company = d.name;
		}
		Object.keys( map ).forEach( function ( k ) {
			var key = NS + k;
			if ( map[ k ] && Object.prototype.hasOwnProperty.call( fields, key ) && free( key, fields[ key ] ) ) {
				next[ key ] = map[ k ];
				mine[ key ] = map[ k ];
			}
		} );
		if ( Object.keys( next ).length ) {
			wpData.dispatch( 'wc/store/checkout' ).setAdditionalFields( next );
		}
		if ( ! aade.own && d.name ) {
			var cart = wpData.select( 'wc/store/cart' ).getCustomerData();
			var now = cart && cart.billingAddress ? cart.billingAddress.company : '';
			if ( free( 'billing_company', now ) ) {
				mine.billing_company = d.name;
				wpData.dispatch( 'wc/store/cart' ).setBillingAddress( { company: d.name } );
			}
		}
		notice( 'inactive' === d.status ? aade.msg.inactive : aade.msg.found );
	}
	function lookup( raw ) {
		var body = new FormData();
		body.append( 'action', 'ivd_aade' );
		body.append( 'nonce', aade.nonce );
		body.append( 'vat', raw );
		fetch( aade.url, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( r && r.success && r.data ) {
					fill( r.data );
				}
			} )
			.catch( function () {} );
	}
	wpData.subscribe( function () {
		var store = checkout();
		if ( ! store || ! store.getAdditionalFields ) {
			return;
		}
		var fields = store.getAdditionalFields() || {};
		if ( ! fields[ NS + 'invoice' ] ) {
			return;
		}
		var raw = String( fields[ NS + 'vat' ] || '' ).toUpperCase().replace( /[\s.\-\/]/g, '' );
		var c = country();
		var greek = /^(EL|GR)?\d{9}$/.test( raw ) && ( /^(EL|GR)/.test( raw ) || '' === c || 'GR' === c );
		if ( ! greek || raw === asked ) {
			return;
		}
		asked = raw;
		clearTimeout( timer );
		timer = setTimeout( function () {
			lookup( raw );
		}, 600 );
	} );
}() );
