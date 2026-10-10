<?php
/**
 * EWD_Settings — the ewd_settings option (autoload: read on every front
 * request that needs it), strict validation with whitelists (Bible §11).
 *
 * Keys:
 *  - mode            'test' (shop managers only, default) | 'live' | 'off'
 *  - page_id         page holding [nox_withdrawal] (0 = none yet)
 *  - days            withdrawal period in days (14–365, default 14)
 *  - grace           days added for delivery after "Completed" (0–60, default 7)
 *  - label_el/_en    text of the buttons and links
 *  - instr_el/_en    return instructions in the receipt email and page
 *  - recipients      admin notice recipients (validated emails)
 *  - excl_cats       product_cat term ids excluded from withdrawal
 *  - excl_products   product ids excluded from withdrawal
 *  - consent         consent checkbox for digital items at checkout
 *  - consent_el/_en  its text
 *  - email_links     link in customer order emails and on the thank-you page
 *  - footer_link     automatic link in wp_footer (off by default)
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Settings {

	const OPT = 'ewd_settings';

	/** Product meta of the "personalised" flag ('yes' | absent). */
	const META_PERSONAL = '_ewd_personalized';

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'          => 'test',
			'page_id'       => 0,
			'days'          => 14,
			'grace'         => 7,
			'label_el'      => 'Υπαναχώρηση από τη σύμβαση εδώ',
			'label_en'      => 'Withdraw from contract here',
			'instr_el'      => '',
			'instr_en'      => '',
			'recipients'    => array(),
			'excl_cats'     => array(),
			'excl_products' => array(),
			'consent'       => false,
			'consent_el'    => 'Ζητώ να λάβω αμέσως το ψηφιακό περιεχόμενο και γνωρίζω ότι έτσι χάνω το δικαίωμα υπαναχώρησης για αυτό.',
			'consent_en'    => 'I ask to receive the digital content immediately and I acknowledge that I thereby lose my right of withdrawal for it.',
			'email_links'   => true,
			'footer_link'   => false,
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

	/** Whitelist validation: unknown keys are dropped, bad values fall back to the defaults. */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode']    = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'test', 'live', 'off' ), true ) ? $raw['mode'] : $d['mode'];
		$s['page_id'] = isset( $raw['page_id'] ) ? max( 0, (int) $raw['page_id'] ) : 0;
		$s['days']    = isset( $raw['days'] ) ? min( 365, max( 14, (int) $raw['days'] ) ) : $d['days'];
		$s['grace']   = isset( $raw['grace'] ) ? min( 60, max( 0, (int) $raw['grace'] ) ) : $d['grace'];

		foreach ( array( 'label_el', 'label_en', 'consent_el', 'consent_en' ) as $k ) {
			$v       = isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? trim( sanitize_text_field( $raw[ $k ] ) ) : '';
			$s[ $k ] = '' !== $v ? mb_substr( $v, 0, 300 ) : $d[ $k ];
		}
		foreach ( array( 'instr_el', 'instr_en' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? mb_substr( trim( sanitize_textarea_field( $raw[ $k ] ) ), 0, 3000 ) : '';
		}

		$s['recipients'] = array();
		$list            = isset( $raw['recipients'] ) ? $raw['recipients'] : array();
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\s,;]+/', $list );
		}
		foreach ( (array) $list as $e ) {
			$e = sanitize_email( (string) $e );
			if ( is_email( $e ) && ! in_array( $e, $s['recipients'], true ) && count( $s['recipients'] ) < 10 ) {
				$s['recipients'][] = $e;
			}
		}

		$s['excl_cats']     = self::int_list( $raw['excl_cats'] ?? array() );
		$s['excl_products'] = self::int_list( $raw['excl_products'] ?? array() );

		foreach ( array( 'consent', 'email_links', 'footer_link' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		return $s;
	}

	/** Positive unique ints from an array or a comma/space separated string (max 500). */
	public static function int_list( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,;]+/', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $v ) {
			if ( is_scalar( $v ) && ctype_digit( (string) $v ) && (int) $v > 0 ) {
				$out[ (int) $v ] = true;
			}
			if ( count( $out ) >= 500 ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/** Admin notice recipients: the saved list, or the WooCommerce "From" address. */
	public static function recipients(): array {
		$s = self::get();
		if ( $s['recipients'] ) {
			return $s['recipients'];
		}
		$from = sanitize_email( (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ) );
		return is_email( $from ) ? array( $from ) : array();
	}

	/** A text setting in the given language ('label' | 'instr' | 'consent'). */
	public static function text( string $key, string $lang = '' ): string {
		$s    = self::get();
		$lang = '' !== $lang ? $lang : EWD_Lang::lang();
		$k    = $key . '_' . ( 'en' === $lang ? 'en' : 'el' );
		return isset( $s[ $k ] ) ? (string) $s[ $k ] : '';
	}

	/** URL of the withdrawal page ('' when no published page is set). */
	public static function page_url(): string {
		$id = (int) self::get()['page_id'];
		if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		$url = get_permalink( $id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Whether the current visitor sees the front-end parts (form, buttons,
	 * links, checkout consent): live for everyone, test for shop managers only.
	 */
	public static function visible(): bool {
		if ( Easy_Withdrawal::killed() ) {
			return false;
		}
		$mode = self::get()['mode'];
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( 'manage_woocommerce' );
	}
}
