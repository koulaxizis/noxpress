<?php
/**
 * SHN_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts visitors see are store settings with a Greek and an English
 * value (Bible §14.17, SHN_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Το Shipping Notes χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Shipping Notes requires WooCommerce to be active — the plugin stays inactive until it is activated.',
		'Προετοιμασία από (εργάσιμες)'                              => 'Preparation from (business days)',
		'Shipping Notes: ημέρες μέχρι να φύγει το δέμα (π.χ. χρόνος παραγωγής). Κενό = από την κατηγορία ή το κατάστημα.' => 'Shipping Notes: days until the parcel leaves (e.g. production time). Empty = from the category or the store.',
		'Προετοιμασία έως (εργάσιμες)'                              => 'Preparation to (business days)',
		'Διαθέσιμο από'                                             => 'Available from',
		'Shipping Notes: για προπαραγγελίες. Ο χρόνος παράδοσης μετράει από αυτή την ημερομηνία. Μετά από αυτήν το πεδίο δεν ισχύει πια.' => 'Shipping Notes: for pre-orders. The delivery time counts from this date. After it, the field no longer applies.',
		'Χρόνος προετοιμασίας (Shipping Notes)'                     => 'Preparation time (Shipping Notes)',
		'Από'                                                       => 'From',
		'Έως'                                                       => 'To',
		'Εργάσιμες μέχρι να φύγει το δέμα για τα προϊόντα της κατηγορίας και των υποκατηγοριών της. Κενό = του καταστήματος.' => 'Business days until the parcel leaves, for the products of the category and its subcategories. Empty = the store\'s.',
		'Δωρεάν αποστολή (Shipping Notes)'                          => 'Free shipping (Shipping Notes)',
		'Μήνυμα και μπάρα για τη δωρεάν αποστολή, με το καλάθι του επισκέπτη.' => 'Message and bar for free shipping, with the visitor\'s cart.',
		'Χρόνος παράδοσης (Shipping Notes)'                         => 'Delivery time (Shipping Notes)',
		'Χρόνος παράδοσης ενός προϊόντος.'                          => 'Delivery time of a product.',
		'ID προϊόντος (κενό = το τρέχον)'                           => 'Product ID (empty = the current one)',
		'Εμφανίζεται στο site με τις ρυθμίσεις του Shipping Notes.' => 'Shown on the site with the Shipping Notes settings.',
		'Δωρεάν αποστολή'                                           => 'Free shipping',
		'Χρόνος παράδοσης'                                          => 'Delivery time',
		'Προεπισκόπηση'                                             => 'Preview',
		'Εργαλεία'                                                  => 'Tools',
		'Shipping Notes'                                            => 'Shipping Notes',
		'Αποστολή'                                                  => 'Shipping',
		'Ρυθμίσεις'                                                 => 'Settings',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Δύο μηνύματα για τον πελάτη: πόσα απομένουν για δωρεάν αποστολή, και σε πόσες εργάσιμες φτάνει ένα προϊόν.' => 'Two messages for the customer: how much is left for free shipping, and in how many business days a product arrives.',
		'Ανενεργό'                                                  => 'Off',
		'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: only the store\'s managers see it',
		'Ενεργό για όλους'                                          => 'On for everyone',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch without admin access: add this line to wp-config.php',
		'Γλώσσα κειμένων'                                           => 'Text language',
		'Αυτόματα (γλώσσα του site)'                                => 'Automatic (site language)',
		'Ελληνικά'                                                  => 'Ελληνικά',
		'English'                                                   => 'English',
		'Κείμενο'                                                   => 'Text',
		'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό.' => 'Empty Greek text = the default. Empty English = shows the Greek.',
		'από %s'                                                    => 'from %s',
		'από %s ή με κουπόνι'                                       => 'from %s or with a coupon',
		'από %s και με κουπόνι'                                     => 'from %s and with a coupon',
		'μόνο με κουπόνι'                                           => 'coupon only',
		'πάντα δωρεάν'                                              => 'always free',
		'πριν από τις εκπτώσεις κουπονιών'                          => 'before coupon discounts',
		'ανενεργή'                                                  => 'disabled',
		'Περιοχές που δεν καλύπτονται από άλλες ζώνες'              => 'Locations not covered by your other zones',
		'Λειτουργία'                                                => 'Mode',
		'Όριο δωρεάν αποστολής'                                     => 'Free shipping threshold',
		'Αυτόματα από τις ζώνες αποστολής του WooCommerce (μέθοδος «Δωρεάν αποστολή», ελάχιστο ποσό)' => 'Automatic, from WooCommerce\'s shipping zones (method "Free shipping", minimum amount)',
		'Αυτόματα, με δικό μου ποσό για τις ζώνες χωρίς «Δωρεάν αποστολή»' => 'Automatic, with my own amount for zones without "Free shipping"',
		'Σταθερό ποσό για όλο το site:'                             => 'One amount for the whole site:',
		'Το ποσό που μετράει υπολογίζεται όπως στο WooCommerce: με ή χωρίς ΦΠΑ όπως δείχνει τις τιμές το καλάθι, μετά τις εκπτώσεις κουπονιών (εκτός αν η μέθοδος λέει «πριν από τις εκπτώσεις»). Με δικό σου ποσό, το μήνυμα μπορεί να διαφωνεί με το πραγματικό κόστος στο ταμείο.' => 'The amount that counts is calculated as WooCommerce does: with or without tax as the cart shows prices, after coupon discounts (unless the method says "before discounts"). With your own amount, the message may disagree with the real cost at checkout.',
		'Ζώνη'                                                      => 'Zone',
		'Δωρεάν αποστολή στο WooCommerce'                           => 'Free shipping in WooCommerce',
		'Δικό μου ποσό'                                             => 'My amount',
		'Ισχύει τώρα'                                               => 'In effect now',
		'τίποτα (δεν εμφανίζεται)'                                  => 'nothing (not shown)',
		'μόνο «κερδίσατε»'                                          => 'only "free shipping won"',
		'διαφέρει από το WooCommerce'                               => 'differs from WooCommerce',
		'Το «Δικό μου ποσό» ισχύει μόνο με την επιλογή «με δικό μου ποσό» και μόνο σε ζώνες χωρίς ενεργή «Δωρεάν αποστολή». Με «μόνο με κουπόνι» δεν υπάρχει ποσό· με «ποσό και κουπόνι» η μπάρα φαίνεται μόλις μπει το κουπόνι.' => '"My amount" applies only with the "my own amount" option and only in zones without an enabled "Free shipping". With "coupon only" there is no amount; with "amount and coupon" the bar shows once the coupon is applied.',
		'Πού εμφανίζεται'                                           => 'Where it shows',
		'Καλάθι (κλασικό)'                                          => 'Cart (classic)',
		'πάνω από τα σύνολα'                                        => 'above the totals',
		'πάνω από τα προϊόντα'                                      => 'above the products',
		'Mini-cart (widget και καλάθι στο header του θέματος)'      => 'Mini-cart (widget and the theme\'s header cart)',
		'Ταμείο (κλασικό)'                                          => 'Checkout (classic)',
		'Μπλοκ «Καλάθι»'                                            => '"Cart" block',
		'Μπλοκ «Ταμείο»'                                            => '"Checkout" block',
		'Οπουδήποτε αλλού: το shortcode [nox_free_shipping] ή το μπλοκ «Δωρεάν αποστολή (Shipping Notes)». Στο μπλοκ Mini-Cart των block themes, βάλε το μπλοκ μας στο template part «Mini-Cart» από τον Site Editor.' => 'Anywhere else: the [nox_free_shipping] shortcode or the "Free shipping (Shipping Notes)" block. In the Mini-Cart block of block themes, add our block to the "Mini-Cart" template part in the Site Editor.',
		'Εμφάνιση'                                                  => 'Display',
		'Μπάρα προόδου κάτω από το κείμενο'                         => 'Progress bar under the text',
		'Μήνυμα και με άδειο καλάθι'                                => 'Message with an empty cart too',
		'Μήνυμα όταν κερδίζει δωρεάν αποστολή'                      => 'Message when free shipping is won',
		'Χρώμα μπάρας'                                              => 'Bar colour',
		'Κενό = το χρώμα του κειμένου του θέματος.'                 => 'Empty = the theme\'s text colour.',
		'Κείμενα'                                                   => 'Texts',
		'Άδειο καλάθι'                                              => 'Empty cart',
		'Απομένει ποσό'                                             => 'Amount left',
		'Κερδίσατε'                                                 => 'Won',
		'Placeholders: {threshold} όριο, {remaining} ποσό που απομένει, {total} ποσό που μετράει, {percent} ποσοστό.' => 'Placeholders: {threshold} threshold, {remaining} amount left, {total} amount that counts, {percent} percentage.',
		'Αποθήκευση ρυθμίσεων'                                      => 'Save settings',
		'Δευ'                                                       => 'Mon',
		'Τρί'                                                       => 'Tue',
		'Τετ'                                                       => 'Wed',
		'Πέμ'                                                       => 'Thu',
		'Παρ'                                                       => 'Fri',
		'Σάβ'                                                       => 'Sat',
		'Κυρ'                                                       => 'Sun',
		'Χρόνος μεταφοράς ανά μέθοδο αποστολής'                     => 'Transit time per shipping method',
		'Ζώνη στη σελίδα προϊόντος'                                 => 'Zone on product pages',
		'Η ζώνη της διεύθυνσης του καταστήματος (%s)'               => 'The zone of the store\'s address (%s)',
		'Μία ζώνη για όλους τους επισκέπτες, ώστε η page cache να δείχνει πάντα σωστό κείμενο.' => 'One zone for every visitor, so the page cache always shows the right text.',
		'Μέθοδος'                                                   => 'Method',
		'Εργάσιμες (από – έως)'                                     => 'Business days (from – to)',
		'Παραδίδει Σάββατο'                                         => 'Delivers on Saturday',
		'Δεν υπάρχουν μέθοδοι αποστολής. Πρόσθεσέ τες στο WooCommerce → Ρυθμίσεις → Αποστολή.' => 'There are no shipping methods. Add them in WooCommerce → Settings → Shipping.',
		'Εργάσιμες από την αποστολή του δέματος ως την παράδοση. Μέθοδος χωρίς ημέρες δεν μπαίνει στον υπολογισμό (π.χ. παραλαβή από το κατάστημα).' => 'Business days from dispatch to delivery. A method without days is left out (e.g. local pickup).',
		'Χρόνος προετοιμασίας'                                      => 'Preparation time',
		'Κατάστημα (εργάσιμες)'                                     => 'Store (business days)',
		'Προϊόντα σε backorder'                                     => 'Products on backorder',
		'Εργάσιμες μέχρι να φύγει το δέμα. Ισχύει η πιο συγκεκριμένη τιμή: προϊόν (καρτέλα «Αποστολή»), μετά κατηγορία (η πιο αργή από τις κατηγορίες του), μετά κατάστημα.' => 'Business days until the parcel leaves. The most specific value applies: product (Shipping tab), then category (the slowest of its categories), then store.',
		'Ημερολόγιο'                                                => 'Calendar',
		'Ώρα cut-off'                                               => 'Cut-off time',
		'Ζώνη ώρας του site: %s. Κενό = χωρίς cut-off.'             => 'Site time zone: %s. Empty = no cut-off.',
		'Εργάσιμες αποστολής'                                       => 'Dispatch days',
		'Αργίες'                                                    => 'Holidays',
		'Μία ανά γραμμή: ΜΜ-ΗΗ για κάθε χρόνο (π.χ. 12-25) ή ΕΕΕΕ-ΜΜ-ΗΗ για μία φορά (π.χ. 2027-04-30). Δεκτό και ΗΗ/ΜΜ ή ΗΗ/ΜΜ/ΕΕΕΕ. Οι περασμένες ημερομηνίες σβήνονται στην αποθήκευση. Οι αργίες ισχύουν και για την αποστολή και για την παράδοση.' => 'One per line: MM-DD for every year (e.g. 12-25) or YYYY-MM-DD for once (e.g. 2027-04-30). DD/MM and DD/MM/YYYY are accepted too. Past dates are removed on save. Holidays apply to both dispatch and delivery.',
		'Ένα εύρος, από τη γρηγορότερη ως την πιο αργή μέθοδο'      => 'One range, from the fastest to the slowest method',
		'Λίστα ανά μέθοδο'                                          => 'List per method',
		'Θέση στη σελίδα προϊόντος'                                 => 'Position on the product page',
		'Κάτω από την τιμή'                                         => 'Below the price',
		'Κάτω από το κουμπί καλαθιού'                               => 'Below the add-to-cart button',
		'Μόνο με shortcode ή μπλοκ'                                 => 'Only by shortcode or block',
		'Ημερομηνίες αντί για εργάσιμες (π.χ. «Τρί 13/10 – Πέμ 15/10»)' => 'Dates instead of business days (e.g. "Tue 13/10 – Thu 15/10")',
		'Γραμμή «Παραγγείλτε μέσα σε…» πριν από το cut-off'         => '"Order within…" line before the cut-off',
		'Οι ημερομηνίες και η αντίστροφη μέτρηση υπολογίζονται στον browser του επισκέπτη με την ώρα του καταστήματος, ώστε να είναι σωστές και σε σελίδες από page cache. Χωρίς JavaScript μένει το κείμενο σε εργάσιμες. Οι προπαραγγελίες δείχνουν πάντα την ημερομηνία κυκλοφορίας. Shortcode: [nox_delivery] ή [nox_delivery id="123"]· μπλοκ «Χρόνος παράδοσης (Shipping Notes)».' => 'Dates and the countdown are computed in the visitor\'s browser with the store\'s time, so they are right on page-cached pages too. Without JavaScript the business-days text stays. Pre-orders always show the release date. Shortcode: [nox_delivery] or [nox_delivery id="123"]; block "Delivery time (Shipping Notes)".',
		'Εύρος'                                                     => 'Range',
		'Τίτλος λίστας'                                             => 'List title',
		'Γραμμή λίστας'                                             => 'List line',
		'Μονάδα: μία μέρα'                                          => 'Unit: one day',
		'Μονάδα: πολλές μέρες'                                      => 'Unit: several days',
		'Εύρος με ημερομηνίες'                                      => 'Range with dates',
		'Γραμμή λίστας με ημερομηνίες'                              => 'List line with dates',
		'Γραμμή cut-off'                                            => 'Cut-off line',
		'Προπαραγγελία'                                             => 'Pre-order',
		'Placeholders: {days} π.χ. «1–3 εργάσιμες», {method} μέθοδος, {dates} ημερομηνίες, {hours} {minutes} {cutoff} για το cut-off, {available} ημερομηνία κυκλοφορίας.' => 'Placeholders: {days} e.g. "1–3 business days", {method} method, {dates} dates, {hours} {minutes} {cutoff} for the cut-off, {available} release date.',
		'Ελληνικές αργίες'                                          => 'Greek public holidays',
		'Προσθήκη ελληνικών αργιών'                                 => 'Add Greek public holidays',
		'Προσθέτει στη λίστα τις σταθερές αργίες και τις κινητές του Πάσχα (Καθαρά Δευτέρα, Μεγάλη Παρασκευή, Δευτέρα του Πάσχα, Αγίου Πνεύματος) για φέτος και του χρόνου. Υπολογίζονται εδώ, χωρίς σύνδεση σε άλλο site.' => 'Adds the fixed holidays and the movable ones of Orthodox Easter (Clean Monday, Good Friday, Easter Monday, Whit Monday) for this year and the next. Computed here, without contacting any other site.',
		'Ποσό που μετράει'                                          => 'Amount that counts',
		'Σε αυτή τη ζώνη δεν εμφανίζεται τίποτα: δεν υπάρχει όριο δωρεάν αποστολής.' => 'Nothing shows in this zone: there is no free shipping threshold.',
		'Αυτό το μήνυμα είναι κλειστό στις ρυθμίσεις.'              => 'This message is turned off in the settings.',
		'Ποσό 0 = άδειο καλάθι. Τα κουπόνια δωρεάν αποστολής και τα φίλτρα άλλων plugins φαίνονται μόνο σε πραγματικό καλάθι.' => 'Amount 0 = empty cart. Free-shipping coupons and other plugins\' filters show only in a real cart.',
		'ID προϊόντος'                                              => 'Product ID',
		'Δεν βρέθηκε προϊόν με αυτό το ID.'                         => 'No product with this ID.',
		'Για αυτό το προϊόν δεν εμφανίζεται χρόνος παράδοσης (ψηφιακό, εξωτερικό, εξαντλημένο, χωρίς ρυθμισμένη μέθοδο ή χωρίς εργάσιμες).' => 'No delivery time shows for this product (digital, external, out of stock, no configured method or no business days).',
		'Προετοιμασία'                                              => 'Preparation',
		'Μεταφορά'                                                  => 'Transit',
		'Παράδοση για παραγγελία τώρα'                              => 'Delivery for an order now',
		'Σάββατο'                                                   => 'Saturday',
		'Ώρα καταστήματος τώρα: %s.'                                => 'Store time now: %s.',
		'Backup ρυθμίσεων'                                          => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)'                                 => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)'                                  => 'Export settings (JSON)',
		'Οι ρυθμίσεις των μεθόδων και των ζωνών δείχνουν σε IDs του WooCommerce: σε άλλο site ίσως χρειαστεί να τις ξαναδείς. Οι τιμές προϊόντων και κατηγοριών δεν μπαίνουν στο backup.' => 'The method and zone settings point to WooCommerce IDs: on another site you may need to review them. Product and category values are not in the backup.',
		'Shortcodes και μπλοκ'                                      => 'Shortcodes and blocks',
		'Το μήνυμα δωρεάν αποστολής για το καλάθι του επισκέπτη, οπουδήποτε. Ανανεώνεται μαζί με το καλάθι.' => 'The free shipping message for the visitor\'s cart, anywhere. It refreshes with the cart.',
		'Ο χρόνος παράδοσης του τρέχοντος ή ενός συγκεκριμένου προϊόντος.' => 'The delivery time of the current product or of a given one.',
		'Μπλοκ'                                                     => 'Blocks',
		'«Δωρεάν αποστολή (Shipping Notes)» και «Χρόνος παράδοσης (Shipping Notes)», στην ομάδα WooCommerce του editor.' => '"Free shipping (Shipping Notes)" and "Delivery time (Shipping Notes)", in the editor\'s WooCommerce group.',
		'Φίλτρα'                                                    => 'Filters',
		'Άγνωστη ενέργεια.'                                         => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                               => 'Settings saved.',
		'Διάλεξες σταθερό ποσό χωρίς ποσό: το μήνυμα δεν εμφανίζεται.' => 'You chose one amount without an amount: the message does not show.',
		'Το χρώμα θέλει μορφή #rrggbb: αγνοήθηκε.'                  => 'The colour needs the #rrggbb format: it was ignored.',
		'Καμία ζώνη δεν έχει ενεργή «Δωρεάν αποστολή» με ελάχιστο ποσό: το μήνυμα δεν θα εμφανιστεί πουθενά.' => 'No zone has an enabled "Free shipping" with a minimum amount: the message will not show anywhere.',
		'Η ώρα cut-off θέλει μορφή ΩΩ:ΛΛ: κρατήθηκε η προηγούμενη.' => 'The cut-off time needs the HH:MM format: the previous one was kept.',
		'Κάποιες αργίες ήταν άκυρες, διπλές ή περασμένες και αγνοήθηκαν.' => 'Some holidays were invalid, duplicate or past and were ignored.',
		'Δεν υπάρχει καμία εργάσιμη: ο χρόνος παράδοσης δεν εμφανίζεται.' => 'There is no business day: the delivery time does not show.',
		'Η ζώνη της σελίδας προϊόντος δεν έχει μέθοδο με ημέρες μεταφοράς: ο χρόνος παράδοσης δεν εμφανίζεται.' => 'The product-page zone has no method with transit days: the delivery time does not show.',
		'Προστέθηκαν %d αργίες.'                                    => '%d holidays added.',
		'Το αρχείο είναι πολύ μεγάλο.'                              => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.'                               => 'No JSON file was chosen.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                          => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Shipping Notes).' => 'Invalid backup file (the Shipping Notes settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.'                                  => 'Import completed.',
		'Made with ❤ by %s'                                         => 'Made with ❤ by %s',
		'More plugins at'                                           => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                            => '☕ Support the project on Ko-fi',
		'Noxpress Dashboard'                                        => 'Noxpress Dashboard',
		// Parcels (1.1.0).
		'Δέμα' => 'Parcel',
		'Δέματα' => 'Parcels',
		'Όταν ένα plugin, π.χ. της Printful, χωρίζει το καλάθι σε δέμα του συνεργάτη και δέμα του καταστήματος, το WooCommerce δείχνει δύο «Αποστολές» με δύο χρεώσεις χωρίς εξήγηση. Εδώ δίνεις όνομα στα δέματα, ένα μήνυμα που εξηγεί τις δύο χρεώσεις, ελληνικές ετικέτες για τις τιμές του συνεργάτη και τη λίστα των δεμάτων στην παραγγελία.' => 'When a plugin such as Printful splits the cart into a partner parcel and a store parcel, WooCommerce shows two "Shipping" rows with two charges and no explanation. Here you name the parcels, add a message that explains the two charges, translate the partner\'s rate labels and list the parcels in the order.',
		'Δέματα του συνεργάτη' => 'Partner parcels',
		'Το δέμα που φτιάχνει το plugin της Printful' => 'The parcel the Printful plugin makes',
		'Και δέματα με προϊόντα από αυτές τις κλάσεις αποστολής. Οι ίδιες κλάσεις δείχνουν ποια προϊόντα παίρνουν την ένδειξη στη σελίδα προϊόντος.' => 'Also parcels with products of these shipping classes. The same classes mark the products that get the product-page hint.',
		'Το κατάστημα δεν έχει κλάσεις αποστολής.' => 'The store has no shipping classes.',
		'Η κλάση αποστολής «%s» δεν υπάρχει πια.' => 'The shipping class "%s" no longer exists.',
		'Κλάσεις αποστολής του WooCommerce' => 'WooCommerce shipping classes',
		'Τι εμφανίζεται' => 'What is shown',
		'Ονόματα δεμάτων στο καλάθι και στο ταμείο («Δέμα 1 · …»), όταν υπάρχουν πάνω από ένα δέματα' => 'Parcel names in the cart and at checkout ("Parcel 1 · …") when there is more than one parcel',
		'Μήνυμα «δύο δέματα» πάνω από τις γραμμές αποστολής, όταν δέμα του συνεργάτη και δέμα του καταστήματος έρχονται μαζί' => '"Two parcels" message above the shipping rows when a partner parcel and a store parcel come together',
		'Χρώμα αριστερού περιγράμματος' => 'Left border colour',
		'Ετικέτες των τιμών του συνεργάτη στη γλώσσα του επισκέπτη (πίνακας παρακάτω)' => 'The partner\'s rate labels in the visitor\'s language (table below)',
		'Ημερομηνίες στα ελληνικά («Oct 20–22» → «20–22 Οκτ»)' => 'Dates in Greek ("Oct 20–22" → "20–22 Οκτ")',
		'Λίστα δεμάτων στη σελίδα ευχαριστώ, στο My account και στα emails του πελάτη' => 'Parcel list on the thank-you page, in My account and in the customer emails',
		'Ένδειξη κάτω από την τιμή στα προϊόντα του συνεργάτη (με τις κλάσεις αποστολής)' => 'A line under the price of partner products (by shipping class)',
		'Ετικέτες των τιμών του συνεργάτη' => 'Partner rate labels',
		'Κείμενο του συνεργάτη' => 'Partner\'s text',
		'Ολόκληρες λέξεις, χωρίς διάκριση πεζών και κεφαλαίων. Κενή στήλη = η ετικέτα μένει όπως τη στέλνει ο συνεργάτης. Αλλάζει μόνο ό,τι βλέπει ο πελάτης: η παραγγελία κρατά την ετικέτα του συνεργάτη, για να τη διαβάζει σωστά όταν παίρνει την παραγγελία. Για να αδειάσει μια γραμμή, σβήσε το πρώτο πεδίο.' => 'Whole words, case-insensitive. An empty column leaves the label as the partner sends it. Only what the customer sees changes: the order keeps the partner\'s label, so the partner reads it correctly when it imports the order. To clear a row, empty its first field.',
		'Παράδειγμα' => 'Example',
		'Δέμα του συνεργάτη' => 'Partner parcel',
		'Δέμα του καταστήματος' => 'Store parcel',
		'Μήνυμα «δύο δέματα»' => '"Two parcels" message',
		'Τίτλος στην παραγγελία' => 'Title in the order',
		'Ένδειξη στη σελίδα προϊόντος' => 'Product-page hint',
		'«Estimated delivery» στις ετικέτες' => '"Estimated delivery" in the labels',
		'Placeholders: {n} αριθμός δέματος, {count} πλήθος δεμάτων.' => 'Placeholders: {n} parcel number, {count} number of parcels.',
		'Δεν διάλεξες ούτε τα δέματα της Printful ούτε κλάση αποστολής: κανένα δέμα δεν θεωρείται του συνεργάτη.' => 'You chose neither the Printful parcels nor a shipping class: no parcel counts as the partner\'s.',
		'Η ένδειξη στη σελίδα προϊόντος θέλει κλάση αποστολής: δεν εμφανίζεται.' => 'The product-page hint needs a shipping class: it is not shown.',
	);

	public static function init(): void {
		add_filter( 'gettext_shipping-notes', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_shipping-notes filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'shipping-notes' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
