/**
 * Featured Creators — blocks.js (v1.0.0)
 *
 * Editor side of the three dynamic blocks. The site renders them on the
 * server (render_callback) with the plugin's settings; the editor shows a
 * placeholder.
 */
( function ( blocks, element, blockEditor, components ) {
	'use strict';

	var el = element.createElement;
	var t = window.crtBlocks || {};
	var useBlockProps = blockEditor.useBlockProps;

	function placeholder( title ) {
		return el( 'div', { className: 'crt-editor-placeholder', style: { padding: '12px 14px', border: '1px dashed currentColor', opacity: 0.8 } },
			el( 'strong', null, title ),
			el( 'div', { style: { fontSize: '12px', marginTop: '4px' } }, t.note || '' )
		);
	}

	blocks.registerBlockType( 'noxpress/creators', {
		apiVersion: 2,
		title: t.indexTitle || 'Creators (Featured Creators)',
		description: t.indexDesc || '',
		category: 'woocommerce',
		icon: 'groups',
		attributes: { role: { type: 'string', default: '' } },
		supports: { html: false, align: [ 'wide', 'full' ] },
		edit: function ( props ) {
			return el( element.Fragment, null,
				el( blockEditor.InspectorControls, null,
					el( components.PanelBody, null,
						el( components.TextControl, {
							label: t.roleLabel,
							value: props.attributes.role || '',
							onChange: function ( v ) {
								props.setAttributes( { role: String( v ).trim() } );
							}
						} )
					)
				),
				el( 'div', useBlockProps(), placeholder( t.indexTitle + ( props.attributes.role ? ' · ' + props.attributes.role : '' ) ) )
			);
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'noxpress/creator-box', {
		apiVersion: 2,
		title: t.boxTitle || 'Creator box (Featured Creators)',
		description: t.boxDesc || '',
		category: 'woocommerce',
		icon: 'id',
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
				el( 'div', useBlockProps(), placeholder( t.boxTitle + ( props.attributes.productId ? ' · #' + props.attributes.productId : '' ) ) )
			);
		},
		save: function () {
			return null;
		}
	} );

	blocks.registerBlockType( 'noxpress/creator', {
		apiVersion: 2,
		title: t.pageTitle || 'Creator page (Featured Creators)',
		description: t.pageDesc || '',
		category: 'theme',
		icon: 'admin-users',
		usesContext: [ 'postId', 'postType' ],
		supports: { html: false, align: [ 'wide', 'full' ] },
		edit: function () {
			return el( 'div', useBlockProps(), placeholder( t.pageTitle ) );
		},
		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components ) );
