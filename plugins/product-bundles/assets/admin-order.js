/**
 * Product Bundles — order screen box "Bundles": choices of the picked
 * bundle, then "Add bundle" adds its products with the bundle prices and
 * reloads WooCommerce's items box.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.pbnOrder || {};

	function reloadItems( orderId ) {
		var meta = window.woocommerce_admin_meta_boxes || {};
		if ( ! meta.order_item_nonce ) {
			window.location.reload();
			return;
		}
		$.post( meta.ajax_url || cfg.ajax, {
			action: 'woocommerce_load_order_items',
			order_id: orderId,
			security: meta.order_item_nonce
		} ).done( function ( html ) {
			var $box = $( '#woocommerce-order-items' ).find( '.inside' );
			if ( $box.length && 'string' === typeof html ) {
				$box.empty().append( html );
				$( document.body ).trigger( 'wc-enhanced-select-init' );
				$( '#woocommerce-order-items' ).trigger( 'wc_order_items_reloaded' );
			} else {
				window.location.reload();
			}
		} ).fail( function () {
			window.location.reload();
		} );
	}

	$( function () {
		var $box = $( '.pbn-order-box' );
		if ( ! $box.length ) {
			return;
		}
		var orderId = $box.data( 'order' );
		var $msg = $box.find( '.pbn-order-msg' );
		var $add = $box.find( '.pbn-order-add' );

		$box.on( 'change', '.pbn-order-bundle', function () {
			var bundle = $( this ).val();
			$box.find( '.pbn-order-choices' ).empty();
			$msg.text( '' );
			$add.prop( 'disabled', ! bundle );
			if ( ! bundle ) {
				return;
			}
			$.post( cfg.ajax, {
				action: 'pbn_order_choices',
				nonce: cfg.nonce,
				order_id: orderId,
				bundle: bundle
			} ).done( function ( r ) {
				if ( r && r.success ) {
					$box.find( '.pbn-order-choices' ).html( r.data.html );
				}
			} );
		} );

		$add.on( 'click', function () {
			var data = {
				action: 'pbn_order_add',
				nonce: cfg.nonce,
				order_id: orderId,
				bundle: $box.find( '.pbn-order-bundle' ).val(),
				qty: $box.find( '.pbn-order-qty' ).val()
			};
			$box.find( '.pbn-order-choice' ).each( function () {
				data[ 'choice[' + $( this ).data( 'row' ) + ']' ] = $( this ).val();
			} );
			$add.prop( 'disabled', true );
			$msg.text( '' );
			$.post( cfg.ajax, data ).done( function ( r ) {
				if ( r && r.success ) {
					$msg.text( cfg.i18n.added );
					reloadItems( orderId );
				} else {
					$msg.text( r && r.data && r.data.message ? r.data.message : cfg.i18n.error );
				}
			} ).fail( function ( x ) {
				var m = x && x.responseJSON && x.responseJSON.data && x.responseJSON.data.message;
				$msg.text( m || cfg.i18n.error );
			} ).always( function () {
				$add.prop( 'disabled', false );
			} );
		} );
	} );
}( jQuery ) );
