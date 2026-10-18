/* Pre-Orders: product editor and product list helpers (admin only). */
( function ( $ ) {
	'use strict';

	var cfg = window.porProduct || {};

	/* «Sale until release»: WooCommerce's own sale schedule, from today to the day before the release. */
	$( document ).on( 'click', '.por-sale', function ( e ) {
		e.preventDefault();
		var date = $( '#por_date' ).val() || '';
		if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
			window.alert( cfg.needDate || '' );
			return;
		}
		var d = new Date( date + 'T12:00:00' );
		d.setDate( d.getDate() - 1 );
		var to = d.getFullYear() + '-' + ( '0' + ( d.getMonth() + 1 ) ).slice( -2 ) + '-' + ( '0' + d.getDate() ).slice( -2 );
		$( '.sale_schedule' ).first().trigger( 'click' );
		$( '.sale_price_dates_fields' ).first().show();
		$( '#_sale_price_dates_from' ).val( cfg.today || '' ).trigger( 'change' );
		$( '#_sale_price_dates_to' ).val( to ).trigger( 'change' );
		if ( '' === $.trim( $( '#_sale_price' ).val() || '' ) ) {
			window.alert( cfg.needPrice || '' );
			$( '#_sale_price' ).trigger( 'focus' );
		}
	} );

	/* Quick edit: fill our fields from the hidden data of the list row. */
	if ( window.inlineEditPost && window.inlineEditPost.edit ) {
		var orig = window.inlineEditPost.edit;
		window.inlineEditPost.edit = function ( id ) {
			orig.apply( this, arguments );
			var postId = 'object' === typeof id ? parseInt( this.getId( id ), 10 ) : parseInt( id, 10 );
			if ( ! postId ) {
				return;
			}
			var $data = $( '#post-' + postId ).find( '.por-qe' );
			var $edit = $( '#edit-' + postId );
			var simple = $( '#woocommerce_inline_' + postId ).find( '.product_type' ).text() === 'simple';
			$edit.find( '.por-quick' ).toggle( simple );
			$edit.find( 'input[name="por_on"]' ).prop( 'checked', 'yes' === $data.data( 'on' ) );
			$edit.find( 'input[name="por_date"]' ).val( $data.data( 'date' ) || '' );
		};
	}
} )( jQuery );
