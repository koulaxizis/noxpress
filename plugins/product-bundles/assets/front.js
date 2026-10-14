/**
 * Product Bundles — bundle page: the "Separately … · You save …" line and
 * the price of every choice follow the selects. Display only: the cart
 * computes the real prices on the server. Without JavaScript the line shows
 * the cheapest combination and the form works as it is.
 */
( function () {
	'use strict';

	function money( n, d ) {
		var fixed = Math.abs( n ).toFixed( d.dec );
		var parts = fixed.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, d.ts );
		var num = parts.join( d.ds );
		return d.fmt.replace( '%1$s', d.sym ).replace( '%2$s', num ).replace( /&nbsp;/g, ' ' );
	}

	function fill( tpl, args ) {
		var i = 0;
		return tpl.replace( /%(\d+)\$s|%s/g, function ( m, n ) {
			var k = n ? parseInt( n, 10 ) - 1 : i++;
			return args[ k ] !== undefined ? args[ k ] : m;
		} );
	}

	function init( root ) {
		var d;
		try {
			d = JSON.parse( root.getAttribute( 'data-pbn' ) || '{}' );
		} catch ( e ) {
			return;
		}
		var selects = root.querySelectorAll( 'select.pbn-choice' );
		var line = root.querySelector( '.pbn-savings' );
		if ( ! selects.length ) {
			return;
		}

		function update() {
			var value = d.fixed;
			var complete = true;
			for ( var i = 0; i < selects.length; i++ ) {
				var s = selects[ i ];
				var opt = s.options[ s.selectedIndex ];
				var price = root.querySelector( '[data-pbn-row="' + s.name.replace( /\D/g, '' ) + '"]' );
				if ( opt && opt.value && opt.getAttribute( 'data-d' ) !== null ) {
					var v = parseFloat( opt.getAttribute( 'data-d' ) ) || 0;
					value += v;
					if ( price ) {
						price.textContent = money( v, d );
					}
				} else {
					complete = false;
					if ( price ) {
						price.textContent = '';
					}
				}
			}
			if ( ! line ) {
				return;
			}
			if ( ! complete ) {
				value = d.min_v;
			}
			var bundle = 'price' === d.t ? d.min_p + Math.max( 0, value - d.min_v ) : value * ( 100 - d.d ) / 100;
			var save = Math.max( 0, value - bundle );
			var pct = value > 0 ? Math.round( save / value * 100 ) : 0;
			line.textContent = fill( d.save, [ money( value, d ), money( save, d ), pct + '%' ] );
		}

		for ( var i = 0; i < selects.length; i++ ) {
			selects[ i ].addEventListener( 'change', update );
		}
		update();
	}

	function boot() {
		var roots = document.querySelectorAll( '.pbn-root[data-pbn]' );
		for ( var i = 0; i < roots.length; i++ ) {
			try {
				init( roots[ i ] );
			} catch ( e ) {
				// Display only: the form works without it.
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
