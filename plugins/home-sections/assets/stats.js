/*
 * Home Sections — live counters for [hs_stats].
 *
 * The page holds the numbers as the server printed them (they work
 * without JavaScript). This script asks the plugin's REST route for the
 * current numbers once, so a page that a page cache kept for hours still
 * shows the latest ones, and counts each number up from 0 the first time
 * its counters come into view. With prefers-reduced-motion, or without
 * IntersectionObserver, the numbers are only replaced. window.HMS_STATS
 * guards against a second copy.
 */
( function () {
	if ( window.HMS_STATS ) {
		return;
	}
	window.HMS_STATS = true;

	var cfg = window.HMS_STATS_CFG || {};
	var fmt;
	try {
		fmt = new Intl.NumberFormat( cfg.locale || document.documentElement.lang || undefined );
	} catch ( e ) {
		fmt = { format: function ( n ) { return String( n ); } };
	}
	var still = ! window.IntersectionObserver || ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	var dur = 1400;

	function vals( root ) {
		return root.querySelectorAll( '.hms-stats__val[data-hms-n]' );
	}

	function target( el ) {
		var n = parseInt( el.getAttribute( 'data-hms-n' ), 10 );
		return isNaN( n ) || n < 0 ? 0 : n;
	}

	function show( el, n ) {
		el.textContent = fmt.format( n );
	}

	function count( el ) {
		if ( el.hmsRun ) {
			return;
		}
		el.hmsRun = true;
		var start = null;
		function step( t ) {
			if ( start === null ) {
				start = t;
			}
			var p = Math.min( 1, ( t - start ) / dur );
			var e = 1 - Math.pow( 1 - p, 3 );
			show( el, Math.round( target( el ) * e ) );
			if ( p < 1 ) {
				window.requestAnimationFrame( step );
			} else {
				el.hmsDone = true;
				show( el, target( el ) );
			}
		}
		window.requestAnimationFrame( step );
	}

	function init() {
		var secs = document.querySelectorAll( '.hms-stats' );
		if ( ! secs.length ) {
			return;
		}

		if ( ! still ) {
			var io = new IntersectionObserver( function ( entries ) {
				for ( var i = 0; i < entries.length; i++ ) {
					if ( ! entries[ i ].isIntersecting ) {
						continue;
					}
					io.unobserve( entries[ i ].target );
					var vs = vals( entries[ i ].target );
					for ( var j = 0; j < vs.length; j++ ) {
						count( vs[ j ] );
					}
				}
			}, { threshold: 0.3 } );
			for ( var s = 0; s < secs.length; s++ ) {
				var r = secs[ s ].getBoundingClientRect();
				if ( r.top < window.innerHeight && r.bottom > 0 ) {
					continue; // Already on screen: no jump back to 0.
				}
				var vs0 = vals( secs[ s ] );
				for ( var k = 0; k < vs0.length; k++ ) {
					show( vs0[ k ], 0 );
				}
				io.observe( secs[ s ] );
			}
		}

		if ( ! window.fetch || typeof cfg.url !== 'string' || ! cfg.url ) {
			return;
		}
		fetch( cfg.url, { credentials: 'omit', cache: 'no-store' } )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( d ) {
				if ( ! d || typeof d !== 'object' ) {
					return;
				}
				var all = document.querySelectorAll( '.hms-stats__val[data-hms-key]' );
				for ( var i = 0; i < all.length; i++ ) {
					var v = d[ all[ i ].getAttribute( 'data-hms-key' ) ];
					if ( typeof v !== 'number' || v < 0 || Math.floor( v ) !== v ) {
						continue;
					}
					all[ i ].setAttribute( 'data-hms-n', String( v ) );
					// A running count picks the new target up; a finished
					// or never started one (on screen, or still) shows it.
					if ( still || all[ i ].hmsDone || ! all[ i ].hmsRun && all[ i ].textContent !== fmt.format( 0 ) ) {
						show( all[ i ], v );
					}
				}
			} )
			[ 'catch' ]( function () {} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
