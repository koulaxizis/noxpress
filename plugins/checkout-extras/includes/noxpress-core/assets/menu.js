/**
 * Noxpress Core — menu.js (Bible §8, Core 1.5.0)
 *
 * 1. Every suite page becomes a command in WordPress's own command palette
 *    (Ctrl/Cmd+K), where WordPress loads it.
 * 2. A small "Go to…" dialog of ours: the tab bar button, and Ctrl/Cmd+K
 *    where WordPress has no palette.
 * 3. The hub's search box: filters the plugin table as you type.
 *
 * No dependencies, no network requests. Data: #nx-menu-config (JSON).
 */
( function () {
	'use strict';

	var node = document.getElementById( 'nx-menu-config' );
	var config = null;
	try {
		config = node ? JSON.parse( node.textContent ) : null;
	} catch ( e ) {
		config = null;
	}
	var items = config && Array.isArray( config.items ) ? config.items : [];
	var text = ( config && config.text ) || {};

	function fold( s ) {
		return String( s || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
	}

	function matches( item, q ) {
		var hay = fold( item.name + ' ' + item.page + ' ' + item.cat );
		return fold( q ).split( /\s+/ ).every( function ( w ) {
			return ! w || hay.indexOf( w ) !== -1;
		} );
	}

	/* 1. WordPress command palette. */
	function wpPalette() {
		var wp = window.wp;
		return !! ( wp && wp.commands && wp.commands.store && wp.data && wp.data.dispatch );
	}

	function registerCommands() {
		if ( ! wpPalette() || ! items.length ) {
			return;
		}
		var store = window.wp.data.dispatch( window.wp.commands.store );
		if ( ! store || typeof store.registerCommand !== 'function' ) {
			return;
		}
		items.forEach( function ( item ) {
			var label = item.name === item.page ? 'Noxpress: ' + item.name : 'Noxpress: ' + item.name + ' › ' + item.page;
			try {
				store.registerCommand( {
					name: 'noxpress/' + item.id,
					label: label,
					searchLabel: label + ' ' + item.cat,
					callback: function ( args ) {
						if ( args && typeof args.close === 'function' ) {
							args.close();
						}
						window.location.href = item.url;
					},
				} );
			} catch ( e ) {
				/* An older palette: our dialog still works. */
			}
		} );
	}

	/* 2. Our dialog. */
	var dialog = null;

	function el( tag, attrs, children ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				n.textContent = attrs[ k ];
			} else {
				n.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			n.appendChild( c );
		} );
		return n;
	}

	function render( list, q ) {
		list.textContent = '';
		var found = items.filter( function ( item ) {
			return matches( item, q );
		} ).slice( 0, 50 );
		if ( ! found.length ) {
			list.appendChild( el( 'li', { class: 'nx-go-empty', text: text.empty || '' } ) );
			return;
		}
		found.forEach( function ( item, i ) {
			var a = el( 'a', { href: item.url, class: 'nx-go-item' + ( i === 0 ? ' is-active' : '' ) }, [
				el( 'strong', { text: item.name } ),
				el( 'span', { text: item.name === item.page ? item.cat : item.page + ( item.cat ? ' · ' + item.cat : '' ) } ),
			] );
			list.appendChild( el( 'li', {}, [ a ] ) );
		} );
	}

	function move( list, step ) {
		var links = Array.prototype.slice.call( list.querySelectorAll( '.nx-go-item' ) );
		if ( ! links.length ) {
			return;
		}
		var at = links.findIndex( function ( a ) {
			return a.classList.contains( 'is-active' );
		} );
		links.forEach( function ( a ) {
			a.classList.remove( 'is-active' );
		} );
		var next = links[ ( at + step + links.length ) % links.length ];
		next.classList.add( 'is-active' );
		next.scrollIntoView( { block: 'nearest' } );
	}

	function openGo() {
		if ( ! items.length ) {
			return;
		}
		if ( ! dialog ) {
			var input = el( 'input', { type: 'search', class: 'nx-go-input', placeholder: text.placeholder || '', 'aria-label': text.title || '', autocomplete: 'off' } );
			var list = el( 'ul', { class: 'nx-go-list', role: 'listbox' } );
			var close = el( 'button', { type: 'button', class: 'nx-go-close', 'aria-label': text.close || '', text: '×' } );
			dialog = el( 'dialog', { class: 'nx-go', 'aria-label': text.title || '' }, [
				el( 'div', { class: 'nx-go-head' }, [ el( 'strong', { text: text.title || '' } ), close ] ),
				input,
				list,
			] );
			document.body.appendChild( dialog );
			input.addEventListener( 'input', function () {
				render( list, input.value );
			} );
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
					e.preventDefault();
					move( list, e.key === 'ArrowDown' ? 1 : -1 );
				} else if ( e.key === 'Enter' ) {
					var active = list.querySelector( '.nx-go-item.is-active' );
					if ( active ) {
						e.preventDefault();
						window.location.href = active.getAttribute( 'href' );
					}
				}
			} );
			close.addEventListener( 'click', function () {
				dialog.close();
			} );
			dialog.addEventListener( 'click', function ( e ) {
				if ( e.target === dialog ) {
					dialog.close();
				}
			} );
		}
		var field = dialog.querySelector( '.nx-go-input' );
		field.value = '';
		render( dialog.querySelector( '.nx-go-list' ), '' );
		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
		field.focus();
	}

	document.addEventListener( 'click', function ( e ) {
		var b = e.target && e.target.closest ? e.target.closest( '[data-nx-go]' ) : null;
		if ( b ) {
			e.preventDefault();
			openGo();
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && ! e.altKey && ! e.shiftKey && ( e.key === 'k' || e.key === 'K' ) && ! wpPalette() ) {
			e.preventDefault();
			openGo();
		}
	} );

	/* 3. Hub search. */
	function hubSearch() {
		var box = document.getElementById( 'nx-hub-search' );
		var table = document.querySelector( '.nx-table' );
		if ( ! box || ! table ) {
			return;
		}
		var empty = document.getElementById( 'nx-hub-empty' );
		box.addEventListener( 'input', function () {
			var q = fold( box.value );
			var words = q.split( /\s+/ ).filter( Boolean );
			var shown = 0;
			var head = null;
			var headShown = 0;
			Array.prototype.forEach.call( table.tBodies[ 0 ].rows, function ( tr ) {
				if ( tr.classList.contains( 'nx-cat' ) ) {
					if ( head ) {
						head.hidden = ! headShown;
					}
					head = tr;
					headShown = 0;
					return;
				}
				var hay = fold( tr.getAttribute( 'data-nx-search' ) );
				var ok = words.every( function ( w ) {
					return hay.indexOf( w ) !== -1;
				} );
				tr.hidden = ! ok;
				if ( ok ) {
					shown++;
					headShown++;
				}
			} );
			if ( head ) {
				head.hidden = ! headShown;
			}
			if ( empty ) {
				empty.hidden = shown > 0;
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', hubSearch );
	} else {
		hubSearch();
	}
	if ( document.readyState === 'complete' ) {
		registerCommands();
	} else {
		window.addEventListener( 'load', registerCommands );
	}
}() );
