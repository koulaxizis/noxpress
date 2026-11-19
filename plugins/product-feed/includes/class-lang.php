<?php
/**
 * PFD_Lang — domain dictionary + gettext filter (pattern PL_Lang).
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

final class PFD_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'%1$s · %2$s εγγραφές από %3$s προϊόντα' => '%1$s · %2$s items from %3$s products',
		'%d ώρα' => '%d hour',
		'%d ώρες' => '%d hours',
		'%s (από CTX Feed)' => '%s (from CTX Feed)',
		'BestPrice: τα ονόματα των πεδίων ακολουθούν τη δημοσιευμένη δομή του BestPrice. Σύγκρινέ τα με τον οδηγό XML στο merchant panel σου πριν δηλώσεις το feed.' => 'BestPrice: the field names follow BestPrice\'s published structure. Compare them with the XML guide in your merchant panel before you submit the feed.',
		'Brand / κατασκευαστής' => 'Brand / manufacturer',
		'Downloadable ή virtual' => 'Downloadable or virtual',
		'Feed' => 'Feed',
		'Feeds' => 'Feeds',
		'Feeds προϊόντων για Google Merchant Center, Skroutz, BestPrice, κατάλογο Meta και CSV. Κάθε feed είναι ένα στατικό αρχείο με σταθερό URL που ανανεώνεται στο παρασκήνιο.' => 'Product feeds for Google Merchant Center, Skroutz, BestPrice, the Meta catalog and CSV. Each feed is a static file with a fixed URL, refreshed in the background.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'Meta key για GTIN, όταν λείπει το πεδίο GTIN του WooCommerce (προαιρετικό)' => 'Meta key for the GTIN when WooCommerce\'s GTIN field is empty (optional)',
		'More plugins at' => 'More plugins at',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Product Feed' => 'Product Feed',
		'URL' => 'URL',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Άλλο feed έχει ήδη αυτό το όνομα αρχείου.' => 'Another feed already uses this file name.',
		'Άλλο feed γράφει ήδη σε αυτή τη διαδρομή.' => 'Another feed already writes to this path.',
		'Έλεγχος' => 'Check',
		'Έμειναν έξω' => 'Left out',
		'Ένα προϊόν (ID)' => 'One product (ID)',
		'Έξω όσα έχουν σοβαρό σφάλμα (χωρίς τιμή, εικόνα ή τίτλο), για να μην απορριφθούν' => 'Leave out products with a serious error (no price, image or title), so they are not rejected',
		'Έφτασες το όριο των 20 feeds.' => 'You have reached the limit of 20 feeds.',
		'Όλα τα URLs των feeds θα αλλάξουν και θα πρέπει να τα δηλώσεις ξανά σε κάθε υπηρεσία. Συνέχεια;' => 'Every feed URL will change and you will have to submit them again to every service. Continue?',
		'Όνομα' => 'Name',
		'Όνομα αρχείου (λατινικά, αριθμοί, παύλα)' => 'File name (latin letters, digits, hyphen)',
		'Όταν απενεργοποιήσεις το CTX Feed, βάλε «%s» στο «Και σε αυτή τη διαδρομή» του feed, ώστε η υπηρεσία να διαβάζει το νέο feed από το ίδιο URL.' => 'Once you switch CTX Feed off, put «%s» in the feed\'s «Also write to this path», so the service reads the new feed from the same URL.',
		'Ακύρωση' => 'Cancel',
		'Αλλαγή' => 'Edit',
		'Αλλαγή feed' => 'Edit feed',
		'Ανά κατηγορία του καταστήματος: «slug ή id = αριθμός κατηγορίας Google», μία ανά γραμμή' => 'Per shop category: «slug or id = Google category number», one per line',
		'Ανανέωση κάθε' => 'Refresh every',
		'Ανανέωση και 10 λεπτά μετά από αλλαγή τιμής, αποθέματος ή προϊόντος' => 'Also refresh 10 minutes after a change of price, stock or a product',
		'Ανανέωση τώρα' => 'Refresh now',
		'Αντίγραφο ρυθμίσεων' => 'Settings backup',
		'Αντιγράφηκε' => 'Copied',
		'Αντιγραφή' => 'Copy',
		'Αποθήκευση' => 'Save',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Βρέθηκαν %d feeds του CTX Feed:' => '%d CTX Feed feeds found:',
		'Βρέθηκε %d feed του CTX Feed:' => '%d CTX Feed feed found:',
		'Για προπαραγγελίες και backorder' => 'For pre-orders and back-orders',
		'Για προϊόντα με απόθεμα' => 'For products in stock',
		'Δεν έχει φτιαχτεί ακόμα' => 'Not built yet',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You are not allowed to access this page.',
		'Δεν βρέθηκαν feeds του CTX Feed σε αυτό το site.' => 'No CTX Feed feeds were found on this site.',
		'Δεν είναι αντίγραφο ρυθμίσεων του Product Feed: τίποτα δεν άλλαξε.' => 'This is not a Product Feed settings backup: nothing changed.',
		'Δεν είναι δημόσιο (πρόχειρο, κωδικός ή κρυφό από τον κατάλογο)' => 'Not public (draft, password or hidden from the catalog)',
		'Δεν υπάρχει ακόμα κανένα feed.' => 'There is no feed yet.',
		'Δεν υπάρχει ακόμα κανένα feed. Φτιάξε ένα παρακάτω ή εισήγαγε τα feeds του CTX Feed από τις Ρυθμίσεις.' => 'There is no feed yet. Make one below or import the CTX Feed feeds from Settings.',
		'Διαγραφή' => 'Delete',
		'Διαγραφή των αρχείων των feeds όταν διαγραφεί το plugin (η δεύτερη διαδρομή δεν σβήνεται ποτέ)' => 'Delete the feed files when the plugin is deleted (the second path is never deleted)',
		'Διακοπή' => 'Stop',
		'Εγγραφές' => 'Items',
		'Εικόνα χωρίς https' => 'Image without https',
		'Εισήχθη %d feed.' => '%d feed imported.',
		'Εισήχθη %d feed. Πάτα «Ανανέωση τώρα» για να το φτιάξεις.' => '%d feed imported. Press «Refresh now» to build it.',
		'Εισήχθησαν %d feeds.' => '%d feeds imported.',
		'Εισήχθησαν %d feeds. Πάτα «Ανανέωση τώρα» για να τα φτιάξεις.' => '%d feeds imported. Press «Refresh now» to build them.',
		'Εισαγωγή' => 'Import',
		'Εισαγωγή JSON' => 'Import JSON',
		'Εισαγωγή από το CTX Feed' => 'Import from CTX Feed',
		'Εκτός από αυτές τις κατηγορίες' => 'Except these categories',
		'Εκτός από προϊόντα με αυτές τις ετικέτες (ονόματα ή ids, με κόμμα)' => 'Except products with these tags (names or ids, comma-separated)',
		'Ενεργό (ανανεώνεται μόνο του)' => 'Active (refreshes on its own)',
		'Εξαιρέσεις ανά κατηγορία: μία ανά γραμμή, «slug ή id = αριθμός επιλογής» (1 = πρώτη επιλογή παραπάνω)' => 'Exceptions per category: one per line, «slug or id = choice number» (1 = first choice above)',
		'Ετικέτα εκτός feed' => 'Tag left out of the feed',
		'Η ανανέωση δεν ξεκίνησε (το feed λείπει, το PFD_DISABLE είναι ενεργό ή ο φάκελος δεν γράφεται).' => 'The refresh did not start (the feed is missing, PFD_DISABLE is on or the folder cannot be written).',
		'Η ανανέωση ξεκίνησε στο παρασκήνιο. Η σελίδα δείχνει την πρόοδο όταν την ανανεώσεις.' => 'The refresh started in the background. Reload the page to see its progress.',
		'Η ανανέωση σταμάτησε. Το προηγούμενο αρχείο έμεινε.' => 'The refresh stopped. The previous file stays.',
		'Η δεύτερη διαδρομή δεν είναι δεκτή (μόνο μέσα στο uploads, χωρίς «..», με την κατάληξη του feed) και έμεινε κενή.' => 'The second path was not accepted (only inside uploads, without «..», with the feed\'s extension) and was left empty.',
		'Και σε αυτή τη διαδρομή, μέσα στο uploads (π.χ. το παλιό αρχείο άλλου plugin· κενό = όχι)' => 'Also write to this path, inside uploads (e.g. the old file of another plugin; empty = no)',
		'Και:' => 'Also:',
		'Κανάλι' => 'Channel',
		'Κατάσταση' => 'Status',
		'Κατηγορία Google' => 'Google category',
		'Κατηγορία Google για όλα (αριθμός, π.χ. 784 = Βιβλία· κενό = καμία)' => 'Google category for everything (number, e.g. 784 = Books; empty = none)',
		'Κατηγορία εκτός feed' => 'Category left out of the feed',
		'Κρυφό από το Hide Catalog' => 'Hidden by Hide Catalog',
		'Λάθος ψηφίο ελέγχου στο GTIN / ISBN' => 'Wrong check digit in the GTIN / ISBN',
		'Λήψη JSON' => 'Download JSON',
		'Μάρκα του WooCommerce (π.χ. εκδότης)' => 'WooCommerce brand (e.g. publisher)',
		'Μαζί με downloadable και virtual προϊόντα' => 'Include downloadable and virtual products',
		'Μαζί με τίτλους από το Book Feed (μπαίνουν και όταν το Hide Catalog τους κρύβει)' => 'Include titles from Book Feed (also when Hide Catalog hides them)',
		'Μαζί με όσα κρύβει το Hide Catalog από το κατάστημα' => 'Include what Hide Catalog hides from the shop',
		'Μόνο από αυτές τις κατηγορίες (καμία = όλες)' => 'Only from these categories (none = all)',
		'Μόνο όσα έχουν απόθεμα (ή είναι σε προπαραγγελία / backorder)' => 'Only products in stock (or on pre-order / back-order)',
		'Νέο feed' => 'New feed',
		'Νέο τυχαίο όνομα φακέλου' => 'New random folder name',
		'Να διαγραφεί αυτό το feed και το αρχείο του; Όποια υπηρεσία το διαβάζει θα βρει 404.' => 'Delete this feed and its file? Any service that reads it will get a 404.',
		'Ο φάκελος άλλαξε. Ανανέωσε τα feeds και δήλωσε τα νέα URLs.' => 'The folder changed. Refresh the feeds and submit the new URLs.',
		'Ο φάκελος των feeds δεν δημιουργήθηκε (δικαιώματα στο uploads).' => 'The feeds folder could not be created (permissions in uploads).',
		'Οι ανανεώσεις τρέχουν στο παρασκήνιο με το Action Scheduler του WooCommerce, σε παρτίδες των 100 προϊόντων. Το παλιό αρχείο μένει ώσπου να ετοιμαστεί ολόκληρο το νέο.' => 'Refreshes run in the background with WooCommerce\'s Action Scheduler, in batches of 100 products. The old file stays until the whole new one is ready.',
		'Οι αριθμοί είναι στη λίστα κατηγοριών προϊόντων της Google (taxonomy-with-ids). Η διαδρομή κατηγορίας του καταστήματος πηγαίνει πάντα στο product_type.' => 'The numbers are in Google\'s product category list (taxonomy-with-ids). The shop\'s category path always goes to product_type.',
		'Οι παραλλαγές ως χωριστές εγγραφές (Google, Meta, CSV) ή ως μεγέθη του προϊόντος (Skroutz, BestPrice)' => 'Variations as separate items (Google, Meta, CSV) or as sizes of the product (Skroutz, BestPrice)',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Ομαδοποιημένο ή εξωτερικό προϊόν' => 'Grouped or external product',
		'Πεδία' => 'Fields',
		'Περιγραφή' => 'Description',
		'Πλήρης (αλλιώς σύντομη)' => 'Full (otherwise short)',
		'Ποια προϊόντα μπαίνουν' => 'Which products go in',
		'Ποσότητα για προϊόντα χωρίς μέτρηση αποθέματος' => 'Quantity for products without stock management',
		'Προβολή' => 'Show',
		'Προειδοποιήσεις' => 'Warnings',
		'Προειδοποιήσεις (μπαίνουν, αλλά καλό είναι να διορθωθούν)' => 'Warnings (they go in, but are worth fixing)',
		'Προεπισκόπηση του προϊόντος' => 'Preview of the product',
		'Προεπισκόπηση των πρώτων 20 εγγραφών' => 'Preview of the first 20 items',
		'Προϊόν' => 'Product',
		'Προϊόντα με σφάλμα ή προειδοποίηση (έως 300)' => 'Products with an error or a warning (up to 300)',
		'Ρυθμίσεις' => 'Settings',
		'Σοβαρό σφάλμα (βλ. παρακάτω)' => 'Serious error (see below)',
		'Σταθερό brand (και όταν λείπει η μάρκα)' => 'Fixed brand (also when the brand is missing)',
		'Σταθερό κείμενο' => 'Fixed text',
		'Σταμάτησε από σένα.' => 'Stopped by you.',
		'Συνέχεια' => 'Continue',
		'Σφάλμα: %s' => 'Error: %s',
		'Σφάλματα' => 'Errors',
		'Σύνδεσμοι και διαδρομή' => 'Links and path',
		'Σύντομη (αλλιώς πλήρης)' => 'Short (otherwise full)',
		'Τίτλος από το Book Feed' => 'Title from Book Feed',
		'Τίτλος πάνω από 150 χαρακτήρες (κόβεται)' => 'Title over 150 characters (cut)',
		'Τα feeds αυτού του site θα αντικατασταθούν από το αρχείο. Συνέχεια;' => 'The feeds of this site will be replaced by the file. Continue?',
		'Τα feeds σου' => 'Your feeds',
		'Τελευταία ανανέωση' => 'Last refresh',
		'Το CTX Feed είναι ακόμα ενεργό και ξαναγράφει αυτό το αρχείο κάθε ώρα.' => 'CTX Feed is still active and rewrites this file every hour.',
		'Το EAN δεν έχει 13 ψηφία' => 'The EAN does not have 13 digits',
		'Το Google δεν δέχεται ψηφιακά βιβλία στις διαφημίσεις Shopping: τα downloadable και τα virtual προϊόντα μένουν έξω, εκτός αν το αλλάξεις παρακάτω.' => 'Google does not accept digital books in Shopping ads: downloadable and virtual products stay out unless you change it below.',
		'Το PFD_DISABLE είναι ενεργό στο wp-config.php: κανένα feed δεν ανανεώνεται. Τα αρχεία που υπάρχουν μένουν όπως είναι.' => 'PFD_DISABLE is on in wp-config.php: no feed is refreshed. The existing files stay as they are.',
		'Το Product Feed χρειάζεται το WooCommerce: ενεργοποίησέ το για να φτιάξεις feeds προϊόντων.' => 'Product Feed needs WooCommerce: activate it to make product feeds.',
		'Το Skroutz δεν έχει τιμή «εξαντλημένο»: προϊόντα χωρίς απόθεμα δεν μπαίνουν ποτέ.' => 'Skroutz has no «out of stock» value: products without stock never go in.',
		'Το feed αποθηκεύτηκε. Πάτα «Ανανέωση τώρα» για να δεις το αποτέλεσμα.' => 'Feed saved. Press «Refresh now» to see the result.',
		'Το feed γράφτηκε, αλλά όχι και στη δεύτερη διαδρομή.' => 'The feed was written, but not to the second path.',
		'Το feed δεν έχει φτιαχτεί ακόμα. Πάτα «Ανανέωση τώρα» στη λίστα των feeds.' => 'The feed has not been built yet. Press «Refresh now» in the list of feeds.',
		'Το feed δεν βρέθηκε.' => 'Feed not found.',
		'Το feed διαγράφηκε.' => 'Feed deleted.',
		'Το αρχείο έχει τα feeds και τις ρυθμίσεις τους, όχι το όνομα του φακέλου.' => 'The file holds the feeds and their settings, not the folder name.',
		'Το αρχείο δεν ανέβηκε ή είναι πολύ μεγάλο.' => 'The file was not uploaded or is too large.',
		'Το αρχείο δεν γράφτηκε (δικαιώματα ή χώρος στον δίσκο).' => 'The file could not be written (permissions or disk space).',
		'Το νέο αρχείο δεν αντικατέστησε το παλιό.' => 'The new file did not replace the old one.',
		'Το τυχαίο όνομα του φακέλου δυσκολεύει όποιον θέλει να διαβάσει όλο τον κατάλογο. Τα δεδομένα είναι ούτως ή άλλως δημόσια (τιμές και σελίδες του καταστήματος).' => 'The random folder name makes it harder to read the whole catalog. The data are public anyway (the shop\'s prices and pages).',
		'Φάκελος και διαγραφή' => 'Folder and deletion',
		'Φάκελος των feeds:' => 'Feeds folder:',
		'Φτιάχνει ένα feed για το καθένα, με τα ίδια φίλτρα, τις ετικέτες που εξαιρούνται και τα UTM. Το CTX Feed δεν αλλάζει και συνεχίζει να δουλεύει. Το νέο feed παίρνει δικό του URL, για να συγκρίνεις πρώτα.' => 'Makes one feed for each, with the same filters, excluded tags and UTM values. CTX Feed is not changed and keeps working. The new feed gets its own URL, so you can compare first.',
		'Φτιάχνεται τώρα, με τις τρέχουσες ρυθμίσεις, χωρίς να γραφτεί αρχείο.' => 'Built now with the current settings, without writing a file.',
		'Χάθηκε το προσωρινό αρχείο: ξεκίνα ξανά.' => 'The temporary file was lost: start again.',
		'Χαρακτηριστικό' => 'Attribute',
		'Χαρακτηριστικό για το brand' => 'Attribute for the brand',
		'Χαρακτηριστικό μεγέθους' => 'Size attribute',
		'Χαρακτηριστικό χρώματος' => 'Colour attribute',
		'Χρήσιμο όταν μια υπηρεσία διαβάζει ήδη ένα παλιό URL: το Merchant Center δεν χρειάζεται αλλαγή. Βάλ\' το μόνο αφού απενεργοποιήσεις το άλλο plugin, αλλιώς θα γράφουν και τα δύο το ίδιο αρχείο.' => 'Useful when a service already reads an old URL: Merchant Center needs no change. Set it only after you switch the other plugin off, otherwise both will write the same file.',
		'Χρόνος αποστολής' => 'Shipping time',
		'Χωρίς GTIN / ISBN' => 'No GTIN / ISBN',
		'Χωρίς MPN / ISBN (το Skroutz δεν κατατάσσει βιβλία χωρίς ISBN)' => 'No MPN / ISBN (Skroutz does not classify books without an ISBN)',
		'Χωρίς brand / εκδότη' => 'No brand / publisher',
		'Χωρίς brand, GTIN ή SKU (η Meta θέλει ένα από αυτά)' => 'No brand, GTIN or SKU (Meta needs one of them)',
		'Χωρίς απόθεμα' => 'Out of stock',
		'Χωρίς εικόνα' => 'No image',
		'Χωρίς κατηγορία' => 'No category',
		'Χωρίς περιγραφή' => 'No description',
		'Χωρίς τίτλο' => 'No title',
		'Χωρίς τιμή' => 'No price',
		'έτοιμο' => 'ready',
		'ανενεργό' => 'inactive',
		'σφάλμα' => 'error',
		'φτιάχνεται (%d προϊόντα)' => 'building (%d products)',
		'♡ Στήριξε το Noxpress' => '♡ Support Noxpress',
	);

	public static function init(): void {
		add_filter( 'gettext_product-feed', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_product-feed', array( __CLASS__, 'filter_n' ), 10, 5 );
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

	/** gettext_product-feed filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'product-feed' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_product-feed filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'product-feed' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
