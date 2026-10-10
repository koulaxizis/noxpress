<?php
/**
 * SHF_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin (and the widget form): rs_lang (user meta) is READ only
 * (Bible §9): 'el' | 'en' | ''/'auto'. Only Revenue Splitter writes it.
 * Without an explicit choice (or without RS) the user's locale decides:
 * el* → Greek (msgids pass through as they are), anything else → English.
 *
 * Front end (the filters visitors see): the site language decides, the
 * same for every visitor and for logged-in shop managers.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Shop Filters χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Shop Filters requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Shop Filters'                                             => 'Shop Filters',
		'Shop Filters — Ρυθμίσεις'                                 => 'Shop Filters — Settings',
		'SHF Ρυθμίσεις'                                            => 'SHF Settings',
		'Ρυθμίσεις'                                                => 'Settings',
		'Κατηγορία'                                                => 'Category',
		'Τιμή'                                                     => 'Price',
		'Χαρακτηριστικό: %s'                                       => 'Attribute: %s',
		'Άμεσα στον υπολογιστή, με κουμπί «Εφαρμογή» στο κινητό'   => 'Immediately on desktop, with an "Apply" button on mobile',
		'Πάντα άμεσα (κάθε κλικ φορτώνει τα αποτελέσματα)'         => 'Always immediately (every click loads the results)',
		'Πάντα με κουμπί «Εφαρμογή»'                               => 'Always with an "Apply" button',
		'Απόκρυψη'                                                 => 'Hide',
		'Εμφάνιση αχνά, χωρίς σύνδεσμο'                            => 'Show dimmed, without a link',
		'Κρυφές από το φίλτρο'                                     => 'Hidden from the filter',
		'Σε μια αυτόματη ομάδα «Άλλο»'                             => 'In an automatic "Other" group',
		'Μόνες τους, μετά τις ομάδες'                              => 'On their own, after the groups',
		'Μήνες'                                                    => 'Months',
		'Έτη'                                                      => 'Years',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'          => 'You do not have access to this page.',
		'Φίλτρα προϊόντων με κατηγορίες, χαρακτηριστικά και τιμή. Οι ομάδες τιμών αλλάζουν μόνο ό,τι δείχνει το φίλτρο: τα προϊόντα και οι σελίδες τους μένουν ίδια.' => 'Product filters by category, attribute and price. Value groups change only what the filter shows: products and their pages stay the same.',
		'Κατάσταση'                                                => 'Status',
		'Shop Filters:'                                            => 'Shop Filters:',
		'Απενεργοποιημένο από το SHF_DISABLE (wp-config.php)'      => 'Disabled by SHF_DISABLE (wp-config.php)',
		'Ανενεργό'                                                 => 'Off',
		'Λειτουργία δοκιμής — τα φίλτρα φαίνονται μόνο στους διαχειριστές του καταστήματος' => 'Test mode — the filters are visible to shop managers only',
		'Ενεργό για όλους τους επισκέπτες'                         => 'Active for all visitors',
		'Αλλαγή'                                                   => 'Change',
		'Widget:'                                                  => 'Widget:',
		'Υπάρχει σε sidebar'                                       => 'Placed in a sidebar',
		'Δεν έχει μπει σε sidebar'                                 => 'Not placed in a sidebar',
		'Widgets'                                                  => 'Widgets',
		'Widget «Noxpress: Φίλτρα», ή το shortcode [shf_filters].' => 'Widget "Noxpress: Φίλτρα", or the shortcode [shf_filters].',
		'Πηγή μετρητών:'                                           => 'Count source:',
		'Πίνακας χαρακτηριστικών του WooCommerce'                  => 'WooCommerce attributes lookup table',
		'Μια παραλλαγή (π.χ. χρώμα) μετρά μόνο όταν είναι σε απόθεμα.' => 'A variation (e.g. a colour) counts only when it is in stock.',
		'Εφεδρεία: όροι προϊόντων'                                 => 'Fallback: product terms',
		'Object cache:'                                            => 'Object cache:',
		'Μόνιμη'                                                   => 'Persistent',
		'Μόνο ανά αίτημα'                                          => 'Per request only',
		'Με %d προϊόντα οι μετρητές χτίζονται σε κάθε σελίδα και αργούν. Μια μόνιμη object cache (Redis ή Memcached, από τη φιλοξενία) τους κρατά έτοιμους.' => 'With %d products the counts are built on every page and take longer. A persistent object cache (Redis or Memcached, from your host) keeps them ready.',
		'Ο πίνακας χαρακτηριστικών του WooCommerce λείπει ή ξαναχτίζεται (WooCommerce → Ρυθμίσεις → Προϊόντα → Για προχωρημένους). Τα φίλτρα δουλεύουν, χωρίς έλεγχο αποθέματος ανά παραλλαγή.' => 'WooCommerce\'s attributes lookup table is missing or being rebuilt (WooCommerce → Settings → Products → Advanced). The filters work, without a stock check per variation.',
		'%1$d τιμές του «%2$s» δεν ανήκουν σε καμία ομάδα.'        => '%1$d values of "%2$s" belong to no group.',
		'Αντιστοίχιση'                                             => 'Assign',
		'Σετ φίλτρων'                                              => 'Filter sets',
		'Ένα σετ ορίζει ποια φίλτρα φαίνονται και με ποια σειρά. Το σετ χωρίς κατηγορίες είναι το προεπιλεγμένο. Ένα σετ με κατηγορίες ισχύει σε αυτές και στις υποκατηγορίες τους.' => 'A set defines which filters show and in what order. The set without categories is the default one. A set with categories applies to them and to their subcategories.',
		'Όνομα'                                                    => 'Name',
		'Κατηγορίες'                                               => 'Categories',
		'Φίλτρα'                                                   => 'Filters',
		'Προεπιλογή'                                               => 'Default',
		'Επεξεργασία'                                              => 'Edit',
		'Δεν υπάρχει ακόμα σετ φίλτρων. Το πρώτο σετ γίνεται το προεπιλεγμένο.' => 'There is no filter set yet. The first set becomes the default one.',
		'Νέο σετ φίλτρων'                                          => 'New filter set',
		'Όλα τα σετ'                                               => 'All sets',
		'Σύρε τις γραμμές για να αλλάξεις τη σειρά. «Ανοιχτό»: το φίλτρο ξεκινά ανοιχτό (ένα φίλτρο με ενεργή επιλογή ανοίγει πάντα).' => 'Drag the rows to change the order. "Open": the filter starts open (a filter with an active choice always opens).',
		'Τίτλος'                                                   => 'Title',
		'Ανοιχτό'                                                  => 'Open',
		'Μετρητές'                                                 => 'Counts',
		'Αφαίρεση'                                                 => 'Remove',
		'Δεν υπάρχουν φίλτρα σε αυτό το σετ.'                      => 'There are no filters in this set.',
		'Προσθήκη φίλτρου:'                                        => 'Add filter:',
		'— Καμία —'                                                => '— None —',
		'Δεν υπάρχουν καθολικά χαρακτηριστικά (Προϊόντα → Χαρακτηριστικά). Τα χαρακτηριστικά που γράφονται μόνο μέσα σε ένα προϊόν δεν φιλτράρονται.' => 'There are no global attributes (Products → Attributes). Attributes typed inside a single product cannot be filtered.',
		'Χωρίς επιλογή: προεπιλεγμένο σετ (κατάστημα και κάθε κατηγορία χωρίς δικό της σετ). Με επιλογή: το σετ ισχύει σε αυτές τις κατηγορίες και στις υποκατηγορίες τους, αν δεν έχουν δικό τους.' => 'None selected: default set (shop and every category without a set of its own). With a selection: the set applies to these categories and to their subcategories, unless they have their own.',
		'Αποθήκευση σετ'                                           => 'Save set',
		'Διαγραφή σετ'                                             => 'Delete set',
		'Ναι, διάγραψε αυτό το σετ'                                => 'Yes, delete this set',
		'Διαγραφή'                                                 => 'Delete',
		'Δεν υπάρχουν κατηγορίες προϊόντων.'                       => 'There are no product categories.',
		'Δεν υπάρχουν καθολικά χαρακτηριστικά (Προϊόντα → Χαρακτηριστικά).' => 'There are no global attributes (Products → Attributes).',
		'Χαρακτηριστικό'                                           => 'Attribute',
		'%1$d τιμές, %2$d ομάδες. Το φίλτρο δείχνει τις ομάδες· η σελίδα του προϊόντος συνεχίζει να δείχνει την αναλυτική τιμή.' => '%1$d values, %2$d groups. The filter shows the groups; the product page keeps showing the detailed value.',
		'Ομάδες'                                                   => 'Groups',
		'Εύρος: για τιμές όπως «6-18M», «12 μηνών-5 ετών», «3 Ετών+». Το «Έως» δεν περιλαμβάνεται (0–6 και 6–12 δεν επικαλύπτονται)· κενό «Έως» = χωρίς όριο. Λέξεις: χωρισμένες με κόμμα, χωρίς διάκριση τόνων και πεζών-κεφαλαίων (π.χ. «γκρι, grey, gray, graphite»). Εύρος και λέξεις χρησιμοποιούνται μόνο για προτάσεις.' => 'Range: for values like "6-18M", "12 μηνών-5 ετών", "3 Ετών+". "To" is not included (0–6 and 6–12 do not overlap); an empty "To" = no limit. Keywords: comma separated, accent and case insensitive (e.g. "γκρι, grey, gray, graphite"). Range and keywords are only used for suggestions.',
		'Μονάδα εύρους:'                                           => 'Range unit:',
		'Τιμές χωρίς ομάδα:'                                       => 'Values in no group:',
		'Ετικέτα'                                                  => 'Label',
		'Slug (URL)'                                               => 'Slug (URL)',
		'Εύρος από'                                                => 'Range from',
		'Έως'                                                      => 'To',
		'Λέξεις'                                                   => 'Keywords',
		'Τιμές'                                                    => 'Values',
		'Νέα ομάδα'                                                => 'New group',
		'Αποθήκευση ομάδων'                                        => 'Save groups',
		'Αντιστοίχιση τιμών'                                       => 'Value assignment',
		'Πρότεινε αντιστοίχιση'                                    => 'Suggest assignment',
		'Όλες οι τιμές'                                            => 'All values',
		'Μόνο χωρίς ομάδα'                                         => 'Only values in no group',
		'Οι προτάσεις είναι τσεκαρισμένες και σημειωμένες με πλαίσιο. Δεν αποθηκεύεται τίποτα πριν πατήσεις «Αποθήκευση αντιστοίχισης».' => 'Suggestions are checked and outlined. Nothing is saved until you press "Save assignment".',
		'Δεν υπάρχουν ομάδες: το φίλτρο δείχνει κάθε τιμή χωριστά. Μπορείς να κρύψεις λανθασμένες τιμές με το «Αγνόησε».' => 'There are no groups: the filter shows every value on its own. You can hide wrong values with "Ignore".',
		'Προϊόντα'                                                 => 'Products',
		'Αγνόησε'                                                  => 'Ignore',
		'ύποπτη'                                                   => 'suspicious',
		'χωρίς ομάδα'                                              => 'no group',
		'δεν αναγνωρίστηκε'                                        => 'not recognised',
		'Καμία τιμή για εμφάνιση.'                                 => 'No values to show.',
		'Αποθήκευση αντιστοίχισης'                                 => 'Save assignment',
		'Λειτουργία'                                               => 'Mode',
		'Ενεργοποίηση των φίλτρων στο front end'                   => 'Enable the filters on the front end',
		'Λειτουργία δοκιμής: τα φίλτρα φαίνονται και φιλτράρουν μόνο για όσους διαχειρίζονται το κατάστημα' => 'Test mode: the filters show and filter for shop managers only',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Το SHF_DISABLE είναι ενεργό: τα φίλτρα δεν εμφανίζονται και δεν φιλτράρουν.' => 'SHF_DISABLE is on: the filters do not show and do not filter.',
		'Συμπεριφορά'                                              => 'Behaviour',
		'Εφαρμογή επιλογών'                                        => 'Applying choices',
		'Χωρίς JavaScript κάθε επιλογή εφαρμόζεται αμέσως.'        => 'Without JavaScript every choice applies immediately.',
		'Επιλογές χωρίς αποτελέσματα'                              => 'Options without results',
		'Μέγιστος αριθμός επιλεγμένων τιμών'                       => 'Maximum number of selected values',
		'Περισσότερες τιμές στο URL αγνοούνται.'                   => 'Further values in the URL are ignored.',
		'SEO'                                                      => 'SEO',
		'Οι σελίδες με ενεργά φίλτρα παίρνουν «noindex, follow» και canonical προς τη σελίδα χωρίς φίλτρα' => 'Pages with active filters get "noindex, follow" and a canonical to the page without filters',
		'Συνεργάζεται με Yoast SEO και Rank Math. Οι σύνδεσμοι των φίλτρων έχουν πάντα rel="nofollow".' => 'Works with Yoast SEO and Rank Math. Filter links always have rel="nofollow".',
		'Αποθήκευση ρυθμίσεων'                                     => 'Save settings',
		'Backup & Επαναφορά'                                       => 'Backup & Restore',
		'Εισαγωγή ρυθμίσεων (JSON)'                                => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                 => 'Export settings (JSON)',
		'Περιλαμβάνονται οι ρυθμίσεις, τα σετ φίλτρων και οι ομάδες τιμών. Οι ομάδες αναφέρονται σε όρους με το ID τους, άρα μεταφέρονται μόνο σε αντίγραφο του ίδιου site. Η εισαγωγή αντικαθιστά ό,τι περιέχει το αρχείο, μετά από πλήρη έλεγχο εγκυρότητας.' => 'Includes the settings, the filter sets and the value groups. Groups refer to terms by their ID, so they only carry over to a copy of the same site. The import replaces what the file contains, after a full validity check.',
		'Άγνωστη ενέργεια.'                                        => 'Unknown action.',
		'Το Shop Filters είναι ακόμη ανενεργό: ενεργοποίησέ το από τις Ρυθμίσεις (με τη λειτουργία δοκιμής ενεργή, τα φίλτρα φαίνονται μόνο σε εσένα).' => 'Shop Filters is still off: enable it in the Settings (with test mode on, only you see the filters).',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                              => 'Settings saved.',
		'Έφτασες το όριο των σετ φίλτρων.'                         => 'You have reached the limit of filter sets.',
		'Σετ %d'                                                   => 'Set %d',
		'Το σετ δημιουργήθηκε. Πρόσθεσε φίλτρα χαρακτηριστικών και αποθήκευσε.' => 'The set was created. Add attribute filters and save.',
		'Το σετ δεν βρέθηκε.'                                      => 'The set was not found.',
		'Κάποιες κατηγορίες ανήκουν ήδη στο σετ «%s» και δεν προστέθηκαν.' => 'Some categories already belong to the set "%s" and were not added.',
		'Υπάρχει ήδη προεπιλεγμένο σετ (χωρίς κατηγορίες): ισχύει το πρώτο στη λίστα.' => 'There is already a default set (without categories): the first one in the list applies.',
		'Το σετ αποθηκεύτηκε.'                                     => 'Set saved.',
		'Επιβεβαίωσε τη διαγραφή.'                                 => 'Confirm the deletion.',
		'Το σετ διαγράφηκε.'                                       => 'Set deleted.',
		'Άγνωστο χαρακτηριστικό.'                                  => 'Unknown attribute.',
		'Οι ομάδες αποθηκεύτηκαν.'                                 => 'Groups saved.',
		'Μη έγκυρα δεδομένα αντιστοίχισης.'                        => 'Invalid assignment data.',
		'Η αντιστοίχιση αποθηκεύτηκε.'                             => 'Assignment saved.',
		'Δεν επιλέχθηκε αρχείο JSON.'                              => 'No JSON file was selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                         => 'The file is not valid JSON.',
		'Το αρχείο είναι πολύ μεγάλο.'                             => 'The file is too large.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Shop Filters).' => 'Invalid backup file (the Shop Filters settings are missing).',
		'Οι γενικές ρυθμίσεις δεν ήταν έγκυρες και δεν άλλαξαν.'   => 'The general settings were not valid and were not changed.',
		'Τα σετ φίλτρων δεν ήταν έγκυρα και δεν άλλαξαν.'          => 'The filter sets were not valid and were not changed.',
		'Οι ομάδες του «%s» παραλείφθηκαν (άγνωστο χαρακτηριστικό ή άκυρα δεδομένα).' => 'The groups of "%s" were skipped (unknown attribute or invalid data).',
		'Δεν εισήχθη τίποτα.'                                      => 'Nothing was imported.',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ.'                         => 'The import completed PARTIALLY.',
		'Η εισαγωγή ολοκληρώθηκε.'                                 => 'Import completed.',
		'Made with ❤ by %s'                                        => 'Made with ❤ by %s',
		'More plugins at'                                          => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                           => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                       => 'Noxpress Dashboard',
		'Άλλο'                                                     => 'Other',
		'Εφαρμογή'                                                 => 'Apply',
		'Άνοιγμα ή κλείσιμο υποκατηγοριών'                         => 'Open or close subcategories',
		'Ενεργά φίλτρα'                                            => 'Active filters',
		'Καθαρισμός όλων'                                          => 'Clear all',
		'Αφαίρεση φίλτρου: %s'                                     => 'Remove filter: %s',
		'Όλα τα προϊόντα'                                          => 'All products',
		'Ελάχιστη τιμή'                                            => 'Minimum price',
		'Μέγιστη τιμή'                                             => 'Maximum price',
		'Από'                                                      => 'From',
		'Noxpress: Φίλτρα'                                         => 'Noxpress: Filters',
		'Φίλτρα προϊόντων του Shop Filters (σετ φίλτρων ανά κατηγορία).' => 'Product filters by Shop Filters (filter sets per category).',
		'Τίτλος (προαιρετικός):'                                   => 'Title (optional):',
		'Σετ φίλτρων:'                                             => 'Filter set:',
		'Αυτόματα, ανά κατηγορία'                                  => 'Automatic, by category',
		'Δεν υπάρχει ακόμα σετ φίλτρων: φτιάξε ένα στο Noxpress → Shop Filters.' => 'There is no filter set yet: create one in Noxpress → Shop Filters.',
		'Ομάδες τιμών'                                             => 'Value groups',
	);

	public static function init(): void {
		add_filter( 'gettext_shop-filters', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en': the user's choice in the admin, the site language on the front end. */
	public static function lang(): string {
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		$front = ! is_admin();
		if ( $front ) {
			$lang = ( 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ) ? 'el' : 'en';
			self::$lang = $lang;
			return $lang;
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

	/** gettext_shop-filters filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'shop-filters' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
