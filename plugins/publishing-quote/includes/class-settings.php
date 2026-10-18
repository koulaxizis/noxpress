<?php
/**
 * PBQ_Settings — the pbq_settings option and its strict validation
 * (Bible §11: whitelists; unknown keys dropped, bad values → defaults).
 *
 * Money is stored in euros (or the shop currency) as floats with up to 4
 * decimals and never negative: a paper rate per page can be 0.012. All
 * amounts are net (without VAT); every service has its own VAT rate, or
 * -1 for the general one.
 *
 * Groups of keys:
 *  - general   mode (test | live | off), lang (auto | el | en), vat (%),
 *              show (incl | excl), step (rounding step in cents: 1, 10,
 *              100, 500, 1000), round (up | near), valid (days, 0 = no
 *              date), email, ack, keep (months, 0 = forever), wipe, cur_*
 *              (currency without WooCommerce), privacy
 *  - editing   ed_on, ed_unit (pages | words), ed_wpp (words per manuscript
 *              page), ed_max, ed_min, ed_vat, levels[]
 *  - cover     cv_on, cv_price, cv_hard (extra with a hard binding), cv_vat
 *  - illus     il_on, il_bw, il_color, il_max, il_min, il_vat, il_tiers[]
 *  - printing  pr_on, pr_setup, pr_cover (cover print per copy), pr_color
 *              (factor for a colour body), pr_color_on, pr_front (pages
 *              outside the text), pr_run_min / max / def, pr_min, pr_vat,
 *              pr_tiers[]
 *  - typeset   ts_fixed, ts_page, ts_mode (prop | editing | cover | illus |
 *              print), ts_need[] (services that need it)
 *  - templates sizes[], papers[], bindings[], finishes[], extras[]
 *  - texts     t_<key>_el / t_<key>_en (Bible §14.17), keys in texts()
 *
 * Not autoloaded: only pages with the calculator, the REST routes and the
 * admin read it.
 */

defined( 'ABSPATH' ) || exit;

final class PBQ_Settings {

	const OPT = 'pbq_settings';

