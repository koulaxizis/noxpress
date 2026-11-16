<?php
/**
 * LVS_Text — how the plugin compares text. Every rule here is mirrored in
 * assets/live-search.js (functions norm, phon, codes, near): the index is
 * normalised in PHP, the visitor's query in the browser, and both must
 * meet. A change here changes the JS in the same commit.
 *
 * norm()   lower case, accents and diaeresis removed (Unicode NFD and the
 *          combining marks dropped; without intl, a table for Greek and
 *          remove_accents() for Latin), ς → σ, hyphens between digits
 *          removed (978-960-… is one code), every other non letter / digit
 *          a single space.
 * phon()   the greeklish / phonetic key of a normalised text, word by word:
 *          Greek letters to Latin (ου → ou, ει / οι / υι / η / ι / υ → i,
 *          αι → e, μπ → b, ντ → d, γκ / γγ → g …), then the habits of
 *          greeklish writers (th / 8 → θ, ch / kh / x → χ, h → η, w → ω,
 *          y / u → υ, ks → ξ, mp → b, nt → d), then double letters once.
 *          «η ειρήνη των κυμάτων» and «h eirini twn kumatwn» both give
 *          «i irini ton kimaton»; «Maas» and «mass» give «mas».
 * codes()  ISBN / GTIN / SKU as compared: lower case, letters and digits
 *          only, and the other ISBN form (10 ↔ 13, prefix 978).
 * near()   Damerau distance ≤ 1 (one letter wrong, missing, extra or two
 *          letters swapped), for words of 4+ letters.
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Text {

	/** Greek accents, when the intl extension (Normalizer) is missing. */
	const GREEK = array(
		'ά' => 'α',
		'έ' => 'ε',
		'ή' => 'η',
		'ί' => 'ι',
		'ό' => 'ο',
		'ύ' => 'υ',
		'ώ' => 'ω',
		'ϊ' => 'ι',
		'ϋ' => 'υ',
		'ΐ' => 'ι',
		'ΰ' => 'υ',
	);

	/** Greek to Latin; strtr() tries the longest key first. */
	const G2L = array(
		'ου' => 'ou',
		'ει' => 'i',
		'οι' => 'i',
		'υι' => 'i',
		'αι' => 'e',
		'ευ' => 'ev',
		'αυ' => 'av',
		'μπ' => 'b',
		'ντ' => 'd',
		'γκ' => 'g',
		'γγ' => 'g',
		'γχ' => 'nx',
		'τσ' => 'ts',
		'τζ' => 'tz',
		'α'  => 'a',
		'β'  => 'v',
		'γ'  => 'g',
		'δ'  => 'd',
		'ε'  => 'e',
		'ζ'  => 'z',
		'η'  => 'i',
		'θ'  => 'th',
		'ι'  => 'i',
		'κ'  => 'k',
		'λ'  => 'l',
		'μ'  => 'm',
		'ν'  => 'n',
		'ξ'  => 'ks',
		'ο'  => 'o',
		'π'  => 'p',
		'ρ'  => 'r',
		'σ'  => 's',
		'τ'  => 't',
		'υ'  => 'i',
		'φ'  => 'f',
		'χ'  => 'x',
		'ψ'  => 'ps',
		'ω'  => 'o',
	);

	/**
	 * Latin steps, applied in this order (each pair to the result of the
	 * previous one). Upper case letters are placeholders: the text is lower
	 * case, so they never clash with a real letter.
	 */
	const STEPS = array(
		array( 'th', 'T' ),
		array( 'ch', 'x' ),
		array( 'kh', 'x' ),
		array( 'ph', 'f' ),
		array( '8', 'T' ),
		// h rules (in phon()).
		array( 'ou', 'U' ),
		array( 'ks', 'x' ),
		array( 'mp', 'b' ),
		array( 'nt', 'd' ),
		array( 'gk', 'g' ),
		array( 'gg', 'g' ),
		array( 'eu', 'ev' ),
		array( 'au', 'av' ),
		array( 'ei', 'i' ),
		array( 'oi', 'i' ),
		array( 'ai', 'e' ),
		array( 'u', 'i' ),
		array( 'y', 'i' ),
		array( 'w', 'o' ),
		array( 'b', 'v' ),
		array( 'j', 'tz' ),
		array( 'c', 'k' ),
		array( 'q', 'k' ),
		array( 'U', 'u' ),
		array( 'T', 'th' ),
	);

	/** Index of the first step after the h rules (the 5 above it run first). */
	const H_AT = 5;

	public static function norm( string $s ): string {
		if ( '' === $s ) {
			return '';
		}
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		if ( class_exists( 'Normalizer' ) ) {
			$d = \Normalizer::normalize( $s, \Normalizer::FORM_D );
			if ( is_string( $d ) ) {
				$s = (string) preg_replace( '/\p{Mn}+/u', '', $d );
			}
		} else {
			$s = strtr( $s, self::GREEK );
			if ( function_exists( 'remove_accents' ) ) {
				$s = remove_accents( $s );
			}
		}
		$s = str_replace( 'ς', 'σ', $s );
		$s = (string) preg_replace( '/(\d)[\x{2010}\x{2011}\x{2013}\-](?=[\dx])/u', '$1', $s );
		$s = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
		return trim( $s );
	}

	/** Phonetic key of a normalised text (see the class docblock). */
	public static function phon( string $n ): string {
		if ( '' === $n ) {
			return '';
		}
		$out = array();
		foreach ( explode( ' ', $n ) as $w ) {
			$out[] = self::phon_word( $w );
		}
		return implode( ' ', $out );
	}

	public static function phon_word( string $w ): string {
		if ( '' === $w || ! preg_match( '/\p{L}/u', $w ) ) {
			return $w;
		}
		$w = strtr( $w, self::G2L );
		$n = count( self::STEPS );
		for ( $i = 0; $i < self::H_AT; $i++ ) {
			$w = str_replace( self::STEPS[ $i ][0], self::STEPS[ $i ][1], $w );
		}
		// h: alone it is «η»; at the end after a vowel it is silent (Sarah);
		// anywhere else greeklish writers mean «η».
		if ( 'h' === $w ) {
			$w = 'i';
		} else {
			$w = (string) preg_replace( '/([aeiouyw])h$/', '$1', $w );
			$w = str_replace( 'h', 'i', $w );
		}
		for ( $i = self::H_AT; $i < $n; $i++ ) {
			$w = str_replace( self::STEPS[ $i ][0], self::STEPS[ $i ][1], $w );
		}
		return (string) preg_replace( '/(.)\1+/u', '$1', $w );
	}

	/** Words of a normalised text (empty words dropped). */
	public static function words( string $n ): array {
		return '' === $n ? array() : array_values( array_filter( explode( ' ', $n ), 'strlen' ) );
	}

	/**
	 * A code as compared (letters and digits only, lower case), with the
	 * other ISBN form when it is an ISBN.
	 *
	 * @return string[]
	 */
	public static function codes( string $raw ): array {
		$c = strtolower( (string) preg_replace( '/[^0-9A-Za-z]+/', '', $raw ) );
		if ( '' === $c ) {
			return array();
		}
		$out = array( $c );
		if ( preg_match( '/^97[89]\d{10}$/', $c ) && '978' === substr( $c, 0, 3 ) ) {
			$out[] = self::isbn10( substr( $c, 3, 9 ) );
		} elseif ( preg_match( '/^\d{9}[\dx]$/', $c ) ) {
			$out[] = self::isbn13( '978' . substr( $c, 0, 9 ) );
		}
		return array_values( array_unique( $out ) );
	}

	/** ISBN-10 from its first 9 digits. */
	public static function isbn10( string $nine ): string {
		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $nine[ $i ] * ( 10 - $i );
		}
		$chk = ( 11 - ( $sum % 11 ) ) % 11;
		return $nine . ( 10 === $chk ? 'x' : (string) $chk );
	}

	/** ISBN-13 from its first 12 digits. */
	public static function isbn13( string $twelve ): string {
		$sum = 0;
		for ( $i = 0; $i < 12; $i++ ) {
			$sum += (int) $twelve[ $i ] * ( 0 === $i % 2 ? 1 : 3 );
		}
		return $twelve . (string) ( ( 10 - ( $sum % 10 ) ) % 10 );
	}

	/** True when the query is one ISBN (10 or 13, hyphens allowed). */
	public static function is_isbn( string $q ): bool {
		$c = strtolower( (string) preg_replace( '/[\s\-\x{2010}\x{2011}\x{2013}]+/u', '', $q ) );
		return (bool) preg_match( '/^(97[89]\d{10}|\d{9}[\dx])$/', $c );
	}

	/** Damerau distance ≤ 1 between two normalised words. */
	public static function near( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		$x = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
		$y = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $x ) || ! is_array( $y ) ) {
			return false;
		}
		$lx = count( $x );
		$ly = count( $y );
		if ( abs( $lx - $ly ) > 1 ) {
			return false;
		}
		if ( $lx === $ly ) {
			$diff = array();
			for ( $i = 0; $i < $lx; $i++ ) {
				if ( $x[ $i ] !== $y[ $i ] ) {
					$diff[] = $i;
					if ( count( $diff ) > 2 ) {
						return false;
					}
				}
			}
			if ( 1 === count( $diff ) ) {
				return true;
			}
			return 2 === count( $diff ) && $diff[1] === $diff[0] + 1
				&& $x[ $diff[0] ] === $y[ $diff[1] ] && $x[ $diff[1] ] === $y[ $diff[0] ];
		}
		if ( $lx < $ly ) {
			list( $x, $y, $lx, $ly ) = array( $y, $x, $ly, $lx );
		}
		// $x is one letter longer: skipping one letter of $x gives $y.
		$i    = 0;
		$j    = 0;
		$skip = false;
		while ( $i < $lx && $j < $ly ) {
			if ( $x[ $i ] === $y[ $j ] ) {
				$i++;
				$j++;
			} elseif ( $skip ) {
				return false;
			} else {
				$skip = true;
				$i++;
			}
		}
		return true;
	}
}
