/**
 * Shop Filters — admin.js (v1.0.0)
 *
 *  - Filter rows of a set: drag to reorder (jQuery UI Sortable, bundled
 *    with WordPress); the row indexes are renumbered before submit so the
 *    posted order is the visual order.
 *  - Term matrix: sent as one JSON field, so large attributes do not hit
 *    PHP's max_input_vars. Without JavaScript the checkboxes are posted.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $list = $( '#shf-filters' );
		if ( $list.length && $.fn.sortable ) {
			$list.sortable( { handle: '.shf-handle', axis: 'y', placeholder: 'ui-sortable-placeholder' } );
			$list.closest( 'form' ).on( 'submit', function () {
				$list.children( 'li' ).each( function ( i ) {
					$( this ).find( '[name^="shf_f["]' ).each( function () {
						this.name = this.name.replace( /^shf_f\[\d+\]/, 'shf_f[' + i + ']' );
					} );
				} );
			} );
		}

		$( 'form.shf-matrix-form' ).on( 'submit', function () {
			var data = { rows: {}, ignore: [] };
			$( this ).find( 'tr[data-term]' ).each( function () {
				var id = $( this ).attr( 'data-term' );
				data.rows[ id ] = [];
				$( this ).find( 'input[type="checkbox"]' ).each( function () {
					var g = $( this ).attr( 'data-group' );
					if ( ! this.checked ) {
						return;
					}
					if ( g === '__ignore' ) {
						data.ignore.push( parseInt( id, 10 ) );
					} else {
						data.rows[ id ].push( g );
					}
				} );
			} );
			$( this ).find( 'input[name="shf_matrix_json"]' ).val( JSON.stringify( data ) );
			// Only the JSON field is needed now.
			$( this ).find( 'tr[data-term] input' ).prop( 'disabled', true );
		} );

		$( '.shf-matrix' ).on( 'change', 'input[data-group="__ignore"]', function () {
			$( this ).closest( 'tr' ).toggleClass( 'is-ignored', this.checked );
		} );
	} );
}( jQuery ) );