	/** Core services, in the order of the result. */
	const SERVICES = array( 'editing', 'cover', 'illus', 'print' );

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Visitor texts: key => array( default EL, default EN, max length ).
	 * Plain text (sanitize_text_field), never msgids (Bible §14.17).
	 */
	public static function texts(): array {
		return array(
			// Form.
			'title'       => array( 'Κοστολόγηση έκδοσης', 'Publishing quote', 120 ),
			'intro'       => array( 'Διάλεξε τι χρειάζεται το βιβλίο σου και δες αμέσως ένα ενδεικτικό κόστος.', 'Pick what your book needs and see an estimated cost right away.', 400 ),
			'sec_book'    => array( 'Το βιβλίο', 'Your book', 80 ),
			'size'        => array( 'Μέγεθος', 'Size', 80 ),
			'ms_pages'    => array( 'Σελίδες χειρογράφου (A4)', 'Manuscript pages (A4)', 80 ),
			'ms_words'    => array( 'Λέξεις του κειμένου', 'Words in the text', 80 ),
			'book_pages'  => array( 'Σελίδες βιβλίου, αν τις ξέρεις', 'Book pages, if you know them', 80 ),
			'want_edit'   => array( 'Θέλω επιμέλεια του κειμένου', 'I want my text edited', 120 ),
			'level'       => array( 'Είδος επιμέλειας', 'Type of editing', 80 ),
			'sec_design'  => array( 'Εξώφυλλο και εικονογράφηση', 'Cover and illustrations', 80 ),
			'want_cover'  => array( 'Σχεδιασμός εξωφύλλου', 'Cover design', 120 ),
			'want_illus'  => array( 'Εσωτερική εικονογράφηση', 'Interior illustrations', 120 ),
			'illus_count' => array( 'Πόσα σχέδια', 'How many drawings', 80 ),
			'colour'      => array( 'Έγχρωμα', 'In colour', 40 ),
			'bw'          => array( 'Ασπρόμαυρα', 'Black and white', 40 ),
			'sec_print'   => array( 'Εκτύπωση', 'Printing', 80 ),
			'want_print'  => array( 'Θέλω εκτύπωση', 'I want it printed', 120 ),
			'run'         => array( 'Τιράζ (αντίτυπα)', 'Print run (copies)', 80 ),
			'paper'       => array( 'Χαρτί σώματος', 'Paper', 80 ),
			'body'        => array( 'Χρώμα σελίδων', 'Page colour', 80 ),
			'body_bw'     => array( 'Ασπρόμαυρες', 'Black and white', 40 ),
			'body_colour' => array( 'Έγχρωμες', 'Colour', 40 ),
			'binding'     => array( 'Δέσιμο', 'Binding', 80 ),
			'finish'      => array( 'Φινίρισμα εξωφύλλου', 'Cover finish', 80 ),
			'sec_extra'   => array( 'Έξτρα παροχές', 'Extras', 80 ),
			'qty'         => array( 'Ποσότητα', 'Quantity', 40 ),
			'calc'        => array( 'Υπολογισμός', 'Calculate', 40 ),
			// Result.
			'result'      => array( 'Ενδεικτικό κόστος', 'Estimated cost', 80 ),
			'l_editing'   => array( 'Επιμέλεια', 'Editing', 80 ),
			'l_cover'     => array( 'Σχεδιασμός εξωφύλλου', 'Cover design', 80 ),
			'l_illus'     => array( 'Εικονογράφηση', 'Illustrations', 80 ),
			'l_print'     => array( 'Εκτύπωση', 'Printing', 80 ),
			'typeset'     => array( 'Σελιδοποίηση', 'Typesetting', 80 ),
			'included'    => array( 'Περιλαμβάνεται', 'Included', 40 ),
			'ask'         => array( 'Κατόπιν συζήτησης', 'On request', 40 ),
			'total'       => array( 'Σύνολο', 'Total', 40 ),
			'net'         => array( 'Καθαρό ποσό', 'Net amount', 40 ),
			'vat_line'    => array( 'ΦΠΑ {rate}', 'VAT {rate}', 40 ),
			'per_copy'    => array( 'Ενδεικτικά ανά αντίτυπο', 'About per copy', 80 ),
			'vat_incl'    => array( 'Οι τιμές περιλαμβάνουν ΦΠΑ.', 'Prices include VAT.', 120 ),
			'vat_excl'    => array( 'Οι τιμές δεν περιλαμβάνουν ΦΠΑ.', 'Prices do not include VAT.', 120 ),
			'validity'    => array( 'Ενδεικτική τιμή, ισχύει έως {date}.', 'Estimated price, valid until {date}.', 160 ),
			'disclaimer'  => array( 'Η τελική προσφορά δίνεται μετά την αξιολόγηση του κειμένου.', 'The final offer follows a review of your text.', 400 ),
			'empty'       => array( 'Διάλεξε τουλάχιστον μία υπηρεσία.', 'Pick at least one service.', 160 ),
			// Contact form.
			'interest'    => array( 'Ενδιαφέρομαι', 'I am interested', 40 ),
			'f_intro'     => array( 'Άφησε τα στοιχεία σου και θα σου στείλουμε την προσφορά.', 'Leave your details and we will send you the offer.', 400 ),
			'f_name'      => array( 'Όνομα', 'Name', 80 ),
			'f_email'     => array( 'Email', 'Email', 80 ),
			'f_phone'     => array( 'Τηλέφωνο (προαιρετικό)', 'Phone (optional)', 80 ),
			'f_book'      => array( 'Τίτλος ή είδος του βιβλίου (προαιρετικό)', 'Title or kind of book (optional)', 120 ),
			'f_msg'       => array( 'Μήνυμα (προαιρετικό)', 'Message (optional)', 80 ),
			'f_consent'   => array( 'Συμφωνώ να χρησιμοποιηθούν τα στοιχεία μου για την προσφορά.', 'I agree that my details are used for the offer.', 300 ),
			'f_privacy'   => array( 'Πολιτική απορρήτου', 'Privacy policy', 80 ),
			'f_send'      => array( 'Αποστολή', 'Send', 40 ),
			'thanks'      => array( 'Ευχαριστούμε! Λάβαμε το αίτημά σου και θα σου στείλουμε την προσφορά σύντομα.', 'Thank you! We received your request and will send you the offer soon.', 400 ),
			// Messages.
			'err_input'   => array( 'Έλεγξε τα πεδία που έχουν σημειωθεί.', 'Please check the marked fields.', 200 ),
			'err_pages'   => array( 'Αυτό το δέσιμο δέχεται από {min} έως {max} σελίδες.', 'This binding takes {min} to {max} pages.', 200 ),
			'err_contact' => array( 'Συμπλήρωσε όνομα, ένα σωστό email και τη συναίνεση.', 'Please fill in your name, a valid email and the consent.', 200 ),
			'err_limit'   => array( 'Πολλά αιτήματα από αυτή τη σύνδεση. Δοκίμασε ξανά σε λίγο.', 'Too many requests from this connection. Please try again later.', 200 ),
			'err_generic' => array( 'Κάτι πήγε στραβά. Δοκίμασε ξανά.', 'Something went wrong. Please try again.', 200 ),
			// E-mail to the visitor (no amounts).
			'ack_subject' => array( 'Λάβαμε το αίτημά σου', 'We received your request', 160 ),
			'ack_body'    => array( 'Γεια σου {name}, λάβαμε το αίτημά σου για προσφορά έκδοσης. Θα το δούμε και θα σου στείλουμε την προσφορά σύντομα. {site}', 'Hello {name}, we received your request for a publishing offer. We will look at it and send you the offer soon. {site}', 1000 ),
		);
	}

