<?php
/**
 * SF_Rules — Registry κανόνων μορφοποίησης (Wave 1).
 *
 * Κάθε κανόνας δηλώνεται με:
 *  - id           σταθερή τιμή (checkbox values στο UI)
 *  - label        Greek msgid (μεταφράζεται στο UI μέσω __())
 *  - scope        'document' (ολόκληρο το HTML string, ΠΡΙΝ την
 *                 tokenization) ή 'text' (ανά text segment, ΜΕΤΑ —
 *                 ποτέ δεν βλέπει tags/attributes/entities)
 *  - order        deterministic σειρά εκτέλεσης μέσα στο scope
 *  - callback      string => string
 *
 * Σειρά scope 'text' (σημασιολογία «combos»):
 *  20 normalize_whitespace  — καθάρισμα πρώτα
 *  30/31 quoted_*            — μηνιαία value rules
 *  40 parens_italic
 *  50 numbers_bold
 *  90/91 all_italic/all_bold — ΟΛΟΚΛΗΡΩΤΙΚΑ wraps ΤΕΛΕΥΤΑΙΑ ώστε να
 *               περικλείουν και τα tags που πρόσθεσαν οι προηγούμενοι
 *               κανόνες (έγκυρο nesting, όχι σπασμένο HTML)
 *
 * Exclusions (ρώτησε μηχανής): το ctx['excluded'] (array φράσεων)
 * προστατεύεται ΜΕΣΑ σε κάθε segment μέσω placeholders — οι φράσεις
 * αυτές δεν μεταμορφώνονται ποτέ, όποιος κανόνας κι αν τρέχει.
 *
 * Security: καθαρό string-in/string-out, καμία DB access, κανένα
 * eval/create_function. Κανένα input δεν εμπιστεύεται από εδώ —
 * το SF_Engine καλεί πάντα με validated data.
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

	/** Tags που αφαιρεί το strip (keep content, drop markup). */
	const STRIP_TAGS = 'strong|b|em|i|u|s|strike|del|ins|mark';

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
				'order'    => 20,
				'callback' => array( __CLASS__, 'cb_ws' ),
			),
			self::R_QUOTES_IT  => array(
				'id'       => self::R_QUOTES_IT,
				'label'    => 'Περιεχόμενο εισαγωγικών italic',
				'scope'    => 'text',
				'order'    => 30,
				'callback' => array( __CLASS__, 'cb_quotes_italic' ),
			),
			self::R_QUOTES_BD  => array(
				'id'       => self::R_QUOTES_BD,
				'label'    => 'Περιεχόμενο εισαγωγικών bold',
				'scope'    => 'text',
				'order'    => 31,
				'callback' => array( __CLASS__, 'cb_quotes_bold' ),
			),
			self::R_PARENS_IT  => array(
				'id'       => self::R_PARENS_IT,
				'label'    => 'Περιεχόμενο παρενθέσεων italic',
				'scope'    => 'text',
				'order'    => 40,
				'callback' => array( __CLASS__, 'cb_parens_italic' ),
			),
			self::R_NUMBERS_BD => array(
				'id'       => self::R_NUMBERS_BD,
				'label'    => 'Αριθμοί bold',
				'scope'    => 'text',
				'order'    => 50,
				'callback' => array( __CLASS__, 'cb_numbers_bold' ),
			),
			self::R_ALL_ITALIC => array(
				'id'       => self::R_ALL_ITALIC,
				'label'    => 'Όλο το κείμενο italic',
				'scope'    => 'text',
				'order'    => 90,
				'callback' => array( __CLASS__, 'cb_all_italic' ),
			),
			self::R_ALL_BOLD   => array(
				'id'       => self::R_ALL_BOLD,
				'label'    => 'Όλο το κείμενο bold',
				'scope'    => 'text',
				'order'    => 91,
				'callback' => array( __CLASS__, 'cb_all_bold' ),
			),
		);
	}

	/** Ενεργά text rules σε σειρά order (helper για το SF_Engine). */
	public static function text_rules( array $enabled ): array {
		$out = array();
		foreach ( self::registry() as $id => $def ) {
			if ( 'text' === $def['scope'] && in_array( $id, $enabled, true ) ) {
				$out[ $def['order'] ] = $def;
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/** Ενεργά document rules σε σειρά order (helper για το SF_Engine). */
	public static function document_rules( array $enabled ): array {
		$out = array();
		foreach ( self::registry() as $id => $def ) {
			if ( 'document' === $def['scope'] && in_array( $id, $enabled, true ) ) {
				$out[ $def['order'] ] = $def;
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Εφαρμογή ΟΛΩΝ των ενεργών κανόνων σε ένα text segment, με
	 * protection των excluded φράσεων και των tags που παράγουν οι
	 * προηγούμενοι κανόνες (τα <strong>/<em> χωρίς attributes είναι
	 * αδύνατο να μπερδέψουν τα regex των επόμενων — αλλά τα excluded
	 * προστατεύονται ρητά σε ΚΑΘΕ κανόνα ξεχωριστά).
	 */
	public static function apply_text( string $text, array $enabled, array $ctx = array() ): string {

		$rules = self::text_rules( $enabled );
		if ( empty( $rules ) ) {
			return $text;
		}

		foreach ( $rules as $rule ) {
			$text = self::with_exclusions( $text, $ctx['excluded'] ?? array(), $rule['callback'] );
		}

		return $text;
	}

	/**
	 * Protection μηχανισμός: Οι φράσεις του exclusion list
	 * αντικαθίστανται με μοναδικά placeholders (\x00N\x00, ώστε
	 * ούτε κανένα regex να τις φτάσει), τρέχει το callback, γίνεται
	 * restore. Τα control chars \x00 δεν υπάρχουν ΠΟΤΕ σε
	 * legitimate περιεχόμενο (WordPress τα φιλτράρει) — safe marker.
	 */
	public static function with_exclusions( string $text, array $excluded, callable $cb ): string {

		if ( empty( $excluded ) ) {
			return $cb( $text );
		}

		$placeholders = array();
		$i            = 0;

		// Μεγαλύτερες φράσεις πρώτα — ώστε «The Pleiades and the
		// Morning Star» να μην κοπεί από το «Morning Star».
		$excluded = array_values( array_unique( array_filter( array_map( 'strval', $excluded ) ) ) );
		usort( $excluded, static function ( $a, $b ) {
			return mb_strlen( $b ) <=> mb_strlen( $a );
		} );

		foreach ( $excluded as $phrase ) {
			if ( '' !== $phrase && false !== mb_strpos( $text, $phrase ) ) {
				$ph          = "\x00SF" . self::num_alpha( $i ) . "\x00";
				$text        = str_replace( $phrase, $ph, $text );
				$placeholders[ $ph ] = $phrase;
				$i++;
			}
		}

		$text = $cb( $text );

		return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $text );
	}

	/** Letters-only encoding (a, b … z, aa, ab …) — markers χωρίς ψηφία, ώστε κανένα rule regex (\d+) να μην τα αγγίζει. */
	private static function num_alpha( int $n ): string {
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
	 * Πλήρης αφαίρεση μορφοποίησης: πετάει τα (opening/closing) tags
	 * της STRIP_TAGS list, ΚΡΑΤΩΝΕΙ το περιεχόμενο. Τρέχει ΠΡΙΝ την
	 * tokenization — είναι ο μόνος κανόνας που επιτρέπεται να
	 * «αντιμετωπίζει» markup. Attributes δεν απομένουν (ολόκληρο το
	 * tag σβήνεται), οπότε δεν υπάρχει attribute-injection επιφάνεια.
	 */
	public static function cb_strip( string $html ): string {
		return preg_replace( '~</?(?:' . self::STRIP_TAGS . ')[^>]*>~i', '', $html );
	}

	/* =====================================================================
	 * Callbacks — text scope (input = καθαρό text segment, ΟΧΙ tags)
	 * =================================================================== */

	/**
	 * Κανονικοποίηση κενών (συντηρητικό, safe by design):
	 *  - \r\n / \r → \n
	 *  - Πολλαπλά spaces/tabs σε ένα
	 *  - Trailing spaces στο τέλος κάθε γραμμής
	 *  - 3+ κενές γραμμές → 2
	 *  - Κενό ΠΡΙΝ από σημεία στίξης έξω (συχνό λάθος «κείμενο , κείμενο»)
	 * ΔΕΝ προσθέτει κενά (κίνδυνος σε URLs, entitites, συντομογραφίες).
	 */
	public static function cb_ws( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '~[ \t]{2,}~', ' ', $text );
		$text = preg_replace( '~[ \t]+$~m', '', $text );
		$text = preg_replace( '~\n{3,}~', "\n\n", $text );
		$text = preg_replace( '~[ \t]+([,.!?;:])~u', '$1', $text );
		return $text;
	}

	/**
	 * Περικλείει ΟΛΟ το quoted segment (μαζί με τα εισαγωγικά) σε
	 * <em> ή <strong>. Υποστήριξη: ελληνικά guillemets «», curly
	 * “”, straight "" — δεν σπάει σε nesting (η [^»] δεν ταιριάζει
	 * το closing mark, δεν υπάρχουν tags στο input).
	 */
	public static function cb_quotes_italic( string $text ): string {
		return self::wrap_quotes( $text, 'em' );
	}

	public static function cb_quotes_bold( string $text ): string {
		return self::wrap_quotes( $text, 'strong' );
	}

	/** Κοινή υλοποίηση quoted-wrap (τα τρία στυλ εισαγωγικών). */
	private static function wrap_quotes( string $text, string $tag ): string {
		$text = preg_replace( '~«([^»]+)»~u', '<' . $tag . '>«$1»</' . $tag . '>', $text );
		$text = preg_replace( '~“([^”]+)”~u', '<' . $tag . '>“$1”</' . $tag . '>', $text );
		$text = preg_replace( '~"([^"]+)"~', '<' . $tag . '>"$1"</' . $tag . '>', $text );
		return $text;
	}

	/**
	 * Περιεχόμενο παρενθέσεων italic — περιλαμβάνει και τα παρενθέσεις
	 * στο wrap (πιο ομοιόμορφο οπτικά απ' ό,τι μόνο το εσωτερικό).
	 * Nesting παρενθέσεων ΔΕΝ υποστηρίζεται (ΣΚΟΠΙΜΟ, Wave 1 — το
	 * [^)] σταματά στο πρώτο ')' — απρόβλεπτα deep-nest περιεχόμενα
	 * είναι έξω scope των περιγραφών προϊόντων).
	 */
	public static function cb_parens_italic( string $text ): string {
		return preg_replace( '~\(([^()\n]{1,300})\)~u', '<em>($1)</em>', $text );
	}

	/**
	 * Αριθμοί bold — ακέραιοι και δεκαδικοί (κόμμα ή τελεία, π.χ.
	 * «σελίδες 320» → bold 320). Το input είναι καθαρό text segment:
	 * καμία πιθανότητα να πιάσει digits μέσα σε attributes/entities.
	 */
	public static function cb_numbers_bold( string $text ): string {
		return preg_replace( '~\d+(?:[.,]\d+)*~u', '<strong>$0</strong>', $text );
	}

	/**
	 * Whole-segment wraps. ΠΡΟΣΟΧΗ στα semantics: τρέχουν ΤΕΛΕΥΤΑΙΑ
	 * (order 90/91), οπότε περικλείουν και tags προηγούμενων κανόνων
	 * → <em><strong>123</strong></em> = έγκυρο nesting. Κενό segment
	 * (whitespace-only, π.χ. newline μεταξύ <p>) ΔΕΝ τυλίγεται —
	 * αφήνουμε το whitespace απ' έξω.
	 */
	public static function cb_all_italic( string $text ): string {
		return self::wrap_if_content( $text, 'em' );
	}

	public static function cb_all_bold( string $text ): string {
		return self::wrap_if_content( $text, 'strong' );
	}

	/** Wrap μόνο αν υπάρχει μη-whitespace περιεχόμενο. */
	private static function wrap_if_content( string $text, string $tag ): string {
		if ( '' === trim( $text ) ) {
			return $text;
		}
		return '<' . $tag . '>' . $text . '</' . $tag . '>';
	}

}