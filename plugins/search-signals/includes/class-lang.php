<?php
/**
 * SSG_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitors never see a text of this plugin
 * through gettext: the two visitor texts (the page number in titles and
 * the home crumb) follow the site locale, with the filters ssg_page_text
 * and ssg_home_crumb.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'%1$d ίδια ήδη, %2$d χωρίς κάτι να μεταφερθεί (π.χ. robots μόνο «index», κύρια κατηγορία που το προϊόν δεν έχει πια).' => '%1$d already the same, %2$d with nothing to carry over (e.g. robots only «index», a primary category the product no longer has).',
		'%1$d διευθύνσεις σε %2$d αρχεία.' => '%1$d addresses in %2$d files.',
		'%1$s · logo %2$s · %3$d τηλέφωνα' => '%1$s · logo %2$s · %3$d phones',
		'%1$s: οι μεταβλητές %2$s δεν υπάρχουν εδώ και θα παραλείπονται.' => '%1$s: the variables %2$s do not exist here and will be left out.',
		'%d χαρακτήρες' => '%d characters',
		'(χωρίς περιγραφή: η μηχανή αναζήτησης θα διαλέξει ένα κομμάτι της σελίδας)' => '(no description: the search engine will pick a part of the page)',
		'/sitemap_index.xml με τα ίδια ονόματα αρχείων με το Rank Math, εικόνες, γραμμή στο robots.txt.' => '/sitemap_index.xml with the same file names as Rank Math, images, a line in robots.txt.',
		'<title>, περιγραφή, robots, canonical, σελιδοποίηση, πεδία στον editor.' => '<title>, description, robots, canonical, pagination, fields in the editor.',
		'Abilities (WordPress 6.9+) για το Site Bridge.' => 'Abilities (WordPress 6.9+) for Site Bridge.',
		'Backups' => 'Backups',
		'Canonical' => 'Canonical',
		'Email μέσα' => 'Contains an email',
		'Logo' => 'Logo',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'Meta σελίδων και όρων' => 'Page and term meta',
		'Modules' => 'Modules',
		'More plugins at' => 'More plugins at',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Open Graph και Twitter (summary_large_image). Ανοίγει μαζί και το «Τίτλοι & meta».' => 'Open Graph and Twitter (summary_large_image). Also turns on «Titles & meta».',
		'Rank Math' => 'Rank Math',
		'Schema & social' => 'Schema & social',
		'Schema (JSON-LD)' => 'Schema (JSON-LD)',
		'Search Signals' => 'Search Signals',
		'Search Signals: παθητικό όσο τρέχει το %s. Η μετάβαση γίνεται με ένα κουμπί από τη σελίδα του plugin, ώστε να μη μείνει κενό στους τίτλους, τα sitemaps και το schema.' => 'Search Signals: passive while %s runs. Switch over with one button on the plugin\'s page, so titles, sitemaps and schema never have a gap.',
		'Sitemaps' => 'Sitemaps',
		'Social' => 'Social',
		'Social και αναζήτηση' => 'Social and search',
		'Templates' => 'Templates',
		'canonical / JSON-LD' => 'canonical / JSON-LD',
		'llms.txt' => 'llms.txt',
		'nofollow: οι σύνδεσμοι της σελίδας να μη μετρούν' => 'nofollow: the links of the page do not count',
		'noindex από τη 2η σελίδα των αρχείων (σελίδα 2, 3 …)' => 'noindex from page 2 of archives (page 2, 3 …)',
		'noindex στις κατηγορίες και τα tags χωρίς περιεχόμενο' => 'noindex on categories and tags without content',
		'noindex: να μη μπει στις μηχανές αναζήτησης (φεύγει και από το sitemap)' => 'noindex: keep it out of search engines (it also leaves the sitemap)',
		'rank_math_og_content_image: %d εγγραφές. Είναι η cache του Rank Math για την πρώτη εικόνα του περιεχομένου· το Search Signals τη βρίσκει μόνο του.' => 'rank_math_og_content_image: %d entries. It is Rank Math\'s cache of the first image in the content; Search Signals finds it by itself.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Άνοιξε πρώτα τα modules που θέλεις (Επισκόπηση).' => 'First turn on the modules you want (Overview).',
		'Έγινε' => 'Done',
		'Έγινε εισαγωγή των ρυθμίσεων και %d meta.' => 'Imported the settings and %d meta.',
		'Έλεγχος: ποιος απαντά στο /llms.txt' => 'Check: who answers /llms.txt',
		'Ένα αρχείο llms.txt ή llms-full.txt στη ρίζα το στέλνει ο server πριν τρέξει το WordPress, οπότε κρύβει το llms.txt του plugin. Μεταφέρεται μόνο με το κλικ σου, σε ιδιωτικό φάκελο backup στο uploads, και γυρίζει πίσω με ένα κλικ.' => 'An llms.txt or llms-full.txt file in the web root is sent by the server before WordPress runs, so it hides the plugin\'s llms.txt. It is moved only on your click, to a private backup folder in uploads, and comes back with one click.',
		'Ένα γράφημα ανά σελίδα: Organization, WebSite, WebPage, Product, Article, BreadcrumbList.' => 'One graph per page: Organization, WebSite, WebPage, Product, Article, BreadcrumbList.',
		'Ίδια %1$d · άλλο κείμενο %2$d · λείπουν %3$d · επιπλέον %4$d' => 'Same %1$d · other text %2$d · missing %3$d · extra %4$d',
		'Ό,τι είναι noindex (ανά σελίδα ή ανά τύπο), κρυφά προϊόντα, καλάθι, ταμείο και λογαριασμός δεν μπαίνουν ποτέ.' => 'Anything that is noindex (per page or per type), hidden products, cart, checkout and account never go in.',
		'Όνομα (και og:site_name)' => 'Name (also og:site_name)',
		'Όταν όλα δουλεύουν για 1–2 εβδομάδες (Search Console: τα sitemaps διαβάστηκαν, καμία πτώση), μπορείς να σβήσεις το παλιό plugin μαζί με τα δεδομένα του.' => 'Once everything works for 1–2 weeks (Search Console: sitemaps read, no drop), you can delete the old plugin along with its data.',
		'Όχι' => 'No',
		'Αλλαγή' => 'Edit',
		'Αν το plugin τυπώνει τα tags αυτού του αιτήματος· plugins με δικό τους canonical ή JSON-LD το παραλείπουν.' => 'Whether the plugin prints the tags of this request; plugins with their own canonical or JSON-LD skip theirs.',
		'Αναίρεση' => 'Undo',
		'Αναίρεση εισαγωγής' => 'Undo import',
		'Αναίρεση: %1$d meta επανήλθαν, %2$d έμειναν γιατί άλλαξαν με το χέρι.' => 'Undo: %1$d meta restored, %2$d kept because they were changed by hand.',
		'Αναζήτηση' => 'Search',
		'Ανενεργό: τίποτα από το plugin' => 'Off: nothing from the plugin',
		'Απεγκατάσταση' => 'Uninstall',
		'Απενεργοποίηση του %s και Search Signals σε «Ενεργό» τώρα;' => 'Deactivate %s and set Search Signals to «Live» now?',
		'Αποθήκευση' => 'Save',
		'Αποθηκεύτηκε.' => 'Saved.',
		'Αριστερά ό,τι τυπώνει τώρα το site σε έναν επισκέπτη (π.χ. το Rank Math), δεξιά ό,τι θα τυπώσει το Search Signals. Πράσινο = ίδιο, κίτρινο = άλλο κείμενο ή κάτι επιπλέον, κόκκινο = λείπει.' => 'Left: what the site prints now to a visitor (e.g. Rank Math); right: what Search Signals will print. Green = the same, yellow = other text or something extra, red = missing.',
		'Αρχεία ημερομηνιών' => 'Date archives',
		'Αρχεία συγγραφέων' => 'Author archives',
		'Αρχείο' => 'File',
		'Αρχείο: %s' => 'File: %s',
		'Αρχική (τελευταία άρθρα)' => 'Home page (latest posts)',
		'Αυτόματα (%s)' => 'Automatic (%s)',
		'Αφαίρεση' => 'Remove',
		'Αφαίρεση του tag generator του WooCommerce' => 'Remove the WooCommerce generator tag',
		'Για developers' => 'For developers',
		'Για το breadcrumb και την κατηγορία στο schema. Δεν αλλάζει τη διεύθυνση.' => 'For the breadcrumb and the category in the schema. It does not change the address.',
		'Γλώσσες (π.χ. Greek, English)' => 'Languages (e.g. Greek, English)',
		'Δείγματα' => 'Samples',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Δεν βρέθηκαν ρυθμίσεις του Rank Math σε αυτό το site.' => 'No Rank Math settings found on this site.',
		'Δεν γίνεται ακόμα' => 'Not yet possible',
		'Δεν μεταφέρεται' => 'Not carried over',
		'Δεν μεταφέρθηκαν (δικαιώματα αρχείων;): %s' => 'Not moved (file permissions?): %s',
		'Δεν τρέχει άλλο SEO plugin.' => 'No other SEO plugin is running.',
		'Δεν τρέχει άλλο SEO plugin: διάλεξε απλώς «Ενεργό» στη λειτουργία.' => 'No other SEO plugin is running: just choose «Live» as the mode.',
		'Δεν υπάρχει εισαγωγή για αναίρεση.' => 'There is no import to undo.',
		'Δεν υπάρχει μετάβαση για επιστροφή.' => 'There is no switch-over to go back from.',
		'Δεν υπάρχει στατικό llms.txt ή llms-full.txt στη ρίζα.' => 'There is no static llms.txt or llms-full.txt in the web root.',
		'Διάλεξε εικόνα' => 'Choose image',
		'Διάλεξε εικόνα κοινοποίησης' => 'Choose a share image',
		'Διαβάζει μόνο τα δεδομένα του Rank Math, δεν τα αλλάζει ποτέ. Πρώτα η δοκιμαστική εκτέλεση δείχνει τι θα γραφτεί. Η λειτουργία και τα modules δεν αλλάζουν.' => 'It only reads Rank Math\'s data and never changes it. The dry run shows first what will be written. The mode and the modules do not change.',
		'Διαχωριστικό (%sep%)' => 'Separator (%sep%)',
		'Διευθύνσεις' => 'Addresses',
		'Διευθύνσεις ανά αρχείο (10–2000)' => 'Addresses per file (10–2000)',
		'Διεύθυνση' => 'Address',
		'Δοκιμή: μόνο οι διαχειριστές βλέπουν την έξοδο' => 'Test: only administrators see the output',
		'Δοκιμαστική εκτέλεση' => 'Dry run',
		'Είδος (π.χ. customer support)' => 'Type (e.g. customer support)',
		'Εικόνα κοινοποίησης' => 'Share image',
		'Εικόνες (κύρια εικόνα και gallery του προϊόντος)' => 'Images (main image and product gallery)',
		'Εισαγωγή' => 'Import',
		'Εισαγωγή (κενό = η περιγραφή του site)' => 'Intro (empty = the site description)',
		'Εισαγωγή στις %1$s: %2$d meta.' => 'Import on %1$s: %2$d meta.',
		'Εισαγωγή των ρυθμίσεων και των meta από το Rank Math;' => 'Import the settings and the meta from Rank Math?',
		'Ενεργοποίηση του %s ξανά και Search Signals πίσω στη δοκιμή;' => 'Activate %s again and put Search Signals back in test mode?',
		'Ενεργό: όλοι οι επισκέπτες' => 'Live: every visitor',
		'Επαναφορά' => 'Restore',
		'Επαναφορά του αρχείου στη ρίζα; Θα κρύψει ξανά το llms.txt του plugin.' => 'Restore the file to the web root? It will hide the plugin\'s llms.txt again.',
		'Επαναφορά των ρυθμίσεων και των meta όπως ήταν πριν την εισαγωγή; Ό,τι άλλαξες με το χέρι μετά μένει.' => 'Restore the settings and the meta as they were before the import? Whatever you changed by hand since then stays.',
		'Επιλογή' => 'Select',
		'Επισκόπηση' => 'Overview',
		'Επιστροφή στο %s' => 'Switch back to %s',
		'Επιχείρηση / οργανισμός' => 'Business / organisation',
		'Η επιστροφή έγινε: το προηγούμενο SEO plugin είναι ξανά ενεργό.' => 'Switched back: the previous SEO plugin is active again.',
		'Η μετάβαση έγινε: το Search Signals είναι ενεργό για όλους.' => 'Switched over: Search Signals is live for everyone.',
		'Η μεταφορά απέτυχε (δικαιώματα αρχείων;).' => 'The move failed (file permissions?).',
		'Η προεπισκόπηση δείχνει τις αποθηκευμένες τιμές.' => 'The preview shows the saved values.',
		'Η στατική αρχική σελίδα, η σελίδα του καταστήματος και η σελίδα των άρθρων ακολουθούν το template των σελίδων, όπως στο Rank Math.' => 'The static front page, the shop page and the posts page follow the pages template, as in Rank Math.',
		'Κανένα' => 'None',
		'Κανόνες' => 'Rules',
		'Κατάσταση' => 'Status',
		'Κενό = η διεύθυνση της ίδιας της σελίδας. Συμπλήρωσε μόνο αν το ίδιο περιεχόμενο ζει αλλού.' => 'Empty = the address of the page itself. Fill it in only when the same content lives elsewhere.',
		'Κενό = η κύρια εικόνα, αλλιώς η πρώτη εικόνα του περιεχομένου, αλλιώς η προεπιλογή των ρυθμίσεων. Ιδανικό μέγεθος 1200 × 630.' => 'Empty = the featured image, else the first image in the content, else the default from the settings. Ideal size 1200 × 630.',
		'Κενό = το template του τύπου. Δέχεται μεταβλητές, π.χ. %title% %sep% %sitename%. Ως 60 χαρακτήρες φαίνονται ολόκληροι.' => 'Empty = the template of the type. Accepts variables, e.g. %title% %sep% %sitename%. Up to 60 characters show in full.',
		'Κενό = το template του τύπου. Ως 160 χαρακτήρες φαίνονται ολόκληροι.' => 'Empty = the template of the type. Up to 160 characters show in full.',
		'Κλειστό' => 'Off',
		'Κλειστό module = κανένας κώδικας του δεν φορτώνεται.' => 'A module that is off = none of its code loads.',
		'Κύρια: %s' => 'Primary: %s',
		'Λίστα του site για τους βοηθούς AI, χωρίς προσωπικά δεδομένα.' => 'A list of the site for AI assistants, without personal data.',
		'Λειτουργία' => 'Mode',
		'Λειτουργία δοκιμής: τίτλοι, meta, schema, sitemaps και llms.txt φαίνονται μόνο σε διαχειριστές (εσένα), σε σελίδες χωρίς cache. Οι επισκέπτες δεν βλέπουν τίποτα από το plugin.' => 'Test mode: titles, meta, schema, sitemaps and llms.txt show only to administrators (you), on pages without cache. Visitors see nothing from the plugin.',
		'Λογαριασμός X / Twitter (@όνομα, προαιρετικό)' => 'X / Twitter account (@name, optional)',
		'Μέγεθος' => 'Size',
		'Μία διεύθυνση του site ανά γραμμή (έως 40). Κενό = αυτόματα δείγματα.' => 'One address of the site per line (up to 40). Empty = automatic samples.',
		'Μία διεύθυνση του sitemap: ( $entry, $type, $object ). false = εκτός.' => 'One sitemap address: ( $entry, $type, $object ). false = left out.',
		'Με άλλο SEO plugin ενεργό, καμία λειτουργία δεν τυπώνει τίποτα. Για να περάσεις από εκείνο σε αυτό χωρίς κενό, χρησιμοποίησε τη «Μετάβαση» πιο κάτω.' => 'With another SEO plugin active, no module prints anything. To move from that one to this one without a gap, use «Switch over» below.',
		'Με ένα κλικ, στο ίδιο αίτημα: απενεργοποίηση του %s (όχι διαγραφή, τα δεδομένα του μένουν), Search Signals σε «Ενεργό», καθαρισμός της cache σελίδων.' => 'With one click, in the same request: deactivate %s (not delete, its data stays), Search Signals to «Live», clear the page cache.',
		'Μετάβαση' => 'Switch over',
		'Μετάβαση από το %1$s στις %2$s.' => 'Switched over from %1$s on %2$s.',
		'Μετάβαση από το %s' => 'Switch over from %s',
		'Μεταβλητές:' => 'Variables:',
		'Μεταφέρθηκαν: %s' => 'Moved: %s',
		'Μεταφορά στο backup' => 'Move to backup',
		'Μεταφορά των στατικών llms αρχείων σε φάκελο backup;' => 'Move the static llms files to a backup folder?',
		'Μη έγκυρο backup.' => 'Invalid backup.',
		'Μόνο στο Search Signals' => 'Only in Search Signals',
		'Μόνο στο τωρινό sitemap' => 'Only in the current sitemap',
		'Μόνο ό,τι είναι indexable. Ποτέ email, τηλέφωνα, ονόματα χρηστών ή διευθύνσεις. Το /llms-full.txt απαντά 410.' => 'Only what is indexable. Never emails, phones, user names or addresses. /llms-full.txt answers 410.',
		'Να μείνουν οι τίτλοι, οι περιγραφές και τα robots των σελίδων όταν σβήσω το plugin' => 'Keep the titles, descriptions and robots of the pages when I delete the plugin',
		'Ναι' => 'Yes',
		'Νεότερα ανά τύπο (0 = όλα)' => 'Newest per type (0 = all)',
		'Ο έλεγχος ζητά τις διευθύνσεις ως επισκέπτης: στη δοκιμή, ή με άλλο SEO plugin ενεργό, το Search Signals δεν απαντά ακόμα.' => 'The check requests the addresses as a visitor: in test mode, or with another SEO plugin active, Search Signals does not answer yet.',
		'Ο τύπος είναι noindex από τις ρυθμίσεις· αν διαλέξεις κάτι εδώ, ισχύει μόνο αυτό.' => 'The type is noindex in the settings; if you pick something here, only this applies.',
		'Οι ανακατευθύνσεις και το log των 404 του Rank Math μεταφέρονται με το plugin Redirects, όχι εδώ.' => 'Rank Math\'s redirects and 404 log move with the Redirects plugin, not here.',
		'Οι ενότητες του llms.txt.' => 'The sections of llms.txt.',
		'Οι κόμβοι του JSON-LD πριν τυπωθούν: ( $nodes, $context ). Ίδιο σχήμα με το rank_math/json_ld.' => 'The JSON-LD nodes before they are printed: ( $nodes, $context ). The same shape as rank_math/json_ld.',
		'Οργανισμός' => 'Organisation',
		'Παθητικό: τρέχει το %s. Το Search Signals δεν τυπώνει και δεν σερβίρει τίποτα, σε καμία λειτουργία, ώστε να μη δουλεύουν ποτέ δύο SEO plugins μαζί. Η εισαγωγή και η σύγκριση δουλεύουν κανονικά.' => 'Passive: %s is running. Search Signals prints and serves nothing, in any mode, so that two SEO plugins never work together. Import and Compare work as usual.',
		'Περιγραφή' => 'Description',
		'Περιεχόμενο' => 'Content',
		'Ποιος είναι το site' => 'Who the site is',
		'Πριν: εισαγωγή, σύγκριση των δειγμάτων και των sitemaps, μεταφορά των ανακατευθύνσεων στο Redirects, και τα στατικά llms.txt στην καρτέλα llms.txt.' => 'Before: import, compare the samples and the sitemaps, move the redirects to Redirects, and the static llms.txt in the llms.txt tab.',
		'Προεπιλεγμένη εικόνα κοινοποίησης (αλλιώς το logo)' => 'Default share image (else the logo)',
		'Προεπισκόπηση αποτελέσματος αναζήτησης' => 'Search result preview',
		'Προσοχή' => 'Warning',
		'Προφίλ (sameAs), ένα ανά γραμμή' => 'Profiles (sameAs), one per line',
		'Πρόσωπο' => 'Person',
		'Ρυθμίσεις' => 'Settings',
		'Ρυθμίσεις → Ανάγνωση: «Αποθάρρυνση των μηχανών αναζήτησης» είναι ενεργό.' => 'Settings → Reading: «Discourage search engines» is on.',
		'Σελίδα 404' => '404 page',
		'Σελίδες' => 'Pages',
		'Σελίδες με κωδικό, πρόχειρα και ιδιωτικά είναι πάντα noindex. Ανά σελίδα αλλάζουν από τον editor.' => 'Password-protected pages, drafts and private pages are always noindex. Per page they change from the editor.',
		'Σημείωση' => 'Note',
		'Στατικά αρχεία στη ρίζα' => 'Static files in the web root',
		'Στη δοκιμή τα αρχεία ανοίγουν μόνο για διαχειριστές· με άλλο SEO plugin ενεργό, τα URLs τα σερβίρει εκείνο.' => 'In test mode the files open only for administrators; with another SEO plugin active, that plugin serves the URLs.',
		'Στο wp-config.php: καμία έξοδος (και τα core sitemaps μένουν κλειστά).' => 'In wp-config.php: no output (and the core sitemaps stay off).',
		'Συγγραφείς (μόνο αν τα αρχεία τους δεν είναι noindex)' => 'Authors (only if their archives are not noindex)',
		'Σύγκριση' => 'Compare',
		'Σύγκριση sitemaps' => 'Compare sitemaps',
		'Τίτλοι & meta' => 'Titles & meta',
		'Τίτλοι, περιγραφές, robots, canonical, schema, social, sitemaps και llms.txt, μόνο ό,τι χρειάζεται ένα κατάστημα. Χωρίς εξωτερικές κλήσεις και χωρίς cookies.' => 'Titles, descriptions, robots, canonical, schema, social, sitemaps and llms.txt, only what a shop needs. No external calls and no cookies.',
		'Τίτλος' => 'Title',
		'Τίτλος, περιγραφή, robots ([noindex, nofollow]) και canonical μιας σελίδας: ( $value, $context ).' => 'Title, description, robots ([noindex, nofollow]) and canonical of a page: ( $value, $context ).',
		'Τα δημόσια τηλέφωνα του καταστήματος. Το Live Operator προσθέτει εδώ τις ώρες εξυπηρέτησης.' => 'The public phones of the shop. Live Operator adds the opening hours here.',
		'Τα ορατά breadcrumbs του Rank Math είναι ανοιχτά· το Search Signals βγάζει breadcrumbs μόνο στο schema.' => 'Rank Math\'s visible breadcrumbs are on; Search Signals outputs breadcrumbs only in the schema.',
		'Ταξινομίες' => 'Taxonomies',
		'Τηλέφωνα επικοινωνίας (ContactPoint)' => 'Contact phones (ContactPoint)',
		'Τηλέφωνο' => 'Phone',
		'Τιμές' => 'Values',
		'Το %s τρέχει από άλλο αρχείο ή ως must-use plugin: απενεργοποίησέ το από τη λίστα των plugins.' => '%s runs from another file or as a must-use plugin: deactivate it from the plugins list.',
		'Το SSG_DISABLE είναι ενεργό στο wp-config.php.' => 'SSG_DISABLE is on in wp-config.php.',
		'Το SSG_DISABLE είναι ενεργό στο wp-config.php: το plugin δεν τυπώνει και δεν σερβίρει τίποτα.' => 'SSG_DISABLE is on in wp-config.php: the plugin prints and serves nothing.',
		'Το backup δεν βρέθηκε.' => 'The backup was not found.',
		'Το module Sitemaps είναι κλειστό.' => 'The Sitemaps module is off.',
		'Το αρχείο γύρισε στη ρίζα.' => 'The file is back in the web root.',
		'Το πλαίσιο αναζήτησης της Google ψάχνει στα προϊόντα (WooCommerce)' => 'Google\'s search box searches the products (WooCommerce)',
		'Τύποι' => 'Types',
		'Τύπος' => 'Type',
		'Τώρα' => 'Now',
		'Τώρα %1$d διευθύνσεις, Search Signals %2$d.' => 'Now %1$d addresses, Search Signals %2$d.',
		'Υπάρχει ήδη αρχείο με αυτό το όνομα στη ρίζα· τίποτα δεν άλλαξε.' => 'A file with this name already exists in the web root; nothing changed.',
		'Υπάρχει πραγματικό αρχείο robots.txt στη ρίζα: η γραμμή Sitemap πρέπει να μπει με το χέρι.' => 'There is a real robots.txt file in the web root: the Sitemap line must be added by hand.',
		'Χρήση' => 'Use',
		'Χρειάζεται το δικαίωμα ενεργοποίησης plugins.' => 'Needs the capability to activate plugins.',
		'Χωρίς επιλογή ισχύει η ρύθμιση του τύπου (index, follow).' => 'With nothing chosen, the setting of the type applies (index, follow).',
		'άλλος (στατικό αρχείο ή άλλο plugin)' => 'another (a static file or another plugin)',
		'απαντά %1$d από %2$s.' => 'answers %1$d from %2$s.',
		'κανείς' => 'nobody',
		'σφάλμα αιτήματος' => 'request error',
		'το Search Signals' => 'Search Signals',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_search-signals', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_search-signals', array( __CLASS__, 'filter_n' ), 10, 5 );
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

	/** gettext_search-signals filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'search-signals' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_search-signals filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'search-signals' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
