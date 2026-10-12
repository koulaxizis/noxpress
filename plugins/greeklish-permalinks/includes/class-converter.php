<?php
/**
 * GLP_Converter — Greek → Latin transliteration (no WordPress state).
 *
 * The text is split into tokens: every Greek letter becomes its base letter
 * (lower case) plus three flags: upper case, accent (tonos, varia, oxia,
 * perispomeni) and diaeresis. Precomposed monotonic and polytonic letters
 * come from the table below; combining marks (U+0300–U+036F) that follow
 * a Greek letter are folded into its flags; spacing accents are dropped.
 * Anything that is not Greek passes through untouched.
 *
 * Maximal runs of Greek letters are words: custom rules (whole words,
 * case and accent insensitive) and the optional stop words work on them.
 *
 * Modes:
 *  - simple    every letter on its own (ου → oy, μπ → mp): the output of
 *              AutoConvert Greeklish Permalinks with diphthongs off.
 *  - phonetic  αυ/ευ/ηυ → av/ef…, ου → ou, μπ/ντ/γκ at the start of a
 *              word → b/d/g, γγ → ng, γξ → nx, γχ → nch. A diaeresis on υ,
 *              or an accent on the first vowel only (άυλος), keeps the two
 *              letters apart.
 */

defined( 'ABSPATH' ) || exit;

final class GLP_Converter {

	/** Any Greek or Coptic / Greek Extended code point. */
	const GREEK_RE = '/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u';

	const F_UPPER = 1;
	const F_ACC   = 2;
	const F_DIA   = 4;

	/** Base letters (lower case) → Latin, the same in both modes. */
	private static $letters = array(
		'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i', 'θ' => 'th',
		'ι' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'π' => 'p',
		'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps',
		'ω' => 'o',
	);

	/** Upper case base letters → lower case. */
	private static $lower = array(
		'Α' => 'α', 'Β' => 'β', 'Γ' => 'γ', 'Δ' => 'δ', 'Ε' => 'ε', 'Ζ' => 'ζ', 'Η' => 'η', 'Θ' => 'θ',
		'Ι' => 'ι', 'Κ' => 'κ', 'Λ' => 'λ', 'Μ' => 'μ', 'Ν' => 'ν', 'Ξ' => 'ξ', 'Ο' => 'ο', 'Π' => 'π',
		'Ρ' => 'ρ', 'Σ' => 'σ', 'Τ' => 'τ', 'Υ' => 'υ', 'Φ' => 'φ', 'Χ' => 'χ', 'Ψ' => 'ψ', 'Ω' => 'ω',
	);

