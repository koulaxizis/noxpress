/**
 * Forms — admin.js (v1.0.0). The builder: add a field (from its
 * <template>), remove, move up / down, and show only the settings of the
 * chosen field type. The order of the cards in the page is the order that
 * is saved.
 */
( function () {
	'use strict';
	var n = 0;

	function sync( card ) {
		var type = card.querySelector( '.frm-type' );
		if ( ! type ) {
			return;
		}
		var t = type.value;
		card.setAttribute( 'data-type', t );
		var rows = card.querySelectorAll( '[data-for]' );
		for ( var i = 0; i < rows.length; i++ ) {
			var list = ' ' + rows[ i ].getAttribute( 'data-for' ) + ' ';
			rows[ i ].hidden = list.indexOf( ' ' + t + ' ' ) < 0;
		}
		var hint = card.querySelector( '.frm-hint-consent' );
		if ( hint ) {
			hint.hidden = t !== 'consent';
		}
	}

	function title( card ) {
		var el = card.querySelector( '.frm-label-el' );
		var out = card.querySelector( '.frm-card-title' );
		if ( el && out ) {
			out.textContent = el.value;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-frm-act]' ) : null;
		if ( ! b ) {
			return;
		}
		var act = b.getAttribute( 'data-frm-act' );
		var card = b.closest( '.frm-card' );
		if ( act === 'add' ) {
			e.preventDefault();
			var list = document.getElementById( 'frm-fields' );
			var tpl = document.getElementById( 'frm-field-tpl' );
			if ( ! list || ! tpl ) {
				return;
			}
			var key = 'n' + Date.now().toString( 36 ) + ( n++ );
			list.insertAdjacentHTML( 'beforeend', tpl.innerHTML.replace( /__I__/g, key ) );
			var added = list.lastElementChild;
			if ( added ) {
				var id = added.querySelector( 'input[name$="[id]"]' );
				if ( id ) {
					id.value = 'f' + Math.random().toString( 36 ).slice( 2, 9 );
				}
				sync( added );
				var sel = added.querySelector( '.frm-type' );
				if ( sel ) {
					sel.focus();
				}
			}
		} else if ( act === 'del' && card ) {
			e.preventDefault();
			card.parentNode.removeChild( card );
		} else if ( act === 'up' && card && card.previousElementSibling ) {
			e.preventDefault();
			card.parentNode.insertBefore( card, card.previousElementSibling );
			b.focus();
		} else if ( act === 'down' && card && card.nextElementSibling ) {
			e.preventDefault();
			card.parentNode.insertBefore( card.nextElementSibling, card );
			b.focus();
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t && t.classList && t.classList.contains( 'frm-type' ) ) {
			var card = t.closest( '.frm-card' );
			if ( card ) {
				sync( card );
			}
		}
	} );

	document.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( t && t.classList && t.classList.contains( 'frm-label-el' ) ) {
			var card = t.closest( '.frm-card' );
			if ( card ) {
				title( card );
			}
		}
	} );

	var cards = document.querySelectorAll( '#frm-fields .frm-card' );
	for ( var i = 0; i < cards.length; i++ ) {
		sync( cards[ i ] );
	}
}() );
