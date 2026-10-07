/**
 * Noxpress Migrator — admin.js
 * Export/Verify polling loops + abort handler.
 * Περιμένει: window.nm_ajax = { ajaxurl, nonce, slug, confirm_abort }
 * (localized από NM_Admin_UI::assets()).
 *
 * Autostart gating: τo polling ξεκινά μόνο όταν υπάρχει το αντίστοιχο
 * panel με data-autostart="1" — κανένα console noise στις σελίδες
 * φόρμας/report.
 */
(function ( $ ) {
	'use strict';

	if ( typeof nm_ajax === 'undefined' ) {
		return;
	}

	var cfg = nm_ajax;

	/* ---------- Helpers ---------- */

	function pct( v ) {
		return ( parseFloat( v ) || 0 ).toFixed( 1 );
	}

	function fmtBytes( b ) {
		b = parseFloat( b ) || 0;
		var u = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		while ( b >= 1024 && i < 3 ) { b /= 1024; i++; }
		return b.toFixed( ( 0 === i ) ? 0 : ( b < 10 ? 2 : 1 ) ) + ' ' + u[ i ];
	}

	/* ---------- Export progress polling ---------- */

	var exportRunning = false;

	function loopExport() {

		if ( ! exportRunning ) {
			return;
		}

		$.post( cfg.ajaxurl, {
			action: 'nm_progress_step',
			nonce:  cfg.nonce
		} ).done( function ( data ) {

			if ( ! data || ! data.status ) {
				return;
			}

			$( '#nm-status-phase' ).text( data.status );
			$( '#nm-status-table' ).text( data.table || '' );
			$( '#nm-status-rows' ).text( ( parseInt( data.rows, 10 ) || 0 ).toLocaleString() );
			$( '#nm-status-bytes' ).text( fmtBytes( data.bytes ) );
			$( '#nm-status-pct' ).text( pct( data.pct ) );
			$( '#nm-progress-fill' ).css( 'width', pct( data.pct ) + '%' );

			if ( 'complete' === data.status || 'failed' === data.status ) {
				exportRunning = false; // Τα σχόλια δεν πειράζονται το reload.
				location.reload();
				return;
			}

		} ).always( function () {
			if ( exportRunning ) {
				setTimeout( loopExport, 1500 );
			}
		} );
	}

	/** Global — καλείται από το onclick του abort button (render_dashboard). */
	window.nm_abort = function () {
		if ( ! window.confirm( cfg.confirm_abort || 'Are you sure?' ) ) {
			return;
		}
		$.post( cfg.ajaxurl, {
			action: 'nm_abort',
			nonce:  cfg.nonce
		} ).done( function () {
			location.reload();
		} );
	};

	/* ---------- Verify polling ---------- */

	var verifyRunning = false;

	function loopVerify() {

		if ( ! verifyRunning ) {
			return;
		}

		$.post( cfg.ajaxurl, {
			action: 'nm_verify_step',
			nonce:  cfg.nonce
		} ).done( function ( data ) {

			if ( ! data || ! data.status ) {
				return;
			}

			$( '#nm-verify-phase' ).text( data.status );
			$( '#nm-verify-pct' ).text( pct( data.pct ) );
			$( '#nm-verify-fill' ).css( 'width', pct( data.pct ) + '%' );

			if ( 'done' === data.status || 'failed' === data.status ) {
				verifyRunning = false;
				location.reload();
				return;
			}

		} ).always( function () {
			if ( verifyRunning ) {
				setTimeout( loopVerify, 2000 );
			}
		} );
	}

	/* ---------- Boot ---------- */

	$( function () {

		// Export panel (render_dashboard — running/paused branch).
		if ( $( '#nm-progress-panel[data-autostart]' ).length > 0 ) {
			exportRunning = true;
			loopExport();
		}

		// Verify panel (render_verify — in-progress branch).
		if ( $( '#nm-verify-progress[data-autostart]' ).length > 0 ) {
			verifyRunning = true;
			loopVerify();
		}
	} );

}( jQuery ) );