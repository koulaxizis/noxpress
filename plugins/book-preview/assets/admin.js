/**
 * Book Preview — admin.js (v1.0.0)
 *
 *  - Product screen box: PDF from the media library, "Create sample" and
 *    "Delete" over AJAX (nonce + edit_post checked on the server).
 *  - Samples page: bulk creation in batches with a progress bar.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.BPV === 'undefined' ) {
		return;
	}
	var cfg = window.BPV;

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return s.replace( /%(\d+)\$d/g, function ( m, n ) {
			return String( args[ n - 1 ] );
		} );
	}

	/* ---------------- Product screen box ---------------- */

	var $box = $( '.bpv-box' );
	if ( $box.length ) {
		var frame = null;

		$box.on( 'click', '.bpv-media', function ( e ) {
			e.preventDefault();
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( {
					title: cfg.mediaTitle,
					button: { text: cfg.mediaButton },
					library: { type: 'application/pdf' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var key = 'a:' + parseInt( a.id, 10 );
					var $sel = $( '#bpv_source' );
					$sel.find( 'optgroup.bpv-media-group' ).remove();
					var $g = $( '<optgroup class="bpv-media-group"></optgroup>' ).attr( 'label', cfg.mediaGroup );
					$g.append( $( '<option></option>' ).val( key ).text( a.filename || a.title ) );
					$sel.find( 'option[value="' + key + '"]' ).remove();
					$sel.append( $g ).val( key ).trigger( 'change' );
				} );
			}
			frame.open();
		} );

		var send = function ( action, extra ) {
			var $spin = $box.find( '.spinner' );
			var $btns = $box.find( '.bpv-generate, .bpv-delete' );
			$btns.prop( 'disabled', true );
			$spin.addClass( 'is-active' );
			$box.find( '.bpv-box__msg' ).remove();
			return $.post( cfg.ajax, $.extend( {
				action: action,
				nonce: cfg.boxNonce,
				product: $box.data( 'product' )
			}, extra || {} ) ).done( function ( r ) {
				if ( r && r.success ) {
					$box.find( '.bpv-box__status' ).html( r.data.status );
					$box.find( '.bpv-delete' ).prop( 'hidden', ! r.data.has );
					$( '<p class="bpv-box__msg"></p>' )
						.addClass( r.data.ok ? '' : 'bpv-box-error' )
						.text( r.data.message )
						.prependTo( $box.find( '.bpv-box__status' ) );
				} else {
					$( '<p class="bpv-box__msg bpv-box-error"></p>' ).text( cfg.failed ).prependTo( $box.find( '.bpv-box__status' ) );
				}
			} ).fail( function () {
				$( '<p class="bpv-box__msg bpv-box-error"></p>' ).text( cfg.failed ).prependTo( $box.find( '.bpv-box__status' ) );
			} ).always( function () {
				$btns.prop( 'disabled', false );
				$spin.removeClass( 'is-active' );
			} );
		};

		$box.on( 'click', '.bpv-generate', function ( e ) {
			e.preventDefault();
			send( 'bpv_generate', {
				source: $( '#bpv_source' ).val() || '',
				pages: $( '#bpv_pages' ).val() || '',
				hidden: $box.find( 'input[name="bpv_hidden"]' ).is( ':checked' ) ? '1' : ''
			} );
		} );

		$box.on( 'click', '.bpv-delete', function ( e ) {
			e.preventDefault();
			if ( window.confirm( cfg.confirmDelete ) ) {
				send( 'bpv_delete' );
			}
		} );

		// Enter in the page field creates the sample instead of submitting the product form.
		$box.on( 'keydown', '#bpv_pages', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				$box.find( '.bpv-generate' ).trigger( 'click' );
			}
		} );
	}

	/* ---------------- Bulk creation ---------------- */

	var $start = $( '#bpv-bulk-start' );
	if ( $start.length ) {
		var $bar = $( '#bpv-progress' );
		var $text = $( '#bpv-progress-text' );

		var step = function () {
			$.post( cfg.ajax, { action: 'bpv_bulk_step', nonce: cfg.nonce } ).done( function ( r ) {
				if ( ! r || ! r.success ) {
					$text.text( cfg.stopped );
					$start.prop( 'disabled', false );
					return;
				}
				var d = r.data;
				$bar.find( '.bpv-progress__bar' ).css( 'width', ( d.total ? Math.round( d.done * 100 / d.total ) : 100 ) + '%' );
				$text.text( fmt( cfg.progress, d.done, d.total ) );
				if ( d.left > 0 ) {
					step();
				} else {
					window.location.reload();
				}
			} ).fail( function () {
				$text.text( cfg.stopped );
				$start.prop( 'disabled', false );
			} );
		};

		$start.on( 'click', function ( e ) {
			e.preventDefault();
			$start.prop( 'disabled', true );
			$text.text( cfg.working );
			$.post( cfg.ajax, {
				action: 'bpv_bulk_start',
				nonce: cfg.nonce,
				cat: $( '#bpv-bulk-cat' ).val() || 0,
				children: $( '#bpv-bulk-children' ).is( ':checked' ) ? '1' : '',
				n: $( '#bpv-bulk-n' ).val(),
				skip: $( '#bpv-bulk-skip' ).val(),
				mode: $( 'input[name="bpv_bulk_mode"]:checked' ).val()
			} ).done( function ( r ) {
				if ( ! r || ! r.success ) {
					$text.text( cfg.failed );
					$start.prop( 'disabled', false );
					return;
				}
				if ( ! r.data.total ) {
					$text.text( cfg.none );
					$start.prop( 'disabled', false );
					return;
				}
				$bar.prop( 'hidden', false );
				$text.text( fmt( cfg.progress, 0, r.data.total ) );
				step();
			} ).fail( function () {
				$text.text( cfg.failed );
				$start.prop( 'disabled', false );
			} );
		} );
	}
}( jQuery ) );