	/** Row fields of each list: field => type. */
	public static function lists(): array {
		return array(
			'levels'   => array( 'el' => 'text', 'en' => 'text', 'rate' => 'money' ),
			'sizes'    => array( 'el' => 'text', 'en' => 'text', 'factor' => 'factor', 'wpp' => 'int', 'sig' => 'int' ),
			'papers'   => array( 'el' => 'text', 'en' => 'text', 'page' => 'money' ),
			'bindings' => array( 'el' => 'text', 'en' => 'text', 'copy' => 'money', 'setup' => 'money', 'pmin' => 'int', 'pmax' => 'int', 'hard' => 'bool' ),
			'finishes' => array( 'el' => 'text', 'en' => 'text', 'kind' => 'kind_f', 'copy' => 'money', 'setup' => 'money', 'hard' => 'bool' ),
			'extras'   => array( 'el' => 'text', 'en' => 'text', 'd_el' => 'long', 'd_en' => 'long', 'kind' => 'kind_e', 'price' => 'money', 'min' => 'money', 'vat' => 'vat', 'ask' => 'bool', 'qmax' => 'int' ),
		);
	}

	/** Default rows: names only, every price 0 (the business sets them). */
	private static function default_lists(): array {
		$row = static function ( string $id, string $el, string $en, array $more = array() ): array {
			return array_merge(
				array(
					'id' => $id,
					'on' => true,
					'el' => $el,
					'en' => $en,
				),
				$more
			);
		};
		return array(
			'levels'   => array(
				$row( 'std', 'Επιμέλεια κειμένου', 'Editing', array( 'rate' => 0.0 ) ),
			),
			'sizes'    => array(
				$row( 's1421', '14 × 21 cm', '14 × 21 cm', array( 'factor' => 1.0, 'wpp' => 300, 'sig' => 16 ) ),
				$row( 's1724', '17 × 24 cm', '17 × 24 cm', array( 'factor' => 1.0, 'wpp' => 420, 'sig' => 16 ) ),
				$row( 's1219', '12 × 19 cm', '12 × 19 cm', array( 'factor' => 1.0, 'wpp' => 230, 'sig' => 16 ) ),
			),
			'papers'   => array(
				$row( 'munken', 'Munken Premium Cream 80 gr', 'Munken Premium Cream 80 gsm', array( 'page' => 0.0 ) ),
				$row( 'offset', 'Λευκό offset 90 gr', 'White offset 90 gsm', array( 'page' => 0.0 ) ),
				$row( 'velvet', 'Velvet 115 gr', 'Velvet 115 gsm', array( 'page' => 0.0 ) ),
			),
			'bindings' => array(
				$row( 'soft', 'Μαλακό (θερμοκόλληση)', 'Paperback (perfect bound)', array( 'copy' => 0.0, 'setup' => 0.0, 'pmin' => 0, 'pmax' => 0, 'hard' => false ) ),
				$row( 'hard', 'Σκληρό (ραφτό)', 'Hardcover (sewn)', array( 'copy' => 0.0, 'setup' => 0.0, 'pmin' => 0, 'pmax' => 0, 'hard' => true ) ),
			),
			'finishes' => array(
				$row( 'matte', 'Ματ πλαστικοποίηση', 'Matte lamination', array( 'kind' => 'one', 'copy' => 0.0, 'setup' => 0.0, 'hard' => false ) ),
				$row( 'gloss', 'Γυαλιστερή πλαστικοποίηση', 'Gloss lamination', array( 'kind' => 'one', 'copy' => 0.0, 'setup' => 0.0, 'hard' => false ) ),
				$row( 'soft', 'Soft touch', 'Soft touch', array( 'kind' => 'one', 'copy' => 0.0, 'setup' => 0.0, 'hard' => false ) ),
				$row( 'flaps', 'Πτερύγια (αυτιά)', 'Flaps', array( 'kind' => 'add', 'copy' => 0.0, 'setup' => 0.0, 'hard' => false ) ),
				$row( 'uv', 'Επιλεκτικό βερνίκι UV', 'Spot UV', array( 'kind' => 'add', 'copy' => 0.0, 'setup' => 0.0, 'hard' => false ) ),
				$row( 'jacket', 'Κουβερτούρα', 'Dust jacket', array( 'kind' => 'add', 'copy' => 0.0, 'setup' => 0.0, 'hard' => true ) ),
			),
			'extras'   => array(
				$row( 'launch', 'Οργάνωση παρουσίασης', 'Book launch event', self::extra( 'fixed', false ) ),
				$row( 'ads', 'Διαφημιστική καμπάνια', 'Advertising campaign', self::extra( 'fixed', true ) ),
				$row( 'trailer', 'Book trailer', 'Book trailer', self::extra( 'fixed', false ) ),
				$row( 'bookmark', 'Σελιδοδείκτες με σχέδιο, ένας σε κάθε αντίτυπο', 'Custom bookmarks, one in every copy', self::extra( 'copy', false ) ),
				$row( 'tshirt', 'T-shirt με σχέδιο του βιβλίου', 'T-shirt with the book\'s art', self::extra( 'qty', false ) ),
				$row( 'isbn', 'ISBN και νόμιμη κατάθεση', 'ISBN and legal deposit', self::extra( 'fixed', false ) ),
				$row( 'epub', 'Ηλεκτρονικό βιβλίο (EPUB)', 'E-book (EPUB)', self::extra( 'fixed', false ) ),
				$row( 'translate', 'Μετάφραση', 'Translation', self::extra( 'words', true ) ),
				$row( 'ship', 'Αποστολή των αντιτύπων', 'Shipping the copies', self::extra( 'fixed', true ) ),
				$row( 'stores', 'Διανομή σε βιβλιοπωλεία', 'Distribution to bookshops', self::extra( 'fixed', true ) ),
				$row( 'photo', 'Φωτογράφιση συγγραφέα', 'Author photo shoot', self::extra( 'fixed', false ) ),
			),
		);
	}

