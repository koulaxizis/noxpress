/**
 * Maintenance Mode — admin.js (v1.0.0): media picker for the logo and the
 * background image, «copy» for preview links. No other behaviour.
 */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var pick = e.target.closest( '[data-mmd-pick]' );
		if ( pick && window.wp && wp.media ) {
			e.preventDefault();
			var field = document.getElementById( pick.getAttribute( 'data-mmd-pick' ) );
			var thumb = document.getElementById( pick.getAttribute( 'data-mmd-pick' ) + '-thumb' );
			var frame = wp.media( { library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				field.value = a.id;
				if ( thumb ) {
					thumb.src = ( a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url );
					thumb.hidden = false;
				}
			} );
			frame.open();
			return;
		}
		var clear = e.target.closest( '[data-mmd-clear]' );
		if ( clear ) {
			e.preventDefault();
			var id = clear.getAttribute( 'data-mmd-clear' );
			document.getElementById( id ).value = '0';
			var t = document.getElementById( id + '-thumb' );
			if ( t ) {
				t.hidden = true;
			}
			return;
		}
		var copy = e.target.closest( '[data-mmd-copy]' );
		if ( copy ) {
			e.preventDefault();
			var input = document.getElementById( copy.getAttribute( 'data-mmd-copy' ) );
			input.select();
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( input.value );
			} else {
				document.execCommand( 'copy' );
			}
			copy.textContent = copy.getAttribute( 'data-done' );
		}
	} );
}() );
