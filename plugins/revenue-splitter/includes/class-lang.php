<?php
/**
 * Δίγλωσσο UI (Ελληνικά / English) — ανά χρήστη.
 *
 * Μηχανισμός: το WordPress «gettext» filter. Τα ελληνικά strings είναι
 * το msgid σε όλο το plugin (μοναδική πηγή αλήθειας). Όταν ο τρέχων χρήστης
 * έχει επιλέξει «en» στα ρυθμίσεις του plugin, το φίλτρο αντικαθιστά το
 * κείμενο με την αγγλική του εκδοχή. Χωρίς αλλαγές στα υπόλοιπα classes.
 *
 * Επιλογή γλώσσας: user meta 'rs_lang' ('el' | 'en') — αποθηκεύεται
 * από τη σελίδα Ρυθμίσεων του plugin (RS_Admin_UI::render_settings).
 *
 * v1.3.0 FIX (#3): Συμπλήρωμα λεξικού με ΟΛΑ τα strings της v1.2.0/v1.3.0.
 * v1.3.0: Πρόσθετα strings για — hashed portal keys (UI μηνύματα),
 * mini chart, lifetime balance, ΦΠΑ ανά συντελεστή, backup/import,
 * λογιστική δικαιούχων. Καθαρίστηκαν οι διπλές εγγραφές του λεξικού.
 * v1.3.1: Συμπλήρωμα με τα strings του ΝΕΟΥ portal (shortcode
 * [author_portal], login όνομα+κλειδί, rate-limiting, key rotation
 * μέσω transient, μερίδια ανά προϊόν) και του νέου widget/dashboard.
 * v1.3.1 (2nd re-audit #1/#2): Πρόσθετες entries — ledger delete
 * notices, metabox validation suffix, HTML export «Δικαιούχοι» heading,
 * notice prefix «Revenue Splitter:».
 *
 * v1.3.2 CLEANUP (#7): Αφαίρεση ΟΡΦΑΝΩΝ λεξικογραφικών εγγραφών —
 * strings που ΚΑΝΕΝΑ v1.3.x markup δεν παράγει:
 *  - 'Αναζήτηση σε τίτλους…', 'Όλα τα προϊόντα', 'Όλοι οι δικαιούχοι'
 *    (νεκρά search/filter controls — αφαιρεμένα από το markup),
 *  - 'Εξαγωγή CSV', 'Top δικαιούχοι', 'Άνοιγμα Dashboard',
 *    'Καμία πώληση τον τρέχοντα μήνα.', 'Καθαρά κέρδη: %s'
 *    (νεκρό widget/quick-glance legacy markup),
 *  - ολόκληρο το LEGACY portal block (Access Key, [rs_portal] κ.λπ. —
 *    σημειωμένο ήδη από το v1.3.1 ως safe-to-delete· το νέο portal
 *    δεν παράγει ΚΑΝΕΝΑ από αυτά τα strings).
 *
 * v1.3.2 ADD (#3): Νέο entry για το partial-import notice του
 * import_state() — με errors το success notice αντικαθίσταται από
 * ρητό «Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ…».
 *
 * Διατηρήθηκαν τα strings των admin hashed-key μηνυμάτων (v1.3.0):
 * δεν επιβεβαιώθηκε ότι είναι νεκρά σε ΟΛΑ τα paths — αφήνονται
 * έως τον επόμενο πλήρη coverage audit.
 */

defined( 'ABSPATH' ) || exit;

class RS_Lang {

	const USER_META = 'rs_lang';

	/** Cache ανά request — αποφεύγει επαναλαμβανόμενα get_user_meta(). */
	private static $current = null;

	public static function init(): void {
		add_filter( 'gettext', array( __CLASS__, 'filter_gettext' ), 10, 3 );
	}

	/** Η ρητή επιλογή του χρήστη: 'el' | 'en' | 'auto' (default). */
	public static function get_choice( ?int $user_id = null ): string {

		$uid  = $user_id ?? get_current_user_id();
		$lang = $uid ? (string) get_user_meta( $uid, self::USER_META, true ) : '';

		return in_array( $lang, array( 'el', 'en' ), true ) ? $lang : 'auto';
	}

	/**
	 * Η ΕΝΕΡΓΗ γλώσσα ('el' | 'en') — για rendering.
	 *
	 * v1.3.6 (#5): 'auto' (μηδενική επιλογή) ακολουθεί πλέον το
	 * WordPress locale (get_user_locale / get_locale) αντί για
	 * hardcoded 'el': el* → Ελληνικά, οτιδήποτε άλλο → English.
	 */
	public static function get_lang( ?int $user_id = null ): string {

		if ( null !== self::$current && null === $user_id ) {
			return self::$current;
		}

		$lang = self::get_choice( $user_id );

		if ( 'auto' === $lang ) {
			$locale = function_exists( 'get_user_locale' )
				? get_user_locale( $user_id ?? get_current_user_id() )
				: get_locale();

			$lang = ( 0 === strpos( strtolower( (string) $locale ), 'el' ) ) ? 'el' : 'en';
		}

		if ( null === $user_id ) {
			self::$current = $lang;
		}

		return $lang;
	}

