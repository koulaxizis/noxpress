<?php
/**
 * PFM_Front — what visitors see.
 *
 *  - "Available formats" block on the product page: one entry per format
 *    of the work (label, variant, price, stock note), a link to each other
 *    format and the current one marked (aria-current). The price is
 *    WooCommerce's get_price_html(), so sale prices, variable ranges and
 *    labels of other plugins (e.g. Smart Formatter's "Free") apply as they
 *    are.
 *  - Position: after the price, after the add to cart form, before the
 *    tabs, or manual (shortcode [nox_formats] only). Classic themes use
 *    WooCommerce's hooks; themes that move the price (Astra) are followed
 *    through their own hooks; block themes through render_block. A guard
 *    prints the block once per page.
 *  - Optional line in product lists: "Also: eBook · Audiobook" (plain
 *    text, because the list item is often wrapped in a link).
 *  - Optional hiding of upsells that point to the other formats of the
 *    same work (front end only; the data stays as it is).
 *
 * Bible §14: off until enabled, test mode for shop managers only,
 * PFM_DISABLE stops every hook (the bootstrap does not call init()), page
 * requests only, every callback in try/catch, nothing written, CSS only
 * on pages that need it.
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Front {

	const SHORTCODE = 'nox_formats';

	/** Per-request state. */
	private static $active     = null;
	private static $block_done = false;
	private static $loop_done  = array();
	private static $broken     = false;

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp', array( __CLASS__, 'setup' ) );
	}

	/** Hooks for this page view (runs once the main query is known). */
	public static function setup(): void {
		try {
			if ( ! self::active() ) {
				return;
			}
			$s = PFM_Settings::get();

			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

			// Block themes: only the blocks. WooCommerce's compatibility layer also fires the
			// classic hooks there, around other blocks, which would put the block in the wrong place.
			$blocks = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();

			if ( function_exists( 'is_product' ) && is_product() ) {
				switch ( $s['position'] ) {
					case 'after_price':
						if ( ! $blocks ) {
							add_action( 'astra_woo_single_price_after', array( __CLASS__, 'print_block' ), 10 );
							add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'print_block' ), 11 );
						}
						add_filter( 'render_block_woocommerce/product-price', array( __CLASS__, 'block_after' ), 10, 3 );
						break;
					case 'after_cart':
						if ( ! $blocks ) {
							add_action( 'astra_woo_single_add_to_cart_after', array( __CLASS__, 'print_block' ), 10 );
							add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'print_block' ), 31 );
						}
						add_filter( 'render_block_woocommerce/add-to-cart-form', array( __CLASS__, 'block_after' ), 10, 3 );
						add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( __CLASS__, 'block_after' ), 10, 3 );
						break;
					case 'before_tabs':
						if ( ! $blocks ) {
							add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'print_block' ), 5 );
						}
						add_filter( 'render_block_woocommerce/product-details', array( __CLASS__, 'block_before' ), 10, 3 );
						break;
				}
			}

			if ( $s['loop_line'] ) {
				if ( ! $blocks ) {
					add_action( 'woocommerce_before_shop_loop_item', array( __CLASS__, 'loop_reset' ), 0 );
					add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'loop_line_classic' ), 15 );
					add_action( 'astra_woo_shop_price_after', array( __CLASS__, 'loop_line' ), 10 );
					add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'loop_line' ), 11 );
				}
				add_filter( 'render_block_woocommerce/product-price', array( __CLASS__, 'block_loop_line' ), 11, 3 );
			}

			if ( $s['hide_upsells'] ) {
				add_filter( 'woocommerce_product_get_upsell_ids', array( __CLASS__, 'filter_upsells' ), 10, 2 );
			}
		} catch ( \Throwable $e ) {
			self::$broken = true;
		}
	}

	/* =====================================================================
	 * Gating
	 * =================================================================== */

	public static function active(): bool {
		if ( self::$broken ) {
			return false;
		}
		if ( null !== self::$active ) {
			return self::$active;
		}
		$s  = PFM_Settings::get();
		$ok = ! empty( $s['enabled'] ) && ! Product_Formats::killed() && self::is_page_request();
		if ( $ok && ! empty( $s['test_mode'] ) ) {
			if ( ! did_action( 'set_current_user' ) ) {
				return false; // Not cached: the user is not known yet.
			}
			$ok = current_user_can( 'manage_woocommerce' );
		}
		self::$active = $ok;
		return $ok;
	}

	/** A front-end page view (Bible §14.4). */
	public static function is_page_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			return false;
		}
		return true;
	}

	/* =====================================================================
	 * Assets
	 * =================================================================== */

	public static function assets(): void {
		try {
			$s       = PFM_Settings::get();
			$single  = function_exists( 'is_product' ) && is_product() && 'manual' !== $s['position'];
			$listing = $s['loop_line'] && function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() || is_product() );
			if ( $single || $listing ) {
				self::enqueue();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function enqueue(): void {
		wp_enqueue_style( 'pfm-front', PFM_URL . 'assets/front.css', array(), PFM_VERSION );
	}

	/* =====================================================================
	 * Product page block
	 * =================================================================== */

	/** Action callback: prints the block of the current product, once. */
	public static function print_block(): void {
		if ( self::$block_done || ! self::active() ) {
			return;
		}
		$html = self::render( self::current_product_id() );
		if ( '' !== $html ) {
			self::$block_done = true;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in render().
		}
	}

	/** render_block filter: block after the main product's price / cart form. */
	public static function block_after( $content, $parsed = null, $block = null ) {
		return self::block_attach( (string) $content, $block, false );
	}

	/** render_block filter: block before the main product's details (tabs). */
	public static function block_before( $content, $parsed = null, $block = null ) {
		return self::block_attach( (string) $content, $block, true );
	}

	private static function block_attach( string $content, $block, bool $before ): string {
		try {
			if ( self::$block_done || ! self::active() || ! self::is_main_product_block( $block ) ) {
				return $content;
			}
			$html = self::render( self::current_product_id() );
			if ( '' === $html ) {
				return $content;
			}
			self::$block_done = true;
			return $before ? $html . $content : $content . $html;
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return $content;
		}
	}

	/** A block of the viewed product, not of a product list on the same page. */
	private static function is_main_product_block( $block ): bool {
		if ( is_object( $block ) && isset( $block->context['postId'] ) ) {
			return (int) $block->context['postId'] === (int) get_queried_object_id();
		}
		return true;
	}

	private static function current_product_id(): int {
		global $product;
		if ( $product instanceof WC_Product ) {
			return (int) $product->get_id();
		}
		return (int) get_queried_object_id();
	}

	/** [nox_formats] or [nox_formats id="123"]. */
	public static function shortcode( $atts ): string {
		try {
			if ( ! self::active() ) {
				return '';
			}
			$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
			$id   = absint( $atts['id'] );
			if ( ! $id ) {
				$id = self::current_product_id();
			}
			$html = self::render( $id );
			if ( '' !== $html ) {
				self::enqueue();
				if ( $id === self::current_product_id() ) {
					self::$block_done = true;
				}
			}
			return $html;
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	/**
	 * The block HTML for a product, '' when it is in no work or the work
	 * has fewer than two formats a visitor can open.
	 */
	public static function render( int $product_id ): string {
		try {
			if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
				return '';
			}
			$work = PFM_Works::work_of( $product_id );
			if ( ! $work ) {
				return '';
			}
			$members = PFM_Works::visible_members( $work );
			$ids     = wp_list_pluck( $members, 'id' );
			if ( ! in_array( $product_id, $ids, true ) ) {
				// The viewed product may be hidden from the catalog: it still shows itself.
				foreach ( PFM_Works::members( $work ) as $m ) {
					if ( $m['id'] === $product_id ) {
						$members[] = $m;
					}
				}
				$members = PFM_Works::sort( $members );
			}
			if ( count( $members ) < 2 ) {
				return '';
			}

			$s     = PFM_Settings::get();
			$items = '';
			foreach ( $members as $m ) {
				$p = wc_get_product( $m['id'] );
				if ( ! $p ) {
					continue;
				}
				$items .= self::item( $p, $m, $m['id'] === $product_id, ! empty( $s['show_stock'] ) );
			}
			if ( '' === $items ) {
				return '';
			}
			$title = self::block_title( $s );
			return '<div class="pfm-formats" role="navigation" aria-label="' . esc_attr( $title ) . '">'
				. '<div class="pfm-formats__title">' . esc_html( $title ) . '</div>'
				. '<div class="pfm-formats__list" role="list">' . $items . '</div>'
				. '</div>';
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	private static function item( WC_Product $p, array $m, bool $current, bool $stock ): string {
		$label = PFM_Settings::label( $m['format'] );
		if ( '' === $label ) {
			$label = $p->get_name();
		}
		$inner = '<span class="pfm-format__label">' . esc_html( $label ) . '</span>';
		if ( '' !== $m['variant'] ) {
			$inner .= '<span class="pfm-format__variant">' . esc_html( $m['variant'] ) . '</span>';
		}
		$price = (string) $p->get_price_html();
		if ( '' !== trim( wp_strip_all_tags( $price ) ) ) {
			$inner .= '<span class="pfm-format__price">' . wp_kses_post( $price ) . '</span>';
		}
		if ( $stock && $p->is_purchasable() && ! $p->is_in_stock() ) {
			$inner .= '<span class="pfm-format__stock">' . esc_html__( 'Εξαντλημένο', 'product-formats' ) . '</span>';
		}
		// The listitem role sits on a wrapper so the link keeps its own role.
		if ( $current ) {
			return '<div class="pfm-formats__item" role="listitem"><span class="pfm-format pfm-format--current" aria-current="page">' . $inner . '</span></div>';
		}
		return '<div class="pfm-formats__item" role="listitem"><a class="pfm-format" href="' . esc_url( $p->get_permalink() ) . '">' . $inner . '</a></div>';
	}

	private static function block_title( array $s ): string {
		$custom = 'en' === PFM_Lang::lang() ? $s['title_en'] : $s['title_el'];
		return '' !== $custom ? $custom : __( 'Διαθέσιμες μορφές', 'product-formats' );
	}

	/* =====================================================================
	 * Line in product lists
	 * =================================================================== */

	public static function loop_reset(): void {
		self::$loop_done = array();
	}

	/** woocommerce_after_shop_loop_item_title: only where the theme keeps WooCommerce's loop price there. */
	public static function loop_line_classic(): void {
		if ( false === has_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price' ) ) {
			return; // The theme prints the price elsewhere (e.g. Astra): its own hook or the fallback prints the line.
		}
		self::loop_line();
	}

	public static function loop_line(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$id = (int) $product->get_id();
		if ( isset( self::$loop_done[ $id ] ) ) {
			return;
		}
		$html = self::line( $id );
		self::$loop_done[ $id ] = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in line().
	}

	/** Block themes: the line after the price of a product in a list. */
	public static function block_loop_line( $content, $parsed = null, $block = null ) {
		try {
			if ( ! is_object( $block ) || ! isset( $block->context['postId'] ) ) {
				return $content;
			}
			$id = (int) $block->context['postId'];
			if ( $id === (int) get_queried_object_id() && function_exists( 'is_product' ) && is_product() ) {
				return $content; // The main product: it has the block.
			}
			if ( isset( self::$loop_done[ $id ] ) ) {
				return $content;
			}
			// Marked done, so a classic hook fired by a later block (e.g. the add to cart button) adds nothing.
			self::$loop_done[ $id ] = true;
			$line  = self::line( $id );
			$align = is_array( $parsed ) && isset( $parsed['attrs']['textAlign'] ) ? sanitize_key( (string) $parsed['attrs']['textAlign'] ) : '';
			if ( '' !== $line && in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
				$line = str_replace( '<span class="pfm-also">', '<span class="pfm-also" style="text-align:' . $align . '">', $line );
			}
			return (string) $content . $line;
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return $content;
		}
	}

	/** "Also: eBook · Audiobook" for a product in a list, or ''. */
	public static function line( int $product_id ): string {
		try {
			if ( ! self::active() ) {
				return '';
			}
			$work = PFM_Works::work_of( $product_id );
			if ( ! $work ) {
				return '';
			}
			$labels = array();
			foreach ( PFM_Works::visible_members( $work ) as $m ) {
				if ( $m['id'] === $product_id ) {
					continue;
				}
				$label = PFM_Settings::label( $m['format'] );
				if ( '' !== $label ) {
					$labels[ $label ] = true;
				}
			}
			if ( ! $labels ) {
				return '';
			}
			return '<span class="pfm-also">' . esc_html__( 'Επίσης:', 'product-formats' ) . ' '
				. esc_html( implode( ' · ', array_keys( $labels ) ) ) . '</span>';
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	/* =====================================================================
	 * Upsells
	 * =================================================================== */

	/** Removes the other formats of the same work from the upsells (view only). */
	public static function filter_upsells( $ids, $product = null ) {
		try {
			if ( ! is_array( $ids ) || ! $ids || ! $product instanceof WC_Product || ! self::active() ) {
				return $ids;
			}
			$work = PFM_Works::work_of( (int) $product->get_id() );
			if ( ! $work ) {
				return $ids;
			}
			$siblings = wp_list_pluck( PFM_Works::members( $work ), 'id' );
			return array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $siblings ) {
						return ! in_array( (int) $id, $siblings, true );
					}
				)
			);
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return $ids;
		}
	}
}
