/**
 * Data Migrator admin: backup steps, chunked upload, analysis steps,
 * confirm dialogs. Every step is a short AJAX request; the server keeps
 * the job state, so a reload resumes where it stopped.
 */
( function () {
	'use strict';

	var A = window.nmAdmin;
	if ( ! A ) {
		return;
	}
	// wp_localize_script() turns numbers into strings.
	var CHUNK = parseInt( A.chunk, 10 ) || 1048576;
	var $ = function ( sel, root ) {
		return ( root || document ).querySelector( sel );
	};

	function post( action, data ) {
		var fd = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) && data ) {
			Object.keys( data ).forEach( function ( k ) {
				fd.append( k, data[ k ] );
			} );
		}
		fd.append( 'action', 'nm_' + action );
		fd.append( 'nonce', A.nonce );
		return fetch( A.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json().catch( function () {
				throw new Error( 'http ' + r.status );
			} );
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				var e = new Error( ( j && j.data && j.data.text ) || A.i18n.error );
				e.final = true;
				throw e;
			}
			return j.data;
		} );
	}

	function show( box, pct, text, err ) {
		if ( ! box ) {
			return;
		}
		box.classList.add( 'is-on' );
		var bar = $( '.nm-progress span', box );
		if ( bar && null !== pct ) {
			bar.style.width = Math.max( 0, Math.min( 100, pct ) ) + '%';
		}
		var t = $( '.nm-text', box );
		if ( t ) {
			t.textContent = text;
			t.classList.toggle( 'nm-err', !! err );
		}
	}

	/** Step a job until it ends. onDone( data ) gets the last answer. */
	function run( job, box, onDone ) {
		var fails = 0;
		var cancel = box ? $( '.nm-cancel', box ) : null;
		if ( cancel ) {
			cancel.hidden = false;
			cancel.onclick = function () {
				if ( window.confirm( A.i18n.cancel ) ) {
					post( 'cancel', { job: job } ).then( function () {
						window.location.reload();
					} );
				}
			};
		}
		( function tick() {
			post( 'step', { job: job } ).then( function ( d ) {
				fails = 0;
				show( box, d.pct, d.text, 'failed' === d.status );
				if ( 'running' === d.status || 'busy' === d.status ) {
					setTimeout( tick, 'busy' === d.status ? 2000 : 150 );
					return;
				}
				if ( cancel ) {
					cancel.hidden = true;
				}
				onDone( d );
			} ).catch( function ( e ) {
				window.console.error( e );
				if ( e.final ) {
					show( box, null, e.message, true );
					return;
				}
				fails++;
				if ( fails > 8 ) {
					show( box, null, A.i18n.stopped, true );
					return;
				}
				show( box, null, A.i18n.error, true );
				setTimeout( tick, 3000 * fails );
			} );
		} )();
	}

	/* Backups page --------------------------------------------------- */

	var form = $( '#nm-backup-form' );
	var brun = $( '#nm-backup-run' );
	if ( form ) {
		var custom = $( '.nm-custom', form );
		form.addEventListener( 'change', function ( e ) {
			if ( 'scope' === e.target.name && custom ) {
				custom.hidden = 'custom' !== e.target.value;
			}
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var btn = $( 'button[type="submit"], .button-primary', form );
			if ( btn ) {
				btn.disabled = true;
			}
			show( brun, 0, '…' );
			post( 'backup_start', new FormData( form ) ).then( function ( d ) {
				run( d.job, brun, function () {
					window.location.href = A.backups;
				} );
			} ).catch( function ( err ) {
				show( brun, null, err.message, true );
				if ( btn ) {
					btn.disabled = false;
				}
			} );
		} );
	}
	if ( brun && brun.getAttribute( 'data-job' ) ) {
		run( brun.getAttribute( 'data-job' ), brun, function () {
			window.location.href = A.backups;
		} );
	}

	/* Restore page: chunked upload ----------------------------------- */

	var up = $( '#nm-upload-go' );
	if ( up ) {
		var urun = $( '#nm-upload-run' );
		up.addEventListener( 'click', function () {
			var files = Array.prototype.slice.call( $( '#nm-upload-file' ).files || [] );
			if ( ! files.length ) {
				show( urun, 0, A.i18n.nofile, true );
				return;
			}
			if ( files.length > 100 || files.some( function ( f ) {
				return ! /\.zip$/i.test( f.name );
			} ) ) {
				show( urun, 0, A.i18n.notzip, true );
				return;
			}
			up.disabled = true;
			var id = '';
			var a = new Uint8Array( 16 );
			window.crypto.getRandomValues( a );
			for ( var i = 0; i < a.length; i++ ) {
				id += ( '0' + a[ i ].toString( 16 ) ).slice( -2 );
			}
			var total = files.reduce( function ( s, f ) {
				return s + f.size;
			}, 0 );
			var sent = 0;
			var fi = 0;
			var off = 0;
			var fails = 0;
			( function next() {
				if ( fi >= files.length ) {
					show( urun, 100, A.i18n.uploaded );
					post( 'upload_done', { upload: id } ).then( function ( d ) {
						window.location.href = A.restore + '&backup=' + encodeURIComponent( d.id );
					} ).catch( function ( e ) {
						show( urun, null, e.message, true );
						up.disabled = false;
					} );
					return;
				}
				var f = files[ fi ];
				var fd = new FormData();
				fd.append( 'upload', id );
				fd.append( 'index', fi );
				fd.append( 'offset', off );
				fd.append( 'total', f.size );
				fd.append( 'chunk', f.slice( off, off + CHUNK ), 'chunk' );
				post( 'upload', fd ).then( function ( d ) {
					fails = 0;
					sent += d.size - off;
					off = d.size;
					if ( off >= f.size ) {
						fi++;
						off = 0;
					}
					show( urun, total ? ( 100 * sent ) / total : 100, f.name + ' · ' + Math.round( ( 100 * sent ) / Math.max( 1, total ) ) + '%' );
					next();
				} ).catch( function ( e ) {
					window.console.error( e );
					if ( e.final || ++fails > 8 ) {
						show( urun, null, e.final ? e.message : A.i18n.stopped, true );
						up.disabled = false;
						return;
					}
					show( urun, null, A.i18n.error, true );
					setTimeout( next, 3000 * fails );
				} );
			} )();
		} );
	}

	/* Restore page: analysis ----------------------------------------- */

	var an = $( '#nm-analyze-go' );
	if ( an ) {
		var arun = $( '#nm-analyze-run' );
		an.addEventListener( 'click', function () {
			an.disabled = true;
			show( arun, 0, '…' );
			post( 'analyze', { id: $( '#nm-pick' ).value } ).then( function ( d ) {
				run( d.job, arun, function ( r ) {
					if ( 'done' === r.status && r.url ) {
						window.location.href = r.url;
					} else {
						an.disabled = false;
					}
				} );
			} ).catch( function ( e ) {
				show( arun, null, e.message, true );
				an.disabled = false;
			} );
		} );
	}

	/* Confirm dialogs on dangerous forms ----------------------------- */

	document.addEventListener( 'submit', function ( e ) {
		var msg = e.target.getAttribute && e.target.getAttribute( 'data-nm-confirm' );
		if ( msg && ! window.confirm( msg ) ) {
			e.preventDefault();
		}
	}, true );
}() );
