<?php
/**
 * PKP_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts visitors see are store settings with a Greek and an English
 * value (Bible §14.17, PKP_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class PKP_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Store Pickup χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Store Pickup requires WooCommerce to be active — the plugin stays inactive until it is activated.',

		// Shipping method (zone modal).
		'Ο πελάτης παραλαμβάνει την παραγγελία από ένα από τα καταστήματά σου, που τα διαλέγει στο ταμείο. Τα καταστήματα ορίζονται στο Noxpress › Παραλαβή.' => 'The customer collects the order from one of your stores, chosen at checkout. The stores are set in Noxpress › Pickup.',
		'Όνομα'                                                     => 'Name',
		'Το όνομα της μεθόδου στο ταμείο.'                          => 'The name of the method at checkout.',
		'Όνομα (English)'                                           => 'Name (English)',
		'Για επισκέπτες στα αγγλικά. Κενό = το ελληνικό.'           => 'For visitors in English. Empty = the Greek one.',
		'Κόστος'                                                    => 'Cost',
		'Ένα σταθερό ποσό. 0 = χωρίς χρέωση.'                       => 'A fixed amount. 0 = no charge.',
		'Κατάσταση φόρου'                                           => 'Tax status',
		'Κανένας'                                                   => 'None',
		'Φορολογήσιμο'                                              => 'Taxable',
		'Καταστήματα'                                               => 'Stores',
		'Ποια καταστήματα προσφέρονται σε αυτή τη ζώνη. Κενό = όλα τα ενεργά.' => 'Which stores are offered in this zone. Empty = every active store.',
		'Δεν υπάρχει ακόμα ενεργό κατάστημα: πρόσθεσέ τα στο Noxpress › Παραλαβή.' => 'There is no active store yet: add them in Noxpress › Pickup.',

		// Settings page.
		'Κείμενα'                                                   => 'Texts',
		'Εργαλεία'                                                  => 'Tools',
		'Store Pickup'                                              => 'Store Pickup',
		'Παραλαβή'                                                  => 'Pickup',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Παραλαβή από το κατάστημα ως μέθοδος αποστολής, με επιλογή καταστήματος στο ταμείο.' => 'Store pickup as a shipping method, with a store choice at checkout.',
		'Ανενεργό'                                                  => 'Off',
		'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: only the store\'s managers see it',
		'Ενεργό για όλους'                                          => 'On for everyone',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Περιοχές που δεν καλύπτονται από άλλες ζώνες'              => 'Locations not covered by your other zones',
		'Λειτουργία'                                                => 'Mode',
		'Το PKP_DISABLE είναι ενεργό στο wp-config.php: η μέθοδος δεν εμφανίζεται πουθενά.' => 'PKP_DISABLE is on in wp-config.php: the method does not show anywhere.',
		'Απόκρυψη της διεύθυνσης αποστολής όταν ο πελάτης διαλέγει παραλαβή (ταμείο, παραγγελία, emails)' => 'Hide the shipping address when the customer chooses pickup (checkout, order, emails)',
		'Ζώνες αποστολής'                                           => 'Shipping zones',
		'Η μέθοδος «Παραλαβή από το κατάστημα» μπαίνει σε κάθε ζώνη όπως όλες οι μέθοδοι του WooCommerce. Εκεί ορίζεις όνομα, κόστος (0 = χωρίς χρέωση) και ποια καταστήματα προσφέρονται.' => 'The "Store pickup" method is added to each zone like every WooCommerce method. There you set its name, cost (0 = no charge) and which stores are offered.',
		'Ζώνη'                                                      => 'Zone',
		'Παραλαβή από το κατάστημα'                                 => 'Store pickup',
		'ενεργή'                                                    => 'enabled',
		'ανενεργή'                                                  => 'disabled',
		'δεν έχει προστεθεί'                                        => 'not added',
		'Ζώνες αποστολής του WooCommerce'                           => 'WooCommerce shipping zones',
		'Ο πελάτης βλέπει τα ενεργά καταστήματα με αυτή τη σειρά. Οι ώρες και οι διευκρινίσεις δέχονται έως 5 γραμμές. Κενό αγγλικό κείμενο = δείχνει το ελληνικό.' => 'The customer sees the active stores in this order. Hours and details take up to 5 lines. Empty English text = shows the Greek.',
		'Αποθήκευση'                                                => 'Save',
		'Νέο κατάστημα'                                             => 'New store',
		'Διεύθυνση'                                                 => 'Address',
		'Ώρες και διευκρινίσεις (Ελληνικά)'                         => 'Hours and details (Greek)',
		'Ώρες και διευκρινίσεις (English)'                          => 'Hours and details (English)',
		'Σύνδεσμος χάρτη (προαιρετικό)'                             => 'Map link (optional)',
		'Σειρά'                                                     => 'Order',
		'Ενεργό'                                                    => 'Active',
		'Διαγραφή'                                                  => 'Delete',
		'Τίτλος της λίστας στο ταμείο και γραμμή στην παραγγελία'   => 'Title of the list at checkout and row on the order',
		'Ετικέτα των ωρών στην παραγγελία (admin)'                  => 'Label of the hours on the order (admin)',
		'Σφάλμα όταν δεν επιλέχθηκε κατάστημα'                      => 'Error when no store was chosen',
		'Σύνδεσμος χάρτη'                                           => 'Map link',
		'Κείμενα για τον πελάτη'                                    => 'Customer texts',
		'Γλώσσα κειμένων'                                           => 'Text language',
		'Αυτόματα (γλώσσα του site)'                                => 'Automatic (site language)',
		'Ελληνικά'                                                  => 'Ελληνικά',
		'English'                                                   => 'English',
		'Το όνομα της μεθόδου (ελληνικά και αγγλικά) ορίζεται σε κάθε ζώνη αποστολής.' => 'The name of the method (Greek and English) is set in each shipping zone.',
		'Κείμενο'                                                   => 'Text',
		'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό.' => 'Empty Greek text = the default. Empty English = shows the Greek.',
		'Backup ρυθμίσεων'                                          => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)'                                 => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                  => 'Export settings (JSON)',
		'Το backup έχει τα καταστήματα, τους κανόνες και τα κείμενα. Οι ρυθμίσεις της μεθόδου σε κάθε ζώνη (όνομα, κόστος, καταστήματα) μένουν στο WooCommerce.' => 'The backup holds the stores, the rules and the texts. The method\'s settings in each zone (name, cost, stores) stay in WooCommerce.',
		'Για προγραμματιστές'                                       => 'For developers',
		'Το κατάστημα παραλαβής μιας παραγγελίας (id, όνομα, διεύθυνση, διευκρινίσεις, χάρτης), όπως αποθηκεύτηκε στο ταμείο, ή null.' => 'The pickup store of an order (id, name, address, details, map) as saved at checkout, or null.',
		'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).'             => 'Filter for the customer texts (e.g. WPML).',
		'Το id της μεθόδου αποστολής.'                              => 'The id of the shipping method.',
		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Ένας σύνδεσμος χάρτη δεν ήταν έγκυρη διεύθυνση http(s) και αγνοήθηκε.' => 'A map link was not a valid http(s) address and was ignored.',
		'Κρατήθηκαν μόνο τα πρώτα 20 καταστήματα.'                  => 'Only the first 20 stores were kept.',
		'Δεν υπάρχει ενεργό κατάστημα: η μέθοδος δεν εμφανίζεται στο ταμείο.' => 'There is no active store: the method does not show at checkout.',
		'Καμία ζώνη αποστολής δεν έχει ενεργή τη μέθοδο «Παραλαβή από το κατάστημα»: πρόσθεσέ τη στις ζώνες του WooCommerce.' => 'No shipping zone has the "Store pickup" method enabled: add it to your WooCommerce zones.',
		'Το αρχείο είναι πολύ μεγάλο.'                              => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                               => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                          => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Store Pickup).' => 'Invalid backup file (the Store Pickup settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.'                                  => 'Import completed.',
		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',

		// 1.1.0: no-pickup rule, admin email line, shortcode.
		'Γραμμή «Παραλαβή από: …» στην κορυφή του email «Νέα παραγγελία» του διαχειριστή' => 'A "Pickup at: …" line at the top of the admin New order email',
		'Χωρίς παραλαβή'                                            => 'No pickup',
		'Χωρίς παραλαβή για προϊόντα που στέλνει συνεργάτης' => 'No pickup for products a partner ships',
		'Για προϊόντα που δεν περνούν από το κατάστημα, π.χ. όσα τυπώνονται κατά παραγγελία και τα στέλνει ο συνεργάτης παραγωγής. Ο κανόνας ισχύει ανά δέμα: σε μικτό καλάθι η παραλαβή μένει στο δέμα με τα δικά σου προϊόντα. Όταν κανένα δέμα δεν έχει παραλαβή, ο πελάτης βλέπει το μήνυμα «Χωρίς παραλαβή» (καρτέλα Κείμενα) στο καλάθι και στο ταμείο.' => 'For products that never pass through the store, e.g. print-on-demand items shipped by the production partner. The rule works per parcel: in a mixed cart, pickup stays in the parcel with your own products. When no parcel has pickup, the customer sees the "No pickup" message (Texts tab) in the cart and at checkout.',
		'Δέματα της Printful (το plugin της Printful χωρίζει τα προϊόντα της σε δικό τους δέμα)' => 'Printful parcels (the Printful plugin puts its products in a parcel of their own)',
		'Και δέματα με προϊόντα από αυτές τις κλάσεις αποστολής (οι παραλλαγές χωρίς δική τους κλάση ακολουθούν το γονικό προϊόν):' => 'Also parcels with products of these shipping classes (variations without a class of their own follow the parent product):',
		'Το κατάστημα δεν έχει ακόμα κλάσεις αποστολής. Φτιάξε μία (π.χ. «Printful») και βάλ\' τη στα προϊόντα.' => 'The store has no shipping classes yet. Create one (e.g. "Printful") and set it on the products.',
		'Η κλάση αποστολής «%s» δεν υπάρχει πια.'                   => 'The shipping class "%s" no longer exists.',
		'Κλάσεις αποστολής του WooCommerce'                         => 'WooCommerce shipping classes',
		'Χωρίς παραλαβή: μήνυμα στο καλάθι και στο ταμείο'          => 'No pickup: message in the cart and at checkout',
		'Αρχή της γραμμής στο email του διαχειριστή («… : κατάστημα»)' => 'Start of the line in the admin email ("…: store")',
		'Shortcode'                                                 => 'Shortcode',
		'τυπώνει τα ενεργά καταστήματα (όνομα, διεύθυνση, ώρες, χάρτης) σε μια σελίδα, π.χ. «Τρόποι παραλαβής» ή τις Συχνές ερωτήσεις. Με το ids δείχνει μόνο όσα θέλεις. Όσο η λειτουργία είναι ανενεργή δεν τυπώνει τίποτα, και στη λειτουργία δοκιμής φαίνεται μόνο στους διαχειριστές.' => 'prints the active stores (name, address, hours, map) on a page, e.g. "Pickup options" or the FAQ. With ids it shows only the ones you want. While the mode is off it prints nothing, and in test mode only shop managers see it.',
		'Κατάστημα'                                                 => 'Store',
		'ανενεργό'                                                  => 'inactive',
		'Φίλτρο: αν ένα πακέτο αποστολής μπορεί να πάρει την παραλαβή (false = κρύβεται η μέθοδος).' => 'Filter: whether a shipping package may get pickup (false = the method is hidden).',
		'Ο κανόνας «Χωρίς παραλαβή» είναι ενεργός χωρίς δέματα της Printful και χωρίς κλάση αποστολής, οπότε δεν κάνει τίποτα.' => 'The "No pickup" rule is on without Printful parcels and without a shipping class, so it does nothing.',
	);

	public static function init(): void {
		add_filter( 'gettext_store-pickup', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_store-pickup filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'store-pickup' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
