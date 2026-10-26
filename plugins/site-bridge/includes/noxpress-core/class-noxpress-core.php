<?php
/**
 * Noxpress_Core — shared menu, suite catalog and language (Bible §16).
 *
 * Loaded once per request by noxpress_core_boot() (loader.php), from the
 * newest copy among the active Noxpress plugins. Runs with or without
 * WooCommerce, so a site can always reach the hub and receive updates.
 *
 * Dispatch map:
 *  - Noxpress_Core     → catalog of suite plugins, top-level menu, language
 *  - Noxpress_Updater  → updates.json, update transient, plugin details,
 *                        package verification (sha256 + Ed25519)
 *  - Noxpress_Hub      → the "Noxpress" landing page, channel setting
 *
 * Language (Bible §9): Greek msgids in the 'noxpress' domain, English from
 * the dictionary below; rs_lang (user meta) is read only.
 */

defined( 'ABSPATH' ) || exit;

final class Noxpress_Core {

	/** Shared top-level menu slug (Bible §8). */
	const MENU = 'noxpress';

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Noxpress'                                              => 'Noxpress',
		'Επισκόπηση'                                            => 'Overview',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'       => 'You do not have access to this page.',

		// Catalog.
		'Καταμερισμός εσόδων ανά δικαιούχο, ΦΠΑ, ledger και portal δικαιούχων.' => 'Revenue split per beneficiary, VAT, ledger and beneficiary portal.',
		'Εικόνα του καταστήματος με μια ματιά: παραγγελίες, επιστροφές, στοκ και οφειλές.' => 'Your store at a glance: orders, refunds, stock and amounts owed.',
		'Μαζική μορφοποίηση κειμένων προϊόντων με preview, dry run και undo.' => 'Bulk formatting of product texts with preview, dry run and undo.',
		'Διορθώνει κλασικά θέματα WooCommerce χωρίς child theme και χωρίς αλλαγή στα αρχεία τους.' => 'Fixes classic WooCommerce themes without a child theme and without editing their files.',
		'Λιτά φίλτρα προϊόντων για κλασικά θέματα, με ομαδοποίηση τιμών χαρακτηριστικών.' => 'Lean product filters for classic themes, with value groups for attributes.',
		'Συνδέει τις μορφές ενός έργου (έντυπο, ebook, ηχητικό βιβλίο, ταινία) με το μπλοκ «Διαθέσιμες μορφές».' => 'Links the formats of a work (print, e-book, audiobook, film) with an "Available formats" block.',
		'Φόρμα υπαναχώρησης για πελάτες και επισκέπτες, με απόδειξη και λίστα αιτημάτων.' => 'Withdrawal form for customers and guests, with a receipt and a request list.',
		'Δείγμα βιβλίου στη σελίδα του προϊόντος: ξεχωριστό PDF μόνο με τις σελίδες που διαλέγεις.' => 'A book sample on the product page: a separate PDF with only the pages you choose.',
		'Δωρεάν αποστολή με μπάρα προόδου και χρόνος παράδοσης στη σελίδα προϊόντος.' => 'Free shipping progress bar and delivery time on the product page.',
		'Μία σελίδα για κάθε δημιουργό, με τα έργα του ανά ρόλο, συνδέσμους από τα προϊόντα και ευρετήριο.' => 'One page for every creator, with their works by role, links from the products and an index.',
		'Παραλαβή από το κατάστημα στο ταμείο, με επιλογή καταστήματος, ώρες και χωρίς μεταφορικά.' => 'Store pickup at checkout, with a choice of store, opening hours and no shipping cost.',
		'Απόκρυψη κατηγοριών και των προϊόντων τους από το shop, την αναζήτηση και τα blocks, χωρίς αλλαγή στα προϊόντα.' => 'Hide categories and their products from the shop, search and blocks, without changing the products.',
		'Swatches για τις παραλλαγές: χρώματα, εικόνες και κουμπιά, οδηγός μεγεθών και swatches στις κάρτες του καταστήματος.' => 'Swatches for variations: colors, images and buttons, a size guide and swatches on shop cards.',
		'Ελληνικά σε λατινικά στα slugs και στα ονόματα αρχείων, με μαζική μετατροπή των υπαρχόντων.' => 'Greek to Latin in slugs and file names, with bulk conversion of existing ones.',
		'Σύνδεση με το orOS σε modules: φόρμα εγγραφής και κουτάκι στο ταμείο για τη λίστα σου στο orOS Newsletter.' => 'Connects the site to orOS in modules: sign-up form and checkout checkbox for your list in orOS Newsletter.',
		'Πακέτα προϊόντων: κάθε προϊόν με το δικό του απόθεμα και ΦΠΑ, έκπτωση πακέτου και σωστές αναφορές πωλήσεων.' => 'Product bundles: every product with its own stock and VAT, a bundle discount and correct sales reports.',
		'Κοστολόγηση έκδοσης για υποψήφιους συγγραφείς: ποσό ανά υπηρεσία και σύνολο, με τους συντελεστές κρυφούς στον server και αιτήματα «Ενδιαφέρομαι».' => 'Publishing quotes for prospective authors: an amount per service and a total, with the rates hidden on the server and «I\'m interested» requests.',
		'Προπαραγγελίες με ημερομηνία κυκλοφορίας: σήμα και κουμπί στο κατάστημα, η ημερομηνία στο καλάθι και στην παραγγελία, αυτόματη κυκλοφορία και emails.' => 'Pre-orders with a release date: badge and button in the store, the date in the cart and on the order, automatic release and emails.',
		'Λίστα επιθυμιών: καρδιά στα προϊόντα, λίστα για επισκέπτες χωρίς cookies, κοινοποίηση και αναφορά με τα πιο επιθυμητά προϊόντα.' => 'Wishlist: a heart on products, guest lists without cookies, sharing and a report of the most wanted products.',
		'Δώρο με αφιέρωση στο ταμείο, μήνυμα και προϊόντα στη σελίδα ευχαριστίας, και κουπόνι ευχαριστίας για την επόμενη παραγγελία.' => 'A gift option with a message at checkout, a message and products on the thank-you page, and a thank-you coupon for the next order.',
		'Πλήρες αντίγραφο ασφαλείας και επαναφορά του site, εδώ ή σε άλλον server, με αλλαγή domain χωρίς χειροκίνητες διορθώσεις.' => 'Full backup and restore of the site, here or on another server, with a domain change and no manual fixes.',
		'Ενότητες προϊόντων με το shortcode [products]: marker classes που εξαιρούν προϊόντα, κατηγορίες ή προπαραγγελίες και φιλτράρουν με λέξη στον τίτλο.' => 'Product sections with the [products] shortcode: marker classes that leave out products, categories or pre-orders and filter by a word in the title.',
		'Τίτλοι άλλων εκδοτικών από τον κατάλογο Bookpoint (ΟΣΔΕΛ) ως προϊόντα: αναζήτηση και εισαγωγή, κανόνες ανά εκδότη και καθημερινός συγχρονισμός τιμής και διαθεσιμότητας.' => 'Titles of other publishers from the Bookpoint catalogue (OSDEL) as products: search and import, rules per publisher and a daily sync of price and availability.',
		'Το live chat του PurioChat στο κινητό: διακόπτης online / offline, ειδοποιήσεις για κάθε αίτημα και μήνυμα, και απαντήσεις από την εφαρμογή Android.' => "PurioChat's live chat on your phone: an online / offline switch, notifications for every request and message, and replies from the Android app.",
		'Λογαριασμοί χονδρικής για βιβλιοπωλεία: προκαθορισμένη έκπτωση στους τίτλους που ορίζεις, και API (Trade Feed) σε JSON ή XML για να περνούν τα βιβλία στα δικά τους e-shops.' => 'Trade accounts for bookstores: a preset discount on the titles you choose, and an API (Trade Feed) in JSON or XML to list the books in their own e-shops.',

