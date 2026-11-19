<?php
/**
 * VLG_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ (Bible §9): 'el' | 'en' | ''/'auto'. Only
 * Revenue Splitter writes it; without RS this WordPress-only plugin shows
 * the same switch and writes the same meta (§9, 1.39). Without an explicit
 * choice the user's locale decides: el* → Greek (msgids pass through as
 * they are), anything else → English. The English front end never shows
 * these msgids (its note is plain English).
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class VLG_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Visitor Language: μια αγγλική εκδοχή του site στο /en/, με αγγλικά κείμενα για όσα προϊόντα και σελίδες θέλεις.' => 'Visitor Language: an English version of the site at /en/, with English texts for the products and pages you want.',
		'hreflang el / en στις σελίδες με αγγλικά κείμενα, για τις μηχανές αναζήτησης' => 'hreflang el / en on pages with English texts, for search engines',
		'«el» ή «en» για το τρέχον αίτημα.' => '«el» or «en» for the current request.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Ένας σύνδεσμος του site στην ελληνική ή στην αγγλική εκδοχή.' => 'A link of the site in its Greek or English version.',
		'Ίδιο' => 'Same',
		'Όνομα προϊόντος' => 'Product name',
		'Όπως η γλώσσα του λογαριασμού' => 'Same as the account language',
		'Αγγλικά' => 'English',
		'Αγγλικά Βρετανίας (en_GB, χρειάζεται τη γλώσσα εγκατεστημένη)' => 'British English (en_GB, needs the language installed)',
		'Αγγλικά ΗΠΑ (en_US)' => 'US English (en_US)',
		'Αποθήκευση' => 'Save',
		'Απόσπασμα' => 'Excerpt',
		'Για developers' => 'For developers',
		'Γλώσσα παραγγελίας' => 'Order language',
		'Για κάθε θέση μενού του θέματος, το μενού που βλέπει ο επισκέπτης στα αγγλικά. «Ίδιο» = το ελληνικό, με τα σύντομα κείμενα μεταφρασμένα.' => 'For every menu location of the theme, the menu an English visitor sees. «Same» = the Greek one, with the short texts translated.',
		'Γλώσσα και developers' => 'Language and developers',
		'Γλώσσα των σελίδων Noxpress' => 'Language of the Noxpress pages',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Δες την αγγλική σελίδα' => 'See the English page',
		'Διακόπτης' => 'Switch',
		'Επιλογή γλώσσας' => 'Language switch',
		'Η αγγλική εκδοχή απαντά στο /en/' => 'The English version answers at /en/',
		'Η επιλογή EL / EN όπου το βάλεις.' => 'The EL / EN switch wherever you put it.',
		'Κάποιες γραμμές δεν είχαν τη μορφή «Ελληνικά = English» ή ξεπέρασαν τις 300, και αγνοήθηκαν.' => 'Some lines were not in the form «Ελληνικά = English» or went over 300, and were skipped.',
		'Κανένα ακόμα. Γράψε τα αγγλικά στο κουτί «English» του προϊόντος ή της σελίδας.' => 'None yet. Write the English texts in the «English» box of the product or page.',
		'Κατάσταση' => 'Status',
		'Κλειστό: το /en/ δίνει 404, όπως πριν.' => 'Off: /en/ gives 404, as before.',
		'Μία γραμμή για κάθε κείμενο, «Ελληνικά = English». Ισχύει για ονόματα και τιμές χαρακτηριστικών (π.χ. Μέγεθος, Χρώμα), κατηγορίες και ετικέτες προϊόντων, στοιχεία μενού, τρόπους αποστολής και πληρωμής. Έως 300 γραμμές.' => 'One line per text, «Ελληνικά = English». It applies to attribute names and values (e.g. Μέγεθος, Χρώμα), product categories and tags, menu items, shipping and payment methods. Up to 300 lines.',
		'Με αγγλικά κείμενα' => 'With English texts',
		'Μενού' => 'Menus',
		'Μια αγγλική εκδοχή του site στο /en/, για επισκέπτες από το εξωτερικό. Το WordPress, το WooCommerce, το θέμα και τα plugins Noxpress μιλούν αγγλικά μόνα τους· εσύ γράφεις στα αγγλικά μόνο όσα προϊόντα και σελίδες θέλεις. Το ελληνικό site δεν αλλάζει.' => 'An English version of the site at /en/, for visitors from abroad. WordPress, WooCommerce, the theme and the Noxpress plugins speak English on their own; you write English only for the products and pages you want. The Greek site does not change.',
		'Μικρό κουμπί EL / EN κάτω αριστερά σε κάθε σελίδα' => 'A small EL / EN button at the bottom left of every page',
		'Μόνο όπου βάζω το shortcode [vlg_switch]' => 'Only where I put the [vlg_switch] shortcode',
		'Και σε κάθε σελίδα για επισκέπτες εκτός Ελλάδας (η χώρα έρχεται από το Cloudflare του site, χωρίς υπηρεσία τρίτων)' => 'Also on every page for visitors outside Greece (the country comes from the site\'s own Cloudflare, with no third-party service)',
		'Το ίδιο κουμπί μόνο στα προϊόντα με αγγλικά κείμενα (π.χ. όσα στέλνονται στο εξωτερικό)' => 'The same button only on products with English texts (e.g. those shipped abroad)',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Περιγραφή' => 'Description',
		'Περιεχόμενο' => 'Content',
		'Ρυθμίσεις' => 'Settings',
		'Σελίδες χωρίς αγγλικά' => 'Pages without English',
		'Σημείωση «This page is only available in Greek» στην αρχή τους' => 'A «This page is only available in Greek» note at their top',
		'Στο wp-config.php: σταματά αμέσως το /en/.' => 'In wp-config.php: stops /en/ at once.',
		'Σύντομα κείμενα' => 'Short texts',
		'Σύντομη περιγραφή' => 'Short description',
		'Τίτλος' => 'Title',
		'Τα αγγλικά κείμενα, ως post meta, και στο REST API (wp/v2 και wc/v3, με δικαίωμα επεξεργασίας).' => 'The English texts, as post meta, also in the REST API (wp/v2 and wc/v3, with edit permission).',
		'Τα κείμενα που βλέπει ο επισκέπτης στο /en/. Ό,τι μείνει κενό δείχνεται στα ελληνικά.' => 'The texts a visitor sees at /en/. Whatever stays empty is shown in Greek.',
		'Το VLG_DISABLE είναι ενεργό στο wp-config.php: το /en/ δεν απαντά, ό,τι κι αν λένε οι ρυθμίσεις.' => 'VLG_DISABLE is on in wp-config.php: /en/ does not answer, whatever the settings say.',
		'Το θέμα δεν έχει θέσεις μενού.' => 'The theme has no menu locations.',
		'Τύπος' => 'Type',
		'Φίλτρο: οι τύποι περιεχομένου με κουτί «English» (page, post, product).' => 'Filter: the content types with an «English» box (page, post, product).',
		'κείμενο' => 'content',
		'σύντομο' => 'short',
		'τίτλος' => 'title',
		'♡ Στήριξε το Noxpress' => '♡ Support Noxpress',
	);

	public static function init(): void {
		add_filter( 'gettext_visitor-language', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_visitor-language', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** Revenue Splitter is active (then only RS shows the language switch). */
	public static function rs_active(): bool {
		return class_exists( 'RS_Lang' ) || defined( 'RS_VERSION' );
	}

	/** Forgets the cached choice (after the language is changed). */
	public static function reset(): void {
		self::$lang = null;
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

	/** gettext_visitor-language filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'visitor-language' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_visitor-language filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'visitor-language' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
