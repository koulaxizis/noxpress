<?php
/**
 * PL_Lang — domain dictionary + gettext filter (pattern RDR_Lang).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitors never see a text of this plugin.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release: no missing keys, no stale ones).
 */

defined( 'ABSPATH' ) || exit;

final class PL_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'%d δοκιμαστικές καταχωρίσεις.' => '%d test entries.',
		'%d δοκιμαστική καταχώριση.' => '%d test entry.',
		'%d παραγγελία' => '%d order',
		'%d παραγγελίες' => '%d orders',
		'Excel ελληνικά (; και δεκαδικό κόμμα)' => 'Excel Greek (; and decimal comma)',
		'ID προϊόντος' => 'Product ID',
		'ISBN/άδειες' => 'ISBN/licences',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'Modules' => 'Modules',
		'More plugins at' => 'More plugins at',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Printful, παραγγελία #%s' => 'Printful, order #%s',
		'Store ID στο Printful (μόνο για κλειδί λογαριασμού)' => 'Printful store ID (only for an account-level key)',
		'orOS Budget' => 'orOS Budget',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Άκυρη ημερομηνία έναρξης: έμεινε η προηγούμενη.' => 'Invalid start date: the previous one stays.',
		'Άκυρη κατηγορία.' => 'Invalid category.',
		'Άκυρο πλήθος.' => 'Invalid quantity.',
		'Άκυρος μήνας.' => 'Invalid month.',
		'Ένα προϊόν' => 'One product',
		'Έσοδα (καθαρά)' => 'Revenue (net)',
		'Έσοδα εκτός site' => 'Off-site revenue',
		'Έτος' => 'Year',
		'Ήχος/mastering' => 'Sound/mastering',
		'Όλες οι αποστολές του μήνα' => 'All shipments of the month',
		'Όλες οι κατηγορίες' => 'All categories',
		'Α/Α' => 'No.',
		'Ακύρωση' => 'Cancel',
		'Αλλαγή' => 'Edit',
		'Αμοιβές δημιουργών (RS)' => 'Creator payouts (RS)',
		'Ανά προϊόν' => 'Per product',
		'Αναίρεση' => 'Undo',
		'Αναζήτηση προϊόντος…' => 'Search product…',
		'Αναλώσιμα' => 'Consumables',
		'Αναλώσιμα & προμήθειες' => 'Consumables & fees',
		'Αναλώσιμα (μερίδιο)' => 'Consumables (share)',
		'Αντίγραφο ρυθμίσεων' => 'Settings backup',
		'Αποθήκευση' => 'Save',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Αποστολή ±' => 'Shipping ±',
		'Αρ. παραστατικού' => 'Invoice no.',
		'Γενικά έξοδα' => 'General expenses',
		'Γενικό' => 'General',
		'Γενικό έξοδο' => 'General expense',
		'Γράψε 3 γράμματα ή το ID' => 'Type 3 letters or the ID',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You are not allowed to access this page.',
		'Δεν έχεις ορίσει ποιοι δικαιούχοι του Revenue Splitter είναι ο ίδιος ο εκδότης: όλα τα μερίδια μετριούνται ως αμοιβές δημιουργών.' => 'You have not set which Revenue Splitter beneficiaries are the publisher itself: every share counts as a creator payout.',
		'Δεν βρέθηκαν δικαιούχοι (το Revenue Splitter δεν είναι ενεργό).' => 'No beneficiaries found (Revenue Splitter is not active).',
		'Δεν είναι αντίγραφο ρυθμίσεων του Production Ledger: τίποτα δεν άλλαξε.' => 'This is not a Production Ledger settings backup: nothing changed.',
		'Δημιουργοί' => 'Creators',
		'Διάλεξε προϊόν (γράψε 3 γράμματα του τίτλου ή το ID του).' => 'Choose a product (type 3 letters of its title or its ID).',
		'Διέγραψες μια καταχώριση.' => 'You deleted an entry.',
		'Διαγραμμένες' => 'Deleted',
		'Διαγραφή' => 'Delete',
		'Διαγραφή του plugin' => 'Plugin deletion',
		'Διαγραφή του αποθηκευμένου κλειδιού' => 'Delete the stored key',
		'Διαγραφή όλων των καταχωρίσεων όταν διαγραφεί το plugin' => 'Delete all entries when the plugin is deleted',
		'Δοκιμαστικές καταχωρίσεις' => 'Test entries',
		'Δοκιμαστική (δεν μετρά στην αναφορά ούτε πάει στο orOS)' => 'Test (does not count in the report and does not go to orOS)',
		'Δύο αρχεία: ένα με κάθε καταχώριση και ένα με το αποτέλεσμα κάθε προϊόντος, τα γενικά έξοδα και το τελικό σύνολο. Ανοίγουν στο Excel, στο Υπολογιστικό φύλλο και στην εισαγωγή του orOS Budget.' => 'Two files: one with every entry and one with the result of each product, the general expenses and the final total. They open in Excel, in spreadsheets and in the orOS Budget import.',
		'Είδος' => 'Type',
		'Εισαγωγή' => 'Import',
		'Εκδηλώσεις' => 'Events',
		'Εκτός site' => 'Off-site',
		'Εκτύπωση' => 'Printing',
		'Εμφάνιση' => 'Show',
		'Εξαγωγή' => 'Export',
		'Εξαγωγή CSV' => 'CSV export',
		'Επαναφορά' => 'Restore',
		'Επεξεργασία καταχώρισης' => 'Edit entry',
		'Επιμέλεια/διόρθωση' => 'Editing/proofreading',
		'Επισκόπηση' => 'Overview',
		'Η επαναφορά δεν έγινε: η καταχώριση δεν είναι διαγραμμένη ή η πηγή της έφτιαξε νεότερη.' => 'Not restored: the entry is not deleted, or its source made a newer one.',
		'Η ημερομηνία δεν είναι έγκυρη.' => 'The date is not valid.',
		'Η ημερομηνία είναι πάνω από ένα χρόνο μπροστά.' => 'The date is more than a year ahead.',
		'Η ημερομηνία είναι πριν από την αρχή της καταγραφής (%s).' => 'The date is before the start of the ledger (%s).',
		'Η καταγραφή ξεκινά στις' => 'The ledger starts on',
		'Η καταγραφή ξεκινά στις %s: η περίοδος είναι πριν από αυτή.' => 'The ledger starts on %s: the period is before it.',
		'Η κατανομή δεν ταιριάζει με την κατηγορία (μεταφορικά → μεταφορέας, αναλώσιμα → όλες οι αποστολές, προμήθειες → πάροχος πληρωμών).' => 'The split does not match the category (shipping → carrier, consumables → all shipments, fees → payment provider).',
		'Η καταχώριση αποθηκεύτηκε.' => 'The entry was saved.',
		'Η καταχώριση δεν αποθηκεύτηκε (σφάλμα βάσης).' => 'The entry was not saved (database error).',
		'Η καταχώριση δεν βρέθηκε.' => 'The entry was not found.',
		'Η καταχώριση διαγράφηκε.' => 'The entry was deleted.',
		'Η καταχώριση επανήλθε.' => 'The entry was restored.',
		'Η καταχώριση προστέθηκε.' => 'The entry was added.',
		'Η κατηγορία «Γενικό έξοδο» είναι μόνο για τα γενικά έξοδα.' => 'The category «General expense» is only for general expenses.',
		'Ημερομηνία' => 'Date',
		'Ημερομηνία παραστατικού' => 'Invoice date',
		'Ιστορικό Printful' => 'Printful log',
		'Ιστορικές' => 'Historical',
		'Ιστορικές καταχωρίσεις (όλες)' => 'Historical entries (all)',
		'Ιστορική: έξοδο πριν από την αρχή της καταγραφής, μόνο για στατιστικά (δεν μετρά πουθενά και δεν πάει στο orOS)' => 'Historical: a cost from before the start of the ledger, for statistics only (counts nowhere and does not go to orOS)',
		'ιστορική' => 'historical',
		'Μια ιστορική καταχώριση έχει ημερομηνία πριν από την αρχή της καταγραφής (%s).' => 'A historical entry is dated before the start of the ledger (%s).',
		'Οι ιστορικές καταχωρίσεις (έξοδα πριν από την αρχή της καταγραφής) βγαίνουν μόνο στο δικό τους αρχείο, όλες μαζί, ανεξάρτητα από τον μήνα.' => 'Historical entries (costs from before the start of the ledger) come out only in their own file, all together, whatever the month.',
		'Καθαρό' => 'Net',
		'Καθαρό αποτέλεσμα' => 'Net result',
		'Καθαρό ποσό (χωρίς ΦΠΑ)' => 'Net amount (without VAT)',
		'Καθαρό προϊόντων' => 'Products net',
		'Καμία καταχώριση.' => 'No entries.',
		'Καμία πώληση ή καταχώριση σε αυτή την περίοδο.' => 'No sales or entries in this period.',
		'Καταγραφή' => 'Ledger',
		'Κατανομή' => 'Split',
		'Κατανομή σε' => 'Split over',
		'Καταχωρίσεις' => 'Entries',
		'Καταχωρίσεις του μήνα' => 'Entries of the month',
		'Κατηγορία' => 'Category',
		'Κλειδί API Printful (μόνο ανάγνωση)' => 'Printful API key (read-only)',
		'Κλειστό: οι καταχωρίσεις είναι τα βιβλία σου και μένουν στη βάση. Οι ρυθμίσεις φεύγουν πάντα.' => 'Off: the entries are your books and stay in the database. The settings are always removed.',
		'Κόστη & Απόδοση' => 'Costs & Returns',
		'Κόστη προϊόντων' => 'Product costs',
		'Κόστος αποστολής (μεταφορέας)' => 'Shipping cost (carrier)',
		'Λήψη JSON' => 'Download JSON',
		'Λιανική' => 'Retail',
		'Μήνας' => 'Month',
		'Μία γραμμή ανά μεταφορέα: κλειδί | όνομα | λέξεις. Μια αποστολή ανήκει στον μεταφορέα όταν το id της μεθόδου ή το όνομά της περιέχει μία από τις λέξεις. Το τιμολόγιο του μήνα μπαίνει ως «μοιρασμένο» και πέφτει ίσα σε κάθε δέμα του.' => 'One line per carrier: key | name | words. A shipment belongs to the carrier when its method id or name contains one of the words. The month\'s invoice is entered as «shared» and falls equally on each of its parcels.',
		'Μία γραμμή ανά πάροχο: κλειδί | όνομα | ids μεθόδων πληρωμής. Το τιμολόγιο προμηθειών του μήνα μοιράζεται στις παραγγελίες που πληρώθηκαν μέσω του παρόχου, ανάλογα με την αξία τους.' => 'One line per provider: key | name | payment method ids. The month\'s fee invoice is spread over the orders paid through the provider, by their value.',
		'Μεταφορείς' => 'Carriers',
		'Μεταφορικά' => 'Shipping',
		'Μεταφορικά που πλήρωσαν οι πελάτες' => 'Shipping paid by customers',
		'Μοιρασμένο' => 'Shared',
		'Μοιρασμένο στις παραγγελίες του μήνα (μεταφορικά, αναλώσιμα, προμήθειες πληρωμών)' => 'Shared over the month\'s orders (shipping, consumables, payment fees)',
		'Μορφή' => 'Format',
		'Νέα καταχώριση' => 'New entry',
		'Νέο γενικό έξοδο' => 'New general expense',
		'Νεκρό σημείο' => 'Break-even',
		'Νεκρό σημείο (τεμάχια)' => 'Break-even (units)',
		'Νόμισμα' => 'Currency',
		'Ο ΦΠΑ πρέπει να είναι ποσό ≥ 0.' => 'VAT must be an amount ≥ 0.',
		'Ο εκδότης στο Revenue Splitter' => 'The publisher in Revenue Splitter',
		'Ο συντελεστής ΦΠΑ πρέπει να είναι από 0 έως 100.' => 'The VAT rate must be from 0 to 100.',
		'Ο σύνδεσμος πρέπει να είναι διεύθυνση http(s).' => 'The link must be an http(s) address.',
		'Οι δοκιμαστικές καταχωρίσεις θα σβηστούν οριστικά. Συνέχεια;' => 'The test entries will be erased for good. Continue?',
		'Οι μεταφορείς δεν διαβάστηκαν (μορφή: κλειδί | όνομα | λέξεις): κράτησα τους προηγούμενους.' => 'The carriers could not be read (format: key | name | words): the previous ones were kept.',
		'Οι ομάδες πληρωμών δεν διαβάστηκαν (μορφή: κλειδί | όνομα | ids): κράτησα τις προηγούμενες.' => 'The payment groups could not be read (format: key | name | ids): the previous ones were kept.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Οι ρυθμίσεις εισήχθησαν (το κλειδί του Printful έμεινε όπως ήταν).' => 'Settings imported (the Printful key stayed as it was).',
		'Ομάδες πληρωμών' => 'Payment groups',
		'Οριστική διαγραφή των δοκιμαστικών' => 'Erase the test entries for good',
		'Παραγωγή' => 'Production',
		'Παραστατικό' => 'Document',
		'Περιγραφή' => 'Description',
		'Πηγή' => 'Source',
		'Πλήθος (π.χ. δέματα)' => 'Quantity (e.g. parcels)',
		'Προμήθειες πληρωμών' => 'Payment fees',
		'Προμήθειες πληρωμών (μερίδιο)' => 'Payment fees (share)',
		'Προμηθευτής' => 'Supplier',
		'Προμηθευτής, περιγραφή, αρ. παραστατικού' => 'Supplier, description, invoice no.',
		'Προσθήκη' => 'Add',
		'Προϊόν' => 'Product',
		'Προϊόν (ID)' => 'Product (ID)',
		'Προϊόν / κατανομή' => 'Product / split',
		'Προώθηση' => 'Promotion',
		'Ρυθμίσεις' => 'Settings',
		'Σβήστηκαν %d δοκιμαστικές καταχωρίσεις.' => '%d test entries were erased.',
		'Σβήστηκε %d δοκιμαστική καταχώριση.' => '%d test entry was erased.',
		'Συντελεστής ΦΠΑ %' => 'VAT rate %',
		'Σχέδιο/εικονογράφηση' => 'Design/illustration',
		'Σύνδεσμος παραστατικού' => 'Document link',
		'Σύνολο προϊόντων' => 'Products total',
		'Σύνολο σελίδας' => 'Page total',
		'Σύνοψη ανά προϊόν' => 'Summary per product',
		'Τίποτα πριν από αυτή την ημέρα δεν μετρά στην αναφορά και καμία καταχώριση δεν μπορεί να έχει παλαιότερη ημερομηνία.' => 'Nothing before this day counts in the report, and no entry can have an earlier date.',
		'Τα έσοδα εκτός site χρειάζονται προϊόν.' => 'Off-site revenue needs a product.',
		'Τα αναλώσιμα μοιράζονται στις αποστολές του μήνα (αλλιώς μετρούν ως γενικό έξοδο)' => 'Consumables are spread over the month\'s shipments (otherwise they count as a general expense)',
		'Τελευταία ανάγνωση από το orOS: %1$s, %2$d γραμμές.' => 'Last read by orOS: %1$s, %2$d rows.',
		'Τελικό αποτέλεσμα' => 'Final result',
		'Τεμ.' => 'Units',
		'Τεμάχια' => 'Units',
		'Τεμάχια που μένουν να πουληθούν για να βγει το κόστος από την αρχή της καταγραφής' => 'Units still to sell to cover the cost since the start of the ledger',
		'Τι έφερε κάθε προϊόν αφού αφαιρεθούν όσα κόστισε, και τι μένει μετά τα γενικά έξοδα. Όλα τα ποσά είναι καθαρά, χωρίς ΦΠΑ.' => 'What each product brought in after its costs, and what remains after the general expenses. All amounts are net, without VAT.',
		'Τι πλήρωσαν οι πελάτες για αποστολή μείον ό,τι κόστισε στον μεταφορέα' => 'What customers paid for shipping minus what the carrier cost',
		'Το PL_DISABLE είναι ενεργό στο wp-config.php: τα modules δεν τρέχουν και η ροή για το orOS απαντά 403.' => 'PL_DISABLE is set in wp-config.php: the modules do not run and the orOS feed answers 403.',
		'Το Production Ledger χρειάζεται το WooCommerce: ενεργοποίησέ το για να δεις κόστη και απόδοση ανά προϊόν.' => 'Production Ledger needs WooCommerce: activate it to see costs and returns per product.',
		'Το Revenue Splitter δεν είναι ενεργό: οι αμοιβές δημιουργών μετρούν μόνο από τις καταχωρίσεις «Δημιουργοί».' => 'Revenue Splitter is not active: creator payouts count only from «Creators» entries.',
		'Το αρχείο δεν ανέβηκε ή είναι πολύ μεγάλο.' => 'The file was not uploaded or is too large.',
		'Το καθαρό ποσό πρέπει να είναι ≥ 0 με έως 2 δεκαδικά.' => 'The net amount must be ≥ 0 with up to 2 decimals.',
		'Το κλειδί του Printful δεν αποθηκεύτηκε (άκυρη μορφή ή λείπει το OpenSSL).' => 'The Printful key was not saved (invalid format or OpenSSL is missing).',
		'Το προϊόν δεν υπάρχει στο κατάστημα.' => 'The product does not exist in the shop.',
		'Τσέκαρε τα ονόματα δικαιούχων που είσαι εσύ (ο εκδότης). Το μερίδιό τους μένει στο αποτέλεσμα· όλων των άλλων μετρά ως αμοιβή δημιουργού.' => 'Tick the beneficiary names that are you (the publisher). Their share stays in the result; everyone else\'s counts as a creator payout.',
		'Τυπικό (, και δεκαδική τελεία)' => 'Standard (, and decimal point)',
		'ΦΠΑ' => 'VAT',
		'ΦΠΑ (κενό = από τον συντελεστή)' => 'VAT (empty = from the rate)',
		'ΦΠΑ αγορών' => 'Input VAT',
		'ΦΠΑ πωλήσεων' => 'Output VAT',
		'ΦΠΑ πωλήσεων %1$s · ΦΠΑ αγορών (προϊόντα) %2$s. Οι αμοιβές δημιουργών είναι του Revenue Splitter, για τις ίδιες παραγγελίες.' => 'Output VAT %1$s · input VAT (products) %2$s. Creator payouts come from Revenue Splitter, for the same orders.',
		'Φίλτρο' => 'Filter',
		'Χονδρική' => 'Trade',
		'Χωρίς παραγγελίες για κατανομή' => 'No orders to split over',
		'Χωρίς το κλειδί του Printful.' => 'Without the Printful key.',
		'άνοιγμα' => 'open',
		'αποθηκευμένο (κρυπτογραφημένο). Κενό = μένει το τωρινό.' => 'stored (encrypted). Empty = the current one stays.',
		'από τη σταθερά PL_PRINTFUL_KEY στο wp-config.php' => 'from the PL_PRINTFUL_KEY constant in wp-config.php',
		'αυτόματη' => 'automatic',
		'δεν έχει οριστεί.' => 'not set.',
		'δεν διαβάζεται πια (άλλαξαν τα salts του wp-config.php): γράψ\' το ξανά.' => 'can no longer be read (the wp-config.php salts changed): enter it again.',
		'δημιουργοί %s' => 'creators %s',
		'δοκιμή' => 'test',
		'λιανική %1$s · χονδρική %2$s' => 'retail %1$s · trade %2$s',
		'μοιρασμένο' => 'shared',
		'ροή εγγραφών μόνο για ανάγνωση στο /wp-json/wc/v3/pl/records, που τραβά το orOS Budget με τα κλειδιά REST του καταστήματος.' => 'a read-only records feed at /wp-json/wc/v3/pl/records, which orOS Budget pulls with the shop\'s REST keys.',
		'το κόστος κάθε ολοκληρωμένης παραγγελίας από το Printful γίνεται καταχώριση «Εκτύπωση» των προϊόντων της.' => 'the Printful cost of every completed order becomes a «Printing» entry of its products.',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_production-ledger', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_production-ledger', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current admin user. */
	public static function lang(): string {
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		$uid    = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$choice = $uid ? (string) get_user_meta( $uid, 'rs_lang', true ) : '';
		if ( 'el' === $choice || 'en' === $choice ) {
			$lang = $choice;
		} else {
			$locale = $uid && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** Every msgid with its English text (for the release check). */
	public static function dict(): array {
		return self::$dict;
	}

	/** gettext_production-ledger filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'production-ledger' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_production-ledger filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'production-ledger' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
