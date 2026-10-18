<?php
/**
 * POR_Front — what visitors see on products (Bible §14).
 *
 *  - Badge «Pre-order» on product cards and on the product page (setting
 *    «badge»), as a small inline pill next to the title or price, so no
 *    theme positioning is needed. Classic themes: WooCommerce's loop and
 *    summary hooks. Block themes: render_block of the Product Price block
 *    only (WooCommerce's compatibility layer also fires the classic hooks
 *    around blocks, which would print it twice).
 *  - Button «Pre-order» instead of «Add to cart» (add_to_cart_text and
 *    single_add_to_cart_text, which the Product Button block reads too);
 *    on variable products a few lines of JS swap the text when a variation
 *    on pre-order is chosen (data from woocommerce_available_variation).
 *  - Availability «Releases on …» / «Coming soon» on the product page
 *    (woocommerce_get_availability_text, read by the Stock Indicator
 *    block and the variation form too). Out of stock stays out of stock.
 *  - Structured data: availability PreOrder and availabilityStarts on the
 *    Offer (WooCommerce's filter, and Rank Math's product entity when it
 *    prints the graph instead, Bible §14.20). Never a second graph.
 *  - Shipping Notes: the release date feeds shn_available_from, so its
 *    delivery estimate counts from the release day (Bible §6).
 *  - [nox_preorders] and the block noxpress/preorders: the products on
 *    pre-order as the theme's own cards, nearest release first.
 *
 * Nothing runs while the mode is off, in test mode only for shop
 * managers, never in admin / feeds / REST (the Store API reads the cart
 * texts of POR_Cart). Each callback is wrapped in try/catch and returns
 * the original output on error (Bible §14.6). With no product on
 * pre-order in the store (por_horizon), no CSS or JS is printed.
 */

defined( 'ABSPATH' ) || exit;

final class POR_Front {

	/** @var bool Set after an error: the plugin stays out of the rest of the request. */
	private static $broken = false;

	public static function init(): void {
		add_action( 'wp', array( __CLASS__, 'boot' ) );
		add_shortcode( 'nox_preorders', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );

		// Data that other code reads in any context (Store API carts, feeds of
		// Shipping Notes estimates): follows the mode, never edits anything.
		add_filter( 'shn_available_from', array( __CLASS__, 'shn_date' ), 10, 2 );
	}

	/** True on a front-end page view where the treatment may run. */
	private static function front(): bool {
		if ( self::$broken || is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_embed() ) {
			return false;
		}
		return true;
	}

