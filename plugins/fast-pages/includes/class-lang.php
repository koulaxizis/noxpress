<?php
/**
 * FPG_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ here (Bible §9): 'el' | 'en' | ''/'auto'.
 * Revenue Splitter writes it; without RS the Tools tab offers the same
 * choice. Without an explicit choice the user's locale decides: el* →
 * Greek (msgids pass through as they are), anything else → English.
 * Visitor texts (the YouTube button label) follow the site locale in
 * FPG_Optimize::text(), not this dictionary.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Επισκόπηση'                                       => 'Overview',
		'Cache'                                            => 'Cache',
		'Βελτιστοποίηση'                                   => 'Optimization',
		'Εργαλεία'                                         => 'Tools',
		'Fast Pages'                                       => 'Fast Pages',
		'Ρυθμίσεις'                                        => 'Settings',
		'Fast Pages: είναι ενεργό το %s. Δύο page caches μαζί δεν δουλεύουν, οπότε η cache του Fast Pages περιμένει. Πέρασε πρώτα τις ρυθμίσεις (Εργαλεία) και μετά απενεργοποίησέ το.' => 'Fast Pages: %s is active. Two page caches together do not work, so the Fast Pages cache waits. Copy its settings first (Tools), then deactivate it.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'  => 'You do not have access to this page.',
		'Page cache για επισκέπτες, preload, minify, lazy load και YouTube με κλικ. Οι συνδεδεμένοι χρήστες, το καλάθι, το ταμείο και ο λογαριασμός δεν μπαίνουν ποτέ στην cache.' => 'Page cache for visitors, preload, minify, lazy load and click-to-play YouTube. Logged-in users, the cart, the checkout and the account never go into the cache.',
		'Κατάσταση'                                        => 'Status',
		'Page cache'                                       => 'Page cache',
		'κλειστή'                                          => 'off',
		'Τίποτα δεν αλλάζει στο site μέχρι να την ενεργοποιήσεις.' => 'Nothing changes on the site until you turn it on.',
		'περιμένει'                                        => 'waiting',
		'Δες τους ελέγχους από κάτω.'                      => 'See the checks below.',
		'λειτουργία δοκιμής'                               => 'test mode',
		'Οι σελίδες γράφονται στην cache, αλλά οι επισκέπτες τις παίρνουν ακόμα από το WordPress. Δες τες με ?fpg-test=1.' => 'Pages are written to the cache, but visitors still get them from WordPress. See them with ?fpg-test=1.',
		'ενεργή για τους επισκέπτες'                       => 'on for visitors',
		'Σταθερά FPG_DISABLE'                              => 'FPG_DISABLE constant',
		'Άλλη page cache'                                  => 'Other page cache',
		'Drop-in advanced-cache.php'                       => 'advanced-cache.php drop-in',
		'WP_CACHE στο wp-config.php'                       => 'WP_CACHE in wp-config.php',
		'Φάκελος wp-content/cache/fast-pages'              => 'wp-content/cache/fast-pages folder',
		'εντάξει'                                          => 'ok',
		'Η cache και οι βελτιστοποιήσεις είναι σταματημένες από το wp-config.php.' => 'The cache and the optimizations are stopped from wp-config.php.',
		'Ενεργό: %s. Απενεργοποίησέ το για να ξεκινήσει η cache του Fast Pages.' => 'Active: %s. Deactivate it so the Fast Pages cache can start.',
		'Το wp-content/advanced-cache.php είναι άλλου plugin. Απενεργοποίησέ το plugin αυτό, ή σβήσε το αρχείο αν δεν χρησιμοποιείται πια.' => 'wp-content/advanced-cache.php belongs to another plugin. Deactivate that plugin, or delete the file if it is no longer used.',
		'Λείπει. Πάτα «Ενεργοποίηση» ξανά για να γραφτεί.' => 'Missing. Press «Turn on» again to write it.',
		'Πρόσθεσε στο wp-config.php, κάτω από το <?php:'   => 'Add to wp-config.php, below <?php:',
		'Ο server δεν επιτρέπει εγγραφή εκεί. Δώσε δικαίωμα εγγραφής στον φάκελο wp-content/cache.' => 'The server does not allow writing there. Give write permission to the wp-content/cache folder.',
		'πρόβλημα'                                         => 'problem',
		'Άλλο minify'                                      => 'Other minify',
		'Κράτα το minify σε ένα μόνο plugin.'              => 'Keep minify in one plugin only.',
		'Να ενεργοποιηθεί η page cache; Θα γραφτούν το wp-content/advanced-cache.php και η γραμμή WP_CACHE στο wp-config.php.' => 'Turn on the page cache? wp-content/advanced-cache.php and the WP_CACHE line in wp-config.php will be written.',
		'Συμφωνώ να γραφτεί το wp-content/advanced-cache.php και, αν λείπει, η γραμμή define( \'WP_CACHE\', true ); στο wp-config.php. Και τα δύο φεύγουν όταν απενεργοποιηθεί το plugin.' => 'I agree that wp-content/advanced-cache.php and, if missing, the line define( \'WP_CACHE\', true ); in wp-config.php are written. Both are removed when the plugin is deactivated.',
		'Ενεργοποίηση page cache'                          => 'Turn on page cache',
		'Έξοδος από τη λειτουργία δοκιμής'                 => 'Leave test mode',
		'Από τώρα όλοι οι επισκέπτες θα παίρνουν τις σελίδες από την cache. Συνέχεια;' => 'From now on every visitor gets the pages from the cache. Continue?',
		'Η αρχική όπως στην cache'                         => 'The home page as cached',
		'Λειτουργία δοκιμής'                               => 'Test mode',
		'Καθάρισμα όλης της cache'                         => 'Purge all cache',
		'Διακοπή preload'                                  => 'Stop preload',
		'Preload τώρα'                                     => 'Preload now',
		'Απενεργοποίηση page cache'                        => 'Turn off page cache',
		'Να σταματήσει η page cache; Η cache σβήνεται και φεύγουν το advanced-cache.php και η γραμμή WP_CACHE που έβαλε το plugin.' => 'Stop the page cache? The cache is deleted, and the advanced-cache.php file and the WP_CACHE line that the plugin added are removed.',
		'Αριθμοί'                                          => 'Numbers',
		'Σελίδες στην cache'                               => 'Pages in cache',
		'%1$d (%2$s)'                                      => '%1$d (%2$s)',
		'Αρχεία minify'                                    => 'Minified files',
		'Διάρκεια'                                         => 'Lifespan',
		'μέχρι το επόμενο καθάρισμα'                       => 'until the next purge',
		'Τελευταίο πλήρες καθάρισμα'                       => 'Last full purge',
		'δεν έτρεξε ακόμα'                                 => 'not run yet',
		'τρέχει'                                           => 'running',
		'ολοκληρώθηκε'                                     => 'done',
		'σταμάτησε'                                        => 'stopped',
		'%1$s: %2$d / %3$d (νέες %4$d, ήδη στην cache %5$d, σφάλματα %6$d)' => '%1$s: %2$d / %3$d (new %4$d, already cached %5$d, errors %6$d)',
		'Το αυτόματο preload είναι κλειστό (καρτέλα Cache).' => 'Automatic preload is off (Cache tab).',
		'Λίστα από το sitemap.'                            => 'List from the sitemap.',
		'Χωρίς sitemap: λίστα από τα δημοσιευμένα άρθρα, σελίδες και προϊόντα.' => 'No sitemap: list from the published posts, pages and products.',
		'Preload'                                          => 'Preload',
		'Αποθήκευση'                                       => 'Save',
		'Μέχρι το επόμενο καθάρισμα'                       => 'Until the next purge',
		'%d σελίδες το λεπτό'                              => '%d pages per minute',
		'Διάρκεια μιας σελίδας στην cache'                 => 'How long a page stays in the cache',
		'Μετά ξαναφτιάχνεται. Έως 12 ώρες, ώστε οι φόρμες και τα κουμπιά (nonces) να μη μένουν παλιά.' => 'Then it is built again. At most 12 hours, so forms and buttons (nonces) do not go stale.',
		'Ξεχωριστή cache για κινητά'                       => 'Separate cache for mobile',
		'Μόνο αν το θέμα ή κάποιο plugin δείχνει άλλο HTML στα κινητά (wp_is_mobile). Τα responsive θέματα δεν το χρειάζονται.' => 'Only if the theme or a plugin shows different HTML on mobile (wp_is_mobile). Responsive themes do not need it.',
		'Σελίδες που δεν μπαίνουν ποτέ στην cache'         => 'Pages that never go into the cache',
		'Μία ανά γραμμή, * = οτιδήποτε. Το καλάθι, το ταμείο, ο λογαριασμός, το admin, τα feeds, οι αναζητήσεις και οι διευθύνσεις με παραμέτρους μένουν πάντα έξω.' => 'One per line, * = anything. The cart, the checkout, the account, the admin, feeds, searches and addresses with parameters always stay out.',
		'Cookies που σημαίνουν προσωπική σελίδα'           => 'Cookies that mean a personal page',
		'Ένα όνομα (ή αρχή ονόματος) ανά γραμμή. Οι συνδεδεμένοι χρήστες και όσοι έχουν καλάθι ή σχόλιο σε αναμονή μένουν πάντα έξω.' => 'One name (or start of a name) per line. Logged-in users and visitors with a cart or a pending comment always stay out.',
		'Γέμισμα της cache στο παρασκήνιο'                 => 'Fill the cache in the background',
		'Από το sitemap (Rank Math, Yoast ή του WordPress), μετά από κάθε πλήρες καθάρισμα και μία φορά ανά διάρκεια. Οι σελίδες που καθαρίστηκαν μπαίνουν πρώτες.' => 'From the sitemap (Rank Math, Yoast or WordPress), after every full purge and once per lifespan. Purged pages go first.',
		'Ρυθμός'                                           => 'Rate',
		'Χαμηλά για μικρούς servers.'                      => 'Low for small servers.',
		'Το πολύ σελίδες ανά preload'                      => 'Maximum pages per preload',
		'Σελίδες χωρίς preload'                            => 'Pages without preload',
		'Μία ανά γραμμή, * = οτιδήποτε. Μπαίνουν στην cache μόνο όταν τις ανοίξει επισκέπτης.' => 'One per line, * = anything. They go into the cache only when a visitor opens them.',
		'Ισχύουν μόνο στις σελίδες που γράφονται στην cache, μία φορά, όταν γράφονται. Μετά από αλλαγή η cache καθαρίζεται.' => 'These apply only to pages written to the cache, once, when they are written. After a change the cache is purged.',
		'Minify'                                           => 'Minify',
		'Minify των αρχείων CSS του site'                  => 'Minify the site\'s CSS files',
		'Ένα μικρότερο αντίγραφο ανά αρχείο, χωρίς ένωση αρχείων. Τα .min.css μένουν ως έχουν.' => 'One smaller copy per file, no combining. .min.css files stay as they are.',
		'Minify των αρχείων JavaScript του site'           => 'Minify the site\'s JavaScript files',
		'Όπως το CSS. Αρχεία που φορτώνουν άλλα αρχεία από τον φάκελό τους (webpack, modules) μένουν ως έχουν.' => 'Like CSS. Files that load other files from their folder (webpack, modules) stay as they are.',
		'Αρχεία χωρίς minify'                              => 'Files without minify',
		'Ένα κομμάτι της διεύθυνσης ανά γραμμή, π.χ. όνομα αρχείου ή φακέλου plugin.' => 'One part of the address per line, e.g. a file name or a plugin folder.',
		'Lazy load'                                        => 'Lazy load',
		'Εικόνες: loading="lazy" όπου λείπει'              => 'Images: loading="lazy" where missing',
		'Ο browser φορτώνει τις εικόνες όταν πλησιάζουν στην οθόνη. Το WordPress το κάνει ήδη στο περιεχόμενο· αυτό καλύπτει θέμα και plugins.' => 'The browser loads images when they come near the screen. WordPress already does this in the content; this covers the theme and plugins.',
		'Οι πρώτες εικόνες της σελίδας φορτώνουν αμέσως'   => 'The first images of the page load right away',
		'Λογότυπο και πρώτη εικόνα (LCP) δεν πρέπει να περιμένουν.' => 'The logo and the first image (LCP) should not wait.',
		'Iframes: loading="lazy" όπου λείπει'              => 'Iframes: loading="lazy" where missing',
		'Εικόνες φόντου (inline style) όταν πλησιάζουν στην οθόνη' => 'Background images (inline style) when they come near the screen',
		'Μικρό script μόνο στις σελίδες που έχουν τέτοιο φόντο. Τα φόντα από αρχεία CSS δεν αλλάζουν.' => 'A small script only on pages that have such a background. Backgrounds from CSS files do not change.',
		'Χωρίς lazy load'                                  => 'No lazy load',
		'Ένα κομμάτι του src ή του class ανά γραμμή. Το class skip-lazy ή no-lazy κάνει το ίδιο.' => 'One part of the src or class per line. The class skip-lazy or no-lazy does the same.',
		'Βίντεο, διαστάσεις, hover'                        => 'Video, dimensions, hover',
		'YouTube ως εικόνα με κουμπί'                      => 'YouTube as an image with a play button',
		'Ο player (και τα cookies του YouTube) φορτώνει μόνο με το κλικ, από το youtube-nocookie.com.' => 'The player (and YouTube\'s cookies) loads only on click, from youtube-nocookie.com.',
		'Διαστάσεις (width / height) στις εικόνες του site που δεν τις έχουν' => 'Dimensions (width / height) on site images that lack them',
		'Η σελίδα δεν «πηδάει» όσο φορτώνουν (CLS).'       => 'The page does not «jump» while loading (CLS).',
		'Preload στο hover'                                => 'Preload on hover',
		'Όπως το WordPress (προεπιλογή)'                   => 'Like WordPress (default)',
		'Prefetch στο hover'                               => 'Prefetch on hover',
		'Prerender στο hover'                              => 'Prerender on hover',
		'Κλειστό'                                          => 'Off',
		'Με το speculative loading του WordPress 6.8+. Το καλάθι, το ταμείο και ο λογαριασμός μένουν πάντα έξω.' => 'With WordPress 6.8+ speculative loading. The cart, the checkout and the account always stay out.',
		'Ρυθμίσεις από το WP Rocket'                       => 'Settings from WP Rocket',
		'Δεν βρέθηκαν ρυθμίσεις του WP Rocket σε αυτό το site.' => 'No WP Rocket settings were found on this site.',
		'Διαβάζει μόνο τις ρυθμίσεις του WP Rocket (δεν τις αλλάζει) και αντιγράφει minify, lazy load, YouTube, διαστάσεις, preload και εξαιρέσεις.' => 'Only reads the WP Rocket settings (does not change them) and copies minify, lazy load, YouTube, dimensions, preload and exclusions.',
		'Τελευταία εισαγωγή: %s'                           => 'Last import: %s',
		'Εισαγωγή από το WP Rocket'                        => 'Import from WP Rocket',
		'Καθαρισμός βάσης'                                 => 'Database cleanup',
		'Αναθεωρήσεις άρθρων: %1$d · Ληγμένα transients: %2$d' => 'Post revisions: %1$d · Expired transients: %2$d',
		'Σβήνει τις αναθεωρήσεις (έως 500 κάθε φορά) και τα ληγμένα transients. Δεν αναιρείται: πάρε πρώτα backup.' => 'Deletes the revisions (up to 500 each time) and the expired transients. Cannot be undone: take a backup first.',
		'Καθαρισμός τώρα'                                  => 'Clean up now',
		'Να σβηστούν οι αναθεωρήσεις και τα ληγμένα transients; Δεν αναιρείται.' => 'Delete the revisions and the expired transients? This cannot be undone.',
		'Γλώσσα'                                           => 'Language',
		'Όπως η γλώσσα του λογαριασμού'                    => 'Same as the account language',
		'Ισχύει για όλα τα plugins της σουίτας Noxpress.'  => 'Applies to all plugins of the Noxpress suite.',
		'Τσέκαρε πρώτα ότι συμφωνείς.'                     => 'Tick the agreement first.',
		'Η page cache ενεργοποιήθηκε σε λειτουργία δοκιμής. Δες τις σελίδες με ?fpg-test=1 και μετά βγες από τη λειτουργία δοκιμής.' => 'The page cache is on in test mode. Check the pages with ?fpg-test=1, then leave test mode.',
		'Η page cache σταμάτησε και η cache σβήστηκε.'     => 'The page cache stopped and the cache was deleted.',
		'Σβήσε με το χέρι τη γραμμή:'                      => 'Delete this line by hand:',
		'Η cache απαντά τώρα σε όλους τους επισκέπτες.'    => 'The cache now answers every visitor.',
		'Λειτουργία δοκιμής: οι επισκέπτες παίρνουν τις σελίδες από το WordPress.' => 'Test mode: visitors get the pages from WordPress.',
		'Η cache καθαρίστηκε (%d σελίδες).'                => 'The cache was purged (%d pages).',
		'Το preload ξεκίνησε στο παρασκήνιο.'              => 'The preload started in the background.',
		'Η page cache δεν είναι έτοιμη.'                   => 'The page cache is not ready.',
		'Το preload σταμάτησε.'                            => 'The preload stopped.',
		'Οι ρυθμίσεις αποθηκεύτηκαν και η cache καθαρίστηκε.' => 'Settings saved and the cache purged.',
		'Αναθεωρήσεις: %1$d → %2$d · Ληγμένα transients: %3$d → %4$d' => 'Revisions: %1$d → %2$d · Expired transients: %3$d → %4$d',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                      => 'Settings saved.',
		'Άγνωστη ενέργεια.'                                => 'Unknown action.',
		'Η σταθερά FPG_DISABLE είναι ενεργή στο wp-config.php.' => 'The FPG_DISABLE constant is on in wp-config.php.',
		'Είναι ενεργή άλλη page cache: απενεργοποίησέ την πρώτα.' => 'Another page cache is active: deactivate it first.',
		'Το wp-content/advanced-cache.php είναι άλλου plugin και δεν το αγγίζουμε.' => 'wp-content/advanced-cache.php belongs to another plugin and is left alone.',
		'Δεν γράφεται ο φάκελος wp-content/cache/fast-pages.' => 'The wp-content/cache/fast-pages folder cannot be written.',
		'Δεν γράφτηκε το wp-content/advanced-cache.php (δικαιώματα;).' => 'wp-content/advanced-cache.php was not written (permissions?).',
		'Δεν βρέθηκε το wp-config.php.'                    => 'wp-config.php was not found.',
		'Το wp-config.php δεν γράφεται από τον server.'    => 'The server cannot write wp-config.php.',
		'Το wp-config.php δεν διαβάζεται.'                 => 'wp-config.php cannot be read.',
		'Το wp-config.php έχει μορφή που δεν αλλάζουμε αυτόματα.' => 'wp-config.php has a layout that is not changed automatically.',
		'Η αλλαγή στο wp-config.php δεν θα ήταν έγκυρη PHP, οπότε δεν έγινε.' => 'The change to wp-config.php would not be valid PHP, so it was not made.',
		'Η εγγραφή στο wp-config.php απέτυχε και το αρχείο έμεινε όπως ήταν.' => 'Writing wp-config.php failed and the file was left as it was.',
		'Πρόσθεσε με το χέρι, κάτω από το <?php:'          => 'Add by hand, below <?php:',
		'Made with ❤ by %s'                                => 'Made with ❤ by %s',
		'More plugins at'                                  => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                   => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                               => 'Noxpress Dashboard',
		'Καθάρισμα αυτής της σελίδας'                      => 'Purge this page',
		'Η σελίδα όπως στην cache'                         => 'This page as cached',
		'Η σελίδα καθαρίστηκε από την cache.'              => 'The page was purged from the cache.',
		'Η cache καθαρίστηκε.'                             => 'The cache was purged.',
		'Δεν βρέθηκαν ρυθμίσεις του WP Rocket.'            => 'No WP Rocket settings were found.',
		'Οι ρυθμίσεις του WP Rocket αντιγράφηκαν. Το WP Rocket δεν άλλαξε.' => 'The WP Rocket settings were copied. WP Rocket was not changed.',
		'Το WP Rocket κρατούσε ξεχωριστή cache για κινητά· εδώ υπάρχει μία για όλους (το αλλάζεις στην καρτέλα Cache).' => 'WP Rocket kept a separate cache for mobile; here there is one for everyone (change it in the Cache tab).',
		'Στο WP Rocket η cache δεν έληγε ποτέ· εδώ λήγει μετά από λίγες ώρες (καρτέλα Cache), ώστε οι φόρμες να μη μένουν με παλιό nonce.' => 'In WP Rocket the cache never expired; here it expires after a few hours (Cache tab), so forms do not keep an old nonce.',
		'Αφαίρεση αχρησιμοποίητου CSS'                     => 'Remove Unused CSS',
		'Critical CSS'                                     => 'Critical CSS',
		'Καθυστέρηση JavaScript'                           => 'Delay JavaScript',
		'Defer JavaScript'                                 => 'Defer JavaScript',
		'CDN'                                              => 'CDN',
		'Google Fonts'                                     => 'Google Fonts',
		'Το «%s» του WP Rocket δεν υπάρχει στο Fast Pages και έμεινε έξω.' => 'WP Rocket\'s «%s» does not exist in Fast Pages and was left out.',
		// Plurals (_n): singular and plural msgids.
		'%d ώρα'                                           => '%d hour',
		'%d ώρες'                                          => '%d hours',
	);

	public static function init(): void {
		add_filter( 'gettext_fast-pages', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_fast-pages', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** The dictionary, for the release check script. */
	public static function dict(): array {
		return self::$dict;
	}

	/** Revenue Splitter is active (then its page owns the language choice). */
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

	/** 'el' | 'en' for the current user (or the site, without one). */
	public static function lang(): string {
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		$uid    = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$choice = $uid ? (string) get_user_meta( $uid, 'rs_lang', true ) : '';
		if ( 'el' === $choice || 'en' === $choice ) {
			$lang = $choice;
		} else {
			$locale = ( $uid && function_exists( 'get_user_locale' ) ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_fast-pages filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'fast-pages' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_fast-pages filter (English plural rule: 1 → single). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'fast-pages' === $domain && 'en' === self::lang() ) {
			$msgid = 1 === (int) $number ? $single : $plural;
			if ( isset( self::$dict[ $msgid ] ) ) {
				return self::$dict[ $msgid ];
			}
		}
		return $translation;
	}
}
