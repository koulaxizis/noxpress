<?php
/**
 * PKP_Checkout — the store choice and everything the order shows.
 *
 *  - Classic checkout: under the chosen "Store pickup" rate, a list of the
 *    zone's stores with address, hours / details and map link (radio
 *    buttons; one store = shown without a choice). Rendered inside the
 *    order review, so it refreshes with WooCommerce's update_order_review
 *    AJAX (Bible §14.4 exception); the selection survives the refresh
 *    through the posted form (post_data), never through a cookie or the
 *    session.
 *  - Validation: an order with the method needs a store of that zone.
 *  - The chosen store goes on the order's shipping line: a hidden
 *    snapshot (_pkp_store) plus readable meta (store, hours / details),
 *    so the admin order screen shows it even without the plugin.
 *  - Order totals rows (thank-you page, My account, emails): a "Pickup
 *    store" row under "Shipping".
 *  - Shipping address (setting hide_address): hidden in the checkout form
 *    while the method is chosen (small script), ignored on submit, and
 *    hidden on the order and in emails (woocommerce_order_hide_shipping_address).
 *  - Taxes: the method counts as local pickup (woocommerce_local_pickup_methods),
 *    so WooCommerce may tax at the store's base address.
 *  - No-pickup rule (1.1.0): a package with a product of one of the chosen
 *    shipping classes gets no pickup rate (PKP_Method::is_available), and
 *    the cart / checkout totals show why, when the zone offers pickup.
 *    Filter pkp_pickup_allowed.
 *  - Admin New order email (1.1.0): "Pickup at: <store>" above the order
 *    details, so pickup orders stand out.
 *
 * Every callback is wrapped in try/catch (Bible §14.6): on an error the
 * plugin stops for the rest of the request and returns the original value.
 */

defined( 'ABSPATH' ) || exit;

final class PKP_Checkout {

	const META = '_pkp_store';

	/** Set after the first error: the plugin stays out for the rest of the request. */
	private static $broken = false;

