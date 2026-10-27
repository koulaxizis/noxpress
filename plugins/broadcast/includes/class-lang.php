<?php
/**
 * BRC_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' | ''/'auto'.
 * Only Revenue Splitter writes it. Without an explicit choice (or without
 * RS) the user's locale decides: el* → Greek (msgids pass through as they
 * are), anything else → English. Channel posts are templates in the
 * channel's language (settings), not msgids.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Ουρά' => 'Queue',
		'Ιστορικό' => 'History',
		'Modules' => 'Modules',
		'Πηγές' => 'Sources',
		'Viber' => 'Viber',
		'Πρότυπα' => 'Templates',
		'Εργαλεία' => 'Tools',
		'Νέα προϊόντα' => 'New products',
		'Newsletter' => 'Newsletter',
		'YouTube' => 'YouTube',
		'Ουρά αναρτήσεων' => 'Post queue',
		'Εβδομαδιαίο digest Book Feed' => 'Weekly Book Feed digest',
		'Viber Channel' => 'Viber Channel',
		'Περιμένει' => 'Waiting',
		'Στην ουρά' => 'Queued',
		'Αποστολή' => 'Sending',
		'Στάλθηκε' => 'Sent',
		'Δοκιμή' => 'Test',
		'Απέτυχε' => 'Failed',
		'Παραλείφθηκε' => 'Skipped',
		'Απορρίφθηκε' => 'Rejected',
		'χρειάζεται έγκριση' => 'needs approval',
		'φρένο: πολλές αναρτήσεις' => 'brake: too many posts',
		'περιμένει «Δημοσιεύτηκε»' => 'waits for «Published»',
		'ίσως Short' => 'maybe a Short',
		'ίδιος σύνδεσμος μέσα σε 7 ημέρες' => 'same link within 7 days',
		'ο προορισμός είναι κλειστός' => 'the destination is off',
		'χρειάζεται νέα σύνδεση' => 'needs a new connection',
		'δίκτυο' => 'network',
		'πολλά αιτήματα' => 'too many requests',
		'το απέρριψε ο προορισμός' => 'refused by the destination',
		'Broadcast' => 'Broadcast',
		'Ρυθμίσεις' => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Ανακοινώνει γεγονότα του site σε κανάλια: πηγές → ουρά → προορισμοί. Κάθε module ενεργοποιείται χωριστά· όσα είναι κλειστά δεν φορτώνουν τίποτα.' => 'Announces the site\'s events on channels: sources → queue → destinations. Each module is switched on separately; the ones that are off load nothing.',
		'Το BRC_DISABLE είναι ενεργό στο wp-config.php: καμία πηγή, καμία ανάρτηση και κανένα webhook.' => 'BRC_DISABLE is on in wp-config.php: no sources, no posts and no webhooks.',
		'Λειτουργία δοκιμής: οι πηγές γεμίζουν την ουρά κανονικά, αλλά τίποτα δεν φεύγει από το site. Στο Ιστορικό βλέπεις τι θα στελνόταν.' => 'Test mode: the sources fill the queue as usual, but nothing leaves the site. The History shows what would have been sent.',
		'Ανενεργό: οι πηγές δεν προσθέτουν τίποτα και τίποτα δεν στέλνεται.' => 'Off: the sources add nothing and nothing is sent.',
		'Καμία πηγή δεν έχει πού να στείλει: ενεργοποίησε έναν προορισμό (Viber Channel) στα Modules.' => 'The sources have nowhere to send: switch on a destination (Viber Channel) in Modules.',
		'Πέρασαν 14 ημέρες δοκιμής με έγκριση. Να γίνουν όλες οι πηγές αυτόματες; Η ουρά αναρτήσεων περιμένει πάντα το «Δημοσιεύτηκε».' => 'The 14-day trial with approval is over. Make every source automatic? The post queue always waits for «Published».',
		'Να γίνουν αυτόματες' => 'Make them automatic',
		'Ρώτα με σε 7 ημέρες' => 'Ask me in 7 days',
		'Να μείνει η έγκριση' => 'Keep the approval',
		'Το φρένο σταμάτησε αναρτήσεις το τελευταίο 24ωρο (όριο %1$d την ώρα και %2$d την ημέρα ανά πηγή). Περιμένουν στην Ουρά για έγκριση.' => 'The brake held posts back in the last 24 hours (limit %1$d per hour and %2$d per day per source). They wait in the Queue for approval.',
		'Σφάλμα στην τελευταία εκτέλεση στο παρασκήνιο: %s' => 'Error in the last background run: %s',
		'Το module «%s» είναι κλειστό. Οι ρυθμίσεις αποθηκεύονται, αλλά δεν κάνει τίποτα μέχρι να το ενεργοποιήσεις στα Modules.' => 'The «%s» module is off. Settings are saved, but it does nothing until you switch it on in Modules.',
		'Περιμένουν' => 'Waiting',
		'Τίποτα δεν περιμένει.' => 'Nothing is waiting.',
		'Δημοσιεύτηκε' => 'Published',
		'Έγκριση και αποστολή' => 'Approve and send',
		'Αποθήκευση κειμένου' => 'Save text',
		'Απόρριψη αυτής της ανάρτησης;' => 'Reject this post?',
		'Απόρριψη' => 'Reject',
		'Νέα ανάρτηση' => 'New post',
		'Μπαίνει στην ουρά και περιμένει το «Δημοσιεύτηκε», όπως όσα στέλνει μια ρουτίνα.' => 'It goes into the queue and waits for «Published», like the posts a routine sends.',
		'Κείμενο' => 'Text',
		'Σύνδεσμος (προαιρετικό)' => 'Link (optional)',
		'Εικόνα (URL https, προαιρετικό)' => 'Image (https URL, optional)',
		'Προσθήκη στην ουρά' => 'Add to the queue',
		'Όλες οι καταστάσεις' => 'All statuses',
		'Όλες οι πηγές' => 'All sources',
		'Από' => 'From',
		'Έως' => 'To',
		'Τίτλος' => 'Title',
		'Φίλτρο' => 'Filter',
		'CSV (Excel ελληνικά)' => 'CSV (Excel Greek)',
		'CSV (Τυπικό)' => 'CSV (Standard)',
		'%d εγγραφές' => '%d rows',
		'Πότε' => 'When',
		'Πηγή' => 'Source',
		'Κατάσταση' => 'Status',
		'Απάντηση' => 'Answer',
		'Καμία εγγραφή.' => 'No rows.',
		'Ξανά' => 'Retry',
		'Κάθε νέο προϊόν που δημοσιεύεται, με φίλτρο κατηγοριών. Οι τίτλοι του Book Feed μένουν έξω από προεπιλογή.' => 'Every new product that is published, with a category filter. Book Feed titles are left out by default.',
		'Όταν σταλεί μια καμπάνια στο MailerLite (webhook campaign.sent), ανάρτηση με τον τίτλο και σύνδεσμο. Το orOS Newsletter θα έρθει από το ίδιο σημείο.' => 'When a MailerLite campaign is sent (campaign.sent webhook), a post with its title and a link. orOS Newsletter will come in through the same route.',
		'Νέα βίντεο από τα κανάλια YouTube που ορίζεις, χωρίς κλειδί API. Τα Shorts μένουν έξω από προεπιλογή.' => 'New videos from the YouTube channels you choose, without an API key. Shorts are left out by default.',
		'Αναρτήσεις που ετοιμάζει μια ρουτίνα (π.χ. μέσω Site Bridge) ή εσύ, και φεύγουν όταν πατήσεις «Δημοσιεύτηκε».' => 'Posts prepared by a routine (e.g. through Site Bridge) or by you, sent when you press «Published».',
		'Ένα μήνυμα την εβδομάδα με τους νέους τίτλους άλλων εκδοτών από το Book Feed, αντί για ένα ανά τίτλο.' => 'One message a week with the new titles of other publishers from Book Feed, instead of one per title.',
		'Αναρτήσεις σε ένα Viber Channel μέσω του Channels Post API, με το token του καναλιού.' => 'Posts to a Viber Channel through the Channels Post API, with the channel\'s token.',
		'Ενεργό' => 'On',
		'Κλειστό' => 'Off',
		'Θέλει WooCommerce.' => 'Needs WooCommerce.',
		'Θέλει το Book Feed.' => 'Needs Book Feed.',
		'Ενεργοποίηση' => 'Switch on',
		'Χρειάζεται έγκριση' => 'Needs approval',
		'Instagram' => 'Instagram',
		'Σύντομα' => 'Coming soon',
		'Νέες αναρτήσεις του λογαριασμού Instagram σου (Business), μέσω δικής σου εφαρμογής Meta.' => 'New posts of your Instagram account (Business), through your own Meta app.',
		'Προορισμοί' => 'Destinations',
		'Telegram Channel' => 'Telegram Channel',
		'Οι ίδιες αναρτήσεις σε ένα κανάλι Telegram, με bot διαχειριστή.' => 'The same posts in a Telegram channel, with an admin bot.',
		'Facebook Page' => 'Facebook Page',
		'Οι ίδιες αναρτήσεις στη σελίδα σου στο Facebook.' => 'The same posts on your Facebook Page.',
		'Λειτουργία' => 'Mode',
		'Δοκιμή: η ουρά γεμίζει, αλλά τίποτα δεν φεύγει από το site' => 'Test: the queue fills, but nothing leaves the site',
		'Ενεργό: οι αναρτήσεις στέλνονται' => 'Live: posts are sent',
		'Ανενεργό: οι πηγές δεν προσθέτουν τίποτα' => 'Off: the sources add nothing',
		'Φρένο και ιστορικό' => 'Brake and history',
		'Έως τόσες αναρτήσεις ανά πηγή την ώρα' => 'At most this many posts per source per hour',
		'Έως τόσες αναρτήσεις ανά πηγή την ημέρα' => 'At most this many posts per source per day',
		'Ό,τι περισσεύει (π.χ. μαζική δημοσίευση προϊόντων) δεν χάνεται: περιμένει στην Ουρά για έγκριση.' => 'Anything over the limit (e.g. a bulk publish of products) is not lost: it waits in the Queue for approval.',
		'Το ιστορικό κρατά' => 'The history keeps',
		'ημέρες' => 'days',
		'Αποθήκευση ρυθμίσεων' => 'Save settings',
		'Από το wp-config.php' => 'From wp-config.php',
		'Αποθηκευμένο' => 'Saved',
		'Κενό πεδίο = μένει το τωρινό.' => 'Empty field = the current one stays.',
		'Το αποθηκευμένο μυστικό δεν διαβάζεται πια (άλλαξαν τα salts του wp-config.php). Γράψ\' το ξανά.' => 'The saved secret can no longer be read (the salts in wp-config.php changed). Enter it again.',
		'Αναμονή μετά τη δημοσίευση' => 'Wait after publishing',
		'λεπτά' => 'minutes',
		'Ώστε διορθώσεις τίτλου ή εικόνας αμέσως μετά τη δημοσίευση να μπαίνουν στην ανάρτηση. Μόνο νέα προϊόντα, ποτέ ενημερώσεις.' => 'So that title or image fixes made right after publishing are in the post. New products only, never updates.',
		'Μόνο από αυτές τις κατηγορίες (καμία = όλες)' => 'Only from these categories (none = all)',
		'Ποτέ από αυτές τις κατηγορίες' => 'Never from these categories',
		'Οι υποκατηγορίες μετρούν μαζί με τη γονική. Ctrl / Cmd για πολλές.' => 'Subcategories count with their parent. Ctrl / Cmd for several.',
		'Τύποι προϊόντων (κανένας = όλοι)' => 'Product types (none = all)',
		'Μόνο προϊόντα με εικόνα' => 'Only products with an image',
		'Χωρίς τους τίτλους που έφερε το Book Feed (άλλοι εκδότες)' => 'Without the titles Book Feed brought in (other publishers)',
		'Χωρίς τις κατηγορίες που κρύβει το Hide Catalog' => 'Without the categories Hide Catalog hides',
		'Newsletter (MailerLite)' => 'Newsletter (MailerLite)',
		'Στο MailerLite: Integrations → Webhooks → Create webhook, γεγονός «Campaign sent».' => 'In MailerLite: Integrations → Webhooks → Create webhook, event «Campaign sent».',
		'Διεύθυνση (URL) του webhook:' => 'Webhook address (URL):',
		'Αντέγραψε το Secret του webhook εδώ:' => 'Copy the webhook\'s Secret here:',
		'Secret του webhook' => 'Webhook secret',
		'Τελευταίο υπογεγραμμένο αίτημα από το MailerLite: %s' => 'Last signed request from MailerLite: %s',
		'Σύνδεσμος της ανάρτησης' => 'Link of the post',
		'Το MailerLite δεν στέλνει θέμα ούτε δημόσιο σύνδεσμο της καμπάνιας. Βάλε έναν σταθερό σύνδεσμο (π.χ. αρχείο newsletter ή σελίδα εγγραφής). Κενό = η ανάρτηση έχει μόνο τον τίτλο.' => 'MailerLite sends neither the subject nor a public link of the campaign. Set a fixed link (e.g. a newsletter archive or a sign-up page). Empty = the post has only the title.',
		'Χωρίς σταθερό σύνδεσμο: χρήση του preview_url της καμπάνιας (μόνο αν ανοίγει χωρίς σύνδεση στο MailerLite)' => 'Without a fixed link: use the campaign\'s preview_url (only if it opens without signing in to MailerLite)',
		'Κανάλια (ένα ανά γραμμή, έως 5): @handle, διεύθυνση καναλιού ή id UC…' => 'Channels (one per line, up to 5): @handle, channel address or UC… id',
		'Μαζί με τα Shorts' => 'Include Shorts',
		'Μαζί με τα live' => 'Include live streams',
		'Έλεγχος κάθε' => 'Check every',
		'Χωρίς κλειδί API: διαβάζεται το δημόσιο feed της λίστας «μεγάλα βίντεο» του καναλιού, όπου τα Shorts δεν εμφανίζονται. Στον πρώτο έλεγχο τα υπάρχοντα βίντεο απλώς σημειώνονται· αναρτώνται μόνο τα νέα.' => 'No API key: the plugin reads the public feed of the channel\'s «long videos» list, where Shorts do not appear. On the first check the existing videos are only noted; only new ones are posted.',
		'μόνο μεγάλα βίντεο (UULF)' => 'long videos only (UULF)',
		'όλα τα βίντεο' => 'all videos',
		'όλα τα βίντεο + έλεγχος Short ανά βίντεο' => 'all videos + a Short check per video',
		'δεν ελέγχθηκε ακόμα' => 'not checked yet',
		'Τελευταίος έλεγχος: %s' => 'Last check: %s',
		'Έλεγχος τώρα' => 'Check now',
		'Ημέρα' => 'Day',
		'Ώρα (του site)' => 'Hour (site time)',
		'Τίτλοι στη λίστα' => 'Titles in the list',
		'Σύνδεσμος του μηνύματος (κενό = το κατάστημα)' => 'Link of the message (empty = the shop)',
		'Τις τελευταίες 7 ημέρες δημοσιεύτηκαν %d τίτλοι άλλων εκδοτών.' => '%d titles of other publishers were published in the last 7 days.',
		'Το Book Feed δεν είναι ενεργό.' => 'Book Feed is not active.',
		'Σύνδεση' => 'Connection',
		'Συνδέθηκε' => 'Connected',
		'· %d συνδρομητές' => '· %d subscribers',
		'Συνδέθηκε: %s' => 'Connected: %s',
		'τελευταίο υπογεγραμμένο αίτημα από το Viber: %s' => 'last signed request from Viber: %s',
		'Η διεύθυνση του site άλλαξε από τη σύνδεση: πάτησε ξανά «Σύνδεση με το Viber».' => 'The site\'s address changed since the connection: press «Connect to Viber» again.',
		'Δεν συνδέθηκε' => 'Not connected',
		'Στο Viber (ως superadmin του καναλιού): Πληροφορίες καναλιού → Ρυθμίσεις → Developer Tools → αντιγραφή token.' => 'In Viber (as the channel\'s superadmin): Channel info → Settings → Developer Tools → copy the token.',
		'Επικόλλησέ το εδώ και πάτησε «Σύνδεση με το Viber». Το site δηλώνει το webhook του και διαβάζει το κανάλι.' => 'Paste it here and press «Connect to Viber». The site registers its webhook and reads the channel.',
		'Token του καναλιού' => 'Channel token',
		'Webhook του site (το δηλώνει η σύνδεση):' => 'The site\'s webhook (registered by the connection):',
		'Σύνδεση με το Viber' => 'Connect to Viber',
		'Αποθήκευση token' => 'Save token',
		'Αποσύνδεση από το Viber; Το token θα σβηστεί.' => 'Disconnect from Viber? The token will be deleted.',
		'Αποσύνδεση' => 'Disconnect',
		'Η Αποσύνδεση σβήνει το webhook στο Viber και το token από το site. Στη λειτουργία δοκιμής η σύνδεση γίνεται κανονικά, αλλά καμία ανάρτηση δεν φεύγει.' => 'Disconnect removes the webhook at Viber and the token from the site. In test mode the connection works as usual, but no post leaves.',
		'Ένα κείμενο ανά πηγή, στη γλώσσα του καναλιού. Μια γραμμή που μένει κενή επειδή η πηγή δεν έχει την τιμή (π.χ. τιμή) παραλείπεται. Κενό πρότυπο = το αρχικό.' => 'One text per source, in the channel\'s language. A line left empty because the source has no value for it (e.g. a price) is dropped. Empty template = the default one.',
		'Placeholders:' => 'Placeholders:',
		'Επαναφορά αρχικών' => 'Restore defaults',
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Χωρίς το token του Viber και το secret του newsletter: μετά την εισαγωγή μένουν όσα υπάρχουν. Οι διακόπτες των modules μένουν όπως είναι, για να τους ανοίξεις εσύ.' => 'Without the Viber token and the newsletter secret: after an import the existing ones stay. The module switches stay as they are, for you to switch on.',
		'Σβήνει όσα στάλθηκαν, απέτυχαν, παραλείφθηκαν ή απορρίφθηκαν. Όσα περιμένουν μένουν. Η προστασία από διπλές αναρτήσεις για αυτά χάνεται.' => 'Deletes what was sent, failed, skipped or rejected. Waiting items stay. The protection against duplicate posts is lost for the deleted ones.',
		'Να σβηστεί το ιστορικό;' => 'Delete the history?',
		'Καθαρισμός ιστορικού' => 'Clear the history',
		'Για developers' => 'For developers',
		'(ability)' => '(ability)',
		'Νέα ανάρτηση στην ουρά: { text, url, image_url | attachment_id, ref }. Περιμένει το «Δημοσιεύτηκε».' => 'A new post in the queue: { text, url, image_url | attachment_id, ref }. It waits for «Published».',
		'Τι περιμένει, αποστολή ενός ( { id, text } ), κατάσταση.' => 'What is waiting, sending one ( { id, text } ), status.',
		'(φίλτρο)' => '(filter)',
		'Αλλάζει ένα μήνυμα πριν μπει στην ουρά: ( $message, $source, $key ). Κενός πίνακας = δεν μπαίνει.' => 'Changes a message before it is queued: ( $message, $source, $key ). An empty array = not queued.',
		'(action)' => '(action)',
		'Μετά από κάθε ανάρτηση: ( $id, $item ).' => 'After each post: ( $id, $item ).',
		'Webhook «campaign.sent» του MailerLite, μόνο με σωστή υπογραφή.' => 'MailerLite\'s «campaign.sent» webhook, with a valid signature only.',
		'Στο wp-config.php: τα μυστικά από εκεί, με προτεραιότητα.' => 'In wp-config.php: the secrets from there, taking precedence.',
		'Στο wp-config.php: σταματά κάθε πηγή, κάθε ανάρτηση και κάθε webhook.' => 'In wp-config.php: stops every source, every post and every webhook.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Εγκρίθηκε. Λειτουργία δοκιμής: θα σημειωθεί «Δοκιμή» και τίποτα δεν θα φύγει.' => 'Approved. Test mode: it will be marked «Test» and nothing will leave.',
		'Εγκρίθηκε: φεύγει μέσα σε ένα λεπτό.' => 'Approved: it goes out within a minute.',
		'Το στοιχείο δεν περιμένει πια.' => 'The item is no longer waiting.',
		'Το κείμενο αποθηκεύτηκε.' => 'Text saved.',
		'Απορρίφθηκε.' => 'Rejected.',
		'Ξανά στην ουρά.' => 'Back in the queue.',
		'Το στοιχείο δεν έχει αποτύχει.' => 'The item has not failed.',
		'Ο έλεγχος του YouTube έγινε.' => 'The YouTube check is done.',
		'Τα πρότυπα επανήλθαν στα αρχικά.' => 'The templates are back to the defaults.',
		'Αποσυνδέθηκε. Το token σβήστηκε από το site.' => 'Disconnected. The token was deleted from the site.',
		'Όλες οι πηγές στέλνουν πια αυτόματα. Η έγκριση ανοίγει ξανά ανά πηγή στα Modules.' => 'Every source now sends automatically. Approval can be switched on again per source in Modules.',
		'Θα σε ρωτήσει ξανά σε 7 ημέρες.' => 'It will ask you again in 7 days.',
		'Η έγκριση μένει. Αλλάζει ανά πηγή στα Modules.' => 'Approval stays. It can be changed per source in Modules.',
		'Σβήστηκαν %d εγγραφές.' => '%d rows deleted.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Το module «Ουρά αναρτήσεων» είναι κλειστό.' => 'The «Post queue» module is off.',
		'Δεν προστέθηκε:' => 'Not added:',
		'Προστέθηκε στην ουρά.' => 'Added to the queue.',
		'Ο σύνδεσμος του newsletter θέλει https://. Δεν κρατήθηκε.' => 'The newsletter link needs https://. It was not kept.',
		'Το secret του newsletter αποθηκεύτηκε σφραγισμένο.' => 'The newsletter secret was saved sealed.',
		'Το secret δεν αποθηκεύτηκε: λείπει το OpenSSL (aes-256-gcm). Βάλ\' το ως σταθερά στο wp-config.php.' => 'The secret was not saved: OpenSSL (aes-256-gcm) is missing. Set it as a constant in wp-config.php.',
		'Δεν βρέθηκε κανάλι YouTube για: %s. Βάλε το Channel ID (ξεκινά με UC), από το youtube.com/account_advanced.' => 'No YouTube channel found for: %s. Enter the Channel ID (it starts with UC), from youtube.com/account_advanced.',
		'Κρατήθηκαν τα πρώτα 5 κανάλια.' => 'The first 5 channels were kept.',
		'Το token δεν μοιάζει με token του Viber (γράμματα, αριθμοί και -). Δεν κρατήθηκε.' => 'This does not look like a Viber token (letters, digits and -). It was not kept.',
		'Το token αποθηκεύτηκε σφραγισμένο.' => 'The token was saved sealed.',
		'Το token δεν αποθηκεύτηκε: λείπει το OpenSSL (aes-256-gcm). Βάλ\' το ως σταθερά στο wp-config.php.' => 'The token was not saved: OpenSSL (aes-256-gcm) is missing. Set it as a constant in wp-config.php.',
		'Ενεργοποίησε πρώτα το module Viber Channel στα Modules.' => 'Switch on the Viber Channel module in Modules first.',
		'✓ Συνδέθηκε με το κανάλι «%1$s» (%2$d συνδρομητές).' => '✓ Connected to the channel «%1$s» (%2$d subscribers).',
		'✗ Η σύνδεση απέτυχε: %s' => '✗ The connection failed: %s',
		'Προορισμός' => 'Destination',
		'Σύνδεσμος' => 'Link',
		'Αιτία' => 'Reason',
		'Προσπάθειες' => 'Tries',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.' => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Broadcast).' => 'Invalid backup file (the Broadcast settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε. Τα modules έμειναν όπως ήταν.' => 'Import done. The modules stayed as they were.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_broadcast', array( __CLASS__, 'filter' ), 10, 3 );
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
			$locale = $uid && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
			$lang   = self::from_locale( (string) $locale );
		}
		// Cache only once the user is known (not before set_current_user).
		if ( did_action( 'set_current_user' ) ) {
			self::$lang = $lang;
		}
		return $lang;
	}

	/** gettext_broadcast filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'broadcast' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
