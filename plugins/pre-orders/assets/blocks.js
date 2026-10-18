/**
 * Pre-Orders — blocks.js (v1.0.0)
 *
 * Editor side of the dynamic block noxpress/preorders. The site renders it
 * on the server (render_callback, the same output as [nox_preorders]); the
 * editor shows a placeholder.
 */
( function ( blocks, element, blockEditor, components ) {
	'use strict';

	var el = element.createElement;
	var t = window.porBlocks || {};
	var useBlockProps = blockEditor.useBlockProps;

	function num( v, min, max, fallback ) {
		var n = parseInt( v, 10 );
		if ( isNaN( n ) ) {
			return fallback;
		}
		return Math.max( min, Math.min( max, n ) );
	}

	blocks.registerBlockType( 'noxpress/preorders', {
		apiVersion: 2,
		title: t.title || 'Pre-orders (Pre-Orders)',
		description: t.desc || '',
		category: 'woocommerce',
		icon: 'calendar-alt',
		attributes: {
			limit: { type: 'number', default: 12 },
			columns: { type: 'number', default: 4 }
		},
		supports: { html: false, align: [ 'wide', 'full' ] },
		edit: function ( props ) {
			var a = props.attributes;
			return el( element.Fragment, null,
				el( blockEditor.InspectorControls, null,
					el( components.PanelBody, null,
						el( components.RangeControl, {
							label: t.limit,
							value: a.limit,
							min: 1,
							max: 48,
							onChange: function ( v ) {
								props.setAttributes( { limit: num( v, 1, 48, 12 ) } );
							}
						} ),
						el( components.RangeControl, {
							label: t.columns,
							value: a.columns,
							min: 1,
							max: 6,
							onChange: function ( v ) {
								props.setAttributes( { columns: num( v, 1, 6, 4 ) } );
							}
						} )
					)
				),
				el( 'div', useBlockProps(),
					el( 'div', { className: 'por-editor-placeholder', style: { padding: '12px 14px', border: '1px dashed currentColor', opacity: 0.8 } },
						el( 'strong', null, ( t.title || '' ) + ' · ' + a.limit + ' / ' + a.columns ),
						el( 'div', { style: { fontSize: '12px', marginTop: '4px' } }, t.note || '' )
					)
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components );
