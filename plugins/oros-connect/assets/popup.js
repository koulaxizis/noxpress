/**
 * orOS Connect — popup.js (v1.3.0). Opens the newsletter <dialog> that
 * ORC_Popup prints in the footer: after data-delay seconds or when the
 * visitor has scrolled data-scroll % of the page, at most once in
 * data-days days. The browser remembers when it showed in localStorage
 * ("orc_pop", a time in ms; far in the future after a sign-up). No cookie,
 * nothing sent anywhere. Without localStorage or <dialog> it never opens.
 * ?orc_popup=1 opens it at once (testing).
 */
( function () {
	'use strict';
	if ( window.orcPopup ) {
		return;
	}
	window.orcPopup = true;

	var dlg = document.getElementById( 'orc-pop' );
	if ( ! dlg || typeof dlg.showModal !== 'function' ) {
		return;
	}
	var KEY = 'orc_pop';
	var DAY = 864e5;
	var num = function ( name, def ) {
		var v = parseInt( dlg.getAttribute( name ), 10 );
		return isNaN( v ) ? def : v;
	};
	var days = num( 'data-days', 30 );
	var delay = num( 'data-delay', 20 ) * 1000;
	var scroll = num( 'data-scroll', 50 );
	var force = /[?&]orc_popup=1(?:&|$)/.test( window.location.search );

	function read() {
		try {
			return Number( window.localStorage.getItem( KEY ) ) || 0;
		} catch ( e ) {
			return -1;
		}
	}
	function write( t ) {
		try {
			window.localStorage.setItem( KEY, String( t ) );
		} catch ( e ) {}
	}

	if ( ! force ) {
		var last = read();
		// No storage: never, or it would open on every page.
		if ( last < 0 ) {
			return;
		}
		// Shown lately, or signed up (a time in the future).
		if ( last && Date.now() - last < days * DAY ) {
			return;
		}
		// The page already shows the form (e.g. a widget).
		var forms = document.querySelectorAll( '.orc-nl' );
		for ( var i = 0; i < forms.length; i++ ) {
			if ( ! dlg.contains( forms[ i ] ) ) {
				return;
			}
		}
	}

	var done = false;
	var timer = 0;

	function onScroll() {
		var h = document.documentElement.scrollHeight - window.innerHeight;
		if ( h > 0 && ( window.scrollY / h ) * 100 >= scroll ) {
			open();
		}
	}
	function open() {
		if ( done ) {
			return;
		}
		done = true;
		window.clearTimeout( timer );
		window.removeEventListener( 'scroll', onScroll );
		// Another dialog (cart drawer, viewer) is open: not now, and not counted.
		if ( document.querySelector( 'dialog[open]' ) ) {
			return;
		}
		dlg.hidden = false;
		try {
			dlg.showModal();
		} catch ( e ) {
			dlg.hidden = true;
			return;
		}
		write( Date.now() );
	}

	dlg.addEventListener( 'click', function ( e ) {
		var t = e.target;
		// The dialog itself is only hit on the backdrop (no padding of its own).
		if ( t === dlg || ( t.closest && t.closest( '[data-orc-close]' ) ) ) {
			dlg.close();
		}
	} );
	dlg.addEventListener( 'close', function () {
		dlg.hidden = true;
	} );
	var form = dlg.querySelector( 'form' );
	if ( form ) {
		form.addEventListener( 'submit', function () {
			write( Date.now() + 3650 * DAY );
		} );
	}

	timer = window.setTimeout( open, force ? 300 : delay );
	if ( scroll > 0 && ! force ) {
		window.addEventListener( 'scroll', onScroll, { passive: true } );
	}
}() );
