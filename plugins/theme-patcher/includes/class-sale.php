<?php
/**
 * TP_Sale — sale badge text for the whole store: a template such as
 * "Sale {percent}" instead of WooCommerce's "Sale!".
 *
 * Where (WooCommerce's own filters, so every theme that draws the badge
 * through WooCommerce is covered, classic or block):
 *  - woocommerce_sale_flash       classic themes: shop and category lists,
 *                                 related products, the product page
 *                                 (templates loop/sale-flash.php and
 *                                 single-product/sale-flash.php)
 *  - woocommerce_sale_badge_text  the Product Sale Badge block (WooCommerce
 *                                 10.0+); left alone when the block itself
 *                                 shows a percentage or an amount
 *  - the slot of Theme Patcher's areas (TP_Render), instead of "-20%"
 * Themes that build their own badge without these filters keep it.
 *
 * Placeholders: {percent} → "20%", {amount} → the money saved, formatted by
 * WooCommerce as the store shows prices (with or without tax). Variable and
 * grouped products use the largest discount, and the "up to" text when
 * their discounts differ.
 *
 * Rules (Bible §14): follows the master switch and test mode of
 * tp_settings; TP_DISABLE stops it (not even initialised); the same request
 * gate as the price labels (front-end pages and AJAX, for quick views and
 * product lists loaded over AJAX; never admin, REST, feeds, cron, CLI or
 * XML-RPC); works on block themes; never writes to the database; every
 * callback is wrapped in try/catch and returns the original output on any
 * Throwable. Prices, carts, orders, emails, feeds and the schema are never
 * touched: the badge is display only (§14.17).
 *
 * Texts are store settings (Greek and English) with the language of the
 * price labels. Developers can change a text with `tp_sale_badge_text`.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Sale {

	/** Priority after other plugins (Customizer for WooCommerce uses 50), so the setting wins while both run. */
	const PRIORITY = 100;

	/** Gate result for this request (null = not decided yet). */
	private static $gate = null;

	/** Set after any Throwable: no further work this request. */
	private static $failed = false;

	/** Per-request cache: "<product id>|<context>" => text. */
	private static $cache = array();

	public static function init(): void {
		add_filter( 'woocommerce_sale_flash', array( __CLASS__, 'sale_flash' ), self::PRIORITY, 3 );
		add_filter( 'woocommerce_sale_badge_text', array( __CLASS__, 'badge_text' ), self::PRIORITY, 2 );
	}

	/* =====================================================================
	 * Gate
	 * =================================================================== */

	/** True when the badge text applies to this request and visitor. */
	public static function on(): bool {
		if ( self::$failed ) {
			return false;
		}
		if ( null !== self::$gate ) {
			return self::$gate;
		}
		if ( defined( 'TP_DISABLE' ) && TP_DISABLE ) {
			return false;
		}
		$s  = TP_Settings::get();
		$on = ! empty( $s['enabled'] )
			&& ! empty( $s['sale']['on'] )
			&& ( empty( $s['test_mode'] ) || current_user_can( 'manage_woocommerce' ) )
			&& TP_Prices::request_ok();
		// Decide once the request type and the user are known.
		if ( did_action( 'wp' ) || wp_doing_ajax() ) {
			self::$gate = $on;
		}
		return $on;
	}

	/* =====================================================================
	 * Filters
	 * =================================================================== */

	/** woocommerce_sale_flash: keep the theme's element, change its text. */
	public static function sale_flash( $html, $post = null, $product = null ) {
		try {
			if ( ! self::on() ) {
				return $html;
			}
			$product = self::product( $product );
			if ( ! $product || ! $product->is_on_sale() ) {
				return $html;
			}
			$text = self::text( $product, self::context( $product ) );
			if ( '' === $text ) {
				return $html;
			}
			return self::replace_text( (string) $html, $text );
		} catch ( \Throwable $e ) {
			self::$failed = true;
			return $html;
		}
	}

	/** woocommerce_sale_badge_text (block): plain text, WooCommerce escapes it. */
	public static function badge_text( $text, $product = null ) {
		try {
			if ( ! self::on() || ! $product instanceof WC_Product ) {
				return $text;
			}
			// The block is set to show a percentage or an amount: respect it.
			if ( 1 === preg_match( '/\d/', (string) $text ) ) {
				return $text;
			}
			$new = self::text( $product, self::context( $product ) );
			return '' === $new ? $text : $new;
		} catch ( \Throwable $e ) {
			self::$failed = true;
			return $text;
		}
	}

	/**
	 * Text for Theme Patcher's slot: '' when the module is off for this
	 * request (the slot then keeps its "-20%"), or when nothing applies.
	 */
	public static function slot_text( WC_Product $product ): string {
		try {
			return self::on() ? self::text( $product, 'loop' ) : '';
		} catch ( \Throwable $e ) {
			self::$failed = true;
			return '';
		}
	}

	/* =====================================================================
	 * Text
	 * =================================================================== */

	/**
	 * Badge text for a product on sale ('' = keep the theme's badge).
	 *
	 * @param WC_Product $product Product.
	 * @param string     $context 'loop' (lists, cards) or 'single' (the product page's own product).
	 */
	public static function text( WC_Product $product, string $context = 'loop' ): string {
		$key = $product->get_id() . '|' . $context;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$s = TP_Settings::sale();
		$d = self::discount( $product );
		if ( null === $d || $d['percent'] < max( 1, (int) $s['min'] ) ) {
			$text = self::pick( $s['fallback'] );
		} else {
			$tpl = $d['mixed'] ? self::pick( $s['upto'] ) : '';
			if ( '' === $tpl && 'single' === $context ) {
				$tpl = self::pick( $s['single'] );
			}
			if ( '' === $tpl ) {
				$tpl = self::pick( $s['text'] );
			}
			$text = self::fill( $tpl, $d['percent'], $d['amount'] );
		}

		/**
		 * Filters the sale badge text.
		 *
		 * @param string     $text    Badge text (plain text; '' = keep the theme's badge).
		 * @param WC_Product $product Product.
		 * @param string     $context 'loop' or 'single'.
		 * @param string     $lang    'el' or 'en'.
		 */
		$text = (string) apply_filters( 'tp_sale_badge_text', $text, $product, $context, TP_Prices::lang() );

		self::$cache[ $key ] = $text;
		return $text;
	}

	/** Fill the placeholders of a template; unknown placeholders go (Bible §14.17). */
	public static function fill( string $tpl, int $percent, float $amount ): string {
		if ( false === strpos( $tpl, '{' ) ) {
			return $tpl;
		}
		$text = strtr(
			$tpl,
			array(
				'{percent}' => $percent . '%',
				'{amount}'  => self::money( $amount ),
			)
		);
		$text = (string) preg_replace( '/\{[a-z_]+\}/', '', $text );
		return trim( (string) preg_replace( '/\s{2,}/u', ' ', $text ) );
	}

	/** Money as plain text in the store's format ("4,00 €"). */
	private static function money( float $amount ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
	}

	/** Pick the visitor's language from a { el, en } pair (empty English → Greek). */
	private static function pick( array $pair ): string {
		if ( 'en' === TP_Prices::lang() && '' !== $pair['en'] ) {
			return $pair['en'];
		}
		return $pair['el'];
	}

	/* =====================================================================
	 * Discount
	 * =================================================================== */

	/**
	 * Largest discount of a product: [ 'percent' => int, 'amount' => float,
	 * 'mixed' => bool ], or null when there is none to show.
	 * Variable: from the variation prices WooCommerce caches (display
	 * prices, one read). Grouped: from its visible children. "mixed" means
	 * the shown percentages differ (a variation without a discount counts
	 * as 0%), so the "up to" text applies.
	 */
	public static function discount( WC_Product $product ): ?array {
		$pairs = array();
		if ( $product->is_type( 'variable' ) && is_callable( array( $product, 'get_variation_prices' ) ) ) {
			$prices = $product->get_variation_prices( true );
			foreach ( $prices['regular_price'] as $vid => $regular ) {
				$pairs[] = array( (float) $regular, isset( $prices['price'][ $vid ] ) ? (float) $prices['price'][ $vid ] : (float) $regular );
			}
		} elseif ( $product->is_type( 'grouped' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && $child->is_visible() && ! $child->is_type( array( 'variable', 'grouped' ) ) ) {
					$pairs[] = self::display_pair( $child );
				}
			}
		} else {
			$pairs[] = self::display_pair( $product );
		}

		$best  = null;
		$shown = array();
		foreach ( $pairs as $pair ) {
			list( $regular, $price ) = $pair;
			$pct = self::percent( $regular, $price );
			$shown[ $pct ] = true;
			if ( $pct > 0 && ( null === $best || $pct > $best['percent'] || ( $pct === $best['percent'] && $regular - $price > $best['amount'] ) ) ) {
				$best = array(
					'percent' => $pct,
					'amount'  => $regular - $price,
				);
			}
		}
		if ( null === $best ) {
			return null;
		}
		$best['mixed'] = count( $shown ) > 1;
		return $best;
	}

	/** Regular and current price of a simple-like product, as the store displays them. */
	private static function display_pair( WC_Product $product ): array {
		$regular = (float) $product->get_regular_price();
		$price   = (float) $product->get_price();
		if ( $regular > 0 ) {
			$regular = (float) wc_get_price_to_display( $product, array( 'price' => $regular ) );
			$price   = (float) wc_get_price_to_display( $product, array( 'price' => $price ) );
		}
		return array( $regular, $price );
	}

	/**
	 * Shown percentage, 0 when there is no discount. Rounded down by default
	 * (never more than the real discount), or to the nearest whole number.
	 * The value is first rounded to 4 decimals, so 25% that comes out as
	 * 24.999998 still shows 25%: prices with tax are only kept to 6
	 * decimals (12.00 → 9.677419 at 24% VAT), which shifts the 6th decimal.
	 */
	public static function percent( float $regular, float $price ): int {
		if ( $regular <= 0 || $price < 0 || $price >= $regular ) {
			return 0;
		}
		$raw = round( ( $regular - $price ) / $regular * 100, 4 );
		return (int) ( 'round' === TP_Settings::sale()['round'] ? round( $raw ) : floor( $raw ) );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	private static function product( $product ): ?WC_Product {
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		return isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof WC_Product ? $GLOBALS['product'] : null;
	}

	/** 'single' for the product page's own product, 'loop' everywhere else (related products included). */
	private static function context( WC_Product $product ): string {
		if ( function_exists( 'is_product' ) && is_product() && (int) get_queried_object_id() === (int) $product->get_id() ) {
			return 'single';
		}
		return 'loop';
	}

	/**
	 * Replace the text of the badge element the theme (or WooCommerce)
	 * built: one span / div / p / em / strong without nested elements of
	 * the same tag keeps its tag and attributes. Anything else becomes
	 * WooCommerce's standard markup.
	 */
	public static function replace_text( string $html, string $text ): string {
		$safe = esc_html( $text );
		if ( 1 === preg_match( '#^(\s*<(span|div|p|em|strong)\b[^>]*>)(.*)(</\2>\s*)$#is', $html, $m )
			&& false === stripos( $m[3], '<' . $m[2] ) ) {
			return $m[1] . $safe . $m[4];
		}
		return '<span class="onsale">' . $safe . '</span>';
	}

	/* =====================================================================
	 * Customizer for WooCommerce (import, admin only)
	 * =================================================================== */

	/** Its sale texts: [ 'loop' => str, 'single' => str ], empty strings when unset. */
	public static function customizer_texts(): array {
		$opt = get_option( 'wc_customizer_active_customizations', array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array(
			'loop'   => isset( $opt['loop_sale_flash_text'] ) && is_string( $opt['loop_sale_flash_text'] ) ? TP_Settings::valid_label( $opt['loop_sale_flash_text'] ) : '',
			'single' => isset( $opt['single_sale_flash_text'] ) && is_string( $opt['single_sale_flash_text'] ) ? TP_Settings::valid_label( $opt['single_sale_flash_text'] ) : '',
		);
	}

	/** True while Customizer for WooCommerce is active. */
	public static function customizer_active(): bool {
		return class_exists( 'WC_Customizer' );
	}
}
