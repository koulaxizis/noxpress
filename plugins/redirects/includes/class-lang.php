<?php
/**
 * RDR_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitors never see a text of this plugin
 * (a redirect has no body; a 410 renders the theme's 404 template).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'%1$d αρχεία, %2$s συνολικά. Κρατούνται %3$d μήνες.' => '%1$d files, %2$s in total. Kept for %3$d months.',
		'%1$d κανόνας απαντά 302 πάνω από %2$d ημέρες χωρίς πρόβλημα. Κάν\' τον 301 (μόνιμη), ώστε οι μηχανές αναζήτησης να περάσουν τη θέση του στη νέα διεύθυνση.' => '%1$d rule has answered 302 for more than %2$d days without trouble. Make it 301 (permanent), so search engines pass its place on to the new address.',
		'%1$d κανόνες απαντούν 302 πάνω από %2$d ημέρες χωρίς πρόβλημα. Κάν\' τους 301 (μόνιμη), ώστε οι μηχανές αναζήτησης να περάσουν τη θέση τους στη νέα διεύθυνση.' => '%1$d rules have answered 302 for more than %2$d days without trouble. Make them 301 (permanent), so search engines pass their place on to the new address.',
		'%d διευθύνσεις αγνοούνται και δεν φαίνονται στον πίνακα.' => '%d addresses are ignored and hidden from the table.',
		'%d διεύθυνση αγνοείται και δεν φαίνεται στον πίνακα.' => '%d address is ignored and hidden from the table.',
		'%d κανόνας έγινε 301.' => '%d rule became 301.',
		'%d κανόνας που έστελνε σε αυτή τη διεύθυνση πηγαίνει τώρα κατευθείαν στον νέο στόχο.' => '%d rule that sent to this address now goes straight to the new target.',
		'%d κανόνες έγιναν 301.' => '%d rules became 301.',
		'%d κανόνες που έστελναν σε αυτή τη διεύθυνση πηγαίνουν τώρα κατευθείαν στον νέο στόχο.' => '%d rules that sent to this address now go straight to the new target.',
		'%s γραμμές' => '%s rows',
		'(Λειτουργία δοκιμής: μόνο για διαχειριστές.)' => '(Test mode: administrators only.)',
		'(φίλτρο)' => '(filter)',
		'301 εδώ' => '301 here',
		'301 μόνιμη' => '301 permanent',
		'301 στη νέα διεύθυνση' => '301 to the new address',
		'301 στην κατηγορία του' => '301 to its category',
		'302 προσωρινή' => '302 temporary',
		'307 προσωρινή' => '307 temporary',
		'410 δεν υπάρχει πια' => '410 gone',
		'Abilities (WordPress 6.9+) για το Site Bridge, με το ίδιο δικαίωμα (manage_options).' => 'Abilities (WordPress 6.9+) for Site Bridge, with the same capability (manage_options).',
		'Header σε κάθε ανακατεύθυνση: το id του κανόνα (και «test» στη δοκιμή).' => 'Header on every redirect: the rule id (and «test» in test mode).',
		'Hits 30 ημ.' => 'Hits 30 days',
		'Log 404' => '404 log',
		'MB ανά μήνα (1–50)' => 'MB per month (1–50)',
		'Slug που περιέχει το παλιό ή περιέχεται σε αυτό' => 'A slug that contains the old one or is contained in it',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Άγνωστη πηγή.' => 'Unknown source.',
		'Άλλαξαν %d κανόνες.' => '%d rules changed.',
		'Άλλαξε %d κανόνας.' => '%d rule changed.',
		'Άλλος στόχος' => 'Another target',
		'Έλεγχος ξανά' => 'Check again',
		'Ένα 301 ξεκινά ως 302 (προσωρινή) και σε %d ημέρες προτείνεται να γίνει μόνιμο, ώστε ένα λάθος να μη μείνει για πάντα στους browsers' => 'A 301 starts as 302 (temporary) and in %d days you are asked to make it permanent, so a mistake never stays in browsers for good',
		'Ένας νέος κανόνας 301 ξεκινά ως 302 και σε %d ημέρες προτείνεται να γίνει μόνιμος' => 'A new 301 rule starts as 302 and in %d days you are asked to make it permanent',
		'Έως 200 κανόνες «αρχίζει με».' => 'Up to 200 «starts with» rules.',
		'Έως 50 κανόνες regex.' => 'Up to 50 regex rules.',
		'Όλοι' => 'All',
		'Όχι' => 'No',
		'Αγνόηση' => 'Ignore',
		'Ακύρωση' => 'Cancel',
		'Αλλαγή κανόνα #%d' => 'Edit rule #%d',
		'Αλλαγή' => 'Edit',
		'Αναζήτηση' => 'Search',
		'Ανακατευθύνσεις για παλιές διευθύνσεις, μόνο εκεί που το site απαντά 404: μια σελίδα που υπάρχει δεν επηρεάζεται ποτέ. Τα 404 γράφονται σε ιδιωτικό αρχείο, χωρίς IP και χωρίς τα bots, με πρόταση στόχου για καθένα.' => 'Redirects for old addresses, only where the site answers 404: a page that exists is never affected. 404s are written to a private file, without IPs and without bots, with a suggested target for each.',
		'Ανενεργοί' => 'Inactive',
		'Ανενεργοί: %s' => 'Inactive: %s',
		'Απεγκατάσταση' => 'Uninstall',
		'Απενεργοποίηση' => 'Deactivate',
		'Αποθήκευση κανόνα' => 'Save rule',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Από crawlers: %s' => 'From crawlers: %s',
		'Από links του site σου: %s' => 'From links on your site: %s',
		'Από πού' => 'From where',
		'Αρχεία του log' => 'Log files',
		'Αρχείο με παρόμοιο όνομα' => 'A file with a similar name',
		'Αυτός ο κανόνας κλείνει κύκλο με άλλους κανόνες (Α→Β→Α).' => 'This rule closes a circle with other rules (A→B→A).',
		'Για developers' => 'For developers',
		'Γράφονται μόνο η διαδρομή (χωρίς query string), η ώρα, το host από το οποίο ήρθε ο επισκέπτης και αν ήταν crawler. Καμία IP, κανένα user agent, καμία εγγραφή στη βάση.' => 'Only the path (without query string), the time, the host the visitor came from and whether it was a crawler are written. No IP, no user agent, no database write.',
		'Γράψε πού πηγαίνει η ανακατεύθυνση (ή διάλεξε 410).' => 'Write where the redirect goes (or choose 410).',
		'Γράψε την παλιά διεύθυνση.' => 'Write the old address.',
		'Γραμμές το λεπτό (10–600)' => 'Lines a minute (10–600)',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Δεν βρέθηκαν κανόνες από Rank Math, Redirection, Yoast SEO Premium ή Simple 301 Redirects σε αυτό το site.' => 'No rules from Rank Math, Redirection, Yoast SEO Premium or Simple 301 Redirects were found on this site.',
		'Δεν είναι διεύθυνση αυτού του site.' => 'Not an address of this site.',
		'Δεν ελέγχθηκε (το site δεν απάντησε)' => 'Not checked (the site did not answer)',
		'Δεν επιλέχθηκε αρχείο.' => 'No file was chosen.',
		'Δεν περνούν: %s' => 'Will not pass: %s',
		'Δες τους' => 'See them',
		'Δεύτερη φορά στη λίστα.' => 'Second time in the list.',
		'Διάλεξε κανόνες και ενέργεια.' => 'Choose rules and an action.',
		'Διαγραφή των επιλεγμένων κανόνων;' => 'Delete the selected rules?',
		'Διαγραφή' => 'Delete',
		'Διευθύνσεις που μόλις σταμάτησαν' => 'Addresses that just stopped working',
		'Διευθύνσεις: %s' => 'Addresses: %s',
		'Διεύθυνση που δεν ανακατευθύνεται ποτέ (αρχική, διαχείριση, σύνδεση, REST, καλάθι, ταμείο ή λογαριασμός).' => 'An address that is never redirected (home page, admin, login, REST, cart, checkout or account).',
		'Διεύθυνση που δεν ανακατευθύνεται ποτέ.' => 'An address that is never redirected.',
		'Διεύθυνση' => 'Address',
		'Δοκιμή διεύθυνσης' => 'Test an address',
		'Δοκιμή: οι κανόνες ισχύουν μόνο για διαχειριστές· οι επισκέπτες βλέπουν το 404' => 'Test: rules apply only to administrators; visitors see the 404',
		'Είναι ενεργό και άλλο σύστημα ανακατευθύνσεων: %s. Δουλεύουν μαζί, αλλά ο κανόνας του άλλου plugin τρέχει πρώτος, και δύο λίστες μπερδεύουν. Πέρασε τους κανόνες του εδώ και κλείσε το δικό του module.' => 'Another redirect system is active: %s. They work together, but the other plugin\'s rule runs first, and two lists confuse. Bring its rules here and switch its module off.',
		'Εισαγωγή / Εξαγωγή' => 'Import / Export',
		'Εισαγωγή από CSV' => 'Import from CSV',
		'Εισαγωγή από άλλο plugin' => 'Import from another plugin',
		'Εισαγωγή των επιλεγμένων' => 'Import the selected',
		'Εισαγωγή' => 'Import',
		'Εμφάνιση' => 'Show',
		'Ενεργοί: %s' => 'Active: %s',
		'Ενεργοποίηση' => 'Activate',
		'Ενεργό: οι κανόνες ισχύουν για όλους' => 'Live: rules apply to everyone',
		'Εξαγωγή και backup' => 'Export and backup',
		'Εξαγωγή κανόνων (CSV)' => 'Export rules (CSV)',
		'Επαναφορά backup (JSON)' => 'Restore backup (JSON)',
		'Επιλογή όλων' => 'Select all',
		'Εφαρμογή' => 'Apply',
		'Η διεύθυνση «/%s» δεν ανακατευθύνεται ποτέ (αρχική, διαχείριση, σύνδεση, REST, καλάθι, ταμείο ή λογαριασμός).' => 'The address «/%s» is never redirected (home page, admin, login, REST, cart, checkout or account).',
		'Η διεύθυνση δεν θα φαίνεται πια στον πίνακα.' => 'The address will no longer show in the table.',
		'Η ελληνική διεύθυνση σε greeklish' => 'The Greek address in greeklish',
		'Η παλιά διεύθυνση πρέπει να είναι αυτού του site.' => 'The old address must belong to this site.',
		'Η πηγή δεν έχει κανόνες που μπορούν να περάσουν.' => 'The source has no rules that can be imported.',
		'Η προεπισκόπηση έληξε. Κάνε ξανά σάρωση.' => 'The preview has expired. Scan again.',
		'Η σελίδα ή το αρχείο υπάρχει: ανοίγει κανονικά και κανένας κανόνας δεν εφαρμόζεται.' => 'The page or file exists: it opens normally and no rule applies.',
		'Κάνε τους 301 (μόνιμη)' => 'Make them 301 (permanent)',
		'Κάνε τους 301' => 'Make them 301',
		'Καθαρισμός log' => 'Clear log',
		'Κανένα 404 σε αυτή την περίοδο.' => 'No 404 in this period.',
		'Κανένας κανόνας ακόμα.' => 'No rules yet.',
		'Κανένας κανόνας: 404.' => 'No rule: 404.',
		'Κανένας κανόνας: ελληνική διεύθυνση, που μπορεί να τη στείλει το Greeklish Permalinks, αν βρει άρθρο ή όρο στη μορφή greeklish. Αλλιώς 404.' => 'No rule: a Greek address, which Greeklish Permalinks may redirect if it finds a post or term in greeklish form. Otherwise 404.',
		'Κανένας κανόνας: το WordPress το στέλνει με 301 από το παλιό slug στο %s.' => 'No rule: WordPress sends it with 301 from the old slug to %s.',
		'Κανόνας #%1$d: %2$d προς %3$s' => 'Rule #%1$d: %2$d to %3$s',
		'Κανόνας #%d, αλλά ο στόχος του δεν υπάρχει: μένει το 404.' => 'Rule #%d, but its target does not exist: the 404 stays.',
		'Κανόνας #%d: απαντά 410 (δεν υπάρχει πια).' => 'Rule #%d: answers 410 (gone).',
		'Κανόνες' => 'Rules',
		'Κατάσταση' => 'Status',
		'Καταγραφή των 404 και των hits των κανόνων σε ιδιωτικό αρχείο' => 'Log 404s and rule hits to a private file',
		'Κατεβαίνει από τον browser' => 'Can be downloaded from the browser',
		'Κλειστός από τον browser' => 'Closed to the browser',
		'Λίστα (μία ανά γραμμή, * = οτιδήποτε, χωρίς την πρώτη κάθετο)' => 'List (one per line, * = anything, without the first slash)',
		'Λείπει' => 'Missing',
		'Λειτουργία δοκιμής: ο κανόνας ισχύει μόνο για διαχειριστές μέχρι να διαλέξεις «Ενεργό» στις Ρυθμίσεις.' => 'Test mode: the rule applies only to administrators until you choose «Live» in Settings.',
		'Λειτουργία δοκιμής: οι κανόνες ισχύουν μόνο για διαχειριστές (εσένα). Οι επισκέπτες βλέπουν ακόμα το 404. Το log γράφει κανονικά. Όταν είσαι έτοιμος, διάλεξε «Ενεργό» στις Ρυθμίσεις.' => 'Test mode: rules apply only to administrators (you). Visitors still see the 404. The log writes normally. When you are ready, choose «Live» in Settings.',
		'Λειτουργία' => 'Mode',
		'Μήνες που κρατιούνται (1–12)' => 'Months kept (1–12)',
		'Μαζική ενέργεια' => 'Bulk action',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Redirects).' => 'Invalid backup file (the Redirects settings are missing).',
		'Μπήκαν %1$d κανόνες, %2$d δεν πέρασαν.' => '%1$d rules imported, %2$d did not pass.',
		'Μόνο για το log: οι κανόνες δουλεύουν και για αυτές τις διευθύνσεις. Κενό πεδίο = η προεπιλεγμένη λίστα.' => 'Only for the log: rules work for these addresses too. Empty field = the default list.',
		'Μόνο ό,τι έμεινε 404: ό,τι έστειλε κάποιος κανόνας, το WordPress ή το Greeklish Permalinks δεν είναι εδώ. Οι διευθύνσεις που ψάχνουν τα bots για τρύπες (λίστα στις Ρυθμίσεις) δεν γράφονται καθόλου. «Από links του site σου» σημαίνει σπασμένο link μέσα στο site: διόρθωσέ το και στο κείμενο.' => 'Only what stayed 404: whatever a rule, WordPress or Greeklish Permalinks redirected is not here. Addresses that bots probe for holes (list in Settings) are not written at all. «From links on your site» means a broken link inside the site: fix it in the text too.',
		'Νέα διεύθυνση' => 'New address',
		'Νέοι: %s' => 'New: %s',
		'Νέος κανόνας' => 'New rule',
		'Να μείνουν οι κανόνες και το log όταν σβήσω το plugin' => 'Keep the rules and the log when I delete the plugin',
		'Να ξαναφανούν' => 'Show them again',
		'Ο κανόνας #%d αποθηκεύτηκε.' => 'Rule #%d saved.',
		'Ο κανόνας δεν αποθηκεύτηκε (σφάλμα βάσης).' => 'The rule was not saved (database error).',
		'Ο κανόνας δεν βρέθηκε.' => 'The rule was not found.',
		'Ο στόχος είναι η ίδια η παλιά διεύθυνση: αυτό θα ήταν ατέρμονος βρόχος.' => 'The target is the old address itself: that would be an endless loop.',
		'Ο στόχος είναι η παλιά διεύθυνση του κανόνα #%d: ο νέος κανόνας πηγαίνει κατευθείαν στον τελικό στόχο.' => 'The target is the old address of rule #%d: the new rule goes straight to the final target.',
		'Ο στόχος είναι πολύ μεγάλος.' => 'The target is too long.',
		'Ο στόχος πρέπει να είναι διεύθυνση http(s) ή διαδρομή του site που αρχίζει με /.' => 'The target must be an http(s) address or a path of the site starting with /.',
		'Ο στόχος πριν από την ανακατεύθυνση: ( $url, $rule, $path ). Κενό = χωρίς ανακατεύθυνση.' => 'The target before the redirect: ( $url, $rule, $path ). Empty = no redirect.',
		'Ο φάκελος έχει .htaccess και web.config που τον κλείνουν. Σε nginx χρειάζεται κανόνας στον server· ο έλεγχος ζητά ένα δοκιμαστικό αρχείο από τον browser για να το δει.' => 'The folder has an .htaccess and a web.config that close it. On nginx a server rule is needed; the check requests a test file from the browser to see.',
		'Ο φάκελος είναι κλειστός από τον browser.' => 'The folder is closed to the browser.',
		'Ο φάκελος κατεβαίνει από τον browser.' => 'The folder can be downloaded from the browser.',
		'Ο φάκελος του log δεν φτιάχτηκε (το uploads δεν γράφεται;). Χωρίς αυτόν δεν γράφεται log.' => 'The log folder was not created (is uploads not writable?). Without it no log is written.',
		'Ο φάκελος του log κατεβαίνει από τον browser (ο server αγνοεί το .htaccess, π.χ. nginx). Το log δεν έχει IP ή email, αλλά πρόσθεσε έναν κανόνα deny για τον φάκελο στον server ή κλείσε το log στις Ρυθμίσεις.' => 'The log folder can be downloaded from the browser (the server ignores .htaccess, e.g. nginx). The log has no IP or email, but add a deny rule for the folder on the server or switch the log off in Settings.',
		'Ο όρος «%s» άλλαξε διεύθυνση.' => 'The term «%s» changed its address.',
		'Οι αγνοημένες διευθύνσεις φαίνονται ξανά.' => 'Ignored addresses show again.',
		'Οι διευθύνσεις που ψάχνουν τα bots για τρύπες δεν γράφονται καθόλου' => 'Addresses that bots probe for holes are not written at all',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Οι ρυθμίσεις επανήλθαν. Κανόνες: %1$d προστέθηκαν, %2$d παραλείφθηκαν (υπήρχαν ή δεν περνούν τους ελέγχους).' => 'Settings restored. Rules: %1$d added, %2$d skipped (they existed or did not pass the checks).',
		'Πίνακας του Greeklish Permalinks' => 'Greeklish Permalinks table',
		'Παλιά διεύθυνση' => 'Old address',
		'Παλιά διεύθυνση: διαδρομή ή URL του site, με ή χωρίς τελική κάθετο, ελληνικά ή encoded. «Αρχίζει με» πιάνει και ό,τι είναι κάτω από τη διαδρομή. Regex: πάνω στη διαδρομή χωρίς την πρώτη κάθετο, σε πεζά, με $1…$9 στη νέα διεύθυνση (π.χ. ^μάρκα/(.+) → /brand/$1). Νέα διεύθυνση: διαδρομή του site που αρχίζει με / ή URL· αν είναι άρθρο, προϊόν ή κατηγορία, ο κανόνας το ακολουθεί κι αν αλλάξει ξανά slug. Για το 410 μένει κενή.' => 'Old address: a path or URL of the site, with or without the final slash, Greek or encoded. «Starts with» also catches whatever is under the path. Regex: on the path without the first slash, in lower case, with $1…$9 in the new address (e.g. ^μάρκα/(.+) → /brand/$1). New address: a path of the site starting with / or a URL; if it is a post, product or category, the rule follows it even if its slug changes again. For 410 it stays empty.',
		'Παλιό slug του WordPress' => 'WordPress old slug',
		'Παρόμοιο slug' => 'Similar slug',
		'Προεπισκόπηση: %s' => 'Preview: %s',
		'Προσθέτει κανόνα με τους ίδιους ελέγχους με αυτή τη σελίδα· επιστρέφει το id ή 0.' => 'Adds a rule with the same checks as this page; returns the id or 0.',
		'Προσθήκη κανόνα' => 'Add rule',
		'Πρόταση' => 'Suggestion',
		'Πρώτα σάρωση και προεπισκόπηση: τίποτα δεν αποθηκεύεται πριν πατήσεις «Εισαγωγή». Οι πίνακες των άλλων plugins μόνο διαβάζονται. Μετά την εισαγωγή κλείσε το module ανακατευθύνσεων του άλλου plugin, ώστε να μένει ένα σύστημα.' => 'Scan and preview first: nothing is saved before you press «Import». The other plugins\' tables are only read. After the import switch the other plugin\'s redirect module off, so one system remains.',
		'Ρυθμίσεις' => 'Settings',
		'Σάρωση CSV' => 'Scan CSV',
		'Σάρωση' => 'Scan',
		'Σβήνονται όλα τα αρχεία του log (και τα hits των κανόνων). Συνέχεια;' => 'All log files (and the rule hits) are deleted. Continue?',
		'Σβήστηκαν %d αρχεία του log.' => '%d log files deleted.',
		'Σημείωση' => 'Note',
		'Στήλες: from, to, code (301, 302, 307, 410· κενό = 301), match (exact, prefix, regex· κενό = exact). Η πρώτη γραμμή μπορεί να είναι επικεφαλίδα. Έως 5.000 γραμμές.' => 'Columns: from, to, code (301, 302, 307, 410; empty = 301), match (exact, prefix, regex; empty = exact). The first row may be a header. Up to 5,000 rows.',
		'Στη δοκιμή άνοιξε μια παλιά διεύθυνση συνδεδεμένος: θα δεις την ανακατεύθυνση, και το header X-Redirects λέει ποιος κανόνας απάντησε.' => 'In test mode open an old address while logged in: you will see the redirect, and the X-Redirects header says which rule answered.',
		'Στο wp-config.php: κανένας κανόνας και κανένα log.' => 'In wp-config.php: no rule and no log.',
		'Σύγκριση' => 'Match',
		'Τελευταία' => 'Last',
		'Τελευταίες %d ημέρες' => 'Last %d days',
		'Τελευταίο 24ωρο' => 'Last 24 hours',
		'Τι θα γίνει;' => 'What will happen?',
		'Το CSV δεν έχει γραμμές με δύο στήλες τουλάχιστον.' => 'The CSV has no rows with at least two columns.',
		'Το RDR_DISABLE είναι ενεργό στο wp-config.php: κανένας κανόνας δεν εφαρμόζεται και τίποτα δεν γράφεται στο log.' => 'RDR_DISABLE is on in wp-config.php: no rule applies and nothing is written to the log.',
		'Το backup έχει τις ρυθμίσεις, τους κανόνες και τις αγνοημένες διευθύνσεις, όχι το log. Η επαναφορά προσθέτει όσους κανόνες λείπουν, με τους ίδιους ελέγχους· οι υπάρχοντες μένουν όπως είναι.' => 'The backup holds the settings, the rules and the ignored addresses, not the log. A restore adds the rules that are missing, with the same checks; existing ones stay as they are.',
		'Το log 404 είναι κλειστό στις Ρυθμίσεις: δεν γράφεται τίποτα καινούργιο.' => 'The 404 log is off in Settings: nothing new is written.',
		'Το query string της παλιάς διεύθυνσης (π.χ. ?utm_source=…) περνά στη νέα, αν η νέα δεν έχει δικό της' => 'The query string of the old address (e.g. ?utm_source=…) passes to the new one, if the new one has none',
		'Το regex δεν είναι έγκυρο.' => 'The regex is not valid.',
		'Το regex είναι πολύ μεγάλο (έως 300 χαρακτήρες).' => 'The regex is too long (up to 300 characters).',
		'Το regex πιάνει και τη διεύθυνση «/%s», που δεν ανακατευθύνεται ποτέ. Κάν\' το πιο συγκεκριμένο (π.χ. με ^ στην αρχή).' => 'The regex also catches the address «/%s», which is never redirected. Make it more specific (e.g. with ^ at the start).',
		'Το site δεν απάντησε στον έλεγχο.' => 'The site did not answer the check.',
		'Το uploads δεν γράφεται.' => 'Uploads is not writable.',
		'Το «%s» πήγε στον κάδο ή σβήστηκε.' => '«%s» went to the trash or was deleted.',
		'Το αρχείο δεν διαβάζεται.' => 'The file cannot be read.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Τσεκαρισμένοι είναι οι νέοι κανόνες που ήταν ενεργοί στην πηγή. Οι κανόνες μπαίνουν ενεργοί με τον τύπο τους (όχι ως 302), αφού λειτουργούσαν ήδη.' => 'Ticked are the new rules that were active at the source. Rules come in active with their own code (not as 302), since they already worked.',
		'Τύπος' => 'Type',
		'Τώρα κλείσε το module ανακατευθύνσεων του άλλου plugin, ώστε να μένει ένα σύστημα.' => 'Now switch the other plugin\'s redirect module off, so one system remains.',
		'Υπάρχει ήδη κανόνας #%d για αυτή τη διεύθυνση.' => 'There is already rule #%d for this address.',
		'Υπάρχει ήδη κανόνας.' => 'There is already a rule.',
		'Υπάρχουν ήδη: %s' => 'Exist already: %s',
		'Φάκελος του log' => 'Log folder',
		'Φίλτρο' => 'Filter',
		'Φαίνονται οι πρώτες 1.000 γραμμές. Χώρισε το CSV για τις υπόλοιπες.' => 'The first 1,000 rows are shown. Split the CSV for the rest.',
		'Φορές' => 'Times',
		'Ως 302 (δοκιμή)' => 'As 302 (test)',
		'Ως 302 (δοκιμή): %s' => 'As 302 (test): %s',
		'έλεγχος: %s' => 'checked: %s',
		'ακολουθεί το άρθρο' => 'follows the post',
		'ακολουθεί τον όρο' => 'follows the term',
		'ακριβώς' => 'exactly',
		'ανενεργός' => 'inactive',
		'απαντά 302' => 'answers 302',
		'αρχίζει με' => 'starts with',
		'δεν περνά' => 'will not pass',
		'νέος' => 'new',
		'ο στόχος δεν υπάρχει: μένει το 404' => 'the target does not exist: the 404 stays',
		'πρόταση για 301 στις %s' => 'suggested for 301 on %s',
		'στον κάδο' => 'in the trash',
		'το site σου (%s)' => 'your site (%s)',
		'υπάρχει' => 'exists',
		'όρος άλλαξε διεύθυνση' => 'term changed its address',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_redirects', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_redirects', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current admin user. */
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

	/** gettext_redirects filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'redirects' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_redirects filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'redirects' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
