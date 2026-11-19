<?php
/**
 * PTB_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Visitor texts are store settings (PTB_Settings, Bible §14.17), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class PTB_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Product Tabs χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Product Tabs requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Ρυθμίσεις'                                            => 'Settings',
		'Κοινές καρτέλες'                                      => 'Shared tabs',
		'Εισαγωγή και backup'                                  => 'Import and backup',
		'Για προγραμματιστές'                                  => 'For developers',
		'Product Tabs'                                         => 'Product Tabs',
		'Καρτέλες'                                             => 'Tabs',
		'Το «Custom Product Tabs for WooCommerce» είναι ακόμα ενεργό. Κάνε την εισαγωγή από το Product Tabs, έλεγξε μερικά προϊόντα και μετά απενεργοποίησέ το.' => '"Custom Product Tabs for WooCommerce" is still active. Run the import from Product Tabs, check a few products, then deactivate it.',
		'Εισαγωγή'                                             => 'Import',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'      => 'You do not have access to this page.',
		'Επιπλέον καρτέλες στη σελίδα προϊόντος, ανά προϊόν και κοινές, βίντεο που φορτώνουν μόνο με κλικ και μία σειρά για όλες τις καρτέλες.' => 'Extra tabs on the product page, per product and shared, videos that load only on a click, and one order for all tabs.',
		'Ανενεργό'                                             => 'Off',
		'Λειτουργία δοκιμής: οι καρτέλες και η νέα σειρά φαίνονται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: the tabs and the new order show to the store\'s managers only',
		'Ενεργό για όλους'                                     => 'On for everyone',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Περιγραφή (WooCommerce)'                              => 'Description (WooCommerce)',
		'Πρόσθετες πληροφορίες (WooCommerce)'                  => 'Additional information (WooCommerce)',
		'Κριτικές (WooCommerce)'                               => 'Reviews (WooCommerce)',
		'Φροντίδα & Ασφάλεια (Theme Patcher)'                  => 'Care & Safety (Theme Patcher)',
		'Δημιουργοί (Featured Creators)'                       => 'Creators (Featured Creators)',
		'Ξεφυλλίστε το βιβλίο (Book Preview)'                  => 'Leaf through the book (Book Preview)',
		'Τίτλος καρτέλας trailer'                              => 'Trailer tab title',
		'Τίτλος καρτέλας παρουσίασης'                          => 'Presentation tab title',
		'Ετικέτα κουμπιού αναπαραγωγής (για αναγνώστες οθόνης, %s = ο τίτλος)' => 'Play button label (for screen readers, %s = the title)',
		'Λειτουργία'                                           => 'Mode',
		'Το PTB_DISABLE είναι ενεργό στο wp-config.php: καμία καρτέλα δεν αλλάζει.' => 'PTB_DISABLE is on in wp-config.php: no tab changes.',
		'Εικόνα αφίσας για τα βίντεο YouTube (από το i.ytimg.com· χωρίς αυτό, σκούρα αφίσα με κουμπί)' => 'Poster image for YouTube videos (from i.ytimg.com; without it, a dark poster with a button)',
		'Το shortcode [wb_cpt_youtube_embed_shortcode] δουλεύει και μετά την απενεργοποίηση του «Custom Product Tabs for WooCommerce»' => 'The [wb_cpt_youtube_embed_shortcode] shortcode keeps working after "Custom Product Tabs for WooCommerce" is deactivated',
		'Όσο τρέχουν και τα δύο, κρύβονται οι καρτέλες του παλιού plugin στα προϊόντα που έχουν εισαχθεί' => 'While both run, the old plugin\'s tabs are hidden on imported products',
		'Καθαρισμός της page cache σε κάθε αποθήκευση'         => 'Clear the page cache on every save',
		'Διαγραφή όλων των καρτελών (ανά προϊόν και κοινών) όταν διαγραφεί το plugin' => 'Delete all tabs (per product and shared) when the plugin is deleted',
		'Κείμενα για τους επισκέπτες'                          => 'Visitor texts',
		'Γλώσσα των κειμένων'                                  => 'Language of the texts',
		'Αυτόματα (γλώσσα του site)'                           => 'Automatic (site language)',
		'Ελληνικά'                                             => 'Greek',
		'Αγγλικά'                                              => 'English',
		'Κενό πεδίο = το προεπιλεγμένο κείμενο. Κενό αγγλικό κείμενο δείχνει το ελληνικό.' => 'Empty field = the default text. An empty English text shows the Greek one.',
		'Σειρά των καρτελών'                                   => 'Order of the tabs',
		'Θέση: μικρότερος αριθμός = πιο αριστερά (το WooCommerce βάζει Περιγραφή 10, Πρόσθετες πληροφορίες 20, Κριτικές 30). Κενό = όπως είναι. Οι καρτέλες κάθε προϊόντος και οι κοινές έχουν τη δική τους θέση.' => 'Position: a lower number = further left (WooCommerce uses Description 10, Additional information 20, Reviews 30). Empty = as it is. Each product\'s tabs and the shared tabs have their own position.',
		'Καρτέλα'                                              => 'Tab',
		'Θέση'                                                 => 'Position',
		'Νέος τίτλος (ελληνικά)'                               => 'New title (Greek)',
		'Νέος τίτλος (αγγλικά)'                                => 'New title (English)',
		'Απόκρυψη'                                             => 'Hide',
		'Αποθήκευση'                                           => 'Save',
		'Μία καρτέλα για πολλά προϊόντα, π.χ. «Αποστολές & επιστροφές» σε όλα ή «Οδηγός μεγεθών» σε μια κατηγορία. Ένα προϊόν δείχνει την καρτέλα όταν ταιριάζει σε έναν τουλάχιστον κανόνα· στο κουτί «Καρτέλες προϊόντος» μπορείς να την κρύψεις ή να γράψεις δικό του κείμενο.' => 'One tab for many products, e.g. "Shipping & returns" on all of them or "Size guide" in one category. A product shows the tab when it matches at least one rule; in its "Product tabs" box you can hide it or write its own text.',
		'Απλά'                                                 => 'Simple',
		'Με παραλλαγές'                                        => 'Variable',
		'Ομαδοποιημένα'                                        => 'Grouped',
		'Εξωτερικά'                                            => 'External',
		'Downloadable'                                         => 'Downloadable',
		'Εικονικά'                                             => 'Virtual',
		'Νέα κοινή καρτέλα'                                    => 'New shared tab',
		'Τίτλος (ελληνικά)'                                    => 'Title (Greek)',
		'Τίτλος (αγγλικά)'                                     => 'Title (English)',
		'Είδος'                                                => 'Type',
		'Κείμενο'                                              => 'Text',
		'Βίντεο'                                               => 'Video',
		'Εμφανίζεται'                                          => 'Shown',
		'Διαγραφή'                                             => 'Delete',
		'Διεύθυνση YouTube ή Vimeo (για είδος «Βίντεο»)'       => 'YouTube or Vimeo address (for type "Video")',
		'Κείμενο (ελληνικά)'                                   => 'Text (Greek)',
		'Κείμενο (αγγλικά, κενό = το ελληνικό)'                => 'Text (English, empty = the Greek one)',
		'Σε ποια προϊόντα'                                     => 'Which products',
		'Σε όλα τα προϊόντα'                                   => 'All products',
		'Κατηγορίες (και οι υποκατηγορίες τους)'               => 'Categories (and their subcategories)',
		'Ετικέτες'                                             => 'Tags',
		'Είδη προϊόντων'                                       => 'Product types',
		'Εκτός από τα προϊόντα (IDs, χωρισμένα με κόμμα)'      => 'Except the products (IDs, comma-separated)',
		'Στο Ctrl / Cmd + κλικ διαλέγεις πολλά. Χωρίς κανέναν κανόνα η καρτέλα δεν φαίνεται πουθενά.' => 'Ctrl / Cmd + click selects several. Without any rule the tab shows nowhere.',
		'Εισαγωγή από το Custom Product Tabs for WooCommerce'  => 'Import from Custom Product Tabs for WooCommerce',
		'Διαβάζει μόνο τις καρτέλες του παλιού plugin (meta wb_custom_tabs): τίποτα δεν αλλάζει ή σβήνεται εκεί. Το [wb_cpt_youtube_embed_shortcode] γίνεται καρτέλα βίντεο (θέση 30 = trailer, 31 = παρουσίαση)· κάθε άλλο περιεχόμενο μεταφέρεται όπως είναι, με τα shortcodes του. Δεύτερη εισαγωγή δεν διπλασιάζει τίποτα.' => 'Only reads the old plugin\'s tabs (meta wb_custom_tabs): nothing is changed or deleted there. [wb_cpt_youtube_embed_shortcode] becomes a video tab (position 30 = trailer, 31 = presentation); any other content is moved over as it is, with its shortcodes. A second import duplicates nothing.',
		'Προϊόντα με δεδομένα του παλιού plugin: %1$d (με καρτέλες: %2$d)' => 'Products with data of the old plugin: %1$d (with tabs: %2$d)',
		'Καρτέλες: %1$d (βίντεο: %2$d, κείμενο: %3$d)'         => 'Tabs: %1$d (videos: %2$d, text: %3$d)',
		'Με [book_preview]: %d. Όταν το Book Preview δείχνει δική του καρτέλα, αυτές κρύβονται για να μη φαίνεται δύο φορές.' => 'With [book_preview]: %d. When Book Preview shows its own tab, these are hidden so it does not show twice.',
		'Δεν μεταφέρονται: %d (κενές ή κοινές καρτέλες του παλιού plugin).' => 'Not moved over: %d (empty tabs or global tabs of the old plugin).',
		'Ήδη εισηγμένα προϊόντα: %d'                           => 'Products already imported: %d',
		'Αναίρεση εισαγωγής'                                   => 'Undo import',
		'Η αναίρεση αφαιρεί μόνο τις καρτέλες που έφερε η εισαγωγή· όσες πρόσθεσες εσύ μένουν.' => 'Undo removes only the tabs the import brought; the ones you added stay.',
		'Σειρά: λειτουργία δοκιμής → εισαγωγή → έλεγχος μερικών προϊόντων → ενεργό για όλους → απενεργοποίηση του παλιού plugin.' => 'Order: test mode → import → check a few products → on for everyone → deactivate the old plugin.',
		'Backup ρυθμίσεων'                                     => 'Settings backup',
		'Εξαγωγή ρυθμίσεων (JSON)'                             => 'Export settings (JSON)',
		'Εισαγωγή ρυθμίσεων (JSON)'                            => 'Import settings (JSON)',
		'Περιέχει τις ρυθμίσεις και τις κοινές καρτέλες. Οι καρτέλες κάθε προϊόντος μένουν στο προϊόν (και στο backup του site).' => 'Contains the settings and the shared tabs. Each product\'s tabs stay with the product (and in the site backup).',
		'Το YouTube ID του βίντεο ενός ρόλου (trailer, presentation): πρώτα οι καρτέλες του προϊόντος, μετά όσες μοιράζονται οι άλλες μορφές του έργου. Κενό όταν δεν υπάρχει.' => 'The YouTube ID of a role\'s video (trailer, presentation): first the product\'s tabs, then those shared by the other formats of its work. Empty when there is none.',
		'Οι καρτέλες του προϊόντος όπως είναι αποθηκευμένες.'  => 'The product\'s tabs as stored.',
		'Φίλτρο στις καρτέλες του προϊόντος πριν φτάσουν στο WooCommerce.' => 'Filter on the product\'s tabs before they reach WooCommerce.',
		'Φίλτρο στο HTML μιας καρτέλας κειμένου.'              => 'Filter on the HTML of a text tab.',
		'Φίλτρο στα κείμενα για τους επισκέπτες (κείμενο, κλειδί, γλώσσα).' => 'Filter on the visitor texts (text, key, language).',
		'Το ίδιο κουμπί βίντεο σε οποιαδήποτε σελίδα.'         => 'The same video button on any page.',
		'Σταθερά στο wp-config.php που σταματά κάθε αλλαγή στις καρτέλες.' => 'Constant in wp-config.php that stops every change to the tabs.',
		'Άγνωστη ενέργεια.'                                    => 'Unknown action.',
		'Καθαρίστηκε η page cache: %s.'                        => 'Page cache cleared: %s.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                          => 'Settings saved.',
		'Η λειτουργία είναι «Ανενεργό»: οι καρτέλες δεν φαίνονται ακόμα στο κατάστημα.' => 'The mode is "Off": the tabs do not show in the store yet.',
		'Οι κοινές καρτέλες αποθηκεύτηκαν.'                    => 'Shared tabs saved.',
		'Μια καρτέλα δεν αποθηκεύτηκε: θέλει ελληνικό τίτλο και κείμενο (ή διεύθυνση βίντεο).' => 'A tab was not saved: it needs a Greek title and a text (or a video address).',
		'Η καρτέλα «%s» δεν έχει κανόνα και δεν φαίνεται σε κανένα προϊόν.' => 'The tab "%s" has no rule and shows on no product.',
		'Κρατήθηκαν μόνο οι πρώτες 30 κοινές καρτέλες.'        => 'Only the first 30 shared tabs were kept.',
		'Δεν βρέθηκε τίποτα καινούργιο για εισαγωγή.'          => 'Nothing new was found to import.',
		'Εισήχθησαν %1$d καρτέλες σε %2$d προϊόντα. Έλεγξε μερικά σε λειτουργία δοκιμής πριν απενεργοποιήσεις το παλιό plugin.' => 'Imported %1$d tabs into %2$d products. Check a few in test mode before you deactivate the old plugin.',
		'Η εισαγωγή αναιρέθηκε σε %d προϊόντα.'                => 'The import was undone on %d products.',
		'Το αρχείο είναι πολύ μεγάλο.'                         => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                          => 'No JSON file was selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                     => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Product Tabs).' => 'Invalid backup file (the Product Tabs settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: κρατήθηκαν %1$d από %2$d κοινές καρτέλες.' => 'The import was completed PARTIALLY: %1$d of %2$d shared tabs were kept.',
		'Η εισαγωγή ολοκληρώθηκε.'                             => 'The import is complete.',
		'Made with ❤ by %s'                                    => 'Made with ❤ by %s',
		'More plugins at'                                      => 'More plugins at',
		'♡ Στήριξε το Noxpress'                       => '♡ Support Noxpress',
		'Noxpress Dashboard'                                   => 'Noxpress Dashboard',
		'Καρτέλες προϊόντος'                                   => 'Product tabs',
		'Να αφαιρεθεί αυτή η καρτέλα;'                         => 'Remove this tab?',
		'Το Product Tabs είναι ανενεργό: οι καρτέλες αποθηκεύονται, αλλά δεν φαίνονται ακόμα στο κατάστημα.' => 'Product Tabs is off: tabs are saved, but they do not show in the store yet.',
		'Η σειρά ακολουθεί τη θέση: Περιγραφή 10, Πρόσθετες πληροφορίες 20, Κριτικές 30. Μικρότερος αριθμός = πιο αριστερά.' => 'The order follows the position: Description 10, Additional information 20, Reviews 30. A lower number = further left.',
		'+ Νέα καρτέλα'                                        => '+ New tab',
		'Κοινές καρτέλες σε αυτό το προϊόν'                    => 'Shared tabs on this product',
		'ανενεργή'                                             => 'inactive',
		'Να μη φαίνεται σε αυτό το προϊόν'                     => 'Do not show on this product',
		'Δικό του κείμενο αντί για το κοινό (κενό = το κοινό)' => 'Own text instead of the shared one (empty = the shared one)',
		'Τίτλος'                                               => 'Title',
		'Και στις άλλες μορφές του έργου'                      => 'Also on the other formats of the work',
		'Αφαίρεση'                                             => 'Remove',
		'Διεύθυνση YouTube ή Vimeo'                            => 'YouTube or Vimeo address',
		'Ρόλος'                                                => 'Role',
		'— Κανένας —'                                          => '— None —',
		'Trailer'                                              => 'Trailer',
		'Παρουσίαση'                                           => 'Presentation',
		'Κενός τίτλος = ο τίτλος του ρόλου από τις ρυθμίσεις. Το Home Sections διαλέγει το trailer και την παρουσίαση από εδώ.' => 'Empty title = the role\'s title from the settings. Home Sections picks the trailer and the presentation from here.',
	);

	public static function init(): void {
		add_filter( 'gettext_product-tabs', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_product-tabs filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'product-tabs' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
