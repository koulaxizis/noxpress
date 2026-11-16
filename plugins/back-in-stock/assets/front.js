/**
 * Back in Stock — front.js (v1.0.0).
 *
 * - Variable products: the box shows only when the chosen variation is one
 *   of the out-of-stock ones (WooCommerce's found_variation / reset_data
 *   events) and its id goes into the form. Without JavaScript the box
 *   shows a list of those variations instead.
 * - Every form: the time from page load to submit goes into bis_e (the
 *   server treats a form sent in under 1.5 seconds as a bot).
 */
( function ( $ ) {
	'use strict';
	var start = Date.now();
	document.documentElement.classList.add( 'bis-js' );

	$( function () {
		$( '.bis-box' ).each( function () {
			var $box = $( this );
			var $form = $box.find( 'form.bis-form' );
			$form.on( 'submit', function () {
				$form.find( '.bis-elapsed' ).val( String( Date.now() - start ) );
			} );
			if ( ! $box.is( '[data-bis-variable]' ) ) {
				return;
			}
			var ids = [];
			try {
				ids = JSON.parse( $box.attr( 'data-bis-variations' ) || '[]' );
			} catch ( e ) {
				ids = [];
			}
			var $select = $box.find( 'select.bis-variation' );
			var keepOpen = window.location.search.indexOf( 'bis_r=' ) !== -1;
			var $vform = $( 'form.variations_form' ).first();
			if ( ! $vform.length ) {
				// No variation form on the page: keep the plain list.
				document.documentElement.classList.remove( 'bis-js' );
				return;
			}
			if ( ! keepOpen ) {
				$box.attr( 'hidden', 'hidden' );
			}
			$vform.on( 'found_variation', function ( e, v ) {
				var id = v && v.variation_id ? parseInt( v.variation_id, 10 ) : 0;
				if ( id && ids.indexOf( id ) !== -1 ) {
					$select.val( String( id ) );
					$box.removeAttr( 'hidden' );
				} else {
					$box.attr( 'hidden', 'hidden' );
				}
			} );
			$vform.on( 'reset_data', function () {
				if ( ! keepOpen ) {
					$box.attr( 'hidden', 'hidden' );
				}
				keepOpen = false;
			} );
		} );
	} );
} )( jQuery );
