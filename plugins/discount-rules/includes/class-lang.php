<?php
/**
 * DSR_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts visitors see (cart line, badges, tier table, hint, Omnibus
 * line) are store settings with a Greek and an English value (Bible
 * §14.17, DSR_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release: no missing keys, no duplicates).
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Έως %d κανόνες.' => 'Up to %d rules.',
		'(αντίγραφο)' => '(copy)',
		'%d κανόνες' => '%d rules',
		'Η ημερομηνία έναρξης δεν είναι έγκυρη.' => 'The start date is not valid.',
		'Η ημερομηνία λήξης δεν είναι έγκυρη.' => 'The end date is not valid.',
		'Η λήξη πρέπει να είναι μετά την έναρξη.' => 'The end must be after the start.',
		'Δώσε ένα όνομα στον κανόνα.' => 'Give the rule a name.',
		'Μια σταθερή έκπτωση στο καλάθι θέλει ελάχιστο ποσό καλαθιού.' => 'A fixed cart discount needs a cart minimum.',
		'Κάθε κλίμακα ξεκινά από 2 έως 9999 τεμάχια.' => 'Each tier starts at 2 to 9999 units.',
		'Βάλε τουλάχιστον μία κλίμακα (από πόσα τεμάχια και πόση έκπτωση).' => 'Add at least one tier (from how many units and how much off).',
		'Στο «πάρε Χ πλήρωσε Υ» το Χ είναι 2 έως 100 και το Υ από 1 έως Χ − 1.' => 'In «buy X pay Y», X is 2 to 100 and Y is 1 to X − 1.',
		'Διάλεξε σε ποια προϊόντα ισχύει (ή «Όλα τα προϊόντα»).' => 'Choose which products it applies to (or «All products»).',
		'Το ποσοστό έκπτωσης είναι από 0,01 έως 100.' => 'The percentage is from 0.01 to 100.',
		'Το ποσό έκπτωσης πρέπει να είναι πάνω από 0.' => 'The discount amount must be above 0.',
		'Ο κανόνας δίνει έως %1$s%%, πάνω από το ανώτατο όριο ανά παραγγελία (%2$d%%): η έκπτωση θα κόβεται στο όριο.' => 'The rule gives up to %1$s%%, above the order cap (%2$d%%): the discount will be cut at the cap.',
		'Η λήξη του κανόνα έχει ήδη περάσει.' => 'The rule\'s end has already passed.',
		'Κανόνες' => 'Rules',
		'Νέος κανόνας' => 'New rule',
		'Δοκιμή καλαθιού' => 'Test cart',
		'Ιστορικό' => 'History',
		'Ρυθμίσεις' => 'Settings',
		'Κείμενα' => 'Texts',
		'Εργαλεία' => 'Tools',
		'Discount Rules' => 'Discount Rules',
		'Κανόνες εκπτώσεων' => 'Discount rules',
		'Παράδειγμα με τιμή 10,00: ' => 'Example at a price of 10.00: ',
		'τεμάχια' => 'units',
		'πληρώνεις' => 'you pay',
		'το ένα' => 'each',
		'καλάθι' => 'cart',
		'Διαγραφή του κανόνα; Δεν αναιρείται (μένει στο ιστορικό).' => 'Delete the rule? This cannot be undone (it stays in the history).',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have access to this page.',
		'Κανόνες εκπτώσεων με ημερομηνίες: προσφορά σε προϊόντα, κλιμακωτή έκπτωση ποσότητας, «πάρε Χ πλήρωσε Υ» και έκπτωση στο σύνολο του καλαθιού. Κάθε γραμμή του καλαθιού παίρνει έναν κανόνα, αυτόν που συμφέρει περισσότερο τον πελάτη.' => 'Discount rules with dates: product sales, quantity tiers, «buy X pay Y» and a discount on the cart total. Each cart line gets one rule, the one that saves the customer the most.',
		'Επεξεργασία κανόνα' => 'Edit rule',
		'Το DSR_DISABLE είναι ενεργό στο wp-config.php: κανένας κανόνας δεν εφαρμόζεται και τα καλάθια έχουν τις κανονικές τιμές.' => 'DSR_DISABLE is on in wp-config.php: no rule applies and carts have their normal prices.',
		'Η λειτουργία είναι ανενεργή: κανένας κανόνας δεν εφαρμόζεται. Άλλαξέ τη στην καρτέλα Ρυθμίσεις.' => 'The mode is off: no rule applies. Change it in the Settings tab.',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος βλέπουν και παίρνουν τις εκπτώσεις.' => 'Test mode: only the store\'s managers see and get the discounts.',
		'Ενεργός' => 'Active',
		'Προγραμματισμένος' => 'Scheduled',
		'Έληξε' => 'Expired',
		'Ανενεργός' => 'Off',
		'Προσφορά σε προϊόντα' => 'Product sale',
		'Κλιμακωτή ποσότητα' => 'Quantity tiers',
		'Πάρε Χ πλήρωσε Υ' => 'Buy X pay Y',
		'Σύνολο καλαθιού' => 'Cart total',
		'%1$d για %2$d' => '%1$d for %2$d',
		'από %s' => 'from %s',
		'Όλα τα προϊόντα' => 'All products',
		'%d προϊόν' => '%d product',
		'%d προϊόντα' => '%d products',
		'%d κατηγορία' => '%d category',
		'%d κατηγορίες' => '%d categories',
		'%d ετικέτα' => '%d tag',
		'%d ετικέτες' => '%d tags',
		'%d brand' => '%d brand',
		'%d brands' => '%d brands',
		'(με εξαιρέσεις)' => '(with exclusions)',
		'Δεν υπάρχει ακόμα κανένας κανόνας. Φτιάξε τον πρώτο με το «Νέος κανόνας».' => 'There are no rules yet. Make the first one with «New rule».',
		'Κανόνας' => 'Rule',
		'Τύπος' => 'Type',
		'Έκπτωση' => 'Discount',
		'Προϊόντα' => 'Products',
		'Από' => 'From',
		'Έως' => 'To',
		'Κατάσταση' => 'Status',
		'Παραγγελίες' => 'Orders',
		'Έκπτωση που δόθηκε' => 'Discount given',
		'μόνο επισκέπτες' => 'guests only',
		'μόνο συνδεδεμένοι πελάτες' => 'logged-in customers only',
		'Απενεργοποίηση' => 'Disable',
		'Ενεργοποίηση' => 'Enable',
		'Αντιγραφή' => 'Copy',
		'Διαγραφή' => 'Delete',
		'Οι παραγγελίες και η έκπτωση μετρούν από τις %d πιο πρόσφατες πληρωμένες παραγγελίες με κανόνα (Σε επεξεργασία, Ολοκληρωμένη, Σε αναμονή), χωρίς ΦΠΑ.' => 'Orders and discount count from the %d most recent paid orders with a rule (Processing, Completed, On hold), without VAT.',
		'Διάλεξε…' => 'Choose…',
		'Ο κανόνας δεν βρέθηκε.' => 'The rule was not found.',
		'Όνομα' => 'Name',
		'π.χ. Black Friday 2026' => 'e.g. Black Friday 2026',
		'Ισχύει μέσα στις ημερομηνίες του' => 'Applies within its dates',
		'χαμηλότερη τιμή σε προϊόντα ή κατηγορίες, με διαγραμμένη την κανονική τιμή στο κατάστημα.' => 'a lower price on products or categories, with the normal price struck through in the shop.',
		'έκπτωση ανάλογα με το πόσα τεμάχια μπαίνουν στο καλάθι, με πίνακα στη σελίδα του προϊόντος.' => 'a discount by how many units go in the cart, with a table on the product page.',
		'π.χ. 3 για 2: για κάθε 3 τεμάχια το φθηνότερο είναι δώρο.' => 'e.g. 3 for 2: for every 3 units the cheapest is free.',
		'μία γραμμή «Έκπτωση» στα σύνολα όταν το καλάθι φτάνει ένα ποσό.' => 'one «Discount» line in the totals when the cart reaches an amount.',
		'Πόση' => 'How much',
		'Σταθερό ποσό = τόσο λιγότερο ανά τεμάχιο.' => 'Fixed amount = that much less per unit.',
		'Σταθερό ποσό = τόσο λιγότερο σε όλο το καλάθι.' => 'Fixed amount = that much less on the whole cart.',
		'Από τεμάχια' => 'From units',
		'Έκπτωση ανά τεμάχιο' => 'Discount per unit',
		'Κενές γραμμές αγνοούνται. Ισχύει η μεγαλύτερη κλίμακα που φτάνει το καλάθι.' => 'Empty lines are ignored. The highest tier the cart reaches applies.',
		'Πάρε' => 'Buy',
		'πλήρωσε' => 'pay',
		'Για κάθε ομάδα τεμαχίων, τα φθηνότερα είναι δώρο. Επαναλαμβάνεται: 6 τεμάχια με «3 για 2» = 2 δώρο.' => 'For each group of units, the cheapest are free. It repeats: 6 units with «3 for 2» = 2 free.',
		'Μετρούν' => 'Counted',
		'Τα τεμάχια κάθε προϊόντος χωριστά (οι παραλλαγές μαζί)' => 'The units of each product separately (variations together)',
		'Όλα τα προϊόντα του κανόνα μαζί (π.χ. οποιαδήποτε 3 βιβλία της κατηγορίας)' => 'All the rule\'s products together (e.g. any 3 books of the category)',
		'Σε ποια προϊόντα' => 'Which products',
		'Αναζήτηση προϊόντος…' => 'Search for a product…',
		'Κατηγορίες (μαζί με τις υποκατηγορίες τους)' => 'Categories (with their subcategories)',
		'Ετικέτες' => 'Tags',
		'Brands' => 'Brands',
		'Εκτός από αυτά τα προϊόντα' => 'Except these products',
		'Εκτός από αυτές τις κατηγορίες' => 'Except these categories',
		'Οι γραμμές πακέτων (Product Bundles) και οι λογαριασμοί χονδρικής (Trade Accounts) δεν παίρνουν ποτέ κανόνα. Οι εξαιρέσεις όλου του καταστήματος είναι στις Ρυθμίσεις.' => 'Bundle lines (Product Bundles) and trade accounts (Trade Accounts) never get a rule. Store-wide exclusions are in Settings.',
		'Πότε και για ποιους' => 'When and for whom',
		'Κενό = από τώρα.' => 'Empty = from now.',
		'Κενό = χωρίς λήξη. Ώρα του site (%s).' => 'Empty = no end. Site time (%s).',
		'Για' => 'For',
		'Όλους' => 'Everyone',
		'Μόνο επισκέπτες (χωρίς σύνδεση)' => 'Guests only (not logged in)',
		'Μόνο συνδεδεμένους πελάτες' => 'Logged-in customers only',
		'Ελάχιστο καλάθι' => 'Cart minimum',
		'Κενό = χωρίς ελάχιστο. Κανόνας με ελάχιστο δεν δείχνει διαγραμμένη τιμή στο κατάστημα· φαίνεται στο καλάθι.' => 'Empty = no minimum. A rule with a minimum shows no struck-through price in the shop; it shows in the cart.',
		'Τι βλέπει ο πελάτης' => 'What the customer sees',
		'Ετικέτα (ελληνικά)' => 'Label (Greek)',
		'Ετικέτα (English)' => 'Label (English)',
		'Φαίνεται στο καλάθι («Προσφορά: …»), στην παραγγελία, στα emails και κάτω από την τιμή. Κενό = φτιάχνεται από τον κανόνα («3 για 2», «−20%») ή το όνομά του.' => 'Shows in the cart («Offer: …»), the order, the emails and under the price. Empty = made from the rule («3 for 2», «−20%») or its name.',
		'Αποθήκευση κανόνα' => 'Save rule',
		'Πίσω στους κανόνες' => 'Back to the rules',
		'Διάλεξε προϊόντα και ποσότητες: βλέπεις τι θα έπαιρνε το καλάθι με τον ίδιο υπολογισμό, χωρίς να φτιαχτεί καλάθι ή παραγγελία. Μετρούν και οι ανενεργοί από τη λειτουργία κανόνες, όχι όμως οι απενεργοποιημένοι.' => 'Choose products and quantities: you see what the cart would get with the same calculation, without making a cart or an order. Rules held back by the mode count too, but disabled rules do not.',
		'Προϊόν' => 'Product',
		'Τεμάχια' => 'Units',
		'Ως συνδεδεμένος πελάτης' => 'As a logged-in customer',
		'Ημερομηνία και ώρα' => 'Date and time',
		'Κενό = τώρα.' => 'Empty = now.',
		'Υπολογισμός' => 'Calculate',
		'Διάλεξε τουλάχιστον ένα προϊόν (απλό ή παραλλαγή).' => 'Choose at least one product (simple or a variation).',
		'Αποτέλεσμα' => 'Result',
		'Τιμή' => 'Price',
		'Νέα τιμή' => 'New price',
		'Υποσύνολο:' => 'Subtotal:',
		'Έκπτωση καλαθιού «%1$s»: −%2$s' => 'Cart discount «%1$s»: −%2$s',
		'Σύνολο εκπτώσεων:' => 'Total discounts:',
		'Πληρώνει:' => 'Pays:',
		'Η έκπτωση κόπηκε στο ανώτατο όριο ανά παραγγελία (%d%%).' => 'The discount was cut at the order cap (%d%%).',
		'Τιμές όπως είναι αποθηκευμένες (με ή χωρίς ΦΠΑ, όπως τα προϊόντα). Οι λογαριασμοί χονδρικής δεν παίρνουν ποτέ κανόνα.' => 'Prices as stored (with or without VAT, like the products). Trade accounts never get a rule.',
		'Δημιουργία' => 'Created',
		'Αλλαγή' => 'Changed',
		'Εισαγωγή' => 'Import',
		'Καμία αλλαγή ακόμα.' => 'No changes yet.',
		'Πότε' => 'When',
		'Ποιος' => 'Who',
		'Τι' => 'What',
		'Αλλαγές' => 'Changes',
		'Οι τελευταίες %d αλλαγές κανόνων και ρυθμίσεων.' => 'The last %d changes to rules and settings.',
		'Λειτουργία' => 'Mode',
		'Ανενεργό' => 'Off',
		'Λειτουργία δοκιμής: μόνο οι διαχειριστές του καταστήματος βλέπουν και παίρνουν τις εκπτώσεις' => 'Test mode: only the store\'s managers see and get the discounts',
		'Ενεργό για όλους' => 'Live for everyone',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch-off without admin access: add this line to wp-config.php',
		'Όρια' => 'Limits',
		'Ανώτατη έκπτωση ανά παραγγελία' => 'Maximum discount per order',
		'του υποσυνόλου' => 'of the subtotal',
		'Όλες οι εκπτώσεις των κανόνων μαζί δεν περνούν ποτέ αυτό το ποσοστό. Πιάνει λάθη πληκτρολόγησης (π.χ. 100% αντί για 10%).' => 'All the rules\' discounts together never pass this percentage. It catches typing mistakes (e.g. 100% instead of 10%).',
		'Μέγιστη έκπτωση ανά κατηγορία' => 'Maximum discount per category',
		'Κατηγορία (και οι υποκατηγορίες της)' => 'Category (and its subcategories)',
		'— Καμία —' => '— None —',
		'Καμία γραμμή της κατηγορίας δεν πληρώνει λιγότερο από την κανονική τιμή μείον αυτό το ποσοστό, όποιοι κανόνες κι αν ισχύουν· οι γραμμές αυτές δεν παίρνουν έκπτωση καλαθιού. Χρήσιμο για την ενιαία τιμή βιβλίου (π.χ. νέα βιβλία έως 10%): ρώτα τον λογιστή σου για τα ακριβή όρια.' => 'No line of the category pays less than the normal price minus this percentage, whatever rules apply; these lines get no cart discount. Useful for fixed book prices (e.g. new books up to 10%): ask your accountant for the exact limits.',
		'Εξαιρέσεις όλου του καταστήματος' => 'Store-wide exclusions',
		'Τίτλοι άλλων εκδοτών (Book Feed, Trade Feed Import) δεν παίρνουν ποτέ κανόνα' => 'Titles of other publishers (Book Feed, Trade Feed Import) never get a rule',
		'Προϊόντα σε προπαραγγελία (Pre-Orders) δεν παίρνουν κανόνα' => 'Products on pre-order (Pre-Orders) get no rule',
		'Πάντα εκτός: γραμμές πακέτων (Product Bundles) και λογαριασμοί χονδρικής (Trade Accounts). Τα κουπόνια των πελατών δεν ισχύουν σε γραμμές με κανόνα, εκτός από κουπόνι 100%.' => 'Always excluded: bundle lines (Product Bundles) and trade accounts (Trade Accounts). Customer coupons do not apply to lines with a rule, except a 100% coupon.',
		'Εμφάνιση' => 'Display',
		'Πίνακας κλιμάκων στη σελίδα του προϊόντος' => 'Tier table on the product page',
		'«Πρόσθεσε ακόμη 1…» στο καλάθι όταν λείπει ένα τεμάχιο για την επόμενη κλίμακα ή το δώρο' => '«Add 1 more…» in the cart when one unit is missing for the next tier or the free item',
		'Η υπόδειξη φαίνεται στο κλασικό καλάθι. Στα blocks Καλάθι και Ταμείο οι εκπτώσεις και τα ονόματα των κανόνων φαίνονται κανονικά.' => 'The hint shows in the classic cart. In the Cart and Checkout blocks the discounts and rule names show as usual.',
		'Χαμηλότερη τιμή 30 ημερών' => 'Lowest price of 30 days',
		'Κάτω από κάθε τιμή που χαμηλώνει ένας κανόνας «Προσφορά»' => 'Under every price a «Sale» rule lowers',
		'Και κάτω από τις τιμές προσφοράς του ίδιου του WooCommerce' => 'And under WooCommerce\'s own sale prices',
		'Οδηγία Omnibus (ΕΕ 2019/2161): όταν ανακοινώνεις μείωση τιμής, δείχνεις τη χαμηλότερη τιμή των 30 ημερών πριν από τη μείωση. Το plugin κρατά ιστορικό τιμών από τη μέρα που ενεργοποιήθηκε (μία εργασία κάθε βράδυ και σε κάθε αποθήκευση προϊόντος)· όσο δεν έχει ιστορικό για ένα προϊόν, η γραμμή δεν φαίνεται. Οι προσφορές ποσότητας και το «3 για 2» συνήθως δεν θεωρούνται μείωση τιμής: επιβεβαίωσέ το με τον λογιστή σου.' => 'Omnibus Directive (EU 2019/2161): when you announce a price reduction, you show the lowest price of the 30 days before the reduction. The plugin keeps a price history from the day it was activated (a job every night and on every product save); while it has no history for a product, the line does not show. Quantity offers and «3 for 2» are usually not price reductions: confirm it with your accountant.',
		'Αποθήκευση' => 'Save',
		'Γραμμή στο καλάθι, στην παραγγελία και στα emails («Προσφορά: 3 για 2»)' => 'Line in the cart, the order and the emails («Offer: 3 for 2»)',
		'Γραμμή της έκπτωσης καλαθιού στα σύνολα' => 'The cart discount line in the totals',
		'Ετικέτα «πάρε Χ πλήρωσε Υ» ({buy}, {pay})' => '«Buy X pay Y» label ({buy}, {pay})',
		'Εξήγηση «πάρε Χ πλήρωσε Υ» στις λίστες, στο προϊόν και στη σελίδα της προσφοράς ({buy}, {pay})' => '«Buy X pay Y» explanation in product lists, on the product page and on the offer page ({buy}, {pay})',
		'Δεύτερη γραμμή όταν μετράνε μαζί όλα τα προϊόντα του κανόνα' => 'Second line when all products of the rule count together',
		'Σύνδεσμος στη σελίδα με όλα τα προϊόντα της προσφοράς ({label})' => 'Link to the page with all products of the offer ({label})',
		'Κανόνες ποσότητας στις λίστες προϊόντων: ribbon πάνω στην εικόνα, εξήγηση και σύνδεσμος στη σελίδα της προσφοράς' => 'Quantity rules in product lists: a ribbon over the image, an explanation and a link to the offer page',
		'Ένδειξη έκπτωσης ({percent})' => 'Discount badge ({percent})',
		'Τίτλος του πίνακα κλιμάκων' => 'Title of the tier table',
		'Γραμμή του πίνακα ({min}, {price})' => 'Row of the table ({min}, {price})',
		'Λήξη ({date})' => 'End ({date})',
		'Υπόδειξη στο καλάθι ({n}, {rule})' => 'Hint in the cart ({n}, {rule})',
		'Χαμηλότερη τιμή 30 ημερών ({price})' => 'Lowest price of 30 days ({price})',
		'Γλώσσα κειμένων' => 'Text language',
		'Αυτόματα, από τη γλώσσα του site' => 'Automatic, from the site language',
		'Ελληνικά' => 'Ελληνικά',
		'English' => 'English',
		'Απλό κείμενο. Κενό ελληνικό = το αρχικό κείμενο· κενό αγγλικό = δείχνει το ελληνικό.' => 'Plain text. Empty Greek = the original text; empty English = shows the Greek.',
		'Αντίγραφο ασφαλείας' => 'Backup',
		'Εξαγωγή κανόνων και ρυθμίσεων (JSON)' => 'Export rules and settings (JSON)',
		'Η εισαγωγή αντικαθιστά όλους τους κανόνες και τις ρυθμίσεις, με τον ίδιο έλεγχο με τις φόρμες. Η λειτουργία γίνεται «Ανενεργό» μέχρι να την αλλάξεις, ώστε να δεις πρώτα τους κανόνες. Ένα μη έγκυρο αρχείο δεν αλλάζει τίποτα.' => 'Import replaces all rules and settings, with the same checks as the forms. The mode becomes «Off» until you change it, so you see the rules first. An invalid file changes nothing.',
		'Για προγραμματιστές' => 'For developers',
		'Η τιμή ενός προϊόντος από κανόνα «Προσφορά» τώρα, για επισκέπτες (για feeds και schema), ή null.' => 'A product\'s price from a «Sale» rule now, for guests (for feeds and schema), or null.',
		'Ο κανόνας μιας γραμμής παραγγελίας (κανόνας, τιμή πριν και μετά, έκπτωση), ή null.' => 'An order line\'s rule (rule, price before and after, discount), or null.',
		'Action: μετά από κάθε αλλαγή κανόνα και όταν ένας κανόνας αρχίζει ή λήγει.' => 'Action: after every rule change and when a rule starts or ends.',
		'Φίλτρο: false = το προϊόν δεν παίρνει ποτέ κανόνα (π.χ. δωροκάρτες).' => 'Filter: false = the product never gets a rule (e.g. gift cards).',
		'Φίλτρο: η έκπτωση μιας γραμμής του καλαθιού, η τελευταία λέξη (0 = καμία).' => 'Filter: a cart line\'s discount, the last word (0 = none).',
		'Φίλτρο: τα κείμενα του επισκέπτη.' => 'Filter: the visitor texts.',
		'Abilities μόνο ανάγνωσης (WordPress 6.9+): οι κανόνες και τι θα έπαιρνε ένα καλάθι.' => 'Read-only abilities (WordPress 6.9+): the rules and what a cart would get.',
		'Ο κανόνας ενεργοποιήθηκε.' => 'The rule was enabled.',
		'Ο κανόνας απενεργοποιήθηκε.' => 'The rule was disabled.',
		'Ο κανόνας αντιγράφηκε, ανενεργός. Άλλαξε ημερομηνίες και ενεργοποίησέ τον.' => 'The rule was copied, disabled. Change its dates and enable it.',
		'Ο κανόνας διαγράφηκε.' => 'The rule was deleted.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Ο κανόνας αποθηκεύτηκε.' => 'The rule was saved.',
		'Η λειτουργία είναι ανενεργή: ο κανόνας θα ισχύσει όταν τη βάλεις σε δοκιμή ή ενεργό, στις Ρυθμίσεις.' => 'The mode is off: the rule will apply when you set it to test or live, in Settings.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'The settings were saved.',
		'Ένα όριο κατηγορίας δεν ήταν έγκυρο ποσοστό (0 έως 99,99) και δεν κρατήθηκε.' => 'A category limit was not a valid percentage (0 to 99.99) and was not kept.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο αντίγραφο του Discount Rules.' => 'The file is not a valid Discount Rules backup.',
		'Κανένας έγκυρος κανόνας στο αρχείο: δεν άλλαξε τίποτα.' => 'No valid rule in the file: nothing changed.',
		'Εισαγωγή %d κανόνων.' => 'Imported %d rules.',
		'Η λειτουργία έγινε «Ανενεργό»: έλεγξε τους κανόνες και άλλαξέ τη στις Ρυθμίσεις.' => 'The mode became «Off»: check the rules and change it in Settings.',
		'Made with ❤ by %s' => 'Made with ❤ by %s',
		'More plugins at' => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard' => 'Noxpress Dashboard',
		'Το Discount Rules χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Discount Rules requires WooCommerce to be active — the plugin stays inactive until it is activated.',
	);

	public static function init(): void {
		add_filter( 'gettext_discount-rules', array( __CLASS__, 'filter' ), 10, 3 );
		add_filter( 'ngettext_discount-rules', array( __CLASS__, 'filter_n' ), 10, 5 );
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

	/** gettext_discount-rules filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'discount-rules' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}

	/** ngettext_discount-rules filter (Greek and English share the plural rule). */
	public static function filter_n( $translation, $single, $plural, $number, $domain ) {
		if ( 'discount-rules' !== $domain || 'en' !== self::lang() ) {
			return $translation;
		}
		$key = 1 === (int) $number ? $single : $plural;
		return isset( self::$dict[ $key ] ) ? self::$dict[ $key ] : $translation;
	}
}
