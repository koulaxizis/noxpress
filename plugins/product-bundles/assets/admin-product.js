/**
 * Product Bundles — product screen: type-specific fields, contents rows
 * (add, remove, reorder) and the live summary.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.pbnProduct || {};
	var type = cfg.type || 'nox_bundle';
	var timer = null;

	// Fields WooCommerce shows by type: keep SKU and "Sold individually",
	// hide the stock status (it comes from the contents).
	$( '.inventory_sold_individually, ._sold_individually_field' ).addClass( 'show_if_' + type );
	$( '.stock_status_field' ).addClass( 'hide_if_' + type );
	$( '._manage_stock_field, .stock_fields' ).addClass( 'hide_if_' + type );

	function isBundle() {
		return type === $( 'select#product-type' ).val();
	}

	function bundleId() {
		return $( '#post_ID' ).val() || 0;
	}

	function reindex() {
		$( '#pbn_bundle_data .pbn-items .pbn-row' ).each( function ( i ) {
			$( this ).find( '[name^="pbn_rows["]' ).each( function () {
				this.name = this.name.replace( /^pbn_rows\[\d+\]/, 'pbn_rows[' + i + ']' );
			} );
		} );
		var empty = 0 === $( '#pbn_bundle_data .pbn-items .pbn-row' ).length;
		$( '#pbn_bundle_data .pbn-empty' ).prop( 'hidden', ! empty );
	}

	function summary() {
		if ( ! isBundle() ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( function () {
			var data = $( '#pbn_bundle_data' ).find( 'input, select' ).not( '#pbn-add' ).serializeArray();
			data.push( { name: 'action', value: 'pbn_admin_summary' } );
			data.push( { name: 'nonce', value: cfg.nonce } );
			data.push( { name: 'bundle', value: bundleId() } );
			$.post( cfg.ajax, data ).done( function ( r ) {
				if ( r && r.success && r.data ) {
					$( '#pbn_bundle_data .pbn-summary' ).html( r.data.html );
				}
			} );
		}, 400 );
	}

	$( function () {
		var $panel = $( '#pbn_bundle_data' );
		if ( ! $panel.length ) {
			return;
		}

		$( 'select#product-type' ).trigger( 'change' );

		$panel.find( '.pbn-items' ).sortable( {
			items: '.pbn-row',
			handle: '.pbn-row__handle',
			placeholder: 'pbn-row-ghost',
			axis: 'y',
			update: function () {
				reindex();
				summary();
			}
		} );

		$panel.on( 'click', '.pbn-add-row', function () {
			var $sel = $( '#pbn-add' );
			var pid = $sel.val();
			var $items = $panel.find( '.pbn-items' );
			if ( ! pid ) {
				return;
			}
			if ( $items.find( '.pbn-row' ).length >= parseInt( $items.data( 'max' ), 10 ) ) {
				window.alert( cfg.i18n.max );
				return;
			}
			var $btn = $( this ).prop( 'disabled', true );
			$.post( cfg.ajax, {
				action: 'pbn_admin_row',
				nonce: cfg.nonce,
				product: pid,
				bundle: bundleId(),
				index: $items.find( '.pbn-row' ).length
			} ).done( function ( r ) {
				if ( r && r.success ) {
					$items.append( r.data.html );
					$sel.val( null ).trigger( 'change' );
					reindex();
					summary();
				} else {
					window.alert( r && r.data && r.data.message ? r.data.message : cfg.i18n.error );
				}
			} ).fail( function ( x ) {
				var m = x && x.responseJSON && x.responseJSON.data && x.responseJSON.data.message;
				window.alert( m || cfg.i18n.error );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$panel.on( 'click', '.pbn-row__remove', function () {
			$( this ).closest( '.pbn-row' ).remove();
			reindex();
			summary();
		} );

		$panel.on( 'change input', '.pbn-row input, input[name="pbn_dtype"], #pbn_disc, #pbn_max', summary );
		$( 'select#product-type' ).on( 'change', summary );
	} );
}( jQuery ) );
