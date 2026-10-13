/**
 * orOS Connect — editor script of the block oros-connect/newsletter.
 * No build step: plain ES5 with the editor's globals. The front end is
 * rendered by PHP (ORC_Newsletter::render_block); the editor shows the same
 * HTML through ServerSideRender.
 */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var be = wp.blockEditor;
	var c = wp.components;
	var SSR = wp.serverSideRender;
	var greek = ( document.documentElement.lang || '' ).toLowerCase().indexOf( 'el' ) === 0;

	wp.blocks.registerBlockType( 'oros-connect/newsletter', {
		edit: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: 'Newsletter (orOS)' },
						el( c.ToggleControl, {
							label: greek ? 'Τίτλος της φόρμας' : 'Form title',
							checked: a.showTitle !== false,
							onChange: function ( v ) {
								props.setAttributes( { showTitle: !! v } );
							}
						} )
					)
				),
				el( c.Disabled, null, el( SSR, { block: 'oros-connect/newsletter', attributes: a } ) )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
