<?php
/**
 * HMS_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Visitors see no msgid of ours: the [products] markers print nothing, and
 * the texts of [hs_video] and [hs_stats] are store settings (Bible §14.17,
 * HMS_Settings).
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class HMS_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Home Sections χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Home Sections requires WooCommerce to be active — the plugin stays inactive until it is activated.',

		'Home Sections'                                             => 'Home Sections',
		'Ενότητες αρχικής'                                          => 'Home sections',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Marker classes για το shortcode [products] του WooCommerce: γράφεις ένα class στο shortcode και η ενότητα αφήνει έξω προϊόντα, κατηγορίες ή προπαραγγελίες, ή κρατά μόνο προϊόντα με μια λέξη στον τίτλο. Τα προϊόντα δεν αλλάζουν.' => 'Marker classes for WooCommerce\'s [products] shortcode: write a class in the shortcode and the section leaves out products, categories or pre-orders, or keeps only products with a word in their title. Products are not changed.',
		'Το HMS_DISABLE είναι ενεργό στο wp-config.php: κανένας marker δεν ισχύει.' => 'HMS_DISABLE is on in wp-config.php: no marker applies.',
		'Έτοιμοι markers'                                           => 'Built-in markers',
		'Δουλεύουν χωρίς ρύθμιση. Βάλε όσους θέλεις στο class του shortcode, χωρισμένους με κενό· οι συνθήκες τους ισχύουν όλες μαζί.' => 'They work without any setup. Put as many as you like in the shortcode\'s class, separated by spaces; all their conditions apply together.',
		'Αφήνει έξω το προϊόν με αυτό το ID. Μπαίνει όσες φορές θέλεις (π.χ. το προϊόν που δείχνει ήδη το hero).' => 'Leaves out the product with this ID. Use it as many times as you like (e.g. the product the hero already shows).',
		'Αφήνει έξω τα προϊόντα μιας κατηγορίας και των υποκατηγοριών της, με το slug της. Μπαίνει όσες φορές θέλεις.' => 'Leaves out the products of a category and its subcategories, by its slug. Use it as many times as you like.',
		'Αφήνει έξω τις προπαραγγελίες (PRENA και Pre-Orders).'     => 'Leaves out pre-orders (PRENA and Pre-Orders).',
		'Κρατά μόνο προϊόντα με αυτή τη λέξη στον τίτλο, χωρίς διάκριση πεζών και κεφαλαίων (π.χ. hs-title-t-shirt βρίσκει «T-Shirt»). Μία λέξη, χωρίς κενά· για φράση με κενά φτιάξε preset.' => 'Keeps only products with this word in their title, ignoring case (e.g. hs-title-t-shirt finds "T-Shirt"). One word, no spaces; for a phrase with spaces, create a preset.',

		'Πρόθεμα alias'                                             => 'Alias prefix',
		'Πρόθεμα'                                                   => 'Prefix',
		'Προαιρετικό. Με πρόθεμα «glk», οι έτοιμοι markers δουλεύουν και ως glk-exclude-{ID}, glk-exclude-cat-{slug}, glk-no-preorders και glk-title-{λέξη}, ώστε να μένουν ίδια τα classes σελίδων που υπάρχουν ήδη. Μόνο πεζά λατινικά και αριθμοί, έως 20.' => 'Optional. With the prefix "glk", the built-in markers also work as glk-exclude-{ID}, glk-exclude-cat-{slug}, glk-no-preorders and glk-title-{word}, so the classes of existing pages can stay as they are. Lowercase Latin letters and digits only, up to 20.',
		'Presets'                                                   => 'Presets',
		'Ένα preset είναι ένας marker με δικό σου όνομα. Γράψε το όνομα στο class του shortcode και ισχύουν όλες οι συνθήκες του.' => 'A preset is a marker with a name of your own. Write the name in the shortcode\'s class and all its conditions apply.',
		'Νέο preset'                                                => 'New preset',
		'Όνομα (class)'                                             => 'Name (class)',
		'Πεζά λατινικά, αριθμοί και παύλες, 3 έως 60 χαρακτήρες. Δεν ξεκινά με «hs-».' => 'Lowercase Latin letters, digits and hyphens, 3 to 60 characters. It does not start with "hs-".',
		'Χωρίς τις κατηγορίες'                                      => 'Without the categories',
		'Και οι υποκατηγορίες τους. Ctrl/Cmd + κλικ για πολλές.'    => 'Their subcategories too. Ctrl/Cmd + click to pick several.',
		'Χωρίς τις προπαραγγελίες'                                  => 'Without pre-orders',
		'Ο τίτλος περιέχει'                                         => 'The title contains',
		'Χωρίς τα προϊόντα (IDs)'                                   => 'Without the products (IDs)',
		'Διαγραφή'                                                  => 'Delete',
		'Αποθήκευση'                                                => 'Save',

		'Shortcode βίντεο'                                          => 'Video shortcode',
		'Το [hs_video] δείχνει το νεότερο δημοσιευμένο προϊόν που έχει βίντεο στην καρτέλα του (Custom Product Tabs for WooCommerce): εικόνα και κουμπί αναπαραγωγής, και ο player του YouTube φορτώνει μόνο με το πάτημα. Χωρίς αποτέλεσμα δεν τυπώνει τίποτα.' => '[hs_video] shows the newest published product that has a video in its tab (Custom Product Tabs for WooCommerce): a poster and a play button, and the YouTube player loads only on click. With no result it prints nothing.',
		'trailer (προεπιλογή) ή presentation: η καρτέλα βίντεο του προϊόντος (θέση 30 ή 31).' => 'trailer (default) or presentation: the product\'s video tab (position 30 or 31).',
		'Ένα συγκεκριμένο προϊόν αντί για το νεότερο.'              => 'A specific product instead of the newest one.',
		'IDs προϊόντων που μένουν έξω, χωρισμένα με κόμμα.'         => 'Product IDs to leave out, comma-separated.',
		'Μόνο προϊόντα αυτών των κατηγοριών (slugs, με κόμμα).'     => 'Only products of these categories (slugs, comma-separated).',
		'no: χωρίς τις προπαραγγελίες (PRENA και Pre-Orders).'      => 'no: without pre-orders (PRENA and Pre-Orders).',
		'Άλλος τίτλος από τον τίτλο των ρυθμίσεων· κενό = χωρίς τίτλο.' => 'A heading other than the one in the settings; empty = no heading.',
		'Κείμενα βίντεο'                                            => 'Video texts',
		'Τα κείμενα που βλέπει ο επισκέπτης. Κενό ελληνικό κείμενο επιστρέφει στην προεπιλογή· κενό αγγλικό δείχνει το ελληνικό.' => 'The texts visitors see. An empty Greek text goes back to the default; an empty English text shows the Greek one.',
		'Γλώσσα'                                                    => 'Language',
		'Αυτόματα (γλώσσα του site)'                                => 'Automatic (site language)',
		'Τίτλος: trailer'                                           => 'Heading: trailer',
		'Τίτλος: παρουσίαση'                                        => 'Heading: presentation',
		'Όνομα player: trailer ({name})'                            => 'Player name: trailer ({name})',
		'Όνομα player: παρουσίαση ({name})'                         => 'Player name: presentation ({name})',
		'Ετικέτα κουμπιού ({title})'                                => 'Button label ({title})',
		'Σύνδεσμος προς το προϊόν'                                  => 'Link to the product',

		'Δοκιμή shortcode'                                          => 'Shortcode test',
		'Επικόλλησε ένα shortcode [products …] για να δεις ποια προϊόντα επιστρέφει και πώς διαβάζεται κάθε class, ή ένα [hs_video …] για να δεις ποιο προϊόν και ποιο βίντεο διαλέγει. Δεν αλλάζει τίποτα.' => 'Paste a [products …] shortcode to see which products it returns and how each class is read, or an [hs_video …] one to see which product and video it picks. Nothing is changed.',
		'Δοκιμή'                                                    => 'Test',
		'Classes'                                                   => 'Classes',
		'Το shortcode δεν έχει class: κανένας marker δεν ισχύει.'   => 'The shortcode has no class: no marker applies.',
		'Προϊόντα'                                                  => 'Products',
		'Η πρώτη σελίδα δείχνει %1$d από τα %2$d προϊόντα που βρίσκει το query (εδώ έως 20).' => 'The first page shows %1$d of the %2$d products the query finds (up to 20 here).',
		'Γράψε ένα μόνο shortcode της μορφής [products …] ή [hs_video …].' => 'Enter a single shortcode of the form [products …] or [hs_video …].',
		'Η δοκιμή απέτυχε.'                                         => 'The test failed.',
		'Attributes'                                                => 'Attributes',
		'Άκυρος τύπος: γράψε trailer ή presentation.'               => 'Invalid type: write trailer or presentation.',
		'μαζί με τις προπαραγγελίες'                                => 'pre-orders included',
		'Αποτέλεσμα'                                                => 'Result',
		'Κανένα προϊόν με βίντεο: το shortcode δεν τυπώνει τίποτα.' => 'No product with a video: the shortcode prints nothing.',
		'βίντεο'                                                    => 'video',
		'Το WooCommerce δεν έχει το shortcode [products].'          => 'WooCommerce does not have the [products] shortcode.',
		'Η δοκιμή απέτυχε: το WooCommerce δεν μπόρεσε να φτιάξει το query αυτού του shortcode.' => 'The test failed: WooCommerce could not build the query of this shortcode.',
		'Δεν υπάρχει προϊόν #%d: δεν αφήνει έξω τίποτα.'            => 'There is no product #%d: it leaves nothing out.',
		'Αφήνει έξω το προϊόν #%1$d «%2$s».'                        => 'Leaves out product #%1$d "%2$s".',
		'Δεν υπάρχει κατηγορία με slug «%s»: δεν αφήνει έξω τίποτα.' => 'There is no category with the slug "%s": it leaves nothing out.',
		'Αφήνει έξω την κατηγορία «%s» και τις υποκατηγορίες της.'  => 'Leaves out the category "%s" and its subcategories.',
		'Αφήνει έξω τις προπαραγγελίες.'                            => 'Leaves out pre-orders.',
		'Μόνο προϊόντα με «%s» στον τίτλο.'                         => 'Only products with "%s" in their title.',
		'Δεν είναι marker: μένει απλό CSS class.'                   => 'Not a marker: it stays a plain CSS class.',
		'χωρίς τις κατηγορίες %s'                                   => 'without the categories %s',
		'χωρίς τις προπαραγγελίες'                                  => 'without pre-orders',
		'μόνο με «%s» στον τίτλο'                                   => 'only with "%s" in the title',
		'χωρίς τα προϊόντα %s'                                      => 'without the products %s',
		'Preset: %s.'                                               => 'Preset: %s.',

		'Για προγραμματιστές'                                       => 'For developers',
		'Φίλτρο στα meta keys των προπαραγγελιών (meta key => τιμή). Προεπιλογή: _simple_preorder = yes (PRENA) και _por_on = yes (Pre-Orders).' => 'Filter on the pre-order meta keys (meta key => value). Default: _simple_preorder = yes (PRENA) and _por_on = yes (Pre-Orders).',
		'Σταθερά στο wp-config.php που σταματά κάθε marker, κάθε [hs_video] και κάθε [hs_stats].' => 'Constant in wp-config.php that stops every marker, every [hs_video] and every [hs_stats].',
		'Φίλτρο στην πηγή των βίντεο: meta_key (προεπιλογή wb_custom_tabs) και positions, η θέση της καρτέλας ανά τύπο (προεπιλογή trailer 30, presentation 31).' => 'Filter on the video source: meta_key (default wb_custom_tabs) and positions, the tab position per type (default trailer 30, presentation 31).',
		'Φίλτρο στα CSS classes του [hs_video]: heading, button, play, meta.' => 'Filter on the CSS classes of [hs_video]: heading, button, play, meta.',
		'Φίλτρο στην ταξινομία με τα πρόσωπα που μπαίνουν μετά το όνομα του προϊόντος (προεπιλογή pa_syggrafeis· κενό = κανένα).' => 'Filter on the taxonomy whose people follow the product name (default pa_syggrafeis; empty = none).',
		'Φίλτρο σε κάθε κείμενο του [hs_video] (κείμενο, κλειδί, γλώσσα), π.χ. για WPML.' => 'Filter on every text of [hs_video] (text, key, language), e.g. for WPML.',
		'Φίλτρο: false για να μη φορτώνεται το script του plugin, όταν η σελίδα έχει δικό της.' => 'Filter: false to skip the plugin\'s script when the page has its own.',
		'Φίλτρο σε κάθε κείμενο του [hs_stats] (κείμενο, μετρητής, μέρος, γλώσσα), π.χ. για WPML.' => 'Filter on every text of [hs_stats] (text, counter, part, language), e.g. for WPML.',
		'Φίλτρο: false για να μη φορτώνεται το CSS του [hs_stats], όταν η σελίδα έχει δικό της.' => 'Filter: false to skip the CSS of [hs_stats] when the page has its own.',
		'Φίλτρο: false για να μη φορτώνεται το script του [hs_stats] (χωρίς ζωντανή ενημέρωση και μέτρημα).' => 'Filter: false to skip the script of [hs_stats] (no live update and no count-up).',
		'Οι αριθμοί που δείχνει το [hs_stats], μόνο για ανάγνωση, χωρίς cache. Από εδώ τους παίρνει το script.' => 'The numbers [hs_stats] shows, read-only, not cached. The script takes them from here.',
		'Η μέτρηση στο παρασκήνιο: ένα λεπτό μετά από κάθε αλλαγή, και κάθε 12 ώρες.' => 'The background count: one minute after every change, and every 12 hours.',

		'Shortcode μετρητών'                                        => 'Stats shortcode',
		'Διαθέσιμα προϊόντα'                                        => 'Available products',
		'Ολοκληρωμένες παραγγελίες'                                 => 'Completed orders',
		'Αγοραστές'                                                 => 'Buyers',
		'Ποιοι μετρητές και με ποια σειρά: products, orders, buyers, groups (όλες οι ομάδες κατηγοριών) ή το κλειδί μιας ομάδας (με κόμμα). Προεπιλογή: products, orders, buyers.' => 'Which counters, in which order: products, orders, buyers, groups (every category group) or the key of one group (comma-separated). Default: products, orders, buyers.',
		'small: μικρότερη σειρά, π.χ. κάτω από τους μετρητές του καταστήματος.' => 'small: a smaller row, e.g. under the store counters.',
		'Τίτλος H2 πάνω από τους μετρητές· κενό = χωρίς τίτλο.'     => 'H2 heading above the counters; empty = no heading.',
		'Επιπλέον CSS classes για την ενότητα.'                     => 'Extra CSS classes for the section.',
		'Το [hs_stats] δείχνει πόσα προϊόντα είναι διαθέσιμα (δημοσιευμένα, ορατά, σε απόθεμα ή σε προπαραγγελία), πόσες παραγγελίες έχουν ολοκληρωθεί και πόσοι διαφορετικοί πελάτες τις έκαναν. Η μέτρηση γίνεται στο παρασκήνιο ένα λεπτό μετά από κάθε αλλαγή και κάθε 12 ώρες· η σελίδα ζητά τους τρέχοντες αριθμούς και τους μετράει από το 0 όταν φανούν, ακόμα κι αν μένει στην page cache.' => '[hs_stats] shows how many products are available (published, visible, in stock or on backorder), how many orders are completed and how many different customers placed them. The count runs in the background one minute after every change and every 12 hours; the page asks for the current numbers and counts them up from 0 when they come into view, even while it stays in the page cache.',
		'Δεν υπάρχει ακόμα μέτρηση: το shortcode δεν τυπώνει τίποτα μέχρι την πρώτη.' => 'No count yet: the shortcode prints nothing until the first one.',
		'Μέτρηση'                                                   => 'Count',
		'Εμφανίζεται'                                               => 'Shown',
		'Τελευταία μέτρηση: %s.'                                    => 'Last count: %s.',
		'Μέτρησε τώρα'                                              => 'Count now',
		'Κείμενα μετρητών'                                          => 'Stats texts',
		'Πρόθεμα και λεζάντα κάθε μετρητή, όπως τα βλέπει ο επισκέπτης. Με στρογγύλεμα, ο αριθμός είναι το μεγαλύτερο πολλαπλάσιο κάτω από τη μέτρηση (605 με βήμα 10 → 600), γι’ αυτό ταιριάζει με πρόθεμα «Πάνω από». Κενή ελληνική λεζάντα επιστρέφει στην προεπιλογή· κενό αγγλικό δείχνει το ελληνικό.' => 'Prefix and label of each counter, as visitors see them. With rounding, the number is the largest multiple below the count (605 with step 10 → 600), so it goes with a prefix such as "Over". An empty Greek label returns to the default; an empty English one shows the Greek one.',
		'Στρογγύλεμα'                                               => 'Rounding',
		'Λεζάντα'                                                   => 'Label',
		'Ακριβής αριθμός'                                           => 'Exact number',
		'Όνομα ενότητας (για αναγνώστες οθόνης, όταν δεν υπάρχει τίτλος)' => 'Section name (for screen readers, when there is no heading)',
		'Η μέτρηση δεν έγινε.'                                      => 'The count did not run.',
		'Μετρήθηκαν %1$s προϊόντα, %2$s ολοκληρωμένες παραγγελίες και %3$s αγοραστές.' => 'Counted %1$s products, %2$s completed orders and %3$s buyers.',
		'Ομάδες κατηγοριών'                                         => 'Category groups',
		'Κάθε ομάδα μετράει τα διαθέσιμα προϊόντα στις κατηγορίες της και στις υποκατηγορίες τους, το καθένα μία φορά, με ακριβή αριθμό. Φαίνονται με show="groups" ή με το κλειδί τους. Η μέτρηση γίνεται αμέσως μετά την αποθήκευση.' => 'Each group counts the available products in its categories and their subcategories, each product once, as an exact number. They show with show="groups" or with their key. They are counted right after saving.',
		'Κλειδί'                                                    => 'Key',
		'Κατηγορίες'                                                => 'Categories',
		'Κλειδί: πεζά λατινικά, αριθμοί και παύλες, έως 30, όχι products, orders, buyers ή groups. Ομάδα χωρίς κατηγορία ή ελληνική λεζάντα δεν κρατιέται. Έως 12 ομάδες. Ctrl/Cmd + κλικ για πολλές κατηγορίες.' => 'Key: lowercase Latin letters, digits and dashes, up to 30, not products, orders, buyers or groups. A group without a category or a Greek label is not kept. Up to 12 groups. Ctrl/Cmd + click for several categories.',
		'Δεν κρατήθηκαν ομάδες με άκυρο κλειδί, χωρίς κατηγορία ή χωρίς ελληνική λεζάντα: %s.' => 'Groups with an invalid key, without a category or without a Greek label were not kept: %s.',
		'Δύο ομάδες είχαν το ίδιο κλειδί ή ήταν πάνω από 12: κρατήθηκαν οι πρώτες.' => 'Two groups had the same key, or there were more than 12: the first ones were kept.',

		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Το πρόθεμα δεν κρατήθηκε: μόνο πεζά λατινικά και αριθμοί, έως 20, που ξεκινούν με γράμμα, και όχι «hs».' => 'The prefix was not kept: lowercase Latin letters and digits only, up to 20, starting with a letter, and not "hs".',
		'Δεν κρατήθηκαν presets με άκυρο όνομα ή όνομα έτοιμου marker: %s.' => 'Presets with an invalid name or the name of a built-in marker were not kept: %s.',
		'Δεν κρατήθηκαν presets χωρίς καμία συνθήκη: %s.'           => 'Presets without any condition were not kept: %s.',
		'Δύο presets είχαν το ίδιο όνομα: κρατήθηκε το πρώτο.'      => 'Two presets had the same name: the first one was kept.',
		'Κρατήθηκαν μόνο τα πρώτα 20 presets.'                      => 'Only the first 20 presets were kept.',
		'Καθαρίστηκε η page cache: %s.'                             => 'The page cache was cleared: %s.',

		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_home-sections', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_home-sections filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'home-sections' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
