<?php
/**
 * SF_Rules — Registry κανόνων μορφοποίησης (v1.1.0).
 *
 * Κάθε κανόνας δηλώνεται με:
 *  - id        σταθερή τιμή (checkbox values στο UI, persisted στα profiles/runs)
 *  - label     Greek msgid (μεταφράζεται στο UI μέσω __())
 *  - scope     'document' (ολόκληρο το HTML string, ΜΕΤΑ την προστασία
 *              code/pre/script/style/comments/shortcodes) ή 'text'
 *              (ανά text segment — ποτέ δεν βλέπει tags/attributes/entities)
 *  - type      μόνο για scope 'text':
 *               'edit' → callback( string $text, array $ctx ): string
 *                        (αλλάζει το ίδιο το κείμενο — π.χ. κενά)
 *               'mark' → callback( string $text ): array of [start, end)
 *                        byte ranges που πρέπει να πάρουν το 'tag'
 *  - tag       'strong' | 'em' (μόνο για 'mark')
 *  - order     deterministic σειρά εκτέλεσης μέσα στο scope
 *
 * v1.1.0 — γιατί ranges και όχι regex replace:
 *  Οι κανόνες 'mark' ΔΕΝ γράφουν πια tags. Επιστρέφουν περιοχές και το
 *  SF_Engine::render_marks() χτίζει ΕΝΑ well-formed markup από όλες μαζί
 *  (επικαλυπτόμενες περιοχές → σωστό nesting, όχι διασταυρούμενα tags).
 *  Το engine επίσης παραλείπει κάθε 'mark' κανόνα όταν το segment βρίσκεται
 *  ήδη μέσα στο αντίστοιχο tag (<strong>/<b> ή <em>/<i>) → idempotent:
 *  δεύτερη εφαρμογή = καμία αλλαγή.
 *
 * Security: καθαρό string-in/string-out, καμία DB access, κανένα
 * eval/create_function.
 */

defined( 'ABSPATH' ) || exit;

final class SF_Rules {

	/* Rule IDs (σταθερά — persisted στα profiles/runs) */
	const R_STRIP        = 'sf_strip_formatting';
	const R_WS           = 'sf_normalize_whitespace';
	const R_QUOTES_IT    = 'sf_quoted_italic';
	const R_QUOTES_BD    = 'sf_quoted_bold';
	const R_PARENS_IT    = 'sf_parens_italic';
	const R_NUMBERS_BD   = 'sf_numbers_bold';
	const R_ALL_ITALIC   = 'sf_all_italic';
	const R_ALL_BOLD     = 'sf_all_bold';

	/**
	 * Inline formatting tags που αφαιρεί το strip (keep content, drop markup).
	 * Ακριβή ονόματα — ΠΟΤΕ prefix match (<ul>, <br>, <span>, <small>,
	 * <sup>, <img>, <blockquote> μένουν άθικτα).
	 */
	const STRIP_TAGS = array( 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'ins', 'mark' );

