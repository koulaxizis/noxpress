<?php
/**
 * CKE_Thankyou — what the thank-you page adds, inside the theme's own page.
 *
 *  - Message: a title and a text with placeholders ({first_name},
 *    {order_number}, {order_total}, {shop_name}, {email}), one for paid
 *    orders (processing / completed) and one for orders waiting for payment
 *    (pending / on-hold; empty = the paid one). Nothing for failed,
 *    cancelled or refunded orders. Shown in woocommerce_before_thankyou
 *    (classic template and the Order confirmation block alike), or, with
 *    t_replace, in place of WooCommerce's "Thank you. Your order has been
 *    received." line (woocommerce_thankyou_order_received_text).
 *  - Coupon box (part "coupon"): the order's thank-you coupon with a copy
 *    button; or, for an order that is not paid yet but would get one, a
 *    line saying it comes by email.
 *  - Products (optional): cross-sells of the order, related products, or
 *    products on sale, with the theme's product cards (wc_setup_loop +
 *    content-product, Bible §14.19), after the order details
 *    (woocommerce_thankyou, priority 20). Products already bought are left
 *    out.
 *
 * Read only: nothing here writes to the database (Bible §14.5). Every
 * callback is wrapped in try/catch (Bible §14.6).
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Thankyou {

	/** @var bool Set after the first error. */
	private static $broken = false;

	public static function init(): void {
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'received_text' ), 20, 2 );
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'render_top' ), 10 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'render_products' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Checkout Extras (thank-you): ' . $e->getMessage() );
		}
	}

	/** 'paid' | 'hold' | '' for an order's status. */
	private static function phase( WC_Order $order ): string {
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			return 'paid';
		}
		if ( $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return 'hold';
		}
		return '';
	}

	/**
	 * The message of an order: { title, body } as plain text (placeholders
	 * filled), or null when there is none.
	 */
	private static function message( WC_Order $order ): ?array {
		$phase = self::phase( $order );
		if ( '' === $phase ) {
			return null;
		}
		$title = CKE_Settings::text( 't_paid_title' );
		$body  = CKE_Settings::text( 't_paid_body' );
		if ( 'hold' === $phase ) {
			$ht = CKE_Settings::text( 't_hold_title' );
			$hb = CKE_Settings::text( 't_hold_body' );
			if ( '' !== $ht || '' !== $hb ) {
				$title = $ht;
				$body  = $hb;
			}
		}
		$vars  = array(
			'first_name'   => (string) $order->get_billing_first_name(),
			'order_number' => (string) $order->get_order_number(),
			'order_total'  => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
			'shop_name'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'email'        => (string) $order->get_billing_email(),
		);
		$title = CKE_Settings::fill( $title, $vars );
		$body  = CKE_Settings::fill( $body, $vars );
		if ( '' === $title && '' === $body ) {
			return null;
		}
		return array(
			'title' => $title,
			'body'  => $body,
		);
	}

	/**
	 * woocommerce_thankyou_order_received_text: with t_replace, our message
	 * instead of WooCommerce's line (inline HTML: the line is a <p>).
	 *
	 * @param string              $text  Text.
	 * @param WC_Order|false|null $order Order, or false / null without access.
	 */
	public static function received_text( $text, $order = null ) {
		if ( self::$broken || ! $order instanceof WC_Order || ! CKE_Settings::get()['t_replace'] || ! CKE_Settings::visible( 't' ) ) {
			return $text;
		}
		try {
			$m = self::message( $order );
			if ( null === $m ) {
				return $text;
			}
			$out = '';
			if ( '' !== $m['title'] ) {
				$out .= '<strong class="cke-ty__title">' . esc_html( $m['title'] ) . '</strong>';
			}
			if ( '' !== $m['body'] ) {
				$out .= ( '' !== $out ? '<br />' : '' ) . '<span class="cke-ty__body">' . nl2br( esc_html( $m['body'] ), false ) . '</span>';
			}
			return $out;
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $text;
		}
	}

	/**
	 * woocommerce_before_thankyou: the message box (unless it replaced
	 * WooCommerce's line) and the coupon box.
	 *
	 * @param int $order_id Order id.
	 */
	public static function render_top( $order_id ): void {
		// Once per page: the boxes carry ids, and some themes fire the hook twice.
		static $done = false;
		if ( self::$broken || $done ) {
			return;
		}
		$done = true;
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$html = '';
			// With t_replace the message goes into WooCommerce's own line (received_text()).
			if ( CKE_Settings::visible( 't' ) && ! CKE_Settings::get()['t_replace'] ) {
				$m = self::message( $order );
				if ( null !== $m ) {
					$html .= '<div id="cke-ty" class="cke-ty cke-ty--' . esc_attr( self::phase( $order ) ) . '">';
					if ( '' !== $m['title'] ) {
						$html .= '<h2 class="cke-ty__title">' . esc_html( $m['title'] ) . '</h2>';
					}
					if ( '' !== $m['body'] ) {
						$html .= '<p class="cke-ty__body">' . nl2br( esc_html( $m['body'] ), false ) . '</p>';
					}
					$html .= '</div>';
				}
			}
			$html .= self::coupon_box( $order );
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above / in coupon_box().
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** The coupon box of an order ('' when there is nothing to show). */
	private static function coupon_box( WC_Order $order ): string {
		if ( ! CKE_Settings::visible( 'c' ) ) {
			return '';
		}
		$c = CKE_Coupon::for_order( $order );
		if ( $c && 'active' === CKE_Coupon::state( $c ) ) {
			$vars  = CKE_Coupon::vars( $c, $order );
			$title = CKE_Settings::fill( CKE_Settings::text( 'c_title' ), $vars );
			$body  = CKE_Settings::fill( CKE_Settings::text( 'c_body' ), $vars );
			$out   = '<div id="cke-coupon" class="cke-coupon">';
			if ( '' !== $title ) {
				$out .= '<h2 class="cke-coupon__title">' . esc_html( $title ) . '</h2>';
			}
			$out .= '<p class="cke-coupon__body">' . nl2br( esc_html( $body ), false ) . '</p>';
			$out .= '<p class="cke-coupon__row"><code class="cke-coupon__code">' . esc_html( strtoupper( $c->get_code() ) ) . '</code>';
			$out .= ' <button type="button" class="' . esc_attr( trim( 'button cke-coupon__copy ' . ( function_exists( 'wc_wp_theme_get_element_class_name' ) ? wc_wp_theme_get_element_class_name( 'button' ) : '' ) ) ) . '" data-code="' . esc_attr( $c->get_code() ) . '" data-done="' . esc_attr( CKE_Settings::text( 'c_copied' ) ) . '" hidden>' . esc_html( CKE_Settings::text( 'c_copy' ) ) . '</button></p>';
			$out .= '</div>';
			return $out;
		}
		if ( ! $c && $order->has_status( array( 'pending', 'on-hold' ) ) && 'none' !== CKE_Settings::get()['c_email'] && '' === CKE_Coupon::refusal( $order, true ) ) {
			$text = CKE_Settings::text( 'c_pending' );
			if ( '' !== $text ) {
				return '<div id="cke-coupon" class="cke-coupon cke-coupon--pending"><p class="cke-coupon__body">' . nl2br( esc_html( $text ), false ) . '</p></div>';
			}
		}
		return '';
	}

	/**
	 * woocommerce_thankyou (priority 20, after the order details): products.
	 *
	 * @param int $order_id Order id.
	 */
	public static function render_products( $order_id ): void {
		if ( self::$broken || ! CKE_Settings::visible( 't' ) || 'none' === CKE_Settings::get()['t_products'] ) {
			return;
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || '' === self::phase( $order ) ) {
				return;
			}
			$ids = self::product_ids( $order );
			if ( ! $ids ) {
				return;
			}
			$title = CKE_Settings::text( 't_products_title' );
			echo '<section class="cke-products related products">';
			if ( '' !== $title ) {
				echo '<h2 class="cke-products__title">' . esc_html( $title ) . '</h2>';
			}
			wc_setup_loop(
				array(
					'name'    => 'cke_thankyou',
					'columns' => min( 4, count( $ids ) ),
				)
			);
			woocommerce_product_loop_start();
			foreach ( $ids as $pid ) {
				$post_object = get_post( $pid );
				if ( ! $post_object ) {
					continue;
				}
				setup_postdata( $GLOBALS['post'] = $post_object ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, Squiz.PHP.DisallowMultipleAssignments.Found -- the WooCommerce loop pattern.
				wc_get_template_part( 'content', 'product' );
			}
			woocommerce_product_loop_end();
			wc_reset_loop();
			wp_reset_postdata();
			echo '</section>';
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** Product ids to show: visible, purchasable, in stock, not in the order. */
	private static function product_ids( WC_Order $order ): array {
		$s      = CKE_Settings::get();
		$bought = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$bought[] = (int) $item->get_product_id();
			}
		}
		$cand = array();
		switch ( $s['t_products'] ) {
			case 'cross':
				foreach ( $bought as $pid ) {
					$p = wc_get_product( $pid );
					if ( $p ) {
						$cand = array_merge( $cand, array_map( 'intval', $p->get_cross_sell_ids() ) );
					}
				}
				break;
			case 'related':
				foreach ( array_slice( $bought, 0, 3 ) as $pid ) {
					$cand = array_merge( $cand, array_map( 'intval', wc_get_related_products( $pid, $s['t_limit'] * 2, $bought ) ) );
				}
				break;
			case 'sale':
				$cand = array_map( 'intval', wc_get_product_ids_on_sale() );
				shuffle( $cand );
				break;
		}
		$out = array();
		foreach ( array_unique( $cand ) as $pid ) {
			if ( in_array( $pid, $bought, true ) ) {
				continue;
			}
			$p = wc_get_product( $pid );
			if ( ! $p || $p->get_parent_id() || 'publish' !== $p->get_status() || ! $p->is_visible() || ! $p->is_in_stock() ) {
				continue;
			}
			$out[] = $pid;
			if ( count( $out ) >= $s['t_limit'] ) {
				break;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Assets (thank-you page only)
	 * =================================================================== */

	public static function assets(): void {
		if ( self::$broken || ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return;
		}
		if ( ! CKE_Settings::visible( 't' ) && 'off' === CKE_Settings::get()['c_mode'] ) {
			return;
		}
		try {
			wp_register_style( 'cke-thankyou', false, array(), CKE_VERSION );
			wp_enqueue_style( 'cke-thankyou' );
			wp_add_inline_style( 'cke-thankyou', self::css() );
			wp_enqueue_script( 'cke-front', CKE_URL . 'assets/front.js', array(), CKE_VERSION, true );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** Scoped CSS that survives theme rules (Bible §14.13). */
	private static function css(): string {
		return '.cke-ty,.cke-coupon{display:block!important;float:none!important;clear:both;box-shadow:none!important;text-transform:none!important;letter-spacing:normal!important;text-align:start!important}'
			. '.cke-ty{margin:0 0 1.5em!important;padding:0!important;border:0!important;background:none!important}'
			. '.cke-ty .cke-ty__title{margin:0 0 .3em!important}'
			. '.cke-ty .cke-ty__body{margin:0!important}'
			. '.cke-coupon{margin:0 0 1.8em!important;padding:1em 1.2em!important;border:2px dashed currentColor!important;border-radius:6px!important;background:none!important;text-align:center!important}'
			. '.cke-coupon .cke-coupon__title{margin:0 0 .3em!important;font-size:1.25em!important}'
			. '.cke-coupon .cke-coupon__body{margin:0 0 .6em!important}'
			. '.cke-coupon--pending .cke-coupon__body{margin:0!important}'
			. '.cke-coupon .cke-coupon__row{margin:0!important;display:flex!important;flex-wrap:wrap!important;gap:.6em!important;align-items:center!important;justify-content:center!important}'
			. '.cke-coupon .cke-coupon__code{display:inline-block!important;padding:.35em .7em!important;font-size:1.3em!important;font-weight:700!important;letter-spacing:.12em!important;font-family:monospace!important;border:1px solid rgba(127,127,127,.5)!important;border-radius:4px!important;background:none!important;color:inherit!important}'
			. '.cke-coupon .cke-coupon__copy[hidden]{display:none!important}'
			// Id rules of the theme (e.g. #content .woocommerce-order p{float:left;width:50%}) beat classes.
			. '#cke-ty p,#cke-coupon p,#cke-ty h2,#cke-coupon h2{float:none!important;width:auto!important;text-transform:none!important;letter-spacing:normal!important}'
			. '#cke-ty p,#cke-coupon .cke-coupon__body{display:block!important}'
			. '#cke-coupon .cke-coupon__row{display:flex!important}'
			. '#cke-coupon code{background:none!important;color:inherit!important;letter-spacing:.12em!important}'
			. '#cke-coupon button{float:none!important;width:auto!important;margin:0!important}'
			. '.cke-products{clear:both;margin-top:2em}';
	}
}
