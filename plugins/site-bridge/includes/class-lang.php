<?php
/**
 * SBR_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Tool results and MCP errors are English for the model; only the admin
 * screens and the consent page are translated.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		// Tabs, menu.
		'Επισκόπηση'                                                  => 'Overview',
		'Πρόσβαση'                                                    => 'Access',
		'Sites'                                                       => 'Sites',
		'Συνδέσεις'                                                   => 'Connections',
		'Ιστορικό'                                                    => 'Log',
		'Site Bridge'                                                 => 'Site Bridge',
		'Ρυθμίσεις'                                                   => 'Settings',
		'Αντιγράφηκε'                                                 => 'Copied',
		'Τα κλειδιά του Site Bridge δεν διαβάζονταν πια (άλλαξαν τα salts του wp-config.php;) και φτιάχτηκαν νέα. Κάνε ξανά σύζευξη των sites με το Hub.' => 'Site Bridge\'s keys could no longer be read (did the wp-config.php salts change?) and new ones were made. Pair the sites with the Hub again.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'             => 'You do not have access to this page.',

		// Overview.
		'Το Claude διαχειρίζεται τα sites σου μέσω MCP, από τους δικούς σου servers. Κάθε site αποφασίζει μόνο του τι επιτρέπεται.' => 'Claude manages your sites over MCP, from your own servers. Each site decides for itself what is allowed.',
		'Το SBR_DISABLE είναι ενεργό στο wp-config.php: όλα τα endpoints είναι κλειστά.' => 'SBR_DISABLE is on in wp-config.php: every endpoint is closed.',
		'Ρόλοι'                                                       => 'Roles',
		'Site'                                                        => 'Site',
		'το Claude μπορεί να δουλέψει σε αυτό το site, μέσα από ένα Hub, με όσα επιτρέπει η καρτέλα Πρόσβαση.' => 'Claude can work on this site, through a Hub, within what the Access tab allows.',
		'Hub'                                                         => 'Hub',
		'εδώ συνδέεται το Claude (OAuth) και από εδώ φτάνει σε όλα τα sites. Ένα Hub αρκεί για όλα.' => 'Claude connects here (OAuth) and reaches every site from here. One Hub is enough for all of them.',
		'Αποθήκευση'                                                  => 'Save',
		'Σύνδεση του Claude'                                          => 'Connecting Claude',
		'Διεύθυνση MCP:'                                              => 'MCP address:',
		'Αντιγραφή'                                                   => 'Copy',
		'Στο Claude: Settings → Connectors → Add custom connector. Όνομα «Site Bridge», URL η διεύθυνση πιο πάνω. Άφησε κενά τα πεδία OAuth client.' => 'In Claude: Settings → Connectors → Add custom connector. Name "Site Bridge", URL the address above. Leave the OAuth client fields empty.',
		'Πάτα Connect: ανοίγει αυτό το site, μπαίνεις με τον λογαριασμό σου και εγκρίνεις τα scopes και τα sites.' => 'Press Connect: this site opens, you log in with your account and approve the scopes and the sites.',
		'Οι συνδέσεις φαίνονται και ανακαλούνται στην καρτέλα Συνδέσεις.' => 'Connections are listed and revoked in the Connections tab.',
		'Κλειδί του Hub: %s'                                          => 'Hub key: %s',
		'Διάγνωση'                                                    => 'Diagnostics',
		'Ελέγχει HTTPS, permalinks, το header Authorization, τα metadata του OAuth, τα κλειδιά και αν μπορούν να αλλάζουν plugins από μακριά.' => 'Checks HTTPS, permalinks, the Authorization header, the OAuth metadata, the keys and whether plugins can be changed remotely.',
		'Έλεγχος τώρα'                                                => 'Check now',
		'Το site έχει HTTPS.'                                         => 'The site has HTTPS.',
		'Το site δεν έχει HTTPS: το Claude και τα sites δεν θα συνδεθούν.' => 'The site has no HTTPS: Claude and the sites will not connect.',
		'Τα permalinks είναι «όμορφα» (χρειάζονται για τις διευθύνσεις του OAuth).' => 'Permalinks are "pretty" (the OAuth addresses need them).',
		'Τα permalinks είναι «Απλά»: διάλεξε άλλη δομή στις Ρυθμίσεις → Μόνιμοι σύνδεσμοι.' => 'Permalinks are "Plain": pick another structure in Settings → Permalinks.',
		'Υπογραφές Ed25519 διαθέσιμες.'                               => 'Ed25519 signatures are available.',
		'Λείπουν οι συναρτήσεις sodium.'                              => 'The sodium functions are missing.',
		'Το site δεν απάντησε σε αίτημα προς τον εαυτό του (%s), οπότε το header Authorization δεν ελέγχθηκε.' => 'The site did not answer a request to itself (%s), so the Authorization header was not checked.',
		'Το header Authorization φτάνει στο WordPress.'               => 'The Authorization header reaches WordPress.',
		'Ο server κόβει το header Authorization: το OAuth δεν θα δουλέψει. Ζήτησε από τον host να το περνά στην PHP (π.χ. CGIPassAuth On).' => 'The server drops the Authorization header: OAuth will not work. Ask the host to pass it to PHP (e.g. CGIPassAuth On).',
		'Τα metadata του OAuth απαντούν στο /.well-known/.'           => 'The OAuth metadata answers at /.well-known/.',
		'Το /.well-known/oauth-authorization-server δεν απαντά (ο server το κρατά για τον εαυτό του;). Το Claude θα βρει τα metadata από το 401 του endpoint, αλλά αν η σύνδεση αποτύχει, αυτό είναι το πρώτο που ελέγχεις.' => '/.well-known/oauth-authorization-server does not answer (does the server keep it for itself?). Claude will find the metadata from the endpoint\'s 401, but if connecting fails, this is the first thing to check.',
		'Το Hub δέχεται συνδέσεις.'                                   => 'The Hub accepts connections.',
		'Το Hub χρειάζεται HTTPS.'                                    => 'The Hub needs HTTPS.',
		'Ο χρήστης εκτέλεσης είναι διαχειριστής.'                     => 'The run-as user is an administrator.',
		'Δεν έχει οριστεί διαχειριστής για την εκτέλεση των εργαλείων (Πρόσβαση).' => 'No administrator is set to run the tools (Access).',
		'Τα plugins και τα θέματα μπορούν να εγκατασταθούν και να ενημερωθούν από μακριά.' => 'Plugins and themes can be installed and updated remotely.',
		'Τα plugins και τα θέματα δεν μπορούν να αλλάξουν από μακριά (DISALLOW_FILE_MODS ή ο server θέλει FTP).' => 'Plugins and themes cannot be changed remotely (DISALLOW_FILE_MODS, or the server wants FTP).',
		'Το site έχει Hub.'                                           => 'The site has a Hub.',
		'Το site δεν έχει γίνει ακόμα σύζευξη με Hub (Πρόσβαση → Σύζευξη).' => 'The site is not paired with a Hub yet (Access → Pairing).',
		'Τα κλειδιά ξαναφτιάχτηκαν μετά από αλλαγή των salts: κάνε ξανά τις συζεύξεις.' => 'The keys were remade after a salts change: pair again.',

		// Access.
		'Ο ρόλος Site είναι κλειστός. Άνοιξέ τον στην Επισκόπηση για να μπορεί το Claude να δουλέψει σε αυτό το site.' => 'The Site role is off. Turn it on in Overview so Claude can work on this site.',
		'Παράθυρο εγγραφής'                                           => 'Write window',
		'Ανοιχτό ως %s'                                               => 'Open until %s',
		'Κλειστό'                                                     => 'Closed',
		'Τα εργαλεία εγγραφής και διαχείρισης δουλεύουν μόνο όσο είναι ανοιχτό, και μόνο αν το επιτρέπει το επίπεδο πιο κάτω. Κλείνει μόνο του.' => 'Write and admin tools work only while it is open, and only if the level below allows them. It closes by itself.',
		'Άνοιγμα για %s'                                              => 'Open for %s',
		'%d λεπτά'                                                    => '%d minutes',
		'%d ώρες'                                                     => '%d hours',
		'Κλείσιμο τώρα'                                               => 'Close now',
		'Επίπεδο'                                                     => 'Level',
		'Κανένα εργαλείο δεν δουλεύει.'                               => 'No tool works.',
		'Ανάγνωση'                                                    => 'Read',
		'Μόνο ανάγνωση: ρυθμίσεις, βάση (SELECT), REST GET, σελίδες, αρχεία κώδικα, logs. Η σωστή επιλογή για sites πελατών.' => 'Read only: settings, database (SELECT), REST GET, pages, code files, logs. The right choice for client sites.',
		'Εγγραφή'                                                     => 'Write',
		'Και αλλαγές περιεχομένου: REST εγγραφές (προϊόντα, σελίδες…), options, πολυμέσα, caches. Μόνο με ανοιχτό παράθυρο.' => 'Plus content changes: REST writes (products, pages…), options, media, caches. Only with an open window.',
		'Διαχείριση'                                                  => 'Admin',
		'Και plugins / θέματα: εγκατάσταση, ενεργοποίηση, ενημέρωση, κανάλι Noxpress. Μόνο με ανοιχτό παράθυρο.' => 'Plus plugins / themes: install, activate, update, Noxpress channel. Only with an open window.',
		'Το Hub μπορεί να ανοίξει το παράθυρο εγγραφής αυτού του site (κλειστό για sites πελατών)' => 'The Hub may open this site\'s write window (keep off for client sites)',
		'Εκτέλεση και προστασία'                                      => 'Execution and protection',
		'Χρήστης εκτέλεσης'                                           => 'Run-as user',
		'Τα εργαλεία τρέχουν ως αυτός ο διαχειριστής: τα δικαιώματά του ισχύουν και το όνομά του φαίνεται στις αλλαγές.' => 'Tools run as this administrator: their permissions apply and their name shows on the changes.',
		'Μάσκα σε προσωπικά δεδομένα (email, τηλέφωνο, διεύθυνση, ΤΚ, IP) σε κάθε αποτέλεσμα' => 'Mask personal data (email, phone, address, postcode, IP) in every result',
		'Email στη διεύθυνση διαχειριστή μετά από κάθε εργαλείο διαχείρισης (plugins, θέματα, κανάλι)' => 'Email the admin address after every admin tool (plugins, themes, channel)',
		'IP που δέχεται το site (μία ανά γραμμή, CIDR)'               => 'IPs the site accepts (one per line, CIDR)',
		'Κενό = κάθε IP (οι υπογραφές μένουν υποχρεωτικές). Βάλε μόνο την IP του Hub αν τη γνωρίζεις· μια λάθος IP κλειδώνει έξω το Hub.' => 'Empty = any IP (signatures stay mandatory). Only add the Hub\'s IP if you know it; a wrong IP locks the Hub out.',
		'Από πού επιτρέπεται εγκατάσταση plugin με zip (προθέματα URL)' => 'Where plugin zips may be installed from (URL prefixes)',
		'Σύζευξη με Hub'                                              => 'Pairing with a Hub',
		'Συνδεδεμένο με το Hub %1$s (κλειδί %2$s) ως «%3$s» από %4$s.' => 'Paired with Hub %1$s (key %2$s) as "%3$s" since %4$s.',
		'Τελευταίο αίτημα από το Hub: %s'                             => 'Last request from the Hub: %s',
		'Να ξεχάσει το site αυτό το Hub; Το Claude δεν θα φτάνει πια εδώ μέχρι νέα σύζευξη.' => 'Forget this Hub? Claude will no longer reach this site until it is paired again.',
		'Αποσύνδεση από το Hub'                                       => 'Unpair from the Hub',
		'Αυτό το site είναι και Hub: φαίνεται αυτόματα στη λίστα των sites του, χωρίς σύζευξη.' => 'This site is also the Hub: it shows in its site list automatically, with no pairing.',
		'Το site δεν έχει Hub ακόμα.'                                 => 'The site has no Hub yet.',
		'Αντέγραψε τον κωδικό στο Hub (Sites → Προσθήκη site). Ισχύει 15 λεπτά και μία φορά:' => 'Copy the code to the Hub (Sites → Add site). It is valid for 15 minutes and once:',
		'Νέος κωδικός σύζευξης'                                       => 'New pairing code',

		// Sites.
		'Id'                                                          => 'Id',
		'Παράθυρο'                                                    => 'Window',
		'Τελευταία επαφή'                                             => 'Last contact',
		'Ενέργειες'                                                   => 'Actions',
		'Κανένα site ακόμα. Πρόσθεσε ένα με κωδικό σύζευξης, ή άνοιξε τον ρόλο Site σε αυτό το site.' => 'No sites yet. Add one with a pairing code, or turn on the Site role on this site.',
		'αυτό το site'                                                => 'this site',
		'ανοιχτό ως %s'                                               => 'open until %s',
		'κλειστό'                                                     => 'closed',
		'Έλεγχος'                                                     => 'Check',
		'Κλείσιμο παραθύρου'                                          => 'Close window',
		'Διάρκεια παραθύρου'                                          => 'Window length',
		'Άνοιγμα παραθύρου'                                           => 'Open window',
		'Να αφαιρεθεί το site; Θα χρειαστεί νέα σύζευξη για να επιστρέψει.' => 'Remove the site? It needs a new pairing to come back.',
		'Αφαίρεση'                                                    => 'Remove',
		'Προσθήκη site'                                               => 'Add site',
		'Στο άλλο site: εγκατάσταση του Site Bridge, ρόλος Site, επίπεδο στην Πρόσβαση, και «Νέος κωδικός σύζευξης». Επικόλλησε τον κωδικό εδώ.' => 'On the other site: install Site Bridge, turn on the Site role, set the level in Access, and press "New pairing code". Paste the code here.',
		'Κωδικός σύζευξης'                                            => 'Pairing code',
		'Όνομα (προαιρετικό, γίνεται και το id)'                      => 'Name (optional, also becomes the id)',
		'Σύζευξη'                                                     => 'Pair',

		// Connections.
		'Client'                                                      => 'Client',
		'Scopes'                                                      => 'Scopes',
		'Εγκρίθηκε'                                                   => 'Approved',
		'Λήγει'                                                       => 'Expires',
		'Τελευταία χρήση'                                             => 'Last used',
		'Κλήσεις'                                                     => 'Calls',
		'Κατάσταση'                                                   => 'Status',
		'Καμία σύνδεση ακόμα.'                                        => 'No connections yet.',
		'όλα, και όσα προστεθούν'                                     => 'all, and any added later',
		'ενεργή'                                                      => 'active',
		'έληξε / ανακλήθηκε'                                          => 'expired / revoked',
		'Να ανακληθεί η σύνδεση; Το Claude θα πρέπει να συνδεθεί ξανά.' => 'Revoke the connection? Claude will have to connect again.',
		'Ανάκληση'                                                    => 'Revoke',
		'Kill switch'                                                 => 'Kill switch',
		'Τερματίζει αμέσως κάθε σύνδεση. Για να κλείσει και το endpoint, σβήσε τον ρόλο Hub ή βάλε define( \'SBR_DISABLE\', true ); στο wp-config.php.' => 'Ends every connection at once. To close the endpoint too, turn off the Hub role or add define( \'SBR_DISABLE\', true ); to wp-config.php.',
		'Να τερματιστούν ΟΛΕΣ οι συνδέσεις;'                          => 'End ALL connections?',
		'Ανάκληση όλων'                                               => 'Revoke all',
		'Ρυθμίσεις του Hub'                                           => 'Hub settings',
		'Clients που επιτρέπονται (προθέματα του client_id)'          => 'Allowed clients (client_id prefixes)',
		'Το Claude ταυτοποιείται με έγγραφο στο claude.ai (CIMD). Οι διευθύνσεις επιστροφής πρέπει να είναι σε αυτά τα hosts ή τοπικές (Claude Code).' => 'Claude identifies itself with a document on claude.ai (CIMD). Redirect addresses must be on these hosts or local (Claude Code).',
		'Δυναμική εγγραφή clients (DCR). Κλειστή, εκτός αν ένας client δεν υποστηρίζει CIMD.' => 'Dynamic client registration (DCR). Keep it off unless a client does not support CIMD.',
		'Κλήσεις ανά λεπτό ανά σύνδεση'                               => 'Calls per minute per connection',
		'Κλήσεις ανά ημέρα ανά σύνδεση'                               => 'Calls per day per connection',
		'Ημέρες ιστορικού'                                            => 'Days of log',
		'IP που δέχεται το endpoint MCP (μία ανά γραμμή, CIDR)'       => 'IPs the MCP endpoint accepts (one per line, CIDR)',
		'Κενό = κάθε IP. Το Claude καλεί από 160.79.104.0/21· το Claude Code από τη δική σου IP.' => 'Empty = any IP. Claude calls from 160.79.104.0/21; Claude Code from your own IP.',

		// Log.
		'Hub και site'                                                => 'Hub and site',
		'Όλα τα sites'                                                => 'All sites',
		'Όλα τα εργαλεία'                                             => 'All tools',
		'Κάθε αποτέλεσμα'                                             => 'Any result',
		'Φίλτρο'                                                      => 'Filter',
		'Εξαγωγή CSV'                                                 => 'Export CSV',
		'Ώρες σε UTC. Τα μυστικά δεν γράφονται ποτέ στο ιστορικό.'    => 'Times in UTC. Secrets are never written to the log.',
		'Ώρα'                                                         => 'Time',
		'Πλευρά'                                                      => 'Side',
		'Εργαλείο'                                                    => 'Tool',
		'Ορίσματα'                                                    => 'Arguments',
		'Αποτέλεσμα'                                                  => 'Result',
		'Σύνδεση'                                                     => 'Connection',
		'Τίποτα ακόμα.'                                               => 'Nothing yet.',
		'Σελίδα %1$d από %2$d (%3$d εγγραφές)'                        => 'Page %1$d of %2$d (%3$d entries)',

		// Notices.
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                                 => 'Settings saved.',
		'Ο χρήστης εκτέλεσης πρέπει να είναι διαχειριστής.'           => 'The run-as user must be an administrator.',
		'Το παράθυρο εγγραφής είναι ανοιχτό ως %s.'                   => 'The write window is open until %s.',
		'Το παράθυρο εγγραφής έκλεισε.'                               => 'The write window is closed.',
		'Το site ξέχασε το Hub.'                                      => 'The site forgot the Hub.',
		'Το site προστέθηκε ως «%s».'                                 => 'The site was added as "%s".',
		'Το site αφαιρέθηκε.'                                         => 'The site was removed.',
		'Άγνωστο site.'                                               => 'Unknown site.',
		'Έγινε.'                                                      => 'Done.',
		'Η σύνδεση ανακλήθηκε.'                                       => 'The connection was revoked.',
		'Ανακλήθηκε 1 σύνδεση.'                                       => '1 connection was revoked.',
		'Ανακλήθηκαν %d συνδέσεις.'                                   => '%d connections were revoked.',
		'Μία γραμμή δεν ήταν έγκυρη και αφαιρέθηκε (IP / CIDR, ή διεύθυνση https που τελειώνει σε /).' => 'One line was not valid and was left out (IP / CIDR, or an https address ending in /).',
		'%d γραμμές δεν ήταν έγκυρες και αφαιρέθηκαν (IP / CIDR, ή διευθύνσεις https που τελειώνουν σε /).' => '%d lines were not valid and were left out (IP / CIDR, or https addresses ending in /).',
		'Άγνωστη ενέργεια.'                                           => 'Unknown action.',

		// Consent page.
		'Ο ρόλος Hub είναι κλειστός σε αυτό το site.'                 => 'The Hub role is off on this site.',
		'Μόνο διαχειριστές εγκρίνουν συνδέσεις.'                      => 'Only administrators approve connections.',
		'Το αίτημα σύνδεσης έληξε ή δεν υπάρχει. Ξεκίνα ξανά από το Claude.' => 'The connection request expired or does not exist. Start again from Claude.',
		'Ανάγνωση: ρυθμίσεις, βάση, REST GET, σελίδες, αρχεία, logs'  => 'Read: settings, database, REST GET, pages, files, logs',
		'Εγγραφή: περιεχόμενο, options, πολυμέσα, caches (όπου το site το επιτρέπει, με ανοιχτό παράθυρο)' => 'Write: content, options, media, caches (where the site allows it, with an open window)',
		'Διαχείριση: plugins, θέματα, κανάλι ενημερώσεων (όπου το site το επιτρέπει, με ανοιχτό παράθυρο)' => 'Admin: plugins, themes, update channel (where the site allows it, with an open window)',
		'Site Bridge: έγκριση σύνδεσης'                               => 'Site Bridge: approve connection',
		'Έγκριση σύνδεσης στο Site Bridge'                            => 'Approve a connection to Site Bridge',
		'Συνδεδεμένος ως %s.'                                         => 'Logged in as %s.',
		'Μετά την έγκριση η σύνδεση επιστρέφει στο:'                  => 'After approval the connection returns to:',
		'Τοπική διεύθυνση (Claude Code): έγκρινε μόνο αν μόλις ξεκίνησες εσύ τη σύνδεση από τον υπολογιστή σου.' => 'Local address (Claude Code): approve only if you just started this connection from your own computer.',
		'Κάθε site εφαρμόζει επιπλέον το δικό του επίπεδο και το παράθυρο εγγραφής.' => 'Each site also applies its own level and write window.',
		'Και όσα sites προστεθούν αργότερα'                           => 'And any sites added later',
		'Η έγκριση ισχύει 90 ημέρες. Ανακαλείται όποτε θέλεις από Noxpress → Site Bridge → Συνδέσεις.' => 'The approval lasts 90 days. Revoke it any time in Noxpress → Site Bridge → Connections.',
		'Έγκριση'                                                     => 'Approve',
		'Άρνηση'                                                      => 'Deny',

		// Footer.
		'Made with ❤ by %s'                                           => 'Made with ❤ by %s',
		'More plugins at'                                             => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                              => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                          => 'Noxpress Dashboard',

		// Hub pairing.
		'Αυτός δεν είναι κωδικός σύζευξης του Site Bridge.'           => 'This is not a Site Bridge pairing code.',
		'Ο κωδικός σύζευξης είναι κατεστραμμένος. Αντέγραψέ τον ξανά ολόκληρο.' => 'The pairing code is damaged. Copy it again in full.',
		'Ο κωδικός σύζευξης έληξε. Φτιάξε νέο στο site.'              => 'The pairing code expired. Make a new one on the site.',
		'Η διεύθυνση του site στον κωδικό δεν είναι έγκυρη.'          => 'The site address in the code is not valid.',
		'Το site πρέπει να έχει HTTPS.'                               => 'The site must have HTTPS.',
		'Αυτό το site υπάρχει ήδη στη λίστα. Αφαίρεσέ το πρώτα αν θέλεις νέα σύζευξη.' => 'This site is already on the list. Remove it first if you want a new pairing.',
		'Το site δεν απάντησε: %s'                                    => 'The site did not answer: %s',
		'Η σύζευξη απέτυχε (%s). Έλεγξε ότι ο ρόλος Site είναι ενεργός στο site και ότι ο κωδικός δεν χρησιμοποιήθηκε ή έληξε.' => 'Pairing failed (%s). Check that the Site role is on at the site and that the code was not used already or expired.',
	);

	public static function init(): void {
		add_filter( 'gettext_site-bridge', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** 'el' | 'en' for the current user (admin screens). */
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
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_site-bridge filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'site-bridge' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
