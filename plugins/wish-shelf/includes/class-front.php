<?php
/**
 * WSH_Front — the heart button, the counter, assets and the front-end
 * request guard.
 *
 * The button's HTML is the same for every visitor (page caches keep
 * working): front.js sets its state from the visitor's list, which is the
 * browser's localStorage for guests and the inline list of a signed-in
 * customer (pages of signed-in users are not shared by page caches).
 *
 * Where it prints:
 *  - classic themes: woocommerce_after_shop_loop_item (9 before the add to
 *    cart button, 11 after), woocommerce_before_shop_loop_item (5, over the
 *    image) and woocommerce_single_product_summary (29 / 31);
 *  - block themes: after / before the Product Button block, over the
 *    Product Image block and around the Add to Cart form block;
 *  - anywhere: [nox_wishlist_button] and the noxpress/wishlist-button block.
 *
 * Request guard (Bible §14.4): never in admin, REST, feeds, embeds, cron,
 * CLI or XML-RPC. The wc-ajax endpoints of WSH_Ajax are the purpose of the
 * plugin and check the same mode.
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Front {

	/** @var bool Set after an error: the plugin stops for the rest of the request. */
	private static $broken = false;

	/** @var bool Assets enqueued. */
	private static $assets = false;

	/**
	 * @var int The product of the last card button. Block themes run the
	 * classic card hooks too (WooCommerce's compatibility layer), so the
	 * second button for the same card is skipped.
	 */
	private static $last_card = 0;

	/** @var bool[] Products whose page already has its button (product id => true). */
	private static $singles = array();

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_assets' ), 20 );

		$s = WSH_Settings::get();
		if ( $s['loop']['enabled'] ) {
			if ( 'image' === $s['loop']['position'] ) {
				add_action( 'woocommerce_before_shop_loop_item', array( __CLASS__, 'loop_button' ), 5 );
				add_filter( 'render_block_woocommerce/product-image', array( __CLASS__, 'block_loop' ), 10, 3 );
			} else {
				add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'loop_button' ), 'before' === $s['loop']['position'] ? 9 : 11 );
				add_filter( 'render_block_woocommerce/product-button', array( __CLASS__, 'block_loop' ), 10, 3 );
			}
		}
		if ( $s['single']['enabled'] ) {
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single_button' ), 'before' === $s['single']['position'] ? 29 : 31 );
			add_filter( 'render_block_woocommerce/add-to-cart-form', array( __CLASS__, 'block_single' ), 10, 3 );
			add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( __CLASS__, 'block_single' ), 10, 3 );
		}
		if ( '' !== $s['menu'] ) {
			add_filter( 'wp_nav_menu_items', array( __CLASS__, 'menu_item' ), 20, 2 );
			add_filter( 'wp_page_menu', array( __CLASS__, 'page_menu' ), 20, 2 );
		}
	}

	/** True when the wishlist may render in this request. */
	public static function active(): bool {
		if ( self::$broken || Wish_Shelf::killed() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			return false;
		}
		return WSH_Settings::visible();
	}

	/** Called by any renderer on error: stop for the rest of the request. */
	public static function fail(): void {
		self::$broken = true;
	}

	/* =====================================================================
	 * Assets
	 * =================================================================== */

	public static function maybe_assets(): void {
		try {
			if ( ! self::active() ) {
				return;
			}
			$s    = WSH_Settings::get();
			$need = '' !== $s['menu'] || is_woocommerce() || is_account_page() || is_cart()
				|| ( WSH_Settings::page_id() && is_page( WSH_Settings::page_id() ) );
			// After "add to cart" from a guest's list: remove the items from the browser list.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag.
			$need = $need || isset( $_GET['wsh_done'] );
			if ( ! $need && is_singular() ) {
				$post = get_post();
				$need = $post && ( has_shortcode( $post->post_content, 'nox_wishlist' ) || has_shortcode( $post->post_content, 'nox_wishlist_button' )
					|| has_shortcode( $post->post_content, 'nox_wishlist_count' ) || false !== strpos( $post->post_content, '<!-- wp:noxpress/wishlist' ) );
			}
			if ( $need ) {
				self::assets();
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/**
	 * Enqueues front.css and front.js once. Called from wp_enqueue_scripts,
	 * and from a renderer on pages the check above cannot foresee (product
	 * blocks on the home page, shortcodes): WordPress then prints them in
	 * the footer.
	 */
	public static function assets(): void {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		wp_enqueue_style( 'wsh-front', WSH_URL . 'assets/front.css', array(), WSH_VERSION );
		wp_enqueue_script( 'wsh-front', WSH_URL . 'assets/front.js', array(), WSH_VERSION, true );
		wp_localize_script( 'wsh-front', 'wshCfg', self::js_config() );
	}

	/**
	 * What front.js needs. The same for every guest; a signed-in customer
	 * also gets the list and a nonce for WSH_Ajax.
	 */
	private static function js_config(): array {
		$s   = WSH_Settings::get();
		$cfg = array(
			'ajax'   => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( '%%endpoint%%' ) : '',
			'key'    => 'wsh:' . get_current_blog_id(),
			'max'    => WSH_Store::max_items(),
			'list'   => WSH_Settings::list_url(),
			'login'  => 'login' === $s['guests'] ? (string) wc_get_page_permalink( 'myaccount' ) : '',
			'toast'  => $s['toast'] ? 1 : 0,
			'zero'   => $s['count_zero'] ? 1 : 0,
			'user'   => null,
			't'      => array(
				'add'         => WSH_Settings::text( 't_add' ),
				'added'       => WSH_Settings::text( 't_added' ),
				'toastAdd'    => WSH_Settings::text( 't_toast_add' ),
				'toastRem'    => WSH_Settings::text( 't_toast_rem' ),
				'view'        => WSH_Settings::text( 't_view' ),
				'login'       => WSH_Settings::text( 't_login' ),
				'empty'       => WSH_Settings::text( 't_empty' ),
				'confirm'     => WSH_Settings::text( 't_confirm' ),
				'confirmList' => WSH_Settings::text( 't_conf_list' ),
				'copied'      => WSH_Settings::text( 't_copied' ),
				'share'       => WSH_Settings::text( 't_share' ),
				'shareNew'    => WSH_Settings::text( 't_share_new' ),
			),
		);
		$uid = get_current_user_id();
		if ( $uid ) {
			$items = array();
			foreach ( WSH_Store::items( $uid ) as $it ) {
				$items[] = array( $it['p'], $it['v'] );
			}
			$cfg['user'] = array(
				'items' => $items,
				'nonce' => wp_create_nonce( 'wsh_sync' ),
			);
		}
		return $cfg;
	}

	/* =====================================================================
	 * Button
	 * =================================================================== */

	/** The heart icon (inline SVG, Bible §14.13). */
	public static function icon(): string {
		return '<svg class="wsh-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M12 20.7l-1.2-1.1C6.1 15.4 3 12.6 3 9.1 3 6.3 5.2 4.1 8 4.1c1.6 0 3.1.7 4 1.9.9-1.2 2.4-1.9 4-1.9 2.8 0 5 2.2 5 5 0 3.5-3.1 6.3-7.8 10.5L12 20.7z"/></svg>';
	}

	/**
	 * The button for a product (parent id; a variation id becomes its
	 * parent). '' when the product cannot be listed.
	 *
	 * @param int    $product_id Product id.
	 * @param string $ctx        'loop' | 'single' | 'over' | 'list' | 'short'.
	 */
	public static function button( int $product_id, string $ctx = 'loop' ): string {
		$pair = WSH_Store::valid_pair( $product_id, 0 );
		if ( null === $pair ) {
			return '';
		}
		$product = wc_get_product( $pair[0] );
		if ( ! $product ) {
			return '';
		}
		self::assets();
		$s     = WSH_Settings::get();
		$style = 'over' === $ctx ? 'icon' : $s['style'];
		$add   = WSH_Settings::text( 't_add' );
		$on    = WSH_Settings::text( 't_added' );
		$name  = wp_strip_all_tags( $product->get_name() );
		$text  = 'icon' === $style ? '' : '<span class="wsh-btn__t">' . esc_html( $add ) . '</span>';
		$icon  = 'text' === $style ? '' : self::icon();
		$var   = 'single' === $ctx && $product->is_type( 'variable' ) ? ' data-wsh-var="1"' : '';
		return sprintf(
			'<span class="wsh-root wsh-wrap wsh-wrap--%1$s"><button type="button" class="wsh-btn wsh-btn--%2$s" data-wsh-p="%3$d" data-wsh-name="%4$s"%5$s aria-pressed="false" aria-label="%6$s" title="%6$s">%7$s%8$s</button></span>',
			esc_attr( $ctx ),
			esc_attr( $style ),
			(int) $pair[0],
			esc_attr( $name ),
			$var,
			esc_attr( $add . ': ' . $name ),
			$icon,
			$text
		);
	}

	/** Shop cards in classic themes. */
	public static function loop_button(): void {
		try {
			if ( ! self::active() ) {
				return;
			}
			global $product;
			if ( $product instanceof WC_Product && ! self::seen_card( (int) $product->get_id() ) ) {
				$ctx = 'image' === WSH_Settings::get()['loop']['position'] ? 'over' : 'loop';
				echo self::button( (int) $product->get_id(), $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/** Product page in classic themes. */
	public static function single_button(): void {
		try {
			if ( ! self::active() ) {
				return;
			}
			global $product;
			if ( $product instanceof WC_Product && ! self::template_has_cart_block() && ! self::seen_single( (int) $product->get_id() ) ) {
				echo self::button( (int) $product->get_id(), 'single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/**
	 * Block themes run the classic product page hooks too (WooCommerce's
	 * compatibility layer, between the blocks). When the template has an
	 * Add to Cart block, the button goes next to that block instead.
	 */
	private static function template_has_cart_block(): bool {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() || empty( $GLOBALS['_wp_current_template_content'] ) ) {
			return false;
		}
		$tpl = (string) $GLOBALS['_wp_current_template_content'];
		return false !== strpos( $tpl, '<!-- wp:woocommerce/add-to-cart-form' ) || false !== strpos( $tpl, '<!-- wp:woocommerce/add-to-cart-with-options' );
	}

	/**
	 * True when this product page already has its button; remembers it.
	 *
	 * @param int $id Product id.
	 */
	private static function seen_single( int $id ): bool {
		$seen                 = isset( self::$singles[ $id ] );
		self::$singles[ $id ] = true;
		return $seen;
	}

	/**
	 * True when the card of this product already has its button; remembers it.
	 *
	 * @param int $id Product id.
	 */
	private static function seen_card( int $id ): bool {
		$seen            = $id === self::$last_card;
		self::$last_card = $id;
		return $seen;
	}

	/**
	 * The product id of a block (context postId, else the current post).
	 *
	 * @param mixed $instance WP_Block or null.
	 */
	private static function block_product_id( $instance ): int {
		if ( $instance instanceof WP_Block && isset( $instance->context['postId'] ) ) {
			return (int) $instance->context['postId'];
		}
		return (int) get_the_ID();
	}

	/**
	 * Shop cards in block themes (Product Button / Product Image blocks).
	 *
	 * @param string $content  Block HTML.
	 * @param array  $block    Parsed block.
	 * @param mixed  $instance WP_Block.
	 */
	public static function block_loop( $content, $block, $instance = null ) {
		try {
			if ( ! is_string( $content ) || '' === $content || ! self::active() ) {
				return $content;
			}
			$pos = WSH_Settings::get()['loop']['position'];
			$id  = self::block_product_id( $instance );
			if ( self::seen_card( $id ) ) {
				return $content;
			}
			$btn = self::button( $id, 'image' === $pos ? 'over' : 'loop' );
			if ( '' === $btn ) {
				return $content;
			}
			return 'before' === $pos ? $btn . $content : $content . $btn;
		} catch ( \Throwable $e ) {
			self::fail();
			return $content;
		}
	}

	/**
	 * Product page in block themes (Add to Cart form blocks).
	 *
	 * @param string $content  Block HTML.
	 * @param array  $block    Parsed block.
	 * @param mixed  $instance WP_Block.
	 */
	public static function block_single( $content, $block, $instance = null ) {
		try {
			if ( ! is_string( $content ) || '' === $content || ! self::active() ) {
				return $content;
			}
			$id = self::block_product_id( $instance );
			if ( self::seen_single( $id ) ) {
				return $content;
			}
			$btn = self::button( $id, 'single' );
			if ( '' === $btn ) {
				return $content;
			}
			return 'before' === WSH_Settings::get()['single']['position'] ? $btn . $content : $content . $btn;
		} catch ( \Throwable $e ) {
			self::fail();
			return $content;
		}
	}

	/* =====================================================================
	 * Counter
	 * =================================================================== */

	/** The counter link (the number is filled by front.js). */
	public static function counter(): string {
		self::assets();
		$url   = WSH_Settings::list_url();
		$title = WSH_Settings::text( 't_title' );
		$inner = self::icon() . '<span class="wsh-count__n" data-wsh-count>0</span>';
		if ( '' === $url ) {
			return '<span class="wsh-root wsh-count" title="' . esc_attr( $title ) . '">' . $inner . '</span>';
		}
		return '<a class="wsh-root wsh-count" href="' . esc_url( $url ) . '" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '">' . $inner . '</a>';
	}

	/**
	 * The counter as the last item of a theme menu location.
	 *
	 * @param string   $items Menu HTML.
	 * @param stdClass $args  wp_nav_menu() arguments.
	 */
	public static function menu_item( $items, $args ) {
		try {
			if ( ! is_string( $items ) || ! is_object( $args ) || empty( $args->theme_location ) || ! self::active() ) {
				return $items;
			}
			if ( WSH_Settings::get()['menu'] !== $args->theme_location ) {
				return $items;
			}
			return $items . '<li class="menu-item wsh-menu-item">' . self::counter() . '</li>';
		} catch ( \Throwable $e ) {
			self::fail();
			return $items;
		}
	}

	/**
	 * The same, when the location has no menu yet and the theme falls back
	 * to the list of pages (wp_page_menu, e.g. Storefront).
	 *
	 * @param string $menu Page menu HTML.
	 * @param array  $args wp_page_menu() arguments (with the nav menu's theme_location).
	 */
	public static function page_menu( $menu, $args ) {
		try {
			if ( ! is_string( $menu ) || ! is_array( $args ) || empty( $args['theme_location'] ) || ! self::active() ) {
				return $menu;
			}
			$pos = strrpos( $menu, '</ul>' );
			if ( WSH_Settings::get()['menu'] !== $args['theme_location'] || false === $pos ) {
				return $menu;
			}
			return substr( $menu, 0, $pos ) . '<li class="menu-item wsh-menu-item">' . self::counter() . '</li>' . substr( $menu, $pos );
		} catch ( \Throwable $e ) {
			self::fail();
			return $menu;
		}
	}
}
