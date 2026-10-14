<?php
/**
 * PBN_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts visitors see are store settings with a Greek and an English
 * value (Bible §14.17, PBN_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Πακέτο'                                                      => 'Bundle',
		'Περιεχόμενο πακέτου'                                         => 'Bundle contents',
		'Ένα πακέτο έχει έως %d προϊόντα.'                            => 'A bundle holds up to %d products.',
		'Κάτι πήγε στραβά. Ξαναδοκίμασε.'                             => 'Something went wrong. Try again.',
		'Προσθήκη προϊόντος'                                          => 'Add product',
		'Αναζήτηση προϊόντος…'                                        => 'Search for a product…',
		'Προσθήκη'                                                    => 'Add',
		'Το πακέτο δεν έχει ακόμη προϊόντα. Πρόσθεσε απλά προϊόντα, παραλλαγές ή μεταβλητά προϊόντα (ο πελάτης διαλέγει παραλλαγή).' => 'The bundle has no products yet. Add simple products, variations or variable products (the customer picks a variation).',
		'Έκπτωση'                                                     => 'Discount',
		'Ποσοστό (%) επί της αξίας χωριστά'                           => 'Percentage (%) of the separate value',
		'Τελική τιμή πακέτου'                                         => 'Final bundle price',
		'Ποσοστό ή τιμή'                                              => 'Percentage or price',
		'Για ποσοστό: 0 έως 99. Για τελική τιμή: η τιμή με τη φορολογία όπως τη γράφεις στα προϊόντα. Όταν μια επιλογή του πελάτη κοστίζει περισσότερο, η διαφορά προστίθεται.' => 'For a percentage: 0 to 99. For a final price: the price with tax as you type it on products. When a customer\'s choice costs more, the difference is added.',
		'Μέγιστα πακέτα'                                              => 'Most bundles',
		'Χωρίς όριο'                                                  => 'No limit',
		'Προαιρετικό, για περιορισμένες εκδόσεις: όταν πουληθούν τόσα πακέτα, το πακέτο γίνεται «Εξαντλημένο».' => 'Optional, for limited editions: when this many bundles are sold, the bundle becomes "Out of stock".',
		'Ποσότητα'                                                    => 'Quantity',
		'Αφαίρεση'                                                    => 'Remove',
		'Ο πελάτης διαλέγει μία από τις επιλεγμένες παραλλαγές:'      => 'The customer picks one of the ticked variations:',
		'έχει «Οποιοδήποτε», δεν μπαίνει'                             => 'has "Any", cannot be added',
		'χωρίς τιμή'                                                  => 'no price',
		'εξαντλημένη'                                                 => 'out of stock',
		'Η σύνοψη εμφανίζεται όταν το πακέτο έχει προϊόντα.'          => 'The summary shows when the bundle has products.',
		'«%s» δεν είναι δημοσιευμένο: το πακέτο δεν πουλιέται.'       => '"%s" is not published: the bundle is not for sale.',
		'«%s» δεν έχει καμία παραλλαγή που να μπορεί να διαλέξει ο πελάτης.' => '"%s" has no variation the customer can pick.',
		'«%s» δεν έχει τιμή: το πακέτο δεν πουλιέται.'                => '"%s" has no price: the bundle is not for sale.',
		'«%s» είναι εξαντλημένο.'                                     => '"%s" is out of stock.',
		'Αξία χωριστά'                                                => 'Separate value',
		'Τιμή πακέτου'                                                => 'Bundle price',
		'Η τιμή που έγραψες είναι μεγαλύτερη από την αξία χωριστά: το πακέτο πουλιέται στην αξία χωριστά.' => 'The price you typed is higher than the separate value: the bundle sells at the separate value.',
		'Δεν υπολογίζεται (κάποιο προϊόν δεν έχει τιμή).'             => 'Cannot be calculated (a product has no price).',
		'Χωρίς όριο (το απόθεμα δεν παρακολουθείται)'                 => 'No limit (stock is not tracked)',
		'Διαθέσιμα πακέτα'                                            => 'Bundles available',
		'Πωλήσεις πακέτου'                                            => 'Bundle sales',
		'Τιμή, απόθεμα και βάρος του πακέτου υπολογίζονται από τα προϊόντα και ενημερώνονται μόνα τους όταν αλλάζουν εκείνα.' => 'The bundle\'s price, stock and weight come from its products and update by themselves when those change.',
		'Product Bundles: %d γραμμές του πακέτου δεν αποθηκεύτηκαν (προϊόν που δεν υπάρχει, δεν μπαίνει σε πακέτο, παραλλαγή με «Οποιοδήποτε» ή μεταβλητό χωρίς καμία επιλεγμένη παραλλαγή).' => 'Product Bundles: %d rows of the bundle were not saved (a product that does not exist, cannot go in a bundle, a variation with "Any" or a variable product with no ticked variation).',
		'Δεν επιτρέπεται.'                                            => 'Not allowed.',
		'Αυτό το προϊόν δεν μπαίνει σε πακέτο (μόνο απλά, μεταβλητά και παραλλαγές με τιμή σε κάθε χαρακτηριστικό).' => 'This product cannot go in a bundle (only simple products, variable products and variations with a value on every attribute).',
		'Γενικά'                                                      => 'General',
		'Κείμενα'                                                     => 'Texts',
		'Εργαλεία'                                                    => 'Tools',
		'Πακέτα'                                                      => 'Bundles',
		'Μετατροπή'                                                   => 'Convert',
		'Product Bundles'                                             => 'Product Bundles',
		'Product Bundles — Ρυθμίσεις'                                 => 'Product Bundles — Settings',
		'PB Ρυθμίσεις'                                                => 'PB Settings',
		'Ρυθμίσεις'                                                   => 'Settings',
		'Είναι ενεργό και άλλο plugin για πακέτα: %s. Όσο είναι ενεργό, το Product Bundles μένει σε λειτουργία δοκιμής (μόνο οι διαχειριστές αγοράζουν πακέτα). Απενεργοποίησέ το όταν είσαι έτοιμος.' => 'Another bundles plugin is active: %s. While it is active, Product Bundles stays in test mode (only managers can buy bundles). Deactivate it when you are ready.',
		'Πακέτα προϊόντων: κάθε προϊόν μπαίνει στην παραγγελία με το δικό του απόθεμα, ΦΠΑ και ποσοστά, και η έκπτωση του πακέτου φαίνεται ως έκπτωση.' => 'Product bundles: every product goes into the order with its own stock, VAT and shares, and the bundle discount shows as a discount.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'             => 'You do not have access to this page.',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος αγοράζουν πακέτα.' => 'Test mode: only the store\'s managers can buy bundles.',
		'Τα πακέτα δεν πουλιούνται ακόμη (λειτουργία «Ανενεργό» στις PB Ρυθμίσεις).' => 'Bundles are not for sale yet (mode "Off" in PB Settings).',
		'Νέο πακέτο'                                                  => 'New bundle',
		'Νέο προϊόν με τύπο «Πακέτο» και τα προϊόντα του στην καρτέλα «Περιεχόμενο πακέτου».' => 'A new product with the type "Bundle" and its products in the "Bundle contents" tab.',
		'Δεν υπάρχουν ακόμη πακέτα.'                                  => 'There are no bundles yet.',
		'Προϊόντα'                                                    => 'Products',
		'Τιμή'                                                        => 'Price',
		'Διαθέσιμα'                                                   => 'Available',
		'Πωλήσεις'                                                    => 'Sales',
		'Προβλήματα'                                                  => 'Problems',
		'Χωρίς προϊόντα'                                              => 'No products',
		'Κάποιο προϊόν δεν έχει τιμή ή επιλογή'                       => 'A product has no price or choice',
		'Εξαντλημένο'                                                 => 'Out of stock',
		'Το προϊόν #%d δεν υπάρχει'                                   => 'Product #%d does not exist',
		'«%s» δεν είναι δημοσιευμένο'                                 => '"%s" is not published',
		'Ξαναϋπολογισμός'                                             => 'Rebuild',
		'Τιμή, απόθεμα και βάρος κάθε πακέτου ενημερώνονται μόνα τους όταν αλλάζει ένα προϊόν του. Αυτό το κουμπί τα ξαναϋπολογίζει όλα (π.χ. μετά από εισαγωγή προϊόντων με CSV ή αλλαγές απευθείας στη βάση).' => 'Each bundle\'s price, stock and weight update by themselves when one of its products changes. This button rebuilds them all (e.g. after a CSV product import or changes made directly in the database).',
		'Ξαναϋπολογισμός όλων'                                        => 'Rebuild all',
		'Μετατροπή υπάρχοντος προϊόντος σε πακέτο'                    => 'Convert an existing product into a bundle',
		'Το ίδιο προϊόν αλλάζει τύπο σε «Πακέτο»: μένουν id, διεύθυνση, φωτογραφίες, κριτικές και SEO. Πριν από την αλλαγή γράφεται αντίγραφο του παλιού τύπου, των τιμών, του αποθέματος και των παραλλαγών, και η αλλαγή αναιρείται με ένα κλικ. Οι παραλλαγές ενός μεταβλητού προϊόντος πάνε στον κάδο, γιατί το μέγεθος το διαλέγει ο πελάτης από το προϊόν μέσα στο πακέτο. Πάρε backup της βάσης πριν από τη μετατροπή στο live site.' => 'The same product changes its type to "Bundle": the id, URL, images, reviews and SEO stay. Before the change a backup of the old type, prices, stock and variations is written, and the change can be undone with one click. The variations of a variable product go to the trash, because the customer picks the size from the product inside the bundle. Take a database backup before converting on the live site.',
		'Προεπισκόπηση'                                               => 'Preview',
		'Μετατροπές με αντίγραφο'                                     => 'Conversions with a backup',
		'Καμία ακόμη.'                                                => 'None yet.',
		'Προϊόν'                                                      => 'Product',
		'Παλιός τύπος'                                                => 'Old type',
		'Ημερομηνία'                                                  => 'Date',
		'%d παραλλαγές στον κάδο'                                     => '%d variations in the trash',
		'Να αναιρεθεί η μετατροπή; Το προϊόν παίρνει πίσω τον παλιό τύπο, τις τιμές και τις παραλλαγές του.' => 'Undo the conversion? The product gets back its old type, prices and variations.',
		'Αναίρεση'                                                    => 'Undo',
		'Να σβηστεί το αντίγραφο; Μετά η μετατροπή δεν αναιρείται.'   => 'Delete the backup? After that the conversion cannot be undone.',
		'Κράτα την αλλαγή και σβήσε το αντίγραφο'                     => 'Keep the change and delete the backup',
		'Προεπισκόπηση: %s'                                           => 'Preview: %s',
		'Μετατρέπονται μόνο απλά και μεταβλητά προϊόντα.'             => 'Only simple and variable products can be converted.',
		'Αυτό το προϊόν έχει ήδη αντίγραφο από προηγούμενη μετατροπή. Αναίρεσέ την πρώτα.' => 'This product already has a backup from an earlier conversion. Undo that first.',
		'Τώρα: τύπος %1$s, τιμή %2$s.'                                => 'Now: type %1$s, price %2$s.',
		'Οι %d παραλλαγές του θα πάνε στον κάδο (με αντίγραφο).'      => 'Its %d variations will go to the trash (with a backup).',
		'Προϊόντα που βρέθηκαν στους συνδέσμους της περιγραφής. Τσέκαρε όσα ανήκουν στο πακέτο. Τα υπόλοιπα (ή άλλα) τα προσθέτεις μετά στην καρτέλα «Περιεχόμενο πακέτου» του προϊόντος.' => 'Products found in the links of the description. Tick the ones that belong to the bundle. You add the rest (or others) later in the product\'s "Bundle contents" tab.',
		'Δεν βρέθηκαν σύνδεσμοι προς προϊόντα. Μπορείς να μετατρέψεις με ένα προϊόν από την αναζήτηση και να συμπληρώσεις τα υπόλοιπα μετά.' => 'No links to products were found. You can convert with one product from the search and fill in the rest later.',
		'(ο πελάτης διαλέγει παραλλαγή)'                              => '(the customer picks a variation)',
		'Ένα ακόμη προϊόν (προαιρετικό)…'                             => 'One more product (optional)…',
		'Ποσοστό'                                                     => 'Percentage',
		'Τελική τιμή'                                                 => 'Final price',
		'Αξία χωριστά (φθηνότερες επιλογές): %1$s. Τωρινή τιμή του προϊόντος: %2$s.' => 'Separate value (cheapest choices): %1$s. Current price of the product: %2$s.',
		'Έχω backup της βάσης και θέλω να μετατρέψω αυτό το προϊόν.'  => 'I have a database backup and want to convert this product.',
		'Μετατροπή σε πακέτο'                                         => 'Convert into a bundle',
		'Λειτουργία'                                                  => 'Mode',
		'Το PBN_DISABLE είναι ενεργό στο wp-config.php: τα πακέτα δεν πουλιούνται και η σελίδα τους δεν δείχνει τη φόρμα. Τα πακέτα που είναι ήδη σε καλάθια μένουν σωστά.' => 'PBN_DISABLE is on in wp-config.php: bundles are not for sale and their page does not show the form. Bundles already in carts stay correct.',
		'Ανενεργό: τα πακέτα δεν αγοράζονται'                         => 'Off: bundles cannot be bought',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος αγοράζουν πακέτα' => 'Test mode: only the store\'s managers can buy bundles',
		'Ενεργό για όλους'                                            => 'On for everyone',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Αν απενεργοποιήσεις το plugin, τα πακέτα γίνονται «Εξαντλημένο» ώστε να μην πουληθούν χωρίς τα προϊόντα τους· με την ενεργοποίηση ξαναϋπολογίζονται.' => 'If you deactivate the plugin, bundles become "Out of stock" so they are never sold without their products; activation rebuilds them.',
		'Προεπιλεγμένη έκπτωση νέων πακέτων (%)'                      => 'Default discount of new bundles (%)',
		'Κουπόνια στα προϊόντα ενός πακέτου'                          => 'Coupons on the products of a bundle',
		'Δεν ισχύουν (εκτός από κουπόνια 100 %, π.χ. δωρεάν αντίτυπα)' => 'Do not apply (except 100 % coupons, e.g. free copies)',
		'Ισχύουν, πάνω στην τιμή του πακέτου'                         => 'Apply, on the bundle price',
		'Ένα ποσοστιαίο κουπόνι πάνω σε πακέτο δίνει διπλή έκπτωση· γι\' αυτό η προεπιλογή είναι «Δεν ισχύουν».' => 'A percentage coupon on a bundle gives a double discount; that is why the default is "Do not apply".',
		'Σελίδα πακέτου'                                              => 'Bundle page',
		'Λίστα με τα προϊόντα του πακέτου (εικόνα, όνομα, τιμή)'      => 'List of the bundle\'s products (image, name, price)',
		'Γραμμή «Χωριστά … · Εξοικονομείς …»'                         => 'Line "Separately … · You save …"',
		'Αποθήκευση'                                                  => 'Save',
		'Τίτλος της λίστας προϊόντων'                                 => 'Title of the product list',
		'Εξοικονόμηση (%1$s = αξία χωριστά, %2$s = ποσό, %3$s = ποσοστό)' => 'Savings (%1$s = separate value, %2$s = amount, %3$s = percentage)',
		'Σημείωση σε κάθε γραμμή καλαθιού και παραγγελίας («Πακέτο: …»)' => 'Note on every cart and order line ("Bundle: …")',
		'Επιλογή παραλλαγής'                                          => 'Variation choice',
		'Κουμπί στο κατάστημα όταν το πακέτο έχει επιλογές'           => 'Shop button when the bundle has choices',
		'Πριν από την τιμή όταν οι επιλογές αλλάζουν την τιμή'        => 'Before the price when choices change the price',
		'Αφαίρεση ολόκληρου πακέτου από το καλάθι (%s = πακέτο)'      => 'Whole bundle removed from the cart (%s = bundle)',
		'Πακέτο που δεν είναι πια διαθέσιμο (%s = πακέτο)'            => 'Bundle that is no longer available (%s = bundle)',
		'Αλλαγή ποσότητας σε προϊόν πακέτου'                          => 'Quantity change on a bundle product',
		'Κείμενα για τον πελάτη'                                      => 'Texts for the customer',
		'Γλώσσα κειμένων'                                             => 'Text language',
		'Αυτόματα (γλώσσα του site)'                                  => 'Automatic (site language)',
		'Ελληνικά'                                                    => 'Greek',
		'English'                                                     => 'English',
		'Κείμενο'                                                     => 'Text',
		'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό. Η σημείωση «Πακέτο» γράφεται στη γραμμή της παραγγελίας τη στιγμή της αγοράς, στη γλώσσα του πελάτη.' => 'Empty Greek text = the default. Empty English = shows the Greek. The "Bundle" note is written on the order line at the time of purchase, in the customer\'s language.',
		'Backup'                                                      => 'Backup',
		'Εισαγωγή (JSON)'                                             => 'Import (JSON)',
		'Εξαγωγή (JSON)'                                              => 'Export (JSON)',
		'Το backup έχει τις ρυθμίσεις και το περιεχόμενο και την έκπτωση κάθε πακέτου. Η εισαγωγή ξαναγράφει το περιεχόμενο μόνο σε πακέτα που υπάρχουν στο site με το ίδιο id (ή το ίδιο SKU), με τον ίδιο έλεγχο όπως στην οθόνη προϊόντος.' => 'The backup holds the settings and the contents and discount of every bundle. The import rewrites the contents only on bundles that exist on the site with the same id (or the same SKU), with the same check as on the product screen.',
		'Διαγραφή του plugin'                                         => 'Deleting the plugin',
		'Διαγραφή του περιεχομένου και της έκπτωσης των πακέτων (και των αντιγράφων μετατροπής) όταν διαγραφεί το plugin' => 'Delete the contents and discount of bundles (and the conversion backups) when the plugin is deleted',
		'Χωρίς το τικ μένουν, ώστε μια νέα εγκατάσταση να τα βρει. Τα ίδια τα πακέτα (προϊόντα) και οι παραγγελίες δεν σβήνονται ποτέ· οι γραμμές παραγγελιών κρατούν τη σημείωση «Πακέτο: …». Οι ρυθμίσεις, ο δείκτης και οι εργασίες φεύγουν πάντα.' => 'Without the tick they stay, so a new install finds them. The bundles themselves (products) and orders are never deleted; order lines keep the "Bundle: …" note. Settings, the index and jobs always go.',
		'Για προγραμματιστές'                                         => 'For developers',
		'Για μια γραμμή παραγγελίας: το πακέτο από το οποίο ήρθε (id, όνομα, ομάδα, πλήθος πακέτων, ποσότητα ανά πακέτο, τιμή χωριστά, μερίδιο) ή null.' => 'For an order line: the bundle it came from (id, name, group, number of bundles, quantity per bundle, separate price, share) or null.',
		'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).'               => 'Filter for the customer texts (e.g. WPML).',
		'Action που τρέχει όταν ξαναϋπολογίζονται τιμή ή απόθεμα πακέτων (ids).' => 'Action that runs when the price or stock of bundles is rebuilt (ids).',
		'Κρυφό meta στις γραμμές παραγγελίας που ήρθαν από πακέτο.'   => 'Hidden meta on order lines that came from a bundle.',
		'Σταθερά στο wp-config.php: σταματά τις πωλήσεις πακέτων και κάθε αλλαγή στο front end.' => 'Constant in wp-config.php: stops the sale of bundles and every front-end change.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                                 => 'Settings saved.',
		'Ξαναϋπολογίστηκαν %d πακέτα.'                                => 'Rebuilt %d bundles.',
		'Ο ξαναϋπολογισμός ξεκίνησε στο παρασκήνιο. Ανανέωσε τη σελίδα σε λίγο.' => 'The rebuild started in the background. Reload the page in a little while.',
		'Το Action Scheduler του WooCommerce δεν είναι διαθέσιμο.'    => 'WooCommerce\'s Action Scheduler is not available.',
		'Η μετατροπή αναιρέθηκε.'                                     => 'The conversion was undone.',
		'Το αντίγραφο σβήστηκε.'                                      => 'The backup was deleted.',
		'Άγνωστη ενέργεια.'                                           => 'Unknown action.',
		'Τσέκαρε την επιβεβαίωση για να γίνει η μετατροπή.'           => 'Tick the confirmation to convert.',
		'Το προϊόν έγινε πακέτο. Έλεγξε το περιεχόμενο στην καρτέλα «Περιεχόμενο πακέτου» του προϊόντος.' => 'The product is now a bundle. Check the contents in the product\'s "Bundle contents" tab.',
		'Το αρχείο είναι πολύ μεγάλο.'                                => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                                 => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                            => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Product Bundles).' => 'Invalid backup file (the Product Bundles settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: ρυθμίσεις και %1$d πακέτα· %2$d πακέτα του αρχείου δεν βρέθηκαν στο site ή δεν ήταν έγκυρα και έμειναν ως είχαν.' => 'Import completed PARTIALLY: settings and %1$d bundles; %2$d bundles of the file were not found on the site or were invalid and were left as they were.',
		'Η εισαγωγή ολοκληρώθηκε: ρυθμίσεις και %d πακέτα.'           => 'Import completed: settings and %d bundles.',
		'Made with ❤ by %s'                                           => 'Made with ❤ by %s',
		'More plugins at'                                             => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                              => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                          => 'Noxpress Dashboard',
		'Διάλεξε τουλάχιστον ένα προϊόν για το πακέτο.'               => 'Pick at least one product for the bundle.',
		'Το αντίγραφο δεν γράφτηκε: δεν άλλαξε τίποτα.'               => 'The backup was not written: nothing changed.',
		'Η μετατροπή δεν ολοκληρώθηκε και αναιρέθηκε.'                => 'The conversion did not complete and was undone.',
		'Δεν υπάρχει αντίγραφο για αυτό το προϊόν.'                   => 'There is no backup for this product.',
		'Το αντίγραφο δεν είναι έγκυρο.'                              => 'The backup is not valid.',
		'Ένα πακέτο δεν μπαίνει ως γραμμή. Χρησιμοποίησε το κουτί «Πακέτα» της παραγγελίας, που προσθέτει τα προϊόντα του με τις τιμές του πακέτου.' => 'A bundle cannot be an order line. Use the order\'s "Bundles" box, which adds its products with the bundle prices.',
		'— Διάλεξε —'                                                 => '— Choose —',
		'Πλήθος πακέτων'                                              => 'Number of bundles',
		'Προσθήκη πακέτου'                                            => 'Add bundle',
		'Προσθέτει τα προϊόντα του πακέτου με τις τιμές του πακέτου. Το απόθεμα αλλάζει όπως σε κάθε γραμμή, όταν η παραγγελία περάσει σε «Σε επεξεργασία» ή «Ολοκληρωμένη».' => 'Adds the bundle\'s products with the bundle prices. Stock changes as for any line, when the order moves to "Processing" or "Completed".',
		'Το πακέτο προστέθηκε.'                                       => 'The bundle was added.',
		'Το πακέτο δεν βρέθηκε.'                                      => 'The bundle was not found.',
		'Το πακέτο δεν έχει τιμή.'                                    => 'The bundle has no price.',
		'Προστέθηκε το πακέτο «%1$s» × %2$d: %3$s'                    => 'Added the bundle "%1$s" × %2$d: %3$s',
		'Το Product Bundles χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Product Bundles requires WooCommerce to be active — the plugin stays inactive until it is activated.',
	);

	public static function init(): void {
		add_filter( 'gettext_product-bundles', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_product-bundles filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'product-bundles' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
