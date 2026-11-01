<?php
/**
 * LIM_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ here (Bible §9): 'el' | 'en' | ''/'auto'.
 * Revenue Splitter writes it; without RS the Tools tab offers the same
 * choice. Without an explicit choice the user's locale decides: el* →
 * Greek (msgids pass through as they are), anything else → English.
 * Lean Images has no visitor texts.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Επισκόπηση'                                                => 'Overview',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Εργαλεία'                                                  => 'Tools',
		'Lean Images'                                               => 'Lean Images',
		'Lean Images: το Imagify είναι ενεργό και δείχνει τις δικές του εικόνες WebP, οπότε το Lean Images περιμένει. Τα WebP του Imagify αναγνωρίζονται χωρίς νέα μετατροπή. Όταν η μετατροπή τελειώσει, απενεργοποίησε το Imagify.' => 'Lean Images: Imagify is active and shows its own WebP images, so Lean Images waits. Imagify\'s WebP files are recognised without a new conversion. When the conversion is done, deactivate Imagify.',
		'Lean Images: το Imagify είναι ακόμα ενεργό. Απενεργοποίησέ το, ώστε οι εικόνες να μη μετατρέπονται δύο φορές.' => 'Lean Images: Imagify is still active. Deactivate it so images are not converted twice.',
		'σφάλμα'                                                    => 'error',
		'σε αναμονή'                                                => 'waiting',
		'χωρίς κέρδος'                                              => 'no gain',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Οι εικόνες JPEG και PNG γίνονται WebP (και προαιρετικά AVIF) στον δικό σου server, σε μικρές παρτίδες στο παρασκήνιο. Τα πρωτότυπα δεν αλλάζουν ποτέ, και οι browsers που δεν ξέρουν WebP παίρνουν το πρωτότυπο.' => 'JPEG and PNG images become WebP (and optionally AVIF) on your own server, in small background batches. The originals never change, and browsers that do not know WebP get the original.',
		'δεν υπήρχαν εικόνες σε αναμονή'                            => 'there were no images waiting',
		'έφτασε το μέγεθος της παρτίδας'                            => 'reached the batch size',
		'έφτασε το όριο χρόνου'                                     => 'reached the time limit',
		'η μνήμη πλησίασε το όριο'                                  => 'memory came close to the limit',
		'υψηλό φορτίο στον server'                                  => 'high load on the server',
		'ο server δεν γράφει τις μορφές που επέλεξες'               => 'the server cannot write the formats you chose',
		'Κατάσταση'                                                 => 'Status',
		'Σταθερά LIM_DISABLE'                                       => 'LIM_DISABLE constant',
		'ενεργή'                                                    => 'on',
		'Η μετατροπή και το σερβίρισμα είναι σταματημένα από το wp-config.php.' => 'Conversion and serving are stopped from wp-config.php.',
		'Μετατροπή'                                                 => 'Conversion',
		'δεν ξεκίνησε'                                              => 'not started',
		'Τίποτα δεν αλλάζει μέχρι να πατήσεις «Έναρξη».'            => 'Nothing changes until you press «Start».',
		'σε παύση'                                                  => 'paused',
		'τρέχει τη νύχτα'                                           => 'running at night',
		'Μία παρτίδα κάθε 5 λεπτά, 01:00–07:00 ώρα του site.'       => 'One batch every 5 minutes, 01:00–07:00 site time.',
		'τρέχει'                                                    => 'running',
		'Μία παρτίδα κάθε 5 λεπτά στο παρασκήνιο.'                  => 'One batch every 5 minutes in the background.',
		'Σερβίρισμα'                                                => 'Serving',
		'περιμένει το Imagify'                                      => 'waiting for Imagify',
		'Το Imagify δείχνει ακόμα τις δικές του εικόνες. Απενεργοποίησέ το όταν είσαι έτοιμος.' => 'Imagify still shows its own images. Deactivate it when you are ready.',
		'λειτουργία δοκιμής'                                        => 'test mode',
		'Μόνο οι διαχειριστές βλέπουν τα WebP. Οι επισκέπτες παίρνουν ακόμα τα πρωτότυπα.' => 'Only administrators see the WebP files. Visitors still get the originals.',
		'ενεργό για όλους'                                          => 'on for everyone',
		'Server'                                                    => 'Server',
		'κανένας επεξεργαστής εικόνων'                              => 'no image editor',
		'Παύση'                                                     => 'Pause',
		'Έναρξη'                                                    => 'Start',
		'Συνέχεια'                                                  => 'Resume',
		'Μία παρτίδα τώρα'                                          => 'One batch now',
		'Έξοδος από τη λειτουργία δοκιμής'                          => 'Leave test mode',
		'Από τώρα όλοι οι επισκέπτες θα παίρνουν τα WebP. Συνέχεια;' => 'From now on every visitor gets the WebP files. Continue?',
		'Λειτουργία δοκιμής'                                        => 'Test mode',
		'Αριθμοί'                                                   => 'Numbers',
		'Εικόνες JPEG / PNG'                                        => 'JPEG / PNG images',
		'%1$d από %2$d έτοιμες'                                     => '%1$d of %2$d done',
		'Σε αναμονή'                                                => 'Waiting',
		'Σφάλματα'                                                  => 'Errors',
		'%1$s → %2$s (−%3$s%%)'                                     => '%1$s → %2$s (−%3$s%%)',
		'Επιπλέον χώρος στον δίσκο'                                 => 'Extra disk space',
		'%1$s σε %2$d αρχεία'                                       => '%1$s in %2$d files',
		'Τελευταία παρτίδα'                                         => 'Last batch',
		'δεν έτρεξε ακόμα'                                          => 'not run yet',
		'%1$s · %2$d εικόνες σε %3$s s · %4$s'                      => '%1$s · %2$d images in %3$s s · %4$s',
		'λιγότερο από μία ώρα'                                      => 'less than an hour',
		'Μορφές'                                                    => 'Formats',
		'WebP'                                                      => 'WebP',
		'Φτιάξε και σέρβιρε WebP'                                   => 'Make and serve WebP',
		'Το δέχονται σχεδόν όλοι οι browsers. Ποιότητα:'            => 'Almost every browser accepts it. Quality:',
		'AVIF'                                                      => 'AVIF',
		'Φτιάξε και σέρβιρε και AVIF'                               => 'Also make and serve AVIF',
		'Περίπου 20% μικρότερο από το WebP, αλλά θέλει πολλαπλάσια CPU και επιπλέον χώρο. Σε shared hosting άφησέ το κλειστό. Ποιότητα:' => 'About 20% smaller than WebP, but it needs several times the CPU and extra space. On shared hosting leave it off. Quality:',
		'Ο server δεν μπορεί να γράψει AVIF.'                       => 'The server cannot write AVIF.',
		'Ελάχιστο κέρδος'                                           => 'Minimum gain',
		'Ένα αρχείο κρατιέται μόνο αν είναι τουλάχιστον τόσο μικρότερο από το πρωτότυπο.' => 'A file is kept only when it is at least this much smaller than the original.',
		'Παρτίδες'                                                  => 'Batches',
		'Εικόνες ανά παρτίδα'                                       => 'Images per batch',
		'Μία παρτίδα κάθε 5 λεπτά. Κάθε εικόνα έχει συνήθως 6–10 μεγέθη.' => 'One batch every 5 minutes. Each image usually has 6–10 sizes.',
		'Όριο χρόνου'                                               => 'Time limit',
		'Η παρτίδα σταματά μετά από τόσα δευτερόλεπτα, ή όταν η μνήμη φτάσει το 80% του ορίου.' => 'A batch stops after this many seconds, or when memory reaches 80% of the limit.',
		'Μόνο τη νύχτα'                                             => 'Only at night',
		'Παρτίδες μόνο 01:00–07:00, ώρα του site'                   => 'Batches only 01:00–07:00, site time',
		'Όλη η σελίδα'                                              => 'Whole page',
		'Και εικόνες που δεν τύπωσε το WordPress'                   => 'Also images that WordPress did not print',
		'Για page builders ή εικόνες που γράφτηκαν με το χέρι στο HTML. Οι εικόνες του θέματος, του WooCommerce και του περιεχομένου καλύπτονται ήδη χωρίς αυτό.' => 'For page builders or images written by hand in the HTML. Images of the theme, WooCommerce and the content are already covered without it.',
		'Αποθήκευση'                                                => 'Save',
		'Δοκιμή σε 20 εικόνες'                                      => 'Test on 20 images',
		'Μετατρέπει έως 20 εικόνες σε προσωρινό φάκελο, μετρά χρόνο και μέγεθος και τα σβήνει όλα. Δίνει εκτίμηση για όλη τη βιβλιοθήκη πριν από την «Έναρξη».' => 'Converts up to 20 images in a temporary folder, measures time and size and deletes everything. Gives an estimate for the whole library before «Start».',
		'Δοκιμή'                                                    => 'Test',
		'Μία εικόνα'                                                => 'One image',
		'ID εικόνας'                                                => 'Image ID',
		'Μετατροπή τώρα'                                            => 'Convert now',
		'Συντήρηση'                                                 => 'Maintenance',
		'Ξαναδοκίμασε τα σφάλματα (%d)'                             => 'Retry the errors (%d)',
		'Καθαρισμός ορφανών'                                        => 'Clean up orphans',
		'Ορφανά: αρχεία WebP / AVIF που έμειναν όταν σβήστηκε ή μετονομάστηκε το πρωτότυπο.' => 'Orphans: WebP / AVIF files left behind when the original was deleted or renamed.',
		'Διαγραφή όλων'                                             => 'Delete all',
		'Σβήνει όλα τα WebP / AVIF που έφτιαξε ή υιοθέτησε το Lean Images και σταματά τη μετατροπή. Τα πρωτότυπα δεν αγγίζονται. Το ίδιο γίνεται όταν διαγράφεις το plugin.' => 'Deletes every WebP / AVIF that Lean Images made or adopted and stops the conversion. The originals are not touched. The same happens when you delete the plugin.',
		'Διαγραφή όλων των αρχείων που φτιάξαμε'                    => 'Delete all the files we made',
		'Να σβηστούν όλα τα WebP / AVIF του Lean Images; Οι σελίδες θα δείχνουν ξανά τα πρωτότυπα.' => 'Delete all of Lean Images\' WebP / AVIF files? Pages will show the originals again.',
		'Γλώσσα'                                                    => 'Language',
		'Αυτόματα'                                                  => 'Automatic',
		'Η μετατροπή ξεκίνησε. Η πρώτη παρτίδα τρέχει σε λίγα λεπτά.' => 'The conversion started. The first batch runs in a few minutes.',
		'Η μετατροπή σταμάτησε. Οι εικόνες που έγιναν ήδη συνεχίζουν να σερβίρονται.' => 'The conversion stopped. Images already done keep being served.',
		'Τρέχει ήδη μια παρτίδα. Δοκίμασε σε λίγο.'                 => 'A batch is already running. Try again in a moment.',
		'%1$d εικόνες σε %2$s s (%3$s). Σε αναμονή: %4$d.'          => '%1$d images in %2$s s (%3$s). Waiting: %4$d.',
		'Όλοι οι επισκέπτες παίρνουν τώρα τα WebP. Η page cache καθαρίστηκε.' => 'Every visitor now gets the WebP files. The page cache was purged.',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τα WebP.' => 'Test mode: only administrators see the WebP files.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Καμία μορφή δεν είναι επιλεγμένη: οι παρτίδες δεν κάνουν τίποτα και τίποτα δεν σερβίρεται.' => 'No format is selected: the batches do nothing and nothing is served.',
		'Δεν βρέθηκε εικόνα JPEG ή PNG με αυτό το ID.'              => 'No JPEG or PNG image with this ID was found.',
		'Έτοιμο: %1$d αρχεία, %2$s → %3$s.'                         => 'Done: %1$d files, %2$s → %3$s.',
		'%d εικόνες μπήκαν ξανά σε αναμονή.'                        => '%d images are waiting again.',
		'Ελέγχθηκαν %1$d εικόνες, σβήστηκαν %2$d ορφανά αρχεία.'    => 'Checked %1$d images, deleted %2$d orphan files.',
		'Έμειναν κι άλλες: πάτα ξανά για να συνεχίσει.'             => 'There are more: press again to continue.',
		'Σβήστηκαν %1$d αρχεία από %2$d εικόνες. Η μετατροπή σταμάτησε.' => 'Deleted %1$d files of %2$d images. The conversion stopped.',
		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Δεν βρέθηκαν εικόνες JPEG ή PNG για δοκιμή, ή ο server δεν γράφει τις μορφές που επέλεξες.' => 'No JPEG or PNG images were found to test, or the server cannot write the formats you chose.',
		'Δοκιμή: %1$d εικόνες (%2$d αρχεία) σε %3$s s. Πρωτότυπα %4$s, νέα αρχεία %5$s.' => 'Test: %1$d images (%2$d files) in %3$s s. Originals %4$s, new files %5$s.',
		'Εκτίμηση για τις %1$d εικόνες σε αναμονή: περίπου %2$s δουλειάς του server συνολικά (μοιρασμένα σε παρτίδες) και %3$s επιπλέον χώρος.' => 'Estimate for the %1$d waiting images: about %2$s of server work in total (spread over batches) and %3$s of extra space.',
		'%d αρχεία δεν μετατράπηκαν.'                               => '%d files were not converted.',
		'Η δοκιμή σταμάτησε στα 20 δευτερόλεπτα.'                   => 'The test stopped at 20 seconds.',
		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
		'περίπου %d νύχτα'                                          => 'about %d night',
		'περίπου %d νύχτες'                                         => 'about %d nights',
		'περίπου %d ώρα'                                            => 'about %d hour',
		'%d λεπτό'                                                  => '%d minute',
		'%d λεπτά'                                                  => '%d minutes',
		'περίπου %d ώρες'                                           => 'about %d hours',
	);

	public static function init(): void {
		add_filter( 'gettext_lean-images', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_lean-images', array( __CLASS__, 'filter_n' ), 10, 5 );
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

	/** gettext_lean-images filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'lean-images' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_lean-images filter (English plural rule: 1 → single). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'lean-images' === $domain && 'en' === self::lang() ) {
			$msgid = 1 === (int) $number ? $single : $plural;
			if ( isset( self::$dict[ $msgid ] ) ) {
				return self::$dict[ $msgid ];
			}
		}
		return $translation;
	}
}
