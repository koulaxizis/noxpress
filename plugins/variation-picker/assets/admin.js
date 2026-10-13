/**
 * Variation Picker — admin.js (v1.0.0)
 *
 * General tab: the live preview follows the Appearance fields (shape,
 * size, font size, border width and colours) before saving. It sets the
 * same custom properties the front end gets from the settings.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'vpk-preview' );
	if ( ! root ) {
		return;
	}

	function val( id ) {
		var el = document.getElementById( id );
		return el ? el.value : '';
	}

	function num( id, min, max, def ) {
		var n = parseInt( val( id ), 10 );
		if ( isNaN( n ) ) {
			return def;
		}
		return Math.min( max, Math.max( min, n ) );
	}

	function update() {
		root.style.setProperty( '--vpk-size', num( 'vpk_size', 20, 80, 32 ) + 'px' );
		root.style.setProperty( '--vpk-font', num( 'vpk_font', 10, 20, 13 ) + 'px' );
		root.style.setProperty( '--vpk-bw', num( 'vpk_border', 0, 4, 1 ) + 'px' );
		var bc = val( 'vpk_border_color' );
		var ac = val( 'vpk_active_color' );
		if ( /^#[0-9a-f]{6}$/i.test( bc ) ) {
			root.style.setProperty( '--vpk-bc', bc );
		}
		if ( /^#[0-9a-f]{6}$/i.test( ac ) ) {
			root.style.setProperty( '--vpk-ac', ac );
		}
		var shape = val( 'vpk_shape' ) || 'rounded';
		var lists = root.querySelectorAll( '.vpk-list' );
		for ( var i = 0; i < lists.length; i++ ) {
			lists[ i ].className = lists[ i ].className.replace( /\bvpk-shape--[a-z]+\b/g, '' ).trim() + ' vpk-shape--' + shape;
		}
	}

	[ 'vpk_shape', 'vpk_size', 'vpk_font', 'vpk_border', 'vpk_border_color', 'vpk_active_color' ].forEach( function ( id ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.addEventListener( 'input', update );
			el.addEventListener( 'change', update );
		}
	} );
	update();
}() );
