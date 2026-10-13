/**
 * Variation Picker — front.js (v1.0.0)
 *
 * Product page: the swatches drive WooCommerce's own <select> (value +
 * "change"), so prices, images, stock and add to cart stay WooCommerce's.
 * Which values are possible comes from WooCommerce's variation list
 * (data-product_variations) or, for products over the AJAX threshold, from
 * the plugin's availability map (data-vpk-map). Re-synced on every
 * WooCommerce variation event; idempotent per form (also for quick views
 * that initialise a form later: "wc_variation_form").
 *
 * Shop cards: hover / focus swaps the card image; a swatch inside the card
 * link opens the product with that value chosen.
 *
 * jQuery only because WooCommerce's variation events are jQuery events.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.vpkCfg || {};
	// wp_localize_script() turns scalars into strings ("0" is truthy).
	[ 'label', 'auto', 'deselect', 'url', 'diff', 'swap' ].forEach( function ( k ) {
		cfg[ k ] = true === cfg[ k ] || '1' === cfg[ k ] || 1 === cfg[ k ];
	} );
	cfg.low = parseInt( cfg.low, 10 ) || 0;
	var T = cfg.t || {};
	var tip = null;

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	function decode( s ) {
		var d = document.createElement( 'textarea' );
		d.innerHTML = String( s );
		return d.value;
	}

	function price( n ) {
		var p = cfg.price || {};
		var dec = parseInt( p.dec, 10 ) || 0;
		var parts = Math.abs( n ).toFixed( dec ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, p.ts || '' );
		var num = parts.join( p.ds || '.' );
		var fmt = p.format || '%1$s%2$s';
		return fmt.replace( '%1$s', p.symbol || '' ).replace( '%2$s', num ).replace( /&nbsp;/g, ' ' );
	}

	/* ------------------------------------------------------------------
	 * Variation data: [{ a: {attribute_x: value}, in: bool, price, qty }]
	 * ---------------------------------------------------------------- */

	function fromWc( list ) {
		var out = [];
		for ( var i = 0; i < list.length; i++ ) {
			var v = list[ i ], a = {};
			for ( var k in v.attributes ) {
				if ( Object.prototype.hasOwnProperty.call( v.attributes, k ) ) {
					a[ k ] = v.attributes[ k ] ? decode( v.attributes[ k ] ) : '';
				}
			}
			var q = v.max_qty;
			out.push( {
				a: a,
				in: !! v.is_in_stock && false !== v.variation_is_active,
				price: parseFloat( v.display_price ),
				qty: ( '' === q || null === q || undefined === q ) ? null : parseInt( q, 10 )
			} );
		}
		return out;
	}

	function fromMap( map ) {
		var out = [], n = map.a.length;
		for ( var i = 0; i < map.v.length; i++ ) {
			var row = map.v[ i ], a = {};
			for ( var j = 0; j < n; j++ ) {
				a[ map.a[ j ] ] = String( row[ j ] );
			}
			out.push( {
				a: a,
				in: !! row[ n ],
				price: parseFloat( row[ n + 1 ] ),
				qty: row[ n + 2 ] < 0 ? null : parseInt( row[ n + 2 ], 10 )
			} );
		}
		return out;
	}

	/* ------------------------------------------------------------------
	 * One variations form
	 * ---------------------------------------------------------------- */

	function Picker( $form ) {
		this.$form = $form;
		this.form = $form[ 0 ];
		this.busy = false;
		this.wraps = [];
		var self = this;

		$form.find( '.vpk-wrap[data-vpk-attr]' ).each( function () {
			var sel = this.querySelector( 'select' );
			var list = this.querySelector( '.vpk-list' );
			if ( ! sel || ! list ) {
				return;
			}
			this.classList.add( 'vpk-js' );
			self.wraps.push( { el: this, sel: sel, list: list, attr: this.getAttribute( 'data-vpk-attr' ) } );
		} );
		if ( ! this.wraps.length ) {
			return;
		}

		this.data = this.loadData();

		$form.on( 'click', '.vpk-wrap .vpk-sw', function ( e ) {
			e.preventDefault();
			self.onClick( this );
		} );
		$form.on( 'keydown', '.vpk-wrap .vpk-list', function ( e ) {
			self.onKey( e );
		} );
		$form.on( 'woocommerce_update_variation_values reset_data found_variation woocommerce_variation_has_changed', function () {
			self.refresh();
		} );
		this.refresh();
	}

	Picker.prototype.loadData = function () {
		var wc = this.$form.data( 'product_variations' );
		if ( wc && wc.length ) {
			return fromWc( wc );
		}
		for ( var i = 0; i < this.wraps.length; i++ ) {
			var raw = this.wraps[ i ].el.getAttribute( 'data-vpk-map' );
			if ( raw ) {
				try {
					var map = JSON.parse( raw );
					if ( map && map.a && map.v ) {
						return fromMap( map );
					}
				} catch ( err ) {
					return null;
				}
			}
		}
		return null;
	};

	/** Current choice of every attribute select of the form. */
	Picker.prototype.chosen = function () {
		var out = {};
		this.$form.find( '.variations select' ).each( function () {
			var name = $( this ).data( 'attribute_name' ) || this.name;
			out[ name ] = this.value || '';
		} );
		return out;
	};

	/** Variations compatible with the choice, with attr = value (other attrs as chosen). */
	Picker.prototype.matches = function ( attr, value, chosen, ignoreAttr ) {
		var out = [];
		if ( ! this.data ) {
			return out;
		}
		for ( var i = 0; i < this.data.length; i++ ) {
			var v = this.data[ i ], ok = true;
			for ( var k in chosen ) {
				if ( ! Object.prototype.hasOwnProperty.call( chosen, k ) || k === attr || ! chosen[ k ] ) {
					continue;
				}
				var have = v.a[ k ];
				if ( have && have !== chosen[ k ] ) {
					ok = false;
					break;
				}
			}
			if ( ok && ! ignoreAttr && attr ) {
				var mine = v.a[ attr ];
				if ( mine && mine !== value ) {
					ok = false;
				}
			}
			if ( ok ) {
				out.push( v );
			}
		}
		return out;
	};

	/** 'ok' | 'oos' | 'unavail' for one value. */
	Picker.prototype.state = function ( attr, value, chosen ) {
		if ( ! this.data ) {
			return 'ok';
		}
		var m = this.matches( attr, value, chosen );
		if ( ! m.length ) {
			return 'unavail';
		}
		for ( var i = 0; i < m.length; i++ ) {
			if ( m[ i ].in ) {
				return 'ok';
			}
		}
		return 'oos';
	};

	Picker.prototype.refresh = function () {
		if ( this.busy ) {
			return;
		}
		var chosen = this.chosen();
		var autoPick = null;

		for ( var w = 0; w < this.wraps.length; w++ ) {
			var wr = this.wraps[ w ], cur = wr.sel.value || '';
			var btns = wr.list.querySelectorAll( '.vpk-sw' );
			var focusable = null, okCount = 0, okValue = null;

			for ( var b = 0; b < btns.length; b++ ) {
				var btn = btns[ b ], val = btn.getAttribute( 'data-value' );
				var st = this.state( wr.attr, val, chosen );
				var mode = 'oos' === st ? cfg.oos : cfg.unavail;
				btn.classList.remove( 'vpk-is-unavail', 'vpk-is-oos', 'vpk-mode-blur', 'vpk-mode-cross', 'vpk-mode-hide' );
				// Still clickable (smart selection), so no aria-disabled: the
				// state goes into the accessible name instead.
				var note = 'ok' === st ? '' : ( 'oos' === st ? T.oos : T.unavail );
				btn.setAttribute( 'aria-label', btn.getAttribute( 'data-name' ) + ( note ? ', ' + note : '' ) );
				if ( 'ok' !== st ) {
					btn.classList.add( 'oos' === st ? 'vpk-is-oos' : 'vpk-is-unavail', 'vpk-mode-' + ( mode || 'blur' ) );
				} else {
					okCount++;
					okValue = val;
				}
				btn.setAttribute( 'data-vpk-state', st );
				var on = '' !== cur && val === cur;
				btn.classList.toggle( 'vpk-is-on', on );
				btn.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				btn.tabIndex = -1;
				if ( on ) {
					focusable = btn;
				}
			}
			if ( ! focusable ) {
				for ( b = 0; b < btns.length; b++ ) {
					if ( ! btns[ b ].classList.contains( 'vpk-mode-hide' ) ) {
						focusable = btns[ b ];
						break;
					}
				}
			}
			if ( focusable ) {
				focusable.tabIndex = 0;
			}

			this.label( wr, cur );

			if ( cfg.auto && '' === cur && 1 === okCount && null === autoPick ) {
				autoPick = { wr: wr, value: okValue };
			}
		}

		if ( autoPick ) {
			this.choose( autoPick.wr, autoPick.value, false );
			return;
		}
		this.syncUrl( chosen );
	};

	/** "Colour: Black" next to the attribute name. */
	Picker.prototype.label = function ( wr, cur ) {
		if ( ! cfg.label || ! wr.sel.id ) {
			return;
		}
		var lab = this.form.querySelector( 'label[for="' + ( window.CSS && CSS.escape ? CSS.escape( wr.sel.id ) : wr.sel.id ) + '"]' );
		if ( ! lab ) {
			return;
		}
		var span = lab.querySelector( '.vpk-current' );
		var name = '';
		if ( cur ) {
			var btn = wr.list.querySelector( '.vpk-sw.vpk-is-on' );
			name = btn ? btn.getAttribute( 'data-name' ) : '';
		}
		if ( ! name ) {
			if ( span ) {
				span.textContent = '';
			}
			return;
		}
		if ( ! span ) {
			span = document.createElement( 'span' );
			span.className = 'vpk-current';
			lab.appendChild( span );
		}
		span.textContent = ': ' + name;
	};

	/**
	 * Sets a select to a value and lets WooCommerce react. With clearOthers,
	 * the other attributes are emptied first (a value that does not fit
	 * the current choice).
	 */
	Picker.prototype.choose = function ( wr, value, clearOthers ) {
		var sel = wr.sel;
		this.busy = true;
		if ( clearOthers ) {
			this.$form.find( '.variations select' ).not( sel ).val( '' );
		}
		if ( value && ! $( sel ).find( 'option' ).filter( function () { return this.value === value; } ).length ) {
			// WooCommerce removed the option (not attached to the current choice).
			var opt = document.createElement( 'option' );
			opt.value = value;
			opt.textContent = value;
			sel.appendChild( opt );
		}
		$( sel ).val( value );
		this.busy = false;
		$( sel ).trigger( 'change' );
		this.refresh();
	};

	Picker.prototype.wrapOf = function ( btn ) {
		for ( var i = 0; i < this.wraps.length; i++ ) {
			if ( this.wraps[ i ].el.contains( btn ) ) {
				return this.wraps[ i ];
			}
		}
		return null;
	};

	Picker.prototype.onClick = function ( btn ) {
		var wr = this.wrapOf( btn );
		if ( ! wr ) {
			return;
		}
		hideTip();
		var val = btn.getAttribute( 'data-value' );
		var st = btn.getAttribute( 'data-vpk-state' ) || 'ok';
		if ( 'unavail' === st ) {
			// Does the value exist at all (other choices cleared)?
			var alone = {};
			alone[ wr.attr ] = val;
			if ( ! this.matches( wr.attr, val, alone ).length ) {
				return;
			}
			this.choose( wr, val, true );
			return;
		}
		if ( btn.classList.contains( 'vpk-is-on' ) ) {
			if ( cfg.deselect ) {
				this.choose( wr, '', false );
			}
			return;
		}
		this.choose( wr, val, false );
	};

	/** Arrow keys move and choose within a radiogroup (roving tabindex). */
	Picker.prototype.onKey = function ( e ) {
		var keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1, Home: 0, End: 0 };
		if ( ! Object.prototype.hasOwnProperty.call( keys, e.key ) ) {
			return;
		}
		var list = e.currentTarget;
		var btns = Array.prototype.filter.call( list.querySelectorAll( '.vpk-sw' ), function ( b ) {
			return ! b.classList.contains( 'vpk-mode-hide' ) && 'unavail' !== b.getAttribute( 'data-vpk-state' );
		} );
		if ( ! btns.length ) {
			return;
		}
		e.preventDefault();
		var idx = btns.indexOf( document.activeElement );
		var next;
		if ( 'Home' === e.key ) {
			next = 0;
		} else if ( 'End' === e.key ) {
			next = btns.length - 1;
		} else {
			next = ( idx + keys[ e.key ] + btns.length ) % btns.length;
		}
		var btn = btns[ next ];
		btn.focus();
		var wr = this.wrapOf( btn );
		if ( wr && ! btn.classList.contains( 'vpk-is-on' ) ) {
			this.choose( wr, btn.getAttribute( 'data-value' ), false );
			btn.focus();
		}
	};

	/** The chosen values in the page URL (shareable link), main product form only. */
	Picker.prototype.syncUrl = function ( chosen ) {
		if ( ! cfg.url || ! window.history || ! window.history.replaceState || ! window.URL ) {
			return;
		}
		if ( ! document.body.classList.contains( 'single-product' ) || this.$form.closest( '.related, .upsells, .cross-sells, [role="dialog"], .modal' ).length ) {
			return;
		}
		if ( $( '.variations_form' ).get( 0 ) !== this.form ) {
			return;
		}
		var url;
		try {
			url = new URL( window.location.href );
		} catch ( err ) {
			return;
		}
		var keys = [];
		url.searchParams.forEach( function ( v, k ) {
			if ( 0 === k.indexOf( 'attribute_' ) ) {
				keys.push( k );
			}
		} );
		keys.forEach( function ( k ) {
			url.searchParams.delete( k );
		} );
		for ( var k in chosen ) {
			if ( Object.prototype.hasOwnProperty.call( chosen, k ) && chosen[ k ] ) {
				var v = chosen[ k ];
				// WooCommerce preselects from the request value: a term slug is
				// stored percent-encoded (non-Latin), so it is decoded first.
				if ( 0 === k.indexOf( 'attribute_pa_' ) ) {
					try {
						v = decodeURIComponent( v );
					} catch ( err ) {
						v = chosen[ k ];
					}
				}
				url.searchParams.set( k, v );
			}
		}
		if ( url.href !== window.location.href ) {
			window.history.replaceState( window.history.state, '', url.href );
		}
	};

	/** Extra tooltip lines for a product-page swatch: state, price difference, low stock. */
	Picker.prototype.extra = function ( btn ) {
		var wr = this.wrapOf( btn );
		if ( ! wr || ! this.data ) {
			return [];
		}
		var out = [];
		var st = btn.getAttribute( 'data-vpk-state' ) || 'ok';
		if ( 'unavail' === st && T.unavail ) {
			return [ T.unavail ];
		}
		if ( 'oos' === st && T.oos ) {
			return [ T.oos ];
		}
		var val = btn.getAttribute( 'data-value' );
		var chosen = this.chosen();
		var mine = this.matches( wr.attr, val, chosen ).filter( function ( v ) { return v.in; } );

		if ( cfg.diff && mine.length ) {
			var base = this.matches( wr.attr, '', chosen, true ).filter( function ( v ) { return v.in; } );
			var min = function ( list ) {
				var m = Infinity;
				for ( var i = 0; i < list.length; i++ ) {
					if ( ! isNaN( list[ i ].price ) && list[ i ].price < m ) {
						m = list[ i ].price;
					}
				}
				return m;
			};
			var d = min( mine ) - min( base );
			if ( isFinite( d ) && Math.abs( d ) > 0.004 ) {
				out.push( ( d > 0 ? '+' : '−' ) + price( d ) );
			}
		}
		if ( cfg.low > 0 && 1 === mine.length && null !== mine[ 0 ].qty && mine[ 0 ].qty > 0 && mine[ 0 ].qty <= cfg.low && T.low ) {
			out.push( T.low.replace( '%d', String( mine[ 0 ].qty ) ) );
		}
		return out;
	};

	/* ------------------------------------------------------------------
	 * Tooltip (one element for the page)
	 * ---------------------------------------------------------------- */

	function showTip( el ) {
		if ( ! cfg.tip || 'none' === cfg.tip ) {
			return;
		}
		var name = el.getAttribute( 'data-vpk-tip' );
		if ( ! name ) {
			return;
		}
		if ( ! tip ) {
			tip = document.createElement( 'div' );
			tip.className = 'vpk-tip';
			tip.setAttribute( 'role', 'tooltip' );
			tip.hidden = true;
			document.body.appendChild( tip );
		}
		tip.textContent = '';
		var img = el.getAttribute( 'data-vpk-img' );
		if ( img && 'image' === cfg.tip ) {
			var i = document.createElement( 'img' );
			i.src = img;
			i.alt = '';
			tip.appendChild( i );
		}
		var s = document.createElement( 'span' );
		s.textContent = name;
		tip.appendChild( s );

		var $form = $( el ).closest( '.variations_form' );
		var picker = $form.length ? $form.data( 'vpkPicker' ) : null;
		var extra = picker ? picker.extra( el ) : ( el.classList.contains( 'vpk-is-oos' ) && T.oos ? [ T.oos ] : [] );
		for ( var x = 0; x < extra.length; x++ ) {
			var e = document.createElement( 'span' );
			e.className = 'vpk-tip__extra';
			e.textContent = extra[ x ];
			tip.appendChild( e );
		}

		tip.hidden = false;
		var r = el.getBoundingClientRect();
		var tw = tip.offsetWidth, th = tip.offsetHeight;
		var left = r.left + window.pageXOffset + r.width / 2 - tw / 2;
		left = Math.max( window.pageXOffset + 4, Math.min( left, window.pageXOffset + document.documentElement.clientWidth - tw - 4 ) );
		var top = r.top + window.pageYOffset - th - 8;
		if ( r.top - th - 8 < 0 ) {
			top = r.bottom + window.pageYOffset + 8;
		}
		tip.style.left = left + 'px';
		tip.style.top = top + 'px';
	}

	function hideTip() {
		if ( tip ) {
			tip.hidden = true;
		}
	}

	/* ------------------------------------------------------------------
	 * Size guide dialog
	 * ---------------------------------------------------------------- */

	function openGuide( btn ) {
		var d = document.getElementById( btn.getAttribute( 'data-vpk-guide' ) );
		if ( ! d ) {
			return;
		}
		if ( d.parentNode !== document.body ) {
			document.body.appendChild( d );
		}
		d._vpkReturn = btn;
		if ( ! d._vpkBound ) {
			// "close" does not bubble: bound on the dialog itself.
			d._vpkBound = true;
			d.addEventListener( 'close', function () {
				if ( d._vpkReturn ) {
					d._vpkReturn.focus();
				}
			} );
		}
		if ( 'function' === typeof d.showModal ) {
			d.showModal();
		} else {
			d.classList.add( 'vpk-dialog--fallback' );
			d.setAttribute( 'open', '' );
		}
		var close = d.querySelector( '[data-vpk-close]' );
		if ( close ) {
			close.focus();
		}
	}

	function closeGuide( d ) {
		if ( 'function' === typeof d.close && d.open ) {
			d.close();
		} else {
			d.removeAttribute( 'open' );
		}
	}

	/* ------------------------------------------------------------------
	 * Shop cards
	 * ---------------------------------------------------------------- */

	function cardImage( sw ) {
		var card = sw.closest( 'li.product, .product, .wc-block-grid__product' );
		if ( ! card ) {
			return null;
		}
		var imgs = card.querySelectorAll( 'img' );
		for ( var i = 0; i < imgs.length; i++ ) {
			if ( ! imgs[ i ].closest( '.vpk-root' ) ) {
				return imgs[ i ];
			}
		}
		return null;
	}

	function swap( sw ) {
		var src = sw.getAttribute( 'data-vpk-src' );
		var img = src ? cardImage( sw ) : null;
		if ( ! img ) {
			return;
		}
		if ( undefined === img._vpkSrc ) {
			img._vpkSrc = img.getAttribute( 'src' );
			img._vpkSet = img.getAttribute( 'srcset' );
		}
		img.setAttribute( 'src', src );
		var set = sw.getAttribute( 'data-vpk-srcset' );
		if ( set ) {
			img.setAttribute( 'srcset', set );
		} else {
			img.removeAttribute( 'srcset' );
		}
	}

	function restore( loop ) {
		var any = loop.querySelector( '.vpk-sw[data-vpk-src]' );
		var img = any ? cardImage( any ) : null;
		if ( ! img || undefined === img._vpkSrc ) {
			return;
		}
		img.setAttribute( 'src', img._vpkSrc );
		if ( img._vpkSet ) {
			img.setAttribute( 'srcset', img._vpkSet );
		} else {
			img.removeAttribute( 'srcset' );
		}
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * ---------------------------------------------------------------- */

	function init( form ) {
		var $form = $( form );
		if ( $form.data( 'vpkPicker' ) ) {
			return;
		}
		$form.data( 'vpkPicker', new Picker( $form ) );
	}

	$( document ).on( 'wc_variation_form', '.variations_form', function () {
		init( this );
	} );
	$( function () {
		$( '.variations_form' ).each( function () {
			init( this );
		} );
	} );

	$( document ).on( 'mouseenter focusin', '.vpk-root [data-vpk-tip]', function () {
		showTip( this );
	} );
	$( document ).on( 'mouseleave focusout', '.vpk-root [data-vpk-tip]', hideTip );
	$( window ).on( 'scroll resize', hideTip );

	$( document ).on( 'click', '.vpk-guide-btn', function ( e ) {
		e.preventDefault();
		openGuide( this );
	} );
	$( document ).on( 'click', '.vpk-dialog', function ( e ) {
		var d = this;
		if ( e.target === d || $( e.target ).closest( '[data-vpk-close]' ).length ) {
			closeGuide( d );
		}
	} );
	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			$( '.vpk-dialog.vpk-dialog--fallback[open]' ).each( function () {
				closeGuide( this );
				if ( this._vpkReturn ) {
					this._vpkReturn.focus();
				}
			} );
		}
	} );

	// Shop cards: a swatch inside the card link opens the product with that value.
	$( document ).on( 'click', '.vpk-loop [data-vpk-href]', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		window.location.href = this.getAttribute( 'data-vpk-href' );
	} );
	if ( cfg.swap ) {
		$( document ).on( 'mouseenter focusin', '.vpk-loop .vpk-sw[data-vpk-src]', function () {
			swap( this );
		} );
		$( document ).on( 'mouseleave', '.vpk-loop', function () {
			restore( this );
		} );
		$( document ).on( 'focusout', '.vpk-loop', function ( e ) {
			if ( ! this.contains( e.relatedTarget ) ) {
				restore( this );
			}
		} );
	}
}( jQuery ) );
