<?php
/**
 * SF_Lang — Domain dict + gettext filter (pattern RS_Lang, compact).
 *
 * Το rs_lang (user meta) ΔΙΑΒΑΖΕΤΑΙ μόνο (Bible §9): 'el' | 'en' | ''/'auto'.
 * Το γράφει αποκλειστικά το Revenue Splitter. Χωρίς ρητή επιλογή (ή χωρίς
 * RS) ακολουθούμε το locale του χρήστη: el* → Ελληνικά (τα msgids
 * περνούν ως έχουν), οτιδήποτε άλλο → English από το dict.
 *
 * Φορτώνεται ΠΑΝΤΑ (και χωρίς WooCommerce) ώστε και το admin notice
 * «λείπει το WooCommerce» να μεταφράζεται.
 *
 * Τα keys του dict είναι byte-identical με τα msgids του κώδικα
 * (ελέγχεται με script σε κάθε release — κανένα λατινικό γράμμα μέσα
 * σε ελληνικές λέξεις, κανένα διπλότυπο).
 */

defined( 'ABSPATH' ) || exit;

final class SF_Lang {

	/** Cache ανά request: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		// Menu / σελίδες.
		'Smart Formatter'                                         => 'Smart Formatter',
		'Smart Formatter — Ρυθμίσεις'                             => 'Smart Formatter — Settings',
		'SF Ρυθμίσεις'                                            => 'SF Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'         => 'You do not have access to this page.',
		'Το Smart Formatter χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Smart Formatter requires WooCommerce to be active — the plugin stays inactive until it is activated.',

		// Εργαλείο — ενότητες.
		'Μαζική μορφοποίηση κειμένων προϊόντων. Το κείμενο σε HTML tags, attributes, shortcodes, code/pre blocks και entities δεν αγγίζεται ποτέ.' => 'Bulk-format product texts. Text inside HTML tags, attributes, shortcodes, code/pre blocks and entities is never touched.',
		'1. Στόχος'                                               => '1. Target',
		'Όλα τα δημοσιευμένα προϊόντα'                            => 'All published products',
		'Ξεχωριστά προϊόντα'                                      => 'Specific products',
		'Κατηγορίες'                                              => 'Categories',
		'Ετικέτες'                                                => 'Tags',
		'Αναζήτηση προϊόντος…'                                    => 'Search products…',
		'Ctrl/Cmd + click για πολλαπλή επιλογή.'                  => 'Ctrl/Cmd + click for multiple selection.',
		'Επιλογή όρων (πολλαπλή).'                                => 'Select terms (multiple).',
		'2. Πεδία'                                                => '2. Fields',
		'Σημείωση: στις ιδιότητες μορφοποιούνται μόνο τα custom (όχι τα global) — τα global είναι κοινά όροι σε όλο το κατάστημα.' => 'Note: only custom attributes are formatted (not global ones) — global attributes are shared terms across the whole store.',
		'3. Κανόνες'                                              => '3. Rules',
		'Η αφαίρεση μορφοποίησης τρέχει πρώτη· τα «όλο bold/italic» τελευταία — κάθε συνδυασμός δίνει έγκυρο HTML.' => 'Stripping runs first; "all bold/italic" rules run last — every combination yields valid HTML.',
		'4. Προφίλ'                                               => '4. Profiles',
		'— Φόρτωση προφίλ —'                                      => '— Load profile —',
		'Όνομα νέου προφίλ'                                       => 'New profile name',
		'Αποθήκευση κανόνων/πεδίων ως προφίλ'                     => 'Save rules/fields as profile',
		'Διαγραφή προφίλ'                                         => 'Delete profile',
		'Δεν υπάρχουν αποθηκευμένα προφίλ.'                       => 'No saved profiles yet.',
		'5. Εξαιρέσεις φράσεων (εκτός από τα global)'             => '5. Phrase exclusions (besides global)',
		'Μία φράση ανά γραμμή (προαιρετικό)'                      => 'One phrase per line (optional)',
		'6. Εκτέλεση'                                             => '6. Run',
		'Preview (πρώτα 5)'                                       => 'Preview (first 5)',
		'Dry Run (καταμέτρηση)'                                   => 'Dry Run (count only)',
		'Εφαρμογή'                                                => 'Apply',
		'Αποτελέσματα'                                            => 'Results',
		'Στοιχείο'                                                => 'Item',
		'Πεδίο'                                                   => 'Field',
		'Πριν'                                                    => 'Before',
		'Μετά'                                                    => 'After',

		// Πεδία (SF_Targets::fields labels).
		'Σύντομη περιγραφή'                                       => 'Short description',
		'Αναλυτική περιγραφή'                                     => 'Long description',
		'Σημείωση αγοράς (purchase note)'                         => 'Purchase note',
		'Ιδιότητες (custom μόνο)'                                 => 'Attributes (custom only)',
		'Περιγραφές κατηγοριών/ετικετών'                          => 'Category/tag descriptions',

		// Κανόνες (SF_Rules::registry labels).
		'Πλήρης αφαίρεση μορφοποίησης'                            => 'Strip all formatting',
		'Κανονικοποίηση κενών'                                    => 'Normalize whitespace',
		'Περιεχόμενο εισαγωγικών italic'                          => 'Quoted text italic',
		'Περιεχόμενο εισαγωγικών bold'                            => 'Quoted text bold',
		'Περιεχόμενο παρενθέσεων italic'                          => 'Parentheses italic',
		'Αριθμοί bold'                                            => 'Numbers bold',
		'Όλο το κείμενο italic'                                   => 'All text italic',
		'Όλο το κείμενο bold'                                     => 'All text bold',

		// Ιστορικό & Undo.
		'Ιστορικό & Undo'                                         => 'History & Undo',
		'Run'                                                     => 'Run',
		'Ημερομηνία'                                              => 'Date',
		'Κατάσταση'                                               => 'Status',
		'Αλλαγές'                                                 => 'Changes',
		'Ενέργειες'                                               => 'Actions',
		'Δεν υπάρχουν runs ακόμη.'                                => 'No runs yet.',
		'Ολοκληρώθηκε'                                            => 'Completed',
		'Σε εξέλιξη'                                              => 'Running',
		'μερικό (περικομμένο)'                                    => 'partial (truncated)',
		'Δες μονάδες'                                             => 'View units',
		'Επαναφορά όλων'                                          => 'Restore all',
		'Επαναφορά διαθέσιμων'                                    => 'Restore available',
		'Διαγραφή'                                                => 'Delete',

		// JS (wp_localize_script).
		'Είσαι σίγουρος; Θα μορφοποιηθούν τα επιλεγμένα κείμενα — δημιουργείται snapshot για undo.' => 'Are you sure? The selected texts will be formatted — a snapshot is created for undo.',
		'Επεξεργασία…'                                            => 'Working…',
		'Ολοκληρώθηκε!'                                           => 'Done!',
		'Απέτυχε — δοκίμασε ξανά.'                                => 'Failed — try again.',
		'Καμία αλλαγή δεν εντοπίστηκε.'                           => 'No changes detected.',
		'Διαγραφή του snapshot; Δεν επηρεάζει τα προϊόντα — χάνεται μόνο η δυνατότητα undo.' => 'Delete snapshot? Products are not affected — only the undo option is lost.',
		'Θα αλλάξουν %1$s από %2$s στοιχεία του δείγματος (πρώτα 5).' => '%1$s of %2$s sampled items would change (first 5).',
		'Θα αλλάξουν %1$s από %2$s στοιχεία. Δείγμα παρακάτω.'    => '%1$s of %2$s items would change. Sample below.',
		'Άλλαξαν %1$s στοιχεία.'                                  => '%1$s items changed.',
		'Επαναφορά όλων των αποθηκευμένων τιμών αυτού του run στα προϊόντα;' => 'Restore all saved values of this run to the products?',
		'Το snapshot είναι μερικό (περικομμένο): θα επαναφερθούν ΜΟΝΟ τα units που κρατήθηκαν. Συνέχεια;' => 'This snapshot is partial (truncated): ONLY the kept units will be restored. Continue?',
		'Επαναφορά των επιλεγμένων units στα προϊόντα;'           => 'Restore the selected units to the products?',
		'Επαναφορά επιλεγμένων'                                   => 'Restore selected',
		'Επαναφέρθηκαν %1$s units· παραλείφθηκαν %2$s.'           => '%1$s units restored; %2$s skipped.',
		'ΜΕΡΙΚΗ επαναφορά: το snapshot ήταν περικομμένο — τα παλαιότερα units δεν είχαν κρατηθεί.' => 'PARTIAL restore: the snapshot was truncated — the oldest units had not been kept.',
		'Προϊόν'                                                  => 'Product',
		'Όρος'                                                    => 'Term',
		'Αποθηκεύτηκε.'                                           => 'Saved.',
		'Διάλεξε ένα προφίλ.'                                     => 'Pick a profile.',

		// AJAX μηνύματα.
		'Διάλεξε τουλάχιστον έναν κανόνα και ένα πεδίο.'          => 'Pick at least one rule and one field.',
		'Μη έγκυρο run id.'                                       => 'Invalid run id.',
		'Άγνωστη φάση.'                                           => 'Unknown phase.',
		'Δεν έχεις δικαίωμα.'                                     => 'You do not have permission.',
		'Άγνωστος τύπος αναζήτησης.'                              => 'Unknown search type.',
		'Δώσε όνομα προφίλ.'                                      => 'Enter a profile name.',
		'Η αποθήκευση απέτυχε.'                                   => 'Saving failed.',
		'Το προφίλ δεν βρέθηκε.'                                  => 'Profile not found.',
		'Το snapshot δεν βρέθηκε.'                                => 'Snapshot not found.',
		'Τίποτα δεν επαναφέρθηκε.'                                => 'Nothing was restored.',

		// Targets / Snapshots σφάλματα.
		'Το προϊόν δεν βρέθηκε.'                                  => 'Product not found.',
		'Ο όρος δεν βρέθηκε.'                                     => 'Term not found.',
		'Αποτυχία ενημέρωσης όρου: %s'                            => 'Failed to update term: %s',
		'Το προϊόν #%d δεν βρέθηκε — δεν επαναφέρθηκε.'           => 'Product #%d not found — not restored.',
		'Ο όρος #%d δεν βρέθηκε — δεν επαναφέρθηκε.'              => 'Term #%d not found — not restored.',
		'Αποτυχία επαναφοράς όρου: %s'                            => 'Failed to restore term: %s',

		// Ρυθμίσεις / Backup.
		'Καθολικές εξαιρέσεις φράσεων'                            => 'Global phrase exclusions',
		'Φράσεις που δεν μορφοποιούνται ποτέ, σε οποιοδήποτε run (π.χ. τίτλοι έργων). Μία ανά γραμμή. Συνδυάζονται με τυχόν per-run εξαιρέσεις στη σελίδα του εργαλείου.' => 'Phrases that are never formatted, in any run (e.g. titles of works). One per line. They are combined with any per-run exclusions on the tool page.',
		'The Pleiades and the Morning Star'                       => 'The Pleiades and the Morning Star',
		'Αποθήκευση ρυθμίσεων'                                    => 'Save settings',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                             => 'Settings saved.',
		'Backup & Επαναφορά'                                      => 'Backup & Restore',
		'Εισαγωγή state (JSON)'                                   => 'Import state (JSON)',
		'Εξαγωγή state (JSON)'                                    => 'Export state (JSON)',
		'Συμπεριλαμβάνονται: προφίλ, καθολικές εξαιρέσεις και ιστορικό snapshots (με τα undo payloads). Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τα αντίστοιχα δεδομένα. Τα Undo εκτελούνται από τη σελίδα του εργαλείου.' => 'Included: profiles, global exclusions and snapshot history (with undo payloads). Importing REPLACES the corresponding data. Undo runs from the tool page.',
		'Δεν επιλέχθηκε αρχείο JSON.'                             => 'No JSON file selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                        => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν τα options).'           => 'Invalid backup file (options missing).',
		'Μη έγκυρο blob προφίλ.'                                  => 'Invalid profiles blob.',
		'Μη έγκυρο blob εξαιρέσεων.'                              => 'Invalid exclusions blob.',
		'Μη έγκυρο blob ιστορικού snapshots.'                     => 'Invalid snapshot history blob.',
		'Παραλείφθηκαν %d units ιστορικού (άκυρα ή για προϊόντα/όρους που δεν υπάρχουν).' => '%d history units were skipped (invalid, or for products/terms that no longer exist).',
		'Η εισαγωγή ολοκληρώθηκε.'                                => 'Import completed.',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ — τα άκυρα τμήματα ΔΕΝ αντικαταστάθηκαν.' => 'Import completed PARTIALLY — invalid sections were NOT replaced.',

		// Footer.
		'Made with ❤ by %s'                                       => 'Made with ❤ by %s',
		'Noxpress Dashboard'                                      => 'Noxpress Dashboard',
		'More plugins at'                                         => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                          => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_smart-formatter', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' για τον τρέχοντα χρήστη (rs_lang → locale fallback). */
	public static function lang(): string {
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		$uid    = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$choice = $uid ? (string) get_user_meta( $uid, 'rs_lang', true ) : '';
		if ( 'el' === $choice || 'en' === $choice ) {
			$lang = $choice;
		} else {
			$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = ( 0 === strpos( strtolower( (string) $locale ), 'el' ) ) ? 'el' : 'en';
		}
		// Cache μόνο όταν ο χρήστης είναι πια γνωστός (όχι πριν το set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_smart-formatter filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'smart-formatter' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
