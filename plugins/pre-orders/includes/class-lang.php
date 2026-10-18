<?php
/**
 * POR_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts visitors see are store settings with a Greek and an English
 * value (Bible §14.17, POR_Settings::text()), not msgids. The customer
 * emails are built in the visitor language of the plugin, forced with
 * with_lang() while the email is built.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class POR_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	/** Forced language while an email is built ('' = none). */
	private static $force = '';

	private static $dict = array(
		'Βάλε πρώτα την ημερομηνία κυκλοφορίας.'                      => 'Enter the release date first.',
		'Βάλε και την τιμή προσφοράς: ισχύει από σήμερα μέχρι την προηγούμενη μέρα της κυκλοφορίας.' => 'Also enter the sale price: it applies from today until the day before the release.',
		'Προπαραγγελία'                                               => 'Pre-order',
		'Το προϊόν πουλιέται ως προπαραγγελία μέχρι την ημερομηνία κυκλοφορίας.' => 'The product sells as a pre-order until the release date.',
		'Ημερομηνία κυκλοφορίας'                                      => 'Release date',
		'Κενό = «Σύντομα διαθέσιμο», χωρίς ημερομηνία. Από αυτή τη μέρα το προϊόν πουλιέται κανονικά, χωρίς καμία ρύθμιση.' => 'Empty = «Coming soon», with no date. From this day on the product sells as normal, with nothing to change.',
		'Κείμενο για αυτό το προϊόν'                                  => 'Text for this product',
		'Προαιρετικό. Αντί για το γενικό κείμενο διαθεσιμότητας· το {date} γίνεται η ημερομηνία.' => 'Optional. Instead of the general availability text; {date} becomes the date.',
		'Προσφορά μέχρι την κυκλοφορία'                               => 'Sale until release',
		'Γεμίζει τις ημερομηνίες της προσφοράς του WooCommerce: από σήμερα μέχρι την προηγούμενη μέρα της κυκλοφορίας.' => 'Fills WooCommerce\'s sale dates: from today until the day before the release.',
		'Το προϊόν είναι «Εξαντλημένο»: το WooCommerce δεν το πουλάει. Για προπαραγγελία βάλε «Σε απόθεμα» ή επίτρεψε backorders· για όριο τεμαχίων χρησιμοποίησε τη διαχείριση αποθέματος.' => 'The product is «Out of stock»: WooCommerce does not sell it. For a pre-order set «In stock» or allow backorders; to limit the units use stock management.',
		'και email «Νέα ημερομηνία κυκλοφορίας» στους πελάτες'        => 'and the «New release date» email to the customers',
		'το email είναι ανενεργό στις ρυθμίσεις emails του WooCommerce' => 'the email is disabled in WooCommerce\'s email settings',
		'%1$s: η ημερομηνία κυκλοφορίας «%2$s» δεν είναι έγκυρη μελλοντική ημερομηνία (ΕΕΕΕ-ΜΜ-ΗΗ). Κράτησα την προηγούμενη.' => '%1$s: the release date «%2$s» is not a valid future date (YYYY-MM-DD). The previous one was kept.',
		'Κυκλοφορία'                                                  => 'Release',
		'κυκλοφόρησε'                                                 => 'released',
		'χωρίς ημερομηνία'                                            => 'no date',
		'σε παραλλαγές'                                               => 'in variations',
		'Προπαραγγελία: όλα'                                          => 'Pre-order: all',
		'Σε προπαραγγελία'                                            => 'On pre-order',
		'— Χωρίς αλλαγή —'                                            => '— No change —',
		'Ναι, με αυτή την ημερομηνία'                                 => 'Yes, with this date',
		'Όχι'                                                         => 'No',
		'Μόνο απλά προϊόντα· οι παραλλαγές ρυθμίζονται στη σελίδα του προϊόντος.' => 'Simple products only; variations are set on the product\'s page.',
		'Αν αλλάξεις την ημερομηνία: ενημέρωση %d ανοιχτής παραγγελίας με τη νέα ημερομηνία' => 'If you change the date: update %d open order with the new date',
		'Αν αλλάξεις την ημερομηνία: ενημέρωση %d ανοιχτών παραγγελιών με τη νέα ημερομηνία' => 'If you change the date: update %d open orders with the new date',
		'Επερχόμενες κυκλοφορίες'                                     => 'Upcoming releases',
		'Ρυθμίσεις'                                                   => 'Settings',
		'Κείμενα'                                                     => 'Texts',
		'Εργαλεία'                                                    => 'Tools',
		'Pre-Orders'                                                  => 'Pre-Orders',
		'Προπαραγγελίες'                                              => 'Pre-orders',
		'Pre-Orders: ένα άλλο plugin προπαραγγελιών είναι ενεργό (%s). Το Pre-Orders μένει σε λειτουργία δοκιμής μέχρι να το απενεργοποιήσεις, ώστε να μη δουλεύουν δύο μαζί.' => 'Pre-Orders: another pre-order plugin is active (%s). Pre-Orders stays in test mode until you deactivate it, so the two never run together.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'             => 'You do not have access to this page.',
		'Προπαραγγελίες για προϊόντα που δεν έχουν κυκλοφορήσει ακόμα. Τα προϊόντα σημειώνονται στη σελίδα επεξεργασίας τους ή μαζικά από τη λίστα προϊόντων.' => 'Pre-orders for products that are not released yet. Products are marked on their edit page or in bulk from the products list.',
		'Το POR_DISABLE είναι ενεργό στο wp-config.php: οι επισκέπτες δεν βλέπουν προπαραγγελίες και τα προϊόντα πωλούνται ως κανονικά.' => 'POR_DISABLE is on in wp-config.php: visitors see no pre-orders and the products sell as normal ones.',
		'Η λειτουργία είναι ανενεργή: οι επισκέπτες δεν βλέπουν προπαραγγελίες. Άλλαξέ τη στην καρτέλα Ρυθμίσεις.' => 'The mode is off: visitors see no pre-orders. Change it in the Settings tab.',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος βλέπουν τις προπαραγγελίες.' => 'Test mode: only the store\'s managers see the pre-orders.',
		'Κανένα προϊόν δεν είναι σε προπαραγγελία. Άνοιξε ένα προϊόν, σημείωσε «Προπαραγγελία» στα Γενικά (ή σε μια παραλλαγή) και δώσε ημερομηνία κυκλοφορίας.' => 'No product is on pre-order. Open a product, tick «Pre-order» in General (or in a variation) and give a release date.',
		'Προϊόντα'                                                    => 'Products',
		'Προϊόν'                                                      => 'Product',
		'Μορφή'                                                       => 'Format',
		'Παραγγελίες'                                                 => 'Orders',
		'Τεμάχια'                                                     => 'Units',
		'Έσοδα'                                                       => 'Revenue',
		'CSV'                                                         => 'CSV',
		'Μετρούν οι παραγγελίες «Σε επεξεργασία» και «Σε αναμονή». Τα έσοδα είναι οι γραμμές του προϊόντος με ΦΠΑ, χωρίς μεταφορικά.' => 'Orders «Processing» and «On hold» are counted. Revenue is the product\'s lines including VAT, without shipping.',
		'Μετρήθηκαν οι πρώτες 1000 παραγγελίες ανά προϊόν.'           => 'The first 1000 orders per product were counted.',
		'Φαίνονται τα πρώτα 200 προϊόντα.'                            => 'The first 200 products are shown.',
		'Λίστα αποστολής (CSV)'                                       => 'Shipping list (CSV)',
		'Excel ελληνικά (; και δεκαδικό κόμμα)'                       => 'Excel Greek (; and decimal comma)',
		'Τυπικό (, και δεκαδική τελεία)'                              => 'Standard (, and decimal point)',
		'Λήψη CSV για όλα'                                            => 'Download CSV for all',
		'Μία γραμμή ανά γραμμή παραγγελίας που περιμένει: παραγγελία, ημερομηνία, κατάσταση, πελάτης, email, τηλέφωνο, διεύθυνση αποστολής, προϊόν, τεμάχια, ποσό, κυκλοφορία. Ο σύνδεσμος CSV σε κάθε γραμμή του πίνακα δίνει μόνο εκείνο το προϊόν.' => 'One row per waiting order line: order, date, status, customer, email, phone, shipping address, product, units, amount, release. The CSV link on each table row gives that product only.',
		'Λειτουργία'                                                  => 'Mode',
		'Ανενεργό'                                                    => 'Off',
		'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: only the store\'s managers see it',
		'Ενεργό για όλους'                                            => 'On for everyone',
		'Όσο είναι ανενεργό, τα προϊόντα σε προπαραγγελία πωλούνται ως κανονικά προϊόντα. Η ημέρα κυκλοφορίας και οι εργασίες των παραγγελιών τρέχουν πάντα.' => 'While it is off, the products on pre-order sell as normal products. The release day and the order jobs always run.',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Σήμα «Προπαραγγελία»'                                        => '«Pre-order» badge',
		'Στις κάρτες προϊόντων και στη σελίδα προϊόντος'              => 'On product cards and on the product page',
		'Μόνο στις κάρτες προϊόντων'                                  => 'Only on product cards',
		'Μόνο στη σελίδα προϊόντος'                                   => 'Only on the product page',
		'Πουθενά (μένουν το κουμπί και η ημερομηνία)'                 => 'Nowhere (the button and the date stay)',
		'Το χρώμα αλλάζει από το θέμα με τις μεταβλητές CSS --por-badge-bg και --por-badge-fg.' => 'The theme can change the colour with the CSS variables --por-badge-bg and --por-badge-fg.',
		'Καλάθι με προπαραγγελίες και διαθέσιμα προϊόντα'             => 'Cart with pre-orders and available products',
		'Επιτρέπεται, με σημείωση στο καλάθι και στο ταμείο'          => 'Allowed, with a note in the cart and at checkout',
		'Επιτρέπεται, με προειδοποίηση που προτείνει δύο παραγγελίες' => 'Allowed, with a warning that suggests two orders',
		'Δεν επιτρέπεται: οι προπαραγγελίες αγοράζονται σε ξεχωριστή παραγγελία' => 'Not allowed: pre-orders are bought in a separate order',
		'Η σημείωση και η προειδοποίηση φαίνονται στο κλασικό καλάθι και ταμείο. Στα blocks Καλάθι και Ταμείο κάθε προϊόν σε προπαραγγελία δείχνει την ημερομηνία του, και ο κανόνας «Δεν επιτρέπεται» ισχύει παντού.' => 'The note and the warning show in the classic cart and checkout. In the Cart and Checkout blocks each pre-order product shows its date, and the «Not allowed» rule applies everywhere.',
		'Μορφή ημερομηνίας'                                           => 'Date format',
		'Του καταστήματος (Ρυθμίσεις › Γενικά), π.χ. %s'              => 'The store\'s (Settings › General), e.g. %s',
		'Δική μου'                                                    => 'My own',
		'Δική μου μορφή (PHP)'                                        => 'My own format (PHP)',
		'Emails στους πελάτες'                                        => 'Emails to customers',
		'Δύο emails του WooCommerce, κλειστά μέχρι να τα ανοίξεις. Θέμα, επικεφαλίδα και πρόσθετο κείμενο ρυθμίζονται εκεί.' => 'Two WooCommerce emails, off until you turn them on. Subject, heading and additional content are set there.',
		'«Κυκλοφόρησε»: την ημέρα κυκλοφορίας'                        => '«Released»: on the release day',
		'«Νέα ημερομηνία κυκλοφορίας»: όταν αλλάζεις την ημερομηνία και ζητάς ενημέρωση' => '«New release date»: when you change the date and ask to update',
		'ενεργό'                                                      => 'on',
		'ανενεργό'                                                    => 'off',
		'Ρυθμίσεις email'                                             => 'Email settings',
		'Αποθήκευση'                                                  => 'Save',
		'Σήμα στις κάρτες και στη σελίδα προϊόντος'                   => 'Badge on product cards and on the product page',
		'Κουμπί αντί για «Προσθήκη στο καλάθι»'                       => 'Button instead of «Add to cart»',
		'Διαθεσιμότητα με ημερομηνία ({date} = η ημερομηνία)'         => 'Availability with a date ({date} = the date)',
		'Διαθεσιμότητα χωρίς ημερομηνία'                              => 'Availability without a date',
		'Ετικέτα στη γραμμή του καλαθιού και της παραγγελίας'         => 'Label on the cart line and the order line',
		'Μικτό καλάθι: σημείωση («Επιτρέπεται»)'                      => 'Mixed cart: note («Allowed»)',
		'Μικτό καλάθι: προειδοποίηση'                                 => 'Mixed cart: warning',
		'Μικτό καλάθι: σφάλμα («Δεν επιτρέπεται»)'                    => 'Mixed cart: error («Not allowed»)',
		'Κείμενα για τον πελάτη'                                      => 'Texts for the customer',
		'Γλώσσα κειμένων'                                             => 'Text language',
		'Αυτόματα (γλώσσα του site)'                                  => 'Automatic (site language)',
		'Ελληνικά'                                                    => 'Greek',
		'English'                                                     => 'English',
		'Η ίδια γλώσσα ισχύει και για τα δύο emails. Κάθε προϊόν μπορεί να έχει και δικό του κείμενο διαθεσιμότητας στη σελίδα επεξεργασίας του.' => 'The same language applies to both emails. Each product can also have its own availability text on its edit page.',
		'Κείμενο'                                                     => 'Text',
		'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό.' => 'Empty Greek text = the default. Empty English = shows the Greek.',
		'Άλλο plugin προπαραγγελιών'                                  => 'Another pre-order plugin',
		'Όσο είναι ενεργό, το Pre-Orders μένει σε λειτουργία δοκιμής, ακόμα κι αν το έχεις βάλει «Ενεργό για όλους». Σημείωσε τα προϊόντα εδώ, δοκίμασέ τα ως διαχειριστής, και μετά απενεργοποίησε το άλλο plugin. Τα δεδομένα του άλλου plugin δεν αλλάζουν.' => 'While it is active, Pre-Orders stays in test mode, even if you set it to «On for everyone». Mark the products here, test them as a manager, then deactivate the other plugin. The other plugin\'s data is not changed.',
		'Backup ρυθμίσεων'                                            => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)'                                   => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                    => 'Export settings (JSON)',
		'Το backup έχει τις ρυθμίσεις και τα κείμενα. Οι ημερομηνίες των προϊόντων μένουν στα προϊόντα.' => 'The backup holds the settings and the texts. The products\' dates stay on the products.',
		'Δεδομένα καταλόγου'                                          => 'Catalog data',
		'Ξαναχτίσιμο στο παρασκήνιο'                                  => 'Rebuild in the background',
		'Ξαναγράφει τη σειρά ταξινόμησης, τις εργασίες της ημέρας κυκλοφορίας και το αν το κατάστημα έχει προπαραγγελίες, για όλα τα προϊόντα. Χρειάζεται μόνο αν άλλαξες προϊόντα με εισαγωγή CSV ή απευθείας στη βάση.' => 'Rewrites the sort order, the release day jobs and whether the store has pre-orders, for every product. Only needed if you changed products with a CSV import or directly in the database.',
		'Λίστα «Έρχονται σύντομα»'                                    => '«Coming soon» list',
		'ή το block «Προπαραγγελίες» στον επεξεργαστή: τα προϊόντα σε προπαραγγελία με τις κάρτες του θέματος, πρώτα όσα κυκλοφορούν νωρίτερα. Όσο η λειτουργία είναι ανενεργή δεν τυπώνει τίποτα, και στη λειτουργία δοκιμής φαίνεται μόνο στους διαχειριστές.' => 'or the «Pre-orders» block in the editor: the products on pre-order with the theme\'s cards, the earliest release first. While the mode is off it prints nothing, and in test mode only managers see it.',
		'Για προγραμματιστές'                                         => 'For developers',
		'Η προπαραγγελία ενός προϊόντος (active, date, text, και variations για τα μεταβλητά), ή null.' => 'The pre-order of a product (active, date, text, and variations for variable products), or null.',
		'Φίλτρο: αν ένα απλό προϊόν ή μια παραλλαγή είναι τώρα σε προπαραγγελία (false = πουλιέται κανονικά).' => 'Filter: whether a simple product or a variation is on pre-order right now (false = sells as normal).',
		'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).'               => 'Filter for the customer texts (e.g. WPML).',
		'Action την ημέρα κυκλοφορίας, αφού το προϊόν έγινε κανονικό.' => 'Action on the release day, after the product became a normal one.',
		'Το Pre-Orders δίνει την ημερομηνία κυκλοφορίας στο Shipping Notes, ώστε ο χρόνος παράδοσης να μετρά από την κυκλοφορία.' => 'Pre-Orders gives the release date to Shipping Notes, so the delivery time counts from the release.',
		'Άγνωστη ενέργεια.'                                           => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                                 => 'Settings saved.',
		'Ένα άλλο plugin προπαραγγελιών είναι ενεργό: το Pre-Orders μένει σε λειτουργία δοκιμής μέχρι να το απενεργοποιήσεις.' => 'Another pre-order plugin is active: Pre-Orders stays in test mode until you deactivate it.',
		'Η δική σου μορφή ημερομηνίας δεν βγάζει κείμενο.'            => 'Your own date format prints no text.',
		'Το Action Scheduler του WooCommerce δεν είναι διαθέσιμο.'    => 'WooCommerce\'s Action Scheduler is not available.',
		'Το ξαναχτίσιμο ξεκίνησε στο παρασκήνιο.'                     => 'The rebuild started in the background.',
		'Παραγγελία'                                                  => 'Order',
		'Ημερομηνία'                                                  => 'Date',
		'Κατάσταση'                                                   => 'Status',
		'Πελάτης'                                                     => 'Customer',
		'Email'                                                       => 'Email',
		'Τηλέφωνο'                                                    => 'Phone',
		'Διεύθυνση αποστολής'                                         => 'Shipping address',
		'SKU'                                                         => 'SKU',
		'Ποσό'                                                        => 'Amount',
		'Το αρχείο είναι πολύ μεγάλο.'                                => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                                 => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                            => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Pre-Orders).' => 'Invalid backup file (the Pre-Orders settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.'                                    => 'Import complete.',
		'Made with ❤ by %s'                                           => 'Made with ❤ by %s',
		'More plugins at'                                             => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                              => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                          => 'Noxpress Dashboard',
		'Προπαραγγελία: κυκλοφόρησε'                                  => 'Pre-order: released',
		'Στέλνεται στον πελάτη την ημέρα κυκλοφορίας ενός προϊόντος που προπαρήγγειλε, για τις παραγγελίες σε επεξεργασία ή σε αναμονή. Μία φορά ανά παραγγελία και προϊόν.' => 'Sent to the customer on the release day of a product they pre-ordered, for orders that are processing or on hold. Once per order and product.',
		'Κυκλοφόρησε: {product}'                                      => 'Released: {product}',
		'Κυκλοφόρησε!'                                                => 'Released!',
		'Προπαραγγελία: νέα ημερομηνία κυκλοφορίας'                   => 'Pre-order: new release date',
		'Στέλνεται στον πελάτη όταν αλλάζεις την ημερομηνία κυκλοφορίας ενός προϊόντος και επιλέγεις να ενημερωθούν οι ανοιχτές προπαραγγελίες.' => 'Sent to the customer when you change a product\'s release date and choose to update the open pre-orders.',
		'Νέα ημερομηνία κυκλοφορίας για την παραγγελία #{order_number}' => 'New release date for order #{order_number}',
		'Νέα ημερομηνία κυκλοφορίας'                                  => 'New release date',
		'Προπαραγγελίες (Pre-Orders)'                                 => 'Pre-orders (Pre-Orders)',
		'Τα προϊόντα σε προπαραγγελία, με πρώτη την πιο κοντινή κυκλοφορία.' => 'The products on pre-order, the nearest release first.',
		'Πλήθος προϊόντων'                                            => 'Number of products',
		'Στήλες'                                                      => 'Columns',
		'Εμφανίζεται στο site με τις κάρτες προϊόντων του θέματος.'   => 'Shows on the site with the theme\'s product cards.',
		'Pre-Orders: κυκλοφόρησε %1$s (%2$s).'                        => 'Pre-Orders: %1$s released (%2$s).',
		'Pre-Orders: νέα ημερομηνία κυκλοφορίας για %1$s: %2$s → %3$s.' => 'Pre-Orders: new release date for %1$s: %2$s → %3$s.',
		'Όλες οι παραγγελίες'                                         => 'All orders',
		'Με προπαραγγελία'                                            => 'With pre-orders',
		'Το Pre-Orders χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Pre-Orders requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Γεια σας %s,'                                                => 'Hi %s,',
		'Άλλαξε η ημερομηνία κυκλοφορίας για προϊόντα της παραγγελίας σας #%s. Η παραγγελία σας ισχύει κανονικά.' => 'The release date of products in your order #%s has changed. Your order stands as it is.',
		'Δείτε την παραγγελία σας'                                    => 'View your order',
		'Κυκλοφόρησε ό,τι προπαραγγείλατε με την παραγγελία #%s. Θα σας ειδοποιήσουμε ξανά όταν αποσταλεί.' => 'What you pre-ordered with order #%s has been released. We will let you know again when it ships.',
		'Ποσότητα'                                                    => 'Quantity',
	);

	public static function init(): void {
		add_filter( 'gettext_pre-orders', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_pre-orders', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current user (admin screens), or the forced language. */
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
			$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/**
	 * Run $fn with the language forced to $lang ('el' | 'en'), then restore.
	 *
	 * @param string   $lang Language.
	 * @param callable $fn   Callback.
	 * @return mixed The callback's result.
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

	/** gettext_pre-orders filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'pre-orders' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_pre-orders filter (Greek and English share the plural rule). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'pre-orders' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = 1 === (int) $number ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
