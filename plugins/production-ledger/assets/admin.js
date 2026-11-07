/**
 * Production Ledger — admin.js (v1.0.0)
 *
 * Progressive enhancement only: every form works without it.
 *  - Overview: month / year switch, table search, column sort.
 *  - Entry form: product / shared mode, categories and pools that fit,
 *    product look-up (AJAX, from the 3rd letter or an ID), VAT from rate.
 *  - Forms with data-confirm ask first.
 */
( function () {
	'use strict';

	var $ = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var $$ = function ( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); };
	var wrap = $( '.pl-wrap' );
	if ( ! wrap ) {
		return;
	}

	/* ----- Period: month or year ----- */
	var kind = $( '.pl-period-kind', wrap );
	if ( kind ) {
		var period = function () {
			var year = 'year' === kind.value;
			$$( '.pl-when-month', wrap ).forEach( function ( el ) { el.classList.toggle( 'is-hidden', year ); el.disabled = year; } );
			$$( '.pl-when-year', wrap ).forEach( function ( el ) { el.classList.toggle( 'is-hidden', ! year ); el.disabled = ! year; } );
		};
		kind.addEventListener( 'change', period );
		period();
	}

	/* ----- Table search ----- */
	$$( '.pl-search[data-table]', wrap ).forEach( function ( input ) {
		var table = document.getElementById( input.getAttribute( 'data-table' ) );
		if ( ! table ) {
			return;
		}
		input.addEventListener( 'input', function () {
			var q = input.value.trim().toLowerCase();
			$$( 'tbody tr', table ).forEach( function ( tr ) {
				tr.classList.toggle( 'is-hidden', '' !== q && -1 === tr.textContent.toLowerCase().indexOf( q ) );
			} );
		} );
	} );

	/* ----- Column sort ----- */
	$$( '.pl-sortable', wrap ).forEach( function ( table ) {
		var heads = $$( 'thead th', table );
		heads.forEach( function ( th, col ) {
			var type = th.getAttribute( 'data-sort' );
			if ( ! type ) {
				return;
			}
			th.setAttribute( 'tabindex', '0' );
			th.setAttribute( 'role', 'button' );
			var sort = function () {
				var dir = 'asc' === th.getAttribute( 'data-dir' ) ? 'desc' : ( 'desc' === th.getAttribute( 'data-dir' ) ? 'asc' : ( 'num' === type ? 'desc' : 'asc' ) );
				heads.forEach( function ( h ) { h.removeAttribute( 'data-dir' ); h.removeAttribute( 'aria-sort' ); } );
				th.setAttribute( 'data-dir', dir );
				th.setAttribute( 'aria-sort', 'asc' === dir ? 'ascending' : 'descending' );
				var body = table.tBodies[ 0 ];
				var rows = Array.prototype.slice.call( body.rows );
				var val = function ( tr ) {
					var td = tr.cells[ col ];
					var v = td ? ( td.getAttribute( 'data-v' ) || td.textContent ) : '';
					return 'num' === type ? ( parseFloat( v ) || 0 ) : v.toLowerCase();
				};
				rows.sort( function ( a, b ) {
					var x = val( a ), y = val( b );
					var r = 'num' === type ? x - y : x.localeCompare( y, 'el' );
					return 'asc' === dir ? r : -r;
				} );
				rows.forEach( function ( tr ) { body.appendChild( tr ); } );
			};
			th.addEventListener( 'click', sort );
			th.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key || ' ' === e.key ) {
					e.preventDefault();
					sort();
				}
			} );
		} );
	} );

	/* ----- Entry form ----- */
	var form = $( '.pl-entry-form', wrap );
	if ( form ) {
		var cat = $( '.pl-category', form );
		var pool = $( 'select[name="pl_pool"]', form );
		var modeOf = function () {
			var r = $( 'input[name="pl_mode"]:checked', form ) || $( 'input[name="pl_mode"]', form );
			return r ? r.value : 'product';
		};
		var show = function ( sel, on ) {
			$$( sel, form ).forEach( function ( el ) {
				el.classList.toggle( 'is-hidden', ! on );
				$$( 'input, select', el ).forEach( function ( f ) { f.disabled = ! on; } );
			} );
		};
		var firstVisible = function ( select ) {
			var opts = Array.prototype.slice.call( select.options );
			var cur = select.options[ select.selectedIndex ];
			if ( cur && ! cur.hidden ) {
				return;
			}
			for ( var i = 0; i < opts.length; i++ ) {
				if ( ! opts[ i ].hidden ) {
					select.value = opts[ i ].value;
					return;
				}
			}
		};
		var pools = function () {
			if ( ! pool || ! cat ) {
				return;
			}
			$$( 'option', pool ).forEach( function ( o ) {
				var on = o.getAttribute( 'data-cat' ) === cat.value;
				o.hidden = ! on;
				o.disabled = ! on;
			} );
			firstVisible( pool );
		};
		var mode = function () {
			var m = modeOf();
			if ( 'general' === m ) {
				return;
			}
			show( '.pl-only-product', 'product' === m );
			show( '.pl-only-pool', 'pool' === m );
			if ( cat ) {
				$$( 'option', cat ).forEach( function ( o ) {
					var on = 'pool' !== m || '1' === o.getAttribute( 'data-pool' );
					o.hidden = ! on;
					o.disabled = ! on;
				} );
				firstVisible( cat );
			}
			pools();
		};
		$$( 'input[name="pl_mode"]', form ).forEach( function ( r ) { r.addEventListener( 'change', mode ); } );
		if ( cat ) {
			cat.addEventListener( 'change', pools );
		}
		mode();

		/* Product look-up. */
		var search = $( '.pl-product-search', form );
		var list = document.getElementById( 'pl-product-list' );
		if ( search && list && window.plAdmin ) {
			var timer = null;
			var last = '';
			search.addEventListener( 'input', function () {
				var q = search.value.trim();
				if ( /^\d+ — /.test( q ) || q === last || ( ! /^\d+$/.test( q ) && q.length < 3 ) ) {
					return;
				}
				clearTimeout( timer );
				timer = setTimeout( function () {
					last = q;
					var url = window.plAdmin.ajax + '?action=pl_products&nonce=' + encodeURIComponent( window.plAdmin.nonce ) + '&q=' + encodeURIComponent( q );
					fetch( url, { credentials: 'same-origin' } )
						.then( function ( r ) { return r.ok ? r.json() : null; } )
						.then( function ( j ) {
							if ( ! j || ! j.success || ! Array.isArray( j.data ) ) {
								return;
							}
							list.textContent = '';
							j.data.forEach( function ( label ) {
								var o = document.createElement( 'option' );
								o.value = label;
								list.appendChild( o );
							} );
						} )
						.catch( function () {} );
				}, 250 );
			} );
		}

		/* VAT from the rate, until the VAT field is typed in. */
		var net = $( '.pl-net', form );
		var rate = $( '.pl-rate', form );
		var vat = $( '.pl-vat', form );
		if ( net && rate && vat ) {
			var touched = '' !== vat.value.trim();
			var num = function ( s ) {
				s = String( s ).trim().replace( /\s/g, '' );
				if ( s.indexOf( ',' ) > -1 ) {
					s = s.replace( /\./g, '' ).replace( ',', '.' );
				}
				return parseFloat( s );
			};
			var calc = function () {
				if ( touched ) {
					return;
				}
				var n = num( net.value ), r = num( rate.value );
				vat.placeholder = ( isFinite( n ) && isFinite( r ) ) ? ( Math.round( n * r ) / 100 ).toFixed( 2 ).replace( '.', ',' ) : '';
			};
			vat.addEventListener( 'input', function () { touched = '' !== vat.value.trim(); calc(); } );
			net.addEventListener( 'input', calc );
			rate.addEventListener( 'input', calc );
			calc();
		}
	}

	/* ----- Confirm ----- */
	$$( 'form[data-confirm]', wrap ).forEach( function ( f ) {
		f.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( f.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );
}() );