	/**
	 * Precomposed letters: char => base letter + flag digit (1 accent,
	 * 2 diaeresis, 3 both). Generated from Unicode's canonical
	 * decompositions (Greek and Coptic, Greek Extended), plus the symbol
	 * variants (ϐ ϑ ϕ ϖ ϰ ϱ ϲ ϵ ϒ ϓ ϔ ϴ).
	 */
	private static $table = array(
		'Ά' => 'Α1', 'Έ' => 'Ε1', 'Ή' => 'Η1', 'Ί' => 'Ι1', 'Ό' => 'Ο1', 'Ύ' => 'Υ1', 'Ώ' => 'Ω1', 'ΐ' => 'ι3',
		'Ϊ' => 'Ι2', 'Ϋ' => 'Υ2', 'ά' => 'α1', 'έ' => 'ε1', 'ή' => 'η1', 'ί' => 'ι1', 'ΰ' => 'υ3', 'ϊ' => 'ι2',
		'ϋ' => 'υ2', 'ό' => 'ο1', 'ύ' => 'υ1', 'ώ' => 'ω1', 'ἀ' => 'α0', 'ἁ' => 'α0', 'ἂ' => 'α1', 'ἃ' => 'α1',
		'ἄ' => 'α1', 'ἅ' => 'α1', 'ἆ' => 'α1', 'ἇ' => 'α1', 'Ἀ' => 'Α0', 'Ἁ' => 'Α0', 'Ἂ' => 'Α1', 'Ἃ' => 'Α1',
		'Ἄ' => 'Α1', 'Ἅ' => 'Α1', 'Ἆ' => 'Α1', 'Ἇ' => 'Α1', 'ἐ' => 'ε0', 'ἑ' => 'ε0', 'ἒ' => 'ε1', 'ἓ' => 'ε1',
		'ἔ' => 'ε1', 'ἕ' => 'ε1', 'Ἐ' => 'Ε0', 'Ἑ' => 'Ε0', 'Ἒ' => 'Ε1', 'Ἓ' => 'Ε1', 'Ἔ' => 'Ε1', 'Ἕ' => 'Ε1',
		'ἠ' => 'η0', 'ἡ' => 'η0', 'ἢ' => 'η1', 'ἣ' => 'η1', 'ἤ' => 'η1', 'ἥ' => 'η1', 'ἦ' => 'η1', 'ἧ' => 'η1',
		'Ἠ' => 'Η0', 'Ἡ' => 'Η0', 'Ἢ' => 'Η1', 'Ἣ' => 'Η1', 'Ἤ' => 'Η1', 'Ἥ' => 'Η1', 'Ἦ' => 'Η1', 'Ἧ' => 'Η1',
		'ἰ' => 'ι0', 'ἱ' => 'ι0', 'ἲ' => 'ι1', 'ἳ' => 'ι1', 'ἴ' => 'ι1', 'ἵ' => 'ι1', 'ἶ' => 'ι1', 'ἷ' => 'ι1',
		'Ἰ' => 'Ι0', 'Ἱ' => 'Ι0', 'Ἲ' => 'Ι1', 'Ἳ' => 'Ι1', 'Ἴ' => 'Ι1', 'Ἵ' => 'Ι1', 'Ἶ' => 'Ι1', 'Ἷ' => 'Ι1',
		'ὀ' => 'ο0', 'ὁ' => 'ο0', 'ὂ' => 'ο1', 'ὃ' => 'ο1', 'ὄ' => 'ο1', 'ὅ' => 'ο1', 'Ὀ' => 'Ο0', 'Ὁ' => 'Ο0',
		'Ὂ' => 'Ο1', 'Ὃ' => 'Ο1', 'Ὄ' => 'Ο1', 'Ὅ' => 'Ο1', 'ὐ' => 'υ0', 'ὑ' => 'υ0', 'ὒ' => 'υ1', 'ὓ' => 'υ1',
		'ὔ' => 'υ1', 'ὕ' => 'υ1', 'ὖ' => 'υ1', 'ὗ' => 'υ1', 'Ὑ' => 'Υ0', 'Ὓ' => 'Υ1', 'Ὕ' => 'Υ1', 'Ὗ' => 'Υ1',
		'ὠ' => 'ω0', 'ὡ' => 'ω0', 'ὢ' => 'ω1', 'ὣ' => 'ω1', 'ὤ' => 'ω1', 'ὥ' => 'ω1', 'ὦ' => 'ω1', 'ὧ' => 'ω1',
		'Ὠ' => 'Ω0', 'Ὡ' => 'Ω0', 'Ὢ' => 'Ω1', 'Ὣ' => 'Ω1', 'Ὤ' => 'Ω1', 'Ὥ' => 'Ω1', 'Ὦ' => 'Ω1', 'Ὧ' => 'Ω1',
		'ὰ' => 'α1', 'ά' => 'α1', 'ὲ' => 'ε1', 'έ' => 'ε1', 'ὴ' => 'η1', 'ή' => 'η1', 'ὶ' => 'ι1', 'ί' => 'ι1',
		'ὸ' => 'ο1', 'ό' => 'ο1', 'ὺ' => 'υ1', 'ύ' => 'υ1', 'ὼ' => 'ω1', 'ώ' => 'ω1', 'ᾀ' => 'α0', 'ᾁ' => 'α0',
		'ᾂ' => 'α1', 'ᾃ' => 'α1', 'ᾄ' => 'α1', 'ᾅ' => 'α1', 'ᾆ' => 'α1', 'ᾇ' => 'α1', 'ᾈ' => 'Α0', 'ᾉ' => 'Α0',
		'ᾊ' => 'Α1', 'ᾋ' => 'Α1', 'ᾌ' => 'Α1', 'ᾍ' => 'Α1', 'ᾎ' => 'Α1', 'ᾏ' => 'Α1', 'ᾐ' => 'η0', 'ᾑ' => 'η0',
		'ᾒ' => 'η1', 'ᾓ' => 'η1', 'ᾔ' => 'η1', 'ᾕ' => 'η1', 'ᾖ' => 'η1', 'ᾗ' => 'η1', 'ᾘ' => 'Η0', 'ᾙ' => 'Η0',
		'ᾚ' => 'Η1', 'ᾛ' => 'Η1', 'ᾜ' => 'Η1', 'ᾝ' => 'Η1', 'ᾞ' => 'Η1', 'ᾟ' => 'Η1', 'ᾠ' => 'ω0', 'ᾡ' => 'ω0',
		'ᾢ' => 'ω1', 'ᾣ' => 'ω1', 'ᾤ' => 'ω1', 'ᾥ' => 'ω1', 'ᾦ' => 'ω1', 'ᾧ' => 'ω1', 'ᾨ' => 'Ω0', 'ᾩ' => 'Ω0',
		'ᾪ' => 'Ω1', 'ᾫ' => 'Ω1', 'ᾬ' => 'Ω1', 'ᾭ' => 'Ω1', 'ᾮ' => 'Ω1', 'ᾯ' => 'Ω1', 'ᾰ' => 'α0', 'ᾱ' => 'α0',
		'ᾲ' => 'α1', 'ᾳ' => 'α0', 'ᾴ' => 'α1', 'ᾶ' => 'α1', 'ᾷ' => 'α1', 'Ᾰ' => 'Α0', 'Ᾱ' => 'Α0', 'Ὰ' => 'Α1',
		'Ά' => 'Α1', 'ᾼ' => 'Α0', 'ῂ' => 'η1', 'ῃ' => 'η0', 'ῄ' => 'η1', 'ῆ' => 'η1', 'ῇ' => 'η1', 'Ὲ' => 'Ε1',
		'Έ' => 'Ε1', 'Ὴ' => 'Η1', 'Ή' => 'Η1', 'ῌ' => 'Η0', 'ῐ' => 'ι0', 'ῑ' => 'ι0', 'ῒ' => 'ι3', 'ΐ' => 'ι3',
		'ῖ' => 'ι1', 'ῗ' => 'ι3', 'Ῐ' => 'Ι0', 'Ῑ' => 'Ι0', 'Ὶ' => 'Ι1', 'Ί' => 'Ι1', 'ῠ' => 'υ0', 'ῡ' => 'υ0',
		'ῢ' => 'υ3', 'ΰ' => 'υ3', 'ῤ' => 'ρ0', 'ῥ' => 'ρ0', 'ῦ' => 'υ1', 'ῧ' => 'υ3', 'Ῠ' => 'Υ0', 'Ῡ' => 'Υ0',
		'Ὺ' => 'Υ1', 'Ύ' => 'Υ1', 'Ῥ' => 'Ρ0', 'ῲ' => 'ω1', 'ῳ' => 'ω0', 'ῴ' => 'ω1', 'ῶ' => 'ω1', 'ῷ' => 'ω1',
		'Ὸ' => 'Ο1', 'Ό' => 'Ο1', 'Ὼ' => 'Ω1', 'Ώ' => 'Ω1', 'ῼ' => 'Ω0', 'ϐ' => 'β0', 'ϑ' => 'θ0', 'ϕ' => 'φ0',
		'ϖ' => 'π0', 'ϰ' => 'κ0', 'ϱ' => 'ρ0', 'ϲ' => 'σ0', 'Ϲ' => 'Σ0', 'ϒ' => 'Υ0', 'ϓ' => 'Υ1', 'ϔ' => 'Υ2',
		'ϵ' => 'ε0', 'ϴ' => 'Θ0',	);

