<?php
/**
 * TRA_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitor texts are settings, not msgids
 * (Bible §14.17, TRA_Settings::text()). The Trade Feed answers in English
 * (machine-read error messages), outside this dictionary.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		// Accounts, scope, bootstrap.
		'Το email δεν είναι έγκυρο.' => 'The email is not valid.',
		'Αυτό το email ανήκει σε λογαριασμό προσωπικού του site. Δεν αλλάζει σε χονδρική.' => 'This email belongs to a staff account of the site. It is not changed to trade.',
		'Ο χρήστης δεν είναι λογαριασμός χονδρικής.' => 'The user is not a trade account.',
		'Τα Application Passwords δεν είναι διαθέσιμα σε αυτό το site (χρειάζονται HTTPS και να μην τα έχει κλείσει κάποιο plugin ασφαλείας).' => 'Application Passwords are not available on this site (they need HTTPS and must not be turned off by a security plugin).',
		'Εκτός χονδρικής' => 'Not for trade',
		'Χωρίς τιμή χονδρικής και έξω από το Trade Feed, ακόμα κι αν η κατηγορία του είναι επιλέξιμη.' => 'No trade price and left out of the Trade Feed, even if its category is eligible.',
		'Το Trade Accounts χρειάζεται ενεργό WooCommerce.' => 'Trade Accounts needs WooCommerce to be active.',
		// Page, tabs.
		'Modules' => 'Modules',
		'Ρυθμίσεις' => 'Settings',
		'Λογαριασμοί' => 'Accounts',
		'Trade Feed' => 'Trade Feed',
		'Εργαλεία' => 'Tools',
		'Trade Accounts' => 'Trade Accounts',
		'Για τους λογαριασμούς χονδρικής (βιβλιοπωλεία και μεταπωλητές) κρατάμε την έκπτωση του λογαριασμού και, όταν ο λογαριασμός χρησιμοποιεί το Trade Feed, πότε έγινε κάθε κλήση και τι ζητήθηκε (τελευταίες 200 κλήσεις, χωρίς διεύθυνση IP).' => 'For trade accounts (bookstores and resellers) we keep the account\'s discount and, when the account uses the Trade Feed, when each call was made and what was asked (last 200 calls, without IP address).',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Λογαριασμοί χονδρικής για βιβλιοπωλεία και μεταπωλητές: τιμές χονδρικής στο κατάστημα και κατάλογος (API) για τα δικά τους e-shops. Κάθε module ενεργοποιείται χωριστά· όσα είναι κλειστά δεν φορτώνουν τίποτα.' => 'Trade accounts for bookstores and resellers: trade prices in the shop and a catalogue (API) for their own e-shops. Each module is switched on separately; the ones that are off load nothing.',
		'Το TRA_DISABLE είναι ενεργό στο wp-config.php: καμία τιμή χονδρικής και το Trade Feed απαντά 403.' => 'TRA_DISABLE is on in wp-config.php: no trade prices, and the Trade Feed answers 403.',
		'Ενεργό' => 'On',
		'Κλειστό' => 'Off',
		// Modules.
		'Τιμές χονδρικής' => 'Trade prices',
		'Οι λογαριασμοί χονδρικής βλέπουν και αγοράζουν τα επιλέξιμα προϊόντα με την έκπτωσή τους, στο κατάστημα, στο καλάθι και στην παραγγελία. Πληρωμές, μεταφορικά, ΦΠΑ και Revenue Splitter δουλεύουν όπως στη λιανική.' => 'Trade accounts see and buy the eligible products with their discount, in the shop, the cart and the order. Payments, shipping, VAT and Revenue Splitter work as in retail.',
		'Ενεργοποίηση' => 'Switch on',
		'API για τα e-shops των λογαριασμών: τα επιλέξιμα προϊόντα με τιμές λιανικής και χονδρικής, διαθεσιμότητα και προπαραγγελίες, σε JSON ή XML, με όνομα χρήστη και κωδικό ανά λογαριασμό.' => 'An API for the accounts\' e-shops: the eligible products with retail and trade prices, availability and pre-orders, in JSON or XML, with a username and password per account.',
		'Λειτουργία' => 'Mode',
		'Δοκιμή: μόνο οι διαχειριστές βλέπουν τιμές χονδρικής (ως προεπισκόπηση)' => 'Test: only managers see trade prices (as a preview)',
		'Ενεργό: οι λογαριασμοί χονδρικής βλέπουν τις τιμές τους' => 'Live: trade accounts see their prices',
		'Ανενεργό: καμία τιμή χονδρικής στο κατάστημα (το Trade Feed δεν επηρεάζεται)' => 'Off: no trade prices in the shop (the Trade Feed is not affected)',
		'Γλώσσα των κειμένων για τους επισκέπτες' => 'Language of the visitor texts',
		'Αυτόματα, από τη γλώσσα του site' => 'Automatic, from the site language',
		'Ελληνικά' => 'Greek',
		'English' => 'English',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'English (κενό = το ελληνικό)' => 'English (empty = the Greek one)',
		// Settings.
		'Δεν έχει επιλεγεί καμία κατηγορία: κανένα προϊόν δεν είναι επιλέξιμο ακόμα.' => 'No category is chosen: no product is eligible yet.',
		'Έκπτωση' => 'Discount',
		'Έκπτωση κάθε λογαριασμού χονδρικής (%)' => 'Discount of every trade account (%)',
		'Ένας λογαριασμός μπορεί να έχει και δική του έκπτωση (καρτέλα Λογαριασμοί).' => 'An account can also have its own discount (Accounts tab).',
		'Η έκπτωση υπολογίζεται' => 'The discount is taken',
		'Πάνω στην κανονική τιμή λιανικής, χωρίς να μετρά καμία προσφορά' => 'Off the regular retail price, whatever sale is running',
		'Πάνω στην τιμή που δείχνει το κατάστημα τώρα (και πάνω στην τιμή προσφοράς)' => 'Off the price the shop shows now (the sale price too)',
		'Επιλέξιμα προϊόντα' => 'Eligible products',
		'Επιλέξιμα τώρα: %d προϊόντα.' => 'Eligible now: %d products.',
		'Κατηγορίες (μαζί με τις υποκατηγορίες τους)' => 'Categories (with their subcategories)',
		'Brands (κανένα = όλα)' => 'Brands (none = all)',
		'Ένα προϊόν είναι επιλέξιμο όταν είναι σε μία από τις κατηγορίες ΚΑΙ σε ένα από τα brands.' => 'A product is eligible when it is in one of the categories AND of one of the brands.',
		'Περιορισμοί' => 'Limits',
		'Μόνο φυσικά προϊόντα (όχι εικονικά ή για λήψη)' => 'Physical products only (not virtual or downloadable)',
		'Πάντα εκτός: τίτλοι άλλων εκδοτών από το Book Feed, εξωτερικά και ομαδικά προϊόντα, και όσα έχουν τσεκαρισμένο το «Εκτός χονδρικής» στη σελίδα του προϊόντος.' => 'Always left out: other publishers\' titles from Book Feed, external and grouped products, and those with «Not for trade» ticked on the product page.',
		'Κατάστημα' => 'Shop',
		'Ένδειξη δίπλα στην τιμή χονδρικής' => 'Label next to the trade price',
		'Κείμενο της ένδειξης' => 'Label text',
		'Τιμολόγιο' => 'Invoice',
		'Οι παραγγελίες χονδρικής θέλουν τιμολόγιο (κλασικό ταμείο)' => 'Trade orders need an invoice (classic checkout)',
		'Πεδίο του ταμείου' => 'Checkout field',
		'Τιμή για «Τιμολόγιο»' => 'Value for «Invoice»',
		'Προεπιλογή: το πεδίο τύπου παραστατικού του «Timologia for WooCommerce» (OBS). Το ταμείο προεπιλέγει το τιμολόγιο για τον λογαριασμό χονδρικής.' => 'Default: the document-type field of «Timologia for WooCommerce» (OBS). The checkout preselects the invoice for a trade account.',
		'Μήνυμα όταν δεν επιλεγεί τιμολόγιο' => 'Message when no invoice is chosen',
		// Accounts.
		'Και τα δύο modules είναι κλειστά: οι λογαριασμοί υπάρχουν, αλλά δεν βλέπουν τιμές χονδρικής ούτε το Trade Feed μέχρι να ενεργοποιήσεις ένα module.' => 'Both modules are off: the accounts exist, but they see neither trade prices nor the Trade Feed until you switch a module on.',
		'Οι τιμές χονδρικής δεν είναι ενεργές για τους λογαριασμούς (λειτουργία δοκιμής ή ανενεργό, καρτέλα Modules).' => 'Trade prices are not live for the accounts (test or off mode, Modules tab).',
		'Νέος κωδικός Trade Feed. Φαίνεται μόνο αυτή τη φορά: αντέγραψέ τον τώρα και στείλ’ τον στο βιβλιοπωλείο με ασφαλή τρόπο.' => 'New Trade Feed password. It is shown only this once: copy it now and send it to the bookstore in a safe way.',
		'Όνομα χρήστη' => 'Username',
		'Κωδικός' => 'Password',
		'Διεύθυνση' => 'Address',
		'Λογαριασμοί χονδρικής' => 'Trade accounts',
		'Λογαριασμός' => 'Account',
		'Έκπτωση (%)' => 'Discount (%)',
		'Τελευταία κλήση' => 'Last call',
		'Ενέργειες' => 'Actions',
		'Δεν υπάρχουν λογαριασμοί χονδρικής ακόμα.' => 'There are no trade accounts yet.',
		'Ναι' => 'Yes',
		'από %s' => 'since %s',
		'χρήση %s' => 'used %s',
		'κανένας' => 'none',
		'Αποθήκευση' => 'Save',
		'Νέος κωδικός: ο παλιός σταματά να ισχύει αμέσως. Συνέχεια;' => 'New password: the old one stops working at once. Continue?',
		'Νέος κωδικός' => 'New password',
		'Ανάκληση κωδικού' => 'Revoke password',
		'Ο λογαριασμός γίνεται απλός πελάτης: χωρίς τιμές χονδρικής και χωρίς Trade Feed. Οι παραγγελίες του μένουν. Συνέχεια;' => 'The account becomes a plain customer: no trade prices and no Trade Feed. Its orders stay. Continue?',
		'Αφαίρεση' => 'Remove',
		'Κενή έκπτωση = η έκπτωση των ρυθμίσεων. Ένας υπάρχων πελάτης γίνεται λογαριασμός χονδρικής και από τους Χρήστες, με τον ρόλο «Χονδρική».' => 'Empty discount = the discount in the settings. An existing customer can also become a trade account from Users, with the «Χονδρική» role.',
		'Νέος λογαριασμός' => 'New account',
		'Επωνυμία' => 'Company',
		'Email' => 'Email',
		'Όνομα' => 'First name',
		'Επώνυμο' => 'Last name',
		'Δική του έκπτωση (%, κενό = των ρυθμίσεων)' => 'Own discount (%, empty = the settings)',
		'Πρόσβαση στο Trade Feed' => 'Access to the Trade Feed',
		'Email με σύνδεσμο για να ορίσει κωδικό σύνδεσης στο site (μόνο για νέο χρήστη)' => 'Email with a link to set a login password for the site (new users only)',
		'Αν το email ανήκει ήδη σε πελάτη, ο πελάτης γίνεται λογαριασμός χονδρικής. Λογαριασμοί προσωπικού δεν αλλάζουν ποτέ.' => 'If the email already belongs to a customer, the customer becomes a trade account. Staff accounts are never changed.',
		'Δημιουργία λογαριασμού' => 'Create account',
		// Trade Feed tab.
		'Το module Trade Feed είναι κλειστό: το API δεν απαντά μέχρι να το ενεργοποιήσεις στα Modules.' => 'The Trade Feed module is off: the API does not answer until you switch it on in Modules.',
		'Πώς συνδέεται ένα βιβλιοπωλείο' => 'How a bookstore connects',
		'Φτιάχνεις λογαριασμό χονδρικής με πρόσβαση στο Trade Feed και πατάς «Νέος κωδικός» (καρτέλα Λογαριασμοί).' => 'You create a trade account with access to the Trade Feed and press «New password» (Accounts tab).',
		'Στέλνεις στο βιβλιοπωλείο το όνομα χρήστη, τον κωδικό και τη διεύθυνση του API.' => 'You send the bookstore the username, the password and the API address.',
		'Το e-shop του καλεί το API με HTTP Basic (όνομα χρήστη και κωδικός), πάντα με HTTPS, σε JSON ή XML.' => 'Its e-shop calls the API with HTTP Basic (username and password), always over HTTPS, in JSON or XML.',
		'Ο λογαριασμός: έκπτωση, νόμισμα, όριο κλήσεων.' => 'The account: discount, currency, rate limit.',
		'Τα επιλέξιμα προϊόντα, 50 ανά σελίδα (έως 100), με τιμές λιανικής και χονδρικής, διαθεσιμότητα, προπαραγγελία και ημερομηνία κυκλοφορίας.' => 'The eligible products, 50 per page (up to 100), with retail and trade prices, availability, pre-order and release date.',
		'Ένα προϊόν με ISBN, ISSN, GTIN ή SKU.' => 'One product by ISBN, ISSN, GTIN or SKU.',
		'Οι επιλέξιμες κατηγορίες.' => 'The eligible categories.',
		'Φίλτρα του /products' => 'Filters of /products',
		'Παράδειγμα' => 'Example',
		'Οι τιμές είναι κείμενα με τα δεκαδικά του καταστήματος («13.00»). Κάθε απάντηση έχει τα headers X-RateLimit-Limit, X-RateLimit-Remaining και X-RateLimit-Reset· πάνω από το όριο έρχεται 429 με Retry-After.' => 'Prices are strings with the shop\'s decimals («13.00»). Every answer has the X-RateLimit-Limit, X-RateLimit-Remaining and X-RateLimit-Reset headers; above the limit comes 429 with Retry-After.',
		'Δοκιμή (ως διαχειριστής, 5 προϊόντα)' => 'Test (as a manager, 5 products)',
		'Ρυθμίσεις του Trade Feed' => 'Trade Feed settings',
		'Κλήσεις ανά ώρα και λογαριασμό' => 'Calls per hour and account',
		'Ιδιότητες με συντελεστές (συγγραφείς, εικονογράφοι, μεταφραστές…)' => 'Attributes with contributors (authors, illustrators, translators…)',
		'Στο Trade Feed βγαίνουν ως «contributors» με τον ρόλο τους, και το φίλτρο author ψάχνει σε αυτές. Οι υπόλοιπες ιδιότητες βγαίνουν στα «attributes».' => 'In the Trade Feed they come out as «contributors» with their role, and the author filter searches them. The other attributes come out in «attributes».',
		'Τελευταίες κλήσεις' => 'Latest calls',
		'Πότε' => 'When',
		'Κλήση' => 'Call',
		'Αποτέλεσμα' => 'Result',
		'Προϊόντα' => 'Products',
		'Καμία κλήση ακόμα.' => 'No calls yet.',
		// Tools.
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Μόνο οι ρυθμίσεις: οι λογαριασμοί, οι εκπτώσεις τους και οι κωδικοί δεν μπαίνουν στο αρχείο.' => 'Settings only: the accounts, their discounts and the passwords are not in the file.',
		'Ιστορικό κλήσεων' => 'Call log',
		'Κρατά τις τελευταίες 200 κλήσεις του Trade Feed, χωρίς διευθύνσεις IP και χωρίς κωδικούς.' => 'Keeps the last 200 Trade Feed calls, without IP addresses and without passwords.',
		'Καθαρισμός ιστορικού' => 'Clear the log',
		'Για developers' => 'For developers',
		'Αν ο χρήστης είναι λογαριασμός χονδρικής (0 = ο τρέχων).' => 'Whether the user is a trade account (0 = the current one).',
		'Αν το προϊόν είναι επιλέξιμο για χονδρική και Trade Feed.' => 'Whether the product is eligible for trade and the Trade Feed.',
		'Η τιμή χονδρικής του προϊόντος για τον λογαριασμό, ή null.' => 'The trade price of the product for the account, or null.',
		'(φίλτρο)' => '(filter)',
		'Αλλάζει την επιλεξιμότητα: ( $ok, $product ).' => 'Changes eligibility: ( $ok, $product ).',
		'Αλλάζει την έκπτωση ενός λογαριασμού: ( $percent, $user_id ).' => 'Changes the discount of an account: ( $percent, $user_id ).',
		'Αλλάζει ένα προϊόν του Trade Feed: ( $item, $product ).' => 'Changes a product of the Trade Feed: ( $item, $product ).',
		'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).' => 'Changes a visitor text: ( $text, $key, $lang ).',
		'Στο wp-config.php: σταματά τις τιμές χονδρικής και κλείνει το Trade Feed (403).' => 'In wp-config.php: stops trade prices and closes the Trade Feed (403).',
		// Messages.
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Το ιστορικό καθαρίστηκε.' => 'The log was cleared.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Ο λογαριασμός αποθηκεύτηκε.' => 'The account was saved.',
		'Νέος κωδικός Trade Feed: ο παλιός δεν ισχύει πια.' => 'New Trade Feed password: the old one no longer works.',
		'Ο κωδικός ανακλήθηκε: το βιβλιοπωλείο δεν μπορεί να καλέσει το Trade Feed μέχρι να πάρει νέο.' => 'The password was revoked: the bookstore cannot call the Trade Feed until it gets a new one.',
		'Ο λογαριασμός έγινε απλός πελάτης.' => 'The account became a plain customer.',
		'Ο λογαριασμός χονδρικής δημιουργήθηκε. Για το Trade Feed πάτα «Νέος κωδικός» στη γραμμή του.' => 'The trade account was created. For the Trade Feed press «New password» on its row.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.' => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Trade Accounts).' => 'Invalid backup file (the Trade Accounts settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import completed.',
		// Footer (Bible §7).
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_trade-accounts', array( __CLASS__, 'filter' ), 10, 3 );
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
			$locale = $uid && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_trade-accounts filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'trade-accounts' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
