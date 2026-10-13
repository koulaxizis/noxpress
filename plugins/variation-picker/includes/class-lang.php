<?php
/**
 * VPK_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts visitors see are store settings with a Greek and an English
 * value (Bible §14.17, VPK_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Κουμπί με κείμενο'                                         => 'Text button',
		'Χρώμα'                                                     => 'Color',
		'Εικόνα'                                                    => 'Image',
		'Εικόνα παραλλαγής (αυτόματα)'                              => 'Variation image (automatic)',
		'Λίστα (radio)'                                             => 'List (radio)',
		'Dropdown (όπως το WooCommerce)'                            => 'Dropdown (like WooCommerce)',
		'Τετράγωνο'                                                 => 'Square',
		'Στρογγυλεμένο'                                             => 'Rounded',
		'Κύκλος'                                                    => 'Circle',
		'Επιλογή εικόνας'                                           => 'Choose image',
		'Χρήση εικόνας'                                             => 'Use image',
		'Γενική ρύθμιση (Variation Picker)'                         => 'Global setting (Variation Picker)',
		'Γενική ρύθμιση'                                            => 'Global setting',
		'Πώς εμφανίζεται το χαρακτηριστικό στη σελίδα προϊόντος (Variation Picker). Χρώματα και εικόνες ορίζονται σε κάθε τιμή.' => 'How the attribute shows on the product page (Variation Picker). Colors and images are set on each value.',
		'Swatches'                                                  => 'Swatches',
		'Δεύτερο χρώμα (δίχρωμο)'                                   => 'Second color (two-tone)',
		'Αφαίρεση'                                                  => 'Remove',
		'Σύντομη ετικέτα (π.χ. XL)'                                 => 'Short label (e.g. XL)',
		'Swatch'                                                    => 'Swatch',
		'Το χρώμα ή η εικόνα φαίνεται όταν το χαρακτηριστικό έχει τύπο «Χρώμα» ή «Εικόνα»· η σύντομη ετικέτα στα κουμπιά με κείμενο.' => 'The color or image shows when the attribute has the type "Color" or "Image"; the short label on text buttons.',
		'Αποθήκευσε πρώτα τα χαρακτηριστικά που χρησιμοποιούνται για παραλλαγές· μετά εμφανίζονται εδώ.' => 'Save the attributes used for variations first; then they show here.',
		'Ρύθμιση του χαρακτηριστικού'                               => 'Attribute setting',
		'Χρώματα, εικόνες και ετικέτες των τιμών'                   => 'Colors, images and labels of the values',
		'Χρώματα και εικόνες ορίζονται στις τιμές του χαρακτηριστικού' => 'Colors and images are set on the attribute\'s values',
		'Οδηγός μεγεθών'                                            => 'Size guide',
		'Αυτόματα (από τις κατηγορίες)'                             => 'Automatic (from the categories)',
		'Κανένας'                                                   => 'None',
		'Γενικά'                                                    => 'General',
		'Χαρακτηριστικά'                                            => 'Attributes',
		'Κατάστημα'                                                 => 'Shop',
		'Οδηγοί μεγεθών'                                            => 'Size guides',
		'Κείμενα'                                                   => 'Texts',
		'Εργαλεία'                                                  => 'Tools',
		'Variation Picker'                                          => 'Variation Picker',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Swatches για τις παραλλαγές: κουμπιά, χρώματα, εικόνες, οδηγός μεγεθών και swatches στο κατάστημα.' => 'Swatches for variations: buttons, colors, images, size guide and swatches in the shop.',
		'Θολό'                                                      => 'Blurred',
		'Διαγραμμένο (×)'                                           => 'Crossed out (×)',
		'Κρυφό'                                                     => 'Hidden',
		'Λειτουργία'                                                => 'Mode',
		'Το VPK_DISABLE είναι ενεργό στο wp-config.php: τα swatches δεν εμφανίζονται πουθενά.' => 'VPK_DISABLE is on in wp-config.php: the swatches do not show anywhere.',
		'Είναι ενεργό και άλλο plugin για swatches: %s. Όσο είναι ενεργό, το Variation Picker μένει σε λειτουργία δοκιμής (μόνο οι διαχειριστές το βλέπουν), ώστε ο επισκέπτης να μη δει διπλά swatches. Απενεργοποίησέ το όταν είσαι έτοιμος.' => 'Another swatches plugin is active: %s. While it is active, Variation Picker stays in test mode (only managers see it), so visitors do not see double swatches. Deactivate it when you are ready.',
		'Ανενεργό'                                                  => 'Off',
		'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: only the store\'s managers see it',
		'Ενεργό για όλους'                                          => 'On for everyone',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Χαρακτηριστικά χωρίς ρύθμιση'                              => 'Attributes without a setting',
		'Γίνονται κουμπιά με κείμενο'                               => 'Become text buttons',
		'Μένουν dropdown'                                           => 'Stay dropdowns',
		'Εμφάνιση'                                                  => 'Appearance',
		'Σχήμα'                                                     => 'Shape',
		'Μέγεθος (px)'                                              => 'Size (px)',
		'Μέγεθος γραμμάτων (px)'                                    => 'Font size (px)',
		'Πάχος περιγράμματος (px)'                                  => 'Border width (px)',
		'Χρώμα περιγράμματος'                                       => 'Border color',
		'Χρώμα επιλεγμένου'                                         => 'Selected color',
		'Προεπισκόπηση'                                             => 'Preview',
		'Συμπεριφορά'                                               => 'Behavior',
		'Συνδυασμοί που δεν υπάρχουν'                               => 'Combinations that do not exist',
		'Εκτός αποθέματος'                                          => 'Out of stock',
		'Tooltip'                                                   => 'Tooltip',
		'Κανένα'                                                    => 'None',
		'Όνομα'                                                     => 'Name',
		'Όνομα και εικόνα'                                          => 'Name and image',
		'Η επιλεγμένη τιμή δίπλα στο όνομα (π.χ. «Χρώμα: Μαύρο»)'   => 'The chosen value next to the name (e.g. "Color: Black")',
		'Αυτόματη επιλογή όταν μένει μόνο μία δυνατή τιμή'          => 'Select automatically when only one possible value is left',
		'Δεύτερο κλικ στην επιλεγμένη τιμή την αποεπιλέγει'         => 'A second click on the chosen value clears it',
		'Οι επιλογές μπαίνουν στη διεύθυνση της σελίδας (ο σύνδεσμος ανοίγει την ίδια παραλλαγή)' => 'The choices go into the page address (the link opens the same variation)',
		'Ένα κλικ σε τιμή που δεν ταιριάζει με τις άλλες επιλογές την επιλέγει και καθαρίζει τις υπόλοιπες.' => 'A click on a value that does not match the other choices selects it and clears the rest.',
		'Πληροφορίες στο tooltip'                                   => 'Tooltip details',
		'Διαφορά τιμής (π.χ. «+3,00 €») όταν μια τιμή κοστίζει περισσότερο' => 'Price difference (e.g. "+3.00 €") when a value costs more',
		'«Μόνο N διαθέσιμα» από απόθεμα'                            => '"Only N left" from stock',
		'0 = ποτέ. Μόνο για παραλλαγές με διαχείριση αποθέματος.'   => '0 = never. Only for variations with stock management.',
		'Καλάθι'                                                    => 'Cart',
		'Χρωματιστή κουκκίδα δίπλα στο χρώμα στο καλάθι, στο mini-cart και στο ταμείο' => 'Colored dot next to the color in the cart, mini-cart and checkout',
		'Για χαρακτηριστικά τύπου «Χρώμα». Το Cart / Checkout block δεν τη δείχνει.' => 'For attributes of type "Color". The Cart / Checkout block does not show it.',
		'Αποθήκευση'                                                => 'Save',
		'Ο τύπος κάθε χαρακτηριστικού στη σελίδα προϊόντος. Κενό = η γενική ρύθμιση. Χρώματα, εικόνες και σύντομες ετικέτες ορίζονται στις τιμές («Τιμές»). Ένα προϊόν μπορεί να αλλάξει τον τύπο στην καρτέλα «Swatches».' => 'The type of each attribute on the product page. Empty = the global setting. Colors, images and short labels are set on the values ("Values"). A product can change the type in its "Swatches" tab.',
		'Δεν υπάρχουν χαρακτηριστικά στο WooCommerce.'              => 'There are no attributes in WooCommerce.',
		'Χαρακτηριστικό'                                            => 'Attribute',
		'Τύπος'                                                     => 'Type',
		'Τιμές'                                                     => 'Values',
		'%d τιμές'                                                  => '%d values',
		'Αυτόματα: το πρώτο χαρακτηριστικό με χρώμα ή εικόνα'       => 'Automatic: the first attribute with a color or image',
		'Swatches στις κάρτες προϊόντων'                            => 'Swatches on product cards',
		'Swatches στο κατάστημα, στις κατηγορίες και στα σχετικά προϊόντα' => 'Swatches in the shop, categories and related products',
		'Θέση'                                                      => 'Position',
		'Μετά τον τίτλο'                                            => 'After the title',
		'Μετά την τιμή'                                             => 'After the price',
		'Μετά το κουμπί'                                            => 'After the button',
		'Μέγιστος αριθμός'                                          => 'Maximum number',
		'0 = όλα. Τα υπόλοιπα γίνονται «+N».'                       => '0 = all. The rest become "+N".',
		'Hover ή focus αλλάζει την εικόνα της κάρτας στην εικόνα της παραλλαγής' => 'Hover or focus changes the card\'s image to the variation\'s image',
		'Ο σύνδεσμος ανοίγει το προϊόν με την τιμή ήδη επιλεγμένη'  => 'The link opens the product with the value already chosen',
		'Για τα κλασικά πρότυπα καταστήματος (και τις κάρτες του Theme Patcher). Στα block themes, το WooCommerce τα δείχνει στο κατάστημα, στις κατηγορίες και στην αναζήτηση, κάτω από τον τίτλο· όχι σε μπλοκ Product Collection σε άλλες σελίδες. Ένα προϊόν χωρίς χάρτη διαθεσιμότητας δεν δείχνει swatches μέχρι να φτιαχτεί (Εργαλεία).' => 'For classic shop templates (and Theme Patcher\'s cards). In block themes, WooCommerce shows them on the shop, category and search pages, below the title; not in Product Collection blocks on other pages. A product without an availability map shows no swatches until it is built (Tools).',
		'Ένα κουμπί «Οδηγός μεγεθών» δίπλα στο χαρακτηριστικό ανοίγει τον πίνακα. Ένα προϊόν παίρνει τον πρώτο ενεργό οδηγό των κατηγοριών του (και των γονικών τους)· στην καρτέλα «Swatches» του προϊόντος διαλέγεις άλλον ή κανέναν. Πίνακας: μία γραμμή ανά σειρά, κελιά με «|», η πρώτη γραμμή είναι η κεφαλίδα.' => 'A "Size guide" button next to the attribute opens the table. A product gets the first active guide of its categories (and their parents); in the product\'s "Swatches" tab you choose another one or none. Table: one line per row, cells separated by "|", the first line is the header.',
		'Κάτω από το τελευταίο χαρακτηριστικό'                      => 'Below the last attribute',
		'Νέος οδηγός'                                               => 'New guide',
		'Τίτλος (Ελληνικά)'                                         => 'Title (Greek)',
		'π.χ. Μεγέθη T-shirt'                                       => 'e.g. T-shirt sizes',
		'Τίτλος (English)'                                          => 'Title (English)',
		'Πίνακας (Ελληνικά)'                                        => 'Table (Greek)',
		'Πίνακας (English)'                                         => 'Table (English)',
		'Σημείωση (Ελληνικά)'                                       => 'Note (Greek)',
		'Σημείωση (English)'                                        => 'Note (English)',
		'Δίπλα στο χαρακτηριστικό'                                  => 'Next to the attribute',
		'Κατηγορίες (καμία = όλα τα προϊόντα)'                      => 'Categories (none = every product)',
		'Εικόνα (προαιρετικό)'                                      => 'Image (optional)',
		'Ενεργός'                                                   => 'Active',
		'Διαγραφή'                                                  => 'Delete',
		'Κουμπί του οδηγού μεγεθών'                                 => 'Size guide button',
		'Χαμηλό απόθεμα (%d = ποσότητα)'                            => 'Low stock (%d = quantity)',
		'Κλείσιμο του οδηγού (για screen readers)'                  => 'Close the guide (for screen readers)',
		'Συνδυασμός που δεν υπάρχει (tooltip)'                      => 'Combination that does not exist (tooltip)',
		'Εκτός αποθέματος (tooltip)'                                => 'Out of stock (tooltip)',
		'Κείμενα για τον πελάτη'                                    => 'Customer texts',
		'Γλώσσα κειμένων'                                           => 'Text language',
		'Αυτόματα (γλώσσα του site)'                                => 'Automatic (site language)',
		'Ελληνικά'                                                  => 'Ελληνικά',
		'English'                                                   => 'English',
		'Κείμενο'                                                   => 'Text',
		'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό. Τα ονόματα των τιμών είναι τα ονόματα των χαρακτηριστικών του WooCommerce.' => 'Empty Greek text = the default. Empty English = shows the Greek. The value names are the names of the WooCommerce attributes.',
		'Εισαγωγή από άλλο plugin για swatches'                     => 'Import from another swatches plugin',
		'Διαβάζει χρώματα, εικόνες, ετικέτες και τύπους χαρακτηριστικών από τα CartFlows Variation Swatches, Variation Swatches (GetWooPlugins) και WCBoost Variation Swatches, και τα αντιγράφει μόνο όπου το Variation Picker δεν έχει ήδη τιμή. Δεν αλλάζει τίποτα στα δεδομένα εκείνων των plugins.' => 'Reads colors, images, labels and attribute types from CartFlows Variation Swatches, Variation Swatches (GetWooPlugins) and WCBoost Variation Swatches, and copies them only where Variation Picker has no value yet. Nothing in those plugins\' data is changed.',
		'Ενεργό τώρα: %s'                                           => 'Active now: %s',
		'Εισαγωγή'                                                  => 'Import',
		'Χάρτες διαθεσιμότητας'                                     => 'Availability maps',
		'Μεταβλητά προϊόντα με χάρτη: %1$d από %2$d.'               => 'Variable products with a map: %1$d of %2$d.',
		'Ο χάρτης λέει ποιοι συνδυασμοί υπάρχουν και ποια εικόνα έχει κάθε τιμή. Φτιάχνεται όταν αποθηκεύεται ένα προϊόν ή αλλάζει το απόθεμά του. Η ανακατασκευή τρέχει στο παρασκήνιο (Action Scheduler) σε ομάδες των 50.' => 'The map says which combinations exist and which image each value has. It is built when a product is saved or its stock changes. The rebuild runs in the background (Action Scheduler) in batches of 50.',
		'Ανακατασκευή όλων'                                         => 'Rebuild all',
		'Backup ρυθμίσεων'                                          => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)'                                 => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                  => 'Export settings (JSON)',
		'Το backup έχει τις ρυθμίσεις, τους τύπους των χαρακτηριστικών και τους οδηγούς μεγεθών. Τα χρώματα και οι εικόνες των τιμών μένουν στις τιμές.' => 'The backup holds the settings, the attribute types and the size guides. The values\' colors and images stay on the values.',
		'Διαγραφή του plugin'                                       => 'Deleting the plugin',
		'Διαγραφή των χρωμάτων, εικόνων και ετικετών των τιμών και των ρυθμίσεων ανά προϊόν όταν διαγραφεί το plugin' => 'Delete the values\' colors, images and labels and the per-product settings when the plugin is deleted',
		'Χωρίς το τικ μένουν, ώστε μια νέα εγκατάσταση να τα βρει. Οι ρυθμίσεις, οι οδηγοί και οι χάρτες φεύγουν πάντα.' => 'Without the tick they stay, so a new install finds them. Settings, guides and maps always go.',
		'Για προγραμματιστές'                                       => 'For developers',
		'Η εμφάνιση μιας τιμής χαρακτηριστικού (τύπος, χρώμα, δεύτερο χρώμα, εικόνα, ετικέτα) ή null, π.χ. για χρωματιστά φίλτρα.' => 'The look of an attribute value (type, color, second color, image, label) or null, e.g. for color filters.',
		'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).'             => 'Filter for the customer texts (e.g. WPML).',
		'Σταθερά στο wp-config.php: σταματά κάθε αλλαγή στο front end.' => 'Constant in wp-config.php: stops every front-end change.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Η εισαγωγή ολοκληρώθηκε: %1$d τιμές και %2$d χαρακτηριστικά.' => 'Import completed: %1$d values and %2$d attributes.',
		'Η ανακατασκευή ξεκίνησε στο παρασκήνιο. Ανανέωσε τη σελίδα σε λίγο για να δεις την πρόοδο.' => 'The rebuild started in the background. Reload the page in a little while to see the progress.',
		'Το Action Scheduler του WooCommerce δεν είναι διαθέσιμο.'  => 'WooCommerce\'s Action Scheduler is not available.',
		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Ένας οδηγός χωρίς ελληνικό τίτλο δεν αποθηκεύτηκε.'        => 'A guide without a Greek title was not saved.',
		'Το αρχείο είναι πολύ μεγάλο.'                              => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                               => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                          => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Variation Picker).' => 'Invalid backup file (the Variation Picker settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: ένα μέρος του αρχείου έλειπε ή δεν ήταν έγκυρο και έμεινε ως είχε.' => 'Import completed PARTIALLY: part of the file was missing or invalid and was left as it was.',
		'Η εισαγωγή ολοκληρώθηκε.'                                  => 'Import completed.',
		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
		'Το Variation Picker χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Variation Picker requires WooCommerce to be active — the plugin stays inactive until it is activated.',
	);

	public static function init(): void {
		add_filter( 'gettext_variation-picker', array( __CLASS__, 'filter' ), 10, 3 );
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
			$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_variation-picker filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'variation-picker' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
