/**
 * Variation Picker — terms.js (v1.0.0)
 *
 * Admin helpers for the value looks: WordPress's colour picker on
 * .vpk-color inputs (when wp-color-picker is loaded) and the media
 * library picker for .vpk-image-field (term screens, product tab, size
 * guides). After a value is added on the term list screen (AJAX), the
 * fields are cleared like WordPress clears its own.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.vpkTerms || {};

	function colors( ctx ) {
		if ( ! $.fn.wpColorPicker ) {
			return;
		}
		$( '.vpk-color', ctx ).each( function () {
			var $i = $( this );
			if ( ! $i.closest( '.wp-picker-container' ).length ) {
				$i.wpColorPicker();
			}
		} );
	}

	function setImage( $field, id, url ) {
		$field.find( '.vpk-image-id' ).val( id || '' );
		var $img = $field.find( '.vpk-image-preview' );
		if ( id && url ) {
			$img.attr( 'src', url ).prop( 'hidden', false );
			$field.find( '.vpk-image-clear' ).prop( 'hidden', false );
		} else {
			$img.attr( 'src', '' ).prop( 'hidden', true );
			$field.find( '.vpk-image-clear' ).prop( 'hidden', true );
		}
	}

	$( document ).on( 'click', '.vpk-image-pick', function ( e ) {
		e.preventDefault();
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var $field = $( this ).closest( '.vpk-image-field' );
		var frame = wp.media( {
			title: cfg.choose || '',
			button: { text: cfg.use || '' },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON();
			var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
			setImage( $field, a.id, url );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.vpk-image-clear', function ( e ) {
		e.preventDefault();
		setImage( $( this ).closest( '.vpk-image-field' ), 0, '' );
	} );

	// Term list screen: a value was added over AJAX → clear our fields.
	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		var data = settings && typeof settings.data === 'string' ? settings.data : '';
		if ( data.indexOf( 'action=add-tag' ) === -1 || ! $( '#addtag .vpk-term-fields' ).length ) {
			return;
		}
		var $form = $( '#addtag' );
		$form.find( '.vpk-image-field' ).each( function () {
			setImage( $( this ), 0, '' );
		} );
		$form.find( '.vpk-color' ).each( function () {
			var $i = $( this );
			if ( $.fn.wpColorPicker && $i.closest( '.wp-picker-container' ).length ) {
				$i.wpColorPicker( 'color', '' );
				$i.val( '' );
				$i.closest( '.wp-picker-container' ).find( '.wp-color-result' ).css( 'background-color', '' );
			} else {
				$i.val( '' );
			}
		} );
		$form.find( '.vpk-term-fields input[type="text"]' ).val( '' );
	} );

	$( function () {
		colors( document );
		// Product screen: the tab's content is in the page from the start, but
		// WooCommerce may reload attributes; re-init when the tab is opened.
		$( document.body ).on( 'click', '.vpk_options a', function () {
			colors( $( '#vpk_product_data' ) );
		} );
	} );
}( jQuery ) );
