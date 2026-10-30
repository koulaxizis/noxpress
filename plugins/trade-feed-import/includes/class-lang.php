<?php
/**
 * TFI_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. The plugin has no visitor texts.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Trade Feed Import χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Trade Feed Import needs WooCommerce to be active — the plugin stays inactive until it is.',
		'Το εξώφυλλο δεν είναι εικόνα JPEG, PNG, GIF ή WebP έως 10 MB.' => 'The cover is not a JPEG, PNG, GIF or WebP image of up to 10 MB.',
		'Τίτλος' => 'Title',
		'Περιγραφή' => 'Description',
		'Σύντομη περιγραφή' => 'Short description',
		'Εξώφυλλο' => 'Cover',
		'Ιδιότητες' => 'Attributes',
		'Διαστάσεις και βάρος' => 'Dimensions and weight',
		'Ακυρώθηκε.' => 'Cancelled.',
		'Ο συγχρονισμός σταμάτησε: κράτησε πάνω από 6 ώρες ή 500 βήματα.' => 'The sync stopped: it ran for more than 6 hours or 500 steps.',
		'Ο εκδότης δεν έδωσε κανέναν τίτλο: τα προϊόντα σου έμειναν όπως ήταν. Έλεγξε τη σύνδεση.' => 'The publisher listed no title at all: your products were left as they were. Check the connection.',
		'Στο feed: %1$d · ενημερώθηκαν: %2$d · νέα: %3$d · συνδέθηκαν: %4$d · εκτός feed: %5$d · σφάλματα: %6$d' => 'In the feed: %1$d · updated: %2$d · new: %3$d · linked: %4$d · left the feed: %5$d · errors: %6$d',
		'Το TFI_DISABLE είναι ενεργό στο wp-config.php: καμία κλήση στο Trade Feed.' => 'TFI_DISABLE is on in wp-config.php: no call to the Trade Feed.',
		'Ο αποθηκευμένος κωδικός δεν διαβάζεται πια (άλλαξαν τα κλειδιά του wp-config.php). Γράψε ξανά τον κωδικό στη Σύνδεση.' => 'The saved password cannot be read any more (the keys in wp-config.php changed). Enter the password again under Connection.',
		'Συμπλήρωσε πρώτα τη διεύθυνση του εκδότη, το όνομα χρήστη και τον κωδικό στη Σύνδεση.' => 'First fill in the publisher\'s address, the username and the password under Connection.',
		'Ο εκδότης ζήτησε παύση στις κλήσεις (όριο κλήσεων). Ξαναδοκίμασε σε %d δευτερόλεπτα.' => 'The publisher asked for a pause in calls (rate limit). Try again in %d seconds.',
		'Ο εκδότης δεν απάντησε: %s' => 'The publisher did not answer: %s',
		'Ο εκδότης ζήτησε παύση στις κλήσεις (όριο κλήσεων). Ο συγχρονισμός συνεχίζει αργότερα μόνος του.' => 'The publisher asked for a pause in calls (rate limit). The sync goes on later by itself.',
		'Η διεύθυνση του εκδότη οδηγεί αλλού (%s). Γράψε τη διεύθυνση όπως είναι στο email.' => 'The publisher\'s address leads elsewhere (%s). Type the address as it is in the e-mail.',
		'Ο εκδότης δεν δέχτηκε το όνομα χρήστη ή τον κωδικό. Έλεγξε ότι αντέγραψες τον κωδικό του Trade Feed όπως ήρθε στο email (με τα κενά ή χωρίς).' => 'The publisher did not accept the username or the password. Check that you copied the Trade Feed password as it came in the e-mail (with or without the spaces).',
		'Ο εκδότης δεν επιτρέπει την κλήση: %s' => 'The publisher does not allow the call: %s',
		'Σε αυτή τη διεύθυνση δεν υπάρχει Trade Feed (δεν βρέθηκε το tra/v1). Έλεγξε τη διεύθυνση του εκδότη.' => 'There is no Trade Feed at this address (tra/v1 was not found). Check the publisher\'s address.',
		'Ο εκδότης απάντησε με σφάλμα %1$d. %2$s' => 'The publisher answered with error %1$d. %2$s',
		'Η απάντηση του εκδότη δεν είναι έγκυρο JSON.' => 'The publisher\'s answer is not valid JSON.',
		'Αρχή' => 'Start',
		'Τίτλοι' => 'Titles',
		'Ρυθμίσεις' => 'Settings',
		'Ιστορικό' => 'History',
		'Εργαλεία' => 'Tools',
		'Trade Feed Import' => 'Trade Feed Import',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Οι τίτλοι του εκδότη σου στο δικό σου κατάστημα, με τιμή και διαθεσιμότητα που ενημερώνονται μόνες τους κάθε μέρα.' => 'Your publisher\'s titles in your own shop, with price and availability that update by themselves every day.',
		'Σύνδεση' => 'Connection',
		'Εισαγωγή τίτλων' => 'Import the titles',
		'Έτοιμο' => 'Done',
		'Βήμα 1: Σύνδεση με τον εκδότη' => 'Step 1: Connect to the publisher',
		'Ο εκδότης σού έστειλε ένα email με τίτλο «Πρόσβαση στο Trade Feed». Άνοιξέ το, επίλεξε όλο το κείμενο (Ctrl+A), αντίγραψέ το (Ctrl+C) και επικόλλησέ το εδώ (Ctrl+V).' => 'The publisher sent you an e-mail titled «Trade Feed access». Open it, select all its text (Ctrl+A), copy it (Ctrl+C) and paste it here (Ctrl+V).',
		'Επικόλλησε εδώ ολόκληρο το email…' => 'Paste the whole e-mail here…',
		'Δεν έχεις το email; Γράψε τα στοιχεία ένα-ένα' => 'No e-mail? Type the details one by one',
		'Διεύθυνση του εκδότη (π.χ. glarolykoi.net)' => 'The publisher\'s address (e.g. glarolykoi.net)',
		'Όνομα χρήστη' => 'Username',
		'Κωδικός' => 'Password',
		'Ο κωδικός φυλάσσεται κρυπτογραφημένος και χρησιμοποιείται μόνο για να διαβάζει τον κατάλογο του εκδότη. Δεν είναι ο κωδικός σύνδεσης στο site του.' => 'The password is kept encrypted and is only used to read the publisher\'s catalogue. It is not the password for logging in to their site.',
		'Η σύνδεση πέτυχε. Ο εκδότης «%1$s» έχει %2$d τίτλους για σένα.' => 'Connected. The publisher «%1$s» has %2$d titles for you.',
		'Η σύνδεση πέτυχε (%s).' => 'Connected (%s).',
		'Η έκπτωσή σου: %s%%.' => 'Your discount: %s%%.',
		'Βήμα 2: Εισαγωγή των τίτλων' => 'Step 2: Import the titles',
		'Σε ποια κατηγορία του καταστήματός σου να μπουν τα βιβλία;' => 'Which category of your shop should the books go into?',
		'Σε νέα κατηγορία με όνομα:' => 'Into a new category named:',
		'Σε κατηγορία που έχω ήδη:' => 'Into a category I already have:',
		'— διάλεξε —' => '— choose —',
		'Να φαίνονται αμέσως στους πελάτες σου;' => 'Should your customers see them right away?',
		'Όχι ακόμα: να μπουν ως πρόχειρα, να τα δω πρώτα (προτείνεται)' => 'Not yet: add them as drafts so I can check them first (recommended)',
		'Ναι, να φαίνονται αμέσως' => 'Yes, show them right away',
		'Φέρε όλους τους τίτλους' => 'Bring all the titles',
		'Κάθε βιβλίο έρχεται με εξώφυλλο, ISBN, συγγραφείς, περιγραφή και την τιμή λιανικής του εκδότη. Αν έχεις ήδη στο κατάστημα ένα βιβλίο με το ίδιο ISBN, δεν φτιάχνεται δεύτερο: το δικό σου απλώς παίρνει τιμή και διαθεσιμότητα από τον εκδότη. Από αύριο, τιμές και διαθεσιμότητα ενημερώνονται μόνες τους κάθε πρωί.' => 'Every book comes with its cover, ISBN, authors, description and the publisher\'s retail price. If your shop already has a book with the same ISBN, no second one is made: yours simply takes its price and availability from the publisher. From tomorrow, prices and availability update by themselves every morning.',
		'Λάθος στοιχεία; Σύνδεση από την αρχή' => 'Wrong details? Connect again from the start',
		'Φέρνουμε τους τίτλους…' => 'Bringing the titles…',
		'Διαβάστηκαν %1$d τίτλοι · νέα προϊόντα: %2$d · ενημερώθηκαν: %3$d' => '%1$d titles read · new products: %2$d · updated: %3$d',
		'Μπορείς να κλείσεις αυτή τη σελίδα: η εισαγωγή συνεχίζει μόνη της. Η σελίδα ανανεώνεται κάθε λίγα δευτερόλεπτα.' => 'You can close this page: the import goes on by itself. The page refreshes every few seconds.',
		'Ο εκδότης ζήτησε μικρή παύση. Συνεχίζουμε σε περίπου %d λεπτά.' => 'The publisher asked for a short pause. We go on in about %d minutes.',
		'Διακοπή' => 'Stop',
		'Έτοιμο!' => 'Done!',
		'Μπήκαν %1$d νέα βιβλία στο κατάστημά σου. %2$d βιβλία που είχες ήδη συνδέθηκαν με τον εκδότη.' => '%1$d new books were added to your shop. %2$d books you already had were linked to the publisher.',
		'Είναι πρόχειρα: οι πελάτες σου δεν τα βλέπουν ακόμα. Άνοιξέ τα, δες ότι είναι όπως τα θέλεις και πάτα «Δημοσίευση» (ή διάλεξέ τα όλα στη λίστα προϊόντων → Μαζική επεξεργασία → Κατάσταση: Δημοσιευμένο).' => 'They are drafts: your customers do not see them yet. Open them, check that they are as you want them and press «Publish» (or select them all in the product list → Bulk actions → Edit → Status: Published).',
		'Δες τα πρόχειρα προϊόντα' => 'See the draft products',
		'Δες τα προϊόντα' => 'See the products',
		'Δεν χρειάζεται να κάνεις τίποτε άλλο. Κάθε πρωί τιμές, διαθεσιμότητα και προπαραγγελίες ενημερώνονται μόνες τους. Ό,τι αλλάξεις εσύ σε ένα βιβλίο (τίτλο, περιγραφή, εξώφυλλο) δεν θα το ξαναγράψουμε.' => 'There is nothing else to do. Every morning prices, availability and pre-orders update by themselves. Whatever you change in a book yourself (title, description, cover) we will not write over.',
		'Κατάσταση' => 'Status',
		'Εκδότης' => 'Publisher',
		'Βιβλία από τον εκδότη στο κατάστημά σου' => 'Books from the publisher in your shop',
		'Με δικές σου αλλαγές (κλειδωμένα πεδία)' => 'With your own changes (locked fields)',
		'Δεν τα δίνει πια ο εκδότης' => 'No longer offered by the publisher',
		'Τελευταίος συγχρονισμός' => 'Last sync',
		'Σφάλμα' => 'Error',
		'Εντάξει' => 'OK',
		'Επόμενος συγχρονισμός' => 'Next sync',
		'Κλειστός (Ρυθμίσεις)' => 'Off (Settings)',
		'Συγχρονισμός τώρα' => 'Sync now',
		'Όλοι οι τίτλοι του εκδότη' => 'All the publisher\'s titles',
		'Η λίστα των τίτλων γεμίζει με τον πρώτο συγχρονισμό. Πάτα «Συγχρονισμός τώρα» στην Αρχή.' => 'The list of titles fills in with the first sync. Press «Sync now» on the Start tab.',
		'Διαθέσιμο' => 'In stock',
		'Εξαντλημένο' => 'Out of stock',
		'Σε αναμονή' => 'On backorder',
		'Προπαραγγελία' => 'Pre-order',
		'Οι τίτλοι του εκδότη' => 'The publisher\'s titles',
		'%1$d τίτλοι, από τον συγχρονισμό της %2$s.' => '%1$d titles, from the sync of %2$s.',
		'Όλα' => 'All',
		'Λιανική' => 'Retail',
		'Χονδρική' => 'Trade',
		'Διαθεσιμότητα' => 'Availability',
		'Στο κατάστημά σου' => 'In your shop',
		'Συνδεδεμένο' => 'Linked',
		'Εισήχθη' => 'Imported',
		'Δικές σου αλλαγές:' => 'Your own changes:',
		'Υπάρχει ήδη' => 'Already exists',
		'Όχι ακόμα' => 'Not yet',
		'Εισαγωγή όσων λείπουν' => 'Import the missing ones',
		'Σύνδεση όσων υπάρχουν ήδη (μόνο τιμή και διαθεσιμότητα)' => 'Link the ones that already exist (price and availability only)',
		'Πάρε ξανά τα πάντα από τον εκδότη (σβήνει τις δικές σου αλλαγές)' => 'Take everything from the publisher again (erases your own changes)',
		'Αποσύνδεση (το προϊόν μένει, δεν ενημερώνεται πια)' => 'Unlink (the product stays, it is no longer updated)',
		'Εφαρμογή στα επιλεγμένα' => 'Apply to the selected',
		'Καθημερινός συγχρονισμός' => 'Daily sync',
		'Κάθε μέρα: τιμή, διαθεσιμότητα και προπαραγγελίες από τον εκδότη' => 'Every day: price, availability and pre-orders from the publisher',
		'Ώρα' => 'Time',
		'Νέοι τίτλοι του εκδότη μπαίνουν μόνοι τους στο κατάστημα' => 'New titles of the publisher are added to the shop by themselves',
		'Ένας τίτλος που δεν τον δίνει πια ο εκδότης' => 'A title the publisher no longer offers',
		'Γίνεται «Εξαντλημένο»' => 'Becomes «Out of stock»',
		'Γίνεται πρόχειρο (κρύβεται από τους πελάτες)' => 'Becomes a draft (hidden from customers)',
		'Η τιμή λιανικής ακολουθεί πάντα τον εκδότη (ενιαία τιμή βιβλίου). Την «Τιμή προσφοράς» την ορίζεις εσύ και δεν την αγγίζουμε. Αν μετράς δικό σου απόθεμα σε ένα βιβλίο, η διαθεσιμότητά του δεν αλλάζει ποτέ από εδώ.' => 'The retail price always follows the publisher (fixed book price). The «Sale price» is yours to set and we never touch it. If you count your own stock for a book, its availability never changes from here.',
		'Νέα προϊόντα' => 'New products',
		'Πρόχειρο' => 'Draft',
		'Δημοσιευμένο' => 'Published',
		'Κατηγορία' => 'Category',
		'Η προεπιλεγμένη του WooCommerce' => 'WooCommerce\'s default',
		'Ανά κατηγορία του εκδότη (προαιρετικό)' => 'Per category of the publisher (optional)',
		'— η παραπάνω —' => '— the one above —',
		'Κατηγορία ΦΠΑ' => 'Tax class',
		'Αυτόματα, με τον συντελεστή ΦΠΑ του εκδότη' => 'Automatic, with the publisher\'s VAT rate',
		'Αυτόματα: κάθε βιβλίο παίρνει την κατηγορία ΦΠΑ του καταστήματός σου που έχει τον ίδιο συντελεστή με του εκδότη (π.χ. 6%), αλλιώς την κανονική.' => 'Automatic: each book gets the tax class of your shop that has the same rate as the publisher\'s (e.g. 6%), otherwise the standard one.',
		'Ο εκδότης ως brand του προϊόντος' => 'The publisher as the product\'s brand',
		'Η τιμή χονδρικής ως «Κόστος» του προϊόντος' => 'The trade price as the product\'s «Cost»',
		'Το πεδίο «Κόστος» του WooCommerce δεν είναι ενεργό σε αυτό το κατάστημα: η τιμή χονδρικής φαίνεται μόνο στο πλαίσιο «Trade Feed» της σελίδας του προϊόντος.' => 'WooCommerce\'s «Cost» field is not on in this shop: the trade price shows only in the «Trade Feed» box of the product page.',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Εισαγωγή' => 'Import',
		'Αποσύνδεση' => 'Unlink',
		'Ενημέρωση' => 'Update',
		'Δικές σου αλλαγές' => 'Your own changes',
		'Εκτός feed' => 'Left the feed',
		'Ξανά στο feed' => 'Back in the feed',
		'Συγχρονισμός' => 'Sync',
		'Τίποτα ακόμα.' => 'Nothing yet.',
		'Πότε' => 'When',
		'Τι' => 'What',
		'Προϊόν' => 'Product',
		'Λεπτομέρειες' => 'Details',
		'Καθαρισμός ιστορικού' => 'Clear the history',
		'κωδικός αποθηκευμένος' => 'password saved',
		'Αν ο εκδότης σού έστειλε νέο κωδικό, αποσυνδέσου και κάνε ξανά τη σύνδεση με το νέο email. Τα προϊόντα σου δεν αλλάζουν.' => 'If the publisher sent you a new password, disconnect and connect again with the new e-mail. Your products do not change.',
		'Αποσύνδεση και νέα σύνδεση' => 'Disconnect and connect again',
		'Αντίγραφο ρυθμίσεων' => 'Settings backup',
		'Εξαγωγή (JSON)' => 'Export (JSON)',
		'Το αρχείο δεν περιέχει τον κωδικό.' => 'The file does not contain the password.',
		'Για προγραμματιστές' => 'For developers',
		'Trade Feed' => 'Trade Feed',
		'συνδεδεμένο: μόνο τιμή και διαθεσιμότητα' => 'linked: price and availability only',
		'Τιμή χονδρικής:' => 'Trade price:',
		'Τελευταίος συγχρονισμός:' => 'Last sync:',
		'Ο εκδότης δεν δίνει πια αυτόν τον τίτλο.' => 'The publisher no longer offers this title.',
		'Δικές σου αλλαγές (δεν τις ξαναγράφουμε):' => 'Your own changes (we do not write over them):',
		'Η τιμή λιανικής και η διαθεσιμότητα έρχονται από τον εκδότη κάθε μέρα.' => 'The retail price and availability come from the publisher every day.',
		'Αποσυνδέθηκες. Τα προϊόντα σου έμειναν όπως ήταν. Κάνε τη σύνδεση με τα νέα στοιχεία.' => 'Disconnected. Your products were left as they were. Connect with the new details.',
		'Σταμάτησε. Όσα βιβλία μπήκαν ήδη έμειναν.' => 'Stopped. The books already added stay.',
		'Ο συγχρονισμός ξεκίνησε.' => 'The sync started.',
		'Ένας συγχρονισμός τρέχει ήδη.' => 'A sync is already running.',
		'Το ιστορικό καθαρίστηκε.' => 'The history was cleared.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'τη διεύθυνση του εκδότη' => 'the publisher\'s address',
		'το όνομα χρήστη' => 'the username',
		'τον κωδικό' => 'the password',
		'Δεν βρήκα %s. Επικόλλησε ολόκληρο το email ή συμπλήρωσε τα πεδία ένα-ένα.' => 'I could not find %s. Paste the whole e-mail or fill in the fields one by one.',
		'Ο κωδικός δεν μπορεί να φυλαχτεί κρυπτογραφημένος σε αυτόν τον server (λείπει το OpenSSL). Ζήτησε βοήθεια από την εταιρεία φιλοξενίας.' => 'The password cannot be kept encrypted on this server (OpenSSL is missing). Ask your hosting company for help.',
		'Κάνε πρώτα τη σύνδεση.' => 'Connect first.',
		'Διάλεξε κατηγορία ή γράψε όνομα για νέα.' => 'Choose a category or type a name for a new one.',
		'Δεν επιλέχθηκε κανένας τίτλος.' => 'No title was selected.',
		'Έγινε σε %d τίτλους.' => 'Done for %d titles.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Trade Feed Import).' => 'Invalid backup file (the Trade Feed Import settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import completed.',
		'Απεγκατάσταση' => 'Uninstall',
		'Όταν διαγράψω το plugin, να σβηστούν και τα προϊόντα που έφερε' => 'When I delete the plugin, delete the products it brought too',
		'Κλειστό από προεπιλογή: τα προϊόντα μένουν στο κατάστημα. Τα δικά σου προϊόντα που απλώς συνδέθηκαν δεν σβήνονται ποτέ.' => 'Off by default: the products stay in the shop. Your own products that were only linked are never deleted.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_trade-feed-import', array( __CLASS__, 'filter' ), 10, 3 );
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
			$locale = $uid && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_trade-feed-import filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'trade-feed-import' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
