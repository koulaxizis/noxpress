<?php
/**
 * SP_Lang — Δίγλωσσο UI (Ελληνικά / English) — ίδιος μηχανισμός με το RS_Lang.
 *
 * Μηχανισμός: gettext filter στο domain 'store-pulse'. Τα ελληνικά
 * strings είναι τα msgid σε ΟΛΟ το plugin (μοναδική πηγή αλήθειας).
 * Όταν ο τρέχων χρήστης είναι σε EN mode, το φίλτρο επιστρέφει την
 * αγγλική εκδοχή από το λεξικό.
 *
 * ΚΟΙΝΗ γλώσσα οθόνης με το Revenue Splitter: διαβάζουμε το ίδιο
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

		// Cache μόνο αφού οριστεί ο τρέχων χρήστης — αλλιώς μια πρώιμη
		// κλήση θα «κλείδωνε» τη γλώσσα του anonymous χρήστη.
		if ( null === $user_id && did_action( 'set_current_user' ) ) {
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
		'Store Pulse'                                      => 'Store Pulse',
		'Store Pulse — Dashboard'                          => 'Store Pulse — Dashboard',
		'Store Pulse — Ρυθμίσεις'                          => 'Store Pulse — Settings',
		'Dashboard'                                        => 'Dashboard',
		'Ρυθμίσεις'                                        => 'Settings',
		'SP Ρυθμίσεις'                                     => 'SP Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Το Store Pulse απαιτεί WooCommerce για να λειτουργήσει.' => 'Store Pulse requires WooCommerce to function.',

		// --- Χρονικά διαστήματα (presets) ---
		'Σήμερα'                                           => 'Today',
		'Χθες'                                             => 'Yesterday',
		'Τελευταίες 7 ημέρες'                              => 'Last 7 days',
		'Τελευταίες 15 ημέρες'                             => 'Last 15 days',
		'Τον τελευταίο μήνα'                               => 'Last month',

		// --- Κάρτες (dashboards + widget) ---
		'Εκκρεμείς παραγγελίες'                            => 'Pending orders',
		'Εξυπηρετημένες παραγγελίες'                       => 'Completed orders',
		'Εξυπηρετημένες παραγγελίες (%s)'                  => 'Completed orders (%s)',
		'Επιστροφές'                                       => 'Refunds',
		'Ακυρωμένες'                                       => 'Cancelled',
		'Χαμηλό στοκ'                                      => 'Low stock',
		'Εξαντλημένα'                                      => 'Out of stock',
		'Κέρδος εκδότη'                                    => 'Publisher profit',
		'Οφειλές προς άλλους'                              => 'Owed to others',
		'Παλιές εκκρεμείς (>7 ημέρες)'                     => 'Old pending (>7 days)',
		'Πελάτες (περίοδος)'                               => 'Customers (period)',
		'Μέση αξία παραγγελίας'                            => 'Average order value',
		'Καλύτερες πωλήσεις (%s)'                          => 'Best sellers (%s)',

		// --- Money cards (με Revenue Splitter) ---
		'Κέρδος εκδότη (περίοδος)'                         => 'Publisher profit (period)',
		'Καθαρό (μετά κρατήσεων)'                          => 'Net (after deductions)',
		'ΣΥΝΟΛΑ'                                           => 'TOTALS',
		'Δικαιούχος'                                       => 'Beneficiary',
		'Το μεταφορικό ΔΕΝ υπολογίζεται στα ποσά.'         => 'Shipping is NOT included in the amounts.',
		'Καμία οφειλή στην περίοδο.'                       => 'Nothing owed in this period.',
		'Δεν έχεις επιλέξει εκδότη — πήγαινε στις Ρυθμίσεις.' => 'You have not picked a publisher — go to Settings.',
		'Ο εκδότης δεν είναι γνωστός δικαιούχος του Revenue Splitter.' => 'The publisher is not a known Revenue Splitter beneficiary.',
		'Δεν έχει επιλεγεί εκδότης'                        => 'No publisher selected',
		'Άγνωστος εκδότης'                                 => 'Unknown publisher',

		// --- Placeholder χωρίς Revenue Splitter ---
		'Χρειάζεται το Revenue Splitter'                   => 'Requires Revenue Splitter',
		'Τα χρηματικά μεγέθη χρειάζονται το Revenue Splitter. Κάνε ενεργό και τα δύο plugins για εδώ.' => 'The money figures require Revenue Splitter. Activate both plugins to see this section.',

		// --- Πίνακες / κενά μεγέθη ---
		'Προϊόν'                                           => 'Product',
		'Τεμ.'                                             => 'Qty',
		'Στοκ'                                             => 'Stock',
		'Καμία πώληση στην περίοδο.'                       => 'No sales in this period.',
		'Όλα τα προϊόντα έχουν επαρκές απόθεμα.'           => 'All products have sufficient stock.',
		'Κανένα προϊόν χωρίς απόθεμα.'                     => 'No out-of-stock products.',

		// --- Πίνακας top sellers ---
		'Μικτό'                                            => 'Gross',

		// --- Πίνακας οφειλών ---
		'Οφειλές ανά δικαιούχο'                            => 'Owed per beneficiary',
		'Μερίδιο'                                          => 'Share',

		// --- Ρυθμίσεις ---
		'Χρονικό διάστημα εξυπηρετημένων παραγγελιών'      => 'Completed-orders period',
		'Χρονικό διάστημα κερδών & οφειλών'                => 'Profit & owed period',
		'Χρονικό διάστημα επιστροφών'                      => 'Refunds period',
		'Χρονικό διάστημα ακυρώσεων'                       => 'Cancelled period',
		'Ισχύει για την κάρτα «Εξυπηρετημένες παραγγελίες» σε dashboards και widget.' => 'Applies to the completed-orders card in dashboards and the widget.',
		'Ισχύει για τις κάρτες «Κέρδος εκδότη» και «Οφειλές προς άλλους».' => 'Applies to the publisher-profit and owed-to-others cards.',
		'Ισχύει για την κάρτα «Επιστροφές» σε dashboards και widget.'  => 'Applies to the refunds card in dashboards and the widget.',
		'Χρονικό διάστημα προβολής των ακυρωμένων παραγγελιών.'        => 'Display period for cancelled orders.',
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
		'Επιλογή χρήστη'                                   => 'User choice',
		'Η γλώσσα οθόνης επιλέγεται ανά χρήστη στις Ρυθμίσεις του Revenue Splitter και ισχύει για όλα τα Noxpress plugins. Χωρίς το Revenue Splitter ακολουθείται η γλώσσα του προφίλ σου στο WordPress.' => 'The display language is chosen per user in the Revenue Splitter settings and applies to all Noxpress plugins. Without Revenue Splitter, your WordPress profile language is used.',
		'Μη έγκυρο χρονικό διάστημα.'                      => 'Invalid time period.',
		'Μη έγκυρο όριο στοκ (0+).'                        => 'Invalid stock threshold (0+).',
		'Επίλεξε τουλάχιστον μία κάρτα για το Quick View.' => 'Pick at least one card for the Quick View.',

		// --- SP Πωλήσεις (1.6.0) ---
		'Store Pulse — Πωλήσεις ανά προϊόν' => 'Store Pulse — Sales by product',
		'SP Πωλήσεις' => 'SP Sales',
		'Περίοδος' => 'Period',
		'Τελευταίες 30 ημέρες' => 'Last 30 days',
		'Τρέχων μήνας' => 'This month',
		'Προηγούμενος μήνας' => 'Last calendar month',
		'Τρέχον έτος' => 'This year',
		'Προηγούμενο έτος' => 'Last year',
		'Από την αρχή' => 'All time',
		'Από - έως' => 'From - to',
		'Από' => 'From',
		'Έως' => 'To',
		'Οι ημερομηνίες «Από» και «Έως» ισχύουν όταν η περίοδος είναι «Από - έως».' => 'The From and To dates apply when the period is "From - to".',
		'Ποια ημερομηνία μετράει' => 'Which date counts',
		'Ημερομηνία ολοκλήρωσης' => 'Completed date',
		'Ημερομηνία πληρωμής' => 'Paid date',
		'Ημερομηνία δημιουργίας' => 'Created date',
		'Καταστάσεις παραγγελιών' => 'Order statuses',
		'Προϊόντα' => 'Products',
		'Όλα' => 'All',
		'Κατηγορίες' => 'Categories',
		'Συγκεκριμένα προϊόντα' => 'Specific products',
		'Επίλεξε κατηγορίες' => 'Choose categories',
		'Αναζήτηση προϊόντος…' => 'Search for a product…',
		'Μια κατηγορία περιλαμβάνει και τις υποκατηγορίες της. Ένα μεταβλητό προϊόν περιλαμβάνει όλες τις παραλλαγές του.' => 'A category includes its subcategories. A variable product includes all its variations.',
		'Επιλογές' => 'Options',
		'Χωριστή γραμμή για κάθε παραλλαγή' => 'Separate row for each variation',
		'Μαζί οι δωρεάν γραμμές (ποσό 0)' => 'Include free lines (amount 0)',
		'Αφαίρεση επιστροφών' => 'Subtract refunds',
		'Γραμμή συνόλων' => 'Totals row',
		'Στήλες' => 'Columns',
		'ID' => 'ID',
		'SKU' => 'SKU',
		'Τεμάχια' => 'Units',
		'Πριν την έκπτωση' => 'Before discount',
		'Έκπτωση' => 'Discount',
		'Πωλήσεις' => 'Sales',
		'ΦΠΑ' => 'VAT',
		'Καθαρό' => 'Net',
		'Επιστραμμένα τεμάχια' => 'Refunded units',
		'Ποσό επιστροφών' => 'Refunded amount',
		'Παραγγελίες' => 'Orders',
		'Τα ποσά περιλαμβάνουν ΦΠΑ. Τα μεταφορικά δεν μετρούν ποτέ.' => 'Amounts include VAT. Shipping never counts.',
		'Ταξινόμηση' => 'Sort by',
		'Όνομα' => 'Name',
		'Σειρά' => 'Order',
		'Αύξουσα' => 'Ascending',
		'Φθίνουσα' => 'Descending',
		'Μορφή CSV' => 'CSV format',
		'Excel ελληνικά (; και δεκαδικό κόμμα)' => 'Excel, Greek (; and decimal comma)',
		'Τυπικό (, και δεκαδική τελεία)' => 'Standard (, and decimal point)',
		'Προβολή' => 'Show',
		'Λήψη CSV' => 'Download CSV',
		'Προϊόντα: %1$s · Παραγγελίες: %2$s.' => 'Products: %1$s · Orders: %2$s.',
		'Εδώ φαίνονται οι πρώτες %s γραμμές· το CSV έχει όλες.' => 'The first %s rows are shown here; the CSV has them all.',
		'Ο ΦΠΑ υπολογίζεται με τον συντελεστή του Revenue Splitter για κάθε προϊόν.' => 'VAT uses the Revenue Splitter rate of each product.',
		'Ο ΦΠΑ είναι αυτός που κατέγραψε το WooCommerce στις γραμμές των παραγγελιών. Με το Revenue Splitter υπολογίζεται με τον συντελεστή κάθε προϊόντος.' => 'VAT is the tax WooCommerce stored on the order lines. With Revenue Splitter it uses the rate of each product.',
		'Σε απόθεμα' => 'In stock',
		'Εξαντλημένο' => 'Out of stock',
		'Κατόπιν παραγγελίας' => 'On backorder',
		'Μη έγκυρες ημερομηνίες. Δώσε «Από» και «Έως», με το «Από» πριν από το «Έως».' => 'Invalid dates. Enter From and To, with From before To.',
		'Επίλεξε τουλάχιστον μία κατάσταση παραγγελίας.' => 'Choose at least one order status.',
		'Επίλεξε τουλάχιστον μία κατηγορία.' => 'Choose at least one category.',
		'Επίλεξε τουλάχιστον ένα προϊόν.' => 'Choose at least one product.',
		'Επίλεξε τουλάχιστον μία στήλη.' => 'Choose at least one column.',
		'Η σελίδα έληξε. Πάτησε ξανά «Προβολή».' => 'The page expired. Press Show again.',

		// --- Footer / widget / branding ---
		'Made with ❤ by %s'                                => 'Made with ❤ by %s',
		'More plugins at'                                  => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                   => '☕ Support the project on Ko-fi',
		'🤓 Dashboard'                                     => '🤓 Dashboard',
		'Noxpress Dashboard'                               => 'Noxpress Dashboard',
		'☕ Ko-fi'                                          => '☕ Ko-fi',

	);

}