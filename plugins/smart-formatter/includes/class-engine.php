<?php
/**
 * SF_Engine — Tokenizer + Rule pipeline (safe rendering).
 *
 * Architecture:
 *  1) Document-level protection: shortcodes & code/pre → placeholders (\x02…/\x04…),
 *     entities → inline letter-coded markers (\x03…) ΜΕΣΑ στα text segments.
 *     Τα HTML tags ΔΕΝ γίνονται placeholders — τα ξεχωρίζει native το segment().
 *  2) Document rules (strip formatting) εκτελούνται ΠΡΙΝ την tokenization
 *     (μοναδικός κανόνας που επιτρέπεται να αγγίζει markup).
 *  3) Text-level rules εκτελούνται ΜΕΤΑ την tokenization — μόνο σε
 *     segments που είναι καθαρό κείμενο (κανένα tag/attribute/entity μέσα).
 *  4) Restore placeholders → αρχικό HTML, intact.
 *
 * Security guarantees:
 *  - Κανένας κανόνας scope 'text' δεν βλέπει ποτέ:
 *    * <tag attributes>
 *    * shortcodes [shortcode]
 *    * &entity;
 *    * <code>...</code> / <pre>...</pre> περιεχόμενο
 *  - Protected segments κρατιούνται ως placeholders και επαναφέρονται στο τέλος
 *  - excluded phrases (από το SF_Targets/Settings) προστατεύονται με \x00N\x00 markers
 *    που τρέχουν MΕΣΑ σε κάθε text rule (with_exclusions στο SF_Rules).
 *
 * Performance:
 *  - Regex-passes ανά segment ≈ 5-7 (όχι nested loops)
 *  - Παράγουμε 1 output array με segments: {type: 'html'|'text'|'protected', content}
 *    — το render τα ενώνει σε ένα string
 *
 * Wave 2 extensions:
 *  - Απλώς προσθέτουμε περισσότερους callback rules στο SF_Rules registry
 *  - Το engine δεν αλλάζει ποτέ — είναι neutral pipeline
 */

defined( 'ABSPATH' ) || exit;

final class SF_Engine {

	/** Control-char placeholders για tokens. */
	const PH_SHORT    = "\x02S";
	const PH_ENTITY   = "\x03E";
	const PH_CODEPRE  = "\x04C";
	const PH_END      = "\x01X"; // closing marker — κοινός terminator όλων των placeholder families.

	/** Letters-only encoding (a, b … z, aa …) — markers χωρίς ψηφία. */
	private static function num_alpha( int $n ): string {
		$s = '';
		$n = max( 0, $n );
		do {
			$s = chr( 97 + ( $n % 26 ) ) . $s;
			$n = intdiv( $n, 26 ) - 1;
		} while ( $n >= 0 );
		return $s;
	}

	/**
	 * Κύρια entry point — SF_Engine::transform( HTML, enabled_rules, excluded_phrases ).
	 *
	 * @param string $html       Input (full product field HTML).
	 * @param array  $enabled    Array rule IDs από το SF_Rules (π.χ. sf_normalize_whitespace).
	 * @param array  $excluded   Φράσεις που δεν αγγίζονται ποτέ.
	 * @return string            Output (μορφοποιημένο, safe HTML).
	 */
	public static function transform( string $html, array $enabled, array $excluded = array() ): string {

		// 1) Document-level rules (μόνο strip formatting) — Τρέχει ΠΡΙΝ protection.
		$docs = SF_Rules::document_rules( $enabled );
		foreach ( $docs as $rule ) {
			$html = call_user_func( $rule['callback'], $html );
		}

		// 2) Protection: protected blocks (<code>, <pre>, shortcodes) → placeholders.
		list( $html, $tokens ) = self::protect_blocks( $html );

		// 3) HTML entity protection → inline letter-encoded markers
		//    (μένουν ΜΕΣΑ στα text segments — βλ. segment()).
		list( $html, $ent_tokens ) = self::protect_html_entities( $html );

		// 4) Segmentation — split σε text vs HTML tokens.
		$segments = self::segment( $html );

		// 5) Text rules εκτελούνται ΜΕΤΑ την tokenization — μόνο σε text segments.
		$segments = self::apply_text_rules( $segments, $enabled, $excluded );

		// 6) Join segments — map {type, content} → content strings.
		$html = implode( '', array_column( $segments, 'content' ) );

		// 7) Restore HTML entities.
		$html = self::restore_entities( $html, $ent_tokens );

		// 8) Restore protected blocks.
		$html = self::restore_blocks( $html, $tokens );

		return $html;
	}

