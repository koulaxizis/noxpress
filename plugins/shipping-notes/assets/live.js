/**
 * Shipping Notes — live.js (v1.0.0)
 *
 * Pages with the block cart store (Mini-Cart block, Cart/Checkout
 * blocks): redraws every [nox_free_shipping] / noxpress/free-shipping
 * from the Store API cart data the store already holds. Classic pages
 * use WooCommerce's cart fragments instead and never need this.
 */
( function () {
	'use strict';

	var last = null;

	function draw( html ) {
		if ( html === last ) {
			return;
		}
		last = html;
		var nodes = document.querySelectorAll( 'div.shn-fs-wrap' );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].innerHTML = html;
		}
	}

	function start() {
		if ( ! window.wp || ! wp.data || ! wp.data.select ) {
			return;
		}
		var store;
		try {
			store = wp.data.select( 'wc/store/cart' );
		} catch ( e ) {
			return;
		}
		if ( ! store || ! store.getCartData ) {
			return;
		}
		var read = function () {
			try {
				var data = store.getCartData();
				var ext  = data && data.extensions && data.extensions[ 'shipping-notes' ];
				if ( ext && 'string' === typeof ext.html ) {
					draw( ext.html );
				}
			} catch ( e ) {}
		};
		wp.data.subscribe( read, 'wc/store/cart' );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
