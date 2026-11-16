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
		'Επαλήθευση σε δύο βήματα' => 'Two-step verification',
		'Οι κωδικοί ανάκτησης φαίνονται μόνο τώρα.' => 'The recovery codes are shown only now.',
		'Γράψε τους ή τύπωσέ τους και κράτησέ τους μακριά από τον υπολογιστή. Καθένας δουλεύει μία φορά, αν χάσεις το κινητό.' => 'Write them down or print them and keep them away from the computer. Each one works once, if you lose your phone.',
		'Κατάσταση' => 'Status',
		'Ενεργή' => 'On',
		'από %1$s, %2$d κωδικοί ανάκτησης απομένουν' => 'since %1$s, %2$d recovery codes left',
		'Κωδικοί ανάκτησης' => 'Recovery codes',
		'Νέοι κωδικοί ανάκτησης (οι παλιοί σταματούν να δουλεύουν)' => 'New recovery codes (the old ones stop working)',
		'Θυμημένοι browsers' => 'Remembered browsers',
		'Να ζητηθεί ξανά ο κωδικός σε όλους τους browsers' => 'Ask for the code again in every browser',
		'Απενεργοποίηση' => 'Turn off',
		'Ο ρόλος σου τη χρειάζεται, γι\' αυτό δεν κλείνει από εδώ.' => 'Your role requires it, so it cannot be turned off here.',
		'Για να την κλείσεις, γράψε έναν κωδικό από την εφαρμογή ή έναν κωδικό ανάκτησης και πάτα «Ενημέρωση προφίλ».' => 'To turn it off, enter a code from the app or a recovery code and press «Update Profile».',
		'Ανενεργή' => 'Off',
		'ο ρόλος σου τη χρειάζεται' => 'your role requires it',
		'1. Σάρωση' => '1. Scan',
		'Σάρωσε τον κωδικό QR με μια εφαρμογή επαλήθευσης (Aegis, 2FAS, Google Authenticator, Microsoft Authenticator, 1Password κ.ά.), ή γράψε στην εφαρμογή αυτό το κλειδί:' => 'Scan the QR code with an authenticator app (Aegis, 2FAS, Google Authenticator, Microsoft Authenticator, 1Password and others), or enter this key in the app:',
		'2. Επιβεβαίωση' => '2. Confirm',
		'Γράψε τον 6ψήφιο κωδικό που δείχνει τώρα η εφαρμογή και πάτα «Ενημέρωση προφίλ». Μετά θα δεις τους κωδικούς ανάκτησης.' => 'Enter the 6-digit code the app shows now and press «Update Profile». Then you will see your recovery codes.',
		'ο ρόλος τη χρειάζεται' => 'the role requires it',
		'Μηδενισμός' => 'Reset',
		'Μηδενισμός της επαλήθευσης σε δύο βήματα (ο χρήστης τη ρυθμίζει ξανά· του στέλνουμε email)' => 'Reset two-step verification (the user sets it up again; we send them an e-mail)',
		'Για να μηδενίσεις την επαλήθευση άλλου χρήστη, ενεργοποίησε πρώτα τη δική σου.' => 'To reset another user\'s verification, turn on your own first.',
		'Το LGD_DISABLE_2FA είναι ενεργό στο wp-config.php: η επαλήθευση σε δύο βήματα δεν ζητείται.' => 'LGD_DISABLE_2FA is on in wp-config.php: two-step verification is not asked.',
		'Η επαλήθευση σε δύο βήματα χρειάζεται δικά σου κλειδιά (salts) στο wp-config.php και OpenSSL, για να κρατιούνται τα μυστικά κρυπτογραφημένα.' => 'Two-step verification needs keys of your own (salts) in wp-config.php and OpenSSL, so the secrets are kept encrypted.',
		'Η επαλήθευση σε δύο βήματα είναι κλειστή στις ρυθμίσεις του Login Guard.' => 'Two-step verification is off in the Login Guard settings.',
		'Ο κωδικός της εφαρμογής δεν ταιριάζει. Έλεγξε ότι η ώρα του κινητού είναι σωστή και γράψε τον νέο κωδικό.' => 'The code from the app does not match. Check that the phone\'s time is right and enter the new code.',
		'Η επαλήθευση σε δύο βήματα δεν ενεργοποιήθηκε: η κρυπτογράφηση απέτυχε.' => 'Two-step verification was not turned on: encryption failed.',
		'Η επαλήθευση σε δύο βήματα ενεργοποιήθηκε.' => 'Two-step verification is on.',
		'Ο κωδικός για την απενεργοποίηση δεν ταιριάζει.' => 'The code to turn it off does not match.',
		'Η επαλήθευση σε δύο βήματα απενεργοποιήθηκε.' => 'Two-step verification is off.',
		'Νέοι κωδικοί ανάκτησης. Οι παλιοί δεν δουλεύουν πια.' => 'New recovery codes. The old ones no longer work.',
		'Ο κωδικός θα ζητηθεί ξανά σε όλους τους browsers.' => 'The code will be asked again in every browser.',
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
		'2FA ενεργοποιήθηκε' => '2FA turned on',
		'2FA απενεργοποιήθηκε' => '2FA turned off',
		'2FA μηδενίστηκε' => '2FA reset',
		'Νέοι κωδικοί ανάκτησης' => 'New recovery codes',
		'Λάθος κωδικός 2FA' => 'Wrong 2FA code',
		'Σύνδεση με κωδικό ανάκτησης' => 'Login with a recovery code',
		'Ο φύλακας έκλεισε session χωρίς 2FA' => 'Guard ended a session without 2FA',
		'Δοκιμή: ο φύλακας θα έκλεινε session' => 'Test: guard would end a session',
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
		'Ο server βλέπει ιδιωτική διεύθυνση για αυτό το αίτημα, άρα το site είναι πιθανώς πίσω από reverse proxy που δεν είναι η Cloudflare. Τότε όλοι οι επισκέπτες φαίνονται με την ίδια IP και ένα κλείδωμα IP κλειδώνει όλους. Ζήτησε από τον host να δίνει την πραγματική IP στο REMOTE_ADDR· μην τη βάλεις στις έμπιστες, γιατί θα ήταν έμπιστος κάθε επισκέπτης.' => 'The server sees a private address for this request, so the site is probably behind a reverse proxy that is not Cloudflare. Then every visitor has the same IP, and one IP lockout locks everyone. Ask your host to pass the real IP in REMOTE_ADDR; do not add this address to the trusted list, because every visitor would be trusted.',
		'Η διεύθυνση από την οποία αποθηκεύεις τις ρυθμίσεις δεν κλειδώνεται για 24 ώρες, ώστε να μην κλειδωθείς έξω μόνος σου.' => 'The address you save the settings from is not locked for 24 hours, so you cannot lock yourself out.',
		'χρήστες με 2FA' => 'users with 2FA',
		'τη χρειάζονται και δεν την έχουν' => 'need it and do not have it',
		'Κάθε χρήστης τη ρυθμίζει στο προφίλ του, με μια εφαρμογή επαλήθευσης και 10 κωδικούς ανάκτησης.' => 'Each user sets it up in their profile, with an authenticator app and 10 recovery codes.',
		'Το προφίλ μου' => 'My profile',
		'Κανένας χρήστης δεν έχει Application Password.' => 'No user has an Application Password.',
		'Πλήθος' => 'Count',
		'Τελευταία χρήση' => 'Last used',
		'ποτέ' => 'never',
		'Τα Application Passwords συνδέονται χωρίς δεύτερο βήμα, από τον σχεδιασμό του WordPress (τα χρησιμοποιούν orOS, Trade Feed Import και άλλα). Ανακαλείς όσα δεν χρειάζεσαι από το προφίλ του χρήστη.' => 'Application Passwords log in without a second step, by WordPress\'s design (orOS, Trade Feed Import and others use them). Revoke the ones you do not need from the user\'s profile.',
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
		'Επαλήθευση σε δύο βήματα με εφαρμογή (TOTP) για όσους τη ρυθμίσουν στο προφίλ τους' => 'Two-step verification with an app (TOTP) for everyone who sets it up in their profile',
		'Υποχρεωτική για τους ρόλους (μόνο στο «Ενεργό»)' => 'Required for the roles (only in «Live»)',
		'Ένας ρόλος που έχεις κι εσύ γίνεται υποχρεωτικός μόνο αφού ρυθμίσεις τη δική σου επαλήθευση, ώστε να μην κλειδωθείς έξω. Οι πελάτες δεν τη χρειάζονται.' => 'A role you have yourself becomes required only after you set up your own verification, so you cannot lock yourself out. Customers do not need it.',
		'Περίοδος χάριτος σε ημέρες (0–30)' => 'Grace period in days (0–30)',
		'ή σε συνδέσεις (0–20)' => 'or in logins (0–20)',
		'«Να μη ζητηθεί ξανά σε αυτόν τον browser» για ημέρες (0 = ποτέ, 0–90)' => '«Do not ask again in this browser» for days (0 = never, 0–90)',
		'Αυστηρός φύλακας: κλείνει κάθε σύνδεση χρήστη με 2FA που δεν πέρασε από το δεύτερο βήμα (π.χ. «σύνδεση ως» άλλων plugins). Εκτός WP-CLI και cron.' => 'Strict guard: ends every session of a user with 2FA that did not pass the second step (e.g. other plugins\' «log in as»). Not for WP-CLI and cron.',
		'Ειδοποιήσεις' => 'Notifications',
		'Email στον διαχειριστή όταν συνδέεται από συσκευή που δεν είχε χρησιμοποιήσει 30 ημέρες' => 'E-mail to an administrator who logs in from a device not used in 30 days',
		'Email στη διεύθυνση του site σε κύμα επιθέσεων, το πολύ ένα την ώρα' => 'E-mail to the site address on an attack surge, at most one an hour',
		'Κλειδώματα σε μία ώρα για το email (3–500)' => 'Lockouts in one hour for the e-mail (3–500)',
		'Κείμενα για τους επισκέπτες' => 'Texts for visitors',
		'Γλώσσα: αυτόματα, από τη γλώσσα του site' => 'Language: automatic, from the site\'s language',
		'Τα {minutes}, {days}, {site} και {link} συμπληρώνονται μόνα τους. Κενό αγγλικό κείμενο δείχνει το ελληνικό.' => '{minutes}, {days}, {site} and {link} are filled in automatically. An empty English text shows the Greek one.',
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
		'Κωδικός ανάκτησης' => 'Recovery code',
		'Χωρίς το κινητό: ένας από τους 10 κωδικούς ανάκτησης, στο ίδιο πεδίο με τον κωδικό της εφαρμογής.' => 'Without the phone: one of the 10 recovery codes, in the same field as the app\'s code.',
		'Άλλος διαχειριστής με 2FA' => 'Another administrator with 2FA',
		'Μηδενίζει την επαλήθευση από το προφίλ του χρήστη.' => 'Resets the verification from the user\'s profile.',
		'Στο wp-config.php: σταματά μόνο η επαλήθευση σε δύο βήματα· τα όρια συνεχίζουν.' => 'In wp-config.php: only two-step verification stops; the limits go on.',
		'WP-CLI: μηδενίζει την επαλήθευση ενός χρήστη.' => 'WP-CLI: resets one user\'s verification.',
		'FTP ή File Manager' => 'FTP or File Manager',
		'Μετονόμασε τον φάκελο wp-content/plugins/login-guard: το plugin απενεργοποιείται.' => 'Rename the folder wp-content/plugins/login-guard: the plugin is deactivated.',
		'Για developers' => 'For developers',
		'Ένα κλείδωμα ξεκίνησε: ( $kind \'ip\' | \'user\', $ident_hash, $until ). Ποτέ IP ή όνομα.' => 'A lockout started: ( $kind \'ip\' | \'user\', $ident_hash, $until ). Never an IP or a name.',
		'Ένα κλείδωμα τελείωσε νωρίς: ( $kind, $ident_hash, $by \'admin\' | \'mail\' | \'cli\' ).' => 'A lockout ended early: ( $kind, $ident_hash, $by \'admin\' | \'mail\' | \'cli\' ).',
		'( int $user_id ): το δεύτερο βήμα πέρασε, ένας λάθος κωδικός, ο φύλακας έκλεισε ένα session.' => '( int $user_id ): the second step passed, a wrong code, the guard ended a session.',
		'(φίλτρο)' => '(filter)',
		'( bool $required, WP_User $user ): κάνει υποχρεωτική την επαλήθευση για έναν χρήστη. Μόνο προσθέτει, ποτέ δεν αφαιρεί.' => '( bool $required, WP_User $user ): makes the verification required for a user. It only adds, never removes.',
		'( bool $ok, string $ip ): μια διεύθυνση που δεν μετριέται ποτέ.' => '( bool $ok, string $ip ): an address that is never counted.',
		'( $text, $key, $lang ): τα κείμενα των επισκεπτών, π.χ. για WPML.' => '( $text, $key, $lang ): the visitor texts, e.g. for WPML.',
		'Ability (WordPress 6.9+) για το Site Bridge, μόνο ανάγνωση, με το ίδιο δικαίωμα (manage_options). Ποτέ IP, όνομα ή email.' => 'Ability (WordPress 6.9+) for Site Bridge, read-only, with the same capability (manage_options). Never an IP, a name or an e-mail.',
		'Πολιτική απορρήτου' => 'Privacy policy',
		'Ένα προτεινόμενο κείμενο είναι στις Ρυθμίσεις → Απόρρητο → Οδηγός πολιτικής, στην ενότητα «Login Guard».' => 'A suggested text is in Settings → Privacy → Policy guide, in the «Login Guard» section.',
		'Ξεκλειδώθηκε.' => 'Unlocked.',
		'Το κλείδωμα είχε ήδη λήξει.' => 'The lockout had already ended.',
		'Τελείωσε %d κλείδωμα.' => '%d lockout ended.',
		'Τελείωσαν %d κλειδώματα.' => '%d lockouts ended.',
		'Η διεύθυνσή σου είναι τώρα έμπιστη.' => 'Your address is now trusted.',
		'Το ημερολόγιο καθάρισε.' => 'The log was cleared.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'%d γραμμή στις έμπιστες διευθύνσεις δεν είναι έγκυρη IP ή εύρος (ή είναι ευρύτερο από /8) και αγνοήθηκε.' => '%d line in the trusted addresses is not a valid IP or range (or is wider than /8) and was ignored.',
		'%d γραμμές στις έμπιστες διευθύνσεις δεν είναι έγκυρες IP ή εύρη (ή είναι ευρύτερα από /8) και αγνοήθηκαν.' => '%d lines in the trusted addresses are not valid IPs or ranges (or are wider than /8) and were ignored.',
		'Ο ρόλος σου δεν έγινε υποχρεωτικός για επαλήθευση σε δύο βήματα: ρύθμισε πρώτα τη δική σου στο προφίλ και αποθήκευσε ξανά.' => 'Your role was not made required for two-step verification: set up your own in your profile first and save again.',
		'Ενεργό. Δοκίμασε μια σύνδεση από ιδιωτικό παράθυρο. Η δική σου διεύθυνση δεν κλειδώνεται για 24 ώρες.' => 'Live. Try a login from a private window. Your own address is not locked for 24 hours.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο.' => 'No file was chosen.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Login Guard).' => 'Invalid backup file (the Login Guard settings are missing).',
		'Οι ρυθμίσεις επανήλθαν. Η λειτουργία έμεινε όπως ήταν.' => 'Settings restored. The mode stayed as it was.',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Ο ρόλος σου χρειάζεται επαλήθευση σε δύο βήματα. Ρύθμισέ την εδώ για να συνεχίσεις στο admin.' => 'Your role requires two-step verification. Set it up here to go on in the admin.',
		'Ο ρόλος σου χρειάζεται επαλήθευση σε δύο βήματα. Ρύθμισέ την στο προφίλ σου πριν λήξει η περίοδος χάριτος.' => 'Your role requires two-step verification. Set it up in your profile before the grace period ends.',
		'Ρύθμιση τώρα' => 'Set up now',
		'Επαλήθευση σε δύο βήματα στο %s' => 'Two-step verification on %s',
		'Η επαλήθευση σε δύο βήματα ενεργοποιήθηκε για τον λογαριασμό %1$s στο %2$s.' => 'Two-step verification was turned on for the account %1$s on %2$s.',
		'Η επαλήθευση σε δύο βήματα απενεργοποιήθηκε για τον λογαριασμό %1$s στο %2$s.' => 'Two-step verification was turned off for the account %1$s on %2$s.',
		'Ένας διαχειριστής μηδένισε την επαλήθευση σε δύο βήματα του λογαριασμού %1$s στο %2$s. Ρύθμισέ την ξανά από το προφίλ σου.' => 'An administrator reset two-step verification for the account %1$s on %2$s. Set it up again from your profile.',
		'Στη σύνδεση του λογαριασμού %1$s στο %2$s χρησιμοποιήθηκε ένας κωδικός ανάκτησης. Απομένουν %3$d.' => 'A recovery code was used to log in to the account %1$s on %2$s. %3$d are left.',
		'Για να προστατεύσουμε τους λογαριασμούς από επιθέσεις, όταν μια σύνδεση αποτυγχάνει μετράμε τις προσπάθειες ανά διεύθυνση IP και ανά λογαριασμό για λίγα λεπτά, μόνο ως κρυπτογραφικό hash. Όταν μια διεύθυνση ή ένας λογαριασμός κλειδώνεται, κρατάμε για 30 ημέρες την ώρα, τη διεύθυνση IP χωρίς το τελευταίο της μέρος (π.χ. 203.0.113.x) και, αν υπάρχει, τον αριθμό του λογαριασμού. Για τους διαχειριστές κρατάμε 30 ημέρες ένα hash της συσκευής (τμήμα της IP, browser και λειτουργικό), ώστε να τους ειδοποιούμε με email όταν συνδέονται από νέα συσκευή. Τίποτα από αυτά δεν στέλνεται σε τρίτους. Νομική βάση: έννομο συμφέρον για την ασφάλεια του site.' => 'To protect accounts from attacks, when a login fails we count the attempts per IP address and per account for a few minutes, only as a cryptographic hash. When an address or an account is locked, we keep for 30 days the time, the IP address without its last part (e.g. 203.0.113.x) and, if there is one, the account number. For administrators we keep for 30 days a hash of the device (part of the IP, browser and system), so we can e-mail them when they log in from a new device. None of this is sent to third parties. Legal basis: legitimate interest in the security of the site.',
		'Για όσους ενεργοποιούν την επαλήθευση σε δύο βήματα κρατάμε το μυστικό κλειδί της εφαρμογής κρυπτογραφημένο και τους κωδικούς ανάκτησης μόνο ως hash, μέχρι να την απενεργοποιήσουν. Αν επιλέξουν «να μη ζητηθεί ξανά σε αυτόν τον browser», ο browser κρατά ένα cookie με υπογραφή για όσες ημέρες ορίζει ο διαχειριστής.' => 'For users who turn on two-step verification we keep the app\'s secret key encrypted and the recovery codes only as a hash, until they turn it off. If they choose «do not ask again in this browser», the browser keeps a signed cookie for as many days as the administrator sets.',
		'Για νέους κωδικούς ανάκτησης, γράψε έναν κωδικό από την εφαρμογή ή έναν κωδικό ανάκτησης.' => 'For new recovery codes, enter a code from the app or a recovery code.',
		'Κωδικός από την εφαρμογή ή κωδικός ανάκτησης' => 'Code from the app or recovery code',
		'Γράψε έναν κωδικό από την εφαρμογή ή έναν κωδικό ανάκτησης.' => 'Enter a code from the app or a recovery code.',
		'Έγιναν νέοι κωδικοί ανάκτησης για τον λογαριασμό %1$s στο %2$s. Οι παλιοί δεν δουλεύουν πια.' => 'New recovery codes were made for the account %1$s on %2$s. The old ones no longer work.',
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
