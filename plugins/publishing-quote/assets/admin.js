/**
 * Publishing Quote — admin.js (v1.0.0). List editors of the settings:
 * add a row (from its <template>), remove, move up / down. The order of
 * the rows in the page is the order that is saved.
 */
( function () {
	'use strict';
	var n = 0;

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-pbq-act]' ) : null;
		if ( ! b ) {
			return;
		}
		var act = b.getAttribute( 'data-pbq-act' );
		var tr = b.closest( 'tr' );
		if ( act === 'add' ) {
			e.preventDefault();
			var list = document.getElementById( b.getAttribute( 'data-pbq-list' ) );
			var tpl = document.getElementById( b.getAttribute( 'data-pbq-list' ) + '-tpl' );
			if ( ! list || ! tpl ) {
				return;
			}
			var key = 'n' + Date.now().toString( 36 ) + ( n++ );
			var html = tpl.innerHTML.replace( /__KEY__/g, key );
			var tbody = list.querySelector( 'tbody' );
			tbody.insertAdjacentHTML( 'beforeend', html );
			var first = tbody.lastElementChild && tbody.lastElementChild.querySelector( 'input[type="text"]' );
			if ( first ) {
				first.focus();
			}
		} else if ( act === 'del' && tr ) {
			e.preventDefault();
			tr.parentNode.removeChild( tr );
		} else if ( act === 'up' && tr && tr.previousElementSibling ) {
			e.preventDefault();
			tr.parentNode.insertBefore( tr, tr.previousElementSibling );
		} else if ( act === 'down' && tr && tr.nextElementSibling ) {
			e.preventDefault();
			tr.parentNode.insertBefore( tr.nextElementSibling, tr );
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t && t.matches && t.matches( 'td.pbq-on input[type="checkbox"]' ) ) {
			var tr = t.closest( 'tr' );
			if ( tr ) {
				tr.classList.toggle( 'is-off', ! t.checked );
			}
		}
	} );
}() );
