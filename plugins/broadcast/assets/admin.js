/**
 * Broadcast — admin.js (v1.0.0)
 *
 * Confirmation for buttons with data-brc-confirm, and select-all for the
 * read-only address fields. No dependencies; only on the plugin's page.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.brc-wrap [data-brc-confirm]' ) : null;
		if ( btn && ! window.confirm( btn.getAttribute( 'data-brc-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'focusin', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'brc-copy' ) ) {
			e.target.select();
		}
	} );
} )();
