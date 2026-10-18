<?php
/**
 * NM_Lang — domain dictionary + gettext filter (pattern RS_Lang).
 *
 * rs_lang (user meta) decides: 'el' | 'en' | ''/'auto'. Revenue Splitter
 * writes it; when RS is not active, DM Settings shows the same switch
 * and writes the same meta (Bible §9, 1.39), so the choice stays one for
 * the whole suite. Without a choice the user's locale decides: el* →
 * Greek (msgids as they are), anything else → English.
 *
 * Outside WordPress (runner.php, installer) NM_Util::t() calls en()
 * directly with the language stored in the job.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release).
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		// @dict-start
		'Το Data Migrator δεν υποστηρίζει ακόμα multisite και σε αυτό το δίκτυο δεν κάνει τίποτα.' => 'Data Migrator does not support multisite yet and does nothing on this network.',
		'Δεν έγινε σύνδεση με τη βάση δεδομένων.' => 'Could not connect to the database.',
		'Άλλο αίτημα δουλεύει ήδη σε αυτή την εργασία…' => 'Another request is already working on this job…',
		'Αποσυμπίεση αρχείων' => 'Extracting files',
		'Εισαγωγή βάσης σε προσωρινούς πίνακες' => 'Importing the database into temporary tables',
		'Προσαρμογή ρυθμίσεων' => 'Adjusting settings',
		'Λειτουργία συντήρησης' => 'Maintenance mode',
		'Αντικατάσταση αρχείων' => 'Replacing files',
		'Αλλαγή πινάκων' => 'Swapping tables',
		'Τελικά βήματα' => 'Final steps',
		'Έτοιμο' => 'Done',
		'Αναίρεση: επιστρέφουν τα αρχεία και οι πίνακες…' => 'Undo: files and tables are going back…',
		'Data Migrator: επαναφορά' => 'Data Migrator: restore',
		'Μην κλείσεις αυτή τη σελίδα. Αν κλείσει, άνοιξε ξανά τον σύνδεσμο και δώσε τον κωδικό ανάκτησης: η εργασία συνεχίζει από εκεί που έμεινε.' => 'Do not close this page. If it closes, open the link again and give the recovery code: the job continues where it stopped.',
		'Η επαναφορά ολοκληρώθηκε.' => 'The restore is complete.',
		'Σύνδεση στο site' => 'Log in to the site',
		'Άνοιγμα του site' => 'Open the site',
		'Αναίρεση ή οριστικοποίηση' => 'Undo or finalize',
		'Τα προηγούμενα αρχεία και πίνακες κρατήθηκαν. Έλεγξε το site: αν κάτι δεν πάει καλά, η αναίρεση τα επαναφέρει. Όταν όλα είναι εντάξει, η οριστικοποίηση τα σβήνει και ελευθερώνει χώρο.' => 'The previous files and tables were kept. Check the site: if something is wrong, undo brings them back. When everything is fine, finalize deletes them and frees the space.',
		'Αναίρεση επαναφοράς' => 'Undo restore',
		'Να επιστρέψει το site όπως ήταν πριν την επαναφορά;' => 'Put the site back as it was before the restore?',
		'Οριστικοποίηση' => 'Finalize',
		'Να σβηστούν τα στοιχεία αναίρεσης; Μετά δεν γίνεται αναίρεση.' => 'Delete the undo data? After that there is no undo.',
		'Η επαναφορά αναιρέθηκε: το site είναι όπως πριν.' => 'The restore was undone: the site is as it was before.',
		'Αιτία: %s' => 'Reason: %s',
		'Πίσω στη διαχείριση' => 'Back to the admin',
		'Οριστικοποιήθηκε: τα στοιχεία αναίρεσης σβήστηκαν.' => 'Finalized: the undo data was deleted.',
		'Η επαναφορά απέτυχε πριν αλλάξει οτιδήποτε στο site: %s' => 'The restore failed before anything on the site changed: %s',
		'Καθαρισμός προσωρινών δεδομένων' => 'Clean up temporary data',
		'Δεν γράφτηκε το αρχείο .maintenance στη ρίζα του site.' => 'Could not write the .maintenance file in the site\'s root.',
		'Το αρχείο %s δεν διαβάζεται ως ZIP.' => 'The file %s cannot be read as a ZIP.',
		'Δεν δημιουργήθηκε ο φάκελος %s.' => 'Could not create the folder %s.',
		'Το %1$s δεν βγήκε σωστά από το αρχείο (%2$s).' => '%1$s was not extracted correctly from the archive (%2$s).',
		'Το αρχείο δεν έχει βάση δεδομένων.' => 'The archive has no database.',
		'Η εισαγωγή της βάσης σταμάτησε: %s' => 'The database import stopped: %s',
		'Δεν ανοίγει η λίστα αρχείων ή το ημερολόγιο αναίρεσης.' => 'Cannot open the file list or the undo journal.',
		'Δεν μετακινήθηκε το %s στον φάκελο αναίρεσης.' => 'Could not move %s to the undo folder.',
		'Δεν γράφτηκε το %s.' => 'Could not write %s.',
		'Λείπει ο προσωρινός πίνακας %s.' => 'The temporary table %s is missing.',
		'Η αλλαγή των πινάκων απέτυχε: %s' => 'Swapping the tables failed: %s',
		'Ο installer έληξε (ισχύει 7 ημέρες). Φτιάξε νέο από τη σελίδα «Αντίγραφα» του Data Migrator.' => 'The installer has expired (valid for 7 days). Make a new one on the Data Migrator "Backups" page.',
		'Μη έγκυρο αίτημα.' => 'Invalid request.',
		'Πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.' => 'Too many wrong attempts. Try again in 15 minutes.',
		'Λάθος κλειδί.' => 'Wrong key.',
		'Χρειάζεται PHP 7.4 ή νεότερη (εδώ %s).' => 'PHP 7.4 or newer is required (here %s).',
		'Λείπει η επέκταση PHP %s.' => 'The PHP extension %s is missing.',
		'Ο φάκελος δεν γράφεται από την PHP.' => 'PHP cannot write to the folder.',
		'Δεν βρέθηκαν τα κομμάτια του αντιγράφου δίπλα στον installer.' => 'The backup\'s parts were not found next to the installer.',
		'Λείπει το τελευταίο κομμάτι (με το manifest) ή δεν είναι αυτό το αντίγραφο.' => 'The last part (with the manifest) is missing or belongs to another backup.',
		'Βρέθηκαν %1$d από %2$d κομμάτια.' => 'Found %1$d of %2$d parts.',
		'Αντίγραφο του %1$s, %2$s.' => 'Backup of %1$s, %2$s.',
		'Ο installer χρειάζεται πλήρες αντίγραφο (βάση και αρχεία του WordPress).' => 'The installer needs a full backup (database and WordPress files).',
		'Δεν υπάρχει αρκετός χώρος στον δίσκο (ελεύθερα %1$s, χρειάζονται περίπου %2$s).' => 'Not enough disk space (%1$s free, about %2$s needed).',
		'Υπάρχει ήδη wp-config.php εδώ. Για site που ήδη τρέχει, χρησιμοποίησε την επαναφορά μέσα από το plugin.' => 'There is already a wp-config.php here. For a site that already runs, use the restore inside the plugin.',
		'Το πρόθεμα πινάκων δέχεται μόνο λατινικά, αριθμούς και _.' => 'The table prefix accepts only Latin letters, digits and _.',
		'Η διεύθυνση του site δεν είναι έγκυρη.' => 'The site address is not valid.',
		'Δεν έγινε σύνδεση με τη βάση: %s' => 'Could not connect to the database: %s',
		'Η βάση έχει ήδη %1$d πίνακες με το πρόθεμα %2$s. Τσέκαρε «Αντικατάσταση» για να γραφτούν από πάνω.' => 'The database already has %1$d tables with the prefix %2$s. Tick "Replace" to overwrite them.',
		'Δεν γράφτηκε το wp-config.php (λείπει το wp-config-sample.php ή ο φάκελος δεν γράφεται).' => 'Could not write wp-config.php (wp-config-sample.php is missing or the folder is not writable).',
		'Data Migrator: installer' => 'Data Migrator: installer',
		'Κλειδί μίας χρήσης (το έδειξε το plugin όταν έφτιαξε τον installer)' => 'One-time key (shown by the plugin when it made the installer)',
		'Συνέχεια' => 'Continue',
		'Έλεγχος' => 'Check',
		'Βάση δεδομένων' => 'Database',
		'Server βάσης' => 'Database server',
		'Όνομα βάσης' => 'Database name',
		'Χρήστης' => 'User',
		'Κωδικός' => 'Password',
		'Πρόθεμα πινάκων' => 'Table prefix',
		'Αντικατάσταση πινάκων που υπάρχουν ήδη με το ίδιο πρόθεμα' => 'Replace tables that already exist with the same prefix',
		'Νέο site' => 'New site',
		'Διεύθυνση του site' => 'Site address',
		'Παλιά διεύθυνση: %s. Όλες οι αναφορές της αλλάζουν αυτόματα.' => 'Old address: %s. Every reference to it changes automatically.',
		'Ορατό στις μηχανές αναζήτησης' => 'Visible to search engines',
		'Και τα drop-ins (object-cache.php, advanced-cache.php): μόνο αν ο νέος server έχει τις ίδιες υπηρεσίες cache' => 'Also the drop-ins (object-cache.php, advanced-cache.php): only if the new server has the same cache services',
		'Αρχεία της ρίζας' => 'Root files',
		'Αυτά τα αρχεία δεν είναι του WordPress. Επαναφέρονται μόνο αν τα τσεκάρεις.' => 'These files are not part of WordPress. They are restored only if you tick them.',
		'PHP: έλεγξέ το πριν' => 'PHP: check it first',
		'Εγκατάσταση' => 'Install',
		'Μην κλείσεις αυτή τη σελίδα μέχρι να τελειώσει.' => 'Do not close this page until it ends.',
		'Το site εγκαταστάθηκε.' => 'The site is installed.',
		'Ο installer, τα κομμάτια του αντιγράφου και τα προσωρινά αρχεία σβήστηκαν.' => 'The installer, the backup\'s parts and the temporary files were deleted.',
		'Η εγκατάσταση σταμάτησε: %s' => 'The installation stopped: %s',
		'Διόρθωσε την αιτία και σβήσε το αρχείο .nm-installer-…-job.php για να ξεκινήσεις από την αρχή.' => 'Fix the cause and delete the file .nm-installer-…-job.php to start over.',
		'Σφάλμα σύνδεσης. Νέα προσπάθεια σε λίγα δευτερόλεπτα…' => 'Connection error. Retrying in a few seconds…',
		'Η σύνδεση απέτυχε πολλές φορές. Άνοιξε ξανά τη σελίδα με τον κωδικό ανάκτησης για να συνεχίσεις.' => 'The connection failed many times. Open the page again with the recovery code to continue.',
		'Πίνακες: %1$d, γραμμές: %2$d.' => 'Tables: %1$d, rows: %2$d.',
		'Αντικαταστάσεις διευθύνσεων και διαδρομών: %d.' => 'Address and path replacements: %d.',
		'Αρχεία που γράφτηκαν: %d.' => 'Files written: %d.',
		'Αρχεία που δεν γράφτηκαν (υπάρχει φάκελος με το ίδιο όνομα): %s' => 'Files not written (a folder with the same name exists): %s',
		'Πίνακες με collation που άλλαξε για αυτόν τον server: %s' => 'Tables whose collation changed for this server: %s',
		'Foreign keys που δεν μπήκαν ξανά: %s' => 'Foreign keys not added back: %s',
		'Πίνακες αυτού του site που δεν υπήρχαν στο αντίγραφο και έμειναν ως είχαν: %s' => 'Tables of this site that were not in the backup and stayed as they were: %s',
		'Εκκρεμείς εργασίες του Action Scheduler: %d (θα τρέξουν σε αυτόν τον server).' => 'Pending Action Scheduler actions: %d (they will run on this server).',
		'Νέα salts στο wp-config.php: όλοι οι χρήστες αποσυνδέθηκαν.' => 'New salts in wp-config.php: every user was logged out.',
		'Τα salts δεν άλλαξαν: το wp-config.php δεν έχει την αναμενόμενη μορφή ή δεν γράφεται.' => 'The salts did not change: wp-config.php does not have the expected format or is not writable.',
		'Συνδέσου με λογαριασμό του αντιγράφου, π.χ.: %s' => 'Log in with an account of the backup, e.g.: %s',
		'Το αντίγραφο δεν βρέθηκε ή δεν ολοκληρώθηκε.' => 'The backup was not found or did not finish.',
		'Δεν βρέθηκε φάκελος με δικαίωμα εγγραφής για τα αντίγραφα. Όρισε έναν στις Ρυθμίσεις.' => 'No writable folder for the backups was found. Set one in Settings.',
		'Δεν δημιουργήθηκε ο φάκελος εργασίας.' => 'Could not create the work folder.',
		'Δεν γράφτηκε το κομμάτι στον δίσκο.' => 'Could not write the chunk to disk.',
		'Το αρχείο ξεπέρασε το μέγεθος που δηλώθηκε.' => 'The file exceeded the declared size.',
		'Δεν ανέβηκε κανένα αρχείο.' => 'No file was uploaded.',
		'Δεν βρέθηκε το τελευταίο κομμάτι (με το manifest) ενός αντιγράφου του Data Migrator. Ανέβασε όλα τα κομμάτια μαζί.' => 'The last part (with the manifest) of a Data Migrator backup was not found. Upload all the parts together.',
		'Το manifest του αρχείου δεν είναι έγκυρο.' => 'The archive\'s manifest is not valid.',
		'Λείπει το κομμάτι %1$d από %2$d (%3$s).' => 'Part %1$d of %2$d is missing (%3$s).',
		'Δεν μεταφέρθηκε το αρχείο στον φάκελο των αντιγράφων.' => 'Could not move the file to the backups folder.',
		'Λείπει κάποιο κομμάτι του αντιγράφου από τον φάκελο.' => 'A part of the backup is missing from the folder.',
		'Τρέχει ήδη μια εργασία του Data Migrator. Περίμενε να τελειώσει ή ακύρωσέ την.' => 'A Data Migrator job is already running. Wait for it to end or cancel it.',
		'Η εργασία δεν βρέθηκε.' => 'The job was not found.',
		'Έλεγχος αρχείων' => 'Checking files',
		'Ανάγνωση βάσης' => 'Reading the database',
		'Ανάλυση βάσης' => 'Analysing the database',
		'Η ανάλυση απέτυχε: %s' => 'The analysis failed: %s',
		'Το κομμάτι %s είναι κατεστραμμένο (άλλο checksum).' => 'Part %s is damaged (different checksum).',
		'Η PHP εδώ (%1$s) είναι παλαιότερη από της πηγής (%2$s). Κάποιο plugin μπορεί να μη δουλεύει.' => 'PHP here (%1$s) is older than on the source (%2$s). Some plugin may not work.',
		'PHP %1$s (πηγή %2$s).' => 'PHP %1$s (source %2$s).',
		'Το WordPress εδώ (%1$s) είναι παλαιότερο από της πηγής (%2$s) και τα αρχεία του core δεν επαναφέρονται. Ενημέρωσε πρώτα το WordPress.' => 'WordPress here (%1$s) is older than on the source (%2$s) and the core files are not restored. Update WordPress first.',
		'WordPress %1$s (πηγή %2$s).' => 'WordPress %1$s (source %2$s).',
		'Το max_allowed_packet της MySQL (%1$s) είναι μικρότερο από τη μεγαλύτερη εντολή του αντιγράφου (%2$s). Ζήτησε από τον host να το αυξήσει.' => 'MySQL\'s max_allowed_packet (%1$s) is smaller than the backup\'s largest statement (%2$s). Ask the host to raise it.',
		'Βάση %1$s (πηγή %2$s).' => 'Database %1$s (source %2$s).',
		'Η πηγή είναι MySQL 8 και εδώ MariaDB: οι collations utf8mb4_0900_* γίνονται utf8mb4_unicode_520_ci.' => 'The source is MySQL 8 and this is MariaDB: utf8mb4_0900_* collations become utf8mb4_unicode_520_ci.',
		'Χώρος στον δίσκο: %1$s ελεύθερα, χρειάζονται περίπου %2$s.' => 'Disk space: %1$s free, about %2$s needed.',
		'Το πρόθεμα πινάκων αλλάζει από %1$s σε %2$s (το κρατά το wp-config.php εδώ).' => 'The table prefix changes from %1$s to %2$s (kept by wp-config.php here).',
		'Views στην πηγή που δεν επαναφέρονται: %s' => 'Views on the source that are not restored: %s',
		'Η πηγή είχε %1$d triggers και %2$d procedures/functions, που δεν επαναφέρονται.' => 'The source had %1$d triggers and %2$d procedures/functions, which are not restored.',
		'Στην πηγή οι μηχανές αναζήτησης ήταν αποκλεισμένες (blog_public = 0). Διάλεξε παρακάτω τι θα ισχύει εδώ.' => 'On the source, search engines were blocked (blog_public = 0). Choose below what applies here.',
		'Στην πηγή, ο έλεγχος του core βρήκε αλλαγμένα ή άγνωστα αρχεία. Δες τις λεπτομέρειες στο αντίγραφο πριν την επαναφορά.' => 'On the source, the core check found changed or unknown files. See the details on the backup before restoring.',
		'Η βάση έχει διευθύνσεις τοπικής ανάπτυξης (localhost). Δες τις προτάσεις παρακάτω.' => 'The database has local development addresses (localhost). See the suggestions below.',
		'Το Data Migrator δεν υποστηρίζει multisite.' => 'Data Migrator does not support multisite.',
		'Κάνε πρώτα την ανάλυση του αντιγράφου.' => 'Run the backup\'s analysis first.',
		'Δεν γράφτηκε η εργασία στον φάκελο αποθήκευσης.' => 'Could not write the job to the storage folder.',
		'Δεν υπάρχει επαναφορά για αναίρεση.' => 'There is no restore to undo.',
		'Διάλεξε τουλάχιστον τη βάση ή μία ομάδα αρχείων.' => 'Choose at least the database or one group of files.',
		'Ακυρώθηκε.' => 'Cancelled.',
		'Καταγραφή αρχείων' => 'Listing files',
		'Εξαγωγή βάσης' => 'Exporting the database',
		'Συμπίεση' => 'Compressing',
		'Ολοκλήρωση' => 'Finishing',
		'Το αντίγραφο απέτυχε: %s' => 'The backup failed: %s',
		'Δεν γράφτηκε η λίστα αρχείων.' => 'Could not write the file list.',
		'Ο φάκελος %s δεν διαβάζεται.' => 'The folder %s cannot be read.',
		'Ο ελεύθερος χώρος (%1$s) μπορεί να μη φτάσει για το αντίγραφο (έως %2$s).' => 'The free space (%1$s) may not be enough for the backup (up to %2$s).',
		'Η εξαγωγή της βάσης σταμάτησε: %s' => 'The database export stopped: %s',
		'Δεν γράφεται το αρχείο ZIP στον φάκελο των αντιγράφων.' => 'Cannot write the ZIP file in the backups folder.',
		'Δεν γράφεται το αρχείο ZIP (χώρος στον δίσκο;).' => 'Cannot write the ZIP file (disk space?).',
		'Παραλείφθηκε (δεν διαβάζεται ή χάθηκε): %s' => 'Skipped (unreadable or gone): %s',
		'Data Migrator: Αντίγραφα' => 'Data Migrator: Backups',
		'Αντίγραφα' => 'Backups',
		'Data Migrator: Επαναφορά' => 'Data Migrator: Restore',
		'Επαναφορά' => 'Restore',
		'Data Migrator: Ρυθμίσεις' => 'Data Migrator: Settings',
		'DM Ρυθμίσεις' => 'DM Settings',
		'Η σύνδεση απέτυχε πολλές φορές. Ανανέωσε τη σελίδα: η εργασία συνεχίζει από εκεί που έμεινε.' => 'The connection failed many times. Reload the page: the job continues where it stopped.',
		'Να ακυρωθεί η εργασία;' => 'Cancel the job?',
		'Ανέβηκε. Έλεγχος του αρχείου…' => 'Uploaded. Checking the file…',
		'Διάλεξε πρώτα το αρχείο ή τα κομμάτια του αντιγράφου.' => 'Choose the backup file or its parts first.',
		'Μόνο αρχεία .zip του Data Migrator.' => 'Only Data Migrator .zip files.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Πλήρες αντίγραφο του site και επαναφορά εδώ ή σε άλλον server, με αλλαγή domain χωρίς χειροκίνητες διορθώσεις.' => 'Full backup of the site and restore here or on another server, with a domain change and no manual fixes.',
		'Προσοχή: ο φάκελος των αντιγράφων κατεβαίνει από τον browser (ο server αγνοεί το .htaccess, π.χ. nginx). Όρισε φάκελο έξω από το web root στις Ρυθμίσεις ή ζήτησε από τον host να τον κλείσει (location … { deny all; }).' => 'Warning: the backups folder can be downloaded from a browser (the server ignores .htaccess, e.g. nginx). Set a folder outside the web root in Settings, or ask the host to close it (location … { deny all; }).',
		'Αρχεία του WordPress (core)' => 'WordPress files (core)',
		'Άλλα αρχεία της ρίζας' => 'Other root files',
		'Πολυμέσα (uploads)' => 'Media (uploads)',
		'Θέματα' => 'Themes',
		'Plugins' => 'Plugins',
		'Must-use plugins' => 'Must-use plugins',
		'Μεταφράσεις' => 'Translations',
		'Drop-ins (object-cache.php κ.λπ.)' => 'Drop-ins (object-cache.php etc.)',
		'Λοιπά του wp-content' => 'Other wp-content files',
		'Νέο αντίγραφο' => 'New backup',
		'Πλήρες: βάση και όλα τα αρχεία' => 'Full: database and all files',
		'Μόνο η βάση δεδομένων' => 'Database only',
		'Μόνο τα αρχεία' => 'Files only',
		'Επιλογή' => 'Custom',
		'Άλλοι πίνακες της βάσης, χωρίς το πρόθεμα του site (%d)' => 'Other tables in the database, without the site\'s prefix (%d)',
		'Δεν είναι του WordPress. Μπαίνουν στο αντίγραφο μόνο αν τους τσεκάρεις.' => 'Not part of WordPress. They go into the backup only if you tick them.',
		'Άλλοι φάκελοι στη ρίζα του site (%d)' => 'Other folders in the site\'s root (%d)',
		'Δεν είναι του WordPress (π.χ. άλλη εφαρμογή ή στατικά αρχεία). Μπαίνουν μόνο αν τους τσεκάρεις.' => 'Not part of WordPress (e.g. another application or static files). They go in only if you tick them.',
		'Χωρισμός σε κομμάτια' => 'Split into parts',
		'Ένα αρχείο' => 'One file',
		'Σημείωση' => 'Note',
		'Δημιουργία αντιγράφου' => 'Create backup',
		'Ακύρωση' => 'Cancel',
		'Αποθηκευμένα αντίγραφα' => 'Saved backups',
		'Δεν υπάρχει ακόμα αντίγραφο.' => 'There is no backup yet.',
		'Ημερομηνία' => 'Date',
		'Περιεχόμενο' => 'Contents',
		'Μέγεθος' => 'Size',
		'Site' => 'Site',
		'Ενέργειες' => 'Actions',
		'Φάκελος: %1$s · Χώρος: %2$s' => 'Folder: %1$s · Space: %2$s',
		'Έλεγχος αρχείων του WordPress' => 'WordPress file check',
		'Συγκρίνει τα αρχεία του core με τα επίσημα checksums του WordPress.org (μία κλήση στο api.wordpress.org). Γίνεται και αυτόματα σε κάθε πλήρες αντίγραφο.' => 'Compares the core files with the official WordPress.org checksums (one call to api.wordpress.org). Also runs on every full backup.',
		'Έλεγχος τώρα' => 'Check now',
		'Κλειδί του installer (φαίνεται μόνο τώρα):' => 'Installer key (shown only now):',
		'Ανέβασε στον νέο server, στον φάκελο του site, το nm-installer.php και όλα τα κομμάτια του αντιγράφου. Άνοιξε το nm-installer.php στον browser και δώσε αυτό το κλειδί. Ισχύει 7 ημέρες.' => 'Upload nm-installer.php and every part of the backup to the site\'s folder on the new server. Open nm-installer.php in a browser and give this key. Valid for 7 days.',
		'Λήψη nm-installer.php' => 'Download nm-installer.php',
		'%1$s αρχεία, %2$s πίνακες' => '%1$s files, %2$s tables',
		'Σε εξέλιξη' => 'In progress',
		'Απέτυχε: %s' => 'Failed: %s',
		'Άγνωστα αρχεία PHP στη ρίζα: %s' => 'Unknown PHP files in the root: %s',
		'Ο έλεγχος του core βρήκε διαφορές' => 'The core check found differences',
		'%d κομμάτια' => '%d parts',
		'Λήψη %d' => 'Download %d',
		'Λήψη' => 'Download',
		'Για επαναφορά σε άδειο server, χωρίς WordPress' => 'For a restore on an empty server, without WordPress',
		'Installer' => 'Installer',
		'Log' => 'Log',
		'Να διαγραφεί οριστικά αυτό το αντίγραφο;' => 'Delete this backup for good?',
		'Διαγραφή' => 'Delete',
		'Τα αρχεία του WordPress %1$s είναι ίδια με τα επίσημα (%2$s).' => 'The WordPress %1$s files match the official ones (%2$s).',
		'Δεν έγινε έλεγχος: το api.wordpress.org δεν απάντησε ή δεν έχει checksums για αυτή την έκδοση.' => 'No check: api.wordpress.org did not answer or has no checksums for this version.',
		'Βρέθηκαν διαφορές (%s). Έλεγξε τα αρχεία πριν τα πάρεις σε άλλο server.' => 'Differences found (%s). Check the files before you take them to another server.',
		'Αλλαγμένα' => 'Changed',
		'Λείπουν' => 'Missing',
		'Άγνωστα αρχεία στο wp-admin / wp-includes' => 'Unknown files in wp-admin / wp-includes',
		'Τελευταία επαναφορά' => 'Last restore',
		'Το site επαναφέρθηκε στις %s. Τα προηγούμενα αρχεία και πίνακες κρατήθηκαν για αναίρεση.' => 'The site was restored on %s. The previous files and tables were kept for undo.',
		'Μετά την επαναφορά: cache, permalinks και WooCommerce (%s).' => 'After the restore: cache, permalinks and WooCommerce (%s).',
		'Ανέβασμα αντιγράφου' => 'Upload a backup',
		'Ένα αντίγραφο του Data Migrator από αυτό ή άλλο site. Αν έχει κομμάτια, διάλεξέ τα όλα μαζί. Ανεβαίνει σε μικρά κομμάτια, οπότε το όριο upload του server δεν μετράει.' => 'A Data Migrator backup from this or another site. If it has parts, choose them all together. It uploads in small chunks, so the server\'s upload limit does not matter.',
		'Ανέβασμα' => 'Upload',
		'Επιλογή αντιγράφου' => 'Choose a backup',
		'Ανάλυση' => 'Analyse',
		'Η ανάλυση δεν αλλάζει τίποτα: ελέγχει το αρχείο και τον server, μετρά τι θα αλλάξει και δείχνει το πλάνο πριν την επαναφορά.' => 'The analysis changes nothing: it checks the file and the server, counts what will change and shows the plan before the restore.',
		'Πλάνο επαναφοράς' => 'Restore plan',
		'Αντίγραφο του %1$s από %2$s (WordPress %3$s).' => 'Backup of %1$s from %2$s (WordPress %3$s).',
		'Είναι αντίγραφο αυτού του site: επαναφορά χωρίς αλλαγή διευθύνσεων.' => 'This is a backup of this site: restore without address changes.',
		'Μεταφορά: η βάση έχει %d αναφορές στην παλιά διεύθυνση ή διαδρομή, που θα αλλάξουν.' => 'Migration: the database has %d references to the old address or path, which will change.',
		'Διεύθυνση' => 'Address',
		'Διεύθυνση του site μετά την επαναφορά' => 'Site address after the restore',
		'Παλιά διεύθυνση: %s. Αλλάζουν και οι μορφές της: http / https, με και χωρίς www, //, JSON (\\/) και URL-encoded, και η διαδρομή των αρχείων στον server. Το guid των άρθρων μένει ως έχει.' => 'Old address: %s. Its forms change too: http / https, with and without www, //, JSON (\\/) and URL-encoded, and the files\' path on the server. The posts\' guid stays as it is.',
		'Διευθύνσεις τοπικής ανάπτυξης στη βάση' => 'Local development addresses in the database',
		'νέα διεύθυνση' => 'new address',
		'%d εμφάνιση' => '%d occurrence',
		'%d εμφανίσεις' => '%d occurrences',
		'Συνήθως είναι εικόνες ή ρυθμίσεις θέματος που έμειναν από την ανάπτυξη. Τσέκαρε όσες πρέπει να δείχνουν στο νέο site.' => 'Usually images or theme settings left over from development. Tick the ones that should point to the new site.',
		'Και το σκέτο domain %s (π.χ. σε emails όπως info@…)' => 'Also the bare domain %s (e.g. in emails like info@…)',
		'Επιπλέον αντικαταστάσεις, μία ανά γραμμή: παλιό => νέο' => 'Extra replacements, one per line: old => new',
		'Τι επαναφέρεται' => 'What is restored',
		'Βάση δεδομένων (%1$d πίνακες, %2$s γραμμές)' => 'Database (%1$d tables, %2$s rows)',
		'Τα drop-ins (%s) μένουν έξω σε μεταφορά: συνδέονται με υπηρεσίες cache του παλιού server.' => 'The drop-ins (%s) stay out on a migration: they are tied to the old server\'s cache services.',
		'Αρχεία και φάκελοι της ρίζας' => 'Root files and folders',
		'άγνωστο PHP: έλεγξέ το πριν' => 'unknown PHP: check it first',
		'δεν είναι του WordPress' => 'not part of WordPress',
		'του παλιού server' => 'of the old server',
		'Επιλογή πινάκων' => 'Choose tables',
		'Για επαναφορά μόνο μερικών πινάκων (π.χ. μόνο τις παραγγελίες). Ένας πίνακας που μένει έξω κρατά τα σημερινά του δεδομένα.' => 'To restore only some tables (e.g. only the orders). A table left out keeps its current data.',
		'Επιλογές' => 'Options',
		'Νέα salts στο wp-config.php (αποσυνδέει όλους τους χρήστες· χρήσιμο μετά από παραβίαση)' => 'New salts in wp-config.php (logs every user out; useful after a breach)',
		'Το wp-config.php αυτού του site μένει ως έχει. Το Data Migrator δεν αντικαθίσταται ποτέ από το αντίγραφο.' => 'This site\'s wp-config.php stays as it is. Data Migrator itself is never replaced by the backup.',
		'Προετοιμασία επαναφοράς' => 'Prepare the restore',
		'Άκυρο' => 'Cancel',
		'Έναρξη επαναφοράς' => 'Start the restore',
		'Κωδικός ανάκτησης (φαίνεται μόνο τώρα, κράτησέ τον):' => 'Recovery code (shown only now, keep it):',
		'Η επαναφορά τρέχει σε δική της σελίδα, έξω από τη διαχείριση, γιατί αλλάζει τη βάση και τους χρήστες. Αν η σελίδα κλείσει, άνοιξε τον παρακάτω σύνδεσμο και δώσε τον κωδικό: συνεχίζει από εκεί που έμεινε.' => 'The restore runs on its own page, outside the admin, because it changes the database and the users. If the page closes, open the link below and give the code: it continues where it stopped.',
		'Μετά την επαναφορά οι χρήστες είναι αυτοί του αντιγράφου: συνδέεσαι με λογαριασμό του αντιγράφου.' => 'After the restore the users are those of the backup: you log in with an account of the backup.',
		'Αναίρεση τώρα' => 'Undo now',
		'Αν η σελίδα κλείσει' => 'If the page closes',
		'Κωδικός ανάκτησης' => 'Recovery code',
		'Αποθήκευση' => 'Storage',
		'Φάκελος των αντιγράφων' => 'Backups folder',
		'Καλύτερα έξω από το web root. Αν τον αλλάξεις, τα παλιά αντίγραφα μένουν στον παλιό φάκελο (μετέφερέ τα με το χέρι αν τα θέλεις).' => 'Better outside the web root. If you change it, the old backups stay in the old folder (move them by hand if you want them).',
		'Ο φάκελος δεν κατεβαίνει από τον browser.' => 'The folder cannot be downloaded from a browser.',
		'Ο φάκελος είναι έξω από το web root.' => 'The folder is outside the web root.',
		'Ο φάκελος κατεβαίνει από τον browser!' => 'The folder can be downloaded from a browser!',
		'Ο έλεγχος δεν ολοκληρώθηκε (το site δεν απάντησε σε αίτημα προς τον εαυτό του).' => 'The check did not finish (the site did not answer a request to itself).',
		'Χωρισμός σε κομμάτια (προεπιλογή)' => 'Split into parts (default)',
		'Να μείνουν τα αντίγραφα όταν διαγραφεί το plugin' => 'Keep the backups when the plugin is deleted',
		'Από προεπιλογή σβήνονται: ένα ξεχασμένο αντίγραφο έχει όλη τη βάση του site.' => 'By default they are deleted: a forgotten backup holds the site\'s whole database.',
		'Εξαιρέσεις' => 'Exclusions',
		'Μία ανά γραμμή. Χωρίς / ταιριάζει στο όνομα (π.χ. *.mp4), με / στη διαδρομή μέσα στην ομάδα (π.χ. uploads/2019/*). Πάντα έξω: cache, αντίγραφα άλλων plugins, .git, node_modules, *.log.' => 'One per line. Without / it matches the name (e.g. *.mp4), with / the path inside the group (e.g. uploads/2019/*). Always out: cache, other plugins\' backups, .git, node_modules, *.log.',
		'Γλώσσα' => 'Language',
		'Όπως η γλώσσα του λογαριασμού' => 'Same as the account\'s language',
		'Ισχύει για όλα τα plugins της σουίτας Noxpress.' => 'Applies to every plugin of the Noxpress suite.',
		'Έλεγχος του φακέλου μέσω HTTP' => 'Check the folder over HTTP',
		'Τα αρχεία του WordPress είναι ίδια με τα επίσημα.' => 'The WordPress files match the official ones.',
		'Βρέθηκαν διαφορές στα αρχεία του WordPress.' => 'Differences found in the WordPress files.',
		'Το αντίγραφο διαγράφηκε.' => 'The backup was deleted.',
		'Το αντίγραφο δεν βρέθηκε.' => 'The backup was not found.',
		'Ο φάκελος δεν δημιουργήθηκε ή δεν γράφεται. Οι υπόλοιπες ρυθμίσεις αποθηκεύτηκαν.' => 'The folder was not created or is not writable. The other settings were saved.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Δεν υπάρχει επαναφορά για οριστικοποίηση.' => 'There is no restore to finalize.',
		'Το κλειδί του installer έληξε. Φτιάξε νέο installer.' => 'The installer key has expired. Make a new installer.',
		'Το κομμάτι δεν έφτασε στον server.' => 'The chunk did not reach the server.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Data Migrator: το site επαναφέρθηκε από αντίγραφο. Έλεγξέ το και μετά πάτα «Οριστικοποίηση» (ή «Αναίρεση» αν κάτι δεν πάει καλά).' => 'Data Migrator: the site was restored from a backup. Check it, then press "Finalize" (or "Undo" if something is wrong).',
		'Άνοιγμα' => 'Open',
		// @dict-end
	);

	public static function init(): void {
		add_filter( 'gettext_data-migrator', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_data-migrator', array( __CLASS__, 'filter_n' ), 10, 5 );
	}

	/** English for a msgid (the msgid itself when missing). */
	public static function en( string $msgid ): string {
		return self::$dict[ $msgid ] ?? $msgid;
	}

	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current user. */
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
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** Revenue Splitter is active (it owns the language switch). */
	public static function rs_active(): bool {
		return class_exists( 'RS_Lang' ) || defined( 'RS_VERSION' );
	}

	public static function reset(): void {
		self::$lang = null;
	}

	/** gettext_data-migrator filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'data-migrator' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_data-migrator filter (English plural rule: 1 → single). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'data-migrator' === $domain && 'en' === self::lang() ) {
			$msgid = 1 === (int) $number ? $single : $plural;
			if ( isset( self::$dict[ $msgid ] ) ) {
				return self::$dict[ $msgid ];
			}
		}
		return $translation;
	}
}
