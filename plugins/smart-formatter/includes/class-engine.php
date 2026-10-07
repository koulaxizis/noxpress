<?php
/**
 * SF_Engine — Tokenizer + Rule pipeline (safe rendering), v1.1.0.
 *
 * Architecture:
 *  1) Protection ΠΡΙΝ από ΟΠΟΙΟΔΗΠΟΤΕ κανόνα: HTML comments, <script>,
 *     <style>, <textarea>, <pre>, <code> και shortcodes → placeholders
 *     (\x02P…\x01X). Κανένας κανόνας (ούτε το strip) δεν τα βλέπει.
 *  2) Document rules (strip formatting) — ο μόνος κανόνας που αγγίζει
 *     markup, με ακριβή ονόματα tags.
 *  3) Entities → inline letter-coded markers (\x03E…\x01X) ΜΕΣΑ στα
 *     text segments (ατομικά — κανένα range δεν σπάει μέσα τους).
 *  4) Segmentation σε html / text / protected. Κάθε text segment ξέρει
 *     αν βρίσκεται ήδη μέσα σε <strong>/<b> ή <em>/<i> (open-tag stack).
 *  5) Text rules: 'edit' (κενά) αλλάζουν το κείμενο· 'mark' (bold/italic)
 *     δίνουν byte ranges. Το render_marks() χτίζει ΕΝΑ well-formed markup
 *     από όλα τα ranges (επικαλύψεις → σωστό nesting, ποτέ crossing),
 *     χωρίς να περνά ποτέ όριο tag ή αλλαγή παραγράφου (\n\n — ώστε και
 *     μετά το wpautop το HTML να μένει έγκυρο).
 *  6) Restore entities + protected blocks → αρχικό HTML, intact.
 *
 * Εγγυήσεις:
 *  - Κανένας κανόνας scope 'text' δεν βλέπει tags, attributes, shortcodes,
 *    entities, ή περιεχόμενο code/pre/script/style/comments.
 *  - Idempotent: transform( transform( x ) ) === transform( x ) — ένα
 *    'mark' δεν εφαρμόζεται σε κείμενο που είναι ήδη μέσα στο tag του.
 *  - Αν το input είναι well-formed, το output είναι well-formed.
 *  - Excluded phrases δεν αλλάζουν ποτέ και κανένα regex δεν ταιριάζει
 *    μέσα τους (μπορούν να περικλειστούν από ευρύτερο wrap).
 */

defined( 'ABSPATH' ) || exit;

final class SF_Engine {

	/** Control-char placeholders για tokens. */
	const PH_BLOCK  = "\x02P";
	const PH_ENTITY = "\x03E";
	const PH_EXCL   = "\x00SF";
	const PH_END    = "\x01X"; // closing marker — κοινός terminator των block/entity placeholders.

	/** Bit flags για τα 'mark' tags. */
	const F_STRONG = 1;
	const F_EM     = 2;

	/** Void elements (δεν μπαίνουν στο open-tag stack). */
	const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/**
	 * Κύρια entry point — SF_Engine::transform( HTML, enabled_rules, excluded_phrases ).
	 *
	 * @param string $html     Input (full product field HTML).
	 * @param array  $enabled  Rule IDs από το SF_Rules.
	 * @param array  $excluded Φράσεις που δεν αγγίζονται ποτέ.
	 * @return string          Output (μορφοποιημένο, well-formed HTML).
	 */
	public static function transform( string $html, array $enabled, array $excluded = array() ): string {

		if ( '' === $html || empty( $enabled ) ) {
			return $html;
		}

		// Fail-safe: τα control chars \x00-\x04 είναι δικά μας markers.
		// Αν υπάρχουν ήδη στο input, δεν ρισκάρουμε σύγχυση — no-op.
		if ( 1 === preg_match( '~[\x00-\x04]~', $html ) ) {
			return $html;
		}

		// 1) Protection ΠΡΩΤΑ — πριν από κάθε κανόνα.
		list( $work, $blocks ) = self::protect_blocks( $html );

		// 2) Document-level rules (strip formatting).
		foreach ( SF_Rules::document_rules( $enabled ) as $rule ) {
			$work = (string) call_user_func( $rule['callback'], $work );
		}

		// 3) Entities → inline markers.
		list( $work, $entities ) = self::protect_html_entities( $work );

		// 4) Segmentation (+ formatting context ανά text segment).
		$segments = self::segment( $work );

		// 5) Text rules μόνο στα text segments.
		$segments = self::apply_text_rules( $segments, $enabled, $excluded );

		// 6) Join + restore.
		$work = implode( '', array_column( $segments, 'content' ) );
		$work = self::restore( $work, $entities );
		$work = self::restore( $work, $blocks );

		return $work;
	}