		'Το Claude διαχειρίζεται τα sites σου μέσω MCP, από τους δικούς σου servers, με επίπεδο πρόσβασης ανά site και ιστορικό κάθε κλήσης.' => 'Claude manages your sites over MCP, from your own servers, with an access level per site and a log of every call.',

		// Hub.
		'Όλα τα plugins της σουίτας Noxpress: κατάσταση, εκδόσεις και ενημερώσεις.' => 'Every plugin of the Noxpress suite: status, versions and updates.',
		'Plugins'                                               => 'Plugins',
		'Plugin'                                                => 'Plugin',
		'Κατάσταση'                                             => 'Status',
		'Έκδοση'                                                => 'Version',
		'Ενέργειες'                                             => 'Actions',
		'Ενεργό'                                                => 'Active',
		'Ανενεργό'                                              => 'Inactive',
		'Δεν είναι εγκατεστημένο'                               => 'Not installed',
		'Ενημερωμένο'                                           => 'Up to date',
		'Διαθέσιμη: %s'                                         => 'Available: %s',
		'beta'                                                  => 'beta',
		'Άνοιγμα'                                               => 'Open',
		'Ενημέρωση'                                             => 'Update',
		'Εγκατάσταση'                                           => 'Install',
		'Ενεργοποίηση'                                          => 'Activate',
		'Αλλαγές'                                               => 'Changelog',
		'Αυτόματες ενημερώσεις: ναι'                            => 'Auto-updates: on',
		'Αυτόματες ενημερώσεις: όχι'                            => 'Auto-updates: off',
		'Απενεργοποίηση αυτόματων'                              => 'Disable auto-updates',
		'Ενεργοποίηση αυτόματων'                                => 'Enable auto-updates',
		'Ενημερώσεις'                                           => 'Updates',
		'Κανάλι ενημερώσεων'                                    => 'Update channel',
		'Stable: μόνο εκδόσεις που έχουν δοκιμαστεί.'           => 'Stable: tested releases only.',
		'Beta: και οι νέες εκδόσεις που δοκιμάζονται ακόμα.'    => 'Beta: also new releases still under testing.',
		'Αποθήκευση'                                            => 'Save',
		'Έλεγχος τώρα'                                          => 'Check now',
		'Τελευταίος έλεγχος: %s'                                => 'Last check: %s',
		'Τελευταίος έλεγχος: ποτέ'                              => 'Last check: never',
		'Η λίστα εκδόσεων δεν είναι διαθέσιμη αυτή τη στιγμή (noxpress.tech). Θα ξαναδοκιμάσει αυτόματα.' => 'The version list is not available right now (noxpress.tech). It will retry automatically.',
		'Οι αλλαγές αρχείων είναι απενεργοποιημένες σε αυτό το site (DISALLOW_FILE_MODS): οι ενημερώσεις και οι εγκαταστάσεις γίνονται εκτός WordPress.' => 'File changes are disabled on this site (DISALLOW_FILE_MODS): updates and installs happen outside WordPress.',
		'Οι εγκαταστάσεις και οι ενημερώσεις γίνονται από διαχειριστή του site.' => 'Installs and updates are done by a site administrator.',
		'Το κανάλι ενημερώσεων αποθηκεύτηκε.'                   => 'The update channel was saved.',
		'Ο έλεγχος για ενημερώσεις ολοκληρώθηκε.'               => 'The update check is complete.',
		'Κάθε πακέτο ελέγχεται με sha256 και ψηφιακή υπογραφή πριν από την εγκατάσταση.' => 'Every package is checked with sha256 and a digital signature before it is installed.',

