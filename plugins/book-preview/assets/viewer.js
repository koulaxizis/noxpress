/**
 * Book Preview — viewer.js (v1.0.0)
 *
 * Opens the sample in a <dialog> on the first click of a .bpv-open button.
 * PDF.js (bundled, assets/vendor/pdfjs) is imported only then. Pages are
 * drawn when they come near the screen and released when far away, so
 * long samples stay light on phones. No cookies, no storage, no requests
 * other than the sample file and the bundled PDF.js files.
 */
( function () {
	'use strict';

	var C = window.BPV_VIEWER;
	if ( ! C || ! document.addEventListener ) {
		return;
	}
	var T = C.i18n || {};
	var libPromise = null;

	function lib() {
		if ( ! libPromise ) {
			libPromise = import( C.lib ).then( function ( m ) {
				m.GlobalWorkerOptions.workerSrc = C.worker;
				return m;
			} );
		}
		return libPromise;
	}

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text ) {
			e.textContent = text;
		}
		return e;
	}

	function fmt( s, a, b ) {
		return String( s ).replace( '%1$s', a ).replace( '%2$s', b );
	}

	function iconButton( cls, label, path ) {
		var b = el( 'button', 'bpv-tool ' + cls );
		b.type = 'button';
		b.setAttribute( 'aria-label', label );
		b.title = label;
		b.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="' + path + '"/></svg>';
		return b;
	}

	var ICON = {
		close: 'M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7 4.3 4.3l6.3 6.3 6.3-6.3z',
		plus: 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z',
		minus: 'M5 11h14v2H5z',
		full: 'M4 4h6v2H6v4H4zm10 0h6v6h-2V6h-4zM4 14h2v4h4v2H4zm14 0h2v6h-6v-2h4z'
	};

	function open( btn ) {
		var data;
		try {
			data = JSON.parse( btn.getAttribute( 'data-bpv' ) || '{}' );
		} catch ( e ) {
			return;
		}
		if ( ! data.src ) {
			return;
		}
		if ( typeof window.HTMLDialogElement !== 'function' ) {
			window.open( data.src, '_blank', 'noopener' );
			return;
		}

		var dlg = el( 'dialog', 'bpv-dialog' );
		dlg.setAttribute( 'aria-label', ( T.sample || '' ) + ': ' + ( data.title || '' ) );
		var bar = el( 'div', 'bpv-bar' );
		var title = el( 'div', 'bpv-bar__title' );
		title.appendChild( el( 'span', 'bpv-bar__tag', T.sample ) );
		title.appendChild( el( 'span', 'bpv-bar__name', data.title ) );
		var count = el( 'div', 'bpv-bar__count' );
		count.setAttribute( 'aria-live', 'polite' );
		var tools = el( 'div', 'bpv-bar__tools' );
		var bOut = iconButton( 'bpv-zoom-out', T.zoomOut, ICON.minus );
		var bIn = iconButton( 'bpv-zoom-in', T.zoomIn, ICON.plus );
		var bFull = iconButton( 'bpv-full', T.full, ICON.full );
		var bClose = iconButton( 'bpv-close', T.close, ICON.close );
		tools.appendChild( bOut );
		tools.appendChild( bIn );
		if ( dlg.requestFullscreen ) {
			tools.appendChild( bFull );
		}
		tools.appendChild( bClose );
		bar.appendChild( title );
		bar.appendChild( count );
		bar.appendChild( tools );
		var scroller = el( 'div', 'bpv-scroll' );
		scroller.tabIndex = 0;
		var status = el( 'p', 'bpv-msg', T.loading );
		scroller.appendChild( status );
		dlg.appendChild( bar );
		dlg.appendChild( scroller );
		document.body.appendChild( dlg );
		document.documentElement.classList.add( 'bpv-lock' );
		dlg.showModal();
		bClose.focus();

		var doc = null;
		var task = null;
		var zoom = 1;
		var slots = [];
		var drawn = [];
		var observer = null;
		var closed = false;

		function cleanup() {
			if ( closed ) {
				return;
			}
			closed = true;
			if ( observer ) {
				observer.disconnect();
			}
			slots.forEach( function ( s ) {
				if ( s.task ) {
					s.task.cancel();
				}
			} );
			if ( task ) {
				task.destroy();
			}
			document.documentElement.classList.remove( 'bpv-lock' );
			if ( dlg.parentNode ) {
				dlg.parentNode.removeChild( dlg );
			}
			btn.focus();
		}
		dlg.addEventListener( 'close', cleanup );
		bClose.addEventListener( 'click', function () {
			dlg.close();
		} );
		// A click on the backdrop closes the dialog.
		dlg.addEventListener( 'click', function ( e ) {
			if ( e.target === dlg ) {
				dlg.close();
			}
		} );
		bFull.addEventListener( 'click', function () {
			if ( document.fullscreenElement ) {
				document.exitFullscreen();
			} else {
				dlg.requestFullscreen().catch( function () {} );
			}
		} );

		function width() {
			return Math.max( 200, Math.min( scroller.clientWidth - 32, 980 ) );
		}

		function layout() {
			var w = width() * zoom;
			slots.forEach( function ( s ) {
				var h = w * s.ratio;
				s.box.style.width = Math.round( w ) + 'px';
				s.box.style.height = Math.round( h ) + 'px';
				release( s );
			} );
		}

		function release( s ) {
			if ( s.task ) {
				s.task.cancel();
				s.task = null;
			}
			if ( s.canvas ) {
				s.canvas.width = 0;
				s.canvas.height = 0;
				if ( s.canvas.parentNode ) {
					s.canvas.parentNode.removeChild( s.canvas );
				}
				s.canvas = null;
			}
			s.drawn = false;
			var i = drawn.indexOf( s );
			if ( i >= 0 ) {
				drawn.splice( i, 1 );
			}
		}

		function draw( s ) {
			if ( s.drawn || closed ) {
				return;
			}
			s.drawn = true;
			drawn.push( s );
			// Keep at most 8 pages in memory: release the one farthest away.
			if ( drawn.length > 8 ) {
				var far = drawn.slice().sort( function ( a, b ) {
					return Math.abs( b.n - s.n ) - Math.abs( a.n - s.n );
				} )[ 0 ];
				release( far );
			}
			doc.getPage( s.n ).then( function ( page ) {
				if ( ! s.drawn || closed ) {
					return;
				}
				var base = page.getViewport( { scale: 1 } );
				s.ratio = base.height / base.width;
				var w = width() * zoom;
				s.box.style.height = Math.round( w * s.ratio ) + 'px';
				var dpr = Math.min( window.devicePixelRatio || 1, 2 );
				var vp = page.getViewport( { scale: ( w / base.width ) * dpr } );
				var canvas = el( 'canvas', 'bpv-canvas' );
				canvas.width = Math.floor( vp.width );
				canvas.height = Math.floor( vp.height );
				canvas.setAttribute( 'aria-hidden', 'true' );
				s.canvas = canvas;
				s.box.appendChild( canvas );
				s.task = page.render( { canvas: canvas, canvasContext: canvas.getContext( '2d' ), viewport: vp } );
				s.task.promise.then( function () {
					s.task = null;
				} ).catch( function () {} );
			} ).catch( function () {
				s.drawn = false;
			} );
		}

		function current() {
			var top = scroller.scrollTop + scroller.clientHeight / 3;
			var n = 1;
			slots.forEach( function ( s ) {
				if ( s.box.offsetTop <= top ) {
					n = s.n;
				}
			} );
			count.textContent = fmt( T.page, n, slots.length );
		}

		function endCard( end ) {
			var card = el( 'div', 'bpv-end' );
			if ( end.image ) {
				var img = el( 'img', 'bpv-end__img' );
				img.src = end.image;
				img.alt = '';
				img.loading = 'lazy';
				card.appendChild( img );
			}
			var body = el( 'div', 'bpv-end__body' );
			body.appendChild( el( 'p', 'bpv-end__title', end.title ) );
			body.appendChild( el( 'p', 'bpv-end__name', end.name ) );
			if ( end.price ) {
				body.appendChild( el( 'p', 'bpv-end__price', end.price ) );
			}
			var cta;
			if ( 'scroll' === end.action ) {
				cta = el( 'button', btn.className.replace( 'bpv-open', 'bpv-buy' ), end.button );
				cta.type = 'button';
				cta.addEventListener( 'click', function () {
					dlg.close();
					var form = document.querySelector( 'form.cart' );
					var target = form ? ( form.querySelector( '[type=submit], button' ) || form ) : null;
					if ( target ) {
						target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
						if ( target.focus ) {
							target.focus( { preventScroll: true } );
						}
					}
				} );
			} else {
				cta = el( 'a', btn.className.replace( 'bpv-open', 'bpv-buy' ), end.button );
				cta.href = end.href;
			}
			body.appendChild( cta );
			card.appendChild( body );
			return card;
		}

		lib().then( function ( pdfjs ) {
			if ( closed ) {
				return null;
			}
			task = pdfjs.getDocument( {
				url: data.src,
				withCredentials: false,
				standardFontDataUrl: C.fonts,
				wasmUrl: C.wasm,
				iccUrl: C.iccs,
				useSystemFonts: false,
				enableXfa: false,
				isEvalSupported: false
			} );
			return task.promise;
		} ).then( function ( pdf ) {
			if ( ! pdf || closed ) {
				return null;
			}
			doc = pdf;
			return pdf.getPage( 1 );
		} ).then( function ( first ) {
			if ( ! first || closed ) {
				return;
			}
			var vp = first.getViewport( { scale: 1 } );
			var ratio = vp.height / vp.width;
			scroller.removeChild( status );
			for ( var i = 1; i <= doc.numPages; i++ ) {
				var box = el( 'div', 'bpv-page' );
				box.setAttribute( 'data-n', String( i ) );
				scroller.appendChild( box );
				slots.push( { n: i, box: box, ratio: ratio, drawn: false, canvas: null, task: null } );
			}
			if ( data.end ) {
				scroller.appendChild( endCard( data.end ) );
			}
			layout();
			current();
			if ( 'IntersectionObserver' in window ) {
				observer = new IntersectionObserver( function ( entries ) {
					entries.forEach( function ( en ) {
						if ( en.isIntersecting ) {
							draw( slots[ parseInt( en.target.getAttribute( 'data-n' ), 10 ) - 1 ] );
						}
					} );
				}, { root: scroller, rootMargin: '150% 0px' } );
				slots.forEach( function ( s ) {
					observer.observe( s.box );
				} );
			} else {
				slots.forEach( draw );
			}
			scroller.addEventListener( 'scroll', current, { passive: true } );
			scroller.focus( { preventScroll: true } );
		} ).catch( function () {
			if ( closed ) {
				return;
			}
			status.textContent = T.error;
			status.className = 'bpv-msg bpv-msg--error';
			if ( ! status.parentNode ) {
				scroller.appendChild( status );
			}
		} );

		function rezoom( f ) {
			var before = scroller.scrollHeight ? scroller.scrollTop / scroller.scrollHeight : 0;
			zoom = Math.max( 0.5, Math.min( 3, Math.round( zoom * f * 100 ) / 100 ) );
			layout();
			scroller.scrollTop = before * scroller.scrollHeight;
			// Pages in view are drawn again by the observer.
			if ( observer ) {
				slots.forEach( function ( s ) {
					observer.unobserve( s.box );
					observer.observe( s.box );
				} );
			}
		}
		bIn.addEventListener( 'click', function () {
			rezoom( 1.25 );
		} );
		bOut.addEventListener( 'click', function () {
			rezoom( 0.8 );
		} );
		var resizeTimer = null;
		window.addEventListener( 'resize', function () {
			if ( closed || ! slots.length ) {
				return;
			}
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( function () {
				rezoom( 1 );
			}, 200 );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		var b = t && t.closest ? t.closest( '.bpv-open' ) : null;
		if ( b ) {
			e.preventDefault();
			open( b );
		}
	} );
}() );
