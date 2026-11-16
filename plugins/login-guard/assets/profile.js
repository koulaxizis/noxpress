/**
 * Login Guard — profile.js (v1.1.0)
 *
 * Draws the QR code of the 2FA setup from #lgd-qr[data-uri] with the
 * bundled qrcode-generator (MIT). Built with DOM nodes only (no HTML
 * strings), dark modules on white with a quiet zone, 4 px per module.
 */
( function () {
	'use strict';
	var box = document.getElementById( 'lgd-qr' );
	if ( ! box || typeof window.qrcode !== 'function' ) {
		return;
	}
	var uri = box.getAttribute( 'data-uri' ) || '';
	if ( ! uri ) {
		return;
	}
	var qr = window.qrcode( 0, 'M' );
	qr.addData( uri, 'Byte' );
	qr.make();
	var n = qr.getModuleCount();
	var cell = 4;
	var quiet = 4;
	var size = ( n + quiet * 2 ) * cell;
	var ns = 'http://www.w3.org/2000/svg';
	var svg = document.createElementNS( ns, 'svg' );
	svg.setAttribute( 'width', String( size ) );
	svg.setAttribute( 'height', String( size ) );
	svg.setAttribute( 'viewBox', '0 0 ' + size + ' ' + size );
	svg.setAttribute( 'role', 'img' );
	svg.setAttribute( 'aria-label', 'QR' );
	var bg = document.createElementNS( ns, 'rect' );
	bg.setAttribute( 'width', String( size ) );
	bg.setAttribute( 'height', String( size ) );
	bg.setAttribute( 'fill', '#ffffff' );
	svg.appendChild( bg );
	var d = '';
	for ( var r = 0; r < n; r++ ) {
		for ( var c = 0; c < n; c++ ) {
			if ( qr.isDark( r, c ) ) {
				d += 'M' + ( ( c + quiet ) * cell ) + ' ' + ( ( r + quiet ) * cell ) + 'h' + cell + 'v' + cell + 'h-' + cell + 'z';
			}
		}
	}
	var path = document.createElementNS( ns, 'path' );
	path.setAttribute( 'd', d );
	path.setAttribute( 'fill', '#000000' );
	svg.appendChild( path );
	box.appendChild( svg );
}() );
