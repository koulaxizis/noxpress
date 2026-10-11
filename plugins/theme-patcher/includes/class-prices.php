<?php
/**
 * TP_Prices — price labels: "Free" instead of 0,00 € and a label for
 * products that have no price at all (per category, with a general text
 * for the rest).
 *
 * Where (all through WooCommerce's own price filters, so every theme,
 * classic or block, is covered where WooCommerce draws the price):
 *  - woocommerce_get_price_html            price 0: product page, shop and
 *                                          category lists, related, up-sells,
 *                                          cross-sells, widgets, product blocks
 *                                          rendered on the server
 *  - woocommerce_empty_price_html,
 *    woocommerce_variable_empty_price_html products without a price
 *  - woocommerce_cart_item_price,
 *    woocommerce_cart_item_subtotal        cart table, mini cart, checkout
 *                                          review (lines only: subtotals and
 *                                          totals keep their numbers)
 *
 * Never touched: orders, emails, the thank-you page, My Account, invoices,
 * the price schema and product feeds (they read the numeric price).
 * The Cart and Checkout blocks render prices in JavaScript from the Store
 * API, so they keep 0,00 € (the admin tab says so when they are in use).
 *
 * Rules (Bible §14):
 *  - follows the master switch and test mode of tp_settings; TP_DISABLE
 *    stops it (not even initialised);
 *  - runs on front-end page requests AND on AJAX requests, because the
 *    cart, the mini cart and the checkout refresh over AJAX (wc-ajax
 *    fragments, update_order_review) — documented deviation from §14.4;
 *    never on admin screens, REST (Store API included), feeds, cron, CLI
 *    or XML-RPC;
 *  - block themes are NOT skipped (unlike the card runtime): the price
 *    blocks call get_price_html() on the server;
 *  - the category → rule map is computed when the settings or product
 *    categories are saved (§14.10); a page view only reads the product's
 *    cached category ids;
 *  - every callback is wrapped in try/catch: on any Throwable the original
 *    HTML is returned and the module stops for the rest of the request.
 *
 * Label texts are store settings (Greek and English), not msgids; the
 * language comes from the plugin setting (auto = site locale). Developers
 * can change a text with the `tp_price_label_text` filter.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Prices {

	const CSS_CLASS = 'tp-price-label';

	/** Gate result for this request (null = not decided yet). */
	private static $gate = null;

	/** Set after any Throwable: no further work this request. */
	private static $failed = false;

	/** Per-request cache: product id => label HTML ('' = none). */
	private static $empty_cache = array();

	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 100, 2 );
		add_filter( 'woocommerce_empty_price_html', array( __CLASS__, 'empty_price_html' ), 100, 2 );
		add_filter( 'woocommerce_variable_empty_price_html', array( __CLASS__, 'empty_price_html' ), 100, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_item_html' ), 100, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'cart_item_html' ), 100, 2 );

		// §14.10: the category map is rebuilt when categories change, never on a page view.
		add_action( 'created_product_cat', array( __CLASS__, 'refresh_map' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'refresh_map' ) );
		add_action( 'delete_product_cat', array( __CLASS__, 'refresh_map' ) );
	}

	/* =====================================================================
	 * Gate
	 * =================================================================== */

	/** Request type where labels may apply (front-end page or AJAX). */
	public static function request_ok(): bool {
		if ( wp_doing_cron() ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( ! wp_doing_ajax() && did_action( 'wp' ) && ( is_feed() || is_embed() || is_robots() || is_trackback() ) ) {
			return false;
		}
		return true;
	}

	/** True when labels apply to this request and visitor. */
	private static function on(): bool {
		if ( self::$failed ) {
			return false;
		}
		if ( null !== self::$gate ) {
			return self::$gate;
		}
		$s  = TP_Settings::get();
		$on = ! empty( $s['enabled'] )
			&& ( empty( $s['test_mode'] ) || current_user_can( 'manage_woocommerce' ) )
			&& self::configured()
			&& self::request_ok();
		// Decide once the request type and the user are known.
		if ( did_action( 'wp' ) || wp_doing_ajax() ) {
			self::$gate = $on;
		}
		return $on;
	}

	/** True when at least one kind of label is switched on. */
	public static function configured(): bool {
		$p = TP_Settings::prices();
		return $p['zero'] || $p['empty'];
	}

	private static function fail(): void {
		self::$failed = true;
	}

	/* =====================================================================
	 * Filters
	 * =================================================================== */

	/** woocommerce_get_price_html: price 0 → "Free". */
	public static function price_html( $html, $product = null ) {
		try {
			if ( ! self::on() || ! TP_Settings::prices()['zero'] || ! self::is_free( $product ) ) {
				return $html;
			}
			return self::label_html( 'zero', self::text( 'zero', $product ) );
		} catch ( \Throwable $e ) {
			self::fail();
			return $html;
		}
	}

	/** woocommerce_(variable_)empty_price_html: product without a price. */
	public static function empty_price_html( $html, $product = null ) {
		try {
			if ( ! self::on() || ! TP_Settings::prices()['empty'] || ! $product instanceof WC_Product ) {
				return $html;
			}
			$id = (int) $product->get_id();
			if ( ! isset( self::$empty_cache[ $id ] ) ) {
				$text                     = self::text( 'empty', $product );
				self::$empty_cache[ $id ] = '' === $text ? '' : self::label_html( 'empty', $text );
			}
			return '' === self::$empty_cache[ $id ] ? $html : self::$empty_cache[ $id ];
		} catch ( \Throwable $e ) {
			self::fail();
			return $html;
		}
	}

	/** woocommerce_cart_item_price / _subtotal: line of a free product. */
	public static function cart_item_html( $html, $cart_item = array() ) {
		try {
			$p = TP_Settings::prices();
			if ( ! self::on() || ! $p['zero'] || ! $p['cart'] || ! is_array( $cart_item ) || ! isset( $cart_item['data'] ) ) {
				return $html;
			}
			$product = $cart_item['data'];
			if ( ! $product instanceof WC_Product || ! self::zero( $product->get_price() ) ) {
				return $html;
			}
			return self::label_html( 'zero', self::text( 'zero', $product ) );
		} catch ( \Throwable $e ) {
			self::fail();
			return $html;
		}
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/** Price value that is set and equal to 0 ('' = no price, not free). */
	private static function zero( $price ): bool {
		return '' !== $price && null !== $price && is_numeric( $price ) && 0.0 === (float) $price;
	}

	/**
	 * Free product: price 0 (a sale price of 0 counts too). Variable: every
	 * variation costs 0 (a range such as 0 – 10 € stays as it is). Grouped
	 * products keep WooCommerce's own text.
	 */
	public static function is_free( $product ): bool {
		if ( ! $product instanceof WC_Product || $product->is_type( 'grouped' ) ) {
			return false;
		}
		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices( true );
			if ( empty( $prices['price'] ) ) {
				return false;
			}
			return self::zero( (string) min( $prices['price'] ) ) && self::zero( (string) max( $prices['price'] ) );
		}
		return self::zero( $product->get_price() );
	}

	/** Visitor language for the labels: 'el' | 'en'. */
	public static function lang(): string {
		$choice = TP_Settings::prices()['lang'];
		if ( 'el' === $choice || 'en' === $choice ) {
			return $choice;
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}

	/** Pick the visitor's language from a { el, en } pair (empty English → Greek). */
	private static function pick( array $pair ): string {
		if ( 'en' === self::lang() && '' !== $pair['en'] ) {
			return $pair['en'];
		}
		return $pair['el'];
	}

	/**
	 * Label text: kind 'zero' → the general "Free" text; kind 'empty' → the
	 * first rule whose category the product is in, else the general text.
	 */
	public static function text( string $kind, WC_Product $product ): string {
		$p = TP_Settings::prices();
		if ( 'zero' === $kind ) {
			$text = self::pick( $p['zero_text'] );
		} else {
			$text = self::pick( $p['empty_text'] );
			$rule = self::rule_for( $product );
			if ( null !== $rule ) {
				$text = self::pick( $p['rules'][ $rule ] );
			}
		}
		/**
		 * Filters a price label text.
		 *
		 * @param string     $text    Label text (plain text; '' = no label).
		 * @param WC_Product $product Product.
		 * @param string     $kind    'zero' (price 0) or 'empty' (no price).
		 * @param string     $lang    'el' or 'en'.
		 */
		return (string) apply_filters( 'tp_price_label_text', $text, $product, $kind, self::lang() );
	}

	/** Index of the first rule matching the product's categories, or null. */
	private static function rule_for( WC_Product $product ): ?int {
		$map = TP_Settings::prices()['map'];
		if ( empty( $map ) ) {
			return null;
		}
		$id   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$best = null;
		foreach ( wc_get_product_term_ids( $id, 'product_cat' ) as $tid ) {
			if ( isset( $map[ $tid ] ) && ( null === $best || $map[ $tid ] < $best ) ) {
				$best = (int) $map[ $tid ];
			}
		}
		return $best;
	}

	private static function label_html( string $kind, string $text ): string {
		return '<span class="' . esc_attr( self::CSS_CLASS . ' ' . self::CSS_CLASS . '--' . ( 'zero' === $kind ? 'free' : 'none' ) ) . '">' . esc_html( $text ) . '</span>';
	}

	/* =====================================================================
	 * Category map (admin / save hooks only)
	 * =================================================================== */

	/**
	 * term id => index of the first rule that covers it (rules with
	 * "subcategories" cover every descendant). Earlier rules win.
	 */
	public static function build_map( array $rules ): array {
		$map = array();
		foreach ( $rules as $i => $rule ) {
			$ids = array( (int) $rule['cat'] );
			if ( ! empty( $rule['children'] ) ) {
				$kids = get_term_children( (int) $rule['cat'], 'product_cat' );
				if ( is_array( $kids ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $kids ) );
				}
			}
			foreach ( $ids as $tid ) {
				if ( $tid > 0 && ! isset( $map[ $tid ] ) ) {
					$map[ $tid ] = (int) $i;
				}
			}
		}
		return $map;
	}

	/** Rebuild the stored map after a product category change (admin side). */
	public static function refresh_map(): void {
		try {
			$s = TP_Settings::get();
			if ( empty( $s['prices']['rules'] ) ) {
				return;
			}
			// WordPress clears the term hierarchy cache before these hooks fire.
			$s['prices']['map'] = self::build_map( $s['prices']['rules'] );
			TP_Settings::save( $s );
		} catch ( \Throwable $e ) {
			// A stale map only means an older label; never break a term save.
			return;
		}
	}

	/* =====================================================================
	 * Admin information (read only)
	 * =================================================================== */

	/**
	 * Published products: with price 0, without a price, and how many of
	 * those without a price a rule covers. Two read queries, admin only.
	 */
	public static function counts(): array {
		global $wpdb;
		$out = array(
			'zero'    => 0,
			'empty'   => 0,
			'covered' => 0,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- admin-only counts, nothing to cache.
		$out['zero'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT p.ID FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_price'
				WHERE p.post_type = 'product' AND p.post_status = 'publish'
				GROUP BY p.ID
				HAVING SUM( m.meta_value = '' ) = 0 AND MAX( m.meta_value + 0 ) = 0 AND MIN( m.meta_value + 0 ) = 0
			) t"
		);

		$empty_ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_price' AND m.meta_value <> ''
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_id IS NULL"
		);
		// phpcs:enable

		$empty_ids    = array_map( 'intval', is_array( $empty_ids ) ? $empty_ids : array() );
		$out['empty'] = count( $empty_ids );

		$map = TP_Settings::prices()['map'];
		if ( $empty_ids && $map ) {
			foreach ( array_chunk( $empty_ids, 500 ) as $chunk ) {
				$terms = wp_get_object_terms( $chunk, 'product_cat', array( 'fields' => 'all_with_object_id' ) );
				$hit   = array();
				foreach ( is_array( $terms ) ? $terms : array() as $t ) {
					if ( isset( $map[ (int) $t->term_id ] ) ) {
						$hit[ (int) $t->object_id ] = true;
					}
				}
				$out['covered'] += count( $hit );
			}
		}
		return $out;
	}

	/** True when the cart or checkout page uses the Cart / Checkout block. */
	public static function uses_cart_blocks(): bool {
		foreach ( array( 'cart' => 'woocommerce/cart', 'checkout' => 'woocommerce/checkout' ) as $page => $block ) {
			$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $page ) : 0;
			if ( $id > 0 && has_block( $block, $id ) ) {
				return true;
			}
		}
		return false;
	}
}
