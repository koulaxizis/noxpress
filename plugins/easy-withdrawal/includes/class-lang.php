<?php
/**
 * EWD_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * Front end: the language of the page (determine_locale(), which follows
 * WPML, Polylang and TranslatePress when one of them is active).
 *
 * Emails: the language stored with the request (customer emails) or the
 * site language (admin email), forced with with_lang() while the email is
 * built.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	/** Forced language while an email is built ('' = none). */
	private static $force = '';

	private static $dict = array(
		'Το Easy Withdrawal χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Easy Withdrawal requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Easy Withdrawal'                                         => 'Easy Withdrawal',
		'Easy Withdrawal:'                                        => 'Easy Withdrawal:',
		'Υπαναχωρήσεις'                                           => 'Withdrawals',
		'Easy Withdrawal — Ρυθμίσεις'                             => 'Easy Withdrawal — Settings',
		'EWD Ρυθμίσεις'                                           => 'EWD Settings',
		'Ρυθμίσεις'                                               => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'         => 'You do not have access to this page.',
		'Easy Withdrawal: δεν έχει οριστεί ακόμα η σελίδα της φόρμας υπαναχώρησης.' => 'Easy Withdrawal: the withdrawal form page is not set yet.',
		'Νέα'                                                     => 'New',
		'Σε εξέλιξη'                                              => 'In progress',
		'Ολοκληρώθηκε'                                            => 'Completed',
		'Αμφισβητείται'                                           => 'Disputed',
		'Εξατομικευμένο προϊόν: δεν επιστρέφεται.'                => 'Personalised product: it cannot be returned.',
		'Εξαιρείται από το δικαίωμα υπαναχώρησης.'                => 'Excluded from the right of withdrawal.',
		'Ψηφιακό περιεχόμενο που παραδόθηκε με τη συναίνεσή σου.' => 'Digital content supplied with your consent.',
		'Η ποσότητα που ζήτησες δεν είναι πια διαθέσιμη. Δες ξανά την παραγγελία.' => 'The quantity you asked for is no longer available. Please check the order again.',
		'Διάλεξε τουλάχιστον ένα προϊόν.'                         => 'Choose at least one product.',
		'Υπαναχώρηση %1$s: δήλωση του πελάτη για %2$s.'           => 'Withdrawal %1$s: customer statement for %2$s.',
		'Άγνωστη κατάσταση.'                                      => 'Unknown status.',
		'Η αμφισβήτηση χρειάζεται αιτιολογία: τη λαμβάνει ο πελάτης.' => 'A dispute needs a reason: the customer receives it.',
		'Τα προϊόντα του αιτήματος έχουν ζητηθεί ξανά σε νεότερο αίτημα.' => 'The request\'s products were asked for again in a newer request.',
		'Το αίτημα δεν βρέθηκε.'                                  => 'The request was not found.',
		'Υπαναχώρηση %1$s: κατάσταση «%2$s».'                     => 'Withdrawal %1$s: status "%2$s".',
		'Υπαναχώρηση: απόδειξη παραλαβής'                         => 'Withdrawal: acknowledgement of receipt',
		'Στέλνεται στον πελάτη αμέσως μετά τη δήλωση υπαναχώρησης, με το περιεχόμενό της και την ημερομηνία και ώρα υποβολής. Η Οδηγία 2023/2673 τη ζητά σε σταθερό μέσο: μην την απενεργοποιήσεις.' => 'Sent to the customer right after the withdrawal statement, with its content and the date and time of submission. Directive 2023/2673 requires it on a durable medium: do not disable it.',
		'Λάβαμε τη δήλωση υπαναχώρησης για την παραγγελία #{order_number}' => 'We received your withdrawal statement for order #{order_number}',
		'Λάβαμε τη δήλωση υπαναχώρησης'                           => 'We received your withdrawal statement',
		'Υπαναχώρηση: νέα δήλωση (κατάστημα)'                     => 'Withdrawal: new statement (store)',
		'Στέλνεται στους παραλήπτες των ρυθμίσεων του Easy Withdrawal όταν ένας πελάτης υποβάλει δήλωση υπαναχώρησης.' => 'Sent to the recipients in the Easy Withdrawal settings when a customer submits a withdrawal statement.',
		'Νέα υπαναχώρηση {request_id} για την παραγγελία #{order_number}' => 'New withdrawal {request_id} for order #{order_number}',
		'Νέα δήλωση υπαναχώρησης'                                 => 'New withdrawal statement',
		'Υπαναχώρηση: αλλαγή κατάστασης'                          => 'Withdrawal: status change',
		'Στέλνεται στον πελάτη όταν ένα αίτημα υπαναχώρησης ολοκληρωθεί ή αμφισβητηθεί, με τη σημείωση του καταστήματος.' => 'Sent to the customer when a withdrawal request is completed or disputed, with the store\'s note.',
		'Ενημέρωση για την υπαναχώρηση {request_id}'              => 'Update on withdrawal {request_id}',
		'Ενημέρωση για την υπαναχώρησή σου'                       => 'An update on your withdrawal',
		'Ο/Η %1$s υπέβαλε δήλωση υπαναχώρησης για την παραγγελία #%2$s.' => '%1$s submitted a withdrawal statement for order #%2$s.',
		'Άνοιγμα του αιτήματος'                                   => 'Open the request',
		'Γεια σου %s,'                                            => 'Hello %s,',
		'Λάβαμε τη δήλωσή σου ότι υπαναχωρείς από τη σύμβαση αγοράς των παρακάτω προϊόντων. Αυτό το email είναι η απόδειξη παραλαβής της: κράτησέ το.' => 'We received your statement that you withdraw from the contract of sale of the products below. This email is the acknowledgement of receipt: please keep it.',
		'Επόμενα βήματα'                                          => 'Next steps',
		'Η υπαναχώρηση %s ολοκληρώθηκε.'                          => 'Withdrawal %s is completed.',
		'Το κατάστημα αμφισβητεί την υπαναχώρηση %s, για τον λόγο που γράφει παρακάτω. Αν διαφωνείς, απάντησε σε αυτό το email.' => 'The store disputes withdrawal %s, for the reason given below. If you disagree, reply to this email.',
		'Αριθμός αιτήματος'                                       => 'Request number',
		'Υποβλήθηκε'                                              => 'Submitted',
		'Παραγγελία'                                              => 'Order',
		'Όνομα'                                                   => 'Name',
		'Email'                                                   => 'Email',
		'Αιτιολογία'                                              => 'Reason',
		'Προϊόν'                                                  => 'Product',
		'Ποσότητα'                                                => 'Quantity',
		'Αξία'                                                    => 'Value',
		'Υπαναχώρηση'                                             => 'Withdrawal',
		'Αίτημα'                                                  => 'Request',
		'Προϊόντα'                                                => 'Products',
		'Κατάσταση'                                               => 'Status',
		'Αν αλλάξεις γνώμη, μπορείς να υπαναχωρήσεις από την αγορά μέσα στην προθεσμία:' => 'If you change your mind, you can withdraw from the purchase within the withdrawal period:',
		'Η φόρμα έληξε. Ξεκίνα ξανά.'                             => 'The form has expired. Please start again.',
		'Συμπλήρωσε το όνομά σου.'                                => 'Please enter your name.',
		'Κάτι πήγε στραβά και η δήλωση δεν καταγράφηκε. Δοκίμασε ξανά ή επικοινώνησε με το κατάστημα.' => 'Something went wrong and the statement was not recorded. Please try again or contact the store.',
		'Λειτουργία δοκιμής: τη φόρμα τη βλέπουν μόνο οι διαχειριστές του καταστήματος.' => 'Test mode: only the store\'s managers can see this form.',
		'Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.' => 'Too many failed attempts. Please try again in 15 minutes.',
		'Δεν βρέθηκε παραγγελία με αυτόν τον αριθμό και αυτό το email. Έλεγξε το email επιβεβαίωσης της παραγγελίας.' => 'No order was found with this number and this email. Please check your order confirmation email.',
		'Επιβεβαίωση'                                             => 'Confirmation',
		'Απόδειξη'                                                => 'Receipt',
		'Μπορείς να υπαναχωρήσεις από την αγορά σου μέσα στην προθεσμία, χωρίς να δώσεις αιτιολογία. Βρες την παραγγελία σου για να ξεκινήσεις.' => 'You can withdraw from your purchase within the withdrawal period, without giving a reason. Find your order to start.',
		'Οι παραγγελίες σου'                                      => 'Your orders',
		'Επιλογή'                                                 => 'Select',
		'Άλλη παραγγελία'                                         => 'Another order',
		'Αριθμός παραγγελίας'                                     => 'Order number',
		'Email της παραγγελίας'                                   => 'Order email',
		'Εύρεση παραγγελίας'                                      => 'Find order',
		'Παραγγελία #%s'                                          => 'Order #%s',
		'Δεν υπάρχουν προϊόντα αυτής της παραγγελίας για τα οποία μπορείς να υπαναχωρήσεις: η προθεσμία έληξε, έχουν ήδη ζητηθεί ή επιστραφεί, ή εξαιρούνται.' => 'There are no products in this order you can withdraw from: the period has ended, they were already requested or refunded, or they are excluded.',
		'Αγοράστηκαν'                                             => 'Purchased',
		'Υπαναχώρηση για'                                         => 'Withdraw',
		'Ποσότητα για %s'                                         => 'Quantity for %s',
		'Ονοματεπώνυμο'                                           => 'Full name',
		'Αιτιολογία (προαιρετική)'                                => 'Reason (optional)',
		'Η απόδειξη θα σταλεί στο %s.'                            => 'The receipt will be sent to %s.',
		'Συνέχεια με τις ποσότητες που διάλεξα'                   => 'Continue with the quantities I chose',
		'Συνέχεια με όλα τα προϊόντα'                             => 'Continue with all products',
		'Η προθεσμία υπαναχώρησης έληξε.'                         => 'The withdrawal period has ended.',
		'Έχει ήδη ζητηθεί ή επιστραφεί.'                          => 'Already requested or refunded.',
		'Προθεσμία έως %s.'                                       => 'Withdrawal possible until %s.',
		'Προηγούμενες δηλώσεις'                                   => 'Earlier statements',
		'Έλεγχος και επιβεβαίωση'                                 => 'Review and confirm',
		'Εγώ, %1$s, δηλώνω ότι υπαναχωρώ από τη σύμβαση αγοράς των παρακάτω προϊόντων της παραγγελίας #%2$s της %3$s:' => 'I, %1$s, hereby give notice that I withdraw from my contract of sale of the following products of order #%2$s of %3$s:',
		'Επιβεβαίωση υπαναχώρησης'                                => 'Confirm withdrawal',
		'Πίσω'                                                    => 'Back',
		'Η δήλωσή σου καταγράφηκε'                                => 'Your statement has been recorded',
		'Αίτημα %1$s, %2$s. Στείλαμε την απόδειξη στο %3$s.'      => 'Request %1$s, %2$s. We sent the receipt to %3$s.',
		'Εξατομικευμένο'                                          => 'Personalised',
		'Φτιάχνεται κατά παραγγελία ή με προδιαγραφές του πελάτη: εξαιρείται από την υπαναχώρηση (Easy Withdrawal).' => 'Made to order or to the customer\'s specifications: excluded from withdrawal (Easy Withdrawal).',
		'Για τα ψηφιακά προϊόντα χρειάζεται η συναίνεσή σου στην άμεση παράδοση.' => 'The digital products need your consent to immediate supply.',
		'Υπαναχώρηση %s'                                          => 'Withdrawal %s',
		'Οι δηλώσεις υπαναχώρησης των πελατών. Η δήλωση ισχύει από τη στιγμή της υποβολής: εδώ καταγράφεις την πορεία της επιστροφής.' => 'Your customers\' withdrawal statements. A statement takes effect when it is submitted: here you record how the return goes.',
		'Ανενεργό'                                                => 'Off',
		'Λειτουργία δοκιμής — η φόρμα και οι σύνδεσμοι φαίνονται μόνο στους διαχειριστές του καταστήματος' => 'Test mode — the form and the links are visible to shop managers only',
		'Ενεργό για όλους τους πελάτες'                           => 'Live for all customers',
		'Απενεργοποιημένο από το EWD_DISABLE (wp-config.php)'     => 'Disabled by EWD_DISABLE (wp-config.php)',
		'Αλλαγή'                                                  => 'Change',
		'Σελίδα φόρμας:'                                          => 'Form page:',
		'Δεν έχει οριστεί'                                        => 'Not set',
		'Δεν περιέχει το shortcode [nox_withdrawal]'              => 'Does not contain the shortcode [nox_withdrawal]',
		'Απόδειξη στον πελάτη:'                                   => 'Customer receipt:',
		'Ενεργή'                                                  => 'Enabled',
		'Ανενεργή: η Οδηγία ζητά απόδειξη σε σταθερό μέσο'        => 'Disabled: the Directive requires a receipt on a durable medium',
		'Emails'                                                  => 'Emails',
		'Αιτήματα'                                                => 'Requests',
		'Όλες οι καταστάσεις'                                     => 'All statuses',
		'Αριθμός παραγγελίας, αιτήματος ή email'                  => 'Order number, request number or email',
		'Φιλτράρισμα'                                             => 'Filter',
		'Εξαγωγή CSV'                                             => 'Export CSV',
		'Το φίλτρο κατάστασης δείχνει τις παραγγελίες όπου το πιο επείγον αίτημα έχει αυτή την κατάσταση (σειρά: Νέα, Σε εξέλιξη, Αμφισβητείται, Ολοκληρώθηκε).' => 'The status filter shows the orders whose most urgent request has this status (order: New, In progress, Disputed, Completed).',
		'Πελάτης'                                                 => 'Customer',
		'Δεν υπάρχουν αιτήματα.'                                  => 'There are no requests.',
		'Όλα τα αιτήματα'                                         => 'All requests',
		'Άνοιγμα παραγγελίας'                                     => 'Open order',
		'Υποβλήθηκε:'                                             => 'Submitted:',
		'Αιτιολογία:'                                             => 'Reason:',
		'Επιστροφή χρημάτων στο WooCommerce'                      => 'Refund in WooCommerce',
		'Ανοίγει την παραγγελία με τη φόρμα επιστροφής χρημάτων συμπληρωμένη με τα προϊόντα του αιτήματος. Ελέγχεις τα ποσά (και τα μεταφορικά) και πατάς εσύ την επιστροφή.' => 'Opens the order with the refund form filled in with the request\'s products. You check the amounts (and shipping) and click the refund yourself.',
		'Νέα κατάσταση'                                           => 'New status',
		'Σημείωση (υποχρεωτική για την αμφισβήτηση: τη λαμβάνει ο πελάτης)' => 'Note (required for a dispute: the customer receives it)',
		'Αποθήκευση'                                              => 'Save',
		'Τα «Ολοκληρώθηκε» και «Αμφισβητείται» στέλνουν email στον πελάτη.' => '"Completed" and "Disputed" email the customer.',
		'Ιστορικό'                                                => 'History',
		'Επισκέπτης'                                              => 'Guest',
		'Λειτουργία'                                              => 'Mode',
		'Λειτουργία δοκιμής: η φόρμα, τα κουμπιά και η συναίνεση στο checkout φαίνονται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: the form, the buttons and the checkout consent are visible to shop managers only',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Σελίδα φόρμας'                                           => 'Form page',
		'— Καμία —'                                               => '— None —',
		'Η σελίδα πρέπει να περιέχει το shortcode [nox_withdrawal]. Τα κουμπιά του λογαριασμού και οι σύνδεσμοι των emails οδηγούν εδώ. Βάλε έναν σύνδεσμο προς αυτή τη σελίδα στο μενού ή στο footer του site, ώστε η φόρμα να είναι εύκολα προσβάσιμη.' => 'The page must contain the shortcode [nox_withdrawal]. The account buttons and the email links lead here. Put a link to this page in the site\'s menu or footer so the form is easy to reach.',
		'Προθεσμία'                                               => 'Withdrawal period',
		'Ημέρες υπαναχώρησης'                                     => 'Withdrawal days',
		'Τουλάχιστον 14 (Οδηγία 2011/83).'                        => 'At least 14 (Directive 2011/83).',
		'Ημέρες παράδοσης μετά το «Ολοκληρώθηκε»'                 => 'Delivery days after "Completed"',
		'Η προθεσμία μετρά από την παραλαβή, που το κατάστημα δεν γνωρίζει. Για αγαθά η φόρμα μένει ανοιχτή από τη δημιουργία της παραγγελίας μέχρι «Ολοκληρώθηκε» + παράδοση + ημέρες υπαναχώρησης. Για εικονικά προϊόντα: ημέρες υπαναχώρησης από την πληρωμή.' => 'The period runs from delivery, which the store does not know. For goods the form stays open from the order\'s creation until "Completed" + delivery + withdrawal days. For virtual products: withdrawal days from payment.',
		'Κείμενα'                                                 => 'Texts',
		'Κουμπί και σύνδεσμοι (Ελληνικά)'                         => 'Button and links (Greek)',
		'Οδηγίες επιστροφής (Ελληνικά)'                           => 'Return instructions (Greek)',
		'Κουμπί και σύνδεσμοι (Αγγλικά)'                          => 'Button and links (English)',
		'Οδηγίες επιστροφής (Αγγλικά)'                            => 'Return instructions (English)',
		'Η Οδηγία ζητά ετικέτα σαν «Υπαναχώρηση από τη σύμβαση εδώ» ή εξίσου σαφή. Οι οδηγίες επιστροφής (διεύθυνση, τρόπος αποστολής, προθεσμία 14 ημερών για την επιστροφή των προϊόντων) μπαίνουν στην απόδειξη και στη σελίδα επιβεβαίωσης.' => 'The Directive asks for a label like "Withdraw from contract here" or one as clear. The return instructions (address, shipping method, the 14 days to send the products back) go into the receipt and the confirmation page.',
		'Ειδοποιήσεις'                                            => 'Notifications',
		'Παραλήπτες της ειδοποίησης για νέα δήλωση'               => 'Recipients of the new statement notice',
		'Χωρισμένοι με κόμμα, έως 10. Κενό = η διεύθυνση αποστολέα του WooCommerce.' => 'Comma separated, up to 10. Empty = WooCommerce\'s sender address.',
		'Θέμα, επικεφαλίδα και μορφή των emails: WooCommerce → Ρυθμίσεις → Emails' => 'Subject, heading and format of the emails: WooCommerce → Settings → Emails',
		'Εξαιρέσεις'                                              => 'Exclusions',
		'Τα εξαιρούμενα προϊόντα φαίνονται στη φόρμα γκρι, με την αιτία. Για εξατομικευμένα προϊόντα υπάρχει το πεδίο «Εξατομικευμένο» στη σελίδα κάθε προϊόντος (Γενικά).' => 'Excluded products are shown dimmed in the form, with the reason. For personalised products, use the "Personalised" field on each product (General tab).',
		'Κατηγορίες (μαζί με τις υποκατηγορίες τους)'             => 'Categories (with their subcategories)',
		'Δεν υπάρχουν κατηγορίες.'                                => 'There are no categories.',
		'Προϊόντα (IDs χωρισμένα με κόμμα)'                       => 'Products (comma-separated IDs)',
		'(δεν βρέθηκε)'                                           => '(not found)',
		'Ψηφιακό περιεχόμενο (εικονικά ή με λήψη αρχείου)'        => 'Digital content (virtual or downloadable)',
		'Ζήτα στο checkout τη συναίνεση του πελάτη και εξαίρεσε τα ψηφιακά προϊόντα των παραγγελιών όπου δόθηκε' => 'Ask for the customer\'s consent at checkout and exclude the digital products of the orders where it was given',
		'Χωρίς τη συναίνεση, τα ψηφιακά προϊόντα μένουν επιστρέψιμα: η εξαίρεση του νόμου ισχύει μόνο όταν ο πελάτης ζήτησε άμεση παράδοση και αναγνώρισε ότι χάνει το δικαίωμα. Δουλεύει στο κλασικό checkout και στο checkout block (WooCommerce 9.9+).' => 'Without the consent, digital products stay returnable: the legal exception applies only when the customer asked for immediate supply and acknowledged losing the right. Works with the classic checkout and the checkout block (WooCommerce 9.9+).',
		'Κείμενο συναίνεσης (Ελληνικά)'                           => 'Consent text (Greek)',
		'Κείμενο συναίνεσης (Αγγλικά)'                            => 'Consent text (English)',
		'Σύνδεσμοι'                                               => 'Links',
		'Σύνδεσμος υπαναχώρησης στα emails παραγγελίας του πελάτη και στη σελίδα ευχαριστίας' => 'Withdrawal link in the customer\'s order emails and on the thank-you page',
		'Αυτόματος σύνδεσμος στο τέλος κάθε σελίδας (wp_footer)'  => 'Automatic link at the end of every page (wp_footer)',
		'Ο αυτόματος σύνδεσμος βγαίνει όπου το θέμα τυπώνει το wp_footer, σε κάποια θέματα κάτω από το footer. Ένας σύνδεσμος στο μενού του footer είναι συνήθως καλύτερος.' => 'The automatic link appears where the theme prints wp_footer, below the footer in some themes. A link in the footer menu is usually better.',
		'Αποθήκευση ρυθμίσεων'                                    => 'Save settings',
		'Νέα σελίδα φόρμας'                                       => 'New form page',
		'Δημιουργία σελίδας με το shortcode'                      => 'Create a page with the shortcode',
		'Δημιουργεί μια δημοσιευμένη σελίδα «Υπαναχώρηση» με το [nox_withdrawal] και την ορίζει ως σελίδα φόρμας.' => 'Creates a published "Withdrawal" page with [nox_withdrawal] and sets it as the form page.',
		'Επιστροφή χρημάτων'                                      => 'Refund',
		'Διαχείριση αιτημάτων'                                    => 'Manage requests',
		'Άγνωστη ενέργεια.'                                       => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                             => 'Settings saved.',
		'Οι ημέρες υπαναχώρησης είναι από 14 έως 365: η τιμή διορθώθηκε.' => 'Withdrawal days are 14 to 365: the value was corrected.',
		'Το plugin είναι ενεργό αλλά δεν έχει οριστεί δημοσιευμένη σελίδα φόρμας: τα κουμπιά και οι σύνδεσμοι δεν εμφανίζονται.' => 'The plugin is live but no published form page is set: the buttons and links do not appear.',
		'Κάποιες διευθύνσεις παραληπτών δεν ήταν έγκυρες και αγνοήθηκαν.' => 'Some recipient addresses were not valid and were ignored.',
		'Χρειάζεται δικαίωμα δημοσίευσης σελίδων.'                => 'You need permission to publish pages.',
		'Η σελίδα δημιουργήθηκε και ορίστηκε ως σελίδα φόρμας.'   => 'The page was created and set as the form page.',
		'Η κατάσταση αποθηκεύτηκε.'                               => 'Status saved.',
		'Made with ❤ by %s'                                       => 'Made with ❤ by %s',
		'More plugins at'                                         => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                          => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                      => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_easy-withdrawal', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/** 'el' | 'en' for a locale string. */
	public static function from_locale( string $locale ): string {
		return ( 0 === strpos( strtolower( $locale ), 'el' ) ) ? 'el' : 'en';
	}

	/** The site language ('el' | 'en'), independent of the current user. */
	public static function site(): string {
		return self::from_locale( (string) get_locale() );
	}

	/** 'el' | 'en' for the current context. */
	public static function lang(): string {
		if ( '' !== self::$force ) {
			return self::$force;
		}
		if ( null !== self::$lang ) {
			return self::$lang;
		}
		$admin = is_admin() && ! ( wp_doing_ajax() && ! is_user_logged_in() );
		if ( ! $admin ) {
			$lang = self::from_locale( function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale() );
			if ( did_action( 'wp' ) ) {
				self::$lang = $lang;
			}
			return $lang;
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

	/**
	 * Run $fn with the language forced to $lang ('el' | 'en'), then restore.
	 *
	 * @param string   $lang Language.
	 * @param callable $fn   Callback.
	 * @return mixed The callback's result.
	 */
	public static function with_lang( string $lang, callable $fn ) {
		$prev        = self::$force;
		self::$force = ( 'en' === $lang ) ? 'en' : 'el';
		try {
			return $fn();
		} finally {
			self::$force = $prev;
		}
	}

	/** gettext_easy-withdrawal filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'easy-withdrawal' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/**
	 * Date and time with the site's UTC offset, e.g. "10/10/2026 17:42:05 (UTC+03:00)".
	 *
	 * @param int $ts Unix timestamp (UTC).
	 */
	public static function datetime( int $ts ): string {
		$fmt = ( 'el' === self::lang() ) ? 'd/m/Y H:i:s' : 'Y-m-d H:i:s';
		return wp_date( $fmt, $ts ) . ' (UTC' . wp_date( 'P', $ts ) . ')';
	}

	/** Date only, in the language's order. */
	public static function date( int $ts ): string {
		return wp_date( ( 'el' === self::lang() ) ? 'd/m/Y' : 'Y-m-d', $ts );
	}
}
