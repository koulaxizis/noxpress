<?php
/**
 * MGL_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English.
 *
 * The customer e-mail is built inside with_lang( <visitor language> ), so
 * it follows the language of the request, not the admin's rs_lang. The
 * texts of the login forms are settings, not msgids (Bible §14.17,
 * MGL_Settings::text()).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	/** Forced language (with_lang), '' = none. */
	private static $force = '';

	private static $dict = array(
		// E-mail.
		'Γεια σου %s,' => 'Hello %s,',
		'Γεια σου,' => 'Hello,',
		'Άνοιξε τον παρακάτω σύνδεσμο για να συνδεθείς στον λογαριασμό σου στο %1$s. Ισχύει %2$d λεπτά και μόνο μία φορά.' => 'Open the link below to log in to your account at %1$s. It is valid for %2$d minutes and works only once.',
		'Αν δεν ζήτησες εσύ σύνδεση, αγνόησε αυτό το email. Κανείς δεν μπορεί να συνδεθεί χωρίς τον σύνδεσμο, και ο κωδικός σου δεν άλλαξε.' => 'If you did not ask to log in, ignore this email. Nobody can log in without the link, and your password has not changed.',
		'Πάτησε το κουμπί για να συνδεθείς στον λογαριασμό σου στο %1$s. Ο σύνδεσμος ισχύει %2$d λεπτά και μόνο μία φορά.' => 'Press the button to log in to your account at %1$s. The link is valid for %2$d minutes and works only once.',
		'Σύνδεση' => 'Log in',
		'Αν το κουμπί δεν ανοίγει, αντίγραψε αυτή τη διεύθυνση στον browser:' => 'If the button does not open, copy this address into your browser:',
		'Magic Login: σύνδεσμος σύνδεσης' => 'Magic Login: login link',
		'Στέλνεται στον πελάτη όταν ζητά σύνδεση χωρίς κωδικό, με σύνδεσμο μίας χρήσης. Αν το απενεργοποιήσεις, το κουμπί «Στείλε μου σύνδεσμο σύνδεσης» κρύβεται.' => 'Sent to the customer who asks to log in without a password, with a one-time link. If you switch it off, the «Email me a login link» button is hidden.',
		'Ο σύνδεσμος σύνδεσης στο {site_title}' => 'Your login link for {site_title}',
		'Σύνδεση χωρίς κωδικό' => 'Log in without a password',
		// Log events.
		'Στάλθηκε σύνδεσμος' => 'Link sent',
		'Σύνδεση με σύνδεσμο' => 'Logged in by link',
		'Σύνδεσμος που έληξε ή χρησιμοποιήθηκε' => 'Expired or used link',
		'Αίτημα για email χωρίς λογαριασμό' => 'Request for an email without an account',
		'Αίτημα για λογαριασμό προσωπικού (χωρίς email)' => 'Request for a staff account (no email)',
		'Λειτουργία δοκιμής: ο λογαριασμός δεν είναι στη λίστα δοκιμής' => 'Test mode: the account is not a test account',
		'Όριο αιτημάτων' => 'Rate limit',
		'Το email δεν στάλθηκε' => 'The email was not sent',
		// Page, tabs.
		'Ρυθμίσεις' => 'Settings',
		'Ιστορικό' => 'Log',
		'Εργαλεία' => 'Tools',
		'Magic Login' => 'Magic Login',
		'Όταν ζητάς σύνδεση χωρίς κωδικό, κρατάμε για λίγα λεπτά (μέχρι να λήξει ή να χρησιμοποιηθεί) ένα κρυπτογραφημένο αποτύπωμα του συνδέσμου που σου στέλνουμε με email. Για να εμποδίσουμε την κατάχρηση μετράμε τα αιτήματα ανά λογαριασμό και ανά διεύθυνση IP για έως μία ώρα, χωρίς να αποθηκεύουμε τη διεύθυνση IP. Κρατάμε ιστορικό με τις τελευταίες 200 ενέργειες (ώρα, αριθμός λογαριασμού, ενέργεια), χωρίς email και διευθύνσεις IP.' => 'When you ask to log in without a password, we keep a hashed fingerprint of the link we email you for a few minutes (until it expires or is used). To prevent abuse we count requests per account and per IP address for up to an hour, without storing the IP address. We keep a log of the last 200 actions (time, account number, action), without emails or IP addresses.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Σύνδεση πελατών χωρίς κωδικό: στη φόρμα σύνδεσης ο πελάτης γράφει το email του και παίρνει σύνδεσμο μίας χρήσης. Ο κωδικός μένει ως εναλλακτική, και οι λογαριασμοί προσωπικού (διαχειριστές, διαχειριστές καταστήματος, συντάκτες) συνδέονται μόνο με κωδικό.' => 'Passwordless login for customers: in the login form the customer enters their email and gets a one-time link. The password stays as an alternative, and staff accounts (administrators, shop managers, editors) log in with a password only.',
		'Το MGL_DISABLE είναι ενεργό στο wp-config.php: κανένα κουμπί και κανένας σύνδεσμος δεν λειτουργεί.' => 'MGL_DISABLE is on in wp-config.php: no button and no link works.',
		'Ελληνικά' => 'Greek',
		'English (κενό = το ελληνικό)' => 'English (empty = the Greek one)',
		// Settings: checks.
		'Έλεγχος' => 'Checks',
		'Ενεργό' => 'On',
		'Κλειστό' => 'Off',
		'Το email «Magic Login: σύνδεσμος σύνδεσης» του WooCommerce.' => 'The WooCommerce email «Magic Login: login link».',
		'Θέμα, επικεφαλίδα και ενεργοποίηση' => 'Subject, heading and switch',
		'Με το email κλειστό το κουμπί δεν εμφανίζεται πουθενά.' => 'With the email off, the button shows nowhere.',
		'Ναι' => 'Yes',
		'Όχι' => 'No',
		'Σελίδα «Ο λογαριασμός μου» (εκεί ανοίγει ο σύνδεσμος του email).' => 'The «My account» page (the email link opens there).',
		'Φόρμα σύνδεσης στο ταμείο (WooCommerce → Ρυθμίσεις → Λογαριασμοί και απόρρητο, η σύνδεση σε υπάρχοντα λογαριασμό στο ταμείο).' => 'Login form at checkout (WooCommerce → Settings → Accounts & Privacy, logging in to an existing account during checkout).',
		'Τα email φεύγουν μέσω wp_mail, όπως όλα τα email του WooCommerce (π.χ. FluentSMTP). Το Checkout block δεν έχει φόρμα σύνδεσης: εκεί ο πελάτης συνδέεται από το «Ο λογαριασμός μου».' => 'The emails go out through wp_mail, like every WooCommerce email (e.g. FluentSMTP). The Checkout block has no login form: there the customer logs in from «My account».',
		// Settings: mode.
		'Λειτουργία' => 'Mode',
		'Ανενεργό: κανένα κουμπί, κανένας σύνδεσμος δεν λειτουργεί' => 'Off: no button, no link works',
		'Δοκιμή: το κουμπί φαίνεται μόνο με ?mgl-test στη διεύθυνση και σύνδεσμοι πάνε μόνο στους λογαριασμούς δοκιμής' => 'Test: the button shows only with ?mgl-test in the address, and links go only to the test accounts',
		'Ενεργό: όλοι οι πελάτες και οι λογαριασμοί χονδρικής' => 'Live: every customer and trade account',
		'Email των λογαριασμών δοκιμής (ένα ανά γραμμή, έως 10)' => 'Emails of the test accounts (one per line, up to 10)',
		'Πελάτες, όχι διαχειριστές: οι λογαριασμοί προσωπικού δεν συνδέονται ποτέ με σύνδεσμο. Σελίδα δοκιμής (αποσυνδεδεμένος):' => 'Customers, not administrators: staff accounts never log in by link. Test page (logged out):',
		'Γλώσσα των κειμένων και του email για τους επισκέπτες' => 'Language of the visitor texts and the email',
		'Αυτόματα, από τη γλώσσα του site' => 'Automatic, from the site language',
		'English' => 'English',
		// Settings: where, link, limits.
		'Πού εμφανίζεται το κουμπί' => 'Where the button shows',
		'Στη φόρμα σύνδεσης του «Ο λογαριασμός μου»' => 'In the login form of «My account»',
		'Στη φόρμα σύνδεσης του ταμείου (κλασικό ταμείο)' => 'In the checkout login form (classic checkout)',
		'Μετά τη σύνδεση ο πελάτης επιστρέφει εκεί που ζήτησε τον σύνδεσμο: στο ταμείο με το καλάθι του ή στη σελίδα του λογαριασμού.' => 'After the login the customer goes back to where they asked for the link: the checkout with their cart, or the account page.',
		'Σύνδεσμος' => 'Link',
		'Ισχύς του συνδέσμου (λεπτά, 5–60)' => 'Link lifetime (minutes, 5–60)',
		'Κάθε σύνδεσμος χρησιμοποιείται μία φορά. Ένας νέος ακυρώνει τον προηγούμενο, και μια αλλαγή κωδικού ακυρώνει όποιον εκκρεμεί.' => 'Every link works once. A new one cancels the previous one, and a password change cancels any pending link.',
		'Ο πελάτης μένει συνδεδεμένος 14 ημέρες (όπως με το «Να με θυμάσαι»)' => 'The customer stays logged in for 14 days (like «Remember me»)',
		'Όρια' => 'Limits',
		'Σύνδεσμοι ανά λογαριασμό σε 15 λεπτά (1–10)' => 'Links per account in 15 minutes (1–10)',
		'Αιτήματα ανά διεύθυνση IP σε μία ώρα (5–200)' => 'Requests per IP address in an hour (5–200)',
		'Μετρούν και τα αιτήματα για email χωρίς λογαριασμό, ώστε το όριο να μη δείχνει ποια email είναι πελάτες. Οι διευθύνσεις IP δεν αποθηκεύονται, μόνο ένα αποτύπωμά τους για μία ώρα.' => 'Requests for emails without an account count too, so the limit never shows which emails are customers. IP addresses are not stored, only a fingerprint of them for an hour.',
		// Settings: texts.
		'Κείμενα για τους επισκέπτες' => 'Visitor texts',
		'Το {minutes} γίνεται η ισχύς του συνδέσμου και το {email} το email του λογαριασμού, κρυμμένο εν μέρει (π.χ. k***@example.gr). Το κείμενο του email αλλάζει από το WooCommerce → Ρυθμίσεις → Emails ή με template του θέματος.' => '{minutes} becomes the link lifetime and {email} the account\'s email, partly hidden (e.g. k***@example.gr). The email itself is changed in WooCommerce → Settings → Emails or with a theme template.',
		'Κουμπί στη φόρμα σύνδεσης' => 'Button in the login form',
		'Οδηγία κάτω από το κουμπί' => 'Hint under the button',
		'Μήνυμα μετά το αίτημα (ίδιο, είτε υπάρχει λογαριασμός είτε όχι)' => 'Message after the request (the same whether an account exists or not)',
		'Όταν το πεδίο email είναι κενό' => 'When the email field is empty',
		'Όταν φτάσει το όριο αιτημάτων' => 'When the rate limit is reached',
		'Τίτλος της σελίδας επιβεβαίωσης' => 'Title of the confirmation page',
		'Κείμενο της σελίδας επιβεβαίωσης' => 'Text of the confirmation page',
		'Κουμπί της σελίδας επιβεβαίωσης' => 'Button of the confirmation page',
		'Σελίδα που έμεινε ανοιχτή πολύ ώρα' => 'Page left open for too long',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		// Log tab.
		'Τελευταίες 30 ημέρες' => 'Last 30 days',
		'Από τις εγγραφές που κρατά το ιστορικό (οι 200 νεότερες).' => 'From the entries the log keeps (the newest 200).',
		'Ώρα' => 'Time',
		'Λογαριασμός' => 'Account',
		'Ενέργεια' => 'Event',
		'Καμία εγγραφή ακόμα.' => 'No entries yet.',
		'Καθαρισμός ιστορικού' => 'Clear the log',
		// Tools.
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Μόνο οι ρυθμίσεις: σύνδεσμοι και ιστορικό δεν μπαίνουν στο αρχείο. Το θέμα και η επικεφαλίδα του email είναι ρυθμίσεις του WooCommerce.' => 'Settings only: links and the log are not in the file. The subject and heading of the email are WooCommerce settings.',
		'Για developers' => 'For developers',
		'(φίλτρο)' => '(filter)',
		'Αν ένας χρήστης μπορεί να συνδεθεί με σύνδεσμο: ( $ok, $user ). Οι λογαριασμοί προσωπικού μένουν πάντα εκτός.' => 'Whether a user may log in by link: ( $ok, $user ). Staff accounts always stay out.',
		'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).' => 'Changes a visitor text: ( $text, $key, $lang ).',
		'(action)' => '(action)',
		'Μετά από σύνδεση με σύνδεσμο: ( $user_id ). Τρέχει και το wp_login του WordPress.' => 'After a login by link: ( $user_id ). WordPress\'s wp_login runs too.',
		'Template του email, αντικαθίσταται από το θέμα στο yourtheme/woocommerce/emails/ (και plain/).' => 'The email template, overridden by the theme in yourtheme/woocommerce/emails/ (and plain/).',
		'Στο wp-config.php: κανένα κουμπί και κανένας σύνδεσμος δεν λειτουργεί.' => 'In wp-config.php: no button and no link works.',
		// Messages.
		'Το ιστορικό καθαρίστηκε.' => 'The log was cleared.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Λειτουργία δοκιμής χωρίς λογαριασμούς δοκιμής: κανένας σύνδεσμος δεν θα σταλεί.' => 'Test mode without test accounts: no link will be sent.',
		'Το email του Magic Login είναι κλειστό στο WooCommerce: το κουμπί δεν εμφανίζεται.' => 'The Magic Login email is off in WooCommerce: the button does not show.',
		'Λογαριασμός δοκιμής χωρίς χρήστη: %s' => 'Test account without a user: %s',
		'Ο λογαριασμός δοκιμής %s είναι λογαριασμός προσωπικού και δεν θα πάρει σύνδεσμο.' => 'The test account %s is a staff account and will not get a link.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.' => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Magic Login).' => 'Invalid backup file (the Magic Login settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import complete.',
		// Footer (Bible §7).
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		// Bootstrap.
		'Το Magic Login χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Magic Login needs WooCommerce to be active — the plugin stays inactive until it is activated.',
	);

	public static function init(): void {
		add_filter( 'gettext_magic-login', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_magic-login filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'magic-login' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
