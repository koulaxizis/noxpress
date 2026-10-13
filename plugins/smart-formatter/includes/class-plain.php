<?php
/**
 * SF_Plain — HTML → plain text (v1.4.0).
 *
 * Two modes:
 *  - lines:  every paragraph, heading and list item on its own line, a blank
 *            line between paragraphs ("\n" and "\n\n" — WooCommerce runs
 *            wpautop on display, so they become <br> and <p> again).
 *  - single: one continuous paragraph. A line that ends without punctuation
 *            (heading, list item) gets ". " before the next one.
 *
 * Both: every tag and attribute goes (data-path-to-node and friends from AI
 * tools), comments / script / style / embedded media go with their content,
 * &nbsp; and the U+00A0 character become plain spaces, zero-width characters
 * go, other entities become characters except &amp; &lt; &gt; (the result is
 * always safe HTML), blank lines collapse. Shortcodes stay byte for byte.
 *
 * Steps:
 *  1) Control characters out, \r\n → \n.
 *  2) Comments and raw-text / media elements out, with their content.
 *  3) Shortcodes → placeholders (never touched).
 *  4) wpautop(): the stored field mixes tags and wpautop-style newlines
 *     (the classic editor saves paragraphs as blank lines, but keeps a <p>
 *     that carries attributes). After wpautop every break is a tag, so the
 *     source whitespace can be collapsed safely.
 *  5) Tokenize: block tags → paragraph marker, br / li / tr → line marker
 *     (anything inside a list item → line marker, so <li><p>…</p></li>
 *     gives consecutive lines), td / th → space, every other tag → nothing.
 *  6) Entities → characters, whitespace normalized, markers → output.
 *
 * Idempotent: convert( convert( x ) ) === convert( x ).
 * Pure string-in / string-out (needs only wpautop from WordPress).
 */

defined( 'ABSPATH' ) || exit;

final class SF_Plain {

	/** Internal markers (the input loses every control character in step 1). */
	const M_LINE = "\x02";
	const M_PARA = "\x03";
	const M_SC   = "\x04";

	/** Elements removed together with their content. */
	const DROP_ELEMENTS = 'script|style|template|noscript|iframe|object|video|audio|svg|math|textarea|select|button|canvas';

	/** Elements that end a paragraph (blank line). */
	const BLOCK_TAGS = array(
		'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'section', 'article', 'header',
		'footer', 'aside', 'nav', 'main', 'figure', 'figcaption', 'address', 'details', 'summary',
		'fieldset', 'form', 'table', 'hr', 'center', 'pre',
	);

	/** Elements that end a line. */
	const LINE_TAGS = array( 'br', 'tr', 'caption' );

	/** List containers and list items. */
	const LIST_TAGS = array( 'ul', 'ol', 'dl', 'menu' );
	const ITEM_TAGS = array( 'li', 'dt', 'dd' );

	/** Table cells: a space between them. */
	const CELL_TAGS = array( 'td', 'th' );

	/**
	 * @param string $html   Field value (HTML, shortcodes, wpautop-style newlines).
	 * @param bool   $single true = one paragraph, false = keep line breaks.
	 */
	public static function convert( string $html, bool $single = false ): string {

		// 1) Control characters (never meaningful in a description) + newlines.
		$html = (string) preg_replace( '~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~', '', $html );
		$html = str_replace( array( "\r\n", "\r" ), "\n", $html );

		// 2) Comments and dropped elements, with their content.
		$html = (string) preg_replace( '~<!--.*?(?:-->|$)~s', '', $html );
		$res  = preg_replace( '~<(' . self::DROP_ELEMENTS . ')\b[^>]*>.*?(?:</\1\s*>|$)~is', '', $html );
		if ( is_string( $res ) ) {
			$html = $res;
		}

		// 3) Shortcodes → placeholders (letters only, wrapped in M_SC).
		$codes = array();
		$res   = preg_replace_callback(
			SF_Engine::RE_SHORTCODE,
			static function ( $m ) use ( &$codes ) {
				$ph           = self::M_SC . SF_Rules::num_alpha( count( $codes ) ) . self::M_SC;
				$codes[ $ph ] = $m[0];
				return $ph;
			},
			$html
		);
		if ( is_string( $res ) ) {
			$html = $res;
		}

		if ( '' === trim( $html ) ) {
			return '';
		}

		// 4) Every line break becomes a tag.
		if ( function_exists( 'wpautop' ) ) {
			$html = wpautop( $html );
		}

		// 5) Tokenize.
		$text = self::tokenize( $html );

		// 6) Entities → characters, then whitespace.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Unicode patterns: invalid UTF-8 makes preg_replace return null, so keep the text then.
		$res  = preg_replace( '~[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]~u', ' ', $text );
		$text = is_string( $res ) ? $res : $text;
		$res  = preg_replace( '~[\x{200B}-\x{200D}\x{2060}\x{FEFF}]~u', '', $text );
		$text = is_string( $res ) ? $res : $text;
		$text = (string) preg_replace( '~[ \t\n\f]+~', ' ', $text );

		$out = self::assemble( $text, $single );

		// Safe HTML: the only characters that stay encoded.
		$out = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $out );

