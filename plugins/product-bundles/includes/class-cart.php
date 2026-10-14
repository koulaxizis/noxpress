<?php
/**
 * PBN_Cart — a bundle in the cart is its products (approved design, model B).
 *
 * Adding a bundle to the cart (product form, AJAX button, Store API) puts
 * its products in the cart as normal lines, all with the same group, and
 * takes the bundle itself out. Cart item data "pbn":
 *   { b: bundle id, g: group (hash of bundle + choices), r: row index, n: quantity per bundle }
 * so the same bundle with the same choices added again grows the same
 * lines (WooCommerce merges identical cart ids).
 *
 *  - Never half a bundle: every product is checked first; if one fails the
 *    lines already added go back to what they were.
 *  - Prices: every cart calculation sets each line to its share of the
 *    bundle price (PBN_Bundle::quote), from the current prices.
 *  - Quantities are locked (classic cart and Store API); removing one
 *    product removes the whole bundle, and "Undo" brings it all back.
 *  - A group that no longer matches its bundle (changed contents, bundle
 *    unpublished or no longer for sale) is removed with a notice.
 *  - Coupons skip bundle lines unless the setting allows them; a 100 %
 *    coupon (free copies) always applies.
 *
 * These hooks run whatever the mode and with PBN_DISABLE too: they keep a
 * cart that already holds a bundle correct (or remove it), they do not
 * change what the visitor sees on the shop pages.
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Cart {

	const KEY = 'pbn';

	/** @var bool True while this class adds or removes lines itself. */
	private static $busy = false;

	/** @var array Groups already reported in this request. */
	private static $told = array();

	public static function init(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 20, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 20, 4 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'expand' ), 20, 6 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'loaded' ), 20, 1 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check' ), 5 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'prices' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity_html' ), 20, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( __CLASS__, 'update_validation' ), 20, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'editable' ), 20, 3 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'quantity_guard' ), 20, 4 );
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'on_remove' ), 20, 2 );
		add_action( 'woocommerce_cart_item_restored', array( __CLASS__, 'on_restore' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'price_html' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_class', array( __CLASS__, 'item_class' ), 20, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'coupon_valid' ), 20, 4 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( __CLASS__, 'coupon_items' ), 20, 2 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( __CLASS__, 'order_again' ), 20, 3 );
	}

	/* =====================================================================
	 * Adding a bundle
	 * =================================================================== */

	/**
	 * Choices posted with the product form: row index => variation id.
	 *
	 * @param WC_Product $bundle Bundle.
	 */
	public static function posted_choices( WC_Product $bundle ): array {
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce's add-to-cart forms carry no nonce; values are whitelisted below.
		$raw = isset( $_REQUEST['pbn_choice'] ) && is_array( $_REQUEST['pbn_choice'] ) ? wp_unslash( $_REQUEST['pbn_choice'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$out = array();
		foreach ( PBN_Bundle::rows( $bundle ) as $i => $row ) {
			if ( 'choice' === $row['m'] && isset( $raw[ $i ] ) && is_scalar( $raw[ $i ] ) && ctype_digit( (string) $raw[ $i ] ) ) {
				$out[ $i ] = (int) $raw[ $i ];
			}
		}
		return $out;
	}

	/**
	 * What adding $n bundles puts in the cart, or why not.
	 *
	 * @param WC_Product $bundle  Bundle.
	 * @param array      $choices row index => variation id.
	 * @param int        $n       Number of bundles.
	 * @return array { ok: bool, error: string, lines: [ { row, product, per, qty } ] }
	 */
	public static function plan( WC_Product $bundle, array $choices, int $n ): array {
		$out  = array(
			'ok'    => false,
			'error' => '',
			'lines' => array(),
		);
		$rows = PBN_Bundle::rows( $bundle );
		if ( empty( $rows ) || $n < 1 ) {
			$out['error'] = __( 'Sorry, this product cannot be purchased.', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			return $out;
		}
		foreach ( $rows as $i => $row ) {
			$p = PBN_Bundle::resolve( $row, isset( $choices[ $i ] ) ? (int) $choices[ $i ] : null );
			if ( ! $p ) {
				$out['error'] = 'choice' === $row['m']
					? __( 'Please choose product options&hellip;', 'woocommerce' ) // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					: __( 'Sorry, this product cannot be purchased.', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				return $out;
			}
			if ( ! $p->is_purchasable() || '' === $p->get_price() ) {
				$out['error'] = __( 'Sorry, this product cannot be purchased.', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				return $out;
			}
			if ( ! $p->is_in_stock() ) {
				/* translators: %s: product name */
				$out['error'] = sprintf( __( 'You cannot add &quot;%s&quot; to the cart because the product is out of stock.', 'woocommerce' ), $p->get_name() ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				return $out;
			}
			$out['lines'][] = array(
				'row'     => $i,
				'product' => $p,
				'per'     => $row['q'],
				'qty'     => $row['q'] * $n,
			);
		}
		$out['ok'] = true;
		return $out;
	}

	/** Group id: same bundle + same choices = same lines. */
	public static function group_id( int $bundle_id, array $choices ): string {
		ksort( $choices );
		return substr( md5( $bundle_id . '|' . wp_json_encode( array_map( 'intval', $choices ) ) ), 0, 12 );
	}

	/**
	 * woocommerce_add_to_cart_validation: the whole bundle must fit, with
	 * what the cart already holds.
	 *
	 * @param mixed $passed       Passed so far.
	 * @param mixed $product_id   Product id.
	 * @param mixed $qty          Quantity.
	 * @param mixed $variation_id Variation id.
	 * @param mixed $variations   Variation data.
	 * @param mixed $data         Cart item data.
	 * @return bool
	 */
	public static function validate( $passed, $product_id, $qty = 1, $variation_id = 0, $variations = array(), $data = array() ) {
		if ( ! $passed || self::$busy ) {
			return $passed;
		}
		$bundle = wc_get_product( (int) $product_id );
		if ( ! $bundle instanceof PBN_Product ) {
			return $passed;
		}
		try {
			$n = max( 1, (int) wc_stock_amount( $qty ) );
			if ( ! $bundle->is_purchasable() ) {
				wc_add_notice( __( 'Sorry, this product cannot be purchased.', 'woocommerce' ), 'error' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				return false;
			}
			$choices = self::posted_choices( $bundle );
			$plan    = self::plan( $bundle, $choices, $n );
			if ( ! $plan['ok'] ) {
				wc_add_notice( $plan['error'], 'error' );
				return false;
			}
			$cart     = WC()->cart;
			$in_cart  = $cart ? $cart->get_cart_item_quantities() : array();
			$group    = self::group_id( (int) $bundle->get_id(), $choices );
			$existing = $cart ? self::group_count( $cart, $group ) : 0;

			if ( $existing > 0 && $bundle->is_sold_individually() ) {
				/* translators: %s: product name */
				wc_add_notice( sprintf( __( 'You cannot add another "%s" to your cart.', 'woocommerce' ), $bundle->get_name() ), 'error' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				return false;
			}

			$avail = PBN_Bundle::availability( $bundle );
			if ( -1 !== $avail['count'] && $n + self::bundle_count( $cart, (int) $bundle->get_id() ) > $avail['count'] ) {
				/* translators: 1: product name 2: quantity in stock */
				wc_add_notice( sprintf( __( 'You cannot add that amount of &quot;%1$s&quot; to the cart because there is not enough stock (%2$s remaining).', 'woocommerce' ), $bundle->get_name(), max( 0, $avail['count'] - self::bundle_count( $cart, (int) $bundle->get_id() ) ) ), 'error' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				return false;
			}

			// Same product twice in one bundle or already in the cart: the sum counts.
			$need = array();
			foreach ( $plan['lines'] as $line ) {
				$p  = $line['product'];
				$id = $p->get_stock_managed_by_id();
				if ( ! $p->managing_stock() || $p->backorders_allowed() ) {
					continue;
				}
				$need[ $id ] = ( isset( $need[ $id ] ) ? $need[ $id ] : ( isset( $in_cart[ $id ] ) ? (int) $in_cart[ $id ] : 0 ) ) + $line['qty'];
				if ( ! $p->has_enough_stock( $need[ $id ] ) ) {
					/* translators: 1: product name 2: quantity in stock */
					wc_add_notice( sprintf( __( 'You cannot add that amount of &quot;%1$s&quot; to the cart because there is not enough stock (%2$s remaining).', 'woocommerce' ), $p->get_name(), wc_format_stock_quantity_for_display( $p->get_stock_quantity(), $p ) ), 'error' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					return false;
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			wc_add_notice( __( 'Sorry, this product cannot be purchased.', 'woocommerce' ), 'error' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			return false;
		}
	}

	/**
	 * Keeps the posted choices on the (short-lived) bundle line.
	 *
	 * @param mixed $data         Cart item data.
	 * @param mixed $product_id   Product id.
	 * @param mixed $variation_id Variation id.
	 * @param mixed $quantity     Quantity.
	 */
	public static function cart_item_data( $data, $product_id, $variation_id = 0, $quantity = 1 ) {
		$data = is_array( $data ) ? $data : array();
		if ( self::$busy ) {
			return $data;
		}
		$bundle = wc_get_product( (int) $product_id );
		if ( $bundle instanceof PBN_Product ) {
			$data['pbn_choice'] = self::posted_choices( $bundle );
		}
		return $data;
	}

	/**
	 * woocommerce_add_to_cart: the bundle line becomes its products.
	 *
	 * @param mixed $key          Cart item key of the bundle.
	 * @param mixed $product_id   Product id.
	 * @param mixed $qty          Quantity.
	 * @param mixed $variation_id Variation id.
	 * @param mixed $variation    Variation data.
	 * @param mixed $data         Cart item data.
	 * @throws Exception When a product of the bundle cannot be added (classic cart: WooCommerce shows the notice).
	 */
	public static function expand( $key, $product_id, $qty = 1, $variation_id = 0, $variation = array(), $data = array() ): void {
		if ( self::$busy ) {
			return;
		}
		$bundle = wc_get_product( (int) $product_id );
		$cart   = WC()->cart;
		if ( ! $bundle instanceof PBN_Product || ! $cart ) {
			return;
		}

		self::$busy = true;
		$failed     = '';
		try {
			$contents = $cart->get_cart_contents();
			unset( $contents[ $key ] );
			$cart->set_cart_contents( $contents );

			$choices = is_array( $data ) && isset( $data['pbn_choice'] ) && is_array( $data['pbn_choice'] ) ? array_map( 'intval', $data['pbn_choice'] ) : array();
			$plan    = self::plan( $bundle, $choices, max( 1, (int) wc_stock_amount( $qty ) ) );
			if ( ! $plan['ok'] ) {
				$failed = $plan['error'];
			} else {
				$group  = self::group_id( (int) $bundle->get_id(), $choices );
				$before = array();
				foreach ( $plan['lines'] as $line ) {
					$p     = $line['product'];
					$vid   = $p->is_type( 'variation' ) ? (int) $p->get_id() : 0;
					$pid   = $vid ? (int) $p->get_parent_id() : (int) $p->get_id();
					$attrs = $vid ? wc_get_product_variation_attributes( $vid ) : array();
					$idata = array(
						self::KEY => array(
							'b' => (int) $bundle->get_id(),
							'g' => $group,
							'r' => (int) $line['row'],
							'n' => (int) $line['per'],
						),
					);
					$ck    = $cart->generate_cart_id( $pid, $vid, $attrs, $idata );
					if ( ! array_key_exists( $ck, $before ) ) {
						$all           = $cart->get_cart_contents();
						$before[ $ck ] = isset( $all[ $ck ] ) ? (int) $all[ $ck ]['quantity'] : 0;
					}
					$res = $cart->add_to_cart( $pid, $line['qty'], $vid, $attrs, $idata );
					if ( false === $res ) {
						$failed = ' ';
						break;
					}
				}
				if ( '' !== $failed ) {
					// Roll back: never half a bundle.
					$all = $cart->get_cart_contents();
					foreach ( $before as $ck => $q ) {
						if ( 0 === $q ) {
							unset( $all[ $ck ] );
						} elseif ( isset( $all[ $ck ] ) ) {
							$all[ $ck ]['quantity'] = $q;
						}
					}
					$cart->set_cart_contents( $all );
				}
			}
		} catch ( \Throwable $e ) {
			$failed = __( 'Sorry, this product cannot be purchased.', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}
		self::$busy = false;

		if ( '' === $failed ) {
			return;
		}
		$message = trim( $failed );
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			if ( '' === $message ) {
				$notices = wc_get_notices( 'error' );
				$last    = is_array( $notices ) ? end( $notices ) : null;
				$message = is_array( $last ) && isset( $last['notice'] ) ? wp_strip_all_tags( (string) $last['notice'] ) : __( 'Sorry, this product cannot be purchased.', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				wc_clear_notices();
			}
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'woocommerce_rest_add_to_cart_error', esc_html( $message ), 400 );
		}
		// Classic cart: add_to_cart() turns the exception into a notice and returns false.
		throw new Exception( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- shown by WooCommerce as a notice.
	}

	/* =====================================================================
	 * Groups
	 * =================================================================== */

	/**
	 * Bundle lines of a cart by group: group => [ cart keys ].
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function groups( WC_Cart $cart ): array {
		$out = array();
		foreach ( $cart->get_cart_contents() as $k => $item ) {
			if ( isset( $item[ self::KEY ]['g'] ) ) {
				$out[ (string) $item[ self::KEY ]['g'] ][] = $k;
			}
		}
		return $out;
	}

	/**
	 * How many bundles a group holds (0 = none).
	 *
	 * @param WC_Cart $cart  Cart.
	 * @param string  $group Group.
	 */
	public static function group_count( WC_Cart $cart, string $group ): int {
		foreach ( $cart->get_cart_contents() as $item ) {
			if ( isset( $item[ self::KEY ]['g'] ) && $group === $item[ self::KEY ]['g'] ) {
				return (int) floor( (int) $item['quantity'] / max( 1, (int) $item[ self::KEY ]['n'] ) );
			}
		}
		return 0;
	}

	/**
	 * How many of one bundle the cart holds, over all its groups.
	 *
	 * @param WC_Cart|null $cart      Cart.
	 * @param int          $bundle_id Bundle id.
	 */
	public static function bundle_count( $cart, int $bundle_id ): int {
		if ( ! $cart instanceof WC_Cart ) {
			return 0;
		}
		$n = 0;
		foreach ( self::groups( $cart ) as $g => $keys ) {
			$all  = $cart->get_cart_contents();
			$item = $all[ $keys[0] ];
			if ( (int) $item[ self::KEY ]['b'] === $bundle_id ) {
				$n += self::group_count( $cart, (string) $g );
			}
		}
		return $n;
	}

	/** @param mixed $cart Cart loaded from the session. */
	public static function loaded( $cart ): void {
		if ( $cart instanceof WC_Cart ) {
			self::check_groups( $cart );
			self::prices( $cart );
		}
	}

	public static function check(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			self::check_groups( WC()->cart );
		}
	}

	/**
	 * Removes every group that no longer matches its bundle.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function check_groups( WC_Cart $cart ): void {
		if ( self::$busy ) {
			return;
		}
		$groups = self::groups( $cart );
		if ( empty( $groups ) ) {
			return;
		}
		$all     = $cart->get_cart_contents();
		$changed = false;
		foreach ( $groups as $g => $keys ) {
			$first  = $all[ $keys[0] ];
			$bundle = wc_get_product( (int) $first[ self::KEY ]['b'] );
			if ( self::group_valid( $bundle, $keys, $all ) ) {
				continue;
			}
			foreach ( $keys as $k ) {
				unset( $all[ $k ] );
			}
			$changed = true;
			if ( ! isset( self::$told[ $g ] ) ) {
				self::$told[ $g ] = true;
				$name             = $bundle instanceof WC_Product ? $bundle->get_name() : get_the_title( (int) $first[ self::KEY ]['b'] );
				if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( esc_html( PBN_Settings::textf( 't_unavail', array( $name ) ) ), 'notice' );
				}
			}
		}
		if ( $changed ) {
			$cart->set_cart_contents( $all );
		}
	}

	/**
	 * Does a group still match its bundle exactly (rows, products, per-bundle quantities)?
	 *
	 * @param mixed $bundle Bundle.
	 * @param array $keys   Cart keys of the group.
	 * @param array $all    Cart contents.
	 */
	private static function group_valid( $bundle, array $keys, array $all ): bool {
		if ( ! $bundle instanceof PBN_Product || ! $bundle->is_purchasable() ) {
			return false;
		}
		$rows = PBN_Bundle::rows( $bundle );
		if ( count( $rows ) !== count( $keys ) ) {
			return false;
		}
		$seen  = array();
		$count = null;
		foreach ( $keys as $k ) {
			$item = $all[ $k ];
			$meta = $item[ self::KEY ];
			$r    = isset( $meta['r'] ) ? (int) $meta['r'] : -1;
			if ( ! isset( $rows[ $r ] ) || isset( $seen[ $r ] ) ) {
				return false;
			}
			$seen[ $r ] = true;
			$row        = $rows[ $r ];
			if ( (int) $meta['n'] !== (int) $row['q'] ) {
				return false;
			}
			$vid = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			$pid = (int) $item['product_id'];
			if ( 'fixed' === $row['m'] ) {
				if ( ( $vid ? $vid : $pid ) !== (int) $row['p'] ) {
					return false;
				}
			} elseif ( $pid !== (int) $row['p'] || ! isset( PBN_Bundle::choices( $row )[ $vid ] ) ) {
				return false;
			}
			$qty = (int) $item['quantity'];
			if ( $qty < 1 || 0 !== $qty % (int) $row['q'] ) {
				return false;
			}
			$c = intdiv( $qty, (int) $row['q'] );
			if ( null !== $count && $c !== $count ) {
				return false;
			}
			$count = $c;
		}
		return true;
	}

	/**
	 * Sets every bundle line to its share of the bundle price.
	 *
	 * @param mixed $cart Cart.
	 */
	public static function prices( $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		try {
			$contents = $cart->cart_contents;
			foreach ( self::groups( $cart ) as $keys ) {
				$first  = $contents[ $keys[0] ];
				$bundle = PBN_Bundle::product( (int) $first[ self::KEY ]['b'] );
				if ( ! $bundle instanceof PBN_Product ) {
					continue;
				}
				$rows    = PBN_Bundle::rows( $bundle );
				$choices = array();
				foreach ( $keys as $k ) {
					$r = (int) $contents[ $k ][ self::KEY ]['r'];
					if ( isset( $rows[ $r ] ) && 'choice' === $rows[ $r ]['m'] ) {
						$choices[ $r ] = (int) $contents[ $k ]['variation_id'];
					}
				}
				$q = PBN_Bundle::quote( $bundle, $choices );
				if ( ! $q['ok'] ) {
					continue;
				}
				$by_row = array();
				foreach ( $q['rows'] as $qr ) {
					$by_row[ (int) $qr['row'] ] = $qr;
				}
				foreach ( $keys as $k ) {
					$r = (int) $contents[ $k ][ self::KEY ]['r'];
					if ( ! isset( $by_row[ $r ] ) || ! $contents[ $k ]['data'] instanceof WC_Product ) {
						continue;
					}
					$cart->cart_contents[ $k ]['data']->set_price( $by_row[ $r ]['unit'] );
					$cart->cart_contents[ $k ][ self::KEY ]['base'] = $by_row[ $r ]['base'];
					$cart->cart_contents[ $k ][ self::KEY ]['unit'] = $by_row[ $r ]['unit'];
					$cart->cart_contents[ $k ][ self::KEY ]['name'] = $bundle->get_name();
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Display
	 * =================================================================== */

	/**
	 * "Bundle: Pocket Books" under every line (classic cart, mini-cart, blocks).
	 *
	 * @param mixed $data Item data.
	 * @param mixed $item Cart item.
	 */
	public static function item_data( $data, $item ) {
		$data = is_array( $data ) ? $data : array();
		if ( is_array( $item ) && isset( $item[ self::KEY ]['b'] ) ) {
			$name   = isset( $item[ self::KEY ]['name'] ) ? (string) $item[ self::KEY ]['name'] : get_the_title( (int) $item[ self::KEY ]['b'] );
			$data[] = array(
				'key'   => PBN_Settings::text( 't_part' ),
				'value' => wp_strip_all_tags( $name ),
			);
		}
		return $data;
	}

	/**
	 * Classic cart: the quantity is text, not a field.
	 *
	 * @param mixed $html Quantity HTML.
	 * @param mixed $key  Cart key.
	 * @param mixed $item Cart item.
	 */
	public static function quantity_html( $html, $key, $item = null ) {
		if ( ! is_array( $item ) && function_exists( 'WC' ) && WC()->cart ) {
			$item = WC()->cart->get_cart_item( (string) $key );
		}
		if ( is_array( $item ) && isset( $item[ self::KEY ] ) ) {
			$qty = (int) $item['quantity'];
			return '<span class="pbn-qty">' . esc_html( (string) $qty ) . '</span><input type="hidden" name="cart[' . esc_attr( (string) $key ) . '][qty]" value="' . esc_attr( (string) $qty ) . '" />';
		}
		return $html;
	}

	/**
	 * Classic cart update: a bundle quantity cannot change (0 = remove is allowed).
	 *
	 * @param mixed $passed Passed.
	 * @param mixed $key    Cart key.
	 * @param mixed $values Cart item.
	 * @param mixed $qty    New quantity.
	 */
	public static function update_validation( $passed, $key, $values, $qty ) {
		if ( $passed && is_array( $values ) && isset( $values[ self::KEY ] ) && (int) $qty > 0 && (int) $qty !== (int) $values['quantity'] ) {
			wc_add_notice( esc_html( PBN_Settings::text( 't_qty_locked' ) ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Any other way a quantity changes (Store API update-item, plugins):
	 * the old quantity comes back. 0 (remove) goes through remove_cart_item().
	 *
	 * @param mixed $key  Cart key.
	 * @param mixed $qty  New quantity.
	 * @param mixed $old  Old quantity.
	 * @param mixed $cart Cart.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException In the Store API, so the Cart block shows why.
	 */
	public static function quantity_guard( $key, $qty, $old = 0, $cart = null ): void {
		if ( self::$busy || ! $cart instanceof WC_Cart || ! isset( $cart->cart_contents[ $key ][ self::KEY ] ) ) {
			return;
		}
		if ( (float) $qty <= 0 || (float) $qty === (float) $old || (float) $old <= 0 ) {
			return;
		}
		$cart->cart_contents[ $key ]['quantity'] = $old;
		$message = PBN_Settings::text( 't_qty_locked' );
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'pbn_quantity_locked', esc_html( $message ), 400 );
		}
		wc_add_notice( esc_html( $message ), 'error' );
	}

	/**
	 * Store API: bundle lines are not editable in the Cart block.
	 *
	 * @param mixed $value     Editable.
	 * @param mixed $product   Product.
	 * @param mixed $cart_item Cart item.
	 */
	public static function editable( $value, $product = null, $cart_item = null ) {
		return is_array( $cart_item ) && isset( $cart_item[ self::KEY ] ) ? false : $value;
	}

	/**
	 * One product removed: the rest of its bundle goes too.
	 *
	 * @param mixed $key  Cart key.
	 * @param mixed $cart Cart.
	 */
	public static function on_remove( $key, $cart ): void {
		if ( self::$busy || ! $cart instanceof WC_Cart ) {
			return;
		}
		$all = $cart->get_cart_contents();
		if ( ! isset( $all[ $key ][ self::KEY ]['g'] ) ) {
			return;
		}
		$g          = (string) $all[ $key ][ self::KEY ]['g'];
		$name       = isset( $all[ $key ][ self::KEY ]['name'] ) ? (string) $all[ $key ][ self::KEY ]['name'] : get_the_title( (int) $all[ $key ][ self::KEY ]['b'] );
		self::$busy = true;
		$removed    = false;
		foreach ( $all as $k => $item ) {
			if ( $k !== $key && isset( $item[ self::KEY ]['g'] ) && $g === $item[ self::KEY ]['g'] ) {
				$cart->remove_cart_item( $k );
				$removed = true;
			}
		}
		self::$busy = false;
		if ( $removed && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			wc_add_notice( esc_html( PBN_Settings::textf( 't_removed', array( $name ) ) ), 'notice' );
		}
	}

	/**
	 * "Undo" on one product brings the whole bundle back.
	 *
	 * @param mixed $key  Cart key.
	 * @param mixed $cart Cart.
	 */
	public static function on_restore( $key, $cart ): void {
		if ( self::$busy || ! $cart instanceof WC_Cart ) {
			return;
		}
		$all = $cart->get_cart_contents();
		if ( ! isset( $all[ $key ][ self::KEY ]['g'] ) ) {
			return;
		}
		$g          = (string) $all[ $key ][ self::KEY ]['g'];
		self::$busy = true;
		foreach ( $cart->get_removed_cart_contents() as $k => $item ) {
			if ( isset( $item[ self::KEY ]['g'] ) && $g === $item[ self::KEY ]['g'] ) {
				$cart->restore_cart_item( $k );
			}
		}
		self::$busy = false;
	}

	/**
	 * Classic cart price column: the separate price struck through.
	 *
	 * @param mixed $html Price HTML.
	 * @param mixed $item Cart item.
	 * @param mixed $key  Cart key.
	 */
	public static function price_html( $html, $item, $key ) {
		if ( ! is_array( $item ) || ! isset( $item[ self::KEY ]['base'], $item[ self::KEY ]['unit'] ) || ! $item['data'] instanceof WC_Product ) {
			return $html;
		}
		$base = (float) $item[ self::KEY ]['base'];
		$unit = (float) $item[ self::KEY ]['unit'];
		if ( $unit >= $base - 0.004 ) {
			return $html;
		}
		$p    = $item['data'];
		$incl = WC()->cart && WC()->cart->display_prices_including_tax();
		$reg  = $incl ? wc_get_price_including_tax( $p, array( 'price' => $base ) ) : wc_get_price_excluding_tax( $p, array( 'price' => $base ) );
		$now  = $incl ? wc_get_price_including_tax( $p, array( 'price' => $unit ) ) : wc_get_price_excluding_tax( $p, array( 'price' => $unit ) );
		return wc_format_sale_price( $reg, $now );
	}

	/**
	 * @param mixed $class Row class.
	 * @param mixed $item  Cart item.
	 * @param mixed $key   Cart key.
	 */
	public static function item_class( $class, $item, $key ) {
		return is_array( $item ) && isset( $item[ self::KEY ] ) ? trim( $class . ' pbn-item' ) : $class;
	}

	/* =====================================================================
	 * Coupons
	 * =================================================================== */

	/**
	 * A cart item or order item of a bundle?
	 *
	 * @param mixed $item Cart item array or WC_Order_Item_Product.
	 */
	public static function is_bundle_line( $item ): bool {
		if ( is_array( $item ) ) {
			return isset( $item[ self::KEY ] );
		}
		return $item instanceof WC_Order_Item_Product && is_array( $item->get_meta( PBN_Order::META, true ) );
	}

	/** A 100 % coupon (free copies) applies to bundles too. */
	private static function full_coupon( $coupon ): bool {
		return $coupon instanceof WC_Coupon && $coupon->is_type( 'percent' ) && (float) $coupon->get_amount() >= 100.0;
	}

	/**
	 * @param mixed $valid   Valid.
	 * @param mixed $product Product.
	 * @param mixed $coupon  Coupon.
	 * @param mixed $values  Cart item / order item.
	 */
	public static function coupon_valid( $valid, $product, $coupon, $values = null ) {
		if ( ! $valid || 'yes' === PBN_Settings::get()['coupons'] || self::full_coupon( $coupon ) ) {
			return $valid;
		}
		return self::is_bundle_line( $values ) ? false : $valid;
	}

	/**
	 * Cart-wide coupons (fixed cart) skip bundle lines too (WooCommerce 8.8+).
	 *
	 * @param mixed $items  Items to apply the coupon to.
	 * @param mixed $coupon Coupon.
	 */
	public static function coupon_items( $items, $coupon ) {
		if ( ! is_array( $items ) || 'yes' === PBN_Settings::get()['coupons'] || self::full_coupon( $coupon ) ) {
			return $items;
		}
		return array_values(
			array_filter(
				$items,
				static function ( $it ) {
					return ! ( is_object( $it ) && isset( $it->object ) && self::is_bundle_line( $it->object ) );
				}
			)
		);
	}

	/**
	 * "Order again": the lines come back as the same bundle (checked and
	 * priced again like any bundle in the cart).
	 *
	 * @param mixed $data  Cart item data.
	 * @param mixed $item  Order item.
	 * @param mixed $order Order.
	 */
	public static function order_again( $data, $item, $order ) {
		$data = is_array( $data ) ? $data : array();
		if ( $item instanceof WC_Order_Item_Product ) {
			$m = $item->get_meta( PBN_Order::META, true );
			if ( is_array( $m ) && isset( $m['b'], $m['g'], $m['r'], $m['n'] ) ) {
				$data[ self::KEY ] = array(
					'b' => (int) $m['b'],
					'g' => (string) $m['g'],
					'r' => (int) $m['r'],
					'n' => (int) $m['n'],
				);
			}
		}
		return $data;
	}
}
