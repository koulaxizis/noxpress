/**
 * Greeklish Permalinks — admin.js (v1.0.0)
 *
 * Settings tab: live test (AJAX glp_test with the unsaved form values).
 * Bulk tab: scan, dry run and apply in batches, undo, server check.
 * No dependencies. The server recomputes every value: the browser only
 * sends item keys.
 */
( function () {
	'use strict';

	var cfg = window.glpAdmin;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;

	function $( id ) {
		return document.getElementById( id );
	}

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return s.replace( /%(\d+\$)?d/g, function ( m, pos ) {
			var v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return String( v );
		} );
	}

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

	/* ===== Live test ===== */

	var form = $( 'glp-settings-form' );
	var input = $( 'glp-test' );
	if ( form && input ) {
		var timer = null;
		var seq = 0;
		var run = function () {
			var text = input.value;
			if ( '' === text.trim() ) {
				$( 'glp-test-slug' ).textContent = '—';
				$( 'glp-test-file' ).textContent = '—';
				return;
			}
			var data = { text: text };
			new FormData( form ).forEach( function ( v, k ) {
				if ( 0 === k.indexOf( 'glp_' ) ) {
					data[ k ] = v;
				}
			} );
			var mine = ++seq;
			post( 'glp_test', data ).then( function ( d ) {
				if ( mine === seq ) {
					$( 'glp-test-slug' ).textContent = d.slug || '—';
					$( 'glp-test-file' ).textContent = d.file || '—';
				}
			} ).catch( function () {} );
		};
		var later = function () {
			clearTimeout( timer );
			timer = setTimeout( run, 250 );
		};
		input.addEventListener( 'input', later );
		form.addEventListener( 'change', later );
		form.addEventListener( 'input', function ( e ) {
			if ( e.target !== input ) {
				later();
			}
		} );
		// Enter in the test field must not submit the settings.
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
			}
		} );
	}

	/* ===== Bulk ===== */

	var scanBtn = $( 'glp-scan' );
	if ( ! scanBtn ) {
		return;
	}
	var tbody = document.querySelector( '#glp-table tbody' );
	var busy = false;
	var probed = false;

	function setBusy( on ) {
		busy = on;
		[ 'glp-scan', 'glp-dry', 'glp-apply', 'glp-undo' ].forEach( function ( id ) {
			if ( $( id ) ) {
				$( id ).disabled = on;
			}
		} );
	}

	function checkedValues( name ) {
		return Array.prototype.map.call( document.querySelectorAll( 'input[name="' + name + '"]:checked' ), function ( el ) {
			return el.value;
		} );
	}

	$( 'glp-files' ).addEventListener( 'change', function () {
		$( 'glp-files-warn' ).classList.toggle( 'glp-hidden', ! this.checked );
		if ( this.checked && ! probed ) {
			probed = true;
			post( 'glp_probe', {} ).then( function ( d ) {
				var msg = { yes: t.probeYes, no: t.probeNo, unknown: t.probeUnknown };
				$( 'glp-probe-out' ).textContent = msg[ d.state ] || '';
			} ).catch( function () {} );
		}
	} );

	function cell( tr, text, cls ) {
		var td = document.createElement( 'td' );
		if ( cls ) {
			td.className = cls;
		}
		if ( text instanceof Node ) {
			td.appendChild( text );
		} else {
			td.textContent = text;
		}
		tr.appendChild( td );
		return td;
	}

	function code( text ) {
		var c = document.createElement( 'code' );
		c.textContent = text;
		return c;
	}

	function render( data ) {
		tbody.textContent = '';
		var head = document.querySelectorAll( '#glp-table thead th' );
		head[ 1 ].textContent = t.colWhat;
		head[ 2 ].textContent = t.colNow;
		head[ 3 ].textContent = t.colAfter;
		head[ 4 ].textContent = t.colStatus;

		data.rows.forEach( function ( r ) {
			var tr = document.createElement( 'tr' );
			tr.setAttribute( 'data-k', r.k );
			tr.setAttribute( 'data-kind', r.kind );
			var cb = document.createElement( 'input' );
			cb.type = 'checkbox';
			cb.checked = true;
			cb.className = 'glp-row-cb';
			cell( tr, cb, 'glp-c' );

			var what = document.createElement( 'span' );
			var title = r.edit ? document.createElement( 'a' ) : document.createElement( 'strong' );
			title.textContent = r.title || '(—)';
			if ( r.edit ) {
				title.href = r.edit;
				title.target = '_blank';
				title.rel = 'noopener';
			}
			what.appendChild( title );
			var note = document.createElement( 'span' );
			note.className = 'glp-note';
			note.textContent = r.type;
			what.appendChild( note );
			cell( tr, what );
			cell( tr, code( r.old ) );
			var after = cell( tr, code( r.new ) );
			after.className = 'glp-after';
			cell( tr, '', 'glp-st' );
			tbody.appendChild( tr );
		} );

		var parts = [];
		Object.keys( data.counts ).forEach( function ( k ) {
			parts.push( k + ': ' + data.counts[ k ] );
		} );
		var counts = $( 'glp-counts' );
		counts.textContent = '';
		var strong = document.createElement( 'strong' );
		strong.textContent = fmt( t.found, data.total ) + ' ';
		counts.appendChild( strong );
		parts.forEach( function ( p ) {
			var s = document.createElement( 'span' );
			s.textContent = p;
			counts.appendChild( s );
		} );
		if ( data.more ) {
			var m = document.createElement( 'span' );
			m.className = 'glp-warn';
			m.textContent = fmt( t.more, data.rows.length, data.total );
			counts.appendChild( m );
		}
		$( 'glp-all' ).checked = true;
		$( 'glp-run-msg' ).textContent = '';
		$( 'glp-bulk-list' ).classList.toggle( 'glp-hidden', 0 === data.rows.length );
	}

	scanBtn.addEventListener( 'click', function () {
		if ( busy ) {
			return;
		}
		setBusy( true );
		$( 'glp-scan-msg' ).textContent = t.scanning;
		post( 'glp_scan', {
			pt: checkedValues( 'glp_pt[]' ),
			tx: checkedValues( 'glp_tx[]' ),
			files: $( 'glp-files' ).checked ? '1' : ''
		} ).then( function ( d ) {
			$( 'glp-scan-msg' ).textContent = 0 === d.total ? t.none : '';
			render( d );
		} ).catch( function ( e ) {
			$( 'glp-scan-msg' ).textContent = e.message;
		} ).then( function () {
			setBusy( false );
		} );
	} );

	$( 'glp-all' ).addEventListener( 'change', function () {
		var on = this.checked;
		tbody.querySelectorAll( '.glp-row-cb' ).forEach( function ( cb ) {
			cb.checked = on;
		} );
	} );

	function selectedRows() {
		return Array.prototype.filter.call( tbody.querySelectorAll( 'tr' ), function ( tr ) {
			return tr.querySelector( '.glp-row-cb' ).checked;
		} );
	}

	/** Batches: slugs by cfg.batch, files by cfg.batchFiles. */
	function batches( rows ) {
		var out = [];
		var cur = [];
		var curFiles = 0;
		rows.forEach( function ( tr ) {
			var isFile = 'file' === tr.getAttribute( 'data-kind' );
			if ( cur.length >= cfg.batch || ( isFile && curFiles >= cfg.batchFiles ) ) {
				out.push( cur );
				cur = [];
				curFiles = 0;
			}
			cur.push( tr );
			if ( isFile ) {
				curFiles++;
			}
		} );
		if ( cur.length ) {
			out.push( cur );
		}
		return out;
	}

	function progress( done, total ) {
		var p = $( 'glp-progress' );
		p.classList.remove( 'glp-hidden' );
		p.querySelector( '.glp-progress-bar' ).style.width = ( total ? Math.round( 100 * done / total ) : 100 ) + '%';
	}

	function showResult( tr, r, apply ) {
		tr.classList.remove( 'is-ok', 'is-skip', 'is-error' );
		tr.classList.add( 'is-' + r.status );
		var st = tr.querySelector( '.glp-st' );
		st.textContent = 'ok' === r.status ? t.ok : ( 'skip' === r.status ? t.skip : t.error );
		if ( r.new ) {
			var after = tr.querySelector( '.glp-after' );
			after.textContent = '';
			after.appendChild( code( r.new ) );
		}
		var lines = [];
		if ( r.note ) {
			lines.push( r.note );
		}
		if ( r.refs ) {
			lines.push( fmt( t.refs, r.refs.files, r.refs.posts, r.refs.dl, r.refs.other ) + ( r.refs.others && r.refs.others.length ? ' (' + r.refs.others.join( ', ' ) + ')' : '' ) );
		}
		lines.forEach( function ( l ) {
			var n = document.createElement( 'span' );
			n.className = 'glp-note';
			n.textContent = l;
			st.appendChild( n );
		} );
		if ( apply && 'ok' === r.status ) {
			var cb = tr.querySelector( '.glp-row-cb' );
			cb.checked = false;
			cb.disabled = true;
		}
	}

	function runBatches( apply ) {
		var rows = selectedRows();
		if ( ! rows.length ) {
			$( 'glp-run-msg' ).textContent = t.nothingSel;
			return;
		}
		if ( apply ) {
			if ( ! $( 'glp-backup' ).checked ) {
				$( 'glp-run-msg' ).textContent = t.needBackup;
				return;
			}
			if ( ! window.confirm( fmt( t.confirm, rows.length ) ) ) {
				return;
			}
		}
		var list = batches( rows );
		var run = apply ? 'new' : 'dry';
		var n = { ok: 0, skip: 0, error: 0 };
		var i = 0;
		setBusy( true );
		progress( 0, list.length );
		$( 'glp-run-msg' ).textContent = '';

		var next = function () {
			if ( i >= list.length ) {
				setBusy( false );
				$( 'glp-run-msg' ).textContent = fmt( apply ? t.done : t.dryDone, n.ok, n.skip, n.error );
				if ( apply && n.ok ) {
					var z = $( 'glp-undo-zone' );
					z.textContent = '';
					var a = document.createElement( 'a' );
					a.href = window.location.href;
					a.textContent = t.undoHint;
					z.appendChild( a );
				}
				return;
			}
			var batch = list[ i ];
			var byKey = {};
			batch.forEach( function ( tr ) {
				byKey[ tr.getAttribute( 'data-k' ) ] = tr;
			} );
			post( 'glp_step', { run: run, keys: Object.keys( byKey ) } ).then( function ( d ) {
				if ( apply ) {
					run = d.run;
				}
				d.items.forEach( function ( r ) {
					n[ r.status ] = ( n[ r.status ] || 0 ) + 1;
					if ( byKey[ r.k ] ) {
						showResult( byKey[ r.k ], r, apply );
					}
				} );
				i++;
				progress( i, list.length );
				next();
			} ).catch( function ( e ) {
				setBusy( false );
				$( 'glp-run-msg' ).textContent = e.message;
			} );
		};
		next();
	}

	$( 'glp-dry' ).addEventListener( 'click', function () {
		if ( ! busy ) {
			runBatches( false );
		}
	} );
	$( 'glp-apply' ).addEventListener( 'click', function () {
		if ( ! busy ) {
			runBatches( true );
		}
	} );

	var undoBtn = $( 'glp-undo' );
	if ( undoBtn ) {
		undoBtn.addEventListener( 'click', function () {
			if ( busy || ! window.confirm( t.confirmUn ) ) {
				return;
			}
			setBusy( true );
			var errors = [];
			var step = function () {
				post( 'glp_undo', {} ).then( function ( d ) {
					errors = errors.concat( d.errors || [] );
					if ( d.left > 0 && d.done > 0 ) {
						step();
						return;
					}
					setBusy( false );
					var zone = $( 'glp-undo-zone' );
					zone.textContent = '';
					var p = document.createElement( 'p' );
					p.textContent = t.undoDone;
					zone.appendChild( p );
					errors.forEach( function ( er ) {
						var e = document.createElement( 'p' );
						e.className = 'glp-warn';
						e.textContent = er;
						zone.appendChild( e );
					} );
				} ).catch( function ( e ) {
					setBusy( false );
					window.alert( e.message );
				} );
			};
			step();
		} );
	}
}() );
