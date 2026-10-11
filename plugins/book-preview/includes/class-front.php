<?php
/**
 * BPV_Front — what visitors see.
 *
 *  - "Read a sample" button on the product page: below the short
 *    description or below the add to cart form. Classic themes use
 *    WooCommerce's hooks (and Astra's own); block themes only render_block,
 *    because WooCommerce's compatibility layer also fires the classic hooks
 *    there, around other blocks. A guard prints the button once per page.
 *  - Shortcode [book_preview id="123" label="…"] and the block
 *    "book-preview/sample" (same markup) for any other place.
 *  - The viewer: a <dialog> built by assets/viewer.js on the first click;
 *    PDF.js (assets/vendor/pdfjs) loads only then. The sample file is the
 *    only PDF the browser ever receives.
 *  - Optional "Continue reading" card after the last page: on the
 *    product's own page its button closes the viewer and goes to the add
 *    to cart form; elsewhere it adds a simple product to the cart or opens
 *    the product. Products that cannot be bought get no card.
 *
 * Bible §14: off until enabled, test mode for shop managers only,
 * BPV_DISABLE stops every hook (the bootstrap does not call init()), page
 * requests only, every callback in try/catch, nothing written, assets only
 * on pages with a button. No cookies, no storage, no external requests.
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Front {

	const SHORTCODE = 'book_preview';
	const BLOCK     = 'book-preview/sample';

	/** Per-request state. */
	private static $active = null;
	private static $done   = false;
	private static $broken = false;

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'wp', array( __CLASS__, 'setup' ) );
	}

	/** Hooks for this page view (runs once the main query is known). */
	public static function setup(): void {
		try {
			if ( ! self::active() || ! function_exists( 'is_product' ) || ! is_product() ) {
				return;
			}
			$id = (int) get_queried_object_id();
			if ( ! BPV_Samples::visible( $id ) ) {
				return;
			}
			$s = BPV_Settings::get();
			if ( 'manual' === $s['position'] ) {
				return;
			}
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

			$blocks = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
			if ( 'after_excerpt' === $s['position'] ) {
				if ( ! $blocks ) {
					add_action( 'astra_woo_single_short_description_after', array( __CLASS__, 'print_button' ), 10 );
					add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'print_button' ), 21 );
				}
				add_filter( 'render_block_core/post-excerpt', array( __CLASS__, 'block_after' ), 10, 3 );
				add_filter( 'render_block_woocommerce/product-summary', array( __CLASS__, 'block_after' ), 10, 3 );
			} else {
				if ( ! $blocks ) {
					add_action( 'astra_woo_single_add_to_cart_after', array( __CLASS__, 'print_button' ), 10 );
					add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'print_button' ), 31 );
				}
				add_filter( 'render_block_woocommerce/add-to-cart-form', array( __CLASS__, 'block_after' ), 10, 3 );
				add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( __CLASS__, 'block_after' ), 10, 3 );
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
		$s  = BPV_Settings::get();
		$ok = ! empty( $s['enabled'] ) && ! Book_Preview::killed() && self::is_page_request();
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

	public static function enqueue(): void {
		if ( wp_script_is( 'bpv-viewer', 'enqueued' ) ) {
			return;
		}
		$vendor = BPV_URL . 'assets/vendor/pdfjs/';
		wp_enqueue_style( 'bpv-front', BPV_URL . 'assets/front.css', array(), BPV_VERSION );
		wp_enqueue_script( 'bpv-viewer', BPV_URL . 'assets/viewer.js', array(), BPV_VERSION, true );
		$en = 'en' === BPV_Settings::front_lang();
		wp_localize_script(
			'bpv-viewer',
			'BPV_VIEWER',
			array(
				'lib'    => $vendor . 'pdf.min.js?ver=' . rawurlencode( BPV_VERSION ),
				'worker' => $vendor . 'pdf.worker.min.js?ver=' . rawurlencode( BPV_VERSION ),
				'fonts'  => $vendor . 'standard_fonts/',
				'wasm'   => $vendor . 'wasm/',
				'iccs'   => $vendor . 'iccs/',
				'i18n'   => $en ? self::ui_en() : self::ui_el(),
			)
		);
	}

	/** Viewer controls (not shop texts: fixed per language). */
	private static function ui_el(): array {
		return array(
			'close'    => 'Κλείσιμο',
			'zoomIn'   => 'Μεγέθυνση',
			'zoomOut'  => 'Σμίκρυνση',
			'full'     => 'Πλήρης οθόνη',
			'page'     => 'Σελίδα %1$s από %2$s',
			'loading'  => 'Φόρτωση του δείγματος…',
			'error'    => 'Το δείγμα δεν άνοιξε. Δοκιμάστε ξανά αργότερα.',
			'sample'   => 'Δείγμα',
		);
	}

	private static function ui_en(): array {
		return array(
			'close'    => 'Close',
			'zoomIn'   => 'Zoom in',
			'zoomOut'  => 'Zoom out',
			'full'     => 'Full screen',
			'page'     => 'Page %1$s of %2$s',
			'loading'  => 'Loading the sample…',
			'error'    => 'The sample could not be opened. Please try again later.',
			'sample'   => 'Sample',
		);
	}

	/* =====================================================================
	 * Product page button
	 * =================================================================== */

	/** Action callback: prints the button of the viewed product, once. */
	public static function print_button(): void {
		if ( self::$done || ! self::active() ) {
			return;
		}
		$html = self::render( (int) get_queried_object_id() );
		if ( '' !== $html ) {
			self::$done = true;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in render().
		}
	}

	/** render_block filter: button after the main product's excerpt / cart form. */
	public static function block_after( $content, $parsed = null, $block = null ) {
		try {
			if ( self::$done || ! self::active() || ! self::is_main_product_block( $block ) ) {
				return $content;
			}
			$html = self::render( (int) get_queried_object_id() );
			if ( '' === $html ) {
				return $content;
			}
			self::$done = true;
			return (string) $content . $html;
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
		return is_object( $block ) && isset( $block->name ) && 'core/post-excerpt' !== $block->name;
	}

	/* =====================================================================
	 * Shortcode and block
	 * =================================================================== */

	/** [book_preview], [book_preview id="123"], [book_preview label="…"]. */
	public static function shortcode( $atts ): string {
		try {
			$atts = shortcode_atts(
				array(
					'id'    => 0,
					'label' => '',
				),
				is_array( $atts ) ? $atts : array(),
				self::SHORTCODE
			);
			return self::placed( absint( $atts['id'] ), (string) $atts['label'] );
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			BPV_PATH . 'blocks/sample',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
		if ( is_admin() && function_exists( 'generate_block_asset_handle' ) ) {
			// Editor texts from PHP, so they follow the Noxpress admin language (Bible §9).
			wp_add_inline_script(
				generate_block_asset_handle( self::BLOCK, 'editorScript' ),
				'window.BPV_BLOCK = ' . wp_json_encode(
					array(
						'panel'   => __( 'Δείγμα βιβλίου', 'book-preview' ),
						'product' => __( 'ID προϊόντος (κενό = το τρέχον προϊόν)', 'book-preview' ),
						'label'   => __( 'Κείμενο κουμπιού (κενό = από τις ρυθμίσεις)', 'book-preview' ),
						'button'  => BPV_Settings::text( 'button' ),
					)
				) . ';',
				'before'
			);
		}
	}

	public static function render_block( $attrs ): string {
		try {
			$attrs = is_array( $attrs ) ? $attrs : array();
			return self::placed( absint( $attrs['productId'] ?? 0 ), isset( $attrs['label'] ) ? (string) $attrs['label'] : '' );
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	/** Button placed by a shortcode or block (any page). */
	private static function placed( int $id, string $label ): string {
		if ( ! self::active() ) {
			return '';
		}
		if ( ! $id ) {
			global $product;
			$id = $product instanceof WC_Product ? (int) $product->get_id() : (int) get_queried_object_id();
		}
		if ( ! BPV_Samples::visible( $id ) ) {
			return '';
		}
		$html = self::render( $id, BPV_Settings::clip( sanitize_text_field( $label ), BPV_Settings::MAX_TEXT ) );
		if ( '' !== $html ) {
			self::enqueue();
			if ( $id === (int) get_queried_object_id() ) {
				self::$done = true;
			}
		}
		return $html;
	}

	/* =====================================================================
	 * Markup
	 * =================================================================== */

	/** The button for a product, or '' when it has no visible sample. */
	public static function render( int $product_id, string $label = '' ): string {
		try {
			if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) || ! BPV_Samples::visible( $product_id ) ) {
				return '';
			}
			$p = wc_get_product( $product_id );
			if ( ! $p || ! $p->is_visible() && ! current_user_can( 'edit_post', $product_id ) ) {
				return '';
			}
			$sample = BPV_Samples::sample( $product_id );
			$data   = array(
				'src'   => BPV_Samples::url( $product_id ),
				'title' => wp_strip_all_tags( $p->get_name() ),
				'pages' => (int) ( $sample['count'] ?? 0 ),
				'end'   => self::end_card( $p ),
			);
			$label = '' !== $label ? $label : BPV_Settings::text( 'button' );
			$class = 'button bpv-open';
			if ( function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
				$extra = (string) wc_wp_theme_get_element_class_name( 'button' );
				if ( '' !== $extra ) {
					$class .= ' ' . $extra;
				}
			}
			return '<div class="bpv-root"><button type="button" class="' . esc_attr( $class ) . '" data-bpv="' . esc_attr( (string) wp_json_encode( $data ) ) . '" aria-haspopup="dialog">'
				. '<svg class="bpv-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 6.5C10.3 5.2 7.9 4.5 5 4.5c-.9 0-1.8.1-2.6.2L2 4.8v14.4l.6-.1c.8-.1 1.6-.2 2.4-.2 2.7 0 4.9.7 6.5 1.9l.5.4.5-.4c1.6-1.2 3.8-1.9 6.5-1.9.8 0 1.6.1 2.4.2l.6.1V4.8l-.4-.1c-.8-.1-1.7-.2-2.6-.2-2.9 0-5.3.7-7 2zM11 18.4C9.4 17.5 7.3 17 5 17c-.4 0-.7 0-1 .1V6.6c.3 0 .7-.1 1-.1 2.6 0 4.6.6 6 1.7v10.2zm9-1.3c-.3 0-.6-.1-1-.1-2.3 0-4.4.5-6 1.4V8.2c1.4-1.1 3.4-1.7 6-1.7.3 0 .7 0 1 .1v10.5z"/></svg>'
				. '<span>' . esc_html( $label ) . '</span></button></div>';
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	/** Data of the "Continue reading" card, or null. */
	private static function end_card( WC_Product $p ): ?array {
		if ( ! BPV_Settings::get()['end_card'] || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
			return null;
		}
		$own = function_exists( 'is_product' ) && is_product() && (int) get_queried_object_id() === (int) $p->get_id();
		if ( $own ) {
			$action = 'scroll';
			$href   = '';
		} elseif ( $p->is_type( 'simple' ) && $p->supports( 'ajax_add_to_cart' ) ) {
			// Add to cart on the cart page, where WooCommerce shows the
			// "added" notice (a page with the shortcode may show no notices).
			$action = 'link';
			$href   = (string) add_query_arg( 'add-to-cart', $p->get_id(), wc_get_cart_url() );
		} else {
			$action = 'link';
			$href   = (string) $p->get_permalink();
		}
		$price = (string) $p->get_price_html();
		$price = (string) preg_replace( '#<span class="screen-reader-text">.*?</span>#s', '', $price );
		$price = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' ) ) );
		$img   = wp_get_attachment_image_url( (int) $p->get_image_id(), 'woocommerce_thumbnail' );
		return array(
			'title'  => BPV_Settings::text( 'end_title' ),
			'button' => BPV_Settings::text( 'end_button' ),
			'name'   => wp_strip_all_tags( $p->get_name() ),
			'price'  => $price,
			'image'  => $img ? (string) $img : '',
			'action' => $action,
			'href'   => $href,
		);
	}
}
