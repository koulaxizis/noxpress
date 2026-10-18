/**
 * Wish Shelf — editor side of the three dynamic blocks. The blocks render
 * on the server for each visitor (their list is per visitor and per
 * browser), so the editor shows a placeholder. No build step.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var useBlockProps = wp.blockEditor && wp.blockEditor.useBlockProps;
	var L = window.wshBlocks || {};

	function icon() {
		return el( 'svg', { viewBox: '0 0 24 24', width: 24, height: 24 },
			el( 'path', { d: 'M12 20.7l-1.2-1.1C6.1 15.4 3 12.6 3 9.1 3 6.3 5.2 4.1 8 4.1c1.6 0 3.1.7 4 1.9.9-1.2 2.4-1.9 4-1.9 2.8 0 5 2.2 5 5 0 3.5-3.1 6.3-7.8 10.5L12 20.7z' } )
		);
	}

	function edit( title ) {
		return function () {
			var wrap = useBlockProps ? useBlockProps( { className: 'wsh-block-ph' } ) : {};
			return el( 'div', wrap,
				el( 'div', { style: { display: 'flex', gap: '8px', alignItems: 'center', padding: '12px', border: '1px dashed #c3c4c7', borderRadius: '4px', fontSize: '13px' } },
					icon(),
					el( 'span', null, el( 'strong', null, title ), ' — ', L.hint || '' )
				)
			);
		};
	}

	[
		[ 'noxpress/wishlist', L.list || 'Wishlist' ],
		[ 'noxpress/wishlist-button', L.button || 'Wishlist button' ],
		[ 'noxpress/wishlist-count', L.count || 'Wishlist counter' ]
	].forEach( function ( b ) {
		if ( wp.blocks.getBlockType( b[ 0 ] ) ) {
			return;
		}
		wp.blocks.registerBlockType( b[ 0 ], {
			apiVersion: 3,
			title: b[ 1 ],
			category: 'woocommerce',
			icon: icon,
			usesContext: 'noxpress/wishlist-button' === b[ 0 ] ? [ 'postId', 'postType' ] : [],
			supports: { html: false },
			edit: edit( b[ 1 ] ),
			save: function () {
				return null;
			}
		} );
	} );
} )( window.wp );
