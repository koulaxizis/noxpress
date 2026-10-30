/**
 * Trade Feed Import — admin.js (v1.0.0)
 *
 * Only on the plugin's own page:
 *  - a running import reloads the page every few seconds ([data-tfi-refresh])
 *  - "select all" in the titles table
 */
( function () {
	'use strict';

	var box = document.querySelector( '.tfi-wrap [data-tfi-refresh]' );
	if ( box ) {
		var secs = parseInt( box.getAttribute( 'data-tfi-refresh' ), 10 ) || 8;
		window.setTimeout( function () {
			window.location.reload();
		}, secs * 1000 );
	}

	var all = document.querySelector( '.tfi-wrap .tfi-all' );
	if ( all ) {
		all.addEventListener( 'change', function () {
			var boxes = document.querySelectorAll( '.tfi-wrap input[name="tfi_ids[]"]' );
			for ( var i = 0; i < boxes.length; i++ ) {
				boxes[ i ].checked = all.checked;
			}
		} );
	}
}() );