		// Other channel (hub).
		'Υπάρχει νεότερη beta έκδοση για %d plugin.'            => 'There is a newer beta version for %d plugin.',
		'Υπάρχουν νεότερες beta εκδόσεις για %d plugins.'       => 'There are newer beta versions for %d plugins.',
		'Οι beta εκδόσεις δοκιμάζονται ακόμα. Σε live site, καλύτερα πρώτα σε δοκιμαστικό ή με πρόσφατο backup.' => 'Beta versions are still being tested. On a live site, try them on a test site first or have a recent backup.',
		'Μετάβαση στο Beta για όλα'                             => 'Switch everything to Beta',
		'ή «Εγκατάσταση beta» μόνο για όποιο plugin θέλεις, πιο κάτω.' => 'or "Install beta" only for the plugins you choose, below.',
		'Νεότερη beta: %s'                                      => 'Newer beta: %s',
		'Νεότερη stable: %s'                                    => 'Newer stable: %s',
		'Εγκατάσταση beta'                                      => 'Install beta',
		'Αλλαγές beta'                                          => 'Beta changelog',
		'Οι beta εκδόσεις δοκιμάζονται ακόμα. Να εγκατασταθεί η beta;' => 'Beta versions are still being tested. Install the beta?',
		'Απόκρυψη για αυτή την έκδοση'                          => 'Hide for this version',
		'Η ειδοποίηση για το %1$s %2$s κρύφτηκε. Θα ξαναεμφανιστεί με νεότερη έκδοση.' => 'The notice for %1$s %2$s is hidden. It comes back with a newer version.',

