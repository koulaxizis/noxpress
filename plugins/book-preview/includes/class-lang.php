<?php
/**
 * BPV_Lang — domain dictionary + gettext filter (pattern PFM_Lang).
 *
 * Admin: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Front end: the visitor texts are settings with EL/EN values
 * (BPV_Settings::text(), Bible §14.17); this dictionary only covers the
 * few gettext strings that can reach the front end (shortcode errors are
 * never shown there), with the site language.
 *
 * The block's title and description in block.json are translated by WP
 * with gettext contexts ("block title", "block description"); the
 * gettext_with_context filter covers them.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Book Preview χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Book Preview requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Γράψε ποιες σελίδες θα έχει το δείγμα, π.χ. 1-12, 20, 35-40.' => 'Enter the pages the sample will have, e.g. 1-12, 20, 35-40.',
		'Οι σελίδες αρχίζουν από το 1.'                             => 'Pages start at 1.',
		'Σε ένα εύρος η πρώτη σελίδα πρέπει να είναι μικρότερη από την τελευταία (π.χ. 35-40).' => 'In a range the first page must be lower than the last one (e.g. 35-40).',
		'Το PDF δεν έχει σελίδα %d.'                                => 'The PDF has no page %d.',
		'Δεν καταλαβαίνω το πεδίο σελίδων. Γράψε σελίδες και εύρη με κόμμα, π.χ. 1-12, 20, 35-40.' => 'The page field is not clear. Enter pages and ranges separated by commas, e.g. 1-12, 20, 35-40.',
		'Δείγμα βιβλίου'                                            => 'Book sample',
		'ID προϊόντος (κενό = το τρέχον προϊόν)'                    => 'Product ID (empty = the current product)',
		'Κείμενο κουμπιού (κενό = από τις ρυθμίσεις)'               => 'Button text (empty = from the settings)',
		'Δεν έχει επιλεγεί PDF για το δείγμα.'                      => 'No PDF has been chosen for the sample.',
		'Το PDF βρίσκεται σε άλλον server. Το plugin διαβάζει μόνο αρχεία αυτού του site (χωρίς εξωτερικές κλήσεις).' => 'The PDF is on another server. The plugin reads only files of this site (no external calls).',
		'Το αρχείο PDF δεν βρέθηκε στον server.'                    => 'The PDF file was not found on the server.',
		'Το αρχείο δεν είναι PDF.'                                  => 'The file is not a PDF.',
		'Ο φάκελος uploads/noxpress-previews δεν μπορεί να γραφτεί. Έλεγξε τα δικαιώματα του φακέλου uploads.' => 'The uploads/noxpress-previews folder is not writable. Check the permissions of the uploads folder.',
		'Το PDF είναι κρυπτογραφημένο. Αν έχει μόνο κωδικό δικαιωμάτων, το qpdf στον server μπορεί να το ανοίξει· αλλιώς ανέβασε μια εκδοχή χωρίς κρυπτογράφηση.' => 'The PDF is encrypted. If it has only a permissions password, qpdf on the server can open it; otherwise upload a version without encryption.',
		'Το PDF χρησιμοποιεί συμπίεση που η μηχανή δεν διαβάζει. Με qpdf ή Ghostscript στον server θα δοκιμαστούν αυτόματα.' => 'The PDF uses compression the engine cannot read. With qpdf or Ghostscript on the server they will be tried automatically.',
		'Το PDF δεν έχει σελίδες που να διαβάζονται.'               => 'The PDF has no readable pages.',
		'Το αρχείο του δείγματος δεν γράφτηκε (χώρος ή δικαιώματα στον δίσκο).' => 'The sample file was not written (disk space or permissions).',
		'Το PDF είναι κατεστραμμένο ή δεν διαβάζεται. Δοκίμασε να το εξαγάγεις ξανά από το πρόγραμμα σελιδοποίησης.' => 'The PDF is damaged or cannot be read. Try exporting it again from the layout application.',
		'PDF του βιβλίου'                                           => 'PDF of the book',
		'Από τη βιβλιοθήκη πολυμέσων…'                              => 'From the media library…',
		'Νέα αρχεία στα «Αρχεία για λήψη» εμφανίζονται εδώ μετά την ενημέρωση του προϊόντος.' => 'New files in "Downloadable files" show here after the product is updated.',
		'Σελίδες του δείγματος'                                     => 'Sample pages',
		'Π.χ. 1-12, 20, 35-40. Κενό = οι πρώτες %d σελίδες.'        => 'E.g. 1-12, 20, 35-40. Empty = the first %d pages.',
		'Απόκρυψη του κουμπιού (το δείγμα μένει)'                   => 'Hide the button (the sample stays)',
		'Δημιουργία δείγματος'                                      => 'Create sample',
		'Διαγραφή'                                                  => 'Delete',
		'— Χωρίς δείγμα —'                                          => '— No sample —',
		'Αρχεία για λήψη'                                           => 'Downloadable files',
		'(παραλλαγή #%d)'                                           => '(variation #%d)',
		'όχι τοπικό αρχείο'                                         => 'not a local file',
		'Βιβλιοθήκη πολυμέσων'                                      => 'Media library',
		'(το αρχείο δεν υπάρχει πια)'                               => '(the file no longer exists)',
		'%1$d από %2$d σελίδες (%3$s) · %4$s'                       => '%1$d of %2$d pages (%3$s) · %4$s',
		'Έτοιμο'                                                    => 'Ready',
		'Χρειάζεται αναδημιουργία'                                  => 'Needs rebuilding',
		'Άνοιγμα'                                                   => 'Open',
		'Δημιουργήθηκε %s'                                          => 'Created %s',
		'με %s'                                                     => 'with %s',
		'Άλλαξε το PDF ή οι σελίδες. Το δείγμα ξαναφτιάχνεται στο παρασκήνιο μετά την ενημέρωση του προϊόντος· μέχρι τότε φαίνεται το προηγούμενο.' => 'The PDF or the pages changed. The sample is rebuilt in the background after the product is updated; until then the previous one is shown.',
		'Άλλαξε το PDF ή οι σελίδες. Πάτα «Δημιουργία δείγματος»· μέχρι τότε φαίνεται το προηγούμενο.' => 'The PDF or the pages changed. Press "Create sample"; until then the previous one is shown.',
		'Το δείγμα περιέχει όλες τις σελίδες του βιβλίου.'          => 'The sample contains every page of the book.',
		'Το δείγμα είναι μεγάλο και θα αργεί να ανοίξει σε κινητά. Χρησιμοποίησε ως πηγή την εκδοχή ebook (μικρότερες εικόνες) αντί για το PDF του τυπογραφείου.' => 'The sample is large and will be slow to open on phones. Use the ebook version (smaller images) as the source instead of the print PDF.',
		'Δεν υπάρχει δείγμα ακόμα.'                                 => 'There is no sample yet.',
		'Το πλήρες PDF έχει δημόσια διεύθυνση (βιβλιοθήκη πολυμέσων ή φάκελος εκτός woocommerce_uploads): όποιος τη βρει μπορεί να το κατεβάσει. Το plugin δεν τη δείχνει ποτέ, αλλά πιο ασφαλές είναι να μπει ως «Αρχείο για λήψη» του προϊόντος.' => 'The full PDF has a public address (media library or a folder outside woocommerce_uploads): anyone who finds it can download it. The plugin never shows it, but it is safer to add it as a "Downloadable file" of the product.',
		'Ο server είναι nginx: το .htaccess του woocommerce_uploads δεν ισχύει εκεί. Βεβαιώσου ότι ο φάκελος είναι κλειστός στη ρύθμιση του server.' => 'The server is nginx: the .htaccess of woocommerce_uploads does not apply there. Make sure the folder is closed in the server configuration.',
		'Το κουμπί είναι κρυφό σε αυτό το προϊόν.'                  => 'The button is hidden on this product.',
		'Το κουμπί δεν φαίνεται ακόμα στο κατάστημα: ενεργοποίησέ το στις ρυθμίσεις του Book Preview.' => 'The button is not visible in the shop yet: turn it on in the Book Preview settings.',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Το δείγμα δημιουργήθηκε.'                                  => 'Sample created.',
		'Το δείγμα διαγράφηκε.'                                     => 'Sample deleted.',
		'Book Preview'                                              => 'Book Preview',
		'Δείγματα'                                                  => 'Samples',
		'Book Preview — Ρυθμίσεις'                                  => 'Book Preview — Settings',
		'BP Ρυθμίσεις'                                              => 'BP Settings',
		'Επιλογή PDF'                                               => 'Choose a PDF',
		'Χρήση αυτού του PDF'                                       => 'Use this PDF',
		'Να διαγραφεί το αρχείο του δείγματος;'                     => 'Delete the sample file?',
		'Δημιουργία…'                                               => 'Creating…',
		'Το αίτημα απέτυχε. Δοκίμασε ξανά.'                         => 'The request failed. Try again.',
		'%1$d από %2$d προϊόντα'                                    => '%1$d of %2$d products',
		'Η μαζική δημιουργία σταμάτησε. Ό,τι έγινε έχει αποθηκευτεί· πάτα ξανά για να συνεχίσει.' => 'The bulk creation stopped. What was done is saved; press again to continue.',
		'Κανένα προϊόν δεν ταιριάζει σε αυτή την επιλογή.'          => 'No product matches this choice.',
		'Χωρίς δείγμα'                                              => 'No sample',
		'Σφάλμα'                                                    => 'Error',
		'Κάτω από τη σύντομη περιγραφή'                             => 'Below the short description',
		'Κάτω από το κουμπί καλαθιού'                               => 'Below the add to cart button',
		'Μόνο με το shortcode [book_preview] ή το block'            => 'Only with the [book_preview] shortcode or the block',
		'Μόνο όσα δεν έχουν δείγμα'                                 => 'Only those without a sample',
		'Όσα δεν έχουν δείγμα και όσα χρειάζονται αναδημιουργία'    => 'Those without a sample and those that need rebuilding',
		'Όλα (αναδημιουργία και των έτοιμων)'                       => 'All (rebuild the ready ones too)',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Για κάθε βιβλίο φτιάχνεται στον server ξεχωριστό PDF μόνο με τις σελίδες του δείγματος. Ο επισκέπτης το διαβάζει μέσα στη σελίδα του προϊόντος· το πλήρες PDF δεν φτάνει ποτέ στον browser.' => 'For each book the server creates a separate PDF with only the sample pages. Visitors read it inside the product page; the full PDF never reaches the browser.',
		'Κατάσταση'                                                 => 'Status',
		'Book Preview:'                                             => 'Book Preview:',
		'Απενεργοποιημένο από το BPV_DISABLE (wp-config.php)'       => 'Disabled by BPV_DISABLE (wp-config.php)',
		'Ανενεργό στο front end'                                    => 'Off on the front end',
		'Λειτουργία δοκιμής — το κουμπί φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode — the button is visible to shop managers only',
		'Ενεργό για όλους τους επισκέπτες'                          => 'Active for all visitors',
		'Αλλαγή'                                                    => 'Change',
		'Προϊόντα με PDF:'                                          => 'Products with a PDF:',
		'Μαζική δημιουργία'                                         => 'Bulk creation',
		'Φτιάχνει δείγματα για τα προϊόντα μιας κατηγορίας που έχουν PDF. Όπου δεν έχει οριστεί PDF ή σελίδες, παίρνει το πρώτο PDF των αρχείων για λήψη και τις πρώτες σελίδες με τις τιμές από κάτω. Βιβλία με λιγότερες σελίδες παίρνουν όσες έχουν.' => 'Creates samples for the products of a category that have a PDF. Where no PDF or pages are set, it takes the first PDF of the downloadable files and the first pages with the values below. Books with fewer pages get as many as they have.',
		'Κατηγορία'                                                 => 'Category',
		'Μαζί με τις υποκατηγορίες'                                 => 'Include subcategories',
		'Πρώτες σελίδες'                                            => 'First pages',
		'Παράλειψη των πρώτων σελίδων (εξώφυλλο, λευκές, copyright)' => 'Skip the first pages (cover, blank, copyright)',
		'Ποια προϊόντα'                                             => 'Which products',
		'Δημιουργία δειγμάτων'                                      => 'Create samples',
		'Προϊόντα'                                                  => 'Products',
		'Όλες οι καταστάσεις'                                       => 'All states',
		'Φίλτρο'                                                    => 'Filter',
		'Προϊόν'                                                    => 'Product',
		'PDF'                                                       => 'PDF',
		'Σελίδες'                                                   => 'Pages',
		'Μέγεθος'                                                   => 'Size',
		'Κανένα προϊόν με PDF.'                                     => 'No product with a PDF.',
		'πρώτο'                                                     => 'first',
		'δημόσιο'                                                   => 'public',
		'%1$d από %2$d (%3$s)'                                      => '%1$d of %2$d (%3$s)',
		'Όλες οι κατηγορίες'                                        => 'All categories',
		'Κουμπί'                                                    => 'Button',
		'Τίτλος της κάρτας'                                         => 'Card title',
		'Κουμπί αγοράς'                                             => 'Buy button',
		'Λειτουργία'                                                => 'Mode',
		'Ενεργοποίηση στο front end'                                => 'Turn on on the front end',
		'Λειτουργία δοκιμής: το κουμπί φαίνεται μόνο σε όσους διαχειρίζονται το κατάστημα' => 'Test mode: the button is visible only to those who manage the shop',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Το BPV_DISABLE είναι ενεργό: τίποτα δεν εμφανίζεται στο front end.' => 'BPV_DISABLE is on: nothing is shown on the front end.',
		'Κουμπί δείγματος'                                          => 'Sample button',
		'Θέση στη σελίδα προϊόντος'                                 => 'Position on the product page',
		'Το shortcode [book_preview] δουλεύει παντού ([book_preview id="123"] για άλλο προϊόν, label="…" για άλλο κείμενο). Στο block editor υπάρχει και το block «Δείγμα βιβλίου».' => 'The [book_preview] shortcode works anywhere ([book_preview id="123"] for another product, label="…" for another text). The block editor also has the "Book sample" block.',
		'Γλώσσα των κειμένων'                                       => 'Language of the texts',
		'Αυτόματα (γλώσσα του site)'                                => 'Automatic (site language)',
		'Κάρτα «Συνεχίστε την ανάγνωση» μετά την τελευταία σελίδα, με κουμπί αγοράς' => '"Keep reading" card after the last page, with a buy button',
		'%s (ελληνικά)'                                             => '%s (Greek)',
		'%s (αγγλικά)'                                              => '%s (English)',
		'Κενό πεδίο = το προεπιλεγμένο κείμενο. Κενό αγγλικό κείμενο δείχνει το ελληνικό.' => 'Empty field = the default text. An empty English text shows the Greek one.',
		'Σελίδες όταν το πεδίο του προϊόντος είναι κενό'            => 'Pages when the product field is empty',
		'Παράλειψη στη μαζική δημιουργία (προεπιλογή)'              => 'Skip in bulk creation (default)',
		'Προειδοποίηση για δείγματα πάνω από (MB)'                  => 'Warn for samples over (MB)',
		'Αυτόματη αναδημιουργία στο παρασκήνιο όταν αλλάξει το PDF ή οι σελίδες ενός προϊόντος' => 'Rebuild automatically in the background when the PDF or the pages of a product change',
		'Χρήση qpdf ή Ghostscript όταν η μηχανή του plugin δεν διαβάζει ένα PDF (μόνο αν υπάρχουν στον server)' => 'Use qpdf or Ghostscript when the plugin engine cannot read a PDF (only if they exist on the server)',
		'Αποθήκευση ρυθμίσεων'                                      => 'Save settings',
		'Έλεγχος server'                                            => 'Server check',
		'Backup & Επαναφορά'                                        => 'Backup & Restore',
		'Εισαγωγή (JSON)'                                           => 'Import (JSON)',
		'Εξαγωγή (JSON)'                                            => 'Export (JSON)',
		'Περιλαμβάνονται οι ρυθμίσεις. Τα δείγματα και οι επιλογές των προϊόντων μένουν στα προϊόντα.' => 'The settings are included. The samples and the product choices stay with the products.',
		'Μηχανή του plugin (καθαρή PHP): διαβάζει PDF με κλασικό και με συμπιεσμένο cross-reference (PDF 1.5+).' => 'Plugin engine (pure PHP): reads PDFs with a classic and with a compressed cross-reference (PDF 1.5+).',
		'Φάκελος δειγμάτων'                                         => 'Samples folder',
		'Ο server δεν επιτρέπει εξωτερικά προγράμματα (proc_open): qpdf και Ghostscript δεν θα χρησιμοποιηθούν.' => 'The server does not allow external programs (proc_open): qpdf and Ghostscript will not be used.',
		'δεν βρέθηκε'                                               => 'not found',
		'Όριο μνήμης PHP: %1$s · Όριο χρόνου: %2$s s'               => 'PHP memory limit: %1$s · Time limit: %2$s s',
		'Αναδημιουργία στο παρασκήνιο με το Action Scheduler του WooCommerce.' => 'Background rebuilding with the WooCommerce Action Scheduler.',
		'Αναδημιουργία στο παρασκήνιο με το WP-Cron.'               => 'Background rebuilding with WP-Cron.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Δημιουργήθηκαν %d δείγματα.'                               => '%d samples created.',
		'%d προϊόντα απέτυχαν· η αιτία φαίνεται στη λίστα (κατάσταση «Σφάλμα»).' => '%d products failed; the reason shows in the list (state "Error").',
		'Δεν επιλέχθηκε αρχείο JSON.'                               => 'No JSON file was selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                          => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν τα δεδομένα του Book Preview).' => 'Invalid backup file (the Book Preview data is missing).',
		'Οι ρυθμίσεις του αρχείου δεν ήταν έγκυρες και δεν άλλαξαν.' => 'The settings in the file were not valid and did not change.',
		'Η εισαγωγή ολοκληρώθηκε.'                                  => 'Import completed.',
		'Το αρχείο είναι πολύ μεγάλο.'                              => 'The file is too large.',
		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
	);

	/** block.json strings, keyed by gettext context. */
	private static $ctx = array(
		'block title'       => array(
			'Δείγμα βιβλίου' => 'Book sample',
		),
		'block description' => array(
			'Το κουμπί «Διαβάστε ένα δείγμα» ενός προϊόντος με δείγμα από το Book Preview.' => 'The "Read a sample" button of a product that has a Book Preview sample.',
		),
		'block keyword'     => array(
			'δείγμα' => 'sample',
		),
	);

	public static function init(): void {
		add_filter( 'gettext_book-preview', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'gettext_with_context_book-preview', array( __CLASS__, 'filter_ctx' ), 10, 4 );
	}

	/** 'el' | 'en': the user's choice in the admin, the site language on the front end. */
	public static function lang(): string {
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			self::$lang = ( 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ) ? 'el' : 'en';
			return self::$lang;
		}
		$uid    = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$choice = $uid ? (string) get_user_meta( $uid, 'rs_lang', true ) : '';
		if ( 'el' === $choice || 'en' === $choice ) {
			$lang = $choice;
		} else {
			$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = ( 0 === strpos( strtolower( (string) $locale ), 'el' ) ) ? 'el' : 'en';
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** English text of a msgid, whatever the current language. */
	public static function english( string $msgid ): string {
		return self::$dict[ $msgid ] ?? $msgid;
	}

	/** gettext_book-preview filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'book-preview' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** gettext_with_context_book-preview filter (block.json). */
	public static function filter_ctx( $translation, $text, $context, $domain ) {
		if ( 'book-preview' === $domain && isset( self::$ctx[ $context ][ $text ] ) && 'en' === self::lang() ) {
			return self::$ctx[ $context ][ $text ];
		}
		return $translation;
	}
}
