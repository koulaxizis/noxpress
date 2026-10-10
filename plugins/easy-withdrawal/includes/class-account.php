<?php
/**
 * EWD_Account — where customers find the withdrawal function (design §3).
 *
 *  - My Account → Orders: a button on every order that is still open.
 *  - My Account → View order: the button and the requests already made.
 *  - Customer order emails (processing, on-hold, completed) and the
 *    thank-you page: a link with the order key, so the form opens on the
 *    order without the lookup step (setting email_links, on by default).
 *  - wp_footer: an automatic link (setting footer_link, off by default:
 *    themes print wp_footer in different places).
 *
 * Every part follows the mode: live for everyone, test for shop managers.
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Account {

	public static function init(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'orders_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'view_order' ), 20 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_link' ), 20, 4 );
		add_action( 'wp_footer', array( __CLASS__, 'footer_link' ), 20 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 20 );
	}

	/** Form URL for an order: with the key for links that leave the site. */
	public static function url( WC_Order $order, bool $with_key ): string {
		$base = EWD_Settings::page_url();
		if ( '' === $base ) {
			return '';
		}
		$args = array( 'ewd_order' => $order->get_id() );
		if ( $with_key ) {
			$args['key'] = $order->get_order_key();
		}
		return add_query_arg( $args, $base ) . '#ewd-form';
	}

	public static function orders_action( $actions, $order ) {
		try {
			if ( ! is_array( $actions ) || ! $order instanceof WC_Order || ! EWD_Settings::visible() || ! EWD_Rules::order_open( $order ) ) {
				return $actions;
			}
			$url = self::url( $order, false );
			if ( '' !== $url ) {
				$actions['ewd-withdraw'] = array(
					'url'  => $url,
					'name' => EWD_Settings::text( 'label' ),
				);
			}
		} catch ( \Throwable $e ) {
			return $actions;
		}
		return $actions;
	}

	public static function view_order( $order ): void {
		try {
			if ( ! $order instanceof WC_Order || ! EWD_Settings::visible() || ! is_account_page() ) {
				return;
			}
			$reqs = EWD_Requests::for_order( $order );
			$open = EWD_Rules::order_open( $order );
			$url  = $open ? self::url( $order, false ) : '';
			if ( ! $reqs && '' === $url ) {
				return;
			}
			wp_enqueue_style( 'ewd-front', EWD_URL . 'assets/front.css', array(), EWD_VERSION );
			echo '<section class="ewd-root ewd-account"><h2 class="ewd-h">' . esc_html__( 'Υπαναχώρηση', 'easy-withdrawal' ) . '</h2>';
			if ( $reqs ) {
				echo '<table class="ewd-table shop_table"><thead><tr><th>' . esc_html__( 'Αίτημα', 'easy-withdrawal' ) . '</th><th>' . esc_html__( 'Υποβλήθηκε', 'easy-withdrawal' ) . '</th><th>' . esc_html__( 'Προϊόντα', 'easy-withdrawal' ) . '</th><th>' . esc_html__( 'Κατάσταση', 'easy-withdrawal' ) . '</th></tr></thead><tbody>';
				foreach ( $reqs as $r ) {
					echo '<tr><td>' . esc_html( (string) $r['id'] ) . '</td><td>' . esc_html( EWD_Lang::datetime( (int) $r['created'] ) ) . '</td><td>' . esc_html( EWD_Requests::items_text( $r['items'] ) ) . '</td><td>' . esc_html( EWD_Requests::status_label( (string) $r['status'] ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( '' !== $url ) {
				echo '<p><a class="' . esc_attr( EWD_Form::btn() ) . '" href="' . esc_url( $url ) . '">' . esc_html( EWD_Settings::text( 'label' ) ) . '</a></p>';
			}
			echo '</section>';
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Link in customer order emails (and on the thank-you page, which
	 * WooCommerce does not render through this hook: see thankyou()).
	 */
	public static function email_link( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		try {
			if ( $sent_to_admin || ! $order instanceof WC_Order || ! EWD_Settings::get()['email_links'] ) {
				return;
			}
			// Live mode only: emails reach customers, test mode never shows them anything.
			if ( 'live' !== EWD_Settings::get()['mode'] || Easy_Withdrawal::killed() ) {
				return;
			}
			$id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
			if ( ! in_array( $id, array( 'customer_processing_order', 'customer_on_hold_order', 'customer_completed_order', 'customer_invoice' ), true ) ) {
				return;
			}
			if ( ! EWD_Rules::order_open( $order ) ) {
				return;
			}
			$url = self::url( $order, true );
			if ( '' === $url ) {
				return;
			}
			$text = __( 'Αν αλλάξεις γνώμη, μπορείς να υπαναχωρήσεις από την αγορά μέσα στην προθεσμία:', 'easy-withdrawal' );
			if ( $plain_text ) {
				echo "\n" . esc_html( $text ) . "\n" . esc_html( EWD_Settings::text( 'label' ) ) . ': ' . esc_url_raw( $url ) . "\n\n";
				return;
			}
			echo '<p style="margin:0 0 16px;">' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html( EWD_Settings::text( 'label' ) ) . '</a></p>';
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Thank-you page link (classic and block order confirmation). */
	public static function thankyou( $order_id ): void {
		try {
			if ( ! EWD_Settings::get()['email_links'] || ! EWD_Settings::visible() ) {
				return;
			}
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order || ! EWD_Rules::order_open( $order ) ) {
				return;
			}
			$url = self::url( $order, true );
			if ( '' === $url ) {
				return;
			}
			echo '<p class="ewd-thankyou">' . esc_html__( 'Αν αλλάξεις γνώμη, μπορείς να υπαναχωρήσεις από την αγορά μέσα στην προθεσμία:', 'easy-withdrawal' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html( EWD_Settings::text( 'label' ) ) . '</a></p>';
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function footer_link(): void {
		try {
			if ( ! EWD_Settings::get()['footer_link'] || ! EWD_Settings::visible() || is_admin() ) {
				return;
			}
			$url = EWD_Settings::page_url();
			if ( '' === $url ) {
				return;
			}
			echo '<p class="ewd-footer-link" style="text-align:center;margin:12px 0;"><a href="' . esc_url( $url ) . '">' . esc_html( EWD_Settings::text( 'label' ) ) . '</a></p>';
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