	/** wp: hooks for this page view, only when something can be on pre-order. */
	public static function boot(): void {
		if ( ! self::front() || ! POR_Settings::visible() || ! POR_Product::any_active() ) {
			return;
		}
		$s = POR_Settings::get();

		if ( in_array( $s['badge'], array( 'both', 'loop', 'single' ), true ) ) {
			if ( wp_is_block_theme() ) {
				add_filter( 'render_block_woocommerce/product-price', array( __CLASS__, 'block_price' ), 10, 3 );
			} else {
				if ( 'single' !== $s['badge'] ) {
					add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'loop_badge' ), 4 );
				}
				if ( 'loop' !== $s['badge'] ) {
					add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_badge' ), 6 );
				}
			}
			add_action( 'wp_head', array( __CLASS__, 'css' ), 20 );
		}

		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'button_text' ), 20, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'button_text' ), 20, 2 );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'availability' ), 20, 2 );
		add_filter( 'woocommerce_get_availability_class', array( __CLASS__, 'availability_class' ), 20, 2 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'variation_js' ), 30 );
		add_filter( 'woocommerce_structured_data_product_offer', array( __CLASS__, 'schema_offer' ), 20, 2 );
		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( __CLASS__, 'schema_rank_math' ), 20 );
	}

	/** Stops the plugin for the rest of the request after an error (Bible §14.6). */
	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Pre-Orders: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/* =====================================================================
	 * Badge
	 * =================================================================== */

	private static function badge_html( string $where ): string {
		return '<span class="por-badge por-badge--' . esc_attr( $where ) . '">' . esc_html( POR_Settings::text( 't_badge' ) ) . '</span>';
	}

	/** The product of the loop or page, when it (or a variation) is on pre-order. */
	private static function current(): ?WC_Product {
		global $product;
		$p = $product instanceof WC_Product ? $product : wc_get_product( get_the_ID() );
		return ( $p instanceof WC_Product && null !== POR_Product::catalog_date( $p ) ) ? $p : null;
	}

	public static function loop_badge(): void {
		if ( self::$broken ) {
			return;
		}
		try {
			if ( self::current() ) {
				echo self::badge_html( 'loop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge_html().
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	public static function single_badge(): void {
		if ( self::$broken ) {
			return;
		}
		try {
			if ( is_product() && self::current() ) {
				echo '<p class="por-badge-row">' . self::badge_html( 'single' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge_html().
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Block themes: the badge before the Product Price block (cards and
	 * product page), from the block's postId context.
	 */
	public static function block_price( $html, $block = array(), $instance = null ) {
		if ( self::$broken || '' === trim( (string) $html ) ) {
			return $html;
		}
		try {
			$pid = ( $instance instanceof WP_Block && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : (int) get_the_ID();
			$p   = $pid ? wc_get_product( $pid ) : null;
			if ( ! $p instanceof WC_Product || null === POR_Product::catalog_date( $p ) ) {
				return $html;
			}
			$single = is_product() && (int) get_queried_object_id() === (int) $p->get_id() && empty( $instance->context['query'] );
			$badge  = POR_Settings::get()['badge'];
			if ( ( $single && 'loop' === $badge ) || ( ! $single && 'single' === $badge ) ) {
				return $html;
			}
			return '<div class="por-badge-row">' . self::badge_html( $single ? 'single' : 'loop' ) . '</div>' . $html;
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $html;
		}
	}

	/** One small scoped style block in the head (Bible §14.9, §14.13). */
	public static function css(): void {
		if ( self::$broken ) {
			return;
		}
		echo "<style id=\"por-front\">\n"
			. ".por-badge-row{display:block!important;float:none!important;margin:0 0 .5em!important;padding:0!important;border:0!important;background:none!important;line-height:1!important;}\n"
			. "span.por-badge{display:inline-block!important;float:none!important;position:static!important;width:auto!important;height:auto!important;margin:0 0 .4em!important;padding:.35em .7em!important;border:0!important;border-radius:999px!important;background:var(--por-badge-bg,#6d4aff)!important;color:var(--por-badge-fg,#fff)!important;font-size:.75em!important;font-weight:600!important;line-height:1.2!important;letter-spacing:.02em!important;text-transform:none!important;text-decoration:none!important;box-shadow:none!important;white-space:nowrap!important;vertical-align:middle!important;}\n"
			. "</style>\n";
	}

	/* =====================================================================
	 * Button, availability, variations
	 * =================================================================== */

	/** «Pre-order» on simple products and variations on pre-order that can be bought. */
	public static function button_text( $text, $product = null ) {
		if ( self::$broken || ! $product instanceof WC_Product ) {
			return $text;
		}
		try {
			if ( $product->is_type( 'variable' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				return $text;
			}
			if ( null !== POR_Product::active( $product ) ) {
				$t = POR_Settings::text( 't_button' );
				return '' !== $t ? $t : $text;
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $text;
	}

	/** «Releases on …» instead of the stock text, unless the product is out of stock. */
	public static function availability( $text, $product = null ) {
		if ( self::$broken || ! $product instanceof WC_Product ) {
			return $text;
		}
		try {
			if ( ! $product->is_in_stock() ) {
				return $text;
			}
			$a = POR_Product::active( $product );
			if ( null !== $a ) {
				return POR_Product::availability_text( $a );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $text;
	}

	public static function availability_class( $class, $product = null ) {
		if ( self::$broken || ! $product instanceof WC_Product ) {
			return $class;
		}
		try {
			if ( $product->is_in_stock() && null !== POR_Product::active( $product ) ) {
				return trim( (string) $class . ' por-release' );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $class;
	}

	/** Variation data: the button text of a variation on pre-order. */
	public static function variation_data( $data, $parent = null, $variation = null ) {
		if ( self::$broken || ! is_array( $data ) || ! $variation instanceof WC_Product ) {
			return $data;
		}
		try {
			if ( null !== POR_Product::active( $variation ) && $variation->is_in_stock() ) {
				$data['por_button'] = POR_Settings::text( 't_button' );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $data;
	}

	/** A few lines on the variable product page: swap the button text. */
	public static function variation_js(): void {
		if ( self::$broken || ! is_product() ) {
			return;
		}
		try {
			$p = wc_get_product( get_queried_object_id() );
			if ( ! $p instanceof WC_Product || ! $p->is_type( 'variable' ) || null === POR_Product::catalog_date( $p ) ) {
				return;
			}
			$js = "jQuery(function($){var f=$('form.variations_form');f.each(function(){var b=$(this).find('.single_add_to_cart_button'),o=b.text();"
				. "$(this).on('found_variation',function(e,v){b.text(v&&v.por_button?v.por_button:o);}).on('reset_data hide_variation',function(){b.text(o);});});});";
			wp_add_inline_script( 'wc-add-to-cart-variation', $js );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Structured data (Bible §14.20)
	 * =================================================================== */

	/**
	 * The release date for the schema: simple products and variable products
	 * whose every purchasable variation is on pre-order. Null = no change.
	 */
	private static function schema_date( WC_Product $p ): ?string {
		if ( ! $p->is_type( 'variable' ) ) {
			$a = POR_Product::active( $p );
			return ( null !== $a && $p->is_in_stock() ) ? $a['date'] : null;
		}
		if ( null === POR_Product::catalog_date( $p ) ) {
			return null;
		}
		$api = POR_Product::api( $p );
		if ( null === $api ) {
			return null;
		}
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v instanceof WC_Product && $v->is_purchasable() && $v->is_in_stock() && ! isset( $api['variations'][ (int) $vid ] ) ) {
				return null; // Some variation is available now.
			}
		}
		return $api['date'];
	}

	/** Changes one offer array (WooCommerce or Rank Math shape). */
	private static function offer( array $offer, string $date ): array {
		if ( isset( $offer['availability'] ) && false !== strpos( (string) $offer['availability'], 'OutOfStock' ) ) {
			return $offer;
		}
		$offer['availability'] = 'https://schema.org/PreOrder';
		if ( '' !== $date ) {
			$offer['availabilityStarts'] = $date;
		}
		return $offer;
	}

	public static function schema_offer( $offer, $product = null ) {
		if ( self::$broken || ! is_array( $offer ) || ! $product instanceof WC_Product ) {
			return $offer;
		}
		try {
			$date = self::schema_date( $product );
			return null === $date ? $offer : self::offer( $offer, $date );
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $offer;
		}
	}

	/** Rank Math prints its own Product entity instead of WooCommerce's. */
	public static function schema_rank_math( $entity ) {
		if ( self::$broken || ! is_array( $entity ) || ! is_product() ) {
			return $entity;
		}
		try {
			$p = wc_get_product( get_queried_object_id() );
			if ( ! $p instanceof WC_Product || ! isset( $entity['offers'] ) || ! is_array( $entity['offers'] ) ) {
				return $entity;
			}
			$date = self::schema_date( $p );
			if ( null === $date ) {
				return $entity;
			}
			if ( isset( $entity['offers']['@type'] ) ) {
				$entity['offers'] = self::offer( $entity['offers'], $date );
			} else {
				foreach ( $entity['offers'] as $k => $o ) {
					if ( is_array( $o ) ) {
						$entity['offers'][ $k ] = self::offer( $o, $date );
					}
				}
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $entity;
	}

	/* =====================================================================
	 * Shipping Notes (Bible §6)
	 * =================================================================== */

	/** shn_available_from: our release date wins when the product is on pre-order with a date. */
	public static function shn_date( $date, $product = null ) {
		if ( self::$broken || ! $product instanceof WC_Product || ! POR_Settings::visible() ) {
			return $date;
		}
		try {
			$a = POR_Product::active( $product );
			if ( null === $a && $product->get_parent_id() ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$d      = $parent instanceof WC_Product ? POR_Product::catalog_date( $parent ) : null;
				return ( null !== $d && '' !== $d ) ? $d : $date;
			}
			return ( null !== $a && '' !== $a['date'] ) ? $a['date'] : $date;
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $date;
		}
	}

	/* =====================================================================
	 * [nox_preorders] and the block
	 * =================================================================== */

	/**
	 * The products on pre-order as the theme's cards.
	 *
	 * @param array|string $atts limit (1–48, default 12), columns (1–6, default 4).
	 */
	public static function shortcode( $atts = array() ): string {
		if ( Pre_Orders::killed() || ! POR_Settings::visible() || ! POR_Product::any_active() ) {
			return '';
		}
		try {
			$atts = shortcode_atts(
				array(
					'limit'   => 12,
					'columns' => 4,
				),
				is_array( $atts ) ? $atts : array(),
				'nox_preorders'
			);
			$limit = max( 1, min( 48, (int) $atts['limit'] ) );
			$cols  = max( 1, min( 6, (int) $atts['columns'] ) );
			$ids   = self::listing( $limit );
			return $ids ? self::loop( $ids, $cols ) : '';
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return '';
		}
	}

	/** IDs of the visible catalog products on pre-order, nearest release first. */
	private static function listing( int $limit ): array {
		$visibility = wc_get_product_visibility_term_ids();
		$not_in     = array();
		if ( ! empty( $visibility['exclude-from-catalog'] ) ) {
			$not_in[] = (int) $visibility['exclude-from-catalog'];
		}
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! empty( $visibility['outofstock'] ) ) {
			$not_in[] = (int) $visibility['outofstock'];
		}
		$q = new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'fields'              => 'ids',
				'meta_key'            => POR_Product::M_SORT, // phpcs:ignore WordPress.DB.SlowDBQuery -- our own derived meta.
				'meta_value'          => POR_Settings::today(), // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare'        => '>',
				'orderby'             => array(
					'meta_value' => 'ASC',
					'ID'         => 'DESC',
				),
				'tax_query'           => $not_in ? array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'term_taxonomy_id',
						'terms'    => $not_in,
						'operator' => 'NOT IN',
					),
				) : array(),
			)
		);
		return array_map( 'intval', (array) $q->posts );
	}

	/** Products as the theme's own WooCommerce cards (Bible §14.19). */
	private static function loop( array $ids, int $cols ): string {
		global $post;
		$orig = $post;
		wc_setup_loop(
			array(
				'name'         => 'por_preorders',
				'columns'      => $cols,
				'is_shortcode' => true,
				'is_paginated' => false,
				'total'        => count( $ids ),
				'per_page'     => count( $ids ),
			)
		);
		ob_start();
		echo '<div class="woocommerce columns-' . (int) $cols . ' por-preorders">';
		woocommerce_product_loop_start();
		foreach ( $ids as $id ) {
			$post = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			setup_postdata( $post );
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();
		echo '</div>';
		$html = (string) ob_get_clean();

		$post = $orig; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restore.
		if ( $orig instanceof WP_Post ) {
			setup_postdata( $orig );
		} else {
			wp_reset_postdata();
		}
		wc_reset_loop();
		return $html;
	}

	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'por-blocks', POR_URL . 'assets/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ), POR_VERSION, true );
		wp_localize_script(
			'por-blocks',
			'porBlocks',
			array(
				'title'   => __( 'Προπαραγγελίες (Pre-Orders)', 'pre-orders' ),
				'desc'    => __( 'Τα προϊόντα σε προπαραγγελία, με πρώτη την πιο κοντινή κυκλοφορία.', 'pre-orders' ),
				'limit'   => __( 'Πλήθος προϊόντων', 'pre-orders' ),
				'columns' => __( 'Στήλες', 'pre-orders' ),
				'note'    => __( 'Εμφανίζεται στο site με τις κάρτες προϊόντων του θέματος.', 'pre-orders' ),
			)
		);
		register_block_type(
			'noxpress/preorders',
			array(
				'api_version'     => 2,
				'editor_script'   => 'por-blocks',
				'attributes'      => array(
					'limit'   => array(
						'type'    => 'number',
						'default' => 12,
					),
					'columns' => array(
						'type'    => 'number',
						'default' => 4,
					),
				),
				'render_callback' => array( __CLASS__, 'render_block' ),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
	}

	public static function render_block( $attributes = array() ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();
		return self::shortcode(
			array(
				'limit'   => isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 12,
				'columns' => isset( $attributes['columns'] ) ? (int) $attributes['columns'] : 4,
			)
		);
	}
}
