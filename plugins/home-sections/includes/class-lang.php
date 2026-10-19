<?php
/**
 * HMS_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Visitors see no text of ours: the plugin only changes which products a
 * [products] shortcode lists.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class HMS_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Home Sections χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Home Sections requires WooCommerce to be active — the plugin stays inactive until it is activated.',

		'Home Sections'                                             => 'Home Sections',
		'Ενότητες αρχικής'                                          => 'Home sections',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Marker classes για το shortcode [products] του WooCommerce: γράφεις ένα class στο shortcode και η ενότητα αφήνει έξω προϊόντα, κατηγορίες ή προπαραγγελίες, ή κρατά μόνο προϊόντα με μια λέξη στον τίτλο. Τα προϊόντα δεν αλλάζουν.' => 'Marker classes for WooCommerce\'s [products] shortcode: write a class in the shortcode and the section leaves out products, categories or pre-orders, or keeps only products with a word in their title. Products are not changed.',
		'Το HMS_DISABLE είναι ενεργό στο wp-config.php: κανένας marker δεν ισχύει.' => 'HMS_DISABLE is on in wp-config.php: no marker applies.',
		'Έτοιμοι markers'                                           => 'Built-in markers',
		'Δουλεύουν χωρίς ρύθμιση. Βάλε όσους θέλεις στο class του shortcode, χωρισμένους με κενό· οι συνθήκες τους ισχύουν όλες μαζί.' => 'They work without any setup. Put as many as you like in the shortcode\'s class, separated by spaces; all their conditions apply together.',
		'Αφήνει έξω το προϊόν με αυτό το ID. Μπαίνει όσες φορές θέλεις (π.χ. το προϊόν που δείχνει ήδη το hero).' => 'Leaves out the product with this ID. Use it as many times as you like (e.g. the product the hero already shows).',
		'Αφήνει έξω τα προϊόντα μιας κατηγορίας και των υποκατηγοριών της, με το slug της. Μπαίνει όσες φορές θέλεις.' => 'Leaves out the products of a category and its subcategories, by its slug. Use it as many times as you like.',
		'Αφήνει έξω τις προπαραγγελίες (PRENA και Pre-Orders).'     => 'Leaves out pre-orders (PRENA and Pre-Orders).',
		'Κρατά μόνο προϊόντα με αυτή τη λέξη στον τίτλο, χωρίς διάκριση πεζών και κεφαλαίων (π.χ. hs-title-t-shirt βρίσκει «T-Shirt»). Μία λέξη, χωρίς κενά· για φράση με κενά φτιάξε preset.' => 'Keeps only products with this word in their title, ignoring case (e.g. hs-title-t-shirt finds "T-Shirt"). One word, no spaces; for a phrase with spaces, create a preset.',

		'Πρόθεμα alias'                                             => 'Alias prefix',
		'Πρόθεμα'                                                   => 'Prefix',
		'Προαιρετικό. Με πρόθεμα «glk», οι έτοιμοι markers δουλεύουν και ως glk-exclude-{ID}, glk-exclude-cat-{slug}, glk-no-preorders και glk-title-{λέξη}, ώστε να μένουν ίδια τα classes σελίδων που υπάρχουν ήδη. Μόνο πεζά λατινικά και αριθμοί, έως 20.' => 'Optional. With the prefix "glk", the built-in markers also work as glk-exclude-{ID}, glk-exclude-cat-{slug}, glk-no-preorders and glk-title-{word}, so the classes of existing pages can stay as they are. Lowercase Latin letters and digits only, up to 20.',
		'Presets'                                                   => 'Presets',
		'Ένα preset είναι ένας marker με δικό σου όνομα. Γράψε το όνομα στο class του shortcode και ισχύουν όλες οι συνθήκες του.' => 'A preset is a marker with a name of your own. Write the name in the shortcode\'s class and all its conditions apply.',
		'Νέο preset'                                                => 'New preset',
		'Όνομα (class)'                                             => 'Name (class)',
		'Πεζά λατινικά, αριθμοί και παύλες, 3 έως 60 χαρακτήρες. Δεν ξεκινά με «hs-».' => 'Lowercase Latin letters, digits and hyphens, 3 to 60 characters. It does not start with "hs-".',
		'Χωρίς τις κατηγορίες'                                      => 'Without the categories',
		'Και οι υποκατηγορίες τους. Ctrl/Cmd + κλικ για πολλές.'    => 'Their subcategories too. Ctrl/Cmd + click to pick several.',
		'Χωρίς τις προπαραγγελίες'                                  => 'Without pre-orders',
		'Ο τίτλος περιέχει'                                         => 'The title contains',
		'Χωρίς τα προϊόντα (IDs)'                                   => 'Without the products (IDs)',
		'Διαγραφή'                                                  => 'Delete',
		'Αποθήκευση'                                                => 'Save',

		'Δοκιμή shortcode'                                          => 'Shortcode test',
		'Επικόλλησε ένα shortcode [products …] για να δεις ποια προϊόντα επιστρέφει και πώς διαβάζεται κάθε class. Δεν αλλάζει τίποτα.' => 'Paste a [products …] shortcode to see which products it returns and how each class is read. Nothing is changed.',
		'Δοκιμή'                                                    => 'Test',
		'Classes'                                                   => 'Classes',
		'Το shortcode δεν έχει class: κανένας marker δεν ισχύει.'   => 'The shortcode has no class: no marker applies.',
		'Προϊόντα'                                                  => 'Products',
		'Η πρώτη σελίδα δείχνει %1$d από τα %2$d προϊόντα που βρίσκει το query (εδώ έως 20).' => 'The first page shows %1$d of the %2$d products the query finds (up to 20 here).',
		'Γράψε ένα μόνο shortcode της μορφής [products …].'         => 'Enter a single shortcode of the form [products …].',
		'Το WooCommerce δεν έχει το shortcode [products].'          => 'WooCommerce does not have the [products] shortcode.',
		'Η δοκιμή απέτυχε: το WooCommerce δεν μπόρεσε να φτιάξει το query αυτού του shortcode.' => 'The test failed: WooCommerce could not build the query of this shortcode.',
		'Δεν υπάρχει προϊόν #%d: δεν αφήνει έξω τίποτα.'            => 'There is no product #%d: it leaves nothing out.',
		'Αφήνει έξω το προϊόν #%1$d «%2$s».'                        => 'Leaves out product #%1$d "%2$s".',
		'Δεν υπάρχει κατηγορία με slug «%s»: δεν αφήνει έξω τίποτα.' => 'There is no category with the slug "%s": it leaves nothing out.',
		'Αφήνει έξω την κατηγορία «%s» και τις υποκατηγορίες της.'  => 'Leaves out the category "%s" and its subcategories.',
		'Αφήνει έξω τις προπαραγγελίες.'                            => 'Leaves out pre-orders.',
		'Μόνο προϊόντα με «%s» στον τίτλο.'                         => 'Only products with "%s" in their title.',
		'Δεν είναι marker: μένει απλό CSS class.'                   => 'Not a marker: it stays a plain CSS class.',
		'χωρίς τις κατηγορίες %s'                                   => 'without the categories %s',
		'χωρίς τις προπαραγγελίες'                                  => 'without pre-orders',
		'μόνο με «%s» στον τίτλο'                                   => 'only with "%s" in the title',
		'χωρίς τα προϊόντα %s'                                      => 'without the products %s',
		'Preset: %s.'                                               => 'Preset: %s.',

		'Για προγραμματιστές'                                       => 'For developers',
		'Φίλτρο στα meta keys των προπαραγγελιών (meta key => τιμή). Προεπιλογή: _simple_preorder = yes (PRENA) και _por_on = yes (Pre-Orders).' => 'Filter on the pre-order meta keys (meta key => value). Default: _simple_preorder = yes (PRENA) and _por_on = yes (Pre-Orders).',
		'Σταθερά στο wp-config.php που σταματά κάθε marker.'        => 'Constant in wp-config.php that stops every marker.',

		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Το πρόθεμα δεν κρατήθηκε: μόνο πεζά λατινικά και αριθμοί, έως 20, που ξεκινούν με γράμμα, και όχι «hs».' => 'The prefix was not kept: lowercase Latin letters and digits only, up to 20, starting with a letter, and not "hs".',
		'Δεν κρατήθηκαν presets με άκυρο όνομα ή όνομα έτοιμου marker: %s.' => 'Presets with an invalid name or the name of a built-in marker were not kept: %s.',
		'Δεν κρατήθηκαν presets χωρίς καμία συνθήκη: %s.'           => 'Presets without any condition were not kept: %s.',
		'Δύο presets είχαν το ίδιο όνομα: κρατήθηκε το πρώτο.'      => 'Two presets had the same name: the first one was kept.',
		'Κρατήθηκαν μόνο τα πρώτα 20 presets.'                      => 'Only the first 20 presets were kept.',
		'Καθαρίστηκε η page cache: %s.'                             => 'The page cache was cleared: %s.',

		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_home-sections', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_home-sections filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'home-sections' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