	/**
	 * Θέτει τη γλώσσα: 'el' | 'en' | 'auto'.
	 * v1.3.6 (#5): 'auto' = σβήνει το user meta → Follow-WP mode.
	 */
	public static function set_lang( int $user_id, string $lang ): bool {

		// Reset request-cache ώστε η αλλαγή να ισχύσει άμεσα.
		self::$current = null;

		if ( 'auto' === $lang ) {
			return (bool) delete_user_meta( $user_id, self::USER_META );
		}

		$lang = in_array( $lang, array( 'el', 'en' ), true ) ? $lang : 'el';

		return (bool) update_user_meta( $user_id, self::USER_META, $lang );
	}

	/**
	 * Το ίδιο το «μετάφρασμα»: όταν ο χρήστης είναι σε EN mode,
	 * αντικαθιστά τα ελληνικά msgids του domain μας με αγγλικά.
	 */
	public static function filter_gettext( $translation, $text, $domain ) {

		if ( 'revenue-splitter' !== $domain ) {
			return $translation;
		}
		if ( 'en' !== self::get_lang() ) {
			return $translation;
		}
		if ( isset( self::$dict[ $text ] ) ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/**
	 * v1.3.8 (#5): Ημερομηνία σε εμφανιζόμενη μορφή.
	 *
	 * 'el' → ηη/μμ/εεεε, κάθε άλλη γλώσσα → ανέπαφο 'Y-m-d'.
	 * DISPLAY-ONLY: οι εσωτερικές τιμές (GET params, SQL, options,
	 * filenames) παραμένουν ΠΑΝΤΑ 'Y-m-d' — καμία μετάλλαξη δεδομένων.
	 */
	public static function fmt_date( ?string $ymd ): string {

		if ( null === $ymd || '' === $ymd ) {
			return (string) $ymd;
		}

		if ( 'el' !== self::get_lang() ) {
			return $ymd;
		}

		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd );

		return ( $d instanceof DateTimeImmutable ) ? $d->format( 'd/m/Y' ) : $ymd;
	}


	/**
	 * Λεξικό Ελληνικά → English — ΟΛΑ τα εμφανιζόμενα strings του
	 * domain 'revenue-splitter' (v1.7.0: επαληθευμένο με εξαγωγή όλων των
	 * __()/esc_html__()/… κλήσεων — κάθε ελληνικό msgid έχει byte-identical
	 * κλειδί εδώ, χωρίς διπλότυπα).
	 */
	private static $dict = array(

		// --- v1.3.6: Υπερ-πλήρες backup/import ---
		'Μη έγκυρο τμήμα post meta στο backup.'               => 'Invalid post meta section in the backup.',
		'Προϊοντικά overrides: εφαρμόστηκαν %d εγγραφές.'     => 'Product overrides: %d entries applied.',
		'ΔΕΝ βρέθηκαν τα προϊόντα με IDs %s — τα overrides τους παραλείφθηκαν (τα product IDs του backup δεν ταιριάζουν με τα τρέχοντα).' => 'Products with IDs %s were NOT found — their overrides were skipped (the backup product IDs do not match the current ones).',
		'Αιτιολογίες δωρεάν αντιτύπων (HPOS): εφαρμόστηκαν %d εγγραφές.' => 'Free-copy reasons (HPOS): %d entries applied.',
		'Γλώσσα οθόνης: εφαρμόστηκε σε %d χρήστες.'          => 'Display language: applied to %d users.',

		// --- Menus / σελίδες ---
		'Revenue Splitter'                                       => 'Revenue Splitter',
		'Revenue Splitter — Dashboard'                           => 'Revenue Splitter — Dashboard',
		'Revenue Splitter — Ρυθμίσεις'                           => 'Revenue Splitter — Settings',
		'Revenue Splitter — Γρήγορη ματιά'                       => 'Revenue Splitter — Quick glance',
		'Revenue Splitter — Δικαιούχοι'                          => 'Revenue Splitter — Beneficiaries',
		'Revenue Splitter — Portal'                              => 'Revenue Splitter — Portal',
		'Revenue Splitter:'                                      => 'Revenue Splitter:',
		'RS Ρυθμίσεις'                                           => 'RS Settings',
		'RS Portal'                                              => 'RS Portal',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'       => 'You do not have permission to access this page.',

		// --- Φόρμα περιόδου / φίλτρα ---
		// v1.3.2 (#7): αφαίρεσαν τα νεκρά 'Αναζήτηση σε τίτλους…',
		// 'Όλα τα προϊόντα', 'Όλοι οι δικαιούχοι' — κανένα markup δεν
		// τα παράγει πλέον.
		'Τελευταίες 7 ημέρες'                                    => 'Last 7 days',
		'Τελευταίες 30 ημέρες'                                   => 'Last 30 days',
		'Τρέχων μήνας'                                           => 'Current month',
		'Προηγούμενος μήνας'                                     => 'Previous month',
		'Τρέχον έτος'                                            => 'Current year',
		'Προηγούμενο έτος'                                       => 'Previous year',
		'Προσαρμοσμένο'                                          => 'Custom',
		'Εφαρμογή'                                               => 'Apply',
		'Εξαγωγή'                                                => 'Export',
		'Προϊόν'                                                 => 'Product',
		'Περίοδος'                                               => 'Period',
		// v1.3.4: επαναφορά φίλτρου προϊόντος στο dashboard.

		// --- KPIs ---
		'Παραγγελίες'                                            => 'Orders',
		'Παραγγελίες (περιόδου)'                                 => 'Orders (period)',
		'Μικτό (με ΦΠΑ)'                                         => 'Gross (incl. VAT)',
		'ΦΠΑ'                                                    => 'VAT',
		'Καθαρό (πριν καταμερισμό)'                              => 'Net (before split)',

		// --- Πίνακες ---
		'Ανά προϊόν'                                             => 'Per product',
		'Καμία πωλημένη γραμμή στην περίοδο.'                    => 'No sold line items in this period.',
		'Τεμ.'                                                   => 'Qty',
		'Μικτό'                                                  => 'Gross',
		'Καθαρό'                                                 => 'Net',
		'Καταμερισμός'                                           => 'Split',
		'Δεν υπάρχουν δεδομένα δικαιούχων στην περίοδο.'         => 'No beneficiary data in this period.',
		'Ποσό'                                                   => 'Amount',
		'Προϊόν #%d'                                             => 'Product #%d',
		'ΣΥΝΟΛΑ'                                                 => 'TOTALS',

		// --- Widget / dashboard ---
		'global defaults'                                        => 'global defaults',

		// --- Ρυθμίσεις ---
		'Default ΦΠΑ (%)'                                        => 'Default VAT (%)',
		'Ισχύει για προϊόντα χωρίς δικό τους ΦΠΑ στο General tab.' => 'Applies to products without their own VAT in the General tab.',
		'Global Δικαιούχοι'                                      => 'Global Beneficiaries',
		'Ο προεπιλεγμένος καταμερισμός για κάθε προϊόν χωρίς δικό του override.' => 'The default split for any product without its own override.',
		'Μη έγκυρο global default ΦΠΑ (0–100).'                  => 'Invalid global default VAT (0–100).',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                            => 'Settings saved.',
		'Αποθήκευση ρυθμίσεων'                                   => 'Save settings',
		'Γλώσσα οθόνης'                                          => 'Display language',

		// --- Metabox ---
		'Χρησιμοποιεί τα global defaults (δικαιούχοι)'           => 'Uses global defaults (beneficiaries)',
		'Δικαιούχος'                                             => 'Beneficiary',
		'Ποσοστό (%)'                                            => 'Percentage (%)',
		'Αφαίρεση γραμμής'                                       => 'Remove row',
		'Προσθήκη δικαιούχου'                                    => 'Add beneficiary',
		'Σύνολο:'                                                => 'Total:',
		'ΦΠΑ (%)'                                                => 'VAT (%)',
		'Κενό = global default (%s%%).'                          => 'Empty = global default (%s%%).',
		'Δεν έχουν ρυθμιστεί global defaults. Πήγαινε στο WP-admin → Revenue Splitter → Ρυθμίσεις.' => 'No global defaults configured. Go to WP-admin → Revenue Splitter → Settings.',
		'Προϊόν — πλήρης δικαιούχος'                             => 'Product — sole beneficiary',
		'Μη έγκυρη λίστα δικαιούχων.'                            => 'Invalid beneficiaries list.',
		'Το όνομα δικαιούχου δεν επιτρέπεται να περιέχει τον χαρακτήρα «|».' => 'A beneficiary name cannot contain the "|" character.',
		'Κάθε γραμμή χρειάζεται όνομα δικαιούχου.'              => 'Every row needs a beneficiary name.',
		'Τα ποσοστά δικαιούχων πρέπει να είναι μεταξύ 0 και 100 (εκτός 0).' => 'Beneficiary percentages must be between 0 and 100 (excluding 0).',
		'Τα ποσοστά δικαιούχων αθροίζουν %s%% — πρέπει να αθροίζουν 100%%.' => 'Beneficiary percentages add up to %s%% — they must sum to 100%%.',
		'Το Revenue Splitter απαιτεί WooCommerce για να λειτουργήσει.' => 'Revenue Splitter requires WooCommerce to function.',
		'Ο καταμερισμός του προϊόντος ΔΕΝ αποθηκεύτηκε — χρησιμοποιείται η προηγούμενη/κενή τιμή.' => 'The product split was NOT saved — the previous/empty value is in effect.',

		// --- Portal (admin): διαχείριση κλειδιών ---
		'Δεν υπάρχουν δικαιούχοι ακόμη.'                        => 'No beneficiaries yet.',
		'Κατάσταση κλειδιού'                                      => 'Key status',
		'χωρίς κλειδί'                                            => 'no key',
		'Ανανέωση'                                                => 'Rotate',
		'Δημιουργία'                                              => 'Create',
		'Νέο κλειδί για'                                           => 'New key for',
		'Αντιγράψε το ΤΩΡΑ — δεν θα εμφανιστεί ξανά (αποθηκεύεται μόνο sha256).' => 'Copy it NOW — it will not be shown again (only the sha256 is stored).',

		// --- Portal (admin): hashed keys (v1.3.0) — διατηρούνται,
		//     δεν επιβεβαιώθηκε ότι είναι πλήρως νεκρές. ---

		// --- Portal (frontend): login ---
		'Κλειδί'                                                  => 'Key',
		'Είσοδος'                                                => 'Sign in',
		'Πολλές αποτυχημένες προσπάθειες — δοκίμασε ξανά σε 15 λεπτά.' => 'Too many failed attempts — try again in 15 minutes.',
		'Αποσύνδεση'                                             => 'Log out',

		// --- Portal (frontend): dashboard δικαιούχου ---
		'Αυτόν τον μήνα'                                          => 'This month',
		'Αποπληρωτέο υπόλοιπο'                                    => 'Outstanding balance',
		'Με ΦΠΑ'                                                 => 'With VAT',
		'Χωρίς ΦΠΑ'                                               => 'Without VAT',
		'Στοκ'                                                    => 'Stock',
		'Το μερίδιό μου'                                          => 'My share',
		'Το «αποπληρωτέο υπόλοιπο» = all-time μερίδια από πωλήσεις + έσοδα εκτός πωλήσεων − πληρωμές που έχεις λάβει.' => '"Outstanding balance" = all-time shares from sales + income outside sales − payments received.',
		'Ποσοστό'                                                => 'Percent',
		'Μερίδιο'                                                 => 'Share',
		'Καμία πώληση σε αυτή την περίοδο.'                      => 'No sales in this period.',

		// --- Portal: διαφάνεια πωλήσεων (v1.2.0) ---
		'Δωρεάν'                                                 => 'Free',
		'Πλήρης'                                                 => 'Full',
		'Έκπτωση'                                                => 'Discount',

		// --- Dashboard: λογοδοσία περιόδου + λογιστική ---
		'Πωλήσεις'                                               => 'Sales',
		'Υπόλοιπο (περιόδου)'                                    => 'Remaining (period)',
		'Υπόλοιπο'                                               => 'Remaining',
		'Συνολικό υπόλοιπο'                                      => 'Lifetime balance',
		'Έσοδα εκτός πωλήσεων'                                   => 'Non-sales income',
		'Πληρωμές'                                               => 'Payments',
		'Λογιστική δικαιούχων'                                   => 'Beneficiary accounting',

		// --- Dashboard: ΦΠΑ ανά συντελεστή ---
		'ΦΠΑ ανά συντελεστή'                                     => 'VAT by rate',
		'Συντελεστής'                                            => 'Rate',

		// --- Ledger / checkout / stock ---
		'Έσοδα εκτός πωλήσεων & Πληρωμές'                        => 'Non-sales income & Payments',
		'Πληρωμή'                                                => 'Payment',
		'Έσοδο'                                                  => 'Income',
		'Έσοδο (+/−)'                                            => 'Income (+/−)',
		'Καμία εγγραφή.'                                         => 'No entries.',
		'Καταχώριση'                                             => 'Add entry',
		'Η εγγραφή καταχωρήθηκε.'                                => 'Entry added.',
		'Η εγγραφή διαγράφηκε.'                                  => 'Entry deleted.',
		'Διαγραφή εγγραφής'                                      => 'Delete entry',
		'Ημερομηνία'                                             => 'Date',
		'Τύπος'                                                  => 'Type',
		'Αιτιολογία'                                             => 'Reason',
		'Αιτιολογία (υποχρεωτική)…'                              => 'Reason (required)…',
		'Ποσό (π.χ. 150 ή -40)'                                  => 'Amount (e.g. 150 or -40)',
		'Άκυρος τύπος εγγραφής.'                                 => 'Invalid entry type.',
		'Μη έγκυρη ημερομηνία.'                                  => 'Invalid date.',
		'Επίλεξε δικαιούχο.'                                     => 'Select a beneficiary.',
		'Μη έγκυρο ποσό.'                                        => 'Invalid amount.',
		'Το ποσό δεν μπορεί να είναι μηδέν.'                     => 'Amount cannot be zero.',
		'Η αιτιολογία είναι υποχρεωτική.'                         => 'A reason is required.',
		'Άκυρο ID εγγραφής.'                                     => 'Invalid entry ID.',
		'Η εγγραφή δεν βρέθηκε — ίσως έχει ήδη διαγραφεί.'       => 'Entry not found — it may have already been deleted.',
		'Δεν υπάρχουν δικαιούχοι ακόμη — δεν μπορεί να διατηρηθεί ledger.' => 'No beneficiaries yet — the ledger cannot be maintained.',
		'Κουπόνια δωρεάν αντιτύπων'                               => 'Free-copy coupons',
		'Αιτιολογία δωρεάν αντιτύπου'                             => 'Free copy reason',
		'π.χ. δώρο, διαγωνισμός, κριτική βιβλίου…'               => 'e.g. gift, giveaway, book review…',
		'Παρακαλώ συμπλήρωσε την αιτιολογία δωρεάν αντιτύπου.'    => 'Please provide the reason for your free copy.',
		'Προσαρμογές εκτός WooCommerce: bonus, υποτροφίες, διορθώσεις («Έσοδο», + ή −) και αποπληρωμές («Πληρωμή», πάντα θετικές). Κάθε εγγραφή χρειάζεται αιτιολογία.' => 'Adjustments outside WooCommerce: bonuses, grants, corrections ("Income", + or −) and payouts ("Payment", always positive). Every entry requires a reason.',
		'Η πληρωμή πρέπει να είναι θετικό ποσό (ποσά που αφαιρούνται καταχωρούνται ως «Έσοδο» με αρνητική τιμή).' => 'A payment must be a positive amount (deductions are entered as "Income" with a negative value).',
		'Άγνωστος δικαιούχος — αποθηκεύεις πρώτα τους δικαιούχους στις Ρυθμίσεις;' => 'Unknown beneficiary — did you save the beneficiaries in Settings first?',

		// --- Portal CSV headers ---
		'Μέση έκπτωση (%)'                                       => 'Average discount (%)',

		// --- Backup / Import ---
		'Backup & Επαναφορά'                                     => 'Backup & Restore',
		'Εισαγωγή state (JSON)'                                  => 'Import state (JSON)',
		'Εξαγωγή state (JSON)'                                   => 'Export state (JSON)',
		'Μη έγκυρο αρχείο backup (λείπουν τα options).'           => 'Invalid backup file (missing options).',
		'Μη έγκυρο blob κλειδιών portal.'                        => 'Invalid portal keys blob.',
		'Μη έγκυρο blob ledger (JSON).'                          => 'Invalid ledger blob (JSON).',
		'Ledger: εισήχθησαν %d εγγραφές (με πλήρη validation).'  => 'Ledger: %d entries imported (fully validated).',
		'Η εισαγωγή ολοκληρώθηκε.'                               => 'Import completed.',
		// v1.3.2 (#3): τελικό notice όταν η εισαγωγή είχε ΚΑΠΟΙΑ error.
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ — τα άκυρα τμήματα ΔΕΝ αντικαταστάθηκαν (δες τα παραπάνω σφάλματα).' => 'Import completed PARTIALLY — the invalid sections were NOT replaced (see the errors above).',
		'Δεν επιλέχθηκε αρχείο JSON.'                            => 'No JSON file selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                       => 'The file is not valid JSON.',
		'Όνομα|Ποσοστό (μία γραμμή ανά δικαιούχο)'               => 'Name|Percentage (one beneficiary per line)',

		// --- RS_Portal v1.3.5 ---
		'Λάθος κλειδί.'                                          => 'Wrong key.',
		'Μερίδιό σου (περιόδου)'                                 => 'Your share (period)',
		'Κουπόνια'                                               => 'Coupons',
		'Τελευταίοι μήνες'                                        => 'Recent months',

		// --- v1.3.6 (#2): Εξόφληση όλων (περιόδου) ---
		'Εξόφληση όλων (περιόδου)'                              => 'Settle all (period)',
		'Από'                                                    => 'From',
		'Έως'                                                    => 'To',
		'Εξόφληση όλων'                                          => 'Settle all',
		'Άκυρο διάστημα εξόφλησης (η ημερομηνία Από πρέπει να προηγείται της Έως).' => 'Invalid settlement range (the From date must precede the To date).',
		'Εξοφλήθηκαν %1$d δικαιούχοι — σύνολο %2$s.'            => 'Settled %1$d beneficiaries — total %2$s.',
		'Καμία εξόφληση — κανείς δεν έχει υπόλοιπο στο διάστημα.' => 'Nothing settled — nobody has a balance in this range.',
		'Δεν καταχωρήθηκε η πληρωμή για %s.'                    => 'The payment for %s was not recorded.',

		// --- v1.3.6 (#9): Έναρξη καταγραφής πωλήσεων ---
		'Έναρξη καταγραφής πωλήσεων'                             => 'Sales recording start date',
		'Το plugin μετράει πωλήσεις ΚΑΙ ποσοστά ΜΟΝΟ από αυτή την ημερομηνία και μετά. Κενό = χωρίς όριο (καταγράφεται όλο το ιστορικό). Χρήσιμο για καθαρή εκκίνηση χωρίς να διαγραφούν παλιές παραγγελίες.' => 'The plugin counts sales and shares ONLY from this date onwards. Empty = no limit (the whole history is recorded). Useful for a clean start without deleting past orders.',
		'Μη έγκυρη ημερομηνία έναρξης καταγραφής (απαιτείται Y-m-d ή κενό).' => 'Invalid sales-recording start date (Y-m-d or empty required).',

		// --- v1.3.6 (#5): γλώσσα — 'auto' option ---
		'Αυτόματη (WordPress)'                                   => 'Automatic (WordPress)',
		'Ισχύει ανά χρήστη (μόνο για εσένα). Εάν δεν έχεις διαλέξει, ακολουθείται η γλώσσα του WordPress.' => 'Per user (only affects you). If you have not picked one, the WordPress language is followed.',

		// --- v1.3.6: diversified strings (backup desc, footer, export header) ---
		'More plugins at'                                        => 'More plugins at',
		'Revenue Splitter — %1$s έως %2$s'                       => 'Revenue Splitter — %1$s to %2$s',

		// --- v1.3.6-fix (#5): λείποντα strings EN mode ---
		'Ιστορικό συναλλαγών'                                    => 'Transaction history',
		'6 μήνες'                                                => '6 months',
		'12 μήνες'                                               => '12 months',
		'Κανείς δεν έχει μερίδιο στην περίοδο.' => 'Nobody has a share in this period.',

		// --- v1.3.8: multi-select προϊόντων, φίλτρο δικαιούχου, χρώματα ---
		'Αναζήτηση προϊόντος…' => 'Search products…',
		'Ctrl/Cmd + click για πολλαπλή επιλογή. Καμία επιλογή = όλα.' => 'Ctrl/Cmd + click to select multiple. No selection = all.',
		'Όλοι οι δικαιούχοι' => 'All beneficiaries',
		'Χρώματα δικαιούχων' => 'Beneficiary colors',
		'Προσαρμοσμένο χρώμα ανά δικαιούχο — εμφανίζεται στα chips του καταμερισμού και στα ονόματα των πινάκων. Default: μωβ #6d4aff.' => 'Custom color per beneficiary — shown in the split chips and table names. Default: purple #6d4aff.',

		// --- v1.3.8 (#7): κανάλια πώλησης ---
		'Κανάλια πώλησης' => 'Sales channels',
		'Προεπιλεγμένη λίστα καναλιών για το checkout (όταν εφαρμόζεται κουπόνι δωρεάν αντιτύπου) και για τη χειροκίνητη εισαγωγή εσόδων στο ledger. Μία γραμμή ανά κανάλι.' => 'Default channel list for checkout (when a free-copy coupon is applied) and for manual ledger income entries. One channel per line.',
		'Κανάλι πώλησης'                                           => 'Sales channel',
		'— Επιλογή καναλιού —'                                     => '— Select channel —',
		'Παρακαλώ επίλεξε κανάλι πώλησης.'                         => 'Please select a sales channel.',
		'Μη έγκυρο κανάλι πώλησης.'                                => 'Invalid sales channel.',
		'Κανάλι πώλησης:'                                          => 'Sales channel:',
		'Αιτιολογία δωρεάν αντιτύπου:'                             => 'Free copy reason:',
		'Κανάλι'                                                   => 'Channel',
		'— χωρίς κανάλι —'                                        => '— no channel —',
		'Μη έγκυρο κανάλι πώλησης (δεν είναι στη λίστα των Ρυθμίσεων).' => 'Invalid sales channel (not in the Settings list).',

		// --- v1.3.8 (#7 στάδιο 3): αναφορά ανά κανάλι ---
		'Ανά κανάλι'                                               => 'By channel',
		'Καμία κίνηση ανά κανάλι στην περίοδο.'                    => 'No channel activity in this period.',
		'Default κανάλι (παραγγελίες χωρίς μαρκάρισμα)'             => 'Default channel (unmarked orders)',
		'Σε αυτό το κανάλι καταμετρώνται όλες οι κανονικές παραγγελίες του καταστήματος που δεν έχουν μαρκαριστεί με κανάλι (π.χ. από κουπόνι στο checkout). Κενό = «Κατάστημα/Online».' => 'All regular store orders without a channel mark (e.g. via a checkout coupon) are counted here. Empty = “Store/Online”.',
		'Μη έγκυρο default κανάλι.'                               => 'Invalid default channel.',

		// --- v1.4.0 audit: λείποντα strings (χρώματα/κανάλια + audit patches) ---
		'Μη έγκυρο χρώμα δικαιούχου (απαιτείται #RRGGBB).'                  => 'Invalid beneficiary color (#RRGGBB required).',
		'Μη έγκυρο blob χρωμάτων δικαιούχων (απαιτούνται #RRGGBB τιμές).'  => 'Invalid beneficiary colors blob (#RRGGBB values required).',
		'Μη έγκυρο blob καναλιών πώλησης.'                                  => 'Invalid sales channels blob.',
		'Το αρχείο υπερβαίνει το όριο μεταφόρτωσης του server — δες το upload_max_filesize της PHP.' => 'The file exceeds the server upload limit — see the PHP upload_max_filesize.',
		'Σφάλμα κατά τη μεταφόρτωση του αρχείου — δοκίμασε ξανά.'           => 'Error while uploading the file — please try again.',
		'Μη αποδεκτό μέγεθος αρχείου (όριο 64 MB).'                         => 'Unacceptable file size (64 MB limit).',
		'Μη έγκυρη λίστα δικαιούχων (εσωτερικό σφάλμα μορφοποίησης).'      => 'Invalid beneficiaries list (internal formatting error).',

		// --- Πρόταση 1: mini chart τάσης ---
		'Τάση (μήνα με μήνα)'                => 'Trend (month by month)',
		'Μερίδιο δικαιούχου'                 => 'Beneficiary share',
		'Σύνολο μεριδίων'                    => 'Total shares',

		// --- Audit: strings των audit patches (ledger atomic import,
		//     nonce-fail, block checkout warning, sale estimate label) ---
		'Η φόρμα έληξε (nonce) — δοκίμασε ξανά.'                 => 'The form expired (nonce) — please try again.',
		'Ledger: η εγγραφή στη θέση %d δεν είναι έγκυρη.'        => 'Ledger: the entry at position %d is invalid.',
		'Ledger: η εγγραφή στη θέση %1$d απέτυχε — %2$s'         => 'Ledger: the entry at position %1$d failed — %2$s',
		'Ledger: ΚΑΜΙΑ αλλαγή δεν έγινε — το υπάρχον ledger παρέμεινε άθικτο.' => 'Ledger: NO changes were made — the existing ledger remained intact.',
		'εκτίμηση έκπτωσης (τιμοκατάλογος)'                       => 'discount estimate (current price list)',

		// --- v1.4.1 (#2): Backup χωρίς ledger section ----
		'Το backup δεν περιέχει ledger — το υπάρχον ledger παρέμεινε άθικτο.' => 'The backup does not contain a ledger — the existing ledger was left unchanged.',

		// --- v1.5.0 polish (#1): bilingual placeholder καναλιών (Ρυθμίσεις) ---
		"Βιβλιοπωλείο\nΕκδηλώσεις\nOnline\nΧονδρική" => "Bookstore\nEvents\nOnline\nWholesale",

		// --- v1.7.0: συμπλήρωση λεξικού (emails, portal reset, Noxpress home, notices) ---
		'Συμπεριλαμβάνονται: ΦΠΑ default, global δικαιούχοι, χρώματα δικαιούχων, emails δικαιούχων, opt-in μηνιαίας αναφοράς, κανάλια πώλησης, κλειδιά portal (hashed), ledger (πληρωμές & έξτρα έσοδα), κουπόνια, ημερομηνία έναρξης, καταμερισμός/ΦΠΑ ανά προϊόν, αιτιολογίες δωρεάν αντιτύπων και γλώσσες χρηστών. Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τα αντίστοιχα δεδομένα.' => 'Includes: default VAT, global beneficiaries, beneficiary colors, beneficiary emails, monthly report opt-ins, sales channels, portal keys (hashed), ledger (payments & extra income), coupons, start date, per-product splits/VAT, free-copy reasons and user languages. Importing REPLACES the corresponding data.',
		'Ο δικαιούχος μπαίνει στη σελίδα του portal ([rs_portal]) ΜΟΝΟ με το κλειδί του — το κλειδί ταυτοποιεί μοναδικά τον κάτοχό του.' => 'The beneficiary signs in on the portal page ([rs_portal]) ONLY with their personal key — the key uniquely identifies its holder.',
		'Όταν στο checkout εφαρμόζεται οποιοδήποτε από αυτά τα κουπόνια, ο πελάτης υποχρεούται να επιλέξει κανάλι πώλησης από τη λίστα των καναλιών. Διαχωρισμός με κόμμα.' => 'When any of these coupons is applied at checkout, the customer must pick a sales channel from the channel list. Comma-separated.',
		'Η σελίδα checkout χρησιμοποιεί το Checkout block του WooCommerce. Το πεδίο «Κανάλι πώλησης» / «Αιτιολογία δωρεάν αντιτύπου» εμφανίζεται ΜΟΝΟ στο classic checkout (shortcode [woocommerce_checkout]) — με το block τα κουπόνια δωρεάν αντιτύπων ΔΕΝ ζητούν κανάλι/αιτιολογία. %1$sΔιάβασε το επίσημο άρθρο%2$s ή επέστρεψε στο classic checkout.' => 'The checkout page uses the WooCommerce Checkout block. The "Sales channel" / "Free copy reason" field appears ONLY in the classic checkout (shortcode [woocommerce_checkout]) — with the block, free-copy coupons do NOT ask for a channel/reason. %1$sRead the official article%2$s or switch back to the classic checkout.',
		'Τα παρακάτω emails ΔΕΝ αποθηκεύτηκαν (μη έγκυρη διεύθυνση): %s.' => 'The following emails were NOT saved (invalid address): %s.',
		'Τα παρακάτω emails ΔΕΝ αποθηκεύτηκαν (μη έγκυρη διεύθυνση): %s. Ο καταμερισμός αποθηκεύτηκε κανονικά.' => 'The following emails were NOT saved (invalid address): %s. The split was saved normally.',

		'Emails δικαιούχων & μηνιαία αναφορά' => 'Beneficiary emails & monthly report',
		'Το email χρησιμοποιείται για την αποστολή νέου κλειδιού portal (ροή «Ξέχασα το κλειδί») και για τη μηνιαία αναφορά πωλήσεων (στέλνεται τις πρώτες μέρες κάθε μήνα για τον προηγούμενο). Ο συγγραφέας μπορεί να ενεργοποιήσει/απενεργοποιήσει την αναφορά και μόνος του από το portal του.' => 'The email is used to send a new portal key ("Forgot my key" flow) and for the monthly sales report (sent in the first days of each month for the previous one). The author can also enable/disable the report from their own portal.',
		'Μηνιαία αναφορά' => 'Monthly report',
		'Μη έγκυρο email δικαιούχου (τα υπόλοιπα αποθηκεύτηκαν κανονικά).' => 'Invalid beneficiary email (the rest were saved normally).',
		'Μη έγκυρο blob emails δικαιούχων.' => 'Invalid beneficiary emails blob.',
		'Μη έγκυρο blob opt-in μηνιαίας αναφοράς.' => 'Invalid monthly report opt-in blob.',
		'Δοκιμή αποστολής email' => 'Email delivery test',
		'Αποστολή δοκιμαστικού email' => 'Send test email',
		'Στέλνει ένα απλό δοκιμαστικό email για να επιβεβαιώσεις ότι η αποστολή (και άρα η μηνιαία αναφορά) φτάνει σε παραλήπτη.' => 'Sends a simple test email so you can confirm that delivery (and therefore the monthly report) reaches a recipient.',
		'Μη έγκυρη διεύθυνση παραλήπτη για το δοκιμαστικό email.' => 'Invalid recipient address for the test email.',
		'Το δοκιμαστικό email στάλθηκε στο %s.' => 'The test email was sent to %s.',
		'Η αποστολή απέτυχε — έλεγξε τις ρυθμίσεις email του WordPress (π.χ. SMTP plugin ή PHP mail).' => 'Sending failed — check the WordPress email settings (e.g. SMTP plugin or PHP mail).',

		'Αν το email υπάρχει στα αρχεία μας, θα λάβεις νέο κλειδί σε λίγα λεπτά.' => 'If the email is in our records, you will receive a new key in a few minutes.',
		'Ξέχασες το κλειδί;' => 'Forgot your key?',
		'Στείλε νέο κλειδί' => 'Send a new key',
		'Μηνιαία αναφορά email' => 'Monthly email report',
		'Δεν έχει οριστεί email για το όνομά σου — ενημέρωσε τον εκδότη για να μπορείς να ενεργοποιήσεις τη μηνιαία αναφορά.' => 'No email is set for your name — ask the publisher so you can enable the monthly report.',
		'Η αναφορά θα στέλνεται στο %s.' => 'The report will be sent to %s.',
		'Απενεργοποίηση αναφοράς' => 'Disable report',
		'Ενεργοποίηση αναφοράς' => 'Enable report',
		'Η αναφορά είναι ενεργή — στέλνεται τις πρώτες μέρες κάθε μήνα και καλύπτει τον προηγούμενο.' => 'The report is active — it is sent in the first days of each month and covers the previous one.',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',

		'Μηνιαία αναφορά πωλήσεων — %s' => 'Monthly sales report — %s',
		'Μηνιαία αναφορά πωλήσεων' => 'Monthly sales report',
		'Γεια σου' => 'Hello',
		'Το μερίδιό σου τον μήνα' => 'Your share this month',
		'Τα προϊόντα σου' => 'Your products',
		'Καμία πώληση αυτόν τον μήνα.' => 'No sales this month.',
		'Η αναφορά στέλνεται επειδή έχεις ενεργοποιήσει τη μηνιαία αναφορά στο portal σου. Μπορείς να την απενεργοποιήσεις εκεί ανά πάσα στιγμή.' => 'You receive this report because you enabled the monthly report in your portal. You can disable it there at any time.',
		'Νέο κλειδί portal' => 'New portal key',
		'Ζητήθηκε επαναφορά του κλειδιού σου για το Author Portal. Το νέο σου κλειδί είναι:' => 'A reset of your Author Portal key was requested. Your new key is:',
		'Το παλιό σου κλειδί ακυρώθηκε. Αν ΔΕΝ ζήτησες εσύ την επαναφορά, ενημέρωσε τον εκδότη.' => 'Your old key has been revoked. If you did NOT request this reset, notify the publisher.',
		'Νέο κλειδί portal — %s' => 'New portal key — %s',
		'Ζητήθηκε επαναφορά κλειδιού portal μέσω του frontend (forgot-key flow).' => 'A portal key reset was requested via the frontend (forgot-key flow).',
		'Δικαιούχος: %s' => 'Beneficiary: %s',
		'[Revenue Splitter] Επαναφορά κλειδιού portal — %s' => '[Revenue Splitter] Portal key reset — %s',
		'Αυτό είναι ένα δοκιμαστικό email από το plugin Revenue Splitter.' => 'This is a test email from the Revenue Splitter plugin.',
		'Αν το διαβάζεις, η αποστολή email λειτουργεί σωστά — η μηνιαία αναφορά θα φτάνει κανονικά.' => 'If you are reading this, email delivery works — the monthly report will arrive normally.',
		'Στάλθηκε: %s' => 'Sent: %s',
		'[Revenue Splitter] Δοκιμαστικό email — %s' => '[Revenue Splitter] Test email — %s',
	);
}