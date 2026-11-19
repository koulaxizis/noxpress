<?php
/**
 * VLG_Settings — the vlg_settings option, strict validation.
 *
 *  on        bool    the English version answers under /en/ (default off, §14.1)
 *  locale    string  en_US | en_GB (WordPress and WooCommerce speak it natively)
 *  switch    string  none | float | products (float only on the product pages
 *                    that have English texts; the shortcode [vlg_switch] always works)
 *  abroad    bool    also show the switch on every page to visitors outside Greece
 *                    (country from the site's own Cloudflare, /cdn-cgi/trace; default off)
 *  note      bool    "This page is only in Greek" on pages without English texts
 *  hreflang  bool    <link rel="alternate" hreflang> on pages with English texts
 *  menus     array   theme menu location => menu id shown in English (0 = same)
 *  phrases   array   Greek => English for short texts (attribute names and
 *                    values, categories, menu items, shipping and payment
 *                    labels), at most 300 lines
 *
 * The prefix is always "en": one fixed rule, so no setting can make a URL
 * of the Greek site answer in English or the other way round.
 */

defined( 'ABSPATH' ) || exit;

final class VLG_Settings {

	const OPTION      = 'vlg_settings';
	const PREFIX      = 'en';
	const MAX_PHRASES = 300;

	/** Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'on'       => false,
			'locale'   => 'en_US',
			'switch'   => 'float',
			'abroad'   => false,
			'note'     => true,
			'hreflang' => true,
			'menus'    => array(),
			'phrases'  => array(),
		);
	}

	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw         = get_option( self::OPTION, array() );
		self::$cache = self::clean( is_array( $raw ) ? $raw : array() );
		return self::$cache;
	}

	public static function save( array $s ): array {
		$clean = self::clean( $s );
		update_option( self::OPTION, $clean, true );
		self::$cache = $clean;
		return $clean;
	}

	/** Validated copy of a settings array (whitelists only). */
	public static function clean( array $s ): array {
		$d   = self::defaults();
		$out = array(
			'on'       => ! empty( $s['on'] ),
			'locale'   => isset( $s['locale'] ) && in_array( $s['locale'], array( 'en_US', 'en_GB' ), true ) ? (string) $s['locale'] : $d['locale'],
			'switch'   => isset( $s['switch'] ) && in_array( $s['switch'], array( 'none', 'float', 'products' ), true ) ? (string) $s['switch'] : $d['switch'],
			'abroad'   => ! empty( $s['abroad'] ),
			'note'     => isset( $s['note'] ) ? (bool) $s['note'] : $d['note'],
			'hreflang' => isset( $s['hreflang'] ) ? (bool) $s['hreflang'] : $d['hreflang'],
			'menus'    => array(),
			'phrases'  => array(),
		);
		if ( isset( $s['menus'] ) && is_array( $s['menus'] ) ) {
			foreach ( $s['menus'] as $loc => $id ) {
				$loc = sanitize_key( (string) $loc );
				$id  = absint( $id );
				if ( '' !== $loc && $id > 0 ) {
					$out['menus'][ $loc ] = $id;
				}
			}
		}
		if ( isset( $s['phrases'] ) && is_array( $s['phrases'] ) ) {
			foreach ( $s['phrases'] as $el => $en ) {
				$el = self::line( (string) $el );
				$en = self::line( (string) $en );
				if ( '' !== $el && '' !== $en && count( $out['phrases'] ) < self::MAX_PHRASES ) {
					$out['phrases'][ $el ] = $en;
				}
			}
		}
		return $out;
	}

	/** One short plain-text line (no tags, at most 200 characters). */
	private static function line( string $v ): string {
		$v = trim( wp_strip_all_tags( $v ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 200 ) : substr( $v, 0, 200 );
	}

	/**
	 * "Ελληνικά = English" lines → array. Lines without " = " are skipped
	 * and counted, so the settings page can say so.
	 *
	 * @return array{0:array,1:int} phrases, skipped lines
	 */
	public static function parse_phrases( string $text ): array {
		$out  = array();
		$skip = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $row ) {
			$row = trim( (string) $row );
			if ( '' === $row ) {
				continue;
			}
			$at = strpos( $row, ' = ' );
			if ( false === $at ) {
				++$skip;
				continue;
			}
			$out[ trim( substr( $row, 0, $at ) ) ] = trim( substr( $row, $at + 3 ) );
		}
		return array( $out, $skip );
	}

	/** Array → "Ελληνικά = English" lines, for the textarea. */
	public static function phrases_text( array $phrases ): string {
		$rows = array();
		foreach ( $phrases as $el => $en ) {
			$rows[] = $el . ' = ' . $en;
		}
		return implode( "\n", $rows );
	}
}
