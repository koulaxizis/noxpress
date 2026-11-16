<?php
/**
 * TP_Badges — product badges for the whole store (1.7.0): «Νέο», «Τελευταία
 * κομμάτια», «Εξαντλήθηκε», a text per product tag or category, and a text
 * per product (product meta _tp_badge).
 *
 * Which badges (approved design, notes/small-additions-2026-11):
 *  - Order: sale (drawn by the theme / WooCommerce, only counted here) →
 *    own text → tag / category rule → last pieces → new → sold out.
 *  - At most "max" per product (default 2), the sale badge included.
 *  - New: published (date created) in the last "days" days.
 *  - Last pieces: stock managed, in stock, 1 ≤ quantity ≤ the limit (0 =
 *    WooCommerce's low stock amount of the product / store). Variable:
 *    the parent's stock when the parent manages it, else the sum of the
 *    in-stock variations that manage their own (a variation without stock
 *    management means "enough", so no badge). {stock} = the quantity.
 *  - Sold out: not in stock (backorders count as in stock).
 *  - Product page with a sale: "top left" becomes "top right" (WooCommerce
 *    draws its sale flash top left there).
 *  - Pre-Orders: while a product has an active pre-order, last pieces and
 *    sold out are skipped (its own badge stays, Pre-Orders is not touched).
 *
 * Where:
 *  - classic lists: woocommerce_before_shop_loop_item_title, priority 11
 *    (after WooCommerce's sale flash at 10, inside the card link);
 *  - classic product page: woocommerce_before_single_product_summary, 11;
 *    (both taken off in WooCommerce's blockified templates through
 *    woocommerce_blocks_hook_compatibility_additional_data, so the blocks
 *    below do not draw them twice);
 *  - blocks: woocommerce/product-image (Product Collection, related
 *    products) and woocommerce/product-image-gallery (product page),
 *    inside the block's own wrapper;
 *  - Theme Patcher's slot (TP_Render), next to its sale badge, when the
 *    card did not fire the classic hook for the same product.
 * Themes that build their own cards without these hooks show nothing.
 *
 * Rules (Bible §14): follows the master switch and test mode of
 * tp_settings; TP_DISABLE stops it (not even initialised); the request gate
 * of the price labels (front end and AJAX, never admin, REST, feeds, cron,
 * CLI or XML-RPC); no database writes on visitor requests; every callback
 * in try/catch, the original output stays on any Throwable. Display only:
 * prices, carts, orders, emails, feeds and the schema are never touched.
 * Developers can change the list with the `tp_badges` filter.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Badges {

	const META = '_tp_badge';

	/** Gate result for this request (null = not decided yet). */
	private static $gate = null;

	/** Set after any Throwable: no further work this request. */
	private static $failed = false;

	/** Per-request cache: "<product id>|<context>" => badges. */
	private static $cache = array();

	/** Product whose badges the classic loop hook printed for the current card. */
	private static $hook_pid = 0;

	/** Registration that runs with or without TP_DISABLE (meta is data, not output). */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_field' ) );
		}
	}

	public static function init(): void {
		add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'loop' ), 11 );
		add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'card_end' ), PHP_INT_MAX );
		add_action( 'woocommerce_before_single_product_summary', array( __CLASS__, 'single' ), 11 );
		add_filter( 'render_block_woocommerce/product-image', array( __CLASS__, 'block_image' ), 10, 3 );
		add_filter( 'render_block_woocommerce/product-image-gallery', array( __CLASS__, 'block_gallery' ), 10, 3 );
		add_action( 'wp_head', array( __CLASS__, 'css' ), 99 );
		// Blockified templates: WooCommerce runs the classic hooks there too; the blocks above draw the badges.
		add_filter( 'woocommerce_blocks_hook_compatibility_additional_data', array( __CLASS__, 'block_compat' ), 10, 2 );
	}

	/** Our classic callbacks, for WooCommerce to take off in blockified templates (WC 9.5+). */
	public static function block_compat( $data, $class_name = '' ) {
		$data = is_array( $data ) ? $data : array();
		if ( 'ArchiveProductTemplatesCompatibility' === $class_name ) {
			$data[] = array(
				'hook'     => 'woocommerce_before_shop_loop_item_title',
				'function' => array( __CLASS__, 'loop' ),
				'priority' => 11,
			);
		} elseif ( 'SingleProductTemplateCompatibility' === $class_name ) {
			$data[] = array(
				'hook'     => 'woocommerce_before_single_product_summary',
				'function' => array( __CLASS__, 'single' ),
				'priority' => 11,
			);
		}
		return $data;
	}

	/* =====================================================================
	 * Gate
	 * =================================================================== */

	/** True when badges apply to this request and visitor. */
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
			&& ! empty( $s['badges']['on'] )
			&& ( empty( $s['test_mode'] ) || current_user_can( 'manage_woocommerce' ) )
			&& TP_Prices::request_ok();
		if ( did_action( 'wp' ) || wp_doing_ajax() ) {
			self::$gate = $on;
		}
		return $on;
	}

	/* =====================================================================
	 * Output points
	 * =================================================================== */

	/** Classic list card. */
	public static function loop(): void {
		try {
			$product = self::current();
			if ( ! $product || ! self::on() ) {
				return;
			}
			$html = self::html( $product, 'loop' );
			self::$hook_pid = $product->get_id();
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_html / esc_attr.
		} catch ( \Throwable $e ) {
			self::$failed = true;
		}
	}

	/** End of a classic card: the next card starts clean. */
	public static function card_end(): void {
		self::$hook_pid = 0;
	}

	/** Classic product page (the page's own product). */
	public static function single(): void {
		try {
			$product = self::current();
			if ( ! $product || ! self::on() ) {
				return;
			}
			echo self::html( $product, 'single' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_html / esc_attr.
		} catch ( \Throwable $e ) {
			self::$failed = true;
		}
	}

	/** Block product image: inside its wrapper (position: relative). */
	public static function block_image( $content, $block = array(), $instance = null ) {
		return self::into_block( $content, $instance, 'loop' );
	}

	/** Block product gallery (product page). */
	public static function block_gallery( $content, $block = array(), $instance = null ) {
		return self::into_block( $content, $instance, 'single' );
	}

	private static function into_block( $content, $instance, string $context ) {
		try {
			if ( ! is_string( $content ) || '' === $content || ! self::on() ) {
				return $content;
			}
			$pid = 0;
			if ( $instance instanceof WP_Block && isset( $instance->context['postId'] ) ) {
				$pid = (int) $instance->context['postId'];
			}
			if ( $pid <= 0 ) {
				$current = self::current();
				$pid     = $current ? $current->get_id() : 0;
			}
			$product = $pid > 0 ? wc_get_product( $pid ) : null;
			if ( ! $product instanceof WC_Product ) {
				return $content;
			}
			$html = self::html( $product, $context );
			if ( '' === $html ) {
				return $content;
			}
			// After the opening tag of the block's wrapper.
			$out = preg_replace( '#^(\s*<div\b[^>]*>)#', '$1' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $html ), $content, 1, $n );
			return $n ? (string) $out : $content;
		} catch ( \Throwable $e ) {
			self::$failed = true;
			return $content;
		}
	}

	/**
	 * Badges for Theme Patcher's slot ('' when off, when the theme's card
	 * already printed them through the classic hook, or when none apply).
	 * Always inline: the slot sits in the theme's own layout.
	 */
	public static function slot_html( WC_Product $product ): string {
		try {
			if ( ! self::on() || self::$hook_pid === $product->get_id() ) {
				return '';
			}
			return self::html( $product, 'loop', 'inline' );
		} catch ( \Throwable $e ) {
			self::$failed = true;
			return '';
		}
	}

	/** Styles, once in <head>, only while badges are on for this visitor. */
	public static function css(): void {
		try {
			if ( ! self::on() ) {
				return;
			}
			echo '<style id="tp-badges-css">' . self::css_rules( TP_Settings::badges() ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- colours validated as #rrggbb.
		} catch ( \Throwable $e ) {
			self::$failed = true;
		}
	}

	/** CSS of the badges (colours are validated #rrggbb; themes override with the variables). */
	public static function css_rules( array $b ): string {
		return ':where(ul.products li.product,.wc-block-components-product-image,.wp-block-woocommerce-product-image-gallery,.woocommerce div.product){position:relative}'
			. '.tp-badges{display:flex;flex-direction:column;align-items:flex-start;gap:4px;z-index:9;pointer-events:none;line-height:1.2;margin:0}'
			. '.tp-badges--left,.tp-badges--right{position:absolute;top:var(--tp-badges-top,8px)}'
			. '.tp-badges--left{left:var(--tp-badges-side,8px)}'
			. '.tp-badges--right{right:var(--tp-badges-side,8px);align-items:flex-end}'
			. '.tp-badges--inline{flex-direction:row;flex-wrap:wrap;margin:0 0 .5em}'
			. '.tp-pbadge{display:inline-block;padding:3px 8px;border-radius:3px;font-size:12px;font-weight:600;letter-spacing:.01em;white-space:nowrap;color:var(--tp-badge-fg,' . $b['fg'] . ')}'
			. '.tp-pbadge--new{background:var(--tp-badge-new-bg,' . $b['new']['color'] . ')}'
			. '.tp-pbadge--low{background:var(--tp-badge-low-bg,' . $b['low']['color'] . ')}'
			. '.tp-pbadge--out{background:var(--tp-badge-out-bg,' . $b['out']['color'] . ')}'
			. '.tp-pbadge--own{background:var(--tp-badge-own-bg,' . $b['own']['color'] . ')}'
			. '.tp-pbadge--rule{background:var(--tp-badge-rule-bg,' . $b['rule_color'] . ')}';
	}

	/* =====================================================================
	 * Badges
	 * =================================================================== */

	/** HTML of a product's badges ('' when none). */
	public static function html( WC_Product $product, string $context, string $pos = '' ): string {
		$list = self::for_product( $product, $context );
		if ( ! $list ) {
			return '';
		}
		$b   = TP_Settings::badges();
		$pos = '' !== $pos ? $pos : $b['pos'];
		// On the product page WooCommerce draws the sale flash top left: the badges take the other corner.
		if ( 'single' === $context && 'left' === $pos && $product->is_on_sale() ) {
			$pos = 'right';
		}
		$cls = 'tp-badges tp-badges--' . $pos;
		$out = '<span class="' . esc_attr( $cls ) . '">';
		foreach ( $list as $badge ) {
			$out .= '<span class="tp-pbadge tp-pbadge--' . esc_attr( $badge['kind'] ) . '" data-tp-badge="' . esc_attr( $badge['key'] ) . '">' . esc_html( $badge['text'] ) . '</span>';
		}
		return $out . '</span>';
	}

	/**
	 * Badges of a product, in order, at most "max" minus the sale badge.
	 *
	 * @return array<int,array{key:string,kind:string,text:string}>
	 */
	public static function for_product( WC_Product $product, string $context = 'loop' ): array {
		$key = $product->get_id() . '|' . $context;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$b     = TP_Settings::badges();
		$room  = (int) $b['max'] - ( $product->is_on_sale() ? 1 : 0 );
		$list  = array();
		$pre   = self::preorder( $product );

		if ( $b['own']['on'] ) {
			$own = TP_Settings::valid_label( (string) $product->get_meta( self::META, true ) );
			if ( '' !== $own ) {
				$list[] = array(
					'key'  => 'own',
					'kind' => 'own',
					'text' => $own,
				);
			}
		}
		foreach ( self::rule_texts( $product, $b['rules'] ) as $i => $text ) {
			$list[] = array(
				'key'  => 'rule-' . $i,
				'kind' => 'rule',
				'text' => $text,
			);
		}
		if ( $b['low']['on'] && ! $pre ) {
			$qty = self::low_stock( $product, (int) $b['low']['qty'] );
			if ( null !== $qty ) {
				$list[] = array(
					'key'  => 'low',
					'kind' => 'low',
					'text' => self::fill( self::pick( $b['low']['text'] ), $qty ),
				);
			}
		}
		if ( $b['new']['on'] && self::is_new( $product, (int) $b['new']['days'] ) ) {
			$list[] = array(
				'key'  => 'new',
				'kind' => 'new',
				'text' => self::pick( $b['new']['text'] ),
			);
		}
		if ( $b['out']['on'] && ! $pre && ! $product->is_in_stock() ) {
			$list[] = array(
				'key'  => 'out',
				'kind' => 'out',
				'text' => self::pick( $b['out']['text'] ),
			);
		}

		$list = array_slice( $list, 0, max( 0, $room ) );

		/**
		 * Filters a product's badges (after the limit).
		 *
		 * @param array      $list    Badges: [ 'key' => str, 'kind' => new|low|out|own|rule, 'text' => plain text ].
		 * @param WC_Product $product Product.
		 * @param string     $context 'loop' or 'single'.
		 * @param string     $lang    'el' or 'en'.
		 */
		$list  = apply_filters( 'tp_badges', $list, $product, $context, TP_Prices::lang() );
		$clean = array();
		if ( is_array( $list ) ) {
			foreach ( $list as $badge ) {
				if ( is_array( $badge ) && isset( $badge['text'] ) && is_scalar( $badge['text'] ) && '' !== trim( (string) $badge['text'] ) ) {
					$kind    = isset( $badge['kind'] ) && in_array( $badge['kind'], array( 'new', 'low', 'out', 'own', 'rule' ), true ) ? $badge['kind'] : 'rule';
					$clean[] = array(
						'key'  => isset( $badge['key'] ) && is_scalar( $badge['key'] ) ? sanitize_html_class( (string) $badge['key'] ) : $kind,
						'kind' => $kind,
						'text' => wp_strip_all_tags( (string) $badge['text'] ),
					);
				}
			}
		}
		self::$cache[ $key ] = $clean;
		return $clean;
	}

	/** Texts of the tag / category rules that match, in the order of the rules. */
	private static function rule_texts( WC_Product $product, array $rules ): array {
		if ( ! $rules ) {
			return array();
		}
		$id    = $product->get_id();
		$terms = array();
		foreach ( TP_Settings::BADGE_TAX as $tax ) {
			$ids = function_exists( 'wc_get_product_term_ids' ) ? wc_get_product_term_ids( $id, $tax ) : array();
			$all = array_map( 'intval', is_array( $ids ) ? $ids : array() );
			if ( 'product_cat' === $tax ) {
				foreach ( $all as $tid ) {
					$all = array_merge( $all, array_map( 'intval', get_ancestors( $tid, 'product_cat', 'taxonomy' ) ) );
				}
			}
			$terms[ $tax ] = array_flip( $all );
		}
		$out = array();
		foreach ( $rules as $r ) {
			if ( isset( $terms[ $r['tax'] ][ (int) $r['term'] ] ) ) {
				$out[] = self::pick( $r );
			}
		}
		return $out;
	}

	/** Quantity for "last pieces", or null when the badge does not apply. */
	public static function low_stock( WC_Product $product, int $limit ): ?int {
		if ( ! $product->is_in_stock() ) {
			return null;
		}
		$qty = null;
		if ( $product->managing_stock() ) {
			$qty = (int) $product->get_stock_quantity();
		} elseif ( $product->is_type( 'variable' ) ) {
			$sum = 0;
			$any = false;
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || ! $v->is_in_stock() || 'publish' !== get_post_status( $vid ) ) {
					continue;
				}
				if ( ! $v->managing_stock() ) {
					return null; // An in-stock variation without a count: enough.
				}
				$sum += max( 0, (int) $v->get_stock_quantity() );
				$any  = true;
			}
			$qty = $any ? $sum : null;
		}
		if ( null === $qty || $qty < 1 ) {
			return null; // Unknown, or zero / negative on backorder.
		}
		if ( $limit <= 0 ) {
			$limit = function_exists( 'wc_get_low_stock_amount' ) ? (int) wc_get_low_stock_amount( $product ) : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		}
		return $limit > 0 && $qty <= $limit ? $qty : null;
	}

	/** Created (published) within the last $days days. */
	public static function is_new( WC_Product $product, int $days ): bool {
		$created = $product->get_date_created();
		if ( ! $created ) {
			return false;
		}
		$age = time() - $created->getTimestamp();
		return $age >= 0 && $age < $days * DAY_IN_SECONDS;
	}

	/** True while Pre-Orders has an active pre-order for the product. */
	private static function preorder( WC_Product $product ): bool {
		if ( ! class_exists( 'POR_Product' ) || ! is_callable( array( 'POR_Product', 'active' ) ) ) {
			return false;
		}
		try {
			if ( $product->is_type( 'variable' ) ) {
				return is_callable( array( 'POR_Product', 'catalog_date' ) ) && null !== POR_Product::catalog_date( $product );
			}
			return null !== POR_Product::active( $product );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** {stock} → the quantity; unknown placeholders go (Bible §14.17). */
	public static function fill( string $tpl, int $qty ): string {
		if ( false === strpos( $tpl, '{' ) ) {
			return $tpl;
		}
		$text = str_replace( '{stock}', (string) $qty, $tpl );
		$text = (string) preg_replace( '/\{[a-z_]+\}/', '', $text );
		return trim( (string) preg_replace( '/\s{2,}/u', ' ', $text ) );
	}

	/** The visitor's language from an { el, en } pair (empty English → Greek). */
	private static function pick( array $pair ): string {
		if ( 'en' === TP_Prices::lang() && isset( $pair['en'] ) && '' !== $pair['en'] ) {
			return (string) $pair['en'];
		}
		return isset( $pair['el'] ) ? (string) $pair['el'] : '';
	}

	private static function current(): ?WC_Product {
		return isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof WC_Product ? $GLOBALS['product'] : null;
	}

	/* =====================================================================
	 * Product meta and its field
	 * =================================================================== */

	public static function register_meta(): void {
		register_post_meta(
			'product',
			self::META,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( 'TP_Settings', 'valid_label' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', (int) $post_id );
				},
			)
		);
	}

	/** «Δικό μου σήμα» in the General tab of the product data box. */
	public static function product_field(): void {
		try {
			if ( ! function_exists( 'woocommerce_wp_text_input' ) || ! TP_Settings::badges()['own']['on'] ) {
				return;
			}
			echo '<div class="options_group">';
			woocommerce_wp_text_input(
				array(
					'id'                => self::META,
					'label'             => __( 'Δικό μου σήμα', 'theme-patcher' ),
					'description'       => __( 'Σύντομο κείμενο στην εικόνα του προϊόντος, π.χ. «Υπογεγραμμένο». Κενό = κανένα. Theme Patcher → Σήματα.', 'theme-patcher' ),
					'desc_tip'          => true,
					'custom_attributes' => array( 'maxlength' => (string) TP_Settings::MAX_LABEL_LEN ),
				)
			);
			echo '</div>';
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Saved with the product (WooCommerce has checked its nonce). */
	public static function save_field( $product ): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce_meta_nonce before this hook.
			if ( ! $product instanceof WC_Product || ! isset( $_POST[ self::META ] ) || ! current_user_can( 'edit_post', $product->get_id() ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$text = TP_Settings::valid_label( wp_unslash( $_POST[ self::META ] ) );
			if ( '' === $text ) {
				$product->delete_meta_data( self::META );
			} else {
				$product->update_meta_data( self::META, $text );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Admin counts
	 * =================================================================== */

	/**
	 * Published products today: new, sold out, and last pieces among simple
	 * products and parents that manage stock (variations not counted).
	 *
	 * @return array{new:int,low:int,out:int,own:int}
	 */
	public static function counts(): array {
		global $wpdb;
		$b     = TP_Settings::badges();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $b['new']['days'] * DAY_IN_SECONDS );
		$limit = (int) $b['low']['qty'] > 0 ? (int) $b['low']['qty'] : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- admin-only counts.
		$new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_date_gmt >= %s", $since ) );
		$out = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock_status' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_value = 'outofstock'" );
		$low = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
				JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock'
				JOIN {$wpdb->postmeta} ss ON ss.post_id = p.ID AND ss.meta_key = '_stock_status' AND ss.meta_value = 'instock'
				WHERE p.post_type = 'product' AND p.post_status = 'publish' AND CAST(st.meta_value AS SIGNED) BETWEEN 1 AND %d",
				max( 0, $limit )
			)
		);
		$own = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_value <> ''", self::META ) );
		// phpcs:enable
		return array(
			'new' => $new,
			'low' => $low,
			'out' => $out,
			'own' => $own,
		);
	}
}