	private static function extra( string $kind, bool $ask ): array {
		return array(
			'on'    => false,
			'd_el'  => '',
			'd_en'  => '',
			'kind'  => $kind,
			'price' => 0.0,
			'min'   => 0.0,
			'vat'   => -1.0,
			'ask'   => $ask,
			'qmax'  => 1000,
		);
	}

	public static function defaults(): array {
		$d = array(
			'mode'        => 'test',
			'lang'        => 'auto',
			'vat'         => 24.0,
			'show'        => 'incl',
			'step'        => 100,
			'round'       => 'up',
			'valid'       => 30,
			'email'       => '',
			'ack'         => true,
			'keep'        => 24,
			'wipe'        => false,
			'privacy'     => true,
			'cur_sym'     => '€',
			'cur_pos'     => 'right_space',
			'cur_dec'     => ',',
			'cur_thou'    => '.',

			'ed_on'       => true,
			'ed_unit'     => 'pages',
			'ed_wpp'      => 250,
			'ed_max'      => 2000,
			'ed_min'      => 0.0,
			'ed_vat'      => -1.0,

			'cv_on'       => true,
			'cv_price'    => 0.0,
			'cv_hard'     => 0.0,
			'cv_vat'      => -1.0,

			'il_on'       => true,
			'il_bw'       => 0.0,
			'il_color'    => 0.0,
			'il_max'      => 200,
			'il_min'      => 0.0,
			'il_vat'      => -1.0,
			'il_tiers'    => array(),

			'pr_on'       => true,
			'pr_setup'    => 0.0,
			'pr_cover'    => 0.0,
			'pr_color'    => 1.0,
			'pr_color_on' => true,
			'pr_front'    => 0,
			'pr_run_min'  => 1,
			'pr_run_max'  => 10000,
			'pr_run_def'  => 100,
			'pr_min'      => 0.0,
			'pr_vat'      => -1.0,
			'pr_tiers'    => array(),

			'ts_fixed'    => 0.0,
			'ts_page'     => 0.0,
			'ts_mode'     => 'prop',
			'ts_need'     => array( 'editing', 'print' ),
		);
		$d = array_merge( $d, self::default_lists() );
		foreach ( self::texts() as $k => $t ) {
			$d[ 't_' . $k . '_el' ] = $t[0];
			$d[ 't_' . $k . '_en' ] = $t[1];
		}
		return $d;
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, null );
			self::$cache = is_array( $raw ) ? self::sanitize( $raw ) : self::defaults();
		}
		return self::$cache;
	}

	public static function save( array $raw ): void {
		$clean = self::sanitize( $raw );
		update_option( self::OPT, $clean, false );
		self::$cache = $clean;
	}

	/** Drops the per-request cache (tests, import). */
	public static function flush(): void {
		self::$cache = null;
	}

	/* =====================================================================
	 * Validation
	 * =================================================================== */

	/** A non-negative amount with up to 4 decimals, capped. */
	public static function money( $v ): float {
		if ( is_string( $v ) ) {
			$v = str_replace( array( ' ', "\xC2\xA0" ), '', $v );
			// "1.234,56" or "1234,56" → 1234.56; "1234.56" stays.
			if ( false !== strpos( $v, ',' ) ) {
				$v = str_replace( '.', '', $v );
				$v = str_replace( ',', '.', $v );
			}
		}
		if ( ! is_numeric( $v ) ) {
			return 0.0;
		}
		$f = (float) $v;
		if ( ! is_finite( $f ) || $f < 0 ) {
			return 0.0;
		}
		return round( min( $f, 10000000.0 ), 4 );
	}

	private static function factor( $v, float $def = 1.0 ): float {
		$f = self::money( $v );
		return $f > 0 ? min( $f, 100.0 ) : $def;
	}

	private static function int( $v, int $min, int $max, int $def ): int {
		if ( ! is_numeric( $v ) ) {
			return $def;
		}
		return max( $min, min( $max, (int) $v ) );
	}

	/** A VAT rate 0–100, or -1 (= the general rate) when $inherit. */
	private static function vat( $v, bool $inherit, float $def ): float {
		if ( is_string( $v ) ) {
			$v = trim( str_replace( ',', '.', $v ) );
		}
		if ( $inherit && ( null === $v || '' === $v || ( is_numeric( $v ) && (float) $v < 0 ) ) ) {
			return -1.0;
		}
		if ( ! is_numeric( $v ) ) {
			return $def;
		}
		return round( max( 0.0, min( (float) $v, 100.0 ) ), 2 );
	}

	private static function str( $v, int $max ): string {
		return is_scalar( $v ) ? mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, $max ) : '';
	}

	private static function choice( array $raw, string $key, array $allowed, $def ) {
		return isset( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $def;
	}

	/** Tier rows: from (count) => discount %, sorted, at most 20. */
	private static function tiers( $rows ): array {
		$out = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['from'] ) || '' === trim( (string) $r['from'] ) ) {
				continue;
			}
			$from = self::int( $r['from'], 1, 10000000, 0 );
			$pct  = isset( $r['pct'] ) ? round( min( self::money( $r['pct'] ), 90.0 ), 2 ) : 0.0;
			if ( $from > 0 ) {
				$out[ $from ] = array(
					'from' => $from,
					'pct'  => $pct,
				);
			}
		}
		ksort( $out );
		return array_slice( array_values( $out ), 0, 20 );
	}

	/** A list of template rows, by lists() schema; ids kept or generated. */
	private static function rows( $rows, string $list ): array {
		$schema = self::lists()[ $list ];
		$out    = array();
		$seen   = array();
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $key => $r ) {
			if ( ! is_array( $r ) || ! empty( $r['del'] ) ) {
				continue;
			}
			$el = self::str( isset( $r['el'] ) ? $r['el'] : '', 120 );
			if ( '' === $el ) {
				continue;
			}
			$id = isset( $r['id'] ) && is_string( $r['id'] ) ? strtolower( $r['id'] ) : '';
			if ( ! preg_match( '/^[a-z0-9]{1,16}$/', $id ) || isset( $seen[ $id ] ) ) {
				$id = 'r' . substr( md5( $list . '|' . $el . '|' . $key . '|' . count( $out ) ), 0, 10 );
			}
			$seen[ $id ] = true;
			$row         = array(
				'id' => $id,
				'on' => ! empty( $r['on'] ),
			);
			foreach ( $schema as $f => $type ) {
				$v = isset( $r[ $f ] ) ? $r[ $f ] : null;
				switch ( $type ) {
					case 'text':
						$row[ $f ] = 'el' === $f ? $el : self::str( $v, 120 );
						break;
					case 'long':
						$row[ $f ] = self::str( $v, 300 );
						break;
					case 'money':
						$row[ $f ] = self::money( $v );
						break;
					case 'factor':
						$row[ $f ] = self::factor( $v );
						break;
					case 'vat':
						$row[ $f ] = self::vat( $v, true, -1.0 );
						break;
					case 'bool':
						$row[ $f ] = ! empty( $v );
						break;
					case 'kind_f':
						$row[ $f ] = in_array( $v, array( 'one', 'add' ), true ) ? $v : 'one';
						break;
					case 'kind_e':
						$row[ $f ] = in_array( $v, array( 'fixed', 'qty', 'copy', 'words' ), true ) ? $v : 'fixed';
						break;
					case 'int':
						if ( 'wpp' === $f ) {
							$row[ $f ] = self::int( $v, 50, 2000, 300 );
						} elseif ( 'sig' === $f ) {
							$row[ $f ] = self::int( $v, 1, 64, 16 );
						} elseif ( 'qmax' === $f ) {
							$row[ $f ] = self::int( $v, 1, 100000, 1000 );
						} else {
							$row[ $f ] = self::int( $v, 0, 100000, 0 );
						}
						break;
				}
			}
			$out[] = $row;
			if ( count( $out ) >= 50 ) {
				break;
			}
		}
		return $out;
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode']  = self::choice( $raw, 'mode', array( 'test', 'live', 'off' ), $d['mode'] );
		$s['lang']  = self::choice( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );
		$s['vat']   = isset( $raw['vat'] ) ? self::vat( $raw['vat'], false, $d['vat'] ) : $d['vat'];
		$s['show']  = self::choice( $raw, 'show', array( 'incl', 'excl' ), $d['show'] );
		$s['step']  = isset( $raw['step'] ) && in_array( (int) $raw['step'], array( 1, 10, 100, 500, 1000 ), true ) ? (int) $raw['step'] : $d['step'];
		$s['round'] = self::choice( $raw, 'round', array( 'up', 'near' ), $d['round'] );
		$s['valid'] = isset( $raw['valid'] ) ? self::int( $raw['valid'], 0, 365, $d['valid'] ) : $d['valid'];
		$email      = isset( $raw['email'] ) && is_string( $raw['email'] ) ? sanitize_email( $raw['email'] ) : '';
		$s['email'] = is_email( $email ) ? $email : '';
		$s['keep']  = isset( $raw['keep'] ) ? self::int( $raw['keep'], 0, 120, $d['keep'] ) : $d['keep'];
		foreach ( array( 'ack', 'wipe', 'privacy' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		$sym           = isset( $raw['cur_sym'] ) ? self::str( $raw['cur_sym'], 8 ) : $d['cur_sym'];
		$s['cur_sym']  = '' !== $sym ? $sym : $d['cur_sym'];
		$s['cur_pos']  = self::choice( $raw, 'cur_pos', array( 'left', 'right', 'left_space', 'right_space' ), $d['cur_pos'] );
		$s['cur_dec']  = self::choice( $raw, 'cur_dec', array( ',', '.' ), $d['cur_dec'] );
		$s['cur_thou'] = self::choice( $raw, 'cur_thou', array( '.', ',', ' ', '' ), $d['cur_thou'] );
		if ( $s['cur_thou'] === $s['cur_dec'] ) {
			$s['cur_thou'] = ',' === $s['cur_dec'] ? '.' : ',';
		}

		// Services.
		foreach ( array( 'ed_on', 'cv_on', 'il_on', 'pr_on', 'pr_color_on' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		$s['ed_unit'] = self::choice( $raw, 'ed_unit', array( 'pages', 'words' ), $d['ed_unit'] );
		$s['ed_wpp']  = isset( $raw['ed_wpp'] ) ? self::int( $raw['ed_wpp'], 50, 2000, $d['ed_wpp'] ) : $d['ed_wpp'];
		$s['ed_max']  = isset( $raw['ed_max'] ) ? self::int( $raw['ed_max'], 1, 20000, $d['ed_max'] ) : $d['ed_max'];
		$s['il_max']  = isset( $raw['il_max'] ) ? self::int( $raw['il_max'], 1, 5000, $d['il_max'] ) : $d['il_max'];
		$s['pr_front'] = isset( $raw['pr_front'] ) ? self::int( $raw['pr_front'], 0, 200, $d['pr_front'] ) : $d['pr_front'];
		$s['pr_run_min'] = isset( $raw['pr_run_min'] ) ? self::int( $raw['pr_run_min'], 1, 1000000, $d['pr_run_min'] ) : $d['pr_run_min'];
		$s['pr_run_max'] = isset( $raw['pr_run_max'] ) ? self::int( $raw['pr_run_max'], $s['pr_run_min'], 1000000, $d['pr_run_max'] ) : max( $d['pr_run_max'], $s['pr_run_min'] );
		$s['pr_run_def'] = isset( $raw['pr_run_def'] ) ? self::int( $raw['pr_run_def'], $s['pr_run_min'], $s['pr_run_max'], $d['pr_run_def'] ) : max( $s['pr_run_min'], min( $s['pr_run_max'], $d['pr_run_def'] ) );
		foreach ( array( 'ed_min', 'cv_price', 'cv_hard', 'il_bw', 'il_color', 'il_min', 'pr_setup', 'pr_cover', 'pr_min', 'ts_fixed', 'ts_page' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? self::money( $raw[ $k ] ) : $d[ $k ];
		}
		$s['pr_color'] = isset( $raw['pr_color'] ) ? self::factor( $raw['pr_color'] ) : $d['pr_color'];
		foreach ( array( 'ed_vat', 'cv_vat', 'il_vat', 'pr_vat' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? self::vat( $raw[ $k ], true, -1.0 ) : $d[ $k ];
		}
		$s['il_tiers'] = isset( $raw['il_tiers'] ) ? self::tiers( $raw['il_tiers'] ) : $d['il_tiers'];
		$s['pr_tiers'] = isset( $raw['pr_tiers'] ) ? self::tiers( $raw['pr_tiers'] ) : $d['pr_tiers'];
		$s['ts_mode']  = self::choice( $raw, 'ts_mode', array_merge( array( 'prop' ), self::SERVICES ), $d['ts_mode'] );
		$need          = isset( $raw['ts_need'] ) && is_array( $raw['ts_need'] ) ? $raw['ts_need'] : ( isset( $raw['ts_need'] ) ? array() : $d['ts_need'] );
		$s['ts_need']  = array_values( array_intersect( self::SERVICES, $need ) );

		// Templates.
		foreach ( array_keys( self::lists() ) as $list ) {
			$s[ $list ] = isset( $raw[ $list ] ) ? self::rows( $raw[ $list ], $list ) : $d[ $list ];
		}
		// A calculator needs one level, one size, one paper and one binding.
		foreach ( array( 'levels', 'sizes', 'papers', 'bindings' ) as $list ) {
			if ( ! $s[ $list ] ) {
				$s[ $list ] = $d[ $list ];
			}
		}

		// Visitor texts.
		foreach ( self::texts() as $k => $t ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$f       = 't_' . $k . '_' . $l;
				$v       = isset( $raw[ $f ] ) && is_string( $raw[ $f ] ) ? trim( sanitize_text_field( $raw[ $f ] ) ) : null;
				$s[ $f ] = null === $v ? $d[ $f ] : mb_substr( $v, 0, $t[2] );
			}
			// The Greek text never stays empty, except the optional ones.
			if ( '' === $s[ 't_' . $k . '_el' ] && ! in_array( $k, array( 'intro', 'disclaimer', 'f_intro', 'validity' ), true ) ) {
				$s[ 't_' . $k . '_el' ] = $d[ 't_' . $k . '_el' ];
			}
		}

		return $s;
	}

	/* =====================================================================
	 * Reading
	 * =================================================================== */

	/**
	 * Whether the current visitor sees the calculator: live for everyone,
	 * test for managers only, off (or PBQ_DISABLE) for nobody.
	 */
	public static function visible(): bool {
		if ( Publishing_Quote::killed() ) {
			return false;
		}
		$mode = self::get()['mode'];
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( Publishing_Quote::cap() );
	}

	/** Visitor language: the setting, or the site locale ('auto'). */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		$locale = function_exists( 'determine_locale' ) && ! is_admin() ? determine_locale() : get_locale();
		return PBQ_Lang::from_locale( (string) $locale );
	}

	/**
	 * A visitor text in the visitor language, placeholders filled; an empty
	 * English text shows the Greek one (Bible §14.17).
	 *
	 * @param array $vars {placeholder} => value.
	 */
	public static function text( string $key, string $lang = '', array $vars = array() ): string {
		$s    = self::get();
		$lang = 'el' === $lang || 'en' === $lang ? $lang : self::visitor_lang();
		$v    = isset( $s[ 't_' . $key . '_' . $lang ] ) ? (string) $s[ 't_' . $key . '_' . $lang ] : '';
		if ( '' === $v && 'en' === $lang ) {
			$v = isset( $s[ 't_' . $key . '_el' ] ) ? (string) $s[ 't_' . $key . '_el' ] : '';
		}
		foreach ( $vars as $ph => $val ) {
			$v = str_replace( '{' . $ph . '}', (string) $val, $v );
		}
		/**
		 * Filters a visitor text (e.g. for WPML / Polylang).
		 *
		 * @param string $v    Text.
		 * @param string $key  A key of PBQ_Settings::texts().
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'pbq_text', $v, $key, $lang );
	}

	/** The name of a template row in a language (English falls back to Greek). */
	public static function label( array $row, string $lang, string $prefix = '' ): string {
		$el = isset( $row[ $prefix . 'el' ] ) ? (string) $row[ $prefix . 'el' ] : '';
		$en = isset( $row[ $prefix . 'en' ] ) ? (string) $row[ $prefix . 'en' ] : '';
		return 'en' === $lang && '' !== $en ? $en : $el;
	}

	/** The enabled rows of a list, keyed by id. */
	public static function rows_on( string $list ): array {
		$out = array();
		foreach ( self::get()[ $list ] as $r ) {
			if ( ! empty( $r['on'] ) ) {
				$out[ $r['id'] ] = $r;
			}
		}
		return $out;
	}

	/** The VAT rate of a service setting (-1 → the general rate). */
	public static function vat_of( float $v ): float {
		return $v < 0 ? (float) self::get()['vat'] : $v;
	}

	/* =====================================================================
	 * Money format (WooCommerce's when present)
	 * =================================================================== */

	/** Decimals shown: none when amounts are rounded to whole units. */
	public static function decimals(): int {
		return self::get()['step'] >= 100 ? 0 : 2;
	}

	/** Formatted amount from cents, as plain text (no HTML). */
	public static function format( int $cents, ?int $decimals = null ): string {
		$s        = self::get();
		$decimals = null === $decimals ? self::decimals() : $decimals;
		if ( Publishing_Quote::woo() && function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$sym  = html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
			$dec  = (string) wc_get_price_decimal_separator();
			$thou = (string) wc_get_price_thousand_separator();
			$pos  = (string) get_option( 'woocommerce_currency_pos', 'right_space' );
		} else {
			$sym  = $s['cur_sym'];
			$dec  = $s['cur_dec'];
			$thou = $s['cur_thou'];
			$pos  = $s['cur_pos'];
		}
		$num = number_format( $cents / 100, $decimals, $dec, $thou );
		$nb  = "\xC2\xA0";
		switch ( $pos ) {
			case 'left':
				return $sym . $num;
			case 'left_space':
				return $sym . $nb . $num;
			case 'right':
				return $num . $sym;
			default:
				return $num . $nb . $sym;
		}
	}

	/** A VAT rate for display: 24 → "24%", 6.5 → "6,5%". */
	public static function rate_label( float $rate ): string {
		$dec = Publishing_Quote::woo() && function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : self::get()['cur_dec'];
		$t   = rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' );
		return str_replace( '.', $dec, $t ) . '%';
	}
}
