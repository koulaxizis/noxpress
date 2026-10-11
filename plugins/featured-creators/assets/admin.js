/* Featured Creators — admin (creator edit screen + settings). */
( function ( $ ) {
	'use strict';
	var cfg = window.crtAdmin || {};

	/* ---------------------------------------------------------------
	 * Repeaters (links, press): add, delete, drag to reorder.
	 * Indexes only need to be unique; the server re-indexes.
	 * ------------------------------------------------------------- */
	var seq = Date.now();

	$( document ).on( 'click', '[data-crt-add]', function ( e ) {
		e.preventDefault();
		var name = $( this ).attr( 'data-crt-add' );
		var tpl  = $( 'template[data-crt-tpl="' + name + '"]' ).first();
		var box  = $( '[data-crt-rows="' + name + '"]' ).first();
		if ( ! tpl.length || ! box.length ) {
			return;
		}
		var html = tpl.html().replace( /__i__/g, 'n' + ( seq++ ) );
		var row  = $( $.parseHTML( html.trim() ) ).filter( '*' ).first();
		box.append( row );
		row.find( 'input,select' ).first().trigger( 'focus' );
	} );

	$( document ).on( 'click', '[data-crt-del]', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.crt-rowline' ).remove();
	} );

	function sortable( el, handle ) {
		if ( $.fn.sortable ) {
			$( el ).sortable( { handle: handle, axis: 'y', tolerance: 'pointer' } );
		}
	}
	$( '[data-crt-rows]' ).each( function () {
		sortable( this, '.crt-handle' );
	} );
	$( '[data-crt-sortable]' ).each( function () {
		sortable( this, '.crt-handle' );
	} );

	/* Press rows: the image picker only for videos. */
	$( document ).on( 'change', '[data-crt-type]', function () {
		$( this ).closest( '[data-crt-press]' ).find( '[data-crt-video]' ).prop( 'hidden', 'video' !== this.value );
	} );

	$( document ).on( 'click', '[data-crt-pick]', function ( e ) {
		e.preventDefault();
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var wrap  = $( this ).closest( '[data-crt-video]' );
		var frame = wp.media( {
			title: cfg.pick || '',
			button: { text: cfg.use || '' },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var a   = frame.state().get( 'selection' ).first().toJSON();
			var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
			wrap.find( '[data-crt-img]' ).val( a.id );
			wrap.find( '[data-crt-imgview]' ).attr( 'src', url ).prop( 'hidden', false );
		} );
		frame.open();
	} );

	/* Clicking the thumbnail removes it. */
	$( document ).on( 'click', '[data-crt-imgview]', function () {
		var wrap = $( this ).closest( '[data-crt-video]' );
		wrap.find( '[data-crt-img]' ).val( '0' );
		$( this ).attr( 'src', '' ).prop( 'hidden', true );
	} );

	/* ---------------------------------------------------------------
	 * Extra terms: chips + search.
	 * ------------------------------------------------------------- */
	$( document ).on( 'click', '[data-crt-chip-del]', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.crt-chip' ).remove();
	} );

	var timer = null;
	var last  = '';
	var req   = null;

	function chipExists( tt ) {
		return $( '[data-crt-chips] input[name="crt_extra[]"][value="' + tt + '"]' ).length > 0;
	}

	function addChip( tt, label ) {
		if ( chipExists( tt ) ) {
			return;
		}
		var chip = $( '<span class="crt-chip"></span>' );
		chip.append( $( '<input type="hidden" name="crt_extra[]">' ).val( tt ) );
		chip.append( document.createTextNode( label + ' ' ) );
		chip.append( $( '<a href="#" data-crt-chip-del>×</a>' ).attr( 'title', cfg.remove || '' ) );
		$( '[data-crt-chips]' ).append( chip );
	}

	function render( list, items ) {
		list.empty();
		if ( ! items.length ) {
			list.append( $( '<div class="crt-ac-empty"></div>' ).text( cfg.noResults || '' ) );
		}
		items.forEach( function ( it ) {
			var b = $( '<button type="button" class="crt-ac-item"></button>' ).text( it.label ).attr( 'data-tt', it.tt );
			if ( chipExists( it.tt ) ) {
				b.prop( 'disabled', true );
			}
			list.append( b );
		} );
		list.prop( 'hidden', false );
	}

	$( document ).on( 'input', '[data-crt-ac]', function () {
		var input = $( this );
		var list  = input.siblings( '[data-crt-ac-list]' );
		var q     = input.val().trim();
		clearTimeout( timer );
		if ( q.length < 3 ) {
			list.prop( 'hidden', true ).empty();
			last = '';
			return;
		}
		timer = setTimeout( function () {
			if ( q === last ) {
				return;
			}
			last = q;
			if ( req ) {
				req.abort();
			}
			req = $.get( cfg.ajax, { action: 'crt_terms', nonce: cfg.nonce, q: q } ).done( function ( r ) {
				render( list, r && r.success && Array.isArray( r.data ) ? r.data : [] );
			} );
		}, 250 );
	} );

	$( document ).on( 'click', '.crt-ac-item', function () {
		addChip( String( $( this ).attr( 'data-tt' ) ), $( this ).text() );
		$( this ).prop( 'disabled', true );
	} );

	$( document ).on( 'keydown', '[data-crt-ac]', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault(); // Never submit the post form from the search box.
		} else if ( 'Escape' === e.key ) {
			$( this ).siblings( '[data-crt-ac-list]' ).prop( 'hidden', true );
		}
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.crt-ac' ).length ) {
			$( '[data-crt-ac-list]' ).prop( 'hidden', true );
		}
	} );

	/* ---------------------------------------------------------------
	 * Import: batches over AJAX with a progress bar.
	 * ------------------------------------------------------------- */
	$( document ).on( 'change', '[data-crt-all]', function () {
		$( this ).closest( '[data-crt-import]' ).find( '[data-crt-slug]' ).prop( 'checked', this.checked );
	} );

	$( document ).on( 'click', '[data-crt-run]', function () {
		var zone  = $( this ).closest( '[data-crt-import]' );
		var btn   = $( this );
		var bar   = zone.find( '[data-crt-bar]' );
		var msg   = zone.find( '[data-crt-msg]' );
		var slugs = zone.find( '[data-crt-slug]:checked' ).map( function () {
			return $( this ).attr( 'data-crt-slug' );
		} ).get();
		if ( ! slugs.length ) {
			msg.text( cfg.none || '' );
			return;
		}
		var size    = parseInt( cfg.batch, 10 ) || 20;
		var total   = slugs.length;
		var created = 0;
		var skipped = 0;
		btn.prop( 'disabled', true );
		bar.prop( 'hidden', false ).find( 'span' ).css( 'width', '0%' );
		msg.text( '' );

		function next() {
			if ( ! slugs.length ) {
				bar.find( 'span' ).css( 'width', '100%' );
				msg.text( String( cfg.done || '' ).replace( '%1$d', created ).replace( '%2$d', skipped ) );
				setTimeout( function () {
					window.location.reload();
				}, 1200 );
				return;
			}
			var chunk = slugs.splice( 0, size );
			$.post( cfg.ajax, { action: 'crt_import', nonce: cfg.nonceImp, slugs: chunk } )
				.done( function ( r ) {
					if ( ! r || ! r.success ) {
						fail();
						return;
					}
					created += parseInt( r.data.created, 10 ) || 0;
					skipped += parseInt( r.data.skipped, 10 ) || 0;
					bar.find( 'span' ).css( 'width', Math.round( 100 * ( total - slugs.length ) / total ) + '%' );
					next();
				} )
				.fail( fail );
		}
		function fail() {
			btn.prop( 'disabled', false );
			msg.text( cfg.error || '' );
		}
		next();
	} );
} )( jQuery );
