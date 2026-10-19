<?php
/**
 * BKF_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Visitors see no text of ours: products carry the catalogue's own text.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το προϊόν δεν αποθηκεύτηκε.'                               => 'The product was not saved.',
		'Το εξώφυλλο δεν είναι εικόνα JPEG, PNG, GIF ή WebP έως 10 MB.' => 'The cover is not a JPEG, PNG, GIF or WebP image of up to 10 MB.',
		'Ο συγχρονισμός σταμάτησε με το χέρι.'                      => 'The sync was stopped by hand.',
		'Ο συγχρονισμός δεν τελείωσε σε 12 ώρες και σταμάτησε.'     => 'The sync did not finish within 12 hours and was stopped.',
		'Η υπηρεσία δεν επέστρεψε κανέναν από τους τίτλους του καταστήματος. Ο συγχρονισμός σταμάτησε χωρίς να αλλάξει κάτι· έλεγξε τη σύνδεση στις Ρυθμίσεις.' => 'The service returned none of the store\'s titles. The sync stopped without changing anything; check the connection in Settings.',
		'Κανόνας «%1$s»: %2$s'                                      => 'Rule "%1$s": %2$s',
		'Έλεγχος %1$d, ενημερώσεις %2$d, εκτός καταλόγου %3$d, νέοι τίτλοι %4$d, υπάρχουν ήδη %5$d, σφάλματα %6$d.' => 'Checked %1$d, updated %2$d, out of the catalogue %3$d, new titles %4$d, already in the store %5$d, errors %6$d.',
		'Κανονικός συντελεστής'                                     => 'Standard rate',
		'Το προϊόν δεν βρέθηκε.'                                    => 'The product was not found.',
		'Ο τίτλος δεν βρέθηκε στην υπηρεσία.'                       => 'The title was not found in the service.',
		'Το προϊόν δεν έχει το ISBN αυτού του τίτλου.'              => 'The product does not have this title\'s ISBN.',
		'Αυτός ο τίτλος είναι ήδη συνδεδεμένος με άλλο προϊόν.'     => 'This title is already linked to another product.',
		'Δεν έχεις δικαίωμα για αυτή την ενέργεια.'                 => 'You are not allowed to do this.',
		'Τσέκαρε τουλάχιστον έναν τίτλο.'                           => 'Tick at least one title.',
		'Το προϊόν αποσυνδέθηκε από τον κατάλογο.'                  => 'The product was unlinked from the catalogue.',
		'Ενημερώθηκαν: %s.'                                         => 'Updated: %s.',
		'Το προϊόν είναι ήδη ενημερωμένο.'                          => 'The product is already up to date.',
		'Τρέχει συγχρονισμός· ξαναδοκίμασε σε λίγο.'                => 'A sync is running; try again in a moment.',
		'Εισήχθησαν %1$d, ενημερώθηκαν %2$d, υπάρχουν ήδη %3$d, δεν βρέθηκαν %4$d, σφάλματα %5$d.' => 'Imported %1$d, updated %2$d, already in the store %3$d, not found %4$d, errors %5$d.',
		'Χωρίς JavaScript εισάγονται έως %d τίτλοι τη φορά.'        => 'Without JavaScript up to %d titles are imported at a time.',
		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Το προϊόν συνδέθηκε με τον κατάλογο. Τα πεδία του δεν αλλάζουν μέχρι να πατήσεις «Ενημέρωση από τον κατάλογο» στο προϊόν.' => 'The product was linked to the catalogue. Its fields do not change until you press "Update from the catalogue" on the product.',
		'Τίτλος'                                                    => 'Title',
		'Συγγραφέας ή άλλος συντελεστής'                            => 'Author or other contributor',
		'Εκδότης'                                                   => 'Publisher',
		'Γράψε 3 γράμματα…'                                         => 'Type 3 letters…',
		'Έτος από – έως'                                            => 'Year from – to',
		'Είδος'                                                     => 'Kind',
		'Όλα'                                                       => 'All',
		'Αναζήτηση'                                                 => 'Search',
		'Πάτησε «Αναζήτηση» για να τρέξει η αναζήτηση.'             => 'Press "Search" to run the search.',
		'Η αναζήτηση απέτυχε: %s'                                   => 'The search failed: %s',
		'Δεν βρέθηκε εκδότης με αυτό το όνομα.'                     => 'No publisher was found with this name.',
		'Βρέθηκαν πολλοί εκδότες· διάλεξε έναν:'                    => 'Several publishers were found; pick one:',
		'%1$s τίτλος · σελίδα %2$d από %3$d'                        => '%1$s title · page %2$d of %3$d',
		'%1$s τίτλοι · σελίδα %2$d από %3$d'                        => '%1$s titles · page %2$d of %3$d',
		'Δεν βρέθηκαν τίτλοι.'                                      => 'No titles were found.',
		'Κατάσταση'                                                 => 'Status',
		'Πρόχειρο'                                                  => 'Draft',
		'Δημοσιευμένο'                                              => 'Published',
		'Κατηγορία'                                                 => 'Category',
		'— Από την αντιστοίχιση των Ρυθμίσεων —'                    => '— From the mapping in Settings —',
		'Εισαγωγή επιλεγμένων'                                      => 'Import selected',
		'Όλοι'                                                      => 'All',
		'Έτος'                                                      => 'Year',
		'Τιμή'                                                      => 'Price',
		'Στο κατάστημα'                                             => 'In the store',
		'Ίδιο ISBN'                                                 => 'Same ISBN',
		'Το υπάρχον προϊόν παίρνει μόνο το σημάδι του καταλόγου· τα πεδία του δεν αλλάζουν.' => 'The existing product only gets the catalogue mark; its fields do not change.',
		'Σύνδεση'                                                   => 'Link',
		'Προηγούμενη'                                               => 'Previous',
		'Επόμενη'                                                   => 'Next',
		'Book Feed'                                                 => 'Book Feed',
		'Συνδεδεμένο'                                               => 'Linked',
		'Από τον κατάλογο'                                          => 'From the catalogue',
		'Εκτός καταλόγου'                                           => 'Out of the catalogue',
		'Κωδικός καταλόγου: %s'                                     => 'Catalogue id: %s',
		'Συγχρονίστηκε: %s'                                         => 'Synced: %s',
		'Δεν έχει συγχρονιστεί ακόμα.'                              => 'Not synced yet.',
		'Τιμή καταλόγου: %s'                                        => 'Catalogue price: %s',
		'Ενιαία τιμή έως: %s'                                       => 'Fixed price until: %s',
		'Υπήρχε ήδη στο κατάστημα: ο κατάλογος δεν αλλάζει τα πεδία του, ούτε τιμή και διαθεσιμότητα, μέχρι να πατήσεις «Ενημέρωση από τον κατάλογο».' => 'It was already in the store: the catalogue does not change its fields, nor price and availability, until you press "Update from the catalogue".',
		'Κλειδωμένα (τα άλλαξες με το χέρι, ο συγχρονισμός δεν τα αγγίζει): %s.' => 'Locked (you changed them by hand, the sync does not touch them): %s.',
		'Συγχρονισμός τώρα'                                         => 'Sync now',
		'Ενημέρωση από τον κατάλογο'                                => 'Update from the catalogue',
		'Ξεκλείδωμα και πλήρης ενημέρωση'                           => 'Unlock and update everything',
		'Αποσύνδεση'                                                => 'Unlink',
		'Λείπουν το όνομα χρήστη ή ο κωδικός της υπηρεσίας (Ρυθμίσεις).' => 'The service user name or password is missing (Settings).',
		'Η υπηρεσία απάντησε με κωδικό HTTP %1$d: %2$s'             => 'The service answered with HTTP code %1$d: %2$s',
		'Η υπηρεσία δεν απαντά.'                                    => 'The service does not respond.',
		'Η απάντηση της υπηρεσίας δεν είναι JSON: %s'               => 'The service\'s answer is not JSON: %s',
		'Σφάλμα της υπηρεσίας: %s'                                  => 'Service error: %s',
		'Η απάντηση της υπηρεσίας δεν έχει τη μορφή που περιμέναμε: %s' => 'The service\'s answer is not in the expected format: %s',
		'(κενή απάντηση)'                                           => '(empty answer)',
		'Book Feed: η έκπτωση είναι %1$s%% ενώ ο τίτλος έχει ενιαία τιμή έως %3$s, με όριο έκπτωσης %2$s%%. Έλεγξε την τιμή προσφοράς.' => 'Book Feed: the discount is %1$s%% while the title has a fixed price until %3$s, with a discount limit of %2$s%%. Check the sale price.',
		'Κανόνες'                                                   => 'Rules',
		'Ιστορικό'                                                  => 'History',
		'BF Ρυθμίσεις'                                              => 'BF Settings',
		'Εργαλεία'                                                  => 'Tools',
		'Τίτλοι εκδοτών'                                            => 'Publishers\' titles',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Δεν βρέθηκε εκδότης.'                                      => 'No publisher found.',
		'Εισαγωγή…'                                                 => 'Importing…',
		'Η εισαγωγή τελείωσε.'                                      => 'The import is finished.',
		'Εισήχθη'                                                   => 'Imported',
		'Ενημερώθηκε'                                               => 'Updated',
		'Υπάρχει ήδη (ίδιο ISBN)'                                   => 'Already exists (same ISBN)',
		'Δεν βρέθηκε στην υπηρεσία'                                 => 'Not found in the service',
		'Σφάλμα'                                                    => 'Error',
		'Τρέχει συγχρονισμός· ξαναδοκιμάζω…'                        => 'A sync is running; retrying…',
		'Συνδέθηκε'                                                 => 'Linked',
		'Η σύνδεση με τον server απέτυχε.'                          => 'The connection to the server failed.',
		'Ο συγχρονισμός τελείωσε.'                                  => 'The sync is finished.',
		'Ανανέωση σελίδας'                                          => 'Reload page',
		'Σίγουρα;'                                                  => 'Are you sure?',
		'Όλα τα πεδία του προϊόντος θα γραφτούν ξανά από τον κατάλογο. Συνέχεια;' => 'Every field of the product will be written again from the catalogue. Continue?',
		'Το προϊόν μένει όπως είναι, αλλά δεν θα συγχρονίζεται πια. Συνέχεια;' => 'The product stays as it is, but it will no longer be synced. Continue?',
		'Book Feed: ο αποθηκευμένος κωδικός της υπηρεσίας δεν διαβάζεται πια (άλλαξαν τα κλειδιά του wp-config.php). Γράψ’ τον ξανά στις Ρυθμίσεις.' => 'Book Feed: the saved service password can no longer be read (the keys in wp-config.php changed). Enter it again in Settings.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Τίτλοι άλλων εκδοτικών από τον κατάλογο Bookpoint (ΟΣΔΕΛ) ως προϊόντα του καταστήματος, με καθημερινό συγχρονισμό τιμής και διαθεσιμότητας.' => 'Titles of other publishers from the Bookpoint catalogue (OSDEL) as store products, with a daily sync of price and availability.',
		'Χωρίς σύνδεση'                                             => 'Not connected',
		'Γράψε όνομα χρήστη και κωδικό στις Ρυθμίσεις.'             => 'Enter a user name and password in Settings.',
		'Χρήστης %s'                                                => 'User %s',
		'http χωρίς κρυπτογράφηση'                                  => 'http without encryption',
		'%d τίτλος στο κατάστημα'                                   => '%d title in the store',
		'%d τίτλοι στο κατάστημα'                                   => '%d titles in the store',
		'Συγχρονισμός σε εξέλιξη'                                   => 'Sync in progress',
		'Διακοπή'                                                   => 'Stop',
		'Τελευταίος συγχρονισμός %1$s: %2$s'                        => 'Last sync %1$s: %2$s',
		'Δεν έχει γίνει ακόμα συγχρονισμός.'                        => 'No sync has run yet.',
		'Επόμενος αυτόματος: %s'                                    => 'Next automatic: %s',
		'Ένας κανόνας φέρνει αυτόματα τους νέους τίτλους ενός εκδότη, στον καθημερινό συγχρονισμό, πάντα ως πρόχειρα για να τους ελέγξεις πριν τη δημοσίευση. Οι τίτλοι που υπάρχουν ήδη στο κατάστημα (ίδιο ISBN) δεν διπλασιάζονται.' => 'A rule brings in a publisher\'s new titles automatically, during the daily sync, always as drafts so you can check them before publishing. Titles already in the store (same ISBN) are not duplicated.',
		'Αποθήκευση'                                                => 'Save',
		'Νέος κανόνας'                                              => 'New rule',
		'Ενεργός'                                                   => 'Active',
		'Κωδικός εκδότη'                                            => 'Publisher id',
		'Όνομα κανόνα (προαιρετικό)'                                => 'Rule name (optional)',
		'Μόνο από το έτος'                                          => 'Only from the year',
		'Νέοι τίτλοι ανά εκτέλεση (έως)'                            => 'New titles per run (up to)',
		'Τρόπος'                                                    => 'Mode',
		'Πώληση από το κατάστημα'                                   => 'Sold by the store',
		'Μόνο προβολή, με κουμπί προς άλλο site'                    => 'Display only, with a button to another site',
		'Διεύθυνση του κουμπιού (για «Μόνο προβολή»)'               => 'Button address (for "Display only")',
		'Μπαίνουν τα {isbn}, {isbn13} (μόνο ψηφία) και {title}.'    => 'You can use {isbn}, {isbn13} (digits only) and {title}.',
		'Κείμενο κουμπιού'                                          => 'Button text',
		'Τελευταία εκτέλεση: %s'                                    => 'Last run: %s',
		'Δεν έχει τρέξει ακόμα'                                     => 'Has not run yet',
		'Δες τους τίτλους του'                                      => 'See its titles',
		'Διαγραφή κανόνα'                                           => 'Delete rule',
		'Όλες οι ενέργειες'                                         => 'All actions',
		'Φίλτρο'                                                    => 'Filter',
		'CSV (Excel ελληνικά)'                                      => 'CSV (Excel Greek)',
		'CSV (τυπικό)'                                              => 'CSV (standard)',
		'Πότε'                                                      => 'When',
		'Ενέργεια'                                                  => 'Action',
		'Πεδία'                                                     => 'Fields',
		'Μήνυμα'                                                    => 'Message',
		'Το ιστορικό είναι άδειο.'                                  => 'The history is empty.',
		'Καθαρισμός ιστορικού'                                      => 'Clear history',
		'Παράλειψη'                                                 => 'Skip',
		'Χαρακτηριστικό μόνο του προϊόντος'                         => 'Attribute of this product only',
		'Brand (product_brand)'                                     => 'Brand (product_brand)',
		'Χαρακτηριστικό: %s'                                        => 'Attribute: %s',
		'Έτος έκδοσης'                                              => 'Publication year',
		'Σελίδες'                                                   => 'Pages',
		'Γλώσσα'                                                    => 'Language',
		'Σειρά'                                                     => 'Series',
		'Μορφή (δέσιμο)'                                            => 'Format (binding)',
		'Είδος (βιβλίο, ebook, audiobook)'                          => 'Kind (book, ebook, audiobook)',
		'Υπότιτλος (όταν μπαίνει σε χαρακτηριστικό)'                => 'Subtitle (when it goes into an attribute)',
		'Διεύθυνση της υπηρεσίας'                                   => 'Service address',
		'Όνομα χρήστη (από τον ΟΣΔΕΛ)'                              => 'User name (from OSDEL)',
		'Κωδικός'                                                   => 'Password',
		'Από τη σταθερά BKF_PASSWORD στο wp-config.php'             => 'From the BKF_PASSWORD constant in wp-config.php',
		'Κενό = μένει ο τωρινός'                                    => 'Empty = keep the current one',
		'Διαγραφή κωδικού'                                          => 'Delete password',
		'Ο αποθηκευμένος κωδικός δεν διαβάζεται πια· γράψ’ τον ξανά' => 'The saved password can no longer be read; enter it again',
		'Ο κωδικός φυλάγεται κρυπτογραφημένος με τα κλειδιά του wp-config.php και δεν εμφανίζεται ποτέ. Εναλλακτικά: define( \'BKF_PASSWORD\', \'…\' ); στο wp-config.php.' => 'The password is stored encrypted with the keys in wp-config.php and is never shown. Alternatively: define( \'BKF_PASSWORD\', \'…\' ); in wp-config.php.',
		'Δοκιμή πρώτα με https'                                     => 'Try https first',
		'Η σύνδεση γίνεται με http: ο κωδικός ταξιδεύει χωρίς κρυπτογράφηση σε κάθε αίτημα. Γράψε τη διεύθυνση με https:// αν η υπηρεσία το δέχεται.' => 'The connection uses http: the password travels unencrypted with every request. Enter the address with https:// if the service accepts it.',
		'Αποθήκευση και δοκιμή σύνδεσης'                            => 'Save and test the connection',
		'Εισαγωγή'                                                  => 'Import',
		'Κατάσταση νέων προϊόντων (εισαγωγή με το χέρι)'            => 'Status of new products (manual import)',
		'Υπότιτλος'                                                 => 'Subtitle',
		'Στον τίτλο, μετά από «:»'                                  => 'In the title, after ":"',
		'Σε χαρακτηριστικό'                                         => 'In an attribute',
		'Πουθενά'                                                   => 'Nowhere',
		'ISBN στο προϊόν'                                           => 'ISBN on the product',
		'Όπως το δίνει ο κατάλογος'                                 => 'As the catalogue gives it',
		'Μόνο ψηφία'                                                => 'Digits only',
		'Κατηγορία για τίτλους χωρίς αντιστοίχιση'                  => 'Category for titles without a mapping',
		'— Η προεπιλογή του WooCommerce —'                          => '— The WooCommerce default —',
		'Το ISBN και ως κωδικός προϊόντος (SKU)'                    => 'The ISBN also as the product code (SKU)',
		'Περιγραφή και σύντομη περιγραφή από τον κατάλογο'          => 'Description and short description from the catalogue',
		'Κατέβασμα του εξωφύλλου στη βιβλιοθήκη πολυμέσων'          => 'Download the cover into the media library',
		'Και το οπισθόφυλλο, στη συλλογή εικόνων'                   => 'And the back cover, in the image gallery',
		'Πριν δημοσιεύσεις περιγραφές και εξώφυλλα, βεβαιώσου ότι το επιτρέπει η σύμβασή σου με τον ΟΣΔΕΛ.' => 'Before you publish descriptions and covers, make sure your contract with OSDEL allows it.',
		'Αντιστοίχιση πεδίων'                                       => 'Field mapping',
		'Ένα χαρακτηριστικό του καταστήματος (pa_) φιλτράρεται στο Shop Filters και στα widgets. Τα «χαρακτηριστικά μόνο του προϊόντος» φαίνονται στην καρτέλα «Επιπλέον πληροφορίες».' => 'A store attribute (pa_) can be filtered in Shop Filters and in widgets. "Attributes of this product only" show in the "Additional information" tab.',
		'Ρόλοι συντελεστών'                                         => 'Contributor roles',
		'Οι ρόλοι (π.χ. συγγραφέας, μεταφραστής) εμφανίζονται εδώ μόλις τους δει το plugin σε μια αναζήτηση. Μέχρι τότε κάθε ρόλος γίνεται χαρακτηριστικό του προϊόντος με το όνομά του.' => 'Roles (e.g. author, translator) show up here once the plugin sees them in a search. Until then every role becomes a product attribute with its own name.',
		'(χωρίς ρόλο)'                                              => '(no role)',
		'Κατηγορίες'                                                => 'Categories',
		'Οι κατηγορίες του καταλόγου εμφανίζονται εδώ μόλις τις δει το plugin σε μια αναζήτηση.' => 'The catalogue\'s categories show up here once the plugin sees them in a search.',
		'— Χωρίς αντιστοίχιση —'                                    => '— No mapping —',
		'Η κατηγορία μπαίνει μόνο στην εισαγωγή. Ένας κανόνας με δική του κατηγορία κερδίζει την αντιστοίχιση. Για χρόνο αποστολής ειδικά για αυτούς τους τίτλους, όρισε ημέρες προετοιμασίας στην κατηγορία τους από το Shipping Notes.' => 'The category is set on import only. A rule with its own category wins over the mapping. For a shipping time just for these titles, set preparation days on their category in Shipping Notes.',
		'ΦΠΑ'                                                       => 'VAT',
		'Οι συντελεστές ΦΠΑ του καταλόγου εμφανίζονται εδώ μόλις τους δει το plugin. Μέχρι τότε η κλάση φόρου βγαίνει αυτόματα από τον συντελεστή.' => 'The catalogue\'s VAT rates show up here once the plugin sees them. Until then the tax class comes automatically from the rate.',
		'Αυτόματα (καμία κλάση με αυτόν τον συντελεστή· μένει όπως είναι)' => 'Automatic (no class with this rate; stays as it is)',
		'Αυτόματα (%s)'                                             => 'Automatic (%s)',
		'Το κατάστημα δίνει τιμές χωρίς ΦΠΑ: η τιμή του καταλόγου (με ΦΠΑ) μετατρέπεται με τον συντελεστή του τίτλου.' => 'The store enters prices without VAT: the catalogue price (with VAT) is converted with the title\'s rate.',
		'Η τιμή του καταλόγου περιλαμβάνει ΦΠΑ και μπαίνει όπως είναι.' => 'The catalogue price includes VAT and is used as it is.',
		'Διαθεσιμότητα'                                             => 'Availability',
		'Μια τιμή διαθεσιμότητας που δεν έχει αντιστοιχιστεί κάνει τον τίτλο «Εξαντλημένο», ώστε να μην πουλάς κάτι που ίσως δεν υπάρχει. Οι νέες τιμές εμφανίζονται εδώ μόλις τις δει το plugin.' => 'An availability value that has not been mapped makes the title "Out of stock", so you do not sell something that may not exist. New values show up here once the plugin sees them.',
		'Συγχρονισμός'                                              => 'Sync',
		'Καθημερινός συγχρονισμός'                                  => 'Daily sync',
		'Ώρα'                                                       => 'Time',
		'Η τιμή και η διαθεσιμότητα ακολουθούν πάντα τον κατάλογο. Αυτά ακολουθούν κι αυτά, όσο δεν τα έχεις αλλάξει με το χέρι:' => 'Price and availability always follow the catalogue. These follow it too, as long as you have not changed them by hand:',
		'Τίτλος που φεύγει από τον κατάλογο'                        => 'A title that leaves the catalogue',
		'Εξαντλημένο (μένει ορατό)'                                 => 'Out of stock (stays visible)',
		'Πρόχειρο (φεύγει από το site)'                             => 'Draft (leaves the site)',
		'Παύση ανάμεσα στις σελίδες του καταλόγου (ms)'             => 'Pause between catalogue pages (ms)',
		'Ενιαία τιμή βιβλίου'                                       => 'Fixed book price',
		'Μέγιστη έκπτωση όσο ισχύει η ενιαία τιμή (%)'              => 'Largest discount while the fixed price applies (%)',
		'Μόνο προειδοποίηση όταν αποθηκεύεις τιμή προσφοράς με μεγαλύτερη έκπτωση, για τίτλους που ο κατάλογος δίνει ημερομηνία λήξης ενιαίας τιμής. Όταν ο τύπος τιμής του καταλόγου γράφει ποσοστό, ισχύει εκείνο. Το ποσοστό και οι εξαιρέσεις του νόμου είναι δική σου ευθύνη.' => 'Only a warning when you save a sale price with a larger discount, for titles where the catalogue gives an end date for the fixed price. When the catalogue\'s price type states a percentage, that one applies. The percentage and the exceptions in the law are your responsibility.',
		'Διαγραφή του plugin'                                       => 'Deleting the plugin',
		'Διαγραφή και των προϊόντων που εισήχθησαν, όταν διαγραφεί το plugin' => 'Also delete the imported products when the plugin is deleted',
		'Χωρίς αυτό, τα προϊόντα, οι εικόνες και οι κατηγορίες μένουν· φεύγουν μόνο οι ρυθμίσεις, το ιστορικό και τα σημάδια του plugin στα προϊόντα. Οι εικόνες μένουν πάντα.' => 'Without this, products, images and categories stay; only the settings, the history and the plugin\'s marks on the products go. Images always stay.',
		'Backup ρυθμίσεων'                                          => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)'                                 => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                  => 'Export settings (JSON)',
		'Ρυθμίσεις και κανόνες, χωρίς τον κωδικό. Κατηγορίες και χαρακτηριστικά δείχνονται με το id ή το slug τους: σε άλλο site κρατιούνται μόνο όσα υπάρχουν εκεί.' => 'Settings and rules, without the password. Categories and attributes are referenced by their id or slug: on another site only those that exist there are kept.',
		'Για προγραμματιστές'                                       => 'For developers',
		'Από πού ήρθε ένα προϊόν: source, id, isbn, synced, linked· null για προϊόντα που δεν ήρθαν από τον κατάλογο.' => 'Where a product came from: source, id, isbn, synced, linked; null for products that did not come from the catalogue.',
		'Πυροδοτείται με τα ids των προϊόντων που άλλαξαν σε κάθε εισαγωγή ή βήμα συγχρονισμού.' => 'Fires with the ids of the products that changed on every import or sync step.',
		'Εντολές WP-CLI: κατάσταση, αναζήτηση στον κατάλογο (μόνο ανάγνωση), συγχρονισμός με --yes.' => 'WP-CLI commands: status, catalogue search (read only), sync with --yes.',
		'Σταθερά στο wp-config.php με τον κωδικό της υπηρεσίας, αντί για τις ρυθμίσεις.' => 'Constant in wp-config.php with the service password, instead of the settings.',
		'Ο συγχρονισμός ξεκίνησε στο παρασκήνιο. Η σελίδα δείχνει την πρόοδο.' => 'The sync started in the background. The page shows its progress.',
		'Τρέχει ήδη συγχρονισμός.'                                  => 'A sync is already running.',
		'Ο συγχρονισμός σταμάτησε.'                                 => 'The sync was stopped.',
		'Το ιστορικό καθαρίστηκε.'                                  => 'The history was cleared.',
		'Η διεύθυνση δεν είναι έγκυρη (http ή https, χωρίς παραμέτρους): κρατήθηκε η προεπιλογή.' => 'The address is not valid (http or https, without parameters): the default was kept.',
		'Ο κωδικός δεν αποθηκεύτηκε: έως 200 χαρακτήρες, χωρίς χαρακτήρες ελέγχου.' => 'The password was not saved: up to 200 characters, without control characters.',
		'Ο κωδικός δεν αποθηκεύτηκε: η PHP του server δεν έχει OpenSSL με AES-256-GCM. Χρησιμοποίησε τη σταθερά BKF_PASSWORD.' => 'The password was not saved: the server\'s PHP has no OpenSSL with AES-256-GCM. Use the BKF_PASSWORD constant.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Η σύνδεση απέτυχε: %s'                                     => 'The connection failed: %s',
		'Η σύνδεση δουλεύει (%2$s): ο κατάλογος έχει %1$s τίτλους.' => 'The connection works (%2$s): the catalogue has %1$s titles.',
		'Προσοχή: χωρίς https ο κωδικός ταξιδεύει χωρίς κρυπτογράφηση.' => 'Warning: without https the password travels unencrypted.',
		'Οι κανόνες αποθηκεύτηκαν.'                                 => 'Rules saved.',
		'Κάποιοι κανόνες δεν κρατήθηκαν ή άλλαξαν: ο κωδικός εκδότη θέλει μόνο γράμματα, ψηφία, - και _, και η «Μόνο προβολή» θέλει διεύθυνση http(s).' => 'Some rules were not kept or were changed: the publisher id takes only letters, digits, - and _, and "Display only" needs an http(s) address.',
		'Προϊόν'                                                    => 'Product',
		'Το αρχείο είναι πολύ μεγάλο.'                              => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                               => 'No JSON file was selected.',
		'Το αρχείο δεν είναι έγκυρο JSON με ρυθμίσεις του Book Feed.' => 'The file is not valid JSON with Book Feed settings.',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: κρατήθηκαν %1$d από %2$d κανόνες, και ό,τι δεν ήταν έγκυρο έμεινε όπως ήταν.' => 'The import was completed PARTIALLY: %1$d of %2$d rules were kept, and whatever was not valid stayed as it was.',
		'Η εισαγωγή ολοκληρώθηκε.'                                  => 'The import is complete.',
		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
		'Ενημέρωση'                                                 => 'Update',
		'Ξανά στον κατάλογο'                                        => 'Back in the catalogue',
		'Κλειδωμένα πεδία'                                          => 'Locked fields',
		'Περιγραφή'                                                 => 'Description',
		'Σύντομη περιγραφή'                                         => 'Short description',
		'Εξώφυλλο'                                                  => 'Cover',
		'Χαρακτηριστικά'                                            => 'Attributes',
		'Βάρος και διαστάσεις'                                      => 'Weight and dimensions',
		'Κλάση ΦΠΑ'                                                 => 'VAT class',
		'Το Book Feed χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Book Feed requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Κατηγορίες του καταλόγου (π.χ. ως «Είδος»)' => 'Catalogue categories (e.g. as "Genre")',
		'Όνομα αρχείου του εξωφύλλου' => 'Cover file name',
		'Εναλλακτικό κείμενο (alt) του εξωφύλλου' => 'Cover alternative text (alt)',
		'Μπορείς να γράψεις: %s. Το όνομα αρχείου γίνεται greeklish με παύλες.' => 'You can use: %s. The file name is turned into Latin letters with dashes.',
		'Σειρά των χαρακτηριστικών στο προϊόν' => 'Order of the attributes on the product',
		'Ένα όνομα ανά γραμμή, όπως φαίνεται στο προϊόν (π.χ. ISBN, Συγγραφείς, Έτος έκδοσης). Όσα δεν γράψεις μπαίνουν μετά. Κενό: ISBN, συντελεστές, κατηγορίες, είδος, έτος, σελίδες, μορφή, γλώσσα, σειρά, εκδότης.' => 'One name per line, as shown on the product (e.g. ISBN, Authors, Publication year). Anything not listed comes after. Empty: ISBN, contributors, categories, kind, year, pages, format, language, series, publisher.',
		'Μετονομασία τιμών' => 'Value renames',
		'Μία ανά γραμμή: «τιμή του καταλόγου = τιμή του καταστήματος», π.χ. «Μαλακό εξώφυλλο = Χαρτόδετο». Με κενό δεξιά, η τιμή παραλείπεται. Ισχύει για εκδότη, μορφή, είδος, κατηγορίες ως χαρακτηριστικό, γλώσσα, σειρά και ετικέτες.' => 'One per line: "catalogue value = store value", e.g. "Μαλακό εξώφυλλο = Paperback". With nothing on the right, the value is left out. Applies to publisher, format, kind, categories as an attribute, language, series and tags.',
		'Μορφές και είδη που έχουν φανεί: %s' => 'Formats and kinds seen so far: %s',
		'Θέματα και λέξεις-κλειδιά που έχουν φανεί: %s' => 'Subjects and keywords seen so far: %s',
		'Και οι γονικές κατηγορίες της κατηγορίας που αντιστοιχίστηκε' => 'Also the parent categories of the mapped category',
		'Ετικέτες' => 'Tags',
		'Ετικέτες προϊόντος από' => 'Product tags from',
		'Καμία ετικέτα' => 'No tags',
		'Θέματα και λέξεις-κλειδιά του καταλόγου' => 'Catalogue subjects and keywords',
		'Κατηγορίες του καταλόγου' => 'Catalogue categories',
		'Το πολύ' => 'At most',
		'Οι ετικέτες μπαίνουν μόνο στην εισαγωγή· μετά είναι δικές σου.' => 'Tags are set on import only; after that they are yours.',
		'Κατάλογος %1$s → εκδότης %2$s' => 'Catalogue %1$s → publisher %2$s',
		'Τιμή στο site του εκδότη: %s' => 'Price on the publisher\'s site: %s',
		'Η τιμή του εκδότη (%1$s) απέχει πολύ από του καταλόγου (%2$s)· κρατήθηκε του καταλόγου.' => 'The publisher\'s price (%1$s) is far from the catalogue\'s (%2$s); the catalogue\'s was kept.',
		'Η σελίδα του εκδότη απάντησε %d.' => 'The publisher\'s page answered %d.',
		'Δεν βρέθηκε τιμή για αυτό το ISBN στη σελίδα του εκδότη.' => 'No price for this ISBN was found on the publisher\'s page.',
		'Τιμές από τα sites των εκδοτών' => 'Prices from the publishers\' sites',
		'Για κάθε εκδότη εδώ, το plugin διαβάζει την τιμή από τη σελίδα του βιβλίου στο site του (δομημένα δεδομένα schema.org, όπως τα δίνει κάθε WooCommerce) και τη βάζει αντί για την τιμή του καταλόγου, στην εισαγωγή και σε κάθε συγχρονισμό. Αν η σελίδα δεν διαβάζεται ή η τιμή απέχει πάνω από το διπλάσιο ή κάτω από το μισό της τιμής του καταλόγου, μένει η τιμή του καταλόγου. Κάθε αλλαγή γράφεται στο ιστορικό.' => 'For each publisher listed here, the plugin reads the price from the book\'s page on the publisher\'s site (schema.org structured data, as every WooCommerce shop provides) and uses it instead of the catalogue price, on import and on every sync. If the page cannot be read, or the price is more than double or less than half the catalogue price, the catalogue price stays. Every change is written to the history.',
		'Σελίδα του βιβλίου στο site του εκδότη' => 'The book\'s page on the publisher\'s site',
		'Γράψε {isbn} (με παύλες, όπως το δίνει ο κατάλογος) ή {isbn13} (μόνο ψηφία) εκεί που μπαίνει το ISBN. Σε site WooCommerce: https://site/wp-json/wc/store/v1/products?search={isbn13} ή η σελίδα του προϊόντος. Άδεια διεύθυνση αφαιρεί τον εκδότη. Δοκίμασε ένα ISBN από τα Εργαλεία.' => 'Write {isbn} (with dashes, as the catalogue gives it) or {isbn13} (digits only) where the ISBN goes. On a WooCommerce site: https://site/wp-json/wc/store/v1/products?search={isbn13} or the product page. An empty address removes the publisher. Try an ISBN from Tools.',
		'Δοκιμή τιμής εκδότη' => 'Publisher price test',
		'Διάβασε την τιμή' => 'Read the price',
		'Βρίσκει τον τίτλο στον κατάλογο, ανοίγει τη σελίδα του εκδότη από τις Ρυθμίσεις και δείχνει τι τιμή διαβάζει. Δεν αλλάζει κανένα προϊόν.' => 'Finds the title in the catalogue, opens the publisher\'s page from Settings and shows the price it reads. No product is changed.',
		'Δεν κρατήθηκε η σελίδα για: %s. Χρειάζεται http(s):// και {isbn} ή {isbn13}.' => 'The page was not kept for: %s. It needs http(s):// and {isbn} or {isbn13}.',
		'Γράψε ένα έγκυρο ISBN.' => 'Enter a valid ISBN.',
		'Δεν έχει οριστεί σελίδα για τον εκδότη «%s» στις Ρυθμίσεις.' => 'No page is set for the publisher "%s" in Settings.',
		'«%1$s»: μένει η τιμή του καταλόγου (%2$s). %3$s Σελίδα: %4$s' => '"%1$s": the catalogue price stays (%2$s). %3$s Page: %4$s',
		'«%1$s»: κατάλογος %2$s, εκδότης %3$s. Σελίδα: %4$s' => '"%1$s": catalogue %2$s, publisher %3$s. Page: %4$s',
		'Τιμή εκδότη' => 'Publisher price',
	);

	public static function init(): void {
		add_filter( 'gettext_book-feed', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_book-feed', array( __CLASS__, 'filter_n' ), 10, 5 );
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

	/** gettext_book-feed filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'book-feed' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_book-feed filter (Greek and English share the plural rule). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'book-feed' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = 1 === (int) $number ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
