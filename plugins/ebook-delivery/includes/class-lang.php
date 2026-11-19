<?php
/**
 * EBD_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English.
 *
 * The texts that buyers see (watermark line, Ex libris, library, e-mail
 * line) are settings, not msgids (Bible §14.17, EBD_Settings::text()).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Ebook Delivery χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Ebook Delivery needs WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Κρυπτογραφημένο αρχείο ή DRM: στάλθηκε το αρχικό' => 'Encrypted file or DRM: the original was sent',
		'Χαλασμένο αρχείο: στάλθηκε το αρχικό' => 'Damaged file: the original was sent',
		'Μορφή που δεν δέχεται υδατογράφημα: στάλθηκε το αρχικό' => 'Format that does not take a watermark: the original was sent',
		'Η κατάληξη λέει PDF αλλά το αρχείο δεν είναι PDF: στάλθηκε το αρχικό' => 'The extension says PDF but the file is not a PDF: the original was sent',
		'Η κατάληξη λέει EPUB αλλά το αρχείο δεν είναι EPUB: στάλθηκε το αρχικό' => 'The extension says EPUB but the file is not an EPUB: the original was sent',
		'Ύποπτο αρχείο ZIP (ονόματα ή μέγεθος): στάλθηκε το αρχικό' => 'Suspicious ZIP file (names or size): the original was sent',
		'Μεγαλύτερο από το όριο μεγέθους: στάλθηκε το αρχικό' => 'Larger than the size limit: the original was sent',
		'Λίγος ελεύθερος χώρος στον δίσκο: στάλθηκε το αρχικό' => 'Little free disk space: the original was sent',
		'Ο ιδιωτικός φάκελος δεν γράφεται: στάλθηκε το αρχικό' => 'The private folder is not writable: the original was sent',
		'Αρχείο σε άλλον server: στάλθηκε όπως είναι' => 'File on another server: sent as it is',
		'Σφάλμα ανάγνωσης ή εγγραφής: στάλθηκε το αρχικό' => 'Read or write error: the original was sent',
		'Απρόσμενο σφάλμα: στάλθηκε το αρχικό' => 'Unexpected error: the original was sent',
		'Χωρίς υδατογράφημα' => 'No watermark',
		'Το Ebook Delivery στέλνει τα αρχεία αυτού του προϊόντος όπως είναι.' => 'Ebook Delivery sends the files of this product as they are.',
		'Υδατογράφημα' => 'Watermark',
		'Βιβλιοθήκη' => 'Library',
		'Όρια λήψεων' => 'Download limits',
		'Ποιος το μοιράστηκε;' => 'Who shared it?',
		'Κατάσταση' => 'Status',
		'Εργαλεία' => 'Tools',
		'Ebook Delivery' => 'Ebook Delivery',
		'Ρυθμίσεις' => 'Settings',
		'Όταν κατεβάζεις ένα ψηφιακό βιβλίο (PDF ή EPUB) που αγόρασες, το αρχείο που παίρνεις έχει το όνομά σου, τον αριθμό της παραγγελίας και έναν κωδικό, ορατά στις σελίδες και μέσα στα στοιχεία του αρχείου (μαζί με το ονοματεπώνυμό σου). Έτσι προστατεύουμε τους δημιουργούς από την παράνομη διανομή. Το αντίγραφο με τα στοιχεία σου κρατιέται στον server μας για λίγες ημέρες, ώστε οι επόμενες λήψεις να είναι γρήγορες, και μετά σβήνεται. Ο κωδικός αντιστοιχεί στην παραγγελία σου μόνο για εμάς.' => 'When you download an ebook (PDF or EPUB) you bought, the file you get carries your name, the order number and a code, visible on the pages and inside the file\'s details (together with your full name). This is how we protect creators from illegal distribution. The copy with your details is kept on our server for a few days, so that later downloads are fast, and is then deleted. Only we can match the code to your order.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Κάθε PDF και EPUB που κατεβαίνει παίρνει το όνομα του αγοραστή, τον αριθμό της παραγγελίας και έναν κρυφό κωδικό. Οι λήψεις μένουν του WooCommerce: έλεγχος συνδέσμου, όρια, λήξη και ιστορικό δεν αλλάζουν.' => 'Every PDF and EPUB that is downloaded gets the buyer\'s name, the order number and a hidden code. Downloads stay WooCommerce\'s: link check, limits, expiry and history do not change.',
		'Το EBD_DISABLE είναι ενεργό στο wp-config.php: τα αρχεία στέλνονται χωρίς υδατογράφημα και η βιβλιοθήκη δεν εμφανίζεται.' => 'EBD_DISABLE is on in wp-config.php: files are sent without a watermark and the library is not shown.',
		'Κάποια αρχεία στάλθηκαν χωρίς υδατογράφημα τις τελευταίες 7 ημέρες.' => 'Some files were sent without a watermark in the last 7 days.',
		'Δες την Κατάσταση' => 'See Status',
		'Ελληνικά' => 'Greek',
		'English (κενό = το ελληνικό)' => 'English (empty = the Greek one)',
		'Λειτουργία' => 'Mode',
		'Ανενεργό: τα αρχεία στέλνονται όπως πριν, χωρίς βιβλιοθήκη' => 'Off: files are sent as before, without the library',
		'Δοκιμή: υδατογράφημα και βιβλιοθήκη μόνο για τους διαχειριστές καταστήματος (κατέβασε ένα αρχείο από μια δική σου δοκιμαστική παραγγελία)' => 'Test: watermark and library only for shop managers (download a file from a test order of your own)',
		'Ενεργό: για όλους τους αγοραστές' => 'On: for every buyer',
		'Αν ένα αρχείο δεν δέχεται υδατογράφημα (κρυπτογραφημένο, χαλασμένο, πολύ μεγάλο), ο αγοραστής παίρνει το αρχικό και εσύ βλέπεις σημείωση στην Κατάσταση. Κανείς δεν μένει χωρίς το βιβλίο του.' => 'If a file does not take a watermark (encrypted, damaged, too big), the buyer gets the original and you see a note in Status. Nobody is left without their book.',
		'Αρχεία' => 'Files',
		'PDF: γραμμή στο κάτω μέρος κάθε σελίδας, κρυφός κωδικός και στοιχεία στα metadata' => 'PDF: a line at the bottom of every page, a hidden code and details in the metadata',
		'EPUB: σελίδα Ex libris μετά το εξώφυλλο, γραμμή στο τέλος κάθε κεφαλαίου και στοιχεία στα metadata' => 'EPUB: an Ex libris page after the cover, a line at the end of every chapter and details in the metadata',
		'Τα υπόλοιπα αρχεία (MP3, ZIP, MOBI…) στέλνονται όπως είναι. Ένα προϊόν εξαιρείται από το κουτί «Χωρίς υδατογράφημα» στις επιλογές λήψης του.' => 'Other files (MP3, ZIP, MOBI…) are sent as they are. A product is left out with the «No watermark» box in its download options.',
		'Όνομα του αγοραστή στη γραμμή και στο Ex libris' => 'Buyer\'s name in the line and in the Ex libris',
		'Όνομα και αρχικό επωνύμου (Μαρία Π.)' => 'First name and initial of the surname (Maria P.)',
		'Ονοματεπώνυμο (Μαρία Παπαδοπούλου)' => 'Full name (Maria Papadopoulou)',
		'Τα metadata του αρχείου κρατούν πάντα το ονοματεπώνυμο. Το email δεν γράφεται πουθενά στο αρχείο.' => 'The file\'s metadata always keep the full name. The e-mail is written nowhere in the file.',
		'Μέγεθος γραμμάτων της γραμμής (5–12 pt)' => 'Font size of the line (5–12 pt)',
		'Μέγιστο μέγεθος αρχείου για υδατογράφημα (MB, 1–2048)' => 'Largest file size for the watermark (MB, 1–2048)',
		'Ημέρες που κρατιέται ένα υδατογραφημένο αντίγραφο (1–30)' => 'Days a watermarked copy is kept (1–30)',
		'Το αντίγραφο φτιάχνεται στην πρώτη λήψη και ξαναχρησιμοποιείται στις επόμενες. Μετά σβήνεται και ξαναφτιάχνεται όταν χρειαστεί.' => 'The copy is made on the first download and reused on the next ones. Then it is deleted and made again when needed.',
		'Όνομα αρχείου' => 'File name',
		'Ο αγοραστής παίρνει «Συγγραφέας - Τίτλος.pdf» αντί για το όνομα του αρχείου στον server' => 'The buyer gets «Author - Title.pdf» instead of the file\'s name on the server',
		'Χαρακτηριστικό με τον συγγραφέα' => 'Attribute with the author',
		'— Κανένα (μόνο ο τίτλος) —' => '— None (title only) —',
		'Ισχύει για όλα τα αρχεία του προϊόντος, και για όσα δεν παίρνουν υδατογράφημα (π.χ. MP3). Όταν ένα προϊόν έχει πολλά αρχεία, μπαίνει και το όνομα του αρχείου.' => 'Applies to all the product\'s files, also to those that get no watermark (e.g. MP3). When a product has several files, the file\'s name is added too.',
		'Κείμενα υδατογραφήματος' => 'Watermark texts',
		'Γλώσσα των κειμένων για τους αγοραστές' => 'Language of the texts for buyers',
		'Αυτόματα, από τη γλώσσα του site' => 'Automatic, from the site\'s language',
		'English' => 'English',
		'Μεταβλητές: {name} (το όνομα όπως ορίστηκε παραπάνω), {order} (αριθμός παραγγελίας), {date} (ημερομηνία παραγγελίας), {site} (όνομα του site), {code} (ο κρυφός κωδικός).' => 'Variables: {name} (the name as set above), {order} (order number), {date} (order date), {site} (site name), {code} (the hidden code).',
		'Γραμμή στις σελίδες (PDF) και στο τέλος των κεφαλαίων (EPUB)' => 'Line on the pages (PDF) and at the end of chapters (EPUB)',
		'Τίτλος της σελίδας Ex libris (EPUB)' => 'Title of the Ex libris page (EPUB)',
		'Ex libris: πρώτη γραμμή' => 'Ex libris: first line',
		'Ex libris: δεύτερη γραμμή' => 'Ex libris: second line',
		'Ex libris: σημείωση (κενό = καμία)' => 'Ex libris: note (empty = none)',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Η βιβλιοθήκη μου' => 'My library',
		'Σελίδα με τα ψηφιακά αρχεία του πελάτη στον λογαριασμό του, ένα ανά τίτλο με εξώφυλλο' => 'A page with the customer\'s digital files in their account, one per title with its cover',
		'Αντικαθιστά την καρτέλα «Λήψεις» του WooCommerce (η διεύθυνσή της συνεχίζει να δουλεύει)' => 'Replaces WooCommerce\'s «Downloads» tab (its address keeps working)',
		'Σύνδεσμος στη βιβλιοθήκη στα email παραγγελίας που έχουν ψηφιακά αρχεία (πελάτες με λογαριασμό)' => 'Link to the library in order e-mails that have digital files (customers with an account)',
		'Φαίνεται όταν η λειτουργία είναι «Ενεργό», ή «Δοκιμή» για τους διαχειριστές καταστήματος. Οι επισκέπτες χωρίς λογαριασμό κατεβάζουν όπως πριν από το email της παραγγελίας.' => 'Shown when the mode is «On», or «Test» for shop managers. Guests without an account download from the order e-mail as before.',
		'Κείμενα της βιβλιοθήκης' => 'Library texts',
		'Μεταβλητές: {date} (ημερομηνία), {n} (λήψεις που απομένουν), {tab} (το όνομα της καρτέλας).' => 'Variables: {date} (date), {n} (downloads left), {tab} (the tab\'s name).',
		'Όνομα της καρτέλας και τίτλος της σελίδας' => 'Tab name and page title',
		'Όταν δεν υπάρχει κανένα αρχείο' => 'When there are no files',
		'Κουμπί προς το κατάστημα' => 'Button to the shop',
		'Ημερομηνία αγοράς' => 'Purchase date',
		'Λήψεις που απομένουν' => 'Downloads left',
		'Χωρίς όριο λήψεων' => 'No download limit',
		'Λήξη πρόσβασης' => 'Access expires',
		'Γραμμή στο email της παραγγελίας' => 'Line in the order e-mail',
		'Το WooCommerce κρατά όριο λήψεων και λήξη σε κάθε προϊόν (Δεδομένα προϊόντος → Γενικά) και τα αντιγράφει σε κάθε αγορά. Για έναν πελάτη που έχασε το αρχείο του, η παραγγελία έχει το κουτί «Δικαιώματα λήψης», όπου αλλάζεις τις λήψεις που απομένουν και τη λήξη. Τίποτα εδώ δεν αλλάζει αγορές που έγιναν ήδη.' => 'WooCommerce keeps a download limit and an expiry on every product (Product data → General) and copies them into every purchase. For a customer who lost their file, the order has the «Downloadable product permissions» box, where you change the downloads left and the expiry. Nothing here changes purchases already made.',
		'Προεπιλογές για νέα προϊόντα' => 'Defaults for new products',
		'Νέο downloadable προϊόν με κενά πεδία παίρνει αυτές τις τιμές' => 'A new downloadable product with empty fields gets these values',
		'Όριο λήψεων (κενό = απεριόριστες)' => 'Download limit (empty = unlimited)',
		'Λήξη πρόσβασης σε ημέρες (κενό = χωρίς λήξη)' => 'Access expiry in days (empty = never)',
		'Αποθήκευση' => 'Save',
		'Μαζική εφαρμογή σε όλα τα downloadable προϊόντα' => 'Bulk apply to all downloadable products',
		'Έλεγχος (χωρίς αλλαγές)' => 'Check (no changes)',
		'Θα αλλάξουν %1$s από %2$s downloadable προϊόντα.' => '%1$s of %2$s downloadable products will change.',
		'Εφαρμογή' => 'Apply',
		'Οι προηγούμενες τιμές γράφονται πρώτα σε αντίγραφο, ώστε να επαναφέρονται.' => 'The previous values are written to a backup first, so they can be restored.',
		'Αντίγραφο της τελευταίας μαζικής εφαρμογής' => 'Backup of the last bulk apply',
		'%1$s: %2$s προϊόντα.' => '%1$s: %2$s products.',
		'Επαναφορά' => 'Restore',
		'Λήψη αντιγράφου (JSON)' => 'Download backup (JSON)',
		'Η επαναφορά αγγίζει μόνο προϊόντα που έχουν ακόμα τις τιμές της μαζικής εφαρμογής· όσα άλλαξες με το χέρι μένουν όπως είναι.' => 'The restore touches only products that still have the bulk apply\'s values; those you changed by hand stay as they are.',
		'Βρήκες ένα βιβλίο σου σε κάποιο site; Γράψε τον κωδικό ED-… που υπάρχει στις σελίδες του (ή στη σελίδα Ex libris του EPUB), ή ανέβασε το αρχείο. Θα δεις από ποια παραγγελία βγήκε. Το αρχείο διαβάζεται μόνο για τον έλεγχο και δεν αποθηκεύεται.' => 'Found one of your books on some site? Enter the ED-… code printed on its pages (or on the EPUB\'s Ex libris page), or upload the file. You will see which order it came from. The file is read only for the check and is not stored.',
		'Κωδικός' => 'Code',
		'Αναζήτηση' => 'Search',
		'ή αρχείο PDF / EPUB' => 'or a PDF / EPUB file',
		'Έλεγχος αρχείου' => 'Check file',
		'Αποτέλεσμα' => 'Result',
		'Δεν βρέθηκε κωδικός. Το αρχείο ίσως δεν βγήκε από αυτό το site ή κάποιος έσβησε τα στοιχεία του.' => 'No code found. The file may not come from this site, or someone removed its details.',
		'Κανένα δικαίωμα λήψης του καταστήματος δεν έχει αυτόν τον κωδικό (άλλο site, ή η παραγγελία διαγράφηκε).' => 'No download permission of the shop has this code (another site, or the order was deleted).',
		'Παραγγελία' => 'Order',
		'Πελάτης' => 'Customer',
		'Προϊόν' => 'Product',
		'Λήψεις' => 'Downloads',
		'Πρόσβαση από' => 'Access since',
		'Ανενεργό' => 'Off',
		'Δοκιμή' => 'Test',
		'Ενεργό' => 'On',
		'Έλεγχος' => 'Check',
		'Ναι' => 'Yes',
		'Όχι' => 'No',
		'Επέκταση PHP zip (για EPUB)' => 'PHP zip extension (for EPUB)',
		'Επέκταση PHP zlib (για PDF)' => 'PHP zlib extension (for PDF)',
		'Ο ιδιωτικός φάκελος γράφεται' => 'The private folder is writable',
		'Ασφαλής' => 'Safe',
		'Ανοιχτός' => 'Open',
		'Άγνωστο' => 'Unknown',
		'Ο φάκελος δεν ανοίγει από τον browser (δοκιμή με HTTP)' => 'The folder does not open from a browser (HTTP test)',
		'Ο server σου αγνοεί το .htaccess (π.χ. nginx). Τα ονόματα των αρχείων δεν μαντεύονται, αλλά πρόσθεσε έναν κανόνα «deny all» για τον φάκελο στη ρύθμιση του server ή ζήτα το από τον host.' => 'Your server ignores .htaccess (e.g. nginx). File names cannot be guessed, but add a «deny all» rule for the folder in the server\'s configuration or ask your host.',
		'Δοκιμή φακέλου τώρα' => 'Test the folder now',
		'Ελεύθερος χώρος στον δίσκο: %s.' => 'Free disk space: %s.',
		'Ο ελεύθερος χώρος στον δίσκο δεν διαβάζεται σε αυτόν τον server.' => 'Free disk space cannot be read on this server.',
		'Μέθοδος λήψης του WooCommerce: %s (για τα υδατογραφημένα αρχεία πάντα «Force downloads»).' => 'WooCommerce download method: %s (watermarked files always use «Force downloads»).',
		'Αντίγραφα στον server' => 'Copies on the server',
		'%1$s αρχεία, %2$s.' => '%1$s files, %2$s.',
		'Διαγραφή όλων των αντιγράφων' => 'Delete all copies',
		'Ξαναφτιάχνονται στην επόμενη λήψη. Χρήσιμο αφού αλλάξεις ένα αρχείο-πηγή με το ίδιο όνομα και μέγεθος.' => 'They are made again on the next download. Useful after you replace a source file with one of the same name and size.',
		'Αρχεία που στάλθηκαν χωρίς υδατογράφημα' => 'Files sent without a watermark',
		'Ώρα' => 'Time',
		'Αρχείο' => 'File',
		'Αιτία' => 'Reason',
		'Κανένα. Όλα τα PDF και EPUB στάλθηκαν με υδατογράφημα.' => 'None. All PDF and EPUB files were sent with a watermark.',
		'Καθαρισμός λίστας' => 'Clear list',
		'Δοκιμή υδατογραφήματος' => 'Watermark test',
		'Ανέβασε ένα PDF ή EPUB και κατέβασε αμέσως το αντίγραφο με δοκιμαστικά στοιχεία (Μαρία Παπαδοπούλου, παραγγελία #1234), με τις τωρινές ρυθμίσεις. Δεν αποθηκεύεται τίποτα.' => 'Upload a PDF or EPUB and download the copy right away with sample details (Maria Papadopoulou, order #1234), with the current settings. Nothing is stored.',
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Ρυθμίσεις και προεπιλογές ορίων. Το μυστικό κλειδί των κωδικών δεν μπαίνει στο αρχείο: σε άλλο site οι κωδικοί είναι άλλοι.' => 'Settings and limit defaults. The secret key of the codes is not in the file: on another site the codes are different.',
		'Για developers' => 'For developers',
		'(φίλτρο)' => '(filter)',
		'Αν μια λήψη παίρνει υδατογράφημα: ( $stamp, $product, $download, $order ).' => 'Whether a download gets a watermark: ( $stamp, $product, $download, $order ).',
		'Η ορατή γραμμή: ( $text, $context ).' => 'The visible line: ( $text, $context ).',
		'Το καθαρό όνομα αρχείου χωρίς κατάληξη: ( $name, $product, $ext ).' => 'The clean file name without extension: ( $name, $product, $ext ).',
		'Οι τίτλοι της βιβλιοθήκης: ( $items, $user_id ).' => 'The library\'s titles: ( $items, $user_id ).',
		'Το slug της σελίδας στον λογαριασμό (προεπιλογή library). Μετά την αλλαγή αποθήκευσε τα permalinks.' => 'The slug of the account page (default library). After a change, save the permalinks.',
		'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).' => 'Changes a visitor text: ( $text, $key, $lang ).',
		'(action)' => '(action)',
		'Μετά από κάθε νέο αντίγραφο: ( $path, $context ).' => 'After every new copy: ( $path, $context ).',
		'Στο wp-config.php: τα αρχεία στέλνονται χωρίς υδατογράφημα και η βιβλιοθήκη δεν εμφανίζεται.' => 'In wp-config.php: files are sent without a watermark and the library is not shown.',
		'Οι προεπιλογές αποθηκεύτηκαν.' => 'The defaults were saved.',
		'Το αντίγραφο δεν γράφτηκε: η εφαρμογή σταμάτησε και ό,τι είχε αλλάξει επανήλθε.' => 'The backup was not written: the apply stopped and what had changed was put back.',
		'Άλλαξαν %s προϊόντα.' => '%s products changed.',
		'Επαναφέρθηκαν %1$s προϊόντα, %2$s έμειναν όπως είναι.' => '%1$s products restored, %2$s left as they are.',
		'Ο κωδικός έχει τη μορφή ED- και 8 χαρακτήρες.' => 'The code has the form ED- and 8 characters.',
		'Δεν επιλέχθηκε αρχείο.' => 'No file was chosen.',
		'Διαγράφηκαν %s αρχεία.' => '%s files deleted.',
		'Η λίστα καθαρίστηκε.' => 'The list was cleared.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'The settings were saved.',
		'Η γραμμή δεν έχει το {code}: ο κωδικός μένει μόνο κρυφός μέσα στο αρχείο.' => 'The line has no {code}: the code stays only hidden inside the file.',
		'Ο server δεν έχει την επέκταση PHP zip: τα EPUB στέλνονται χωρίς υδατογράφημα.' => 'The server has no PHP zip extension: EPUB files are sent without a watermark.',
		'Δεν επιλέχθηκε αρχείο ή είναι μεγαλύτερο από το όριο μεγέθους.' => 'No file was chosen, or it is larger than the size limit.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.' => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Ebook Delivery).' => 'Not a valid backup file (Ebook Delivery\'s settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'The import is complete.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'♡ Στήριξε το Noxpress' => '♡ Support Noxpress',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_ebook-delivery', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current admin (rs_lang first, then the locale). */
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

	/** gettext_ebook-delivery filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'ebook-delivery' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
