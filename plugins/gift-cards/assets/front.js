/**
 * Gift Cards — front.js (v1.0.0)
 *
 * The "Have a gift card?" field in the classic cart and checkout: posts
 * the code to wc-ajax gfc_apply (POST + nonce), then lets WooCommerce
 * refresh the totals (wc_update_cart / update_checkout). The removal
 * links in the totals post to gfc_remove. Without JavaScript the cart
 * form and the links still work (plain POST / GET with nonce).
 */
( function ( $ ) {
	'use strict';
	var cfg = window.gfcCfg || {};
	if ( ! cfg.apply ) {
		return;
	}

	function refresh() {
		if ( $( 'form.woocommerce-checkout' ).length ) {
			$( document.body ).trigger( 'update_checkout' );
		} else if ( $( '.woocommerce-cart-form' ).length ) {
			$( document.body ).trigger( 'wc_update_cart' );
			// wc_update_cart needs a changed cart form; reload the totals block otherwise.
			if ( ! $( '.woocommerce-cart-form :input[name="update_cart"]' ).length ) {
				window.location.reload();
			}
		} else {
			window.location.reload();
		}
	}

	function say( $box, text, error ) {
		$box.find( '.gfc-msg' ).toggleClass( 'is-error', !! error ).html( text || '' );
	}

	function apply( $box ) {
		var $in = $box.find( '.gfc-code' );
		var code = String( $in.val() || '' ).trim();
		if ( ! code ) {
			return;
		}
		var $btn = $box.find( '.gfc-apply' ).prop( 'disabled', true );
		$.post( cfg.apply, { code: code, nonce: cfg.nonce } )
			.done( function ( r ) {
				say( $box, r && r.message, ! ( r && r.ok ) );
				if ( r && r.ok ) {
					$in.val( '' );
					refresh();
				}
			} )
			.fail( function ( x ) {
				var r = x && x.responseJSON;
				say( $box, r && r.message ? r.message : '', true );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	}

	$( document.body ).on( 'click', '.gfc-field .gfc-apply', function ( e ) {
		e.preventDefault();
		apply( $( this ).closest( '.gfc-field' ) );
	} );

	$( document.body ).on( 'keydown', '.gfc-field .gfc-code', function ( e ) {
		if ( 13 === e.which ) {
			e.preventDefault();
			apply( $( this ).closest( '.gfc-field' ) );
		}
	} );

	$( document.body ).on( 'click', '.gfc-remove', function ( e ) {
		e.preventDefault();
		var card = $( this ).data( 'card' );
		$.post( cfg.remove, { card: card, nonce: cfg.nonce } ).always( refresh );
	} );
}( jQuery ) );
