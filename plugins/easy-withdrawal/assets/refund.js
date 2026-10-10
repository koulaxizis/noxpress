/**
 * Easy Withdrawal — refund.js (v1.0.0)
 *
 * On the order screen with &ewd_refund=<request>: opens WooCommerce's
 * refund form and fills in the request's quantities and a reason. Nothing
 * is refunded here: the admin reviews the amounts (and shipping) and
 * clicks WooCommerce's own refund button.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.ewdRefund !== 'object' || ! window.ewdRefund.items ) {
		return;
	}

	$( function () {
		var $items = $( '#woocommerce-order-items' );
		var $open  = $items.find( 'button.refund-items' );
		if ( ! $open.length ) {
			return;
		}
		$open.trigger( 'click' );

		$.each( window.ewdRefund.items, function ( itemId, qty ) {
			var $row   = $items.find( 'tr.item[data-order_item_id="' + parseInt( itemId, 10 ) + '"]' );
			var $input = $row.find( 'input.refund_order_item_qty' );
			if ( $input.length ) {
				$input.val( parseInt( qty, 10 ) ).trigger( 'change' );
			}
		} );

		var $reason = $( '#refund_reason' );
		if ( $reason.length && ! $reason.val() ) {
			$reason.val( window.ewdRefund.reason );
		}

		if ( $items.length && $items[ 0 ].scrollIntoView ) {
			$items[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	} );
}( jQuery ) );
