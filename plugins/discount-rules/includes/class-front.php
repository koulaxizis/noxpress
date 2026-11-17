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
 *  - quantity rules (tiers Β, buy-X-pay-Y Γ): a ribbon with the rule's
 *    label over the product image (lists and product page), an
 *    explanation and a link to the offer page
 *  - the product page: «έως 23/4» for a sale rule with an end, the tier
 *    table (Β) and the offer box of a quantity rule
 *  - the offer page: the shop with ?dsr_offer=<rule id> lists only the
 *    products of that rule, with its label and explanation on top
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
		add_action( 'woocommerce_before_single_product_summary', array( __CLASS__, 'single_ribbon' ), 19 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'loop_ribbon' ), 9 );
		add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'loop' ), 7 );
		add_action( 'woocommerce_product_query', array( __CLASS__, 'offer_query' ) );
		add_filter( 'woocommerce_page_title', array( __CLASS__, 'offer_title' ) );
		add_action( 'woocommerce_archive_description', array( __CLASS__, 'offer_head' ), 5 );
		add_filter( 'wp_robots', array( __CLASS__, 'offer_robots' ) );
		add_filter( 'option_woocommerce_shop_page_display', array( __CLASS__, 'offer_display' ) );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'offer_rm_robots' ) );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'offer_rm_title' ) );
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
					$out .= self::offer_box( $r, 'div' );
				} elseif ( 'yes' === DSR_Settings::get()['table'] ) {
					$out .= self::tier_table( $r, $product ) . self::more_link( $r );
				}
			}
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** The first quantity rule of a product in a list, or null (setting, mode). */
	private static function loop_rule(): ?array {
		global $product;
		if ( ! $product instanceof WC_Product || 'yes' !== DSR_Settings::get()['loop_label'] || ! self::on() ) {
			return null;
		}
		foreach ( DSR_Engine::qty_rules_for( $product ) as $r ) {
			return $r;
		}
		return null;
	}

	/** Product lists: the ribbon over the image (inside the product link, so no link of its own). */
	public static function loop_ribbon(): void {
		try {
			$r = self::loop_rule();
			if ( null !== $r ) {
				echo self::ribbon( $r ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ribbon().
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Product lists: the explanation and the link to the offer page, after the product link. */
	public static function loop(): void {
		try {
			$r = self::loop_rule();
			if ( null !== $r ) {
				echo self::offer_box( $r, 'span' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in offer_box().
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Product page: the ribbon over the gallery (the first quantity rule). */
	public static function single_ribbon(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		try {
			if ( ! self::on() ) {
				return;
			}
			foreach ( DSR_Engine::qty_rules_for( $product ) as $r ) {
				echo self::ribbon( $r, 'single' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ribbon().
				break;
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * The ribbon of a quantity rule: its label, with the explanation as title.
	 *
	 * @param array  $r     Rule.
	 * @param string $where loop | single.
	 */
	private static function ribbon( array $r, string $where = 'loop' ): string {
		$label = self::rule_label( $r );
		if ( '' === $label ) {
			return '';
		}
		$help = self::help( $r );
		$cls  = 'single' === $where ? 'dsr-ribbon dsr-ribbon--single' : 'dsr-ribbon';
		return '<span class="' . $cls . '"' . ( '' !== $help ? ' title="' . esc_attr( $help ) . '"' : '' ) . '>' . esc_html( $label ) . '</span>';
	}

	/** A quantity rule's visitor label (tiers without their own: «Έκπτωση ποσότητας»). */
	private static function rule_label( array $r ): string {
		if ( 'tiers' === $r['type'] && '' === $r['label']['el'] ) {
			return DSR_Settings::text( 't_tiers' );
		}
		return DSR_Rules::label( $r );
	}

	/**
	 * The explanation of a buy-X-pay-Y rule (empty for tiers: the table
	 * explains them). Lists get the short sentence, the product page and
	 * the offer page also the «count together» one.
	 *
	 * @param array $r    Rule.
	 * @param bool  $full With the second sentence.
	 */
	private static function help( array $r, bool $full = true ): string {
		if ( 'xy' !== $r['type'] ) {
			return '';
		}
		$text = DSR_Settings::fill(
			't_xy_help',
			array(
				'buy' => (int) $r['buy'],
				'pay' => (int) $r['pay'],
			)
		);
		if ( $full && 'set' === $r['count'] ) {
			$mix  = DSR_Settings::text( 't_xy_mix' );
			$text = trim( $text . ' ' . $mix );
		}
		return $text;
	}

	/**
	 * The explanation, the end date and the link to the offer page.
	 *
	 * @param array  $r   Rule.
	 * @param string $tag div (product page) | span (lists).
	 */
	private static function offer_box( array $r, string $tag ): string {
		$tag   = 'div' === $tag ? 'div' : 'span';
		$label = self::rule_label( $r );
		$help  = self::help( $r, 'div' === $tag );
		$until = '' !== $r['to'] ? ' <span class="dsr-until">' . esc_html( DSR_Settings::fill( 't_until', array( 'date' => self::date( $r['to'] ) ) ) ) . '</span>' : '';
		$head  = 'div' === $tag && '' !== $label ? '<span class="dsr-pill">' . esc_html( $label ) . '</span>' . $until . ' ' : '';
		$body  = '' !== $help ? '<span class="dsr-help">' . esc_html( $help ) . '</span>' : '';
		if ( 'span' === $tag ) {
			$body .= $until;
		}
		$more  = self::more_link( $r );
		if ( '' === $head . $body . $more ) {
			return '';
		}
		return '<' . $tag . ' class="dsr-offer dsr-offer--' . ( 'div' === $tag ? 'single' : 'loop' ) . '">' . $head . $body . $more . '</' . $tag . '>';
	}

	/** The link to the offer page of a rule. */
	private static function more_link( array $r ): string {
		$text = DSR_Settings::fill( 't_more', array( 'label' => self::rule_label( $r ) ) );
		$url  = self::offer_url( (int) $r['id'] );
		if ( '' === $text || '' === $url ) {
			return '';
		}
		return ' <a class="dsr-more" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
	}

	/** The offer page of a rule: the shop with ?dsr_offer=<id>. */
	public static function offer_url( int $id ): string {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		return ( $id > 0 && '' !== $shop ) ? add_query_arg( 'dsr_offer', $id, $shop ) : '';
	}

	/* =====================================================================
	 * Offer page (?dsr_offer=<rule id> on the shop)
	 * =================================================================== */

	/** @var array|null|false The rule of this request's offer page (false = not looked up yet). */
	private static $offer = false;

	/** The active quantity rule named by ?dsr_offer on the shop page, or null. */
	private static function offer_rule(): ?array {
		if ( false !== self::$offer ) {
			return self::$offer;
		}
		self::$offer = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter of a public list.
		$id = isset( $_GET['dsr_offer'] ) ? absint( wp_unslash( $_GET['dsr_offer'] ) ) : 0;
		if ( $id <= 0 || ! self::on() ) {
			return null;
		}
		$rules = DSR_Engine::active( array( 'tiers', 'xy' ), DSR_Engine::context() );
		if ( isset( $rules[ $id ] ) ) {
			self::$offer = $rules[ $id ];
		}
		return self::$offer;
	}

	/**
	 * The ids of the published products a rule covers now (per request,
	 * nothing is stored: Bible §14.5).
	 *
	 * @param array $r Rule.
	 * @return int[]
	 */
	private static function offer_ids( array $r ): array {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		if ( 'yes' !== $r['all'] ) {
			$or = array( 'relation' => 'OR' );
			foreach ( array( 'cats' => 'product_cat', 'tags' => 'product_tag', 'brands' => 'product_brand' ) as $key => $tax ) {
				if ( ! empty( $r[ $key ] ) && taxonomy_exists( $tax ) ) {
					$or[] = array(
						'taxonomy'         => $tax,
						'terms'            => array_map( 'intval', $r[ $key ] ),
						'include_children' => true,
					);
				}
			}
			$ids = array();
			if ( count( $or ) > 1 ) {
				$ids = get_posts( $args + array( 'tax_query' => $or ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- offer page only.
			}
			foreach ( $r['products'] as $pid ) {
				$p = wc_get_product( (int) $pid );
				if ( $p instanceof WC_Product ) {
					$ids[] = DSR_Engine::main_id( $p );
				}
			}
			$ids = array_unique( array_map( 'intval', $ids ) );
		} else {
			$ids = array_map( 'intval', get_posts( $args ) );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p instanceof WC_Product && 'publish' === $p->get_status() && self::covers( $r, $p ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** True when a rule covers a product or, for a variable product, one of its variations. */
	private static function covers( array $r, WC_Product $p ): bool {
		if ( DSR_Engine::in_scope( $r, $p ) ) {
			return true;
		}
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( (int) $vid );
				if ( $v instanceof WC_Product && DSR_Engine::in_scope( $r, $v ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The shop's main query on an offer page: only the rule's products.
	 *
	 * @param mixed $q WP_Query.
	 */
	public static function offer_query( $q ): void {
		try {
			if ( ! $q instanceof WP_Query || ! $q->is_main_query() || ! function_exists( 'is_shop' ) || ! is_shop() ) {
				return;
			}
			$r = self::offer_rule();
			if ( null === $r ) {
				return;
			}
			$ids  = self::offer_ids( $r );
			$prev = array_filter( array_map( 'intval', (array) $q->get( 'post__in' ) ) );
			if ( ! empty( $prev ) ) {
				$ids = array_values( array_intersect( $ids, $prev ) ); // Another filter (e.g. Shop Filters) narrows it further.
			}
			$q->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * The shop title on an offer page: the rule's label.
	 *
	 * @param mixed $title Title.
	 */
	public static function offer_title( $title ) {
		try {
			if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
				return $title;
			}
			$r = self::offer_rule();
			return null !== $r ? self::rule_label( $r ) : $title;
		} catch ( \Throwable $e ) {
			return $title;
		}
	}

	/** The explanation on top of an offer page. */
	public static function offer_head(): void {
		try {
			if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
				return;
			}
			$r = self::offer_rule();
			if ( null === $r ) {
				return;
			}
			$help  = self::help( $r );
			$until = '' !== $r['to'] ? ' <span class="dsr-until">' . esc_html( DSR_Settings::fill( 't_until', array( 'date' => self::date( $r['to'] ) ) ) ) . '</span>' : '';
			if ( '' === $help && '' === $until ) {
				return;
			}
			echo '<div class="dsr-offer dsr-offer--page"><span class="dsr-help">' . esc_html( $help ) . '</span>' . $until . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Offer pages stay out of search results (a filtered copy of the shop).
	 *
	 * @param mixed $robots Robots directives.
	 */
	public static function offer_robots( $robots ) {
		try {
			if ( is_array( $robots ) && function_exists( 'is_shop' ) && is_shop() && null !== self::offer_rule() ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
			}
		} catch ( \Throwable $e ) {
			return $robots;
		}
		return $robots;
	}

	/** True on the shop page while it shows a valid offer (?dsr_offer=ID). */
	private static function on_offer_page(): bool {
		return did_action( 'wp' ) && function_exists( 'is_shop' ) && is_shop() && null !== self::offer_rule();
	}

	/**
	 * An offer page lists products even when the shop page is set to show
	 * categories (WooCommerce → Products → Shop page display).
	 *
	 * @param mixed $value Option value.
	 * @return mixed
	 */
	public static function offer_display( $value ) {
		try {
			return self::on_offer_page() ? '' : $value;
		} catch ( \Throwable $e ) {
			return $value;
		}
	}

	/**
	 * Rank Math prints its own robots meta and ignores wp_robots.
	 *
	 * @param mixed $robots Rank Math robots array.
	 * @return mixed
	 */
	public static function offer_rm_robots( $robots ) {
		try {
			if ( is_array( $robots ) && self::on_offer_page() ) {
				$robots['index']  = 'noindex';
				$robots['follow'] = 'follow';
			}
		} catch ( \Throwable $e ) {
			return $robots;
		}
		return $robots;
	}

	/**
	 * Rank Math builds the document title itself: name the offer there too.
	 *
	 * @param mixed $title Title.
	 * @return mixed
	 */
	public static function offer_rm_title( $title ) {
		try {
			if ( ! self::on_offer_page() ) {
				return $title;
			}
			return self::rule_label( self::offer_rule() ) . ' | ' . wp_strip_all_tags( get_bloginfo( 'name' ) );
		} catch ( \Throwable $e ) {
			return $title;
		}
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

	/** The small stylesheet, while a rule is active (product lists also appear on ordinary pages through shortcodes). */
	public static function assets(): void {
		try {
			if ( ! self::on() || is_feed() ) {
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
