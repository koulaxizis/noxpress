/**
 * Product Formats — admin.js (v1.0.0)
 *
 *  - Autocomplete (Bible §5) on [data-pfm-ac="work|product"]: from the 3rd
 *    character (or a product ID), 30 results; picking one fills the hidden
 *    field named in data-pfm-target. Typing again clears it, so a new name
 *    on the product tab creates a new work.
 *  - Formats table: drag to reorder (jQuery UI Sortable, bundled with
 *    WordPress); the row indexes are renumbered before submit so the
 *    posted order is the visual order.
 *  - [data-pfm-checkall]: ticks or clears the checkboxes it names.
 *  - Upsell cleanup: batches through AJAX with a progress bar (Bible §5),
 *    then a reload shows the result and the snapshot.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.PFM || {};

	function autocomplete() {
		if ( ! $.fn.autocomplete ) {
			return;
		}
		$( '[data-pfm-ac]' ).each( function () {
			var $input  = $( this );
			var type    = $input.attr( 'data-pfm-ac' );
			var $target = $( document.getElementById( $input.attr( 'data-pfm-target' ) || '' ) );
			$input.autocomplete( {
				classes: { 'ui-autocomplete': 'pfm-ac-menu' },
				minLength: 1,
				delay: 250,
				source: function ( req, res ) {
					var q = $.trim( req.term );
					if ( q.length < 3 && ! ( 'product' === type && /^\d+$/.test( q ) ) ) {
						res( [] );
						return;
					}
					$.getJSON( cfg.ajax, { action: 'pfm_search', nonce: cfg.nonce, type: type, q: q } )
						.done( function ( r ) {
							res( r && r.success ? r.data : [] );
						} )
						.fail( function () {
							res( [] );
						} );
				},
				select: function ( e, ui ) {
					$target.val( ui.item.id );
				}
			} );
			$input.on( 'input', function () {
				$target.val( '' );
			} );
		} );
	}

	function sortable() {
		var $body = $( '.pfm-sortable tbody' );
		if ( ! $body.length || ! $.fn.sortable ) {
			return;
		}
		$body.sortable( { handle: '.pfm-handle', axis: 'y', placeholder: 'ui-sortable-placeholder' } );
		$body.closest( 'form' ).on( 'submit', function () {
			$body.children( 'tr' ).each( function ( i ) {
				$( this ).find( '[name^="pfm_f["]' ).each( function () {
					this.name = this.name.replace( /^pfm_f\[\d+\]/, 'pfm_f[' + i + ']' );
				} );
			} );
		} );
	}

	function checkall() {
		$( '[data-pfm-checkall]' ).on( 'change', function () {
			$( $( this ).attr( 'data-pfm-checkall' ) ).prop( 'checked', this.checked );
		} );
	}

	function cleanup() {
		var $btn = $( '#pfm-clean' );
		if ( ! $btn.length ) {
			return;
		}
		var total = parseInt( $btn.attr( 'data-total' ), 10 ) || 1;
		var $prog = $( '#pfm-progress' );
		var $bar  = $prog.find( '.pfm-progress__bar' );

		function fail() {
			window.alert( cfg.failed );
			window.location.reload();
		}

		function step( run, done ) {
			$.post( cfg.ajax, { action: 'pfm_upsells_batch', nonce: cfg.nonce, run: run } )
				.done( function ( r ) {
					if ( ! r || ! r.success ) {
						fail();
						return;
					}
					done += r.data.done;
					$bar.css( 'width', Math.min( 100, Math.round( 100 * done / total ) ) + '%' );
					if ( 0 === r.data.left || 0 === r.data.done ) {
						window.location.reload();
						return;
					}
					step( run, done );
				} )
				.fail( fail );
		}

		$btn.on( 'click', function () {
			if ( ! window.confirm( cfg.confirm ) ) {
				return;
			}
			$btn.prop( 'disabled', true );
			$prog.prop( 'hidden', false );
			$bar.css( 'width', '0%' );
			$.post( cfg.ajax, { action: 'pfm_upsells_start', nonce: cfg.nonce } )
				.done( function ( r ) {
					if ( r && r.success && r.data.run ) {
						step( r.data.run, 0 );
					} else {
						fail();
					}
				} )
				.fail( fail );
		} );
	}

	$( function () {
		autocomplete();
		sortable();
		checkall();
		cleanup();
	} );
}( jQuery ) );
