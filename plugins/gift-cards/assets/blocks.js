/**
 * Gift Cards — blocks.js (v1.0.0)
 *
 * Cart and Checkout blocks: the "Have a gift card?" field and the applied
 * cards in the order summary (ExperimentalDiscountsMeta). Uses only
 * extensionCartUpdate (Store API, with its own nonce) — no endpoint of
 * our own. Plain wp.element, no build step.
 */
( function ( wp, wc ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element || ! wc || ! wc.blocksCheckout ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var t = window.gfcBlocks || {};
	var Slot = wc.blocksCheckout.ExperimentalDiscountsMeta;
	var update = wc.blocksCheckout.extensionCartUpdate;
	if ( ! Slot || ! update ) {
		return;
	}

	function Field( props ) {
		var exts = props.extensions || ( props.cart && props.cart.extensions ) || {};
		var ext = exts[ 'gift-cards' ] || {};
		var cards = ext.cards || [];
		var st = useState( '' ), code = st[ 0 ], setCode = st[ 1 ];
		var ms = useState( null ), msg = ms[ 0 ], setMsg = ms[ 1 ];
		var bs = useState( false ), busy = bs[ 0 ], setBusy = bs[ 1 ];

		if ( false === ext.enabled ) {
			return null;
		}

		function apply() {
			var c = String( code || '' ).trim();
			if ( ! c || busy ) {
				return;
			}
			setBusy( true );
			setMsg( null );
			update( { namespace: 'gift-cards', data: { action: 'apply', code: c } } )
				.then( function () {
					setCode( '' );
				} )
				.catch( function ( e ) {
					setMsg( { text: ( e && e.message ) || '', error: true } );
				} )
				.finally( function () {
					setBusy( false );
				} );
		}

		function remove( id ) {
			update( { namespace: 'gift-cards', data: { action: 'remove', card: id } } );
		}

		return el(
			'div',
			{ className: 'gfc-b' },
			el( 'p', { className: 'gfc-b__title' }, el( 'strong', null, t.title || '' ) ),
			el(
				'div',
				{ className: 'gfc-b__line' },
				el( 'input', {
					type: 'text',
					value: code,
					maxLength: 24,
					autoComplete: 'off',
					spellCheck: false,
					placeholder: t.placeholder || '',
					'aria-label': t.placeholder || '',
					onChange: function ( e ) { setCode( e.target.value ); },
					onKeyDown: function ( e ) {
						if ( 'Enter' === e.key ) {
							e.preventDefault();
							apply();
						}
					}
				} ),
				el( 'button', { type: 'button', className: 'wc-block-components-button wp-element-button', disabled: busy, onClick: apply }, t.apply || '' )
			),
			msg ? el( 'p', { className: 'gfc-b__msg' + ( msg.error ? ' is-error' : '' ), role: 'status' }, msg.text ) : null,
			cards.map( function ( c ) {
				return el(
					'div',
					{ className: 'gfc-b__row', key: c.id },
					el( 'span', null, c.label, ' ', el( 'button', { type: 'button', className: 'gfc-b__x', onClick: function () { remove( c.id ); } }, t.remove || '' ), el( 'small', null, c.balance ) ),
					el( 'strong', null, c.amount )
				);
			} )
		);
	}

	wp.plugins.registerPlugin( 'gift-cards', {
		scope: 'woocommerce-checkout',
		render: function () {
			// The slot passes its fill props (cart, extensions) to each child.
			return el( Slot, null, el( Field ) );
		}
	} );
}( window.wp, window.wc ) );
