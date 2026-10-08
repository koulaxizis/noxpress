<?php
/**
 * LF_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as
 * they are), anything else → English from the dictionary.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class LF_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		// Bootstrap / menu / pages.
		'Το Loop Fixer χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Loop Fixer requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Noxpress'                                                => 'Noxpress',
		'Loop Fixer'                                              => 'Loop Fixer',
		'Loop Fixer — Ρυθμίσεις'                                  => 'Loop Fixer — Settings',
		'LF Ρυθμίσεις'                                            => 'LF Settings',
		'Ρυθμίσεις'                                               => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'         => 'You do not have access to this page.',

		// Areas (LF_Areas::builtin labels).
		'Κατάστημα, κατηγορίες & αναζήτηση προϊόντων'             => 'Shop, categories & product search',
		'Σχετικά προϊόντα'                                        => 'Related products',
		'Up-sells (σελίδα προϊόντος)'                             => 'Up-sells (product page)',
		'Cross-sells (καλάθι)'                                    => 'Cross-sells (cart)',
		'Αρχείο θέματος: %s'                                      => 'Theme file: %s',

		// Option labels.
		'Ανενεργό'                                                => 'Off',
		'Έγχυση στην κάρτα του θέματος'                           => 'Inject into the theme\'s card',
		'Αντικατάσταση με το πρότυπο του WooCommerce'             => 'Replace with the WooCommerce template',
		'Βαθμολογία'                                              => 'Rating',
		'Τιμή'                                                    => 'Price',
		'Κουμπί καλαθιού'                                         => 'Add-to-cart button',
		'Τίτλος προϊόντος'                                        => 'Product title',
		'Εικόνα προϊόντος'                                        => 'Product image',
		'Όπως το θέμα'                                            => 'As the theme',
		'Αριστερά'                                                => 'Left',
		'Κέντρο'                                                  => 'Center',
		'Δεξιά'                                                   => 'Right',
		'Χρώμα τιμής'                                             => 'Price color',
		'Χρώμα τιμής (hover κάρτας)'                              => 'Price color (card hover)',
		'Φόντο κουμπιού'                                          => 'Button background',
		'Κείμενο κουμπιού'                                        => 'Button text',

		// Main page — status.
		'Επαναφέρει τιμή, κουμπί καλαθιού και βαθμολογία στις κάρτες προϊόντων που ζωγραφίζει το θέμα με δικό του κώδικα. Τα αρχεία του θέματος δεν αλλάζουν ποτέ.' => 'Restores price, add-to-cart button and rating in product cards that the theme draws with its own code. Theme files are never changed.',
		'Κατάσταση'                                               => 'Status',
		'Ενεργό θέμα:'                                            => 'Active theme:',
		'Loop Fixer:'                                             => 'Loop Fixer:',
		'Απενεργοποιημένο από το LF_DISABLE (wp-config.php)'      => 'Disabled by LF_DISABLE (wp-config.php)',
		'Λειτουργία δοκιμής — οι αλλαγές φαίνονται μόνο στους διαχειριστές του καταστήματος' => 'Test mode — changes are visible to shop managers only',
		'Ενεργό για όλους τους επισκέπτες'                        => 'Active for all visitors',
		'Αλλαγή'                                                  => 'Change',
		'Το ενεργό θέμα είναι block theme: οι κάρτες προϊόντων σχεδιάζονται από blocks του WooCommerce και δεν χρειάζονται διόρθωση. Το Loop Fixer μένει ανενεργό σε αυτό το θέμα.' => 'The active theme is a block theme: product cards are drawn by WooCommerce blocks and need no fixing. Loop Fixer stays inactive with this theme.',
		'Η περιοχή «%s» ανεστάλη: τα αρχεία του θέματος άλλαξαν μετά την τελευταία ρύθμιση (π.χ. ενημέρωση θέματος). Έλεγξε τη σελίδα με τη σάρωση και επιβεβαίωσε για να ενεργοποιηθεί ξανά.' => 'The area "%s" was suspended: theme files changed after it was last configured (e.g. a theme update). Check the page with the page probe and confirm to turn it back on.',
		'Επιβεβαίωση & επανενεργοποίηση'                          => 'Confirm & re-enable',

		// Main page — detector.
		'1. Ανιχνευτής θέματος'                                   => '1. Theme detector',
		'Αρχεία του θέματος που ζωγραφίζουν κάρτες προϊόντων: overrides προτύπων του WooCommerce και αρχεία με δικό τους WP_Query προϊόντων. Η ανάλυση διαβάζει μόνο τον κώδικα (τα σχόλια αγνοούνται).' => 'Theme files that draw product cards: WooCommerce template overrides and files with their own product WP_Query. The analysis reads code only (comments are ignored).',
		'Αρχείο'                                                  => 'File',
		'Περιοχή'                                                 => 'Area',
		'Ευρήματα'                                                => 'Findings',
		'Πρόταση'                                                 => 'Suggestion',
		'Δεν βρέθηκαν overrides καρτών ή βρόχοι προϊόντων στο θέμα — πιθανότατα οι κάρτες είναι ήδη οι κανονικές του WooCommerce.' => 'No card overrides or product loops were found in the theme — the cards are most likely WooCommerce\'s standard ones already.',
		'Override προτύπου WooCommerce'                           => 'WooCommerce template override',
		'Δικός του βρόχος προϊόντων'                              => 'Own product loop',
		'Hooks κάρτας'                                            => 'Card hooks',
		'Κουμπί'                                                  => 'Button',
		'Σπασμένος βρόχος'                                        => 'Broken loop',
		'Προσθήκη ως περιοχή'                                     => 'Add as area',
		'Νέα σάρωση θέματος'                                      => 'Rescan theme',

		// Main page — areas.
		'2. Περιοχές'                                             => '2. Areas',
		'Έγχυση: το slot (τιμή / κουμπί / βαθμολογία) μπαίνει μέσα στην κάρτα του θέματος, αμέσως μετά το κλείσιμο του στοιχείου που περιέχει τον τίτλο ή την εικόνα. Αντικατάσταση: η περιοχή σχεδιάζεται με το πρότυπο του WooCommerce (κανονικές κάρτες με όλα τα hooks — διορθώνει και σπασμένους βρόχους). Οι κάρτες που είναι ήδη κανονικές δεν αγγίζονται.' => 'Inject: the slot (price / button / rating) goes inside the theme\'s card, right after the element that holds the title or the image is closed. Replace: the area is drawn with the WooCommerce template (standard cards with every hook — also fixes broken loops). Cards that are already standard are never touched.',
		'Αποθήκευση περιοχών'                                     => 'Save areas',
		'Προσθήκη αρχείου θέματος'                                => 'Add a theme file',
		'Προσθήκη'                                                => 'Add',
		'Διαδρομή σχετική με τον φάκελο του θέματος, για αρχεία που τρέχουν δικό τους WP_Query προϊόντων και δεν εμφανίζονται στον ανιχνευτή.' => 'Path relative to the theme folder, for files that run their own product WP_Query and do not show up in the detector.',
		'Σε αναστολή'                                             => 'Suspended',
		'Ρυθμίσεις έγχυσης'                                       => 'Injection settings',
		'Τι προστίθεται'                                          => 'What is added',
		'Σημείο αναφοράς'                                         => 'Anchor',
		'Μετά το κλείσιμο του στοιχείου'                          => 'After the closing of the element',
		'Το πρώτο τέτοιο κλείσιμο μετά το σημείο αναφοράς, μέσα στην ίδια κάρτα.' => 'The first such closing tag after the anchor, within the same card.',
		'Εμφάνιση'                                                => 'Appearance',
		'Στοίχιση'                                                => 'Alignment',
		'Απόσταση από πάνω (px)'                                  => 'Top spacing (px)',
		'CSS selector της κάρτας (για το χρώμα τιμής στο hover)'  => 'Card CSS selector (for the price color on hover)',
		'Πρόσθετο CSS'                                            => 'Additional CSS',
		'Το slot αυτής της περιοχής έχει την κλάση %s.'           => 'The slot of this area has the class %s.',
		'Αφαίρεση αυτής της περιοχής'                             => 'Remove this area',

		// Main page — text fixes.
		'3. Διορθώσεις κειμένου στις σελίδες καταστήματος'        => '3. Text fixes on shop pages',
		'Ακριβής αναζήτηση και αντικατάσταση στο HTML των σελίδων καταστήματος, κατηγοριών και αναζήτησης προϊόντων — π.χ. ένα σταθερό <h1>Shop</h1> του θέματος γίνεται ο σωστός τίτλος κάθε σελίδας. Όταν η αναζήτηση είναι ολόκληρο στοιχείο, αλλάζει μόνο το κείμενό του.' => 'Exact find and replace in the HTML of shop, category and product search pages — e.g. a fixed <h1>Shop</h1> in the theme becomes the right title of each page. When the find text is a whole element, only its text changes.',
		'Αναζήτηση (ακριβώς όπως στο HTML)'                       => 'Find (exactly as in the HTML)',
		'Αντικατάσταση με'                                        => 'Replace with',
		'Δικό σου κείμενο'                                        => 'Your own text',
		'Τίτλος σελίδας του WooCommerce'                          => 'WooCommerce page title',
		'Άδειασε την αναζήτηση για να διαγράψεις μια διόρθωση. Έως %d διορθώσεις ανά θέμα.' => 'Empty the find field to delete a fix. Up to %d fixes per theme.',
		'Αποθήκευση διορθώσεων'                                   => 'Save fixes',

		// Main page — probe.
		'4. Σάρωση σελίδας'                                       => '4. Page probe',
		'Ανοίγει μια σελίδα του site σε νέα καρτέλα με τις τρέχουσες ρυθμίσεις εφαρμοσμένες μόνο για σένα (ακόμη κι αν το Loop Fixer είναι ανενεργό) και καταγράφει τους βρόχους προϊόντων που βρήκε. Μετά ανανέωσε αυτή τη σελίδα για να δεις τα αποτελέσματα.' => 'Opens a page of the site in a new tab with the current settings applied for you only (even when Loop Fixer is off) and records the product loops it found. Then reload this page to see the results.',
		'Σάρωση σελίδας'                                          => 'Probe page',
		'Δεν υπάρχει ακόμη αποτέλεσμα σάρωσης.'                   => 'No probe result yet.',
		'Τελευταία σάρωση: %1$s — %2$s'                           => 'Last probe: %1$s — %2$s',
		'Η σάρωση έγινε με άλλο ενεργό θέμα.'                     => 'The probe ran with a different active theme.',
		'Παρουσιάστηκε σφάλμα κατά τη σάρωση: το Loop Fixer σταμάτησε τις αλλαγές για εκείνη τη σελίδα και άφησε το HTML του θέματος όπως ήταν.' => 'An error occurred during the probe: Loop Fixer stopped its changes for that page and left the theme\'s HTML as it was.',
		'Λειτουργία'                                              => 'Mode',
		'Κάρτες'                                                  => 'Cards',
		'Κανονικές'                                               => 'Standard',
		'Με slot'                                                 => 'With slot',
		'Χωρίς θέση'                                              => 'Not placed',
		'Χρόνος (ms)'                                             => 'Time (ms)',
		'Δεν βρέθηκαν βρόχοι προϊόντων σε αυτή τη σελίδα.'        => 'No product loops were found on this page.',
		'«Χωρίς θέση»: το slot δεν τοποθετήθηκε γιατί δεν βρέθηκε το κλείσιμο του στοιχείου μέσα στην κάρτα — άλλαξε το σημείο αναφοράς ή το στοιχείο. Σε περιοχή με Αντικατάσταση οι κάρτες είναι κανονικές.' => '"Not placed": the slot was not inserted because the element\'s closing tag was not found inside the card — change the anchor or the element. In an area set to Replace the cards are standard.',

		// Settings page.
		'Ενεργοποίηση του Loop Fixer στο front end'               => 'Enable Loop Fixer on the front end',
		'Λειτουργία δοκιμής: οι αλλαγές φαίνονται μόνο σε όσους διαχειρίζονται το κατάστημα' => 'Test mode: changes are visible only to users who manage the shop',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Το LF_DISABLE είναι ενεργό: καμία αλλαγή δεν εφαρμόζεται στο front end.' => 'LF_DISABLE is active: no change is applied on the front end.',
		'Αποθήκευση ρυθμίσεων'                                    => 'Save settings',
		'Ρυθμίσεις ενεργού θέματος'                               => 'Active theme settings',
		'Οι περιοχές και οι διορθώσεις κειμένου αποθηκεύονται ξεχωριστά για κάθε θέμα. Η επαναφορά διαγράφει μόνο εκείνες του ενεργού θέματος.' => 'Areas and text fixes are stored separately for each theme. Resetting deletes only those of the active theme.',
		'Ναι, διάγραψε τις ρυθμίσεις του ενεργού θέματος'         => 'Yes, delete the settings of the active theme',
		'Επαναφορά'                                               => 'Reset',
		'Backup & Επαναφορά'                                      => 'Backup & Restore',
		'Εισαγωγή ρυθμίσεων (JSON)'                               => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                => 'Export settings (JSON)',
		'Περιλαμβάνονται όλες οι ρυθμίσεις, για όλα τα θέματα. Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τις τρέχουσες ρυθμίσεις, μετά από πλήρη έλεγχο εγκυρότητας.' => 'All settings are included, for every theme. Import REPLACES the current settings, after full validation.',

		// Notices.
		'Η σάρωση του θέματος ολοκληρώθηκε.'                      => 'The theme scan is complete.',
		'Άγνωστη ενέργεια.'                                       => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                             => 'Settings saved.',
		'Δεν επιβεβαιώθηκε η επαναφορά.'                          => 'The reset was not confirmed.',
		'Οι ρυθμίσεις του ενεργού θέματος διαγράφηκαν.'           => 'The settings of the active theme were deleted.',
		'Το αρχείο δεν βρέθηκε στο ενεργό θέμα (διαδρομή σχετική με τον φάκελο του θέματος, π.χ. template-parts/home.php).' => 'The file was not found in the active theme (path relative to the theme folder, e.g. template-parts/home.php).',
		'Η περιοχή υπάρχει ήδη.'                                  => 'The area already exists.',
		'Έφτασες το όριο των %d αρχείων θέματος.'                 => 'You reached the limit of %d theme files.',
		'Η περιοχή προστέθηκε — ρύθμισέ την παρακάτω (ξεκινά ανενεργή).' => 'The area was added — configure it below (it starts off).',
		'Οι περιοχές αποθηκεύτηκαν.'                              => 'Areas saved.',
		'Το Loop Fixer είναι ακόμη ανενεργό: δοκίμασε με τη σάρωση σελίδας και ενεργοποίησέ το από τις Ρυθμίσεις.' => 'Loop Fixer is still off: test with the page probe and enable it from Settings.',
		'Οι διορθώσεις κειμένου αποθηκεύτηκαν.'                   => 'Text fixes saved.',
		'Άγνωστη περιοχή.'                                        => 'Unknown area.',
		'Η περιοχή ενεργοποιήθηκε ξανά με τα τρέχοντα αρχεία του θέματος.' => 'The area is active again with the current theme files.',
		'Η σάρωση δέχεται μόνο σελίδες αυτού του site.'           => 'The probe accepts pages of this site only.',
		'Δεν επιλέχθηκε αρχείο JSON.'                             => 'No JSON file selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                        => 'The file is not valid JSON.',
		'Το αρχείο είναι πολύ μεγάλο.'                            => 'The file is too large.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Loop Fixer).' => 'Invalid backup file (Loop Fixer settings missing).',
		'Μη έγκυρο blob ρυθμίσεων.'                               => 'Invalid settings blob.',
		'Η εισαγωγή ολοκληρώθηκε.'                                => 'Import completed.',
		'Κάποια θέματα παραλείφθηκαν επειδή τα δεδομένα τους δεν ήταν έγκυρα.' => 'Some themes were skipped because their data was not valid.',

		// Footer.
		'Made with ❤ by %s'                                       => 'Made with ❤ by %s',
		'Noxpress Dashboard'                                      => 'Noxpress Dashboard',
		'More plugins at'                                         => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                          => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_loop-fixer', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' for the current user (rs_lang → locale fallback). */
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
			$lang   = ( 0 === strpos( strtolower( (string) $locale ), 'el' ) ) ? 'el' : 'en';
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_loop-fixer filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'loop-fixer' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
