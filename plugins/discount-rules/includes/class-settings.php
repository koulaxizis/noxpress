<?php
/**
 * DSR_Settings — the dsr_settings option (autoload: read on every product
 * view), strict validation with whitelists (Bible §11), mode, visitor
 * language and texts.
 *
 *  - mode        'off' (default) | 'test' (shop managers only) | 'live'
 *  - cap         the most a whole order can get from rules, in percent of
 *                its subtotal (1–100, default 50)
 *  - cat_max     [ term_id => percent ] the most any line of a category
 *                (or of its subcategories) can get, e.g. new books 10 %
 *  - ex_others   other publishers' titles never get a rule (Book Feed,
 *                Trade Feed Import), default on
 *  - ex_preorder pre-order products never get a rule, default off
 *  - hint        «Πρόσθεσε 1 ακόμη…» in the classic cart, default on
 *  - loop_label  quantity rules in product lists: the ribbon over the
 *                image, the explanation and the link to the offer page
 *  - table       the tier table on the product page
 *  - omnibus     the lowest 30-day price under a reduced price:
 *                'off' | 'rules' (default: when a rule lowers the price) |
 *                'all' (also WooCommerce's own sale prices)
 *  - lang        visitor text language 'auto' | 'el' | 'en'
 *  - t_*         { el, en } visitor texts (Bible §14.17)
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Settings {

	const OPT = 'dsr_settings';

	/** Most categories with their own limit. */
	const MAX_CATS = 30;

	/** Text keys => max length. */
	const TEXTS = array(
		't_line'    => 40,
		't_cart'    => 60,
		't_xy'      => 40,
		't_badge'   => 30,
		't_tiers'   => 60,
		't_tier'    => 60,
		't_until'   => 40,
		't_hint'    => 160,
		't_omnibus' => 120,
		't_xy_help' => 120,
		't_xy_mix'  => 160,
		't_more'    => 60,
	);

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'        => 'off',
			'cap'         => 50,
			'cat_max'     => array(),
			'ex_others'   => 'yes',
			'ex_preorder' => 'no',
			'hint'        => 'yes',
			'loop_label'  => 'yes',
			'table'       => 'yes',
			'omnibus'     => 'rules',
			'lang'        => 'auto',
			't_line'      => array(
				'el' => 'Προσφορά',
				'en' => 'Offer',
			),
			't_cart'      => array(
				'el' => 'Έκπτωση',
				'en' => 'Discount',
			),
			't_xy'        => array(
				'el' => '{buy} για {pay}',
				'en' => '{buy} for {pay}',
			),
			't_badge'     => array(
				'el' => '−{percent}',
				'en' => '−{percent}',
			),
			't_tiers'     => array(
				'el' => 'Έκπτωση ποσότητας',
				'en' => 'Quantity discount',
			),
			't_tier'      => array(
				'el' => '{min}+ τεμάχια: {price}',
				'en' => '{min}+ items: {price}',
			),
			't_until'     => array(
				'el' => 'έως {date}',
				'en' => 'until {date}',
			),
			't_hint'      => array(
				'el' => 'Πρόσθεσε ακόμη {n} για την προσφορά «{rule}».',
				'en' => 'Add {n} more for the «{rule}» offer.',
			),
			't_omnibus'   => array(
				'el' => 'Χαμηλότερη τιμή των τελευταίων 30 ημερών: {price}',
				'en' => 'Lowest price in the last 30 days: {price}',
			),
			't_xy_help'   => array(
				'el' => 'Βάλτε {buy} στο καλάθι και πληρώνετε μόνο τα {pay}.',
				'en' => 'Put {buy} in the cart and pay for only {pay}.',
			),
			't_xy_mix'    => array(
				'el' => 'Μετράνε μαζί όλα τα προϊόντα της προσφοράς, και δώρο είναι πάντα το φθηνότερο.',
				'en' => 'All products in the offer count together, and the cheapest one is always free.',
			),
			't_more'      => array(
				'el' => 'Όλα τα «{label}» →',
				'en' => 'All «{label}» products →',
			),
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

		$s['mode']    = self::pick( $raw, 'mode', array( 'off', 'test', 'live' ), $d['mode'] );
		$s['omnibus'] = self::pick( $raw, 'omnibus', array( 'off', 'rules', 'all' ), $d['omnibus'] );
		$s['lang']    = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );
		foreach ( array( 'ex_others', 'ex_preorder', 'hint', 'loop_label', 'table' ) as $k ) {
			$s[ $k ] = self::pick( $raw, $k, array( 'yes', 'no' ), $d[ $k ] );
		}

		$cap      = isset( $raw['cap'] ) && is_numeric( $raw['cap'] ) ? (int) $raw['cap'] : $d['cap'];
		$s['cap'] = ( $cap >= 1 && $cap <= 100 ) ? $cap : $d['cap'];

		$s['cat_max'] = array();
		if ( isset( $raw['cat_max'] ) && is_array( $raw['cat_max'] ) ) {
			foreach ( $raw['cat_max'] as $term => $pct ) {
				$term = (int) $term;
				if ( $term <= 0 || ! is_numeric( $pct ) || count( $s['cat_max'] ) >= self::MAX_CATS ) {
					continue;
				}
				$pct = round( (float) $pct, 2 );
				if ( $pct >= 0 && $pct < 100 ) {
					$s['cat_max'][ $term ] = $pct;
				}
			}
		}

		foreach ( self::TEXTS as $key => $len ) {
			$s[ $key ] = $d[ $key ];
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				foreach ( array( 'el', 'en' ) as $l ) {
					$v = isset( $raw[ $key ][ $l ] ) && is_string( $raw[ $key ][ $l ] ) ? self::line( $raw[ $key ][ $l ], $len ) : '';
					if ( 'el' === $l && '' === $v ) {
						continue; // Empty Greek = the default.
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

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/** True when the current user gets the discounts (mode + test mode, Bible §14.1–2). */
	public static function visible(): bool {
		if ( Discount_Rules::killed() ) {
			return false;
		}
		$mode = self::get()['mode'];
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
		 * Filters a visitor text of Discount Rules (e.g. for WPML).
		 *
		 * @param string $text Text.
		 * @param string $key  Text key (t_line, t_cart, t_xy, t_badge, t_tiers, t_tier, t_until, t_hint, t_omnibus, t_xy_help, t_xy_mix, t_more).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'dsr_text', $text, $key, $lang );
	}

	/**
	 * A visitor text with its {placeholders} filled (values escaped by the caller's output).
	 *
	 * @param string $key  Text key.
	 * @param array  $vars name => plain value.
	 */
	public static function fill( string $key, array $vars ): string {
		$text = self::text( $key );
		foreach ( $vars as $k => $v ) {
			$text = str_replace( '{' . $k . '}', (string) $v, $text );
		}
		return (string) preg_replace( '/\{[a-z_]+\}/', '', $text );
	}
}
