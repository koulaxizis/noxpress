<?php
/**
 * PFM_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Front end (the block and the list line visitors see): the site
 * language decides, the same for every visitor.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Product Formats χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Product Formats requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Product Formats'                                           => 'Product Formats',
		'Product Formats — Ρυθμίσεις'                               => 'Product Formats — Settings',
		'PFM Ρυθμίσεις'                                             => 'PFM Settings',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Έργα'                                                      => 'Works',
		'Προτάσεις'                                                 => 'Suggestions',
		'Μορφές'                                                    => 'Formats',
		'Upsells'                                                   => 'Upsells',
		'Έντυπο'                                                    => 'Print',
		'Ηλεκτρονικό βιβλίο'                                        => 'E-book',
		'Ηχητικό βιβλίο'                                            => 'Audiobook',
		'Ταινία'                                                    => 'Film',
		'Μουσική'                                                   => 'Music',
		'Να αφαιρεθούν τα upsells προς τις άλλες μορφές; Κρατιέται snapshot για επαναφορά.' => 'Remove the upsells that point to the other formats? A snapshot is kept for restoring.',
		'Ο καθαρισμός σταμάτησε. Δοκίμασε ξανά· όσα έγιναν έχουν snapshot.' => 'The cleanup stopped. Try again; what was done has a snapshot.',
		'Κάτω από την τιμή'                                         => 'Below the price',
		'Κάτω από το κουμπί καλαθιού'                               => 'Below the add to cart button',
		'Πάνω από τα tabs της περιγραφής'                           => 'Above the description tabs',
		'Μόνο με το shortcode [nox_formats]'                        => 'Only with the [nox_formats] shortcode',
		'δημοσιευμένο'                                              => 'published',
		'πρόχειρο'                                                  => 'draft',
		'σε αναμονή'                                                => 'pending',
		'ιδιωτικό'                                                  => 'private',
		'προγραμματισμένο'                                          => 'scheduled',
		'από το έργο'                                               => 'from the work',
		'από το slug'                                               => 'from the slug',
		'από την κατηγορία'                                         => 'from the category',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Κάθε έργο ενώνει τα προϊόντα που είναι το ίδιο έργο σε άλλη μορφή. Η σελίδα κάθε προϊόντος δείχνει το μπλοκ «Διαθέσιμες μορφές» με ετικέτα, τιμή και σύνδεσμο. Τα προϊόντα μένουν χωριστά.' => 'Each work joins the products that are the same work in another format. Each product page shows the "Available formats" block with label, price and link. The products stay separate.',
		'Κατάσταση'                                                 => 'Status',
		'Product Formats:'                                          => 'Product Formats:',
		'Απενεργοποιημένο από το PFM_DISABLE (wp-config.php)'       => 'Disabled by PFM_DISABLE (wp-config.php)',
		'Ανενεργό στο front end'                                    => 'Off on the front end',
		'Λειτουργία δοκιμής — το μπλοκ φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode — the block is visible to shop managers only',
		'Ενεργό για όλους τους επισκέπτες'                          => 'Active for all visitors',
		'Αλλαγή'                                                    => 'Change',
		'Έργα:'                                                     => 'Works:',
		'Θέση μπλοκ:'                                               => 'Block position:',
		'Αναζήτηση έργου'                                           => 'Search works',
		'Αναζήτηση'                                                 => 'Search',
		'Νέο έργο'                                                  => 'New work',
		'Προτάσεις έργων'                                           => 'Work suggestions',
		'Έργο'                                                      => 'Work',
		'Ενέργειες'                                                 => 'Actions',
		'Δεν υπάρχουν έργα ακόμα. Ξεκίνα από τις «Προτάσεις» ή φτιάξε ένα «Νέο έργο».' => 'There are no works yet. Start from "Suggestions" or create a "New work".',
		'Επεξεργασία'                                               => 'Edit',
		'χωρίς μορφή'                                               => 'no format',
		'κρυφό'                                                     => 'hidden',
		'Όλα τα έργα'                                               => 'All works',
		'Όνομα έργου'                                               => 'Work name',
		'Το όνομα φαίνεται μόνο στο admin.'                         => 'The name is shown in the admin only.',
		'Προϊόν'                                                    => 'Product',
		'Μορφή'                                                     => 'Format',
		'Υπότιτλος'                                                 => 'Subtitle',
		'Αφαίρεση'                                                  => 'Remove',
		'Προβολή'                                                   => 'View',
		'Προσθήκη προϊόντος (3+ γράμματα ή ID)'                     => 'Add a product (3+ letters or ID)',
		'Ένα προϊόν που ανήκει σε άλλο έργο μετακινείται σε αυτό. Η σειρά στο μπλοκ ακολουθεί τη σειρά των μορφών.' => 'A product that belongs to another work moves to this one. The order in the block follows the order of the formats.',
		'Αποθήκευση έργου'                                          => 'Save work',
		'Συγχώνευση'                                                => 'Merge',
		'Μετακίνησε όλα τα προϊόντα αυτού του έργου στο έργο:'      => 'Move all products of this work to the work:',
		'Όνομα έργου (3+ γράμματα)'                                 => 'Work name (3+ letters)',
		'Διαγραφή έργου'                                            => 'Delete work',
		'Ναι, διάγραψε το έργο (τα προϊόντα μένουν ως έχουν)'       => 'Yes, delete the work (the products stay as they are)',
		'Διαγραφή'                                                  => 'Delete',
		'Πρώτο προϊόν'                                              => 'First product',
		'Δημιουργία'                                                => 'Create',
		'Η σάρωση βρίσκει προϊόντα με το ίδιο slug χωρίς την κατάληξη μορφής (π.χ. -ebook) ή με τον ίδιο τίτλο χωρίς τα [..] και (..). Τα upsells μετρούν μόνο για τη βεβαιότητα. Τίποτα δεν αλλάζει πριν πατήσεις «Αποδοχή».' => 'The scan finds products with the same slug without the format suffix (e.g. -ebook) or with the same title without [..] and (..). Upsells count only towards confidence. Nothing changes before you click "Accept".',
		'Όταν η μορφή δεν φαίνεται από το slug, βγαίνει από την κατηγορία του προϊόντος. Όρισε τις κατηγορίες κάθε μορφής στην καρτέλα' => 'When the format is not clear from the slug, it comes from the product\'s category. Set the categories of each format in the tab',
		'Σάρωση καταλόγου'                                          => 'Scan the catalogue',
		'Ξανά οι απορριφθείσες (%d)'                                => 'Show rejected again (%d)',
		'Τελευταία σάρωση: %1$s · %2$d προτάσεις που μένουν'        => 'Last scan: %1$s · %2$d suggestions left',
		'Καμία πρόταση. Όλα τα προϊόντα με κοινό slug ή τίτλο είναι ήδη σε έργα.' => 'No suggestions. All products with a shared slug or title are already in works.',
		'κάποια μέλη χωρίς μορφή'                                   => 'some members have no format',
		'ίδια μορφή δύο φορές: συμπλήρωσε υπότιτλο'                 => 'same format twice: add a subtitle',
		'συνδέονται ήδη με upsells'                                 => 'already linked with upsells',
		'Αποδοχή επιλεγμένων'                                       => 'Accept selected',
		'Επιλογή όλων με υψηλή βεβαιότητα'                          => 'Select all with high confidence',
		'Επιλογή'                                                   => 'Select',
		'υψηλή βεβαιότητα'                                          => 'high confidence',
		'έλεγξέ το'                                                 => 'check it',
		'προσθήκη σε υπάρχον έργο'                                  => 'added to an existing work',
		'Αποδοχή'                                                   => 'Accept',
		'Απόρριψη'                                                  => 'Reject',
		'Μέλος'                                                     => 'Member',
		'ήδη στο έργο'                                              => 'already in the work',
		'Φαίνονται οι πρώτες %d. Οι υπόλοιπες εμφανίζονται όταν τελειώσεις με αυτές.' => 'The first %d are shown. The rest appear once you are done with these.',
		'Η σειρά εδώ είναι η σειρά στο μπλοκ (σύρε τις γραμμές). Οι καταλήξεις slug και οι κατηγορίες χρησιμοποιούνται μόνο στις προτάσεις. Μια κατηγορία ισχύει και για τις υποκατηγορίες της.' => 'The order here is the order in the block (drag the rows). Slug suffixes and categories are used only for suggestions. A category also applies to its subcategories.',
		'Ενεργή'                                                    => 'Enabled',
		'Κλειδί'                                                    => 'Key',
		'Ετικέτα (ελληνικά)'                                        => 'Label (Greek)',
		'Ετικέτα (αγγλικά)'                                         => 'Label (English)',
		'Καταλήξεις slug'                                           => 'Slug suffixes',
		'Κατηγορίες'                                                => 'Categories',
		'Σύρε για αλλαγή σειράς'                                    => 'Drag to reorder',
		'Νέα μορφή'                                                 => 'New format',
		'Λατινικά πεζά, αριθμοί, - και _.'                          => 'Lowercase Latin letters, digits, - and _.',
		'Αποθήκευση μορφών'                                         => 'Save formats',
		'Δεν υπάρχουν κατηγορίες προϊόντων.'                        => 'There are no product categories.',
		'— Καμία —'                                                 => '— None —',
		'Upsells προς τις άλλες μορφές'                             => 'Upsells to the other formats',
		'Πριν από το Product Formats, οι μορφές ενός έργου συνδέονταν συχνά με upsells. Δύο τρόποι να φύγουν από τη σελίδα:' => 'Before Product Formats, the formats of a work were often linked with upsells. Two ways to remove them from the page:',
		'1. Απόκρυψη'                                               => '1. Hide',
		'ενεργή'                                                    => 'on',
		'ανενεργή'                                                  => 'off',
		'Μόνο στο front end, χωρίς αλλαγή στα προϊόντα. Ανοίγει από τις Ρυθμίσεις.' => 'Front end only, with no change to the products. Turned on in Settings.',
		'2. Καθαρισμός'                                             => '2. Cleanup',
		'Αφαιρεί από τα upsells μόνο τα προϊόντα του ίδιου έργου και κρατά τα υπόλοιπα. Πριν από κάθε αλλαγή κρατά snapshot για επαναφορά.' => 'Removes from the upsells only the products of the same work and keeps the rest. Before each change it keeps a snapshot for restoring.',
		'Προεπισκόπηση: %d προϊόντα'                                => 'Preview: %d products',
		'Φεύγουν από τα upsells'                                    => 'Removed from upsells',
		'Μένουν'                                                    => 'Kept',
		'Καθαρισμός upsells'                                        => 'Clean up upsells',
		'Κανένα προϊόν δεν έχει upsells προς άλλες μορφές του έργου του.' => 'No product has upsells to other formats of its work.',
		'Snapshots'                                                 => 'Snapshots',
		'Πότε'                                                      => 'When',
		'Χρήστης'                                                   => 'User',
		'Προϊόντα'                                                  => 'Products',
		'Δεν έχει γίνει καθαρισμός ακόμα.'                          => 'No cleanup has run yet.',
		'επαναφέρθηκε'                                              => 'restored',
		'Επαναφορά'                                                 => 'Restore',
		'Διαγραφή snapshot'                                         => 'Delete snapshot',
		'Λειτουργία'                                                => 'Mode',
		'Ενεργοποίηση στο front end'                                => 'Enable on the front end',
		'Λειτουργία δοκιμής: το μπλοκ, η γραμμή στις λίστες και η απόκρυψη των upsells ισχύουν μόνο για όσους διαχειρίζονται το κατάστημα' => 'Test mode: the block, the line in lists and the hiding of upsells apply only to shop managers',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch-off without admin access: add this line to wp-config.php',
		'Το PFM_DISABLE είναι ενεργό: τίποτα δεν εμφανίζεται στο front end.' => 'PFM_DISABLE is on: nothing is shown on the front end.',
		'Μπλοκ «Διαθέσιμες μορφές»'                                 => '"Available formats" block',
		'Θέση στη σελίδα προϊόντος'                                 => 'Position on the product page',
		'Το shortcode [nox_formats] δουλεύει σε κάθε θέση (και [nox_formats id="123"] για άλλο προϊόν). Στα block themes χρησιμοποίησέ το μέσα σε block Shortcode, αν θέλεις άλλη θέση.' => 'The [nox_formats] shortcode works in any position (and [nox_formats id="123"] for another product). In block themes, use it inside a Shortcode block if you want another position.',
		'Τίτλος (ελληνικά)'                                         => 'Title (Greek)',
		'Τίτλος (αγγλικά)'                                          => 'Title (English)',
		'Η γλώσσα του front end ακολουθεί τη γλώσσα του site.'      => 'The front end language follows the site language.',
		'Ένδειξη «Εξαντλημένο» στις μορφές χωρίς απόθεμα'           => '"Out of stock" note on formats without stock',
		'Λίστες προϊόντων'                                          => 'Product lists',
		'Γραμμή «Επίσης: …» με τις άλλες μορφές, κάτω από την τιμή στις λίστες' => 'An "Also: …" line with the other formats, below the price in lists',
		'Απόκρυψη των upsells που δείχνουν σε άλλες μορφές του ίδιου έργου (μόνο στο front end, τα προϊόντα δεν αλλάζουν)' => 'Hide the upsells that point to other formats of the same work (front end only, the products do not change)',
		'Για μόνιμο καθαρισμό με επαναφορά:'                        => 'For a permanent cleanup with restore:',
		'Αποθήκευση ρυθμίσεων'                                      => 'Save settings',
		'Backup & Επαναφορά'                                        => 'Backup & Restore',
		'Εισαγωγή (JSON)'                                           => 'Import (JSON)',
		'Εξαγωγή (JSON)'                                            => 'Export (JSON)',
		'Περιλαμβάνονται οι ρυθμίσεις, οι μορφές και τα έργα. Τα προϊόντα αναγνωρίζονται με ID και slug (ή SKU), άρα τα έργα μεταφέρονται σε αντίγραφο του ίδιου site. Η εισαγωγή αντικαθιστά ό,τι περιέχει το αρχείο, μετά από πλήρη έλεγχο εγκυρότητας.' => 'Includes the settings, the formats and the works. Products are matched by ID and slug (or SKU), so works carry over to a copy of the same site. Import replaces what the file contains, after a full validity check.',
		'Οι απορριφθείσες προτάσεις θα ξαναφανούν στην επόμενη σάρωση.' => 'Rejected suggestions will show again on the next scan.',
		'Το snapshot διαγράφηκε.'                                   => 'The snapshot was deleted.',
		'Το snapshot δεν βρέθηκε.'                                  => 'The snapshot was not found.',
		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Το μπλοκ δεν φαίνεται ακόμα στο front end: ενεργοποίησέ το από τις Ρυθμίσεις (με τη λειτουργία δοκιμής ενεργή, το βλέπεις μόνο εσύ).' => 'The block is not on the front end yet: enable it in Settings (with test mode on, only you see it).',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Το «%1$s» μετακινήθηκε από το έργο «%2$s».'                => '"%1$s" was moved from the work "%2$s".',
		'Το έργο δεν βρέθηκε.'                                      => 'The work was not found.',
		'Το έργο έμεινε χωρίς προϊόντα και διαγράφηκε.'             => 'The work had no products left and was deleted.',
		'Το έργο αποθηκεύτηκε.'                                     => 'Work saved.',
		'Γράψε όνομα έργου ή διάλεξε προϊόν.'                       => 'Enter a work name or pick a product.',
		'Το έργο δεν δημιουργήθηκε.'                                => 'The work was not created.',
		'Το έργο δημιουργήθηκε. Πρόσθεσε τις άλλες μορφές.'         => 'Work created. Add the other formats.',
		'Διάλεξε ένα άλλο υπάρχον έργο από τη λίστα.'               => 'Pick another existing work from the list.',
		'Μετακινήθηκαν %d προϊόντα.'                                => '%d products were moved.',
		'Επιβεβαίωσε τη διαγραφή.'                                  => 'Confirm the deletion.',
		'Το έργο διαγράφηκε. Τα προϊόντα δεν άλλαξαν.'              => 'Work deleted. The products did not change.',
		'Η σάρωση βρήκε %d προτάσεις.'                              => 'The scan found %d suggestions.',
		'Οι προτάσεις έληξαν: κάνε ξανά σάρωση.'                    => 'The suggestions expired: scan again.',
		'Η πρόταση απορρίφθηκε και δεν θα ξαναφανεί.'               => 'The suggestion was rejected and will not show again.',
		'Η πρόταση δεν βρέθηκε.'                                    => 'The suggestion was not found.',
		'Δεν επιλέχθηκε καμία πρόταση.'                             => 'No suggestion was selected.',
		'Η πρόταση «%s» δεν εφαρμόστηκε (χρειάζονται τουλάχιστον δύο προϊόντα).' => 'The suggestion "%s" was not applied (it needs at least two products).',
		'Εφαρμόστηκαν %d προτάσεις.'                                => '%d suggestions applied.',
		'Η νέα μορφή χρειάζεται κλειδί που δεν υπάρχει ήδη και ελληνική ετικέτα.' => 'The new format needs a key that does not exist yet and a Greek label.',
		'Οι μορφές αποθηκεύτηκαν.'                                  => 'Formats saved.',
		'Τα upsells επανήλθαν σε %d προϊόντα.'                      => 'Upsells restored on %d products.',
		'στο έργο «%s»'                                             => 'in the work "%s"',
		'Τα upsells καθαρίστηκαν σε %d προϊόντα. Το snapshot είναι παρακάτω.' => 'Upsells cleaned on %d products. The snapshot is below.',
		'Δεν επιλέχθηκε αρχείο JSON.'                               => 'No JSON file was selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                          => 'The file is not valid JSON.',
		'Το αρχείο είναι πολύ μεγάλο.'                              => 'The file is too large.',
		'Μη έγκυρο αρχείο backup (λείπουν τα δεδομένα του Product Formats).' => 'Invalid backup file (the Product Formats data is missing).',
		'Οι γενικές ρυθμίσεις δεν ήταν έγκυρες και δεν άλλαξαν.'    => 'The general settings were not valid and did not change.',
		'Οι μορφές δεν ήταν έγκυρες και δεν άλλαξαν.'               => 'The formats were not valid and did not change.',
		'%d προϊόντα του αρχείου δεν βρέθηκαν σε αυτό το site και παραλείφθηκαν.' => '%d products in the file were not found on this site and were skipped.',
		'Τα έργα δεν ήταν έγκυρα και δεν άλλαξαν.'                  => 'The works were not valid and did not change.',
		'Δεν εισήχθη τίποτα.'                                       => 'Nothing was imported.',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ.'                          => 'The import was only PARTLY completed.',
		'Η εισαγωγή ολοκληρώθηκε.'                                  => 'Import completed.',
		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
		'Εξαντλημένο'                                               => 'Out of stock',
		'Διαθέσιμες μορφές'                                         => 'Available formats',
		'Επίσης:'                                                   => 'Also:',
		'Γράψε 3 γράμματα για αναζήτηση. Νέο όνομα = νέο έργο· κενό = εκτός έργου.' => 'Type 3 letters to search. A new name = a new work; empty = no work.',
		'Προαιρετικό, π.χ. όταν ένα έργο έχει δύο προϊόντα της ίδιας μορφής.' => 'Optional, e.g. when a work has two products of the same format.',
		'Άλλες μορφές'                                              => 'Other formats',
		'Καμία ακόμα.'                                              => 'None yet.',
		'Διαχείριση του έργου'                                      => 'Manage the work',
		'— Χωρίς μορφή —'                                           => '— No format —',
	);

	public static function init(): void {
		add_filter( 'gettext_product-formats', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en': the user's choice in the admin, the site language on the front end. */
	public static function lang(): string {
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		if ( ! is_admin() ) {
			self::$lang = ( 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ) ? 'el' : 'en';
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

	/** English text of a msgid, whatever the current language (placeholders in the admin). */
	public static function english( string $msgid ): string {
		return self::$dict[ $msgid ] ?? $msgid;
	}

	/** gettext_product-formats filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'product-formats' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
