<?php
/**
 * EVT_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English.
 *
 * The visitor texts are settings, not msgids (Bible §14.17,
 * EVT_Settings::text()).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	/** Forced language (with_lang), '' = none. */
	private static $force = '';

	private static $dict = array(
		'Ρυθμίσεις' => 'Settings',
		'Κείμενα' => 'Texts',
		'Εργαλεία' => 'Tools',
		'Εκδηλώσεις' => 'Events',
		'Events — Ρυθμίσεις' => 'Events — Settings',
		'Εκδηλώσεις: ρυθμίσεις' => 'Events: settings',
		'Πότε' => 'When',
		'Κατάσταση' => 'Status',
		'Ημερομηνία' => 'Date',
		'Προγραμματισμένη' => 'Scheduled',
		'Αναβλήθηκε' => 'Postponed',
		'Νέα ημερομηνία' => 'New date',
		'Ακυρώθηκε' => 'Cancelled',
		'Χωρίς ημερομηνία' => 'No date',
		'Online' => 'Online',
		'Έγινε' => 'Ended',
		'Χώροι' => 'Venues',
		'Δοκιμή: τις σελίδες τις βλέπουν μόνο όσοι επεξεργάζονται άρθρα.' => 'Test: only people who edit posts see the pages.',
		'Ανενεργό: καμία σελίδα εκδήλωσης δεν φαίνεται.' => 'Off: no event page shows.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Events' => 'Events',
		'Εκδηλώσεις με ημερομηνία, χώρο, κατάσταση και είσοδο, συνδεδεμένες με προϊόντα και δημιουργούς. Λίστα επερχόμενων, αρχείο, «Πρόσθεσε στο ημερολόγιο» και Event schema.' => 'Events with a date, venue, status and entry, linked to products and creators. Upcoming list, archive, «Add to calendar» and Event schema.',
		'Όλες οι εκδηλώσεις' => 'All events',
		'Νέα εκδήλωση' => 'New event',
		'Το EVT_DISABLE είναι ενεργό στο wp-config.php: καμία σελίδα εκδήλωσης, λίστα ή κουτί δεν εμφανίζεται.' => 'EVT_DISABLE is on in wp-config.php: no event page, list or box shows.',
		'Τα permalinks είναι «Απλά»: οι σελίδες των εκδηλώσεων ανοίγουν με ?nox_event=… αντί για τη βάση URL.' => 'Permalinks are «Plain»: the event pages open with ?nox_event=… instead of the URL base.',
		'Λειτουργία' => 'Mode',
		'Ανενεργό: καμία σελίδα, λίστα ή κουτί εκδηλώσεων' => 'Off: no event page, list or box',
		'Δοκιμή: τις σελίδες, τις λίστες και τα κουτιά τα βλέπουν μόνο όσοι επεξεργάζονται άρθρα' => 'Test: only people who edit posts see the pages, lists and boxes',
		'Ενεργό: όλοι οι επισκέπτες' => 'Live: every visitor',
		'Γλώσσα των κειμένων για τους επισκέπτες' => 'Language of the visitor texts',
		'Αυτόματα, από τη γλώσσα του site' => 'Automatic, from the site language',
		'Ελληνικά' => 'Greek',
		'English' => 'English',
		'Διευθύνσεις' => 'Addresses',
		'Βάση URL των εκδηλώσεων' => 'URL base of the events',
		'Βάση URL των χώρων' => 'URL base of the venues',
		'Λατινικά πεζά, αριθμοί και παύλες. Αν αλλάξεις βάση, οι παλιοί σύνδεσμοι σταματούν να ανοίγουν (βάλε ανακατευθύνσεις στο Redirects).' => 'Latin lowercase letters, digits and dashes. If you change a base, the old links stop opening (add redirects in Redirects).',
		'Αρχείο εκδηλώσεων:' => 'Event archive:',
		'Κουτιά «Επόμενες εκδηλώσεις»' => '«Upcoming events» boxes',
		'Στις σελίδες των προϊόντων που συνδέονται με μια επερχόμενη εκδήλωση' => 'On the pages of products linked to an upcoming event',
		'Στις σελίδες των δημιουργών (Featured Creators)' => 'On the creator pages (Featured Creators)',
		'Εκδηλώσεις σε κάθε κουτί' => 'Events in each box',
		'Το κουτί εμφανίζεται μόνο όταν υπάρχει επερχόμενη εκδήλωση. Λίστα οπουδήποτε: shortcode [nx_events] ή το block «Εκδηλώσεις».' => 'The box shows only when there is an upcoming event. A list anywhere: the [nx_events] shortcode or the «Events» block.',
		'Ημερολόγιο και SEO' => 'Calendar and SEO',
		'Feed .ics με όλες τις επερχόμενες εκδηλώσεις (για συνδρομή από ημερολόγιο)' => '.ics feed with every upcoming event (for a calendar subscription)',
		'Event schema (JSON-LD) στη σελίδα κάθε εκδήλωσης' => 'Event schema (JSON-LD) on each event page',
		'Με το Search Signals ή το Rank Math ενεργό, το schema μπαίνει μέσα στο δικό τους graph. Κάθε εκδήλωση έχει πάντα το δικό της αρχείο .ics και σύνδεσμο Google Calendar.' => 'With Search Signals or Rank Math active, the schema goes inside their graph. Every event always has its own .ics file and Google Calendar link.',
		'Αποθήκευση' => 'Save',
		'Κείμενα για τους επισκέπτες' => 'Visitor texts',
		'Κενό ελληνικό = το αρχικό. Κενό αγγλικό = το ελληνικό.' => 'Empty Greek = the original. Empty English = the Greek one.',
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Μόνο οι ρυθμίσεις. Οι εκδηλώσεις και οι χώροι μεταφέρονται με το Εργαλεία → Εξαγωγή του WordPress.' => 'Settings only. Events and venues move with WordPress Tools → Export.',
		'Απεγκατάσταση' => 'Uninstall',
		'Διαγραφή των εκδηλώσεων και των χώρων κατά τη διαγραφή του plugin' => 'Delete the events and venues when the plugin is deleted',
		'Κλειστό (προεπιλογή): οι ρυθμίσεις σβήνονται, οι εκδηλώσεις και οι χώροι μένουν στη βάση.' => 'Off (default): the settings are deleted, the events and venues stay in the database.',
		'Για developers' => 'For developers',
		'Λίστα εκδηλώσεων. Τα creator και product δέχονται id ή «current».' => 'A list of events. creator and product take an id or «current».',
		'Μια δημοσιευμένη εκδήλωση ως πίνακας, ή null.' => 'One published event as an array, or null.',
		'Οι επερχόμενες εκδηλώσεις (creator, product, venue, city, limit).' => 'The upcoming events (creator, product, venue, city, limit).',
		'(action)' => '(action)',
		'Μία φορά, την πρώτη φορά που δημοσιεύεται μια εκδήλωση: ( $id ).' => 'Once, the first time an event is published: ( $id ).',
		'Μετά από κάθε αποθήκευση εκδήλωσης: ( $id ).' => 'After every save of an event: ( $id ).',
		'(φίλτρο)' => '(filter)',
		'Αλλάζει το Event schema: ( $node, $event ).' => 'Changes the Event schema: ( $node, $event ).',
		'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).' => 'Changes a visitor text: ( $text, $key, $lang ).',
		'Το .ics μιας εκδήλωσης και το feed (αν είναι ανοιχτό).' => 'The .ics of one event and the feed (when it is on).',
		'Στο wp-config.php: σταματά κάθε λειτουργία στο front-end.' => 'In wp-config.php: stops everything on the front end.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Events).' => 'Invalid backup file (the Events settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import complete.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'♡ Στήριξε το Noxpress' => '♡ Support Noxpress',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Λίστα επερχόμενων ή περασμένων εκδηλώσεων.' => 'A list of upcoming or past events.',
		'Πλήθος' => 'Count',
		'Περασμένες (αντί για επερχόμενες)' => 'Past (instead of upcoming)',
		'Πόλη (κενό = όλες)' => 'City (empty = all)',
		'Τίτλος (κενό = χωρίς)' => 'Heading (empty = none)',
		'Μόνο του τρέχοντος προϊόντος ή δημιουργού' => 'Only those of the current product or creator',
		'Εκδήλωση' => 'Event',
		'Επεξεργασία εκδήλωσης' => 'Edit event',
		'Προβολή εκδήλωσης' => 'View event',
		'Αναζήτηση εκδηλώσεων' => 'Search events',
		'Δεν βρέθηκαν εκδηλώσεις.' => 'No events found.',
		'Δεν βρέθηκαν εκδηλώσεις στον κάδο.' => 'No events found in the trash.',
		'Χώρος' => 'Venue',
		'Νέος χώρος' => 'New venue',
		'Επεξεργασία χώρου' => 'Edit venue',
		'Αναζήτηση χώρων' => 'Search venues',
		'Δεν βρέθηκαν χώροι.' => 'No venues found.',
		'Στοιχεία εκδήλωσης' => 'Event details',
		'Γενική εκδήλωση' => 'General event',
		'Λογοτεχνική (παρουσίαση, ανάγνωση, υπογραφές)' => 'Literary (presentation, reading, signing)',
		'Μουσική (συναυλία)' => 'Music (concert)',
		'Εκπαιδευτική (εργαστήριο, σεμινάριο)' => 'Educational (workshop, seminar)',
		'Έκθεση' => 'Exhibition',
		'Θέατρο' => 'Theatre',
		'Προβολή' => 'Screening',
		'Για παιδιά' => 'For children',
		'Φεστιβάλ' => 'Festival',
		'Κοινωνική (πάρτι, γνωριμία)' => 'Social (party, meet-up)',
		'Αναβλήθηκε (χωρίς νέα ημερομηνία)' => 'Postponed (no new date)',
		'Έναρξη' => 'Start',
		'Λήξη' => 'End',
		'Ώρα του site (%s). Χωρίς λήξη, η εκδήλωση θεωρείται δίωρη.' => 'Site time (%s). Without an end, the event counts as two hours long.',
		'Είδος (για το schema)' => 'Type (for the schema)',
		'Για «Νέα ημερομηνία» άλλαξε και την έναρξη: η παλιά κρατιέται για το schema.' => 'For «New date» change the start too: the old one is kept for the schema.',
		'Πού' => 'Where',
		'— Κανένας —' => '— None —',
		'ή νέος χώρος: όνομα' => 'or a new venue: name',
		'Διεύθυνση' => 'Address',
		'Πόλη' => 'City',
		'Σύνδεσμος χάρτη' => 'Map link',
		'Online (ή και online)' => 'Online (or also online)',
		'Σύνδεσμος της online εκδήλωσης' => 'Link of the online event',
		'Ο σύνδεσμος της online εκδήλωσης φαίνεται στη σελίδα και κρύβεται όταν η εκδήλωση τελειώσει ή ακυρωθεί.' => 'The link of the online event shows on the page and is hidden when the event ends or is cancelled.',
		'Είσοδος' => 'Entry',
		'— Δεν αναφέρεται —' => '— Not stated —',
		'Ελεύθερη' => 'Free',
		'Με εισιτήριο' => 'Ticketed',
		'Τιμή (κείμενο, π.χ. «8 €, μειωμένο 5 €»)' => 'Price (text, e.g. «8 €, reduced 5 €»)',
		'Σύνδεσμος εισιτηρίων ή δήλωσης συμμετοχής' => 'Tickets or RSVP link',
		'Κουμπί' => 'Button',
		'Εισιτήρια' => 'Tickets',
		'Δήλωση συμμετοχής' => 'RSVP',
		'Σχετικά' => 'Related',
		'Προϊόντα (η εκδήλωση φαίνεται και στη σελίδα τους)' => 'Products (the event also shows on their page)',
		'Αναζήτηση προϊόντος…' => 'Search for a product…',
		'Ids σχετικών σελίδων (με κόμμα)' => 'Ids of related pages (comma separated)',
		'Δημιουργοί (Featured Creators: η εκδήλωση φαίνεται και στη σελίδα τους)' => 'Creators (Featured Creators: the event also shows on their page)',
		'Ταχυδρομικός κώδικας' => 'Postcode',
		'Χώρα (κωδικός 2 γραμμάτων, π.χ. GR)' => 'Country (2-letter code, e.g. GR)',
		'Σύνδεσμος χάρτη (π.χ. OpenStreetMap ή Google Maps)' => 'Map link (e.g. OpenStreetMap or Google Maps)',
		// 1.1.0: tickets and check-in.
		'Οι πωλήσεις κλείνουν τόσες ώρες πριν την έναρξη' => 'Ticket sales close this many hours before the start',
		'Τα ονόματα συμμετεχόντων σβήνονται τόσες ημέρες μετά την εκδήλωση' => 'Attendee names are deleted this many days after the event',
		'Ένα εισιτήριο είναι προϊόν του καταστήματος που συνδέεις στην εκδήλωση (κουτί «Εισιτήρια στο κατάστημα»). Οι θέσεις είναι το απόθεμά του. Για την είσοδο, δώσε σε όποιον ελέγχει τα εισιτήρια τον ρόλο «Ταμείο εκδήλωσης».' => 'A ticket is a shop product that you link to the event (box «Tickets in the shop»). Its stock is the seats. For the door, give whoever checks the tickets the role «Event cashier».',
		'Συμμετέχοντες' => 'Attendees',
		'Ταμείο εκδήλωσης' => 'Event cashier',
		'Είσοδος εκδήλωσης' => 'Event check-in',
		'Εκδηλώσεις: είσοδος' => 'Events: check-in',
		'Κάμερα' => 'Camera',
		'Σταμάτα την κάμερα' => 'Stop the camera',
		'Ο browser δεν διαβάζει QR από την κάμερα: πληκτρολόγησε τον κωδικό.' => 'This browser cannot read QR codes from the camera: type the code.',
		'Σφάλμα σύνδεσης. Δοκίμασε ξανά.' => 'Connection error. Try again.',
		'Καμία εκδήλωση με εισιτήρια σήμερα ή αργότερα.' => 'No event with tickets today or later.',
		'Επιλογή' => 'Select',
		'Κωδικός εισιτηρίου' => 'Ticket code',
		'Έλεγχος' => 'Check',
		'Μέσα: %1$d από %2$d' => 'Inside: %1$d of %2$d',
		'Λίστα συμμετεχόντων' => 'Attendee list',
		'Πολλοί έλεγχοι σε ένα λεπτό. Περίμενε λίγο.' => 'Too many checks in one minute. Wait a little.',
		'Άκυρο: δεν υπάρχει τέτοιο εισιτήριο.' => 'Invalid: no such ticket.',
		'Άκυρο: το εισιτήριο ακυρώθηκε (επιστροφή ή ακύρωση παραγγελίας).' => 'Invalid: the ticket was cancelled (order refunded or cancelled).',
		'Άλλη εκδήλωση: %s' => 'Another event: %s',
		'Έγκυρο: καλή διασκέδαση!' => 'Valid: enjoy the event!',
		'Ήδη χρησιμοποιήθηκε στις %s' => 'Already used at %s',
		'Η ενημέρωση φεύγει στο παρασκήνιο, σε παρτίδες.' => 'The notice is sent in the background, in batches.',
		'Το email «Events: ενημέρωση εκδήλωσης» είναι κλειστό στο WooCommerce → Ρυθμίσεις → Emails.' => 'The e-mail «Events: event update» is off in WooCommerce → Settings → Emails.',
		'Γράψε ένα μήνυμα.' => 'Write a message.',
		'Η είσοδος αναιρέθηκε.' => 'The check-in was undone.',
		'Έγκυρα: %1$d · Μέσα: %2$d · Ακυρωμένα: %3$d' => 'Valid: %1$d · Inside: %2$d · Cancelled: %3$d',
		'Ελεύθερες θέσεις: %d' => 'Seats left: %d',
		'Εκτύπωση' => 'Print',
		'CSV' => 'CSV',
		'Κωδικός' => 'Code',
		'Παραγγελία' => 'Order',
		'Αγοραστής' => 'Buyer',
		'Όνομα συμμετέχοντα' => 'Attendee name',
		'Κανένα εισιτήριο ακόμα.' => 'No tickets yet.',
		'Μέσα από τις %s' => 'Inside since %s',
		'Έγκυρο' => 'Valid',
		'Αναίρεση εισόδου' => 'Undo check-in',
		'Ενημέρωσε τους αγοραστές' => 'Notify the buyers',
		'π.χ. Η εκδήλωση μεταφέρεται στις 20:30. Τα εισιτήρια ισχύουν κανονικά.' => 'e.g. The event moves to 20:30. Tickets stay valid.',
		'Ένα email σε κάθε παραγγελία με έγκυρο εισιτήριο, με σημείωση στην παραγγελία. Οι επιστροφές χρημάτων γίνονται από την παραγγελία.' => 'One e-mail to every order with a valid ticket, with a note on the order. Refunds are made from the order.',
		'Τελευταία ενημέρωση: %s' => 'Last notice: %s',
		'Να σταλεί η ενημέρωση σε όλους τους αγοραστές;' => 'Send the notice to every buyer?',
		'Αποστολή' => 'Send',
		'Email' => 'Email',
		'Events: στάλθηκε ενημέρωση για «%s».' => 'Events: notice sent about «%s».',
		'Events: ενημέρωση εκδήλωσης' => 'Events: event update',
		'Ένα μήνυμα του καταστήματος σε όσους έχουν εισιτήρια για μια εκδήλωση (αλλαγή ώρας, αναβολή, ακύρωση). Στέλνεται μόνο με το «Ενημέρωσε τους αγοραστές» στη λίστα συμμετεχόντων.' => 'A message from the shop to everyone holding tickets for an event (time change, postponement, cancellation). Sent only with «Notify the buyers» on the attendee list.',
		'Ενημέρωση για την εκδήλωση «{event_title}»' => 'Update about the event «{event_title}»',
		'Ενημέρωση εκδήλωσης' => 'Event update',
		'Εισιτήρια στο κατάστημα' => 'Tickets in the shop',
		'Προϊόν εισιτηρίου (απλό ή με παραλλαγές για κανονικό, μειωμένο κ.λπ.)' => 'Ticket product (simple, or variable for full price, reduced etc.)',
		'Φτιάξε εισιτήριο' => 'Create a ticket',
		'Τιμή (€)' => 'Price (€)',
		'Θέσεις' => 'Seats',
		'Νέο κρυφό, εικονικό προϊόν με απόθεμα όσο οι θέσεις. Για τύπους εισιτηρίου, άλλαξέ το μετά σε προϊόν με παραλλαγές.' => 'A new hidden, virtual product with stock equal to the seats. For ticket types, change it later to a variable product.',
		'Έναρξη πωλήσεων (κενό: αμέσως)' => 'Sales start (empty: now)',
		'Ονόματα συμμετεχόντων στη σελίδα των εισιτηρίων' => 'Attendee names on the tickets page',
		'Οι πωλήσεις κλείνουν %1$d ώρες πριν την έναρξη. Τα ονόματα σβήνονται %2$d ημέρες μετά την εκδήλωση.' => 'Sales close %1$d hours before the start. Names are deleted %2$d days after the event.',
		'Εισιτήρια: %1$d · μέσα: %2$d' => 'Tickets: %1$d · inside: %2$d',
		'Το προϊόν' => 'The product',
		'Εισιτήριο: %s' => 'Ticket: %s',
		'Events: εισιτήρια που εκδόθηκαν: %d.' => 'Events: tickets issued: %d.',
		'Ποσότητα' => 'Quantity',
		'Εισιτήρια εκδηλώσεων' => 'Event tickets',
	);

	public static function init(): void {
		add_filter( 'gettext_events', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current context (forced language first, then the admin's choice). */
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
			$locale = $uid && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/**
	 * Runs $fn with the dictionary forced to a language (e.g. a text
	 * written in the visitor's language).
	 *
	 * @param string   $lang 'el' | 'en'.
	 * @param callable $fn   Callback.
	 * @return mixed What $fn returns.
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

	/** gettext_events filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'events' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
