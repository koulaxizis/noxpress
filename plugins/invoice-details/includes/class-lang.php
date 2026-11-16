<?php
/**
 * IVD_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts customers see are store settings with a Greek and an English
 * value (Bible §14.17, IVD_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'CSV (, και τελεία)' => 'CSV (, and decimal point)',
		'Excel ελληνικά (; και υποδιαστολή κόμμα)' => 'Excel Greek (; and decimal comma)',
		'Invoice Details' => 'Invoice Details',
		'Invoice Details: αποθηκευμένα στοιχεία τιμολογίου' => 'Invoice Details: saved invoice details',
		'Όταν ζητάτε τιμολόγιο, αποθηκεύουμε στην παραγγελία την επωνυμία, τον ΑΦΜ, τη ΔΟΥ, τη δραστηριότητα και, αν τη δώσετε, τη διεύθυνση της έδρας, για την έκδοση του τιμολογίου και για τα λογιστικά μας βιβλία. Αν έχετε λογαριασμό, τα ίδια στοιχεία μένουν στον λογαριασμό σας για την επόμενη παραγγελία και τα αλλάζετε στον λογαριασμό σας. Για ΑΦΜ άλλης χώρας της ΕΕ στέλνουμε τη χώρα και τον αριθμό στην υπηρεσία VIES της Ευρωπαϊκής Επιτροπής, για να ελεγχθεί ο αριθμός.' => 'When you ask for an invoice, we store on the order the company name, the VAT number, the tax office, the business activity and, if you give it, the head-office address, to issue the invoice and for our accounts. If you have an account, the same details stay on it for your next order, and you can change them in your account. For a VAT number of another EU country we send the country and the number to the European Commission\'s VIES service, to check the number.',
		'Invoice Details: ο ΑΦΜ δεν βρέθηκε στο VIES. Η παραγγελία έγινε δεκτή· έλεγξε τον αριθμό πριν κόψεις τιμολόγιο.' => 'Invoice Details: the VAT number was not found in VIES. The order was accepted; check the number before you issue the invoice.',
		'Invoice Details: το VIES δεν απάντησε (%s). Κάνε «Έλεγχος VIES» αργότερα από το κουτί «Στοιχεία τιμολογίου».' => 'Invoice Details: VIES did not answer (%s). Use "Check in VIES" later in the "Invoice details" box.',
		'Invoice Details: έλεγχος VIES — %s.' => 'Invoice Details: VIES check — %s.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'VIES μη διαθέσιμο, έλεγχος αργότερα' => 'VIES unavailable, check later',
		'VIES:' => 'VIES:',
		'Άκυρος' => 'Not valid',
		'Έγκυρος στο VIES' => 'Valid in VIES',
		'Έδρα' => 'Head office',
		'Έλεγχος' => 'Check',
		'Έλεγχος VIES' => 'Check in VIES',
		'Έλεγχος VIES:' => 'VIES check:',
		'Έλεγχος ΑΦΜ' => 'VAT number check',
		'Έλεγχος στο VIES της ΕΕ για ΑΦΜ άλλων χωρών της ΕΕ, όταν γίνεται η παραγγελία' => 'Check VAT numbers of other EU countries in the EU VIES service when the order is placed',
		'Έως' => 'To',
		'Όταν το VIES δεν απαντά, η παραγγελία περνά πάντα και ο έλεγχος γίνεται αργότερα με το κουμπί «Έλεγχος VIES» στην παραγγελία. Το VIES παίρνει μόνο τη χώρα και τον αριθμό. Γράψε στην πολιτική απορρήτου ότι ο ΑΦΜ επιχειρήσεων της ΕΕ ελέγχεται στην υπηρεσία VIES της Ευρωπαϊκής Επιτροπής.' => 'When VIES does not answer, the order always goes through and the check is done later with the "Check in VIES" button on the order. VIES receives only the country and the number. Mention in your privacy policy that VAT numbers of EU businesses are checked in the European Commission\'s VIES service.',
		'Όταν το VIES λέει ότι ο αριθμός δεν υπάρχει' => 'When VIES says the number does not exist',
		'Όχι' => 'No',
		'ΑΦΜ' => 'VAT number',
		'ΑΦΜ (με πρόθεμα χώρας εκτός Ελλάδας)' => 'VAT number (with country prefix outside Greece)',
		'Ανενεργό' => 'Off',
		'Αποδείξεις και τιμολόγια' => 'Receipts and invoices',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Από' => 'From',
		'Από τη γλώσσα του site' => 'From the site language',
		'Απόδειξη' => 'Receipt',
		'Απόδειξη ή τιμολόγιο στο ταμείο, με επωνυμία, ΑΦΜ, ΔΟΥ και δραστηριότητα, έλεγχο του ΑΦΜ και τα στοιχεία στην παραγγελία. Δεν κόβει παραστατικά: τα δίνει στον πάροχο τιμολόγησης.' => 'Receipt or invoice at checkout, with company name, VAT number, tax office and activity, a check of the number and the details on the order. It does not issue documents: it hands the details to your invoicing provider.',
		'Αρ. ελέγχου VIES:' => 'VIES request no.:',
		'Βοήθεια κάτω από την επιλογή (κλασικό ταμείο)' => 'Help below the choice (classic checkout)',
		'Για ελληνικό ΑΦΜ το VIES είναι μόνο πληροφορία: πολλές ελληνικές επιχειρήσεις δεν είναι γραμμένες για ενδοκοινοτικές συναλλαγές.' => 'For a Greek number VIES is information only: many Greek businesses are not registered for intra-EU trade.',
		'Για προγραμματιστές' => 'For developers',
		'Γλώσσα των κειμένων για τον πελάτη' => 'Language of the customer texts',
		'ΔΟΥ' => 'Tax office',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Διάλεξε τα ονόματα του παλιού plugin όταν ο πάροχος τιμολόγησης ή η αποθήκη σου διαβάζει εκείνα. Τα στοιχεία φαίνονται πάντα και στο REST API του WooCommerce (meta _ivd_*, και η επωνυμία στο billing company).' => 'Choose the names of the old plugin when your invoicing provider or warehouse reads those. The details are always in the WooCommerce REST API too (meta _ivd_*, and the company name in billing company).',
		'Διαγραφή των αποθηκευμένων στοιχείων των πελατών όταν διαγραφεί το plugin (τα στοιχεία των παραγγελιών μένουν πάντα)' => 'Delete the customers\' saved details when the plugin is deleted (the details on orders always stay)',
		'Διαχείριση' => 'Admin',
		'Διεύθυνση έδρας' => 'Head office address',
		'Διεύθυνση έδρας (αν διαφέρει από τη χρέωση)' => 'Head office address (if different from billing)',
		'Δραστηριότητα' => 'Business activity',
		'Ελληνικά' => 'Greek',
		'Εμφάνιση' => 'Show',
		'Ενεργό για όλους' => 'On for everyone',
		'Εξαγωγή CSV' => 'Export CSV',
		'Επιλογή «Απόδειξη / Τιμολόγιο» στο ταμείο' => '"Receipt / Invoice" choice at checkout',
		'Επωνυμία' => 'Company name',
		'Επωνυμία και ΑΦΜ είναι πάντα υποχρεωτικά. Η επωνυμία είναι το πεδίο «Εταιρεία» του WooCommerce· αν το έχεις κρυφό, το plugin δείχνει δικό του πεδίο μόνο για τιμολόγιο. ΔΟΥ και δραστηριότητα ζητούνται μόνο για διεύθυνση χρέωσης στην Ελλάδα.' => 'Company name and VAT number are always required. The company name is WooCommerce\'s "Company" field; if you hide it, the plugin shows its own field for invoices only. Tax office and activity are asked only for a billing address in Greece.',
		'Η παραγγελία περνά, με σημείωση στην παραγγελία' => 'The order goes through, with a note on the order',
		'Ημερομηνία' => 'Date',
		'Καθαρό' => 'Net',
		'Και με τα ονόματα του Timologia OBS' => 'Also with the names of Timologia OBS',
		'Και με τα ονόματα του Timologia for WooCommerce' => 'Also with the names of Timologia for WooCommerce',
		'Κανένα τιμολόγιο σε αυτή την περίοδο.' => 'No invoices in this period.',
		'Κατάσταση' => 'Status',
		'Κείμενα για τον πελάτη' => 'Customer texts',
		'Κείμενο' => 'Text',
		'Κενό αγγλικό κείμενο = δείχνει το ελληνικό.' => 'Empty English text = shows the Greek.',
		'Κουτάκι στο block ταμείο' => 'Checkbox in the block checkout',
		'Κρυφό' => 'Hidden',
		'Λειτουργία' => 'Mode',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν την επιλογή' => 'Test mode: only shop managers see the choice',
		'Λειτουργία δοκιμής: την επιλογή «Απόδειξη / Τιμολόγιο» τη βλέπουν μόνο οι διαχειριστές του καταστήματος. Άλλαξέ τη στις Ρυθμίσεις όταν είσαι έτοιμος.' => 'Test mode: only the store\'s managers see the "Receipt / Invoice" choice. Change it in Settings when you are ready.',
		'Λειτουργεί στο κλασικό ταμείο και στο block ταμείο (WooCommerce 9.9 και νεότερο). Οι παραγγελίες που έχουν ήδη στοιχεία τα δείχνουν σε κάθε λειτουργία.' => 'Works in the classic checkout and the block checkout (WooCommerce 9.9 and later). Orders that already have details show them in every mode.',
		'Μήνυμα: η παραγγελία θέλει τιμολόγιο' => 'Message: the order needs an invoice',
		'Μήνυμα: λάθος ΑΦΜ' => 'Message: wrong VAT number',
		'Μήνυμα: λείπει πεδίο ({field})' => 'Message: missing field ({field})',
		'Μήνυμα: ο ΑΦΜ δεν βρέθηκε στο VIES' => 'Message: number not found in VIES',
		'Μόνο τα δικά μας πεδία' => 'Our own fields only',
		'Μόνο τιμολόγια' => 'Invoices only',
		'Νόμισμα' => 'Currency',
		'Ο ελληνικός ΑΦΜ ελέγχεται πάντα τοπικά με το ψηφίο ελέγχου, χωρίς δίκτυο. Το VIES δεν χρησιμοποιείται για ελληνικούς ΑΦΜ, γιατί δεν γνωρίζει όσες επιχειρήσεις δεν κάνουν ενδοκοινοτικές συναλλαγές.' => 'A Greek VAT number (ΑΦΜ) is always checked locally with its check digit, without any network. VIES is not used for Greek numbers, because it does not know businesses that do not trade within the EU.',
		'Ο πελάτης διορθώνει τον αριθμό' => 'The customer corrects the number',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Ονόματα στοιχείων στην παραγγελία' => 'Names of the details on the order',
		'Πάροχος τιμολόγησης' => 'Invoicing provider',
		'Παραγγελία' => 'Order',
		'Πεδία τιμολογίου' => 'Invoice fields',
		'Ποσά μετά τις επιστροφές. Περιλαμβάνονται και τα τιμολόγια από το Timologia ή το OBS πριν από την αλλαγή.' => 'Amounts after refunds. Invoices made with Timologia or OBS before the switch are included.',
		'Προαιρετικό' => 'Optional',
		'Ρυθμίσεις' => 'Settings',
		'Στήλη «Τ» και φίλτρο «Μόνο τιμολόγια» στη λίστα παραγγελιών' => '"I" column and "Invoices only" filter in the orders list',
		'Στοιχεία από το %s (παραγγελία πριν από το Invoice Details). Με την αποθήκευση γράφονται στο Invoice Details.' => 'Details from %s (order made before Invoice Details). Saving writes them to Invoice Details.',
		'Στοιχεία τιμολογίου' => 'Invoice details',
		'Σωστό ψηφίο ελέγχου' => 'Check digit correct',
		'Σύνολο' => 'Total',
		'Τ' => 'I',
		'Τίτλος της επιλογής' => 'Title of the choice',
		'Τιμολόγια' => 'Invoices',
		'Τιμολόγιο' => 'Invoice',
		'Το IVD_DISABLE είναι ενεργό στο wp-config.php: τίποτα δεν εμφανίζεται στο ταμείο. Οι παραγγελίες δείχνουν κανονικά τα στοιχεία τους.' => 'IVD_DISABLE is on in wp-config.php: nothing shows at checkout. Orders still show their details.',
		'Το Invoice Details χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Invoice Details requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Υποχρεωτικό' => 'Required',
		'ΦΠΑ' => 'VAT',
		'Χωρίς έλεγχο' => 'Not checked',
		'Χώρα' => 'Country',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
	);

	/** Plurals: Greek singular => [ English singular, English plural ]. */
	private static $plural = array(
		'%d παραγγελία με τιμολόγιο.' => array( '%d invoice order.', '%d invoice orders.' ),
	);

	public static function init(): void {
		add_filter( 'gettext_invoice-details', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_invoice-details', array( __CLASS__, 'filter_plural' ), 10, 5 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current user (admin screens). */
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
			$lang   = self::from_locale( (string) $locale );
		}
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_invoice-details filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'invoice-details' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_invoice-details filter. */
	public static function filter_plural( $translation, $single, $plural, $number, $domain ) {
		if ( 'invoice-details' === $domain && isset( self::$plural[ $single ] ) && 'en' === self::lang() ) {
			return 1 === (int) $number ? self::$plural[ $single ][0] : self::$plural[ $single ][1];
		}
		return $translation;
	}
}
