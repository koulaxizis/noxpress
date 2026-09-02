<?php
/**
 * Δίγλωσσο UI (Ελληνικά / English) — ανά χρήστη.
 *
 * Μηχανισμός: το WordPress « gettext » filter. Τα ελληνικά strings είναι
 * το msgid σε όλο το plugin (μοναδική πηγή αλήθειας). Όταν ο τρέχων χρήστης
 * έχει επιλέξει «en» στα ρυθμίσεις του plugin, το φίλτρο αντικαθιστά το
 * κείμενο με την αγγλική του εκδοχή. Χωρίς αλλαγές στα υπόλοιπα classes.
 *
 * Επιλογή γλώσσας: user meta 'rs_lang' ('el' | 'en') — αποθηκεύεται
 * από τη σελίδα Ρυθμίσεων του plugin (RS_Admin_UI::render_settings).
 *
 * AUDIT FIX v1.1.2:
 *  - set_lang() μηδενίζει το request-level cache ($current) ώστε η αλλαγή
 *    γλώσσας να ισχύει ΑΜΕΣΩΣ στο ίδιο request.
 *  - Λεξικό εμπλουτισμένο με ΟΛΑ τα strings του dashboard/widget/exports
 *    (Εξαγωγή, Αναζήτηση, Top δικαιούχοι, ΣΥΝΟΛΑ κ.λπ.).
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

	/** Ορίσε τη γλώσσα χρήστη (μόνο 'el'/'en' γίνονται δεκτά). */
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
	 * Λεξικό Ελληνικά → English. Πρέπει να καλύπτει ΟΛΑ τα εμφανιζόμενα
	 * strings του domain 'revenue-splitter' (κρατάμε και τα untranslated
	 * msgids με ενιαίο στυλ για μελλοντική συντήρηση).
	 */
	private static $dict = array(

		// --- Menus / σελίδες ---
		'Revenue Splitter'                                       => 'Revenue Splitter',
		'Revenue Splitter — Dashboard'                           => 'Revenue Splitter — Dashboard',
		'Revenue Splitter — Ρυθμίσεις'                           => 'Revenue Splitter — Settings',
		'Revenue Splitter — Γρήγορη ματιά'                       => 'Revenue Splitter — Quick glance',
		'Revenue Splitter — Δικαιούχοι'                          => 'Revenue Splitter — Beneficiaries',
		'Dashboard'                                              => 'Dashboard',
		'Ρυθμίσεις'                                              => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'       => 'You do not have permission to access this page.',

		// --- Φόρμα περιόδου / φίλτρα ---
		'Τελευταίες 7 ημέρες'                                    => 'Last 7 days',
		'Τελευταίες 30 ημέρες'                                   => 'Last 30 days',
		'Τρέχων μήνας'                                           => 'Current month',
		'Τρέχον έτος'                                            => 'Current year',
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
		'SΥΝΟΛΑ'                                                 => 'TOTALS',
		'ΣΥΝΟΛΑ'                                                 => 'TOTALS',
		'ΦΠΑ %'                                                  => 'VAT %',

		// --- Exports / widget ---
		'Top δικαιούχοι'                                         => 'Top beneficiaries',
		'Άνοιγμα Dashboard'                                      => 'Open Dashboard',
		'Καμία πώληση τον τρέχοντα μήνα.'                        => 'No sales in the current month.',
		'global defaults'                                        => 'global defaults',

		// --- Ρυθμίσεις ---
		'Default ΦΠΑ (%)'                                        => 'Default VAT (%)',
		'Iσχύει για προϊόντα χωρίς δικό τους ΦΠΑ στο General tab.' => 'Applies to products without their own VAT in the General tab.',
		'Global Δικαιούχοι'                                      => 'Global Beneficiaries',
		'Ο προεπιλεγμένος καταμερισμός για κάθε προϊόν χωρίς δικό του override.' => 'The default split for any product without its own override.',
		'Μη έγκυρο global default ΦΠΑ (0–100).'                  => 'Invalid global default VAT (0–100).',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                            => 'Settings saved.',
		'Αποθήκευση ρυθμίσεων'                                   => 'Save settings',
		'Γλώσσα οθόνης'                                          => 'Display language',
		'Iσχύει ανά χρήστη (μόνο για εσένα).'                    => 'Per user (only affects you).',

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
		'Mη έγκυρη λίστα δικαιούχων.'                            => 'Invalid beneficiaries list.',
		'Τα ποσοστά δικαιούχων αθροίζουν %s%% — πρέπει να αθροίζουν 100%%.' => 'Beneficiary percentages add up to %s%% — they must sum to 100%%.',
		'Το Revenue Splitter απαιτεί WooCommerce για να λειτουργήσει.' => 'Revenue Splitter requires WooCommerce to function.',
	);
}