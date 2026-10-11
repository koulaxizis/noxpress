/**
 * Book Preview — editor script of the "Book sample" block (no build step).
 * The block is rendered on the server (BPV_Front::render_block); the editor
 * shows a placeholder button and the two settings. Texts come from PHP
 * (window.BPV_BLOCK), so they follow the Noxpress admin language.
 */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var T = window.BPV_BLOCK || {};
	var be = wp.blockEditor;
	var c = wp.components;
	var el_ = function ( props ) {
		var a = props.attributes;
		return el(
			'div',
			be.useBlockProps(),
			el(
				be.InspectorControls,
				null,
				el(
					c.PanelBody,
					{ title: T.panel },
					el( c.TextControl, {
						label: T.product,
						type: 'number',
						value: a.productId ? String( a.productId ) : '',
						onChange: function ( v ) {
							props.setAttributes( { productId: parseInt( v, 10 ) || 0 } );
						}
					} ),
					el( c.TextControl, {
						label: T.label,
						value: a.label,
						onChange: function ( v ) {
							props.setAttributes( { label: v } );
						}
					} )
				)
			),
			el(
				'span',
				{ className: 'wp-element-button', style: { display: 'inline-block', cursor: 'default' } },
				a.label || T.button
			)
		);
	};
	wp.blocks.registerBlockType( 'book-preview/sample', {
		edit: el_,
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
