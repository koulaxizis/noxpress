<?php
/**
 * CKE_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts customers see are store settings with a Greek and an English
 * value (Bible §14.17, CKE_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Checkout Extras χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Checkout Extras requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Κουπόνι ευχαριστίας' => 'Thank-you coupon',
		'Στέλνεται στον πελάτη όταν δημιουργείται το κουπόνι ευχαριστίας, μόνο αν στο Checkout Extras έχεις διαλέξει «Χωριστό email». Αλλιώς το κουπόνι μπαίνει στο email της παραγγελίας.' => 'Sent to the customer when the thank-you coupon is created, only if "Separate email" is chosen in Checkout Extras. Otherwise the coupon goes into the order email.',
		'Ένα δώρο από το {site_title} για την επόμενη παραγγελία σας' => 'A gift from {site_title} for your next order',
		'{amount} έκπτωση για εσάς' => '{amount} off for you',
		'Υπενθύμιση κουπονιού' => 'Coupon reminder',
		'Στέλνεται στον πελάτη λίγες ημέρες πριν λήξει ένα κουπόνι ευχαριστίας που δεν χρησιμοποιήθηκε, αν η υπενθύμιση είναι ενεργή στο Checkout Extras.' => 'Sent to the customer a few days before an unused thank-you coupon expires, if the reminder is on in Checkout Extras.',
		'Το κουπόνι σας λήγει σύντομα' => 'Your coupon expires soon',
		'Μη χάσετε την έκπτωσή σας' => 'Don\'t miss your discount',
		'Checkout Extras: κουπόνι ευχαριστίας για την παραγγελία #%s' => 'Checkout Extras: thank-you coupon for order #%s',
		'Checkout Extras: δημιουργήθηκε το κουπόνι ευχαριστίας %s.' => 'Checkout Extras: the thank-you coupon %s was created.',
		'Δώρο' => 'Gift',
		'Η παραγγελία δεν είναι δώρο.' => 'The order is not a gift.',
		'Ο πελάτης σημείωσε ότι είναι δώρο.' => 'The customer marked it as a gift.',
		'Αφιέρωση:' => 'Message:',
		'Χρέωση συσκευασίας:' => 'Gift wrapping fee:',
		'Όλες οι παραγγελίες' => 'All orders',
		'Μόνο δώρα' => 'Gifts only',
		'Σελίδα ευχαριστίας' => 'Thank-you page',
		'Κουπόνι' => 'Coupon',
		'Στατιστικά' => 'Statistics',
		'Εργαλεία' => 'Tools',
		'Checkout Extras' => 'Checkout Extras',
		'Ταμείο' => 'Checkout',
		'Ρυθμίσεις' => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Δώρο στο ταμείο, μήνυμα και προϊόντα στη σελίδα ευχαριστίας, και κουπόνι ευχαριστίας για την επόμενη παραγγελία. Κάθε μέρος ενεργοποιείται χωριστά.' => 'A gift option at checkout, a message and products on the thank-you page, and a thank-you coupon for the next order. Each part is turned on separately.',
		'Το CKE_DISABLE είναι ενεργό στο wp-config.php: τίποτα δεν εμφανίζεται στο ταμείο και στη σελίδα ευχαριστίας, και δεν δημιουργούνται κουπόνια.' => 'CKE_DISABLE is on in wp-config.php: nothing shows at checkout or on the thank-you page, and no coupons are created.',
		'Ανενεργό' => 'Off',
		'Ενεργό για όλους' => 'On for everyone',
		'Κείμενο' => 'Text',
		'Ελληνικά' => 'Greek',
		'English' => 'English',
		'Κενό αγγλικό κείμενο = δείχνει το ελληνικό. Η γλώσσα των κειμένων ορίζεται στα Εργαλεία.' => 'Empty English text = shows the Greek. The language of the texts is set in Tools.',
		'Δεν υπάρχουν κατηγορίες προϊόντων.' => 'There are no product categories.',
		'Λειτουργία' => 'Mode',
		'Λειτουργία δοκιμής: το κουτάκι φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: only the store\'s managers see the checkbox',
		'Ο πελάτης τσεκάρει «Είναι δώρο» στο ταμείο. Η παραγγελία σημειώνεται ως δώρο στο admin, στα emails και στη σελίδα ευχαριστίας, μαζί με την αφιέρωση αν υπάρχει.' => 'The customer ticks "This is a gift" at checkout. The order is marked as a gift in the admin, in the emails and on the thank-you page, with the message if there is one.',
		'Πριν από τα στοιχεία του πελάτη' => 'Before the customer details',
		'Μετά τις σημειώσεις παραγγελίας' => 'After the order notes',
		'Πριν από το κουμπί «Ολοκλήρωση παραγγελίας»' => 'Before the "Place order" button',
		'Θέση στο ταμείο' => 'Position at checkout',
		'Μόνο όταν το καλάθι έχει προϊόν που αποστέλλεται (όχι για καλάθια μόνο με ψηφιακά προϊόντα)' => 'Only when the cart has a product that ships (not for carts with digital products only)',
		'Χρέωση συσκευασίας' => 'Gift wrapping fee',
		'Κόστος' => 'Cost',
		'0 = χωρίς χρέωση' => '0 = no charge',
		'Χωρίς φόρο' => 'No tax',
		'Φορολογήσιμο' => 'Taxable',
		'Φόρος' => 'Tax',
		'Βασικός συντελεστής' => 'Standard rate',
		'Κλάση φόρου' => 'Tax class',
		'Με κόστος μπαίνει γραμμή χρέωσης στην παραγγελία (όνομα από τα κείμενα παρακάτω). Όταν οι τιμές του καταστήματος περιλαμβάνουν ΦΠΑ, το ποσό περιλαμβάνει κι αυτό ΦΠΑ.' => 'With a cost, a fee line is added to the order (its name comes from the texts below). When the store\'s prices include VAT, the amount includes VAT as well.',
		'Η σελίδα ταμείου σου είναι το Checkout block: εκεί το δώρο εμφανίζεται μόνο χωρίς κόστος (το block δεν δέχεται χρέωση από ένα πεδίο). Με κόστος, χρησιμοποίησε το κλασικό ταμείο [woocommerce_checkout].' => 'Your checkout page is the Checkout block: there the gift option shows only without a cost (the block does not accept a fee from a field). With a cost, use the classic checkout [woocommerce_checkout].',
		'Αφιέρωση' => 'Gift message',
		'Χωρίς αφιέρωση' => 'No message',
		'Προαιρετική' => 'Optional',
		'Υποχρεωτική όταν είναι δώρο' => 'Required when it is a gift',
		'Πεδίο αφιέρωσης' => 'Message field',
		'Μέγιστο μήκος' => 'Maximum length',
		'χαρακτήρες (20–1000)' => 'characters (20–1000)',
		'Για το κατάστημα' => 'For the store',
		'Γραμμή «🎁 Δώρο» και η αφιέρωση στην κορυφή του email «Νέα παραγγελία» του διαχειριστή' => 'A "🎁 Gift" line and the message at the top of the admin "New order" email',
		'Στήλη 🎁 στη λίστα παραγγελιών (με φίλτρο «Μόνο δώρα»)' => 'A 🎁 column in the orders list (with a "Gifts only" filter)',
		'Κείμενα για τον πελάτη' => 'Texts for the customer',
		'Το κουτάκι στο ταμείο' => 'The checkbox at checkout',
		'Βοήθεια κάτω από το κουτάκι (προαιρετικό, π.χ. «Δεν βάζουμε απόδειξη στο δέμα»)' => 'Help under the checkbox (optional, e.g. "We do not put the receipt in the parcel")',
		'Όνομα της χρέωσης στην παραγγελία' => 'Name of the fee on the order',
		'Ετικέτα της αφιέρωσης' => 'Label of the message',
		'Σφάλμα: λείπει η αφιέρωση' => 'Error: the message is missing',
		'Σφάλμα: η αφιέρωση είναι μεγάλη ({max} = το όριο)' => 'Error: the message is too long ({max} = the limit)',
		'Γραμμή στην παραγγελία και στα emails' => 'Row on the order and in the emails',
		'Τιμή της γραμμής' => 'Value of the row',
		'Αποθήκευση' => 'Save',
		'Λειτουργία δοκιμής: το μήνυμα και τα προϊόντα φαίνονται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: only the store\'s managers see the message and the products',
		'Το μήνυμα και τα προϊόντα μπαίνουν μέσα στη σελίδα ευχαριστίας του θέματος και του WooCommerce, χωρίς αλλαγή στο layout. Δουλεύουν και στο Order confirmation των block themes.' => 'The message and the products go inside the theme\'s and WooCommerce\'s thank-you page, with no change to the layout. They also work in the Order confirmation of block themes.',
		'Το μήνυμα στη θέση του «Ευχαριστούμε. Η παραγγελία σας ελήφθη.» του WooCommerce (αλλιώς σε δικό του κουτί από πάνω)' => 'The message in place of WooCommerce\'s "Thank you. Your order has been received." (otherwise in its own box above)',
		'Προεπισκόπηση με την παραγγελία #%s' => 'Preview with order #%s',
		'Η προεπισκόπηση δεν αλλάζει τίποτα και δεν δημιουργεί κουπόνι.' => 'The preview changes nothing and creates no coupon.',
		'Μήνυμα' => 'Message',
		'Placeholders: {first_name}, {order_number}, {order_total}, {shop_name}, {email}. Η πρόταση με placeholder χωρίς τιμή φεύγει ολόκληρη. Αλλαγές γραμμής κρατιούνται. Το «αναμένεται πληρωμή» ισχύει για εκκρεμείς παραγγελίες και παραγγελίες σε αναμονή (π.χ. τραπεζική κατάθεση)· κενό = το μήνυμα της πληρωμένης.' => 'Placeholders: {first_name}, {order_number}, {order_total}, {shop_name}, {email}. A sentence with a placeholder that has no value is dropped whole. Line breaks are kept. "Awaiting payment" applies to pending and on-hold orders (e.g. bank transfer); empty = the paid message.',
		'Πληρωμένη παραγγελία: τίτλος' => 'Paid order: title',
		'Πληρωμένη παραγγελία: κείμενο' => 'Paid order: text',
		'Αναμένεται πληρωμή: τίτλος' => 'Awaiting payment: title',
		'Αναμένεται πληρωμή: κείμενο' => 'Awaiting payment: text',
		'Προϊόντα' => 'Products',
		'Κανένα' => 'None',
		'Cross-sells των προϊόντων της παραγγελίας' => 'Cross-sells of the ordered products',
		'Σχετικά προϊόντα' => 'Related products',
		'Προϊόντα σε προσφορά' => 'Products on sale',
		'Μετά τα στοιχεία της παραγγελίας' => 'After the order details',
		'Πλήθος' => 'Count',
		'Με τις κάρτες προϊόντων του θέματος. Δεν δείχνει όσα αγόρασε ήδη ο πελάτης ούτε εξαντλημένα.' => 'With the theme\'s product cards. Does not show what the customer already bought or out-of-stock products.',
		'Τίτλος της ενότητας' => 'Section title',
		'Λειτουργία δοκιμής: κουπόνια μόνο για παραγγελίες λογαριασμών διαχειριστή του καταστήματος' => 'Test mode: coupons only for orders of the store\'s manager accounts',
		'Ο πελάτης παίρνει ένα μοναδικό κουπόνι για την επόμενη παραγγελία του, όταν η παραγγελία του φτάσει σε μία από τις καταστάσεις παρακάτω. Ποτέ σε απλήρωτες, αποτυχημένες ή ακυρωμένες παραγγελίες.' => 'The customer gets a unique coupon for their next order when their order reaches one of the statuses below. Never on unpaid, failed or cancelled orders.',
		'Δημιουργία όταν η παραγγελία γίνει' => 'Create when the order becomes',
		'Το «Σε αναμονή» (π.χ. τραπεζική κατάθεση, αντικαταβολή) σημαίνει κουπόνι πριν από την πληρωμή: άφησέ το κλειστό, εκτός αν το θέλεις.' => '"On hold" (e.g. bank transfer, cash on delivery) means a coupon before payment: leave it unticked unless you want that.',
		'Ποιος παίρνει κουπόνι' => 'Who gets a coupon',
		'Ελάχιστο σύνολο παραγγελίας' => 'Minimum order total',
		'κενό = χωρίς όριο' => 'empty = no limit',
		'Όχι σε παραγγελία που χρησιμοποίησε ήδη κουπόνι ευχαριστίας' => 'Not on an order that already used a thank-you coupon',
		'Ένα ενεργό κουπόνι ανά email: όχι νέο όσο το προηγούμενο δεν έχει χρησιμοποιηθεί ή λήξει' => 'One active coupon per email: no new one while the previous one is unused and not expired',
		'Ημέρες ανάμεσα σε δύο κουπόνια' => 'Days between two coupons',
		'ανά email · 0 = χωρίς όριο' => 'per email · 0 = no limit',
		'Μόνο στην πρώτη παραγγελία ενός email' => 'Only on the first order of an email',
		'Όχι για παραγγελίες που έχουν μόνο προϊόντα από αυτές τις κατηγορίες' => 'Not for orders that only have products from these categories',
		'Το κουπόνι' => 'The coupon',
		'Ποσοστό στο καλάθι' => 'Percentage on the cart',
		'Σταθερό ποσό στο καλάθι' => 'Fixed amount on the cart',
		'Τύπος έκπτωσης' => 'Discount type',
		'Έκπτωση' => 'Discount',
		'% ή ποσό' => '% or amount',
		'Λήξη' => 'Expiry',
		'ημέρες μετά τη δημιουργία' => 'days after creation',
		'Ελάχιστη δαπάνη' => 'Minimum spend',
		'Μέγιστη δαπάνη' => 'Maximum spend',
		'Μόνο σε τόσα τεμάχια' => 'Only on this many items',
		'0 = σε όλο το καλάθι' => '0 = on the whole cart',
		'Δίνει και δωρεάν αποστολή' => 'Also gives free shipping',
		'Μόνο του: δεν συνδυάζεται με άλλα κουπόνια' => 'Individual use: does not combine with other coupons',
		'Όχι σε προϊόντα σε προσφορά' => 'Not on products on sale',
		'Μόνο για το email χρέωσης της παραγγελίας' => 'Only for the order\'s billing email',
		'Πρόθεμα κωδικού' => 'Code prefix',
		'έως 10 πεζά λατινικά, ψηφία ή «-», και 8 τυχαίοι χαρακτήρες' => 'up to 10 lowercase Latin letters, digits or "-", and 8 random characters',
		'Κάθε κουπόνι είναι ένα κανονικό κουπόνι του WooCommerce, μίας χρήσης. Οι αλλαγές εδώ ισχύουν για τα νέα κουπόνια, όχι για όσα έχουν ήδη δοθεί.' => 'Each coupon is a normal single-use WooCommerce coupon. Changes here apply to new coupons, not to those already given.',
		'Περιορισμοί σε προϊόντα και κατηγορίες' => 'Product and category restrictions',
		'Μόνο για αυτά τα προϊόντα (ids, με κόμμα)' => 'Only for these products (ids, comma-separated)',
		'Όχι για αυτά τα προϊόντα (ids, με κόμμα)' => 'Not for these products (ids, comma-separated)',
		'Μόνο για αυτές τις κατηγορίες' => 'Only for these categories',
		'Όχι για αυτές τις κατηγορίες' => 'Not for these categories',
		'Πώς φτάνει στον πελάτη' => 'How it reaches the customer',
		'Στη σελίδα ευχαριστίας φαίνεται πάντα, με κουμπί αντιγραφής. Για το email:' => 'It always shows on the thank-you page, with a copy button. For the email:',
		'Μέσα στο email της παραγγελίας που στέλνει ήδη το WooCommerce (προτείνεται)' => 'Inside the order email WooCommerce already sends (recommended)',
		'Χωριστό email «Κουπόνι ευχαριστίας» (WooCommerce › Ρυθμίσεις › Emails)' => 'A separate "Thank-you coupon" email (WooCommerce › Settings › Emails)',
		'Χωρίς email' => 'No email',
		'Emails της παραγγελίας που το δείχνουν (όσο είναι ενεργό)' => 'Order emails that show it (while it is active)',
		'Παραγγελία σε επεξεργασία' => 'Processing order',
		'Ολοκληρωμένη παραγγελία' => 'Completed order',
		'Παραγγελία σε αναμονή' => 'On-hold order',
		'Υπενθύμιση με email πριν λήξει ένα κουπόνι που δεν χρησιμοποιήθηκε' => 'An email reminder before an unused coupon expires',
		'Πόσες ημέρες πριν' => 'How many days before',
		'Η υπενθύμιση προγραμματίζεται όταν δημιουργείται το κουπόνι (Action Scheduler του WooCommerce) και στέλνεται μόνο αν το κουπόνι είναι ακόμα αχρησιμοποίητο.' => 'The reminder is scheduled when the coupon is created (WooCommerce\'s Action Scheduler) and is sent only if the coupon is still unused.',
		'Καθαρισμός' => 'Cleanup',
		'Διαγραφή των ληγμένων κουπονιών ευχαριστίας που δεν χρησιμοποιήθηκαν' => 'Delete the expired thank-you coupons that were not used',
		'Μετά από' => 'After',
		'ημέρες από τη λήξη' => 'days from expiry',
		'Μία φορά την ημέρα. Μόνο κουπόνια που έφτιαξε αυτό το plugin· κανένα άλλο κουπόνι δεν αγγίζεται. Τα στατιστικά μένουν, γιατί διαβάζονται από τις παραγγελίες.' => 'Once a day. Only coupons this plugin made; no other coupon is touched. The statistics stay, because they are read from the orders.',
		'Placeholders: {amount} (π.χ. 10% ή 5,00 €), {code}, {expiry}, {min_spend}, {first_name}, {shop_name}. Η πρόταση με placeholder χωρίς τιμή φεύγει ολόκληρη (π.χ. «Για αγορές από {min_spend}.» χωρίς ελάχιστη δαπάνη).' => 'Placeholders: {amount} (e.g. 10% or €5.00), {code}, {expiry}, {min_spend}, {first_name}, {shop_name}. A sentence with a placeholder that has no value is dropped whole (e.g. "For purchases from {min_spend}." without a minimum spend).',
		'Τίτλος (σελίδα ευχαριστίας και email)' => 'Title (thank-you page and email)',
		'Κείμενο (σελίδα ευχαριστίας και email)' => 'Text (thank-you page and email)',
		'Αναμένεται πληρωμή: το κουπόνι έρχεται με email' => 'Awaiting payment: the coupon comes by email',
		'Κουμπί αντιγραφής' => 'Copy button',
		'Μετά την αντιγραφή' => 'After copying',
		'Κείμενο της υπενθύμισης' => 'Text of the reminder',
		'Θέμα και επικεφαλίδα των emails αλλάζουν στο WooCommerce › Ρυθμίσεις › Emails.' => 'The subject and heading of the emails are changed in WooCommerce › Settings › Emails.',
		'Κουπόνια ευχαριστίας' => 'Thank-you coupons',
		'Από' => 'From',
		'Έως' => 'To',
		'Εμφάνιση' => 'Show',
		'Ανά ημερομηνία της παραγγελίας που έδωσε το κουπόνι. «Χρησιμοποιήθηκαν» = κουπόνια που μπήκαν σε πληρωμένη παραγγελία.' => 'By date of the order that gave the coupon. "Used" = coupons that went into a paid order.',
		'Εκδόθηκαν' => 'Issued',
		'Χρησιμοποιήθηκαν' => 'Used',
		'Τζίρος παραγγελιών με κουπόνι' => 'Revenue of orders with a coupon',
		'Συνολική έκπτωση' => 'Total discount',
		'Κανένα κουπόνι ευχαριστίας σε αυτή την περίοδο.' => 'No thank-you coupons in this period.',
		'Τελευταία κουπόνια' => 'Latest coupons',
		'Κωδικός' => 'Code',
		'Από την παραγγελία' => 'From order',
		'Email' => 'Email',
		'Κατάσταση' => 'Status',
		'χρησιμοποιήθηκε' => 'used',
		'έληξε' => 'expired',
		'διαγράφηκε' => 'deleted',
		'ενεργό' => 'active',
		'Γλώσσα και uninstall' => 'Language and uninstall',
		'Αυτόματα (γλώσσα του site)' => 'Automatic (site language)',
		'Γλώσσα κειμένων για τον πελάτη' => 'Language of the customer texts',
		'Διαγραφή των κουπονιών ευχαριστίας (και των ενεργών) όταν διαγραφεί το plugin' => 'Delete the thank-you coupons (active ones too) when the plugin is deleted',
		'Χωρίς αυτή την επιλογή τα κουπόνια μένουν, γιατί οι πελάτες κρατούν τους κωδικούς. Το «δώρο» και ο κωδικός πάνω στις παραγγελίες μένουν πάντα: είναι μέρος της παραγγελίας.' => 'Without this option the coupons stay, because customers hold the codes. The gift and the code on the orders always stay: they are part of the order.',
		'Καθαρισμός τώρα' => 'Clean up now',
		'Διαγραφή ληγμένων αχρησιμοποίητων κουπονιών ευχαριστίας' => 'Delete expired unused thank-you coupons',
		'Με τον κανόνα της καρτέλας Κουπόνι (ημέρες μετά τη λήξη), έως 50 κάθε φορά.' => 'With the rule of the Coupon tab (days after expiry), up to 50 at a time.',
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Το backup έχει όλες τις ρυθμίσεις και τα κείμενα. Τα θέματα των emails μένουν στο WooCommerce.' => 'The backup has every setting and text. The email subjects stay in WooCommerce.',
		'Για προγραμματιστές' => 'For developers',
		'Το δώρο μιας παραγγελίας (gift, message, fee), ή null αν δεν είναι δώρο.' => 'The gift of an order (gift, message, fee), or null if it is not a gift.',
		'Φίλτρο: οι ιδιότητες του κουπονιού πριν δημιουργηθεί· false = κανένα κουπόνι.' => 'Filter: the coupon\'s properties before it is created; false = no coupon.',
		'Action μετά τη δημιουργία ενός κουπονιού ευχαριστίας.' => 'Action after a thank-you coupon is created.',
		'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).' => 'Filter for the customer texts (e.g. WPML).',
		'Order meta: δώρο, αφιέρωση και ο κωδικός που πήρε η παραγγελία.' => 'Order meta: gift, message and the code the order received.',
		'Διαγράφηκαν %d κουπόνια.' => '%d coupons were deleted.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Λειτουργία δοκιμής: οι πελάτες δεν βλέπουν ακόμα τίποτα.' => 'Test mode: customers do not see anything yet.',
		'Το κόστος δεν ήταν έγκυρο ποσό και κρατήθηκε το προηγούμενο.' => 'The cost was not a valid amount and the previous one was kept.',
		'Το ταμείο σου είναι το Checkout block: με κόστος το δώρο δεν εμφανίζεται εκεί.' => 'Your checkout is the Checkout block: with a cost the gift option does not show there.',
		'Το πρόθεμα κρατήθηκε ως είχε: έως 10 πεζά λατινικά, ψηφία ή «-».' => 'The prefix was kept as it was: up to 10 lowercase Latin letters, digits or "-".',
		'Δεν διάλεξες καμία κατάσταση παραγγελίας: δεν θα δημιουργηθεί κανένα κουπόνι.' => 'You chose no order status: no coupon will be created.',
		'Δεν διάλεξες κανένα email της παραγγελίας: το κουπόνι θα φαίνεται μόνο στη σελίδα ευχαριστίας.' => 'You chose no order email: the coupon will show only on the thank-you page.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.' => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Checkout Extras).' => 'Invalid backup file (the Checkout Extras settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import completed.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
	);

	public static function init(): void {
		add_filter( 'gettext_checkout-extras', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_checkout-extras filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'checkout-extras' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
