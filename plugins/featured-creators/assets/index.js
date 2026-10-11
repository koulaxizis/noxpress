/**
 * Featured Creators — index.js (v1.0.0)
 *
 * Role filter of the creators index without a page reload. The filter
 * links work without JavaScript too (?crt_role=…); this only shows and
 * hides the cards already on the page and keeps the address in step.
 */
( function () {
	'use strict';

	function apply( root, role ) {
		root.querySelectorAll( '.crt-filter__btn' ).forEach( function ( b ) {
			var on = ( b.getAttribute( 'data-role' ) || '' ) === role;
			b.classList.toggle( 'is-on', on );
			if ( on ) {
				b.setAttribute( 'aria-current', 'true' );
			} else {
				b.removeAttribute( 'aria-current' );
			}
		} );
		root.querySelectorAll( '.crt-group' ).forEach( function ( g ) {
			var shown = 0;
			g.querySelectorAll( '.crt-card' ).forEach( function ( c ) {
				var roles = ( c.getAttribute( 'data-roles' ) || '' ).split( ' ' );
				var show = '' === role || roles.indexOf( role ) !== -1;
				c.hidden = ! show;
				if ( show ) {
					shown++;
				}
			} );
			g.hidden = ! shown;
			var a = root.querySelector( '.crt-letters__a[data-group="' + g.id + '"]' );
			if ( a ) {
				a.hidden = ! shown;
			}
		} );
	}

	function init( root ) {
		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest ? e.target.closest( '.crt-filter__btn' ) : null;
			if ( ! b || ! root.contains( b ) || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || 0 !== e.button ) {
				return;
			}
			e.preventDefault();
			apply( root, b.getAttribute( 'data-role' ) || '' );
			if ( window.history && window.history.replaceState ) {
				window.history.replaceState( null, '', b.href );
			}
		} );
	}

	function boot() {
		document.querySelectorAll( '[data-crt-index]' ).forEach( init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