		// Bulk actions (hub).
		'Άγνωστο plugin της σουίτας.'                           => 'Unknown suite plugin.',
		'Η απενεργοποίηση δεν έγινε.'                           => 'The plugin could not be deactivated.',
		'Οι μαζικές ενέργειες γίνονται από το network admin.'   => 'Bulk actions are done from the network admin.',
		'Σβήνει κλειδιά και συζεύξεις: κόβεται η σύνδεση του Claude με τα sites.' => 'Deletes keys and pairings: Claude loses its connection to the sites.',
		'Χωρίς το τικ «Να μείνουν τα αντίγραφα» στις ρυθμίσεις του, σβήνει όλα τα backups.' => 'Without the "Keep backups" tick in its settings, it deletes every backup.',
		'Εγκατάσταση όλων (%d)'                                 => 'Install all (%d)',
		'Ενημέρωση όλων (%d)'                                   => 'Update all (%d)',
		'Απεγκατάσταση όλων (%d)'                               => 'Uninstall all (%d)',
		'ΔΙΑΓΡΑΦΗ'                                              => 'DELETE',
		'Εγκατάσταση όλων'                                      => 'Install all',
		'Ενημέρωση όλων'                                        => 'Update all',
		'Απεγκατάσταση όλων'                                    => 'Uninstall all',
		'Τα plugins εγκαθίστανται ένα-ένα, από το κανάλι ενημερώσεων του site. Δεν ενεργοποιούνται, εκτός αν το τσεκάρεις.' => 'The plugins are installed one by one, from the site\'s update channel. They are not activated unless you tick it.',
		'Τα plugins ενημερώνονται ένα-ένα. Όσα είναι ενεργά μένουν ενεργά.' => 'The plugins are updated one by one. Active ones stay active.',
		'Τα plugins απενεργοποιούνται και διαγράφονται. Η διαγραφή τρέχει το uninstall κάθε plugin: σβήνει ρυθμίσεις και δεδομένα του, εκτός από όσα κρατά το ίδιο το plugin (π.χ. παραγγελίες ή ό,τι έχεις τσεκάρει να μείνει στα Εργαλεία του). Δεν αναιρείται.' => 'The plugins are deactivated and deleted. Deleting runs each plugin\'s uninstall: it removes its settings and data, except what the plugin itself keeps (e.g. orders, or whatever you ticked to keep in its Tools). This cannot be undone.',
		'Σε live site τα χαρακτηριστικά τους φεύγουν αμέσως από το κατάστημα. Καλύτερα με πρόσφατο backup.' => 'On a live site their features leave the store at once. Best with a recent backup.',
		'Plugins μόνο με beta έκδοση, που δεν μπαίνουν στο κανάλι Stable: %d. Για αυτά, «Μετάβαση στο Beta για όλα» ή «Εγκατάσταση beta» στη γραμμή τους.' => 'Plugins with only a beta version, left out on the Stable channel: %d. For those, use "Switch everything to Beta" or "Install beta" on their row.',
		'Ενεργοποίηση μετά την εγκατάσταση'                     => 'Activate after installing',
		'Γράψε %s για επιβεβαίωση:'                             => 'Type %s to confirm:',
		'Έναρξη'                                                => 'Start',
		'Άκυρο'                                                 => 'Cancel',
		'Κλείσιμο'                                              => 'Close',
		'Σε αναμονή'                                            => 'Waiting',
		'Γίνεται…'                                              => 'Working…',
		'Έγινε'                                                 => 'Done',
		'Σφάλμα'                                                => 'Error',
		'Ολοκληρώθηκε. Έγιναν: %1$d, σφάλματα: %2$d.'          => 'Finished. Done: %1$d, failed: %2$d.',
		'Δεν έμεινε κανένα plugin της σουίτας, άρα ούτε αυτή η σελίδα. Συνέχεια στη σελίδα Plugins.' => 'No suite plugin is left, so neither is this page. Continue on the Plugins page.',
		'Η εργασία δεν τελείωσε. Να φύγεις από τη σελίδα;'       => 'The task is not finished. Leave the page?',
		'Σφάλμα δικτύου.'                                       => 'Network error.',

