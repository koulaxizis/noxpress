/**
 * Book Feed — admin.js (v1.0.0)
 *
 * Search tab: select all, import of the ticked titles in small batches
 * (AJAX bkf_import; the server fetches each title again from the feed,
 * the browser only sends ids), "Σύνδεση" for a product with the same ISBN.
 * Publisher autocomplete (search form and rule cards). Product box
 * buttons. Live text of a running sync. Confirmations (data-bkf-confirm).
 * No dependencies. Without JavaScript every form still works (POST).
 */
( function () {
	'use strict';

	var cfg = window.bkfAdmin;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;
	var BATCH = 3;
	var RETRY_MS = 3000;
	var MAX_RETRY = 40;

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			var v = data[ k ];
			if ( Array.isArray( v ) ) {
				v.forEach( function ( x ) {
					body.append( k + '[]', x );
				} );
			} else {
				body.append( k, v );
			}
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( j && j.data && j.data.message ? j.data.message : t.failed );
				}
				return j.data;
			} );
	}

	/** Runs a request again while a sync step holds the lock. */
	function postRetry( action, data, onBusy, tries ) {
		tries = tries || 0;
		return post( action, data ).then( function ( d ) {
			if ( d && d.busy ) {
				if ( tries >= MAX_RETRY ) {
					throw new Error( t.busy );
				}
				if ( onBusy ) {
					onBusy();
				}
				return new Promise( function ( resolve ) {
					setTimeout( resolve, RETRY_MS );
				} ).then( function () {
					return postRetry( action, data, onBusy, tries + 1 );
				} );
			}
			return d;
		} );
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}

	/* ---------------------------------------------------------------
	 * Confirmations
	 * ------------------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-bkf-confirm]' );
		if ( b && ! window.confirm( t.confirm ) ) {
			e.preventDefault();
		}
	} );

	/* ---------------------------------------------------------------
	 * Publisher autocomplete
	 * ------------------------------------------------------------- */

	function autocomplete( input ) {
		var scope = input.closest( 'fieldset' ) || input.closest( 'form' );
		var idField = scope ? scope.querySelector( '.bkf-pub-id' ) : null;
		var box = el( 'ul', 'bkf-suggest' );
		var timer = null;
		var seq = 0;
		var active = -1;
		box.hidden = true;
		box.setAttribute( 'role', 'listbox' );
		input.parentNode.appendChild( box );
		input.setAttribute( 'aria-autocomplete', 'list' );

		function close() {
			box.hidden = true;
			box.textContent = '';
			active = -1;
		}

		function pick( item ) {
			input.value = item.name;
			if ( idField ) {
				idField.value = item.id;
			}
			close();
		}

		function show( items ) {
			box.textContent = '';
			active = -1;
			if ( ! items.length ) {
				box.appendChild( el( 'li', '', t.none ) );
				box.hidden = false;
				return;
			}
			items.forEach( function ( it ) {
				var li = el( 'li', '', it.name );
				li.setAttribute( 'role', 'option' );
				li.appendChild( el( 'small', '', it.id ) );
				li.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					pick( it );
				} );
				li._item = it;
				box.appendChild( li );
			} );
			box.hidden = false;
		}

		input.addEventListener( 'input', function () {
			// A typed name no longer matches a picked id (hidden id only:
			// in a rule card the id can also be written by hand).
			if ( idField && 'hidden' === idField.type ) {
				idField.value = '';
			}
			clearTimeout( timer );
			var v = input.value.trim();
			if ( v.length < 3 ) {
				close();
				return;
			}
			timer = setTimeout( function () {
				var mine = ++seq;
				post( 'bkf_pubs', { term: v } )
					.then( function ( items ) {
						if ( mine === seq ) {
							show( items || [] );
						}
					} )
					.catch( function ( err ) {
						if ( mine === seq ) {
							box.textContent = '';
							box.appendChild( el( 'li', '', err.message ) );
							box.hidden = false;
						}
					} );
			}, 300 );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			var items = Array.prototype.filter.call( box.children, function ( li ) {
				return !! li._item;
			} );
			if ( box.hidden || ! items.length ) {
				return;
			}
			if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
				e.preventDefault();
				active = ( active + ( 'ArrowDown' === e.key ? 1 : -1 ) + items.length ) % items.length;
				items.forEach( function ( li, i ) {
					li.classList.toggle( 'is-active', i === active );
				} );
			} else if ( 'Enter' === e.key && active >= 0 ) {
				e.preventDefault();
				pick( items[ active ]._item );
			} else if ( 'Escape' === e.key ) {
				close();
			}
		} );

		input.addEventListener( 'blur', function () {
			setTimeout( close, 150 );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.bkf-pub-name' ), autocomplete );

	/* ---------------------------------------------------------------
	 * Search results: select all, import, link
	 * ------------------------------------------------------------- */

	var form = document.getElementById( 'bkf-results' );
	if ( form ) {
		var all = document.getElementById( 'bkf-all' );
		var progress = document.getElementById( 'bkf-progress' );
		var importBtn = document.getElementById( 'bkf-import-btn' );
		var running = false;

		if ( all ) {
			all.addEventListener( 'change', function () {
				Array.prototype.forEach.call( form.querySelectorAll( '.bkf-pick' ), function ( c ) {
					c.checked = all.checked;
				} );
			} );
		}

		var mark = function ( id, cls, text, href ) {
			var row = form.querySelector( 'tr[data-bkf-id="' + ( window.CSS && CSS.escape ? CSS.escape( id ) : id ) + '"]' );
			if ( ! row ) {
				return;
			}
			var cell = row.querySelector( '.bkf-mark' );
			var cb = row.querySelector( '.bkf-pick' );
			if ( cb && 'bkf-badge--danger' !== cls ) {
				cb.remove();
			}
			if ( cb && 'bkf-badge--danger' === cls ) {
				cb.checked = false;
			}
			var b = el( href ? 'a' : 'span', 'bkf-badge ' + cls, text );
			if ( href ) {
				b.href = href;
			}
			cell.textContent = '';
			cell.appendChild( b );
			row.classList.add( 'bkf-row--done' );
		};

		var runImport = function () {
			var ids = Array.prototype.map.call( form.querySelectorAll( '.bkf-pick:checked' ), function ( c ) {
				return c.value;
			} );
			if ( ! ids.length ) {
				window.alert( t.pick );
				return;
			}
			running = true;
			importBtn.disabled = true;
			var total = ids.length;
			var done = 0;
			var status = form.querySelector( '[name="bkf_status"]' );
			var cat = form.querySelector( '[name="bkf_cat"]' );
			var tick = function ( extra ) {
				progress.textContent = t.importing + ' ' + done + '/' + total + ( extra ? ' · ' + extra : '' );
			};
			tick();

			var next = function () {
				if ( ! ids.length ) {
					progress.textContent = t.done + ' ' + done + '/' + total;
					running = false;
					importBtn.disabled = false;
					return;
				}
				var batch = ids.splice( 0, BATCH );
				postRetry(
					'bkf_import',
					{ ids: batch, status: status ? status.value : '', cat: cat ? cat.value : '0' },
					function () {
						tick( t.busy );
					}
				)
					.then( function ( d ) {
						var items = ( d && d.items ) || {};
						batch.forEach( function ( id ) {
							var it = items[ id ];
							if ( ! it ) {
								mark( id, 'bkf-badge--danger', t.error );
								return;
							}
							switch ( it.result ) {
								case 'imported':
									mark( id, 'bkf-badge--ok', t.imported, it.edit );
									break;
								case 'updated':
									mark( id, 'bkf-badge--ok', t.updated, it.edit );
									break;
								case 'exists':
									mark( id, 'bkf-badge--warn', t.exists, it.edit );
									break;
								case 'missing':
									mark( id, 'bkf-badge--danger', t.missing );
									break;
								default:
									mark( id, 'bkf-badge--danger', t.error + ( it.msg ? ': ' + it.msg : '' ) );
							}
						} );
					} )
					.catch( function ( err ) {
						batch.forEach( function ( id ) {
							mark( id, 'bkf-badge--danger', t.error + ': ' + err.message );
						} );
					} )
					.then( function () {
						done += batch.length;
						tick();
						next();
					} );
			};
			next();
		};

		form.addEventListener( 'submit', function ( e ) {
			var sub = e.submitter || document.activeElement;
			e.preventDefault();
			if ( running ) {
				return;
			}
			if ( sub && sub.classList && sub.classList.contains( 'bkf-link' ) ) {
				var parts = sub.value.split( '|' );
				var id = parts[ 0 ];
				sub.disabled = true;
				postRetry( 'bkf_link', { id: id, pid: parts[ 1 ] } )
					.then( function () {
						mark( id, 'bkf-badge--ok', t.linked, null );
					} )
					.catch( function ( err ) {
						sub.disabled = false;
						window.alert( err.message );
					} );
				return;
			}
			runImport();
		} );
	}

	/* ---------------------------------------------------------------
	 * Product box
	 * ------------------------------------------------------------- */

	var box = document.querySelector( '.bkf-box' );
	if ( box ) {
		var msg = box.querySelector( '.bkf-box-msg' );
		box.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.bkf-op' );
			if ( ! b ) {
				return;
			}
			e.preventDefault();
			var op = b.getAttribute( 'data-bkf-op' );
			if ( ( 'take' === op && ! window.confirm( t.take ) ) || ( 'unlink' === op && ! window.confirm( t.unlink ) ) ) {
				return;
			}
			var btns = box.querySelectorAll( '.bkf-op' );
			Array.prototype.forEach.call( btns, function ( x ) {
				x.disabled = true;
			} );
			msg.textContent = '…';
			postRetry( 'bkf_product', { pid: box.getAttribute( 'data-bkf-pid' ), op: op }, function () {
				msg.textContent = t.busy;
			} )
				.then( function ( d ) {
					msg.textContent = ( d && d.message ) || t.done;
					// The product changed on the server: reload so that
					// saving this screen does not write the old values back.
					setTimeout( function () {
						window.location.reload();
					}, 900 );
				} )
				.catch( function ( err ) {
					msg.textContent = err.message;
					Array.prototype.forEach.call( btns, function ( x ) {
						x.disabled = false;
					} );
				} );
		} );
	}

	/* ---------------------------------------------------------------
	 * Running sync: live text
	 * ------------------------------------------------------------- */

	var jobText = document.querySelector( '.bkf-job-text' );
	if ( jobText ) {
		var poll = function () {
			post( 'bkf_job', {} )
				.then( function ( d ) {
					if ( d.running ) {
						jobText.textContent = d.text;
						setTimeout( poll, 5000 );
						return;
					}
					jobText.textContent = t.finished + ' ';
					var a = el( 'a', '', t.reload );
					a.href = window.location.href;
					jobText.appendChild( a );
				} )
				.catch( function () {
					setTimeout( poll, 15000 );
				} );
		};
		setTimeout( poll, 5000 );
	}
} )();
