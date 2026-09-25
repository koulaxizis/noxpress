<?php
/**
 * NM_Lang — Δίγλωσσο UI (English / Ελληνικά) — ανά χρήστη.
 *
 * Μηχανισμός ( mirrored από το RS_Lang): gettext filter στο domain
 * 'noxpress-migrator'. ΕΔΩ όμως το msgid σε όλο το plugin είναι
 * ΑΓΓΛΙΚΟ (default EN σύμφωνα με το spec του plugin) και όταν ο
 * χρήστης επιλέξει 'el', το φίλτρο αντικαθιστά με ελληνική εκδοχή.
 *
 * Επιλογή γλώσσας: user meta 'nm_lang' ('el' | 'en' | 'auto').
 *
 * NOTE: το λεξικό μεγαλώνει wave-by-wave (κάθε νέο string του
 * admin UI προστίθεται εδώ) — κρατάμε ΟΛΑ τα εμφανιζόμενα strings
 * του domain σε μία πηγή αλήθειας.
 */

defined( 'ABSPATH' ) || exit;

class NM_Lang {

	const USER_META = 'nm_lang';

	/** Cache ανά request — αποφεύγει επαναλαμβανόμενα get_user_meta(). */
	private static $current = null;

	public static function init(): void {
		add_filter( 'gettext', array( __CLASS__, 'filter_gettext' ), 10, 3 );
	}

	/** Η ρητή επιλογή του χρήστη: 'el' | 'en' | 'auto' (default). */
	public static function get_choice( ?int $user_id = null ): string {

		$uid  = $user_id ?? get_current_user_id();
		$lang = $uid ? (string) get_user_meta( $uid, self::USER_META, true ) : '';

		return in_array( $lang, array( 'el', 'en' ), true ) ? $lang : 'auto';
	}

	/**
	 * Η ΕΝΕΡΓΗ γλώσσα ('el' | 'en') — για rendering.
	 *
	 * 'auto' (μηδενική επιλογή) ακολουθεί το WordPress locale:
	 * el* → Ελληνικά, οτιδήποτε άλλο → English (το default του plugin).
	 */
	public static function get_lang( ?int $user_id = null ): string {

		if ( null !== self::$current && null === $user_id ) {
			return self::$current;
		}

		$lang = self::get_choice( $user_id );

		if ( 'auto' === $lang ) {
			$locale = function_exists( 'get_user_locale' )
				? get_user_locale( $user_id ?? get_current_user_id() )
				: get_locale();

			$lang = ( 0 === strpos( strtolower( (string) $locale ), 'el' ) ) ? 'el' : 'en';
		}

		if ( null === $user_id ) {
			self::$current = $lang;
		}

		return $lang;
	}

	/**
	 * Θέτει τη γλώσσα: 'el' | 'en' | 'auto'.
	 * 'auto' = σβήνει το user meta → Follow-WP mode.
	 */
	public static function set_lang( int $user_id, string $lang ): bool {

		// Reset request-cache ώστε η αλλαγή να ισχύσει άμεσα.
		self::$current = null;

		if ( 'auto' === $lang ) {
			return (bool) delete_user_meta( $user_id, self::USER_META );
		}

		$lang = in_array( $lang, array( 'el', 'en' ), true ) ? $lang : 'en';

		return (bool) update_user_meta( $user_id, self::USER_META, $lang );
	}

