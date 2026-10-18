<?php
/**
 * WSH_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts visitors see are store settings with a Greek and an English
 * value (Bible §14.17, WSH_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Wish Shelf χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Wish Shelf requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Λίστα επιθυμιών'                                             => 'Wishlist',
		'Κουμπί λίστας επιθυμιών'                                     => 'Wishlist button',
		'Μετρητής λίστας επιθυμιών'                                   => 'Wishlist counter',
		'Εμφανίζεται στο site για κάθε επισκέπτη, όταν το Wish Shelf είναι ενεργό (Noxpress → Λίστα επιθυμιών).' => 'Shows on the site for each visitor, when Wish Shelf is on (Noxpress → Wishlist).',
		'Όταν προσθέτετε ένα προϊόν στη λίστα επιθυμιών χωρίς να έχετε συνδεθεί, η λίστα αποθηκεύεται μόνο στον browser σας (localStorage) και δεν στέλνεται στο κατάστημα. Όταν είστε συνδεδεμένοι, αποθηκεύουμε στον λογαριασμό σας τα προϊόντα της λίστας, την ημερομηνία και την τιμή τη στιγμή της προσθήκης, για να σας δείχνουμε αν έπεσε η τιμή. Αν κοινοποιήσετε τη λίστα, όποιος έχει τον σύνδεσμο βλέπει τα προϊόντα της, όχι το όνομά σας. Μπορείτε να αδειάσετε τη λίστα ή να σταματήσετε την κοινοποίηση όποτε θέλετε.' => 'When you add a product to the wishlist without signing in, the list is stored only in your browser (localStorage) and is not sent to the store. When you are signed in, we store in your account the products in your list, the date and the price at the time you added them, to show you if the price dropped. If you share the list, anyone with the link sees its products, not your name. You can empty the list or stop sharing it at any time.',
		'Γενικά'                                                      => 'General',
		'Λίστα'                                                       => 'List',
		'Κείμενα'                                                     => 'Texts',
		'Αναφορά'                                                     => 'Report',
		'Εργαλεία'                                                    => 'Tools',
		'Wish Shelf'                                                  => 'Wish Shelf',
		'Ρυθμίσεις'                                                   => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'             => 'You do not have access to this page.',
		'Λίστα επιθυμιών για τους πελάτες: καρδιά στα προϊόντα, σελίδα λίστας, καρτέλα στον λογαριασμό, κοινοποίηση και αναφορά με τα πιο επιθυμητά προϊόντα.' => 'A wishlist for customers: heart on products, list page, account tab, sharing and a report of the most wanted products.',
		'Λειτουργία'                                                  => 'Mode',
		'Το WSH_DISABLE είναι ενεργό στο wp-config.php: η λίστα επιθυμιών δεν εμφανίζεται πουθενά.' => 'WSH_DISABLE is on in wp-config.php: the wishlist does not show anywhere.',
		'Είναι ενεργό και άλλο plugin για λίστα επιθυμιών: %s. Όσο είναι ενεργό, το Wish Shelf μένει σε λειτουργία δοκιμής (μόνο οι διαχειριστές το βλέπουν), ώστε ο επισκέπτης να μη δει δύο κουμπιά. Πέρασε τις λίστες με τα Εργαλεία → Εισαγωγή και απενεργοποίησέ το όταν είσαι έτοιμος.' => 'Another wishlist plugin is active: %s. While it is active, Wish Shelf stays in test mode (only managers see it), so visitors do not see two buttons. Bring the lists over with Tools → Import and deactivate it when you are ready.',
		'Ο πίνακας των λιστών δεν δημιουργήθηκε. Απενεργοποίησε και ενεργοποίησε ξανά το plugin.' => 'The lists table was not created. Deactivate and activate the plugin again.',
		'Ανενεργό'                                                    => 'Off',
		'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: only the store\'s managers see it',
		'Ενεργό για όλους'                                            => 'On for everyone',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Επισκέπτες χωρίς σύνδεση'                                    => 'Guests (not signed in)',
		'Κρατούν λίστα στον browser τους (localStorage, χωρίς cookie)' => 'Keep a list in their browser (localStorage, no cookie)',
		'Πρέπει να συνδεθούν'                                         => 'Must sign in',
		'Η λίστα ενός επισκέπτη δεν στέλνεται ποτέ στο κατάστημα: μένει μόνο σε εκείνη τη συσκευή και σε εκείνον τον browser, και περνά στον λογαριασμό του όταν συνδεθεί. Γι\' αυτό η αναφορά μετρά μόνο τους συνδεδεμένους πελάτες.' => 'A guest\'s list is never sent to the store: it stays only on that device and in that browser, and moves to their account when they sign in. That is why the report counts signed-in customers only.',
		'Κουμπί'                                                      => 'Button',
		'Στις κάρτες προϊόντων (κατάστημα, κατηγορίες, σχετικά)'      => 'On product cards (shop, categories, related)',
		'Θέση στις κάρτες'                                            => 'Position on cards',
		'Πριν από το «Προσθήκη στο καλάθι»'                           => 'Before "Add to cart"',
		'Μετά το «Προσθήκη στο καλάθι»'                               => 'After "Add to cart"',
		'Πάνω στην εικόνα (γωνία, μόνο καρδιά)'                       => 'Over the image (corner, heart only)',
		'Στη σελίδα προϊόντος'                                        => 'On the product page',
		'Θέση στη σελίδα προϊόντος'                                   => 'Position on the product page',
		'Εμφάνιση'                                                    => 'Appearance',
		'Καρδιά και κείμενο'                                          => 'Heart and text',
		'Μόνο καρδιά'                                                 => 'Heart only',
		'Μόνο κείμενο'                                                => 'Text only',
		'Μικρή ειδοποίηση μετά το κλικ, με σύνδεσμο «Προβολή λίστας»' => 'A small notice after the click, with a "View list" link',
		'Σε μεταβλητά προϊόντα, στη σελίδα του προϊόντος αποθηκεύεται η παραλλαγή που έχει επιλέξει ο πελάτης (αλλιώς το προϊόν). Σε block themes το κουμπί μπαίνει δίπλα στα blocks «Product Button» και «Add to Cart»· αλλού χρησιμοποίησε το block «Wishlist button» ή το [nox_wishlist_button].' => 'On variable products, the product page saves the variation the customer chose (otherwise the product). In block themes the button goes next to the "Product Button" and "Add to Cart" blocks; elsewhere use the "Wishlist button" block or [nox_wishlist_button].',
		'Αποθήκευση'                                                  => 'Save',
		'Σελίδα λίστας'                                               => 'List page',
		'Σελίδα'                                                      => 'Page',
		'— Καμία —'                                                   => '— None —',
		'Προβολή'                                                     => 'View',
		'Η σελίδα που επέλεξες δεν είναι δημοσιευμένη.'               => 'The page you chose is not published.',
		'Η σελίδα χρειάζεται το shortcode [nox_wishlist] ή το block «Wishlist». Αν αλλάζεις από άλλο plugin, βάλε το [nox_wishlist] στη θέση του παλιού shortcode στην ίδια σελίδα, ώστε τα μενού να μην αλλάξουν.' => 'The page needs the [nox_wishlist] shortcode or the "Wishlist" block. If you are switching from another plugin, put [nox_wishlist] in place of the old shortcode on the same page, so menus do not change.',
		'Δημιουργία σελίδας'                                          => 'Create page',
		'Εμφάνιση της λίστας'                                         => 'List display',
		'Διάταξη'                                                     => 'Layout',
		'Πίνακας'                                                     => 'Table',
		'Κάρτες του θέματος (όπως το κατάστημα)'                      => 'Theme cards (like the shop)',
		'Στήλες του πίνακα'                                           => 'Table columns',
		'Εικόνα'                                                      => 'Image',
		'Τιμή'                                                        => 'Price',
		'Διαθεσιμότητα'                                               => 'Availability',
		'Ημερομηνία προσθήκης'                                        => 'Date added',
		'Ποσότητα'                                                    => 'Quantity',
		'Κουμπί «Στο καλάθι»'                                         => '"Add to cart" button',
		'Αφαίρεση'                                                    => 'Remove',
		'Ένδειξη «Η τιμή έπεσε κατά …» (συνδεδεμένοι πελάτες)'        => '"The price dropped by …" note (signed-in customers)',
		'Μαζικές ενέργειες: «Όλα στο καλάθι», «Επιλεγμένα στο καλάθι», «Άδειασμα λίστας»' => 'Bulk actions: "All to cart", "Selected to cart", "Empty list"',
		'Αφαίρεση από τη λίστα όταν ένα προϊόν μπαίνει στο καλάθι από τη λίστα' => 'Remove from the list when a product goes to the cart from the list',
		'Μεταβλητά προϊόντα χωρίς επιλεγμένη παραλλαγή, πακέτα και άλλοι τύποι που θέλουν επιλογές δείχνουν «Επιλογές» και ανοίγουν τη σελίδα του προϊόντος.' => 'Variable products without a chosen variation, bundles and other types that need options show "Options" and open the product page.',
		'Λογαριασμός και μετρητής'                                    => 'Account and counter',
		'Καρτέλα «Λίστα επιθυμιών» στο «Ο λογαριασμός μου»'           => '"Wishlist" tab in "My account"',
		'Διεύθυνση της καρτέλας'                                      => 'Tab address',
		'Μετρητής στο μενού'                                          => 'Counter in the menu',
		'— Κανένα —'                                                  => '— None —',
		'Ο μετρητής φαίνεται και όταν η λίστα είναι άδεια'            => 'The counter also shows when the list is empty',
		'Σε block themes ο μετρητής μπαίνει με το block «Wishlist counter» στο header· αλλού με το [nox_wishlist_count].' => 'In block themes the counter goes in the header with the "Wishlist counter" block; elsewhere with [nox_wishlist_count].',
		'Κοινοποίηση'                                                 => 'Sharing',
		'Σύνδεσμος κοινοποίησης στη λίστα'                            => 'Share link on the list',
		'Επισκέπτες: σύνδεσμος με τα προϊόντα μέσα στη διεύθυνση. Συνδεδεμένοι πελάτες: σύνδεσμος με τυχαίο κλειδί, ιδιωτικός μέχρι να τον φτιάξουν, με «Νέος σύνδεσμος» που ακυρώνει τον παλιό. Το όνομα του πελάτη δεν φαίνεται. Κανένα script κοινωνικών δικτύων: αντιγραφή συνδέσμου και το μενού κοινοποίησης της συσκευής. Οι σελίδες κοινοποίησης έχουν noindex.' => 'Guests: a link with the products in the address. Signed-in customers: a link with a random key, private until they create it, with "New link" that cancels the old one. The customer\'s name does not show. No social network scripts: copy link and the device\'s share menu. Share pages have noindex.',
		'Κουμπί, όταν το προϊόν είναι στη λίστα'                      => 'Button, when the product is in the list',
		'Ειδοποίηση προσθήκης ({product_name})'                       => 'Added notice ({product_name})',
		'Ειδοποίηση αφαίρεσης ({product_name})'                       => 'Removed notice ({product_name})',
		'Σύνδεσμος «Προβολή λίστας»'                                  => '"View list" link',
		'Τίτλος (καρτέλα λογαριασμού, μετρητής)'                      => 'Title (account tab, counter)',
		'Άδεια λίστα'                                                 => 'Empty list',
		'Σύνδεση για επισκέπτες'                                      => 'Sign-in for guests',
		'Πτώση τιμής ({amount})'                                      => 'Price drop ({amount})',
		'Στήλη: προϊόν'                                               => 'Column: product',
		'Στήλη: τιμή'                                                 => 'Column: price',
		'Στήλη: διαθεσιμότητα'                                        => 'Column: availability',
		'Στήλη: ημερομηνία'                                           => 'Column: date',
		'Στήλη: ποσότητα'                                             => 'Column: quantity',
		'Κουμπί «Επιλογές»'                                           => '"Options" button',
		'Αφαίρεση (για screen readers)'                               => 'Remove (for screen readers)',
		'Όλα στο καλάθι'                                              => 'All to cart',
		'Επιλεγμένα στο καλάθι'                                       => 'Selected to cart',
		'Άδειασμα λίστας'                                             => 'Empty list',
		'Επιβεβαίωση αδειάσματος'                                     => 'Empty confirmation',
		'Αντιγραφή συνδέσμου'                                         => 'Copy link',
		'Ο σύνδεσμος αντιγράφηκε'                                     => 'Link copied',
		'Νέος σύνδεσμος'                                              => 'New link',
		'Διακοπή κοινοποίησης'                                        => 'Stop sharing',
		'Προϊόντα που δεν μπήκαν στο καλάθι'                          => 'Products that did not go to the cart',
		'Τίτλος κοινής λίστας'                                        => 'Shared list title',
		'Κείμενα για τον πελάτη'                                      => 'Customer texts',
		'Γλώσσα κειμένων'                                             => 'Text language',
		'Αυτόματα (γλώσσα του site)'                                  => 'Automatic (site language)',
		'Ελληνικά'                                                    => 'Greek',
		'English'                                                     => 'English',
		'Κείμενο'                                                     => 'Text',
		'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό. Τα {product_name} και {amount} γεμίζουν με το όνομα του προϊόντος και το ποσό. Τα μηνύματα του καλαθιού και της διαθεσιμότητας είναι του WooCommerce.' => 'Empty Greek text = the default. Empty English = shows the Greek. {product_name} and {amount} are filled with the product name and the amount. Cart and availability messages are WooCommerce\'s.',
		'Τι θέλουν οι πελάτες'                                        => 'What customers want',
		'Πελάτες με λίστα'                                            => 'Customers with a list',
		'Προϊόντα σε λίστες'                                          => 'Products in lists',
		'Μετρούν μόνο οι συνδεδεμένοι πελάτες: η λίστα ενός επισκέπτη μένει στον browser του. Η στήλη «Αγοράστηκαν από λίστα» μετρά γραμμές πληρωμένων παραγγελιών που μπήκαν στο καλάθι από μια λίστα, και των επισκεπτών.' => 'Only signed-in customers count: a guest\'s list stays in their browser. The "Bought from a list" column counts paid order lines that went to the cart from a list, guests included.',
		'Κανένα προϊόν σε λίστα ακόμα.'                               => 'No product in a list yet.',
		'Προϊόν'                                                      => 'Product',
		'SKU'                                                         => 'SKU',
		'Σε λίστες'                                                   => 'In lists',
		'Αγοράστηκαν από λίστα'                                       => 'Bought from a list',
		'Απόθεμα'                                                     => 'Stock',
		'Σε απόθεμα'                                                  => 'In stock',
		'Εξαντλημένο'                                                 => 'Out of stock',
		'Excel ελληνικά (; και κόμμα)'                                => 'Excel Greek (; and comma)',
		'Τυπικό CSV (, και τελεία)'                                   => 'Standard CSV (, and dot)',
		'Εξαγωγή CSV'                                                 => 'Export CSV',
		'Εισαγωγή από WishSuite'                                      => 'Import from WishSuite',
		'Αντιγράφει τις λίστες των συνδεδεμένων πελατών από το WishSuite, χωρίς διπλά και χωρίς να αλλάξει τα δεδομένα του. Οι λίστες επισκεπτών του WishSuite είναι στους browsers τους και δεν μεταφέρονται.' => 'Copies the signed-in customers\' lists from WishSuite, without duplicates and without changing its data. WishSuite\'s guest lists are in the guests\' browsers and cannot be moved.',
		'Δεν βρέθηκε πίνακας του WishSuite σε αυτό το site.'          => 'No WishSuite table was found on this site.',
		'Βρέθηκαν %1$d προϊόντα σε λίστες %2$d πελατών.'              => 'Found %1$d products in the lists of %2$d customers.',
		'Εισαγωγή'                                                    => 'Import',
		'Backup ρυθμίσεων'                                            => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)'                                   => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                    => 'Export settings (JSON)',
		'Το backup έχει μόνο τις ρυθμίσεις και τα κείμενα, όχι τις λίστες των πελατών.' => 'The backup holds only the settings and texts, not the customers\' lists.',
		'Διαγραφή του plugin'                                         => 'Deleting the plugin',
		'Διαγραφή των λιστών των πελατών όταν διαγραφεί το plugin'    => 'Delete the customers\' lists when the plugin is deleted',
		'Χωρίς το τικ οι λίστες και οι σύνδεσμοι κοινοποίησης μένουν, ώστε μια νέα εγκατάσταση να τις βρει. Οι ρυθμίσεις και οι μετρητές των προϊόντων φεύγουν πάντα.' => 'Without the tick the lists and share links stay, so a new install finds them. Settings and product counters always go.',
		'Για προγραμματιστές'                                         => 'For developers',
		'Η λίστα ενός συνδεδεμένου πελάτη: προϊόν, παραλλαγή, ημερομηνία, τιμή κατά την προσθήκη.' => 'A signed-in customer\'s list: product, variation, date, price when added.',
		'Σε πόσες λίστες πελατών είναι ένα προϊόν.'                   => 'In how many customers\' lists a product is.',
		'Actions όταν αλλάζει η λίστα ενός συνδεδεμένου πελάτη ( $user_id, $product_id, $variation_id ).' => 'Actions when a signed-in customer\'s list changes ( $user_id, $product_id, $variation_id ).',
		'Φίλτρα για τα κείμενα του πελάτη (π.χ. WPML) και το όριο προϊόντων ανά λίστα (200).' => 'Filters for the customer texts (e.g. WPML) and the item limit per list (200).',
		'Shortcodes για τη λίστα, το κουμπί και τον μετρητή (και ως blocks).' => 'Shortcodes for the list, the button and the counter (also as blocks).',
		'Σταθερά στο wp-config.php: σταματά κάθε αλλαγή στο front end.' => 'Constant in wp-config.php: stops every front-end change.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                                 => 'Settings saved.',
		'Η εισαγωγή ολοκληρώθηκε: %1$d προϊόντα μπήκαν σε λίστες, %2$d παραλείφθηκαν (υπήρχαν ήδη ή δεν υπάρχουν πια).' => 'Import completed: %1$d products went into lists, %2$d were skipped (already there or no longer exist).',
		'Άγνωστη ενέργεια.'                                           => 'Unknown action.',
		'Η σελίδα δεν δημιουργήθηκε.'                                 => 'The page was not created.',
		'Η σελίδα δημιουργήθηκε και επιλέχθηκε.'                      => 'The page was created and selected.',
		'Το αρχείο είναι πολύ μεγάλο.'                                => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                                 => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                            => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Wish Shelf).' => 'Invalid backup file (the Wish Shelf settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.'                                    => 'Import completed.',
		'Made with ❤ by %s'                                           => 'Made with ❤ by %s',
		'More plugins at'                                             => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                              => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                          => 'Noxpress Dashboard',
		'Σε λίστες επιθυμιών'                                         => 'In wishlists',
		'ID'                                                          => 'ID',
	);

	public static function init(): void {
		add_filter( 'gettext_wish-shelf', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_wish-shelf filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'wish-shelf' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
