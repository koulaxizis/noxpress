<?php
/**
 * ORC_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitor texts are settings, not msgids
 * (Bible §14.17, ORC_Settings::text()).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Modules' => 'Modules',
		'Newsletter' => 'Newsletter',
		'Εργαλεία' => 'Tools',
		'orOS Connect' => 'orOS Connect',
		'Ρυθμίσεις' => 'Settings',
		'Όταν γράφεσαι στο newsletter μας (φόρμα εγγραφής ή κουτάκι στο ταμείο), το email σου, το όνομά σου αν το δώσεις και η γλώσσα σου στέλνονται στη λίστα μας στο orOS Newsletter μέσω του relay του orOS (Cloudflare Workers). Εκεί κρατιούνται κρυπτογραφημένα μέχρι να τα παραλάβουμε, και μετά διαγράφονται από το relay. Θα λάβεις email επιβεβαίωσης, και κάθε email μας έχει σύνδεσμο διαγραφής.' => 'When you subscribe to our newsletter (sign-up form or checkbox at checkout), your email, your name if you give it and your language are sent to our list in orOS Newsletter through the orOS relay (Cloudflare Workers). They are kept there encrypted until we collect them, and then deleted from the relay. You will get a confirmation email, and every email we send has an unsubscribe link.',
		'Στις παραγγελίες κρατάμε πότε δόθηκε η συναίνεση στο ταμείο και το κείμενό της, ως απόδειξη της συναίνεσης.' => 'In orders we keep when the consent was given at checkout and its text, as proof of the consent.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Σύνδεση του site με το orOS. Κάθε module ενεργοποιείται χωριστά· όσα είναι κλειστά δεν φορτώνουν τίποτα.' => 'Connects the site to orOS. Each module is switched on separately; the ones that are off load nothing.',
		'Το ORC_DISABLE είναι ενεργό στο wp-config.php: καμία φόρμα, κανένα κουτάκι στο ταμείο και καμία αποστολή.' => 'ORC_DISABLE is on in wp-config.php: no form, no checkbox at checkout and no sends.',
		'Ενεργό' => 'On',
		'Κλειστό' => 'Off',
		'Φόρμα εγγραφής (μπλοκ, shortcode, widget) και κουτάκι στο ταμείο, που στέλνουν νέους συνδρομητές στη λίστα σου στο orOS Newsletter.' => 'Sign-up form (block, shortcode, widget) and a checkbox at checkout that send new subscribers to your list in orOS Newsletter.',
		'Ενεργοποίηση' => 'Switch on',
		'Stock ledger' => 'Stock ledger',
		'Κάθε αλλαγή αποθέματος του καταστήματος στο orOS Stock, και αλλαγές από το orOS κατά διαφορά, χωρίς χαμένες πωλήσεις.' => 'Every stock change of the shop in orOS Stock, and changes from orOS as differences, with no lost sales.',
		'Receipt link' => 'Receipt link',
		'Ο ΜΑΡΚ και το PDF της απόδειξης από το orOS Invoices στον λογαριασμό του πελάτη και στο email.' => 'The ΜΑΡΚ and the receipt PDF from orOS Invoices in the customer\'s account and email.',
		'Inbox' => 'Inbox',
		'Νέες παραγγελίες, υπαναχωρήσεις και χαμηλό απόθεμα στο Today του orOS.' => 'New orders, withdrawals and low stock in orOS Today.',
		'Σύντομα' => 'Coming soon',
		'Λειτουργία' => 'Mode',
		'Δοκιμή: μόνο οι διαχειριστές βλέπουν τη φόρμα και το κουτάκι στο ταμείο' => 'Test: only managers see the form and the checkbox at checkout',
		'Ενεργό για όλους' => 'Live for everyone',
		'Ανενεργό: τίποτα στο site (οι συναινέσεις που δόθηκαν ήδη στέλνονται κανονικά)' => 'Off: nothing on the site (consents already given are still sent)',
		'Γλώσσα των κειμένων για τους επισκέπτες' => 'Language of the visitor texts',
		'Αυτόματα, από τη γλώσσα του site' => 'Automatic, from the site language',
		'Ελληνικά' => 'Greek',
		'English' => 'English',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'English (κενό = το ελληνικό)' => 'English (empty = the Greek one)',
		'Το module Newsletter είναι κλειστό. Οι ρυθμίσεις αποθηκεύονται, αλλά τίποτα δεν φαίνεται στο site μέχρι να το ενεργοποιήσεις στα Modules.' => 'The Newsletter module is off. Settings are saved, but nothing shows on the site until you switch it on in Modules.',
		'Σύνδεση με το orOS' => 'Connection to orOS',
		'Στο orOS: Newsletter → η λίστα σου → Subscribe link → ενεργοποίησε τη φόρμα εγγραφής.' => 'In orOS: Newsletter → your list → Subscribe link → turn the sign-up form on.',
		'Αντέγραψε το «HTML για το site σου» (ή μόνο τη διεύθυνση https://…/nl/s/…).' => 'Copy the «HTML for your site» (or only the address https://…/nl/s/…).',
		'Επικόλλησέ το εδώ. Κρατάμε μόνο τη διεύθυνση εγγραφής.' => 'Paste it here. Only the sign-up address is kept.',
		'Διεύθυνση εγγραφής' => 'Sign-up address',
		'Έλεγχος σύνδεσης' => 'Check connection',
		'Μετά την εγγραφή ο επισκέπτης βλέπει τη σελίδα ευχαριστίας που όρισες στο orOS (Subscribe link → σελίδα ευχαριστίας), αλλιώς ένα απλό μήνυμα του orOS. Το orOS στέλνει το email επιβεβαίωσης.' => 'After signing up the visitor sees the thank-you page you set in orOS (Subscribe link → thank-you page), otherwise a plain orOS message. orOS sends the confirmation email.',
		'Φόρμα εγγραφής' => 'Sign-up form',
		'Πεδίο για όνομα (προαιρετικό για τον επισκέπτη)' => 'Name field (optional for the visitor)',
		'Τίτλος' => 'Title',
		'Κείμενο πάνω από τη φόρμα' => 'Text above the form',
		'Πεδίο email' => 'Email field',
		'Πεδίο ονόματος' => 'Name field',
		'Κουμπί' => 'Button',
		'Σημείωση κάτω από τη φόρμα' => 'Note under the form',
		'Πού μπαίνει: το μπλοκ «Newsletter (orOS)», το shortcode [oros_newsletter] (title="no" χωρίς τίτλο, name="yes|no") ή το widget «Newsletter (orOS)».' => 'Where it goes: the «Newsletter (orOS)» block, the [oros_newsletter] shortcode (title="no" without the title, name="yes|no") or the «Newsletter (orOS)» widget.',
		'Ταμείο' => 'Checkout',
		'Κουτάκι newsletter στο ταμείο (ατσέκαρτο), στο κλασικό ταμείο και στο μπλοκ Checkout' => 'Newsletter checkbox at checkout (unticked), in the classic checkout and the Checkout block',
		'Κείμενο του κουτιού' => 'Checkbox text',
		'Ο πελάτης στέλνεται στο orOS όταν πληρωθεί η παραγγελία (σε επεξεργασία ή ολοκληρωμένη), με το email και το όνομα χρέωσης. Η συναίνεση μένει στην παραγγελία.' => 'The customer is sent to orOS when the order is paid (processing or completed), with the billing email and name. The consent stays in the order.',
		'Επιβεβαίωση' => 'Confirmation',
		'Email επιβεβαίωσης και για όσους γράφονται από το ταμείο (double opt-in)' => 'Confirmation email for checkout sign-ups too (double opt-in)',
		'Κλειστό: η συναίνεση στο ταμείο αρκεί και ο πελάτης γράφεται κατευθείαν. Για να το δεχτεί το orOS, η εγγραφή υπογράφεται με το κλειδί καταστήματος· όσο το orOS δεν το υποστηρίζει ακόμα, στέλνεται email επιβεβαίωσης σε όλους.' => 'Off: the consent at checkout is enough and the customer is subscribed directly. For orOS to accept that, the sign-up is signed with the shop key; until orOS supports it, everyone gets a confirmation email.',
		'Κλειδί καταστήματος' => 'Shop key',
		'32 γράμματα και αριθμοί' => '32 letters and digits',
		'Νέο κλειδί' => 'New key',
		'Το ίδιο κλειδί μπαίνει και στο orOS, στη λίστα (Subscribe link). Κράτα το μυστικό: όποιος το έχει μπορεί να γράφει emails στη λίστα χωρίς επιβεβαίωση.' => 'The same key goes into orOS, in the list (Subscribe link). Keep it secret: whoever has it can add emails to the list without confirmation.',
		'Τελευταίες αποστολές από το ταμείο' => 'Latest sends from checkout',
		'Πότε' => 'When',
		'Παραγγελία' => 'Order',
		'Αποτέλεσμα' => 'Result',
		'Προσπάθεια' => 'Try',
		'Καμία αποστολή ακόμα.' => 'No sends yet.',
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Χωρίς το κλειδί καταστήματος: μετά την εισαγωγή το υπάρχον κλειδί μένει όπως είναι.' => 'Without the shop key: after an import the current key stays as it is.',
		'Ιστορικό αποστολών' => 'Send log',
		'Κρατά τις τελευταίες 50 προσπάθειες, χωρίς emails.' => 'Keeps the last 50 attempts, without emails.',
		'Καθαρισμός ιστορικού' => 'Clear the log',
		'Για developers' => 'For developers',
		'Η φόρμα εγγραφής.' => 'The sign-up form.',
		'(φίλτρο)' => '(filter)',
		'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).' => 'Changes a visitor text: ( $text, $key, $lang ).',
		'(action)' => '(action)',
		'Μετά από κάθε αποστολή από το ταμείο: ( $order_id, $result ).' => 'After each send from checkout: ( $order_id, $result ).',
		'Στο wp-config.php: σταματά τις φόρμες, το κουτάκι στο ταμείο και τις αποστολές.' => 'In wp-config.php: stops the forms, the checkbox at checkout and the sends.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Νέο κλειδί καταστήματος: βάλε το ίδιο και στο orOS. Το παλιό δεν ισχύει πια.' => 'New shop key: put the same one in orOS. The old one no longer works.',
		'Το ιστορικό καθαρίστηκε.' => 'The log was cleared.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Δεν βρέθηκε διεύθυνση εγγραφής του orOS (https://…/nl/s/ και 16 γράμματα ή αριθμοί). Η διεύθυνση άδειασε.' => 'No orOS sign-up address was found (https://…/nl/s/ and 16 letters or digits). The address was cleared.',
		'Το κλειδί καταστήματος θέλει ακριβώς 32 λατινικά γράμματα ή αριθμούς. Δεν κρατήθηκε.' => 'The shop key needs exactly 32 Latin letters or digits. It was not kept.',
		'Το κουτάκι στο ταμείο δεν φαίνεται μέχρι να μπει η διεύθυνση εγγραφής του orOS.' => 'The checkbox at checkout does not show until the orOS sign-up address is set.',
		'Δεν υπάρχει διεύθυνση εγγραφής για έλεγχο.' => 'There is no sign-up address to check.',
		'✓ Το orOS απάντησε: η λίστα υπάρχει και δέχεται εγγραφές.' => '✓ orOS answered: the list exists and accepts sign-ups.',
		'✗ Ο έλεγχος απέτυχε: %1$s (HTTP %2$d).' => '✗ The check failed: %1$s (HTTP %2$d).',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.' => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του orOS Connect).' => 'Invalid backup file (the orOS Connect settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import completed.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τη φόρμα.' => 'Test mode: only managers see the form.',
		'orOS Connect: η φόρμα newsletter δεν έχει ακόμα τη διεύθυνση εγγραφής του orOS (Noxpress → orOS Connect → Newsletter).' => 'orOS Connect: the newsletter form does not have the orOS sign-up address yet (Noxpress → orOS Connect → Newsletter).',
		'orOS Connect: η φόρμα newsletter είναι κλειστή (λειτουργία «Ανενεργό»).' => 'orOS Connect: the newsletter form is off («Off» mode).',
		'Newsletter: ο πελάτης στάλθηκε στη λίστα του orOS.' => 'Newsletter: the customer was sent to the orOS list.',
		'Newsletter: η αποστολή στο orOS απέτυχε (%s).' => 'Newsletter: sending to orOS failed (%s).',
		'στάλθηκε στο orOS' => 'sent to orOS',
		'απέτυχε' => 'failed',
		'σε αναμονή' => 'queued',
		'θα σταλεί όταν πληρωθεί η παραγγελία' => 'will be sent when the order is paid',
		'Newsletter:' => 'Newsletter:',
		'συναίνεση στο ταμείο, %s' => 'consent at checkout, %s',
		'εντάξει' => 'OK',
		'άκυρο email' => 'invalid email',
		'η διεύθυνση εγγραφής δεν ισχύει ή η φόρμα έκλεισε στο orOS' => 'the sign-up address is not valid or the form was turned off in orOS',
		'πολλά αιτήματα, νέα προσπάθεια αργότερα' => 'too many requests, will try again later',
		'οι εγγραφές δεν είναι διαθέσιμες στο relay του orOS' => 'sign-ups are not available on the orOS relay',
		'σφάλμα του relay' => 'relay error',
		'δεν υπήρξε σύνδεση' => 'no connection',
		'το module ή η διεύθυνση λείπει' => 'the module or the address is missing',
		'Newsletter (orOS)' => 'Newsletter (orOS)',
		'Φόρμα εγγραφής στη λίστα σου στο orOS Newsletter.' => 'Sign-up form for your list in orOS Newsletter.',
		'Τίτλος (κενό = ο τίτλος των ρυθμίσεων):' => 'Title (empty = the title in the settings):',
		'Τα κείμενα και η διεύθυνση του orOS ρυθμίζονται στο Noxpress → orOS Connect.' => 'The texts and the orOS address are set in Noxpress → orOS Connect.',
	);

	public static function init(): void {
		add_filter( 'gettext_oros-connect', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current user (admin screens, manager notes). */
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

	/** gettext_oros-connect filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'oros-connect' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
