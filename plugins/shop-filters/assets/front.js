/**
 * Shop Filters — front.js (v1.0.0). No dependencies.
 *
 * Everything works without this file: options are links, the price is a
 * GET form, subcategories of the current branch are open. This file adds:
 *  - toggle buttons for subcategories;
 *  - immediate visual feedback when an option is clicked;
 *  - the "Apply" mode (data-shf-apply = button, or auto on narrow screens):
 *    several options are chosen, then one URL is loaded;
 *  - the two-handle price slider; in instant mode the price applies when a
 *    handle is released, and a range equal to the bounds is left out of
 *    the URL.
 */
( function () {
	'use strict';

	var ANCHOR = '#shf-results';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function narrow() {
		return !! ( window.matchMedia && window.matchMedia( '(max-width: 767px)' ).matches );
	}

	function init( root ) {
		var mode = root.getAttribute( 'data-shf-apply' ) || 'auto';
		var batch = mode === 'button' || ( mode === 'auto' && narrow() );
		var applyLabel = root.getAttribute( 'data-shf-apply-label' ) || 'Apply';
		var toggleLabel = root.getAttribute( 'data-shf-toggle-label' ) || '';
		var priceForm = root.querySelector( 'form.shf-price' );

		if ( ! batch ) {
			root.className += ' shf-js-instant';
		}

		// Subcategory toggles.
		var togs = root.querySelectorAll( '.shf-tog' );
		for ( var i = 0; i < togs.length; i++ ) {
			togs[ i ].removeAttribute( 'hidden' );
			if ( toggleLabel ) {
				togs[ i ].setAttribute( 'aria-label', toggleLabel );
			}
			togs[ i ].addEventListener( 'click', function ( e ) {
				var item = e.currentTarget.closest( '.shf-item' );
				if ( ! item ) {
					return;
				}
				var open = item.classList.toggle( 'is-open' );
				e.currentTarget.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		}

		// Options.
		root.addEventListener( 'click', function ( e ) {
			var a = e.target.closest( 'a.shf-opt[data-shf-attr]' );
			if ( ! a || ! root.contains( a ) || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button ) {
				return;
			}
			var on = a.classList.toggle( 'is-on' );
			a.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			if ( batch ) {
				e.preventDefault();
				dirty();
			}
		} );
		root.addEventListener( 'keydown', function ( e ) {
			var a = e.target.closest ? e.target.closest( 'a.shf-opt[role="checkbox"]' ) : null;
			if ( a && ( e.key === ' ' || e.key === 'Spacebar' ) ) {
				e.preventDefault();
				a.click();
			}
		} );

		// "Apply" bar.
		var bar = null;
		function dirty() {
			if ( ! bar ) {
				bar = document.createElement( 'div' );
				bar.className = 'shf-applybar';
				var btn = document.createElement( 'button' );
				btn.type = 'button';
				btn.className = 'shf-btn button';
				btn.textContent = applyLabel;
				btn.addEventListener( 'click', function ( ev ) {
					ev.preventDefault();
					window.location.href = buildUrl();
				} );
				bar.appendChild( btn );
				root.appendChild( bar );
			}
			root.classList.add( 'is-dirty' );
		}

		function buildUrl() {
			var url = new URL( root.getAttribute( 'data-shf-base' ) || window.location.href, window.location.href );
			var byAttr = {};
			var opts = root.querySelectorAll( 'a.shf-opt[data-shf-attr]' );
			for ( var j = 0; j < opts.length; j++ ) {
				var attr = opts[ j ].getAttribute( 'data-shf-attr' );
				byAttr[ attr ] = byAttr[ attr ] || [];
				if ( opts[ j ].classList.contains( 'is-on' ) ) {
					byAttr[ attr ].push( opts[ j ].getAttribute( 'data-shf-token' ) );
				}
			}
			Object.keys( byAttr ).forEach( function ( attr ) {
				if ( byAttr[ attr ].length ) {
					url.searchParams.set( 'shf_' + attr, byAttr[ attr ].join( ',' ) );
				} else {
					url.searchParams.delete( 'shf_' + attr );
				}
			} );
			if ( priceForm ) {
				var p = priceValues();
				[ [ 'min_price', p.min, p.lo ], [ 'max_price', p.max, p.hi ] ].forEach( function ( x ) {
					if ( x[ 1 ] === x[ 2 ] ) {
						url.searchParams.delete( x[ 0 ] );
					} else {
						url.searchParams.set( x[ 0 ], String( x[ 1 ] ) );
					}
				} );
			}
			url.searchParams.delete( 'paged' );
			url.hash = '';
			// URLSearchParams encodes the token separator; keep links readable
			// (?shf_age=0-6m,1-3y), the same as the links printed by PHP.
			return url.toString().replace( /%2C/gi, ',' ) + ANCHOR;
		}

		// Price slider.
		var rMin, rMax, nMin, nMax, fill;
		function priceValues() {
			var lo = parseFloat( priceForm.getAttribute( 'data-shf-min' ) );
			var hi = parseFloat( priceForm.getAttribute( 'data-shf-max' ) );
			var a = parseFloat( nMin.value );
			var b = parseFloat( nMax.value );
			a = isNaN( a ) ? lo : Math.max( lo, Math.min( hi, a ) );
			b = isNaN( b ) ? hi : Math.max( lo, Math.min( hi, b ) );
			if ( a > b ) {
				var t = a;
				a = b;
				b = t;
			}
			return { min: a, max: b, lo: lo, hi: hi };
		}
		function paint() {
			var p = priceValues();
			var span = p.hi - p.lo || 1;
			fill.style.left = ( ( p.min - p.lo ) / span * 100 ) + '%';
			fill.style.right = ( ( p.hi - p.max ) / span * 100 ) + '%';
		}
		function prepare() {
			if ( ! nMin || ! nMax ) {
				return;
			}
			var p = priceValues();
			nMin.value = p.min;
			nMax.value = p.max;
			// A range equal to the bounds is no filter: keep it out of the URL.
			nMin.disabled = p.min === p.lo;
			nMax.disabled = p.max === p.hi;
		}
		function priceChanged() {
			if ( batch ) {
				dirty();
			} else {
				prepare();
				priceForm.submit();
			}
		}
		if ( priceForm ) {
			var range = priceForm.querySelector( '.shf-range' );
			rMin = priceForm.querySelector( '.shf-range-min' );
			rMax = priceForm.querySelector( '.shf-range-max' );
			nMin = priceForm.querySelector( '.shf-num-min' );
			nMax = priceForm.querySelector( '.shf-num-max' );
			if ( range && rMin && rMax && nMin && nMax ) {
				fill = document.createElement( 'span' );
				fill.className = 'shf-range-fill';
				range.insertBefore( fill, range.firstChild );
				range.removeAttribute( 'hidden' );
				rMin.addEventListener( 'input', function () {
					if ( parseFloat( rMin.value ) > parseFloat( rMax.value ) ) {
						rMin.value = rMax.value;
					}
					nMin.value = rMin.value;
					paint();
				} );
				rMax.addEventListener( 'input', function () {
					if ( parseFloat( rMax.value ) < parseFloat( rMin.value ) ) {
						rMax.value = rMin.value;
					}
					nMax.value = rMax.value;
					paint();
				} );
				[ rMin, rMax, nMin, nMax ].forEach( function ( el ) {
					el.addEventListener( 'change', function () {
						if ( el === nMin ) {
							rMin.value = nMin.value;
						} else if ( el === nMax ) {
							rMax.value = nMax.value;
						}
						paint();
						priceChanged();
					} );
				} );
				paint();
			}
			priceForm.addEventListener( 'submit', function ( e ) {
				if ( batch ) {
					e.preventDefault();
					window.location.href = buildUrl();
					return;
				}
				prepare();
			} );
		}
	}

	ready( function () {
		var roots = document.querySelectorAll( '.shf-root' );
		for ( var i = 0; i < roots.length; i++ ) {
			try {
				init( roots[ i ] );
			} catch ( err ) {
				// Without the script everything still works: links and forms.
			}
		}
	} );
}() );
