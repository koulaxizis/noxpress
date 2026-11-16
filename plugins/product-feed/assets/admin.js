/**
 * Product Feed — admin.js (v1.0.0)
 *
 *  - Forms with data-confirm ask first.
 *  - «Copy» buttons copy a feed URL (Clipboard API, fallback: select).
 * No network calls; everything else is plain POST with PRG.
 */
( function () {
	'use strict';

	var wrap = document.querySelector( '.pfd-wrap' );
	if ( ! wrap ) {
		return;
	}

	Array.prototype.forEach.call( wrap.querySelectorAll( 'form[data-confirm]' ), function ( f ) {
		f.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( f.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );

	Array.prototype.forEach.call( wrap.querySelectorAll( '.pfd-copy' ), function ( b ) {
		b.addEventListener( 'click', function () {
			var text = b.getAttribute( 'data-copy' ) || '';
			var done = function () {
				var old = b.textContent;
				b.textContent = ( window.pfdAdmin && window.pfdAdmin.copied ) || '✓';
				window.setTimeout( function () {
					b.textContent = old;
				}, 1500 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done, function () {} );
				return;
			}
			var t = document.createElement( 'textarea' );
			t.value = text;
			t.setAttribute( 'readonly', '' );
			t.style.position = 'absolute';
			t.style.left = '-9999px';
			document.body.appendChild( t );
			t.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( err ) {}
			document.body.removeChild( t );
		} );
	} );
}() );
