/* Search Signals — editor fields: character counters and the share image picker. */
( function ( $ ) {
	'use strict';
	var L = window.ssgEditor || {};

	function count( el ) {
		var $el = $( el ), n = ( $el.val() || '' ).length, max = parseInt( $el.data( 'max' ), 10 ) || 0;
		var $c = $el.closest( '.ssg-ed-row' ).find( '.ssg-counter' );
		$c.text( n ? ( L.chars || '%d' ).replace( '%d', n ) : '' ).toggleClass( 'ssg-over', max > 0 && n > max );
	}

	$( function () {
		$( '.ssg-count' ).each( function () { count( this ); } ).on( 'input', function () { count( this ); } );

		var frame = null;
		$( document ).on( 'click', '.ssg-image-pick', function ( e ) {
			e.preventDefault();
			var $box = $( this ).closest( '.ssg-ed-image' );
			if ( ! window.wp || ! wp.media ) {
				return;
			}
			frame = wp.media( { title: L.pick || '', button: { text: L.use || 'OK' }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				var src = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
				$box.find( '.ssg-image-id' ).val( a.id );
				$box.find( '.ssg-image-preview' ).empty().append( $( '<img alt="">' ).attr( 'src', src ) );
			} );
			frame.open();
		} );
		$( document ).on( 'click', '.ssg-image-clear', function ( e ) {
			e.preventDefault();
			var $box = $( this ).closest( '.ssg-ed-image' );
			$box.find( '.ssg-image-id' ).val( '0' );
			$box.find( '.ssg-image-preview' ).empty();
		} );
	} );
}( jQuery ) );
