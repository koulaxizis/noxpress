/*
 * Product Tabs — the "Product tabs" box on the product screen.
 *
 * Adds a tab from the template (wp.editor starts the classic editor of
 * the new row), removes a tab (its editor first), and shows the video or
 * the text fields by the row's type. Nothing is sent from here: the box
 * is saved with the product form.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.PTB_MB || {};

	function editor( id ) {
		if ( ! window.wp || ! wp.editor || ! wp.editor.initialize ) {
			return;
		}
		wp.editor.initialize( id, {
			tinymce: {
				wpautop: true,
				plugins: 'charmap colorpicker hr lists paste tabfocus textcolor fullscreen wordpress wpautoresize wpeditimage wpemoji wpgallery wplink wptextpattern',
				toolbar1: 'formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright link wp_more fullscreen'
			},
			quicktags: true,
			mediaButtons: true
		} );
	}

	$( function () {
		var $box = $( '#ptb-tabs' );
		if ( ! $box.length ) {
			return;
		}
		var $list = $box.find( '.ptb-mb-list' );
		var tpl = $( '#tmpl-ptb-mb-row' ).html() || '';

		$box.on( 'click', '.ptb-mb-add', function () {
			var count = $list.children( '.ptb-mb-row' ).length;
			if ( cfg.max && count >= cfg.max ) {
				return;
			}
			var next = parseInt( $list.attr( 'data-next' ), 10 ) || 0;
			$list.attr( 'data-next', next + 1 );
			var $row = $( tpl.split( '__i__' ).join( String( next ) ) );
			$list.append( $row );
			editor( 'ptbc' + next );
			$row.find( 'input[type="text"]' ).first().trigger( 'focus' );
		} );

		$box.on( 'click', '.ptb-mb-remove', function () {
			if ( cfg.remove && ! window.confirm( cfg.remove ) ) {
				return;
			}
			var $row = $( this ).closest( '.ptb-mb-row' );
			var id = $row.attr( 'data-editor' );
			if ( id && window.wp && wp.editor && wp.editor.remove ) {
				try {
					wp.editor.remove( id );
				} catch ( e ) {}
			}
			$row.remove();
		} );

		$box.on( 'change', '.ptb-mb-type', function () {
			$( this ).closest( '.ptb-mb-row' ).attr( 'data-type', $( this ).val() === 'video' ? 'video' : 'text' );
		} );
	} );
}( jQuery ) );