	/**
	 * Το «μετάφρασμα»: όταν ο χρήστης είναι σε EL mode, αντικαθιστά
	 * τα αγγλικά msgids του domain μας με ελληνικά.
	 */
	public static function filter_gettext( $translation, $text, $domain ) {

		if ( 'noxpress-migrator' !== $domain ) {
			return $translation;
		}
		if ( 'el' !== self::get_lang() ) {
			return $translation; // EN mode → τα msgids ΕΙΝΑΙ ήδη αγγλικά.
		}
		if ( isset( self::$dict[ $text ] ) ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/**
	 * Ανθρώπινο μέγεθος αρχείου (progress UI / manifest αναφορές).
	 * Το number_format_i18n φροντίζει για τα thousands separators.
	 */
	public static function fmt_bytes( $bytes ): string {

		$b = (float) $bytes;

		if ( $b < 1024.0 ) {
			return number_format_i18n( $b, 0 ) . ' B';
		}
		if ( $b < 1048576.0 ) {
			return number_format_i18n( $b / 1024.0, 1 ) . ' KB';
		}
		if ( $b < 1073741824.0 ) {
			return number_format_i18n( $b / 1048576.0, 1 ) . ' MB';
		}
		return number_format_i18n( $b / 1073741824.0, 2 ) . ' GB';
	}

	/**
	 * Λεξικό English → Ελληνικά.
	 *
	 * Τα κλειδιά = ΟΛΑ τα __( '…', 'noxpress-migrator' ) msgids του
	 * plugin. Κάθε νέο string προστίθεται εδώ (μια πηγή αλήθειας).
	 */
	private static $dict = array(

		// --- Menus / σελίδες ---
		'Noxpress Migrator'                                     => 'Noxpress Migrator',
		'Migration'                                             => 'Μετανάστευση',
		'Settings'                                              => 'Ρυθμίσεις',
		'You do not have permission to access this page.'       => 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.',
		'The following components are missing from the plugin folder (protection against a partially uploaded copy — the site keeps working normally):' => 'Οι ακόλουθες συνιστώσες λείπουν από τον φάκελο του plugin (προστασία έναντι ημιτελούς μεταφοράς αρχείων — το site συνεχίζει κανονικά):',

		// --- Φόρμα export ---
		'Start export'                                          => 'Έναρξη εξαγωγής',
		'New export'                                            => 'Νέα εξαγωγή',
		'Resume'                                                => 'Συνέχεια',
		'What to include'                                       => 'Τι θα συμπεριληφθεί',
		'Everything (database + all files)'                     => 'Τα πάντα (βάση + όλα τα αρχεία)',
		'Database only'                                         => 'Μόνο η βάση δεδομένων',
		'Files only'                                            => 'Μόνο τα αρχεία',
		'Uploads'                                               => 'Uploads',
		'Plugins'                                               => 'Plugins',
		'Themes'                                                => 'Themes',
		'Media newer than'                                      => 'Πολυμέσα νεότερα από',
		'No limit'                                              => 'Χωρίς όριο',
		'Volume size (MB)'                                      => 'Μέγεθος τόμου (MB)',
		'Apply'                                                 => 'Εφαρμογή',

		// --- Status / progress ---
		'Status'                                                => 'Κατάσταση',
		'Idle'                                                  => 'Σε αδράνεια',
		'Preparing'                                             => 'Προετοιμασία',
		'In progress'                                           => 'Σε εξέλιξη',
		'Database'                                              => 'Βάση δεδομένων',
		'Files'                                                 => 'Αρχεία',
		'Manifest'                                              => 'Manifest',
		'Packaging'                                             => 'Συσκευασία',
		'Completed'                                             => 'Ολοκληρώθηκε',
		'Failed'                                                => 'Απέτυχε',
		'Paused — resumable'                                    => 'Σε παύση — με δυνατότητα συνέχειας',
		'Checkpoint saved.'                                     => 'Το checkpoint αποθηκεύτηκε.',
		'Download'                                              => 'Λήψη',
		'Delete package'                                        => 'Διαγραφή πακέτου',

		// --- Ρυθμίσεις ---
		'Display language'                                      => 'Γλώσσα οθόνης',
		'Automatic (WordPress)'                                 => 'Αυτόματη (WordPress)',
		'Per user (only affects you). If you have not picked one, the WordPress language is followed.' => 'Ισχύει ανά χρήστη (μόνο για εσένα). Εάν δεν έχεις διαλέξει, ακολουθείται η γλώσσα του WordPress.',
		'Settings saved.'                                       => 'Οι ρυθμίσεις αποθηκεύτηκαν.',
		'Invalid volume size (50–4096).'                        => 'Μη έγκυρο μέγεθος τόμου (50–4096).',
		'Save settings'                                         => 'Αποθήκευση ρυθμίσεων',

		// --- Errors ---
		'Something went wrong during the export — check the log.' => 'Κάτι πήγε στραβά κατά την εξαγωγή — δες το log.',
		'The export was aborted.'                               => 'Η εξαγωγή διακόπηκε.',
		'Not enough disk space for staging.'                    => 'Δεν υπάρχει αρκετός χώρος στο δίσκο για staging.',

		// --- Footer ---
		'Made with <3 by %s'                                    => 'Made with <3 by %s',
		'More plugins at'                                       => 'Περισσότερα plugins στο',

		// --- Download / package ---
		'Download package'                                      => 'Λήψη πακέτου',
		'File'                                                   => 'Αρχείο',
		'Size'                                                   => 'Μέγεθος',
		'Actions'                                                => 'Ενέργειες',
		'Download'                                               => 'Λήψη',
		'Delete the package permanently?'                       => 'Οριστική διαγραφή του πακέτου;',
		'Abort'                                                 => 'Διακοπή',
		'Are you sure?'                                          => 'Είσαι σίγουρος;',
		'Current item'                                           => 'Τρέχον στοιχείο',
		'Items'                                                  => 'Στοιχεία',
		'Error'                                                  => 'Σφάλμα',
		'The file was not found.'                                => 'Το αρχείο δεν βρέθηκε.',
		'The file cannot be read.'                               => 'Το αρχείο δεν μπορεί να διαβαστεί.',
		'Checksum mismatch — the file is not trustworthy. Start a new export.' => 'Ασυμφωνία checksum — το αρχείο δεν είναι αξιόπιστο. Ξεκίνα νέα εξαγωγή.',
		'Only uploads newer than this date (optional). Leave empty for no limit.' => 'Μόνο uploads νεότερα από αυτή την ημερομηνία (προαιρετικό). Κενό = χωρίς όριο.',
		'Split point for multi-volume archives.'                => 'Σημείο τεμαχισμού για πολυτομικά archives.',
	);
}