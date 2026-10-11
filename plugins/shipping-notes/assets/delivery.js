/**
 * Shipping Notes — delivery.js (v1.0.0)
 *
 * Delivery dates and the cut-off countdown, computed at view time in the
 * store's time zone, so a cached product page never shows an old date.
 * The same rules as SHN_Calendar (PHP):
 *  - dispatch days: the store's working days that are not holidays; an
 *    order after the cut-off or on a day off starts on the next one;
 *  - delivery days: Monday–Friday (and Saturday for methods that deliver
 *    then) that are not holidays;
 *  - date = start + preparation dispatch days + transit delivery days.
 * Without JavaScript the server text (business days) stays as it is.
 */
( function () {
	'use strict';

	var DAY = 86400000;
	var HORIZON = 400;

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	/** Store-local "now": { date: UTC midnight Date, hm: 'HH:MM', min: minutes since midnight }. */
	function now( cfg ) {
		var y, m, d, h, i;
		if ( cfg.tz && window.Intl && Intl.DateTimeFormat ) {
			try {
				var parts = new Intl.DateTimeFormat( 'en-US', {
					timeZone: cfg.tz, year: 'numeric', month: 'numeric', day: 'numeric',
					hour: 'numeric', minute: 'numeric', hourCycle: 'h23'
				} ).formatToParts( new Date() );
				var p = {};
				for ( var k = 0; k < parts.length; k++ ) {
					p[ parts[ k ].type ] = parseInt( parts[ k ].value, 10 );
				}
				y = p.year; m = p.month; d = p.day; h = p.hour % 24; i = p.minute;
			} catch ( e ) {
				y = null;
			}
		}
		if ( ! y ) {
			var t = new Date( Date.now() + ( cfg.off || 0 ) * 60000 );
			y = t.getUTCFullYear(); m = t.getUTCMonth() + 1; d = t.getUTCDate(); h = t.getUTCHours(); i = t.getUTCMinutes();
		}
		return { date: new Date( Date.UTC( y, m - 1, d ) ), hm: pad( h ) + ':' + pad( i ), min: h * 60 + i };
	}

	function iso( d ) {
		var n = d.getUTCDay();
		return 0 === n ? 7 : n;
	}

	function holiday( d, hol ) {
		var md = pad( d.getUTCMonth() + 1 ) + '-' + pad( d.getUTCDate() );
		return hol.indexOf( md ) !== -1 || hol.indexOf( d.getUTCFullYear() + '-' + md ) !== -1;
	}

	function dispatchDay( d, cfg ) {
		return cfg.wd.indexOf( iso( d ) ) !== -1 && ! holiday( d, cfg.hol );
	}

	function deliveryDay( d, sat, cfg ) {
		var n = iso( d );
		return ( n <= 5 || ( 6 === n && sat ) ) && ! holiday( d, cfg.hol );
	}

	function next( d ) {
		return new Date( d.getTime() + DAY );
	}

	function start( cfg, n ) {
		var d = n.date;
		if ( cfg.cut && n.hm >= cfg.cut ) {
			d = next( d );
		}
		for ( var i = 0; i < HORIZON; i++ ) {
			if ( dispatchDay( d, cfg ) ) {
				return d;
			}
			d = next( d );
		}
		return null;
	}

	function add( d, count, test ) {
		for ( var i = 0; count > 0 && i < HORIZON; i++ ) {
			d = next( d );
			if ( test( d ) ) {
				count--;
			}
		}
		return count > 0 ? null : d;
	}

	/** [ min, max ] dates of a line [ pmin, pmax, tmin, tmax, sat ]. */
	function windowOf( s, line, cfg ) {
		var out = [];
		for ( var k = 0; k < 2; k++ ) {
			var ship = add( s, line[ k ], function ( d ) { return dispatchDay( d, cfg ); } );
			var at = ship ? add( ship, line[ 2 + k ], function ( d ) { return deliveryDay( d, !! line[ 4 ], cfg ); } ) : null;
			if ( ! at ) {
				return null;
			}
			out.push( at );
		}
		return out;
	}

	function fmt( cfg ) {
		var loc = 'en' === cfg.lang ? 'en-GB' : 'el-GR';
		try {
			var f = new Intl.DateTimeFormat( loc, { weekday: 'short', day: 'numeric', month: 'numeric', timeZone: 'UTC' } );
			return function ( d ) { return f.format( d ); };
		} catch ( e ) {
			return function ( d ) { return d.getUTCDate() + '/' + ( d.getUTCMonth() + 1 ); };
		}
	}

	function span( w, f ) {
		var a = f( w[ 0 ] );
		var b = f( w[ 1 ] );
		return a === b ? a : a + ' – ' + b;
	}

	function render( box ) {
		var cfg;
		try {
			cfg = JSON.parse( box.getAttribute( 'data-shn-dl' ) );
		} catch ( e ) {
			return;
		}
		if ( ! cfg || ! cfg.lines ) {
			return;
		}
		var n = now( cfg );
		var s = start( cfg, n );

		if ( cfg.dates && s ) {
			var f = fmt( cfg );
			var lines = box.querySelectorAll( '.shn-dl__line' );
			for ( var i = 0; i < lines.length; i++ ) {
				var idx = parseInt( lines[ i ].getAttribute( 'data-i' ), 10 );
				var w = null;
				var tpl = '';
				if ( idx < 0 ) {
					for ( var j = 0; j < cfg.lines.length; j++ ) {
						var lw = windowOf( s, cfg.lines[ j ], cfg );
						if ( ! lw ) {
							w = null;
							break;
						}
						w = w ? [ lw[ 0 ] < w[ 0 ] ? lw[ 0 ] : w[ 0 ], lw[ 1 ] > w[ 1 ] ? lw[ 1 ] : w[ 1 ] ] : lw;
					}
					tpl = cfg.tpl[ 0 ];
				} else if ( cfg.lines[ idx ] ) {
					w = windowOf( s, cfg.lines[ idx ], cfg );
					tpl = cfg.tpl[ idx ];
				}
				if ( w && tpl ) {
					lines[ i ].textContent = tpl.split( '{dates}' ).join( span( w, f ) );
				}
			}
		}

		var cut = box.querySelector( '.shn-dl__cutoff' );
		if ( cut ) {
			var parts = cfg.cut ? cfg.cut.split( ':' ) : null;
			var left = parts ? parseInt( parts[ 0 ], 10 ) * 60 + parseInt( parts[ 1 ], 10 ) - n.min : -1;
			if ( cfg.cutTpl && left > 0 && dispatchDay( n.date, cfg ) ) {
				cut.textContent = cfg.cutTpl
					.split( '{hours}' ).join( String( Math.floor( left / 60 ) ) )
					.split( '{minutes}' ).join( String( left % 60 ) )
					.split( '{cutoff}' ).join( cfg.cut );
				cut.hidden = false;
			} else {
				cut.hidden = true;
			}
		}
	}

	function all() {
		var boxes = document.querySelectorAll( '.shn-dl[data-shn-dl]' );
		for ( var i = 0; i < boxes.length; i++ ) {
			render( boxes[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', all );
	} else {
		all();
	}
	setInterval( all, 60000 );
}() );
