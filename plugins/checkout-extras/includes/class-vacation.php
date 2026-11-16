<?php
/**
 * CKE_Vacation — store vacation mode: the site stays open, buying stops.
 *
 * Settings v_* (CKE_Settings): v_mode off | test | live, v_kind
 * 'closed' (no orders) | 'later' (orders accepted, shipped after the
 * vacation), optional v_start / v_end ('Y-m-d H:i', site time zone),
 * v_warn_days (a bar before the start), exemptions v_allow_digital,
 * v_allow_preorders, v_roles.
 *
 * The state is decided on every request from the clock (no cron), so the
 * shop opens exactly at v_end. Cron (Action Scheduler) is used only to
 * purge the page cache at the moments the pages change (warning, start,
 * end); a late job only delays the look of cached pages, never the rule.
 *
 * "closed" stops buying in three layers, all on the server:
 *  1. products: woocommerce_is_purchasable / woocommerce_variation_is_purchasable
 *     return false (front end and Store API only: never in WP admin, in
 *     cron or in the authenticated REST API), and the add-to-cart form of
 *     the product page is replaced by a short note (classic templates and
 *     the Add to cart blocks);
 *  2. cart: woocommerce_add_to_cart_validation refuses new items (classic,
 *     AJAX, Store API);
 *  3. checkout: woocommerce_check_cart_items adds an error for the items
 *     that are not exempt, which stops the classic checkout and the cart /
 *     checkout pages; the Store API refuses non-purchasable cart items by
 *     itself (CartController::validate_cart_item) and also gets our error
 *     through woocommerce_store_api_cart_errors.
 * Items already in a cart are kept (the purchasable rule is not applied
 * while WooCommerce restores the cart from the session), so customers find
 * them again after the vacation.
 *
 * Never closed: paying an existing order (order-pay), payment gateway
 * callbacks, orders created in WP admin or through the authenticated
 * REST API, the account pages, downloads. Prices, structured data and
 * feeds do not change: the site stays 200 and indexable.
 *
 * "later": buying stays open; the bar says when orders ship, the checkout
 * asks for a required acknowledgement (classic field / block additional
 * field), the order gets _cke_vacation_until (the end date, or 'yes'),
 * shown in the order totals (thank-you page, account, emails) and on the
 * admin order screen; Shipping Notes' estimate starts at the end date
 * (filter shn_available_from).
 *
 * Exemptions: digital products (virtual + downloadable), Noxpress
 * Pre-Orders products (por_get_release()), the filter cke_vacation_allow,
 * and the roles in v_roles (they buy as usual while "closed").
 *
 * Every front-end callback is wrapped in try/catch (Bible §14.6): an error
 * switches the vacation off for the rest of the request (the shop sells as
 * usual rather than breaking).
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Vacation {

	/** Order meta: the end date ('Y-m-d H:i') or 'yes' when there is none. */
	const META = '_cke_vacation_until';

	/** Block checkout field id. */
	const BLOCK_ACK = 'checkout-extras/vacation-ack';

	/** Action Scheduler hook that purges the page cache at the change times. */
	const FLIP = 'cke_vacation_flip';

	/** Date format of v_start / v_end. */
	const FORMAT = 'Y-m-d H:i';

	/** @var string|null Per-request state for the current visitor. */
	private static $state = null;

	/** True while WooCommerce restores the cart from the session. */
	private static $restoring = false;

	/** Set after the first error: the vacation stays out for the rest of the request. */
	private static $broken = false;

	/** Add-to-cart actions whose default renderer we replaced for one product. */
	private static $removed = array();

	/** Product add-to-cart actions => WooCommerce's default renderer. */
	const FORMS = array(
		'woocommerce_simple_add_to_cart'   => 'woocommerce_simple_add_to_cart',
		'woocommerce_variable_add_to_cart' => 'woocommerce_variable_add_to_cart',
		'woocommerce_grouped_add_to_cart'  => 'woocommerce_grouped_add_to_cart',
	);

	/** Hooks that work whatever the mode (orders that carry the note, the cache job). */
	public static function init_display(): void {
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'totals' ), 12, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'admin_order' ) );
		add_action( self::FLIP, array( __CLASS__, 'purge' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
	}

	/** Front-end hooks (not registered with CKE_DISABLE). */
	public static function init_front(): void {
		add_action( 'woocommerce_load_cart_from_session', array( __CLASS__, 'restore_start' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'restore_end' ) );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 50, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 50, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 50, 4 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ), 50 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
		foreach ( array_keys( self::FORMS ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'form_before' ), 1 );
			add_action( $hook, array( __CLASS__, 'form_after' ), 99 );
		}
		add_filter( 'render_block_woocommerce/add-to-cart-form', array( __CLASS__, 'block_form' ), 20, 2 );
		add_filter( 'render_block_woocommerce/add-to-cart-with-options', array( __CLASS__, 'block_form' ), 20, 2 );

		add_action( 'wp_head', array( __CLASS__, 'css' ), 20 );
		add_action( 'wp_body_open', array( __CLASS__, 'bar' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'bar_fallback' ), 5 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'later_notice' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'later_notice' ), 5 );

		// "Shipped later": acknowledgement, order note, Shipping Notes.
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'ack_field' ), 8 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'ack_validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic' ), 10, 2 );
		add_action( 'woocommerce_init', array( __CLASS__, 'block_register' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'save_block' ), 10, 2 );
		add_filter( 'shn_available_from', array( __CLASS__, 'shn_date' ), 20, 2 );
	}

	/** Records the error and switches the vacation off for this request. */
	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		self::$state  = 'off';
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Checkout Extras (vacation): ' . $e->getMessage() );
		}
	}

	/* =====================================================================
	 * State
	 * =================================================================== */

	/**
	 * A 'Y-m-d H:i' setting as a timestamp in the site time zone, or 0.
	 *
	 * @param string $v Date and time.
	 */
	public static function ts( string $v ): int {
		if ( '' === $v ) {
			return 0;
		}
		$d = DateTime::createFromFormat( '!' . self::FORMAT, $v, wp_timezone() );
		return $d instanceof DateTime ? (int) $d->getTimestamp() : 0;
	}

	/**
	 * The schedule, whoever looks: 'off' (mode off) | 'ended' (end passed)
	 * | 'scheduled' (before the start, no warning yet) | 'warn' (warning
	 * period) | 'active'.
	 *
	 * @param int|null $now Timestamp (tests); null = now.
	 */
	public static function phase( ?int $now = null ): string {
		$s = CKE_Settings::get();
		if ( 'off' === $s['v_mode'] ) {
			return 'off';
		}
		$now   = null === $now ? time() : $now;
		$start = self::ts( $s['v_start'] );
		$end   = self::ts( $s['v_end'] );
		if ( $end > 0 && $now >= $end ) {
			return 'ended';
		}
		if ( $start > 0 && $now < $start ) {
			$warn = (int) $s['v_warn_days'];
			return ( $warn > 0 && $now >= $start - $warn * DAY_IN_SECONDS ) ? 'warn' : 'scheduled';
		}
		return 'active';
	}

	/**
	 * What the current visitor gets: 'off' | 'warn' (bar only) | 'closed'
	 * | 'later'. Test mode = shop managers only; an exempt role buys as
	 * usual while closed.
	 */
	public static function state(): string {
		if ( null !== self::$state ) {
			return self::$state;
		}
		$state = 'off';
		if ( ! self::$broken && ! Checkout_Extras::killed() ) {
			$s = CKE_Settings::get();
			if ( 'live' === $s['v_mode'] || ( 'test' === $s['v_mode'] && current_user_can( 'manage_woocommerce' ) ) ) {
				$phase = self::phase();
				if ( 'warn' === $phase ) {
					$state = 'warn';
				} elseif ( 'active' === $phase ) {
					$state = 'later' === $s['v_kind'] ? 'later' : 'closed';
					if ( 'closed' === $state && self::exempt_user() ) {
						$state = 'off';
					}
				}
			}
		}
		// Cache only once the user is known.
		if ( did_action( 'set_current_user' ) ) {
			self::$state = $state;
		}
		return $state;
	}

	/** Drops the per-request state (tests, after a save). */
	public static function flush(): void {
		self::$state = null;
	}

	/** True when the logged-in user has one of the exempt roles. */
	private static function exempt_user(): bool {
		$roles = CKE_Settings::get()['v_roles'];
		if ( ! $roles || ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/** True when the request is one where the purchasable rule applies. */
	private static function front_request(): bool {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// Only the Store API (Cart / Checkout blocks), never wc/v3 and the rest.
			return function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request();
		}
		return true;
	}

	/**
	 * True when a product may still be bought while closed.
	 *
	 * @param WC_Product $product Product or variation.
	 */
	public static function allowed( WC_Product $product ): bool {
		$s     = CKE_Settings::get();
		$allow = false;
		if ( $s['v_allow_digital'] && $product->is_virtual() && $product->is_downloadable() ) {
			$allow = true;
		}
		if ( ! $allow && $s['v_allow_preorders'] && function_exists( 'por_get_release' ) ) {
			$allow = null !== por_get_release( $product );
		}
		/**
		 * Filters whether a product can be bought while the shop is closed for
		 * the vacation (e.g. pre-orders of another plugin).
		 *
		 * @param bool       $allow   Whether it stays on sale.
		 * @param WC_Product $product Product or variation.
		 */
		return (bool) apply_filters( 'cke_vacation_allow', $allow, $product );
	}

	/**
	 * True when a product page still offers a form: the product itself, or
	 * (variable / grouped) at least one of its children, may be bought.
	 *
	 * @param WC_Product $product Product.
	 */
	private static function offers_form( WC_Product $product ): bool {
		if ( self::allowed( $product ) ) {
			return true;
		}
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			foreach ( array_slice( $product->get_children(), 0, 100 ) as $id ) {
				$child = wc_get_product( $id );
				if ( $child && self::allowed( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The placeholders of the texts.
	 *
	 * @param string $end_raw The end date to show ('Y-m-d H:i'); null = the setting.
	 */
	public static function vars( ?string $end_raw = null ): array {
		$s   = CKE_Settings::get();
		$fmt = (string) get_option( 'date_format', 'j F Y' );
		$end = self::ts( null === $end_raw ? $s['v_end'] : $end_raw );
		$sta = self::ts( $s['v_start'] );
		return array(
			'return_date' => $end > 0 ? wp_date( $fmt, $end ) : '',
			'start_date'  => $sta > 0 ? wp_date( $fmt, $sta ) : '',
			'shop_name'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/**
	 * A visitor text with its placeholders filled.
	 *
	 * @param string $key  Text key.
	 * @param array  $more Extra placeholders.
	 */
	private static function text( string $key, array $more = array() ): string {
		return CKE_Settings::fill( CKE_Settings::text( $key ), array_merge( self::vars(), $more ) );
	}

	/* =====================================================================
	 * Layer 1: products
	 * =================================================================== */

	public static function restore_start(): void {
		self::$restoring = true;
	}

	public static function restore_end(): void {
		self::$restoring = false;
	}

	/**
	 * woocommerce_is_purchasable / woocommerce_variation_is_purchasable.
	 *
	 * @param bool       $purchasable Current value.
	 * @param WC_Product $product     Product.
	 */
	public static function purchasable( $purchasable, $product ) {
		if ( ! $purchasable || self::$restoring || ! $product instanceof WC_Product || ! self::front_request() ) {
			return $purchasable;
		}
		try {
			if ( 'closed' === self::state() && ! self::allowed( $product ) ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $purchasable;
	}

	/** The product the add-to-cart form is for. */
	private static function current_product(): ?WC_Product {
		global $product;
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		$p = wc_get_product( get_the_ID() );
		return $p instanceof WC_Product ? $p : null;
	}

	/** The note in place of the add-to-cart form. */
	private static function note_html(): string {
		$text = self::text( 'v_note' );
		return '' === $text ? '' : '<p class="cke-vacation-note">' . nl2br( esc_html( $text ), false ) . '</p>';
	}

	/** Priority 1 of woocommerce_{type}_add_to_cart: the note instead of the form. */
	public static function form_before(): void {
		$hook = current_action();
		if ( 'closed' !== self::state() || ! isset( self::FORMS[ $hook ] ) ) {
			return;
		}
		try {
			$p = self::current_product();
			if ( ! $p || self::offers_form( $p ) ) {
				return;
			}
			if ( false !== has_action( $hook, self::FORMS[ $hook ] ) ) {
				remove_action( $hook, self::FORMS[ $hook ], 30 );
				self::$removed[ $hook ] = true;
			}
			echo self::note_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in note_html().
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** Priority 99: puts WooCommerce's form back for the next product. */
	public static function form_after(): void {
		$hook = current_action();
		if ( isset( self::$removed[ $hook ] ) ) {
			add_action( $hook, self::FORMS[ $hook ], 30 );
			unset( self::$removed[ $hook ] );
		}
	}

	/**
	 * The Add to cart blocks of block themes.
	 *
	 * @param string $html  Block output.
	 * @param array  $block Block.
	 */
	public static function block_form( $html, $block ) {
		if ( 'closed' !== self::state() ) {
			return $html;
		}
		try {
			$id = isset( $block['attrs']['productId'] ) ? absint( $block['attrs']['productId'] ) : 0;
			$p  = $id ? wc_get_product( $id ) : self::current_product();
			if ( $p instanceof WC_Product && ! self::offers_form( $p ) ) {
				return self::note_html();
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $html;
	}

	/* =====================================================================
	 * Layers 2 and 3: cart and checkout
	 * =================================================================== */

	/**
	 * woocommerce_add_to_cart_validation.
	 *
	 * @param bool $passed       Current value.
	 * @param int  $product_id   Product id.
	 * @param int  $quantity     Quantity.
	 * @param int  $variation_id Variation id.
	 */
	public static function validate_add( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
		if ( ! $passed || 'closed' !== self::state() ) {
			return $passed;
		}
		try {
			$p = wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id );
			if ( $p instanceof WC_Product && ! self::allowed( $p ) ) {
				wc_add_notice( self::text( 'v_note' ), 'error' );
				return false;
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $passed;
	}

	/** Names of the cart items that are not exempt ('' when none). */
	private static function blocked_names(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}
		$names = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = isset( $item['data'] ) ? $item['data'] : null;
			if ( $p instanceof WC_Product && ! self::allowed( $p ) ) {
				$names[] = $p->get_name();
			}
		}
		return implode( ', ', array_unique( $names ) );
	}

	/** woocommerce_check_cart_items: cart page, classic checkout, Store API. */
	public static function check_cart(): void {
		if ( 'closed' !== self::state() ) {
			return;
		}
		try {
			$names = self::blocked_names();
			if ( '' !== $names ) {
				wc_add_notice( self::text( 'v_cart_error', array( 'products' => $names ) ), 'error' );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_store_api_cart_errors (Cart / Checkout blocks).
	 *
	 * @param WP_Error $errors Errors.
	 * @param WC_Cart  $cart   Cart.
	 */
	public static function store_api_errors( $errors, $cart ): void {
		if ( ! $errors instanceof WP_Error || 'closed' !== self::state() ) {
			return;
		}
		try {
			$names = self::blocked_names();
			if ( '' !== $names ) {
				$errors->add( 'cke_vacation', self::text( 'v_cart_error', array( 'products' => $names ) ) );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Bar and notices
	 * =================================================================== */

	/** The text of the bar for the current state ('' = no bar). */
	private static function bar_text(): string {
		switch ( self::state() ) {
			case 'warn':
				return self::text( 'v_warn' );
			case 'closed':
				return self::text( 'v_bar' );
			case 'later':
				return self::text( 'v_bar_later' );
		}
		return '';
	}

	/** Small CSS with the theme's colours (Bible §14.19), only while a bar shows. */
	public static function css(): void {
		if ( 'off' === self::state() ) {
			return;
		}
		echo '<style id="cke-vacation-css">'
			. '.cke-vacation-bar{margin:0;padding:.6em 1em;text-align:center;font-size:.95em;line-height:1.4;'
			. 'background:var(--wp--preset--color--contrast,#1e1e1e);color:var(--wp--preset--color--base,#fff)}'
			. '.cke-vacation-note{margin:1em 0;padding:.75em 1em;border-left:3px solid currentColor;opacity:.9}'
			. '</style>' . "\n";
	}

	/** wp_body_open: the bar at the top of every page. */
	public static function bar(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		try {
			$text = self::bar_text();
			if ( '' !== $text ) {
				echo '<p class="cke-vacation-bar" role="status">' . esc_html( $text ) . '</p>';
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** wp_footer: the bar for themes without wp_body_open. */
	public static function bar_fallback(): void {
		if ( ! did_action( 'wp_body_open' ) ) {
			self::bar();
		}
	}

	/** Cart and classic checkout while "shipped later": the same text as a notice. */
	public static function later_notice(): void {
		if ( 'later' !== self::state() ) {
			return;
		}
		$text = self::text( 'v_bar_later' );
		if ( '' !== $text ) {
			wc_print_notice( esc_html( $text ), 'notice' );
		}
	}

	/* =====================================================================
	 * "Shipped after the vacation"
	 * =================================================================== */

	/**
	 * The posted acknowledgement: from the checkout POST or the AJAX
	 * refresh's post_data. WooCommerce verifies its own nonces for both.
	 */
	private static function posted_ack(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its checkout / update_order_review nonces; one whitelisted field is read.
		if ( isset( $_POST['cke_vacation_present'] ) ) {
			return isset( $_POST['cke_vacation_ack'] ) && '1' === (string) ( is_scalar( $_POST['cke_vacation_ack'] ) ? wp_unslash( $_POST['cke_vacation_ack'] ) : '' );
		}
		if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			$data = array();
			wp_parse_str( wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only one field is compared.
			return isset( $data['cke_vacation_ack'] ) && '1' === (string) ( is_scalar( $data['cke_vacation_ack'] ) ? $data['cke_vacation_ack'] : '' );
		}
		// phpcs:enable
		return false;
	}

	/** Classic checkout: the required acknowledgement above "Place order". */
	public static function ack_field(): void {
		if ( 'later' !== self::state() ) {
			return;
		}
		try {
			echo '<div class="cke-vacation-ack"><input type="hidden" name="cke_vacation_present" value="1" />';
			woocommerce_form_field(
				'cke_vacation_ack',
				array(
					'type'     => 'checkbox',
					'class'    => array( 'form-row-wide' ),
					'label'    => esc_html( self::text( 'v_ack' ) ),
					'required' => true,
				),
				self::posted_ack() ? '1' : ''
			);
			echo '</div>';
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_after_checkout_validation: the acknowledgement is required.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function ack_validate( $data, $errors ): void {
		if ( ! $errors instanceof WP_Error || 'later' !== self::state() ) {
			return;
		}
		try {
			if ( ! self::posted_ack() ) {
				$errors->add( 'cke_vacation_ack', self::text( 'v_ack_required' ), array( 'id' => 'cke_vacation_ack' ) );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** The value stored on an order: the end date, or 'yes'. */
	private static function meta_value(): string {
		$end = CKE_Settings::get()['v_end'];
		return '' !== $end ? $end : 'yes';
	}

	/**
	 * woocommerce_checkout_create_order (classic).
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted data.
	 */
	public static function save_classic( $order, $data ): void {
		if ( $order instanceof WC_Order && 'later' === self::state() ) {
			$order->update_meta_data( self::META, self::meta_value() );
		}
	}

	/**
	 * woocommerce_store_api_checkout_update_order_from_request (block).
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public static function save_block( $order, $request ): void {
		if ( $order instanceof WC_Order && 'later' === self::state() ) {
			$order->update_meta_data( self::META, self::meta_value() );
		}
	}

	/** woocommerce_init: the required checkbox of the Checkout block. */
	public static function block_register(): void {
		try {
			if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || 'later' !== self::state() ) {
				return;
			}
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::BLOCK_ACK,
					'label'                      => self::text( 'v_ack' ),
					'location'                   => 'order',
					'type'                       => 'checkbox',
					'required'                   => true,
					'error_message'              => self::text( 'v_ack_required' ),
					'show_in_order_confirmation' => false,
				)
			);
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * shn_available_from: Shipping Notes' estimate starts after the vacation.
	 *
	 * @param string     $date    'Y-m-d' or ''.
	 * @param WC_Product $product Product.
	 */
	public static function shn_date( $date, $product ) {
		if ( 'later' !== self::state() ) {
			return $date;
		}
		$end = self::ts( CKE_Settings::get()['v_end'] );
		if ( $end <= 0 ) {
			return $date;
		}
		$day = wp_date( 'Y-m-d', $end );
		return ( is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date > $day ) ? $date : $day;
	}

	/* =====================================================================
	 * Orders that carry the note (front + admin)
	 * =================================================================== */

	/**
	 * The "Shipping: after the vacation" value of an order ('' = none).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_text( WC_Order $order ): string {
		$v = (string) $order->get_meta( self::META, true );
		if ( '' === $v ) {
			return '';
		}
		$text = CKE_Settings::fill( CKE_Settings::text( 'v_row_value' ), self::vars( 'yes' === $v ? '' : $v ) );
		return '' !== $text ? $text : CKE_Settings::text( 'v_row' );
	}

	/**
	 * woocommerce_get_order_item_totals: a row on the thank-you page, in the
	 * account and in the emails.
	 *
	 * @param array    $rows  Rows.
	 * @param WC_Order $order Order.
	 */
	public static function totals( $rows, $order ) {
		if ( ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $rows;
		}
		try {
			$text = self::order_text( $order );
			if ( '' !== $text ) {
				$rows['cke_vacation'] = array(
					'label' => CKE_Settings::text( 'v_row' ) . ':',
					'value' => esc_html( $text ),
				);
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $rows;
	}

	/**
	 * The admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function admin_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$text = self::order_text( $order );
		if ( '' !== $text ) {
			echo '<p class="cke-vacation-admin"><strong>' . esc_html__( 'Διακοπές καταστήματος:', 'checkout-extras' ) . '</strong> ' . esc_html( $text ) . '</p>';
		}
	}

	/* =====================================================================
	 * Cache and schedule
	 * =================================================================== */

	/** Purges the page cache (Fast Pages and the other known caches). */
	public static function purge(): void {
		if ( class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'purge_page_cache' ) ) {
			Noxpress_Core::purge_page_cache();
		}
	}

	/**
	 * After a change of the vacation settings: purge now when visitors may
	 * see a difference, and one purge job at each future change time.
	 *
	 * @param array $before The settings before the change.
	 */
	public static function changed( array $before ): void {
		self::flush();
		$after = CKE_Settings::get();
		if ( 'live' === $before['v_mode'] || 'live' === $after['v_mode'] ) {
			self::purge();
		}
		self::reschedule();
	}

	/** One purge job at the warning, the start and the end (live mode only). */
	public static function reschedule(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_unschedule_all_actions( self::FLIP );
		$s = CKE_Settings::get();
		if ( 'live' !== $s['v_mode'] ) {
			return;
		}
		$start = self::ts( $s['v_start'] );
		$times = array(
			$start > 0 && $s['v_warn_days'] > 0 ? $start - (int) $s['v_warn_days'] * DAY_IN_SECONDS : 0,
			$start,
			self::ts( $s['v_end'] ),
		);
		foreach ( array_unique( $times ) as $t ) {
			if ( $t > time() ) {
				as_schedule_single_action( $t, self::FLIP, array(), 'checkout-extras' );
			}
		}
	}

	/** A short date for the admin ('' when empty). */
	public static function short_date( string $v ): string {
		$t = self::ts( $v );
		return $t > 0 ? wp_date( 'j/n/Y H:i', $t ) : '';
	}

	/** The state for the admin: label and tone ('ok' | 'warn' | 'off'). */
	public static function admin_status(): array {
		$s     = CKE_Settings::get();
		$phase = self::phase();
		$end   = self::short_date( $s['v_end'] );
		$start = self::short_date( $s['v_start'] );
		$test  = 'test' === $s['v_mode'] ? ' ' . __( '(δοκιμή, μόνο για διαχειριστές)', 'checkout-extras' ) : '';
		switch ( $phase ) {
			case 'off':
				return array( __( 'Ανοιχτό: οι διακοπές είναι ανενεργές.', 'checkout-extras' ), 'off' );
			case 'ended':
				/* translators: %s: date */
				return array( sprintf( __( 'Ανοιχτό: οι διακοπές έληξαν στις %s. Κλείσε τον διακόπτη ή βάλε νέες ημερομηνίες.', 'checkout-extras' ), $end ) . $test, 'warn' );
			case 'scheduled':
			case 'warn':
				/* translators: %s: date */
				$label = sprintf( __( 'Ανοιχτό: οι διακοπές ξεκινούν στις %s.', 'checkout-extras' ), $start );
				if ( 'warn' === $phase ) {
					$label .= ' ' . __( 'Η προειδοποίηση φαίνεται ήδη.', 'checkout-extras' );
				}
				return array( $label . $test, 'ok' );
		}
		$label = 'later' === $s['v_kind'] ? __( 'Διακοπές: οι παραγγελίες γίνονται δεκτές και αποστέλλονται μετά.', 'checkout-extras' ) : __( 'Διακοπές: οι αγορές είναι κλειστές.', 'checkout-extras' );
		if ( '' !== $end ) {
			/* translators: %s: date */
			$label .= ' ' . sprintf( __( 'Ανοίγει αυτόματα στις %s.', 'checkout-extras' ), $end );
		} else {
			$label .= ' ' . __( 'Χωρίς ημερομηνία λήξης: ανοίγει μόνο με το χέρι.', 'checkout-extras' );
		}
		return array( $label . $test, 'warn' );
	}

	/**
	 * The admin bar: a reminder while the vacation is on or planned.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ): void {
		if ( ! $bar instanceof WP_Admin_Bar || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s     = CKE_Settings::get();
		$phase = self::phase();
		if ( 'off' === $phase ) {
			return;
		}
		$end   = self::ts( $s['v_end'] );
		$start = self::ts( $s['v_start'] );
		if ( 'active' === $phase ) {
			/* translators: %s: date */
			$title = $end > 0 ? sprintf( __( 'Διακοπές ως %s', 'checkout-extras' ), wp_date( 'j/n', $end ) ) : __( 'Διακοπές', 'checkout-extras' );
		} elseif ( 'ended' === $phase ) {
			$title = __( 'Διακοπές: έληξαν', 'checkout-extras' );
		} else {
			/* translators: %s: date */
			$title = sprintf( __( 'Διακοπές από %s', 'checkout-extras' ), wp_date( 'j/n', $start ) );
		}
		if ( 'test' === $s['v_mode'] ) {
			$title .= ' ' . __( '(δοκιμή)', 'checkout-extras' );
		}
		$bar->add_node(
			array(
				'id'    => 'cke-vacation',
				'title' => esc_html( $title ),
				'href'  => admin_url( 'admin.php?page=cke-settings&tab=vacation' ),
			)
		);
	}
}
