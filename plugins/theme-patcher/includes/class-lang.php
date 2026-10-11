<?php
/**
 * TP_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
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

final class TP_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		// Bootstrap / menu / pages.
		'Το Theme Patcher χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Theme Patcher requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Theme Patcher'                                              => 'Theme Patcher',
		'Theme Patcher — Ρυθμίσεις'                                  => 'Theme Patcher — Settings',
		'TP Ρυθμίσεις'                                            => 'TP Settings',
		'Ρυθμίσεις'                                               => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'         => 'You do not have access to this page.',

		// Areas (TP_Areas::builtin labels).
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
		'Κατάσταση'                                               => 'Status',
		'Ενεργό θέμα:'                                            => 'Active theme:',
		'Theme Patcher:'                                             => 'Theme Patcher:',
		'Απενεργοποιημένο από το TP_DISABLE (wp-config.php)'      => 'Disabled by TP_DISABLE (wp-config.php)',
		'Λειτουργία δοκιμής — οι αλλαγές φαίνονται μόνο στους διαχειριστές του καταστήματος' => 'Test mode — changes are visible to shop managers only',
		'Ενεργό για όλους τους επισκέπτες'                        => 'Active for all visitors',
		'Αλλαγή'                                                  => 'Change',
		'Το ενεργό θέμα είναι block theme: οι κάρτες προϊόντων σχεδιάζονται από blocks του WooCommerce και δεν χρειάζονται διόρθωση. Σε αυτό το θέμα ισχύουν μόνο οι ετικέτες τιμής.' => 'The active theme is a block theme: product cards are drawn by WooCommerce blocks and need no fixing. Only the price labels apply with this theme.',
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
		'Πρόσθετο CSS'                                            => 'Additional CSS',
		'Το slot αυτής της περιοχής έχει την κλάση %s.'           => 'The slot of this area has the class %s.',
		'Αφαίρεση αυτής της περιοχής'                             => 'Remove this area',

		// Main page — text fixes.
		'Αντικατάσταση με'                                        => 'Replace with',
		'Δικό σου κείμενο'                                        => 'Your own text',
		'Τίτλος σελίδας του WooCommerce'                          => 'WooCommerce page title',

		// Main page — probe.
		'3. Σάρωση σελίδας'                                       => '3. Page probe',
		'Ανοίγει μια σελίδα του site σε νέα καρτέλα με τις τρέχουσες ρυθμίσεις εφαρμοσμένες μόνο για σένα (ακόμη κι αν το Theme Patcher είναι ανενεργό) και καταγράφει τους βρόχους προϊόντων που βρήκε. Μετά ανανέωσε αυτή τη σελίδα για να δεις τα αποτελέσματα.' => 'Opens a page of the site in a new tab with the current settings applied for you only (even when Theme Patcher is off) and records the product loops it found. Then reload this page to see the results.',
		'Σάρωση σελίδας'                                          => 'Probe page',
		'Δεν υπάρχει ακόμη αποτέλεσμα σάρωσης.'                   => 'No probe result yet.',
		'Τελευταία σάρωση: %1$s — %2$s'                           => 'Last probe: %1$s — %2$s',
		'Η σάρωση έγινε με άλλο ενεργό θέμα.'                     => 'The probe ran with a different active theme.',
		'Παρουσιάστηκε σφάλμα κατά τη σάρωση: το Theme Patcher σταμάτησε τις αλλαγές για εκείνη τη σελίδα και άφησε το HTML του θέματος όπως ήταν.' => 'An error occurred during the probe: Theme Patcher stopped its changes for that page and left the theme\'s HTML as it was.',
		'Λειτουργία'                                              => 'Mode',
		'Κάρτες'                                                  => 'Cards',
		'Κανονικές'                                               => 'Standard',
		'Με slot'                                                 => 'With slot',
		'Χωρίς θέση'                                              => 'Not placed',
		'Χρόνος (ms)'                                             => 'Time (ms)',
		'Δεν βρέθηκαν βρόχοι προϊόντων σε αυτή τη σελίδα.'        => 'No product loops were found on this page.',
		'«Χωρίς θέση»: το slot δεν τοποθετήθηκε γιατί δεν βρέθηκε το κλείσιμο του στοιχείου μέσα στην κάρτα — άλλαξε το σημείο αναφοράς ή το στοιχείο. Σε περιοχή με Αντικατάσταση οι κάρτες είναι κανονικές.' => '"Not placed": the slot was not inserted because the element\'s closing tag was not found inside the card — change the anchor or the element. In an area set to Replace the cards are standard.',

		// Settings page.
		'Ενεργοποίηση του Theme Patcher στο front end'               => 'Enable Theme Patcher on the front end',
		'Λειτουργία δοκιμής: οι αλλαγές φαίνονται μόνο σε όσους διαχειρίζονται το κατάστημα' => 'Test mode: changes are visible only to users who manage the shop',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Το TP_DISABLE είναι ενεργό: καμία αλλαγή δεν εφαρμόζεται στο front end.' => 'TP_DISABLE is active: no change is applied on the front end.',
		'Αποθήκευση ρυθμίσεων'                                    => 'Save settings',
		'Ρυθμίσεις ενεργού θέματος'                               => 'Active theme settings',
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
		'Το Theme Patcher είναι ακόμη ανενεργό: δοκίμασε με τη σάρωση σελίδας και ενεργοποίησέ το από τις Ρυθμίσεις.' => 'Theme Patcher is still off: test with the page probe and enable it from Settings.',
		'Άγνωστη περιοχή.'                                        => 'Unknown area.',
		'Η περιοχή ενεργοποιήθηκε ξανά με τα τρέχοντα αρχεία του θέματος.' => 'The area is active again with the current theme files.',
		'Η σάρωση δέχεται μόνο σελίδες αυτού του site.'           => 'The probe accepts pages of this site only.',
		'Δεν επιλέχθηκε αρχείο JSON.'                             => 'No JSON file selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                        => 'The file is not valid JSON.',
		'Το αρχείο είναι πολύ μεγάλο.'                            => 'The file is too large.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Theme Patcher).' => 'Invalid backup file (Theme Patcher settings missing).',
		'Μη έγκυρο blob ρυθμίσεων.'                               => 'Invalid settings blob.',
		'Η εισαγωγή ολοκληρώθηκε.'                                => 'Import completed.',
		'Κάποια θέματα παραλείφθηκαν επειδή τα δεδομένα τους δεν ήταν έγκυρα.' => 'Some themes were skipped because their data was not valid.',

		// Tabs.
		'Κάρτες προϊόντων' => 'Product cards',
		'Κείμενα' => 'Texts',
		'Σελίδα' => 'Page',
		'Ρυθμίσεις θέματος' => 'Theme settings',
		'Κατηγορίες' => 'Categories',
		'Έλεγχοι' => 'Checks',

		// Main page, cards tab.
		'Διορθώνει ό,τι κάνει λάθος το θέμα στις κάρτες προϊόντων, στα κείμενα, στις κατηγορίες και στις ρυθμίσεις του. Τα αρχεία του θέματος δεν αλλάζουν ποτέ.' => 'Fixes what the theme gets wrong in product cards, texts, categories and its own settings. Theme files are never modified.',
		'Έγχυση: το slot (έκπτωση / βαθμολογία / τιμή / κουμπί) μπαίνει μέσα στην κάρτα του θέματος, αμέσως μετά το κλείσιμο του στοιχείου που περιέχει τον τίτλο ή την εικόνα. Αντικατάσταση: η περιοχή σχεδιάζεται με το πρότυπο του WooCommerce (κανονικές κάρτες με όλα τα hooks — διορθώνει και σπασμένους βρόχους). Οι κάρτες που είναι ήδη κανονικές δεν αγγίζονται.' => 'Inject: the slot (sale badge / rating / price / button) goes inside the theme\'s card, right after the element that holds the title or image closes. Replace: the area is drawn with the WooCommerce template (standard cards with every hook — also fixes broken loops). Cards that are already standard are left alone.',
		'Ένδειξη έκπτωσης (%)' => 'Sale badge (%)',
		'Φόντο ένδειξης έκπτωσης' => 'Sale badge background',
		'Κείμενο ένδειξης έκπτωσης' => 'Sale badge text',
		'Hooks κάρτας για άλλα plugins' => 'Card hooks for other plugins',
		'Τυπώνει ό,τι προσθέτουν άλλα plugins στην κάρτα (π.χ. δείγματα χρώματος, λίστα επιθυμιών). Τιμή, βαθμολογία και κουμπί του WooCommerce δεν διπλασιάζονται.' => 'Prints what other plugins add to the card (e.g. colour swatches, wishlist). WooCommerce\'s price, rating and button are not duplicated.',
		'Μέγεθος εικόνας κάρτας' => 'Card image size',
		'Συνήθως woocommerce_thumbnail. Λειτουργεί σε περιοχές με Έγχυση.' => 'Usually woocommerce_thumbnail. Works in areas set to Inject.',
		'CSS selector της κάρτας (για τη θέση της έκπτωσης, το hover και τον τίτλο)' => 'Card CSS selector (for the sale badge position, hover and title)',
		'Selector της ετικέτας έκπτωσης του θέματος' => 'Selector of the theme\'s sale tag',
		'Κρύβεται όταν είναι ενεργή η «Ένδειξη έκπτωσης», για να μη φαίνονται δύο.' => 'Hidden while "Sale badge" is on, so two badges never show.',
		'Selector τίτλου' => 'Title selector',
		'Μέγιστες γραμμές τίτλου' => 'Title max lines',
		'Μέγεθος τίτλου (px)' => 'Title size (px)',
		'Ο τίτλος μετριέται μέσα στην κάρτα (selector κάρτας + selector τίτλου). Χωρίς selector κάρτας ισχύει σε όλη τη σελίδα.' => 'The title is matched inside the card (card selector + title selector). Without a card selector it applies to the whole page.',
		'Αλλαγές στη σελίδα' => 'Page changes',
		'Κείμενα που άλλαξαν' => 'Texts replaced',
		'Στοιχεία που αφαιρέθηκαν' => 'Elements removed',
		'Σύνδεσμοι στην ίδια καρτέλα' => 'Links opening in the same tab',
		'Εικόνες κατηγοριών σε μικρότερο μέγεθος' => 'Category images in a smaller size',
		'Εικόνες που πήραν alt' => 'Images given alt text',
		'Σύνδεσμοι και κουμπιά που πήραν όνομα' => 'Links and buttons given a name',
		'Εικόνες κατηγοριών από προϊόν' => 'Category images from a product',
		'Λίστες κατηγοριών που άλλαξαν' => 'Category lists changed',
		'Αρχεία θέματος από τον δίσκο αντί για HTTP' => 'Theme files read from disk instead of HTTP',
		'Εγγραφές ρυθμίσεων θέματος που μπλοκαρίστηκαν' => 'Theme setting writes blocked',

		// Texts tab.
		'Όλο το site' => 'Whole site',
		'Κατάστημα & κατηγορίες' => 'Shop & categories',
		'Σελίδες προϊόντων' => 'Product pages',
		'Κείμενα του θέματος' => 'Theme texts',
		'Πίνακας «κείμενο του θέματος → δικό μου κείμενο». Η αναζήτηση γίνεται ακριβώς όπως είναι στο HTML της σελίδας (και σε κρυφά κείμενα). Όταν η αναζήτηση είναι ολόκληρο στοιχείο, π.χ. <h1>Shop</h1>, αλλάζει μόνο το κείμενό του. Το δικό σου κείμενο δέχεται απλό HTML (συνδέσμους, έντονα). Άδειο δικό σου κείμενο σβήνει το κείμενο του θέματος.' => 'A "theme text → my text" table. The search matches the page HTML exactly (hidden texts included). When the search is a whole element, e.g. <h1>Shop</h1>, only its text changes. Your text accepts simple HTML (links, bold). An empty text of yours removes the theme\'s text.',
		'Κείμενο του θέματος (ακριβώς όπως στο HTML)' => 'Theme text (exactly as in the HTML)',
		'Πού' => 'Where',
		'Άδειασε το κείμενο του θέματος για να διαγράψεις μια γραμμή. Έως %d γραμμές ανά θέμα. Μετά την αποθήκευση εμφανίζονται δύο νέες κενές γραμμές.' => 'Empty the theme text to delete a row. Up to %d rows per theme. Two new empty rows appear after saving.',
		'Αποθήκευση κειμένων' => 'Save texts',
		'Τα κείμενα αποθηκεύτηκαν.' => 'Texts saved.',

		// Page tab.
		'Κανόνες σελίδας' => 'Page rules',
		'Εφαρμόζονται στο τελικό HTML κάθε σελίδας του front end.' => 'Applied to the final HTML of every front-end page.',
		'Οι σύνδεσμοι προς σελίδες αυτού του site ανοίγουν στην ίδια καρτέλα' => 'Links to pages of this site open in the same tab',
		'Αφαιρεί το target="_blank" μόνο από εσωτερικούς συνδέσμους. Οι εξωτερικοί (π.χ. social) μένουν όπως είναι.' => 'Removes target="_blank" from internal links only. External links (e.g. social) stay as they are.',
		'Περιγραφή (alt) στις εικόνες που δεν έχουν' => 'Alt text for images that have none',
		'Εικόνες κατηγοριών: το όνομα της κατηγορίας. Άλλες εικόνες της βιβλιοθήκης: το alt ή ο τίτλος τους.' => 'Category images: the category name. Other media library images: their alt text or title.',
		'Όνομα για αναγνώστες οθόνης σε συνδέσμους και κουμπιά που έχουν μόνο εικονίδιο' => 'Screen reader name for icon-only links and buttons',
		'Το όνομα βγαίνει από το εικονίδιο ή τη διεύθυνση: Facebook, Instagram, καλάθι, αναζήτηση, μενού, επόμενο / προηγούμενο, επιστροφή στην αρχή κ.ά.' => 'The name comes from the icon or the address: Facebook, Instagram, cart, search, menu, next / previous, back to top and more.',
		'Μέγεθος εικόνων κατηγοριών' => 'Category image size',
		'Για θέματα που τυπώνουν την εικόνα κατηγορίας σε πλήρες μέγεθος (π.χ. πλακίδια αρχικής).' => 'For themes that print the category image at full size (e.g. home page tiles).',
		'Αφαίρεση στοιχείων' => 'Remove elements',
		'Μορφή: span.credit_link, .κλάση, #id ή div.κλάση. Πρόσθεσε :empty για να αφαιρείται μόνο όταν είναι άδειο (π.χ. span.product-sale-tag:empty).' => 'Format: span.credit_link, .class, #id or div.class. Add :empty to remove it only when empty (e.g. span.product-sale-tag:empty).',
		'CSS σελίδας' => 'Page CSS',
		'Τυπώνεται στο <head> όλων των σελίδων. Για το λευκό φόντο φωτογραφιών πάνω σε χρωματιστό πλακίδιο: .tile img { mix-blend-mode: multiply; }' => 'Printed in the <head> of every page. For white photo backgrounds on coloured tiles: .tile img { mix-blend-mode: multiply; }',
		'Αποθήκευση κανόνων' => 'Save rules',
		'Οι κανόνες σελίδας αποθηκεύτηκαν.' => 'Page rules saved.',
		'Κάποιοι κανόνες αφαίρεσης δεν έχουν έγκυρη μορφή και αγνοήθηκαν.' => 'Some removal rules are not in a valid format and were ignored.',

		// Theme tab.
		'Αρχείο θέματος' => 'Theme file',
		'Γράφει ρυθμίσεις του θέματος' => 'Writes theme settings',
		'Διαβάζει αρχεία μέσω HTTP' => 'Reads files over HTTP',
		'Ό,τι γράφεται μέσα σε πρότυπο γράφεται σε κάθε προβολή σελίδας και πατάει την επιλογή του Customizer.' => 'Anything written inside a template is written on every page view and overrides the Customizer choice.',
		'Δεν βρέθηκαν αρχεία του θέματος που γράφουν ρυθμίσεις ή διαβάζουν αρχεία μέσω HTTP.' => 'No theme files write settings or read files over HTTP.',
		'Προστασία ρυθμίσεων: οι προβολές σελίδων δεν μπορούν να γράψουν ρυθμίσεις του θέματος' => 'Settings guard: page views cannot write theme settings',
		'Καμία εγγραφή στη βάση από επισκέψεις. Ό,τι αποθηκεύεις στον Customizer μένει όπως το αποθήκευσες. Ο Customizer και το admin δεν επηρεάζονται.' => 'No database writes from visits. What you save in the Customizer stays as you saved it. The Customizer and the admin are not affected.',
		'Ανάγνωση αρχείων του θέματος από τον δίσκο αντί για HTTP' => 'Read theme files from disk instead of HTTP',
		'Μόνο στις γραμμές του θέματος που διαβάζουν δικό του αρχείο (π.χ. SVG) μέσω της διεύθυνσης του site. Το site δεν καλεί πια τον εαυτό του.' => 'Only on theme lines that read the theme\'s own file (e.g. an SVG) through the site\'s address. The site no longer calls itself.',
		'Σταθερές τιμές ρυθμίσεων' => 'Fixed setting values',
		'Η ρύθμιση παίρνει αυτή την τιμή στο front end, ό,τι κι αν γράψει ή διαβάσει το θέμα (π.χ. καθυστέρηση slider σε χιλιοστά του δευτερολέπτου). Η αποθηκευμένη τιμή δεν αλλάζει. Κενό όνομα διαγράφει τη γραμμή.' => 'The setting gets this value on the front end, whatever the theme writes or reads (e.g. slider delay in milliseconds). The stored value does not change. An empty name deletes the row.',
		'Ρύθμιση του θέματος' => 'Theme setting',
		'Αποθηκευμένη τιμή' => 'Stored value',
		'Αποθήκευση' => 'Save',
		'Οι ρυθμίσεις θέματος αποθηκεύτηκαν.' => 'Theme settings saved.',
		'Κάποιες σταθερές τιμές δεν είναι έγκυρες (όνομα με γράμματα, αριθμούς, - και _) και αγνοήθηκαν.' => 'Some fixed values are not valid (names use letters, digits, - and _) and were ignored.',

		// Categories tab.
		'Εικόνες κατηγοριών' => 'Category images',
		'%1$d κατηγορίες: %2$d με δική τους εικόνα, %3$d με εικόνα από προϊόν.' => '%1$d categories: %2$d with their own image, %3$d with an image from a product.',
		'Χωρίς εικόνα: %s' => 'Without an image: %s',
		'Οι κατηγορίες χωρίς εικόνα δείχνουν την εικόνα ενός προϊόντος τους' => 'Categories without an image show the image of one of their products',
		'Οι κατηγορίες με δική τους εικόνα δεν αλλάζουν. Η επιλογή ενημερώνεται όταν αποθηκεύεται προϊόν ή κατηγορία, ποτέ σε προβολή σελίδας.' => 'Categories with their own image do not change. The choice is updated when a product or category is saved, never on a page view.',
		'Ποιο προϊόν' => 'Which product',
		'Το πιο πρόσφατο' => 'The most recent',
		'Το πιο δημοφιλές (πωλήσεις)' => 'The most popular (sales)',
		'Λίστες κατηγοριών του θέματος' => 'Theme category lists',
		'Για αρχεία του θέματος που τυπώνουν κατηγορίες (π.χ. πλακίδια αρχικής): ποιες κατηγορίες εμφανίζονται και με ποια σειρά. Χωρίς επιλεγμένες κατηγορίες, το θέμα κρατά τη δική του λίστα.' => 'For theme files that print categories (e.g. home page tiles): which categories show and in what order. With no category chosen, the theme keeps its own list.',
		'Νέα λίστα' => 'New list',
		'— Καμία —' => '— None —',
		'Χωρίς άδειες κατηγορίες' => 'No empty categories',
		'Κατηγορίες (%d επιλεγμένες)' => 'Categories (%d chosen)',
		'Τσέκαρε όσες θέλεις και γράψε τη σειρά τους (1, 2, 3…).' => 'Tick the ones you want and enter their order (1, 2, 3…).',
		'Επιλογή εικόνων τώρα για όλες τις κατηγορίες' => 'Choose images now for all categories',
		'Επιλέχθηκε εικόνα προϊόντος για %d κατηγορίες.' => 'A product image was chosen for %d categories.',
		'Οι ρυθμίσεις κατηγοριών αποθηκεύτηκαν.' => 'Category settings saved.',

		// Checks tab and notice.
		'Φίλτρα προϊόντων' => 'Product filters',
		'Δεν βρέθηκαν φίλτρα WooCommerce σε μορφή block σε ενεργές περιοχές widgets.' => 'No WooCommerce block filters found in active widget areas.',
		'Περιοχή widgets' => 'Widget area',
		'Blocks' => 'Blocks',
		'Άνοιγμα widgets' => 'Open widgets',
		'Βρέθηκαν φίλτρα WooCommerce σε μορφή block (%s). Σε κλασικά θέματα με δική τους σελίδα καταστήματος συχνά δεν φιλτράρουν τα προϊόντα. Αν δεν δουλεύουν, χρησιμοποίησε τα κλασικά widgets «Φιλτράρισμα προϊόντων κατά τιμή», «… κατά χαρακτηριστικό», «… κατά βαθμολογία» και «Ενεργά φίλτρα προϊόντων».' => 'WooCommerce block filters found (%s). On classic themes with their own shop page they often do not filter the products. If they do not work, use the classic widgets "Filter Products by Price", "… by Attribute", "… by Rating" and "Active Product Filters".',
		'Όλες οι ρυθμίσεις καρτών, κειμένων, σελίδας, θέματος και κατηγοριών αποθηκεύονται ξεχωριστά για κάθε θέμα. Η επαναφορά διαγράφει μόνο εκείνες του ενεργού θέματος.' => 'All card, text, page, theme and category settings are stored per theme. Reset deletes only those of the active theme.',

		// Accessible names (front end).
		'Email' => 'Email',
		'Τηλέφωνο' => 'Phone',
		'Καλάθι' => 'Cart',
		'Ο λογαριασμός μου' => 'My account',
		'Λίστα επιθυμιών' => 'Wishlist',
		'Αναζήτηση' => 'Search',
		'Επιστροφή στην αρχή' => 'Back to top',
		'Προηγούμενο' => 'Previous',
		'Επόμενο' => 'Next',
		'Αναπαραγωγή βίντεο' => 'Play video',
		'Κλείσιμο' => 'Close',
		'Μενού' => 'Menu',
		'Αρχική σελίδα' => 'Home page',

		// Tab: prices.
		'Τιμές' => 'Prices',
		'Ετικέτες τιμής' => 'Price labels',
		'Κείμενο στη θέση της τιμής για δωρεάν προϊόντα και για προϊόντα χωρίς τιμή. Ισχύουν για όλο το κατάστημα, με οποιοδήποτε θέμα. Οι τιμές των προϊόντων, οι παραγγελίες, τα emails, τα τιμολόγια και το schema δεν αλλάζουν.' => 'Text in place of the price for free products and for products without a price. They apply to the whole store, with any theme. Product prices, orders, emails, invoices and the schema do not change.',
		'Δημοσιευμένα προϊόντα: %1$d με τιμή 0, %2$d χωρίς τιμή (από αυτά, %3$d καλύπτονται από κανόνα κατηγορίας).' => 'Published products: %1$d with price 0, %2$d without a price (of those, %3$d are covered by a category rule).',
		'Το καλάθι ή το ταμείο χρησιμοποιεί τα blocks του WooCommerce. Αυτά δείχνουν τις τιμές με JavaScript, οπότε εκεί η τιμή 0 μένει 0,00 €. Οι ετικέτες ισχύουν στις υπόλοιπες σελίδες και στο mini-cart.' => 'The cart or the checkout uses the WooCommerce blocks. They show prices with JavaScript, so a price of 0 stays 0.00 there. The labels apply on the other pages and in the mini cart.',
		'Προϊόντα με τιμή 0' => 'Products with price 0',
		'Κείμενο αντί για 0,00 €' => 'Text instead of 0.00',
		'Σελίδα προϊόντος, λίστες καταστήματος και κατηγοριών, σχετικά προϊόντα, up-sells, cross-sells, widgets. Ένα variable προϊόν αλλάζει μόνο όταν όλες οι παραλλαγές του κοστίζουν 0. Έκπτωση στο 0 δείχνει μόνο το κείμενο.' => 'Product page, shop and category lists, related products, up-sells, cross-sells, widgets. A variable product changes only when all its variations cost 0. A sale price of 0 shows the text only.',
		'Ελληνικά' => 'Greek',
		'English' => 'English',
		'Και στο καλάθι, στο mini-cart και στο ταμείο' => 'Also in the cart, the mini cart and the checkout',
		'Αλλάζουν η τιμή και το υποσύνολο κάθε γραμμής. Το υποσύνολο και το σύνολο της παραγγελίας μένουν αριθμοί (0,00 €).' => 'The price and the subtotal of each line change. The order subtotal and total stay numbers (0.00).',
		'Προϊόντα χωρίς τιμή' => 'Products without a price',
		'Κείμενο εκεί όπου θα ήταν η τιμή' => 'Text where the price would be',
		'Για προϊόντα χωρίς τιμή (δεν αγοράζονται, π.χ. μουσική για ακρόαση). Ισχύει ο πρώτος κανόνας του πίνακα που ταιριάζει σε μια κατηγορία του προϊόντος. Τα υπόλοιπα παίρνουν το γενικό κείμενο. Άδειο γενικό κείμενο = καμία ετικέτα.' => 'For products without a price (they cannot be bought, e.g. music to listen to). The first rule of the table that matches one of the product\'s categories applies. The rest get the general text. Empty general text = no label.',
		'Γενικό κείμενο, Ελληνικά' => 'General text, Greek',
		'Γενικό κείμενο, English' => 'General text, English',
		'Σειρά' => 'Order',
		'Κατηγορία' => 'Category',
		'Και υποκατηγορίες' => 'Subcategories too',
		'Διάλεξε «— Καμία —» για να διαγράψεις έναν κανόνα. Η σειρά ορίζει ποιος κανόνας ισχύει όταν ένα προϊόν είναι σε πολλές κατηγορίες. Έως %d κανόνες. Μετά την αποθήκευση εμφανίζονται δύο νέες κενές γραμμές.' => 'Choose "— None —" to delete a rule. The order decides which rule applies when a product is in several categories. Up to %d rules. Two new empty rows appear after saving.',
		'Γλώσσα των ετικετών' => 'Label language',
		'Αυτόματα (γλώσσα του site)' => 'Automatic (site language)',
		'Αυτόματα: ελληνικά όταν η γλώσσα του site είναι ελληνική, αλλιώς αγγλικά. Κενό αγγλικό κείμενο δείχνει το ελληνικό.' => 'Automatic: Greek when the site language is Greek, English otherwise. An empty English text shows the Greek one.',
		'Αποθήκευση ετικετών' => 'Save labels',
		'Οι ετικέτες τιμής αποθηκεύτηκαν.' => 'The price labels were saved.',
		'Κανόνες χωρίς κείμενο αγνοήθηκαν.' => 'Rules without a text were ignored.',
		'Κάθε κατηγορία μπαίνει σε έναν μόνο κανόνα: οι επαναλήψεις αγνοήθηκαν.' => 'Each category can be in one rule only: the repeats were ignored.',
		'Αν το site έχει cache σελίδων (π.χ. WP Rocket), καθάρισέ το για να δουν οι επισκέπτες τις αλλαγές.' => 'If the site has a page cache (e.g. WP Rocket), clear it so visitors see the changes.',

		// Footer.
		'Made with ❤ by %s'                                       => 'Made with ❤ by %s',
		'Noxpress Dashboard'                                      => 'Noxpress Dashboard',
		'More plugins at'                                         => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                          => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_theme-patcher', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_theme-patcher filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'theme-patcher' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
