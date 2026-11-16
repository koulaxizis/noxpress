<?php
/**
 * ACL_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitors never see a text of this plugin.
 * The critical-change e-mail is written in the language of the admin it
 * goes to (force()).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'%1$s γραμμές. Το ιστορικό κρατά %2$d ημέρες.' => '%1$s rows. The history keeps %2$d days.',
		'%1$s γραμμή. Το ιστορικό κρατά %2$d ημέρες.' => '%1$s row. The history keeps %2$d days.',
		'+%d ακόμα' => '+%d more',
		'Activity Log: τα γεγονότα μένουν, χωρίς όνομα και IP.' => 'Activity Log: the events stay, without name and IP.',
		'Broadcast: στάλθηκε' => 'Broadcast: sent',
		'Easy Withdrawal: αλλαγή κατάστασης' => 'Easy Withdrawal: status changed',
		'Easy Withdrawal: νέα αίτηση υπαναχώρησης' => 'Easy Withdrawal: new withdrawal request',
		'Email για κρίσιμες αλλαγές' => 'E-mail for critical changes',
		'Featured Creators: αλλαγή δημιουργού' => 'Featured Creators: creator changed',
		'Login Guard: η συνεδρία κόπηκε' => 'Login Guard: session stopped',
		'Login Guard: κλείδωμα' => 'Login Guard: locked',
		'Login Guard: λάθος κωδικός 2FA' => 'Login Guard: wrong 2FA code',
		'Login Guard: ξεκλείδωμα' => 'Login Guard: unlocked',
		'Plugins, θέματα και πυρήνας' => 'Plugins, themes and core',
		'Pre-Orders: κυκλοφόρησε' => 'Pre-Orders: released',
		'Production Ledger: αλλαγή εγγραφών' => 'Production Ledger: entries changed',
		'Publishing Quote: αλλαγή κατάστασης αιτήματος' => 'Publishing Quote: request status changed',
		'Trade Accounts: εκκαθάριση παρακαταθήκης' => 'Trade Accounts: consignment settled',
		'Trade Accounts: νέα παρακαταθήκη' => 'Trade Accounts: new consignment',
		'Trade Accounts: πληρωμή παρακαταθήκης' => 'Trade Accounts: consignment paid',
		'[%1$s] %2$d κρίσιμες αλλαγές στο site' => '[%1$s] %2$d critical changes on the site',
		'[%1$s] %2$d κρίσιμη αλλαγή στο site' => '[%1$s] %2$d critical change on the site',
		'Άδειασμα' => 'Empty',
		'Άδειασμα ιστορικού' => 'Empty the history',
		'Έκδοση' => 'Version',
		'Ένα email το πολύ κάθε 15 λεπτά, με όλες τις αλλαγές μαζί. Κενό = το email διαχείρισης του site.' => 'At most one e-mail every 15 minutes, with all changes together. Empty = the site\'s admin e-mail.',
		'Έως' => 'To',
		'Έως γραμμές' => 'At most rows',
		'Ήδη μέσα: οι βασικές ρυθμίσεις του WordPress, όλες οι ρυθμίσεις του WooCommerce και οι ρυθμίσεις των plugins της σουίτας. Ονόματα που μοιάζουν με κωδικό ή κλειδί δεν γίνονται δεκτά. Για developers: φίλτρα acl_watched_options, acl_skip_event, acl_categories και η συνάρτηση acl_log(). Το ACL_DISABLE στο wp-config.php σταματά την καταγραφή.' => 'Already included: the main WordPress settings, every WooCommerce setting and the settings of the suite\'s plugins. Names that look like a password or a key are refused. For developers: filters acl_watched_options, acl_skip_event, acl_categories and the function acl_log(). ACL_DISABLE in wp-config.php stops recording.',
		'Όλα' => 'All',
		'Όλες' => 'All',
		'Όλο το ιστορικό' => 'Whole history',
		'Όλο το ιστορικό του' => 'Its whole history',
		'Όλο το ιστορικό:' => 'Whole history:',
		'Όλοι' => 'Everyone',
		'Όνομα' => 'Name',
		'Όριο χρήσεων' => 'Usage limit',
		'Αιτία' => 'Reason',
		'Αλλαγές' => 'Changes',
		'Αλλαγή' => 'Changed',
		'Αλλαγή αρχείου από τον επεξεργαστή αρχείων' => 'File changed in the file editor',
		'Αλλαγή θέματος' => 'Theme switched',
		'Αλλαγή κατάστασης παραγγελίας' => 'Order status changed',
		'Αλλαγή κουπονιού' => 'Coupon changed',
		'Αλλαγή λογαριασμού' => 'Account changed',
		'Αλλαγή μενού' => 'Menu changed',
		'Αλλαγή παραγγελίας' => 'Order changed',
		'Αλλαγή προϊόντος' => 'Product changed',
		'Αλλαγή ρόλου' => 'Role changed',
		'Αλλαγή ρύθμισης' => 'Setting changed',
		'Αν τις έκανες εσύ ή κάποιος που ξέρεις, δεν χρειάζεται τίποτα.' => 'If you or someone you know made them, nothing is needed.',
		'Ανάκληση Application Password' => 'Application password revoked',
		'Αναζήτηση' => 'Search',
		'Αντικείμενο' => 'Object',
		'Απενεργοποίηση plugin' => 'Plugin deactivated',
		'Αποθήκευση' => 'Save',
		'Αποσύνδεση' => 'Logout',
		'Αποτυχημένη σύνδεση' => 'Failed login',
		'Αποτυχημένη σύνδεση με άγνωστο όνομα χρήστη' => 'Failed login with an unknown user name',
		'Από' => 'From',
		'Απόθεμα' => 'Stock',
		'Απόθεμα που αλλάζει από παραγγελίες' => 'Stock changed by orders',
		'Απόσπασμα' => 'Excerpt',
		'Αρχεία λήψης' => 'Downloads',
		'Αρχεία πολυμέσων' => 'Media files',
		'Αρχείο' => 'File',
		'Βάρος' => 'Weight',
		'Για προχωρημένους' => 'Advanced',
		'Για την ασφάλεια του site κρατάμε ιστορικό των αλλαγών που κάνουν οι διαχειριστές και το προσωπικό: ποιος άλλαξε τι και πότε. Για τους πελάτες γράφονται μόνο αλλαγές κατάστασης παραγγελιών και λογαριασμών, με αριθμό και χωρίς όνομα, email ή διεύθυνση.' => 'For the security of the site we keep a history of the changes made by administrators and staff: who changed what and when. For customers only order and account status changes are written, by number and without name, e-mail or address.',
		'Γονέας' => 'Parent',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Δεν καταγράφονται ποτέ: αναγνώσεις, κωδικοί και κλειδιά, ονόματα, email και διευθύνσεις πελατών, ό,τι πληκτρολογήθηκε σε αποτυχημένη σύνδεση. Τα 404 τα κρατά το Redirects και τις κλήσεις του Claude το Site Bridge.' => 'Never recorded: page views, passwords and keys, customer names, e-mails and addresses, what was typed into a failed login. Redirects keeps the 404s and Site Bridge keeps Claude\'s calls.',
		'Δημιουργία' => 'Created',
		'Δημοσίευση' => 'Published',
		'Δημοσίευση κουπονιού' => 'Coupon published',
		'Δημοσίευση προϊόντος' => 'Product published',
		'Διαγραμμένος χρήστης' => 'Deleted user',
		'Διαγραφή plugin' => 'Plugin deleted',
		'Διαγραφή αρχείου πολυμέσων' => 'Media file deleted',
		'Διαγραφή θέματος' => 'Theme deleted',
		'Διαγραφή μενού' => 'Menu deleted',
		'Διαγραφή χρήστη' => 'User deleted',
		'Διαθεσιμότητα' => 'Stock status',
		'Διαχείριση αποθέματος' => 'Manage stock',
		'Διεύθυνση IP' => 'IP address',
		'Είδος έκπτωσης' => 'Discount type',
		'Εγκατάσταση plugin' => 'Plugin installed',
		'Εγκατάσταση θέματος' => 'Theme installed',
		'Εικόνα' => 'Image',
		'Ενέργεια' => 'Action',
		'Ενεργοποίηση plugin' => 'Plugin activated',
		'Ενημέρωση WordPress' => 'WordPress updated',
		'Ενημέρωση plugin' => 'Plugin updated',
		'Ενημέρωση θέματος' => 'Theme updated',
		'Εξαγωγή CSV' => 'Export CSV',
		'Επαναφορά' => 'Restored',
		'Επαναφορά κουπονιού' => 'Coupon restored',
		'Επαναφορά κωδικού' => 'Password reset',
		'Επαναφορά προϊόντος' => 'Product restored',
		'Επιπλέον options που παρακολουθούνται (ένα ανά γραμμή)' => 'More options to watch (one per line)',
		'Επισκέπτης' => 'Visitor',
		'Επιστροφή χρημάτων' => 'Refund',
		'Εργασία παρασκηνίου' => 'Background job',
		'Ετικέτες' => 'Tags',
		'Ημέρες ιστορικού' => 'Days of history',
		'Ημερομηνία' => 'Date',
		'Θέμα' => 'Theme',
		'Ιδιωτικότητα και διάρκεια' => 'Privacy and retention',
		'Ιστορικό' => 'History',
		'Ιστορικό αλλαγών' => 'Activity Log',
		'Ιστορικό αλλαγών: τελευταία σημαντικά' => 'Activity Log: latest important changes',
		'Κάθε μέρα σβήνονται οι γραμμές που πέρασαν τις ημέρες και οι παλαιότερες πάνω από το όριο.' => 'Every day, rows older than the days and the oldest rows above the limit are deleted.',
		'Καθαρισμός' => 'Clear',
		'Καθόλου' => 'None',
		'Καμία αλλαγή ακόμα.' => 'No changes yet.',
		'Καμία σημαντική αλλαγή ακόμα.' => 'No important changes yet.',
		'Κανονική τιμή' => 'Regular price',
		'Κατάργηση δημοσίευσης' => 'Unpublished',
		'Κατάσταση' => 'Status',
		'Κατηγορία' => 'Category',
		'Κατηγορίες' => 'Categories',
		'Κλάση αποστολής' => 'Shipping class',
		'Κουπόνι στον κάδο' => 'Coupon trashed',
		'Κουπόνια' => 'Coupons',
		'Κρίσιμο' => 'Critical',
		'Κωδικός' => 'Password',
		'Λήξη' => 'Expiry',
		'Μέσω της πύλης πληρωμής' => 'Through the payment gateway',
		'Μαζί γράφεται η διεύθυνση IP χωρίς το τελευταίο μέρος της, ώστε να μη δείχνει μία σύνδεση.' => 'The IP address is stored without its last part, so it does not point to one connection.',
		'Μαζί γράφεται η διεύθυνση IP.' => 'The IP address is stored with it.',
		'Με μάσκα: χωρίς το τελευταίο μέρος (προτείνεται)' => 'Masked: without the last part (recommended)',
		'Με τη διαγραφή του plugin φεύγουν ο πίνακας και οι ρυθμίσεις του.' => 'Deleting the plugin removes its table and its settings.',
		'Μενού' => 'Menus',
		'Μόνο κρίσιμα' => 'Critical only',
		'Νέα παραγγελία' => 'New order',
		'Νέες παραγγελίες από το ταμείο' => 'New checkout orders',
		'Νέο Application Password' => 'New application password',
		'Νέο αρχείο πολυμέσων' => 'Media file uploaded',
		'Νέο κουπόνι' => 'New coupon',
		'Νέο προϊόν' => 'New product',
		'Νέος χρήστης' => 'New user',
		'Ναι, άδειασε το ιστορικό' => 'Yes, empty the history',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Ορατότητα στον κατάλογο' => 'Catalog visibility',
		'Οριστική διαγραφή' => 'Deleted',
		'Οριστική διαγραφή κουπονιού' => 'Coupon deleted',
		'Οριστική διαγραφή παραγγελίας' => 'Order deleted',
		'Οριστική διαγραφή προϊόντος' => 'Product deleted',
		'Πάνω από 30 αποτυχημένες συνδέσεις αυτή την ώρα: οι υπόλοιπες δεν γράφονται' => 'More than 30 failed logins this hour: the rest are not written',
		'Παραγγελία' => 'Order',
		'Παραγγελία στον κάδο' => 'Order trashed',
		'Παραγγελίες (κατάσταση, επιστροφές, αλλαγές από το admin)' => 'Orders (status, refunds, admin changes)',
		'Πελάτης #%d' => 'Customer #%d',
		'Περιγραφή' => 'Description',
		'Περιεχόμενο' => 'Content',
		'Πηγή' => 'Source',
		'Πλήρης' => 'Full',
		'Πληροφορία' => 'Info',
		'Ποιος' => 'Who',
		'Ποιος άλλαξε τι και πότε στο site, από το admin, το REST API, το WP-CLI, το cron ή το Site Bridge. Χωρίς μυστικά και χωρίς στοιχεία πελατών.' => 'Who changed what and when on the site, from the admin, the REST API, WP-CLI, cron or Site Bridge. No secrets and no customer details.',
		'Ποσό' => 'Amount',
		'Προβεβλημένο' => 'Featured',
		'Προγραμματισμένη δημοσίευση' => 'Scheduled',
		'Προγραμματισμένη δημοσίευση προϊόντος' => 'Product scheduled',
		'Προπαραγγελίες χωρίς απόθεμα' => 'Backorders',
		'Προς' => 'To',
		'Προσοχή' => 'Warning',
		'Προσοχή και πάνω' => 'Warning and above',
		'Προσφορά έως' => 'Sale to',
		'Προσφορά από' => 'Sale from',
		'Προϊόν στον κάδο' => 'Product trashed',
		'Προϊόντα' => 'Products',
		'Πότε' => 'When',
		'Πύλη πληρωμής' => 'Payment gateway',
		'Ρυθμίσεις' => 'Settings',
		'Ρόλος' => 'Role',
		'Σβήνει όλες τις γραμμές. Μένει μόνο μία, που λέει ποιος άδειασε το ιστορικό και πότε. Δεν αναιρείται.' => 'Deletes every row. Only one stays, saying who emptied the history and when. This cannot be undone.',
		'Σελίδες και άρθρα' => 'Pages and posts',
		'Σημαντικό' => 'Notable',
		'Σημαντικό και πάνω' => 'Notable and above',
		'Σημασία' => 'Importance',
		'Σουίτα Noxpress' => 'Noxpress suite',
		'Στείλε email όταν μπαίνει νέος διαχειριστής, εγκαθίσταται, ενεργοποιείται ή σβήνεται plugin, αλλάζει θέμα, αλλάζει η διεύθυνση ή το email του site, ή χρησιμοποιείται ο επεξεργαστής αρχείων' => 'Send an e-mail when a new administrator appears, a plugin is installed, activated or deleted, the theme is switched, the site address or e-mail changes, or the file editor is used',
		'Στοιχεία αποστολής' => 'Shipping details',
		'Στοιχεία πελάτη' => 'Customer details',
		'Στοιχεία χρέωσης' => 'Billing details',
		'Στον κάδο' => 'Trashed',
		'Συλλογή εικόνων' => 'Gallery',
		'Συνδέσεις πελατών' => 'Customer logins',
		'Συντάκτης' => 'Author',
		'Σύνδεση' => 'Login',
		'Σύνδεση με σύνδεσμο (Magic Login)' => 'Login with a link (Magic Login)',
		'Σύνολο' => 'Total',
		'Σύντομη περιγραφή' => 'Short description',
		'Σύστημα' => 'System',
		'Τίτλος' => 'Title',
		'Ταμείο' => 'Checkout',
		'Τι' => 'What',
		'Τι καταγράφεται' => 'What is recorded',
		'Τι κλειδώθηκε' => 'What was locked',
		'Τιμή' => 'Value',
		'Τρέχουσα τιμή' => 'Current price',
		'Τιμή προσφοράς' => 'Sale price',
		'Το ACL_DISABLE είναι ενεργό στο wp-config.php: τίποτα δεν καταγράφεται.' => 'ACL_DISABLE is on in wp-config.php: nothing is recorded.',
		'Το Activity Log κατέγραψε αλλαγές που αξίζει να ελέγξεις:' => 'Activity Log recorded changes worth a look:',
		'Το email αυτό κλείνει από τις Ρυθμίσεις του Activity Log.' => 'You can turn this e-mail off in the Activity Log settings.',
		'Το ιστορικό άδειασε' => 'History emptied',
		'Το ιστορικό άδειασε.' => 'The history was emptied.',
		'Το ιστορικό σβήνεται αυτόματα μετά από %d ημέρες.' => 'The history is deleted automatically after %d days.',
		'Το κουπόνι απενεργοποιήθηκε' => 'Coupon turned off',
		'Το προϊόν έφυγε από το κατάστημα' => 'Product taken off the shop',
		'Τρόπος πληρωμής' => 'Payment method',
		'Ημερομηνία πληρωμής' => 'Date paid',
		'Ημερομηνία ολοκλήρωσης' => 'Date completed',
		'Τσέκαρε «Ναι, άδειασε το ιστορικό» για να συνεχίσεις.' => 'Tick «Yes, empty the history» to go on.',
		'Τύπος' => 'Type',
		'Φίλτρο' => 'Filter',
		'Φορολογική κατάσταση' => 'Tax status',
		'Φορολογική κλάση' => 'Tax class',
		'Χαρακτηριστικά' => 'Attributes',
		'Χρήστες και συνδέσεις προσωπικού' => 'Users and staff logins',
		'άλλαξε' => 'changed',
		'άλλαξε (η τιμή δεν γράφεται)' => 'changed (value not stored)',
		'αποθηκεύτηκε' => 'saved',
		'γραμμή #%s του Site Bridge' => 'Site Bridge row #%s',
		'και άλλες αλλαγές' => 'and more changes',
		'πολλές αλλαγές: δεν χωρούν' => 'too many changes to fit',
		'προϊόν, ρύθμιση, χρήστης…' => 'product, setting, user…',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_activity-log', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_activity-log', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** @var string|null Language forced for an e-mail: 'el' | 'en'. */
	private static $forced = null;

	/** Sets (or with null clears) the language for texts made now. */
	public static function force( ?string $lang ): void {
		self::$forced = in_array( $lang, array( 'el', 'en' ), true ) ? $lang : null;
	}

	/** 'el' | 'en' for a given user: rs_lang, else their locale. */
	public static function for_user( int $uid ): string {
		$choice = (string) get_user_meta( $uid, 'rs_lang', true );
		if ( 'el' === $choice || 'en' === $choice ) {
			return $choice;
		}
		$locale = (string) get_user_meta( $uid, 'locale', true );
		return self::from_locale( '' !== $locale ? $locale : (string) get_locale() );
	}

	/** 'el' | 'en' for the current admin user. */
	public static function lang(): string {
		if ( null !== self::$forced ) {
			return self::$forced;
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

	/** gettext_activity-log filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'activity-log' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_activity-log filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'activity-log' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
