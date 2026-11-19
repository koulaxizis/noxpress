<?php
/**
 * FRM_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitor texts are settings, not msgids
 * (Bible §14.17, FRM_Settings::text()).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Φόρμες' => 'Forms',
		'Μηνύματα' => 'Messages',
		'Ρυθμίσεις' => 'Settings',
		'Κείμενα' => 'Texts',
		'Εργαλεία' => 'Tools',
		'Όταν μας γράφεις από μια φόρμα του site, λαμβάνουμε με email ό,τι συμπληρώνεις (π.χ. όνομα, email, μήνυμα), τη σελίδα από την οποία το έστειλες και, σε ερώτηση για προϊόν, το προϊόν. Τα χρησιμοποιούμε μόνο για να σου απαντήσουμε.' => 'When you write to us from a form on this site, we receive by e-mail what you fill in (e.g. name, e-mail, message), the page you sent it from and, for a product question, the product. We use them only to answer you.',
		'Το μήνυμα κρατιέται στο site μόνο αν αποτύχει η αποστολή του email (ή αν η φόρμα ορίζει να κρατιούνται όλα) και σβήνεται αυτόματα μετά από τους μήνες που ορίζονται στις ρυθμίσεις. Για την προστασία από spam δεν χρησιμοποιούμε υπηρεσίες τρίτων ούτε cookies· η διεύθυνση IP κρατιέται μόνο ως κρυπτογραφικό αποτύπωμα, για λίγα λεπτά, για το όριο μηνυμάτων.' => 'The message is kept on the site only if sending the e-mail fails (or if the form is set to keep every message) and it is deleted automatically after the months set in the settings. Spam protection uses no third-party services and no cookies; the IP address is kept only as a cryptographic fingerprint, for a few minutes, for the message limit.',
		'Αν μια φόρμα σε γράφει στο newsletter, το email σου στέλνεται στη λίστα μας μόνο όταν το ζητήσεις με το αντίστοιχο κουτάκι. Μπορείς να ζητήσεις αντίγραφο ή διαγραφή των μηνυμάτων σου όποτε θέλεις.' => 'If a form signs you up to the newsletter, your e-mail goes to our list only when you ask for it with the matching box. You can ask for a copy or the deletion of your messages at any time.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Φόρμες επικοινωνίας με block και shortcode. Κάθε μήνυμα έρχεται με email· κρατιέται εδώ μόνο όταν το email αποτύχει, ώστε να μη χάνεται. Προστασία από spam χωρίς υπηρεσίες τρίτων.' => 'Contact forms with a block and a shortcode. Every message arrives by e-mail; it is kept here only when the e-mail fails, so it is never lost. Spam protection without third-party services.',
		'Το FRM_DISABLE είναι ενεργό στο wp-config.php: οι φόρμες δεν φαίνονται πουθενά.' => 'FRM_DISABLE is on in wp-config.php: the forms do not show anywhere.',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τις φόρμες. Άλλαξέ τη σε «Ζωντανό» στις Ρυθμίσεις όταν είναι έτοιμες.' => 'Test mode: only managers see the forms. Switch to «Live» in Settings when they are ready.',
		'Οι φόρμες είναι κλειστές (λειτουργία «Ανενεργό»).' => 'The forms are off («Off» mode).',
		'Η φόρμα δεν βρέθηκε.' => 'The form was not found.',
		'Νέα φόρμα' => 'New form',
		'Οι φόρμες σου' => 'Your forms',
		'Τίτλος' => 'Title',
		'Πεδία' => 'Fields',
		'Email προς' => 'E-mail to',
		'Κατάσταση' => 'Status',
		'Καμία φόρμα ακόμα. Ξεκίνα από ένα πρότυπο παραπάνω, ή εισήγαγε τις φόρμες του Contact Form 7 από τα Εργαλεία.' => 'No forms yet. Start from a template above, or import the Contact Form 7 forms from Tools.',
		'Από το Contact Form 7' => 'From Contact Form 7',
		'Εγγραφή στο orOS Newsletter' => 'Sign-up to orOS Newsletter',
		'%d νέα' => '%d new',
		'Ενεργή' => 'Active',
		'Ανενεργή' => 'Inactive',
		'Βάλε μια φόρμα σε σελίδα με το block «Φόρμα» ή με το shortcode. Μια φόρμα που εισήχθη από το Contact Form 7 δουλεύει και με το παλιό shortcode του, όταν το Contact Form 7 απενεργοποιηθεί.' => 'Put a form on a page with the «Form» block or with its shortcode. A form imported from Contact Form 7 also works with its old shortcode once Contact Form 7 is deactivated.',
		'Είδος πεδίου' => 'Field type',
		'Πάνω' => 'Up',
		'Κάτω' => 'Down',
		'Αφαίρεση' => 'Remove',
		'Ετικέτα (ελληνικά)' => 'Label (Greek)',
		'Ετικέτα (αγγλικά)' => 'Label (English)',
		'Κλειδί (για το θέμα του email: {κλειδί})' => 'Key (for the e-mail subject: {key})',
		'Πλάτος' => 'Width',
		'Όλη η γραμμή' => 'Full row',
		'Μισή γραμμή' => 'Half row',
		'Κείμενο μέσα στο πεδίο (ελληνικά)' => 'Placeholder (Greek)',
		'Κείμενο μέσα στο πεδίο (αγγλικά)' => 'Placeholder (English)',
		'Κείμενο (ελληνικά)' => 'Text (Greek)',
		'Βοήθεια κάτω από το πεδίο (ελληνικά)' => 'Help below the field (Greek)',
		'Κείμενο (αγγλικά)' => 'Text (English)',
		'Βοήθεια κάτω από το πεδίο (αγγλικά)' => 'Help below the field (English)',
		'Επιλογές, μία ανά γραμμή: «Ελληνικά | English»' => 'Options, one per line: «Ελληνικά | English»',
		'Μέγιστοι χαρακτήρες' => 'Maximum characters',
		'Από / έως (αριθμός)' => 'From / to (number)',
		'Τιμή' => 'Value',
		'Η σελίδα της φόρμας' => 'The page of the form',
		'Το προϊόν της σελίδας (ερώτηση για προϊόν)' => 'The product of the page (product question)',
		'Σταθερό κείμενο' => 'Fixed text',
		'Υποχρεωτικό' => 'Required',
		'Η συναίνεση είναι πάντα υποχρεωτική. Κενή ετικέτα = το τυπικό κείμενο με σύνδεσμο στην πολιτική απορρήτου· γράψε {privacy} όπου θέλεις τον σύνδεσμο.' => 'Consent is always required. Empty label = the standard text with a link to the privacy policy; write {privacy} where you want the link.',
		'Όλες οι φόρμες' => 'All forms',
		'και το παλιό shortcode του Contact Form 7' => 'and the old Contact Form 7 shortcode',
		'Τίτλος (μόνο για σένα και στο θέμα του email)' => 'Title (only for you and in the e-mail subject)',
		'Ενεργή (φαίνεται στο site)' => 'Active (shows on the site)',
		'+ Προσθήκη πεδίου' => '+ Add field',
		'Ειδοποίηση με email' => 'E-mail notification',
		'Προς (κόμμα ανάμεσα· κενό = το email του site)' => 'To (comma between; empty = the site e-mail)',
		'Θέμα' => 'Subject',
		'{site}, {form}, {name} και {κλειδί} κάθε πεδίου.' => '{site}, {form}, {name} and the {key} of each field.',
		'Απάντηση στο email του πεδίου' => 'Reply to the e-mail of the field',
		'— αυτόματα —' => '— automatic —',
		'Όνομα αποστολέα από το πεδίο' => 'Sender name from the field',
		'Το email φεύγει από τον αποστολέα του site (ή το Mail Relay / SMTP plugin)· το «Απάντηση» πηγαίνει στον επισκέπτη.' => 'The e-mail leaves from the site\'s sender (or Mail Relay / an SMTP plugin); «Reply» goes to the visitor.',
		'Αποθήκευση μηνυμάτων' => 'Storing messages',
		'Μόνο όταν αποτύχει το email (προτείνεται)' => 'Only when the e-mail fails (recommended)',
		'Πάντα' => 'Always',
		'Ποτέ (αν αποτύχει το email, ο επισκέπτης βλέπει σφάλμα)' => 'Never (if the e-mail fails, the visitor sees an error)',
		'Κείμενα της φόρμας' => 'Texts of the form',
		'Κουμπί (ελληνικά)' => 'Button (Greek)',
		'Κενό = το γενικό κείμενο.' => 'Empty = the general text.',
		'Κουμπί (αγγλικά)' => 'Button (English)',
		'Μήνυμα επιτυχίας (ελληνικά)' => 'Success message (Greek)',
		'Μήνυμα επιτυχίας (αγγλικά)' => 'Success message (English)',
		'Προστασία από spam' => 'Spam protection',
		'Πάντα ενεργά: κρυφό πεδίο για bots, υπογεγραμμένος χρόνος, όριο μηνυμάτων ανά επισκέπτη, μία αποστολή ανά token.' => 'Always on: hidden field for bots, signed time, message limit per visitor, one send per token.',
		'Αόρατο proof of work (ο browser λύνει ένα μικρό πρόβλημα όσο γράφει ο επισκέπτης)' => 'Invisible proof of work (the browser solves a small puzzle while the visitor types)',
		'Χωρίς JavaScript: σελίδα «Επιβεβαίωση αποστολής» (αλλιώς η αποστολή χωρίς JS απορρίπτεται)' => 'Without JavaScript: a «Confirm sending» page (otherwise a send without JS is refused)',
		'Έλεγχος με τις «Λέξεις που δεν επιτρέπονται» του WordPress (Ρυθμίσεις → Συζήτηση)' => 'Check against WordPress\'s «Disallowed comment keys» (Settings → Discussion)',
		'Μόνο ελληνικά ή λατινικά γράμματα στο μήνυμα' => 'Only Greek or Latin letters in the message',
		'Έλεγχος ότι ο τομέας του email δέχεται μηνύματα (DNS)' => 'Check that the e-mail domain accepts mail (DNS)',
		'Μέγιστα links στο μήνυμα (-1 = χωρίς όριο)' => 'Maximum links in the message (-1 = no limit)',
		'Δικές σου λέξεις που δεν επιτρέπονται (μία ανά γραμμή)' => 'Your own words that are not allowed (one per line)',
		'Ένα bot παίρνει σιωπηλό «Ευχαριστούμε» και τίποτα δεν στέλνεται. Ένα μήνυμα που κόβεται από κανόνα περιεχομένου βλέπει το σφάλμα και πηγαίνει στο Spam (κρατιέται 7 ημέρες), εκτός αν η αποθήκευση είναι «Ποτέ».' => 'A bot gets a silent «Thank you» and nothing is sent. A message stopped by a content rule shows the error and goes to Spam (kept 7 days), unless storing is «Never».',
		'Αυτόματη απάντηση' => 'Auto-reply',
		'Στέλνε στον επισκέπτη ένα σύντομο «Λάβαμε το μήνυμά σας» (μία φορά την ώρα ανά email)' => 'Send the visitor a short «We received your message» (once an hour per e-mail)',
		'Θέμα (ελληνικά)' => 'Subject (Greek)',
		'Θέμα (αγγλικά)' => 'Subject (English)',
		'Μόνο {name} και {site}: τίποτα άλλο από όσα έγραψε ο επισκέπτης.' => 'Only {name} and {site}: nothing else of what the visitor wrote.',
		'Γράψε τον επισκέπτη στο orOS Newsletter όταν τσεκάρει τη συναίνεση' => 'Sign the visitor up to orOS Newsletter when they tick the consent box',
		'Μέσω του orOS Connect (module Newsletter), πάντα με email επιβεβαίωσης από το orOS. Χρειάζεται πεδίο Email και πεδίο Συναίνεση με κείμενο για newsletter.' => 'Through orOS Connect (module Newsletter), always with the orOS confirmation e-mail. Needs an Email field and a Consent field with a newsletter text.',
		'Το orOS Connect με το module Newsletter δεν είναι ενεργό ή ρυθμισμένο: η επιλογή μένει αποθηκευμένη και δουλεύει όταν ενεργοποιηθεί.' => 'orOS Connect with the Newsletter module is not active or not set up: the choice stays saved and works once it is.',
		'Αποθήκευση φόρμας' => 'Save form',
		'Αντίγραφο' => 'Duplicate',
		'Διαγραφή' => 'Delete',
		'Οριστική διαγραφή της φόρμας και των μηνυμάτων της;' => 'Delete the form and its messages for good?',
		'Σβήνει τη φόρμα και τα %d αποθηκευμένα μηνύματά της. Όπου υπάρχει το shortcode της, δεν θα φαίνεται τίποτα.' => 'Deletes the form and its %d stored messages. Wherever its shortcode is, nothing will show.',
		'Διαγραφή φόρμας' => 'Delete form',
		'Εισερχόμενα' => 'Inbox',
		'Νέα' => 'New',
		'Email που απέτυχε' => 'Failed e-mail',
		'Αναζήτηση' => 'Search',
		'Φιλτράρισμα' => 'Filter',
		'Εξαγωγή CSV' => 'Export CSV',
		'Ημερομηνία' => 'Date',
		'Φόρμα' => 'Form',
		'Από' => 'From',
		'Μήνυμα' => 'Message',
		'Κανένα μήνυμα εδώ. Τα μηνύματα έρχονται με email και κρατιούνται εδώ μόνο όταν το email αποτύχει (ή όταν η φόρμα ορίζει «Πάντα»).' => 'No messages here. Messages arrive by e-mail and are kept here only when the e-mail fails (or when the form is set to «Always»).',
		'Νεότερα' => 'Newer',
		'Παλαιότερα' => 'Older',
		'Τα μηνύματα σβήνονται αυτόματα μετά από %1$d μήνες και το spam μετά από %2$d ημέρες (Ρυθμίσεις).' => 'Messages are deleted automatically after %1$d months and spam after %2$d days (Settings).',
		'Όλα τα μηνύματα' => 'All messages',
		'Το μήνυμα δεν βρέθηκε.' => 'The message was not found.',
		'Ναι' => 'Yes',
		'Όχι' => 'No',
		'Σελίδα' => 'Page',
		'Προϊόν' => 'Product',
		'Γλώσσα' => 'Language',
		'Ελληνικά' => 'Greek',
		'Συναίνεση' => 'Consent',
		'Λόγος spam' => 'Spam reason',
		'Ενέργειες' => 'Actions',
		'Σημείωση ως διαβασμένο' => 'Mark as read',
		'Σημείωση ως νέο' => 'Mark as new',
		'Δεν είναι spam' => 'Not spam',
		'Είναι spam' => 'Spam',
		'Ξανά αποστολή του email' => 'Send the e-mail again',
		'Αποστολή του email τώρα' => 'Send the e-mail now',
		'Οριστική διαγραφή του μηνύματος;' => 'Delete the message for good?',
		'Απάντηση με email' => 'Reply by e-mail',
		'Λειτουργία' => 'Mode',
		'Ανενεργό (οι φόρμες δεν φαίνονται)' => 'Off (the forms do not show)',
		'Δοκιμή (μόνο οι διαχειριστές)' => 'Test (managers only)',
		'Ζωντανό' => 'Live',
		'Γλώσσα επισκέπτη' => 'Visitor language',
		'Αυτόματα (Polylang, WPML ή η γλώσσα του site)' => 'Automatic (Polylang, WPML or the site language)',
		'Πάντα ελληνικά' => 'Always Greek',
		'Πάντα αγγλικά' => 'Always English',
		'Διατήρηση' => 'Retention',
		'Μηνύματα: μήνες (0 = για πάντα)' => 'Messages: months (0 = forever)',
		'Spam: ημέρες' => 'Spam: days',
		'Απεγκατάσταση' => 'Uninstall',
		'Σβήσε και τα μηνύματα όταν διαγράφεται το plugin' => 'Also delete the messages when the plugin is deleted',
		'Προστασία από spam (όλες οι φόρμες)' => 'Spam protection (all forms)',
		'Ελάχιστα δευτερόλεπτα πριν την αποστολή' => 'Minimum seconds before sending',
		'Δυσκολία proof of work (bits, 12–20)' => 'Proof of work difficulty (bits, 12–20)',
		'16 ≈ λιγότερο από 1 δευτερόλεπτο σε κινητό.' => '16 ≈ less than 1 second on a phone.',
		'Μηνύματα ανά φόρμα ανά επισκέπτη σε 10 λεπτά' => 'Messages per form per visitor in 10 minutes',
		'Μηνύματα ανά επισκέπτη την ώρα (όλες οι φόρμες)' => 'Messages per visitor per hour (all forms)',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Μπλοκαρισμένες προσπάθειες (30 ημέρες)' => 'Blocked attempts (30 days)',
		'Σήμερα: %1$d · 30 ημέρες: %2$d' => 'Today: %1$d · 30 days: %2$d',
		'Λόγος' => 'Reason',
		'Πλήθος' => 'Count',
		'Μόνο πλήθος ανά ημέρα: κανένα περιεχόμενο και καμία IP. Μετράει έως 20 φορές την ώρα ανά επισκέπτη.' => 'Only a count per day: no content and no IP. Counts at most 20 times an hour per visitor.',
		'Τα κείμενα που βλέπει ο επισκέπτης, σε όλες τις φόρμες. Το κουμπί και το μήνυμα επιτυχίας αλλάζουν και ανά φόρμα. Κενό αγγλικό = το ελληνικό.' => 'The texts the visitor sees, in every form. The button and the success message can also change per form. Empty English = the Greek one.',
		'Αγγλικά' => 'English',
		'Αποθήκευση κειμένων' => 'Save texts',
		'Εισαγωγή από Contact Form 7' => 'Import from Contact Form 7',
		'Δεν βρέθηκαν φόρμες του Contact Form 7 σε αυτό το site.' => 'No Contact Form 7 forms were found on this site.',
		'Η εισαγωγή διαβάζει μόνο: το Contact Form 7 και οι φόρμες του μένουν όπως είναι. Η νέα φόρμα είναι ανενεργή μέχρι να τη δεις και να την ενεργοποιήσεις. Όταν απενεργοποιήσεις το Contact Form 7, το shortcode του δείχνει τη φόρμα του Forms.' => 'The import only reads: Contact Form 7 and its forms stay as they are. The new form is inactive until you check and activate it. Once you deactivate Contact Form 7, its shortcode shows the Forms form.',
		'Φόρμα Contact Form 7' => 'Contact Form 7 form',
		'Στο Forms' => 'In Forms',
		'Νέα εισαγωγή' => 'Import again',
		'Εισαγωγή' => 'Import',
		'Το Contact Form 7 είναι ακόμα ενεργό: το shortcode του δείχνει ακόμα τις δικές του φόρμες.' => 'Contact Form 7 is still active: its shortcode still shows its own forms.',
		'Το Contact Form 7 δεν είναι ενεργό: τα shortcodes του που έχουν εισαχθεί δείχνουν τις φόρμες του Forms.' => 'Contact Form 7 is not active: its imported shortcodes show the Forms forms.',
		'Backup φορμών και ρυθμίσεων' => 'Backup of forms and settings',
		'Εξαγωγή JSON' => 'Export JSON',
		'Εισαγωγή JSON' => 'Import JSON',
		'Το αρχείο έχει τις ρυθμίσεις, τα κείμενα και τις φόρμες, όχι μηνύματα. Η εισαγωγή αντικαθιστά τις ρυθμίσεις και προσθέτει τις φόρμες ως νέες, ανενεργές.' => 'The file has the settings, the texts and the forms, no messages. The import replaces the settings and adds the forms as new, inactive ones.',
		'Για developers' => 'For developers',
		'%s (αντίγραφο)' => '%s (copy)',
		'Το αντίγραφο φτιάχτηκε (ανενεργό).' => 'The copy was made (inactive).',
		'Η φόρμα δεν αποθηκεύτηκε.' => 'The form was not saved.',
		'Η φόρμα διαγράφηκε, μαζί με %d μηνύματα.' => 'The form was deleted, together with %d messages.',
		'Η κατάσταση αποθηκεύτηκε.' => 'The status was saved.',
		'Το email στάλθηκε.' => 'The e-mail was sent.',
		'Το email δεν στάλθηκε ξανά. Έλεγξε τη ρύθμιση email του site.' => 'The e-mail was not sent again. Check the site\'s e-mail setup.',
		'Το μήνυμα διαγράφηκε.' => 'The message was deleted.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Η φόρμα «%s» εισήχθη (ανενεργή). Έλεγξέ τη και ενεργοποίησέ τη.' => 'The form «%s» was imported (inactive). Check it and activate it.',
		'Η εισαγωγή δεν έγινε.' => 'The import did not happen.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Η φόρμα αποθηκεύτηκε.' => 'The form was saved.',
		'Η φόρμα δεν έχει πεδία.' => 'The form has no fields.',
		'Η φόρμα δεν έχει κανένα πεδίο που συμπληρώνει ο επισκέπτης.' => 'The form has no field the visitor fills in.',
		'Για εγγραφή στο orOS Newsletter η φόρμα χρειάζεται πεδίο Email και πεδίο Συναίνεση.' => 'To sign up to orOS Newsletter the form needs an Email field and a Consent field.',
		'Το πεδίο «Το προϊόν της σελίδας» χρειάζεται WooCommerce.' => 'The field «The product of the page» needs WooCommerce.',
		'Η αυτόματη απάντηση χρειάζεται πεδίο Email.' => 'The auto-reply needs an Email field.',
		'ειδοποίησης' => 'notification',
		'Όνομα' => 'Name',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Μη έγκυρο αρχείο backup του Forms.' => 'Not a valid Forms backup file.',
		'Οι ρυθμίσεις εισήχθησαν.' => 'The settings were imported.',
		'Οι ρυθμίσεις του αρχείου δεν είναι έγκυρες· οι τωρινές έμειναν ίδιες.' => 'The settings in the file are not valid; the current ones stayed the same.',
		'Φόρμες που προστέθηκαν (ανενεργές): %d.' => 'Forms added (inactive): %d.',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ.' => 'The import completed PARTIALLY.',
		'♡ Στήριξε το Noxpress' => '♡ Support Noxpress',
		'Η συναίνεση «%s» ήταν προαιρετική στο Contact Form 7· στο Forms είναι πάντα υποχρεωτική.' => 'The consent «%s» was optional in Contact Form 7; in Forms it is always required.',
		'Το «%s» είναι ένα μόνο κουτάκι: έγινε «Πολλές επιλογές» με μία επιλογή.' => '«%s» is a single box: it became «Many choices» with one choice.',
		'Ο παραλήπτης του Contact Form 7 είχε πεδίο της φόρμας: μπήκε η διεύθυνση του site.' => 'The Contact Form 7 recipient used a form field: the site address was used.',
		'Το email του Contact Form 7 ήταν σε HTML· το Forms στέλνει απλό κείμενο με όλα τα πεδία.' => 'The Contact Form 7 e-mail was HTML; Forms sends plain text with every field.',
		'συνημμένα στο email' => 'e-mail attachments',
		'Το Contact Form 7 έστελνε δεύτερο email (αυτόματη απάντηση). Δεν εισήχθη: η αυτόματη απάντηση του Forms ανοίγει στη φόρμα, με σταθερό κείμενο.' => 'Contact Form 7 sent a second e-mail (auto-reply). It was not imported: the Forms auto-reply is turned on in the form, with fixed text.',
		'Η φόρμα δεν ζητά συναίνεση. Για εγγραφές στο orOS Newsletter χρειάζεται ένα πεδίο «Συναίνεση».' => 'The form asks for no consent. orOS Newsletter sign-ups need a «Consent» field.',
		'Δεν εισήχθησαν: %s.' => 'Not imported: %s.',
		'Η φόρμα του Contact Form 7 δεν βρέθηκε.' => 'The Contact Form 7 form was not found.',
		'Δεν βρέθηκε κανένα πεδίο που να μπορεί να εισαχθεί.' => 'No field that can be imported was found.',
		'Διαβασμένο' => 'Read',
		'Νέο' => 'New',
		'Στάλθηκε' => 'Sent',
		'Δεν στάλθηκε' => 'Not sent',
		'Χωρίς email' => 'No e-mail',
		'Μηνύματα φορμών (Forms)' => 'Form messages (Forms)',
		'Μηνύματα φορμών' => 'Form messages',
		'Κείμενο' => 'Text',
		'Τηλέφωνο' => 'Phone',
		'Διεύθυνση (URL)' => 'Address (URL)',
		'Αριθμός' => 'Number',
		'Μεγάλο κείμενο' => 'Long text',
		'Λίστα' => 'List',
		'Μία επιλογή' => 'One choice',
		'Πολλές επιλογές' => 'Many choices',
		'Κρυφό' => 'Hidden',
		'Κείμενο στη φόρμα' => 'Text in the form',
		'Επικοινωνία' => 'Contact',
		'Εγγραφή email' => 'E-mail sign-up',
		'Ερώτηση για προϊόν' => 'Product question',
		'Κενή φόρμα' => 'Empty form',
		'Forms: η φόρμα δεν βρέθηκε.' => 'Forms: the form was not found.',
		'Forms: οι φόρμες είναι κλειστές (λειτουργία «Ανενεργό»).' => 'Forms: the forms are off («Off» mode).',
		'Forms: η φόρμα είναι ανενεργή.' => 'Forms: the form is inactive.',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τις φόρμες.' => 'Test mode: only managers see the forms.',
		'Forms: αυτή η φόρμα του Contact Form 7 δεν έχει εισαχθεί (Noxpress → Forms → Εργαλεία).' => 'Forms: this Contact Form 7 form has not been imported (Noxpress → Forms → Tools).',
		'Forms: διάλεξε φόρμα στις ρυθμίσεις του block.' => 'Forms: choose a form in the block settings.',
		'Κρυφό πεδίο' => 'Hidden field',
		'Πολύ γρήγορη ή παλιά αποστολή' => 'Sent too fast or too late',
		'Χωρίς έγκυρο token' => 'No valid token',
		'Όριο ανά επισκέπτη' => 'Limit per visitor',
		'Πολλά links' => 'Too many links',
		'Λέξεις που δεν επιτρέπονται' => 'Words that are not allowed',
		'Χωρίς ελληνικά ή λατινικά γράμματα' => 'No Greek or Latin letters',
		'Email χωρίς mail server' => 'E-mail without a mail server',
		'Άλλος έλεγχος (φίλτρο)' => 'Other check (filter)',
		'νέα μηνύματα' => 'new messages',
		'με email που απέτυχε' => 'with a failed e-mail',
		'μπλοκαρίστηκαν σήμερα' => 'blocked today',
		'σε 7 ημέρες' => 'in 7 days',
		'Τα μηνύματα έρχονται με email. Κρατιούνται εδώ μόνο όταν το email αποτύχει, εκτός αν η φόρμα ορίζει «Πάντα».' => 'Messages arrive by e-mail. They are kept here only when the e-mail fails, unless the form is set to «Always».',
		'Κάποια email δεν στάλθηκαν: έλεγξε τη ρύθμιση email του site (π.χ. SMTP).' => 'Some e-mails were not sent: check the site\'s e-mail setup (e.g. SMTP).',
	);

	public static function init(): void {
		add_filter( 'gettext_forms', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** 'el' | 'en' for e-mails to the owner: the site language (a visitor sends them). */
	public static function owner_lang(): string {
		return self::from_locale( (string) get_locale() );
	}

	/** gettext_forms filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'forms' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
