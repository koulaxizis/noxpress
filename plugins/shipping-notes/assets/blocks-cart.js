/**
 * Shipping Notes — blocks-cart.js (v1.0.0)
 *
 * Cart and Checkout blocks: draws the free-shipping bar in WooCommerce's
 * ExperimentalOrderMeta slot. The HTML comes from the Store API cart
 * response (extensions["shipping-notes"].html), which WooCommerce refreshes
 * after every cart change: no request of our own.
 */
( function () {
	'use strict';

	if ( ! window.wp || ! wp.plugins || ! wp.element || ! window.wc || ! wc.blocksCheckout || ! wc.blocksCheckout.ExperimentalOrderMeta ) {
		return;
	}

	var el = wp.element.createElement;

	function Bar( props ) {
		var ext = ( props && props.extensions && props.extensions[ 'shipping-notes' ] ) || {};
		var ctx = props && props.context;
		if ( ! ext.html ) {
			return null;
		}
		if ( ( 'woocommerce/cart' === ctx && ! ext.cart ) || ( 'woocommerce/checkout' === ctx && ! ext.checkout ) ) {
			return null;
		}
		return el( 'div', { className: 'shn-fs-block', dangerouslySetInnerHTML: { __html: ext.html } } );
	}

	wp.plugins.registerPlugin( 'shipping-notes', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el( wc.blocksCheckout.ExperimentalOrderMeta, null, el( Bar, null ) );
		}
	} );
}() );
