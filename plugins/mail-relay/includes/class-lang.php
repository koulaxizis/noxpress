<?php
/**
 * MRL_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Visitors never see a text of this plugin.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'%1$s: %2$s, αποστολέας %3$s' => '%1$s: %2$s, sender %3$s',
		'%d συνημμένα' => '%d attachments',
		'%d συνημμένο' => '%d attachment',
		'( bool $sensitive, array $atts ): true = το email γράφεται χωρίς περιεχόμενο και δεν ξαναστέλνεται. Για email με links σύνδεσης ή κωδικούς.' => '( bool $sensitive, array $atts ): true = the email is logged without content and is not resent. For emails with sign-in links or codes.',
		'( string $source, string $file ): η πηγή που γράφεται στο αρχείο.' => '( string $source, string $file ): the source written to the log.',
		'(φίλτρο)' => '(filter)',
		'Abilities (WordPress 6.9+) για το Site Bridge, μόνο ανάγνωση, με manage_options· παραλήπτες μασκαρισμένοι, ποτέ περιεχόμενο.' => 'Abilities (WordPress 6.9+) for Site Bridge, read only, with manage_options; recipients masked, never content.',
		'Domain αποστολέα: %s (μόνο ανάγνωση του DNS, τίποτα δεν αλλάζει).' => 'Sender domain: %s (DNS read only, nothing changes).',
		'Domain αποστολής' => 'Sending domain',
		'Email αποστολέα (From)' => 'Sender email (From)',
		'Email που στάλθηκαν' => 'Emails sent',
		'Email σύνοψης όταν αποτυγχάνουν αποστολές (το πολύ ένα την ώρα)' => 'Summary email when sending fails (at most one an hour)',
		'Email του hosting (mail.%s)' => 'Hosting email (mail.%s)',
		'Gmail / Google Workspace (κωδικός εφαρμογής)' => 'Gmail / Google Workspace (app password)',
		'Mail Relay: αποτυχία αποστολής στο %s' => 'Mail Relay: sending failed on %s',
		'Mail Relay: αρχείο αποστολών' => 'Mail Relay: sent log',
		'mail() του server' => 'server mail()',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Άγνωστο' => 'Unknown',
		'Άδειασμα αρχείου' => 'Empty the log',
		'Άλλαξε: αποστολή σε παύση' => 'Changed: sending held',
		'Άλλο plugin έχει αντικαταστήσει τη συνάρτηση wp_mail() (%s).' => 'Another plugin has replaced the wp_mail() function (%s).',
		'Άλλο plugin έχει αντικαταστήσει τη συνάρτηση wp_mail(), οπότε το Mail Relay δεν στέλνει. Απενεργοποίησε το άλλο plugin email.' => 'Another plugin has replaced the wp_mail() function, so Mail Relay does not send. Deactivate the other mail plugin.',
		'Άλλος SMTP server' => 'Another SMTP server',
		'Άνοιγμα' => 'Open',
		'Έλεγχος ξανά' => 'Check again',
		'Έτοιμη ρύθμιση' => 'Ready setup',
		'Όλα' => 'All',
		'Όλα τα email του site μέσω του λογαριασμού που ορίζεις (SMTP ή API παρόχου), με εφεδρική σύνδεση και αρχείο αποστολών. Οι κωδικοί δεν εμφανίζονται ποτέ και δεν μπαίνουν σε αρχεία ή αντίγραφα ρυθμίσεων.' => 'Every email of the site through the account you choose (SMTP or a provider\'s API), with a backup connection and a sent log. Passwords are never shown and never go into files or settings backups.',
		'Όλα τα email του site φεύγουν από τη σύνδεση που ορίζεις εδώ.' => 'Every email of the site leaves through the connection you set here.',
		'Όνομα αποστολέα' => 'Sender name',
		'Όταν η διεύθυνση του site αλλάζει (αντίγραφο σε staging, μεταφορά με το Data Migrator), η αποστολή παγώνει, ώστε ένα δοκιμαστικό site να μη στείλει ποτέ email σε πραγματικούς πελάτες. Τα email γράφονται στο αρχείο ως «Σε παύση».' => 'When the address of the site changes (a staging copy, a move with Data Migrator), sending pauses, so a test site never emails real customers. The emails are logged as «Held».',
		'Όταν η κύρια σύνδεση αποτυγχάνει, το ίδιο email δοκιμάζεται αμέσως από εδώ' => 'When the primary connection fails, the same email is tried at once from here',
		'Αντίγραφο ρυθμίσεων' => 'Settings backup',
		'Απέτυχε' => 'Failed',
		'Απεγκατάσταση' => 'Uninstall',
		'Απενεργοποίησε πρώτα: %s. Δύο plugins email μαζί στέλνουν από λάθος λογαριασμό ή δύο φορές.' => 'First deactivate: %s. Two mail plugins together send from the wrong account or twice.',
		'Απενεργοποίηση (το WordPress στέλνει όπως χωρίς το plugin)' => 'Turn off (WordPress sends as without the plugin)',
		'Αποθήκευση' => 'Save',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Αποθήκευση σύνδεσης' => 'Save connection',
		'Αποστολέας' => 'Sender',
		'Αποστολή δοκιμής' => 'Send test',
		'Αποστολή μέσω Mail Relay' => 'Sending through Mail Relay',
		'Αποτυχίες τις τελευταίες 24 ώρες: %d' => 'Failures in the last 24 hours: %d',
		'Από τώρα όλα τα email του site θα φεύγουν από αυτή τη σύνδεση. Συνέχεια;' => 'From now on every email of the site will leave through this connection. Continue?',
		'Αρχείο αποστολών' => 'Sent log',
		'Αρχείο αποστολών:' => 'Sent log:',
		'Αυτό είναι ένα δοκιμαστικό email από το Mail Relay. Αν το διαβάζεις, η σύνδεση δουλεύει.' => 'This is a test email from Mail Relay. If you are reading it, the connection works.',
		'Αυτό είναι το πραγματικό site: συνέχεια αποστολής' => 'This is the real site: resume sending',
		'Για developers' => 'For developers',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Δεν βρέθηκαν ρυθμίσεις του FluentSMTP.' => 'No FluentSMTP settings were found.',
		'Δεν επιλέχθηκε αρχείο.' => 'No file was selected.',
		'Δεν στάλθηκε ξανά:' => 'Not sent again:',
		'Δεν υπάρχει αποθηκευμένος κωδικός.' => 'No password is stored.',
		'Δες το αρχείο' => 'See the log',
		'Διαγραφή του αποθηκευμένου κωδικού' => 'Delete the stored password',
		'Δοκιμάστηκε' => 'Tested',
		'Δοκιμή Mail Relay: %1$s (%2$s)' => 'Mail Relay test: %1$s (%2$s)',
		'Δοκιμαστικό email' => 'Test email',
		'Ειδοποιήσεις' => 'Alerts',
		'Εισαγωγή' => 'Import',
		'Εισαγωγή από FluentSMTP' => 'Import from FluentSMTP',
		'Ενεργή' => 'On',
		'Ενεργοποίηση' => 'Turn on',
		'Εντάξει' => 'OK',
		'Εξαγωγή CSV (χωρίς περιεχόμενο)' => 'Export CSV (without content)',
		'Εξαγωγή JSON' => 'Export JSON',
		'Επαναφορά' => 'Restore',
		'Επιβεβαιωμένη' => 'Confirmed',
		'Εργαλεία' => 'Tools',
		'Εφεδρική' => 'Backup',
		'Εφεδρική σύνδεση' => 'Backup connection',
		'Η αποστολή δεν ενεργοποιήθηκε: δες τι λείπει παραπάνω.' => 'Sending was not turned on: see what is missing above.',
		'Η αποστολή μέσω Mail Relay έκλεισε: το WordPress στέλνει όπως χωρίς το plugin.' => 'Sending through Mail Relay is off: WordPress sends as without the plugin.',
		'Η αποστολή μέσω Mail Relay είναι ενεργή.' => 'Sending through Mail Relay is on.',
		'Η αποστολή μέσω Mail Relay είναι κλειστή: το WordPress στέλνει όπως χωρίς το plugin. Ρύθμισε τη σύνδεση, στείλε δοκιμαστικό και ενεργοποίησέ την στην καρτέλα «Σύνδεση».' => 'Sending through Mail Relay is off: WordPress sends as without the plugin. Set up the connection, send a test and turn it on in the «Connection» tab.',
		'Η διεύθυνση επιβεβαιώθηκε: η αποστολή συνεχίζεται.' => 'The address was confirmed: sending resumes.',
		'Η διεύθυνση του site άλλαξε (αντίγραφο ή μεταφορά), οπότε η αποστολή email είναι σε παύση. Αν αυτό είναι το πραγματικό site, επιβεβαίωσέ το στα Εργαλεία.' => 'The site address changed (a copy or a move), so sending email is paused. If this is the real site, confirm it in Tools.',
		'Η διεύθυνση του site άλλαξε: η αποστολή είναι σε παύση μέχρι να την επιβεβαιώσεις.' => 'The site address changed: sending is paused until you confirm it.',
		'Η κύρια σύνδεση άλλαξε μετά την τελευταία επιτυχημένη δοκιμή. Στείλε δοκιμαστικό από τα Εργαλεία.' => 'The primary connection changed after the last successful test. Send a test from Tools.',
		'Η σύνδεση αποθηκεύτηκε.' => 'The connection was saved.',
		'Ημέρες που κρατιούνται οι εγγραφές (1–90)' => 'Days rows are kept (1–90)',
		'Ημερομηνία' => 'Date',
		'Θέμα' => 'Subject',
		'Θύρα' => 'Port',
		'Κάθε email φεύγει με αυτόν τον αποστολέα· ο αρχικός αποστολέας ενός plugin μπαίνει ως Reply-To' => 'Every email leaves with this sender; a plugin\'s original sender becomes Reply-To',
		'Κάποια email δεν στάλθηκαν τις τελευταίες 24 ώρες.' => 'Some emails were not sent in the last 24 hours.',
		'Καμία (μόνο τοπικό relay χωρίς κωδικό)' => 'None (local relay without a password only)',
		'Κανένα email στο αρχείο.' => 'No email in the log.',
		'Κατάσταση' => 'Status',
		'Κλείσιμο' => 'Close',
		'Κλειδί API' => 'API key',
		'Κλειστή' => 'Off',
		'Κλειστό από προεπιλογή. Όταν είναι ανοιχτό, το περιεχόμενο κρατιέται σφραγισμένο (AES-256-GCM) και σβήνεται μετά από 7 ημέρες. Ποτέ για ευαίσθητα email (επαναφορά κωδικού, σύνδεσμοι σύνδεσης ή επιβεβαίωσης, tokens), ποτέ τα συνημμένα. Όταν το κλείνεις, σβήνεται αμέσως ό,τι κρατήθηκε.' => 'Off by default. When on, content is kept sealed (AES-256-GCM) and deleted after 7 days. Never for sensitive emails (password reset, sign-in or confirmation links, tokens), never attachments. Turning it off deletes what was kept at once.',
		'Κρυπτογράφηση' => 'Encryption',
		'Κωδικός (για Proton Mail: το SMTP token)' => 'Password (for Proton Mail: the SMTP token)',
		'Κωδικός χωρίς κρυπτογράφηση δεν στέλνεται ποτέ: διάλεξε SSL ή STARTTLS.' => 'A password is never sent without encryption: choose SSL or STARTTLS.',
		'Κύρια' => 'Primary',
		'Κύρια σύνδεση' => 'Primary connection',
		'Λείπει' => 'Missing',
		'Λείπει μια έγκυρη διεύθυνση αποστολέα (From).' => 'A valid sender address (From) is missing.',
		'Λείπει ο SMTP server.' => 'The SMTP server is missing.',
		'Λείπει ο κωδικός (ή το SMTP token).' => 'The password (or SMTP token) is missing.',
		'Λείπει το Access key ID του Amazon SES.' => 'The Amazon SES Access key ID is missing.',
		'Λείπει το domain του Mailgun.' => 'The Mailgun domain is missing.',
		'Λείπει το κλειδί API.' => 'The API key is missing.',
		'Μη έγκυρη διεύθυνση.' => 'Invalid address.',
		'Μη έγκυρο αρχείο ρυθμίσεων του Mail Relay.' => 'Not a valid Mail Relay settings file.',
		'Μόνο αριθμοί και το τελευταίο σφάλμα, ποτέ περιεχόμενο ή παραλήπτες πελατών. Φεύγει από την κύρια, την εφεδρική και, τελευταία, από τη mail() του server. Οι αποτυχίες φαίνονται και σε banner του admin.' => 'Only counts and the last error, never content or customers\' recipients. It leaves through the primary, the backup and, last, the server\'s mail(). Failures also show in an admin banner.',
		'Να κρατιέται και το περιεχόμενο των email για 7 ημέρες, για προβολή και επαναποστολή' => 'Also keep the content of emails for 7 days, for viewing and resending',
		'Να μείνουν οι ρυθμίσεις και το αρχείο όταν σβήσω το plugin (οι κωδικοί σβήνονται πάντα)' => 'Keep the settings and the log when I delete the plugin (passwords are always deleted)',
		'Να σβηστεί όλο το αρχείο αποστολών;' => 'Delete the whole sent log?',
		'Να σταλεί ξανά αυτό το email;' => 'Send this email again?',
		'Ξανά' => 'Again',
		'Ο SMTP server δεν βρέθηκε (DNS).' => 'The SMTP server was not found (DNS).',
		'Ο SMTP server είναι τοπική ή ιδιωτική διεύθυνση. Επιτρέπεται μόνο χωρίς κωδικό και με τη σταθερά MRL_ALLOW_LOCAL_SMTP στο wp-config.php.' => 'The SMTP server is a local or private address. Allowed only without a password and with the constant MRL_ALLOW_LOCAL_SMTP in wp-config.php.',
		'Ο αποθηκευμένος κωδικός δεν ανοίγει πια (άλλαξαν τα salts του wp-config.php). Γράψ\' τον ξανά.' => 'The stored password can no longer be opened (the wp-config.php salts changed). Type it again.',
		'Ο αποθηκευμένος κωδικός σβήστηκε, γιατί άλλαξε ο server, ο χρήστης ή ο πάροχος. Γράψ\' τον ξανά.' => 'The stored password was deleted because the server, the user or the provider changed. Type it again.',
		'Ο αποθηκευμένος κωδικός σβήστηκε.' => 'The stored password was deleted.',
		'Ο αποστολέας αποθηκεύτηκε.' => 'The sender was saved.',
		'Ο κωδικός έρχεται από τη σταθερά %s στο wp-config.php.' => 'The password comes from the constant %s in wp-config.php.',
		'Η σταθερά %s του wp-config.php δεν έχει δεθεί ακόμη με σύνδεση: πάτα «Αποθήκευση σύνδεσης» μία φορά.' => 'The constant %s in wp-config.php is not tied to a connection yet: press «Save connection» once.',
		'Η σταθερά %s του wp-config.php ανήκει σε άλλη σύνδεση (άλλαξε ο server, ο χρήστης ή ο πάροχος) και δεν χρησιμοποιείται εδώ. Μπορεί να τη δέσει με αυτή τη σύνδεση μόνο διαχειριστής που μπορεί να αλλάξει αρχεία του site.' => 'The constant %s in wp-config.php belongs to another connection (the server, the user or the provider changed) and is not used here. Only an administrator who can change the site\'s files can tie it to this connection.',
		'Η σταθερά %s δέθηκε με τη νέα σύνδεση.' => 'The constant %s is now tied to the new connection.',
		'Η σταθερά %s ανήκει στην προηγούμενη σύνδεση και δεν θα χρησιμοποιηθεί για αυτή.' => 'The constant %s belongs to the previous connection and will not be used for this one.',
		'Η σταθερά του wp-config.php δεν έχει δεθεί ακόμη με τη σύνδεση: πάτα «Αποθήκευση σύνδεσης» μία φορά.' => 'The wp-config.php constant is not tied to the connection yet: press «Save connection» once.',
		'Η σταθερά του wp-config.php ανήκει σε άλλη σύνδεση και δεν χρησιμοποιείται εδώ.' => 'The wp-config.php constant belongs to another connection and is not used here.',
		'Ο κωδικός αποθηκεύτηκε σφραγισμένος.' => 'The password was saved sealed.',
		'Ο κωδικός δεν αποθηκεύτηκε: λείπουν τα salts του wp-config.php ή το OpenSSL. Βάλ\' τον ως σταθερά %s στο wp-config.php.' => 'The password was not saved: the wp-config.php salts or OpenSSL are missing. Set it as the constant %s in wp-config.php.',
		'Ο κωδικός δεν αποθηκεύτηκε: χωρίς κρυπτογράφηση δεν στέλνεται ποτέ. Διάλεξε SSL ή STARTTLS.' => 'The password was not saved: it is never sent without encryption. Choose SSL or STARTTLS.',
		'Ο κωδικός είναι πολύ μεγάλος.' => 'The password is too long.',
		'Οι εγγραφές σβήνονται μετά από %d ημέρες.' => 'Rows are deleted after %d days.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Οι ρυθμίσεις επανήλθαν. Η αποστολή είναι κλειστή: γράψε τους κωδικούς, στείλε δοκιμαστικό και ενεργοποίησέ την.' => 'Settings restored. Sending is off: type the passwords, send a test and turn it on.',
		'Οι ρυθμίσεις του FluentSMTP μπήκαν χωρίς κωδικούς. Γράψε τον κωδικό ή το κλειδί API, στείλε δοκιμαστικό και ενεργοποίησε την αποστολή.' => 'The FluentSMTP settings were imported without passwords. Type the password or API key, send a test and turn sending on.',
		'Οι τωρινές ρυθμίσεις σύνδεσης θα αντικατασταθούν. Συνέχεια;' => 'The current connection settings will be replaced. Continue?',
		'Ορίσε πρώτα το email αποστολέα.' => 'Set the sender email first.',
		'Πάροχος' => 'Provider',
		'Παίρνει μόνο server, θύρα, κρυπτογράφηση, χρήστη και αποστολέα. Τον κωδικό ή το κλειδί API το γράφεις ξανά στη «Σύνδεση» (ή το βάζεις σταθερά στο wp-config.php). Η αποστολή μένει κλειστή μέχρι να τη δοκιμάσεις και να την ενεργοποιήσεις.' => 'Takes only server, port, encryption, user and sender. Type the password or API key again in «Connection» (or set it as a constant in wp-config.php). Sending stays off until you test it and turn it on.',
		'Παραλήπτες' => 'Recipients',
		'Παραλήπτης, θέμα ή πηγή' => 'Recipient, subject or source',
		'Περιοχή' => 'Region',
		'Πηγή' => 'Source',
		'Πολλές δοκιμές σε λίγο χρόνο. Ξαναδοκίμασε σε 10 λεπτά.' => 'Too many tests in a short time. Try again in 10 minutes.',
		'Προβολή' => 'View',
		'Προς' => 'To',
		'Ρυθμίσεις' => 'Settings',
		'Σίγουρα δεν είναι αντίγραφο; Τα email θα φεύγουν σε πραγματικούς παραλήπτες.' => 'Are you sure it is not a copy? Emails will go to real recipients.',
		'Σβήστηκαν %d εγγραφές.' => '%d rows deleted.',
		'Σε εξέλιξη' => 'In progress',
		'Σε παύση' => 'Held',
		'Στάλθηκαν τις τελευταίες 24 ώρες: %d' => 'Sent in the last 24 hours: %d',
		'Στάλθηκε' => 'Sent',
		'Στέλνει από τη σύνδεση που διαλέγεις, ακόμα κι όταν η αποστολή μέσω Mail Relay είναι κλειστή. Μια επιτυχημένη δοκιμή της κύριας σύνδεσης επιτρέπει την ενεργοποίηση.' => 'Sends through the connection you choose, even while sending through Mail Relay is off. A successful test of the primary connection allows turning it on.',
		'Στείλε πρώτα ένα επιτυχημένο δοκιμαστικό από την κύρια σύνδεση (Εργαλεία).' => 'First send a successful test through the primary connection (Tools).',
		'Στο wp-config.php: επιτρέπει τοπικό SMTP relay (localhost, ιδιωτική IP), μόνο χωρίς κωδικό.' => 'In wp-config.php: allows a local SMTP relay (localhost, private IP), only without a password.',
		'Στο wp-config.php: ο κωδικός ή το κλειδί API κάθε σύνδεσης, με προτεραιότητα.' => 'In wp-config.php: the password or API key of each connection, with priority.',
		'Στο wp-config.php: το WordPress στέλνει όπως χωρίς το plugin.' => 'In wp-config.php: WordPress sends as without the plugin.',
		'Συμφωνώ: όλα τα email του site θα φεύγουν από την κύρια σύνδεση (και την εφεδρική, αν είναι ανοιχτή).' => 'I agree: every email of the site will leave through the primary connection (and the backup, if it is on).',
		'Συνήθως: %s' => 'Usually: %s',
		'Σφάλμα' => 'Error',
		'Σφραγίζεται (AES-256-GCM) με τα salts του wp-config.php. Πιο ασφαλές: σταθερά %s στο wp-config.php. Σβήνεται μόνος του όταν αλλάξει ο server, ο χρήστης ή ο πάροχος.' => 'Sealed (AES-256-GCM) with the wp-config.php salts. Safer: the constant %s in wp-config.php. Deleted by itself when the server, the user or the provider changes.',
		'Σύνδεση' => 'Connection',
		'Τελευταίες 24 ώρες: %1$d στάλθηκαν, %2$d απέτυχαν, %3$d σε παύση, %4$d από την εφεδρική σύνδεση.' => 'Last 24 hours: %1$d sent, %2$d failed, %3$d held, %4$d by the backup connection.',
		'Τελευταίο σφάλμα:' => 'Last error:',
		'Το MRL_DISABLE είναι ενεργό στο wp-config.php: το WordPress στέλνει όπως χωρίς το plugin και τίποτα δεν γράφεται στο αρχείο.' => 'MRL_DISABLE is on in wp-config.php: WordPress sends as without the plugin and nothing is written to the log.',
		'Το Mail Relay στο %s δεν μπόρεσε να στείλει email.' => 'Mail Relay on %s could not send email.',
		'Το Proton Mail και οι πάροχοι API δέχονται μόνο διευθύνσεις που έχεις επιβεβαιώσει εκεί. Για το Proton, το From πρέπει να είναι η διεύθυνση για την οποία έφτιαξες το SMTP token.' => 'Proton Mail and the API providers accept only addresses you have verified there. For Proton, the From must be the address you made the SMTP token for.',
		'Το email στάλθηκε ξανά.' => 'The email was sent again.',
		'Το wp-config.php δεν έχει δικά του salts ή λείπει το OpenSSL, οπότε ο κωδικός δεν μπορεί να σφραγιστεί και δεν αποθηκεύεται στη βάση. Βάλ\' τον ως σταθερά %s στο wp-config.php.' => 'wp-config.php has no salts of its own or OpenSSL is missing, so the password cannot be sealed and is not stored in the database. Set it as the constant %s in wp-config.php.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Το δοκιμαστικό δεν στάλθηκε:' => 'The test was not sent:',
		'Το δοκιμαστικό στάλθηκε. Έλεγξε τα εισερχόμενα (και τα ανεπιθύμητα).' => 'The test was sent. Check the inbox (and spam).',
		'Το περιεχόμενο αυτού του email δεν κρατήθηκε, άρα δεν ξαναστέλνεται.' => 'The content of this email was not kept, so it cannot be resent.',
		'Το περιεχόμενο αυτού του email δεν κρατήθηκε.' => 'The content of this email was not kept.',
		'Το περιεχόμενο κρατιέται σφραγισμένο για 7 ημέρες, ποτέ για ευαίσθητα email.' => 'Content is kept sealed for 7 days, never for sensitive emails.',
		'Το περιεχόμενο των email δεν κρατιέται (Ρυθμίσεις).' => 'The content of emails is not kept (Settings).',
		'Τσέκαρε το «Συμφωνώ» για να ενεργοποιηθεί η αποστολή.' => 'Tick «I agree» to turn sending on.',
		'Υπάρχει αποθηκευμένος κωδικός (σφραγισμένος). Κενό πεδίο = μένει ο τωρινός.' => 'A password is stored (sealed). Empty field = keep the current one.',
		'Φιλτράρισμα' => 'Filter',
		'Φύλακας αντιγράφων (staging)' => 'Copy guard (staging)',
		'Χρήστης (συνήθως η διεύθυνση email)' => 'User (usually the email address)',
		'Χωρίς δικά του salts στο wp-config.php ή χωρίς OpenSSL το περιεχόμενο δεν μπορεί να σφραγιστεί, οπότε δεν κρατιέται καθόλου.' => 'Without salts of its own in wp-config.php or without OpenSSL the content cannot be sealed, so it is not kept at all.',
		'Χωρίς δοκιμή' => 'Not tested',
		'Χωρίς κωδικούς, κλειδιά και αρχείο αποστολών. Η επαναφορά αφήνει την αποστολή κλειστή.' => 'Without passwords, keys and the sent log. Restoring leaves sending off.',
		'δεν εισάγεται' => 'not imported',
		'ευαίσθητο: χωρίς περιεχόμενο' => 'sensitive: no content',
		'εφεδρική' => 'backup',
		'κύρια' => 'primary',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
	);

	public static function init(): void {
		add_filter( 'gettext_mail-relay', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_mail-relay', array( __CLASS__, 'filter_n' ), 10, 5 );
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

	/** gettext_mail-relay filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'mail-relay' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_mail-relay filter: English plural rule (1 = singular). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'mail-relay' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = ( 1 === (int) $number ) ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
