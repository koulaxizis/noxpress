<?php
/**
 * BKF_Guard — fixed book price (warning only).
 *
 * The feed says until when a title's price is a fixed price
 * (price_valid_until) and what kind of price it is (price_type). While
 * that date has not passed, a sale price whose discount is larger than
 * the allowed one gets a warning when the product is saved, and on the
 * product's Book Feed box. The allowed discount comes from a percentage
 * in price_type when there is one, else from the setting (default 10 %).
 * Nothing is blocked or changed: the law and its exceptions are the
 * store's call.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Guard {

	const NOTICE = 'bkf_guard_';

	public static function init(): void {
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'on_save' ), 99 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** Largest allowed discount in % for a product, or null when no fixed price applies. */
	public static function max_discount( int $pid ): ?float {
		$until = (string) get_post_meta( $pid, '_bkf_fixed_until', true );
		if ( '' === $until || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) || $until < wp_date( 'Y-m-d' ) ) {
			return null;
		}
		$ptype = (string) get_post_meta( $pid, '_bkf_ptype', true );
		if ( preg_match( '/(\d{1,2}(?:[.,]\d+)?)\s*%/u', $ptype, $m ) ) {
			return (float) str_replace( ',', '.', $m[1] );
		}
		return (float) BKF_Settings::get()['guard_pct'];
	}

	/**
	 * The warning text for a product, or ''.
	 *
	 * @param WC_Product $p Product (its current, possibly unsaved, prices).
	 */
	public static function check( WC_Product $p ): string {
		$max = self::max_discount( $p->get_id() );
		if ( null === $max ) {
			return '';
		}
		$reg  = (float) $p->get_regular_price( 'edit' );
		$sale = $p->get_sale_price( 'edit' );
		if ( $reg <= 0 || '' === $sale || null === $sale ) {
			return '';
		}
		$pct = ( 1 - (float) $sale / $reg ) * 100;
		if ( $pct <= $max + 0.01 ) {
			return '';
		}
		return sprintf(
			/* translators: 1: discount %, 2: allowed %, 3: date */
			__( 'Book Feed: η έκπτωση είναι %1$s%% ενώ ο τίτλος έχει ενιαία τιμή έως %3$s, με όριο έκπτωσης %2$s%%. Έλεγξε την τιμή προσφοράς.', 'book-feed' ),
			wc_format_localized_decimal( round( $pct, 1 ) ),
			wc_format_localized_decimal( $max ),
			date_i18n( get_option( 'date_format' ), strtotime( (string) get_post_meta( $p->get_id(), '_bkf_fixed_until', true ) ) )
		);
	}

	/** On save: keep the warning for the next screen. */
	public static function on_save( $p ): void {
		if ( ! $p instanceof WC_Product || ! $p->get_id() || '' === (string) get_post_meta( $p->get_id(), '_bkf_source', true ) ) {
			return;
		}
		$msg = self::check( $p );
		if ( '' !== $msg ) {
			set_transient( self::NOTICE . get_current_user_id(), $msg, 60 );
		}
	}

	public static function notice(): void {
		$key = self::NOTICE . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! is_string( $msg ) || '' === $msg ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
}