	/**
	 * Registry — ΠΑΝΤΑ fresh array (όχι static cache): τα labels είναι
	 * plain msgids, η μετάφραση γίνεται στο render (Bible §9 pattern).
	 */
	public static function registry(): array {
		return array(
			self::R_STRIP      => array(
				'id'       => self::R_STRIP,
				'label'    => 'Πλήρης αφαίρεση μορφοποίησης',
				'scope'    => 'document',
				'order'    => 10,
				'callback' => array( __CLASS__, 'cb_strip' ),
			),
			self::R_WS         => array(
				'id'       => self::R_WS,
				'label'    => 'Κανονικοποίηση κενών',
				'scope'    => 'text',
				'type'     => 'edit',
				'order'    => 20,
				'callback' => array( __CLASS__, 'cb_ws' ),
			),
			self::R_QUOTES_IT  => array(
				'id'       => self::R_QUOTES_IT,
				'label'    => 'Περιεχόμενο εισαγωγικών italic',
				'scope'    => 'text',
				'type'     => 'mark',
				'tag'      => 'em',
				'order'    => 30,
				'callback' => array( __CLASS__, 'cb_quotes' ),
			),
			self::R_QUOTES_BD  => array(
				'id'       => self::R_QUOTES_BD,
				'label'    => 'Περιεχόμενο εισαγωγικών bold',
				'scope'    => 'text',
				'type'     => 'mark',
				'tag'      => 'strong',
				'order'    => 31,
				'callback' => array( __CLASS__, 'cb_quotes' ),
			),
			self::R_PARENS_IT  => array(
				'id'       => self::R_PARENS_IT,
				'label'    => 'Περιεχόμενο παρενθέσεων italic',
				'scope'    => 'text',
				'type'     => 'mark',
				'tag'      => 'em',
				'order'    => 40,
				'callback' => array( __CLASS__, 'cb_parens' ),
			),
			self::R_NUMBERS_BD => array(
				'id'       => self::R_NUMBERS_BD,
				'label'    => 'Αριθμοί bold',
				'scope'    => 'text',
				'type'     => 'mark',
				'tag'      => 'strong',
				'order'    => 50,
				'callback' => array( __CLASS__, 'cb_numbers' ),
			),
			self::R_ALL_ITALIC => array(
				'id'       => self::R_ALL_ITALIC,
				'label'    => 'Όλο το κείμενο italic',
				'scope'    => 'text',
				'type'     => 'mark',
				'tag'      => 'em',
				'order'    => 90,
				'callback' => array( __CLASS__, 'cb_all' ),
			),
			self::R_ALL_BOLD   => array(
				'id'       => self::R_ALL_BOLD,
				'label'    => 'Όλο το κείμενο bold',
				'scope'    => 'text',
				'type'     => 'mark',
				'tag'      => 'strong',
				'order'    => 91,
				'callback' => array( __CLASS__, 'cb_all' ),
			),
		);
	}

