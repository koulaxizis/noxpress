/**
 * Discount Rules admin: the rule form (type boxes, scope, live example),
 * the delete confirmation and the WooCommerce product/category selects.
 * Plain jQuery, no build step.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.dsrAdmin || {};

	function parse( v ) {
		v = String( v || '' ).trim().replace( /\s/g, '' );
		if ( cfg.dec && cfg.dec !== '.' ) {
			v = v.split( '.' ).join( '' ).replace( cfg.dec, '.' );
		} else {
			v = v.replace( ',', '.' );
		}
		var n = parseFloat( v );
		return isFinite( n ) ? n : 0;
	}

	function money( n ) {
		var d = parseInt( cfg.decimals, 10 );
		d = isFinite( d ) ? d : 2;
		var s = Math.max( 0, n ).toFixed( d );
		return cfg.dec && cfg.dec !== '.' ? s.replace( '.', cfg.dec ) : s;
	}

	function after( price, amount, kind ) {
		if ( amount <= 0 ) {
			return price;
		}
		return 'fixed' === kind ? price - amount : price * ( 1 - Math.min( 100, amount ) / 100 );
	}

	function type( $form ) {
		return $form.find( '.dsr-type:checked' ).val() || 'sale';
	}

	function boxes( $form ) {
		var t = type( $form );
		$form.find( '.dsr-type-box' ).each( function () {
			var types = String( $( this ).data( 'types' ) || '' ).split( ' ' );
			$( this ).toggle( types.indexOf( t ) !== -1 );
		} );
	}

	function scope( $form ) {
		$form.find( '.dsr-scope' ).toggle( ! $form.find( '.dsr-all' ).is( ':checked' ) );
	}

	function example( $form ) {
		var $out = $form.find( '.dsr-example' );
		if ( ! $out.length ) {
			return;
		}
		var t = type( $form );
		var base = 10;
		var parts = [];
		if ( 'sale' === t || 'cart' === t ) {
			var $box = $form.find( '.dsr-type-box[data-types="sale cart"]' );
			var a = parse( $box.find( '.dsr-amount' ).val() );
			if ( a > 0 ) {
				parts.push( ( 'cart' === t ? cfg.cart + ' ' : '' ) + money( base ) + ' → ' + money( after( base, a, $box.find( '.dsr-kind' ).val() ) ) );
			}
		} else if ( 'tiers' === t ) {
			$form.find( '.dsr-tiers-edit tbody tr' ).each( function () {
				var min = parseInt( $( this ).find( '.dsr-tmin' ).val(), 10 );
				var a = parse( $( this ).find( '.dsr-tamount' ).val() );
				if ( min >= 2 && a > 0 ) {
					parts.push( min + '+ ' + cfg.items + ': ' + money( after( base, a, $( this ).find( '.dsr-kind' ).val() ) ) + ' ' + cfg.each );
				}
			} );
		} else if ( 'xy' === t ) {
			var buy = parseInt( $form.find( '.dsr-buy' ).val(), 10 );
			var pay = parseInt( $form.find( '.dsr-paynum' ).val(), 10 );
			if ( buy >= 2 && pay >= 1 && pay < buy ) {
				parts.push( buy + ' ' + cfg.items + ': ' + cfg.pay + ' ' + money( base * pay ) );
			}
		}
		$out.text( parts.length ? ( cfg.sample || '' ) + parts.join( ' · ' ) : '' );
	}

	$( function () {
		$( 'form' ).has( '.dsr-type' ).each( function () {
			var $form = $( this );
			var all = function () {
				boxes( $form );
				scope( $form );
				example( $form );
			};
			$form.on( 'change input', 'input, select', all );
			all();
		} );

		$( document ).on( 'click', '.dsr-delete', function ( e ) {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( cfg.confirm || '' ) ) {
				e.preventDefault();
			}
		} );

		$( document.body ).trigger( 'wc-enhanced-select-init' );
	} );
}( jQuery ) );
