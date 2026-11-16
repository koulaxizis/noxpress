/**
 * Mail Relay — admin.js (v1.0.0). No dependencies.
 *  - Forms with data-mrl-confirm ask before they are sent.
 *  - Connection forms show only the fields of the chosen provider, and the
 *    SMTP host / port / encryption only for «Άλλος SMTP server».
 * Without JavaScript every field stays visible and the server ignores the
 * ones that do not belong to the provider.
 */
( function () {
	'use strict';

	document.querySelectorAll( 'form[data-mrl-confirm]' ).forEach( function ( f ) {
		f.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( f.getAttribute( 'data-mrl-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );

	document.querySelectorAll( 'form[data-mrl-conn]' ).forEach( function ( f ) {
		var type   = f.querySelector( '[data-mrl-type]' );
		var preset = f.querySelector( '[data-mrl-preset]' );
		var label  = f.querySelector( '[data-mrl-secret-label]' );
		if ( ! type ) {
			return;
		}
		function sync() {
			var t = type.value;
			f.querySelectorAll( '[data-mrl-for]' ).forEach( function ( el ) {
				var show = el.getAttribute( 'data-mrl-for' ) === t;
				if ( show && el.hasAttribute( 'data-mrl-custom' ) && preset ) {
					show = 'custom' === preset.value;
				}
				el.hidden = ! show;
			} );
			if ( label ) {
				label.textContent = 'smtp' === t ? label.getAttribute( 'data-smtp' ) : ( 'ses' === t ? label.getAttribute( 'data-ses' ) : label.getAttribute( 'data-api' ) );
			}
		}
		type.addEventListener( 'change', sync );
		if ( preset ) {
			preset.addEventListener( 'change', sync );
		}
		sync();
	} );
}() );