	/**
	 * Protected blocks → placeholders. Σειρά: comments πρώτα (μπορεί να
	 * περιέχουν «<pre>»), μετά raw-text elements, μετά pre (μπορεί να
	 * περιέχει <code>), code, shortcodes.
	 */
	private static function protect_blocks( string $html ): array {

		$tokens = array();
		$count  = 0;

		$stash = static function ( $m ) use ( &$tokens, &$count ) {
			$ph            = self::PH_BLOCK . SF_Rules::num_alpha( $count++ ) . self::PH_END;
			$tokens[ $ph ] = $m[0];
			return $ph;
		};

		$patterns = array(
			'~<!--.*?(?:-->|$)~s',
			'~<(script|style|textarea)\b[^>]*>.*?(?:</\1\s*>|$)~is',
			'~<(pre)\b[^>]*>.*?</pre\s*>~is',
			'~<(code)\b[^>]*>.*?</code\s*>~is',
			// Shortcodes: [name attrs]content[/name] ή [name attrs] / [name /].
			'~\[([a-zA-Z_][\w-]*)(?=[\s\]/])[^\]]*\].*?\[/\1\]|\[[a-zA-Z_][\w-]*(?=[\s\]/])[^\]]*\]~s',
		);

		foreach ( $patterns as $re ) {
			$res = preg_replace_callback( $re, $stash, $html );
			if ( is_string( $res ) ) {
				$html = $res;
			}
		}

		return array( $html, $tokens );
	}

	/** HTML entities (&nbsp;, &#123;, &alpha;) → letter-coded markers. */
	private static function protect_html_entities( string $html ): array {

		$tokens = array();
		$count  = 0;

		$res = preg_replace_callback(
			'~&(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#[xX][0-9a-fA-F]+);~',
			static function ( $m ) use ( &$tokens, &$count ) {
				$ph            = self::PH_ENTITY . SF_Rules::num_alpha( $count++ ) . self::PH_END;
				$tokens[ $ph ] = $m[0];
				return $ph;
			},
			$html
		);

		return array( is_string( $res ) ? $res : $html, $tokens );
	}

