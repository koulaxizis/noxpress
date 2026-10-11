/**
 * Store Pickup — checkout.js (v1.0.0)
 *
 * Classic checkout only: while "Store pickup" is the chosen shipping
 * method, the shipping address section is hidden and "Ship to a different
 * address" is unticked (WooCommerce then uses the billing address and
 * refreshes the order review by itself). Choosing another method shows the
 * section again. The server ignores the shipping address too
 * (PKP_Checkout::drop_shipping_address), so the order is right without
 * this script.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	var ID = 'nox_store_pickup:';

	function pickupChosen() {
		var found = false;
		$( 'select.shipping_method, input.shipping_method[type="radio"]:checked, input.shipping_method[type="hidden"]' ).each( function () {
			if ( 0 === String( $( this ).val() || '' ).indexOf( ID ) ) {
				found = true;
			}
		} );
		return found;
	}

	function sync() {
		var $fields = $( '.woocommerce-shipping-fields' );
		if ( ! $fields.length ) {
			return;
		}
		if ( pickupChosen() ) {
			var $box = $( '#ship-to-different-address-checkbox' );
			if ( $box.is( ':checked' ) ) {
				$box.prop( 'checked', false ).trigger( 'change' );
			}
			$fields.hide();
		} else {
			$fields.show();
		}
	}

	$( document.body ).on( 'updated_checkout', sync );
	$( sync );
}( window.jQuery ) );