		return strtr( $out, $codes );
	}

	/** Tags → markers, text → text (source whitespace collapsed outside <pre>). */
	private static function tokenize( string $html ): string {

		$parts = preg_split( '~(</?[a-zA-Z][^>]*>|<[!?][^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}

		$out   = '';
		$lists = array(); // One entry per open list: 1 while one of its items is open.
		$pre   = 0;

		foreach ( $parts as $i => $part ) {

			if ( 0 === $i % 2 ) { // Text.
				$out .= ( $pre > 0 )
					? str_replace( "\n", self::M_LINE, $part )
					: (string) preg_replace( '~[ \t\n\f]+~', ' ', $part );
				continue;
			}

			if ( 1 !== preg_match( '~^<(/?)([a-zA-Z][a-zA-Z0-9-]*)~', $part, $m ) ) {
				continue; // <!doctype>, <?xml …>: nothing.
			}

			$close = ( '/' === $m[1] );
			$name  = strtolower( $m[2] );

			if ( in_array( $name, self::LIST_TAGS, true ) ) {
				if ( $close ) {
					array_pop( $lists );
				}
				$out .= in_array( 1, $lists, true ) ? self::M_LINE : self::M_PARA;
				if ( ! $close ) {
					$lists[] = 0;
				}
			} elseif ( in_array( $name, self::ITEM_TAGS, true ) ) {
				if ( empty( $lists ) ) {
					$lists[] = 0;
				}
				$lists[ count( $lists ) - 1 ] = $close ? 0 : 1;
				$out .= self::M_LINE;
			} elseif ( in_array( $name, self::BLOCK_TAGS, true ) ) {
				if ( 'pre' === $name ) {
					$pre = max( 0, $pre + ( $close ? -1 : 1 ) );
				}
				$out .= in_array( 1, $lists, true ) ? self::M_LINE : self::M_PARA;
			} elseif ( in_array( $name, self::LINE_TAGS, true ) ) {
				$out .= self::M_LINE;
			} elseif ( in_array( $name, self::CELL_TAGS, true ) ) {
				$out .= ' ';
			}
			// Every other tag (inline formatting, links, images…): nothing.
		}

		return $out;
	}

	/** Markers → "\n" / "\n\n" (lines) or one paragraph (single). */
	private static function assemble( string $text, bool $single ): string {

		$parts = preg_split( '~([\x02\x03][\x02\x03 ]*)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return trim( $text );
		}

		$out = '';
		$sep = ''; // Pending separator kind before the next non-empty piece.

		foreach ( $parts as $i => $part ) {

			if ( 1 === $i % 2 ) { // Separator run.
				if ( '' !== $out ) {
					$sep = ( false !== strpos( $part, self::M_PARA ) || 'para' === $sep ) ? 'para' : 'line';
				}
				continue;
			}

			$piece = trim( $part, ' ' );
			if ( '' === $piece ) {
				continue;
			}

			if ( '' !== $out ) {
				if ( $single ) {
					$out .= self::ends_sentence( $out ) ? ' ' : '. ';
				} else {
					$out .= ( 'para' === $sep ) ? "\n\n" : "\n";
				}
			}
			$out .= $piece;
			$sep  = '';
		}

		return $out;
	}

	/**
	 * Does the text end with punctuation (closing quotes / brackets after it
	 * allowed)? Greek question mark (;  U+037E) and ano teleia (· U+0387) count.
	 */
	private static function ends_sentence( string $text ): bool {
		return 1 === preg_match( '~[.!?;:…·,\x{037E}\x{0387}][)\]}»”’"\']*$~u', $text )
			|| 1 === preg_match( '~\x04$~', $text ); // Ends with a shortcode: leave it alone.
	}
}