	/** Unvoiced consonants: αυ/ευ/ηυ before them (or at the end) → f. */
	private static $unvoiced = array( 'θ', 'κ', 'ξ', 'π', 'σ', 'ς', 'τ', 'φ', 'χ', 'ψ' );

	/** Stop words (lower case, no accents): articles, «και», short prepositions. */
	private static $stop = array(
		'ο', 'η', 'το', 'οι', 'τα', 'του', 'της', 'των', 'τον', 'την', 'τη', 'τους', 'τις',
		'ενα', 'ενας', 'μια', 'μιας', 'ενος', 'και', 'κι', 'σε', 'στο', 'στη', 'στην', 'στον',
		'στα', 'στους', 'στις', 'στης', 'στου', 'στων', 'για', 'με', 'απο', 'που', 'ως',
	);

	/** True when the text has at least one Greek character. */
	public static function has_greek( string $text ): bool {
		return '' !== $text && 1 === preg_match( self::GREEK_RE, $text );
	}

	/**
	 * Transliterate.
	 *
	 * @param string $text Any text.
	 * @param array  $opt  mode 'simple'|'phonetic', rules (key => latin, keys
	 *                     from key()), stop (bool, drop stop words when
	 *                     another word is left), lower (bool).
	 */
	public static function convert( string $text, array $opt = array() ): string {
		if ( ! self::has_greek( $text ) ) {
			return $text;
		}
		$phonetic = isset( $opt['mode'] ) && 'phonetic' === $opt['mode'];
		$rules    = isset( $opt['rules'] ) && is_array( $opt['rules'] ) ? $opt['rules'] : array();
		$stop     = ! empty( $opt['stop'] );

		// Segments: strings (not Greek) and arrays of letter tokens (Greek words).
		$segments = self::tokenize( $text );

		$drop = array();
		if ( $stop ) {
			$kept = false;
			foreach ( $segments as $i => $seg ) {
				if ( is_array( $seg ) && in_array( self::word_key( $seg ), self::$stop, true ) ) {
					$drop[ $i ] = true;
				} elseif ( is_array( $seg ) || preg_match( '/[\p{L}\p{N}]/u', $seg ) ) {
					$kept = true;
				}
			}
			if ( ! $kept ) {
				$drop = array();
			}
		}

		$out = '';
		foreach ( $segments as $i => $seg ) {
			if ( ! is_array( $seg ) ) {
				$out .= $seg;
				continue;
			}
			if ( isset( $drop[ $i ] ) ) {
				$out .= ' ';
				continue;
			}
			$key = self::word_key( $seg );
			if ( isset( $rules[ $key ] ) && '' !== $rules[ $key ] ) {
				$out .= (string) $rules[ $key ];
				continue;
			}
			$out .= self::word( $seg, $phonetic );
		}
		if ( ! empty( $opt['lower'] ) ) {
			$out = strtolower( $out );
		}
		return $out;
	}

