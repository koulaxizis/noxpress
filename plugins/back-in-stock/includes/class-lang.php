<?php
/**
 * BIS_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English.
 *
 * The customer e-mails are built inside with_lang( <the request's language> ),
 * so they follow the language of the request, not the admin's rs_lang.
 * The texts of the form are settings, not msgids (Bible §14.17,
 * BIS_Settings::text()).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	/** Forced language (with_lang), '' = none. */
	private static $force = '';

	private static $dict = array(
		'Το Back in Stock χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Back in Stock needs WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Αιτήματα' => 'Requests',
		'Ρυθμίσεις' => 'Settings',
		'Εργαλεία' => 'Tools',
		'Back in Stock' => 'Back in Stock',
		'Ξανά σε απόθεμα' => 'Back in stock',
		'Ζήτηση εξαντλημένων' => 'Out-of-stock demand',
		'Κανένα αίτημα δεν περιμένει.' => 'No request is waiting.',
		'Όλα τα αιτήματα' => 'All requests',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Φόρμα «Ειδοποίησέ με όταν ξαναέρθει» στα εξαντλημένα προϊόντα. Ο πελάτης παίρνει ένα email όταν το προϊόν ξαναμπεί σε απόθεμα και μετά το αίτημα σβήνεται. Χωρίς newsletter.' => 'A «Notify me when it is back» form on out-of-stock products. The customer gets one email when the product is back in stock, and then the request is deleted. No newsletter.',
		'Το BIS_DISABLE είναι ενεργό στο wp-config.php: καμία φόρμα δεν εμφανίζεται.' => 'BIS_DISABLE is on in wp-config.php: no form shows.',
		'Ελληνικά' => 'Greek',
		'English (κενό = το ελληνικό)' => 'English (empty = the Greek one)',
		'Σε απόθεμα' => 'In stock',
		'Κατόπιν παραγγελίας' => 'On backorder',
		'Εξαντλημένο' => 'Out of stock',
		'Ζήτηση' => 'Demand',
		'Ενεργά' => 'Active',
		'Περιμένουν επιβεβαίωση' => 'Awaiting confirmation',
		'Στάλθηκαν σε 30 ημέρες' => 'Sent in 30 days',
		'Προϊόν' => 'Product',
		'Απόθεμα τώρα' => 'Stock now',
		'Ανεπιβεβαίωτα' => 'Unconfirmed',
		'Στάλθηκαν (30 ημ.)' => 'Sent (30 days)',
		'Κανένα αίτημα ακόμα.' => 'No requests yet.',
		'Επεξεργασία' => 'Edit',
		'Στείλε τώρα' => 'Send now',
		'Τα emails φεύγουν μόνα τους όταν ένα προϊόν ξαναμπεί σε απόθεμα. Το «Στείλε τώρα» χρειάζεται μόνο όταν το απόθεμα άλλαξε έξω από το WooCommerce (π.χ. απευθείας στη βάση) ή για να μην περιμένεις την καθυστέρηση.' => 'The emails go out by themselves when a product is back in stock. «Send now» is only needed when the stock changed outside WooCommerce (e.g. directly in the database) or so you do not wait for the delay.',
		'Όλα τα προϊόντα' => 'All products',
		'Email' => 'Email',
		'Λογαριασμός' => 'Account',
		'Κατάσταση' => 'Status',
		'Γλώσσα' => 'Language',
		'Ημερομηνία' => 'Date',
		'Κανένα ανοιχτό αίτημα.' => 'No open request.',
		'Επισκέπτης' => 'Guest',
		'Ενεργό' => 'Active',
		'Περιμένει επιβεβαίωση' => 'Awaiting confirmation',
		'Διαγραφή' => 'Delete',
		'Έλεγχος' => 'Checks',
		'Κλειστό' => 'Off',
		'Το email «Back in Stock: ξανά σε απόθεμα».' => 'The email «Back in Stock: back in stock».',
		'Θέμα, επικεφαλίδα και ενεργοποίηση' => 'Subject, heading and switch',
		'Το email «Back in Stock: επιβεβαίωση» (για επισκέπτες χωρίς λογαριασμό).' => 'The email «Back in Stock: confirmation» (for guests without an account).',
		'Με το email «ξανά σε απόθεμα» κλειστό η φόρμα δεν εμφανίζεται πουθενά.' => 'With the «back in stock» email off, the form shows nowhere.',
		'Με το email επιβεβαίωσης κλειστό τη φόρμα τη βλέπουν μόνο οι συνδεδεμένοι πελάτες.' => 'With the confirmation email off, only logged-in customers see the form.',
		'Το Action Scheduler δεν βρέθηκε: τα emails δεν μπορούν να σταλούν στο παρασκήνιο.' => 'Action Scheduler was not found: the emails cannot be sent in the background.',
		'Λειτουργία' => 'Mode',
		'Ανενεργό: καμία φόρμα, κανένα email' => 'Off: no form, no email',
		'Δοκιμή: τη φόρμα τη βλέπουν μόνο οι διαχειριστές του καταστήματος' => 'Test: only shop managers see the form',
		'Ενεργό: όλοι οι επισκέπτες' => 'Live: every visitor',
		'Γλώσσα των κειμένων και των emails για τους επισκέπτες' => 'Language of the texts and emails for visitors',
		'Αυτόματα, από τη γλώσσα του site' => 'Automatic, from the site language',
		'English' => 'English',
		'Κάθε αίτημα κρατά τη γλώσσα του, και το email του φεύγει σε αυτή.' => 'Every request keeps its language, and its email goes out in it.',
		'Διάρκεια' => 'Lifetime',
		'Ώρες για την επιβεβαίωση ενός επισκέπτη (12–168)' => 'Hours a guest has to confirm (12–168)',
		'Ημέρες που περιμένει ένα αίτημα το απόθεμα (30–365)' => 'Days a request waits for the stock (30–365)',
		'Μετά σβήνεται χωρίς email. Μετά την αποστολή το email του πελάτη σβήνεται αμέσως· μένει μόνο ένα αποτύπωμα για 30 ημέρες, για τις μετρήσεις και για να μη στείλει και το Wish Shelf το ίδιο μήνυμα.' => 'Then it is deleted without an email. After sending, the customer\'s email is deleted at once; only a fingerprint stays for 30 days, for the counts and so that Wish Shelf does not send the same message too.',
		'Αποστολή' => 'Sending',
		'Λεπτά αναμονής μετά το «σε απόθεμα» (0–120)' => 'Minutes to wait after «in stock» (0–120)',
		'Emails ανά παρτίδα (10–200)' => 'Emails per batch (10–200)',
		'Η αναμονή προστατεύει από λάθος αλλαγή (0 → 5 → 0): πριν σταλεί οτιδήποτε το απόθεμα ελέγχεται ξανά. Οι παρτίδες τρέχουν στο παρασκήνιο με το Action Scheduler του WooCommerce, μία κάθε 30 δευτερόλεπτα.' => 'The wait protects against a mistaken change (0 → 5 → 0): the stock is checked again before anything is sent. Batches run in the background with WooCommerce\'s Action Scheduler, one every 30 seconds.',
		'Όρια' => 'Limits',
		'Αιτήματα ανά διεύθυνση IP σε μία ώρα (3–100)' => 'Requests per IP address in an hour (3–100)',
		'Ανοιχτά αιτήματα ανά email (1–100)' => 'Open requests per email (1–100)',
		'Οι διευθύνσεις IP δεν αποθηκεύονται, μόνο ένα αποτύπωμά τους για μία ώρα. Η φόρμα έχει και honeypot και έλεγχο χρόνου για τα bots.' => 'IP addresses are not stored, only a fingerprint of them for an hour. The form also has a honeypot and a time check against bots.',
		'Συνεργασία' => 'Cooperation',
		'Χωρίς φόρμα στους τίτλους που έφυγαν από τη ροή του Book Feed (εκτός κυκλοφορίας)' => 'No form on titles that left the Book Feed feed (out of print)',
		'«Διαθέσιμο και ως» στο email, όταν το Product Formats έχει άλλη μορφή του ίδιου έργου σε απόθεμα' => '«Also available as» in the email, when Product Formats has another format of the same work in stock',
		'Σύνδεσμος στην πολιτική απορρήτου δίπλα στο κουτάκι συναίνεσης' => 'Link to the privacy policy next to the consent box',
		'Χωρίς φόρμα, πάντα: προϊόντα σε προπαραγγελία (Pre-Orders), κρυφές κατηγορίες (Hide Catalog), προϊόντα «κατόπιν παραγγελίας». Όποιος έχει το προϊόν στη λίστα του Wish Shelf και ζήτησε και εδώ ειδοποίηση, παίρνει ένα email, από το Back in Stock.' => 'Never a form: products on pre-order (Pre-Orders), hidden categories (Hide Catalog), products «on backorder». Whoever has the product on their Wish Shelf list and also asked for a notification here gets one email, from Back in Stock.',
		'Κείμενα για τους επισκέπτες' => 'Texts for visitors',
		'Το {product} γίνεται το όνομα του προϊόντος και το {email} το email, κρυμμένο εν μέρει. Το κείμενο των emails αλλάζει από το WooCommerce → Ρυθμίσεις → Emails ή με template του θέματος.' => '{product} becomes the product name and {email} the email, partly hidden. The text of the emails changes from WooCommerce → Settings → Emails or with a theme template.',
		'Τίτλος της φόρμας' => 'Form title',
		'Οδηγία' => 'Hint',
		'Πάνω από τη λίστα παραλλαγών (χωρίς JavaScript)' => 'Above the list of variations (without JavaScript)',
		'Ετικέτα του πεδίου email' => 'Label of the email field',
		'Κουτάκι συναίνεσης' => 'Consent box',
		'Κουμπί' => 'Button',
		'Μετά το αίτημα (συνδεδεμένος πελάτης)' => 'After the request (logged-in customer)',
		'Μετά το αίτημα (επισκέπτης, ίδιο είτε υπήρχε αίτημα είτε όχι)' => 'After the request (guest, the same whether a request existed or not)',
		'Λάθος email' => 'Wrong email',
		'Χωρίς τσεκαρισμένο κουτάκι' => 'Box not ticked',
		'Όριο αιτημάτων' => 'Rate limit',
		'Σφάλμα' => 'Error',
		'Σελίδα επιβεβαίωσης' => 'Confirmation page',
		'Κουμπί επιβεβαίωσης' => 'Confirmation button',
		'Μετά την επιβεβαίωση' => 'After confirming',
		'Σελίδα ακύρωσης' => 'Cancellation page',
		'Κουμπί ακύρωσης' => 'Cancellation button',
		'Μετά την ακύρωση' => 'After cancelling',
		'Σύνδεσμος που έληξε' => 'Expired link',
		'«Διαθέσιμο και ως»' => '«Also available as»',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Μόνο οι ρυθμίσεις: τα αιτήματα και τα emails των πελατών δεν μπαίνουν ποτέ στο αρχείο.' => 'Settings only: requests and customers\' emails never go into the file.',
		'Για developers' => 'For developers',
		'Αν το Back in Stock στέλνει (ή έστειλε τις τελευταίες 2 ημέρες) το email για αυτό το email και προϊόν. Το διαβάζει το Wish Shelf.' => 'Whether Back in Stock sends (or sent in the last 2 days) the email for this email and product. Wish Shelf reads it.',
		'(φίλτρο)' => '(filter)',
		'Αν ένα προϊόν παίρνει φόρμα: ( $ok, $product ).' => 'Whether a product gets the form: ( $ok, $product ).',
		'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).' => 'Changes a visitor text: ( $text, $key, $lang ).',
		'(action)' => '(action)',
		'Μετά από κάθε email «ξανά σε απόθεμα»: ( $request_id, $product_id, $variation_id ).' => 'After every «back in stock» email: ( $request_id, $product_id, $variation_id ).',
		'Templates των emails, αντικαθίστανται από το θέμα στο yourtheme/woocommerce/emails/ (και plain/).' => 'Templates of the emails, overridden by the theme in yourtheme/woocommerce/emails/ (and plain/).',
		'Στο wp-config.php: καμία φόρμα δεν εμφανίζεται.' => 'In wp-config.php: no form shows.',
		'Η αποστολή ξεκινά σε λίγα δευτερόλεπτα, στο παρασκήνιο.' => 'Sending starts in a few seconds, in the background.',
		'Το προϊόν δεν είναι σε απόθεμα ή το Action Scheduler δεν βρέθηκε.' => 'The product is not in stock or Action Scheduler was not found.',
		'Το αίτημα διαγράφηκε.' => 'The request was deleted.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Το email «Back in Stock: ξανά σε απόθεμα» είναι κλειστό στο WooCommerce: η φόρμα δεν εμφανίζεται.' => 'The email «Back in Stock: back in stock» is off in WooCommerce: the form does not show.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Back in Stock).' => 'Invalid backup file (the Back in Stock settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import complete.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Back in Stock: επιβεβαίωση' => 'Back in Stock: confirmation',
		'Στέλνεται στον επισκέπτη χωρίς λογαριασμό που ζήτησε ειδοποίηση, για να επιβεβαιώσει ότι το email είναι δικό του. Αν το απενεργοποιήσεις, τη φόρμα τη βλέπουν μόνο οι συνδεδεμένοι πελάτες.' => 'Sent to a guest without an account who asked for a notification, to confirm that the email is theirs. If you switch it off, only logged-in customers see the form.',
		'Επιβεβαίωσε την ειδοποίηση για το {product_name}' => 'Confirm the notification for {product_name}',
		'Επιβεβαίωσε την ειδοποίηση' => 'Confirm the notification',
		'Back in Stock: ξανά σε απόθεμα' => 'Back in Stock: back in stock',
		'Στέλνεται μία φορά σε όποιον ζήτησε ειδοποίηση, όταν το προϊόν ξαναμπεί σε απόθεμα. Αν το απενεργοποιήσεις, η φόρμα δεν εμφανίζεται πουθενά και τίποτα δεν στέλνεται.' => 'Sent once to whoever asked for a notification, when the product is back in stock. If you switch it off, the form shows nowhere and nothing is sent.',
		'Ξανά σε απόθεμα: {product_name}' => 'Back in stock: {product_name}',
		'Ήρθε ξανά' => 'It is back',
		'Πολιτική απορρήτου' => 'Privacy policy',
		'Αφήστε το κενό' => 'Leave this empty',
		'Επιστροφή στο κατάστημα' => 'Back to the shop',
		'Back in Stock: ειδοποιήσεις αποθέματος' => 'Back in Stock: stock notifications',
		'Στάλθηκε' => 'Sent',
		'Ημερομηνία αιτήματος' => 'Request date',
		'Ειδοποιήσεις αποθέματος' => 'Stock notifications',
		'Όταν ζητάς ειδοποίηση για ένα εξαντλημένο προϊόν, κρατάμε το email σου, το προϊόν, τη γλώσσα και την ημερομηνία, για να σου στείλουμε ένα μόνο email όταν ξαναμπεί σε απόθεμα. Αν δεν έχεις λογαριασμό, σου στέλνουμε πρώτα email επιβεβαίωσης· ένα αίτημα που δεν επιβεβαιώνεται σβήνεται σε 48 ώρες. Μετά την αποστολή το email σου σβήνεται· κρατάμε για 30 ημέρες μόνο ένα κρυπτογραφημένο αποτύπωμά του, για να μη σου σταλεί το ίδιο μήνυμα δύο φορές. Ένα αίτημα που περιμένει σβήνεται μετά από 180 ημέρες. Για να εμποδίσουμε την κατάχρηση μετράμε τα αιτήματα ανά διεύθυνση IP για μία ώρα, χωρίς να αποθηκεύουμε τη διεύθυνση. Δεν σε γράφουμε σε κανένα newsletter.' => 'When you ask to be notified about an out-of-stock product, we keep your email, the product, the language and the date, to send you a single email when it is back in stock. If you have no account, we first send you a confirmation email; a request that is not confirmed is deleted after 48 hours. After sending, your email is deleted; for 30 days we keep only a hashed fingerprint of it, so that the same message is not sent to you twice. A request that is still waiting is deleted after 180 days. To prevent abuse we count requests per IP address for an hour, without storing the address. We do not add you to any newsletter.',
		'Γεια σου,' => 'Hello,',
		'Ζήτησες να σε ειδοποιήσουμε όταν το «%1$s» ξαναμπεί σε απόθεμα στο %2$s. Πάτα το κουμπί για να το επιβεβαιώσεις.' => 'You asked to be notified when «%1$s» is back in stock at %2$s. Press the button to confirm.',
		'Επιβεβαίωση' => 'Confirm',
		'Ο σύνδεσμος ισχύει %d ώρες. Θα λάβεις ένα μόνο email, όταν το προϊόν ξαναέρθει, και μετά το αίτημα σβήνεται.' => 'The link is valid for %d hours. You will get a single email, when the product is back, and then the request is deleted.',
		'Αν δεν το ζήτησες εσύ, αγνόησε αυτό το email: χωρίς επιβεβαίωση δεν θα λάβεις τίποτα άλλο.' => 'If you did not ask for this, ignore this email: without confirmation you will get nothing else.',
		'Καλά νέα: το «%1$s» είναι ξανά σε απόθεμα στο %2$s.' => 'Good news: «%1$s» is back in stock at %2$s.',
		'Δες το προϊόν' => 'View the product',
		'Διαθέσιμο και ως:' => 'Also available as:',
		'Το απόθεμα μπορεί να εξαντληθεί ξανά. Αυτό ήταν το μόνο email για αυτό το αίτημα, που τώρα σβήστηκε.' => 'The stock may run out again. This was the only email for this request, which is now deleted.',
		'Ακύρωση όλων των ειδοποιήσεων αποθέματος για αυτό το email' => 'Cancel every stock notification for this email',
		'Ζήτησες να σε ειδοποιήσουμε όταν το «%1$s» ξαναμπεί σε απόθεμα στο %2$s. Άνοιξε τον σύνδεσμο για να το επιβεβαιώσεις:' => 'You asked to be notified when «%1$s» is back in stock at %2$s. Open the link to confirm:',
		'Ακύρωση όλων των ειδοποιήσεων αποθέματος για αυτό το email:' => 'Cancel every stock notification for this email:',
	);

	public static function init(): void {
		add_filter( 'gettext_back-in-stock', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current context (forced language first, then the admin's choice). */
	public static function lang(): string {
		if ( '' !== self::$force ) {
			return self::$force;
		}
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

	/**
	 * Runs $fn with the dictionary forced to a language (the customer
	 * e-mail is written in the visitor's language).
	 *
	 * @param string   $lang 'el' | 'en'.
	 * @param callable $fn   Callback.
	 * @return mixed What $fn returns.
	 */
	public static function with_lang( string $lang, callable $fn ) {
		$prev        = self::$force;
		self::$force = ( 'en' === $lang ) ? 'en' : 'el';
		try {
			return $fn();
		} finally {
			self::$force = $prev;
		}
	}

	/** gettext_back-in-stock filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'back-in-stock' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
