<?php
/**
 * SHN_Front — everything the visitor sees (Bible §14).
 *
 * Free-shipping bar (fs):
 *  - classic cart: woocommerce_before_cart_totals (inside .cart_totals) or
 *    woocommerce_before_cart_table (inside the cart form); cart.js reloads
 *    both after every change. Empty cart page: woocommerce_cart_is_empty.
 *  - classic mini-cart: woocommerce_before_mini_cart, inside
 *    div.widget_shopping_cart_content, which is already a fragment.
 *  - classic checkout: woocommerce_review_order_before_payment, refreshed
 *    through woocommerce_update_order_review_fragments.
 *  - every div.shn-fs-wrap (shortcode, block) is a cart fragment too
 *    (woocommerce_add_to_cart_fragments).
 *  - Cart / Checkout blocks: read-only data in the Store API cart response
 *    (extensions.shipping-notes.html), drawn by assets/blocks-cart.js in
 *    the ExperimentalOrderMeta slot; assets/live.js redraws shortcodes and
 *    blocks on pages where the block cart store (wc/store/cart) exists.
 *  No endpoint of our own: only WooCommerce's refresh mechanisms.
 *
 * Delivery time (dl): product page (after the price or the add-to-cart
 * form; classic hooks, Astra hooks, or render_block on block themes),
 * [nox_delivery] and the noxpress/delivery block. Dates and the cut-off
 * countdown come from assets/delivery.js.
 *
 * Rules: off until enabled; test mode shows only to shop managers;
 * SHN_DISABLE stops everything; never in admin pages, REST (except the
 * read-only Store API cart data), feeds, cron, CLI or XML-RPC; the cart
 * parts also run on WooCommerce's cart AJAX (Bible §14.4 exception). Every
 * callback is wrapped in try/catch: on an error the plugin stops for the
 * rest of the request and the original output stays.
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Front {

	const SC_FS    = 'nox_free_shipping';
	const SC_DL    = 'nox_delivery';
	const NS       = 'shipping-notes';
	const SELECTOR = 'div.shn-fs-wrap';

	/** Per-request state. */
	private static $broken    = false;
	private static $store_api = false;
	private static $dl_done   = false;
	private static $cart_done = false;
	private static $css       = false;
	private static $dl_js     = false;
	private static $live_js   = false;

	public static function init(): void {
		add_shortcode( self::SC_FS, array( __CLASS__, 'shortcode_fs' ) );
		add_shortcode( self::SC_DL, array( __CLASS__, 'shortcode_dl' ) );
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );

		$s = SHN_Settings::get();

		if ( 'off' !== $s['fs_mode'] ) {
			if ( $s['fs_cart'] ) {
				add_action( 'totals' === $s['fs_cart_pos'] ? 'woocommerce_before_cart_totals' : 'woocommerce_before_cart_table', array( __CLASS__, 'print_cart' ), 5 );
				add_action( 'woocommerce_cart_is_empty', array( __CLASS__, 'print_cart_empty' ), 5 );
			}
			if ( $s['fs_minicart'] ) {
				add_action( 'woocommerce_before_mini_cart', array( __CLASS__, 'print_fs' ), 5 );
			}
			if ( $s['fs_checkout'] ) {
				add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'print_fs' ), 5 );
				add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'fragments' ) );
			}
			add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'fragments' ) );
			add_action( 'woocommerce_init', array( __CLASS__, 'store_api_register' ) );
		}

		if ( 'off' !== $s['dl_mode'] && 'none' !== $s['dl_position'] ) {
			add_action( 'wp', array( __CLASS__, 'setup_product' ) );
		}

		if ( 'off' !== $s['fs_mode'] || 'off' !== $s['dl_mode'] ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
	}

	/* =====================================================================
	 * Gating
	 * =================================================================== */

	/**
	 * Whether the visitor sees a part now: 'fs' (free shipping) or 'dl'
	 * (delivery time).
	 */
	public static function visible( string $part ): bool {
		if ( self::$broken || Shipping_Notes::killed() ) {
			return false;
		}
		$mode = SHN_Settings::get()[ 'fs' === $part ? 'fs_mode' : 'dl_mode' ];
		if ( 'off' === $mode || ! self::context_ok( 'fs' === $part ) ) {
			return false;
		}
		if ( 'test' === $mode ) {
			return did_action( 'set_current_user' ) && current_user_can( 'manage_woocommerce' );
		}
		return true;
	}

	/**
	 * A front-end page view; with $cart, also WooCommerce's cart AJAX and
	 * the Store API cart data callback.
	 */
	private static function context_ok( bool $cart ): bool {
		if ( wp_doing_cron() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $cart && self::$store_api;
		}
		if ( wp_doing_ajax() ) {
			return $cart;
		}
		if ( is_admin() ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			return false;
		}
		return true;
	}

	/** Stops the plugin for the rest of the request (Bible §14.6). */
	private static function fail(): void {
		self::$broken = true;
	}

	/* =====================================================================
	 * Free shipping: HTML
	 * =================================================================== */

	/** The bar for the current cart ('' when nothing shows), without the wrapper. */
	public static function fs_inner(): string {
		$st = SHN_Threshold::for_cart();
		if ( null === $st ) {
			return '';
		}
		return self::fs_html( $st );
	}

	/** The bar HTML of a state (also used by the admin preview). */
	public static function fs_html( array $st ): string {
		$s = SHN_Settings::get();
		if ( ( 'empty' === $st['state'] && ! $s['fs_show_empty'] ) || ( 'won' === $st['state'] && ! $s['fs_show_won'] ) ) {
			return '';
		}
		$key  = array(
			'empty' => 'fs_t_empty',
			'left'  => 'fs_t_left',
			'won'   => 'fs_t_won',
		)[ $st['state'] ];
		$tpl  = SHN_Settings::text( $key );
		$vals = array(
			'{threshold}' => wc_price( $st['threshold'] ),
			'{remaining}' => wc_price( $st['remaining'] ),
			'{total}'     => wc_price( $st['total'] ),
			'{percent}'   => (string) (int) $st['percent'],
		);
		$text  = strtr( esc_html( $tpl ), $vals );
		$plain = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
		$style = '' !== $s['fs_color'] ? ' style="--shn-color:' . esc_attr( $s['fs_color'] ) . '"' : '';

		$html = '<div class="shn-fs shn-fs--' . esc_attr( $st['state'] ) . '"' . $style . '>'
			. '<p class="shn-fs__text">' . $text . '</p>';
		if ( $s['fs_bar'] && 'empty' !== $st['state'] ) {
			$html .= '<div class="shn-fs__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $st['percent'] . '" aria-label="' . esc_attr( $plain ) . '">'
				. '<span class="shn-fs__fill" style="width:' . (int) $st['percent'] . '%"></span></div>';
		}
		return $html . '</div>';
	}

	/** The wrapper every classic place prints (kept even when empty, so a fragment can fill it). */
	public static function fs_wrapped(): string {
		return '<div class="shn-fs-wrap">' . self::fs_inner() . '</div>';
	}

	/* =====================================================================
	 * Free shipping: places
	 * =================================================================== */

	/** Mini-cart / checkout action. */
	public static function print_fs(): void {
		try {
			if ( ! self::visible( 'fs' ) ) {
				return;
			}
			self::css();
			echo self::fs_wrapped(); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in fs_html().
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/** Classic cart (once per request). */
	public static function print_cart(): void {
		if ( self::$cart_done ) {
			return;
		}
		self::$cart_done = true;
		self::print_fs();
	}

	/** Empty classic cart page: the "free shipping from X" message. */
	public static function print_cart_empty(): void {
		if ( self::$cart_done || ! SHN_Settings::get()['fs_show_empty'] ) {
			return;
		}
		self::$cart_done = true;
		self::print_fs();
	}

	/** woocommerce_add_to_cart_fragments / woocommerce_update_order_review_fragments. */
	public static function fragments( $fragments ) {
		try {
			if ( is_array( $fragments ) && self::visible( 'fs' ) ) {
				$fragments[ self::SELECTOR ] = self::fs_wrapped();
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
		return $fragments;
	}

	/** Store API: read-only bar HTML in the cart response (Cart and Checkout blocks). */
	public static function store_api_register(): void {
		try {
			if ( Shipping_Notes::killed() || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace'       => self::NS,
					'data_callback'   => array( __CLASS__, 'store_api_data' ),
					'schema_callback' => static function () {
						return array(
							'html'     => array(
								'description' => 'Free shipping bar (HTML).',
								'type'        => 'string',
								'readonly'    => true,
							),
							'cart'     => array(
								'description' => 'Show in the Cart block.',
								'type'        => 'boolean',
								'readonly'    => true,
							),
							'checkout' => array(
								'description' => 'Show in the Checkout block.',
								'type'        => 'boolean',
								'readonly'    => true,
							),
						);
					},
					'schema_type'     => ARRAY_A,
				)
			);
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	public static function store_api_data(): array {
		$s    = SHN_Settings::get();
		$html = '';
		try {
			self::$store_api = true;
			$html            = self::visible( 'fs' ) ? self::fs_inner() : '';
		} catch ( \Throwable $e ) {
			self::fail();
			$html = '';
		} finally {
			self::$store_api = false;
		}
		return array(
			'html'     => $html,
			'cart'     => (bool) $s['fs_block_cart'],
			'checkout' => (bool) $s['fs_block_checkout'],
		);
	}

	/** [nox_free_shipping] — anywhere; kept fresh as a cart fragment. */
	public static function shortcode_fs( $atts = array() ): string {
		try {
			if ( ! self::visible( 'fs' ) ) {
				return '';
			}
			self::css();
			self::fragments_js();
			return self::fs_wrapped();
		} catch ( \Throwable $e ) {
			self::fail();
			return '';
		}
	}

	/* =====================================================================
	 * Delivery time
	 * =================================================================== */

	/** Hooks for the product page view (runs once the main query is known). */
	public static function setup_product(): void {
		try {
			if ( ! function_exists( 'is_product' ) || ! is_product() || ! self::visible( 'dl' ) ) {
				return;
			}
			// Block themes: only the blocks. WooCommerce's compatibility layer also fires the
			// classic hooks there, around other blocks, which would put the text in the wrong place.
			$blocks = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
			if ( 'price' === SHN_Settings::get()['dl_position'] ) {
				if ( ! $blocks ) {
					add_action( 'astra_woo_single_price_after', array( __CLASS__, 'print_dl' ), 10 );
					add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'print_dl' ), 11 );
				}
				add_filter( 'render_block_woocommerce/product-price', array( __CLASS__, 'block_after' ), 10, 3 );
			} else {
				if ( ! $blocks ) {
					add_action( 'astra_woo_single_add_to_cart_after', array( __CLASS__, 'print_dl' ), 10 );
					add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'print_dl' ), 31 );
				}
				add_filter( 'render_block_woocommerce/add-to-cart-form', array( __CLASS__, 'block_after' ), 10, 3 );
				add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( __CLASS__, 'block_after' ), 10, 3 );
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/** Action callback: the estimate of the viewed product, once. */
	public static function print_dl(): void {
		try {
			if ( self::$dl_done || ! self::visible( 'dl' ) ) {
				return;
			}
			$html = self::dl_html( self::current_product_id() );
			if ( '' !== $html ) {
				self::$dl_done = true;
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in SHN_Delivery::render().
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/** render_block filter: after the viewed product's price / add-to-cart form. */
	public static function block_after( $content, $parsed = null, $block = null ) {
		try {
			$content = (string) $content;
			if ( self::$dl_done || ! self::visible( 'dl' ) ) {
				return $content;
			}
			if ( is_object( $block ) && isset( $block->context['postId'] ) && (int) $block->context['postId'] !== (int) get_queried_object_id() ) {
				return $content; // A product list on the same page.
			}
			$html = self::dl_html( (int) get_queried_object_id() );
			if ( '' === $html ) {
				return $content;
			}
			self::$dl_done = true;
			return $content . $html;
		} catch ( \Throwable $e ) {
			self::fail();
			return (string) $content;
		}
	}

	/** [nox_delivery] or [nox_delivery id="123"]. */
	public static function shortcode_dl( $atts = array() ): string {
		try {
			if ( ! self::visible( 'dl' ) ) {
				return '';
			}
			$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), self::SC_DL );
			$id   = absint( $atts['id'] );
			if ( ! $id ) {
				$id = self::current_product_id();
			}
			$html = self::dl_html( $id );
			if ( '' !== $html && $id === self::current_product_id() ) {
				self::$dl_done = true;
			}
			return $html;
		} catch ( \Throwable $e ) {
			self::fail();
			return '';
		}
	}

	private static function dl_html( int $id ): string {
		if ( $id <= 0 || 'product' !== get_post_type( $id ) ) {
			return '';
		}
		$html = SHN_Delivery::render( wc_get_product( $id ) );
		if ( '' !== $html ) {
			self::css();
		}
		return $html;
	}

	private static function current_product_id(): int {
		global $product;
		if ( $product instanceof WC_Product ) {
			return (int) $product->get_id();
		}
		return is_singular( 'product' ) ? (int) get_queried_object_id() : 0;
	}

	/* =====================================================================
	 * Blocks (dynamic, server-rendered)
	 * =================================================================== */

	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'shn-blocks', SHN_URL . 'assets/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ), SHN_VERSION, true );
		wp_localize_script(
			'shn-blocks',
			'shnBlocks',
			array(
				'fsTitle' => __( 'Δωρεάν αποστολή (Shipping Notes)', 'shipping-notes' ),
				'fsDesc'  => __( 'Μήνυμα και μπάρα για τη δωρεάν αποστολή, με το καλάθι του επισκέπτη.', 'shipping-notes' ),
				'dlTitle' => __( 'Χρόνος παράδοσης (Shipping Notes)', 'shipping-notes' ),
				'dlDesc'  => __( 'Χρόνος παράδοσης ενός προϊόντος.', 'shipping-notes' ),
				'idLabel' => __( 'ID προϊόντος (κενό = το τρέχον)', 'shipping-notes' ),
				'note'    => __( 'Εμφανίζεται στο site με τις ρυθμίσεις του Shipping Notes.', 'shipping-notes' ),
			)
		);
		register_block_type(
			'noxpress/free-shipping',
			array(
				'api_version'     => 2,
				'editor_script'   => 'shn-blocks',
				'render_callback' => array( __CLASS__, 'render_block_fs' ),
				'supports'        => array( 'html' => false ),
			)
		);
		register_block_type(
			'noxpress/delivery',
			array(
				'api_version'     => 2,
				'editor_script'   => 'shn-blocks',
				'attributes'      => array(
					'productId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'uses_context'    => array( 'postId', 'postType' ),
				'render_callback' => array( __CLASS__, 'render_block_dl' ),
				'supports'        => array( 'html' => false ),
			)
		);
	}

	public static function render_block_fs( $attributes = array(), $content = '', $block = null ): string {
		$html = self::shortcode_fs();
		if ( '' !== $html ) {
			self::live_js();
		}
		return $html;
	}

	public static function render_block_dl( $attributes = array(), $content = '', $block = null ): string {
		$id = isset( $attributes['productId'] ) ? absint( $attributes['productId'] ) : 0;
		if ( ! $id && is_object( $block ) && isset( $block->context['postId'], $block->context['postType'] ) && 'product' === $block->context['postType'] ) {
			$id = (int) $block->context['postId'];
		}
		return self::shortcode_dl( $id ? array( 'id' => $id ) : array() );
	}

	/* =====================================================================
	 * Assets (only where the page needs them, Bible §14.9)
	 * =================================================================== */

	public static function assets(): void {
		try {
			$s       = SHN_Settings::get();
			$fs      = self::visible( 'fs' );
			$dl      = self::visible( 'dl' );
			$cart    = function_exists( 'is_cart' ) && is_cart();
			$check   = function_exists( 'is_checkout' ) && is_checkout();
			$product = function_exists( 'is_product' ) && is_product();
			// The queried post, not the global one: block themes render the template
			// (and its product loops) before wp_head.
			$post = is_singular() ? get_queried_object() : null;
			$post = $post instanceof WP_Post ? $post : null;
			$body = $post ? (string) $post->post_content : '';

			if ( ( $fs && ( $s['fs_minicart'] || $cart || $check || false !== strpos( $body, self::SC_FS ) || false !== strpos( $body, 'noxpress/free-shipping' ) ) )
				|| ( $dl && ( $product || false !== strpos( $body, self::SC_DL ) || false !== strpos( $body, 'noxpress/delivery' ) ) ) ) {
				self::css();
			}

			// Cart / Checkout blocks: the slot fill.
			if ( $fs && $post && ( ( $cart && $s['fs_block_cart'] && has_block( 'woocommerce/cart', $post ) ) || ( $check && $s['fs_block_checkout'] && has_block( 'woocommerce/checkout', $post ) ) ) ) {
				wp_enqueue_script( 'shn-blocks-cart', SHN_URL . 'assets/blocks-cart.js', array( 'wc-blocks-checkout', 'wp-plugins', 'wp-element' ), SHN_VERSION, true );
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	private static function css(): void {
		if ( self::$css ) {
			return;
		}
		self::$css = true;
		wp_enqueue_style( 'shn-front', SHN_URL . 'assets/front.css', array(), SHN_VERSION );
	}

	/** wc-cart-fragments is not on every page since WooCommerce 7.8: load it where a shortcode needs it. */
	private static function fragments_js(): void {
		if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}
	}

	/** Redraws shortcodes/blocks from the block cart store, where one exists. */
	private static function live_js(): void {
		if ( self::$live_js ) {
			return;
		}
		self::$live_js = true;
		wp_enqueue_script( 'shn-live', SHN_URL . 'assets/live.js', array(), SHN_VERSION, true );
	}

	/** Called by SHN_Delivery::render() when the estimate needs the browser. */
	public static function need_delivery_js(): void {
		if ( self::$dl_js ) {
			return;
		}
		self::$dl_js = true;
		wp_enqueue_script( 'shn-delivery', SHN_URL . 'assets/delivery.js', array(), SHN_VERSION, true );
	}
}
