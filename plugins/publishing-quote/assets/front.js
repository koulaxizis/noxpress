/**
 * Publishing Quote — front.js (v1.0.0).
 *
 * Posts the visitor's choices to pbq/v1/quote and draws the answer (labels
 * and rounded amounts only) with textContent; opens and sends the contact
 * form through pbq/v1/request. No prices live here: the page has none.
 * Without fetch() the forms post the page and PHP draws the same markup.
 */
( function () {
	'use strict';

	if ( ! window.fetch || ! window.JSON || ! document.querySelector ) {
		return;
	}

	var RX = /^q\[([a-z0-9_]+)\](?:\[([a-z0-9]*)\])?$/;

	function collect( form ) {
		var q = {};
		var els = form.elements;
		for ( var i = 0; i < els.length; i++ ) {
			var el = els[ i ];
			var m = el.name ? RX.exec( el.name ) : null;
			if ( ! m || el.disabled ) {
				continue;
			}
			if ( ( el.type === 'checkbox' || el.type === 'radio' ) && ! el.checked ) {
				continue;
			}
			if ( m[ 2 ] === undefined ) {
				q[ m[ 1 ] ] = el.value;
			} else if ( m[ 2 ] === '' ) {
				( q[ m[ 1 ] ] = q[ m[ 1 ] ] || [] ).push( el.value );
			} else {
				( q[ m[ 1 ] ] = q[ m[ 1 ] ] || {} )[ m[ 2 ] ] = el.value;
			}
		}
		return q;
	}

	function node( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			n.textContent = text;
		}
		return n;
	}

	function row( parent, r, cls ) {
		var tr = node( 'tr', cls || '' );
		var th = node( 'th', '', r.label );
		th.setAttribute( 'scope', 'row' );
		tr.appendChild( th );
		tr.appendChild( node( 'td', '', r.amount ) );
		parent.appendChild( tr );
	}

	function msg( text, kind ) {
		var p = node( 'p', 'pbq-msg' + ( kind ? ' pbq-msg--' + kind : '' ), text );
		if ( kind === 'err' ) {
			p.setAttribute( 'role', 'alert' );
		}
		return p;
	}

	function draw( box, v ) {
		box.textContent = '';
		var i;
		if ( v.errors && v.errors.length ) {
			for ( i = 0; i < v.errors.length; i++ ) {
				box.appendChild( msg( v.errors[ i ], 'err' ) );
			}
			return;
		}
		if ( v.empty ) {
			box.appendChild( msg( v.empty ) );
			return;
		}
		var title = node( 'p', 'pbq-result__title' );
		title.appendChild( node( 'strong', '', v.title ) );
		box.appendChild( title );
		var table = node( 'table', 'pbq-table' );
		var body = node( 'tbody' );
		var foot = node( 'tfoot' );
		for ( i = 0; i < v.lines.length; i++ ) {
			row( body, v.lines[ i ] );
		}
		if ( v.typeset ) {
			row( body, v.typeset, 'is-included' );
		}
		if ( v.net ) {
			row( foot, v.net, 'is-sub' );
		}
		for ( i = 0; i < v.vat.length; i++ ) {
			row( foot, v.vat[ i ], 'is-sub' );
		}
		row( foot, v.total, 'is-total' );
		if ( v.per_copy ) {
			row( foot, v.per_copy, 'is-sub' );
		}
		table.appendChild( body );
		table.appendChild( foot );
		box.appendChild( table );
		for ( i = 0; i < v.notes.length; i++ ) {
			var p = node( 'p', 'pbq-note' );
			p.appendChild( node( 'small', '', v.notes[ i ] ) );
			box.appendChild( p );
		}
	}

	function mark( form, fields, prefix ) {
		var all = form.querySelectorAll( '[aria-invalid]' );
		var i;
		for ( i = 0; i < all.length; i++ ) {
			all[ i ].removeAttribute( 'aria-invalid' );
		}
		for ( i = 0; i < ( fields || [] ).length; i++ ) {
			var el = form.querySelector( '[name="' + prefix( fields[ i ] ) + '"]' );
			if ( el ) {
				el.setAttribute( 'aria-invalid', 'true' );
			}
		}
	}

	function post( root, path, data ) {
		var h = { 'Content-Type': 'application/json' };
		if ( root.getAttribute( 'data-nonce' ) ) {
			h[ 'X-WP-Nonce' ] = root.getAttribute( 'data-nonce' );
		}
		return fetch( root.getAttribute( 'data-rest' ) + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: h,
			body: JSON.stringify( data )
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} );
		} );
	}

	function setup( root ) {
		var form = root.querySelector( '.pbq-form' );
		var box = root.querySelector( '.pbq-result' );
		var more = root.querySelector( '.pbq-interest' );
		var contact = root.querySelector( '.pbq-contact' );
		var lang = root.getAttribute( 'data-lang' ) || '';
		var seq = 0;
		var timer = null;
		if ( ! form || ! box ) {
			return;
		}

		function toggles() {
			var conds = form.querySelectorAll( '[data-pbq-if]' );
			for ( var i = 0; i < conds.length; i++ ) {
				var key = conds[ i ].getAttribute( 'data-pbq-if' );
				var sw = form.querySelector( '[data-pbq-toggle="' + key + '"]' );
				conds[ i ].hidden = !! sw && ! sw.checked;
			}
			var b = form.querySelector( '[data-pbq-binding]' );
			var hard = false;
			if ( b ) {
				var opt = b.tagName === 'SELECT' ? b.options[ b.selectedIndex ] : b;
				hard = !! opt && opt.getAttribute( 'data-hard' ) === '1';
			}
			var hs = form.querySelectorAll( '[data-pbq-hard]' );
			for ( var j = 0; j < hs.length; j++ ) {
				var el = hs[ j ].tagName === 'INPUT' ? hs[ j ].closest( 'label' ) : hs[ j ];
				if ( el ) {
					el.hidden = ! hard;
				}
				var inp = hs[ j ].tagName === 'INPUT' ? hs[ j ] : hs[ j ].querySelector( 'input' );
				if ( inp && ! hard && inp.checked ) {
					inp.checked = false;
				}
			}
		}

		function calc() {
			var mine = ++seq;
			root.classList.add( 'is-busy' );
			post( root, 'quote', { q: collect( form ), lang: lang } ).then( function ( v ) {
				if ( mine !== seq ) {
					return;
				}
				root.classList.remove( 'is-busy' );
				if ( ! v || ! v.lines ) {
					box.textContent = '';
					box.appendChild( msg( ( v && v.message ) || '…', 'err' ) );
					if ( more ) {
						more.hidden = true;
					}
					return;
				}
				draw( box, v );
				mark( form, v.fields, function ( f ) {
					return 'q[' + f + ']';
				} );
				if ( more ) {
					more.hidden = ! v.ok;
				}
			} ).catch( function () {
				root.classList.remove( 'is-busy' );
			} );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearTimeout( timer );
			calc();
		} );
		form.addEventListener( 'change', function () {
			toggles();
			clearTimeout( timer );
			timer = setTimeout( calc, 400 );
		} );
		form.addEventListener( 'input', function ( e ) {
			if ( e.target && e.target.type === 'number' ) {
				clearTimeout( timer );
				timer = setTimeout( calc, 400 );
			}
		} );
		toggles();

		if ( contact ) {
			contact.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var f = contact.elements;
				var data = { q: collect( form ), lang: lang };
				[ 'name', 'email', 'phone', 'book', 'msg', 'website' ].forEach( function ( k ) {
					data[ k ] = f[ 'pbq_' + k ] ? f[ 'pbq_' + k ].value : '';
				} );
				data.consent = f.pbq_consent && f.pbq_consent.checked ? 1 : 0;
				var btn = contact.querySelector( 'button[type="submit"]' );
				if ( btn ) {
					btn.disabled = true;
				}
				post( root, 'request', data ).then( function ( r ) {
					if ( btn ) {
						btn.disabled = false;
					}
					var old = contact.querySelector( '.pbq-msg' );
					if ( old ) {
						old.parentNode.removeChild( old );
					}
					if ( r && r.ok ) {
						var done = msg( r.message, 'ok' );
						done.setAttribute( 'role', 'status' );
						more.parentNode.replaceChild( done, more );
						return;
					}
					contact.insertBefore( msg( ( r && r.message ) || '…', 'err' ), contact.firstChild );
					mark( contact, r && r.fields, function ( k ) {
						return 'pbq_' + k;
					} );
				} ).catch( function () {
					if ( btn ) {
						btn.disabled = false;
					}
				} );
			} );
		}
	}

	function boot() {
		var roots = document.querySelectorAll( '.pbq[data-rest]' );
		for ( var i = 0; i < roots.length; i++ ) {
			setup( roots[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
