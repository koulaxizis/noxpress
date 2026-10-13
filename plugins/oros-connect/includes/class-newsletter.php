<?php
/**
 * ORC_Newsletter — module "newsletter": new subscribers for a list in
 * orOS Newsletter.
 *
 *  - Form: shortcode [oros_newsletter], block oros-connect/newsletter and a
 *    classic widget. Plain HTML that posts straight from the visitor's
 *    browser to the list's sign-up address on the orOS relay (as the HTML
 *    orOS gives): no script, honeypot, the visitor's language. Nothing is
 *    written here; page caches are fine. orOS sends the confirmation
 *    e-mail and the relay shows (or redirects to) the thank-you page set
 *    in orOS.
 *  - Checkout (WooCommerce): an unticked checkbox. Classic checkout →
 *    woocommerce_review_order_before_submit; block checkout (WC 8.9+) →
 *    additional order field oros-connect/newsletter. The consent is saved
 *    in the order (_orc_nl_consent = Unix time, _orc_nl_text, _orc_nl_lang).
 *  - Send: when the order is paid (processing or completed) the sign-up
 *    is queued (Action Scheduler, group oros-connect) and posted from the
 *    server with the billing e-mail and name. Retries after 5 min, 30 min,
 *    2 h and 12 h for busy / unavailable / network answers; an order note
 *    says how it went (_orc_nl_done = time, or _orc_nl_fail = reason).
 *    Without the confirmation for checkout sign-ups (default) and with a
 *    shop key, the post is signed (ORC_Relay::fields) so orOS can skip it.
 *
 * Front-end parts follow the mode (test: managers only) and ORC_DISABLE.
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Newsletter {

	const SHORTCODE = 'oros_newsletter';
	const FIELD_ID  = 'oros-connect/newsletter';
	const GROUP     = 'oros-connect';
	const HOOK      = 'orc_nl_send';
	const TRIES     = 5;

	const META_CONSENT = '_orc_nl_consent';
	const META_TEXT    = '_orc_nl_text';
	const META_LANG    = '_orc_nl_lang';
	const META_QUEUED  = '_orc_nl_queued';
	const META_DONE    = '_orc_nl_done';
	const META_FAIL    = '_orc_nl_fail';

	/** @var bool Front CSS printed or queued in this request. */
	private static $css = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );

		if ( Oros_Connect::killed() ) {
			// The block and the widget stay registered (no "missing block"
			// in the editor) but render nothing.
			return;
		}

		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_css' ) );

		if ( Oros_Connect::woo() ) {
			add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'classic_field' ), 20 );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_save' ), 10, 2 );
			add_action( 'woocommerce_init', array( __CLASS__, 'block_register' ) );
			add_action( 'woocommerce_set_additional_field_value', array( __CLASS__, 'block_save' ), 10, 4 );
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'paid' ), 10, 2 );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'paid' ), 10, 2 );
			if ( is_admin() ) {
				add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'order_line' ) );
			}
		}
	}

	/* =====================================================================
	 * Form
	 * =================================================================== */

	/** True when the form can show to the current visitor. */
	public static function form_ready(): bool {
		return ! Oros_Connect::killed() && ORC_Settings::visible() && '' !== ORC_Settings::get()['nl_url'];
	}

	/**
	 * The form HTML ('' when it does not show).
	 *
	 * @param array $args title (bool), name (bool|null = setting).
	 */
	public static function form( array $args = array() ): string {
		try {
			if ( ! self::form_ready() ) {
				return self::manager_hint();
			}
			$s     = ORC_Settings::get();
			$lang  = ORC_Settings::visitor_lang();
			$title = ! isset( $args['title'] ) || $args['title'];
			$name  = isset( $args['name'] ) && null !== $args['name'] ? (bool) $args['name'] : (bool) $s['nl_name'];
			$uid   = 'orc-nl-' . wp_unique_id();

			self::enqueue_css();

			$t = static function ( string $k ) use ( $lang ): string {
				return ORC_Settings::text( $k, $lang );
			};

			$h  = '<div class="orc-nl">';
			$h .= '<form class="orc-nl__form" method="post" action="' . esc_url( $s['nl_url'] ) . '" accept-charset="UTF-8">';
			if ( $title && '' !== $t( 'title' ) ) {
				$h .= '<p class="orc-nl__title"><strong>' . esc_html( $t( 'title' ) ) . '</strong></p>';
			}
			if ( '' !== $t( 'intro' ) ) {
				$h .= '<p class="orc-nl__intro">' . esc_html( $t( 'intro' ) ) . '</p>';
			}
			$h .= '<p class="orc-nl__row">';
			$h .= '<label class="screen-reader-text" for="' . esc_attr( $uid ) . '-e">' . esc_html( $t( 'email' ) ) . '</label>';
			$h .= '<input type="email" id="' . esc_attr( $uid ) . '-e" name="email" required maxlength="254" autocomplete="email" placeholder="' . esc_attr( $t( 'email' ) ) . '" />';
			if ( $name ) {
				$h .= '<label class="screen-reader-text" for="' . esc_attr( $uid ) . '-n">' . esc_html( $t( 'name' ) ) . '</label>';
				$h .= '<input type="text" id="' . esc_attr( $uid ) . '-n" name="name" maxlength="120" autocomplete="name" placeholder="' . esc_attr( $t( 'name' ) ) . '" />';
			}
			$h .= '<button type="submit" class="' . esc_attr( self::button_class() ) . '">' . esc_html( $t( 'button' ) ) . '</button>';
			$h .= '</p>';
			$h .= '<input type="hidden" name="lang" value="' . esc_attr( $lang ) . '" />';
			// Honeypot: the relay thanks and drops a filled one. Inline style
			// so it stays hidden even if a cache strips our stylesheet.
			$h .= '<div style="position:absolute;left:-5000px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off" value="" /></div>';
			if ( '' !== $t( 'note' ) ) {
				$h .= '<p class="orc-nl__note"><small>' . esc_html( $t( 'note' ) ) . '</small></p>';
			}
			if ( 'test' === $s['mode'] ) {
				$h .= '<p class="orc-nl__note"><small>' . esc_html__( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τη φόρμα.', 'oros-connect' ) . '</small></p>';
			}
			$h .= '</form></div>';
			return $h;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/** For managers only: why the form does not show (no address, off). */
	private static function manager_hint(): string {
		if ( Oros_Connect::killed() || ! is_user_logged_in() || ! current_user_can( Oros_Connect::cap() ) ) {
			return '';
		}
		$s = ORC_Settings::get();
		if ( '' === $s['nl_url'] ) {
			$msg = __( 'orOS Connect: η φόρμα newsletter δεν έχει ακόμα τη διεύθυνση εγγραφής του orOS (Noxpress → orOS Connect → Newsletter).', 'oros-connect' );
		} elseif ( 'off' === $s['mode'] ) {
			$msg = __( 'orOS Connect: η φόρμα newsletter είναι κλειστή (λειτουργία «Ανενεργό»).', 'oros-connect' );
		} else {
			return '';
		}
		return '<p class="orc-nl__note"><small>' . esc_html( $msg ) . '</small></p>';
	}

	/** Theme button classes (Bible §14.16). */
	private static function button_class(): string {
		$c = 'button';
		if ( function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
			$e = (string) wc_wp_theme_get_element_class_name( 'button' );
			if ( '' !== $e ) {
				$c .= ' ' . $e;
			}
		} elseif ( function_exists( 'wp_theme_get_element_class_name' ) ) {
			$e = (string) wp_theme_get_element_class_name( 'button' );
			if ( '' !== $e ) {
				$c .= ' ' . $e;
			}
		}
		return $c;
	}

	/**
	 * [oros_newsletter title="yes|no" name="yes|no"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'title' => 'yes',
				'name'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$name = '' === $atts['name'] ? null : self::yes( $atts['name'] );
		return self::form(
			array(
				'title' => self::yes( $atts['title'] ),
				'name'  => $name,
			)
		);
	}

	private static function yes( $v ): bool {
		return in_array( strtolower( (string) $v ), array( '1', 'yes', 'true', 'on', 'ναι' ), true );
	}

	/* =====================================================================
	 * Front CSS (Bible §14.9: only where the form shows, scoped, small)
	 * =================================================================== */

	/** Early enqueue when we can tell the page holds the form (prints in <head>). */
	public static function maybe_css(): void {
		if ( ! self::form_ready() ) {
			return;
		}
		$need = false;
		if ( is_singular() ) {
			$post = get_post();
			if ( $post instanceof WP_Post ) {
				$need = has_shortcode( $post->post_content, self::SHORTCODE ) || has_block( 'oros-connect/newsletter', $post );
			}
		}
		if ( ! $need && is_active_widget( false, false, 'orc_newsletter', true ) ) {
			$need = true;
		}
		if ( $need ) {
			self::enqueue_css();
		}
	}

	private static function enqueue_css(): void {
		if ( self::$css ) {
			return;
		}
		self::$css = true;
		wp_enqueue_style( 'orc-front', ORC_URL . 'assets/front.css', array(), ORC_VERSION );
	}

	/* =====================================================================
	 * Block and widget
	 * =================================================================== */

	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			ORC_PATH . 'blocks/newsletter',
			array(
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/** @param array $attrs Block attributes. */
	public static function render_block( $attrs ): string {
		$attrs = is_array( $attrs ) ? $attrs : array();
		$html  = self::form(
			array(
				'title' => ! isset( $attrs['showTitle'] ) || (bool) $attrs['showTitle'],
			)
		);
		if ( '' === $html ) {
			return '';
		}
		$wrap = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : 'class="wp-block-oros-connect-newsletter"';
		return '<div ' . $wrap . '>' . $html . '</div>';
	}

	public static function register_widget(): void {
		require_once ORC_PATH . 'includes/class-widget.php';
		register_widget( 'ORC_Widget' );
	}

	/* =====================================================================
	 * Checkout: shared
	 * =================================================================== */

	/** The checkbox shows (setting on, visible, address set). */
	public static function checkout_on(): bool {
		$s = ORC_Settings::get();
		return Oros_Connect::woo() && ! Oros_Connect::killed() && $s['nl_checkout'] && '' !== $s['nl_url'] && ORC_Settings::visible();
	}

	/** Stores the consent in the order (the caller saves it). */
	private static function record( WC_Order $order ): void {
		$lang = ORC_Settings::visitor_lang();
		$order->update_meta_data( self::META_CONSENT, time() );
		$order->update_meta_data( self::META_TEXT, ORC_Settings::text( 'checkbox', $lang ) );
		$order->update_meta_data( self::META_LANG, $lang );
	}

	/* =====================================================================
	 * Checkout: classic
	 * =================================================================== */

	public static function classic_field(): void {
		try {
			if ( ! self::checkout_on() ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only (re-render after a failed submit).
			$checked = ! empty( $_POST['orc_nl_optin'] );
			echo '<p class="form-row orc-nl-optin" id="orc_nl_optin_field">';
			echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
			echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="orc_nl_optin" id="orc_nl_optin" value="1"' . checked( $checked, true, false ) . ' /> ';
			echo '<span>' . esc_html( ORC_Settings::text( 'checkbox' ) ) . '</span>';
			echo '</label></p>';
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function classic_save( $order, $data ): void {
		try {
			if ( ! $order instanceof WC_Order || ! self::checkout_on() ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
			if ( ! empty( $_POST['orc_nl_optin'] ) ) {
				self::record( $order );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Checkout: block (WooCommerce 8.9+: additional order field)
	 * =================================================================== */

	public static function block_register(): void {
		try {
			if ( ! self::checkout_on() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
				return;
			}
			if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '8.9', '<' ) ) {
				return;
			}
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::FIELD_ID,
					'label'    => ORC_Settings::text( 'checkbox' ),
					'location' => 'order',
					'type'     => 'checkbox',
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * WooCommerce stores the field itself; we add the same consent record
	 * as the classic checkout.
	 */
	public static function block_save( $key, $value, $group, $wc_object ): void {
		try {
			if ( self::FIELD_ID !== $key || ! $wc_object instanceof WC_Order || ! $value || ! self::checkout_on() ) {
				return;
			}
			self::record( $wc_object );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Send (Action Scheduler)
	 * =================================================================== */

	/**
	 * Order paid: queue the sign-up once.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 */
	public static function paid( $order_id, $order = null ): void {
		try {
			if ( Oros_Connect::killed() ) {
				return;
			}
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! $order->get_meta( self::META_CONSENT ) || $order->get_meta( self::META_QUEUED ) ) {
				return;
			}
			$order->update_meta_data( self::META_QUEUED, time() );
			$order->save_meta_data();
			self::schedule( (int) $order->get_id(), 1, 0 );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function schedule( int $order_id, int $try, int $delay ): void {
		$args = array( $order_id, $try );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( $delay <= 0 && function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			} else {
				as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, $args, self::GROUP );
			}
			return;
		}
		wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, $args );
	}

	/** Delay before try N+1 (seconds). */
	private static function delay( int $try ): int {
		$d = array(
			1 => 5 * MINUTE_IN_SECONDS,
			2 => 30 * MINUTE_IN_SECONDS,
			3 => 2 * HOUR_IN_SECONDS,
			4 => 12 * HOUR_IN_SECONDS,
		);
		return isset( $d[ $try ] ) ? $d[ $try ] : DAY_IN_SECONDS;
	}

	/** One attempt (queued callback). */
	public static function send( int $order_id, int $try ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_meta( self::META_DONE ) || ! $order->get_meta( self::META_CONSENT ) ) {
			return;
		}
		$s = ORC_Settings::get();
		if ( Oros_Connect::killed() || ! ORC_Settings::module( 'newsletter' ) || '' === $s['nl_url'] ) {
			ORC_Relay::log( $order_id, 'skip', 0, $try );
			return;
		}

		$email = sanitize_email( (string) $order->get_billing_email() );
		$res   = 'bad';
		$code  = 0;
		if ( is_email( $email ) ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$lang = 'en' === $order->get_meta( self::META_LANG ) ? 'en' : 'el';
			$key  = ! $s['nl_doi'] ? $s['nl_key'] : '';
			$r    = ORC_Relay::post( $s['nl_url'], ORC_Relay::fields( $email, $name, $lang, $key ) );
			$res  = $r['res'];
			$code = $r['code'];
		}
		ORC_Relay::log( $order_id, $res, $code, $try );

		if ( 'ok' === $res ) {
			$order->update_meta_data( self::META_DONE, time() );
			$order->delete_meta_data( self::META_FAIL );
			$order->save_meta_data();
			$order->add_order_note( __( 'Newsletter: ο πελάτης στάλθηκε στη λίστα του orOS.', 'oros-connect' ) );
		} elseif ( ORC_Relay::retryable( $res ) && $try < self::TRIES ) {
			self::schedule( $order_id, $try + 1, self::delay( $try ) );
		} else {
			$order->update_meta_data( self::META_FAIL, $res );
			$order->save_meta_data();
			/* translators: %s: reason */
			$order->add_order_note( sprintf( __( 'Newsletter: η αποστολή στο orOS απέτυχε (%s).', 'oros-connect' ), ORC_Relay::reason( $res ) ) );
		}

		/**
		 * After each checkout sign-up attempt.
		 *
		 * @param int    $order_id Order ID.
		 * @param string $res      ok | bad | gone | busy | off | error | network.
		 */
		do_action( 'orc_newsletter_sent', $order_id, $res );
	}

	/* =====================================================================
	 * Order screen
	 * =================================================================== */

	/** @param WC_Order $order Order. */
	public static function order_line( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$at = (int) $order->get_meta( self::META_CONSENT );
		if ( $at <= 0 ) {
			return;
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		if ( $order->get_meta( self::META_DONE ) ) {
			$state = __( 'στάλθηκε στο orOS', 'oros-connect' );
		} elseif ( $order->get_meta( self::META_FAIL ) ) {
			$state = sprintf( '%s (%s)', __( 'απέτυχε', 'oros-connect' ), ORC_Relay::reason( (string) $order->get_meta( self::META_FAIL ) ) );
		} elseif ( $order->get_meta( self::META_QUEUED ) ) {
			$state = __( 'σε αναμονή', 'oros-connect' );
		} else {
			$state = __( 'θα σταλεί όταν πληρωθεί η παραγγελία', 'oros-connect' );
		}
		echo '<p><strong>' . esc_html__( 'Newsletter:', 'oros-connect' ) . '</strong> '
			/* translators: %s: date and time */
			. esc_html( sprintf( __( 'συναίνεση στο ταμείο, %s', 'oros-connect' ), wp_date( $fmt, $at ) ) )
			. ' · ' . esc_html( $state ) . '</p>';
	}
}
