<?php
/**
 * SP_Lang — Δίγλωσσο UI (Ελληνικά / English) — ίδιος μηχανισμός με το RS_Lang.
 *
 * Μηχανισμός: gettext filter στο domain 'store-pulse'. Τα ελληνικά
 * strings είναι τα msgid σε ΟΛΟ το plugin (μοναδική πηγή αλήθειας).
 * Όταν ο τρέχων χρήστης είναι σε EN mode, το φίλτρο επιστρέφει την
 * αγγλική εκδοχή από το λεξικό.
 *
 * ΚΟΙΝΗ γλώσσα οθόνη με το Revenue Splitter: διαβάζουμε το ίδιο
 * user meta 'rs_lang'. Έτσι όποιος διάλεξε γλώσσα στο RS τη βλέπει
 * και στο Store Pulse — μία επιλογή, όλο το οικοσύστημα.
 *
 *  - rs_lang = 'en'  → EN λεξικό ( μετάφραση των ελληνικών msgids ).
 *  - rs_lang = 'el'  → Ελληνικά (msgid ως έχει).
 *  - κενό / auto     → ακολουθείται το WordPress locale
 *                      (el* → Ελληνικά, οτιδήποτε άλλο → English).
 *
 * Το user meta 'rs_lang' ΔΕΝ γράφεται ποτέ από εδώ (δεν υπάρχει
 * ρύθμιση γλώσσας στο Store Pulse) — αλλάζει ΜΟΝΟ από το RS. Αν το
 * RS δεν είναι εγκατεστημένο, το meta απλώς δεν υπάρχει → auto mode.
 */

defined( 'ABSPATH' ) || exit;

final class SP_Lang {

	/** Ίδιο user meta με το Revenue Splitter — κοινή επιλογή γλώσσας. */
	const USER_META = 'rs_lang';

	/** Cache ανά request — αποφεύγει επαναλαμβανόμενα get_user_meta(). */
	private static $current = null;

	public static function init(): void {
		add_filter( 'gettext', array( __CLASS__, 'filter_gettext' ), 10, 3 );
	}

	/**
	 * Η ρητή επιλογή του χρήστη: 'el' | 'en' | 'auto' (default).
	 *
	 * Διαβάζει ΑΜΕΣΑ το κοινό 'rs_lang' user meta — δεν αφορά το
	 * option του RS· αν ο χρήστης δεν έχει επιλογή, 'auto'.
	 */
	public static function get_choice( ?int $user_id = null ): string {

		$uid  = $user_id ?? get_current_user_id();
		$lang = $uid ? (string) get_user_meta( $uid, self::USER_META, true ) : '';

		return in_array( $lang, array( 'el', 'en' ), true ) ? $lang : 'auto';
	}

