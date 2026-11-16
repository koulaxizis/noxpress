/* Events — the «Events» block (noxpress/events). Plain JS, no build step. */
( function ( wp, cfg ) {
	'use strict';
	if ( ! wp || ! wp.blocks ) {
		return;
	}
	var el = wp.element.createElement;
	var C = wp.components;
	var Inspector = ( wp.blockEditor || wp.editor ).InspectorControls;
	var SSR = wp.serverSideRender;
	wp.blocks.registerBlockType( 'noxpress/events', {
		apiVersion: 2,
		title: cfg.title,
		description: cfg.desc,
		icon: 'calendar-alt',
		category: 'widgets',
		attributes: {
			count: { type: 'number', default: 5 },
			past: { type: 'boolean', default: false },
			city: { type: 'string', default: '' },
			title: { type: 'string', default: '' },
			current: { type: 'boolean', default: false }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};
			return el( 'div', blockProps,
				el( Inspector, null,
					el( C.PanelBody, { title: cfg.settings },
						el( C.TextControl, { label: cfg.heading, value: a.title, onChange: function ( v ) { set( { title: v } ); } } ),
						el( C.RangeControl, { label: cfg.count, value: a.count, min: 1, max: 20, onChange: function ( v ) { set( { count: v } ); } } ),
						el( C.TextControl, { label: cfg.city, value: a.city, onChange: function ( v ) { set( { city: v } ); } } ),
						el( C.ToggleControl, { label: cfg.past, checked: a.past, onChange: function ( v ) { set( { past: v } ); } } ),
						el( C.ToggleControl, { label: cfg.current, checked: a.current, onChange: function ( v ) { set( { current: v } ); } } )
					)
				),
				el( SSR, { block: 'noxpress/events', attributes: a } )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp, window.evtBlock || {} );