	public static function init(): void {
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'packages' ) );
		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_choice' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'drop_shipping_address' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( __CLASS__, 'save' ), 10, 4 );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'totals' ), 10, 2 );
		add_filter( 'woocommerce_order_hide_shipping_address', array( __CLASS__, 'hide_address_methods' ) );
		add_filter( 'woocommerce_local_pickup_methods', array( __CLASS__, 'local_pickup_methods' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render_blocked' ) );
		add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'render_blocked' ) );
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'admin_line' ), 5, 4 );
	}

	/** Records the error and switches the plugin off for this request. */
	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Store Pickup: ' . $e->getMessage() );
		}
	}

	/** True in REST requests (Store API: Cart / Checkout blocks). */
	public static function is_rest(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		return function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request();
	}

	/**
	 * woocommerce_cart_shipping_packages: WooCommerce caches the rates per
	 * package hash. The method depends on test mode (who is looking) and on
	 * the request type (classic or blocks), so both go into the package.
	 */
	public static function packages( $packages ) {
		if ( self::$broken || ! is_array( $packages ) || 'off' === PKP_Settings::get()['mode'] ) {
			return $packages;
		}
		try {
			$ctx = ( self::is_rest() ? 'b' : 'c' ) . ( PKP_Settings::visible() ? '1' : '0' );
			foreach ( $packages as $k => $p ) {
				if ( is_array( $p ) ) {
					$packages[ $k ]['pkp'] = $ctx;
				}
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $packages;
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/** Our method instance for a rate id "nox_store_pickup:12", or null. */
	public static function method_for_rate( string $rate_id ): ?PKP_Method {
		if ( 0 !== strpos( $rate_id, PKP_Method::ID . ':' ) ) {
			return null;
		}
		return self::method( (int) substr( $rate_id, strlen( PKP_Method::ID ) + 1 ) );
	}

	/** Our method instance by instance id, or null. */
	public static function method( int $instance_id ): ?PKP_Method {
		if ( $instance_id <= 0 ) {
			return null;
		}
		$m = WC_Shipping_Zones::get_shipping_method( $instance_id );
		return $m instanceof PKP_Method ? $m : null;
	}

	/** The store id the visitor picked (checkout POST or the AJAX refresh's post_data). */
	private static function posted_store(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its checkout / update_order_review nonces; we only read a whitelisted id.
		if ( isset( $_POST['pkp_store'] ) && is_string( $_POST['pkp_store'] ) ) {
			return sanitize_key( wp_unslash( $_POST['pkp_store'] ) );
		}
		if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			$data = array();
			wp_parse_str( wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only pkp_store is read, sanitized below.
			if ( isset( $data['pkp_store'] ) && is_string( $data['pkp_store'] ) ) {
				return sanitize_key( $data['pkp_store'] );
			}
		}
		// phpcs:enable
		return '';
	}

	/**
	 * The store of a list that the visitor picked; the only store when the
	 * list has one; else null.
	 */
	private static function resolve( array $stores, string $picked ): ?array {
		if ( 1 === count( $stores ) ) {
			return $stores[0];
		}
		foreach ( $stores as $st ) {
			if ( $st['id'] === $picked ) {
				return $st;
			}
		}
		return null;
	}

	/** True when one of the posted / chosen rate ids is ours. */
	private static function has_pickup( $rate_ids ): bool {
		foreach ( (array) $rate_ids as $id ) {
			if ( is_string( $id ) && 0 === strpos( $id, PKP_Method::ID . ':' ) ) {
				return true;
			}
		}
		return false;
	}

	/* =====================================================================
	 * Checkout
	 * =================================================================== */

	/**
	 * woocommerce_after_shipping_rate: the store list under our chosen rate,
	 * on the checkout only (the cart shows just the method).
	 *
	 * @param WC_Shipping_Rate $rate  Rate.
	 * @param int              $index Package index.
	 */
	public static function render_choice( $rate, $index ): void {
		if ( self::$broken || ! $rate instanceof WC_Shipping_Rate || PKP_Method::ID !== $rate->get_method_id() || ! is_checkout() ) {
			return;
		}
		try {
			$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods', array() ) : array();
			if ( ! is_array( $chosen ) || ! isset( $chosen[ $index ] ) || $chosen[ $index ] !== $rate->get_id() ) {
				return;
			}
			$method = self::method( (int) $rate->get_instance_id() );
			$stores = $method ? $method->stores() : array();
			if ( ! $stores ) {
				return;
			}
			$picked = self::resolve( $stores, self::posted_store() );
			$html   = '<div class="pkp-stores" role="radiogroup" aria-label="' . esc_attr( PKP_Settings::text( 't_heading' ) ) . '">';
			$html  .= '<span class="pkp-stores__title">' . esc_html( PKP_Settings::text( 't_heading' ) ) . '</span>';
			foreach ( $stores as $st ) {
				$id    = 'pkp_store_' . $st['id'];
				$html .= '<label class="pkp-store"' . ( 1 === count( $stores ) ? '' : ' for="' . esc_attr( $id ) . '"' ) . '>';
				if ( 1 === count( $stores ) ) {
					$html .= '<input type="hidden" name="pkp_store" id="' . esc_attr( $id ) . '" value="' . esc_attr( $st['id'] ) . '" />';
				} else {
					$html .= '<input type="radio" name="pkp_store" id="' . esc_attr( $id ) . '" value="' . esc_attr( $st['id'] ) . '"' . checked( null !== $picked && $picked['id'] === $st['id'], true, false ) . ' />';
				}
				$html .= '<span class="pkp-store__body">' . self::store_html( $st, PKP_Settings::details( $st ), 'pkp-store' ) . '</span>';
				$html .= '</label>';
			}
			$html .= '</div>';
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped above / in store_html().
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Escaped HTML of one store: name, address, details (line breaks kept),
	 * map link.
	 *
	 * @param array  $st      Store (name, address, map).
	 * @param string $details Details in the wanted language.
	 * @param string $cls     Class prefix.
	 */
	public static function store_html( array $st, string $details, string $cls ): string {
		$out = '<span class="' . esc_attr( $cls ) . '__name">' . esc_html( $st['name'] ) . '</span>';
		if ( '' !== $st['address'] ) {
			$out .= '<span class="' . esc_attr( $cls ) . '__addr">' . esc_html( $st['address'] ) . '</span>';
		}
		if ( '' !== $details ) {
			$out .= '<span class="' . esc_attr( $cls ) . '__det">' . nl2br( esc_html( $details ), false ) . '</span>';
		}
		if ( '' !== $st['map'] ) {
			$out .= '<a class="' . esc_attr( $cls ) . '__map" href="' . esc_url( $st['map'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( PKP_Settings::text( 't_map' ) ) . '</a>';
		}
		return $out;
	}

	/**
	 * woocommerce_checkout_process: with hide_address on and our method
	 * chosen, the shipping address is not used — WooCommerce then copies
	 * the billing address, as with "ship to a different address" unticked.
	 * The script does the same in the form; this covers browsers without it.
	 */
	public static function drop_shipping_address(): void {
		if ( self::$broken || ! PKP_Settings::get()['hide_address'] ) {
			return;
		}
		try {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this action.
			$methods = isset( $_POST['shipping_method'] ) ? wc_clean( wp_unslash( $_POST['shipping_method'] ) ) : array();
			if ( self::has_pickup( $methods ) ) {
				unset( $_POST['ship_to_different_address'] );
			}
			// phpcs:enable
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_after_checkout_validation: our method needs a store of its zone.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ): void {
		if ( self::$broken || ! is_array( $data ) || ! $errors instanceof WP_Error || empty( $data['shipping_method'] ) ) {
			return;
		}
		try {
			foreach ( (array) $data['shipping_method'] as $rate_id ) {
				$method = is_string( $rate_id ) ? self::method_for_rate( $rate_id ) : null;
				if ( ! $method ) {
					continue;
				}
				if ( null === self::resolve( $method->stores(), self::posted_store() ) ) {
					$errors->add( 'shipping', PKP_Settings::text( 't_required' ) );
					return;
				}
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_checkout_create_order_shipping_item: the store on the
	 * shipping line (also in Store API checkouts, where only a single
	 * store is possible).
	 *
	 * @param WC_Order_Item_Shipping $item        Shipping line.
	 * @param int|string             $package_key Package key.
	 * @param array                  $package     Package.
	 * @param WC_Order               $order       Order.
	 */
	public static function save( $item, $package_key, $package, $order ): void {
		if ( self::$broken || ! $item instanceof WC_Order_Item_Shipping || PKP_Method::ID !== $item->get_method_id() ) {
			return;
		}
		try {
			$method = self::method( (int) $item->get_instance_id() );
			$store  = $method ? self::resolve( $method->stores(), self::posted_store() ) : null;
			if ( null === $store ) {
				return;
			}
			$details = PKP_Settings::details( $store );
			$item->add_meta_data(
				self::META,
				array(
					'id'      => $store['id'],
					'name'    => $store['name'],
					'address' => $store['address'],
					'details' => $details,
					'map'     => $store['map'],
				),
				true
			);
			// Readable copies (the meta key is the label), shown on the admin order screen.
			$item->add_meta_data( PKP_Settings::text( 't_heading' ), trim( $store['name'] . ( '' !== $store['address'] ? ', ' . $store['address'] : '' ) ), true );
			if ( '' !== $details ) {
				$item->add_meta_data( PKP_Settings::text( 't_details' ), $details, true );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * The saved store of an order, or null (public API pkp_get_order_store()).
	 *
	 * @param int|WC_Order $order Order or order ID.
	 */
	public static function order_store( $order ): ?array {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$snap = $item->get_meta( self::META, true );
			if ( is_array( $snap ) && isset( $snap['name'] ) && is_string( $snap['name'] ) ) {
				$out = array();
				foreach ( array( 'id', 'name', 'address', 'details', 'map' ) as $k ) {
					$out[ $k ] = isset( $snap[ $k ] ) && is_string( $snap[ $k ] ) ? $snap[ $k ] : '';
				}
				return $out;
			}
		}
		return null;
	}

	/**
	 * woocommerce_get_order_item_totals: a "Pickup store" row after
	 * "Shipping" (thank-you page, My account, emails).
	 *
	 * @param array    $rows  Totals rows.
	 * @param WC_Order $order Order.
	 */
	public static function totals( $rows, $order ) {
		if ( self::$broken || ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $rows;
		}
		try {
			$store = self::order_store( $order );
			if ( null === $store ) {
				return $rows;
			}
			$row = array(
				'pkp_store' => array(
					'label' => PKP_Settings::text( 't_heading' ) . ':',
					'value' => str_replace( '</span><', "</span><br />\n<", self::store_html( $store, $store['details'], 'pkp-order-store' ) ),
				),
			);
			$at  = array_search( 'shipping', array_keys( $rows ), true );
			if ( false === $at ) {
				return $rows + $row;
			}
			return array_slice( $rows, 0, $at + 1, true ) + $row + array_slice( $rows, $at + 1, null, true );
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $rows;
		}
	}

	/** woocommerce_order_hide_shipping_address: our method, when hide_address is on. */
	public static function hide_address_methods( $methods ) {
		if ( ! self::$broken && is_array( $methods ) && PKP_Settings::get()['hide_address'] ) {
			$methods[] = PKP_Method::ID;
		}
		return $methods;
	}

	/** woocommerce_local_pickup_methods: taxes at the store's base address (WooCommerce's own setting decides). */
	public static function local_pickup_methods( $methods ) {
		if ( ! self::$broken && is_array( $methods ) ) {
			$methods[] = PKP_Method::ID;
		}
		return $methods;
	}

	/* =====================================================================
	 * No-pickup rule
	 * =================================================================== */

	/**
	 * True when a package may get the pickup rate: the rule is off, or none
	 * of its products has one of the rule's shipping classes. Variations
	 * without a class of their own use the parent's (WooCommerce does).
	 *
	 * @param array $package Shipping package (contents with 'data' => WC_Product).
	 */
	public static function pickup_allowed( array $package ): bool {
		$allowed = true;
		$rule    = PKP_Settings::get()['no_pickup'];
		if ( $rule['on'] && $rule['printful'] && ! empty( $package['printful'] ) ) {
			$allowed = false; // The package the Printful plugin split off: sent by the partner.
		} elseif ( $rule['on'] && $rule['classes'] && isset( $package['contents'] ) && is_array( $package['contents'] ) ) {
			foreach ( $package['contents'] as $item ) {
				$product = is_array( $item ) && isset( $item['data'] ) ? $item['data'] : null;
				if ( $product instanceof WC_Product && in_array( (string) $product->get_shipping_class(), $rule['classes'], true ) ) {
					$allowed = false;
					break;
				}
			}
		}
		/**
		 * Filters whether a shipping package may get the "Store pickup" rate.
		 *
		 * @param bool  $allowed False when the no-pickup rule applies.
		 * @param array $package Shipping package.
		 */
		return (bool) apply_filters( 'pkp_pickup_allowed', $allowed, $package );
	}

	/** True when the zone of a package has an enabled pickup method with stores. */
	private static function zone_offers_pickup( array $package ): bool {
		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
		foreach ( $zone->get_shipping_methods( true ) as $m ) {
			if ( $m instanceof PKP_Method && $m->stores() ) {
				return true;
			}
		}
		return false;
	}

	/** True when a calculated package offers the pickup rate. */
	private static function has_pickup_rate( array $package ): bool {
		foreach ( isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array() as $rate ) {
			if ( $rate instanceof WC_Shipping_Rate && PKP_Method::ID === $rate->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * woocommerce_review_order_after_shipping / woocommerce_cart_totals_after_shipping:
	 * why pickup is missing, once, when the rule removed it from a package
	 * whose zone offers it and no other package of the cart offers it. In
	 * a mixed cart (partner parcel + own parcel) pickup stays in the own
	 * parcel, so there is nothing to explain. Inside the totals table, so
	 * it refreshes with the checkout AJAX like the rates.
	 */
	public static function render_blocked(): void {
		if ( self::$broken || ! PKP_Settings::visible() || ! PKP_Settings::get()['no_pickup']['on'] || ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return;
		}
		try {
			$packages = array_filter( (array) WC()->shipping()->get_packages(), 'is_array' );
			$blocked  = false;
			foreach ( $packages as $package ) {
				if ( self::has_pickup_rate( $package ) ) {
					return;
				}
				if ( ! $blocked && ! self::pickup_allowed( $package ) && self::zone_offers_pickup( $package ) ) {
					$blocked = true;
				}
			}
			$text = $blocked ? PKP_Settings::text( 't_blocked' ) : '';
			if ( '' !== $text ) {
				echo '<tr class="pkp-blocked"><td colspan="2"><p class="pkp-blocked__text">' . esc_html( $text ) . '</p></td></tr>';
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Admin email
	 * =================================================================== */

	/**
	 * woocommerce_email_order_details (priority 5): "Pickup at: <store>" at
	 * the top of the admin New order email.
	 *
	 * @param WC_Order      $order         Order.
	 * @param bool          $sent_to_admin Sent to the admin.
	 * @param bool          $plain_text    Plain text email.
	 * @param WC_Email|null $email         Email.
	 */
	public static function admin_line( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( self::$broken || ! $sent_to_admin || ! $order instanceof WC_Order || ! PKP_Settings::get()['admin_line'] ) {
			return;
		}
		if ( ! is_object( $email ) || ! isset( $email->id ) || 'new_order' !== $email->id ) {
			return;
		}
		try {
			$store = self::order_store( $order );
			if ( null === $store ) {
				return;
			}
			$line = PKP_Settings::text( 't_admin' ) . ': ' . $store['name'];
			if ( $plain_text ) {
				echo esc_html( $line ) . "\n\n";
			} else {
				echo '<p class="pkp-admin-line" style="font-size:1.15em;margin:0 0 16px;"><strong>' . esc_html( $line ) . '</strong></p>';
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Assets (checkout page only, when the method can show)
	 * =================================================================== */

	public static function assets(): void {
		if ( self::$broken || ! function_exists( 'is_checkout' ) || ! ( is_checkout() || is_cart() ) || is_order_received_page() || ! PKP_Settings::visible() ) {
			return;
		}
		try {
			wp_register_style( 'pkp-front', false, array(), PKP_VERSION );
			wp_enqueue_style( 'pkp-front' );
			wp_add_inline_style( 'pkp-front', self::css() );
			if ( is_checkout() && PKP_Settings::get()['hide_address'] ) {
				wp_enqueue_script( 'pkp-checkout', PKP_URL . 'assets/checkout.js', array( 'jquery' ), PKP_VERSION, true );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** Scoped CSS that survives theme rules (Bible §14.13). */
	private static function css(): string {
		return '.pkp-stores,.pkp-stores span,.pkp-stores label{float:none!important;box-shadow:none!important;text-transform:none!important;letter-spacing:normal!important}'
			. '.pkp-stores{display:block!important;margin:.6em 0 .2em!important;padding:0!important;border:0!important;background:none!important;text-align:start!important}'
			. '.pkp-stores .pkp-stores__title{display:block!important;margin:0 0 .4em!important;font-weight:600!important}'
			. '.pkp-stores .pkp-store{display:flex!important;align-items:flex-start!important;gap:.6em!important;margin:0 0 .5em!important;padding:.55em .7em!important;border:1px solid rgba(127,127,127,.4)!important;border-radius:4px!important;background:none!important;font-weight:400!important;line-height:1.4!important;cursor:pointer;width:auto!important}'
			. '.pkp-stores .pkp-store input[type="radio"]{flex:0 0 auto!important;margin:.25em 0 0!important}'
			. '.pkp-stores .pkp-store__body{display:block!important;min-width:0!important;overflow-wrap:break-word;word-break:normal!important}'
			. '.pkp-stores .pkp-store__name{display:block!important;font-weight:600!important}'
			. '.pkp-stores .pkp-store__addr,.pkp-stores .pkp-store__det{display:block!important;font-size:.9em!important;opacity:.85}'
			. '.pkp-stores .pkp-store__map{display:inline-block!important;font-size:.9em!important;margin-top:.15em!important}'
			. '.pkp-blocked td{padding-top:.4em!important;padding-bottom:.4em!important}'
			. '.pkp-blocked .pkp-blocked__text{margin:0!important;font-size:.9em!important;line-height:1.45!important;text-align:start!important;font-weight:400!important}';
	}
}
