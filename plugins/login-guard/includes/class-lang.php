<?php
/**
 * LGD_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is read (Bible §9): 'el' | 'en' | ''. Without
 * Revenue Splitter the settings page shows the suite's language switch and
 * writes the same rs_lang (Bible §9, WordPress-only plugins). Without an
 * explicit choice the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English. Visitor texts are store
 * settings (LGD_Settings::texts(), Bible §14.17), not msgids.
 *
 * force() switches the language for an e-mail to another user and back.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release).
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	/** Forced language (e-mails), or null. */
	private static $forced = null;

	private static $dict = array(
		'Σύνδεση διαχειριστή από νέα συσκευή στο %s' => 'Administrator login from a new device on %s',
		'Ο λογαριασμός %1$s συνδέθηκε στο %2$s από συσκευή που δεν είχε χρησιμοποιήσει τις τελευταίες 30 ημέρες.' => 'The account %1$s logged in to %2$s from a device it had not used in the last 30 days.',
		'Ώρα: %s' => 'Time: %s',
		'Browser και σύστημα: %1$s σε %2$s' => 'Browser and system: %1$s on %2$s',
		'Αν ήσουν εσύ, δεν χρειάζεται να κάνεις τίποτα.' => 'If this was you, there is nothing to do.',
		'Αν δεν ήσουν εσύ, άλλαξε αμέσως τον κωδικό σου και αποσύνδεσε όλες τις άλλες συσκευές από το προφίλ σου:' => 'If it was not you, change your password now and log out all other devices from your profile:',
		'Επίθεση σε συνδέσεις στο %s' => 'Login attack on %s',
		'Το Login Guard κλείδωσε %1$d διευθύνσεις ή λογαριασμούς την τελευταία ώρα στο %2$s. Οι επιθέσεις σταματούν μόνες τους στο όριο· δεν χρειάζεται κάτι από σένα, εκτός αν βλέπεις και κλειδωμένους δικούς σου λογαριασμούς.' => 'Login Guard locked %1$d addresses or accounts in the last hour on %2$s. Attacks stop by themselves at the limit; nothing is needed from you, unless you also see your own accounts locked.',
		'Επισκόπηση και ημερολόγιο:' => 'Overview and log:',
		'Αυτό το email στέλνεται το πολύ μία φορά την ώρα. Το κλείνεις στις ρυθμίσεις του Login Guard.' => 'This e-mail is sent at most once an hour. You can turn it off in the Login Guard settings.',
		'Κλείδωμα IP' => 'IP lockout',
		'Μακρύ κλείδωμα IP' => 'Long IP lockout',
		'Κλείδωμα λογαριασμού' => 'Account lockout',
		'Δοκιμή: θα κλείδωνε IP' => 'Test: would lock IP',
		'Δοκιμή: θα κλείδωνε λογαριασμό' => 'Test: would lock account',
		'Ξεκλείδωμα' => 'Unlock',
		'Στάλθηκε σύνδεσμος ξεκλειδώματος' => 'Unlock link sent',
		'Όριο αιτημάτων επαναφοράς κωδικού' => 'Lost-password request limit',
		'Όριο αιτημάτων ξεκλειδώματος' => 'Unlock request limit',
		'XML-RPC κλειστό' => 'XML-RPC closed',
		'Διαχειριστής από νέα συσκευή' => 'Administrator from a new device',
		'Email για κύμα επιθέσεων' => 'Attack surge e-mail',
		'Επισκόπηση' => 'Overview',
		'Ρυθμίσεις' => 'Settings',
		'Ημερολόγιο' => 'Log',
		'Εργαλεία' => 'Tools',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Όριο αποτυχημένων συνδέσεων ανά IP και ανά λογαριασμό, κλείσιμο των συνδέσεων μέσω XML-RPC και προστασία από απαρίθμηση χρηστών. Ό,τι γράφεται κρατά μόνο hash ή κομμένη IP, για 30 ημέρες.' => 'Limits on failed logins per IP and per account, XML-RPC logins switched off and protection against user enumeration. Whatever is written keeps only a hash or a truncated IP, for 30 days.',
		'Το LGD_DISABLE είναι ενεργό στο wp-config.php: τίποτα δεν μετριέται και κανείς δεν κλειδώνεται. Σβήσε τη γραμμή όταν τελειώσεις.' => 'LGD_DISABLE is on in wp-config.php: nothing is counted and nobody is locked out. Remove the line when you are done.',
		'Το Login Guard είναι κλειστό: τίποτα δεν μετριέται ή κλειδώνεται. Ξεκίνα με «Δοκιμή» στις Ρυθμίσεις.' => 'Login Guard is off: nothing is counted or locked. Start with «Test» in Settings.',
		'Λειτουργία δοκιμής: οι αποτυχίες μετριούνται και το ημερολόγιο γράφει τι θα κλείδωνε, αλλά κανείς δεν κλειδώνεται. XML-RPC και απαρίθμηση αλλάζουν μόνο στο «Ενεργό».' => 'Test mode: failures are counted and the log shows what would be locked, but nobody is locked out. XML-RPC and enumeration change only in «Live».',
		'Το wp-config.php δεν έχει δικά του κλειδιά (salts). Τα hashes και οι σύνδεσμοι ξεκλειδώματος είναι πιο αδύναμα. Πρόσθεσε κλειδιά από το api.wordpress.org/secret-key/1.1/salt/.' => 'wp-config.php has no keys of its own (salts). Hashes and unlock links are weaker. Add keys from api.wordpress.org/secret-key/1.1/salt/.',
		'Κλειστό' => 'Off',
		'Δοκιμή' => 'Test',
		'Ενεργό' => 'Live',
		'Κατάσταση' => 'Status',
		'αποτυχημένες συνδέσεις, 24 ώρες' => 'failed logins, 24 hours',
		'αποτυχημένες συνδέσεις, 30 ημέρες' => 'failed logins, 30 days',
		'κλειδώματα, 24 ώρες' => 'lockouts, 24 hours',
		'κλειδώματα, 30 ημέρες' => 'lockouts, 30 days',
		'Στη δοκιμή θα είχαν γίνει %d κλειδώματα τις τελευταίες 30 ημέρες.' => 'In test mode there would have been %d lockouts in the last 30 days.',
		'Ενεργά κλειδώματα' => 'Active lockouts',
		'Κανένα κλείδωμα αυτή τη στιγμή.' => 'No lockouts right now.',
		'Τι' => 'What',
		'Ποιος' => 'Who',
		'Ως' => 'Until',
		'Λογαριασμός' => 'Account',
		'όνομα χωρίς λογαριασμό' => 'name without an account',
		'Ξεκλείδωμα όλων' => 'Unlock all',
		'Η διεύθυνσή σου' => 'Your address',
		'Άγνωστη' => 'Unknown',
		'Ο server δεν δίνει διεύθυνση IP: τα όρια ανά IP δεν δουλεύουν, μόνο τα όρια ανά λογαριασμό.' => 'The server gives no IP address: the per-IP limits do not work, only the per-account limits.',
		'Έμπιστη' => 'Trusted',
		'Πρόσθεσέ τη στις έμπιστες διευθύνσεις' => 'Add it to the trusted addresses',
		'Ο server βλέπει ιδιωτική διεύθυνση για αυτό το αίτημα, άρα το site είναι πιθανώς πίσω από reverse proxy που δεν είναι η Cloudflare. Τότε όλοι οι επισκέπτες φαίνονται με την ίδια IP και ένα κλείδωμα IP κλειδώνει όλους. Βάλε τη διεύθυνση του proxy στις έμπιστες ή ζήτησε από τον host να δίνει την πραγματική IP στο REMOTE_ADDR.' => 'The server sees a private address for this request, so the site is probably behind a reverse proxy that is not Cloudflare. Then every visitor shows the same IP and one IP lockout locks everybody out. Add the proxy\'s address to the trusted ones or ask your host to pass the real IP in REMOTE_ADDR.',
		'Η διεύθυνση από την οποία αποθηκεύεις τις ρυθμίσεις δεν κλειδώνεται για 24 ώρες, ώστε να μην κλειδωθείς έξω μόνος σου.' => 'The address you save the settings from is not locked for 24 hours, so you cannot lock yourself out.',
		'Λειτουργία' => 'Mode',
		'Κλειστό: τίποτα δεν μετριέται ή κλειδώνεται' => 'Off: nothing is counted or locked',
		'Δοκιμή: μετρά και γράφει τι θα κλείδωνε, χωρίς να κλειδώνει κανέναν' => 'Test: counts and logs what would be locked, without locking anybody',
		'Ενεργό: όρια, XML-RPC και απαρίθμηση ισχύουν για όλους' => 'Live: limits, XML-RPC and enumeration apply to everybody',
		'Όρια συνδέσεων' => 'Login limits',
		'Ανά IP' => 'Per IP',
		'Αποτυχίες (2–50)' => 'Failures (2–50)',
		'μέσα σε λεπτά (1–1440)' => 'within minutes (1–1440)',
		'κλείδωμα σε λεπτά (1–1440)' => 'lockout in minutes (1–1440)',
		'Μακρύ κλείδωμα μετά από κλειδώματα σε 24 ώρες (2–20)' => 'Long lockout after lockouts in 24 hours (2–20)',
		'διάρκεια σε ώρες (1–168)' => 'length in hours (1–168)',
		'Ανά λογαριασμό, από όλες τις IP' => 'Per account, from all IPs',
		'Αποτυχίες (5–500)' => 'Failures (5–500)',
		'μέσα σε λεπτά (5–1440)' => 'within minutes (5–1440)',
		'Σύνδεσμος ξεκλειδώματος με email για κλειδωμένο λογαριασμό (δεν παρακάμπτει τον κωδικό)' => 'Unlock link by e-mail for a locked account (does not skip the password)',
		'Μετρούν οι φόρμες σύνδεσης του WordPress και του WooCommerce, το XML-RPC και τα Application Passwords. Μια κλειδωμένη IP ή ένας κλειδωμένος λογαριασμός παίρνει το ίδιο μήνυμα είτε ο κωδικός είναι σωστός είτε όχι.' => 'The WordPress and WooCommerce login forms, XML-RPC and Application Passwords are counted. A locked IP or account gets the same message whether the password is right or not.',
		'Έμπιστες διευθύνσεις' => 'Trusted addresses',
		'Μία IP ή ένα εύρος CIDR ανά γραμμή (π.χ. 203.0.113.7 ή 203.0.113.0/24). Δεν μετριούνται και δεν κλειδώνουν ποτέ.' => 'One IP or CIDR range per line (e.g. 203.0.113.7 or 203.0.113.0/24). They are never counted or locked.',
		'Χωρίς συνδέσεις: καμία μέθοδος που θέλει κωδικό και κανένα system.multicall (προτείνεται)' => 'No logins: no method that needs a password and no system.multicall (recommended)',
		'Κλειστό εντελώς: το xmlrpc.php απαντά 403' => 'Fully closed: xmlrpc.php answers 403',
		'Όπως το WordPress' => 'As WordPress has it',
		'Χωρίς pingbacks (και χωρίς το header X-Pingback)' => 'No pingbacks (and no X-Pingback header)',
		'Το Jetpack είναι ενεργό και χρειάζεται το XML-RPC με συνδέσεις: διάλεξε «Όπως το WordPress».' => 'Jetpack is active and needs XML-RPC with logins: choose «As WordPress has it».',
		'Απαρίθμηση χρηστών' => 'User enumeration',
		'Ουδέτερα μηνύματα: ίδιο μήνυμα για λάθος όνομα και λάθος κωδικό, ίδια σελίδα στο «Ξέχασα τον κωδικό» είτε υπάρχει λογαριασμός είτε όχι' => 'Neutral messages: the same message for a wrong name and a wrong password, the same page on «Lost password» whether the account exists or not',
		'Όριο στο «Ξέχασα τον κωδικό»: 5 αιτήματα την ώρα ανά IP και 3 ανά λογαριασμό' => 'Limit on «Lost password»: 5 requests an hour per IP and 3 per account',
		'Το /wp-json/wp/v2/users μόνο για συνδεδεμένους χρήστες' => '/wp-json/wp/v2/users only for logged-in users',
		'Το /?author=1 απαντά 404 στους επισκέπτες' => '/?author=1 answers 404 to visitors',
		'Χωρίς χρήστες στο wp-sitemap.xml' => 'No users in wp-sitemap.xml',
		'Χωρίς όνομα συγγραφέα στις απαντήσεις oEmbed' => 'No author name in oEmbed answers',
		'Οι σελίδες /author/όνομα/ απαντούν 404 στους επισκέπτες (όχι σε blog με συγγραφείς)' => '/author/name/ pages answer 404 to visitors (not for blogs with authors)',
		'Ειδοποιήσεις' => 'Notifications',
		'Email στον διαχειριστή όταν συνδέεται από συσκευή που δεν είχε χρησιμοποιήσει 30 ημέρες' => 'E-mail to an administrator who logs in from a device not used in 30 days',
		'Email στη διεύθυνση του site σε κύμα επιθέσεων, το πολύ ένα την ώρα' => 'E-mail to the site address on an attack surge, at most one an hour',
		'Κλειδώματα σε μία ώρα για το email (3–500)' => 'Lockouts in one hour for the e-mail (3–500)',
		'Κείμενα για τους επισκέπτες' => 'Texts for visitors',
		'Γλώσσα: αυτόματα, από τη γλώσσα του site' => 'Language: automatic, from the site\'s language',
		'Τα {minutes}, {site} και {link} συμπληρώνονται μόνα τους. Κενό αγγλικό κείμενο δείχνει το ελληνικό.' => '{minutes}, {site} and {link} are filled in automatically. An empty English text shows the Greek one.',
		'Κείμενο' => 'Text',
		'Γλώσσα' => 'Language',
		'Όπως η γλώσσα του λογαριασμού' => 'Same as the account\'s language',
		'Ισχύει για όλα τα plugins της σουίτας Noxpress.' => 'Applies to all plugins of the Noxpress suite.',
		'Απεγκατάσταση' => 'Uninstall',
		'Να μείνουν οι ρυθμίσεις και το ημερολόγιο όταν σβήσω το plugin' => 'Keep the settings and the log when I delete the plugin',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Γεγονότα ασφαλείας' => 'Security events',
		'Τα 200 νεότερα: κλειδώματα, ξεκλειδώματα, όρια, email. Κρατιούνται 30 ημέρες, με κομμένη IP. Οι κανονικές συνδέσεις δεν γράφονται εδώ.' => 'The newest 200: lockouts, unlocks, limits, e-mails. Kept for 30 days, with a truncated IP. Normal logins are not written here.',
		'Κανένα γεγονός ακόμα.' => 'No events yet.',
		'Ώρα' => 'Time',
		'Γεγονός' => 'Event',
		'Λεπτομέρειες' => 'Details',
		'Καθαρισμός ημερολογίου' => 'Clear log',
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Επαναφορά' => 'Restore',
		'Η επαναφορά δεν αλλάζει τη λειτουργία: μένει όπως είναι τώρα, ώστε ένα backup να μην κλειδώσει κανέναν χωρίς δοκιμή.' => 'Restoring does not change the mode: it stays as it is now, so a backup cannot lock anybody out without a test.',
		'Αν κλειδωθείς έξω' => 'If you are locked out',
		'Περίμενε' => 'Wait',
		'Κάθε κλείδωμα λήγει μόνο του (προεπιλογή 15 λεπτά).' => 'Every lockout ends by itself (15 minutes by default).',
		'Σύνδεσμος με email' => 'Link by e-mail',
		'Για κλειδωμένο λογαριασμό, από τον σύνδεσμο στο μήνυμα της φόρμας σύνδεσης.' => 'For a locked account, from the link in the login form\'s message.',
		'Άλλος διαχειριστής' => 'Another administrator',
		'Ξεκλειδώνει από την Επισκόπηση.' => 'Unlocks from the Overview.',
		'Στο wp-config.php: το Login Guard σταματά εντελώς.' => 'In wp-config.php: Login Guard stops completely.',
		'WP-CLI: ξεκλειδώνει τα πάντα. Επίσης unlock --ip=…, unlock --user=…, mode off, status.' => 'WP-CLI: unlocks everything. Also unlock --ip=…, unlock --user=…, mode off, status.',
		'FTP ή File Manager' => 'FTP or File Manager',
		'Μετονόμασε τον φάκελο wp-content/plugins/login-guard: το plugin απενεργοποιείται.' => 'Rename the folder wp-content/plugins/login-guard: the plugin is deactivated.',
		'Για developers' => 'For developers',
		'Ένα κλείδωμα ξεκίνησε: ( $kind \'ip\' | \'user\', $ident_hash, $until ). Ποτέ IP ή όνομα.' => 'A lockout started: ( $kind \'ip\' | \'user\', $ident_hash, $until ). Never an IP or a name.',
		'Ένα κλείδωμα τελείωσε νωρίς: ( $kind, $ident_hash, $by \'admin\' | \'mail\' | \'cli\' ).' => 'A lockout ended early: ( $kind, $ident_hash, $by \'admin\' | \'mail\' | \'cli\' ).',
		'(φίλτρο)' => '(filter)',
		'( bool $ok, string $ip ): μια διεύθυνση που δεν μετριέται ποτέ.' => '( bool $ok, string $ip ): an address that is never counted.',
		'( $text, $key, $lang ): τα κείμενα των επισκεπτών, π.χ. για WPML.' => '( $text, $key, $lang ): the visitor texts, e.g. for WPML.',
		'Ability (WordPress 6.9+) για το Site Bridge, μόνο ανάγνωση, με το ίδιο δικαίωμα (manage_options). Ποτέ IP, όνομα ή email.' => 'Ability (WordPress 6.9+) for Site Bridge, read-only, with the same capability (manage_options). Never an IP, a name or an e-mail.',
		'Πολιτική απορρήτου' => 'Privacy policy',
		'Ένα προτεινόμενο κείμενο είναι στις Ρυθμίσεις → Απόρρητο → Οδηγός πολιτικής, στην ενότητα «Login Guard».' => 'A suggested text is in Settings → Privacy → Policy guide, in the «Login Guard» section.',
		'Ξεκλειδώθηκε.' => 'Unlocked.',
		'Το κλείδωμα είχε ήδη λήξει.' => 'The lockout had already ended.',
		'Η διεύθυνσή σου είναι τώρα έμπιστη.' => 'Your address is now trusted.',
		'Το ημερολόγιο καθάρισε.' => 'The log was cleared.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Ενεργό. Δοκίμασε μια σύνδεση από ιδιωτικό παράθυρο. Η δική σου διεύθυνση δεν κλειδώνεται για 24 ώρες.' => 'Live. Try a login from a private window. Your own address is not locked for 24 hours.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο.' => 'No file was chosen.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Login Guard).' => 'Invalid backup file (the Login Guard settings are missing).',
		'Οι ρυθμίσεις επανήλθαν. Η λειτουργία έμεινε όπως ήταν.' => 'Settings restored. The mode stayed as it was.',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Για να προστατεύσουμε τους λογαριασμούς από επιθέσεις, όταν μια σύνδεση αποτυγχάνει μετράμε τις προσπάθειες ανά διεύθυνση IP και ανά λογαριασμό για λίγα λεπτά, μόνο ως κρυπτογραφικό hash. Όταν μια διεύθυνση ή ένας λογαριασμός κλειδώνεται, κρατάμε για 30 ημέρες την ώρα, τη διεύθυνση IP χωρίς το τελευταίο της μέρος (π.χ. 203.0.113.x) και, αν υπάρχει, τον αριθμό του λογαριασμού. Για τους διαχειριστές κρατάμε 30 ημέρες ένα hash της συσκευής (τμήμα της IP, browser και λειτουργικό), ώστε να τους ειδοποιούμε με email όταν συνδέονται από νέα συσκευή. Τίποτα από αυτά δεν στέλνεται σε τρίτους. Νομική βάση: έννομο συμφέρον για την ασφάλεια του site.' => 'To protect accounts from attacks, when a login fails we count the attempts per IP address and per account for a few minutes, only as a cryptographic hash. When an address or an account is locked, we keep for 30 days the time, the IP address without its last part (e.g. 203.0.113.x) and, if there is one, the account number. For administrators we keep for 30 days a hash of the device (part of the IP, browser and system), so we can e-mail them when they log in from a new device. None of this is sent to third parties. Legal basis: legitimate interest in the security of the site.',
		'Τελείωσε %d κλείδωμα.' => '%d lockout ended.',
		'Τελείωσαν %d κλειδώματα.' => '%d lockouts ended.',
		'%d γραμμή στις έμπιστες διευθύνσεις δεν είναι έγκυρη IP ή εύρος (ή είναι ευρύτερο από /8) και αγνοήθηκε.' => '%d line in the trusted addresses is not a valid IP or range (or is wider than /8) and was ignored.',
		'%d γραμμές στις έμπιστες διευθύνσεις δεν είναι έγκυρες IP ή εύρη (ή είναι ευρύτερα από /8) και αγνοήθηκαν.' => '%d lines in the trusted addresses are not valid IPs or ranges (or are wider than /8) and were ignored.',
	);

	public static function init(): void {
		add_filter( 'gettext_login-guard', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_login-guard', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** True when Revenue Splitter (the writer of rs_lang) is active. */
	public static function rs_active(): bool {
		return class_exists( 'RS_Lang' ) || defined( 'RS_VERSION' );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' of a given user (rs_lang, else the user's locale). */
	public static function lang_of( WP_User $user ): string {
		$choice = (string) get_user_meta( (int) $user->ID, 'rs_lang', true );
		if ( 'el' === $choice || 'en' === $choice ) {
			return $choice;
		}
		$loc = (string) get_user_meta( (int) $user->ID, 'locale', true );
		return self::from_locale( '' !== $loc ? $loc : (string) get_locale() );
	}

	/** Forces a language ('' or null ends it); returns the previous one. */
	public static function force( ?string $lang ): ?string {
		$prev         = self::$forced;
		self::$forced = ( 'el' === $lang || 'en' === $lang ) ? $lang : null;
		return $prev;
	}

	public static function reset(): void {
		self::$lang = null;
	}

	/** 'el' | 'en' for the current admin user. */
	public static function lang(): string {
		if ( null !== self::$forced ) {
			return self::$forced;
		}
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		$uid    = function_exists( 'get_current_user_id' ) && did_action( 'set_current_user' ) ? get_current_user_id() : 0;
		$choice = $uid ? (string) get_user_meta( $uid, 'rs_lang', true ) : '';
		if ( 'el' === $choice || 'en' === $choice ) {
			$lang = $choice;
		} else {
			$locale = $uid && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_login-guard filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'login-guard' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_login-guard filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'login-guard' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