	/**
	 * Segmentation — array of {type: 'html'|'text'|'protected', content,
	 * bold, italic, skip}. Τα bold/italic/skip ισχύουν για text segments:
	 * αν το segment είναι ήδη μέσα σε <strong>/<b>, <em>/<i> ή σε element
	 * όπου δεν επιτρέπεται inline markup (<option>, <title>).
	 *
	 * Tag = '<' + γράμμα | '/' | '!' | '?'. Ένα σκέτο '<' (π.χ. «a < b»)
	 * μένει κείμενο. Το '>' μέσα σε quoted attribute value δεν κλείνει το tag.
	 */
	private static function segment( string $text ): array {

		$segments = array();
		$stack    = array();
		$buf      = '';
		$len      = strlen( $text );
		$i        = 0;

		$flush = static function () use ( &$segments, &$buf, &$stack ) {
			if ( '' === $buf ) {
				return;
			}
			$segments[] = array(
				'type'    => 'text',
				'content' => $buf,
				'bold'    => (bool) array_intersect( $stack, array( 'strong', 'b' ) ),
				'italic'  => (bool) array_intersect( $stack, array( 'em', 'i' ) ),
				'skip'    => (bool) array_intersect( $stack, array( 'option', 'title' ) ),
			);
			$buf = '';
		};

		while ( $i < $len ) {
			$c = $text[ $i ];

			// Protected block placeholder (\x02P…\x01X).
			if ( "\x02" === $c ) {
				$flush();
				$end = strpos( $text, self::PH_END, $i );
				$end = ( false === $end ) ? $len : $end + 2;
				$segments[] = array( 'type' => 'protected', 'content' => substr( $text, $i, $end - $i ) );
				$i = $end;
				continue;
			}

			if ( '<' === $c && $i + 1 < $len && 1 === preg_match( '~[a-zA-Z/!?]~', $text[ $i + 1 ] ) ) {
				$flush();
				$end = self::tag_end( $text, $i );
				$tag = substr( $text, $i, $end - $i );
				$segments[] = array( 'type' => 'html', 'content' => $tag );
				self::track_tag( $tag, $stack );
				$i = $end;
				continue;
			}

			$buf .= $c;
			$i++;
		}
		$flush();

		return $segments;
	}

	/** Θέση ΜΕΤΑ το '>' ενός tag που ξεκινά στο $i (quote-aware). */
	private static function tag_end( string $text, int $i ): int {
		$len   = strlen( $text );
		$quote = '';
		for ( $j = $i + 1; $j < $len; $j++ ) {
			$c = $text[ $j ];
			if ( '' !== $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				// Quote μετρά μόνο ως attribute value (μετά από '=').
				$k = $j - 1;
				while ( $k > $i && ( ' ' === $text[ $k ] || "\t" === $text[ $k ] || "\n" === $text[ $k ] ) ) {
					$k--;
				}
				if ( '=' === $text[ $k ] ) {
					$quote = $c;
				}
				continue;
			}
			if ( '>' === $c ) {
				return $j + 1;
			}
		}
		// Μη κλειστό quote: fallback στο πρώτο '>' (ή τέλος κειμένου).
		$gt = strpos( $text, '>', $i );
		return ( false === $gt ) ? $len : $gt + 1;
	}

	/** Ενημέρωση του open-tag stack με ένα tag. */
	private static function track_tag( string $tag, array &$stack ): void {
		if ( 1 !== preg_match( '~^<(/?)([a-zA-Z][a-zA-Z0-9-]*)~', $tag, $m ) ) {
			return; // <!doctype>, processing instructions κ.λπ.
		}
		$name = strtolower( $m[2] );
		if ( '/' === $m[1] ) {
			$pos = array_search( $name, array_reverse( $stack, true ), true );
			if ( false !== $pos ) {
				$stack = array_slice( $stack, 0, $pos );
			}
			return;
		}
		if ( in_array( $name, self::VOID_TAGS, true ) || '/>' === substr( rtrim( $tag ), -2 ) ) {
			return;
		}
		$stack[] = $name;
	}

	/** Apply text rules ONLY σε 'text' segments. */
	private static function apply_text_rules( array $segments, array $enabled, array $excluded ): array {

		$rules = SF_Rules::text_rules( $enabled );
		if ( empty( $rules ) ) {
			return $segments;
		}

		$excluded = self::prepare_exclusions( $excluded );
		$last     = count( $segments ) - 1;

		foreach ( $segments as $k => $seg ) {
			if ( 'text' !== $seg['type'] || ! empty( $seg['skip'] ) ) {
				continue;
			}
			$segments[ $k ]['content'] = self::apply_to_segment(
				$seg['content'],
				$rules,
				$excluded,
				array(
					'bold'    => $seg['bold'],
					'italic'  => $seg['italic'],
					'is_last' => ( $k === $last ),
				)
			);
		}

		return $segments;
	}