	/**
	 * Lookup key of a Greek word as typed by a person: lower case, no
	 * accents, no diaeresis, final sigma as σ. '' when the text is not one
	 * Greek word.
	 */
	public static function key( string $word ): string {
		$segments = self::tokenize( trim( $word ) );
		if ( 1 !== count( $segments ) || ! is_array( $segments[0] ) ) {
			return '';
		}
		return self::word_key( $segments[0] );
	}

	/** @param array $tokens Letter tokens of one word. */
	private static function word_key( array $tokens ): string {
		$k = '';
		foreach ( $tokens as $t ) {
			$k .= 'ς' === $t[0] ? 'σ' : $t[0];
		}
		return $k;
	}

	/**
	 * Split into segments. A Greek word is an array of tokens
	 * array( base (lower case), flags ).
	 */
	private static function tokenize( string $text ): array {
		$chars    = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$segments = array();
		$word     = array();
		$plain    = '';
		if ( ! is_array( $chars ) ) {
			return array( $text );
		}
		foreach ( $chars as $c ) {
			$tok = null;
			if ( isset( self::$letters[ $c ] ) ) {
				$tok = array( $c, 0 );
			} elseif ( isset( self::$lower[ $c ] ) ) {
				$tok = array( self::$lower[ $c ], self::F_UPPER );
			} elseif ( isset( self::$table[ $c ] ) ) {
				$base  = mb_substr( self::$table[ $c ], 0, 1, 'UTF-8' );
				$digit = (int) substr( self::$table[ $c ], -1 );
				$flags = ( $digit & 1 ? self::F_ACC : 0 ) | ( $digit & 2 ? self::F_DIA : 0 );
				if ( isset( self::$lower[ $base ] ) ) {
					$base   = self::$lower[ $base ];
					$flags |= self::F_UPPER;
				}
				$tok = array( $base, $flags );
			}

			if ( null !== $tok ) {
				if ( '' !== $plain ) {
					$segments[] = $plain;
					$plain      = '';
				}
				$word[] = $tok;
				continue;
			}

			$cp = self::ord( $c );
			// Combining mark after a Greek letter: fold into its flags.
			if ( $word && $cp >= 0x0300 && $cp <= 0x036F ) {
				$last = count( $word ) - 1;
				if ( 0x0308 === $cp ) {
					$word[ $last ][1] |= self::F_DIA;
				} elseif ( in_array( $cp, array( 0x0300, 0x0301, 0x0340, 0x0341, 0x0342, 0x0344 ), true ) ) {
					$word[ $last ][1] |= self::F_ACC;
					if ( 0x0344 === $cp ) {
						$word[ $last ][1] |= self::F_DIA;
					}
				}
				continue;
			}
			// Spacing accents and breathings, and any other Greek sign: dropped.
			if ( ( $cp >= 0x0370 && $cp <= 0x03FF ) || ( $cp >= 0x1F00 && $cp <= 0x1FFF ) ) {
				if ( 0x037E === $cp ) { // Greek question mark.
					$c = ';';
				} elseif ( 0x0387 === $cp ) { // Ano teleia.
					$c = '·';
				} else {
					continue;
				}
			}

			if ( $word ) {
				$segments[] = $word;
				$word       = array();
			}
			$plain .= $c;
		}
		if ( $word ) {
			$segments[] = $word;
		}
		if ( '' !== $plain ) {
			$segments[] = $plain;
		}
		return $segments;
	}

