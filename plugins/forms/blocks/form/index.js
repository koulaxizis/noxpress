/**
 * Forms — editor script of the block forms/form.
 * No build step: plain ES5 with the editor's globals. The front end is
 * rendered by PHP (FRM_Front::render_block); the editor shows the same HTML
 * through ServerSideRender. The list of forms comes from window.frmForms
 * (FRM_Front::editor_data).
 */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var be = wp.blockEditor;
	var c = wp.components;
	var SSR = wp.serverSideRender;
	var greek = ( document.documentElement.lang || '' ).toLowerCase().indexOf( 'el' ) === 0;
	var forms = Array.isArray( window.frmForms ) ? window.frmForms : [];

	wp.blocks.registerBlockType( 'forms/form', {
		edit: function ( props ) {
			var a = props.attributes;
			var options = [ { value: 0, label: greek ? '— Διάλεξε φόρμα —' : '— Pick a form —' } ].concat( forms );
			var picker = el( c.SelectControl, {
				label: greek ? 'Φόρμα' : 'Form',
				value: a.formId || 0,
				options: options,
				onChange: function ( v ) {
					props.setAttributes( { formId: parseInt( v, 10 ) || 0 } );
				}
			} );
			return el(
				'div',
				be.useBlockProps(),
				el( be.InspectorControls, null, el( c.PanelBody, { title: greek ? 'Φόρμα' : 'Form' }, picker ) ),
				a.formId
					? el( c.Disabled, null, el( SSR, { block: 'forms/form', attributes: a } ) )
					: el( c.Placeholder, { icon: 'feedback', label: 'Forms', instructions: forms.length ? '' : ( greek ? 'Φτιάξε μια φόρμα στο Noxpress → Forms.' : 'Create a form in Noxpress → Forms.' ) }, picker )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
