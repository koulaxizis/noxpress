<?php
/**
 * HIC_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Visitors see no text of ours: the plugin only hides things.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class HIC_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Hide Catalog χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Hide Catalog requires WooCommerce to be active — the plugin stays inactive until it is activated.',

		'Hide Catalog'                                              => 'Hide Catalog',
		'Απόκρυψη καταλόγου'                                        => 'Hide catalog',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Κανόνες'                                                   => 'Rules',
		'Εργαλεία'                                                  => 'Tools',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Απόκρυψη κατηγοριών και των προϊόντων τους από το shop, τις λίστες, την αναζήτηση και τα blocks, χωρίς αλλαγή στα προϊόντα.' => 'Hide categories and their products from the shop, the listings, search and blocks, without changing the products.',
		'Λειτουργία'                                                => 'Mode',
		'Ανενεργό'                                                  => 'Off',
		'Λειτουργία δοκιμής: οι κανόνες ισχύουν μόνο για τους διαχειριστές του καταστήματος' => 'Test mode: the rules apply to the store\'s managers only',
		'Ενεργό για όλους'                                          => 'On for everyone',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Το HIC_DISABLE είναι ενεργό στο wp-config.php: κανένας κανόνας δεν ισχύει.' => 'HIC_DISABLE is on in wp-config.php: no rule applies.',
		'Καθαρισμός της page cache (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache) σε κάθε αποθήκευση' => 'Clear the page cache (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache) on every save',
		'Το FiboSearch έχει δικό του ευρετήριο αναζήτησης: τα αποτελέσματά του δεν ακολουθούν ακόμα την απόκρυψη από την αναζήτηση.' => 'FiboSearch has its own search index: its results do not follow the search hiding yet.',
		'Το «Hide Categories Or Products On Shop Page» είναι ακόμα ενεργό. Μετέφερε τη ρύθμισή του από τα Εργαλεία και μετά απενεργοποίησέ το.' => '"Hide Categories Or Products On Shop Page" is still active. Move its setting over from Tools, then deactivate it.',
		'Ένας κανόνας ανά κατηγορία. Ό,τι δεν τσεκάρεις μένει όπως είναι. Τα προϊόντα δεν αλλάζουν: η απόκρυψη γίνεται τη στιγμή που φορτώνει η σελίδα και σταματά μόλις απενεργοποιηθεί το plugin.' => 'One rule per category. Whatever you leave unchecked stays as it is. Products are not changed: hiding happens when the page loads and stops as soon as the plugin is deactivated.',
		'Αποθήκευση'                                                => 'Save',
		'Γρήγορη ρύθμιση'                                           => 'Quick setup',
		'Απόκρυψη της «%s» από τις λίστες κατηγοριών'               => 'Hide "%s" from category lists',
		'Η προεπιλεγμένη κατηγορία του WooCommerce δεν σβήνεται. Αυτό την κρύβει από τις λίστες, τις κατηγορίες των προϊόντων και τα breadcrumbs· τα προϊόντα της μένουν ορατά.' => 'WooCommerce\'s default category cannot be deleted. This hides it from lists, product categories and breadcrumbs; its products stay visible.',

		'— Διάλεξε κατηγορία —'                                     => '— Choose a category —',
		'Νέος κανόνας'                                              => 'New rule',
		'Κατηγορία'                                                 => 'Category',
		'Και οι υποκατηγορίες της'                                  => 'Its subcategories too',
		'Τα προϊόντα της κρύβονται από'                             => 'Its products are hidden from',
		'Σελίδα shop'                                               => 'Shop page',
		'Άλλες λίστες προϊόντων (άλλες κατηγορίες, ετικέτες, χαρακτηριστικά)' => 'Other product listings (other categories, tags, attributes)',
		'Αναζήτηση'                                                 => 'Search',
		'Σχετικά προϊόντα, upsells και cross-sells'                 => 'Related products, upsells and cross-sells',
		'Shortcodes και blocks προϊόντων'                           => 'Product shortcodes and blocks',
		'Μόνο όταν όλες οι κατηγορίες του προϊόντος είναι κρυφές'   => 'Only when all of the product\'s categories are hidden',
		'Χωρίς αυτό, ένα προϊόν που ανήκει και σε ορατή κατηγορία κρύβεται κι αυτό.' => 'Without this, a product that is also in a visible category is hidden too.',
		'Η ίδια η κατηγορία'                                        => 'The category itself',
		'Κρυφή από τις λίστες κατηγοριών, τις κατηγορίες των προϊόντων και τα breadcrumbs' => 'Hidden from category lists, product categories and breadcrumbs',
		'Η σελίδα της κατηγορίας'                                   => 'The category page',
		'Ανοιχτή (προεπιλογή)'                                      => 'Open (default)',
		'Ανοιχτή, χωρίς ευρετηρίαση (noindex)'                      => 'Open, not indexed (noindex)',
		'Σελίδα 404'                                                => '404 page',
		'Ανακατεύθυνση στο shop'                                    => 'Redirect to the shop',
		'%1$d δημοσιευμένα προϊόντα, %2$d από αυτά και σε άλλη κατηγορία' => '%1$d published products, %2$d of them also in another category',
		'δεν κρύβει τίποτα'                                         => 'hides nothing',
		'Διαγραφή'                                                  => 'Delete',

		'Μεταφορά από το Hide Categories Or Products On Shop Page'  => 'Move over from Hide Categories Or Products On Shop Page',
		'Φτιάχνει τον αντίστοιχο κανόνα από τη ρύθμιση του παλιού plugin (μόνο ανάγνωση: το παλιό plugin δεν αλλάζει). Μετά απενεργοποίησέ το και έλεγξε το shop.' => 'Creates the matching rule from the old plugin\'s setting (read only: the old plugin is not changed). Then deactivate it and check the shop.',
		'Μεταφορά ρύθμισης'                                         => 'Move setting',
		'Backup ρυθμίσεων'                                          => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)'                                 => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                  => 'Export settings (JSON)',
		'Οι κανόνες δείχνουν τις κατηγορίες με το id τους: σε άλλο site κρατιούνται μόνο όσοι βρίσκουν κατηγορία με το ίδιο id.' => 'Rules point to categories by id: on another site only those that find a category with the same id are kept.',
		'Για προγραμματιστές'                                       => 'For developers',
		'Οι κρυφές κατηγορίες (ids, μαζί με τις υποκατηγορίες) για το τρέχον αίτημα. Context: shop, listings, search, related, blocks ή terms (η ίδια η κατηγορία). Κενό όταν το plugin δεν ισχύει για αυτό το αίτημα.' => 'The hidden categories (ids, subcategories included) for the current request. Context: shop, listings, search, related, blocks or terms (the category itself). Empty when the plugin does not apply to this request.',
		'Φίλτρο στο αποτέλεσμα της hic_hidden_terms() (ids, context).' => 'Filter on the result of hic_hidden_terms() (ids, context).',
		'Σταθερά στο wp-config.php που σταματά κάθε κανόνα.'        => 'Constant in wp-config.php that stops every rule.',

		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Καθαρίστηκε η page cache: %s.'                             => 'Page cache cleared: %s.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Δύο κανόνες είχαν την ίδια κατηγορία: κρατήθηκε ο πρώτος.' => 'Two rules had the same category: the first one was kept.',
		'Κρατήθηκαν μόνο οι πρώτοι 50 κανόνες.'                     => 'Only the first 50 rules were kept.',
		'Η λειτουργία είναι «Ανενεργό»: οι κανόνες δεν ισχύουν ακόμα.' => 'The mode is "Off": the rules do not apply yet.',
		'Ένας κανόνας δεν έχει τσεκαρισμένη καμία επιλογή και δεν κρύβει τίποτα.' => 'A rule has no option checked and hides nothing.',
		'Δεν βρέθηκε η προεπιλεγμένη κατηγορία.'                    => 'The default category was not found.',
		'Υπάρχει ήδη κανόνας για αυτή την κατηγορία.'               => 'There is already a rule for this category.',
		'Ο κανόνας προστέθηκε.'                                     => 'The rule was added.',
		'Η ρύθμιση του παλιού plugin δεν έχει έγκυρη κατηγορία.'    => 'The old plugin\'s setting has no valid category.',
		'Η ρύθμιση μεταφέρθηκε. Έλεγξε τον κανόνα, βάλε τη λειτουργία σε δοκιμή ή ενεργό και απενεργοποίησε το παλιό plugin.' => 'The setting was moved over. Check the rule, set the mode to test or on, and deactivate the old plugin.',
		'Το αρχείο είναι πολύ μεγάλο.'                              => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                               => 'No JSON file was selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                          => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Hide Catalog).' => 'Invalid backup file (the Hide Catalog settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: κρατήθηκαν %1$d από %2$d κανόνες (οι υπόλοιποι δείχνουν κατηγορία που δεν υπάρχει εδώ).' => 'The import was completed PARTIALLY: %1$d of %2$d rules were kept (the others point to a category that does not exist here).',
		'Η εισαγωγή ολοκληρώθηκε.'                                  => 'The import is complete.',

		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_hide-catalog', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_hide-catalog filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'hide-catalog' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
