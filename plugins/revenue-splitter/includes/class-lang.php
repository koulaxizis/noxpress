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
 * v1.1.3 (#8): Συμπλήρωμα λεξικού — καλύπτονται πλέον ΚΑΙ τα strings
 * του Portal (login/dashboard/logout/messages) και τα νέα μηνύματα
 * του rate limiter. Οι ανώνυμοι επισκέπτες του Portal ΔΕΝ επηρεάζονται:
 * δεν έχουν user meta, άρα μένουν στα Ελληνικά.
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
	 * Λεξικό Ελληνικά → English. Καλύπτει ΟΛΑ τα εμφανιζόμενα
	 * strings του domain 'revenue-splitter' (v1.1.3: incl. Portal).
	 */
	private static $dict = array(

		// --- Menus / σελίδες ---
		'Revenue Splitter'                                       => 'Revenue Splitter',
		'Revenue Splitter — Dashboard'                           => 'Revenue Splitter — Dashboard',
		'Revenue Splitter — Ρυθμίσεις'                           => 'Revenue Splitter — Settings',
		'Revenue Splitter — Γρήγορη ματιά'                       => 'Revenue Splitter — Quick glance',
		'Revenue Splitter — Δικαιούχοι'                          => 'Revenue Splitter — Beneficiaries',
		'Revenue Splitter — Portal'                              => 'Revenue Splitter — Portal',
		'Dashboard'                                              => 'Dashboard',
		'Ρυθμίσεις'                                              => 'Settings',
		'Portal'                                                 => 'Portal',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'       => 'You do not have permission to access this page.',

		// --- Φόρμα περιόδου / φίλτρα ---
		'Τελευταίες 7 ημέρες'                                    => 'Last 7 days',
		'Τελευταίες 30 ημέρες'                                   => 'Last 30 days',
		'Τρέχων μήνας'                                           => 'Current month',
		'Προηγούμενος μήνας'                                     => 'Previous month',
		'Τρέχον έτος'                                            => 'Current year',
		'Προηγούμενο έτος'                                       => 'Previous year',
		'Προσαρμοσμένο'                                          => 'Custom',
		'Εφαρμογή'                                               => 'Apply',
		'Εξαγωγή'                                                => 'Export',
		'Αναζήτηση σε τίτλους…'                                  => 'Search in titles…',
		'Όλα τα προϊόντα'                                        => 'All products',
		'Όλοι οι δικαιούχοι'                                     => 'All beneficiaries',
		'Προϊόν'                                                 => 'Product',
		'Περίοδος'                                               => 'Period',

		// --- KPIs ---
		'Παραγγελίες'                                            => 'Orders',
		'Παραγγελίες (περιόδου)'                                 => 'Orders (period)',
		'Μικτό (με ΦΠΑ)'                                         => 'Gross (incl. VAT)',
		'ΦΠΑ'                                                    => 'VAT',
		'Καθαρό (πριν καταμερισμό)'                              => 'Net (before split)',
		'Καθαρά κέρδη: %s'                                       => 'Net earnings: %s',

		// --- Πίνακες ---
		'Ανά προϊόν'                                             => 'Per product',
		'Καμία πωλημένη γραμμή στην περίοδο.'                    => 'No sold line items in this period.',
		'Τεμ.'                                                   => 'Qty',
		'Μικτό'                                                  => 'Gross',
		'Καθαρό'                                                 => 'Net',
		'Καταμερισμός'                                           => 'Split',
		'Σύνολα ανά δικαιούχο'                                   => 'Totals per beneficiary',
		'Δεν υπάρχουν δεδομένα δικαιούχων στην περίοδο.'         => 'No beneficiary data in this period.',
		'Ποσό'                                                   => 'Amount',
		'Προϊόν #%d'                                             => 'Product #%d',
		'ID'                                                     => 'ID',
		'ΣΥΝΟΛΑ'                                                 => 'TOTALS',
		'Σύνολο'                                                 => 'Total',
		'ΦΠΑ %'                                                  => 'VAT %',

		// --- Exports / widget ---
		'Top δικαιούχοι'                                         => 'Top beneficiaries',
		'Άνοιγμα Dashboard'                                      => 'Open Dashboard',
		'Καμία πώληση τον τρέχοντα μήνα.'                        => 'No sales in the current month.',
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

		// --- Portal (admin) ---
		'Νέο κλειδί'                                              => 'New key',
		'Access Key'                                              => 'Access Key',
		'Ο δικαιούχος μπαίνει σε σελίδα με το shortcode [rs_portal] και βλέπει ΜΟΝΟ τα δικά του κέρδη. Κανένα IP log, κανένα tracking — μόνο ένα λειτουργικό cookie token 24h.' => 'Beneficiaries log in on a page with the [rs_portal] shortcode and see ONLY their own earnings. No IP logs, no tracking — just one functional 24h cookie token.',
		'Δεν έχουν ρυθμιστεί δικαιούχοι ακόμη. Πήγαινε στις Ρυθμίσεις.' => 'No beneficiaries configured yet. Go to Settings.',
		'«Νέο κλειδί» αντικαθιστά το παλιό ΑΜΕΣΩΣ (αν κλεβεί, το ανανεώνεις και τέλος). Στείλε το με ασφαλές κανάλι.' => '«New key» replaces the old one IMMEDIATELY (if leaked, just regenerate). Send it over a secure channel.',
		'Σημείωση:'                                               => 'Note:',
		'Νέο κλειδί για: %s — το παλιό σταματά να λειτουργεί άμεσα.' => 'New key for: %s — the old one stops working immediately.',

		// --- Portal (frontend / v1.1.3 #8) ---
		'Author Portal'                                          => 'Author Portal',
		'Βάλε το προσωπικό σου κλειδί για να δεις τα καθαρά σου κέρδη.' => 'Enter your personal key to see your net earnings.',
		'Access key'                                             => 'Access key',
		'Είσοδος'                                                => 'Log in',
		'Λάθος κλειδί — δοκίμασε ξανά.'                           => 'Wrong key — try again.',
		'Καλωσόρισες, %s!'                                       => 'Welcome, %s!',
		'Ποσοστό σου'                                            => 'Your percentage',
		'Ποσό σου'                                               => 'Your amount',
		'Καμία πώληση σε αυτή την περίοδο.'                      => 'No sales in this period.',
		'Εξαγωγή CSV'                                            => 'Export CSV',
		'Αποσύνδεση'                                             => 'Log out',
		'Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.' => 'Too many failed attempts. Try again in 15 minutes.',
		'Η συνεδρία έληξε. Ξανασυνδέσου με το κλειδί σου.'        => 'Your session has expired. Log in again with your key.',
	);
}