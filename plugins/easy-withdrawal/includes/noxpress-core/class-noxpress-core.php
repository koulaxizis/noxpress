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

		require_once NOXPRESS_CORE_PATH . '/class-noxpress-updater.php';
		Noxpress_Updater::init();

		if ( is_admin() ) {
			require_once NOXPRESS_CORE_PATH . '/class-noxpress-hub.php';
			Noxpress_Hub::init();
			// Before every suite plugin (RS 9, SP 20, SF 30, TP 40, SHF 50, PFM 60, EWD 70).
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 5 );
			add_action( 'network_admin_menu', array( __CLASS__, 'admin_menu' ), 5 );
		}
	}

	/**
	 * Suite plugins the hub and the updater handle, keyed by slug
	 * (folder and main file name). Data Migrator joins once it is fixed.
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
		$cap = self::view_cap();

		add_menu_page(
			__( 'Noxpress', 'noxpress' ),
			__( 'Noxpress', 'noxpress' ),
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

	/** gettext_noxpress filter. */
	public static function translate( $translation, $text, $domain ) {
		if ( 'noxpress' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
