/**
 * Live Search — admin.js (v1.0.0)
 *
 * Only on our own page (lvs-settings):
 *  - «Rebuild now»: the index in batches of 200 through admin-ajax
 *    (lvs_rebuild, nonce, manage_woocommerce), with a progress bar. A
 *    failed batch stops and says so; the rows already written stay.
 *  - Forms with data-lvs-confirm ask before they submit.
 */
( function () {
	'use strict';

	var cfg = window.lvsAdmin || {};
	var t = cfg.t || {};

	function fmt( s, a, b ) {
		return String( s || '' )
			.replace( '%1$d', String( a ) )
			.replace( '%2$d', String( b ) )
			.replace( '%d', String( a ) );
	}

	document.addEventListener( 'submit', function ( e ) {
		var f = e.target;
		if ( f && f.getAttribute && f.hasAttribute( 'data-lvs-confirm' ) ) {
			if ( ! window.confirm( f.getAttribute( 'data-lvs-confirm' ) ) ) {
				e.preventDefault();
			}
		}
	} );

	var btn = document.getElementById( 'lvs-rebuild' );
	if ( ! btn || ! cfg.ajax ) {
		return;
	}
	var msg = document.getElementById( 'lvs-rebuild-msg' );
	var bar = document.getElementById( 'lvs-bar' );
	var box = bar ? bar.parentNode : null;

	function say( s ) {
		if ( msg ) {
			msg.textContent = s;
		}
	}

	function step( offset, start ) {
		var body = new URLSearchParams();
		body.set( 'action', 'lvs_rebuild' );
		body.set( 'nonce', cfg.nonce || '' );
		body.set( 'offset', String( offset ) );
		body.set( 'start', String( start ) );
		fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success || ! j.data ) {
					throw new Error( 'fail' );
				}
				var d = j.data;
				var total = Math.max( 1, d.total || 0 );
				var at = d.done ? total : Math.min( d.next || 0, total );
				if ( bar ) {
					bar.style.width = Math.round( ( at / total ) * 100 ) + '%';
				}
				if ( d.done ) {
					say( fmt( t.done, d.count || 0 ) );
					btn.disabled = false;
					return;
				}
				say( fmt( t.run, at, total ) );
				step( d.next, d.start );
			} )
			.catch( function () {
				say( t.fail || '' );
				btn.disabled = false;
			} );
	}

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		if ( box ) {
			box.hidden = false;
		}
		if ( bar ) {
			bar.style.width = '0';
		}
		say( fmt( t.run, 0, 0 ) );
		step( 0, 0 );
	} );
} )();
