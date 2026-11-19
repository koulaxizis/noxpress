<?php
/**
 * MMD_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ (Bible §9): 'el' | 'en' | ''/'auto'. Only
 * Revenue Splitter writes it; without RS this WordPress-only plugin shows
 * the same switch and writes the same meta (§9, 1.39). Without an explicit
 * choice the user's locale decides: el* → Greek (msgids pass through as
 * they are), anything else → English. The visitor page has its own texts
 * (settings, Bible §14.17), never these msgids.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class MMD_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'(πάντα)' => '(always)',
		'(φίλτρα)' => '(filters)',
		'(φίλτρο)' => '(filter)',
		'Abilities (WordPress 6.9+) για το Site Bridge, με το ίδιο δικαίωμα (manage_options).' => 'Abilities (WordPress 6.9+) for Site Bridge, with the same capability (manage_options).',
		'Header της σελίδας: maintenance, coming_soon ή preview.' => 'Header of the page: maintenance, coming_soon or preview.',
		'Maintenance Mode: είναι ενεργό και το %s. Απενεργοποίησέ το, ώστε οι επισκέπτες να μη βλέπουν δύο διαφορετικές σελίδες.' => 'Maintenance Mode: %s is active too. Deactivate it, so visitors do not see two different pages.',
		'Retry-After χωρίς λήξη (ώρες, 1–72)' => 'Retry-After without an end (hours, 1–72)',
		'true = το αίτημα περνά, ενώ το site είναι κλειστό: ( $allow, $mode ).' => 'true = the request passes while the site is closed: ( $allow, $mode ).',
		'«%1$s» από τις %2$s. Ως τότε το site είναι ανοιχτό.' => '«%1$s» from %2$s. Until then the site is open.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Άγνωστη πηγή.' => 'Unknown source.',
		'Έληξε' => 'Expired',
		'Έναρξη (κενό = αμέσως)' => 'Start (empty = now)',
		'Έως 20 σύνδεσμοι. Ανακάλεσε κάποιον πρώτα.' => 'Up to 20 links. Revoke one first.',
		'Όλα τα άλλα routes μένουν ανοιχτά: πληρωμές, WooCommerce, Site Bridge, Broadcast, εφαρμογές.' => 'Every other route stays open: payments, WooCommerce, Site Bridge, Broadcast, apps.',
		'Όποιος έχει δικαίωμα διαχείρισης του site βλέπει πάντα το site, ώστε να μην κλειδωθείς ποτέ έξω. Οι πελάτες με λογαριασμό βλέπουν τη σελίδα συντήρησης, εκτός αν τσεκάρεις τον ρόλο τους.' => 'Whoever can manage the site always sees it, so you can never lock yourself out. Customers with an account see the maintenance page, unless you tick their role.',
		'Όπως η γλώσσα του λογαριασμού' => 'Same as the account language',
		'Ανάκληση' => 'Revoke',
		'Ανοίγει αυτόματα στις %s.' => 'Opens automatically on %s.',
		'Ανοιχτό' => 'Open',
		'Ανοιχτό: όλοι βλέπουν κανονικά το site' => 'Open: everyone sees the site as usual',
		'Αντίστροφη μέτρηση ως την ώρα λήξης (μόνο όταν υπάρχει λήξη)' => 'Countdown to the end time (only when there is an end)',
		'Αντιγράφει τίτλο, κείμενο, λογότυπο, χρώματα, λειτουργία και (από το WP Maintenance) την ώρα της αντίστροφης μέτρησης. Οι ρυθμίσεις του άλλου plugin δεν αλλάζουν και ο διακόπτης μένει όπως είναι.' => 'Copies title, text, logo, colours, mode and (from WP Maintenance) the countdown time. The other plugin\'s settings do not change and the switch stays as it is.',
		'Αντιγράφηκε' => 'Copied',
		'Αντιγραφή' => 'Copy',
		'Απλοί σύνδεσμοι, χωρίς φόρμα και χωρίς συλλογή email.' => 'Plain links, no form and no email collection.',
		'Αποθήκευση' => 'Save',
		'Αρχείο στο (child) θέμα: αντικαθιστά το πρότυπο της σελίδας, με τα ίδια δεδομένα ($mmd).' => 'A file in the (child) theme: replaces the page template, with the same data ($mmd).',
		'Αυτή η έκδοση του SeedProd φτιάχνει τη σελίδα στο δικό της builder και δεν εισάγεται. Αντέγραψε τα κείμενα με το χέρι στην καρτέλα «Σελίδα».' => 'This SeedProd version builds the page in its own builder and cannot be imported. Copy the texts by hand in the «Page» tab.',
		'Αυτόματα (από τη γλώσσα του site)' => 'Automatic (from the site language)',
		'Αφαίρεση' => 'Remove',
		'Για developers' => 'For developers',
		'Για τον πελάτη ή όποιον θέλεις να δει το site χωρίς λογαριασμό. Ο σύνδεσμος βάζει ένα cookie ως τη λήξη του· η ανάκληση τον σταματά αμέσως.' => 'For your client or anyone who should see the site without an account. The link sets a cookie until it expires; revoking stops it at once.',
		'Γλώσσα' => 'Language',
		'Γλώσσα της σελίδας' => 'Page language',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Δεν βρέθηκαν ρυθμίσεις από SeedProd Coming Soon (4.x–5.x) ή WP Maintenance σε αυτό το site.' => 'No settings from SeedProd Coming Soon (4.x–5.x) or WP Maintenance were found on this site.',
		'Δεν βρέθηκε page cache για καθάρισμα.' => 'No page cache was found to purge.',
		'Δεν βρέθηκε τίποτα για εισαγωγή.' => 'Nothing to import was found.',
		'Δες τη σελίδα όπως ο επισκέπτης' => 'See the page as a visitor',
		'Διακόπτης' => 'Switch',
		'Διευθύνσεις IP' => 'IP addresses',
		'Εικόνα φόντου (προαιρετική, σκουραίνει για να διαβάζεται το κείμενο)' => 'Background image (optional, darkened so the text stays readable)',
		'Εισήχθησαν %d ρυθμίσεις. Έλεγξέ τες και αποθήκευσε.' => '%d settings imported. Check them and save.',
		'Εισαγωγή' => 'Import',
		'Εισαγωγή από %s' => 'Import from %s',
		'Εισαγωγή και developers' => 'Import and developers',
		'Εμφάνιση' => 'Look',
		'Επικοινωνία' => 'Contact',
		'Επιλογή' => 'Choose',
		'Ετικέτα' => 'Label',
		'Ετικέτα (π.χ. «Πελάτης»)' => 'Label (e.g. «Client»)',
		'Ζώνη ώρας του site: %s. Η έναρξη και η λήξη ελέγχονται σε κάθε επίσκεψη, χωρίς cron.' => 'Site time zone: %s. Start and end are checked on every visit, without cron.',
		'Η κατάσταση τώρα: state (off, scheduled, on, ended), active, mode, start, end.' => 'The state now: state (off, scheduled, on, ended), active, mode, start, end.',
		'Η λήξη πρέπει να είναι μετά την έναρξη. Τίποτα δεν άλλαξε.' => 'The end must come after the start. Nothing changed.',
		'Η ώρα λήξης (%s) πέρασε: το site είναι ανοιχτό σε όλους.' => 'The end time (%s) has passed: the site is open to everyone.',
		'Η ώρα λήξης έχει περάσει. Άλλαξέ την ή άφησέ την κενή. Τίποτα δεν άλλαξε.' => 'The end time has passed. Change it or leave it empty. Nothing changed.',
		'Ισχύει για μέρες (1–90)' => 'Valid for days (1–90)',
		'Ισχύει για όλα τα plugins της σουίτας Noxpress.' => 'Applies to every plugin of the Noxpress suite.',
		'Κάποιες γραμμές δεν ήταν έγκυρες διευθύνσεις ή εύρη IP και αγνοήθηκαν.' => 'Some lines were not valid IP addresses or ranges and were ignored.',
		'Καθάρισμα cache τώρα' => 'Purge cache now',
		'Καθαρίστηκε η cache: %s.' => 'Cache purged: %s.',
		'Κατάσταση' => 'Status',
		'Κείμενα' => 'Texts',
		'Κείμενο' => 'Text',
		'Κείμενο (αγγλικά)' => 'Text (English)',
		'Κείμενο (ελληνικά, επιτρέπεται απλό HTML)' => 'Text (Greek, simple HTML allowed)',
		'Κενό πεδίο = το έτοιμο κείμενο της λειτουργίας. Κενό αγγλικό = δείχνει το ελληνικό. Έως 2.000 χαρακτήρες.' => 'Empty field = the ready text of the mode. Empty English = the Greek one is shown. Up to 2,000 characters.',
		'Κλειστά τα routes wp/v2 (άρθρα, σελίδες, χρήστες) για επισκέπτες χωρίς σύνδεση, όσο το site είναι κλειστό' => 'wp/v2 routes (posts, pages, users) closed to visitors who are not logged in, while the site is closed',
		'Κλειστό' => 'Closed',
		'Κλειστό για τους επισκέπτες (από την ώρα έναρξης, αν υπάρχει)' => 'Closed to visitors (from the start time, if there is one)',
		'Κλειστό για τους επισκέπτες: «%s».' => 'Closed to visitors: «%s».',
		'Λήξη' => 'Expires',
		'Λήξη (κενό = ώσπου να το κλείσεις)' => 'End (empty = until you open it)',
		'Λειτουργία' => 'Mode',
		'Λογότυπο (κενό = το εικονίδιο του site)' => 'Logo (empty = the site icon)',
		'Μία ανά γραμμή, ακριβής διεύθυνση ή εύρος (CIDR), έως 50. Η δική σου τώρα: %s' => 'One per line, an exact address or a range (CIDR), up to 50. Yours right now: %s',
		'Με κάθε αποθήκευση, και στην έναρξη και στη λήξη, η page cache του site καθαρίζεται (Fast Pages, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache), ώστε οι επισκέπτες να μη βλέπουν παλιές σελίδες. Όσο είναι κλειστό, καμία σελίδα δεν μπαίνει σε cache. Αν έχεις CDN που κρατά σελίδες (π.χ. Cloudflare με «Cache Everything»), καθάρισέ το κι εκεί.' => 'On every save, and at the start and the end, the site\'s page cache is purged (Fast Pages, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache), so visitors never see old pages. While it is closed, no page goes into a cache. If a CDN keeps pages (e.g. Cloudflare with «Cache Everything»), purge it there too.',
		'Νέος σύνδεσμος' => 'New link',
		'Ο σύνδεσμος ανακλήθηκε.' => 'Link revoked.',
		'Ο σύνδεσμος δεν βρέθηκε.' => 'Link not found.',
		'Ο σύνδεσμος δημιουργήθηκε. Αντέγραψέ τον από τον πίνακα.' => 'Link created. Copy it from the table.',
		'Οι επισκέπτες βλέπουν κανονικά το site.' => 'Visitors see the site as usual.',
		'Οι επισκέπτες βλέπουν τη σελίδα συντήρησης. Μην το ξεχάσεις ανοιχτό.' => 'Visitors see the maintenance page. Do not forget it on.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Προγραμματισμένο' => 'Scheduled',
		'Προεπισκόπηση' => 'Preview',
		'Προσοχή' => 'Note',
		'Πρόγραμμα' => 'Schedule',
		'Πρόσβαση' => 'Access',
		'Ρυθμίσεις' => 'Settings',
		'Ρόλοι που βλέπουν το site' => 'Roles that see the site',
		'Σελίδα' => 'Page',
		'Σελίδα «Σύντομα κοντά σας» ή «Συντήρηση» για τους επισκέπτες. Εσύ, οι ρόλοι που διαλέγεις, οι IP της λίστας και όσοι έχουν σύνδεσμο προεπισκόπησης βλέπουν κανονικά το site. Login, REST API και πληρωμές δεν κόβονται ποτέ.' => 'A «Coming soon» or «Maintenance» page for visitors. You, the roles you choose, the IPs of the list and everyone with a preview link see the site as usual. Login, the REST API and payments are never cut off.',
		'Στο wp-config.php: το site ανοίγει σε όλους, ό,τι κι αν λένε οι ρυθμίσεις.' => 'In wp-config.php: the site opens to everyone, whatever the settings say.',
		'Συντήρηση' => 'Maintenance',
		'Συντήρηση από %s' => 'Maintenance from %s',
		'Συντήρηση ενεργή' => 'Maintenance on',
		'Συντήρηση: για site που ήδη λειτουργεί. Κωδικός 503 με Retry-After, ώστε οι μηχανές αναζήτησης να ξαναπεράσουν αργότερα χωρίς να χαθούν θέσεις.' => 'Maintenance: for a site that is already live. Status 503 with Retry-After, so search engines come back later and no rankings are lost.',
		'Σύνδεσμοι (έως 6, ένας ανά γραμμή: Όνομα | https://…)' => 'Links (up to 6, one per line: Name | https://…)',
		'Σύνδεσμοι προεπισκόπησης' => 'Preview links',
		'Σύνδεσμος' => 'Link',
		'Σύντομα κοντά σας' => 'Coming soon',
		'Σύντομα κοντά σας: για νέο site που δεν έχει βγει. Κωδικός 200 με noindex, robots.txt με «Disallow: /», κλειστά feeds και sitemaps.' => 'Coming soon: for a new site that has not launched. Status 200 with noindex, robots.txt with «Disallow: /», feeds and sitemaps closed.',
		'Σύντομα κοντά σας: ενεργό' => 'Coming soon: on',
		'Τίτλος (αγγλικά)' => 'Title (English)',
		'Τίτλος (ελληνικά)' => 'Title (Greek)',
		'Τίτλος / κείμενο ( $t, $mode, $lang ) και το τελικό HTML ( $html, $mmd ) της σελίδας.' => 'Title / text ( $t, $mode, $lang ) and the final HTML ( $html, $mmd ) of the page.',
		'Τηλέφωνο' => 'Phone',
		'Τι βλέπει το τρέχον αίτημα: \'\' (το site), coming_soon ή maintenance. Το διαβάζει το Search Signals για sitemaps και llms.txt.' => 'What the current request meets: \'\' (the site), coming_soon or maintenance. Search Signals reads it for sitemaps and llms.txt.',
		'Το MMD_DISABLE είναι ενεργό στο wp-config.php: το site είναι ανοιχτό σε όλους, ό,τι κι αν λένε οι ρυθμίσεις.' => 'MMD_DISABLE is set in wp-config.php: the site is open to everyone, whatever the settings say.',
		'Το site είναι ανοιχτό στους επισκέπτες' => 'The site is open to visitors',
		'Το site είναι κλειστό στους επισκέπτες (Maintenance Mode)' => 'The site is closed to visitors (Maintenance Mode)',
		'Τόνος (γραμμή, links)' => 'Accent (line, links)',
		'Υπάρχει αρχείο robots.txt στη ρίζα του site: ο server το δίνει χωρίς το WordPress, οπότε το «Disallow: /» δεν μπαίνει. Η σελίδα έχει πάντως noindex.' => 'There is a robots.txt file in the site root: the server sends it without WordPress, so «Disallow: /» is not added. The page has noindex anyway.',
		'Φόντο' => 'Background',
		'♡ Στήριξε το Noxpress' => '♡ Support Noxpress',
	);

	public static function init(): void {
		add_filter( 'gettext_maintenance-mode', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_maintenance-mode', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** Revenue Splitter is active (then only RS shows the language switch). */
	public static function rs_active(): bool {
		return class_exists( 'RS_Lang' ) || defined( 'RS_VERSION' );
	}

	/** Forgets the cached choice (after the language is changed). */
	public static function reset(): void {
		self::$lang = null;
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

	/** gettext_maintenance-mode filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'maintenance-mode' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_maintenance-mode filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'maintenance-mode' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
