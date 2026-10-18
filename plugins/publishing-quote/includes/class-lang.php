<?php
/**
 * PBQ_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitor texts are settings, not msgids
 * (Bible §14.17, PBQ_Settings::text()).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class PBQ_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		// Front, block, widget.
		'Publishing Quote: ο υπολογιστής είναι κλειστός (λειτουργία «Ανενεργό»).' => 'Publishing Quote: the calculator is off («Off» mode).',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τον υπολογιστή.' => 'Test mode: only managers see the calculator.',
		'Κοστολόγηση έκδοσης' => 'Publishing quote',
		'Ο υπολογιστής κόστους έκδοσης του Publishing Quote.' => 'The publishing cost calculator of Publishing Quote.',
		'Τίτλος (κενό = ο τίτλος των ρυθμίσεων):' => 'Title (empty = the title in the settings):',
		'Οι τιμές και τα κείμενα ρυθμίζονται στο Noxpress → Publishing Quote.' => 'Prices and texts are set in Noxpress → Publishing Quote.',

		// Admin: tabs and page.
		'Γενικά' => 'General',
		'Υπηρεσίες' => 'Services',
		'Templates' => 'Templates',
		'Έξτρα παροχές' => 'Extras',
		'Κείμενα' => 'Texts',
		'Δοκιμή' => 'Test',
		'Αιτήματα' => 'Requests',
		'Εργαλεία' => 'Tools',
		'Publishing Quote' => 'Publishing Quote',
		'Ρυθμίσεις' => 'Settings',
		'Όταν ζητάς προσφορά έκδοσης από τον υπολογιστή μας («Ενδιαφέρομαι»), κρατάμε το όνομα, το email, το τηλέφωνο αν το δώσεις, το μήνυμά σου, τις επιλογές σου και το ποσό που είδες, για να σου στείλουμε την προσφορά. Λαμβάνουμε τα στοιχεία με email.' => 'When you ask for a publishing quote from our calculator («I\'m interested»), we keep your name, your email, your phone if you give it, your message, your choices and the amount you saw, so that we can send you the quote. We receive the details by email.',
		'Τα αιτήματα σβήνονται αυτόματα μετά από τους μήνες που ορίζονται στις ρυθμίσεις, και μπορείς να ζητήσεις αντίγραφο ή διαγραφή τους όποτε θέλεις.' => 'Requests are deleted automatically after the months set in the settings, and you can ask for a copy or their deletion whenever you want.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Κοστολόγηση έκδοσης για υποψήφιους συγγραφείς. Ο επισκέπτης βλέπει μόνο το ποσό ανά υπηρεσία και το σύνολο· οι συντελεστές και η σελιδοποίηση μένουν στον server.' => 'Publishing quotes for prospective authors. The visitor sees only the amount per service and the total; the coefficients and the typesetting stay on the server.',
		'Το PBQ_DISABLE είναι ενεργό στο wp-config.php: ο υπολογιστής και η φόρμα δεν φαίνονται πουθενά.' => 'PBQ_DISABLE is on in wp-config.php: the calculator and the form show nowhere.',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',

		// Admin: list editors.
		'Όνομα (ελληνικά)' => 'Name (Greek)',
		'Όνομα (English)' => 'Name (English)',
		'Τιμή ανά σελίδα χειρογράφου' => 'Price per manuscript page',
		'Συντελεστής εκτύπωσης' => 'Print factor',
		'Λέξεις ανά σελίδα βιβλίου' => 'Words per book page',
		'Πολλαπλάσιο σελίδων' => 'Page multiple',
		'Τιμή ανά σελίδα και αντίτυπο' => 'Price per page and copy',
		'Ανά αντίτυπο' => 'Per copy',
		'Στήσιμο' => 'Setup',
		'Ελάχιστες σελίδες' => 'Minimum pages',
		'Μέγιστες σελίδες' => 'Maximum pages',
		'Σκληρό' => 'Hardcover',
		'Είδος' => 'Kind',
		'Μόνο με σκληρό' => 'Hardcover only',
		'Περιγραφή (ελληνικά)' => 'Description (Greek)',
		'Περιγραφή (English)' => 'Description (English)',
		'Χρέωση' => 'Charge',
		'Τιμή' => 'Price',
		'Ελάχιστη χρέωση' => 'Minimum charge',
		'ΦΠΑ %' => 'VAT %',
		'Κατόπιν συζήτησης' => 'On request',
		'Μέγιστη ποσότητα' => 'Maximum quantity',
		'Από (τεμάχια)' => 'From (items)',
		'Έκπτωση %' => 'Discount %',
		'Ένα από τα βασικά' => 'One of the basic ones',
		'Πρόσθετο' => 'Add-on',
		'Πάγιο' => 'Fixed',
		'Ανά τεμάχιο' => 'Per item',
		'Ανά αντίτυπο του τιράζ' => 'Per copy of the print run',
		'Ανά λέξη του κειμένου' => 'Per word of the text',
		'Ενεργό' => 'On',
		'Πάνω' => 'Up',
		'Κάτω' => 'Down',
		'Αφαίρεση' => 'Remove',
		'+ Προσθήκη γραμμής' => '+ Add row',

		// Admin: General.
		'Λειτουργία' => 'Mode',
		'Δοκιμή: μόνο οι διαχειριστές βλέπουν τον υπολογιστή' => 'Test: only managers see the calculator',
		'Ενεργό για όλους' => 'Live for everyone',
		'Ανενεργό: τίποτα στο site' => 'Off: nothing on the site',
		'Πού μπαίνει: το μπλοκ «Publishing quote», το shortcode [publishing_quote] (title="no" χωρίς τίτλο) ή το widget «Κοστολόγηση έκδοσης».' => 'Where it goes: the «Publishing quote» block, the [publishing_quote] shortcode (title="no" without the title) or the «Publishing quote» widget.',
		'Γλώσσα των κειμένων για τους επισκέπτες' => 'Language of the visitor texts',
		'Αυτόματα, από τη γλώσσα του site' => 'Automatic, from the site language',
		'Ελληνικά' => 'Greek',
		'English' => 'English',
		'ΦΠΑ και στρογγύλευση' => 'VAT and rounding',
		'Γενικός συντελεστής ΦΠΑ %' => 'General VAT rate %',
		'Ισχύει όπου μια υπηρεσία δεν έχει δικό της. Ποιος συντελεστής ισχύει για τι το επιβεβαιώνει ο λογιστής σου.' => 'Applies where a service has no rate of its own. Your accountant confirms which rate applies to what.',
		'Βήμα στρογγύλευσης ανά γραμμή' => 'Rounding step per line',
		'0,01 (χωρίς στρογγύλευση)' => '0.01 (no rounding)',
		'Στρογγύλευση προς τα πάνω' => 'Round up',
		'Στρογγύλευση στο πλησιέστερο' => 'Round to the nearest',
		'Τιμές με ΦΠΑ (για ιδιώτες)' => 'Prices with VAT (for individuals)',
		'Τιμές χωρίς ΦΠΑ, με τον ΦΠΑ στο τέλος (για επαγγελματίες)' => 'Prices without VAT, with the VAT at the end (for businesses)',
		'Κάθε γραμμή στρογγυλεύεται χωριστά και το σύνολο είναι το άθροισμα των γραμμών. Η στρογγύλευση δυσκολεύει και όποιον δοκιμάζει τιμές για να βρει τους συντελεστές.' => 'Each line is rounded on its own and the total is the sum of the lines. Rounding also makes it harder for anyone trying values to work out the coefficients.',
		'Αποτέλεσμα και αιτήματα' => 'Result and requests',
		'Ισχύς της τιμής σε ημέρες (0 = χωρίς ημερομηνία)' => 'Price valid for days (0 = no date)',
		'Email για τα αιτήματα' => 'Email for requests',
		'Κενό = %s' => 'Empty = %s',
		'Διαγραφή αιτημάτων μετά από μήνες (0 = ποτέ)' => 'Delete requests after months (0 = never)',
		'Email «λάβαμε το αίτημά σου» στον επισκέπτη, χωρίς ποσά' => '«We got your request» email to the visitor, without amounts',
		'Σύνδεσμος στην πολιτική απορρήτου δίπλα στη συναίνεση (όταν έχει οριστεί σελίδα)' => 'Link to the privacy policy next to the consent (when a page is set)',
		'Διαγραφή των αιτημάτων στο uninstall' => 'Delete the requests on uninstall',
		'Νόμισμα' => 'Currency',
		'Σύμβολο' => 'Symbol',
		'Θέση' => 'Position',
		'Υποδιαστολή' => 'Decimal separator',
		'Διαχωριστικό χιλιάδων' => 'Thousands separator',
		'κενό' => 'space',
		'κανένα' => 'none',
		'Νόμισμα και μορφή αριθμών: από τις ρυθμίσεις του WooCommerce.' => 'Currency and number format: from the WooCommerce settings.',

		// Admin: Services.
		'ΦΠΑ % (κενό = ο γενικός)' => 'VAT % (empty = the general one)',
		'Όλα τα ποσά χωρίς ΦΠΑ. Ο επισκέπτης δεν βλέπει κανέναν από αυτούς τους αριθμούς, μόνο το τελικό ποσό κάθε υπηρεσίας.' => 'All amounts without VAT. The visitor sees none of these numbers, only the final amount of each service.',
		'Επιμέλεια' => 'Editing',
		'Ο επισκέπτης μπορεί να ζητήσει επιμέλεια' => 'The visitor can ask for editing',
		'Ο επισκέπτης δίνει σελίδες χειρογράφου (A4)' => 'The visitor gives manuscript pages (A4)',
		'Ο επισκέπτης δίνει λέξεις' => 'The visitor gives words',
		'Λέξεις ανά σελίδα χειρογράφου' => 'Words per manuscript page',
		'Μετατρέπει λέξεις σε σελίδες (για την τιμή) και σελίδες σε λέξεις (για την εκτίμηση των σελίδων του βιβλίου).' => 'Turns words into pages (for the price) and pages into words (for the estimate of the book pages).',
		'Μέγιστες σελίδες χειρογράφου' => 'Maximum manuscript pages',
		'Είδη επιμέλειας (με ένα μόνο, ο επισκέπτης δεν βλέπει επιλογή)' => 'Kinds of editing (with only one, the visitor sees no choice)',
		'Σχεδιασμός εξωφύλλου' => 'Cover design',
		'Ο επισκέπτης μπορεί να ζητήσει σχεδιασμό εξωφύλλου' => 'The visitor can ask for cover design',
		'Επιπλέον με σκληρό δέσιμο' => 'Extra with hardcover binding',
		'Εσωτερική εικονογράφηση' => 'Interior illustration',
		'Ο επισκέπτης μπορεί να ζητήσει εικονογράφηση' => 'The visitor can ask for illustration',
		'Τιμή ανά ασπρόμαυρο σχέδιο' => 'Price per black and white drawing',
		'Τιμή ανά έγχρωμο σχέδιο' => 'Price per colour drawing',
		'Μέγιστα σχέδια' => 'Maximum drawings',
		'Κλίμακες (έκπτωση από τόσα σχέδια και πάνω)' => 'Tiers (discount from this many drawings up)',
		'Εκτύπωση' => 'Printing',
		'Ο επισκέπτης μπορεί να ζητήσει εκτύπωση' => 'The visitor can ask for printing',
		'Ανά αντίτυπο = σελίδες × τιμή χαρτιού ανά σελίδα × συντελεστής μεγέθους × (συντελεστής χρώματος για έγχρωμες σελίδες) + δέσιμο ανά αντίτυπο + εκτύπωση εξωφύλλου + φινιρίσματα. Σύνολο = ανά αντίτυπο × τιράζ − έκπτωση κλίμακας + στησίματα.' => 'Per copy = pages × paper price per page × size factor × (colour factor for colour pages) + binding per copy + cover printing + finishes. Total = per copy × print run − tier discount + setups.',
		'Στήσιμο εκτύπωσης' => 'Print setup',
		'Εκτύπωση εξωφύλλου ανά αντίτυπο' => 'Cover printing per copy',
		'Συντελεστής για έγχρωμες σελίδες' => 'Factor for colour pages',
		'π.χ. 3 = τριπλάσια τιμή σελίδας' => 'e.g. 3 = three times the page price',
		'Σελίδες εκτός κειμένου (τίτλος, ταυτότητα κ.λπ.)' => 'Pages outside the text (title, imprint etc.)',
		'Ελάχιστο τιράζ' => 'Minimum print run',
		'Μέγιστο τιράζ' => 'Maximum print run',
		'Προεπιλεγμένο τιράζ' => 'Default print run',
		'Ο επισκέπτης διαλέγει ασπρόμαυρες ή έγχρωμες σελίδες' => 'The visitor picks black and white or colour pages',
		'Κλίμακες τιράζ (έκπτωση από τόσα αντίτυπα και πάνω)' => 'Print run tiers (discount from this many copies up)',
		'Μεγέθη, χαρτιά, δεσίματα και φινιρίσματα: καρτέλα Templates.' => 'Sizes, papers, bindings and finishes: Templates tab.',
		'Σελιδοποίηση (κρυφή)' => 'Typesetting (hidden)',
		'ο επισκέπτης βλέπει «Περιλαμβάνεται»' => 'the visitor sees «Included»',
		'Ανά σελίδα βιβλίου' => 'Per book page',
		'Πού μπαίνει το ποσό' => 'Where the amount goes',
		'Αναλογικά σε όλες τις υπηρεσίες με ποσό (προτείνεται)' => 'Proportionally across all services with an amount (recommended)',
		'Στην Επιμέλεια' => 'Into Editing',
		'Στον σχεδιασμό εξωφύλλου' => 'Into Cover design',
		'Στην Εικονογράφηση' => 'Into Illustration',
		'Στην Εκτύπωση' => 'Into Printing',
		'Χρεώνεται όταν ο επισκέπτης διαλέγει' => 'Charged when the visitor picks',
		'Σχεδιασμό εξωφύλλου' => 'Cover design',
		'Εικονογράφηση' => 'Illustration',
		'Το ποσό μοιράζεται μέσα στις υπηρεσίες, ώστε το σύνολο να είναι πάντα το άθροισμα των γραμμών. Ο υπολογισμός γίνεται μόνο στον server: δεν υπάρχει στον κώδικα της σελίδας.' => 'The amount is spread inside the services, so the total is always the sum of the lines. The calculation runs only on the server: it is not in the page code.',

		// Admin: Templates, Extras, Texts.
		'Μεγέθη' => 'Sizes',
		'Λέξεις ανά σελίδα βιβλίου: για την εκτίμηση των σελίδων. Πολλαπλάσιο: οι σελίδες στρογγυλεύονται προς τα πάνω σε τυπογραφικά (π.χ. 16).' => 'Words per book page: for the page estimate. Multiple: pages are rounded up to whole signatures (e.g. 16).',
		'Χαρτιά σώματος' => 'Body papers',
		'Τιμή εκτύπωσης μίας σελίδας σε ένα αντίτυπο, για το μέγεθος με συντελεστή 1.' => 'Print price of one page in one copy, for the size with factor 1.',
		'Δεσίματα' => 'Bindings',
		'Ελάχιστες / μέγιστες σελίδες: 0 = χωρίς όριο. Με «Σκληρό» ισχύει και το επιπλέον ποσό του εξωφύλλου.' => 'Minimum / maximum pages: 0 = no limit. With «Hardcover» the extra cover amount applies too.',
		'Φινιρίσματα εξωφύλλου' => 'Cover finishes',
		'Βασικά: ο επισκέπτης διαλέγει ένα (π.χ. πλαστικοποίηση). Πρόσθετα: όσα θέλει (π.χ. πτερύγια).' => 'Basic: the visitor picks one (e.g. lamination). Add-ons: as many as they want (e.g. flaps).',
		'Πάγιο: ναι / όχι. Ανά τεμάχιο: ο επισκέπτης γράφει ποσότητα. Ανά αντίτυπο: η ποσότητα ακολουθεί το τιράζ (μόνο με εκτύπωση). Ανά λέξη: π.χ. μετάφραση. «Κατόπιν συζήτησης»: η γραμμή δεν έχει ποσό και δεν μπαίνει στο σύνολο. Κενός ΦΠΑ = ο γενικός.' => 'Fixed: yes / no. Per item: the visitor types a quantity. Per copy: the quantity follows the print run (only with printing). Per word: e.g. translation. «On request»: the line has no amount and is not in the total. Empty VAT = the general one.',
		'Κάθε κείμενο που βλέπει ο επισκέπτης. Κενό αγγλικό = το ελληνικό. Placeholders: {rate}, {date}, {min}, {max}, {name}, {site}.' => 'Every text the visitor sees. Empty English = the Greek one. Placeholders: {rate}, {date}, {min}, {max}, {name}, {site}.',
		'English (κενό = το ελληνικό)' => 'English (empty = the Greek one)',

		// Admin: Test.
		'Χαρτί' => 'Paper',
		'Έξτρα' => 'Extras',
		'Χωρίς τιμή ακόμα (ο επισκέπτης θα δει «Περιλαμβάνεται»):' => 'No price yet (the visitor will see «Included»):',
		'Ο ίδιος υπολογισμός με το site, με την πλήρη ανάλυση που ο επισκέπτης δεν βλέπει ποτέ. Τίποτα δεν αποθηκεύεται.' => 'The same calculation as the site, with the full breakdown the visitor never sees. Nothing is saved.',
		'Υπολογισμός' => 'Calculate',
		'Τι βλέπει ο επισκέπτης' => 'What the visitor sees',
		'Ανάλυση (μόνο για σένα)' => 'Breakdown (only for you)',

		// Admin: Requests.
		'Όλα' => 'All',
		'Όνομα' => 'Name',
		'Αναζήτηση' => 'Search',
		'Εξαγωγή CSV' => 'Export CSV',
		'Ημερομηνία' => 'Date',
		'Σύνολο' => 'Total',
		'Κατάσταση' => 'State',
		'Κανένα αίτημα ακόμα.' => 'No requests yet.',
		'Νεότερα' => 'Newer',
		'Παλαιότερα' => 'Older',
		'Τα αιτήματα έρχονται και με email. Η σύνδεση με το orOS (πρόχειρη προσφορά που στέλνεις μετά τον έλεγχο) έρχεται με το orOS Connect.' => 'Requests also arrive by email. The link to orOS (a draft offer you send after checking it) comes with orOS Connect.',
		'Όλα τα αιτήματα' => 'All requests',
		'Το αίτημα δεν βρέθηκε.' => 'The request was not found.',
		'Τηλέφωνο' => 'Phone',
		'Βιβλίο' => 'Book',
		'Μήνυμα' => 'Message',
		'Γλώσσα' => 'Language',
		'Συναίνεση' => 'Consent',
		'Σημείωση (προαιρετική)' => 'Note (optional)',
		'Αποθήκευση' => 'Save',
		'Οριστική διαγραφή του αιτήματος;' => 'Delete the request for good?',
		'Διαγραφή αιτήματος' => 'Delete request',
		'Τι είδε ο επισκέπτης' => 'What the visitor saw',
		'Με τους συντελεστές της στιγμής που στάλθηκε το αίτημα.' => 'With the coefficients of the moment the request was sent.',

		// Admin: Tools.
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Τιμές, templates, έξτρα και κείμενα. Τα αιτήματα δεν μπαίνουν στο backup (για αυτά: Αιτήματα → Εξαγωγή CSV).' => 'Prices, templates, extras and texts. Requests are not in the backup (for those: Requests → Export CSV).',
		'Για developers' => 'For developers',
		'Ο υπολογιστής.' => 'The calculator.',
		'{ q: επιλογές, lang } → ποσά ανά υπηρεσία και σύνολο, χωρίς ανάλυση.' => '{ q: choices, lang } → amounts per service and total, without breakdown.',
		'Τα αιτήματα για άλλα plugins (π.χ. orOS Connect).' => 'Requests for other plugins (e.g. orOS Connect).',
		'(actions)' => '(actions)',
		'Μετά από νέο αίτημα: ( $id, $request ). Μετά από αλλαγή κατάστασης: ( $id, $state, $old ).' => 'After a new request: ( $id, $request ). After a state change: ( $id, $state, $old ).',
		'(φίλτρο)' => '(filter)',
		'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).' => 'Changes a visitor text: ( $text, $key, $lang ).',
		'Στο wp-config.php: σταματά τον υπολογιστή, τα REST routes και τη φόρμα.' => 'In wp-config.php: stops the calculator, the REST routes and the form.',

		// Admin: notices, footer.
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Η κατάσταση αποθηκεύτηκε.' => 'State saved.',
		'Το αίτημα διαγράφηκε.' => 'Request deleted.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.' => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Publishing Quote).' => 'Invalid backup file (the Publishing Quote settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import complete.',
		'Τα είδη επιμέλειας, τα μεγέθη, τα χαρτιά και τα δεσίματα χρειάζονται τουλάχιστον μία γραμμή: όπου τις αφαίρεσες όλες, έμειναν οι προηγούμενες.' => 'Editing kinds, sizes, papers and bindings need at least one row: where you removed them all, the previous ones stayed.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',

		// Requests: states, owner email, privacy.
		'Σε επικοινωνία' => 'In contact',
		'Στο orOS' => 'In orOS',
		'Κλειστό' => 'Closed',
		'Νέο' => 'New',
		'Σελίδες' => 'Pages',
		'χειρόγραφο %1$s (%2$d λέξεις), βιβλίο %3$d' => 'manuscript %1$s (%2$d words), book %3$d',
		'(εκτίμηση)' => '(estimate)',
		'σελιδοποίηση %s' => 'typesetting %s',
		'ελάχιστη χρέωση' => 'minimum charge',
		'ΦΠΑ' => 'VAT',
		'[%1$s] Νέο αίτημα προσφοράς: %2$s, %3$s' => '[%1$s] New quote request: %2$s, %3$s',
		'Ο επισκέπτης είδε μόνο τα ποσά ανά υπηρεσία και το σύνολο. Η προσφορά φεύγει μόνο όταν τη στείλεις εσύ.' => 'The visitor saw only the amounts per service and the total. The offer goes out only when you send it.',
		'Αιτήματα προσφοράς (Publishing Quote)' => 'Quote requests (Publishing Quote)',
		'Αιτήματα προσφοράς' => 'Quote requests',
		'Προσφορά' => 'Quote',
	);

	public static function init(): void {
		add_filter( 'gettext_publishing-quote', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current user (admin screens, owner email). */
	public static function lang(): string {
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		$uid    = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$choice = $uid ? (string) get_user_meta( $uid, 'rs_lang', true ) : '';
		if ( 'el' === $choice || 'en' === $choice ) {
			$lang = $choice;
		} else {
			$locale = $uid && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_publishing-quote filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'publishing-quote' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
