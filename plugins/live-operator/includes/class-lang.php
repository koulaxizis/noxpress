<?php
/**
 * LOP_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Notifications sent from WP-Cron (no
 * current user) follow the site locale. The plugin has no visitor texts.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		// App page.
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Φόρτωση…'                                         => 'Loading…',
		'Η εφαρμογή χρειάζεται JavaScript.'                => 'The app needs JavaScript.',
		'Online'                                           => 'Online',
		'Offline'                                          => 'Offline',
		'έως τις %s'                                       => 'until %s',
		'Παράταση'                                         => 'Extend',
		'Γίνε online'                                      => 'Go online',
		'Γίνε offline'                                     => 'Go offline',
		'Ο διακόπτης δεν είναι ενεργός. Άνοιξέ τον στις ρυθμίσεις του Live Operator.' => 'The switch is not enabled. Turn it on in the Live Operator settings.',
		'Ρυθμίσεις'                                        => 'Settings',
		'Το PurioChat Pro δεν είναι ενεργό ή το Live Chat του είναι κλειστό.' => 'PurioChat Pro is not active or its Live Chat is off.',
		'Το PurioChat δεν είναι στη διαθεσιμότητα «online»: ο διακόπτης δεν αλλάζει τίποτα για τους επισκέπτες.' => 'PurioChat is not set to the «online» availability: the switch changes nothing for visitors.',
		'Οι ειδοποιήσεις δεν είναι ανοιχτές σε αυτή τη συσκευή.' => 'Notifications are not on for this device.',
		'Άνοιγμα ειδοποιήσεων'                             => 'Turn on notifications',
		'Οι ειδοποιήσεις είναι μπλοκαρισμένες για αυτό το site. Άνοιξέ τες από τις ρυθμίσεις του browser ή της εφαρμογής.' => 'Notifications are blocked for this site. Allow them in the browser or app settings.',
		'Αυτός ο browser δεν υποστηρίζει ειδοποιήσεις push.' => 'This browser does not support push notifications.',
		'Ο server δεν μπορεί να στείλει ειδοποιήσεις (λείπει το OpenSSL με κλειδιά EC).' => 'The server cannot send notifications (OpenSSL with EC keys is missing).',
		'Οι ειδοποιήσεις είναι ανοιχτές σε αυτή τη συσκευή.' => 'Notifications are on for this device.',
		'Δοκιμή'                                           => 'Test',
		'Στάλθηκε. Αν δεν ήρθε, έλεγξε τις ειδοποιήσεις της εφαρμογής στο Android.' => 'Sent. If it did not arrive, check the app notifications in Android.',
		'Η δοκιμή δεν στάλθηκε. Άνοιξε ξανά τις ειδοποιήσεις.' => 'The test was not sent. Turn notifications on again.',
		'Κλείσιμο ειδοποιήσεων'                            => 'Turn off notifications',
		'Σε αναμονή'                                       => 'Waiting',
		'Ενεργές'                                          => 'Active',
		'Με το AI τώρα'                                    => 'With the AI now',
		'Πρόσφατες'                                        => 'Recent',
		'Καμία συνομιλία αυτή τη στιγμή.'                  => 'No conversations right now.',
		'Επισκέπτης'                                       => 'Visitor',
		'AI'                                               => 'AI',
		'Εσύ'                                              => 'You',
		'Πίσω'                                             => 'Back',
		'Ανάληψη'                                          => 'Claim',
		'Ανάληψη από το AI'                                => 'Take over from the AI',
		'Επιστροφή στο AI'                                 => 'Return to the AI',
		'Να επιστρέψει η συνομιλία στο AI;'                => 'Return the conversation to the AI?',
		'Αποστολή'                                         => 'Send',
		'Γράψε απάντηση…'                                  => 'Write a reply…',
		'περιμένει %s'                                     => 'waiting %s',
		'επιστρέφει στο AI σε %s'                          => 'back to the AI in %s',
		'Την έχει άλλος χειριστής.'                        => 'Another operator has it.',
		'Η συνομιλία είναι πάλι με το AI.'                 => 'The conversation is with the AI again.',
		'Σελίδα επισκέπτη'                                 => 'Visitor page',
		'Δεν υπάρχει σύνδεση με το site. Ξαναδοκιμάζει…'   => 'No connection to the site. Retrying…',
		'Η ενέργεια δεν έγινε. Δοκίμασε ξανά.'             => 'That did not work. Try again.',
		'Η σύνδεση έληξε. Ξαναφορτώνει…'                   => 'The session expired. Reloading…',
		'Live Chat του PurioChat'                          => 'PurioChat Live Chat',
		'Ένα αίτημα περιμένει %d δευτερόλεπτα πριν γυρίσει στο AI.' => 'A request waits %d seconds before it goes back to the AI.',

		// Admin.
		'Live Operator'                                    => 'Live Operator',
		'Το Live Operator χρειάζεται το PurioChat Pro με ενεργό το Live Chat. Χωρίς αυτό δεν κάνει τίποτα.' => 'Live Operator needs PurioChat Pro with Live Chat on. Without it, it does nothing.',
		'Το live chat του PurioChat στο κινητό: διακόπτης online / offline, ειδοποιήσεις για κάθε αίτημα και μήνυμα, και απαντήσεις από την εφαρμογή.' => 'PurioChat\'s live chat on your phone: an online / offline switch, notifications for every request and message, and replies from the app.',
		'Κατάσταση'                                        => 'Status',
		'PurioChat Pro'                                    => 'PurioChat Pro',
		'δεν είναι ενεργό'                                 => 'not active',
		'το Live Chat είναι κλειστό ή η άδεια δεν ισχύει'  => 'Live Chat is off or the license is not valid',
		'Live Chat ανοιχτό'                                => 'Live Chat on',
		'Διαθεσιμότητα στο PurioChat'                      => 'PurioChat availability',
		'online'                                           => 'online',
		'ωράριο'                                           => 'business hours',
		'πάντα'                                            => 'always',
		'Ο διακόπτης ισχύει μόνο με τη διαθεσιμότητα «online» (PurioChat › Live Chat).' => 'The switch only works with the «online» availability (PurioChat › Live Chat).',
		'Χρόνος αναμονής'                                  => 'Waiting time',
		'%d δευτ.'                                         => '%d s',
		'Από το κινητό, μετά την ειδοποίηση, χρειάζεσαι λίγο χρόνο: προτείνεται 300 δευτερόλεπτα (PurioChat › Live Chat).' => 'From the phone you need some time after the notification: 300 seconds is recommended (PurioChat › Live Chat).',
		'Διακόπτης'                                        => 'Switch',
		'δεν είναι ενεργός'                                => 'not enabled',
		'Ενεργοποίησέ τον στις ρυθμίσεις παρακάτω.'        => 'Enable it in the settings below.',
		'online έως τις %s'                                => 'online until %s',
		'offline'                                          => 'offline',
		'Ειδοποιήσεις'                                     => 'Notifications',
		'ο server δεν τις υποστηρίζει'                     => 'the server does not support them',
		'Χρειάζεται PHP με OpenSSL (κλειδιά EC και AES-GCM).' => 'PHP with OpenSSL (EC keys and AES-GCM) is needed.',
		'συσκευές σου: %d'                                 => 'your devices: %d',
		'Άνοιξε την εφαρμογή στο κινητό και πάτα «Άνοιγμα ειδοποιήσεων».' => 'Open the app on your phone and tap «Turn on notifications».',
		'Μόνιμοι σύνδεσμοι'                                => 'Permalinks',
		'απλοί'                                            => 'plain',
		'Η εφαρμογή θέλει όμορφους μόνιμους συνδέσμους (Ρυθμίσεις › Μόνιμοι σύνδεσμοι) για τις ειδοποιήσεις.' => 'The app needs pretty permalinks (Settings › Permalinks) for notifications.',
		'Άνοιγμα εφαρμογής'                                => 'Open the app',
		'Δοκιμαστική ειδοποίηση'                           => 'Test notification',
		'Ο διακόπτης της εφαρμογής ορίζει πότε είμαι online στο live chat' => 'The app\'s switch decides when I am online in the live chat',
		'Online: οι επισκέπτες βλέπουν το «Μιλήστε με άνθρωπο» και ας είναι κλειστή η οθόνη του κινητού. Offline: δεν το βλέπουν. Κλειστό: το PurioChat δουλεύει όπως πριν.' => 'Online: visitors see «Talk to a human» even with the phone screen off. Offline: they do not. Unticked: PurioChat works as before.',
		'Αυτόματο offline μετά από'                        => 'Automatic offline after',
		'1 ώρα'                                            => '1 hour',
		'%d ώρες'                                          => '%d hours',
		'Για να μη μείνεις ξεχασμένος online. Το «Παράταση» ξαναμετρά από την αρχή, και στη λήξη έρχεται ειδοποίηση.' => 'So you are not left online by mistake. «Extend» starts the count again, and a notification comes when it ends.',
		'Η ανοιχτή σελίδα Live Chat του PurioChat με κάνει επίσης online' => 'An open PurioChat Live Chat page also makes me online',
		'Κλειστό: μόνο ο διακόπτης αποφασίζει, ακόμα κι αν η σελίδα του PurioChat έμεινε ανοιχτή σε κάποιον υπολογιστή.' => 'Unticked: only the switch decides, even if the PurioChat page was left open on a computer.',
		'Το κείμενο του μηνύματος στις ειδοποιήσεις'       => 'The message text in notifications',
		'Κλειστό: η ειδοποίηση λέει μόνο ποιος έγραψε. Το περιεχόμενο ταξιδεύει πάντα κρυπτογραφημένο.' => 'Unticked: the notification only says who wrote. The content always travels encrypted.',
		'Όνομα εφαρμογής'                                  => 'App name',
		'Όπως φαίνεται όταν μπαίνει στην αρχική οθόνη του κινητού.' => 'As shown when added to the phone\'s home screen.',
		'Εφαρμογή Android'                                 => 'Android app',
		'Η εφαρμογή Android του Live Operator ανοίγει αυτή τη σελίδα σε πλήρη οθόνη. Για να μη φαίνεται η γραμμή διεύθυνσης, το site δηλώνει την εφαρμογή στο assetlinks.json με το αποτύπωμα του πιστοποιητικού της (γράφεται στις σημειώσεις κάθε έκδοσης της εφαρμογής).' => 'The Live Operator Android app opens this page full screen. To hide the address bar, the site declares the app in assetlinks.json with its certificate fingerprint (given in the notes of every app release).',
		'Πακέτο εφαρμογής'                                 => 'App package',
		'Αποτυπώματα SHA-256 πιστοποιητικού (ένα ανά γραμμή)' => 'SHA-256 certificate fingerprints (one per line)',
		'Το site δίνει:'                                   => 'The site serves:',
		'(αν ανοίγει 404, ο server δεν στέλνει το /.well-known/ στο WordPress: ανέβασε το αρχείο με το χέρι).' => '(if it gives a 404, the server does not pass /.well-known/ to WordPress: upload the file by hand).',
		'Κενό: η εφαρμογή δουλεύει, με μια λεπτή γραμμή διεύθυνσης στο πάνω μέρος.' => 'Empty: the app works, with a thin address bar at the top.',
		'Αποθήκευση'                                       => 'Save',
		'Το Live Operator είναι σταματημένο με τη σταθερά LOP_DISABLE.' => 'Live Operator is stopped with the LOP_DISABLE constant.',
		'Ενεργοποίησε πρώτα τον διακόπτη στις ρυθμίσεις.'  => 'Enable the switch in the settings first.',
		'Είσαι online έως τις %s.'                         => 'You are online until %s.',
		'Είσαι offline.'                                   => 'You are offline.',
		'Στάλθηκε σε %d συσκευές.'                         => 'Sent to %d devices.',
		'Στάλθηκε σε %1$d, απέτυχε σε %2$d συσκευές. Όσες δεν υπάρχουν πια αφαιρέθηκαν.' => 'Sent to %1$d, failed on %2$d devices. Those that no longer exist were removed.',
		'Άγνωστη ενέργεια.'                                => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                      => 'Settings saved.',
		'%d γραμμές αποτυπωμάτων αγνοήθηκαν: θέλουν 32 ζεύγη δεκαεξαδικών, π.χ. AB:CD:…' => '%d fingerprint lines were ignored: they need 32 hex pairs, e.g. AB:CD:…',
		'Το πακέτο δεν είναι έγκυρο όνομα Android· έμεινε το προεπιλεγμένο.' => 'The package is not a valid Android name; the default was kept.',
		'Made with ❤ by %s'                                => 'Made with ❤ by %s',
		'More plugins at'                                  => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                   => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                               => 'Noxpress Dashboard',

		// Notifications.
		'Οι ειδοποιήσεις φτάνουν σε αυτή τη συσκευή.'      => 'Notifications reach this device.',
		'Είσαι πλέον offline'                              => 'You are now offline',
		'Τελείωσε ο χρόνος online που είχες ορίσει. Άνοιξε την εφαρμογή για να γίνεις ξανά online.' => 'The online time you set is over. Open the app to go online again.',
		'Νέο αίτημα για συνομιλία'                         => 'New chat request',
		'Μήνυμα από %s'                                    => 'Message from %s',
		'Νέο μήνυμα στη συνομιλία.'                        => 'New message in the conversation.',
	);

	public static function init(): void {
		add_filter( 'gettext_live-operator', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** The dictionary, for the release check script. */
	public static function dict(): array {
		return self::$dict;
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

	/** gettext_live-operator filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'live-operator' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
