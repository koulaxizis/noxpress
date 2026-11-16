<?php
/**
 * LVS_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitor texts are store settings with EL
 * and EN values (§14.17, LVS_Settings::text()), never gettext.
 *
 * Dictionary keys are byte-identical to the msgids in the code.
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'%1$s, %2$s, %3$d προϊόντα' => '%1$s, %2$s, %3$d products',
		'%d ημέρες' => '%d days',
		'Brands / εκδότες' => 'Brands / publishers',
		'ISBN, GTIN και SKU (και η άλλη μορφή του ISBN, 10 ↔ 13 ψηφία)' => 'ISBN, GTIN and SKU (and the other form of the ISBN, 10 ↔ 13 digits)',
		'Live Search' => 'Live Search',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Server (REST)' => 'Server (REST)',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Ένα έργο σε πολλές μορφές ως μία πρόταση (Product Formats)' => 'One work in many formats as one suggestion (Product Formats)',
		'Έτοιμο. Το ευρετήριο έχει %d προϊόντα.' => 'Done. The index has %d products.',
		'Ακριβές ISBN ενός προϊόντος: κατευθείαν στη σελίδα του' => 'The exact ISBN of a product: straight to its page',
		'Ακριβές ISBN: το Enter ανοίγει κατευθείαν' => 'Exact ISBN: Enter opens it directly',
		'Αναζήτηση' => 'Search',
		'Αναζήτηση προϊόντων με προτάσεις καθώς γράφει ο επισκέπτης: τίτλος, ISBN, συγγραφείς και ό,τι άλλο διαλέξεις, χωρίς τόνους, με greeklish και με ανοχή σε μικρά λάθη. Οι φόρμες αναζήτησης του θέματος μένουν ίδιες· απλώς βρίσκουν περισσότερα.' => 'Product search with suggestions as the visitor types: title, ISBN, authors and anything else you choose, without accents, with greeklish and tolerant of small typos. The theme\'s search forms stay the same; they simply find more.',
		'Αναζητήσεις: %1$s · χωρίς αποτέλεσμα: %2$s. Δεν μετράνε οι διαχειριστές, τα bots και η λειτουργία δοκιμής.' => 'Searches: %1$s · with no result: %2$s. Administrators, bots and test mode are not counted.',
		'Αντίγραφο ρυθμίσεων' => 'Settings backup',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Αποτελέσματα την τελευταία φορά' => 'Results the last time',
		'Από πού έρχονται οι προτάσεις' => 'Where the suggestions come from',
		'Από το FiboSearch' => 'From FiboSearch',
		'Απόθεμα' => 'Stock',
		'Αρχείο' => 'File',
		'Αρχείο στον browser' => 'File in the browser',
		'Αρχείο: ένα στατικό αρχείο με τα δημόσια στοιχεία των προϊόντων κατεβαίνει μία φορά, όταν ο επισκέπτης πατήσει στο πεδίο αναζήτησης, και κάθε πλήκτρο απαντά αμέσως, χωρίς αίτημα στον server. Server: ένα αίτημα ανά πληκτρολόγηση, για μεγάλους καταλόγους.' => 'File: a static file with the public details of the products is downloaded once, when the visitor taps the search field, and every key answers at once, with no request to the server. Server: one request per keystroke, for big catalogues.',
		'Αυτόματα' => 'Automatic',
		'Αυτόματα: server πάνω από' => 'Automatic: server above',
		'Βαθμός' => 'Score',
		'Γλώσσα' => 'Language',
		'Γράμματα πριν ξεκινήσουν' => 'Letters before they start',
		'Γράψε τι σημαίνει η φράση (χωρίς «=» και «,»).' => 'Write what the phrase means (without «=» and «,»).',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Δεν βρέθηκαν ρυθμίσεις του FiboSearch σε αυτό το site.' => 'No FiboSearch settings were found on this site.',
		'Δεν βρέθηκαν ρυθμίσεις του FiboSearch.' => 'No FiboSearch settings were found.',
		'Δεν υπάρχει ακόμα.' => 'Not yet.',
		'Δεν υπάρχουν ακόμα αναζητήσεις.' => 'No searches yet.',
		'Δημιουργοί:' => 'Creators:',
		'Διάλεξε ένα αρχείο JSON έως 256 KB.' => 'Choose a JSON file of up to 256 KB.',
		'Διαγραφή στατιστικών' => 'Delete statistics',
		'Δοκιμή' => 'Test',
		'Δοκιμή (μόνο διαχειριστές)' => 'Test (administrators only)',
		'Δοκιμή μιας φράσης' => 'Test a phrase',
		'Εισαγωγή / Εξαγωγή' => 'Import / Export',
		'Εισαγωγή JSON' => 'Import JSON',
		'Εισαγωγή από το FiboSearch' => 'Import from FiboSearch',
		'Ενεργό για όλους' => 'Live for everyone',
		'Ενότητα «Δημιουργοί» (Featured Creators)' => '«Creators» section (Featured Creators)',
		'Εξαγωγή JSON' => 'Export JSON',
		'Εξαντλημένα:' => 'Out of stock:',
		'Εξαντλημένο' => 'Out of stock',
		'Ετικέτες' => 'Tags',
		'Ευρετήριο' => 'Index',
		'Η γλώσσα του site' => 'The site\'s language',
		'Κάθε φόρμα αναζήτησης του site ψάχνει προϊόντα' => 'Every search form of the site searches products',
		'Καθαρίστηκε η page cache: %s.' => 'Page cache cleared: %s.',
		'Καμία.' => 'None.',
		'Κανονικοποίηση: «%1$s» · φωνητικό κλειδί: «%2$s» · βρέθηκαν: %3$d' => 'Normalised: «%1$s» · phonetic key: «%2$s» · found: %3$d',
		'Κατάσταση' => 'Status',
		'Κατάσταση ευρετηρίου' => 'Index status',
		'Κατηγορίες' => 'Categories',
		'Κείμενα για τον επισκέπτη' => 'Texts for the visitor',
		'Κλειστό' => 'Off',
		'Κράτα τι ψάχνουν οι επισκέπτες, σε ιδιωτικό αρχείο: μόνο η φράση, πόσα βρήκε και η ώρα, χωρίς IP, browser ή χρήστη, για 90 ημέρες' => 'Keep what visitors search for, in a private file: only the phrase, how many it found and the time, with no IP, browser or user, for 90 days',
		'Λέξεις που ψάχνουν οι επισκέπτες και δεν βρίσκουν τίποτα. Αν εννοούν κάτι που υπάρχει, πρόσθεσε ένα συνώνυμο.' => 'Words visitors search for and find nothing. If they mean something that exists, add a synonym.',
		'Λειτουργία δοκιμής: οι προτάσεις και η νέα σελίδα αποτελεσμάτων φαίνονται μόνο σε διαχειριστές του καταστήματος (εσένα).' => 'Test mode: the suggestions and the new results page are visible only to shop managers (you).',
		'Μέσα στο πεδίο' => 'Inside the field',
		'Μία ομάδα ανά γραμμή, π.χ. «ηχοβιβλίο = audiobook, ακουστικό βιβλίο». Κάθε λέξη βρίσκει και τις άλλες.' => 'One group per line, e.g. «audiobook = ηχοβιβλίο, spoken book». Each word also finds the others.',
		'Μικρή εικόνα' => 'Small image',
		'Μόνο οι φόρμες αναζήτησης προϊόντων' => 'Only the product search forms',
		'Να σβηστούν όλα τα στατιστικά;' => 'Delete all statistics?',
		'Ξαναχτίσιμο τώρα' => 'Rebuild now',
		'Ο τίτλος πάντα. Μετά από αλλαγή εδώ το ευρετήριο ξαναχτίζεται στο παρασκήνιο.' => 'The title always. After a change here the index is rebuilt in the background.',
		'Ο φάκελος των στατιστικών κατεβαίνει από τον browser (ο server αγνοεί το .htaccess, π.χ. nginx). Δεν έχει IP ή email, αλλά πρόσθεσε έναν κανόνα deny στον server ή κλείσε τα στατιστικά.' => 'The statistics folder can be downloaded from a browser (the server ignores .htaccess, e.g. nginx). It holds no IP or email, but add a deny rule on the server or turn the statistics off.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Οι ρυθμίσεις του FiboSearch πέρασαν. Το FiboSearch δεν άλλαξε: απενεργοποίησέ το όταν το Live Search είναι ενεργό και σε έχει πείσει.' => 'The FiboSearch settings were carried over. FiboSearch was not changed: deactivate it once Live Search is live and has convinced you.',
		'Περνούν τα γράμματα πριν ξεκινήσουν οι προτάσεις, το πλήθος τους, το κείμενο μέσα στο πεδίο, τα κείμενα «κανένα αποτέλεσμα» και «όλα», η εικόνα, η τιμή, τα εξαντλημένα, το SKU / GTIN και τα συνώνυμα. Οι ρυθμίσεις του FiboSearch μόνο διαβάζονται.' => 'Carried over: the letters before suggestions start, their number, the text inside the field, the «no result» and «all» texts, the image, the price, out of stock products, SKU / GTIN and the synonyms. The FiboSearch settings are only read.',
		'Πιο συχνές αναζητήσεις' => 'Most frequent searches',
		'Πλήθος προτάσεων' => 'Number of suggestions',
		'Προτάσεις από' => 'Suggestions from',
		'Προϊόν' => 'Product',
		'Προϊόντα στο ευρετήριο' => 'Products in the index',
		'Πρόσθεσε' => 'Add',
		'Πρόσωπα (π.χ. συγγραφείς): ψάχνονται με μεγάλο βάρος και φαίνονται κάτω από τον τίτλο' => 'People (e.g. authors): searched with a high weight and shown under the title',
		'Πρόσωπα κάτω από τον τίτλο' => 'People under the title',
		'Ρυθμίσεις' => 'Settings',
		'Σβήστηκαν %d αρχεία στατιστικών.' => '%d statistics files deleted.',
		'Σε αναμονή' => 'Waiting',
		'Στατιστικά' => 'Statistics',
		'Συνώνυμα' => 'Synonyms',
		'Συνώνυμο' => 'Synonym',
		'Συνώνυμο: «%1$s» = «%2$s».' => 'Synonym: «%1$s» = «%2$s».',
		'Σύνδεσμος για όλα' => 'Link to all results',
		'Σύντομη περιγραφή (μόνο στη σελίδα αποτελεσμάτων)' => 'Short description (only on the results page)',
		'Τα στατιστικά είναι κλειστά στις Ρυθμίσεις.' => 'Statistics are off in Settings.',
		'Τελευταίο πλήρες χτίσιμο' => 'Last full build',
		'Τι δείχνει μια πρόταση' => 'What a suggestion shows',
		'Τι ψάχνει' => 'What it searches',
		'Τιμή (οι λογαριασμοί χονδρικής δεν βλέπουν τιμή)' => 'Price (trade accounts see no price)',
		'Το FiboSearch είναι ακόμα ενεργό: οι φόρμες του δεν είναι φόρμες του θέματος, και οι δύο αναζητήσεις μπορεί να εμφανίζονται μαζί. Απενεργοποίησέ το όταν είσαι σίγουρος.' => 'FiboSearch is still active: its forms are not theme forms, and both searches may show together. Deactivate it once you are sure.',
		'Το LVS_DISABLE είναι ενεργό στο wp-config.php: οι προτάσεις, η σελίδα αποτελεσμάτων και τα routes είναι κλειστά.' => 'LVS_DISABLE is on in wp-config.php: the suggestions, the results page and the routes are closed.',
		'Το Live Search χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Live Search needs WooCommerce active — the plugin stays inactive until it is activated.',
		'Το plugin είναι κλειστό: οι επισκέπτες βλέπουν την αναζήτηση του θέματος όπως πριν. Διάλεξε «Δοκιμή» για να το δεις μόνο εσύ.' => 'The plugin is off: visitors see the theme\'s search as before. Choose «Test» to see it yourself only.',
		'Το αρχείο δεν είναι αντίγραφο ρυθμίσεων του Live Search.' => 'The file is not a Live Search settings backup.',
		'Το ευρετήριο ενημερώνεται μόνο του όταν αλλάζει ένα προϊόν, κάθε ώρα για ό,τι άλλαξε αλλιώς, και ολόκληρο κάθε βράδυ.' => 'The index updates itself when a product changes, every hour for anything else that changed, and in full every night.',
		'Το ευρετήριο ξαναχτίζεται στο παρασκήνιο (λίγα λεπτά). Μπορείς να το κάνεις και τώρα από την καρτέλα «Ευρετήριο».' => 'The index is being rebuilt in the background (a few minutes). You can also do it now from the «Index» tab.',
		'Το κατάστημα δεν έχει καθολικά χαρακτηριστικά προϊόντων.' => 'The store has no global product attributes.',
		'Το χτίσιμο σταμάτησε. Δοκίμασε ξανά· ό,τι έγινε μένει.' => 'The build stopped. Try again; what was done stays.',
		'Φορές' => 'Times',
		'Φράση' => 'Phrase',
		'Χαρακτηριστικό' => 'Attribute',
		'Χτίσιμο: %1$d από %2$d προϊόντα…' => 'Building: %1$d of %2$d products…',
		'Χωρίς αποτέλεσμα' => 'No result',
		'Ψάχνεται' => 'Searched',
		'καθόλου' => 'hidden',
		'κανονικά' => 'normally',
		'π.χ. h eirini, 978-960-…, συγγραφέας' => 'e.g. h eirini, 978-960-…, author',
		'προϊόντα' => 'products',
		'σημαίνει…' => 'means…',
		'στο τέλος' => 'last',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_live-search', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_live-search', array( __CLASS__, 'filter_n' ), 10, 5 );
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

	/** gettext_live-search filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'live-search' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_live-search filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'live-search' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
