<?php
/**
 * PBN_Settings — the pbn_settings option, strict validation with
 * whitelists (Bible §11), visitor texts (Bible §14.17).
 *
 * pbn_settings (autoload: read on every product page and cart):
 *  - mode          'off' (default) | 'test' (shop managers only) | 'live'
 *  - discount      default discount % for new bundles (0–90, default 20)
 *  - coupons       'no' (default: coupons skip bundle lines; a 100 %
 *                  coupon always applies) | 'yes'
 *  - show_contents list of contents on the bundle page
 *  - show_savings  "Separately … · You save …" line on the bundle page
 *  - lang, t_*     visitor texts (el / en)
 *  - delete_data   delete the bundle definitions (_pbn_* product meta) on uninstall
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Settings {

	const OPT = 'pbn_settings';

	/** Text keys => max length. */
	const TEXTS = array(
		't_contains'   => 60,
		't_savings'    => 140,
		't_part'       => 40,
		't_choose'     => 60,
		't_options'    => 40,
		't_from'       => 30,
		't_removed'    => 160,
		't_unavail'    => 160,
		't_qty_locked' => 160,
	);

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/** @var string[]|null Other bundle plugins (per request). */
	private static $others = null;

	public static function defaults(): array {
		return array(
			'mode'          => 'off',
			'discount'      => 20,
			'coupons'       => 'no',
			'show_contents' => true,
			'show_savings'  => true,
			'lang'          => 'auto',
			't_contains'    => array(
				'el' => 'Το πακέτο περιέχει',
				'en' => 'This bundle contains',
			),
			't_savings'     => array(
				'el' => 'Χωριστά %1$s · Εξοικονομείς %2$s (%3$s)',
				'en' => 'Separately %1$s · You save %2$s (%3$s)',
			),
			't_part'        => array(
				'el' => 'Πακέτο',
				'en' => 'Bundle',
			),
			't_choose'      => array(
				'el' => 'Διάλεξε',
				'en' => 'Choose',
			),
			't_options'     => array(
				'el' => 'Επιλογές',
				'en' => 'Select options',
			),
			't_from'        => array(
				'el' => 'Από',
				'en' => 'From',
			),
			't_removed'     => array(
				'el' => 'Το πακέτο «%s» αφαιρέθηκε ολόκληρο από το καλάθι.',
				'en' => 'The whole bundle "%s" was removed from the cart.',
			),
			't_unavail'     => array(
				'el' => 'Το πακέτο «%s» δεν είναι πια διαθέσιμο όπως ήταν και αφαιρέθηκε από το καλάθι.',
				'en' => 'The bundle "%s" is no longer available as it was and was removed from the cart.',
			),
			't_qty_locked'  => array(
				'el' => 'Η ποσότητα των προϊόντων ενός πακέτου δεν αλλάζει. Για περισσότερα πακέτα, πρόσθεσέ τα από τη σελίδα του πακέτου.',
				'en' => 'The quantity of the products in a bundle cannot be changed. To get more bundles, add them from the bundle page.',
			),
			'delete_data'   => false,
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $s ): void {
		$s           = self::sanitize( $s );
		self::$cache = $s;
		update_option( self::OPT, $s, true );
	}

	/** Drops the per-request cache (tests, imports). */
	public static function flush(): void {
		self::$cache = null;
	}

	/** Whitelist validation: unknown keys are dropped, bad values fall back to the defaults. */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode']     = self::pick( $raw, 'mode', array( 'off', 'test', 'live' ), $d['mode'] );
		$s['discount'] = self::int( $raw, 'discount', 0, 90, $d['discount'] );
		$s['coupons']  = self::pick( $raw, 'coupons', array( 'no', 'yes' ), $d['coupons'] );
		$s['lang']     = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );
		foreach ( array( 'show_contents', 'show_savings', 'delete_data' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		foreach ( self::TEXTS as $key => $len ) {
			$s[ $key ] = $d[ $key ];
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				foreach ( array( 'el', 'en' ) as $l ) {
					$v = isset( $raw[ $key ][ $l ] ) && is_string( $raw[ $key ][ $l ] ) ? self::line( $raw[ $key ][ $l ], $len ) : '';
					// Empty Greek = the default; empty English = shows the Greek.
					if ( 'el' === $l && '' === $v ) {
						continue;
					}
					$s[ $key ][ $l ] = $v;
				}
			}
		}

		return $s;
	}

	/** One line of plain text, at most $len characters. */
	public static function line( string $v, int $len ): string {
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $len );
	}

	/** One of the allowed string values, else the default. */
	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/** An integer in [min, max], else the default. */
	private static function int( array $raw, string $key, int $min, int $max, int $default ): int {
		if ( ! isset( $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) ) {
			return $default;
		}
		$v = (int) $raw[ $key ];
		return ( $v >= $min && $v <= $max ) ? $v : $default;
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/** 'off' | 'test' | 'live'; PBN_DISABLE counts as 'off'. */
	public static function mode(): string {
		if ( Product_Bundles::killed() ) {
			return 'off';
		}
		$mode = self::get()['mode'];
		// Bible §14.23: another bundles plugin active → test mode at most.
		if ( 'live' === $mode && self::others() ) {
			return 'test';
		}
		return $mode;
	}

	/**
	 * Other bundle plugins that are active (names), so visitors never see
	 * two kinds of bundles.
	 *
	 * @return string[]
	 */
	public static function others(): array {
		if ( null !== self::$others ) {
			return self::$others;
		}
		// Known by the product type they register (product_type_selector).
		$known = array(
			'bundle'              => 'WooCommerce Product Bundles',
			'woosb'               => 'WPC Product Bundles',
			'easy_product_bundle' => 'Easy Product Bundles',
		);
		$types = function_exists( 'wc_get_product_types' ) ? (array) wc_get_product_types() : array();
		$out   = array();
		foreach ( $known as $type => $name ) {
			if ( isset( $types[ $type ] ) ) {
				$out[] = $name;
			}
		}
		if ( did_action( 'init' ) ) {
			self::$others = $out;
		}
		return $out;
	}

	/**
	 * True when the current visitor may buy bundles (mode + test mode).
	 * Admin, cron and CLI requests may always build orders with bundles
	 * (the order screen box), whatever the mode.
	 */
	public static function sellable(): bool {
		$mode = self::mode();
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( 'manage_woocommerce' );
	}

	/** Visitor language for the texts: 'el' | 'en' (Bible §14.17). */
	public static function lang(): string {
		$choice = self::get()['lang'];
		if ( 'el' === $choice || 'en' === $choice ) {
			return $choice;
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}

	/**
	 * A visitor text in the visitor language (empty English → Greek).
	 *
	 * @param string $key A key of TEXTS.
	 */
	public static function text( string $key ): string {
		$s    = self::get();
		$lang = self::lang();
		$pair = isset( $s[ $key ] ) && is_array( $s[ $key ] ) ? $s[ $key ] : array(
			'el' => '',
			'en' => '',
		);
		$text = ( 'en' === $lang && '' !== $pair['en'] ) ? $pair['en'] : $pair['el'];
		/**
		 * Filters a visitor text of Product Bundles (e.g. for WPML).
		 *
		 * @param string $text Text.
		 * @param string $key  Text key (t_contains, t_savings, t_part, t_choose, t_options, t_from, t_removed, t_unavail, t_qty_locked).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'pbn_text', $text, $key, $lang );
	}

	/**
	 * A text with %s / %1$s placeholders filled, safe when the user's text
	 * has fewer or broken placeholders.
	 *
	 * @param string $key  Text key.
	 * @param array  $args Values.
	 */
	public static function textf( string $key, array $args ): string {
		$text = self::text( $key );
		try {
			$out = @vsprintf( $text, $args ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- user text, checked below.
		} catch ( \Throwable $e ) {
			$out = false;
		}
		return is_string( $out ) ? $out : $text;
	}
}
