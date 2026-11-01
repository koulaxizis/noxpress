/**
 * Noxpress hub: bulk actions (install, update, uninstall all), Bible §16.
 *
 * Each button opens a dialog with a checklist of the plugins it touches.
 * The run goes one plugin at a time, one request each, through WordPress's
 * own AJAX (install-plugin, update-plugin, delete-plugin) plus the hub's
 * noxpress_bulk_state for (de)activation. Uninstall deactivates every
 * selected plugin first, then deletes them, and needs a typed word.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'nx-bulk' );
	var cfgNode = document.getElementById( 'nx-bulk-config' );
	var dialog = document.getElementById( 'nx-bulk-dialog' );
	if ( ! root || ! cfgNode || ! dialog || typeof dialog.showModal !== 'function' ) {
		if ( root ) {
			root.hidden = true;
		}
		return;
	}

	var cfg = JSON.parse( cfgNode.textContent );
	var t = cfg.text;
	var running = false;

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'className' === k ) {
				node.className = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function post( data ) {
		var body = new FormData();
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.text();
			} )
			.then( function ( txt ) {
				var json = null;
				try {
					// WordPress may print notices before the JSON.
					json = JSON.parse( txt.slice( txt.indexOf( '{' ) ) );
				} catch ( e ) {}
				if ( ! json ) {
					throw new Error( '-1' === txt.trim() ? t.network : ( txt.replace( /<[^>]+>/g, ' ' ).trim().slice( 0, 200 ) || t.network ) );
				}
				if ( ! json.success ) {
					var d = json.data || {};
					throw new Error( d.errorMessage || d.message || t.failed );
				}
				return json.data;
			}, function () {
				throw new Error( t.network );
			} );
	}

	function wpAjax( action, item ) {
		var data = { action: action, slug: item.slug, _ajax_nonce: cfg.wpNonce };
		if ( 'install-plugin' !== action ) {
			data.plugin = item.file;
		}
		return post( data );
	}

	function setState( op, slug ) {
		return post( { action: 'noxpress_bulk_state', op: op, slug: slug, nonce: cfg.nonce } );
	}

	function mark( li, state, msg ) {
		var s = li.querySelector( '.nx-bulk-state' );
		s.className = 'nx-bulk-state nx-badge nx-badge--' + ( { done: 'ok', failed: 'warn', working: 'beta' }[ state ] || 'muted' );
		s.textContent = t[ state ] + ( msg ? ': ' + msg : '' );
		var row = document.querySelector( 'tr[data-nx-slug="' + li.getAttribute( 'data-slug' ) + '"] td:nth-child(2)' );
		if ( row ) {
			var b = row.querySelector( '.nx-bulk-row' ) || row.appendChild( el( 'span', {} ) );
			b.className = s.className + ' nx-bulk-row';
			b.textContent = t[ state ];
		}
	}

	function open( op ) {
		var list = cfg.lists[ op ] || [];
		dialog.textContent = '';
		var form = el( 'form', { method: 'dialog' } );
		form.appendChild( el( 'h2', { id: 'nx-bulk-title' }, t[ op + 'Title' ] ) );
		form.appendChild( el( 'p', {}, t[ op + 'Intro' ] ) );
		if ( 'uninstall' === op ) {
			form.appendChild( el( 'p', { className: 'nx-danger-text' }, t.liveWarn ) );
		}
		if ( 'install' === op && cfg.betaOnly ) {
			form.appendChild( el( 'p', { className: 'nx-muted' }, t.betaOnly.replace( '%d', cfg.betaOnly ) ) );
		}

		var ul = el( 'ul', { className: 'nx-bulk-list' } );
		list.forEach( function ( item ) {
			var li = el( 'li', { 'data-slug': item.slug } );
			var label = el( 'label', { className: 'nx-check' } );
			var box = el( 'input', { type: 'checkbox', value: item.slug } );
			box.checked = !! item.checked;
			label.appendChild( box );
			label.appendChild( document.createTextNode( ' ' + item.name + ' ' ) );
			label.appendChild( el( 'code', {}, item.version ) );
			li.appendChild( label );
			li.appendChild( el( 'span', { className: 'nx-bulk-state' } ) );
			if ( item.note ) {
				li.appendChild( el( 'span', { className: 'nx-desc' }, item.note ) );
			}
			ul.appendChild( li );
		} );
		form.appendChild( ul );

		var activate = null;
		if ( 'install' === op ) {
			var al = el( 'label', { className: 'nx-check' } );
			activate = el( 'input', { type: 'checkbox' } );
			al.appendChild( activate );
			al.appendChild( document.createTextNode( ' ' + t.activate ) );
			form.appendChild( al );
		}

		var word = null;
		if ( 'uninstall' === op ) {
			var wl = el( 'label', { className: 'nx-label nx-bulk-word' }, t.typeWord.replace( '%s', cfg.word ) + ' ' );
			word = el( 'input', { type: 'text', autocomplete: 'off', spellcheck: 'false' } );
			wl.appendChild( word );
			form.appendChild( wl );
		}

		var status = el( 'p', { className: 'nx-bulk-status', role: 'status' } );
		form.appendChild( status );

		var actions = el( 'p', { className: 'nx-bulk-actions' } );
		var start = el( 'button', { type: 'button', className: 'button button-primary' + ( 'uninstall' === op ? ' nx-danger-button' : '' ) }, t.start );
		var cancel = el( 'button', { type: 'button', className: 'button' }, t.cancel );
		actions.appendChild( start );
		actions.appendChild( cancel );
		form.appendChild( actions );
		dialog.appendChild( form );

		function picked() {
			return list.filter( function ( item ) {
				return ul.querySelector( 'input[value="' + item.slug + '"]' ).checked;
			} );
		}
		function refresh() {
			var ok = picked().length > 0;
			if ( word ) {
				ok = ok && word.value.trim().toUpperCase() === cfg.word.toUpperCase();
			}
			start.disabled = ! ok;
		}
		form.addEventListener( 'input', refresh );
		form.addEventListener( 'change', refresh );
		refresh();

		cancel.addEventListener( 'click', function () {
			if ( ! running ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'cancel', function ( e ) {
			if ( running ) {
				e.preventDefault();
			}
		} );

		start.addEventListener( 'click', function () {
			var items = picked();
			running = true;
			start.disabled = true;
			cancel.disabled = true;
			Array.prototype.forEach.call( form.querySelectorAll( 'input' ), function ( i ) {
				i.disabled = true;
			} );
			var lis = {};
			items.forEach( function ( item ) {
				lis[ item.slug ] = ul.querySelector( 'li[data-slug="' + item.slug + '"]' );
				mark( lis[ item.slug ], 'waiting' );
			} );
			run( op, items, lis, activate && activate.checked ).then( function ( res ) {
				running = false;
				status.textContent = t.summary.replace( '%1$d', res.ok ).replace( '%2$d', res.failed );
				var gone = 'uninstall' === op && res.deleted >= cfg.suite;
				if ( gone ) {
					status.textContent += ' ' + t.gone;
				}
				cancel.textContent = t.close;
				cancel.disabled = false;
				cancel.onclick = function () {
					window.location.href = gone ? cfg.plugins : window.location.href.split( '#' )[ 0 ];
				};
			} );
		} );

		dialog.showModal();
	}

	function run( op, items, lis, andActivate ) {
		var res = { ok: 0, failed: 0, deleted: 0 };
		var chain = Promise.resolve();
		var failedSlugs = {};

		function step( item, fn ) {
			chain = chain.then( function () {
				if ( failedSlugs[ item.slug ] ) {
					return;
				}
				mark( lis[ item.slug ], 'working' );
				return fn().then( function () {
					return true;
				}, function ( err ) {
					failedSlugs[ item.slug ] = true;
					res.failed++;
					mark( lis[ item.slug ], 'failed', err.message );
				} );
			} );
		}
		function finish( item, counter ) {
			chain = chain.then( function () {
				if ( ! failedSlugs[ item.slug ] ) {
					res.ok++;
					if ( counter ) {
						res.deleted++;
					}
					mark( lis[ item.slug ], 'done' );
				}
			} );
		}

		if ( 'uninstall' === op ) {
			// Deactivate everything first: the hub's own AJAX needs an active suite plugin.
			items.forEach( function ( item ) {
				if ( item.active ) {
					step( item, function () {
						return setState( 'deactivate', item.slug );
					} );
				}
			} );
			items.forEach( function ( item ) {
				step( item, function () {
					return wpAjax( 'delete-plugin', item );
				} );
				finish( item, true );
			} );
		} else {
			items.forEach( function ( item ) {
				step( item, function () {
					return wpAjax( 'install' === op ? 'install-plugin' : 'update-plugin', item );
				} );
				if ( andActivate ) {
					step( item, function () {
						return setState( 'activate', item.slug );
					} );
				}
				finish( item, false );
			} );
		}
		return chain.then( function () {
			return res;
		} );
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( running ) {
			e.preventDefault();
			e.returnValue = t.leave;
			return t.leave;
		}
	} );

	Array.prototype.forEach.call( root.querySelectorAll( '[data-nx-bulk]' ), function ( b ) {
		b.addEventListener( 'click', function () {
			open( b.getAttribute( 'data-nx-bulk' ) );
		} );
	} );
}() );
