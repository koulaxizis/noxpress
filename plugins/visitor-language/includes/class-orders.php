<?php
/**
 * VLG_Orders — an order placed in English stays English for its buyer.
 *
 *  - The order remembers its language: order meta _vlg_lang = 'en' when it
 *    is created under /en/ (classic and block checkout).
 *  - Customer e-mails of that order go out in English, also when they are
 *    sent later from another request: a payment webhook, a status change
 *    in the admin, a resend. The e-mail runs in the English locale and
 *    with the short texts of the phrase list (payment method, attribute
 *    values). Admin e-mails always go out in the site language, also when
 *    an English checkout triggers them, with the short texts stored in
 *    English turned back to their Greek line of the phrase list.
 *  - The thank-you and pay-for-order pages of an English order move to
 *    /en/ when a payment provider returns the buyer to the Greek URL (a
 *    return URL fixed in the provider's own dashboard). Only with the
 *    order key of that order; «?vlg=el» keeps the Greek page.
 *  - The admin order screen says when an order was placed in English.
 *
 * Only with WooCommerce. Every callback in try/catch (§14.6): on an error
 * the e-mail or page stays as it would be without the plugin.
 */

defined( 'ABSPATH' ) || exit;

final class VLG_Orders {

	const META = '_vlg_lang';

	/** Order id of the notification being sent (0 = none). */
	private static $pending = 0;

	/** 'en' | 'el' while an e-mail runs in a switched locale, '' otherwise. */
	private static $email = '';

	/** spl_object_id of the e-mail that switched the locale. */
	private static $owner = 0;

