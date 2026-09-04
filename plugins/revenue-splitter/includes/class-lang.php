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

	/** Η γλώσσα του τρέχοντος χρήστη ('el' default). */
	public static function get_lang( ?int $user_id = null ): string {

		if ( null !== self::$current && null === $user_id ) {
			return self::$current;
		}

		$uid  = $user_id ?? get_current_user_id();
		$lang = $uid ? (string) get_user_meta( $uid, self::USER_META, true ) : '';

		if ( ! in_array( $lang, array( 'el', 'en' ), true ) ) {
			$lang = 'el';
		}

		if ( null === $user_id ) {
			self::$current = $lang;
		}

		return $lang;
	}

	/** Θέτει τη γλώσσα χρήστη (μόνο 'el'/'en' γίνονται δεκτά). */
	public static function set_lang( int $user_id, string $lang ): bool {
		$lang = in_array( $lang, array( 'el', 'en' ), true ) ? $lang : 'el';

		// Reset request-cache ώστε η αλλαγή να ισχύσει άμεσα (ίδιο request).
		self::$current = null;

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
	 * Λεξικό Ελληνικά → English — ΟΛΑ τα εμφανιζόμενα strings του
	 * domain 'revenue-splitter' στην v1.3.2.
	 */
	private static $dict = array(

		// --- Menus / σελίδες ---
		'Revenue Splitter'                                       => 'Revenue Splitter',
		'Revenue Splitter — Dashboard'                           => 'Revenue Splitter — Dashboard',
		'Revenue Splitter — Ρυθμίσεις'                           => 'Revenue Splitter — Settings',
		'Revenue Splitter — Γρήγορη ματιά'                       => 'Revenue Splitter — Quick glance',
		'Revenue Splitter — Δικαιούχοι'                          => 'Revenue Splitter — Beneficiaries',
		'Revenue Splitter — Portal'                              => 'Revenue Splitter — Portal',
		'Revenue Splitter:'                                      => 'Revenue Splitter:',
		'Dashboard'                                              => 'Dashboard',
		'Ρυθμίσεις'                                              => 'Settings',
		'Portal'                                                 => 'Portal',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'       => 'You do not have permission to access this page.',
		'Πλήρες dashboard →'                                     => 'Full dashboard →',

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

		// --- KPIs ---
		'Παραγγελίες'                                            => 'Orders',
		'Παραγγελίες (περιόδου)'                                 => 'Orders (period)',
		'%s παραγγελίες'                                         => '%s orders',
		'Μικτό (με ΦΠΑ)'                                         => 'Gross (incl. VAT)',
		'ΦΠΑ'                                                    => 'VAT',
		'Καθαρό (πριν καταμερισμό)'                              => 'Net (before split)',
		'καθαρά'                                                 => 'net',

		// --- Πίνακες ---
		'Ανά προϊόν'                                             => 'Per product',
		'Καμία πωλημένη γραμμή στην περίοδο.'                    => 'No sold line items in this period.',
		'Τεμ.'                                                   => 'Qty',
		'Μικτό'                                                  => 'Gross',
		'Καθαρό'                                                 => 'Net',
		'Καταμερισμός'                                           => 'Split',
		'Σύνολα ανά δικαιούχο'                                   => 'Totals per beneficiary',
		'Δεν υπάρχουν δεδομένα δικαιούχων στην περίοδο.'         => 'No beneficiary data in this period.',
		'Δικαιούχοι'                                             => 'Beneficiaries',
		'Ποσό'                                                   => 'Amount',
		'Ποσό (περιόδου)'                                        => 'Amount (period)',
		'Προϊόν #%d'                                             => 'Product #%d',
		'ID'                                                     => 'ID',
		'ΣΥΝΟΛΑ'                                                 => 'TOTALS',
		'Σύνολο'                                                 => 'Total',
		'ΦΠΑ %'                                                  => 'VAT %',

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
		'Ισχύει ανά χρήστη (μόνο για εσένα).'                    => 'Per user (only affects you).',

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
		'Όνομα δικαιούχου'                                        => 'Beneficiary name',
		'Προϊόν — πλήρης δικαιούχος'                             => 'Product — sole beneficiary',
		'Μη έγκυρη λίστα δικαιούχων.'                            => 'Invalid beneficiaries list.',
		'Τα ποσοστά δικαιούχων αθροίζουν %s%% — πρέπει να αθροίζουν 100%%.' => 'Beneficiary percentages add up to %s%% — they must sum to 100%%.',
		'Το Revenue Splitter απαιτεί WooCommerce για να λειτουργήσει.' => 'Revenue Splitter requires WooCommerce to function.',
		'Ο καταμερισμός του προϊόντος ΔΕΝ αποθηκεύτηκε — χρησιμοποιείται η προηγούμενη/κενή τιμή.' => 'The product split was NOT saved — the previous/empty value is in effect.',

		// --- Portal (admin): διαχείριση κλειδιών ---
		'Κάθε δικαιούχος μπαίνει στη σελίδα του portal ([author_portal]) με το όνομά του και το προσωπικό του κλειδί.' => 'Each beneficiary signs in on the portal page ([author_portal]) with their name and personal key.',
		'Δεν υπάρχουν δικαιούχοι ακόμη.'                        => 'No beneficiaries yet.',
		'Κατάσταση κλειδιού'                                      => 'Key status',
		'χωρίς κλειδί'                                            => 'no key',
		'Ανανέωση'                                                => 'Rotate',
		'Δημιουργία'                                              => 'Create',
		'Νέο κλειδί για'                                           => 'New key for',
		'Αντιγράψε το ΤΩΡΑ — δεν θα εμφανιστεί ξανά (αποθηκεύεται μόνο sha256).' => 'Copy it NOW — it will not be shown again (only the sha256 is stored).',

		// --- Portal (admin): hashed keys (v1.3.0) — διατηρούνται,
		//     δεν επιβεβαιώθηκε ότι είναι πλήρως νεκρές. ---
		'Καινούρια κλειδιά — αντιγράψε τα ΤΩΡΑ:'                  => 'New keys — copy them NOW:',
		'αποθηκεύονται κατακερματισμένα και δεν θα εμφανιστούν ξανά.' => 'they are stored hashed and will never be shown again.',
		'Κρυφό — αποθηκεύεται κατακερματισμένα.'                  => 'Hidden — stored hashed.',
		'Πατρωτό (plaintext) κλειδί — ανανέωσέ το για να γίνει hash.' => 'Legacy (plaintext) key — regenerate it to convert it to a hash.',

		// --- Portal (frontend): login ---
		'Author Portal'                                          => 'Author Portal',
		'Όνομα'                                                   => 'Name',
		'Κλειδί'                                                  => 'Key',
		'Είσοδος'                                                => 'Sign in',
		'Λάθος όνομα ή κλειδί.'                                   => 'Wrong name or key.',
		'Πολλές αποτυχημένες προσπάθειες — δοκίμασε ξανά σε 15 λεπτά.' => 'Too many failed attempts — try again in 15 minutes.',
		'Αποσύνδεση'                                             => 'Log out',

		// --- Portal (frontend): dashboard δικαιούχου ---
		'Αυτόν τον μήνα'                                          => 'This month',
		'Αποπληρωτέο υπόλοιπο'                                    => 'Outstanding balance',
		'Τελευταίοι 6 μήνες'                                     => 'Last 6 months',
		'Αυτόν τον μήνα — ανά προϊόν'                             => 'This month — per product',
		'Με ΦΠΑ'                                                 => 'With VAT',
		'Χωρίς ΦΠΑ'                                               => 'Without VAT',
		'Στοκ'                                                    => 'Stock',
		'Το μερίδιό μου'                                          => 'My share',
		'Καμία πώληση των προϊόντων σου αυτόν τον μήνα.'          => 'None of your products sold this month.',
		'Το «αποπληρωτέο υπόλοιπο» = all-time μερίδια από πωλήσεις + έσοδα εκτός πωλήσεων − πληρωμές που έχεις λάβει.' => '"Outstanding balance" = all-time shares from sales + income outside sales − payments received.',
		'All-time μερίδια από πωλήσεις'                           => 'All-time shares from sales',
		'Πληρωμές που ελήφθησαν'                                  => 'Payments received',
		'Ποσοστό'                                                => 'Percent',
		'Μερίδιο'                                                 => 'Share',
		'Ποσοστό σου'                                            => 'Your percentage',
		'Ποσό σου'                                               => 'Your amount',
		'Καμία πώληση σε αυτή την περίοδο.'                      => 'No sales in this period.',

		// --- Portal: διαφάνεια πωλήσεων (v1.2.0) ---
		'Τεμάχια (πληρωμένα)'                                    => 'Items (paid)',
		'Σε πλήρη τιμή'                                          => 'Full price',
		'Με έκπτωση'                                             => 'Discounted',
		'Δωρεάν'                                                 => 'Free',
		'Καθαρό (καταμεριζόμενο)'                                => 'Net (splittable)',
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
		'Κινήσεις εκτός πωλήσεων'                                => 'Non-sales activity',
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
		'Mη έγκυρη ημερομηνία.'                                  => 'Invalid date.',
		'Επίλεξε δικαιούχο.'                                     => 'Select a beneficiary.',
		'Μη έγκυρο ποσό.'                                        => 'Invalid amount.',
		'Το ποσό δεν μπορεί να είναι μηδέν.'                     => 'Amount cannot be zero.',
		'Η αιτιολογία είναι υποχρεωτική.'                         => 'A reason is required.',
		'Άκυρο ID εγγραφής.'                                     => 'Invalid entry ID.',
		'Η εγγραφή δεν βρέθηκε — ίσως έχει ήδη διαγραφεί.'       => 'Entry not found — it may have already been deleted.',
		'Δεν υπάρχουν δικαιούχοι ακόμη — δεν μπορεί να συντηρηθεί ledger.' => 'No beneficiaries yet — the ledger cannot be maintained.',
		'Κουπόνια δωρεάν αντιτύπων'                               => 'Free-copy coupons',
		'Κωδικοί κουπονιών'                                      => 'Coupon codes',
		'Αιτιολογία δωρεάν αντιτύπου'                             => 'Free copy reason',
		'π.χ. δώρο, διαγωνισμός, κριτική βιβλίου…'               => 'e.g. gift, giveaway, book review…',
		'Παρακαλώ συμπλήρωσε την αιτιολογία δωρεάν αντιτύπου.'    => 'Please provide the reason for your free copy.',
		'Ledger (τρέχων μήνας)'                                   => 'Ledger (current month)',
		'Προσαρμογές εκτός WooCommerce: bonus, υποτροφίες, διορθώσεις («Έσοδο», + ή −) και αποπληρωμές («Πληρωμή», πάντα θετικές). Κάθε εγγραφή χρειάζεται αιτιολογία.' => 'Adjustments outside WooCommerce: bonuses, grants, corrections ("Income", + or −) and payouts ("Payment", always positive). Every entry requires a reason.',
		'Όταν στο checkout εφαρμόζεται οποιοδήποτε από αυτά τα κουπόνια, ο πελάτης υποχρεούται να συμπληρώσει αιτιολογία δωρεάν αντιτύπου. Διαχωρισμός με κόμμα.' => 'When any of these coupons is applied at checkout, the customer must provide a reason for the free copy. Comma-separated.',
		'Η πληρωμή πρέπει να είναι θετικό ποσό (ποσά που αφαιρούνται καταχωρούνται ως «Έσοδο» με αρνητική τιμή).' => 'A payment must be a positive amount (deductions are entered as "Income" with a negative value).',
		'Άγνωστος δικαιούχος — αποθηκεύεις πρώτα τους δικαιούχους στις Ρυθμίσεις;' => 'Unknown beneficiary — did you save the beneficiaries in Settings first?',
		'Έσοδα εκτός πωλήσεων (περιόδου)'                         => 'Non-sales income (period)',
		'Πληρωμές (περιόδου)'                                     => 'Payments (period)',

		// --- Portal CSV headers ---
		'Πλήρης τιμή (τεμ.)'                                     => 'Full price (qty)',
		'Με έκπτωση (τεμ.)'                                      => 'Discounted (items)',
		'Μέση έκπτωση (%)'                                       => 'Average discount (%)',
		'Δωρεάν (τεμ.)'                                          => 'Free (items)',
		'Ποσοστό σου (%)'                                        => 'Your percentage (%)',

		// --- Backup / Import ---
		'Backup & Επαναφορά'                                     => 'Backup & Restore',
		'Εισαγωγή state (JSON)'                                  => 'Import state (JSON)',
		'Εξαγωγή state (JSON)'                                   => 'Export state (JSON)',
		'Συμπεριλαμβάνονται: ΦΠΑ default, δικαιούχοι, κλειδιά portal (hashed), ledger, κουπόνια. Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τα αντίστοιχα δεδομένα.' => 'Includes: default VAT, beneficiaries, portal keys (hashed), ledger, coupons. Importing REPLACES the corresponding data.',
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
	);
}