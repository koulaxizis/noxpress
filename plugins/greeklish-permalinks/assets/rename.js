/**
 * Greeklish Permalinks — rename.js (v1.3.0)
 *
 * The «Όνομα αρχείου» field (attachment screen and media library details):
 * shows the final name as you type (AJAX glp_rename_preview) and points the
 * «Μετονομασία…» button to the rename page with that name, where the
 * counts are shown before anything changes. Event delegation, because the
 * media modal draws its fields later. No dependencies.
 */
( function () {
	'use strict';

	var cfg = window.glpRename;
	if ( ! cfg ) {
		return;
	}
	var timers = {};

	function out( input ) {
		return document.getElementById( 'glp-ren-out-' + input.getAttribute( 'data-id' ) );
	}

	function button( input ) {
		var wrap = input.closest ? input.closest( '.glp-ren' ) : null;
		return wrap ? wrap.querySelector( '.glp-ren-go' ) : null;
	}

	function setLink( input ) {
		var b = button( input );
		if ( ! b ) {
			return;
		}
		var base = b.getAttribute( 'data-base' ) || b.getAttribute( 'href' );
		b.setAttribute( 'href', base + '&via=field&name=' + encodeURIComponent( input.value ) );
	}

	function preview( input ) {
		var o = out( input );
		if ( ! o ) {
			return;
		}
		var body = new FormData();
		body.append( 'action', 'glp_rename_preview' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'id', input.getAttribute( 'data-id' ) );
		body.append( 'name', input.value );
		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					o.textContent = '';
					return;
				}
				o.textContent = j.data.same || ! j.data.name ? '' : cfg.will.replace( '%s', j.data.name );
			} )
			.catch( function () {
				o.textContent = '';
			} );
	}

	document.addEventListener( 'input', function ( ev ) {
		var t = ev.target;
		if ( ! t || ! t.classList || ! t.classList.contains( 'glp-ren-input' ) ) {
			return;
		}
		setLink( t );
		var id = t.getAttribute( 'data-id' );
		clearTimeout( timers[ id ] );
		timers[ id ] = setTimeout( function () {
			preview( t );
		}, 350 );
	} );

	// Enter in the field opens the rename page instead of saving the attachment.
	document.addEventListener( 'keydown', function ( ev ) {
		var t = ev.target;
		if ( 'Enter' !== ev.key || ! t || ! t.classList || ! t.classList.contains( 'glp-ren-input' ) ) {
			return;
		}
		ev.preventDefault();
		setLink( t );
		var b = button( t );
		if ( b ) {
			window.location.href = b.getAttribute( 'href' );
		}
	} );
}() );
