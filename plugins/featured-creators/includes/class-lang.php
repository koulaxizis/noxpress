<?php
/**
 * CRT_Lang — domain dictionary + gettext filter (pattern RS_Lang, compact).
 *
 * Admin only: rs_lang (user meta) is READ only (Bible §9): 'el' | 'en' |
 * ''/'auto'. Only Revenue Splitter writes it. Without an explicit choice
 * (or without RS) the user's locale decides: el* → Greek (msgids pass
 * through as they are), anything else → English.
 *
 * The texts visitors see are store settings with a Greek and an English
 * value (Bible §14.17, CRT_Settings::text()), not msgids.
 *
 * Always loaded (even without WooCommerce) so the "WooCommerce missing"
 * admin notice is translated too.
 *
 * Dictionary keys are byte-identical to the msgids in the code (checked
 * with a script on every release — no Latin letters inside Greek words,
 * no duplicates, no missing keys).
 */

defined( 'ABSPATH' ) || exit;

final class CRT_Lang {

	/** Per-request cache: 'el' | 'en'. */
	private static $lang = null;

	private static $dict = array(
		'Δημιουργοί' => 'Creators',
		'Δημιουργός' => 'Creator',
		'Νέος δημιουργός' => 'New creator',
		'Επεξεργασία δημιουργού' => 'Edit creator',
		'Προβολή δημιουργού' => 'View creator',
		'Αναζήτηση δημιουργών' => 'Search creators',
		'Δεν βρέθηκαν δημιουργοί.' => 'No creators found.',
		'Δεν βρέθηκαν δημιουργοί στα διαγραμμένα.' => 'No creators found in Trash.',
		'Φωτογραφία' => 'Photo',
		'Ορισμός φωτογραφίας' => 'Set photo',
		'Αφαίρεση φωτογραφίας' => 'Remove photo',
		'Χρήση ως φωτογραφία' => 'Use as photo',
		'Σελίδα δημιουργού' => 'Creator page',
		'Η σελίδα ενός δημιουργού (Featured Creators).' => 'The page of a creator (Featured Creators).',
		'Δημιουργοί (Featured Creators)' => 'Creators (Featured Creators)',
		'Ευρετήριο των δημοσιευμένων δημιουργών, με φίλτρο ρόλου.' => 'Index of the published creators, with a role filter.',
		'Πλαίσιο δημιουργών (Featured Creators)' => 'Creator box (Featured Creators)',
		'Οι δημιουργοί ενός προϊόντος, με φωτογραφία και σύνδεσμο.' => 'The creators of a product, with photo and link.',
		'Σελίδα δημιουργού (Featured Creators)' => 'Creator page (Featured Creators)',
		'Ολόκληρη η σελίδα του δημιουργού: κεφαλίδα, προϊόντα ανά ρόλο, λίστες.' => 'The whole creator page: header, products by role, lists.',
		'Ρόλος από προεπιλογή (π.χ. pa_syggrafeis)' => 'Default role (e.g. pa_authors)',
		'ID προϊόντος (κενό = το τρέχον)' => 'Product ID (empty = the current one)',
		'Εμφανίζεται στο site με τις ρυθμίσεις του Featured Creators.' => 'Shown on the site with the Featured Creators settings.',
		'Κανένα αποτέλεσμα.' => 'No results.',
		'Εικόνα βίντεο' => 'Video image',
		'Χρήση εικόνας' => 'Use image',
		'Αφαίρεση' => 'Remove',
		'Στοιχεία δημιουργού' => 'Creator details',
		'Συνεντεύξεις, βίντεο, κριτικές και αναφορές' => 'Interviews, videos, reviews and mentions',
		'Ταίριασμα με χαρακτηριστικά' => 'Matching with attributes',
		'Ιδιότητες' => 'Roles',
		'Π.χ. «Ποιήτρια, μουσικός». Κενό = οι ρόλοι όπου έχει προϊόντα (φαίνονται ως placeholder).' => 'E.g. "Poet, musician". Empty = the roles where they have products (shown as placeholder).',
		'Όνομα ταξινόμησης' => 'Sort name',
		'Για τη σειρά στο ευρετήριο, π.χ. «Κουλαξίζης Χρήστος». Κενό = το όνομα όπως γράφεται.' => 'For the order in the index, e.g. "Doe John". Empty = the name as written.',
		'Σύνδεσμοι' => 'Links',
		'Προσθήκη συνδέσμου' => 'Add link',
		'Site, μουσικές υπηρεσίες, κοινωνικά δίκτυα. Η υπηρεσία αναγνωρίζεται από τη διεύθυνση (Spotify, YouTube, Instagram…)· η ετικέτα είναι προαιρετική.' => 'Website, music services, social networks. The service is recognised from the address (Spotify, YouTube, Instagram…); the label is optional.',
		'Ετικέτα (προαιρετική)' => 'Label (optional)',
		'Προσθήκη' => 'Add',
		'Στη σελίδα εμφανίζονται σε τρεις λίστες, νεότερο πρώτο. Τα βίντεο είναι σύνδεσμοι (χωρίς ενσωμάτωση, άρα χωρίς cookies τρίτων), με προαιρετική εικόνα από τη βιβλιοθήκη πολυμέσων.' => 'Shown on the page in three lists, newest first. Videos are links (no embeds, so no third-party cookies), with an optional image from the media library.',
		'Συνέντευξη' => 'Interview',
		'Βίντεο' => 'Video',
		'Κριτική / αναφορά' => 'Review / mention',
		'Μέσο' => 'Outlet',
		'Τίτλος' => 'Title',
		'Εικόνα' => 'Image',
		'Δεν έχουν οριστεί ρόλοι.' => 'No roles have been set.',
		'Ρυθμίσεις' => 'Settings',
		'Κλειδί (slug των terms)' => 'Key (slug of the terms)',
		'Ο δημιουργός παίρνει αυτόματα κάθε term με αυτό το slug σε όλους τους ρόλους. Κενό = το slug της σελίδας.' => 'The creator automatically gets every term with this slug in all roles. Empty = the page slug.',
		'Terms με αυτό το κλειδί' => 'Terms with this key',
		'Κανένα term δεν έχει αυτό το slug. Γράψε το σωστό κλειδί ή πρόσθεσε terms παρακάτω.' => 'No term has this slug. Enter the right key or add terms below.',
		'Ρόλος' => 'Role',
		'Όνομα' => 'Name',
		'Προϊόντα' => 'Products',
		'Κατάσταση' => 'Status',
		'Εξαίρεση' => 'Exclude',
		'Εξαίρεση: για συνωνυμίες, όταν ένα term με το ίδιο slug ανήκει σε άλλο πρόσωπο. Ο άλλος δημιουργός το προσθέτει στα «Επιπλέον terms».' => 'Exclude: for homonyms, when a term with the same slug belongs to another person. The other creator adds it to "Extra terms".',
		'Επιπλέον terms (ψευδώνυμα, άλλη γραφή)' => 'Extra terms (pseudonyms, other spelling)',
		'Αναζήτηση ονόματος σε όλους τους ρόλους (3+ γράμματα)' => 'Search a name in all roles (3+ letters)',
		'Οι αλλαγές ισχύουν με την αποθήκευση. Ένα term ανήκει σε έναν μόνο δημιουργό: το χειροκίνητο νικά το αυτόματο, και δύο ίσες διεκδικήσεις είναι σύγκρουση (κανείς δεν το παίρνει μέχρι να λυθεί).' => 'Changes apply on save. A term belongs to one creator only: manual beats automatic, and two equal claims are a conflict (nobody gets it until it is resolved).',
		'δικό του' => 'theirs',
		'σύγκρουση με' => 'conflict with',
		'ανήκει σε:' => 'belongs to:',
		'μετά την αποθήκευση' => 'after saving',
		'Ρόλοι' => 'Roles',
		'Σελίδες και προϊόντα' => 'Pages and products',
		'Κείμενα' => 'Texts',
		'Κάλυψη' => 'Coverage',
		'Εισαγωγή' => 'Import',
		'Εργαλεία' => 'Tools',
		'Featured Creators — Ρυθμίσεις' => 'Featured Creators — Settings',
		'FC Ρυθμίσεις' => 'FC Settings',
		'Ρόλοι με προϊόντα' => 'Roles with products',
		'Κλειδί' => 'Key',
		'Δεν έχεις διαλέξει ακόμα ποια χαρακτηριστικά είναι ρόλοι.' => 'You have not chosen which attributes are roles yet.',
		'Λειτουργία δοκιμής: οι σελίδες δημιουργών φαίνονται μόνο στους διαχειριστές του καταστήματος.' => 'Test mode: creator pages are visible to shop managers only.',
		'Το Featured Creators είναι ανενεργό: οι σελίδες δημιουργών δεν φαίνονται.' => 'Featured Creators is off: creator pages are not visible.',
		'Ολοκληρώθηκε: %1$d νέοι πρόχειροι δημιουργοί, %2$d παραλείφθηκαν.' => 'Done: %1$d new draft creators, %2$d skipped.',
		'Η εισαγωγή σταμάτησε με σφάλμα. Ό,τι δημιουργήθηκε μέχρι εκεί έμεινε· ξανατρέξε τη για τα υπόλοιπα.' => 'The import stopped with an error. Whatever was created so far stays; run it again for the rest.',
		'Δεν επιλέχθηκε κανένα πρόσωπο.' => 'No person selected.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.' => 'You do not have permission to access this page.',
		'Μία σελίδα για κάθε δημιουργό, με τα προϊόντα του ανά ρόλο, συνδέσμους από τα προϊόντα και ευρετήριο.' => 'One page for every creator, with their products by role, links from the products and an index.',
		'Οι δημιουργοί' => 'The creators',
		'Αποθήκευση' => 'Save',
		'— κανένα —' => '— none —',
		'Λειτουργία' => 'Mode',
		'Ανενεργό' => 'Off',
		'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος' => 'Test mode: visible to shop managers only',
		'Ενεργό για όλους' => 'On for everyone',
		'Στη λειτουργία δοκιμής, οι σελίδες δημιουργών, οι σύνδεσμοι, τα πλαίσια, το ευρετήριο και το schema φαίνονται μόνο σε εσένα· οι υπόλοιποι παίρνουν 404 στις σελίδες δημιουργών και τίποτα αλλού.' => 'In test mode, creator pages, links, boxes, the index and the schema are visible only to you; everyone else gets 404 on creator pages and nothing elsewhere.',
		'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή' => 'Emergency switch-off without admin access: add this line to wp-config.php',
		'Διάλεξε ποια χαρακτηριστικά του WooCommerce είναι ρόλοι δημιουργών και σύρε τα στη σειρά που θέλεις στη σελίδα του δημιουργού. Ένα πρόσωπο αναγνωρίζεται από το ίδιο slug σε όλους τους ρόλους.' => 'Choose which WooCommerce attributes are creator roles and drag them into the order you want on the creator page. A person is recognised by the same slug in all roles.',
		'Προεπιλεγμένα είναι τα χαρακτηριστικά που μοιράζονται slugs με άλλα (πιθανοί ρόλοι). Έλεγξέ τα και αποθήκευσε.' => 'Preselected are the attributes that share slugs with others (likely roles). Check them and save.',
		'Δεν υπάρχουν global χαρακτηριστικά (Προϊόντα → Χαρακτηριστικά).' => 'There are no global attributes (Products → Attributes).',
		'Χαρακτηριστικό' => 'Attribute',
		'Τίτλος ενότητας (el / en)' => 'Section title (el / en)',
		'Όνομα ρόλου (el / en)' => 'Role name (el / en)',
		'Στο πλαίσιο' => 'In the box',
		'Τίτλος ενότητας: π.χ. «Βιβλία ως συγγραφέας» στη σελίδα του δημιουργού. Όνομα ρόλου: π.χ. «Συγγραφέας», στο πλαίσιο του προϊόντος, στο ευρετήριο και στις ιδιότητες. Κενό = το όνομα του χαρακτηριστικού· κενό αγγλικό = το ελληνικό.' => 'Section title: e.g. "Books as author" on the creator page. Role name: e.g. "Author", in the product box, the index and the roles line. Empty = the attribute name; empty English = the Greek one.',
		'Γλώσσα κειμένων' => 'Text language',
		'Αυτόματα (γλώσσα του site)' => 'Automatic (site language)',
		'Ελληνικά' => 'Greek',
		'Βάση διεύθυνσης' => 'URL base',
		'Κενό πάνω από τη σελίδα' => 'Space above the page',
		'π.χ. 2.5em, 40px, 0 — ώστε η σελίδα να μην κολλάει στο header του θέματος.' => 'e.g. 2.5em, 40px, 0, so the page does not stick to the theme header.',
		'Στήλες προϊόντων' => 'Product columns',
		'Μία κάρτα ανά έργο όταν είναι ενεργό το Product Formats (οι μορφές του ίδιου έργου δεν επαναλαμβάνονται)' => 'One card per work when Product Formats is active (formats of the same work are not repeated)',
		'Εμφανίζονται μόνο δημοσιευμένα προϊόντα που φαίνονται στον κατάλογο, νεότερα πρώτα, μία ενότητα ανά ρόλο με τη σειρά των ρόλων. Τα εξαντλημένα κρύβονται αν το λέει η ρύθμιση του WooCommerce.' => 'Only published products visible in the catalog are shown, newest first, one section per role in the order of the roles. Out-of-stock products are hidden if the WooCommerce setting says so.',
		'Πακέτα (bundles)' => 'Bundles',
		'Τα προϊόντα αυτών των κατηγοριών (και των υποκατηγοριών τους) ή τύπων μπαίνουν σε χωριστή ομάδα «Πακέτα», μετά τους ρόλους.' => 'Products in these categories (and their subcategories) or types go into a separate "Bundles" group, after the roles.',
		'Κατηγορίες' => 'Categories',
		'Τύποι προϊόντος' => 'Product types',
		'Στο προϊόν' => 'On the product',
		'Τα ονόματα στα «Επιπλέον πληροφορίες» γίνονται σύνδεσμοι προς τη σελίδα του δημιουργού' => 'Names in "Additional information" become links to the creator page',
		'Πλαίσιο δημιουργών (φωτογραφία, ρόλος, δύο γραμμές βιογραφικού, σύνδεσμος)' => 'Creator box (photo, role, two lines of biography, link)',
		'Θέση πλαισίου' => 'Box position',
		'στο τέλος της καρτέλας «Περιγραφή»' => 'at the end of the "Description" tab',
		'μετά τις καρτέλες' => 'after the tabs',
		'μόνο με shortcode ή μπλοκ' => 'only with shortcode or block',
		'Μέγιστος αριθμός καρτών' => 'Maximum number of cards',
		'Στο πλαίσιο μπαίνουν μόνο οι ρόλοι με τικ «Στο πλαίσιο» (καρτέλα Ρόλοι). Χωρίς καρτέλα «Περιγραφή» (κενή περιγραφή), το πλαίσιο μπαίνει μετά τις καρτέλες.' => 'Only roles ticked "In the box" (Roles tab) go into the box. Without a "Description" tab (empty description), the box goes after the tabs.',
		'Ευρετήριο' => 'Index',
		'Σελίδα ευρετηρίου' => 'Index page',
		'Βάλε στη σελίδα το shortcode [nox_creators] ή το μπλοκ «Δημιουργοί (Featured Creators)». Φίλτρο ανά ρόλο και αλφαβητική σειρά με το «όνομα ταξινόμησης».' => 'Put the shortcode [nox_creators] or the block "Creators (Featured Creators)" on the page. Role filter and alphabetical order by "sort name".',
		'Τίτλος ομάδας πακέτων' => 'Bundles group title',
		'Τίτλος λίστας συνεντεύξεων' => 'Interviews list title',
		'Τίτλος λίστας βίντεο' => 'Videos list title',
		'Τίτλος λίστας κριτικών' => 'Reviews list title',
		'Τίτλος πλαισίου στο προϊόν' => 'Product box title',
		'Σύνδεσμος «όλα τα έργα»' => '"All works" link',
		'Φίλτρο ευρετηρίου «όλοι»' => 'Index filter "all"',
		'Μορφές ενός έργου ({n} = πλήθος)' => 'Formats of a work ({n} = count)',
		'Κείμενα επισκέπτη' => 'Visitor texts',
		'Κείμενο' => 'Text',
		'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό. Οι τίτλοι και τα ονόματα των ρόλων ρυθμίζονται στην καρτέλα Ρόλοι. Φίλτρο για κώδικα: crt_text.' => 'Empty Greek text = the default. Empty English = shows the Greek. Role titles and names are set in the Roles tab. Filter for code: crt_text.',
		'Schema ProfilePage + Person (όνομα, φωτογραφία, περιγραφή, ιδιότητες, σύνδεσμοι ως sameAs)' => 'ProfilePage + Person schema (name, photo, description, roles, links as sameAs)',
		'Rank Math είναι ενεργό: τα στοιχεία μπαίνουν στο δικό του graph και το Article που θα έβαζε για τη σελίδα αφαιρείται. Τίτλος, περιγραφή και sitemap από το Rank Math.' => 'Rank Math is active: the data goes into its graph and the Article it would add for the page is removed. Title, description and sitemap come from Rank Math.',
		'Χωρίς Rank Math: το plugin τυπώνει δικό του JSON-LD.' => 'Without Rank Math: the plugin prints its own JSON-LD.',
		'Οι δημιουργοί στο schema του προϊόντος, ως author, illustrator κ.λπ. (η ιδιότητα κάθε ρόλου ορίζεται στην καρτέλα Ρόλοι)' => 'Creators in the product schema, as author, illustrator etc. (the property of each role is set in the Roles tab)',
		'Το schema.org Product δεν έχει author. Γι\' αυτό ένα προϊόν δηλώνεται και ως έργο (Book, Audiobook, MusicAlbum, Movie) ανάλογα με την κατηγορία του· χωρίς αντιστοίχιση κατηγορίας, το schema του προϊόντος μένει όπως είναι. Μια ιδιότητα που δεν υπάρχει στον τύπο γίνεται contributor.' => 'schema.org Product has no author. So a product is also declared as a work (Book, Audiobook, MusicAlbum, Movie) depending on its category; without a category mapping, the product schema stays as it is. A property the type does not have becomes contributor.',
		'Οι υποκατηγορίες κληρονομούν τον τύπο της γονικής.' => 'Subcategories inherit the type of their parent.',
		'%1$d από %2$d δημοσιευμένα προϊόντα έχουν τουλάχιστον έναν ρόλο.' => '%1$d of %2$d published products have at least one role.',
		'Προϊόντα χωρίς ρόλο (%d)' => 'Products without a role (%d)',
		'Πρόσωπα χωρίς δημιουργό' => 'People without a creator',
		'Κάθε πρόσωπο με προϊόντα έχει δημιουργό.' => 'Every person with products has a creator.',
		'%d πρόσωπα έχουν προϊόντα αλλά όχι σελίδα.' => '%d people have products but no page.',
		'Δημοσιευμένοι δημιουργοί χωρίς προϊόντα' => 'Published creators without products',
		'Κανένας.' => 'None.',
		'Συνήθως λείπει το σωστό κλειδί ή κάποιο ψευδώνυμο στο «Ταίριασμα με χαρακτηριστικά».' => 'Usually the right key or a pseudonym is missing in "Matching with attributes".',
		'Συγκρούσεις' => 'Conflicts',
		'Καμία.' => 'None.',
		'Ένα term σε σύγκρουση δεν ανήκει σε κανέναν. Λύση: εξαίρεση στον έναν δημιουργό ή «επιπλέον term» στον άλλο.' => 'A term in conflict belongs to nobody. Fix: exclude it on one creator or add it as an "extra term" on the other.',
		'Πρόχειροι δημιουργοί από τα terms' => 'Draft creators from the terms',
		'Ένα πρόσωπο ανά slug, από όλους τους ρόλους. Οι επιλεγμένοι γίνονται πρόχειροι δημιουργοί με το όνομα και το slug του term· τα στοιχεία, τη φωτογραφία και τη δημοσίευση τα κάνεις εσύ. Όσοι έχουν ήδη δημιουργό δεν ξαναδημιουργούνται.' => 'One person per slug, from all roles. The selected ones become draft creators with the name and slug of the term; you add the details, the photo and publish. Those who already have a creator are not created again.',
		'Όλα' => 'All',
		'Δεν υπάρχουν terms στους ρόλους.' => 'There are no terms in the roles.',
		'Δημιουργία πρόχειρων δημιουργών' => 'Create draft creators',
		'Backup ρυθμίσεων' => 'Settings backup',
		'Εισαγωγή ρυθμίσεων (JSON)' => 'Import settings (JSON)',
		'Εξαγωγή ρυθμίσεων (JSON)' => 'Export settings (JSON)',
		'Μόνο οι ρυθμίσεις. Οι δημιουργοί εξάγονται με το Εργαλεία → Εξαγωγή του WordPress («Δημιουργοί»). Κατηγορίες και σελίδες δείχνουν σε IDs: σε άλλο site ίσως χρειαστεί να τις ξαναδείς.' => 'Settings only. Creators are exported with WordPress Tools → Export ("Creators"). Categories and pages point to IDs: on another site you may need to check them again.',
		'Shortcodes, μπλοκ και φίλτρα' => 'Shortcodes, blocks and filters',
		'Το ευρετήριο των δημιουργών, προαιρετικά με προεπιλεγμένο ρόλο.' => 'The creators index, optionally with a default role.',
		'Το πλαίσιο δημιουργών του τρέχοντος ή ενός συγκεκριμένου προϊόντος.' => 'The creator box of the current or a specific product.',
		'Μπλοκ' => 'Blocks',
		'«Δημιουργοί», «Πλαίσιο δημιουργών» και «Σελίδα δημιουργού» (για block themes).' => '"Creators", "Creator box" and "Creator page" (for block themes).',
		'Φίλτρα και actions' => 'Filters and actions',
		'Απεγκατάσταση' => 'Uninstall',
		'Διαγραφή των δημιουργών (σελίδες, βιογραφικά, λίστες) όταν διαγραφεί το plugin' => 'Delete the creators (pages, biographies, lists) when the plugin is deleted',
		'Κλειστό: οι δημιουργοί μένουν στη βάση μετά το «Διαγραφή» και ξαναεμφανίζονται αν εγκαταστήσεις πάλι το plugin. Οι ρυθμίσεις φεύγουν πάντα. Οι φωτογραφίες μένουν πάντα στη βιβλιοθήκη πολυμέσων.' => 'Off: creators stay in the database after "Delete" and come back if you install the plugin again. Settings are always removed. Photos always stay in the media library.',
		'Άγνωστη ενέργεια.' => 'Unknown action.',
		'Οι ρυθμίσεις αποθηκεύτηκαν.' => 'Settings saved.',
		'Κανένας ρόλος: οι δημιουργοί δεν θα έχουν προϊόντα.' => 'No roles: creators will have no products.',
		'Το κενό θέλει μορφή όπως 2.5em, 40px ή 0: κρατήθηκε η προεπιλογή.' => 'The space needs a format like 2.5em, 40px or 0: the default was kept.',
		'Η βάση διεύθυνσης δεν επιτρέπεται: κρατήθηκε η προεπιλογή.' => 'This URL base is not allowed: the default was kept.',
		'Χωρίς αντιστοίχιση κατηγορίας σε τύπο έργου, οι δημιουργοί δεν μπαίνουν στο schema των προϊόντων.' => 'Without a category mapped to a work type, creators are not added to the product schema.',
		'Το αρχείο είναι πολύ μεγάλο.' => 'The file is too large.',
		'Δεν επιλέχθηκε αρχείο JSON.' => 'No JSON file selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.' => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Featured Creators).' => 'Invalid backup file (the Featured Creators settings are missing).',
		'Η εισαγωγή ολοκληρώθηκε.' => 'Import complete.',
		'☕ Στήριξε το project στο Ko-fi' => '☕ Support the project on Ko-fi',
		'Το Featured Creators χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.' => 'Featured Creators needs WooCommerce active; the plugin stays inactive until it is activated.',
		'Προβολή σελίδας' => 'View page',
		'Ο δημιουργός ενημερώθηκε.' => 'Creator updated.',
		'Ο δημιουργός δημοσιεύτηκε.' => 'Creator published.',
		'Ο δημιουργός αποθηκεύτηκε.' => 'Creator saved.',
		'Ο δημιουργός υποβλήθηκε.' => 'Creator submitted.',
		'Ο δημιουργός προγραμματίστηκε.' => 'Creator scheduled.',
		'Το προσχέδιο του δημιουργού ενημερώθηκε.' => 'Creator draft updated.',
		// 1.1.0
		'Άρθρο στον τίτλο της καρτέλας' => 'Article in the tab title',
		'Χωρίς άρθρο («Συγγραφέας»)' => 'No article ("Author")',
		'Ο («Ο συγγραφέας»)' => 'Masculine («Ο συγγραφέας»)',
		'Η («Η συγγραφέας»)' => 'Feminine («Η συγγραφέας»)',
		'Για την καρτέλα δημιουργού στο προϊόν, όταν το προϊόν έχει μόνο αυτόν τον δημιουργό.' => 'For the creator tab on the product, when the product has only this creator.',
		'Πληθυντικός (el / en)' => 'Plural (el / en)',
		'Ενότητα στη σελίδα' => 'Section on the page',
		'Στην καρτέλα' => 'In the tab',
		'Πληθυντικός: π.χ. «Συγγραφείς», στον τίτλο της καρτέλας όταν ένα προϊόν έχει πολλούς δημιουργούς του ίδιου ρόλου. Ενότητα στη σελίδα: χωρίς τικ, ο ρόλος μετράει για το ταίριασμα και τις ιδιότητες, αλλά τα προϊόντα του δεν εμφανίζονται στη σελίδα του δημιουργού (π.χ. σελιδοποίηση).' => 'Plural: e.g. "Authors", in the tab title when a product has several creators of the same role. Section on the page: unticked, the role counts for matching and the roles line, but its products are not shown on the creator page (e.g. typesetting).',
		'Καρτέλα δημιουργού στο προϊόν (φωτογραφία, βιογραφικό, σύνδεσμοι, τα τρία πιο πρόσφατα δημοσιεύματα)' => 'Creator tab on the product (photo, biography, links, the three newest press items)',
		'Θέση καρτέλας' => 'Tab position',
		'Προτεραιότητα του WooCommerce: Περιγραφή 10, Επιπλέον πληροφορίες 20, Αξιολογήσεις 30.' => 'WooCommerce priority: Description 10, Additional information 20, Reviews 30.',
		'Στην καρτέλα μπαίνουν μόνο οι δημοσιευμένοι δημιουργοί των ρόλων με τικ «Στην καρτέλα». Προϊόν χωρίς τέτοιον δημιουργό δεν παίρνει καρτέλα.' => 'The tab shows only the published creators of the roles ticked "In the tab". A product without such a creator gets no tab.',
		'Τίτλος καρτέλας: ένας δημιουργός, «Ο» ({role} = ρόλος)' => 'Tab title: one creator, masculine ({role} = role)',
		'Τίτλος καρτέλας: μία δημιουργός, «Η» ({role} = ρόλος)' => 'Tab title: one creator, feminine ({role} = role)',
		'Τίτλος καρτέλας: ένας δημιουργός χωρίς άρθρο ({role} = ρόλος)' => 'Tab title: one creator, no article ({role} = role)',
		'Τίτλος καρτέλας: πολλοί, ίδιος ρόλος ({roles} = πληθυντικός)' => 'Tab title: several, same role ({roles} = plural)',
		'Τίτλος καρτέλας: πολλοί, διαφορετικοί ρόλοι' => 'Tab title: several, different roles',
	);

	public static function init(): void {
		add_filter( 'gettext_featured-creators', array( __CLASS__, 'filter' ), 10, 3 );
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

	/** gettext_featured-creators filter. */
	public static function filter( $translation, $text, $domain ) {
		if ( 'featured-creators' === $domain && isset( self::$dict[ $text ] ) && 'en' === self::lang() ) {
			return self::$dict[ $text ];
		}
		return $translation;
	}
}
