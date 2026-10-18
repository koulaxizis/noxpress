/**
 * Wish Shelf — front end (no dependencies).
 *
 * The visitor's list:
 *  - guests: localStorage (key wshCfg.key, per site), never sent to the
 *    store except as product ids in the guest list page request;
 *  - signed-in customers: wshCfg.user.items, kept in sync with the account
 *    through the wc-ajax endpoint wsh_sync. After sign-in the browser list
 *    is merged into the account once and then emptied.
 *
 * The button HTML is the same for everyone (cacheable); this file sets its
 * state. Without JavaScript the button does nothing and the page is the
 * normal page; the list's own form (remove, add to cart) works without it.
 */
( function () {
	'use strict';

	var cfg = window.wshCfg;
	if ( ! cfg || ! document.querySelector ) {
		return;
	}
	var T = cfg.t || {};
	var user = cfg.user && typeof cfg.user === 'object' ? cfg.user : null;

	/* ------------------------------------------------------------------
	 * The list: [[p, v, t], …] (t = Unix time, guests only)
	 * ---------------------------------------------------------------- */

	var items = [];

	function readLocal() {
		try {
			var raw = window.localStorage.getItem( cfg.key );
			var data = raw ? JSON.parse( raw ) : null;
			return clean( data && data.items );
		} catch ( e ) {
			return [];
		}
	}

	function writeLocal( list ) {
		try {
			if ( list.length ) {
				window.localStorage.setItem( cfg.key, JSON.stringify( { v: 1, items: list } ) );
			} else {
				window.localStorage.removeItem( cfg.key );
			}
		} catch ( e ) {}
	}

	function clean( list ) {
		var out = [];
		var seen = {};
		if ( ! Array.isArray( list ) ) {
			return out;
		}
		for ( var i = 0; i < list.length && out.length < ( cfg.max || 200 ); i++ ) {
			var r = list[ i ];
			if ( ! Array.isArray( r ) ) {
				continue;
			}
			var p = parseInt( r[ 0 ], 10 );
			var v = parseInt( r[ 1 ], 10 ) || 0;
			if ( ! ( p > 0 ) || v < 0 || seen[ p + '-' + v ] ) {
				continue;
			}
			seen[ p + '-' + v ] = 1;
			out.push( [ p, v, parseInt( r[ 2 ], 10 ) || 0 ] );
		}
		return out;
	}

	function key( p, v ) {
		return v ? p + '-' + v : String( p );
	}

	function has( p, v ) {
		for ( var i = 0; i < items.length; i++ ) {
			if ( items[ i ][ 0 ] === p && ( v === null || items[ i ][ 1 ] === v ) ) {
				return true;
			}
		}
		return false;
	}

	function keys( list ) {
		return ( list || items ).map( function ( r ) {
			return key( r[ 0 ], r[ 1 ] );
		} );
	}

	/* ------------------------------------------------------------------
	 * Server (signed-in customers)
	 * ---------------------------------------------------------------- */

	function endpoint( name ) {
		return String( cfg.ajax || '' ).replace( '%%endpoint%%', name );
	}

	function sync( data, done ) {
		if ( ! user || ! cfg.ajax || ! window.fetch ) {
			return;
		}
		var body = new URLSearchParams();
		body.append( 'nonce', user.nonce );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		window.fetch( endpoint( 'wsh_sync' ), { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( res && res.success && res.data ) {
					items = clean( res.data.items );
					paint();
					if ( done ) {
						done( res.data );
					}
				} else {
					fail();
				}
			} )
			.catch( fail );
	}

	/** On an error the account list wins: undo the optimistic change. */
	function fail() {
		items = clean( user ? user.items : [] );
		paint();
	}

	/* ------------------------------------------------------------------
	 * Add / remove
	 * ---------------------------------------------------------------- */

	function add( p, v ) {
		if ( has( p, v ) ) {
			return true;
		}
		if ( items.length >= ( cfg.max || 200 ) ) {
			return false;
		}
		items.unshift( [ p, v, Math.floor( Date.now() / 1000 ) ] );
		if ( user ) {
			sync( { op: 'add', p: p, v: v }, keepUser );
		} else {
			writeLocal( items );
		}
		return true;
	}

	/** v === null removes every variation of the product. */
	function remove( p, v ) {
		var gone = items.filter( function ( r ) {
			return r[ 0 ] === p && ( v === null || r[ 1 ] === v );
		} );
		items = items.filter( function ( r ) {
			return ! ( r[ 0 ] === p && ( v === null || r[ 1 ] === v ) );
		} );
		if ( user ) {
			gone.forEach( function ( r ) {
				sync( { op: 'remove', p: r[ 0 ], v: r[ 1 ] }, keepUser );
			} );
		} else {
			writeLocal( items );
		}
	}

	function keepUser() {
		if ( user ) {
			user.items = items.slice();
		}
	}

	/* ------------------------------------------------------------------
	 * Buttons, counters
	 * ---------------------------------------------------------------- */

	/** The variation chosen in a product form, per product id. */
	var chosen = {};

	function btnVariation( btn ) {
		if ( ! btn.hasAttribute( 'data-wsh-var' ) ) {
			return null;
		}
		var p = parseInt( btn.getAttribute( 'data-wsh-p' ), 10 );
		return chosen[ p ] || 0;
	}

	function paint() {
		var btns = document.querySelectorAll( '.wsh-btn[data-wsh-p]' );
		for ( var i = 0; i < btns.length; i++ ) {
			var b = btns[ i ];
			var p = parseInt( b.getAttribute( 'data-wsh-p' ), 10 );
			var on = has( p, btnVariation( b ) );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			b.classList.toggle( 'is-on', on );
			var t = b.querySelector( '.wsh-btn__t' );
			if ( t ) {
				t.textContent = on ? T.added : T.add;
			}
			var label = ( on ? T.added : T.add ) + ': ' + ( b.getAttribute( 'data-wsh-name' ) || '' );
			b.setAttribute( 'aria-label', label );
			b.setAttribute( 'title', label );
		}
		var counts = document.querySelectorAll( '[data-wsh-count]' );
		for ( var j = 0; j < counts.length; j++ ) {
			counts[ j ].textContent = String( items.length );
			var wrap = counts[ j ].closest( '.wsh-count' );
			if ( wrap ) {
				wrap.classList.toggle( 'is-empty', ! items.length );
				wrap.hidden = ! items.length && ! cfg.zero;
			}
		}
	}

	function onButton( btn ) {
		var p = parseInt( btn.getAttribute( 'data-wsh-p' ), 10 );
		if ( ! ( p > 0 ) ) {
			return;
		}
		var name = btn.getAttribute( 'data-wsh-name' ) || '';
		if ( ! user && cfg.login ) {
			// Guests must sign in: a link in the notice, or straight to the login page.
			if ( cfg.toast ) {
				toast( '', cfg.login, T.login );
			} else {
				window.location.href = cfg.login;
			}
			return;
		}
		var v = btnVariation( btn );
		if ( has( p, v ) ) {
			remove( p, v );
			paint();
			toast( fill( T.toastRem, name ) );
		} else if ( add( p, v || 0 ) ) {
			paint();
			toast( fill( T.toastAdd, name ), cfg.list, T.view );
		}
	}

	function fill( text, name ) {
		return String( text || '' ).split( '{product_name}' ).join( name );
	}

	/* ------------------------------------------------------------------
	 * Toast
	 * ---------------------------------------------------------------- */

	var toastEl = null;
	var toastTimer = 0;

	function toast( text, url, linkText ) {
		if ( ! cfg.toast || ( ! text && ! linkText ) ) {
			return;
		}
		if ( ! toastEl ) {
			toastEl = document.createElement( 'div' );
			toastEl.className = 'wsh-root wsh-toast';
			toastEl.setAttribute( 'role', 'status' );
			toastEl.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toastEl );
		}
		toastEl.textContent = '';
		if ( text ) {
			var span = document.createElement( 'span' );
			span.textContent = text;
			toastEl.appendChild( span );
		}
		if ( url && linkText ) {
			var a = document.createElement( 'a' );
			a.href = url;
			a.textContent = linkText;
			toastEl.appendChild( a );
		}
		toastEl.classList.add( 'is-open' );
		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			toastEl.classList.remove( 'is-open' );
		}, 4000 );
	}

	/* ------------------------------------------------------------------
	 * The list (page, account tab)
	 * ---------------------------------------------------------------- */

	function listUrlWith( value ) {
		var base = cfg.list || window.location.href.split( '?' )[ 0 ];
		return base + ( base.indexOf( '?' ) > -1 ? '&' : '?' ) + 'wsh=' + value;
	}

	/** After a row leaves: show the empty text when none is left. */
	function afterRemoveRow( root ) {
		if ( root.querySelector( '.wsh-row, .wsh-cards li.product' ) ) {
			guestShare( root );
			return;
		}
		var form = root.querySelector( '.wsh-form' );
		var bar = root.querySelector( '[data-wsh-share-bar]' );
		if ( form ) {
			form.hidden = true;
		}
		if ( bar ) {
			bar.hidden = true;
		}
		var empty = root.querySelector( '[data-wsh-empty]' );
		if ( empty ) {
			empty.hidden = false;
		}
	}

	function guestShare( root ) {
		var input = root.querySelector( '[data-wsh-guest-url]' );
		if ( input ) {
			input.value = items.length ? listUrlWith( keys().join( ',' ) ) : '';
		}
	}

	/** A guest's list page: the server renders the ids from this browser. */
	function loadGuest() {
		var box = document.querySelector( '[data-wsh-guest]' );
		if ( ! box ) {
			return;
		}
		var body = box.querySelector( '[data-wsh-body]' );
		var empty = box.querySelector( '[data-wsh-empty]' );
		if ( ! items.length || ! cfg.ajax || ! window.fetch ) {
			if ( body ) {
				body.classList.remove( 'wsh-load' );
			}
			if ( empty ) {
				empty.hidden = false;
			}
			return;
		}
		var url = endpoint( 'wsh_render' );
		url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + 'ids=' + encodeURIComponent( keys().join( ',' ) );
		window.fetch( url, { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success || ! body ) {
					throw new Error( 'wsh' );
				}
				body.classList.remove( 'wsh-load' );
				body.innerHTML = res.data.html;
				var inner = body.querySelector( '.wsh-list' );
				guestShare( inner || body );
				nativeShare( body );
				paint();
			} )
			.catch( function () {
				if ( body ) {
					body.classList.remove( 'wsh-load' );
				}
				if ( empty ) {
					empty.hidden = false;
				}
			} );
	}

	function nativeShare( root ) {
		if ( ! navigator.share ) {
			return;
		}
		var n = root.querySelectorAll( '[data-wsh-native]' );
		for ( var i = 0; i < n.length; i++ ) {
			var input = n[ i ].parentNode.querySelector( '[data-wsh-share-url]' );
			n[ i ].hidden = ! input || ! input.value;
		}
	}

	function copy( input ) {
		var done = function () {
			toast( T.copied );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done, function () {
				input.select();
			} );
			return;
		}
		input.hidden = false;
		input.select();
		try {
			if ( document.execCommand( 'copy' ) ) {
				done();
			}
		} catch ( e ) {}
	}

	function onToken( btn ) {
		var bar = btn.closest( '[data-wsh-share-bar]' );
		if ( ! bar ) {
			return;
		}
		var to = btn.getAttribute( 'data-wsh-token' );
		btn.disabled = true;
		sync( { op: 'share', to: to, list: bar.getAttribute( 'data-wsh-list' ) || '0' }, function ( data ) {
			btn.disabled = false;
			var url = typeof data.share === 'string' ? data.share : '';
			var input = bar.querySelector( '[data-wsh-share-url]' );
			if ( input ) {
				input.value = url;
				input.hidden = ! url;
			}
			var c = bar.querySelector( '[data-wsh-copy]' );
			var off = bar.querySelector( '[data-wsh-token="off"]' );
			var nw = bar.querySelector( '[data-wsh-token="new"]' );
			if ( c ) {
				c.hidden = ! url;
			}
			if ( off ) {
				off.hidden = ! url;
			}
			if ( nw ) {
				nw.textContent = url ? T.shareNew : T.share;
			}
			nativeShare( bar );
			if ( url && input ) {
				copy( input );
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Events (delegated: lists and buttons may arrive later)
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! t || ! t.closest ) {
			return;
		}
		var btn = t.closest( '.wsh-btn[data-wsh-p]' );
		if ( btn ) {
			e.preventDefault();
			e.stopPropagation();
			onButton( btn );
			return;
		}
		var dl = t.closest( '[data-wsh-del-list]' );
		if ( dl ) {
			if ( ! window.confirm( T.confirmList ) ) {
				e.preventDefault();
			}
			return;
		}
		var root = t.closest( '.wsh-list' );
		if ( ! root ) {
			return;
		}
		var guest = 'guest' === root.getAttribute( 'data-wsh-mode' );
		var rem = t.closest( '[data-wsh-remove]' );
		if ( rem && guest ) {
			// A guest's list is in this browser: no round trip.
			e.preventDefault();
			var pv = String( rem.getAttribute( 'data-wsh-remove' ) ).split( '-' );
			remove( parseInt( pv[ 0 ], 10 ), parseInt( pv[ 1 ], 10 ) || 0 );
			var row = rem.closest( '.wsh-row' );
			if ( row ) {
				row.parentNode.removeChild( row );
			}
			var hidden = root.querySelector( 'input[name="wsh_keys[]"][value="' + rem.getAttribute( 'data-wsh-remove' ) + '"]' );
			if ( hidden ) {
				hidden.parentNode.removeChild( hidden );
			}
			paint();
			afterRemoveRow( root );
			return;
		}
		var clr = t.closest( '[data-wsh-clear]' );
		if ( clr ) {
			if ( ! window.confirm( T.confirm ) ) {
				e.preventDefault();
				return;
			}
			if ( guest ) {
				e.preventDefault();
				items = [];
				writeLocal( items );
				var rows = root.querySelectorAll( '.wsh-row, .wsh-cards li.product' );
				for ( var i = 0; i < rows.length; i++ ) {
					rows[ i ].parentNode.removeChild( rows[ i ] );
				}
				paint();
				afterRemoveRow( root );
			}
			return;
		}
		var sel = t.closest( '[data-wsh-selected]' );
		if ( sel && ! root.querySelector( 'input[name="wsh_sel[]"]:checked' ) ) {
			e.preventDefault();
			return;
		}
		if ( t.closest( '[data-wsh-copy]' ) ) {
			e.preventDefault();
			var input = t.closest( '[data-wsh-share-bar]' ).querySelector( '[data-wsh-share-url]' );
			if ( input && input.value ) {
				copy( input );
			}
			return;
		}
		if ( t.closest( '[data-wsh-native]' ) ) {
			e.preventDefault();
			var u = t.closest( '[data-wsh-share-bar]' ).querySelector( '[data-wsh-share-url]' );
			if ( u && u.value && navigator.share ) {
				navigator.share( { title: document.title, url: u.value } ).catch( function () {} );
			}
			return;
		}
		var tok = t.closest( '[data-wsh-token]' );
		if ( tok ) {
			e.preventDefault();
			onToken( tok );
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t && t.matches && t.matches( '[data-wsh-all]' ) ) {
			var root = t.closest( '.wsh-list' );
			var boxes = root ? root.querySelectorAll( 'input[name="wsh_sel[]"]' ) : [];
			for ( var i = 0; i < boxes.length; i++ ) {
				boxes[ i ].checked = t.checked;
			}
		}
	} );

	// Variable products: the heart follows the chosen variation.
	if ( window.jQuery ) {
		window.jQuery( document.body )
			.on( 'found_variation', 'form.variations_form', function ( ev, variation ) {
				var p = parseInt( this.getAttribute( 'data-product_id' ), 10 );
				if ( p > 0 ) {
					chosen[ p ] = variation && variation.variation_id ? parseInt( variation.variation_id, 10 ) : 0;
					paint();
				}
			} )
			.on( 'reset_data', 'form.variations_form', function () {
				var p = parseInt( this.getAttribute( 'data-product_id' ), 10 );
				if ( p > 0 ) {
					chosen[ p ] = 0;
					paint();
				}
			} );
	}

	// Another tab changed the guest list.
	window.addEventListener( 'storage', function ( e ) {
		if ( ! user && e.key === cfg.key ) {
			items = readLocal();
			paint();
		}
	} );

	/* ------------------------------------------------------------------
	 * Start
	 * ---------------------------------------------------------------- */

	function start() {
		items = user ? clean( user.items ) : readLocal();

		// Items that left the list on the server side (form "add to cart" /
		// remove): the guest list forgets them too.
		var m = /[?&]wsh_done=([^&#]*)/.exec( window.location.search );
		if ( m ) {
			var done = decodeURIComponent( m[ 1 ] ).split( ',' );
			if ( ! user ) {
				items = items.filter( function ( r ) {
					return done.indexOf( key( r[ 0 ], r[ 1 ] ) ) < 0;
				} );
				writeLocal( items );
			}
			if ( window.history && window.history.replaceState ) {
				var url = window.location.href.replace( /([?&])wsh_done=[^&#]*&?/, '$1' ).replace( /[?&](#|$)/, '$1' );
				window.history.replaceState( null, '', url );
			}
		}

		// After sign-in: the browser list joins the account once.
		if ( user ) {
			var local = readLocal();
			if ( local.length ) {
				// Emptied only once the account has it; a failed merge retries on the next page.
				sync( { op: 'merge', items: JSON.stringify( local ) }, function () {
					writeLocal( [] );
					keepUser();
					// The page was rendered before the merge.
					var own = document.querySelector( '.wsh-list[data-wsh-mode="own"], .wsh-list--own' );
					if ( own && window.location.reload ) {
						window.location.reload();
					}
				} );
			}
		}

		paint();
		loadGuest();
		nativeShare( document );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