	/** Exclusions: strings, unique, μεγαλύτερες πρώτα. */
	private static function prepare_exclusions( array $excluded ): array {
		$excluded = array_values( array_unique( array_filter( array_map( 'strval', $excluded ), 'strlen' ) ) );
		usort(
			$excluded,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		return $excluded;
	}

	/** Όλοι οι κανόνες σε ένα text segment. */
	private static function apply_to_segment( string $text, array $rules, array $excluded, array $ctx ): string {

		// Exclusions → placeholders (\x00SF{letters}\x00).
		$ph_map = array();
		foreach ( $excluded as $n => $phrase ) {
			if ( false !== strpos( $text, $phrase ) ) {
				$ph            = self::PH_EXCL . SF_Rules::num_alpha( $n ) . "\x00";
				$text          = str_replace( $phrase, $ph, $text );
				$ph_map[ $ph ] = $phrase;
			}
		}

		// 'edit' rules πρώτα (αλλάζουν το κείμενο).
		foreach ( $rules as $rule ) {
			if ( 'edit' === $rule['type'] ) {
				$text = (string) call_user_func( $rule['callback'], $text, $ctx );
			}
		}

		// 'mark' rules → flags ανά byte.
		if ( '' !== trim( $text ) ) {
			$len   = strlen( $text );
			$flags = array_fill( 0, $len, 0 );
			$any   = false;

			foreach ( $rules as $rule ) {
				if ( 'mark' !== $rule['type'] ) {
					continue;
				}
				$bit = ( 'strong' === $rule['tag'] ) ? self::F_STRONG : self::F_EM;
				// Idempotency: το segment είναι ήδη μέσα στο tag → skip.
				if ( ( self::F_STRONG === $bit && $ctx['bold'] ) || ( self::F_EM === $bit && $ctx['italic'] ) ) {
					continue;
				}
				foreach ( (array) call_user_func( $rule['callback'], $text ) as $r ) {
					$s = max( 0, (int) $r[0] );
					$e = min( $len, (int) $r[1] );
					for ( $p = $s; $p < $e; $p++ ) {
						$flags[ $p ] |= $bit;
						$any          = true;
					}
				}
			}

			if ( $any ) {
				$flags = self::normalize_flags( $text, $flags );
				$text  = self::render_marks( $text, $flags );
			}
		}

		return empty( $ph_map ) ? $text : str_replace( array_keys( $ph_map ), array_values( $ph_map ), $text );
	}

	/**
	 * Καθαρισμός flags πριν το render:
	 *  - Ατομικά tokens (entity markers, exclusion placeholders): ενιαίο
	 *    flag σε όλο το token (AND) — κανένα tag δεν μπαίνει μέσα τους.
	 *  - Αλλαγές παραγράφου (\n + κενή γραμμή): χωρίς flags — κανένα tag
	 *    δεν διασχίζει παράγραφο (έγκυρο HTML και μετά το wpautop).
	 *  - Leading/trailing whitespace κάθε range μένει έξω από τα tags.
	 */
	private static function normalize_flags( string $text, array $flags ): array {

		$re = '~' . preg_quote( self::PH_ENTITY, '~' ) . '[a-z]+' . preg_quote( self::PH_END, '~' )
			. '|' . preg_quote( self::PH_EXCL, '~' ) . '[a-z]+\x00~';
		if ( preg_match_all( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$s   = (int) $hit[1];
				$e   = $s + strlen( $hit[0] );
				$and = self::F_STRONG | self::F_EM;
				for ( $p = $s; $p < $e; $p++ ) {
					$and &= $flags[ $p ];
				}
				for ( $p = $s; $p < $e; $p++ ) {
					$flags[ $p ] = $and;
				}
			}
		}

		if ( preg_match_all( '~[ \t]*\n[ \t]*\n\s*~', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$s = (int) $hit[1];
				$e = $s + strlen( $hit[0] );
				for ( $p = $s; $p < $e; $p++ ) {
					$flags[ $p ] = 0;
				}
			}
		}

		// Whitespace στα άκρα κάθε run ενός bit → εκτός tag.
		foreach ( array( self::F_STRONG, self::F_EM ) as $bit ) {
			$len = count( $flags );
			$p   = 0;
			while ( $p < $len ) {
				if ( ! ( $flags[ $p ] & $bit ) ) {
					$p++;
					continue;
				}
				$s = $p;
				while ( $p < $len && ( $flags[ $p ] & $bit ) ) {
					$p++;
				}
				$e = $p;
				for ( $q = $s; $q < $e && self::is_space( $text[ $q ] ); $q++ ) {
					$flags[ $q ] &= ~$bit;
				}
				for ( $q = $e - 1; $q >= $s && self::is_space( $text[ $q ] ); $q-- ) {
					$flags[ $q ] &= ~$bit;
				}
			}
		}

		return $flags;
	}

	/**
	 * Well-formed render: σε κάθε αλλαγή flags κλείνουν (από την κορυφή του
	 * stack) όσα tags χρειάζεται, ξανανοίγουν όσα συνεχίζουν, και τα νέα
	 * ανοίγουν με το μακρύτερο εξωτερικά (λιγότερα re-open). Το stack
	 * κλείνει πάντα με αντίστροφη σειρά → ποτέ crossing tags.
	 */
	private static function render_marks( string $text, array $flags ): string {

		$tags  = array( self::F_STRONG => 'strong', self::F_EM => 'em' );
		$len   = strlen( $text );
		$out   = '';
		$stack = array();
		$i     = 0;

		while ( $i < $len ) {
			$f = $flags[ $i ];
			$j = $i + 1;
			while ( $j < $len && $flags[ $j ] === $f ) {
				$j++;
			}

			// Κλείσιμο: από το βαθύτερο tag που ΔΕΝ πρέπει να μείνει και πάνω.
			$cut = null;
			foreach ( $stack as $k => $bit ) {
				if ( ! ( $f & $bit ) ) {
					$cut = $k;
					break;
				}
			}
			if ( null !== $cut ) {
				for ( $k = count( $stack ) - 1; $k >= $cut; $k-- ) {
					$out .= '</' . $tags[ $stack[ $k ] ] . '>';
				}
				$stack = array_slice( $stack, 0, $cut );
			}

			// Άνοιγμα: όσα λείπουν, με το μακρύτερο πρώτο (εξωτερικό).
			$open = array();
			foreach ( $tags as $bit => $name ) {
				if ( ( $f & $bit ) && ! in_array( $bit, $stack, true ) ) {
					$ext = $i;
					while ( $ext < $len && ( $flags[ $ext ] & $bit ) ) {
						$ext++;
					}
					$open[ $bit ] = $ext;
				}
			}
			// Μακρύτερο εξωτερικά· σε ισοπαλία strong έξω (ντετερμινιστικό σε κάθε PHP).
			$order = array_keys( $open );
			usort(
				$order,
				static function ( $a, $b ) use ( $open ) {
					return ( $open[ $b ] <=> $open[ $a ] ) ?: ( $a <=> $b );
				}
			);
			foreach ( $order as $bit ) {
				$out    .= '<' . $tags[ $bit ] . '>';
				$stack[] = $bit;
			}

			$out .= substr( $text, $i, $j - $i );
			$i    = $j;
		}

		for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
			$out .= '</' . $tags[ $stack[ $k ] ] . '>';
		}

		return $out;
	}

	/** ASCII whitespace byte (χωρίς εξάρτηση από το ctype extension). */
	private static function is_space( string $c ): bool {
		return ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c || "\f" === $c || "\v" === $c;
	}

	/** Restore placeholders → αρχικό περιεχόμενο. */
	private static function restore( string $html, array $map ): string {
		return empty( $map ) ? $html : strtr( $html, $map );
	}
}
