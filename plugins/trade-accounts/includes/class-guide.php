<?php
/**
 * TRA_Guide — the trade guide page (setting "guide") for trade accounts:
 * an item in My Account's menu (before «Log out») and a line on its
 * dashboard. Customers and visitors see neither; the page itself stays a
 * normal page.
 *
 * The menu item is an endpoint key whose URL is the page's permalink
 * (woocommerce_get_endpoint_url), so no rewrite rule is added.
 *
 * Loaded with WooCommerce, whatever the modules; nothing runs while no
 * published page is chosen or the mode is "off".
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Guide {

	const KEY = 'tra-guide';

	public static function init(): void {
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'endpoint_url' ), 20, 2 );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'dashboard' ), 5 );
	}

	/** The guide page's URL, or '' when none is set, it is not published or the mode is "off". */
	public static function url(): string {
		$s = TRA_Settings::get();
		if ( 'off' === $s['mode'] || $s['guide'] <= 0 || 'publish' !== get_post_status( $s['guide'] ) ) {
			return '';
		}
		return (string) get_permalink( $s['guide'] );
	}

	private static function shown(): bool {
		return TRA_Accounts::is_trade() && '' !== self::url();
	}

	public static function menu( $items ) {
		if ( ! is_array( $items ) || ! self::shown() ) {
			return $items;
		}
		$label = TRA_Settings::text( 'guide' );
		$out   = array();
		foreach ( $items as $k => $v ) {
			if ( 'customer-logout' === $k ) {
				$out[ self::KEY ] = $label;
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out[ self::KEY ] ) ) {
			$out[ self::KEY ] = $label;
		}
		return $out;
	}

	public static function endpoint_url( $url, $endpoint ) {
		if ( self::KEY !== $endpoint ) {
			return $url;
		}
		$guide = self::url();
		return '' !== $guide ? $guide : $url;
	}

	public static function dashboard(): void {
		if ( ! self::shown() ) {
			return;
		}
		printf(
			'<p class="tra-guide">%s <a href="%s">%s</a></p>',
			esc_html( TRA_Settings::text( 'guide_note' ) ),
			esc_url( self::url() ),
			esc_html( TRA_Settings::text( 'guide' ) )
		);
	}
}
