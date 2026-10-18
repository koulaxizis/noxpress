<?php
/**
 * SHN_Settings — the shn_settings option (autoload: read on every cart,
 * mini-cart and product view), strict validation with whitelists (Bible §11).
 *
 * Shared:
 *  - lang              visitor text language: 'auto' (site locale) | 'el' | 'en'
 *
 * Free shipping (fs_*):
 *  - fs_mode           'off' (default) | 'test' (shop managers only) | 'live'
 *  - fs_source         'auto' (zones) | 'zones' (zones, plus an amount per
 *                      zone without free shipping) | 'fixed' (one amount)
 *  - fs_fixed          amount for 'fixed' (0 = none)
 *  - fs_zones          zone id => amount, for 'zones'
 *  - fs_cart           classic cart; fs_cart_pos 'totals' | 'table'
 *  - fs_minicart       classic mini-cart (widget, theme header carts)
 *  - fs_checkout       classic checkout (off by default)
 *  - fs_block_cart     Cart block; fs_block_checkout Checkout block (off)
 *  - fs_bar            progress bar under the text
 *  - fs_show_empty     message with an empty cart; fs_show_won "won" message
 *  - fs_color          bar colour (#hex, '' = the theme's link colour)
 *  - fs_t_empty / fs_t_left / fs_t_won   { el, en } texts
 *
 * Delivery time (dl_*):
 *  - dl_mode           'off' (default) | 'test' | 'live'
 *  - dl_zone           zone shown on product pages (-1 = the zone of the
 *                      store's own country)
 *  - dl_methods        instance id => { min, max, sat } transit days
 *  - dl_prep_min/max   store preparation days; dl_bo, dl_bo_min/max for
 *                      products on backorder
 *  - dl_cutoff         'HH:MM' ('' = none); dl_workdays ISO weekdays (1–7)
 *  - dl_holidays       'MM-DD' (every year) or 'YYYY-MM-DD' (once)
 *  - dl_format         'range' | 'list'; dl_position 'price' | 'cart' | 'none'
 *  - dl_dates          dates instead of business days (computed in the browser)
 *  - dl_cutoff_line    "order within …" line before the cut-off
 *  - dl_t_* texts      est, item, title, one, many, dates, item_dates,
 *                      cutoff, preorder — each { el, en }
 *
 * Parcels (mx_*, 1.1.0): carts split into several shipping packages, e.g.
 * by the Printful plugin (print on demand, sent by the partner):
 *  - mx_mode           'off' (default) | 'test' | 'live'
 *  - mx_printful       the package the Printful plugin made
 *                      ($package['printful']) is a partner parcel
 *  - mx_classes        shipping class slugs of partner products (also the
 *                      signal of the product-page hint)
 *  - mx_names          names for the parcels ("Parcel 1 · …")
 *  - mx_notice         "two parcels" notice above the shipping rows;
 *                      mx_color its left border (#hex, '' = text colour)
 *  - mx_labels         translate the partner's rate labels: mx_map rows
 *                      { from, el, en } (whole words, case-insensitive),
 *                      mx_dates English month dates → Greek
 *  - mx_order          parcel list in the order (thank-you page, My
 *                      account, customer emails)
 *  - mx_hint           product-page line under the price (off)
 *  - mx_t_* texts      partner, own, notice, order, hint, eta — each { el, en }
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Settings {

	const OPT = 'shn_settings';

	/** Product meta (string days / 'Y-m-d'; absent = not set). */
	const META_PREP_MIN = '_shn_prep_min';
	const META_PREP_MAX = '_shn_prep_max';
	const META_FROM     = '_shn_available_from';

	/** Term meta of product categories. */
	const TERM_PREP_MIN = 'shn_prep_min';
	const TERM_PREP_MAX = 'shn_prep_max';

	/** Upper limit of every day count. */
	const MAX_DAYS = 90;

	/** Text keys => max length. */
	const TEXTS = array(
		'fs_t_empty'      => 200,
		'fs_t_left'       => 200,
		'fs_t_won'        => 200,
		'dl_t_est'        => 200,
		'dl_t_item'       => 200,
		'dl_t_title'      => 100,
		'dl_t_one'        => 50,
		'dl_t_many'       => 50,
		'dl_t_dates'      => 200,
		'dl_t_item_dates' => 200,
		'dl_t_cutoff'     => 200,
		'dl_t_preorder'   => 200,
		'mx_t_partner'    => 150,
		'mx_t_own'        => 150,
		'mx_t_notice'     => 600,
		'mx_t_order'      => 150,
		'mx_t_hint'       => 200,
		'mx_t_eta'        => 60,
	);

	/** Most shipping classes and label map rows of the parcels part. */
	const MAX_CLASSES = 20;
	const MAX_MAP     = 20;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'lang'              => 'auto',

			'fs_mode'           => 'off',
			'fs_source'         => 'auto',
			'fs_fixed'          => 0.0,
			'fs_zones'          => array(),
			'fs_cart'           => true,
			'fs_cart_pos'       => 'totals',
			'fs_minicart'       => true,
			'fs_checkout'       => false,
			'fs_block_cart'     => true,
			'fs_block_checkout' => false,
			'fs_bar'            => true,
			'fs_show_empty'     => true,
			'fs_show_won'       => true,
			'fs_color'          => '',
			'fs_t_empty'        => array(
				'el' => 'Δωρεάν αποστολή για αγορές από {threshold}',
				'en' => 'Free shipping on orders of {threshold} or more',
			),
			'fs_t_left'         => array(
				'el' => 'Απομένουν {remaining} για δωρεάν αποστολή',
				'en' => 'Add {remaining} more for free shipping',
			),
			'fs_t_won'          => array(
				'el' => 'Κερδίσατε δωρεάν αποστολή!',
				'en' => 'You have free shipping!',
			),

			'dl_mode'           => 'off',
			'dl_zone'           => -1,
			'dl_methods'        => array(),
			'dl_prep_min'       => 0,
			'dl_prep_max'       => 1,
			'dl_bo'             => false,
			'dl_bo_min'         => 5,
			'dl_bo_max'         => 10,
			'dl_cutoff'         => '14:00',
			'dl_workdays'       => array( 1, 2, 3, 4, 5 ),
			'dl_holidays'       => array(),
			'dl_format'         => 'range',
			'dl_position'       => 'price',
			'dl_dates'          => false,
			'dl_cutoff_line'    => false,
			'dl_t_est'          => array(
				'el' => 'Παράδοση σε {days}',
				'en' => 'Delivery in {days}',
			),
			'dl_t_item'         => array(
				'el' => '{method}: {days}',
				'en' => '{method}: {days}',
			),
			'dl_t_title'        => array(
				'el' => 'Χρόνος παράδοσης',
				'en' => 'Delivery time',
			),
			'dl_t_one'          => array(
				'el' => 'εργάσιμη',
				'en' => 'business day',
			),
			'dl_t_many'         => array(
				'el' => 'εργάσιμες',
				'en' => 'business days',
			),
			'dl_t_dates'        => array(
				'el' => 'Παράδοση {dates}',
				'en' => 'Delivery {dates}',
			),
			'dl_t_item_dates'   => array(
				'el' => '{method}: {dates}',
				'en' => '{method}: {dates}',
			),
			'dl_t_cutoff'       => array(
				'el' => 'Παραγγείλτε μέσα σε {hours} ώ. και {minutes} λ. για αποστολή σήμερα',
				'en' => 'Order within {hours} h {minutes} min to ship today',
			),
			'dl_t_preorder'     => array(
				'el' => 'Διαθέσιμο από {available}. Παράδοση σε {days} από την κυκλοφορία.',
				'en' => 'Available from {available}. Delivery in {days} after release.',
			),

			'mx_mode'           => 'off',
			'mx_printful'       => true,
			'mx_classes'        => array(),
			'mx_names'          => true,
			'mx_notice'         => true,
			'mx_color'          => '',
			'mx_labels'         => true,
			'mx_map'            => array(
				array(
					'from' => 'Flat Rate',
					'el'   => 'Τυπική αποστολή',
					'en'   => 'Standard shipping',
				),
				array(
					'from' => 'Standard',
					'el'   => 'Τυπική αποστολή',
					'en'   => '',
				),
				array(
					'from' => 'Express',
					'el'   => 'Ταχεία αποστολή',
					'en'   => '',
				),
			),
			'mx_dates'          => true,
			'mx_order'          => true,
			'mx_hint'           => false,
			'mx_t_partner'      => array(
				'el' => 'Δέμα {n} · Προϊόντα κατά παραγγελία (από τον συνεργάτη παραγωγής)',
				'en' => 'Parcel {n} · Made to order (from our production partner)',
			),
			'mx_t_own'          => array(
				'el' => 'Δέμα {n} · Από το κατάστημά μας',
				'en' => 'Parcel {n} · From our store',
			),
			'mx_t_notice'       => array(
				'el' => 'Η παραγγελία σας θα σταλεί σε δύο ξεχωριστά δέματα: τα ρούχα και αξεσουάρ που τυπώνονται κατά παραγγελία έρχονται απευθείας από τον συνεργάτη παραγωγής, με δικά τους μεταφορικά και χρόνο παράδοσης· τα υπόλοιπα προϊόντα αποστέλλονται από εμάς μέσα σε 2 εργάσιμες. Γι\' αυτό βλέπετε δύο χρεώσεις αποστολής.',
				'en' => 'Your order will be sent in two separate parcels: the clothes and accessories printed to order come directly from our production partner, with their own shipping cost and delivery time; the other products are sent by us within 2 business days. That is why you see two shipping charges.',
			),
			'mx_t_order'        => array(
				'el' => 'Η παραγγελία σας έρχεται σε {count} δέματα',
				'en' => 'Your order comes in {count} parcels',
			),
			'mx_t_hint'         => array(
				'el' => 'Τυπώνεται κατά παραγγελία · αποστέλλεται χωριστά από τα υπόλοιπα προϊόντα.',
				'en' => 'Printed to order · ships separately from the other products.',
			),
			'mx_t_eta'          => array(
				'el' => 'Εκτιμώμενη παράδοση',
				'en' => 'Estimated delivery',
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

		$s['lang'] = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );

		// Free shipping.
		$s['fs_mode']     = self::pick( $raw, 'fs_mode', array( 'off', 'test', 'live' ), $d['fs_mode'] );
		$s['fs_source']   = self::pick( $raw, 'fs_source', array( 'auto', 'zones', 'fixed' ), $d['fs_source'] );
		$s['fs_fixed']    = self::amount( $raw['fs_fixed'] ?? 0 );
		$s['fs_cart_pos'] = self::pick( $raw, 'fs_cart_pos', array( 'totals', 'table' ), $d['fs_cart_pos'] );
		$s['fs_zones']    = array();
		if ( isset( $raw['fs_zones'] ) && is_array( $raw['fs_zones'] ) ) {
			foreach ( $raw['fs_zones'] as $zid => $amount ) {
				$zid = is_int( $zid ) || ( is_string( $zid ) && ctype_digit( $zid ) ) ? (int) $zid : -1;
				$amt = self::amount( $amount );
				if ( $zid >= 0 && $amt > 0 && count( $s['fs_zones'] ) < 200 ) {
					$s['fs_zones'][ $zid ] = $amt;
				}
			}
		}
		foreach ( array( 'fs_cart', 'fs_minicart', 'fs_checkout', 'fs_block_cart', 'fs_block_checkout', 'fs_bar', 'fs_show_empty', 'fs_show_won' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		$color         = isset( $raw['fs_color'] ) && is_string( $raw['fs_color'] ) ? sanitize_hex_color( trim( $raw['fs_color'] ) ) : '';
		$s['fs_color'] = is_string( $color ) ? $color : '';

		// Delivery time.
		$s['dl_mode']     = self::pick( $raw, 'dl_mode', array( 'off', 'test', 'live' ), $d['dl_mode'] );
		$s['dl_zone']     = isset( $raw['dl_zone'] ) && is_numeric( $raw['dl_zone'] ) ? max( -1, (int) $raw['dl_zone'] ) : $d['dl_zone'];
		$s['dl_format']   = self::pick( $raw, 'dl_format', array( 'range', 'list' ), $d['dl_format'] );
		$s['dl_position'] = self::pick( $raw, 'dl_position', array( 'price', 'cart', 'none' ), $d['dl_position'] );
		foreach ( array( 'dl_bo', 'dl_dates', 'dl_cutoff_line' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		list( $s['dl_prep_min'], $s['dl_prep_max'] ) = self::range( $raw['dl_prep_min'] ?? $d['dl_prep_min'], $raw['dl_prep_max'] ?? $d['dl_prep_max'] );
		list( $s['dl_bo_min'], $s['dl_bo_max'] )     = self::range( $raw['dl_bo_min'] ?? $d['dl_bo_min'], $raw['dl_bo_max'] ?? $d['dl_bo_max'] );

		$s['dl_methods'] = array();
		if ( isset( $raw['dl_methods'] ) && is_array( $raw['dl_methods'] ) ) {
			foreach ( $raw['dl_methods'] as $iid => $m ) {
				$iid = is_int( $iid ) || ( is_string( $iid ) && ctype_digit( $iid ) ) ? (int) $iid : 0;
				if ( $iid <= 0 || ! is_array( $m ) || count( $s['dl_methods'] ) >= 500 ) {
					continue;
				}
				$min = self::day_or_null( $m['min'] ?? null );
				$max = self::day_or_null( $m['max'] ?? null );
				if ( null === $min && null === $max ) {
					continue; // Not configured: the method is left out of the estimate.
				}
				list( $min, $max )       = self::range( null === $min ? $max : $min, null === $max ? $min : $max );
				$s['dl_methods'][ $iid ] = array(
					'min' => $min,
					'max' => $max,
					'sat' => ! empty( $m['sat'] ),
				);
			}
		}

		$s['dl_cutoff'] = $d['dl_cutoff'];
		if ( isset( $raw['dl_cutoff'] ) && is_string( $raw['dl_cutoff'] ) ) {
			$c              = trim( $raw['dl_cutoff'] );
			$s['dl_cutoff'] = '' === $c ? '' : self::time( $c, $d['dl_cutoff'] );
		}

		$s['dl_workdays'] = $d['dl_workdays'];
		if ( isset( $raw['dl_workdays'] ) && is_array( $raw['dl_workdays'] ) ) {
			$days = array();
			foreach ( $raw['dl_workdays'] as $wd ) {
				if ( is_scalar( $wd ) && ctype_digit( (string) $wd ) && (int) $wd >= 1 && (int) $wd <= 7 ) {
					$days[ (int) $wd ] = true;
				}
			}
			ksort( $days );
			$s['dl_workdays'] = array_keys( $days ); // An empty list is allowed: no estimate is shown.
		}

		$s['dl_holidays'] = self::holidays( $raw['dl_holidays'] ?? array() );

		// Parcels.
		$s['mx_mode'] = self::pick( $raw, 'mx_mode', array( 'off', 'test', 'live' ), $d['mx_mode'] );
		foreach ( array( 'mx_printful', 'mx_names', 'mx_notice', 'mx_labels', 'mx_dates', 'mx_order', 'mx_hint' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		$color           = isset( $raw['mx_color'] ) && is_string( $raw['mx_color'] ) ? sanitize_hex_color( trim( $raw['mx_color'] ) ) : '';
		$s['mx_color']   = is_string( $color ) ? $color : '';
		$s['mx_classes'] = array();
		if ( isset( $raw['mx_classes'] ) && is_array( $raw['mx_classes'] ) ) {
			foreach ( $raw['mx_classes'] as $slug ) {
				$slug = is_string( $slug ) ? sanitize_title( $slug ) : '';
				if ( '' !== $slug && strlen( $slug ) <= 200 && ! in_array( $slug, $s['mx_classes'], true ) && count( $s['mx_classes'] ) < self::MAX_CLASSES ) {
					$s['mx_classes'][] = $slug;
				}
			}
		}
		$s['mx_map'] = $d['mx_map'];
		if ( isset( $raw['mx_map'] ) && is_array( $raw['mx_map'] ) ) {
			$s['mx_map'] = array();
			foreach ( $raw['mx_map'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row = array(
					'from' => isset( $row['from'] ) && is_string( $row['from'] ) ? mb_substr( trim( sanitize_text_field( $row['from'] ) ), 0, 60 ) : '',
					'el'   => isset( $row['el'] ) && is_string( $row['el'] ) ? mb_substr( trim( sanitize_text_field( $row['el'] ) ), 0, 100 ) : '',
					'en'   => isset( $row['en'] ) && is_string( $row['en'] ) ? mb_substr( trim( sanitize_text_field( $row['en'] ) ), 0, 100 ) : '',
				);
				if ( '' !== $row['from'] && ( '' !== $row['el'] || '' !== $row['en'] ) && count( $s['mx_map'] ) < self::MAX_MAP ) {
					$s['mx_map'][] = $row;
				}
			}
		}

		// Texts (Bible §14.17): plain text, length limit, empty English → Greek.
		foreach ( self::TEXTS as $k => $len ) {
			$s[ $k ] = array();
			foreach ( array( 'el', 'en' ) as $l ) {
				$v             = isset( $raw[ $k ][ $l ] ) && is_string( $raw[ $k ][ $l ] ) ? trim( sanitize_text_field( $raw[ $k ][ $l ] ) ) : null;
				$s[ $k ][ $l ] = null === $v ? $d[ $k ][ $l ] : mb_substr( $v, 0, $len );
			}
			if ( '' === $s[ $k ]['el'] ) {
				$s[ $k ]['el'] = $d[ $k ]['el'];
			}
		}

		return $s;
	}

	/** One of the allowed string values, else the default. */
	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/** A non-negative amount from a number or a string with either decimal separator. */
	public static function amount( $v ): float {
		if ( is_string( $v ) ) {
			$v = str_replace( ',', '.', preg_replace( '/[^0-9.,]/', '', $v ) );
		}
		if ( ! is_numeric( $v ) ) {
			return 0.0;
		}
		$f = round( (float) $v, 4 );
		return ( $f > 0 && $f < 100000000 ) ? $f : 0.0;
	}

	/** Day count 0–MAX_DAYS, or null for an empty field. */
	public static function day_or_null( $v ): ?int {
		if ( null === $v || ( is_string( $v ) && '' === trim( $v ) ) ) {
			return null;
		}
		if ( ! is_scalar( $v ) || ! is_numeric( $v ) ) {
			return null;
		}
		return min( self::MAX_DAYS, max( 0, (int) $v ) );
	}

	/** [min, max] day counts, clamped, max >= min. */
	public static function range( $min, $max ): array {
		$a = self::day_or_null( $min );
		$b = self::day_or_null( $max );
		$a = null === $a ? 0 : $a;
		$b = null === $b ? $a : $b;
		return $a <= $b ? array( $a, $b ) : array( $b, $a );
	}

	/** 'HH:MM' (24 h) or the default. */
	private static function time( string $v, string $default ): string {
		if ( preg_match( '/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', $v, $m ) && (int) $m[1] <= 23 && (int) $m[2] <= 59 ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}
		return $default;
	}

	/**
	 * Holidays from a list or a textarea: 'MM-DD', 'YYYY-MM-DD', 'DD/MM' or
	 * 'DD/MM/YYYY', one per line or separated by commas. Stored as
	 * 'MM-DD' / 'YYYY-MM-DD', sorted, unique; dated entries in the past are
	 * dropped (max 200).
	 */
	public static function holidays( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,;]+/', $raw );
		}
		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$out   = array();
		foreach ( (array) $raw as $v ) {
			if ( ! is_string( $v ) ) {
				continue;
			}
			$v = trim( $v );
			$y = 0;
			if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m ) ) {
				list( $y, $mo, $da ) = array( (int) $m[1], (int) $m[2], (int) $m[3] );
			} elseif ( preg_match( '/^(\d{1,2})-(\d{1,2})$/', $v, $m ) ) {
				list( $mo, $da ) = array( (int) $m[1], (int) $m[2] );
			} elseif ( preg_match( '#^(\d{1,2})/(\d{1,2})(?:/(\d{4}))?$#', $v, $m ) ) {
				list( $da, $mo ) = array( (int) $m[1], (int) $m[2] );
				$y               = isset( $m[3] ) ? (int) $m[3] : 0;
			} else {
				continue;
			}
			if ( ! checkdate( $mo, $da, $y > 0 ? $y : 2000 ) ) {
				continue;
			}
			$key = $y > 0 ? sprintf( '%04d-%02d-%02d', $y, $mo, $da ) : sprintf( '%02d-%02d', $mo, $da );
			if ( $y > 0 && $key < $today ) {
				continue;
			}
			$out[ $key ] = true;
			if ( count( $out ) >= 200 ) {
				break;
			}
		}
		$keys = array_map( 'strval', array_keys( $out ) );
		sort( $keys, SORT_STRING );
		return $keys;
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
	 * @param string      $key  A key of TEXTS.
	 * @param string|null $lang 'el' | 'en', or null for the visitor language.
	 */
	public static function text( string $key, ?string $lang = null ): string {
		$s    = self::get();
		$lang = 'el' === $lang || 'en' === $lang ? $lang : self::lang();
		$pair = isset( $s[ $key ] ) && is_array( $s[ $key ] ) ? $s[ $key ] : array(
			'el' => '',
			'en' => '',
		);
		$text = ( 'en' === $lang && '' !== $pair['en'] ) ? $pair['en'] : $pair['el'];
		/**
		 * Filters a visitor text of Shipping Notes (e.g. for WPML).
		 *
		 * @param string $text Text with its {placeholders}.
		 * @param string $key  Text key (fs_t_left, dl_t_est, …).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'shn_text', $text, $key, $lang );
	}
}