	/**
	 * Η ΕΝΕΡΓΗ γλώσσα ('el' | 'en') — για rendering και exports.
	 *
	 * 'auto' ακολουθεί το WordPress locale:
	 *  get_user_locale() → el* → 'el', οτιδήποτε άλλο → 'en'.
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
	 * Το ίδιο το «μετάφρασμα»: όταν ο χρήστης είναι σε EN mode,
	 * αντικαθιστά τα ελληνικά msgids του domain 'store-pulse' με
	 * αγγλικά. Σε 'el'/auto-el mode επιστρέφει το msgid ως έχει.
	 */
	public static function filter_gettext( $translation, $text, $domain ) {

		if ( 'store-pulse' !== $domain ) {
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
	 * Ημερομηνία σε εμφανιζόμενη μορφή (DISPLAY-ONLY).
	 *
	 * 'el' → ηη/μμ/εεεε, κάθε άλλη γλώσσα → ανέπαφο 'Y-m-d'.
	 * Οι εσωτερικές τιμές (GET params, SQL, transients, filenames)
	 * παραμένουν ΠΑΝΤΑ 'Y-m-d' — καμία μετάλλαξη δεδομένων.
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
	 * domain 'store-pulse'. Ίδια πειθαρχία με το RS_Lang: κάθε string
	 * που παράγει κάποιο markup εδώ, έχει entry.
	 */
	private static $dict = array(

		// --- Menus / σελίδες ---
		'Noxpress'                                        => 'Noxpress',
		'Store Pulse'                                      => 'Store Pulse',
		'Store Pulse — Dashboard'                          => 'Store Pulse — Dashboard',
		'Store Pulse — Ρυθμίσεις'                          => 'Store Pulse — Settings',
		'Store Pulse — Γρήγορη ματιά'                      => 'Store Pulse — Quick glance',
		'Store Pulse:'                                     => 'Store Pulse:',
		'Dashboard'                                        => 'Dashboard',
		'Ρυθμίσεις'                                        => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Πλήρες dashboard →'                               => 'Full dashboard →',
		'Το Store Pulse απαιτεί WooCommerce για να λειτουργήσει.' => 'Store Pulse requires WooCommerce to function.',

		// --- Χρονικά διαστήματα (presets) ---
		'Σήμερα'                                           => 'Today',
		'Χθες'                                             => 'Yesterday',
		'Τελευταίες 7 ημέρες'                              => 'Last 7 days',
		'Τελευταίες 15 ημέρες'                             => 'Last 15 days',
		'Τον τελευταίο μήνα'                               => 'Last month',
		'Εφαρμογή'                                         => 'Apply',

		// --- Κάρτες (dashboards + widget) ---
		'Εκκρεμείς παραγγελίες'                            => 'Pending orders',
		'Εξυπηρετημένες παραγγελίες'                       => 'Completed orders',
		'Επιστροφές'                                       => 'Refunds',
		'Ακυρωμένες'                                       => 'Cancelled',
		'Χαμηλό στοκ'                                      => 'Low stock',
		'Εξαντλημένα'                                      => 'Out of stock',
		'Κέρδος εκδότη'                                    => 'Publisher profit',
		'Οφειλές προς άλλους'                              => 'Owed to others',
		'Παλιές εκκρεμείς (>7 ημέρες)'                     => 'Old pending (>7 days)',
		'Πελάτες (περίοδος)'                               => 'Customers (period)',
		'Μέση αξία παραγγελίας'                            => 'Average order value',
		'Καλύτερες πωλήσεις (περίοδος)'                    => 'Best sellers (period)',
		'Παραγγελίες'                                      => 'Orders',
		'Πελάτες'                                          => 'Customers',

		// --- Money cards (με Revenue Splitter) ---
		'Κέρδος εκδότη (περίοδος)'                         => 'Publisher profit (period)',
		'Καθαρό (μετά κρατήσεων)'                          => 'Net (after deductions)',
		'Οφειλές προς άλλους'                              => 'Owed to others',
		'Οφειλές ανά δικαιούχο'                            => 'Owed per beneficiary',
		'Δικαιούχος'                                       => 'Beneficiary',
		'Ποσό'                                             => 'Amount',
		'Το μεταφορικό ΔΕΝ υπολογίζεται στα ποσά.'         => 'Shipping is NOT included in the amounts.',
		'Καμία οφειλή στην περίοδο.'                       => 'Nothing owed in this period.',
		'Καμία οικονομική κίνηση στην περίοδο.'            => 'No money movement in this period.',
		'Δεν έχεις επιλέξει εκδότη — πήγαινε στις Ρυθμίσεις.' => 'You have not picked a publisher — go to Settings.',
		'Ο εκδότης δεν είναι γνωστός δικαιούχος του Revenue Splitter.' => 'The publisher is not a known Revenue Splitter beneficiary.',

		// --- Placeholder χωρίς Revenue Splitter ---
		'Χρειάζεται το Revenue Splitter'                   => 'Requires Revenue Splitter',
		'Τα χρηματικά μεγέθη χρειάζονται το Revenue Splitter. Κάντε ενεργό και τα δύο plugins για εδώ.' => 'The money figures require Revenue Splitter. Activate both plugins to see this section.',
		'Άνοιγμα Revenue Splitter →'                       => 'Open Revenue Splitter →',

		// --- Πίνακες / κενά μεγέθη ---
		'Προϊόν'                                           => 'Product',
		'Τεμ.'                                             => 'Qty',
		'Καθαρό'                                           => 'Net',
		'Ποσό'                                             => 'Amount',
		'Σύνολο'                                           => 'Total',
		'ΣΥΝΟΛΑ'                                           => 'TOTALS',
		'Σύνολο'                                           => 'Total',
		'Ποσότητα'                                         => 'Quantity',
		'Στοκ'                                             => 'Stock',
		'Καμία πώληση στην περίοδο.'                       => 'No sales in this period.',
		'Καμία εκκρεμότητα.'                               => 'Nothing pending.',
		'Όλα τα προϊόντα έχουν επαρκές απόθεμα.'           => 'All products have sufficient stock.',
		'Κανένα προϊόν χωρίς απόθεμα.'                     => 'No out-of-stock products.',
		'Καμία επιστροφή στην περίοδο.'                    => 'No refunds in this period.',
		'Καμία ακύρωση στην περίοδο.'                      => 'No cancellations in this period.',
		'∞'                                                => '∞',

		// --- Πίνακας top sellers ---
		'Καλύτερες πωλήσεις'                               => 'Best sellers',
		'Προϊόν'                                           => 'Product',
		'Μικτό'                                            => 'Gross',
		'Καθαρό (πριν καταμερισμό)'                        => 'Net (before split)',

		// --- Πίνακας κερδών ---
		'Καθαρό (πριν καταμερισμό)'                        => 'Net (before split)',
		'Δικαιούχος'                                       => 'Beneficiary',
		'Ποσοστό'                                          => 'Percent',
		'Μερίδιο'                                          => 'Share',
		'Περίοδος'                                         => 'Period',

		// --- Ρυθμίσεις ---
		'Ρυθμίσεις'                                        => 'Settings',
		'Χρονικό διάστημα εξυπηρετημένων παραγγελιών'      => 'Completed-orders period',
		'Χρονικό διάστημα κερδών & οφειλών'                => 'Profit & owed period',
		'Χρονικό διάστημα επιστροφών'                      => 'Refunds period',
		'Χρονικό διάστημα ακυρώσεων'                       => 'Cancelled period',
		'Ισχύει για την κάρτα «Εξυπηρετημένες παραγγελίες» σε dashboards και widget.' => 'Applies to the completed-orders card in dashboards and the widget.',
		'Ισχύει για τις κάρτες «Κέρδος εκδότη» και «Οφειλές προς άλλους».' => 'Applies to the publisher-profit and owed-to-others cards.',
		'Ισχύει για την κάρτα «Επιστροφές» σε dashboards και widget.'  => 'Applies to the refunds card in dashboards and the widget.',
		'Χρονικό διάστημα προβολής των ακυρωμένων παραγγελιών.'        => 'Display period for cancelled orders.',
		'Χρονικό διάστημα συνολικού κέρδους εκδότη και εκκρεμών οφειλών προς άλλους δικαιούχους.' => 'Profit and owed-to-others display period.',
		'Όριο χαμηλού στοκ (τεμάχια)'                      => 'Low-stock threshold (units)',
		'Ένα προϊόν θεωρείται σε χαμηλό στοκ όταν το απόθεμά του είναι ≤ αυτό το νούμερο. Το «0» θεωρείται εξαντλημένο (ξεχωριστή κάρτα).' => 'A product counts as low-stock when its stock is at or below this number. "0" counts as out of stock (separate card).',
		'Δικαιούχος — εκδότης'                             => 'Beneficiary — publisher',
		'Ο δικαιούχος του Revenue Splitter για τον οποίο εμφανίζεται το κέρδος. Πρέπει να υπάρχει ήδη στις Ρυθμίσεις του Revenue Splitter.' => 'The Revenue Splitter beneficiary who is the publisher. Configure it in Revenue Splitter first.',
		'Κάρτες Quick View (widget)'                       => 'Quick-view cards (widget)',
		'Επίλεξε ποιες κάρτες εμφανίζονται στο Quick View widget της αρχικής σελίδας του WordPress.' => 'Choose which cards appear in the WordPress home Quick View widget.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                      => 'Settings saved.',
		'Αποθήκευση ρυθμίσεων'                             => 'Save settings',
		'Γλώσσα οθόνης'                                    => 'Display language',
		'Αυτόματη (WordPress)'                             => 'Automatic (WordPress)',
		'Ισχύει ανά χρήστη (μόνο για εσένα). Διαβάζεται η ίδια ρύθμιση με του Revenue Splitter — η αλλαγή εδώ ισχύει και εκεί.' => 'Per user (only affects you). The same setting is read by Revenue Splitter — a change here applies there too.',
		'Mη έγκυρο χρονικό διάστημα.'                      => 'Invalid time period.',
		'Μη έγκυρο όριο στοκ (0+).'                        => 'Invalid stock threshold (0+).',
		'Επίλεξε τουλάχιστον μία κάρτα για το Quick View.' => 'Pick at least one card for the Quick View.',

		// --- Quick View widget ---
		'Quick View'                                       => 'Quick View',
		'Σήμερα'                                           => 'Today',
		'Προβολή Quick View στις Ρυθμίσεις του Store Pulse.' => 'Configure Quick View in Store Pulse Settings.',
		'Πηγαίνετε στις Ρυθμίσεις'                         => 'Go to Settings',

		// --- Footer / branding ---
		'Made with <3 by %s'                               => 'Made with <3 by %s',
		'Part of glarolykoi.net'                           => 'Part of glarolykoi.net',
		'More plugins at'                                  => 'More plugins at',

		// --- Misc labels ---
		'Περίοδος'                                         => 'Period',
		'Εξαγωγή CSV'                                      => 'Export CSV',
		'Ημ/νία: %1$s → %2$s'                              => 'Period: %1$s → %2$s',
		'Τεμάχια'                                          => 'Units',
		'Στο όριο τεμαχίων: %s'                            => 'Threshold units: %s',
		'Σύγκριση με προηγούμενο διάστημα'                 => 'Compared to the previous period',
		'Κέρδος (περίοδος)'                                => 'Profit (period)',
		'Οφειλόμενο (περίοδος)'                            => 'Owed (period)',
	);

	/** Λεξικό Ελληνικά → English — consuming στη δομή 'msgid' => 'english'. */
	private static $dict = array(
		/* ...ίδιο περιεχόμενο με παραπάνω... */
	);
}