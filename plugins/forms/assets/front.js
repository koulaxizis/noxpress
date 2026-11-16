/**
 * Forms — front end (Bible §14). No dependencies.
 *
 * On the first touch of a form: GET frm/v1/token (signed time token and the
 * proof-of-work puzzle), then the puzzle in a Web Worker (assets/pow.js)
 * while the visitor types. «Send» (after the browser's own field checks)
 * waits for both, keeps the minimum time, and posts JSON to
 * POST frm/v1/submit. Answers are drawn with textContent only.
 *
 * When the token cannot be had (REST blocked, old browser), the form is
 * posted normally and the server answers with the «Confirm sending» step.
 */
( function () {
	'use strict';

	if ( ! window.fetch || ! document.querySelectorAll ) {
		return;
	}

	function workerSolve( url, t, d ) {
		return new Promise( function ( resolve, reject ) {
			var w;
			try {
				w = new Worker( url );
			} catch ( e ) {
				reject( e );
				return;
			}
			w.onmessage = function ( ev ) {
				w.terminate();
				if ( ev.data && ev.data.n >= 0 ) {
					resolve( String( ev.data.n ) );
				} else {
					reject( new Error( 'pow' ) );
				}
			};
			w.onerror = function ( e ) {
				w.terminate();
				reject( e );
			};
			w.postMessage( { t: t, d: d } );
		} );
	}

	/** Fallback without workers: the same search in slices on the main thread. */
	function loadPow( url ) {
		return new Promise( function ( resolve, reject ) {
			if ( window.frmPow ) {
				resolve( window.frmPow );
				return;
			}
			var s = document.createElement( 'script' );
			s.src = url;
			s.onload = function () {
				if ( window.frmPow ) {
					resolve( window.frmPow );
				} else {
					reject( new Error( 'pow' ) );
				}
			};
			s.onerror = reject;
			document.head.appendChild( s );
		} );
	}

	function sliceSolve( url, t, d ) {
		return loadPow( url ).then( function ( pow ) {
			return new Promise( function ( resolve ) {
				var n = 0;
				( function step() {
					var found = pow.search( t, d, n, 20000 );
					if ( found >= 0 ) {
						resolve( String( found ) );
						return;
					}
					n += 20000;
					setTimeout( step, 0 );
				}() );
			} );
		} );
	}

	function solve( url, t, d ) {
		if ( ! d ) {
			return Promise.resolve( '' );
		}
		if ( window.Worker ) {
			return workerSolve( url, t, d ).catch( function () {
				return sliceSolve( url, t, d );
			} );
		}
		return sliceSolve( url, t, d );
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, Math.max( 0, ms ) );
		} );
	}

	function setup( root ) {
		var form = root.querySelector( 'form.frm-form:not(.frm-confirm)' );
		if ( ! form || form.getAttribute( 'data-frm-ready' ) ) {
			return;
		}
		form.setAttribute( 'data-frm-ready', '1' );

		var rest = root.getAttribute( 'data-rest' ) || '';
		var id = root.getAttribute( 'data-form' ) || '';
		var nonce = root.getAttribute( 'data-nonce' ) || '';
		var powUrl = root.getAttribute( 'data-pow' ) || '';
		var status = form.querySelector( '.frm-status' );
		var button = form.querySelector( 'button[type="submit"]' );
		var buttonText = button ? button.textContent : '';
		var ticket = null; // Promise of { t, answer, at, w }.
		var busy = false;

		function headers( json ) {
			var h = { Accept: 'application/json' };
			if ( json ) {
				h[ 'Content-Type' ] = 'application/json';
			}
			if ( nonce ) {
				h[ 'X-WP-Nonce' ] = nonce;
			}
			return h;
		}

		function start() {
			if ( ticket ) {
				return ticket;
			}
			ticket = fetch( rest + 'token?form=' + encodeURIComponent( id ), {
				credentials: 'same-origin',
				headers: headers( false )
			} )
				.then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( 'token' );
					}
					return r.json();
				} )
				.then( function ( j ) {
					var at = Date.now();
					return solve( powUrl, String( j.t || '' ), parseInt( j.d, 10 ) || 0 ).then( function ( answer ) {
						return { t: String( j.t || '' ), answer: answer, at: at, w: parseInt( j.w, 10 ) || 0 };
					} );
				} );
			ticket.catch( function () {} );
			return ticket;
		}

		form.addEventListener( 'focusin', start, { once: true } );
		form.addEventListener( 'input', start, { once: true } );

		function say( text, kind ) {
			if ( ! status ) {
				return;
			}
			status.textContent = text || '';
			status.className = 'frm-status' + ( text ? ' frm-msg frm-msg--' + kind : '' );
		}

		function clearErrors() {
			var bad = form.querySelectorAll( '.frm-field.is-error' );
			for ( var i = 0; i < bad.length; i++ ) {
				bad[ i ].classList.remove( 'is-error' );
				var p = bad[ i ].querySelector( '.frm-error' );
				if ( p ) {
					p.textContent = '';
					p.hidden = true;
				}
				var inputs = bad[ i ].querySelectorAll( '[aria-invalid]' );
				for ( var j = 0; j < inputs.length; j++ ) {
					inputs[ j ].removeAttribute( 'aria-invalid' );
				}
			}
		}

		function showErrors( errors ) {
			var first = null;
			Object.keys( errors || {} ).forEach( function ( key ) {
				var box = null;
				var all = form.querySelectorAll( '.frm-field[data-key]' );
				for ( var i = 0; i < all.length; i++ ) {
					if ( all[ i ].getAttribute( 'data-key' ) === key ) {
						box = all[ i ];
						break;
					}
				}
				if ( ! box ) {
					return;
				}
				box.classList.add( 'is-error' );
				var p = box.querySelector( '.frm-error' );
				if ( p ) {
					p.textContent = String( errors[ key ] );
					p.hidden = false;
				}
				var input = box.querySelector( 'input, textarea, select, fieldset' );
				if ( input ) {
					input.setAttribute( 'aria-invalid', 'true' );
					if ( ! first ) {
						first = box.querySelector( 'input, textarea, select' );
					}
				}
			} );
			if ( first ) {
				first.focus();
			}
		}

		function values() {
			var out = {};
			var els = form.elements;
			for ( var i = 0; i < els.length; i++ ) {
				var el = els[ i ];
				var m = /^frm\[([a-z0-9_-]+)\](\[\])?$/.exec( el.name || '' );
				if ( ! m ) {
					continue;
				}
				var key = m[ 1 ];
				if ( m[ 2 ] ) {
					out[ key ] = out[ key ] || [];
					if ( el.checked ) {
						out[ key ].push( el.value );
					}
				} else if ( el.type === 'radio' ) {
					if ( el.checked ) {
						out[ key ] = el.value;
					} else if ( ! ( key in out ) ) {
						out[ key ] = '';
					}
				} else if ( el.type === 'checkbox' ) {
					out[ key ] = el.checked ? '1' : '';
				} else {
					out[ key ] = el.value;
				}
			}
			return out;
		}

		function field( name ) {
			var el = form.querySelector( '[name="' + name + '"]' );
			return el ? el.value : '';
		}

		function done() {
			busy = false;
			if ( button ) {
				button.disabled = false;
				button.textContent = buttonText;
			}
		}

		form.addEventListener( 'submit', function ( e ) {
			if ( form.getAttribute( 'data-frm-native' ) ) {
				return; // The no-JS path, after a token failure.
			}
			e.preventDefault();
			if ( busy ) {
				return;
			}
			busy = true;
			clearErrors();
			say( root.getAttribute( 'data-sending' ) || '', 'info' );
			if ( button ) {
				button.disabled = true;
				button.textContent = root.getAttribute( 'data-sending' ) || buttonText;
			}

			start()
				.then(
					function ( tk ) {
						var left = tk.at + tk.w * 1000 + 400 - Date.now();
						return wait( left ).then( function () {
							return fetch( rest + 'submit', {
								method: 'POST',
								credentials: 'same-origin',
								headers: headers( true ),
								body: JSON.stringify( {
									form: parseInt( id, 10 ),
									lang: root.getAttribute( 'data-lang' ) || '',
									fields: values(),
									website: field( 'website' ),
									token: tk.t,
									answer: tk.answer,
									page: window.location.href,
									product: parseInt( field( 'frm_product' ), 10 ) || 0
								} )
							} );
						} );
					},
					function () {
						// No token: let the browser post the form (confirmation step).
						form.setAttribute( 'data-frm-native', '1' );
						done();
						form.submit();
						throw null;
					}
				)
				.then( function ( r ) {
					return r.json().then( function ( j ) {
						return { ok: r.ok, j: j || {} };
					} );
				} )
				.then( function ( res ) {
					ticket = null; // A token works once.
					form.addEventListener( 'focusin', start, { once: true } );
					if ( res.ok && res.j.ok ) {
						form.reset();
						say( String( res.j.message || '' ), 'ok' );
					} else {
						showErrors( res.j.errors );
						say( String( res.j.message || root.getAttribute( 'data-error' ) || '' ), 'error' );
					}
					done();
				} )
				.catch( function ( err ) {
					if ( err === null ) {
						return;
					}
					ticket = null;
					say( root.getAttribute( 'data-error' ) || '', 'error' );
					done();
				} );
		} );
	}

	function init() {
		var roots = document.querySelectorAll( '.frm-root' );
		for ( var i = 0; i < roots.length; i++ ) {
			setup( roots[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
