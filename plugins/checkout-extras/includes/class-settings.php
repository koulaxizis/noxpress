<?php
/**
 * CKE_Settings — the cke_settings option (autoload: read on every checkout
 * and thank-you view), strict validation with whitelists (Bible §11).
 *
 * Three independent parts, each with its own mode 'off' (default) |
 * 'test' (shop managers only) | 'live':
 *
 *  Gift (g_*)
 *   - g_mode, g_position (before_customer | after_notes | before_submit)
 *   - g_fee (decimal string, 0 = no fee line), g_tax ('none' | 'taxable'),
 *     g_tax_class (WooCommerce tax class slug, '' = standard)
 *   - g_msg ('off' | 'optional' | 'required'), g_max (20–1000 characters)
 *   - g_shippable_only (hide the option when nothing in the cart ships)
 *   - g_admin_line (line in the admin New order email), g_column (orders list)
 *
 *  Thank-you page (t_*)
 *   - t_mode, t_replace (our message replaces WooCommerce's "Thank you.
 *     Your order has been received." line instead of a box above it)
 *   - t_products ('none' | 'cross' | 'related' | 'sale'), t_limit (1–8)
 *
 *  Thank-you coupon (c_*)
 *   - c_mode, c_statuses (processing / completed / on-hold)
 *   - issue rules: c_min_total, c_skip_used, c_one_active, c_days_between,
 *     c_first_only, c_skip_cats (orders made only of these categories)
 *   - the coupon: c_type ('percent' | 'fixed_cart'), c_amount, c_free_ship,
 *     c_expiry (days), c_min_spend, c_max_spend, c_individual, c_exclude_sale,
 *     c_products, c_ex_products, c_cats, c_ex_cats, c_limit_items,
 *     c_email_restrict, c_prefix
 *   - delivery: c_email ('order' | 'separate' | 'none'), c_email_ids
 *     (which WooCommerce customer emails carry it), c_remind + c_remind_days
 *   - housekeeping: c_cleanup + c_cleanup_days, c_delete_on_uninstall
 *
 *  Visitor texts (Bible §14.17): lang ('auto' | 'el' | 'en') and one
 *  { el, en } pair per key of TEXTS. Plain text (sanitize_text_field per
 *  line), placeholders filled on output (fill()).
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Settings {

	const OPT = 'cke_settings';

	/** Order statuses that may issue a coupon. */
	const STATUSES = array( 'processing', 'completed', 'on-hold' );

	/** Customer emails that may carry the coupon (c_email = 'order'). */
	const EMAIL_IDS = array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' );

	/** Most ids in a product / category list. */
	const MAX_IDS = 100;

	/**
	 * Text key => [ max length, multiline, empty allowed ].
	 * "Empty allowed": an empty Greek value stays empty (the part is not
	 * shown); otherwise an empty Greek value falls back to the default.
	 */
	const TEXTS = array(
		'g_label'          => array( 100, false, false ),
		'g_help'           => array( 300, true, true ),
		'g_fee_name'       => array( 100, false, false ),
		'g_msg_label'      => array( 100, false, false ),
		'g_msg_required'   => array( 200, false, false ),
		'g_msg_long'       => array( 200, false, false ),
		'g_row'            => array( 50, false, false ),
		'g_yes'            => array( 50, false, false ),
		't_paid_title'     => array( 150, false, true ),
		't_paid_body'      => array( 1000, true, true ),
		't_hold_title'     => array( 150, false, true ),
		't_hold_body'      => array( 1000, true, true ),
		't_products_title' => array( 100, false, true ),
		'c_title'          => array( 150, false, true ),
		'c_body'           => array( 600, true, false ),
		'c_pending'        => array( 300, true, true ),
		'c_copy'           => array( 30, false, false ),
		'c_copied'         => array( 30, false, false ),
		'c_remind_body'    => array( 600, true, false ),
	);

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'lang'                 => 'auto',

			'g_mode'               => 'off',
			'g_position'           => 'before_customer',
			'g_fee'                => '0',
			'g_tax'                => 'none',
			'g_tax_class'          => '',
			'g_msg'                => 'optional',
			'g_max'                => 250,
			'g_shippable_only'     => true,
			'g_admin_line'         => true,
			'g_column'             => true,

			't_mode'               => 'off',
			't_replace'            => false,
			't_products'           => 'none',
			't_limit'              => 4,

			'c_mode'               => 'off',
			'c_statuses'           => array( 'processing', 'completed' ),
			'c_min_total'          => '',
			'c_skip_used'          => true,
			'c_one_active'         => true,
			'c_days_between'       => 0,
			'c_first_only'         => false,
			'c_skip_cats'          => array(),
			'c_type'               => 'percent',
			'c_amount'             => '10',
			'c_free_ship'          => false,
			'c_expiry'             => 60,
			'c_min_spend'          => '',
			'c_max_spend'          => '',
			'c_individual'         => true,
			'c_exclude_sale'       => false,
			'c_products'           => array(),
			'c_ex_products'        => array(),
			'c_cats'               => array(),
			'c_ex_cats'            => array(),
			'c_limit_items'        => 0,
			'c_email_restrict'     => true,
			'c_prefix'             => 'ty-',
			'c_email'              => 'order',
			'c_email_ids'          => array( 'customer_processing_order', 'customer_completed_order' ),
			'c_remind'             => false,
			'c_remind_days'        => 7,
			'c_cleanup'            => true,
			'c_cleanup_days'       => 30,
			'c_delete_on_uninstall' => false,

			'g_label'              => array(
				'el' => 'Είναι δώρο',
				'en' => 'This is a gift',
			),
			'g_help'               => array(
				'el' => '',
				'en' => '',
			),
			'g_fee_name'           => array(
				'el' => 'Συσκευασία δώρου',
				'en' => 'Gift wrapping',
			),
			'g_msg_label'          => array(
				'el' => 'Αφιέρωση',
				'en' => 'Gift message',
			),
			'g_msg_required'       => array(
				'el' => 'Γράψτε την αφιέρωση για το δώρο.',
				'en' => 'Please write the gift message.',
			),
			'g_msg_long'           => array(
				'el' => 'Η αφιέρωση έχει έως {max} χαρακτήρες.',
				'en' => 'The gift message can have up to {max} characters.',
			),
			'g_row'                => array(
				'el' => 'Δώρο',
				'en' => 'Gift',
			),
			'g_yes'                => array(
				'el' => 'Ναι',
				'en' => 'Yes',
			),
			't_paid_title'         => array(
				'el' => 'Ευχαριστούμε, {first_name}!',
				'en' => 'Thank you, {first_name}!',
			),
			't_paid_body'          => array(
				'el' => 'Η παραγγελία #{order_number} καταχωρήθηκε. Θα σας στείλουμε email μόλις φύγει από το κατάστημα.',
				'en' => 'Order #{order_number} has been placed. We will email you as soon as it leaves the shop.',
			),
			't_hold_title'         => array(
				'el' => '',
				'en' => '',
			),
			't_hold_body'          => array(
				'el' => 'Η παραγγελία #{order_number} περιμένει την πληρωμή σας. Μόλις τη λάβουμε, θα σας στείλουμε email.',
				'en' => 'Order #{order_number} is waiting for your payment. We will email you as soon as we receive it.',
			),
			't_products_title'     => array(
				'el' => 'Μπορεί να σας αρέσουν',
				'en' => 'You may also like',
			),
			'c_title'              => array(
				'el' => 'Ένα δώρο για την επόμενη παραγγελία σας',
				'en' => 'A gift for your next order',
			),
			'c_body'               => array(
				'el' => '{amount} έκπτωση στην επόμενη παραγγελία σας με τον κωδικό {code}. Ισχύει έως {expiry}. Για αγορές από {min_spend}.',
				'en' => '{amount} off your next order with the code {code}. Valid until {expiry}. For orders from {min_spend}.',
			),
			'c_pending'            => array(
				'el' => 'Θα σας στείλουμε με email ένα κουπόνι έκπτωσης για την επόμενη παραγγελία σας, μόλις επιβεβαιωθεί η πληρωμή.',
				'en' => 'We will email you a discount coupon for your next order as soon as the payment is confirmed.',
			),
			'c_copy'               => array(
				'el' => 'Αντιγραφή',
				'en' => 'Copy',
			),
			'c_copied'             => array(
				'el' => 'Αντιγράφηκε',
				'en' => 'Copied',
			),
			'c_remind_body'        => array(
				'el' => 'Το κουπόνι σας {code} ({amount} έκπτωση) λήγει στις {expiry}. Μη το ξεχάσετε!',
				'en' => 'Your coupon {code} ({amount} off) expires on {expiry}. Do not miss it!',
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

	/** Validates and stores; an invalid value keeps the one saved before. */
	public static function save( array $s ): void {
		$s           = self::sanitize( $s, self::get() );
		self::$cache = $s;
		update_option( self::OPT, $s, true );
	}

	/** Drops the per-request cache (tests, imports). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Whitelist validation: unknown keys are dropped, bad values fall back
	 * to $base (the saved settings when saving), else to the defaults.
	 */
	public static function sanitize( array $raw, array $base = array() ): array {
		$d = array_merge( self::defaults(), $base );
		$s = array();

		$modes = array( 'off', 'test', 'live' );

		$s['lang'] = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );

		// Gift.
		$s['g_mode']           = self::pick( $raw, 'g_mode', $modes, $d['g_mode'] );
		$s['g_position']       = self::pick( $raw, 'g_position', array( 'before_customer', 'after_notes', 'before_submit' ), $d['g_position'] );
		$s['g_fee']            = self::money( $raw, 'g_fee', $d['g_fee'] );
		$s['g_fee']            = '' === $s['g_fee'] ? '0' : $s['g_fee'];
		$s['g_tax']            = self::pick( $raw, 'g_tax', array( 'none', 'taxable' ), $d['g_tax'] );
		$s['g_tax_class']      = isset( $raw['g_tax_class'] ) && is_string( $raw['g_tax_class'] ) ? substr( sanitize_title( $raw['g_tax_class'] ), 0, 200 ) : '';
		$s['g_msg']            = self::pick( $raw, 'g_msg', array( 'off', 'optional', 'required' ), $d['g_msg'] );
		$s['g_max']            = self::int( $raw, 'g_max', 20, 1000, $d['g_max'] );
		$s['g_shippable_only'] = self::flag( $raw, 'g_shippable_only', $d );
		$s['g_admin_line']     = self::flag( $raw, 'g_admin_line', $d );
		$s['g_column']         = self::flag( $raw, 'g_column', $d );

		// Thank-you page.
		$s['t_mode']     = self::pick( $raw, 't_mode', $modes, $d['t_mode'] );
		$s['t_replace']  = self::flag( $raw, 't_replace', $d );
		$s['t_products'] = self::pick( $raw, 't_products', array( 'none', 'cross', 'related', 'sale' ), $d['t_products'] );
		$s['t_limit']    = self::int( $raw, 't_limit', 1, 8, $d['t_limit'] );

		// Coupon.
		$s['c_mode']                = self::pick( $raw, 'c_mode', $modes, $d['c_mode'] );
		$s['c_statuses']            = self::subset( $raw, 'c_statuses', self::STATUSES, $d['c_statuses'] );
		$s['c_min_total']           = self::money( $raw, 'c_min_total', $d['c_min_total'] );
		$s['c_skip_used']           = self::flag( $raw, 'c_skip_used', $d );
		$s['c_one_active']          = self::flag( $raw, 'c_one_active', $d );
		$s['c_days_between']        = self::int( $raw, 'c_days_between', 0, 3650, $d['c_days_between'] );
		$s['c_first_only']          = self::flag( $raw, 'c_first_only', $d );
		$s['c_skip_cats']           = self::ids( $raw, 'c_skip_cats' );
		$s['c_type']                = self::pick( $raw, 'c_type', array( 'percent', 'fixed_cart' ), $d['c_type'] );
		$s['c_amount']              = self::money( $raw, 'c_amount', $d['c_amount'] );
		if ( '' === $s['c_amount'] || (float) $s['c_amount'] <= 0 ) {
			$s['c_amount'] = $d['c_amount'];
		}
		if ( 'percent' === $s['c_type'] && (float) $s['c_amount'] > 100 ) {
			$s['c_amount'] = '100';
		}
		$s['c_free_ship']           = self::flag( $raw, 'c_free_ship', $d );
		$s['c_expiry']              = self::int( $raw, 'c_expiry', 1, 730, $d['c_expiry'] );
		$s['c_min_spend']           = self::money( $raw, 'c_min_spend', $d['c_min_spend'] );
		$s['c_max_spend']           = self::money( $raw, 'c_max_spend', $d['c_max_spend'] );
		$s['c_individual']          = self::flag( $raw, 'c_individual', $d );
		$s['c_exclude_sale']        = self::flag( $raw, 'c_exclude_sale', $d );
		$s['c_products']            = self::ids( $raw, 'c_products' );
		$s['c_ex_products']         = self::ids( $raw, 'c_ex_products' );
		$s['c_cats']                = self::ids( $raw, 'c_cats' );
		$s['c_ex_cats']             = self::ids( $raw, 'c_ex_cats' );
		$s['c_limit_items']         = self::int( $raw, 'c_limit_items', 0, 1000, $d['c_limit_items'] );
		$s['c_email_restrict']      = self::flag( $raw, 'c_email_restrict', $d );
		$prefix                     = isset( $raw['c_prefix'] ) && is_string( $raw['c_prefix'] ) ? strtolower( trim( $raw['c_prefix'] ) ) : $d['c_prefix'];
		$s['c_prefix']              = 1 === preg_match( '/^[a-z0-9-]{0,10}$/', $prefix ) ? $prefix : $d['c_prefix'];
		$s['c_email']               = self::pick( $raw, 'c_email', array( 'order', 'separate', 'none' ), $d['c_email'] );
		$s['c_email_ids']           = self::subset( $raw, 'c_email_ids', self::EMAIL_IDS, $d['c_email_ids'] );
		$s['c_remind']              = self::flag( $raw, 'c_remind', $d );
		$s['c_remind_days']         = self::int( $raw, 'c_remind_days', 1, 60, $d['c_remind_days'] );
		$s['c_cleanup']             = self::flag( $raw, 'c_cleanup', $d );
		$s['c_cleanup_days']        = self::int( $raw, 'c_cleanup_days', 0, 365, $d['c_cleanup_days'] );
		$s['c_delete_on_uninstall'] = self::flag( $raw, 'c_delete_on_uninstall', $d );

		// Visitor texts.
		$def = self::defaults();
		foreach ( self::TEXTS as $key => $spec ) {
			$s[ $key ] = $d[ $key ];
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				foreach ( array( 'el', 'en' ) as $l ) {
					$v = isset( $raw[ $key ][ $l ] ) && is_string( $raw[ $key ][ $l ] ) ? $raw[ $key ][ $l ] : '';
					$v = $spec[1] ? self::lines( $v, $spec[0] ) : self::line( $v, $spec[0] );
					// Empty Greek = the default (unless the text may be empty); empty English = shows the Greek.
					if ( 'el' === $l && '' === $v && ! $spec[2] ) {
						$s[ $key ]['el'] = $def[ $key ]['el'];
						continue;
					}
					$s[ $key ][ $l ] = $v;
				}
			}
		}

		return $s;
	}

	/* ---------------------------------------------------------------------
	 * Validators
	 * ------------------------------------------------------------------- */

	/** One of the allowed string values, else the default. */
	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/** A boolean (missing = the default). */
	private static function flag( array $raw, string $key, array $d ): bool {
		return isset( $raw[ $key ] ) ? (bool) $raw[ $key ] : (bool) $d[ $key ];
	}

	/** An integer within [min, max], else the default. */
	private static function int( array $raw, string $key, int $min, int $max, int $default ): int {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) ) {
			return $default;
		}
		// Out of range = the nearest limit (the field's min / max).
		return max( $min, min( $max, (int) $raw[ $key ] ) );
	}

	/**
	 * A non-negative decimal as a string with a dot ('12.50'), '' when empty;
	 * a comma is accepted as the decimal mark. Invalid input = $default.
	 */
	private static function money( array $raw, string $key, string $default ): string {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
			return $default;
		}
		$v = str_replace( ',', '.', trim( (string) $raw[ $key ] ) );
		if ( '' === $v ) {
			return '';
		}
		if ( 1 !== preg_match( '/^\d{1,9}(\.\d{1,4})?$/', $v ) ) {
			return $default;
		}
		$v = rtrim( rtrim( number_format( (float) $v, 4, '.', '' ), '0' ), '.' );
		return '' === $v ? '0' : $v;
	}

	/** The allowed values that were given (in the allowed order), else the default. */
	private static function subset( array $raw, string $key, array $allowed, array $default ): array {
		if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
			return $default;
		}
		$given = array_map( 'strval', array_filter( $raw[ $key ], 'is_scalar' ) );
		return array_values( array_intersect( $allowed, $given ) );
	}

	/** Positive unique integer ids (at most MAX_IDS), from an array or a comma list. */
	private static function ids( array $raw, string $key ): array {
		$v = isset( $raw[ $key ] ) ? $raw[ $key ] : array();
		if ( is_string( $v ) ) {
			$v = preg_split( '/[\s,]+/', $v );
		}
		$out = array();
		foreach ( (array) $v as $id ) {
			$id = is_scalar( $id ) ? absint( $id ) : 0;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
			if ( count( $out ) >= self::MAX_IDS ) {
				break;
			}
		}
		return $out;
	}

	/** One line of plain text, at most $len characters. */
	public static function line( string $v, int $len ): string {
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $len );
	}

	/** Plain text that keeps line breaks (at most 10 lines, empty lines dropped), at most $len characters. */
	public static function lines( string $v, int $len ): string {
		$rows = array();
		foreach ( preg_split( '/\R/u', $v ) as $row ) {
			$row = trim( sanitize_text_field( $row ) );
			if ( '' !== $row ) {
				$rows[] = $row;
			}
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}
		return mb_substr( implode( "\n", $rows ), 0, $len );
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/**
	 * True when the current visitor sees a part (mode + test mode).
	 *
	 * @param string $part 'g' | 't' | 'c'.
	 */
	public static function visible( string $part ): bool {
		if ( Checkout_Extras::killed() ) {
			return false;
		}
		$mode = self::get()[ $part . '_mode' ];
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( 'manage_woocommerce' );
	}

	/** The gift fee as a float (0 = no fee). */
	public static function fee(): float {
		return max( 0.0, (float) self::get()['g_fee'] );
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
		 * Filters a visitor text of Checkout Extras (e.g. for WPML).
		 *
		 * @param string $text Text (placeholders not yet filled).
		 * @param string $key  Text key (a key of CKE_Settings::TEXTS).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'cke_text', $text, $key, $lang );
	}

	/**
	 * Fills {placeholders} (Bible §14.17): a sentence (up to its full stop)
	 * with a placeholder that has no value is dropped whole; unknown
	 * placeholders are removed. Values are plain text, the result is plain
	 * text too (escape on output).
	 *
	 * @param string $text Text with placeholders.
	 * @param array  $vars name => value.
	 */
	public static function fill( string $text, array $vars ): string {
		$out = array();
		foreach ( preg_split( '/\R/u', $text ) as $row ) {
			$parts = preg_split( '/(?<=[.!;·])\s+/u', $row );
			$keep  = array();
			foreach ( $parts as $sentence ) {
				$empty = false;
				$done  = preg_replace_callback(
					'/\{([a-z_]+)\}/',
					static function ( $m ) use ( $vars, &$empty ) {
						if ( ! array_key_exists( $m[1], $vars ) ) {
							return '';
						}
						$v = (string) $vars[ $m[1] ];
						if ( '' === $v ) {
							$empty = true;
						}
						return $v;
					},
					$sentence
				);
				if ( ! $empty && '' !== trim( (string) $done ) ) {
					$keep[] = trim( (string) $done );
				}
			}
			if ( $keep ) {
				$out[] = implode( ' ', $keep );
			}
		}
		return implode( "\n", $out );
	}
}
