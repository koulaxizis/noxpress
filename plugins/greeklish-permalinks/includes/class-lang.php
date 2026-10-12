<?php
/**
 * GLP_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
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

final class GLP_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Αρχεία'                                                  => 'Files',
		'Άκυρο στοιχείο.'                                         => 'Invalid item.',
		'Δεν βρέθηκε.'                                            => 'Not found.',
		'Δεν έχει πια ελληνικά.'                                  => 'No longer has Greek.',
		'Το νέο slug θα ήταν κενό.'                               => 'The new slug would be empty.',
		'Υπάρχει ήδη: πήρε αριθμό.'                               => 'Already taken: a number was added.',
		'Δεν βρέθηκε ελεύθερο όνομα αρχείου.'                     => 'No free file name was found.',
		'Το αρχείο δεν είναι στον φάκελο uploads.'                => 'The file is not in the uploads folder.',
		'Το αρχείο λείπει από τον δίσκο.'                         => 'The file is missing from the disk.',
		'Το νέο όνομα είναι ίδιο.'                                => 'The new name is the same.',
		'Η μετονομασία του %s απέτυχε (δικαιώματα φακέλου;). Δεν άλλαξε τίποτα.' => 'Renaming %s failed (folder permissions?). Nothing changed.',
		'Το #%d άλλαξε μετά τη μετατροπή: δεν αναιρέθηκε.'        => '#%d changed after the conversion: not undone.',
		'Ο όρος #%d άλλαξε μετά τη μετατροπή: δεν αναιρέθηκε.'    => 'Term #%d changed after the conversion: not undone.',
		'Το αρχείο #%d άλλαξε μετά τη μετατροπή: δεν αναιρέθηκε.' => 'File #%d changed after the conversion: not undone.',
		'Το %s δεν γύρισε στο παλιό όνομα.'                       => '%s did not go back to its old name.',
		'Ρυθμίσεις'                                               => 'Settings',
		'Μαζική μετατροπή'                                        => 'Bulk conversion',
		'Εργαλεία'                                                => 'Tools',
		'Greeklish Permalinks'                                    => 'Greeklish Permalinks',
		'Greeklish'                                               => 'Greeklish',
		'Σάρωση…'                                                 => 'Scanning…',
		'Δεν βρέθηκε τίποτα με ελληνικά.'                         => 'Nothing with Greek was found.',
		'Εμφανίζονται τα πρώτα %1$d από %2$d. Μετά την εφαρμογή, ξανάκανε σάρωση για τα υπόλοιπα.' => 'Showing the first %1$d of %2$d. After applying, scan again for the rest.',
		'Βρέθηκαν %d.'                                            => '%d found.',
		'Δεν επέλεξες τίποτα.'                                    => 'Nothing is selected.',
		'Τσέκαρε πρώτα ότι έχεις πρόσφατο backup.'                => 'First tick that you have a recent backup.',
		'Να εφαρμοστούν %d αλλαγές; Η προηγούμενη δυνατότητα αναίρεσης θα χαθεί.' => 'Apply %d changes? The previous undo will be lost.',
		'Να αναιρεθεί η τελευταία εφαρμογή;'                      => 'Undo the last run?',
		'Εντάξει'                                                 => 'OK',
		'Παράλειψη'                                               => 'Skipped',
		'Σφάλμα'                                                  => 'Error',
		'Έγινε: %1$d εντάξει, %2$d παραλείψεις, %3$d σφάλματα.'   => 'Done: %1$d OK, %2$d skipped, %3$d errors.',
		'Δοκιμή: %1$d θα αλλάξουν, %2$d παραλείψεις, %3$d σφάλματα. Δεν άλλαξε τίποτα.' => 'Dry run: %1$d would change, %2$d skipped, %3$d errors. Nothing changed.',
		'Η αναίρεση ολοκληρώθηκε.'                                => 'Undo finished.',
		'Το αίτημα απέτυχε. Δοκίμασε ξανά.'                       => 'The request failed. Try again.',
		'%1$d αρχεία στον δίσκο · %2$d άρθρα/σελίδες · %3$d αρχεία λήψης · %4$d άλλες αναφορές (μένουν με 301)' => '%1$d files on disk · %2$d posts/pages · %3$d downloadable files · %4$d other references (kept with a 301)',
		'Τι'                                                      => 'What',
		'Τώρα'                                                    => 'Now',
		'Μετά'                                                    => 'After',
		'Κατάσταση'                                               => 'Status',
		'Ανανέωσε τη σελίδα για να δεις την αναίρεση αυτής της εφαρμογής.' => 'Reload the page to see the undo for this run.',
		'✓ Ο server στέλνει στο WordPress τα αρχεία που λείπουν: το 301 των παλιών URLs αρχείων θα δουλεύει.' => '✓ The server sends missing files to WordPress: the 301 of old file URLs will work.',
		'✗ Ο server ΔΕΝ στέλνει στο WordPress τα αρχεία που λείπουν: τα παλιά URLs αρχείων θα βγάζουν 404.' => '✗ The server does NOT send missing files to WordPress: old file URLs will give a 404.',
		'Ο έλεγχος του server δεν έγινε (το site δεν απάντησε σε αίτημα προς τον εαυτό του).' => 'The server check did not run (the site did not answer a request to itself).',
		'Το AutoConvert Greeklish Permalinks είναι ακόμα ενεργό και μετατρέπει πρώτο τα νέα slugs, οπότε οι ρυθμίσεις του Greeklish Permalinks δεν ισχύουν γι\' αυτά. Απενεργοποίησέ το από τα Πρόσθετα· οι ρυθμίσεις του μένουν όπως είναι.' => 'AutoConvert Greeklish Permalinks is still active and converts new slugs first, so the Greeklish Permalinks settings do not apply to them. Deactivate it from Plugins; its settings stay as they are.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'         => 'You do not have access to this page.',
		'Ελληνικά σε λατινικά στα νέα slugs και, αν το θέλεις, στα ονόματα αρχείων. Τα υπάρχοντα αλλάζουν μόνο με τη μαζική μετατροπή.' => 'Greek to Latin in new slugs and, if you want, in file names. Existing ones change only with the bulk conversion.',
		'Slugs'                                                   => 'Slugs',
		'Μετατροπή των νέων slugs (άρθρα, σελίδες, προϊόντα, κατηγορίες, ετικέτες, χαρακτηριστικά, χρήστες)' => 'Convert new slugs (posts, pages, products, categories, tags, attributes, users)',
		'Μόνο όταν φτιάχνεται ένα slug. Τα υπάρχοντα slugs δεν αλλάζουν και συνεχίζουν να ανοίγουν.' => 'Only when a slug is built. Existing slugs do not change and keep opening.',
		'Τρόπος μεταγραφής'                                       => 'Transliteration',
		'Φωνητικός: ου → ou, αυ/ευ → av/ef, μπ/ντ/γκ στην αρχή λέξης → b/d/g, γγ → ng («Ευχαριστούμε» → efcharistoume)' => 'Phonetic: ου → ou, αυ/ευ → av/ef, μπ/ντ/γκ at the start of a word → b/d/g, γγ → ng («Ευχαριστούμε» → efcharistoume)',
		'Απλός: κάθε γράμμα μόνο του, ου → oy, μπ → mp («Ευχαριστούμε» → eycharistoyme), όπως το AutoConvert Greeklish Permalinks χωρίς δίφθογγους' => 'Simple: every letter on its own, ου → oy, μπ → mp («Ευχαριστούμε» → eycharistoyme), like AutoConvert Greeklish Permalinks without diphthongs',
		'Αφαίρεση άρθρων, «και» και μικρών προθέσεων από τα νέα slugs («Το μοράκι και τα σκιουράκια» → moraki-skioyrakia)' => 'Drop articles, «και» and short prepositions from new slugs («Το μοράκι και τα σκιουράκια» → moraki-skioyrakia)',
		'Ονόματα αρχείων'                                         => 'File names',
		'Μετατροπή του ονόματος κάθε νέου upload (εικόνες, PDF, zip, αρχεία προς λήψη)' => 'Convert the name of every new upload (images, PDF, zip, downloadable files)',
		'Πεζά γράμματα στο όνομα αρχείου (Επιθυμίες.jpg → epithymies.jpg)' => 'Lower case file names (Επιθυμίες.jpg → epithymies.jpg)',
		'Ο τίτλος του πολυμέσου μένει ελληνικός. Τα αρχεία που υπάρχουν ήδη αλλάζουν μόνο με τη μαζική μετατροπή.' => 'The media title stays Greek. Files that already exist change only with the bulk conversion.',
		'Ελληνικά links'                                          => 'Greek links',
		'301 για ελληνικά links που δεν βρίσκονται, όταν η λατινική τους μορφή υπάρχει (π.χ. /product/σοκολατάκια/ → /product/sokolatakia/)' => '301 for Greek links that are not found, when their Latin form exists (e.g. /product/σοκολατάκια/ → /product/sokolatakia/)',
		'Τρέχει μόνο όταν η σελίδα θα έβγαζε 404. Οι ανακατευθύνσεις της μαζικής μετατροπής ισχύουν πάντα.' => 'Runs only when the page would be a 404. The redirects of the bulk conversion always apply.',
		'Δικοί σου κανόνες'                                       => 'Your own rules',
		'Ένας κανόνας ανά γραμμή: ελληνική λέξη = λατινικά (γράμματα, αριθμοί, παύλες). Ισχύει για ολόκληρες λέξεις, χωρίς διάκριση πεζών και τόνων, πριν από τη μεταγραφή.' => 'One rule per line: Greek word = Latin (letters, digits, dashes). Whole words, case and accent insensitive, before transliteration.',
		'Δοκιμή'                                                  => 'Test',
		'Γράψε έναν τίτλο ή ένα όνομα αρχείου…'                   => 'Type a title or a file name…',
		'Slug'                                                    => 'Slug',
		'Όνομα αρχείου'                                           => 'File name',
		'Με τις ρυθμίσεις αυτής της φόρμας, και πριν τις αποθηκεύσεις.' => 'With this form\'s settings, before you save them.',
		'Αποθήκευση ρυθμίσεων'                                    => 'Save settings',
		'Μετατρέπει τα υπάρχοντα ελληνικά slugs και ονόματα αρχείων με τις τρέχουσες ρυθμίσεις. Τα παλιά URLs ανακατευθύνονται με 301 στα νέα.' => 'Converts existing Greek slugs and file names with the current settings. Old URLs redirect to the new ones with a 301.',
		'1. Τι θα ελεγχθεί'                                       => '1. What to check',
		'Τύποι περιεχομένου'                                      => 'Content types',
		'Ταξινομίες'                                              => 'Taxonomies',
		'Ονόματα αρχείων πολυμέσων (μετονομασία στον δίσκο)'      => 'Media file names (renamed on disk)',
		'Προσοχή:'                                                => 'Caution:',
		'ενημερώνονται το κείμενο των άρθρων και τα αρχεία προς λήψη του WooCommerce. Αναφορές σε page builders, sliders, CSS, cache, feeds και emails που στάλθηκαν μένουν στο παλιό URL και βασίζονται στο 301.' => 'post content and WooCommerce downloadable files are updated. References in page builders, sliders, CSS, caches, feeds and emails already sent keep the old URL and rely on the 301.',
		'Σάρωση'                                                  => 'Scan',
		'2. Έλεγχος και εφαρμογή'                                 => '2. Check and apply',
		'Δοκιμή (dry run)'                                        => 'Dry run',
		'Έχω πρόσφατο backup της βάσης και του φακέλου uploads'   => 'I have a recent backup of the database and the uploads folder',
		'Εφαρμογή'                                                => 'Apply',
		'Αναίρεση'                                                => 'Undo',
		'Τελευταία εφαρμογή: %1$s, %2$d αλλαγές.'                 => 'Last run: %1$s, %2$d changes.',
		'Αναίρεση της τελευταίας εφαρμογής'                       => 'Undo the last run',
		'Γυρίζει πίσω slugs, ονόματα αρχείων και αναφορές. Ό,τι άλλαξε με το χέρι μετά την εφαρμογή μένει όπως είναι.' => 'Reverts slugs, file names and references. Anything changed by hand after the run stays as it is.',
		'Δεν υπάρχει εφαρμογή για αναίρεση.'                      => 'There is no run to undo.',
		'Backup ρυθμίσεων'                                        => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)'                               => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                => 'Export settings (JSON)',
		'Μόνο οι ρυθμίσεις και οι κανόνες. Ο πίνακας ανακατευθύνσεων δεν μπαίνει στο backup.' => 'Settings and rules only. The redirect table is not in the backup.',
		'Ανακατευθύνσεις μαζικής μετατροπής'                      => 'Bulk conversion redirects',
		'Παλιές διευθύνσεις στον πίνακα: %d.'                     => 'Old addresses in the table: %d.',
		'Να μείνει ο πίνακας ανακατευθύνσεων όταν διαγραφεί το plugin' => 'Keep the redirect table when the plugin is deleted',
		'Χωρίς το plugin δεν τον διαβάζει κανείς· χρήσιμο μόνο αν θα το ξαναεγκαταστήσεις. Οι ανακατευθύνσεις άρθρων του ίδιου του WordPress (_wp_old_slug) μένουν πάντα.' => 'Nothing reads it without the plugin; useful only if you will install it again. WordPress\'s own post redirects (_wp_old_slug) always stay.',
		'Αποθήκευση'                                              => 'Save',
		'Για developers'                                          => 'For developers',
		'Μετατροπή με τις ρυθμίσεις του site.'                    => 'Conversion with the site\'s settings.',
		'(φίλτρο)'                                                => '(filter)',
		'Αλλάζει το αποτέλεσμα: ( $latin, $original, $context ).' => 'Changes the result: ( $latin, $original, $context ).',
		'Στο wp-config.php: σταματά τις αυτόματες μετατροπές και τις ανακατευθύνσεις.' => 'In wp-config.php: stops the automatic conversions and the redirects.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                             => 'Settings saved.',
		'Άγνωστη ενέργεια.'                                       => 'Unknown action.',
		'%d γραμμές κανόνων αγνοήθηκαν: θέλουν μία ελληνική λέξη, «=» και λατινικά γράμματα, αριθμούς ή παύλες.' => '%d rule lines were ignored: they need one Greek word, «=» and Latin letters, digits or dashes.',
		'Ξεκίνησε άλλη εφαρμογή στο μεταξύ. Ξανάκανε σάρωση.'     => 'Another run started in the meantime. Scan again.',
		'Το αρχείο είναι πολύ μεγάλο.'                            => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                             => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                        => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Greeklish Permalinks).' => 'Invalid backup file (the Greeklish Permalinks settings are missing).',
		'Το κείμενο του #%d άλλαξε μετά τη μετατροπή: οι σύνδεσμοι δεν γύρισαν πίσω.' => 'The text of #%d changed after the conversion: its links were not reverted.',
		'Η εισαγωγή ολοκληρώθηκε.'                                => 'Import completed.',
		'Made with ❤ by %s'                                       => 'Made with ❤ by %s',
		'More plugins at'                                         => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                          => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                      => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_greeklish-permalinks', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_greeklish-permalinks filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'greeklish-permalinks' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
