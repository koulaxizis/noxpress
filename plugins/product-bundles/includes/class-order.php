<?php
/**
 * PBN_Order — bundle lines in orders (approved design, model B and Δ2).
 *
 * Every product of a bundle is its own order line with:
 *  - hidden meta _pbn: { b: bundle id, g: group, r: row, n: quantity per
 *    bundle, c: bundles, name, pre_u / pre_t: total and tax per unit at
 *    the bundle price, sub_u: separate price per unit (ex tax) }
 *  - visible meta "Bundle: Pocket Books" (readable without the plugin,
 *    Bible §14.21)
 *  - subtotal = the separate price, total = the bundle share (Δ2), the
 *    same shape as a coupon, so Revenue Splitter, Store Pulse,
 *    WooCommerce (admin, emails, Analytics) show the bundle discount as a
 *    discount; the order discount is raised by the same amount.
 *
 * WooCommerce rewrites line totals from the subtotal when coupons are
 * recalculated on an existing order (WC_Abstract_Order::recalculate_coupons()
 * sets total = subtotal). before_totals() puts the bundle share back.
 *
 * The bundle's own total_sales grows with every paid order (approved
 * question 17), so "Sort by popularity" works for bundles.
 *
 * Orders created in the admin: the "Bundles" box adds the products of a
 * bundle with its prices; the bundle itself cannot be added as a line.
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Order {

	const META = '_pbn';

	/** @var bool[] Orders whose coupons are being recalculated (id => true). */
	private static $recalc = array();

	public static function init(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item' ), 20, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'checkout_order' ), 20, 2 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( __CLASS__, 'recalc_flag' ), 20, 4 );
		add_action( 'woocommerce_order_before_calculate_totals', array( __CLASS__, 'before_totals' ), 20, 2 );
		add_action( 'woocommerce_recorded_sales', array( __CLASS__, 'recorded_sales' ), 20, 1 );
		add_filter( 'woocommerce_ajax_add_order_item_validation', array( __CLASS__, 'block_raw' ), 20, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ), 20, 1 );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 40, 2 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20, 1 );
			add_action( 'wp_ajax_pbn_order_choices', array( __CLASS__, 'ajax_choices' ) );
			add_action( 'wp_ajax_pbn_order_add', array( __CLASS__, 'ajax_add' ) );
		}
	}

	/* =====================================================================
	 * Checkout
	 * =================================================================== */

	/**
	 * A cart line of a bundle becomes an order line with the bundle meta
	 * and the separate price as subtotal.
	 *
	 * @param mixed $item  Order item.
	 * @param mixed $key   Cart key.
	 * @param mixed $values Cart item.
	 * @param mixed $order Order.
	 */
	public static function line_item( $item, $key, $values, $order ): void {
		if ( ! $item instanceof WC_Order_Item_Product || ! is_array( $values ) || ! isset( $values[ PBN_Cart::KEY ]['b'] ) ) {
			return;
		}
		try {
			$m    = $values[ PBN_Cart::KEY ];
			$p    = isset( $values['data'] ) && $values['data'] instanceof WC_Product ? $values['data'] : $item->get_product();
			$name = isset( $m['name'] ) ? (string) $m['name'] : get_the_title( (int) $m['b'] );
			$base = isset( $m['base'] ) ? (float) $m['base'] : 0.0;
			$unit = isset( $m['unit'] ) ? (float) $m['unit'] : 0.0;
			self::mark( $item, $m, wp_strip_all_tags( $name ), $base, $unit, $p instanceof WC_Product ? $p : null );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Writes the bundle meta on a line and moves its subtotal to the
	 * separate price (Δ2). The line total (the bundle share) is not touched.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 * @param array                 $m    { b, g, r, n }.
	 * @param string                $name Bundle name.
	 * @param float                 $base Separate price per unit (as stored: with or without tax).
	 * @param float                 $unit Bundle share per unit (as stored).
	 * @param WC_Product|null       $p    Product.
	 */
	public static function mark( WC_Order_Item_Product $item, array $m, string $name, float $base, float $unit, ?WC_Product $p ): void {
		$qty   = max( 1, (float) $item->get_quantity() );
		$sub   = (float) $item->get_subtotal();
		$taxes = $item->get_taxes();
		$taxes = is_array( $taxes ) ? $taxes : array();
		$stax  = isset( $taxes['subtotal'] ) && is_array( $taxes['subtotal'] ) ? $taxes['subtotal'] : array();
		$pre_t = (float) $item->get_subtotal_tax();

		$new_sub  = $sub;
		$new_stax = $stax;
		if ( $base > $unit + 0.000001 ) {
			if ( $unit > 0 ) {
				$r       = $base / $unit;
				$new_sub = $sub * $r;
				foreach ( $new_stax as $rate => $amount ) {
					$new_stax[ $rate ] = (float) $amount * $r;
				}
			} elseif ( $p ) {
				// Share 0 (bundle price 0): the separate price from the product's own tax.
				$new_sub  = (float) wc_get_price_excluding_tax(
					$p,
					array(
						'qty'   => $qty,
						'price' => $base,
					)
				);
				$new_stax = array();
				if ( wc_tax_enabled() && $p->is_taxable() ) {
					$new_stax = WC_Tax::calc_tax( $new_sub, WC_Tax::get_rates( $item->get_tax_class() ), false );
				}
			}
		}

		if ( abs( $new_sub - $sub ) > 0.0000001 ) {
			$item->set_subtotal( wc_format_decimal( $new_sub ) );
			$taxes['subtotal'] = $new_stax;
			if ( ! isset( $taxes['total'] ) ) {
				$taxes['total'] = array();
			}
			$item->set_taxes( $taxes );
			$item->set_subtotal_tax( wc_format_decimal( array_sum( array_map( 'floatval', $new_stax ) ) ) );
		}

		$per = max( 1, (int) $m['n'] );
		$item->update_meta_data(
			self::META,
			array(
				'b'     => (int) $m['b'],
				'g'     => (string) $m['g'],
				'r'     => (int) $m['r'],
				'n'     => $per,
				'c'     => (int) floor( $qty / $per ),
				'name'  => $name,
				'pre_u' => $sub / $qty,
				'pre_t' => $pre_t / $qty,
				'sub_u' => $new_sub / $qty,
			)
		);
		$item->update_meta_data( PBN_Settings::text( 't_part' ), $name );
	}

	/**
	 * Classic checkout: the order discount grows by the bundle discount,
	 * so subtotal − discount = total (the block checkout calls
	 * calculate_totals(), which does the same from the lines).
	 *
	 * @param mixed $order Order.
	 * @param mixed $data  Posted data.
	 */
	public static function checkout_order( $order, $data = array() ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		try {
			$d  = 0.0;
			$dt = 0.0;
			foreach ( $order->get_items() as $item ) {
				$m = self::meta( $item );
				if ( null === $m ) {
					continue;
				}
				$qty = (float) $item->get_quantity();
				$d  += (float) $item->get_subtotal() - (float) $m['pre_u'] * $qty;
				$dt += (float) $item->get_subtotal_tax() - (float) $m['pre_t'] * $qty;
			}
			if ( abs( $d ) < 0.0000001 && abs( $dt ) < 0.0000001 ) {
				return;
			}
			$order->set_discount_total( wc_format_decimal( (float) $order->get_discount_total( 'edit' ) + $d, wc_get_price_decimals() ) );
			$order->set_discount_tax( wc_format_decimal( (float) $order->get_discount_tax( 'edit' ) + $dt, wc_get_price_decimals() ) );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Recalculation in the admin
	 * =================================================================== */

	/**
	 * Notes that this order's coupons are being recalculated (its lines
	 * were just reset to total = subtotal).
	 *
	 * @param mixed $coupon      Coupon.
	 * @param mixed $code        Code.
	 * @param mixed $coupon_item Coupon item.
	 * @param mixed $order       Order.
	 */
	public static function recalc_flag( $coupon, $code = '', $coupon_item = null, $order = null ) {
		if ( $order instanceof WC_Abstract_Order ) {
			self::$recalc[ (int) $order->get_id() ] = true;
		}
		return $coupon;
	}

	/**
	 * Puts the bundle share back on lines whose total was reset to the
	 * subtotal. Lines a person edited by hand stay as they are.
	 *
	 * @param mixed $and_taxes Calculate taxes.
	 * @param mixed $order     Order.
	 */
	public static function before_totals( $and_taxes, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$oid  = (int) $order->get_id();
		$flag = ! empty( self::$recalc[ $oid ] );
		unset( self::$recalc[ $oid ] );
		try {
			foreach ( $order->get_items() as $item ) {
				$m = self::meta( $item );
				if ( null === $m || (float) $m['sub_u'] <= 0 || (float) $m['pre_u'] >= (float) $m['sub_u'] - 0.0000001 ) {
					continue;
				}
				$sub   = (float) $item->get_subtotal();
				$total = (float) $item->get_total();
				if ( ! $flag && abs( $total - $sub ) > 0.005 ) {
					continue;
				}
				$f     = (float) $m['pre_u'] / (float) $m['sub_u'];
				$taxes = $item->get_taxes();
				if ( is_array( $taxes ) && isset( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
					foreach ( $taxes['total'] as $rate => $amount ) {
						$taxes['total'][ $rate ] = (float) $amount * $f;
					}
					$item->set_taxes( $taxes );
				}
				$item->set_total( wc_format_decimal( $total * $f ) );
				$item->set_total_tax( wc_format_decimal( (float) $item->get_total_tax() * $f ) );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Sales of the bundle itself
	 * =================================================================== */

	/**
	 * After WooCommerce counts the sales of the lines, counts the bundles.
	 *
	 * @param mixed $order_id Order id.
	 */
	public static function recorded_sales( $order_id ): void {
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$op     = $order->get_data_store()->get_recorded_sales( $order ) ? 'increase' : 'decrease';
			$counts = array();
			foreach ( self::groups( $order ) as $g ) {
				$counts[ $g['b'] ] = ( isset( $counts[ $g['b'] ] ) ? $counts[ $g['b'] ] : 0 ) + $g['c'];
			}
			if ( empty( $counts ) ) {
				return;
			}
			$store = WC_Data_Store::load( 'product' );
			foreach ( $counts as $bid => $c ) {
				if ( $c > 0 && PBN_Bundle::product( (int) $bid ) instanceof PBN_Product ) {
					$store->update_product_sales( (int) $bid, (int) $c, $op );
					PBN_Bundle::queue( (int) $bid ); // A "most bundles" limit depends on it.
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/**
	 * The bundle meta of a line, or null.
	 *
	 * @param mixed $item Order item.
	 */
	public static function meta( $item ): ?array {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return null;
		}
		$m = $item->get_meta( self::META, true );
		if ( ! is_array( $m ) || ! isset( $m['b'], $m['g'], $m['n'] ) ) {
			return null;
		}
		foreach ( array( 'pre_u', 'pre_t', 'sub_u' ) as $k ) {
			$m[ $k ] = isset( $m[ $k ] ) && is_numeric( $m[ $k ] ) ? (float) $m[ $k ] : 0.0;
		}
		return $m;
	}

	/**
	 * The bundles of an order: group => { b, name, c }.
	 *
	 * @param WC_Abstract_Order $order Order.
	 */
	public static function groups( WC_Abstract_Order $order ): array {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$m = self::meta( $item );
			if ( null === $m || isset( $out[ $m['g'] ] ) ) {
				continue;
			}
			$out[ (string) $m['g'] ] = array(
				'b'    => (int) $m['b'],
				'name' => isset( $m['name'] ) ? (string) $m['name'] : '',
				'c'    => (int) floor( (float) $item->get_quantity() / max( 1, (int) $m['n'] ) ),
			);
		}
		return $out;
	}

	/**
	 * Public API (pbn_get_item_bundle()).
	 *
	 * @param WC_Order_Item_Product $item Line.
	 */
	public static function item_bundle( WC_Order_Item_Product $item ): ?array {
		$m = self::meta( $item );
		if ( null === $m ) {
			return null;
		}
		$qty = (float) $item->get_quantity();
		return array(
			'bundle_id' => (int) $m['b'],
			'name'      => isset( $m['name'] ) ? (string) $m['name'] : '',
			'group'     => (string) $m['g'],
			'bundles'   => (int) floor( $qty / max( 1, (int) $m['n'] ) ),
			'per'       => (int) $m['n'],
			'separate'  => (float) $m['sub_u'] * $qty,
			'share'     => (float) $m['pre_u'] * $qty,
		);
	}

	/** @param mixed $keys Hidden item meta keys. */
	public static function hidden_meta( $keys ) {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = self::META;
		return $keys;
	}

	/* =====================================================================
	 * Admin: orders created by hand
	 * =================================================================== */

	/**
	 * The bundle itself cannot be an order line: the "Bundles" box adds its products.
	 *
	 * @param mixed $error   WP_Error.
	 * @param mixed $product Product.
	 * @param mixed $order   Order.
	 * @param mixed $qty     Quantity.
	 */
	public static function block_raw( $error, $product, $order = null, $qty = 1 ) {
		if ( $error instanceof WP_Error && $product instanceof PBN_Product ) {
			$error->add( 'pbn_bundle', __( 'Ένα πακέτο δεν μπαίνει ως γραμμή. Χρησιμοποίησε το κουτί «Πακέτα» της παραγγελίας, που προσθέτει τα προϊόντα του με τις τιμές του πακέτου.', 'product-bundles' ) );
		}
		return $error;
	}

	/** Screen id of the order edit screen (HPOS or posts). */
	private static function order_screen(): string {
		return function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	/**
	 * @param mixed $screen_id Screen / post type.
	 * @param mixed $object    WP_Post or WC_Order.
	 */
	public static function meta_box( $screen_id, $object = null ): void {
		if ( ! in_array( $screen_id, array( self::order_screen(), 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
		if ( ! $order instanceof WC_Order || ! $order->is_editable() || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		add_meta_box( 'pbn-order', __( 'Πακέτα', 'product-bundles' ), array( __CLASS__, 'render_box' ), $screen_id, 'side', 'default' );
	}

	/** @param mixed $object WP_Post or WC_Order. */
	public static function render_box( $object ): void {
		$order = $object instanceof WC_Order ? $object : ( $object instanceof WP_Post ? wc_get_order( $object->ID ) : null );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$ids = PBN_Bundle::bundle_ids( 0, 500 );
		echo '<div class="pbn-order-box" data-order="' . esc_attr( (string) $order->get_id() ) . '">';
		if ( empty( $ids ) ) {
			echo '<p>' . esc_html__( 'Δεν υπάρχουν ακόμη πακέτα.', 'product-bundles' ) . '</p></div>';
			return;
		}
		echo '<p><label for="pbn-order-bundle">' . esc_html__( 'Πακέτο', 'product-bundles' ) . '</label><br /><select id="pbn-order-bundle" class="pbn-order-bundle" style="width:100%"><option value="">' . esc_html__( '— Διάλεξε —', 'product-bundles' ) . '</option>';
		foreach ( $ids as $id ) {
			$b = wc_get_product( $id );
			if ( ! $b instanceof PBN_Product ) {
				continue;
			}
			$label = $b->get_name() . ( 'publish' !== $b->get_status() ? ' (' . $b->get_status() . ')' : '' );
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<div class="pbn-order-choices"></div>';
		echo '<p><label for="pbn-order-qty">' . esc_html__( 'Πλήθος πακέτων', 'product-bundles' ) . '</label><br /><input type="number" id="pbn-order-qty" class="pbn-order-qty small-text" min="1" max="99" step="1" value="1" /></p>';
		echo '<p><button type="button" class="button pbn-order-add" disabled>' . esc_html__( 'Προσθήκη πακέτου', 'product-bundles' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Προσθέτει τα προϊόντα του πακέτου με τις τιμές του πακέτου. Το απόθεμα αλλάζει όπως σε κάθε γραμμή, όταν η παραγγελία περάσει σε «Σε επεξεργασία» ή «Ολοκληρωμένη».', 'product-bundles' ) . '</p>';
		echo '<p class="pbn-order-msg" role="status" aria-live="polite"></p>';
		echo '</div>';
	}

	/** @param mixed $hook Admin page hook. */
	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( self::order_screen(), 'shop_order' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'pbn-admin', PBN_URL . 'assets/admin.css', array(), PBN_VERSION );
		wp_enqueue_script( 'pbn-admin-order', PBN_URL . 'assets/admin-order.js', array( 'jquery' ), PBN_VERSION, true );
		wp_localize_script(
			'pbn-admin-order',
			'pbnOrder',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'pbn_order' ),
				'i18n'  => array(
					'error' => __( 'Κάτι πήγε στραβά. Ξαναδοκίμασε.', 'product-bundles' ),
					'added' => __( 'Το πακέτο προστέθηκε.', 'product-bundles' ),
				),
			)
		);
	}

	/** Shared checks of the two AJAX actions; returns the order. */
	private static function ajax_order(): WC_Order {
		check_ajax_referer( 'pbn_order', 'nonce' );
		$oid   = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order = $oid ? wc_get_order( $oid ) : null;
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $oid ) || ! $order->is_editable() ) {
			wp_send_json_error( array( 'message' => __( 'Δεν επιτρέπεται.', 'product-bundles' ) ), 403 );
		}
		return $order;
	}

	/** The bundle posted to an AJAX action. */
	private static function ajax_bundle(): PBN_Product {
		$bid = isset( $_POST['bundle'] ) ? absint( wp_unslash( $_POST['bundle'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked in ajax_order().
		$b   = $bid ? wc_get_product( $bid ) : null;
		if ( ! $b instanceof PBN_Product || 'trash' === $b->get_status() ) {
			wp_send_json_error( array( 'message' => __( 'Το πακέτο δεν βρέθηκε.', 'product-bundles' ) ), 400 );
		}
		return $b;
	}

	/** AJAX: the choice selects of a bundle. */
	public static function ajax_choices(): void {
		self::ajax_order();
		$b    = self::ajax_bundle();
		$html = '';
		foreach ( PBN_Bundle::rows( $b ) as $i => $row ) {
			if ( 'choice' !== $row['m'] ) {
				continue;
			}
			$parent = PBN_Bundle::product( (int) $row['p'] );
			$html  .= '<p><label for="pbn-oc-' . esc_attr( (string) $i ) . '">' . esc_html( $parent ? $parent->get_name() : '#' . $row['p'] ) . '</label><br /><select id="pbn-oc-' . esc_attr( (string) $i ) . '" class="pbn-order-choice" data-row="' . esc_attr( (string) $i ) . '" style="width:100%">';
			foreach ( PBN_Bundle::choices( $row ) as $vid => $v ) {
				$html .= '<option value="' . esc_attr( (string) $vid ) . '">' . esc_html( wc_get_formatted_variation( $v, true, false, false ) ) . '</option>';
			}
			$html .= '</select></p>';
		}
		wp_send_json_success( array( 'html' => $html ) );
	}

	/** AJAX: adds the products of a bundle to the order. */
	public static function ajax_add(): void {
		$order = self::ajax_order();
		$b     = self::ajax_bundle();
		$n     = isset( $_POST['qty'] ) ? absint( wp_unslash( $_POST['qty'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification -- checked in ajax_order().
		$n     = max( 1, min( 99, $n ) );

		$choices = array();
		$raw     = isset( $_POST['choice'] ) && is_array( $_POST['choice'] ) ? wp_unslash( $_POST['choice'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted below.
		foreach ( PBN_Bundle::rows( $b ) as $i => $row ) {
			if ( 'choice' === $row['m'] && isset( $raw[ $i ] ) && is_scalar( $raw[ $i ] ) && ctype_digit( (string) $raw[ $i ] ) ) {
				$choices[ $i ] = (int) $raw[ $i ];
			}
		}

		try {
			$res = self::add_bundle( $order, $b, $choices, $n );
		} catch ( \Throwable $e ) {
			$res = $e->getMessage();
		}
		if ( true !== $res ) {
			wp_send_json_error( array( 'message' => is_string( $res ) && '' !== $res ? $res : __( 'Κάτι πήγε στραβά. Ξαναδοκίμασε.', 'product-bundles' ) ), 400 );
		}
		wp_send_json_success();
	}

	/**
	 * Adds $n bundles to an order: its products with the bundle prices.
	 *
	 * @param WC_Order    $order   Order.
	 * @param PBN_Product $b       Bundle.
	 * @param array       $choices row => variation id.
	 * @param int         $n       Number of bundles.
	 * @return true|string True or an error message.
	 */
	public static function add_bundle( WC_Order $order, PBN_Product $b, array $choices, int $n ) {
		PBN_Bundle::flush_products();
		$plan = PBN_Cart::plan( $b, $choices, $n );
		if ( ! $plan['ok'] ) {
			return wp_strip_all_tags( html_entity_decode( $plan['error'], ENT_QUOTES ) );
		}
		$q = PBN_Bundle::quote( $b, $choices );
		if ( ! $q['ok'] ) {
			return __( 'Το πακέτο δεν έχει τιμή.', 'product-bundles' );
		}
		$by_row = array();
		foreach ( $q['rows'] as $qr ) {
			$by_row[ (int) $qr['row'] ] = $qr;
		}
		$group = PBN_Cart::group_id( (int) $b->get_id(), $choices );
		$names = array();
		$added = array();
		foreach ( $plan['lines'] as $line ) {
			$p   = $line['product'];
			$qr  = $by_row[ (int) $line['row'] ];
			$qty = (int) $line['qty'];
			$sub = (float) wc_get_price_excluding_tax(
				$p,
				array(
					'qty'   => $qty,
					'price' => $qr['unit'],
				)
			);
			// The order's own item object, so calculate_totals() sees every change.
			$item = new WC_Order_Item_Product();
			$item->set_props(
				array(
					'product'  => $p,
					'quantity' => $qty,
					'subtotal' => $sub,
					'total'    => $sub,
				)
			);
			self::mark(
				$item,
				array(
					'b' => (int) $b->get_id(),
					'g' => $group,
					'r' => (int) $line['row'],
					'n' => (int) $line['per'],
				),
				wp_strip_all_tags( $b->get_name() ),
				(float) $qr['base'],
				(float) $qr['unit'],
				$p
			);
			$order->add_item( $item );
			$added[] = $item;
			$names[] = $p->get_formatted_name();
		}
		$order->calculate_totals( true ); // Taxes from the subtotal (separate price) and the total (share).
		foreach ( $added as $item ) {
			$m = $item->get_meta( self::META, true );
			if ( is_array( $m ) ) {
				$m['pre_t'] = (float) $item->get_total_tax() / max( 1, (float) $item->get_quantity() );
				$item->update_meta_data( self::META, $m );
			}
		}
		$order->save();
		/* translators: 1: bundle name 2: number of bundles 3: products */
		$order->add_order_note( sprintf( __( 'Προστέθηκε το πακέτο «%1$s» × %2$d: %3$s', 'product-bundles' ), $b->get_name(), $n, implode( ', ', $names ) ), 0, true );
		return true;
	}
}