	/** Ενεργά rules ενός scope σε σειρά order (helper για το SF_Engine). */
	private static function rules_of_scope( string $scope, array $enabled ): array {
		$out = array();
		foreach ( self::registry() as $id => $def ) {
			if ( $scope === $def['scope'] && in_array( $id, $enabled, true ) ) {
				$out[ $def['order'] ] = $def;
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/** Ενεργά text rules σε σειρά order. */
	public static function text_rules( array $enabled ): array {
		return self::rules_of_scope( 'text', $enabled );
	}

	/** Ενεργά document rules σε σειρά order. */
	public static function document_rules( array $enabled ): array {
		return self::rules_of_scope( 'document', $enabled );
	}

	/**
	 * Letters-only encoding (a, b … z, aa, ab …) — markers χωρίς ψηφία,
	 * ώστε κανένα rule regex (\d+) να μην τα αγγίζει. Κοινό για SF_Engine
	 * και exclusions (μία υλοποίηση).
	 */
	public static function num_alpha( int $n ): string {
		$s = '';
		$n = max( 0, $n );
		do {
			$s = chr( 97 + ( $n % 26 ) ) . $s;
			$n = intdiv( $n, 26 ) - 1;
		} while ( $n >= 0 );
		return $s;
	}

	/* =====================================================================
	 * Callbacks — document scope
	 * =================================================================== */

	/**
	 * Πλήρης αφαίρεση μορφοποίησης: πετάει τα (opening/closing) tags της
	 * STRIP_TAGS list, ΚΡΑΤΑ το περιεχόμενο. Το όνομα του tag πρέπει να
	 * ταιριάζει ΑΚΡΙΒΩΣ (ακολουθεί κενό, '/' ή '>') — το <b> δεν πιάνει
	 * το <br>/<blockquote>, το <s> δεν πιάνει το <span>/<small>/<sup>,
	 * το <i> δεν πιάνει το <img>/<iframe>. Τρέχει ΜΕΤΑ την προστασία
	 * code/pre/script/style (το SF_Engine τα έχει ήδη κάνει placeholders).
	 */
	public static function cb_strip( string $html ): string {
		$names = implode( '|', self::STRIP_TAGS );
		return (string) preg_replace( '~</?(?:' . $names . ')(?=[\s/>])[^>]*>~i', '', $html );
	}

	/* =====================================================================
	 * Callbacks — text scope 'edit'
	 * =================================================================== */

	/**
	 * Κανονικοποίηση κενών (συντηρητικό, safe by design):
	 *  - \r\n / \r → \n
	 *  - Πολλαπλά spaces/tabs σε ένα
	 *  - Trailing spaces ΠΡΙΝ από πραγματική αλλαγή γραμμής (\n) και στο
	 *    τέλος ολόκληρου του κειμένου ($ctx['is_last']) — ΟΧΙ στο τέλος
	 *    ενός segment που ακολουθείται από tag («Hello <b>World</b>» μένει).
	 *  - 3+ αλλαγές γραμμής → 2
	 *  - Κενό ΠΡΙΝ από σημεία στίξης («κείμενο , κείμενο» → «κείμενο, κείμενο»)
	 * ΔΕΝ προσθέτει κενά (κίνδυνος σε URLs, entities, συντομογραφίες).
	 */
	public static function cb_ws( string $text, array $ctx = array() ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( '~[ \t]{2,}~', ' ', $text );
		$text = (string) preg_replace( '~[ \t]+(?=\n)~', '', $text );
		if ( ! empty( $ctx['is_last'] ) ) {
			$text = rtrim( $text, " \t" );
		}
		$text = (string) preg_replace( '~\n{3,}~', "\n\n", $text );
		$text = (string) preg_replace( '~[ \t]+([,.!?;:])~u', '$1', $text );
		return $text;
	}

	/* =====================================================================
	 * Callbacks — text scope 'mark' (επιστρέφουν byte ranges [start, end))
	 * =================================================================== */

	/**
	 * Quoted segment ΜΑΖΙ με τα εισαγωγικά. Υποστήριξη: ελληνικά
	 * guillemets «», curly “”, straight "".
	 */
	public static function cb_quotes( string $text ): array {
		return array_merge(
			self::match_ranges( '~«[^»]+»~u', $text ),
			self::match_ranges( '~“[^”]+”~u', $text ),
			self::match_ranges( '~"[^"]+"~', $text )
		);
	}

	/**
	 * Περιεχόμενο παρενθέσεων ΜΑΖΙ με τις παρενθέσεις. Nesting παρενθέσεων
	 * δεν υποστηρίζεται (το [^()] σταματά στο πρώτο ')').
	 */
	public static function cb_parens( string $text ): array {
		return self::match_ranges( '~\([^()\n]{1,300}\)~u', $text );
	}

	/** Αριθμοί — ακέραιοι και δεκαδικοί (κόμμα ή τελεία, π.χ. «σελίδες 320»). */
	public static function cb_numbers( string $text ): array {
		return self::match_ranges( '~\d+(?:[.,]\d+)*~u', $text );
	}

	/** Όλο το (μη-whitespace) περιεχόμενο του segment. */
	public static function cb_all( string $text ): array {
		$trimmed = ltrim( $text );
		if ( '' === $trimmed ) {
			return array();
		}
		$start = strlen( $text ) - strlen( $trimmed );
		$end   = strlen( rtrim( $text ) );
		return array( array( $start, $end ) );
	}

	/** Όλα τα matches ενός regex ως byte ranges. */
	private static function match_ranges( string $re, string $text ): array {
		$out = array();
		if ( preg_match_all( $re, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$out[] = array( (int) $hit[1], (int) $hit[1] + strlen( $hit[0] ) );
			}
		}
		return $out;
	}
}
