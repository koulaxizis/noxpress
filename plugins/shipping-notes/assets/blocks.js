/**
 * Shipping Notes — blocks.js (v1.0.0)
 *
 * Editor side of the two dynamic blocks. The site renders them on the
 * server (render_callback), with the visitor's cart and the plugin's
 * settings; the editor shows a placeholder.
 */
( function ( blocks, element, blockEditor, components ) {
	'use strict';

	var el = element.createElement;
	var t = window.shnBlocks || {};
	var useBlockProps = blockEditor.useBlockProps;

	function placeholder( title ) {
		return el( 'div', { className: 'shn-editor-placeholder', style: { padding: '12px 14px', border: '1px dashed currentColor', opacity: 0.8 } },
			el( 'strong', null, title ),
			el( 'div', { style: { fontSize: '12px', marginTop: '4px' } }, t.note || '' )
		);
	}

	blocks.registerBlockType( 'noxpress/free-shipping', {
		apiVersion: 2,
		title: t.fsTitle || 'Free shipping (Shipping Notes)',
		description: t.fsDesc || '',
		category: 'woocommerce',
		icon: 'car',
		supports: { html: false },
		edit: function () {
			return el( 'div', useBlockProps(), placeholder( t.fsTitle ) );
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'noxpress/delivery', {
		apiVersion: 2,
		title: t.dlTitle || 'Delivery time (Shipping Notes)',
		description: t.dlDesc || '',
		category: 'woocommerce',
		icon: 'clock',
		attributes: { productId: { type: 'number', default: 0 } },
		usesContext: [ 'postId', 'postType' ],
		supports: { html: false },
		edit: function ( props ) {
			return el( element.Fragment, null,
				el( blockEditor.InspectorControls, null,
					el( components.PanelBody, null,
						el( components.TextControl, {
							label: t.idLabel,
							type: 'number',
							value: props.attributes.productId || '',
							onChange: function ( v ) {
								var n = parseInt( v, 10 );
								props.setAttributes( { productId: n > 0 ? n : 0 } );
							}
						} )
					)
				),
				el( 'div', useBlockProps(), placeholder( t.dlTitle + ( props.attributes.productId ? ' · #' + props.attributes.productId : '' ) ) )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components ) );
