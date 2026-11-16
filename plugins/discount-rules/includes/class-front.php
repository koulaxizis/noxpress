<?php
/**
 * DSR_Front — what visitors see on the shop pages (display only, Bible
 * §14.17: prices, carts and orders are worked out by DSR_Engine and
 * DSR_Cart from the stored prices).
 *
 *  - «Προσφορά» rules (Α): the regular price struck and the rule price,
 *    in woocommerce_get_price_html (every theme that prints prices through
 *    WooCommerce, classic or block; the Store API product lists too), and
 *    woocommerce_product_is_on_sale, so the theme's sale badge shows.
 *    The badge text: −{percent} through Theme Patcher's tp_sale_badge_text
 *    when it runs, else woocommerce_sale_flash.
 *  - the lowest 30-day price under a reduced price (DSR_History), for
 *    rules and, when so set, WooCommerce's own sale prices
 *  - the product page: «έως 23/4» for a sale rule with an end, the tier
 *    table (Β) and the label of a buy-X-pay-Y rule (Γ)
 *  - product lists: the label of a tiers / buy-X-pay-Y rule under the price
 *
 * Same request gate as the cart (front-end pages, wc-ajax, Store API;
 * never admin, other REST, feeds, cron or CLI), same mode and test mode,
 * never for trade accounts. Every callback returns the original output on
 * any error. One small stylesheet, only on shop pages while a rule is
 * active.
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Front {

	/** Variable products with more variations than this show their own prices. */
	const MAX_VARIATIONS = 100;

	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 30, 2 );
		add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'on_sale' ), 30, 2 );
		add_filter( 'tp_sale_badge_text', array( __CLASS__, 'tp_badge' ), 20, 4 );
		add_filter( 'woocommerce_sale_flash', array( __CLASS__, 'sale_flash' ), 5, 3 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single' ), 11 );
		add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'loop' ), 11 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** True when this request shows the rules to the current visitor. */
	private static function on(): bool {
		return DSR_Cart::request_ok() && ! is_feed() && DSR_Engine::on_for_user();
	}

	/* =====================================================================
	 * Prices
	 * =================================================================== */

	/**
	 * @param mixed $html    Price HTML.
	 * @param mixed $product Product.
	 */
	public static function price_html( $html, $product ) {
		if ( ! $product instanceof WC_Product || ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		try {
			if ( ! self::on() ) {
				return $html;
			}
			if ( $product->is_type( 'variable' ) ) {
				return self::variable_html( $html, $product );
			}
			$s = DSR_Engine::sale_for( $product );
			if ( null === $s ) {
				return $html . self::omnibus_wc( $product );
			}
			$out = wc_format_sale_price(
				wc_get_price_to_display( $product, array( 'price' => $s['regular'] ) ),
				wc_get_price_to_display( $product, array( 'price' => $s['price'] ) )
			) . $product->get_price_suffix( $s['price'] );
			return $out . self::omnibus_line( $product, (int) $s['start'] );
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/** A variable product: the range after the rule, when a rule lowers a variation. */
	private static function variable_html( string $html, WC_Product $product ): string {
		$s = self::variable_sale( $product );
		if ( null === $s ) {
			return $html;
		}
		if ( abs( $s['min'] - $s['max'] ) < 0.000001 ) {
			$out = wc_format_sale_price( $s['reg_min'], $s['min'] );
		} else {
			$out = wc_format_price_range( $s['min'], $s['max'] );
		}
		return $out . $product->get_price_suffix();
	}

	/**
	 * Display prices of a variable product after the sale rules.
	 *
	 * @return array|null { min, max, reg_min, percent } (display prices), null when no rule lowers any variation.
	 */
	private static function variable_sale( WC_Product $product ): ?array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return null;
		}
		$prices = $product->get_variation_prices( true );
		if ( empty( $prices['price'] ) || count( $prices['price'] ) > self::MAX_VARIATIONS ) {
			return null;
		}
		$min     = null;
		$max     = null;
		$reg_min = null;
		$pct     = 0;
		$hit     = false;
		foreach ( $prices['price'] as $vid => $price ) {
			$v   = wc_get_product( (int) $vid );
			$new = (float) $price;
			$reg = isset( $prices['regular_price'][ $vid ] ) ? (float) $prices['regular_price'][ $vid ] : $new;
			if ( $v instanceof WC_Product ) {
				$s = DSR_Engine::sale_for( $v );
				if ( null !== $s ) {
					$new = (float) wc_get_price_to_display( $v, array( 'price' => $s['price'] ) );
					$pct = max( $pct, (int) $s['percent'] );
					$hit = true;
				}
			}
			$min     = null === $min ? $new : min( $min, $new );
			$max     = null === $max ? $new : max( $max, $new );
			$reg_min = null === $reg_min ? $reg : min( $reg_min, $reg );
		}
		if ( ! $hit ) {
			return null;
		}
		return array(
			'min'     => (float) $min,
			'max'     => (float) $max,
			'reg_min' => (float) $reg_min,
			'percent' => $pct,
		);
	}

	/**
	 * @param mixed $on      On sale.
	 * @param mixed $product Product.
	 */
	public static function on_sale( $on, $product ) {
		if ( $on || ! $product instanceof WC_Product ) {
			return $on;
		}
		try {
			if ( ! self::on() ) {
				return $on;
			}
			if ( $product->is_type( 'variable' ) ) {
				return null !== self::variable_sale( $product ) ? true : $on;
			}
			return null !== DSR_Engine::sale_for( $product ) ? true : $on;
		} catch ( \Throwable $e ) {
			return $on;
		}
	}

	/** The percent of the sale rule of a product, or 0. */
	private static function percent( WC_Product $product ): int {
		if ( $product->is_type( 'variable' ) ) {
			$s = self::variable_sale( $product );
			return null !== $s ? (int) $s['percent'] : 0;
		}
		$s = DSR_Engine::sale_for( $product );
		return null !== $s ? (int) $s['percent'] : 0;
	}

	/**
	 * Theme Patcher's sale badge: the rule's percent.
	 *
	 * @param mixed $text    Text.
	 * @param mixed $product Product.
	 * @param mixed $context loop | single.
	 * @param mixed $lang    Language.
	 */
	public static function tp_badge( $text, $product = null, $context = '', $lang = '' ) {
		if ( ! $product instanceof WC_Product ) {
			return $text;
		}
		try {
			if ( ! self::on() ) {
				return $text;
			}
			$pct = self::percent( $product );
			return $pct > 0 ? DSR_Settings::fill( 't_badge', array( 'percent' => $pct . '%' ) ) : $text;
		} catch ( \Throwable $e ) {
			return $text;
		}
	}

	/**
	 * WooCommerce's own badge (classic themes): the rule's percent; the
	 * theme's markup is kept when it is one simple element.
	 *
	 * @param mixed $html    HTML.
	 * @param mixed $post    Post.
	 * @param mixed $product Product.
	 */
	public static function sale_flash( $html, $post = null, $product = null ) {
		if ( ! $product instanceof WC_Product || ! is_string( $html ) ) {
			return $html;
		}
		try {
			if ( ! self::on() || $product->is_on_sale( 'edit' ) ) {
				return $html; // WooCommerce's own sale: its badge.
			}
			$pct = self::percent( $product );
			if ( $pct <= 0 ) {
				return $html;
			}
			$text = esc_html( DSR_Settings::fill( 't_badge', array( 'percent' => $pct . '%' ) ) );
			if ( preg_match( '#^\s*<(span|div)([^>]*)>[^<]*</\1>\s*$#', $html, $m ) ) {
				return '<' . $m[1] . $m[2] . '>' . $text . '</' . $m[1] . '>';
			}
			return '<span class="onsale">' . $text . '</span>';
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/* =====================================================================
	 * Lowest 30-day price
	 * =================================================================== */

	/**
	 * The line under a price reduced by a rule.
	 *
	 * @param WC_Product $p     Product.
	 * @param int        $start When the reduction started.
	 */
	private static function omnibus_line( WC_Product $p, int $start ): string {
		if ( ! DSR_History::enabled() ) {
			return '';
		}
		$low = DSR_History::lowest( (int) $p->get_id(), $start > 0 ? $start : time() );
		if ( null === $low ) {
			return '';
		}
		$text = DSR_Settings::text( 't_omnibus' );
		if ( '' === $text ) {
			return '';
		}
		$price = wc_price( wc_get_price_to_display( $p, array( 'price' => $low ) ) );
		$parts = explode( '{price}', esc_html( $text ), 2 );
		return ' <small class="dsr-omnibus">' . $parts[0] . ( isset( $parts[1] ) ? $price . $parts[1] : '' ) . '</small>';
	}

	/** The same line for WooCommerce's own sale price (setting «all»). */
	private static function omnibus_wc( WC_Product $p ): string {
		if ( 'all' !== DSR_Settings::get()['omnibus'] || $p->is_type( 'variable' ) || ! $p->is_on_sale( 'edit' ) ) {
			return '';
		}
		return self::omnibus_line( $p, DSR_History::sale_start( $p ) );
	}

	/* =====================================================================
	 * Product page and lists
	 * =================================================================== */

	/** Product page: end date of a sale rule, the tier tables, the buy-X-pay-Y labels. */
	public static function single(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		try {
			if ( ! self::on() ) {
				return;
			}
			$out = '';
			if ( ! $product->is_type( 'variable' ) ) {
				$s = DSR_Engine::sale_for( $product );
				if ( null !== $s && '' !== $s['to'] ) {
					$out .= '<p class="dsr-until">' . esc_html( DSR_Settings::fill( 't_until', array( 'date' => self::date( $s['to'] ) ) ) ) . '</p>';
				}
			}
			foreach ( DSR_Engine::qty_rules_for( $product ) as $r ) {
				if ( 'xy' === $r['type'] ) {
					$out .= self::label_html( $r, 'p' );
				} elseif ( 'yes' === DSR_Settings::get()['table'] ) {
					$out .= self::tier_table( $r, $product );
				}
			}
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Product lists: the label of a quantity rule under the price. */
	public static function loop(): void {
		global $product;
		if ( ! $product instanceof WC_Product || 'yes' !== DSR_Settings::get()['loop_label'] ) {
			return;
		}
		try {
			if ( ! self::on() ) {
				return;
			}
			foreach ( DSR_Engine::qty_rules_for( $product ) as $r ) {
				echo self::label_html( $r, 'span' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in label_html().
				break;
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * A rule's label, with its end date when it has one.
	 *
	 * @param array  $r   Rule.
	 * @param string $tag p | span.
	 */
	private static function label_html( array $r, string $tag ): string {
		$label = DSR_Rules::label( $r );
		if ( 'tiers' === $r['type'] && '' === $r['label']['el'] ) {
			$label = DSR_Settings::text( 't_tiers' );
		}
		if ( '' === $label ) {
			return '';
		}
		$until = '' !== $r['to'] ? ' <span class="dsr-until">' . esc_html( DSR_Settings::fill( 't_until', array( 'date' => self::date( $r['to'] ) ) ) ) . '</span>' : '';
		$tag   = 'p' === $tag ? 'p' : 'span';
		return '<' . $tag . ' class="dsr-label"><span class="dsr-pill">' . esc_html( $label ) . '</span>' . $until . '</' . $tag . '>';
	}

	/**
	 * The tier table of a rule on the product page.
	 *
	 * @param array      $r Rule.
	 * @param WC_Product $p Product.
	 */
	private static function tier_table( array $r, WC_Product $p ): string {
		$simple = ! $p->is_type( 'variable' ) && (float) $p->get_price( 'edit' ) > 0;
		$reg    = $simple ? DSR_Engine::regular( $p, (float) $p->get_price( 'edit' ) ) : 0.0;
		$rows   = '';
		foreach ( $r['tiers'] as $t ) {
			if ( $simple ) {
				$value = wp_strip_all_tags( wc_price( wc_get_price_to_display( $p, array( 'price' => DSR_Engine::tier_price( $reg, $t ) ) ) ) );
			} elseif ( 'percent' === $t['kind'] ) {
				$value = '−' . wc_format_localized_decimal( round( $t['amount'], 2 ) ) . '%';
			} else {
				$value = '−' . wp_strip_all_tags( wc_price( $t['amount'] ) );
			}
			$rows .= '<li>' . esc_html(
				DSR_Settings::fill(
					't_tier',
					array(
						'min'   => (int) $t['min'],
						'price' => html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ),
					)
				)
			) . '</li>';
		}
		if ( '' === $rows ) {
			return '';
		}
		$title = '' !== $r['label']['el'] ? DSR_Rules::label( $r ) : DSR_Settings::text( 't_tiers' );
		$until = '' !== $r['to'] ? ' <span class="dsr-until">' . esc_html( DSR_Settings::fill( 't_until', array( 'date' => self::date( $r['to'] ) ) ) ) . '</span>' : '';
		return '<div class="dsr-tiers"><p class="dsr-tiers-title">' . esc_html( $title ) . $until . '</p><ul>' . $rows . '</ul></div>';
	}

	/** A rule's 'Y-m-d H:i' end in the store's date format. */
	private static function date( string $v ): string {
		$ts = DSR_Rules::ts( $v );
		return $ts > 0 ? (string) wp_date( (string) get_option( 'date_format', 'j/n/Y' ), $ts ) : '';
	}

	/* =====================================================================
	 * Assets
	 * =================================================================== */

	/** The small stylesheet, on shop pages, while a rule is active. */
	public static function assets(): void {
		try {
			if ( ! self::on() || ! ( is_woocommerce() || is_cart() || is_checkout() || is_front_page() ) ) {
				return;
			}
			if ( empty( DSR_Engine::active( array( 'sale', 'tiers', 'xy', 'cart' ), DSR_Engine::context() ) ) ) {
				return;
			}
			wp_enqueue_style( 'dsr-front', DSR_URL . 'assets/front.css', array(), DSR_VERSION );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
