<?php
/**
 * ACL_Watch_WC — WooCommerce events, through the CRUD hooks, so the same
 * code sees the admin, quick / bulk edit, REST, WP-CLI, Site Bridge and
 * other plugins, with HPOS on or off.
 *
 *  products  created; changed fields from get_changes() before the save
 *            (name, SKU, prices, sale dates, stock, stock status,
 *            visibility, categories, tags, attributes as «changed» …).
 *            Stock that an order changes is left out unless «Stock from
 *            orders» is on: the order explains it.
 *  orders    status changes, with who changed it (staff, the customer,
 *            a payment gateway or cron); refunds; deletion; changes from
 *            the admin (totals, items, payment method; customer details
 *            only as «changed»). New checkout orders only with «New
 *            orders» on. Orders are «#number», never a name.
 *  coupons   created, changed (amount, type, expiry, limits …), deleted
 *            (trash / delete come from ACL_Watch through the post hooks).
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Watch_WC {

	/** Product fields never written as values (big or noisy). */
	const PRODUCT_SKIP = array( 'date_modified', 'rating_counts', 'average_rating', 'review_count', 'total_sales', 'date_on_sale_from_gmt', 'meta_data', 'default_attributes', 'download_expiry', 'download_limit', 'reviews_allowed', 'purchase_note' );

	/** Product fields shown as «changed» only. */
	const PRODUCT_TEXT = array( 'description', 'short_description', 'attributes', 'downloads', 'gallery_image_ids', 'image_id', 'upsell_ids', 'cross_sell_ids' );

	/** Order fields that may be written as values. */
	const ORDER_FIELDS = array( 'currency', 'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total', 'total_tax', 'payment_method', 'payment_method_title', 'date_created', 'date_paid', 'date_completed', 'customer_id', 'created_via', 'prices_include_tax' );

	/** @var bool Stock is being changed by an order in this request. */
	private static $order_stock = false;

	public static function init(): void {
		add_action(
			'woocommerce_loaded',
			static function () {
				self::hooks();
			}
		);
		if ( did_action( 'woocommerce_loaded' ) ) {
			self::hooks();
		}
	}

	private static function hooks(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		add_action( 'woocommerce_new_product', array( __CLASS__, 'product_created' ), 10, 2 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'product_created' ), 10, 2 );
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'product_saving' ), 10, 1 );
		add_action( 'woocommerce_before_product_variation_object_save', array( __CLASS__, 'product_saving' ), 10, 1 );

		// Stock moved by an order (reduce, restore, line item edit).
		add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'order_stock_on' ), 999 );
		add_filter( 'woocommerce_can_restore_order_stock', array( __CLASS__, 'order_stock_on' ), 999 );
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', array( __CLASS__, 'order_stock_on' ), 999 );
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'order_stock_off' ), 999 );
		add_action( 'woocommerce_restore_order_stock', array( __CLASS__, 'order_stock_off' ), 999 );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'order_status' ), 10, 4 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'order_created' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'order_refunded' ), 10, 2 );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'order_saving' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'order_deleted' ), 10, 2 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'order_trashed' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'order_post_deleted' ), 10, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'order_post_trashed' ), 10, 1 );

		add_action( 'woocommerce_new_coupon', array( __CLASS__, 'coupon_created' ), 10, 2 );
		// WC_Coupon has no object type of its own: its save fires the generic «data» hook.
		add_action( 'woocommerce_before_coupon_object_save', array( __CLASS__, 'coupon_saving' ), 10, 1 );
		add_action( 'woocommerce_before_data_object_save', array( __CLASS__, 'coupon_saving' ), 10, 1 );
	}

	private static function safe( callable $fn ): void {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Products
	 * =================================================================== */

	public static function order_stock_on( $value ) {
		self::$order_stock = true;
		return $value;
	}

	public static function order_stock_off(): void {
		self::$order_stock = false;
	}

	public static function product_created( $id, $product = null ): void {
		self::safe(
			static function () use ( $id, $product ) {
				$product = $product instanceof WC_Product ? $product : wc_get_product( (int) $id );
				if ( ! $product || 'auto-draft' === get_post_status( (int) $id ) ) {
					return;
				}
				$d = array(
					'type' => array( 't' => (string) $product->get_type() ),
				);
				if ( '' !== (string) $product->get_regular_price() ) {
					$d['regular_price'] = array( 't' => (string) $product->get_regular_price() );
				}
				ACL_Store::add(
					array(
						'category'    => 'products',
						'action'      => 'product.created',
						'object_type' => 'product',
						'object_id'   => (int) $id,
						'label'       => (string) $product->get_name(),
						'details'     => $d,
					)
				);
			}
		);
	}

	/** Field changes, read before WooCommerce writes them. */
	public static function product_saving( $product ): void {
		self::safe(
			static function () use ( $product ) {
				if ( ! $product instanceof WC_Product || ! $product->get_id() ) {
					return; // New: product_created records it.
				}
				$changes = $product->get_changes();
				if ( ! $changes ) {
					return;
				}
				$from_order = self::$order_stock && ! ACL_Settings::on( 'stock_orders' );
				$old        = wc_get_product( $product->get_id() );
				$d          = array();
				foreach ( $changes as $field => $new ) {
					$field = (string) $field;
					if ( in_array( $field, self::PRODUCT_SKIP, true ) ) {
						continue;
					}
					if ( $from_order && in_array( $field, array( 'stock_quantity', 'stock_status' ), true ) ) {
						continue;
					}
					$getter = 'get_' . $field;
					$before = $old && is_callable( array( $old, $getter ) ) ? $old->{$getter}( 'edit' ) : null;
					if ( in_array( $field, self::PRODUCT_TEXT, true ) ) {
						if ( wp_json_encode( $before ) !== wp_json_encode( $new ) ) {
							$d[ $field ] = array( 't' => 'changed' );
						}
						continue;
					}
					if ( in_array( $field, array( 'category_ids', 'tag_ids', 'shipping_class_id' ), true ) ) {
						$before = self::term_names( (array) $before, 'shipping_class_id' === $field ? 'product_shipping_class' : ( 'tag_ids' === $field ? 'product_tag' : 'product_cat' ) );
						$new    = self::term_names( (array) $new, 'shipping_class_id' === $field ? 'product_shipping_class' : ( 'tag_ids' === $field ? 'product_tag' : 'product_cat' ) );
					}
					ACL_Redact::diff( $d, $field, $before, $new );
				}
				if ( ! $d ) {
					return;
				}
				$sev = isset( $d['regular_price'] ) || isset( $d['sale_price'] ) || isset( $d['status'] ) ? 'notice' : 'info';
				ACL_Store::add(
					array(
						'category'    => 'products',
						'action'      => 'product.updated',
						'object_type' => 'product',
						'object_id'   => $product->get_id(),
						'label'       => (string) $product->get_name(),
						'details'     => $d,
						'severity'    => $sev,
					)
				);
			}
		);
	}

	private static function term_names( array $ids, string $tax ): string {
		$names = array();
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			$t       = get_term( $id, $tax );
			$names[] = $t instanceof WP_Term ? $t->name : '#' . $id;
		}
		sort( $names );
		return implode( ', ', $names );
	}

	/* =====================================================================
	 * Orders
	 * =================================================================== */

	private static function order_label( $order ): string {
		return $order instanceof WC_Abstract_Order && is_callable( array( $order, 'get_order_number' ) ) ? '#' . $order->get_order_number() : '';
	}

	/**
	 * Who moved the order: staff (the user), the customer on the front, a
	 * payment gateway callback (no user, not cron) or the system.
	 */
	private static function order_actor( $order ): ?array {
		$uid = get_current_user_id();
		if ( $uid > 0 ) {
			return null; // The current user, as usual.
		}
		$ctx = ACL_Context::context();
		if ( in_array( $ctx, array( 'front', 'checkout' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared only.
			if ( isset( $_GET['wc-api'] ) || ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], 'wc-api' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				return array( 0, 'gateway', '' );
			}
			return array( 0, 'visitor', '' );
		}
		if ( 'rest' === $ctx ) {
			return array( 0, 'gateway', '' );
		}
		return array( 0, 'system', '' );
	}

	public static function order_status( $id, $from, $to, $order = null ): void {
		self::safe(
			static function () use ( $id, $from, $to, $order ) {
				$order = $order instanceof WC_Abstract_Order ? $order : wc_get_order( (int) $id );
				if ( ! $order || 'shop_order_refund' === $order->get_type() ) {
					return;
				}
				$d = array();
				ACL_Redact::diff( $d, 'status', wc_get_order_status_name( (string) $from ), wc_get_order_status_name( (string) $to ) );
				$e = array(
					'category'    => 'orders',
					'action'      => 'order.status',
					'object_type' => 'order',
					'object_id'   => (int) $id,
					'label'       => self::order_label( $order ),
					'details'     => $d,
					'severity'    => in_array( (string) $to, array( 'cancelled', 'refunded', 'failed' ), true ) ? 'notice' : 'info',
				);
				$a = self::order_actor( $order );
				if ( null !== $a ) {
					$e['actor'] = $a;
				}
				ACL_Store::add( $e );
			}
		);
	}

	public static function order_created( $id, $order = null ): void {
		self::safe(
			static function () use ( $id, $order ) {
				$order    = $order instanceof WC_Abstract_Order ? $order : wc_get_order( (int) $id );
				$by_staff = ACL_Context::by_staff_tools();
				if ( ! $order || ( ! $by_staff && ! ACL_Settings::on( 'orders_new' ) ) ) {
					return;
				}
				$d = array(
					'total'       => array( 't' => (string) $order->get_total() . ' ' . (string) $order->get_currency() ),
					'created_via' => array( 't' => (string) $order->get_created_via() ),
				);
				$e = array(
					'category'    => $by_staff ? 'orders' : 'orders_new',
					'action'      => 'order.created',
					'object_type' => 'order',
					'object_id'   => (int) $id,
					'label'       => self::order_label( $order ),
					'details'     => $d,
				);
				$a = self::order_actor( $order );
				if ( null !== $a ) {
					$e['actor'] = $a;
				}
				ACL_Store::add( $e );
			}
		);
	}

	public static function order_refunded( $id, $refund_id ): void {
		self::safe(
			static function () use ( $id, $refund_id ) {
				$order  = wc_get_order( (int) $id );
				$refund = wc_get_order( (int) $refund_id );
				if ( ! $order ) {
					return;
				}
				$d = array();
				if ( $refund instanceof WC_Order_Refund ) {
					$d['amount'] = array( 't' => (string) $refund->get_amount() . ' ' . (string) $order->get_currency() );
					if ( '' !== (string) $refund->get_reason() ) {
						$d['reason'] = array( 't' => ACL_Redact::scalar( $refund->get_reason() ) );
					}
					$d['by_gateway'] = array( 't' => $refund->get_refunded_payment() ? '1' : '0' );
				}
				ACL_Store::add(
					array(
						'category'    => 'orders',
						'action'      => 'order.refunded',
						'object_type' => 'order',
						'object_id'   => (int) $id,
						'label'       => self::order_label( $order ),
						'details'     => $d,
						'severity'    => 'warning',
					)
				);
			}
		);
	}

	/** Changes from the admin (or REST / Site Bridge); never from checkout. */
	public static function order_saving( $order ): void {
		self::safe(
			static function () use ( $order ) {
				if ( ! $order instanceof WC_Order || ! $order->get_id() || ! ACL_Context::by_staff_tools() ) {
					return;
				}
				$changes = $order->get_changes();
				if ( ! $changes ) {
					return;
				}
				$old = wc_get_order( $order->get_id() );
				$d   = array();
				foreach ( $changes as $field => $new ) {
					$field = (string) $field;
					if ( 'status' === $field || 'date_modified' === $field || 'version' === $field || 'meta_data' === $field ) {
						continue; // Status has its own row.
					}
					if ( isset( $changes['status'] ) && ( 'date_paid' === $field || 'date_completed' === $field ) ) {
						continue; // Set by WooCommerce with the status change.
					}
					if ( in_array( $field, array( 'billing', 'shipping', 'customer_note', 'customer_ip_address', 'customer_user_agent' ), true ) ) {
						$d[ 'billing' === $field || 'shipping' === $field ? $field : 'customer' ] = array( 'h' => 1 );
						continue;
					}
					if ( ! in_array( $field, self::ORDER_FIELDS, true ) ) {
						continue;
					}
					$getter = 'get_' . $field;
					$before = $old && is_callable( array( $old, $getter ) ) ? $old->{$getter}( 'edit' ) : null;
					ACL_Redact::diff( $d, $field, $before, $new );
				}
				if ( ! $d ) {
					return;
				}
				ACL_Store::add(
					array(
						'category'    => 'orders',
						'action'      => 'order.updated',
						'object_type' => 'order',
						'object_id'   => $order->get_id(),
						'label'       => self::order_label( $order ),
						'details'     => $d,
						'severity'    => isset( $d['total'] ) ? 'notice' : 'info',
					)
				);
			}
		);
	}

	public static function order_deleted( $id, $order = null ): void {
		self::safe(
			static function () use ( $id, $order ) {
				$order = $order instanceof WC_Abstract_Order ? $order : wc_get_order( (int) $id );
				if ( $order && 'shop_order_refund' === $order->get_type() ) {
					return;
				}
				ACL_Store::add(
					array(
						'category'    => 'orders',
						'action'      => 'order.deleted',
						'object_type' => 'order',
						'object_id'   => (int) $id,
						'label'       => $order ? self::order_label( $order ) : '#' . (int) $id,
						'severity'    => 'warning',
					)
				);
			}
		);
	}

	public static function order_trashed( $id ): void {
		self::safe(
			static function () use ( $id ) {
				$order = wc_get_order( (int) $id );
				ACL_Store::add(
					array(
						'category'    => 'orders',
						'action'      => 'order.trashed',
						'object_type' => 'order',
						'object_id'   => (int) $id,
						'label'       => $order ? self::order_label( $order ) : '#' . (int) $id,
						'severity'    => 'notice',
					)
				);
			}
		);
	}

	/** Orders stored as posts (HPOS off): the post hooks. Merged with the WC hooks by key. */
	public static function order_post_deleted( $id, $post = null ): void {
		if ( $post instanceof WP_Post && 'shop_order' === $post->post_type ) {
			self::order_deleted( (int) $id );
		}
	}

	public static function order_post_trashed( $id ): void {
		if ( 'shop_order' === get_post_type( (int) $id ) ) {
			self::order_trashed( (int) $id );
		}
	}

	/* =====================================================================
	 * Coupons
	 * =================================================================== */

	public static function coupon_created( $id, $coupon = null ): void {
		self::safe(
			static function () use ( $id, $coupon ) {
				$coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon( (int) $id );
				ACL_Store::add(
					array(
						'category'    => 'coupons',
						'action'      => 'coupon.created',
						'object_type' => 'coupon',
						'object_id'   => (int) $id,
						'label'       => (string) $coupon->get_code(),
						'details'     => array(
							'discount_type' => array( 't' => (string) $coupon->get_discount_type() ),
							'amount'        => array( 't' => (string) $coupon->get_amount() ),
						),
						'severity'    => 'notice',
					)
				);
			}
		);
	}

	public static function coupon_saving( $coupon ): void {
		self::safe(
			static function () use ( $coupon ) {
				if ( ! $coupon instanceof WC_Coupon || ! $coupon->get_id() ) {
					return;
				}
				$changes = $coupon->get_changes();
				if ( ! $changes ) {
					return;
				}
				$old = new WC_Coupon( $coupon->get_id() );
				$d   = array();
				foreach ( $changes as $field => $new ) {
					$field = (string) $field;
					if ( in_array( $field, array( 'date_modified', 'usage_count', 'used_by', 'meta_data', 'virtual' ), true ) ) {
						continue;
					}
					if ( 'email_restrictions' === $field ) {
						$d[ $field ] = array( 'h' => 1 ); // E-mails of customers.
						continue;
					}
					$getter = 'get_' . $field;
					$before = is_callable( array( $old, $getter ) ) ? $old->{$getter}( 'edit' ) : null;
					if ( is_array( $before ) || is_array( $new ) ) {
						$before = implode( ', ', array_map( 'strval', (array) $before ) );
						$new    = implode( ', ', array_map( 'strval', (array) $new ) );
					}
					ACL_Redact::diff( $d, $field, $before, $new );
				}
				if ( ! $d ) {
					return;
				}
				ACL_Store::add(
					array(
						'category'    => 'coupons',
						'action'      => 'coupon.updated',
						'object_type' => 'coupon',
						'object_id'   => $coupon->get_id(),
						'label'       => (string) $coupon->get_code(),
						'details'     => $d,
						'severity'    => isset( $d['amount'] ) ? 'notice' : 'info',
					)
				);
			}
		);
	}
}