	/** Code point of one UTF-8 character. */
	private static function ord( string $c ): int {
		if ( function_exists( 'mb_ord' ) ) {
			return (int) mb_ord( $c, 'UTF-8' );
		}
		$u = unpack( 'N', (string) mb_convert_encoding( $c, 'UCS-4BE', 'UTF-8' ) );
		return is_array( $u ) ? (int) $u[1] : 0;
	}

	/** Transliterate one Greek word (array of tokens). */
	private static function word( array $t, bool $phonetic ): string {
		$n     = count( $t );
		$upper = 0;
		foreach ( $t as $tok ) {
			if ( $tok[1] & self::F_UPPER ) {
				$upper++;
			}
		}
		$all_caps = $n > 1 && $upper === $n;

		$out = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$l    = $t[ $i ][0];
			$f    = $t[ $i ][1];
			$next = $i + 1 < $n ? $t[ $i + 1 ] : null;
			$lat  = null;
			$used = 1;

			if ( $phonetic && null !== $next ) {
				$nl = $next[0];
				$nf = $next[1];
				// Vowel + υ: a diaeresis on υ, or an accent on the first vowel
				// only, keeps them apart (προϋπόθεση, άυλος).
				$pair = 'υ' === $nl && ! ( $nf & self::F_DIA ) && ! ( ( $f & self::F_ACC ) && ! ( $nf & self::F_ACC ) );
				if ( $pair && in_array( $l, array( 'α', 'ε', 'η' ), true ) ) {
					$after = $i + 2 < $n ? $t[ $i + 2 ][0] : '';
					$v     = '' === $after || in_array( $after, self::$unvoiced, true ) ? 'f' : 'v';
					$lat   = self::$letters[ $l ] . $v;
					$used  = 2;
				} elseif ( $pair && 'ο' === $l ) {
					$lat  = 'ou';
					$used = 2;
				} elseif ( 'μ' === $l && 'π' === $nl ) {
					$lat  = 0 === $i ? 'b' : 'mp';
					$used = 2;
				} elseif ( 'ν' === $l && 'τ' === $nl ) {
					$lat  = 0 === $i ? 'd' : 'nt';
					$used = 2;
				} elseif ( 'γ' === $l && 'κ' === $nl ) {
					$lat  = 0 === $i ? 'g' : 'gk';
					$used = 2;
				} elseif ( 'γ' === $l && 'γ' === $nl ) {
					$lat  = 'ng';
					$used = 2;
				} elseif ( 'γ' === $l && 'ξ' === $nl ) {
					$lat  = 'nx';
					$used = 2;
				} elseif ( 'γ' === $l && 'χ' === $nl ) {
					$lat  = 'nch';
					$used = 2;
				}
			}
			if ( null === $lat ) {
				$lat = self::$letters[ $l ];
			}

			if ( $all_caps ) {
				$lat = strtoupper( $lat );
			} elseif ( $f & self::F_UPPER ) {
				$lat = ucfirst( $lat );
			}
			$out .= $lat;
			$i   += $used - 1;
		}
		return $out;
	}

	/**
	 * A file name with Greek letters in Latin: the name in the given case,
	 * the extension untouched. Spaces become '-', repeated '-' collapse.
	 */
	public static function file_name( string $name, array $opt = array() ): string {
		if ( ! self::has_greek( $name ) ) {
			return $name;
		}
		$dot  = strrpos( $name, '.' );
		$ext  = false !== $dot && $dot > 0 ? substr( $name, $dot ) : '';
		$stem = '' !== $ext ? substr( $name, 0, $dot ) : $name;

		$opt['stop'] = false;
		$stem        = self::convert( $stem, $opt );
		$stem        = preg_replace( '/[\s]+/u', '-', $stem );
		$stem        = preg_replace( '/-{2,}/', '-', (string) $stem );
		$stem        = trim( (string) $stem, '-' );
		if ( '' === $stem ) {
			$stem = 'file';
		}
		return $stem . $ext;
	}
}