	/**
	 * Protected blocks: <code>...</code>, <pre>...</pre>,
	 * [shortcode ...] (με όποια attributes έχουν).
	 * Return: array (modified_html, tokens_array).
	 */
	private static function protect_blocks( string $html ): array {

		$tokens = array();
		$count  = 0;

		// <code>...</code> — greedy μη nested.
		$html = preg_replace_callback(
			'~<(code)\b([^>]*)>(.*?)</\1>~is',
			function ( $m ) use ( &$tokens, &$count ) {
				$id       = $count++;
				$placeholder = self::PH_CODEPRE . $id . self::PH_END;
				$tokens[ $placeholder ] = $m[0]; // full tag+content
				return $placeholder;
			},
			$html
		);

		// <pre>...</pre> — ίδιος λογική.
		$html = preg_replace_callback(
			'~<(pre)\b([^>]*)>(.*?)</\1>~is',
			function ( $m ) use ( &$tokens, &$count ) {
				$id       = $count++;
				$placeholder = self::PH_CODEPRE . $id . self::PH_END;
				$tokens[ $placeholder ] = $m[0];
				return $placeholder;
			},
			$html
		);

		// Shortcodes: [shortcode att="val" attr='val' ]content[/shortcode]
		//              ή [shortcode att="val"] (self-closing).
		$html = preg_replace_callback(
			'~\[([a-zA-Z_][a-zA-Z0-9_]*)(?:[^\]]*)?\].*?\[\/\1\]|\[[a-zA-Z_][a-zA-Z0-9_]*(?:[^\]])*\]~s',
			function ( $m ) use ( &$tokens, &$count ) {
				$id       = $count++;
				$placeholder = self::PH_SHORT . $id . self::PH_END;
				$tokens[ $placeholder ] = $m[0];
				return $placeholder;
			},
			$html
		);

		return array( $html, $tokens );
	}

	/**
	 * HTML entities (&nbsp;, &#123;, &alpha;) → placeholders.
	 * Έτσι κανένα rule text δεν μπορεί να τα χαλάσει ή να τα διπλοεπεξεργαστεί.
	 */
	private static function protect_html_entities( string $html ): array {

		$tokens = array();
		$count  = 0;

		$html = preg_replace_callback(
			'~&(?:[a-zA-Z]+|#\d+|#x[0-9a-fA-F]+);~',
			function ( $m ) use ( &$tokens, &$count ) {
				$id          = $count++;
				$placeholder = self::PH_ENTITY . self::num_alpha( $id ) . self::PH_END;
				$tokens[ $placeholder ] = $m[0];
				return $placeholder;
			},
			$html
		);

		return array( $html, $tokens );
	}

	/**
	 * Segmentation — split σε segments με type.
	 * Input: string με placeholders.
	 * Output: array {type: 'html'|'text'|'protected', content}.
	 */
	private static function segment( string $text ): array {

		$segments = array();
		$current_type = null;
		$current_buffer = '';

		$len = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];

			// Start of placeholder (control char markers). Τα entities (\x03)
			// μένουν INLINE στο text — letter-encoded markers που κανένα rule
			// regex δεν αγγίζει (λείπουν ψηφία, quotes, παρενθέσεις).
			// Το \x01 ΔΕΝ είναι start marker (είναι ο terminator) — dead check.
			if ( "\x02" === $c || "\x04" === $c ) {
				// Flush current text buffer.
				if ( '' !== $current_buffer && null !== $current_type ) {
					$segments[] = array( 'type' => $current_type, 'content' => $current_buffer );
					$current_buffer = '';
				}

				// Read full placeholder (until \x01X).
				$j = $i;
				while ( $j < $len && "\x01X" !== substr( $text, $j, 2 ) ) {
					$j++;
				}
				$placeholder = substr( $text, $i, $j - $i + 2 ); // include \x01X

				// Determine placeholder type.
				$p_type = 'html'; // default fallback for HTML tags
				if ( self::PH_SHORT === substr( $placeholder, 0, 2 ) ) {
					$p_type = 'protected';
				} elseif ( self::PH_CODEPRE === substr( $placeholder, 0, 2 ) ) {
					$p_type = 'protected';
				}

				// Add placeholder as segment.
				$segments[] = array( 'type' => $p_type, 'content' => $placeholder );
				$i = $j + 1; // skip past the placeholder

				continue;
			}

			// Normal character — accumulate.
			if ( '<' === $c ) {
				// Start of HTML tag.
				if ( '' !== $current_buffer && null !== $current_type ) {
					$segments[] = array( 'type' => $current_type, 'content' => $current_buffer );
					$current_buffer = '';
				}

				// Read full tag.
				$j = $i + 1;
				while ( $j < $len && '>' !== $text[ $j ] ) {
					$j++;
				}
				$tag = substr( $text, $i, $j - $i + 1 );

				$segments[] = array( 'type' => 'html', 'content' => $tag );
				$i = $j;

				$current_type = 'html';
				continue;
			}

			// Regular text character.
			if ( 'html' === $current_type && '' !== $current_buffer ) {
				$segments[] = array( 'type' => 'html', 'content' => $current_buffer );
				$current_buffer = '';
			}
			$current_type = 'text';
			$current_buffer .= $c;
		}

		// Flush final buffer.
		if ( '' !== $current_buffer && null !== $current_type ) {
			$segments[] = array( 'type' => $current_type, 'content' => $current_buffer );
		}

		return $segments;
	}

	/**
	 * Apply text rules ONLY on 'text' segments.
	 * Protected/html segments pass through untouched.
	 */
	private static function apply_text_rules( array $segments, array $enabled, array $excluded ): array {

		foreach ( $segments as &$seg ) {
			if ( 'text' === $seg['type'] && '' !== trim( $seg['content'] ) ) {
				$seg['content'] = SF_Rules::apply_text( $seg['content'], $enabled, array( 'excluded' => $excluded ) );
			}
		}

		return $segments;
	}

	/** Restore HTML entities from placeholders. */
	private static function restore_entities( string $html, array $entities ): string {
		return str_replace( array_keys( $entities ), array_values( $entities ), $html );
	}

	/** Restore protected blocks from placeholders. */
	private static function restore_blocks( string $html, array $blocks ): string {
		return str_replace( array_keys( $blocks ), array_values( $blocks ), $html );
	}

}