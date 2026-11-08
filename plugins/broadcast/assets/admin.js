/**
 * Broadcast — admin.js (v1.4.0)
 *
 * Confirmation for buttons with data-brc-confirm, select-all for the
 * read-only address fields, and the media picker for data-brc-media
 * (wp.media, loaded only on the Sources tab). Only on the plugin's page.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.brc-wrap [data-brc-confirm]' ) : null;
		if ( btn && ! window.confirm( btn.getAttribute( 'data-brc-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var pick  = e.target.closest ? e.target.closest( '.brc-wrap [data-brc-media]' ) : null;
		var clear = e.target.closest ? e.target.closest( '.brc-wrap [data-brc-media-clear]' ) : null;
		var input, img, frame;
		if ( clear ) {
			e.preventDefault();
			input = document.getElementById( clear.getAttribute( 'data-brc-media-clear' ) );
			img   = document.getElementById( clear.getAttribute( 'data-brc-media-clear' ) + '-preview' );
			if ( input ) {
				input.value = '0';
			}
			if ( img ) {
				img.hidden = true;
				img.removeAttribute( 'src' );
			}
			return;
		}
		if ( ! pick || ! window.wp || ! window.wp.media ) {
			return;
		}
		e.preventDefault();
		input = document.getElementById( pick.getAttribute( 'data-brc-media' ) );
		img   = document.getElementById( pick.getAttribute( 'data-brc-media' ) + '-preview' );
		frame = window.wp.media( {
			title: pick.getAttribute( 'data-brc-media-title' ) || '',
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON();
			var u = a.sizes && a.sizes.large ? a.sizes.large.url : a.url;
			if ( input ) {
				input.value = String( a.id );
			}
			if ( img ) {
				img.src    = u;
				img.hidden = false;
			}
		} );
		frame.open();
	} );

	document.addEventListener( 'focusin', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'brc-copy' ) ) {
			e.target.select();
		}
	} );
} )();
