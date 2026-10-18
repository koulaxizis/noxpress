<?php
/**
 * SHN_Parcels — carts that WooCommerce ships in several packages (1.1.0).
 *
 * A plugin such as Printful (print on demand) splits the cart into its own
 * package, sent by the partner, and a package with the store's own
 * products. WooCommerce then shows "Shipping 1" / "Shipping 2" with two
 * charges and no explanation. This part:
 *  - names the parcels ("Parcel 1 · Made to order (from our production
 *    partner)", "Parcel 2 · From our store"): woocommerce_cart_shipping_packages
 *    (priority 20000, after the split) marks each package with its position
 *    and partner flag, woocommerce_shipping_package_name reads the marks
 *    (WooCommerce 11 names the packages while it builds them, before any
 *    rates exist);
 *  - prints a "two parcels" notice above the shipping rows of the classic
 *    cart and checkout (woocommerce_cart_totals_before_shipping /
 *    woocommerce_review_order_before_shipping, inside the totals table, so
 *    it refreshes with WooCommerce's own AJAX);
 *  - translates the partner's rate labels for display ("Flat Rate
 *    (Estimated delivery: Oct 20–22)" → "Τυπική αποστολή (Εκτιμώμενη
 *    παράδοση: 20–22 Οκτ)") — woocommerce_cart_shipping_method_full_label
 *    and woocommerce_order_shipping_method. The stored rate label and the
 *    order data stay as the partner sent them, so the partner's order
 *    import (it reads the order over the REST API) sees what it expects;
 *  - saves the parcel name on each shipping line of the order (meta
 *    shn_parcel, shown in the admin order screen) and lists the parcels
 *    under the order table on the thank-you page, in My account and in
 *    the customer emails (HTML and plain text);
 *  - optionally prints a line under the price of partner products.
 *
 * Signals (Bible §1.1, no other plugin's options are read): the
 * $package['printful'] flag of the Printful plugin's package, and the
 * shipping classes chosen in the settings. Filters shn_partner_package,
 * shn_partner_rate and shn_partner_product change them.
 *
 * Rules (Bible §14): off until enabled, test mode for shop managers,
 * SHN_DISABLE (not initialised); the cart parts run on page views and on
 * WooCommerce's cart AJAX only; the only write is the meta on the shipping
 * lines of an order WooCommerce is creating; every callback in try/catch.
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Parcels {

	const META = 'shn_parcel';

	/** English month abbreviations => Greek ones. */
	const MONTHS = array(
		'jan' => 'Ιαν',
		'feb' => 'Φεβ',
		'mar' => 'Μαρ',
		'apr' => 'Απρ',
		'may' => 'Μαΐ',
		'jun' => 'Ιουν',
		'jul' => 'Ιουλ',
		'aug' => 'Αυγ',
		'sep' => 'Σεπ',
		'oct' => 'Οκτ',
		'nov' => 'Νοε',
		'dec' => 'Δεκ',
	);

	/** @var bool Stops the part for the rest of the request after an error. */
	private static $broken = false;

	/** @var bool The notice is printed once per request. */
	private static $notice_done = false;

	/** @var bool The product hint is printed once per request. */
	private static $hint_done = false;

	public static function init(): void {
		$s = SHN_Settings::get();
		if ( 'off' === $s['mx_mode'] ) {
			return;
		}
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'mark' ), 20000 );
		add_filter( 'woocommerce_shipping_package_name', array( __CLASS__, 'package_name' ), 20, 3 );
		add_action( 'woocommerce_cart_totals_before_shipping', array( __CLASS__, 'print_notice' ), 5 );
		add_action( 'woocommerce_review_order_before_shipping', array( __CLASS__, 'print_notice' ), 5 );
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( __CLASS__, 'full_label' ), 20, 2 );
		add_filter( 'woocommerce_order_shipping_method', array( __CLASS__, 'order_shipping_method' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( __CLASS__, 'save_line' ), 10, 4 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'meta_key_label' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'print_order' ), 20 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'print_email' ), 20, 4 );
		if ( $s['mx_hint'] ) {
			add_action( 'wp', array( __CLASS__, 'setup_product' ) );
		}
	}

	private static function on(): bool {
		return ! self::$broken && SHN_Front::visible( 'mx' );
	}

	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Shipping Notes (parcels): ' . $e->getMessage() );
		}
	}

	/* =====================================================================
	 * Signals
	 * =================================================================== */

	/** True when a shipping package is sent by the partner. */
	public static function is_partner_package( array $package ): bool {
		if ( isset( $package['shn_partner'] ) ) {
			return (bool) $package['shn_partner'];
		}
		$s       = SHN_Settings::get();
		$partner = $s['mx_printful'] && ! empty( $package['printful'] );
		if ( ! $partner && $s['mx_classes'] && isset( $package['contents'] ) && is_array( $package['contents'] ) ) {
			foreach ( $package['contents'] as $item ) {
				$product = is_array( $item ) && isset( $item['data'] ) ? $item['data'] : null;
				if ( $product instanceof WC_Product && in_array( (string) $product->get_shipping_class(), $s['mx_classes'], true ) ) {
					$partner = true;
					break;
				}
			}
		}
		/**
		 * Filters whether a shipping package is a partner parcel.
		 *
		 * @param bool  $partner Partner parcel.
		 * @param array $package Shipping package.
		 */
		return (bool) apply_filters( 'shn_partner_package', $partner, $package );
	}

	/** True when a shipping method id is the partner's (its rate labels get translated). */
	public static function is_partner_rate( string $method_id ): bool {
		/**
		 * Filters whether a shipping method id belongs to the partner.
		 *
		 * @param bool   $partner   Default: the id starts with printful_shipping.
		 * @param string $method_id Method id of the rate or the order line.
		 */
		return (bool) apply_filters( 'shn_partner_rate', 0 === strpos( $method_id, 'printful_shipping' ), $method_id );
	}

	/** True when a product is made by the partner (product-page hint). */
	public static function is_partner_product( WC_Product $product ): bool {
		$classes = SHN_Settings::get()['mx_classes'];
		$partner = $classes && in_array( (string) $product->get_shipping_class(), $classes, true );
		/**
		 * Filters whether a product gets the "printed to order" hint.
		 *
		 * @param bool       $partner Default: its shipping class is one of the chosen ones.
		 * @param WC_Product $product Product.
		 */
		return (bool) apply_filters( 'shn_partner_product', $partner, $product );
	}

	/**
	 * The packages of the cart that need shipping, with their partner flag.
	 *
	 * @return array[] { package: array, partner: bool }, keys as WooCommerce has them.
	 */
	private static function packages(): array {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return $out;
		}
		foreach ( (array) WC()->shipping()->get_packages() as $key => $package ) {
			if ( is_array( $package ) && ! empty( $package['contents'] ) ) {
				$out[ $key ] = array(
					'package' => $package,
					'partner' => self::is_partner_package( $package ),
				);
			}
		}
		return $out;
	}

	/** Several packages and at least one of them a partner parcel. */
	private static function split( array $packages ): bool {
		return count( $packages ) > 1 && in_array( true, array_column( $packages, 'partner' ), true );
	}

	/** "Parcel {n} · …" for a package. */
	private static function name_for( bool $partner, int $n ): string {
		return str_replace( '{n}', (string) $n, SHN_Settings::text( $partner ? 'mx_t_partner' : 'mx_t_own' ) );
	}

	/* =====================================================================
	 * Cart and checkout
	 * =================================================================== */

	/**
	 * woocommerce_cart_shipping_packages (after the split): each package
	 * gets its position (shn_n), its partner flag (shn_partner) and whether
	 * the cart is split with a partner parcel (shn_split).
	 *
	 * @param array $packages Packages.
	 */
	public static function mark( $packages ) {
		try {
			if ( ! is_array( $packages ) || ! self::on() ) {
				return $packages;
			}
			$n       = 0;
			$partner = false;
			foreach ( $packages as $k => $package ) {
				if ( is_array( $package ) && ! empty( $package['contents'] ) ) {
					unset( $package['shn_partner'] );
					$packages[ $k ]['shn_n']       = ++$n;
					$packages[ $k ]['shn_partner'] = self::is_partner_package( $package );
					$partner                       = $partner || $packages[ $k ]['shn_partner'];
				}
			}
			foreach ( $packages as $k => $package ) {
				if ( isset( $package['shn_n'] ) ) {
					$packages[ $k ]['shn_split'] = $n > 1 && $partner;
				}
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $packages;
	}

	/**
	 * woocommerce_shipping_package_name (WooCommerce 11: name, package id,
	 * package, total; older: name, index, package).
	 *
	 * @param string     $name    Default name ("Shipment 2").
	 * @param int|string $i       Package id or index.
	 * @param array      $package Package.
	 */
	public static function package_name( $name, $i = 0, $package = array() ) {
		try {
			if ( ! self::on() || ! SHN_Settings::get()['mx_names'] || ! is_array( $package ) || empty( $package['shn_split'] ) || empty( $package['shn_n'] ) ) {
				return $name;
			}
			$text = self::name_for( ! empty( $package['shn_partner'] ), (int) $package['shn_n'] );
			return '' === $text ? $name : $text;
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $name;
		}
	}

	/** The "two parcels" notice row, when a partner parcel and an own parcel ship together. */
	public static function print_notice(): void {
		try {
			if ( self::$notice_done || ! self::on() || ! SHN_Settings::get()['mx_notice'] ) {
				return;
			}
			$packages = self::packages();
			if ( ! self::split( $packages ) || ! in_array( false, array_column( $packages, 'partner' ), true ) ) {
				return;
			}
			$text = SHN_Settings::text( 'mx_t_notice' );
			if ( '' === $text ) {
				return;
			}
			self::$notice_done = true;
			SHN_Front::css();
			$color = SHN_Settings::get()['mx_color'];
			$style = '' !== $color ? ' style="--shn-mx-color:' . esc_attr( $color ) . '"' : '';
			echo '<tr class="shn-mx-row"><td colspan="2"><div class="shn-mx" role="note"' . $style . '><p class="shn-mx__text">' . esc_html( $text ) . '</p></div></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $style is escaped above.
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_cart_shipping_method_full_label: the partner's label in
	 * the visitor language (display only; the rate keeps its label).
	 *
	 * @param string           $label Label with the cost.
	 * @param WC_Shipping_Rate $rate  Rate.
	 */
	public static function full_label( $label, $rate = null ) {
		try {
			if ( ! is_string( $label ) || ! $rate instanceof WC_Shipping_Rate || ! self::on() || ! SHN_Settings::get()['mx_labels'] || ! self::is_partner_rate( (string) $rate->get_method_id() ) ) {
				return $label;
			}
			$orig = (string) $rate->get_label();
			$new  = self::translate( $orig, SHN_Settings::lang() );
			if ( '' !== $orig && $new !== $orig && 0 === strpos( $label, $orig ) ) {
				$label = esc_html( $new ) . substr( $label, strlen( $orig ) );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $label;
	}

	/**
	 * A partner rate label in a language: the label map (whole words,
	 * case-insensitive, one pass), "Estimated delivery", and for Greek the
	 * English month dates. Anything not recognised stays as it is.
	 */
	public static function translate( string $label, string $lang ): string {
		$s    = SHN_Settings::get();
		$lang = 'en' === $lang ? 'en' : 'el';

		$map = array();
		foreach ( $s['mx_map'] as $row ) {
			if ( '' !== $row[ $lang ] ) {
				$map[ mb_strtolower( $row['from'] ) ] = $row[ $lang ];
			}
		}
		$eta = SHN_Settings::text( 'mx_t_eta', $lang );
		if ( '' !== $eta ) {
			$map['estimated delivery'] = $eta;
		}
		if ( $map ) {
			$keys = array_keys( $map );
			usort(
				$keys,
				static function ( $a, $b ) {
					return mb_strlen( (string) $b ) <=> mb_strlen( (string) $a );
				}
			);
			$alt   = implode(
				'|',
				array_map(
					static function ( $k ) {
						return preg_quote( (string) $k, '/' );
					},
					$keys
				)
			);
			$out   = preg_replace_callback(
				'/(?<![\p{L}\p{N}])(' . $alt . ')(?![\p{L}\p{N}])/iu',
				static function ( $m ) use ( $map ) {
					$k = mb_strtolower( $m[1] );
					return isset( $map[ $k ] ) ? $map[ $k ] : $m[1];
				},
				$label
			);
			$label = is_string( $out ) ? $out : $label;
		}

		if ( 'el' === $lang && $s['mx_dates'] ) {
			$label = self::greek_dates( $label );
		}
		return $label;
	}

	/** "Oct 20–22" → "20–22 Οκτ", "Oct 30–Nov 2" → "30 Οκτ – 2 Νοε", "Oct 20" → "20 Οκτ". */
	public static function greek_dates( string $text ): string {
		$mon = '(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?';
		$out = preg_replace_callback(
			'/(?<![\p{L}\p{N}])' . $mon . '\s+(\d{1,2})(?:\s*[-–—]\s*(?:' . $mon . '\s+)?(\d{1,2}))?(?![\p{L}\p{N}])/iu',
			static function ( $m ) {
				$m1 = self::MONTHS[ strtolower( $m[1] ) ];
				$d1 = (int) $m[2];
				if ( ! isset( $m[4] ) || '' === $m[4] ) {
					return $d1 . ' ' . $m1;
				}
				$d2 = (int) $m[4];
				if ( isset( $m[3] ) && '' !== $m[3] && strtolower( $m[3] ) !== strtolower( $m[1] ) ) {
					return $d1 . ' ' . $m1 . ' – ' . $d2 . ' ' . self::MONTHS[ strtolower( $m[3] ) ];
				}
				return $d1 . '–' . $d2 . ' ' . $m1;
			},
			$text
		);
		return is_string( $out ) ? $out : $text;
	}

	/* =====================================================================
	 * Orders
	 * =================================================================== */

	/**
	 * woocommerce_checkout_create_order_shipping_item: the parcel name on
	 * the shipping line (in the visitor language of the checkout).
	 *
	 * @param WC_Order_Item_Shipping $item        Line being created.
	 * @param int|string             $package_key Package key.
	 * @param array                  $package     Package.
	 * @param WC_Order               $order       Order.
	 */
	public static function save_line( $item, $package_key = 0, $package = array(), $order = null ): void {
		try {
			if ( ! $item instanceof WC_Order_Item_Shipping || ! self::on() ) {
				return;
			}
			$packages = self::packages();
			if ( ! self::split( $packages ) || ! isset( $packages[ $package_key ] ) ) {
				return;
			}
			$n    = isset( $package['shn_n'] ) ? (int) $package['shn_n'] : (int) array_search( $package_key, array_keys( $packages ), true ) + 1;
			$name = self::name_for( $packages[ $package_key ]['partner'], $n );
			if ( '' !== $name ) {
				$item->add_meta_data( self::META, $name, true );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Admin order screen: "Parcel" instead of the meta key.
	 *
	 * @param string       $key  Display key.
	 * @param WC_Meta_Data $meta Meta.
	 */
	public static function meta_key_label( $key, $meta = null ) {
		if ( is_object( $meta ) && isset( $meta->key ) && self::META === $meta->key ) {
			return __( 'Δέμα', 'shipping-notes' );
		}
		return $key;
	}

	/** Whether the order shows the parcels (emails are sent from admin, cron and AJAX too). */
	private static function order_on( WC_Order $order ): bool {
		$s = SHN_Settings::get();
		if ( self::$broken || Shipping_Notes::killed() || 'off' === $s['mx_mode'] ) {
			return false;
		}
		if ( 'test' === $s['mx_mode'] ) {
			// Test mode: only orders placed while a shop manager saw the parcels.
			foreach ( $order->get_shipping_methods() as $line ) {
				if ( '' !== (string) $line->get_meta( self::META ) ) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	/** A shipping line's method name for display. */
	private static function line_method( WC_Order_Item_Shipping $line ): string {
		$name = (string) $line->get_name();
		if ( SHN_Settings::get()['mx_labels'] && self::is_partner_rate( (string) $line->get_method_id() ) ) {
			$name = self::translate( $name, SHN_Settings::lang() );
		}
		return $name;
	}

	/**
	 * woocommerce_order_shipping_method: the partner's names in the order
	 * totals ("Shipping: …") of the thank-you page, My account and emails.
	 * Not in REST responses.
	 *
	 * @param string   $names Comma-separated method names.
	 * @param WC_Order $order Order.
	 */
	public static function order_shipping_method( $names, $order = null ) {
		try {
			if ( ! $order instanceof WC_Order || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! SHN_Settings::get()['mx_labels'] || ! self::order_on( $order ) ) {
				return $names;
			}
			$out     = array();
			$changed = false;
			foreach ( $order->get_shipping_methods() as $line ) {
				$name    = self::line_method( $line );
				$changed = $changed || $name !== (string) $line->get_name();
				$out[]   = $name;
			}
			return $changed ? implode( ', ', $out ) : $names;
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $names;
		}
	}

	/**
	 * The parcels of an order: rows { name, method, cost } when it has more
	 * than one shipping line and one of them is a partner parcel (or has a
	 * saved parcel name).
	 */
	public static function order_rows( WC_Order $order ): array {
		$lines = array_values( $order->get_shipping_methods() );
		if ( count( $lines ) < 2 ) {
			return array();
		}
		$ours = false;
		foreach ( $lines as $line ) {
			if ( '' !== (string) $line->get_meta( self::META ) || self::is_partner_rate( (string) $line->get_method_id() ) ) {
				$ours = true;
				break;
			}
		}
		if ( ! $ours ) {
			return array();
		}
		$incl = 'incl' === get_option( 'woocommerce_tax_display_cart' );
		$rows = array();
		foreach ( $lines as $i => $line ) {
			$name = (string) $line->get_meta( self::META );
			if ( '' === $name ) {
				$name = self::name_for( self::is_partner_rate( (string) $line->get_method_id() ), $i + 1 );
			}
			$cost   = (float) $line->get_total() + ( $incl ? (float) $line->get_total_tax() : 0.0 );
			$rows[] = array(
				'name'   => $name,
				'method' => self::line_method( $line ),
				'cost'   => wc_price( $cost, array( 'currency' => $order->get_currency() ) ),
			);
		}
		return $rows;
	}

	private static function order_title( int $count ): string {
		return str_replace( '{count}', (string) $count, SHN_Settings::text( 'mx_t_order' ) );
	}

	/**
	 * Thank-you page and My account → order: the parcels under the order table.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function print_order( $order ): void {
		try {
			if ( ! $order instanceof WC_Order || ! SHN_Settings::get()['mx_order'] || ! self::order_on( $order ) ) {
				return;
			}
			$rows = self::order_rows( $order );
			if ( ! $rows ) {
				return;
			}
			echo '<section class="shn-parcels-order"><h2 class="woocommerce-column__title">' . esc_html( self::order_title( count( $rows ) ) ) . '</h2>';
			echo '<table class="woocommerce-table shop_table shn-parcels-order__table"><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><th scope="row">' . esc_html( $r['name'] ) . '</th><td>' . esc_html( $r['method'] ) . '</td><td class="shn-parcels-order__cost">' . wp_kses_post( $r['cost'] ) . '</td></tr>';
			}
			echo '</tbody></table></section>';
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Customer emails: the parcels after the order table (HTML and plain text).
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Admin email.
	 * @param bool     $plain_text    Plain-text email.
	 * @param WC_Email $email         Email.
	 */
	public static function print_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		try {
			if ( $sent_to_admin || ! $order instanceof WC_Order || ! SHN_Settings::get()['mx_order'] || ! self::order_on( $order ) ) {
				return;
			}
			$rows = self::order_rows( $order );
			if ( ! $rows ) {
				return;
			}
			$title = self::order_title( count( $rows ) );
			if ( $plain_text ) {
				echo "\n" . esc_html( $title ) . "\n\n";
				foreach ( $rows as $r ) {
					$cost = html_entity_decode( wp_strip_all_tags( $r['cost'] ), ENT_QUOTES, 'UTF-8' );
					echo esc_html( $r['name'] ) . "\n" . esc_html( $r['method'] ) . ': ' . esc_html( $cost ) . "\n\n";
				}
				return;
			}
			echo '<div class="shn-parcels-email" style="margin:0 0 40px;"><h2>' . esc_html( $title ) . '</h2>';
			echo '<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;"><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><th class="td" scope="row" style="text-align:left;">' . esc_html( $r['name'] ) . '</th><td class="td" style="text-align:left;">' . esc_html( $r['method'] ) . '</td><td class="td" style="text-align:right;white-space:nowrap;">' . wp_kses_post( $r['cost'] ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Product page hint
	 * =================================================================== */

	public static function setup_product(): void {
		try {
			if ( ! function_exists( 'is_product' ) || ! is_product() || ! self::on() ) {
				return;
			}
			if ( ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
				add_action( 'astra_woo_single_price_after', array( __CLASS__, 'print_hint' ), 12 );
				add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'print_hint' ), 12 );
			}
			add_filter( 'render_block_woocommerce/product-price', array( __CLASS__, 'block_hint' ), 11, 3 );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	private static function hint_html( int $id ): string {
		$product = $id > 0 ? wc_get_product( $id ) : null;
		if ( ! $product instanceof WC_Product || ! self::is_partner_product( $product ) ) {
			return '';
		}
		$text = SHN_Settings::text( 'mx_t_hint' );
		if ( '' === $text ) {
			return '';
		}
		SHN_Front::css();
		return '<p class="shn-mx-hint">' . esc_html( $text ) . '</p>';
	}

	public static function print_hint(): void {
		try {
			if ( self::$hint_done || ! self::on() ) {
				return;
			}
			$html = self::hint_html( (int) get_queried_object_id() );
			if ( '' !== $html ) {
				self::$hint_done = true;
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in hint_html().
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** render_block filter: after the viewed product's price block. */
	public static function block_hint( $content, $parsed = null, $block = null ) {
		try {
			$content = (string) $content;
			if ( self::$hint_done || ! self::on() ) {
				return $content;
			}
			if ( is_object( $block ) && isset( $block->context['postId'] ) && (int) $block->context['postId'] !== (int) get_queried_object_id() ) {
				return $content;
			}
			$html = self::hint_html( (int) get_queried_object_id() );
			if ( '' === $html ) {
				return $content;
			}
			self::$hint_done = true;
			return $content . $html;
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return (string) $content;
		}
	}
}