	public static function init(): void {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'mark' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'mark' ), 10, 1 );

		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'watch' ), 999 );
		add_action( 'woocommerce_before_resend_order_emails', array( __CLASS__, 'resend' ), 1, 1 );
		add_filter( 'woocommerce_allow_switching_email_locale', array( __CLASS__, 'switch_on' ), 99, 2 );
		add_filter( 'woocommerce_allow_restoring_email_locale', array( __CLASS__, 'switch_off' ), 99, 2 );

		add_filter( 'woocommerce_order_get_payment_method_title', array( __CLASS__, 'phrase' ), 20 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'phrase' ), 20 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( __CLASS__, 'phrase_in' ), 20 );

		add_action( 'template_redirect', array( __CLASS__, 'return_redirect' ), 1 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_note' ), 20, 1 );
	}

	/** Whether the text being built now is for an English reader. */
	private static function english(): bool {
		if ( '' !== self::$email ) {
			return 'en' === self::$email;
		}
		return VLG_Router::is_en();
	}

	/** Whether an order was placed in English. */
	public static function is_en_order( $order ): bool {
		return is_object( $order ) && method_exists( $order, 'get_meta' ) && 'en' === $order->get_meta( self::META );
	}

	/* ---------------------------------------------------------------------
	 * The order's language
	 * ------------------------------------------------------------------- */

	public static function mark( $order ): void {
		try {
			if ( ! VLG_Router::is_en() || ! is_a( $order, 'WC_Order' ) ) {
				return;
			}
			$order->update_meta_data( self::META, 'en' );
			if ( $order->get_id() > 0 ) {
				$order->save_meta_data();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* ---------------------------------------------------------------------
	 * E-mails
	 * ------------------------------------------------------------------- */

	/**
	 * woocommerce_email_actions: listen to the order notifications, to know
	 * which order the next e-mail belongs to. Only actions about orders:
	 * the first argument of the others (a customer id) is no order id.
	 */
	public static function watch( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		foreach ( $actions as $action ) {
			$action = (string) $action;
			if ( 0 !== strpos( $action, 'woocommerce_order_' ) && 'woocommerce_new_customer_note' !== $action ) {
				continue;
			}
			add_action( $action . '_notification', array( __CLASS__, 'capture' ), 1, 1 );
			add_action( $action . '_notification', array( __CLASS__, 'release' ), PHP_INT_MAX, 0 );
		}
		return $actions;
	}

	/** The order id from a notification argument (id, order or customer-note array). */
	public static function capture( $arg = null ): void {
		$id = 0;
		if ( is_a( $arg, 'WC_Order' ) ) {
			$id = (int) $arg->get_id();
		} elseif ( is_array( $arg ) && isset( $arg['order_id'] ) ) {
			$id = absint( $arg['order_id'] );
		} elseif ( is_numeric( $arg ) ) {
			$id = absint( $arg );
		}
		self::$pending = $id;
	}

	public static function release(): void {
		self::$pending = 0;
	}

	/** Admin «Resend» and «Send order details»: the order comes as an object. */
	public static function resend( $order ): void {
		self::capture( $order );
	}

	/**
	 * woocommerce_allow_switching_email_locale: an English order's customer
	 * e-mail switches to English; an admin e-mail of an English request
	 * switches to the site language. Returns false when this plugin did
	 * the switch, so WooCommerce does not switch a second time.
	 */
	public static function switch_on( $allow, $email = null ) {
		try {
			if ( '' !== self::$email || ! is_object( $email ) || ! method_exists( $email, 'is_customer_email' ) ) {
				return $allow;
			}
			$target = '';
			if ( $email->is_customer_email() ) {
				$order = self::$pending > 0 ? wc_get_order( self::$pending ) : null;
				if ( ! self::is_en_order( $order ) ) {
					return $allow;
				}
				$target = 'en';
			} elseif ( VLG_Router::is_en() ) {
				$target = 'el';
			} else {
				return $allow;
			}
			$locale = 'en' === $target ? VLG_Settings::get()['locale'] : self::site_locale();
			if ( ! function_exists( 'switch_to_locale' ) || ! switch_to_locale( $locale ) ) {
				return $allow;
			}
			add_filter( 'plugin_locale', 'get_locale' );
			if ( function_exists( 'WC' ) ) {
				WC()->load_plugin_textdomain();
			}
			self::$email = $target;
			self::$owner = spl_object_id( $email );
			return false;
		} catch ( \Throwable $e ) {
			return $allow;
		}
	}

	public static function switch_off( $allow, $email = null ) {
		try {
			if ( '' === self::$email || ! is_object( $email ) || spl_object_id( $email ) !== self::$owner ) {
				return $allow;
			}
			restore_previous_locale();
			remove_filter( 'plugin_locale', 'get_locale' );
			if ( function_exists( 'WC' ) ) {
				WC()->load_plugin_textdomain();
			}
			self::$email = '';
			self::$owner = 0;
			return false;
		} catch ( \Throwable $e ) {
			self::$email = '';
			return $allow;
		}
	}

	/** The site language as saved, without the /en/ locale filter of this request. */
	private static function site_locale(): string {
		$l = (string) get_option( 'WPLANG' );
		return '' !== $l ? $l : 'en_US';
	}

	public static function phrase( $text ) {
		if ( 'el' === self::$email ) {
			return self::greek( $text );
		}
		return self::english() ? VLG_Content::phrase( $text ) : $text;
	}

	public static function phrase_in( $text ) {
		if ( 'el' === self::$email ) {
			return self::greek( $text );
		}
		return self::english() ? VLG_Content::phrase_in( $text ) : $text;
	}

	/**
	 * An admin e-mail of an English order: a short text stored in English
	 * at checkout (payment method, attribute name) back to its Greek line
	 * of the phrase list.
	 */
	private static function greek( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}
		$flip = array_flip( VLG_Settings::get()['phrases'] );
		$key  = trim( wp_strip_all_tags( $text ) );
		return isset( $flip[ $key ] ) ? str_replace( $key, (string) $flip[ $key ], $text ) : $text;
	}

	/* ---------------------------------------------------------------------
	 * Return from a payment provider
	 * ------------------------------------------------------------------- */

	public static function return_redirect(): void {
		try {
			if ( VLG_Router::is_en() || ! function_exists( 'is_order_received_page' ) ) {
				return;
			}
			$var = '';
			if ( is_order_received_page() ) {
				$var = 'order-received';
			} elseif ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
				$var = 'order-pay';
			}
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only; the order key is the check.
			if ( '' === $var || ( isset( $_GET['vlg'] ) && 'el' === $_GET['vlg'] ) || ! isset( $_GET['key'] ) ) {
				return;
			}
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			// phpcs:enable
			$order = wc_get_order( absint( get_query_var( $var ) ) );
			if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $key ) || ! self::is_en_order( $order ) ) {
				return;
			}
			$to = VLG_Router::versions()['en'];
			if ( wp_safe_redirect( $to, 302, 'Visitor Language' ) ) {
				exit;
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------- */

	public static function admin_note( $order ): void {
		if ( self::is_en_order( $order ) ) {
			echo '<p class="vlg-order-lang"><strong>' . esc_html__( 'Γλώσσα παραγγελίας', 'visitor-language' ) . ':</strong> English</p>';
		}
	}
}