		// Updater errors.
		'Το πακέτο δεν υπάρχει στη λίστα εκδόσεων του Noxpress.' => 'The package is not in the Noxpress version list.',
		'Το πακέτο δεν ταιριάζει με το sha256 της λίστας εκδόσεων. Η εγκατάσταση ακυρώθηκε.' => 'The package does not match the sha256 of the version list. The install was cancelled.',
		'Η ψηφιακή υπογραφή του πακέτου δεν είναι έγκυρη. Η εγκατάσταση ακυρώθηκε.' => 'The digital signature of the package is not valid. The install was cancelled.',

		// Footer (Bible §7).
		'Made with ❤ by %s'                                     => 'Made with ❤ by %s',
		'More plugins at'                                       => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                       => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                    => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_noxpress', array( __CLASS__, 'translate' ), 10, 3 );
		add_filter( 'ngettext_noxpress', array( __CLASS__, 'translate_plural' ), 10, 5 );

		require_once NOXPRESS_CORE_PATH . '/class-noxpress-updater.php';
		Noxpress_Updater::init();

		if ( is_admin() ) {
			require_once NOXPRESS_CORE_PATH . '/class-noxpress-hub.php';
			Noxpress_Hub::init();
			// Before every suite plugin (RS 9, SP 20, SF 30, TP 40, SHF 50, PFM 60, EWD 70, BPV 80, SHN 90, CRT 100, PKP 110, HIC 120, VPK 130, GLP 140, ORC 150, PBN 160, SBR 170, PBQ 180, POR 190, WSH 200, CKE 210, NM 220).
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 5 );
			add_action( 'network_admin_menu', array( __CLASS__, 'admin_menu' ), 5 );
		}
	}

	/**
	 * Suite plugins the hub and the updater handle, keyed by slug
	 * (folder and main file name). "unlisted" plugins get updates but
	 * show in the hub only where they are installed.
	 */
	public static function catalog(): array {
		return array(
			'revenue-splitter' => array(
				'name' => 'Revenue Splitter',
				'desc' => __( 'Καταμερισμός εσόδων ανά δικαιούχο, ΦΠΑ, ledger και portal δικαιούχων.', 'noxpress' ),
				'page' => 'revenue-splitter-dashboard',
			),
			'store-pulse'      => array(
				'name' => 'Store Pulse',
				'desc' => __( 'Εικόνα του καταστήματος με μια ματιά: παραγγελίες, επιστροφές, στοκ και οφειλές.', 'noxpress' ),
				'page' => 'sp-dashboard',
			),
			'smart-formatter'  => array(
				'name' => 'Smart Formatter',
				'desc' => __( 'Μαζική μορφοποίηση κειμένων προϊόντων με preview, dry run και undo.', 'noxpress' ),
				'page' => 'sf-formatter',
			),
			'theme-patcher'    => array(
				'name' => 'Theme Patcher',
				'desc' => __( 'Διορθώνει κλασικά θέματα WooCommerce χωρίς child theme και χωρίς αλλαγή στα αρχεία τους.', 'noxpress' ),
				'page' => 'tp-theme-patcher',
			),
			'shop-filters'     => array(
				'name' => 'Shop Filters',
				'desc' => __( 'Λιτά φίλτρα προϊόντων για κλασικά θέματα, με ομαδοποίηση τιμών χαρακτηριστικών.', 'noxpress' ),
				'page' => 'shf-filters',
			),
			'product-formats'  => array(
				'name' => 'Product Formats',
				'desc' => __( 'Συνδέει τις μορφές ενός έργου (έντυπο, ebook, ηχητικό βιβλίο, ταινία) με το μπλοκ «Διαθέσιμες μορφές».', 'noxpress' ),
				'page' => 'pfm-formats',
			),
			'easy-withdrawal'  => array(
				'name' => 'Easy Withdrawal',
				'desc' => __( 'Φόρμα υπαναχώρησης για πελάτες και επισκέπτες, με απόδειξη και λίστα αιτημάτων.', 'noxpress' ),
				'page' => 'ewd-requests',
			),
			'book-preview'     => array(
				'name' => 'Book Preview',
				'desc' => __( 'Δείγμα βιβλίου στη σελίδα του προϊόντος: ξεχωριστό PDF μόνο με τις σελίδες που διαλέγεις.', 'noxpress' ),
				'page' => 'bpv-samples',
			),
			'shipping-notes'   => array(
				'name' => 'Shipping Notes',
				'desc' => __( 'Δωρεάν αποστολή με μπάρα προόδου και χρόνος παράδοσης στη σελίδα προϊόντος.', 'noxpress' ),
				'page' => 'shn-settings',
			),
			'featured-creators' => array(
				'name' => 'Featured Creators',
				'desc' => __( 'Μία σελίδα για κάθε δημιουργό, με τα έργα του ανά ρόλο, συνδέσμους από τα προϊόντα και ευρετήριο.', 'noxpress' ),
				'page' => 'crt-settings',
			),
			'store-pickup'      => array(
				'name' => 'Store Pickup',
				'desc' => __( 'Παραλαβή από το κατάστημα στο ταμείο, με επιλογή καταστήματος, ώρες και χωρίς μεταφορικά.', 'noxpress' ),
				'page' => 'pkp-settings',
			),
			'hide-catalog'      => array(
				'name' => 'Hide Catalog',
				'desc' => __( 'Απόκρυψη κατηγοριών και των προϊόντων τους από το shop, την αναζήτηση και τα blocks, χωρίς αλλαγή στα προϊόντα.', 'noxpress' ),
				'page' => 'hic-settings',
			),
			'variation-picker'  => array(
				'name' => 'Variation Picker',
				'desc' => __( 'Swatches για τις παραλλαγές: χρώματα, εικόνες και κουμπιά, οδηγός μεγεθών και swatches στις κάρτες του καταστήματος.', 'noxpress' ),
				'page' => 'vpk-settings',
			),
			'greeklish-permalinks' => array(
				'name' => 'Greeklish Permalinks',
				'desc' => __( 'Ελληνικά σε λατινικά στα slugs και στα ονόματα αρχείων, με μαζική μετατροπή των υπαρχόντων.', 'noxpress' ),
				'page' => 'glp-settings',
			),
			'oros-connect'      => array(
				'name' => 'orOS Connect',
				'desc' => __( 'Σύνδεση με το orOS σε modules: φόρμα εγγραφής και κουτάκι στο ταμείο για τη λίστα σου στο orOS Newsletter.', 'noxpress' ),
				'page' => 'orc-settings',
			),
			'product-bundles'   => array(
				'name' => 'Product Bundles',
				'desc' => __( 'Πακέτα προϊόντων: κάθε προϊόν με το δικό του απόθεμα και ΦΠΑ, έκπτωση πακέτου και σωστές αναφορές πωλήσεων.', 'noxpress' ),
				'page' => 'pbn-bundles',
			),
			'publishing-quote'  => array(
				'name' => 'Publishing Quote',
				'desc' => __( 'Κοστολόγηση έκδοσης για υποψήφιους συγγραφείς: ποσό ανά υπηρεσία και σύνολο, με τους συντελεστές κρυφούς στον server και αιτήματα «Ενδιαφέρομαι».', 'noxpress' ),
				'page' => 'pbq-settings',
			),
			'pre-orders'        => array(
				'name' => 'Pre-Orders',
				'desc' => __( 'Προπαραγγελίες με ημερομηνία κυκλοφορίας: σήμα και κουμπί στο κατάστημα, η ημερομηνία στο καλάθι και στην παραγγελία, αυτόματη κυκλοφορία και emails.', 'noxpress' ),
				'page' => 'por-settings',
			),
			'wish-shelf'        => array(
				'name' => 'Wish Shelf',
				'desc' => __( 'Λίστα επιθυμιών: καρδιά στα προϊόντα, λίστα για επισκέπτες χωρίς cookies, κοινοποίηση και αναφορά με τα πιο επιθυμητά προϊόντα.', 'noxpress' ),
				'page' => 'wsh-settings',
			),
			'checkout-extras'   => array(
				'name' => 'Checkout Extras',
				'desc' => __( 'Δώρο με αφιέρωση στο ταμείο, μήνυμα και προϊόντα στη σελίδα ευχαριστίας, και κουπόνι ευχαριστίας για την επόμενη παραγγελία.', 'noxpress' ),
				'page' => 'cke-settings',
			),
			'data-migrator'     => array(
				'name' => 'Data Migrator',
				'desc' => __( 'Πλήρες αντίγραφο ασφαλείας και επαναφορά του site, εδώ ή σε άλλον server, με αλλαγή domain χωρίς χειροκίνητες διορθώσεις.', 'noxpress' ),
				'page' => 'nm-backups',
			),
			'home-sections'     => array(
				'name' => 'Home Sections',
				'desc' => __( 'Ενότητες προϊόντων με το shortcode [products]: marker classes που εξαιρούν προϊόντα, κατηγορίες ή προπαραγγελίες και φιλτράρουν με λέξη στον τίτλο.', 'noxpress' ),
				'page' => 'hms-settings',
			),
			'book-feed'         => array(
				'name' => 'Book Feed',
				'desc' => __( 'Τίτλοι άλλων εκδοτικών από τον κατάλογο Bookpoint (ΟΣΔΕΛ) ως προϊόντα: αναζήτηση και εισαγωγή, κανόνες ανά εκδότη και καθημερινός συγχρονισμός τιμής και διαθεσιμότητας.', 'noxpress' ),
				'page' => 'bkf-feed',
			),
			'live-operator'     => array(
				'name' => 'Live Operator',
				'desc' => __( 'Το live chat του PurioChat στο κινητό: διακόπτης online / offline, ειδοποιήσεις για κάθε αίτημα και μήνυμα, και απαντήσεις από την εφαρμογή Android.', 'noxpress' ),
				'page' => 'lop-settings',
			),
			'trade-accounts'    => array(
				'name' => 'Trade Accounts',
				'desc' => __( 'Λογαριασμοί χονδρικής για βιβλιοπωλεία: προκαθορισμένη έκπτωση στους τίτλους που ορίζεις, και API (Trade Feed) σε JSON ή XML για να περνούν τα βιβλία στα δικά τους e-shops.', 'noxpress' ),
				'page' => 'tra-settings',
			),
			'site-bridge'       => array(
				'name' => 'Site Bridge',
				'desc' => __( 'Το Claude διαχειρίζεται τα sites σου μέσω MCP, από τους δικούς σου servers, με επίπεδο πρόσβασης ανά site και ιστορικό κάθε κλήσης.', 'noxpress' ),
				'page' => 'sbr-settings',
			),
		);
	}

	/** Main plugin file of a suite slug, relative to the plugins folder. */
	public static function plugin_file( string $slug ): string {
		return $slug . '/' . $slug . '.php';
	}

	/**
	 * Installed suite plugins: slug => header data (get_plugins() format).
	 * Only the canonical folder/file name counts.
	 */
	public static function installed(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		$out = array();
		foreach ( array_keys( self::catalog() ) as $slug ) {
			$file = self::plugin_file( $slug );
			if ( isset( $all[ $file ] ) ) {
				$out[ $slug ] = $all[ $file ];
			}
		}
		return $out;
	}

	/** Capability for viewing the hub: shop managers with WooCommerce, admins without it. */
	public static function view_cap(): string {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'activate_plugins';
	}

	public static function admin_menu(): void {
		$cap   = self::view_cap();
		$title = __( 'Noxpress', 'noxpress' );

		// Bubble: newer releases in the other channel (hub, Bible §16).
		$count = current_user_can( 'update_plugins' ) ? Noxpress_Updater::other_count() : 0;
		if ( $count ) {
			$title .= ' <span class="update-plugins count-' . (int) $count . '"><span class="plugin-count">' . (int) $count . '</span></span>';
		}

		add_menu_page(
			__( 'Noxpress', 'noxpress' ),
			$title,
			$cap,
			self::MENU,
			array( 'Noxpress_Hub', 'render' ),
			'dashicons-chart-pie',
			57
		);

		// WordPress repeats the top-level as first submenu: give it a label.
		add_submenu_page(
			self::MENU,
			__( 'Noxpress', 'noxpress' ),
			__( 'Επισκόπηση', 'noxpress' ),
			$cap,
			self::MENU,
			array( 'Noxpress_Hub', 'render' )
		);
	}

	/** URL of a file inside the loaded core copy. */
	public static function url( string $file ): string {
		return plugins_url( $file, NOXPRESS_CORE_PATH . '/class-noxpress-core.php' );
	}

	/** 'el' | 'en' for the current user (rs_lang → locale fallback). */
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
			$lang   = ( 0 === strpos( strtolower( (string) $locale ), 'el' ) ) ? 'el' : 'en';
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** ngettext_noxpress filter: both Greek forms are dictionary keys. */
	public static function translate_plural( $translation, $single, $plural, $number, $domain ) {
		$text = 1 === (int) $number ? $single : $plural;
		if ( 'noxpress' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** gettext_noxpress filter. */
	public static function translate( $translation, $text, $domain ) {
		if ( 'noxpress' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
