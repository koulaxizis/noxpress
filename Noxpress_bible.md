# Noxpress Bible

Ενιαίο πρότυπο σχεδιασμού και κώδικα για τα plugins της σουίτας Noxpress (noxpress.tech).

- **Έκδοση:** 1.55 (2026-10-20). Νέο: φίλτρα που ακολουθούν την τρέχουσα προβολή: σε σελίδα κατηγορίας το δέντρο κατηγοριών δείχνει μόνο τον κλάδο της, με ρύθμιση ανά φίλτρο ανοιχτή από προεπιλογή· ένα checkbox ρυθμίσεων που ξεκινά τσεκαρισμένο στέλνει και κρυφό `0`, ώστε να αποθηκεύεται και όταν ξετσεκαριστεί (§14), από το Shop Filters 1.2.0.
- **1.54** (2026-10-20): το Site Bridge βγαίνει από την προσωπική χρήση: ενημερώσεις από το `updates.json`, κατάλογος του Core, κάρτα στο site και μετρητής λήψεων, μετά από security review (§16, §21, Core 1.1.17)· γραμμή `unlisted` στον κατάλογο για plugin χωρίς κάρτα (§16)· κανόνες από το security review: μάσκα κατά όνομα που δεν ξεγελιέται με alias / UNION / subquery, zip URL χωρίς dot segments, αρχεία συμπίεσης και αντίγραφα εκτός (§21).
- **1.53** (2026-10-20): οι κρυφοί όροι δεν φεύγουν από την πλήρη ανάγνωση που κρατά την ιεραρχία του WordPress (`get` = all, `fields` = id=>parent), αλλιώς οι υποκατηγορίες τους χάνονται από παντού (§14.22), από το Hide Catalog 1.0.1.
- **1.52** (2026-10-19): η «τελευταία καλή τιμή» της δεύτερης πηγής καλύπτει μόνο σελίδα που δεν διαβάστηκε ή έδωσε απίθανη τιμή, όχι σελίδα που απάντησε χωρίς τιμή (§12), από το Book Feed 1.4.2.
- **1.51** (2026-10-19): τιμή από κείμενο σελίδας που δείχνει και άλλα βιβλία: μόνο αυτή δίπλα στον τίτλο του προϊόντος, αλλιώς καμία· εικόνα σε πλήρες μέγεθος όταν η σελίδα τη συνδέει (§12), από το Book Feed 1.4.1.
- **1.50** (2026-10-19): η γραμμή του μετρητή λήψεων έχει την ίδια εμφάνιση και τα ίδια ονόματα με το nulljsfx.tech, και το «Site cards» ελέγχει ότι τα inline scripts του site περνούν το `node --check` (§17).
- **1.49** (2026-10-19): εικόνα από τη δεύτερη πηγή μόνο όταν είναι μεγαλύτερη, κουμπιά που κρατούν ό,τι γράφτηκε στο κουτί χωρίς αποθήκευση, αριθμοί χωρίς περιττά μηδενικά (§12), από το Book Feed 1.4.0.
- **1.48** (2026-10-19): δεύτερη πηγή τιμής και για sites χωρίς διεύθυνση ανά αναγνωριστικό: σελίδα ανά προϊόν και τιμή από το κείμενο μετά από ετικέτα («Τιμή εκδότη»), με προτεραιότητα, και ονόματα προσώπων στη μορφή του καταστήματος (§12), από το Book Feed 1.3.0.
- **1.47** (2026-10-19): τιμή από δεύτερη πηγή (το site του εκδότη) πάνω από την τιμή του καταλόγου, αυτόματα αλλά με έλεγχο ταυτότητας (ISBN), έλεγχο λογικού εύρους, μία ανάγνωση την ημέρα και ιστορικό (§12), από το Book Feed 1.2.0.
- **1.46** (2026-10-19): προϊόντα από εξωτερικό κατάλογο που ταιριάζουν στη δομή του καταστήματος: σειρά χαρακτηριστικών, μετονομασία τιμών, γονικές κατηγορίες, πρότυπα για όνομα αρχείου και alt εικόνων, σύνθετοι ρόλοι που σπάνε, και προειδοποίηση για http μόνο όταν η σύνδεση γίνεται πράγματι με http (§12), από το Book Feed 1.1.0.
- **1.45** (2026-10-19): Book Feed στη σουίτα (§8, θέση μενού 240· §13, κωδικός `bf`· §16, Core 1.1.16)· `bkf_get_source()` (§6)· προϊόντα από εξωτερικό κατάλογο: ό,τι στέλνει ο browser είναι μόνο ids, άγνωστες τιμές της πηγής σε πίνακες αντιστοίχισης με συντηρητική προεπιλογή, κλείδωμα πεδίων που άλλαξαν με το χέρι, φρένο όταν η πηγή «χάνει» όλα τα προϊόντα, και uninstall που δεν σβήνει προϊόντα που υπήρχαν πριν (§12)· κωδικοί εξωτερικής υπηρεσίας: https πρώτα και κωδικός που δεν φαίνεται ποτέ σε μηνύματα σφάλματος (§12).
- **1.44** (2026-10-19): παράθυρο εγγραφής και 3 / 5 ωρών στο Site Bridge 1.1.0 (§21).
- **1.43** (2026-10-19): Home Sections στη σουίτα (§8, θέση μενού 230· §13, κωδικός `hs`· §16, Core 1.1.15)· φίλτρο `hms_preorder_meta` (§6)· marker classes σε shortcodes του WooCommerce, χωρίς λειτουργία δοκιμής, με δοκιμή shortcode στη σελίδα ρυθμίσεων, και εξαίρεση προπαραγγελιών άλλων plugins από τα meta τους μέσα στο query (§14.30).
- **1.42** (2026-10-18): κρυπτογράφηση αρχείων με κωδικό, κλειστή από προεπιλογή, και μυστικά (κωδικοί) που φυλάει ένα plugin (§12), από την κρυπτογράφηση αντιγράφων του Data Migrator 1.2.0.
- **1.41** (2026-10-18): η κάρτα του site αλλάζει στο ίδιο PR με την έκδοση του plugin (meta, link λήψης, `{p}_download` EN/EL) και το workflow «Site cards» το ελέγχει σε κάθε PR (§13).
- **1.40** (2026-10-18): προγραμματισμένες εργασίες σε βήματα χωρίς browser, με loopback που φέρει token μίας εκτέλεσης, γεγονός ασφαλείας ανά λεπτό, βήματα έως 10 s, μία εργασία τη φορά και έλεγχο ότι η εργασία προχωρά (§11)· όριο ελεύθερου χώρου στον δίσκο για εργασίες που γράφουν μεγάλα αρχεία (§12), από τα προγραμματισμένα αντίγραφα του Data Migrator 1.1.0.
- **1.39** (2026-10-18): Data Migrator στη σουίτα (§1.2, plugin μόνο για WordPress· §8, θέση μενού 220· §9, δικός του διακόπτης γλώσσας όταν λείπει ο RS· §13, κωδικός `nm`· §16, Core 1.1.14)· μεγάλες εργασίες σε βήματα με αρχείο κατάστασης, κλείδωμα και χρονικό όριο ανά αίτημα (§11)· σελίδες που τρέχουν έξω από το WordPress με κωδικό μίας χρήσης, για εργασίες που αντικαθιστούν όλο το site (§11)· ιδιωτικός φάκελος αρχείων με προστασία από τον browser και έλεγχο μέσω HTTP (§12).
- **1.38** (2026-10-18): λίστες με όνομα ανά πελάτη, ειδοποιήσεις με email ύστερα από opt-in του πελάτη, με σύνδεσμο διακοπής χωρίς σύνδεση, και εισαγωγή από τα δεδομένα άλλων plugins λίστας (§14.28)· `wsh_get_items()` δίνει και `list_id`, φίλτρο `wsh_max_lists` (§6), από το Wish Shelf 1.1.0.
- **1.37** (2026-10-18): Checkout Extras στη σουίτα (§8, θέση μενού 210· §13, κωδικός `ce`· §16, Core 1.1.13)· `cke_get_gift()`, φίλτρα `cke_coupon_args` και `cke_text`, action `cke_coupon_created` (§6)· κουπόνια που φτιάχνει το plugin για πελάτες μένουν μετά το uninstall (§12)· χρέωση από επιλογή του πελάτη στο ταμείο, σελίδα ευχαριστίας και κουπόνια που φτιάχνει το plugin (§14.29).
- **1.36** (2026-10-18): πεδίο που παραχωρεί τη θέση του σε πεδίο άλλου plugin της σουίτας όταν εκείνο είναι ενεργό, χωρίς να χάνονται δεδομένα (§6), από το Shipping Notes 1.1.1 και το Pre-Orders.
- **1.35** (2026-10-18): Wish Shelf στη σουίτα (§8, θέση μενού 200· §13, κωδικός `ws`· §16, Core 1.1.12)· `wsh_get_items()`, `wsh_product_count()`, actions `wsh_item_added` / `wsh_item_removed`, φίλτρα `wsh_text` και `wsh_max_items` (§6)· λίστες που φτιάχνουν οι πελάτες μένουν μετά το uninstall, εκτός αν το ζητήσει ο χρήστης (§12)· wc-ajax endpoints για λειτουργία του επισκέπτη (§14.4)· λίστες επισκεπτών και πελατών (§14.28)· η λίστα slugs του `uninstall.php` έχει κάθε plugin της σουίτας, και τα προσωπικής χρήσης (§16).
- **1.34** (2026-10-18): endpoints που τραβά το εξωτερικό σύστημα, με τα κλειδιά WooCommerce REST ή Application Password (§20)· REST `orc/v1/offers` του orOS Connect 1.1.0 (§6).
- **1.33** (2026-10-18): Pre-Orders στη σουίτα (§8, θέση μενού 190· §13, κωδικός `po`· §16, Core 1.1.11)· `por_get_release()`, φίλτρα `por_is_preorder` και `por_text`, action `por_released`, και το Pre-Orders γεμίζει το `shn_available_from` (§6)· κατάσταση προϊόντος που λήγει σε ημερομηνία, ενέργειες στις ανοιχτές παραγγελίες στο παρασκήνιο και emails στους πελάτες (§14.27).
- **1.32** (2026-10-18): μικτά καλάθια με δέματα συνεργάτη παραγωγής (Printful): σήμα από το πακέτο του plugin του συνεργάτη, κανόνες ανά δέμα, ονόματα δεμάτων για το WooCommerce 11, μεταφρασμένες ετικέτες μόνο στην εμφάνιση, λίστα δεμάτων στην παραγγελία (§14.21)· φίλτρα `shn_partner_package`, `shn_partner_rate`, `shn_partner_product` και κατασκευαστής ανά κλάση αποστολής στο Theme Patcher (§6), από τα Shipping Notes 1.1.0, Store Pickup 1.1.1 και Theme Patcher 1.5.1.
- **1.31** (2026-10-18): Publishing Quote (§8, θέση μενού 180· §13, κωδικός `pq`· §16, Core 1.1.10)· υπολογισμοί με κρυφούς συντελεστές μόνο στον server, με αιτήματα «Ενδιαφέρομαι» (§14.26)· `pbq_get_request()`, `pbq_get_requests()`, `pbq_set_request_state()`, actions `pbq_request_created` / `pbq_request_state` και φίλτρο `pbq_text` (§6).
- **1.30** (2026-10-18): κείμενα ενδείξεων από φίλτρα του WooCommerce (αλλάζει μόνο το κείμενο του στοιχείου του θέματος) και αντικατάσταση plugin τρίτου με εισαγωγή των ρυθμίσεών του (§14.17)· φίλτρο `tp_sale_badge_text` (§6), από την ένδειξη έκπτωσης του Theme Patcher 1.5.0.
- **1.29** (2026-10-15): plugins για προσωπική χρήση, εκτός καταλόγου και site (§21)· Site Bridge (§8, θέση μενού 170· §13, κωδικός `sb`)· κανόνες για απομακρυσμένη πρόσβαση μέσω MCP (§21).
- **1.28** (2026-10-14): κανόνες που κρύβουν μια μέθοδο αποστολής ανά προϊόν, με κλάση αποστολής και εξήγηση στα σύνολα του καλαθιού και του ταμείου· shortcodes που ακολουθούν τη λειτουργία και τη λειτουργία δοκιμής· γραμμή στο email του διαχειριστή (§14.21)· φίλτρο `pkp_pickup_allowed` και shortcode `[pkp_stores]` (§6), από το Store Pickup 1.1.0.
- **1.27** (2026-10-14): βίντεο παρουσίασης για κάθε plugin και για το hub, στο site και στο Ko-fi (§13).
- **1.26** (2026-10-14): Product Bundles στη σουίτα (§8, θέση μενού 160· §13, κωδικός `pb`· §16, Core 1.1.9)· `pbn_get_item_bundle()` και φίλτρο `pbn_text` (§6)· δικός μας τύπος προϊόντος και γραμμές παραγγελίας που φτιάχνει το plugin, με την έκπτωση ως διαφορά subtotal / total (§14.25).
- **1.25** (2026-10-14): compact κάρτες plugins στην αρχική του site, σε δύο στήλες, με «All features» που ανοίγει (§13).
- **1.24** (2026-10-13): κείμενα επισκέπτη σε HTML (`wp_kses_post`) για μεγάλα κείμενα περιεχομένου, με placeholders που γεμίζουν στην έξοδο (§14.17)· εντολές WP-CLI (§13)· φίλτρα `tp_care_*` (§6), από την καρτέλα «Φροντίδα και ασφάλεια» του Theme Patcher 1.4.0.
- **1.23** (2026-10-13): orOS Connect στη σουίτα (§8, θέση μενού 150· §13, κωδικός `oc`· §16, Core 1.1.8)· plugins με modules για ένα εξωτερικό σύστημα (§20)· φίλτρο `orc_text` και action `orc_newsletter_sent` (§6)· φόρμες που στέλνουν τον επισκέπτη κατευθείαν σε εξωτερική υπηρεσία (§20).
- **1.22** (2026-10-13): το αντίγραφο μιας μαζικής αλλαγής γράφεται ανά στοιχείο όταν τα στοιχεία εξαρτώνται το ένα από το άλλο, και μια αποτυχημένη εγγραφή αναιρεί ολόκληρη την εκτέλεση από τη σελίδα (§14.24), από το Greeklish Permalinks 1.1.0.
- **1.21** (2026-10-13): συνταγή «Εξαγωγή CSV» (§5), από τις Πωλήσεις του Store Pulse 1.6.0· `RS_VAT::get_rate` στα δημόσια APIs του RS που διαβάζει το SP (§6)· κανόνας για εύρος ημερομηνιών στο `wc_get_orders` (§1.3), από τον Revenue Splitter 1.9.1.
- **1.20** (2026-10-13): Greeklish Permalinks στη σουίτα (§8, θέση μενού 140· §13, κωδικός `gl`· §16, Core 1.1.7)· plugins μόνο για WordPress, χωρίς WooCommerce (§1.2)· `glp_convert()` και φίλτρο `glp_convert` (§6)· πίνακας ανακατευθύνσεων μετά το uninstall (§12)· μαζικές αλλαγές σε υπάρχον περιεχόμενο (§14.24).
- **1.19** (2026-10-13): σελίδα Supporters του site, με τη λίστα ζωντανά από το NULL JSFX, και σύνδεσμοι Ko-fi και Supporters στο footer του site (§19).
- **1.18** (2026-10-13): δωρεάν για πάντα, πληρωμένα πρόσθετα μόνο ως ξεχωριστά plugins, υπηρεσίες και υποστηρικτές (§18).
- **1.17** (2026-10-13): μαζικές αλλαγές δεδομένων γράφουν το αντίγραφο ασφαλείας πριν από την αλλαγή και δεν ξεκινούν αν δεν χωρά ολόκληρο (§12), από το Smart Formatter 1.4.0.
- **1.16** (2026-10-13): Variation Picker στη σουίτα (§8, θέση μενού 130· §13, κωδικός `vp`· §16, Core 1.1.6)· `vpk_get_term_swatch()` και φίλτρο `vpk_text` (§6)· στοιχεία που οδηγούν τη φόρμα του WooCommerce και παράγωγα δεδομένα στο παρασκήνιο (§14.23).
- **1.15** (2026-10-12): Hide Catalog στη σουίτα (§8, θέση μενού 120· §13, κωδικός `hc`· §16, Core 1.1.5)· `hic_hidden_terms()` (§6)· λίστες προϊόντων του Store API μόνο για ανάγνωση (§14.4)· απόκρυψη από τον κατάλογο την ώρα του query (§14.22).
- **1.14** (2026-10-11): Store Pickup στη σουίτα (§8, θέση μενού 110· §13, κωδικός `pk`· §16, Core 1.1.4)· `pkp_get_order_store()` και φίλτρο `pkp_text` (§6)· μέθοδοι αποστολής και επιλογές του πελάτη στο ταμείο (§14.21).
- **1.13** (2026-10-11): Featured Creators στη σουίτα (§8, θέση μενού 100· §13, κωδικός `cr`· §16, Core 1.1.3)· `crt_get_creators()`, φίλτρο `crt_text`, action `crt_creator_changed` (§6)· περιεχόμενο που γράφει ο χρήστης μένει μετά το uninstall, εκτός αν το ζητήσει (§12)· σελίδες δικού μας post type σε κλασικά και block themes (§14.19)· structured data μαζί με SEO plugins (§14.20).
- **1.12** (2026-10-11): Book Preview στη σουίτα (§8, §13, §16, Core 1.1.2)· κώδικας τρίτων στο plugin με τις άδειές του (§10)· αρχεία που φτιάχνει το plugin στο uploads (§12)· βαριές βιβλιοθήκες front end μόνο μετά από ενέργεια του επισκέπτη (§14.18).
- **1.11** (2026-10-11): Shipping Notes στη σουίτα (§8, θέση μενού 90, γιατί το Book Preview κράτησε το 80· §13, §16)· φίλτρα `shn_threshold`, `shn_available_from`, `shn_text` (§6)· ονόματα plugins χωρίς το πρόθεμα «Noxpress» (§10)· δεδομένα extension του Store API μόνο για ανάγνωση (§14.4).
- **1.10** (2026-10-11): ειδοποίηση στο hub για νεότερη έκδοση στο άλλο κανάλι, με «Εγκατάσταση beta» ανά plugin και απόκρυψη ανά έκδοση (§16, Core 1.1.0).
- **1.9** (2026-10-10): εξαίρεση για τα AJAX του καλαθιού (§14.4)· κανόνας για κείμενα που βλέπει ο επισκέπτης και για ρυθμίσεις που δεν εξαρτώνται από το θέμα (§14.17), από τις ετικέτες τιμής του Theme Patcher.
- **1.8** (2026-10-10): Easy Withdrawal στη σουίτα (§8, §13, §16)· hooks `noxpress_withdrawal_*` (§6)· δεδομένα που είναι αρχείο του καταστήματος μένουν μετά το uninstall (§12)· φόρμες επισκεπτών που καταγράφουν δήλωση και κουμπιά με την κλάση του θέματος (§14).
- **1.7** (2026-10-10): Product Formats στη σουίτα (§8, §13, §16)· φίλτρο `pfm_formats` και API `pfm_get_work()` (§6)· αλλαγή μόνο στον κατάλογο του Core χωρίς νέα έκδοση στα άλλα plugins (§16).
- **1.6** (2026-10-10): Shop Filters στη σουίτα (§8, §13, §16)· φίλτρο `noxpress_filters_present` (§6)· κωδικός release πάντα δύο γράμματα (§13)· κανόνες για CSS που αντέχει το θέμα και για μετρήσεις στην προβολή σελίδας (§14).
- **Προέλευση:** η v1.0 δεν υπήρχε στο repo. Η v1.1 ανασυντέθηκε από τον κώδικα των Revenue Splitter 1.7.0, Store Pulse 1.3.0 και Smart Formatter 1.1.0, από τις αναφορές «Bible §N» μέσα σε αυτόν, και από τις συμβάσεις του PR #1. Η αρίθμηση §2–§10 κρατά τη σημασία που ήδη έχει στον κώδικα.
- **Πρότυπη υλοποίηση:** Revenue Splitter. Το `admin.css` του είναι το «leading design».
- Όταν ένα plugin αποκλίνει από το Bible, η απόκλιση γράφεται στο docblock του bootstrap με αιτιολόγηση.

---

## §1 Αρχές

1. **Αυτονομία.** Κάθε plugin δουλεύει μόνο του. Η συνεργασία με τα άλλα γίνεται **μόνο** μέσω των δημόσιων hooks και APIs του §6, ποτέ με απευθείας ανάγνωση των options ή των πινάκων άλλου plugin.
2. **WooCommerce ως προϋπόθεση.** Χωρίς ενεργό WooCommerce το plugin φορτώνει μόνο τη γλώσσα (§9), το Noxpress Core (§16, ώστε το hub και οι ενημερώσεις να δουλεύουν πάντα) και ένα admin notice (σε χρήστες με `activate_plugins`). Κανένας κώδικας που εξαρτάται από το WC δεν φορτώνεται.
   - **Εξαίρεση: plugins μόνο για WordPress** (νέο, από το Greeklish Permalinks). Ένα plugin που δεν χρειάζεται το WooCommerce (π.χ. permalinks) δουλεύει και χωρίς αυτό: χωρίς `Requires Plugins`, χωρίς τα headers `WC requires / tested`, χωρίς δήλωση HPOS και χωρίς notice. Ό,τι αφορά το WooCommerce (προϊόντα, ιδιότητες, αρχεία για download) φορτώνεται μόνο όταν υπάρχει (`function_exists` / `class_exists`). Capability `manage_options` για ρυθμίσεις όλου του site. Η απόκλιση γράφεται στο docblock του bootstrap. Το ίδιο ισχύει για το Data Migrator (1.39), που κάνει αντίγραφο και επαναφορά για κάθε WordPress, με τα βήματα του WooCommerce μόνο όταν υπάρχει.
3. **HPOS.** Κάθε plugin δηλώνει συμβατότητα `custom_order_tables` στο `before_woocommerce_init`. Παραγγελίες διαβάζονται μόνο μέσω CRUD ή `wc_get_orders`. Εύρος ημερομηνιών (`date_created`, `date_paid`, `date_completed`) δίνεται πάντα ως timestamps `from_ts...to_ts` από τα όρια της τοπικής ημέρας (`wp_timezone()`), ποτέ ως strings `Y-m-d H:i:s...`: αυτά το WooCommerce τα συγκρίνει μόνο κατά ημέρα.
4. **Ασφάλεια πρώτα.** Nonce και capability σε κάθε αλλαγή κατάστασης. Αυστηρή επικύρωση με whitelists. Καμία εμπιστοσύνη σε εισαγόμενα δεδομένα (§11).
5. **Καθαρό uninstall.** Μετά το Delete δεν μένει τίποτα δικό του plugin στη βάση (§12).
6. **Κανένα υπόλειμμα στο frontend** χωρίς λόγο. Ό,τι τρέχει στο frontend ακολουθεί τον §14.

## §2 Παλέτα (dark admin)

Ίδιες τιμές σε όλα τα plugins, ως CSS custom properties με το prefix του plugin (`--rs-*`, `--sp-*`, `--sf-*`, `--tp-*`):

| Token | Τιμή | Χρήση |
|---|---|---|
| bg | `#101218` | φόντο του panel της σελίδας |
| panel | `#14161d` | zones, πίνακες |
| panel-2 | `#1d2029` | thead, progress track, code |
| input | `#1a1d26` | inputs, selects, textareas |
| border | `#2e3240` | περιγράμματα zones/πινάκων |
| border-input | `#3a3f52` | περιγράμματα inputs |
| row-border | `#23262f` | γραμμές πινάκων, footer |
| stripe | `#181b23` | ζυγές γραμμές πινάκων |
| hover | `#1e212c` | hover γραμμής |
| text | `#eae8fa` | κείμενο |
| heading | `#f6f4ff` | h1/h2 |
| muted | `#b7b2d6` | περιγραφές, hints |
| link / link-hover | `#beb1ff` / `#d4c9ff` | links |
| accent / accent-hover | `#6d4aff` / `#8263ff` | primary buttons, checked, Ko-fi |
| warn | `#f5c97e` | προειδοποιήσεις |
| danger | `#ff9a9a` | σφάλματα |

## §3 Panel σελίδας

- Κάθε σελίδα admin είναι `<div class="wrap {p}-wrap">` με `color-scheme: dark`, φόντο bg, περίγραμμα `#2b2e36`, `border-radius: 8px`, `padding: 32px 36px 28px` και σκιά `0 1px 3px rgba(0,0,0,.35)`.
- Το panel πιάνει **όλο το πλάτος** της σελίδας: κανένα `max-width` στο `.{p}-wrap` (όπως RS και SP). Όριο πλάτους μπαίνει μόνο σε επιμέρους στοιχεία (π.χ. ένα select ή μια μπάρα προόδου). Ισχύει και για το hub του Core (`.nx-wrap`, §16).
- Οι τίτλοι ενοτήτων είναι `h2.{p}-h2`: 15px, κεφαλαία, χρώμα muted, με κάτω περίγραμμα.
- Οι ομάδες πεδίων είναι `.{p}-zone`: φόντο panel, περίγραμμα border, ακτίνα 6px, padding 16/18.

## §4 Hardening

Πίνακες (`table.widefat.{p}-table`), inputs, selects, textareas και buttons δέχονται `!important` στα χρώματα. Έτσι τα light κανόνες του WP core ή άλλων plugins δεν σπάνε το dark panel. Όλοι οι selectors έχουν το prefix του plugin και κανένας κανόνας δεν βγαίνει έξω από το `.{p}-wrap`.

## §5 Συνταγές UI

- **Κουμπιά:** `.button` σε σκούρο (`#1e2130`, περίγραμμα `#4a4f66`) και `.button-primary` στο accent.
- **Φόρμες ρυθμίσεων:** απλές φόρμες POST με PRG (§11), όχι Settings API, ώστε να ελέγχεται πλήρως η επικύρωση.
- **Αναζήτηση/autocomplete:** AJAX από τον **3ο χαρακτήρα**, όριο 30 αποτελεσμάτων, `LIKE` με `esc_like`.
- **KPI κάρτες και period form:** όπως στο Store Pulse.
- **Μακριές εργασίες:** batches με progress bar (π.χ. Smart Formatter: 20 units ανά request στο apply).
- **Notices μέσα στη σελίδα:** `.notice.inline`, από transient (§11).
- **Εξαγωγή CSV** (νέο, 1.21, από τις Πωλήσεις του Store Pulse):
  - Privileged GET στο `admin_init` (§11), με τα ίδια φίλτρα που δείχνει η οθόνη· η οθόνη και το αρχείο βγαίνουν από τον ίδιο υπολογισμό.
  - UTF-8 με BOM, επικεφαλίδες στη γλώσσα του admin (§9), όνομα `<slug>-<τι>-<από>_<έως>.csv`.
  - Δύο μορφές: «Excel ελληνικά» (`;` και δεκαδικό κόμμα, προεπιλογή όταν η γλώσσα είναι ελληνικά) και «Τυπικό» (`,` και δεκαδική τελεία). Αριθμοί χωρίς διαχωριστικό χιλιάδων, ώστε το Excel να τους διαβάζει ως αριθμούς.
  - Προστασία από formulas μόνο στα κελιά κειμένου: τιμή που ξεκινά με `=`, `+`, `-`, `@`, tab ή CR παίρνει `'` μπροστά. Τα αριθμητικά κελιά μένουν αριθμοί (και αρνητικοί).
  - Ποσά προϊόντων μόνο από τις γραμμές προϊόντων, με ΦΠΑ: μεταφορικά και fees ποτέ. Ο ΦΠΑ από τον συντελεστή του RS όταν υπάρχει (ίδιο νούμερο με τον RS), αλλιώς από τον φόρο της γραμμής του WooCommerce· η σελίδα λέει ποιο ισχύει.
  - Επιστροφές ανά γραμμή (`_refunded_item_id`)· επιστροφή μόνο ποσού μοιράζεται αναλογικά στις γραμμές, όπως στο `RS_Reports::collect_refunds()`.
  - Οι τελευταίες επιλογές θυμούνται ανά χρήστη σε user option (`update_user_option`, με το prefix του site), μόνο με έγκυρο nonce· τις σβήνει το `uninstall.php`.

## §6 Widget και συνεργασία

- **Dashboard widget** (όπου υπάρχει): `wp_add_dashboard_widget` με capability `manage_woocommerce` και compound scope στο CSS.
- **Συμβόλαιο συνεργασίας** (hooks που ακούνε ή πυροδοτούν τα plugins):
  - `rs_invalidate_cache`: ο RS το πυροδοτεί όταν αλλάζουν δεδομένα RS ή παραγγελίες. Το SP το ακούει.
  - `noxpress_products_changed( int[] $product_ids )`: το πυροδοτεί όποιο plugin αλλάζει δεδομένα προϊόντων (SF μετά από apply/restore, Product Formats μετά από καθαρισμό ή επαναφορά upsells, Product Bundles μετά από ανακατασκευή πακέτων). Το SP το ακούει.
  - Δημόσια APIs του RS που διαβάζει το SP: `RS_Reports::run`, `RS_Beneficiaries::collect_names`, `RS_Ledger::sum`, `RS_VAT::get_rate( int $product_id ): float` (από το SP 1.6.0: ο συντελεστής ΦΠΑ ενός προϊόντος για τις Πωλήσεις· οι αλλαγές του πυροδοτούν `rs_invalidate_cache`).
  - `noxpress_filters_present( bool $present )`: φίλτρο. Το Shop Filters επιστρέφει `true` όταν τα φίλτρα του είναι ενεργά (ενεργοποιημένα, με τουλάχιστον ένα σετ). Το διαβάζει όποιο plugin χρειάζεται να ξέρει ότι υπάρχουν φίλτρα. Το Theme Patcher θα το διαβάζει σε επόμενη έκδοση (`TP_Checks`), ώστε να μην προτείνει τα classic widgets φίλτρων του WooCommerce όταν τα φίλτρα του Shop Filters είναι ενεργά.
- `pfm_get_work( int $product_id ): ?array` (Product Formats): το έργο ενός προϊόντος (`id`, `name`, `members` με `id`, `format`, `variant`, `status`, `visible`), ή `null`. Το φίλτρο `pfm_formats( array $work, int $product_id )` αλλάζει το αποτέλεσμα. Τα διαβάζει όποιο plugin χρειάζεται τις μορφές ενός έργου (π.χ. ένα μελλοντικό plugin βιβλίων για schema).
- `noxpress_withdrawal_submitted( int $order_id, array $request )` και `noxpress_withdrawal_status_changed( int $order_id, array $request, string $old_status )` (Easy Withdrawal): πυροδοτούνται όταν καταγράφεται μια δήλωση υπαναχώρησης και όταν αλλάζει η κατάστασή της. Το `$request` έχει `id`, `status`, `created`, `items` (`item_id`, `product_id`, `name`, `qty`, `unit`), `email`, `lang`, `source`. Τα ακούει όποιο plugin χρειάζεται να ξέρει για επιστροφές (π.χ. ένα μελλοντικό Store Pulse με αναφορά επιστροφών).
- `shn_threshold( float $threshold, WC_Shipping_Zone $zone )`, `shn_available_from( string $date, WC_Product $product )` και `shn_text( string $text, string $key, string $lang )` (Shipping Notes): το όριο δωρεάν αποστολής μιας ζώνης, η ημερομηνία κυκλοφορίας (`YYYY-MM-DD`) ενός προϊόντος σε προπαραγγελία και τα κείμενα του επισκέπτη. Το `shn_available_from` το γεμίζει όποιο plugin ξέρει για προπαραγγελίες (π.χ. ένα μελλοντικό plugin βιβλίων). Από το 1.1.0 (δέματα): `shn_partner_package( bool $partner, array $package )`, `shn_partner_rate( bool $partner, WC_Shipping_Rate $rate )` και `shn_partner_product( bool $partner, WC_Product $product )`, αν ένα πακέτο, μια τιμή αποστολής ή ένα προϊόν ανήκει στον συνεργάτη παραγωγής (π.χ. άλλο plugin print on demand εκτός Printful). Το όνομα του δέματος μένει στη γραμμή αποστολής της παραγγελίας ως meta `shn_parcel`.
- `crt_get_creators( int $product_id ): array` (Featured Creators): οι δημόσιοι δημιουργοί ενός προϊόντος (`id`, `name`, `url`, `roles` με τα taxonomies των χαρακτηριστικών). Φίλτρο `crt_text( string $text, string $key, string $lang )` για τα κείμενα του επισκέπτη (τα κείμενα ρόλων με κλειδιά `h:pa_…` και `l:pa_…`). Action `crt_creator_changed( int $creator_id, int[] $product_ids )` όταν αλλάζουν τα προϊόντα ενός δημιουργού, για page caches (το plugin ήδη καλεί `clean_post_cache()`). Τα διαβάζει όποιο plugin χρειάζεται τους δημιουργούς ενός προϊόντος (π.χ. ένα μελλοντικό plugin βιβλίων).
- `pkp_get_order_store( int|WC_Order $order ): ?array` (Store Pickup): το κατάστημα παραλαβής μιας παραγγελίας (`id`, `name`, `address`, `details`, `map`), όπως αποθηκεύτηκε στη γραμμή αποστολής στο ταμείο, ή `null`. Φίλτρο `pkp_text( string $text, string $key, string $lang )` για τα κείμενα του πελάτη. Το id της μεθόδου είναι `nox_store_pickup`. Τα διαβάζει όποιο plugin χρειάζεται το σημείο παραλαβής (π.χ. ένα μελλοντικό Store Pulse με αναφορά ανά κατάστημα). Από το 1.1.0: φίλτρο `pkp_pickup_allowed( bool $allowed, array $package )`, αν ένα πακέτο αποστολής παίρνει την παραλαβή (false = κρύβεται, π.χ. για προϊόντα που στέλνει συνεργάτης παραγωγής), και shortcode `[pkp_stores ids="…"]` με τη λίστα των ενεργών καταστημάτων.
- `hic_hidden_terms( string $context ): int[]` (Hide Catalog): οι κρυφές κατηγορίες προϊόντων για το τρέχον αίτημα (ids, με τις υποκατηγορίες όπου το λέει ο κανόνας), ανά context: `shop`, `listings`, `search`, `related`, `blocks` (πού κρύβονται τα προϊόντα) ή `terms` (η ίδια η κατηγορία κρύβεται από τις λίστες). Κενό όταν οι κανόνες δεν ισχύουν για το αίτημα (ανενεργό, λειτουργία δοκιμής για επισκέπτη, `HIC_DISABLE`). Φίλτρο με το ίδιο όνομα στο αποτέλεσμα. Το Shop Filters δεν το χρειάζεται: οι μετρητές του διαβάζουν το `tax_query` του καταλόγου του WooCommerce (`woocommerce_product_query_tax_query`), όπου το Hide Catalog βάζει τους κανόνες του. Το διαβάζει όποιο plugin φτιάχνει δικές του λίστες προϊόντων ή κατηγοριών.
- `vpk_get_term_swatch( int|WP_Term $term ): ?array` (Variation Picker): η εμφάνιση μιας τιμής χαρακτηριστικού (`type`, `color`, `color2`, `image` ως URL, `label`), ή `null` όταν δεν έχει οριστεί τίποτα. Φίλτρο `vpk_text( string $text, string $key, string $lang )` για τα κείμενα του επισκέπτη. Τα διαβάζει όποιο plugin δείχνει τιμές χαρακτηριστικών (π.χ. χρωματιστές κουκκίδες στα φίλτρα του Shop Filters).
- `glp_convert( string $text, string $context = 'plain' ): string` (Greeklish Permalinks): ελληνικά σε λατινικά με τις ρυθμίσεις του site. Context `slug` (κανόνες, μικρές λέξεις, πεζά· για πραγματικό slug περνά μετά από `sanitize_title()`), `file` (όνομα αρχείου, κρατά την κατάληξη) ή `plain`. Φίλτρο `glp_convert( string $latin, string $original, string $context )` για αλλαγές στο αποτέλεσμα. Τα διαβάζει όποιο plugin χρειάζεται λατινικά από ελληνικό κείμενο (π.χ. ονόματα αρχείων που φτιάχνει). Μετά από μαζική μετατροπή προϊόντων πυροδοτεί το `noxpress_products_changed`.
- REST `orc/v1/offers` (orOS Connect 1.1.0, module «Προσφορές»): `GET /offers` (state, limit, page), `GET /offers/<id>`, `POST /offers/<id>` ( { state, note } ). Τα αιτήματα του Publishing Quote στη μορφή που χρειάζεται το orOS για πρόχειρη προσφορά (γραμμές όπως τις είδε ο επισκέπτης, σύνολο, ΦΠΑ, ανάλυση ως εσωτερική σημείωση). Μόνο για διαχειριστές (§20).
- `orc_text( string $text, string $key, string $lang )` (orOS Connect): τα κείμενα της φόρμας εγγραφής και του κουτιού στο ταμείο (κλειδιά `title`, `intro`, `email`, `name`, `button`, `note`, `checkbox`). Action `orc_newsletter_sent( int $order_id, string $result )` μετά από κάθε προσπάθεια εγγραφής από το ταμείο (`ok`, `bad`, `gone`, `busy`, `off`, `error`, `network`, `skip`). Τα ακούει όποιο plugin θέλει να ξέρει ότι ένας πελάτης γράφτηκε στη λίστα.
- `tp_care_tab_title( string $title, WC_Product $product, string $lang )`, `tp_care_resolved_profile( string $key, WC_Product $product, string $reason )`, `tp_care_manufacturer( string $key, WC_Product $product, array $profile )` και `tp_care_tab_html( string $html, WC_Product $product, array $resolved )` (Theme Patcher, καρτέλα «Φροντίδα και ασφάλεια»): ο τίτλος της καρτέλας, το κλειδί του προφίλ που βγήκε (κενό = χωρίς καρτέλα), το κλειδί του κατασκευαστή (π.χ. ανίχνευση Printful από κώδικα) και το τελικό HTML. Από το 1.5.1 ο κατασκευαστής βγαίνει και από την κλάση αποστολής του προϊόντος (`class_makers` στις ρυθμίσεις), πριν από τον κατασκευαστή του προφίλ· το `tp_care_manufacturer` τρέχει μετά. Τα meta `_tp_care_profile`, `_tp_care_manufacturer`, `_tp_care_note` είναι στο REST (`show_in_rest`) για όσους μπορούν να επεξεργαστούν το προϊόν.
- `tp_sale_badge_text( string $text, WC_Product $product, string $context, string $lang )` (Theme Patcher, ένδειξη έκπτωσης): το κείμενο της ένδειξης (`$context` = `loop` ή `single`· κενό = μένει η ένδειξη του θέματος).
- `pbn_get_item_bundle( WC_Order_Item_Product $item ): ?array` (Product Bundles): το πακέτο από το οποίο ήρθε μια γραμμή παραγγελίας (`bundle_id`, `name`, `group`, `bundles`, `per`, `separate`, `share`), ή `null`. Φίλτρο `pbn_text( string $text, string $key, string $lang )` για τα κείμενα του επισκέπτη. Τα διαβάζει όποιο plugin θέλει να δείξει πωλήσεις «μέσω πακέτου» (π.χ. μια στήλη στο Store Pulse). Οι γραμμές ενός πακέτου είναι κανονικά προϊόντα με δική τους τιμή στο subtotal και την έκπτωση του πακέτου στο total, οπότε RS και SP τις μετρούν σωστά χωρίς να ξέρουν για πακέτα.
- `pbq_get_request( int $id ): ?array`, `pbq_get_requests( array $args ): int[]` και `pbq_set_request_state( int $id, string $state, string $note = '' ): bool` (Publishing Quote): τα αιτήματα «Ενδιαφέρομαι» για άλλα plugins (π.χ. orOS Connect), με ό,τι είδε ο επισκέπτης τη στιγμή του αιτήματος και την ανάλυση μόνο για τον ιδιοκτήτη· καταστάσεις `new`, `contact`, `oros`, `closed`. Actions `pbq_request_created( int $id, array $request )` και `pbq_request_state( int $id, string $state, string $old )`. Φίλτρο `pbq_text( string $text, string $key, string $lang )` για τα κείμενα του επισκέπτη.
- `por_get_release( int|WC_Product $product ): ?array` (Pre-Orders): η προπαραγγελία ενός προϊόντος (`active`, `date` σε `YYYY-MM-DD` ή κενό για «Σύντομα διαθέσιμο», `text`, και `variations` με `id => date` για τα μεταβλητά), ή `null` όταν δεν είναι σε προπαραγγελία. Φίλτρο `por_is_preorder( bool $on, WC_Product $product )`, αν ένα απλό προϊόν ή μια παραλλαγή είναι τώρα σε προπαραγγελία· φίλτρο `por_text( string $text, string $key, string $lang )` για τα κείμενα του επισκέπτη· action `por_released( int $product_id, string $date )` την ημέρα κυκλοφορίας, αφού το προϊόν έγινε κανονικό. Το Pre-Orders γεμίζει το `shn_available_from` του Shipping Notes με την ημερομηνία κυκλοφορίας (κερδίζει του πεδίου «Διαθέσιμο από» του SN) και διαβάζει το `pfm_get_work()` για τη στήλη «Μορφή» της σελίδας του. Τα διαβάζει όποιο plugin χρειάζεται να ξέρει για προπαραγγελίες (π.χ. ένα φίλτρο «Έρχονται» στο Shop Filters ή μια αναφορά στο Store Pulse).
- `wsh_get_items( int $user_id = 0 ): array` (Wish Shelf): η λίστα επιθυμιών ενός συνδεδεμένου πελάτη (`product_id`, `variation_id`, `added` σε GMT, και από το 1.1.0 `list_id`: 0 = βασική λίστα), νεότερα πρώτα, από όλες τις λίστες του. `wsh_product_count( int $product_id ): int`: σε πόσες λίστες πελατών είναι ένα προϊόν (meta `_wsh_count`). Actions `wsh_item_added` και `wsh_item_removed` ( int $user_id, int $product_id, int $variation_id ) όταν αλλάζει η λίστα ενός συνδεδεμένου πελάτη. Φίλτρα `wsh_text( string $text, string $key, string $lang )` για τα κείμενα του επισκέπτη `wsh_max_items( int $max )` για το όριο προϊόντων ανά πελάτη (200) και `wsh_max_lists( int $max )` για το όριο λιστών με όνομα (10, από το 1.1.0). Οι λίστες των επισκεπτών είναι στον browser τους και δεν φαίνονται εδώ. Τα διαβάζει όποιο plugin θέλει να ξέρει τι θέλουν οι πελάτες (π.χ. μια στήλη στο Store Pulse ή ειδοποιήσεις στο orOS Connect).
- `cke_get_gift( int|WC_Order $order ): ?array` (Checkout Extras): το δώρο μιας παραγγελίας (`gift`, `message`, `fee`), ή `null` όταν δεν είναι δώρο. Φίλτρο `cke_coupon_args( array $args, WC_Order $order )` με τις ιδιότητες του κουπονιού ευχαριστίας πριν δημιουργηθεί (false = κανένα κουπόνι), action `cke_coupon_created( WC_Coupon $coupon, WC_Order $order )` μετά τη δημιουργία, και φίλτρο `cke_text( string $text, string $key, string $lang )` για τα κείμενα του πελάτη. Τα διαβάζει όποιο plugin θέλει να δείξει τα δώρα (π.χ. το Shipping Notes στο δελτίο αποστολής) ή να στείλει το κουπόνι αλλού (π.χ. το orOS Connect στη λίστα του newsletter). Στη σελίδα ευχαριστίας τα προϊόντα του μπαίνουν στο `woocommerce_thankyou` με προτεραιότητα 30, μετά τη γραμμή υπαναχώρησης του Easy Withdrawal (20).
- `hms_preorder_meta( array $meta )` (Home Sections): τα meta keys που σημαίνουν προπαραγγελία, ως meta key => τιμή, για τον marker `hs-no-preorders` και τα presets. Προεπιλογή `_simple_preorder` => `yes` (PRENA) και `_por_on` => `yes` (Pre-Orders).
- `bkf_get_source( int $product_id ): ?array` (Book Feed): από πού ήρθε ένα προϊόν (`source`, `id`, `isbn`, `synced`, `linked`), ή `null` όταν δεν ήρθε από τον κατάλογο. `linked = true` σημαίνει προϊόν που υπήρχε ήδη με το ίδιο ISBN και απλώς σημαδεύτηκε: ο κατάλογος δεν αλλάζει τα πεδία του. Πυροδοτεί `noxpress_products_changed` μετά από κάθε εισαγωγή ή βήμα συγχρονισμού που άλλαξε προϊόντα.
- Plugin που **δεν** αλλάζει δεδομένα (π.χ. Theme Patcher, που αλλάζει μόνο την εμφάνιση) δεν πυροδοτεί τίποτα.
- **Ένα πεδίο, ένα σημείο** (νέο, 1.36): όταν δύο plugins της σουίτας ζητούν το ίδιο στοιχείο (π.χ. «Διαθέσιμο από» του Shipping Notes και ημερομηνία κυκλοφορίας του Pre-Orders), το εξειδικευμένο plugin το κρατά και το άλλο το διαβάζει από το φίλτρο του (`shn_available_from`). Όσο το εξειδικευμένο είναι ενεργό, το δικό του πεδίο του άλλου κρύβεται και στη θέση του μπαίνει μια γραμμή που δείχνει πού ορίζεται τώρα. Αν ένα προϊόν έχει ήδη τιμή στο παλιό πεδίο, το πεδίο μένει ορατό ώστε να αλλάζει ή να σβήνεται. Μια αποθήκευση χωρίς το πεδίο στη φόρμα δεν σβήνει ποτέ την τιμή. Χωρίς το εξειδικευμένο plugin όλα είναι όπως πριν.

## §7 Footer

Πανομοιότυπο σε κάθε σελίδα, στο τέλος του `.{p}-wrap`:

```
Made with ❤ by Christos Koulaxizis · glarolykoi.net · More plugins at noxpress.tech
[☕ Στήριξε το project στο Ko-fi]   Noxpress Dashboard
```

- Ko-fi: pill στο accent → `https://ko-fi.com/koulaxizis`.
- Noxpress Dashboard: link στο `admin.php?page=noxpress`.
- Όλα τα links εξωτερικών σελίδων έχουν `target="_blank" rel="noopener noreferrer"`.

## §8 Μενού

- **Κοινό top-level:** slug `noxpress`, τίτλος «Noxpress», `dashicons-chart-pie`, θέση 57, capability `manage_woocommerce`.
- **Ποιος το δημιουργεί:** το Noxpress Core (§16), στο `admin_menu` με προτεραιότητα 5, με το hub ως landing και πρώτο submenu «Επισκόπηση». Τα plugins **δεν** δημιουργούν ποτέ το top-level· προσθέτουν μόνο submenus.
- **Προτεραιότητες `admin_menu`:**
  - Core 5, RS 9, SP 20, SF 30, TP 40, SHF 50, PFM 60, EWD 70, BPV 80 (Book Preview), SHN 90, CRT 100 (Featured Creators), PKP 110 (Store Pickup), HIC 120 (Hide Catalog), VPK 130 (Variation Picker), GLP 140 (Greeklish Permalinks), ORC 150 (orOS Connect), PBN 160 (Product Bundles), SBR 170 (Site Bridge, §21), PBQ 180 (Publishing Quote), POR 190 (Pre-Orders), WSH 200 (Wish Shelf), CKE 210 (Checkout Extras), NM 220 (Data Migrator), HMS 230 (Home Sections), BKF 240 (Book Feed).
  - Νέα plugins παίρνουν το επόμενο +10.
- **Παλιές εκδόσεις** (πριν από το Core) έλεγχαν `empty( $GLOBALS['admin_page_hooks']['noxpress'] )` και έφτιαχναν το top-level μόνο αν έλειπε. Επειδή το Core τρέχει πρώτο, σε ανάμεικτους συνδυασμούς απλώς προσθέτουν τα submenus τους.
- **Υποσελίδες:** κάθε plugin έχει **πάντα** τα δικά του slugs (`{p}-…`), ώστε τα URLs να δουλεύουν σε κάθε συνδυασμό plugins. Η σελίδα ρυθμίσεων λέγεται «{Συντομογραφία} Ρυθμίσεις».
- **Assets:** φορτώνονται μόνο όταν το `$_GET['page']` είναι δικό μας slug. Τη σελίδα `noxpress` τη στιλάρει μόνο το Core.

## §9 Γλώσσα

- **Πηγή:** τα msgids είναι **ελληνικά** και αποτελούν τη μοναδική πηγή αλήθειας. Τα αγγλικά βρίσκονται σε dict μέσα στην κλάση `{P}_Lang` και εφαρμόζονται με φίλτρο `gettext_{text-domain}`.
- **Επιλογή:**
  - Η επιλογή γλώσσας είναι **μία για όλη τη σουίτα**: user meta `rs_lang` (`el` | `en` | `''`).
  - **Μόνο ο RS** το γράφει. Τα υπόλοιπα plugins μόνο το διαβάζουν.
  - **Εξαίρεση: plugins μόνο για WordPress** (νέο, 1.39, από το Data Migrator). Σε site χωρίς RS, ένα plugin του §1.2 δείχνει στις ρυθμίσεις του τον ίδιο διακόπτη (γλώσσα του λογαριασμού / Ελληνικά / English) και γράφει το ίδιο `rs_lang`, ώστε η επιλογή να μένει μία για όλη τη σουίτα. Με ενεργό RS ο διακόπτης κρύβεται.
  - Χωρίς ρητή επιλογή ή χωρίς RS ισχύει το locale του χρήστη: `el*` → Ελληνικά, οτιδήποτε άλλο → English.
- **Πότε φορτώνεται:** η κλάση Lang φορτώνεται **πάντα**, ακόμα και χωρίς WC, ώστε να μεταφράζεται και το notice.
- **Κλειδιά του dict:** byte-identical με τα msgids του κώδικα. Δεν επιτρέπονται λατινικά γράμματα μέσα σε ελληνικές λέξεις ούτε διπλότυπα. Ελέγχεται με script πριν από κάθε release.
- **Δυναμικά labels:** τα labels από registries αποθηκεύονται ως msgids και μεταφράζονται στο render.

## §10 Σταθερές, header, αρχεία

- **Σταθερές:** `{P}_VERSION`, `{P}_FILE`, `{P}_PATH`, και προαιρετικά `{P}_URL`.
- **Όνομα plugin** (νέο, 1.11): χωρίς το πρόθεμα «Noxpress» (π.χ. «Shipping Notes», όχι «Noxpress Shipping Notes»). Η σουίτα φαίνεται από το μενού, το hub και το docblock.
- **Header:**
  - Plugin Name, Plugin URI `https://noxpress.tech`, ελληνικό Description, Version.
  - Requires at least 6.0, Requires PHP 7.4, Requires Plugins `woocommerce`, WC requires at least 7.1.
  - Author «Christos Koulaxizis», Author URI `https://koulaxizis.gr`, License MIT με License URI, Donate URI `https://ko-fi.com/koulaxizis`.
  - Update URI `https://noxpress.tech/updates/<slug>` (§16): το WordPress.org δεν προτείνει ποτέ άλλο plugin με το ίδιο slug ως ενημέρωση.
  - Text Domain = slug, Domain Path `/languages`.
- **Docblock μετά το header:**
  - «Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md. Reference implementation: Revenue Splitter.»
  - Dispatch map: ποια κλάση κάνει τι.
- **Δομή αρχείων:**
  ```
  plugins/<slug>/
    <slug>.php        μόνο bootstrap: σταθερές, requires, init wiring
    uninstall.php
    readme.txt        μορφή WordPress.org, με changelog
    includes/class-*.php
    includes/noxpress-core/   πανομοιότυπο αντίγραφο σε κάθε plugin (§16)
    assets/admin.css, assets/admin.js
  ```
- **Κλάσεις:** `final class {P}_Name` με static μεθόδους. Το bootstrap γίνεται στο `plugins_loaded` (προτεραιότητα 20 για plugins που δεν είναι ο RS).
- **Κώδικας:**
  - Γλώσσα: PHP 7.4+, χωρίς `match`, enums ή named arguments.
  - `defined( 'ABSPATH' ) || exit;` σε κάθε αρχείο.
  - Τα σχόλια μπορεί να είναι ελληνικά ή αγγλικά (ο νέος κώδικας σε αγγλικά). Το changelog γράφεται στα αγγλικά.
- **Κώδικας τρίτων** (νέο, από το Book Preview, που φέρνει το PDF.js):
  - Μόνο όταν δεν γίνεται αλλιώς, μόνο με άδεια συμβατή με MIT/GPL, και πάντα τοπικά: ποτέ CDN ή άλλη εξωτερική κλήση.
  - Στο `assets/vendor/<βιβλιοθήκη>/`, χωρίς αλλαγές στο περιεχόμενο, με αρχείο `VERSION` και όλα τα αρχεία άδειας της βιβλιοθήκης δίπλα στα αρχεία της.
  - Ένα `LICENSES.md` στη ρίζα του plugin γράφει τι είναι κάθε αρχείο, την έκδοση, την άδεια, ό,τι άλλαξε (π.χ. μετονομασία) ή έμεινε έξω, και πώς ενημερώνεται.
  - Ό,τι λείπει ή αλλάζει σε σχέση με το πακέτο της βιβλιοθήκης γράφεται στο docblock του bootstrap ως απόκλιση.

## §11 Μοτίβα ασφαλείας (admin)

- **Αλλαγή κατάστασης με POST:**
  - Γίνεται στο `admin_init`, με έλεγχο slug, nonce και capability.
  - Ακολουθεί επικύρωση, εγγραφή και **PRG**: μήνυμα σε transient `{p}_aui_msg_{user_id}` (60s), `wp_safe_redirect`, `exit`.
- **Προνομιούχο GET** (π.χ. export): `admin_init` με τριπλό έλεγχο: slug, nonce, capability.
- **AJAX:** `check_ajax_referer` και `current_user_can`, με whitelist για **όλες** τις παραμέτρους.
- **Capability:** `manage_woocommerce`.
- **Backup ρυθμίσεων:**
  - Το export είναι JSON `{ version, options{} }` σε attachment.
  - Το import ελέγχει το μέγεθος του upload και κάνει `json_decode`.
  - Η επικύρωση γίνεται **ανά option**. Ένα άκυρο blob αφήνει το υπάρχον option ανέγγιχτο και βγάζει ρητό σφάλμα. Η μερική επιτυχία δηλώνεται με «ολοκληρώθηκε ΜΕΡΙΚΩΣ».
- **Μεγάλες εργασίες σε βήματα** (νέο, 1.39, από το Data Migrator). Ό,τι μπορεί να κρατήσει πάνω από λίγα δευτερόλεπτα (αντίγραφο όλου του site, εισαγωγή βάσης) τρέχει σε σύντομα αιτήματα AJAX:
  - Η κατάσταση γράφεται σε αρχείο εργασίας (JSON πίσω από `<?php exit; ?>`) μετά από κάθε βήμα, με εγγραφή σε `.tmp` και `rename`. Ένα δεύτερο αίτημα για την ίδια εργασία παίρνει «busy» από κλείδωμα `flock`, ποτέ διπλή εκτέλεση.
  - Κάθε αίτημα δουλεύει έως 20 s ή το μισό `max_execution_time` (η σταθερά `NM_BUDGET` στο wp-config.php το αλλάζει για hosts με αυστηρό proxy), και σταματά σε σημείο από όπου συνεχίζει. Μια ανανέωση της σελίδας ή μια χαμένη σύνδεση δεν χαλάει τίποτα.
  - Το WP-CLI τρέχει τα ίδια βήματα στο ίδιο process.
  - **Χωρίς browser** (νέο, 1.40, από τα προγραμματισμένα αντίγραφα του Data Migrator 1.1.0). Μια εργασία που ξεκινά μόνη της (πρόγραμμα) προχωρά με:
    - Ένα γεγονός (Action Scheduler με group το slug, αλλιώς WP-Cron) που περιμένει την ώρα της. Η ώρα υπολογίζεται στη ζώνη ώρας του site.
    - Κάθε βήμα σε δικό του αίτημα: loopback POST στο admin-ajax χωρίς αναμονή (`blocking => false`), με token 128 bit που αλλάζει σε κάθε εκτέλεση. Το endpoint δεν κάνει τίποτα άλλο από το να προχωρά την εργασία που ήδη τρέχει, και η απόκλιση γράφεται στο docblock του bootstrap.
    - Γεγονός ασφαλείας ένα λεπτό μετά, που ξαναμπαίνει πριν από κάθε βήμα: αν ο host μπλοκάρει το loopback ή ένα βήμα πέσει με fatal, η εργασία συνεχίζει ένα βήμα το λεπτό.
    - Βήματα έως 10 s, ώστε να μη κρατιέται worker της PHP για πολύ. Μία εργασία τη φορά: αν τρέχει άλλη (χειροκίνητη ή επαναφορά), το πρόγραμμα περιμένει και ξαναδοκιμάζει, ποτέ παράλληλα.
    - Εργασία που δεν προχωρά (8 βήματα στη σειρά χωρίς αλλαγή ή 12 ώρες) ακυρώνεται και αναφέρεται.
    - Email στον διαχειριστή μόνο με κείμενο και σύνδεσμο στο admin, ποτέ με συνημμένα δεδομένα. Από προεπιλογή μόνο όταν κάτι δεν πάει καλά.
- **Εργασίες που αντικαθιστούν όλο το site** (νέο, 1.39, από το Data Migrator). Μια επαναφορά αλλάζει τη βάση, τους χρήστες και τα plugins, άρα δεν μπορεί να τρέχει μέσα στο admin που αντικαθιστά. Τρέχει σε δική της σελίδα (`runner.php` με `SHORTINIT`, ή ο installer σε άδειο server), με τους εξής κανόνες:
  - Μόνο POST, χωρίς τίποτα μυστικό σε URL. Κωδικός μίας χρήσης 128 bit που φαίνεται μία φορά· αποθηκεύεται μόνο το sha256 του. Ο installer: κλειδί μίας χρήσης, 5 λάθος προσπάθειες κλειδώνουν για 15 λεπτά, λήξη σε 7 ημέρες, session cookie `SameSite=Strict` και πεδίο CSRF.
  - Τίποτα στο live site δεν αλλάζει πριν από τα τελευταία βήματα: η βάση πάει σε προσωρινούς πίνακες και τα αρχεία σε φάκελο αναμονής. Μετά, ένα `RENAME TABLE` και μετακινήσεις αρχείων με ημερολόγιο, ώστε κάθε αλλαγή να αναιρείται. Αποτυχία στο τελευταίο βήμα = αυτόματη αναίρεση.
  - Η απόκλιση από τον κανόνα «nonce και capability» γράφεται στο docblock του bootstrap.

## §12 Δεδομένα και uninstall

- **Ονόματα:** τα options έχουν πάντα το prefix του plugin. Μεγάλα blobs αποθηκεύονται ως JSON με `autoload = false`, εκτός αν διαβάζονται σε κάθε request.
- **`uninstall.php`:**
  - Τρέχει έξω από το plugin, οπότε δεν καλεί καμία κλάση του. Χρησιμοποιεί μόνο core APIs και `$wpdb`.
  - Διαγράφει μια hardcoded λίστα options και τα transients με `LIKE` που έχει escaped `_`.
  - Στο multisite καθαρίζει κάθε site **μία** φορά.
  - Δεν αγγίζει δεδομένα άλλων plugins. Ειδικά το `rs_lang` το διαγράφει μόνο ο RS, και μόνο αν δεν υπάρχει άλλο plugin της σουίτας.
- **Αρχεία που φτιάχνει το plugin** (νέο, από το Book Preview):
  - Σε δικό του φάκελο `uploads/noxpress-<όνομα>/` (από το `wp_upload_dir()`, όχι hardcoded path), με κενό `index.php` (καμία λίστα αρχείων). Χωρίς `.htaccess`: μια οδηγία που ο host δεν επιτρέπει (`AllowOverride`) κάνει κάθε αρχείο του φακέλου σφάλμα 500.
  - Ονόματα που δεν μαντεύονται (π.χ. `<id>-<32 hex>.pdf`) και αυστηρό pattern: κάθε ανάγνωση, διαγραφή ή URL περνά πρώτα από το pattern, ώστε κανένα αρχείο έξω από αυτό να μην αγγίζεται.
  - Γράψιμο σε `.tmp` και `rename` στο τέλος, ώστε ο επισκέπτης να μη βλέπει ποτέ μισό αρχείο.
  - Το `uninstall.php` σβήνει μόνο τα αρχεία που ταιριάζουν στο pattern (και το `index.php`), και τον φάκελο μόνο αν έμεινε άδειος. Τα αρχεία-πηγή του καταστήματος δεν αγγίζονται ποτέ.
- **Χώρος στον δίσκο** (νέο, 1.40, από το Data Migrator 1.1.0). Μια εργασία που γράφει μεγάλα αρχεία δεν αφήνει ποτέ τον δίσκο να γεμίσει, γιατί ένας γεμάτος δίσκος ρίχνει το site (βάση, sessions, uploads):
  - Πριν γράψει, μια εργασία χωρίς επίβλεψη ελέγχει ότι χωρούν τα δεδομένα μαζί με περιθώριο, αλλιώς δεν ξεκινά.
  - Ενώ γράφει, σταματά όταν ο ελεύθερος χώρος πέσει κάτω από ένα σταθερό περιθώριο (256 MB στο Data Migrator) και σβήνει ό,τι μισό έγραψε.
  - Η διατήρηση (retention) σβήνει μόνο ό,τι έφτιαξε η ίδια αυτόματα, ποτέ χειροκίνητα αρχεία, και μόνο μετά από επιτυχία, ώστε μια αποτυχία να μη σβήνει το τελευταίο καλό αντίγραφο.
- **Κρυπτογράφηση αρχείων με κωδικό** (νέο, 1.42, από το Data Migrator 1.2.0). Όταν ένα plugin κρυπτογραφεί αρχεία που φτιάχνει (π.χ. αντίγραφα όλης της βάσης):
  - Είναι προαιρετική και **κλειστή από προεπιλογή**: με χαμένο κωδικό τα αρχεία χάνονται για πάντα, οπότε ο χρήστης την ανοίγει ρητά, με προειδοποίηση και τικ «Κράτησα τον κωδικό σε ασφαλές μέρος» όταν ορίζει νέο κωδικό. Υπάρχει κουμπί «Δημιουργία κωδικού» (`crypto.getRandomValues`) και ελάχιστο μήκος 12.
  - Μορφή που ανοίγει και χωρίς το plugin, με γνωστά εργαλεία (στο ZIP: WinZip AES-256, AE-2, που ανοίγουν το 7-Zip, το WinZip και το macOS), με δικό της salt ανά εγγραφή και έλεγχο ακεραιότητας (HMAC) πριν χρησιμοποιηθούν τα δεδομένα. Ό,τι χρειάζεται για να αναγνωριστεί ένα αρχείο χωρίς κωδικό (id, ημερομηνία, κομμάτια) μπαίνει σε μια μικρή κεφαλίδα χωρίς κρυπτογράφηση.
  - Ποτέ σιωπηλή επιστροφή σε αρχείο χωρίς κρυπτογράφηση: αν η κρυπτογράφηση είναι ανοιχτή αλλά λείπει ο κωδικός ή η επέκταση (OpenSSL), η εργασία δεν ξεκινά, με καθαρό μήνυμα (και email όταν τρέχει χωρίς επίβλεψη). Μια εργασία σε βήματα συνεχίζει μόνο με τον κωδικό με τον οποίο ξεκίνησε.
  - Το readme λέει τι **δεν** κρύβεται (π.χ. ονόματα αρχείων μέσα στο ZIP) και τι κοστίζει (χρόνος).
- **Μυστικά που φυλάει ένα plugin** (νέο, 1.42): κωδικοί που το plugin πρέπει να ξαναδιαβάσει (π.χ. για προγραμματισμένες εργασίες):
  - Σταθερά στο `wp-config.php` όταν υπάρχει (έχει προτεραιότητα και η σελίδα το λέει), αλλιώς option με `autoload = false`, σφραγισμένο (AES-256-GCM) με κλειδί από τα salts του `wp-config.php`. Ποτέ σε απλό κείμενο, ούτε σε αρχεία κατάστασης εργασιών· εκεί μπαίνει σφραγισμένο και σβήνεται μόλις δεν χρειάζεται.
  - Αν τα salts αλλάξουν, το μυστικό δεν διαβάζεται πια: η σελίδα των ρυθμίσεων το λέει και ζητά να γραφτεί ξανά. Το πεδίο δεν δείχνει ποτέ τον αποθηκευμένο κωδικό· κενό σημαίνει «μένει ο τωρινός».
- **Ιδιωτικά αρχεία** (νέο, 1.39, από το Data Migrator). Αρχεία που δεν πρέπει ποτέ να κατέβουν από τον browser (αντίγραφα όλης της βάσης):
  - Φάκελος έξω από το web root όταν ο host το επιτρέπει, αλλιώς `uploads/noxpress-<όνομα>-<12 hex>/`, ή όποιος φάκελος οριστεί στις ρυθμίσεις.
  - Κενό `index.php`, `.htaccess` με deny και `web.config` με deny. Εδώ το `.htaccess` είναι σκόπιμο: αν ο host δεν δέχεται την οδηγία, ο φάκελος δίνει 500, που για τέτοια αρχεία είναι εξίσου ασφαλές. Ένας έλεγχος μέσω HTTP (για nginx, που αγνοεί το `.htaccess`) προειδοποιεί όταν ο φάκελος κατεβαίνει.
  - Λήψη μόνο από το admin, με nonce και capability, και ονόματα που περνούν από αυστηρό pattern.
  - Στο uninstall φεύγουν, εκτός αν ο χρήστης τσεκάρει ρητά «Να μείνουν τα αντίγραφα…»: ένα ξεχασμένο αντίγραφο έχει όλη τη βάση του site. Ένας φάκελος που όρισε ο χρήστης δεν σβήνεται ποτέ ολόκληρος· φεύγουν μόνο τα δικά μας αρχεία και ο φάκελος αν έμεινε άδειος.
- **Εργασίες στο παρασκήνιο:** Action Scheduler του WooCommerce με group το slug, αλλιώς WP-Cron· το `uninstall.php` τις ακυρώνει και στα δύο. Στο Action Scheduler η ακύρωση γίνεται με το hook μόνο (`as_unschedule_all_actions( 'hook' )`) ή με το group μόνο (`as_unschedule_all_actions( '', array(), 'group' )`): με πίνακα args, ακόμα και κενό, ακυρώνονται μόνο οι εργασίες χωρίς args (νέο, 1.23).
- **Μαζικές αλλαγές δεδομένων** (νέο, από το Smart Formatter 1.4.0): κάθε εργαλείο που αλλάζει δεδομένα του καταστήματος μαζικά (κείμενα προϊόντων, όροι κ.λπ.):
  - Γράφει το αντίγραφο ασφαλείας (τις τιμές «πριν») **πριν** από την αλλαγή, ανά batch. Αν το αντίγραφο δεν γράφτηκε, το batch δεν εφαρμόζεται και η εκτέλεση σταματά· ό,τι άλλαξε ως εκεί επαναφέρεται ολόκληρο.
  - Δεν περικόπτει ποτέ σιωπηλά το αντίγραφο. Αν υπάρχει όριο μεγέθους, ο έλεγχος (dry run) το υπολογίζει και η εφαρμογή δεν ξεκινά όταν δεν χωρά ολόκληρο· ο χρήστης χωρίζει τη δουλειά.
  - Γράφει μόνο τιμές που είναι ακόμα ίδιες με αυτές του αντιγράφου (αλλιώς τις παραλείπει με μήνυμα).
  - Δίνει «Λήψη αντιγράφου» (JSON) για κάθε εκτέλεση, που εισάγεται ξανά από το backup του plugin.
- **Παραγωγικά δεδομένα** (π.χ. κείμενα προϊόντων που άλλαξε το SF) **δεν** επαναφέρονται στο uninstall. Το readme το λέει ρητά.
- **Περιεχόμενο που γράφει ο χρήστης** (νέο, από το Featured Creators): σελίδες ενός δικού μας post type που γράφτηκαν με το χέρι (π.χ. δημιουργοί με βιογραφικό και λίστες) **μένουν** μετά το uninstall, εκτός αν ο χρήστης τσεκάρει ρητά «Διαγραφή … όταν διαγραφεί το plugin» στα Εργαλεία. Οι ρυθμίσεις, τα transients και τα παράγωγα δεδομένα (π.χ. term meta που ξαναϋπολογίζεται) φεύγουν πάντα· τα συνημμένα πολυμέσα μένουν πάντα. Η απόκλιση γράφεται στο docblock του bootstrap, στο `uninstall.php` και στο readme.
- **Πίνακας ανακατευθύνσεων** (νέο, από το Greeklish Permalinks): ό,τι άλλαξε μια μαζική μετατροπή (slugs, ονόματα αρχείων, σύνδεσμοι σε κείμενα) **μένει** μετά το uninstall, όπως τα παραγωγικά δεδομένα. Ο πίνακας ανακατευθύνσεων του plugin (παλιές διευθύνσεις → νέες) φεύγει, εκτός αν ο χρήστης τσεκάρει ρητά «Να μείνει ο πίνακας…» στα Εργαλεία. Οι ανακατευθύνσεις του ίδιου του WordPress (`_wp_old_slug`) μένουν πάντα, γιατί τις διαβάζει το WordPress και χωρίς το plugin.
- **Δεδομένα που είναι αρχείο του καταστήματος** (νέο, από το Easy Withdrawal): ό,τι δήλωσε ή αποδέχτηκε ο πελάτης (π.χ. δηλώσεις υπαναχώρησης, συναίνεση στο checkout) μένει στο order meta μετά το uninstall και φεύγει μαζί με την παραγγελία. Το uninstall σβήνει μόνο ρυθμίσεις, transients και σημαίες προϊόντων. Η εξαίρεση γράφεται στο docblock του bootstrap, στο `uninstall.php` και στο readme.
- **Λίστες που φτιάχνουν οι πελάτες** (νέο, 1.35, από το Wish Shelf): ό,τι έφτιαξαν οι πελάτες για τον εαυτό τους (π.χ. λίστες επιθυμιών, κλειδιά κοινοποίησης) **μένει** μετά το uninstall, όπως το περιεχόμενο που γράφει ο χρήστης, εκτός αν ο διαχειριστής τσεκάρει ρητά «Διαγραφή … όταν διαγραφεί το plugin» στα Εργαλεία. Δικός μας πίνακας (`{prefix}{p}_…`) φτιάχνεται με `dbDelta` στην ενεργοποίηση και στο `admin_init` όταν αλλάζει η έκδοση του σχήματος (option `{p}_db`), και φεύγει με `DROP TABLE` μόνο με εκείνο το τικ. Οι μετρητές προϊόντων και τα σημάδια στις παραγγελίες (παράγωγα) φεύγουν πάντα και ξαναϋπολογίζονται στην επόμενη ενεργοποίηση. Το WordPress τα εξάγει και τα σβήνει ανά πελάτη από τα εργαλεία προσωπικών δεδομένων (`wp_privacy_personal_data_exporters` / `_erasers`).
- **Κουπόνια που φτιάχνει το plugin για πελάτες** (νέο, 1.37, από το Checkout Extras): είναι δεδομένα του καταστήματος, γιατί οι πελάτες κρατούν τους κωδικούς. Μένουν μετά το uninstall, εκτός αν ο διαχειριστής τσεκάρει ρητά τη διαγραφή τους (κλειστό από προεπιλογή). Τα σημαδεύει κρυφό meta του plugin (π.χ. `_cke_generated`), και κάθε καθαρισμός ή διαγραφή αγγίζει **μόνο** όσα έχουν αυτό το meta, σε παρτίδες. Τα στατιστικά τους διαβάζονται από meta των παραγγελιών, ώστε να μένουν και όταν τα κουπόνια σβηστούν.
- **Προϊόντα από εξωτερικό κατάλογο** (νέο, 1.45, από το Book Feed):
  - Ο browser στέλνει μόνο ids. Ο server ξαναφέρνει κάθε εγγραφή από την πηγή πριν γράψει προϊόν· τίποτα από τη φόρμα δεν γίνεται τίτλος, τιμή ή εικόνα.
  - Η εγγραφή περνά από έναν κανονικοποιητή (μήκη, αριθμοί σε όρια, URLs μόνο http(s)) και μια εγγραφή χωρίς id ή τίτλο απορρίπτεται.
  - Τιμές που η πηγή δεν τεκμηριώνει (είδη, καταστάσεις, ρόλοι, κατηγορίες, συντελεστές ΦΠΑ) καταγράφονται όπως εμφανίζονται (option `{p}_seen`, με όριο) και η σελίδα ρυθμίσεων φτιάχνει από αυτές πίνακες αντιστοίχισης. Μια τιμή χωρίς αντιστοίχιση παίρνει τη συντηρητική επιλογή (π.χ. διαθεσιμότητα → «Εξαντλημένο»).
  - Πεδία που ενημερώνονται: για κάθε πεδίο που γράφει το plugin κρατιέται αποτύπωμα (hash) της τιμής του. Αν το προϊόν δεν ταιριάζει πια με το αποτύπωμα, κάποιος το άλλαξε με το χέρι· το πεδίο κλειδώνει και ο συγχρονισμός το αφήνει, με γραμμή στο ιστορικό. Τιμή και διαθεσιμότητα ακολουθούν πάντα την πηγή. Ρητό κουμπί στο προϊόν τα ξαναγράφει όλα.
  - Προϊόν που υπάρχει ήδη (ίδιο ISBN / GTIN / SKU) δεν διπλασιάζεται. Μπορεί να «συνδεθεί» (σημάδι μόνο, κανένα πεδίο δεν αλλάζει) και να «υιοθετηθεί» αργότερα με ρητό κουμπί.
  - Προϊόν που φεύγει από την πηγή γίνεται «Εξαντλημένο» ή πρόχειρο και επιστρέφει όταν ξαναεμφανιστεί. Αν μια παρτίδα με αρκετά προϊόντα (≥ 3) γυρίσει άδεια, ο συγχρονισμός σταματά χωρίς αλλαγές: πιο πιθανό είναι σφάλμα της πηγής παρά μαζική απόσυρση.
  - Τα προϊόντα που έφτιαξε το plugin είναι δεδομένα του καταστήματος: μένουν μετά το uninstall, εκτός αν ο χρήστης τσεκάρει ρητά τη διαγραφή τους (κλειστό από προεπιλογή). Τότε σβήνονται σε παρτίδες μόνο όσα έχουν το σημάδι του plugin και **δεν** ήταν συνδεδεμένα (υπήρχαν πριν). Εικόνες και κατηγορίες μένουν πάντα· τα σημάδια (`_{p}_*` meta) φεύγουν πάντα.
  - Το προϊόν ακολουθεί τη δομή του καταστήματος, όχι της πηγής (νέο, 1.46): σειρά χαρακτηριστικών με τα ονόματα που φαίνονται στο προϊόν, πίνακας μετονομασίας τιμών (κενό δεξιά = η τιμή παραλείπεται), γονικές κατηγορίες όταν το κατάστημα τις βάζει, πρότυπα με placeholders για όνομα αρχείου και alt εικόνων (greeklish στο όνομα αρχείου ακόμη και χωρίς Greeklish Permalinks). Σύνθετες τιμές της πηγής («Συγγραφέας, Εικονογράφηση») σπάνε σε απλές πριν την αντιστοίχιση. Ό,τι μπαίνει μόνο στην εισαγωγή (κατηγορίες, ετικέτες, brand) μετά ανήκει στον χρήστη.
  - Δεύτερη πηγή τιμής (νέο, 1.47): όταν η κύρια πηγή έχει παλιές τιμές, το plugin μπορεί να διαβάζει την τιμή από τη σελίδα του προϊόντος στο site του κατασκευαστή / εκδότη (JSON-LD schema.org, Store API του WooCommerce, Open Graph, microdata) και να τη βάζει αυτόματα. Κανόνες: μόνο `wp_safe_remote_get` με χρονικό όριο και όριο μεγέθους· από τη σελίδα βγαίνουν μόνο αριθμοί· σελίδα που ονομάζει άλλο αναγνωριστικό (ISBN / GTIN) αγνοείται· όταν το κατάστημα της πηγής έχει προσφορά, παίρνεται η κανονική τιμή (ListPrice / regular_price)· τιμή εκτός λογικού εύρους (κάτω από το μισό ή πάνω από το διπλάσιο της κύριας πηγής) δεν χρησιμοποιείται· μία ανάγνωση ανά προϊόν την ημέρα (transient)· όταν η σελίδα αποτυγχάνει (δεν απαντά ή δίνει απίθανη τιμή), μετράει η τελευταία καλή τιμή για λίγες ημέρες και μετά η κύρια πηγή· όταν απαντά χωρίς τιμή για το προϊόν, μετράει αμέσως η κύρια πηγή και η παλιά τιμή σβήνεται (1.52)· κάθε αλλαγή και κάθε νέος λόγος αποτυχίας γράφονται μία φορά στο ιστορικό· κουμπί δοκιμής που δεν αλλάζει κανένα προϊόν.
  - Sites χωρίς διεύθυνση ανά αναγνωριστικό και χωρίς δομημένα δεδομένα (νέο, 1.48): η σελίδα ορίζεται ανά προϊόν (meta, πεδίο στο κουτί του plugin) και η τιμή διαβάζεται από το ορατό κείμενο, ως ο πρώτος αριθμός μετά από ετικέτα που ορίζει ο χρήστης («Τιμή εκδότη»). Η ετικέτα έχει προτεραιότητα έναντι των δομημένων τιμών, που συχνά είναι η τιμή με έκπτωση του e-shop. Δεν χρησιμοποιούνται διαδρομές που ο site κλείνει στο `robots.txt` (π.χ. την αναζήτησή του): ο χρήστης βάζει τη σελίδα του προϊόντος. Ονόματα προσώπων της πηγής («Επώνυμο, Όνομα») γυρίζουν στη μορφή του καταστήματος με ρύθμιση. Σελίδες που δείχνουν και άλλα προϊόντα με την ίδια ετικέτα (λίστες «του ίδιου συγγραφέα», «αγόρασαν επίσης») (1.51): παίρνεται μόνο η τιμή που ακολουθεί τον τίτλο του προϊόντος χωρίς τιμή, έτος ή ISBN ανάμεσα (μικροί αριθμοί, π.χ. «ΣΕΙΡΑ 3», επιτρέπονται, 1.52)· αλλιώς, μόνο αν όλες οι τιμές με την ετικέτα είναι ίδιες· αλλιώς καμία, ούτε από δομημένα δεδομένα της ίδιας σελίδας. Όταν η σελίδα συνδέει την ίδια εικόνα ως αρχείο χωρίς σημάδια αλλαγής μεγέθους (`/w640/`, `-600x600`), παίρνεται αυτό.
  - Εικόνα από τη δεύτερη πηγή (νέο, 1.49): όταν η κύρια πηγή δίνει μικρή εικόνα, η ίδια σελίδα που διαβάζεται για την τιμή δίνει και εικόνα (schema.org `image`, αλλιώς `og:image`, μόνο απόλυτη διεύθυνση http(s), μόνο όταν η σελίδα αφορά το ίδιο αναγνωριστικό). Μπαίνει μόνο αν έχει περισσότερα pixels από την τρέχουσα εικόνα του προϊόντος· μικρότερη σβήνεται αμέσως από τη βιβλιοθήκη και η διεύθυνσή της κρατιέται, ώστε να μην κατεβαίνει κάθε μέρα. Όταν η εικόνα ήρθε από τη δεύτερη πηγή, ο καθημερινός συγχρονισμός δεν τη γυρίζει πίσω στη μικρή· μόνο η ρητή πλήρης ενημέρωση ξαναζυγίζει. Ρύθμιση, ανοιχτή από προεπιλογή.
  - Κουμπιά στο κουτί του προϊόντος που κάνουν δική τους αποθήκευση με AJAX (π.χ. «Συγχρονισμός τώρα») στέλνουν και τα πεδία του κουτιού και τα κρατούν: η σελίδα ξαναφορτώνει μετά, οπότε ό,τι γράφτηκε χωρίς «Αποθήκευση» θα χανόταν σιωπηλά (νέο, 1.49).
  - Αριθμοί που γράφονται σε πεδία προϊόντος (βάρος, διαστάσεις) χωρίς περιττά μηδενικά: «600 γρ.», όχι «600,000 γρ.» (νέο, 1.49).
  - Οι εισαγωγές από τη σελίδα και ο συγχρονισμός μοιράζονται την ίδια κλειδαριά, ώστε να μη γράφουν ποτέ μαζί στο ίδιο προϊόν· όσο τρέχει ένα βήμα, η σελίδα περιμένει και ξαναδοκιμάζει.
- **Κωδικοί εξωτερικής υπηρεσίας** (νέο, 1.45, από το Book Feed): όπως τα μυστικά που φυλάει ένα plugin (σφραγισμένα, σταθερά στο `wp-config.php` με προτεραιότητα). Επιπλέον: δοκιμή πρώτα με https όταν η υπηρεσία δίνει μόνο http, με προειδοποίηση στη σελίδα μόνο όταν η τελευταία σύνδεση έγινε πράγματι με http (1.46: όχι σταθερό κείμενο)· κάθε μήνυμα σφάλματος, απόσπασμα απάντησης ή γραμμή ιστορικού περνά από φίλτρο που σβήνει τον κωδικό (και URL-encoded)· το backup ρυθμίσεων δεν τον περιέχει ποτέ.

## §13 Εκδόσεις, release, site

- **Αρίθμηση:**
  - Semver, με πρώτη έκδοση 1.0.0.
  - Τις εκδόσεις τις προτείνει ο Claude και τις αποφασίζει ο Chris.
  - Μικρή αλλαγή ανεβαίνει κατά patch. Πέρασμα με fix και feature ανεβαίνει κατά minor.
- **Τρία σημεία σε κάθε έκδοση:** η έκδοση αλλάζει μαζί στο header, στη σταθερά `{P}_VERSION` και στο `Stable tag` του readme.
- **Stable:** όταν ο Chris πει «stable», βγαίνει το τικ «pre-release» από το release στο GitHub. Το workflow ξαναφτιάχνει τότε το `updates.json` (§16) και τα sites στο κανάλι Stable βλέπουν την έκδοση.
- **Release workflow** (`.github/workflows/release.yml`):
  - Κωδικός ανά plugin: rs, sp, sf, nm, tp, sh, pf, ew, sn, bp, cr, pk, hc, vp, gl, oc, pb, sb, pq, po, ws, ce, hs, bf. Ο κωδικός είναι **πάντα δύο πεζά γράμματα**, γιατί ο updater (§16) δέχεται μόνο tags `nox-[a-z]{2}-<version>`. Το prefix του κώδικα μπορεί να είναι μεγαλύτερο (Shop Filters: `SHF_`, κωδικός `sh`· Product Formats: `PFM_`, κωδικός `pf`· Easy Withdrawal: `EWD_`, κωδικός `ew`· Shipping Notes: `SHN_`, κωδικός `sn`· Book Preview: `BPV_`, κωδικός `bp`· Featured Creators: `CRT_`, κωδικός `cr`· Store Pickup: `PKP_`, κωδικός `pk`· Hide Catalog: `HIC_`, κωδικός `hc`· Variation Picker: `VPK_`, κωδικός `vp`· Greeklish Permalinks: `GLP_`, κωδικός `gl`· orOS Connect: `ORC_`, κωδικός `oc`· Product Bundles: `PBN_`, κωδικός `pb`· Publishing Quote: `PBQ_`, κωδικός `pq`· Pre-Orders: `POR_`, κωδικός `po`· Wish Shelf: `WSH_`, κωδικός `ws`· Checkout Extras: `CKE_`, κωδικός `ce`· Data Migrator: `NM_`, κωδικός `nm`· Home Sections: `HMS_`, κωδικός `hs`· Book Feed: `BKF_`, κωδικός `bf`).
  - Tag `nox-<code>-<version>`, zip `<slug>.zip` με τον φάκελο στη ρίζα.
  - Βγαίνει ως pre-release (beta) μέχρι να δοκιμαστεί.
  - Τα plugins στη λίστα `AUTO` βγαίνουν αυτόματα με το merge στο main.
  - Τα plugins της λίστας `PERSONAL` (νέο, 1.29) βγαίνουν κι αυτά ως release, αλλά δεν μπαίνουν στο `updates.json` ούτε στον μετρητή λήψεων (§21). Από το 1.54 η λίστα είναι κενή.
  - Πριν από το build ελέγχει ότι το secret `NOXPRESS_SIGNING_KEY` υπάρχει και ότι τα αντίγραφα του Core είναι ίδια.
  - Μετά, το job `manifest` γράφει το `updates.json` στη ρίζα του main (§16).
- **Site** (`index.html`):
  - Μία κάρτα ανά plugin, με EN/EL strings `data-i18n` (`{p}_desc`, `{p}_li*`, `{p}_download`).
  - **Compact κάρτες (1.25):** οι κάρτες του `#plugins` μπαίνουν σε δύο στήλες (μία κάτω από 720px). Κλειστή, η κάρτα δείχνει όνομα, meta (έκδοση, πλατφόρμα, άδεια, badge), την περιγραφή κομμένη στις 3 γραμμές και τα κουμπιά Download / Source / Releases. Τα `{p}_li*` μπαίνουν μέσα σε `<details class="more">`, ανάμεσα στην περιγραφή και στα `.actions`, με `<summary>` από τα κοινά `more_show` / `more_hide` («All features» / «Fewer details», «Όλα τα χαρακτηριστικά» / «Λιγότερα»). Ανοιχτή, η περιγραφή φαίνεται ολόκληρη. Ένα link `#<plugin>` ανοίγει την κάρτα. Νέα κάρτα plugin ακολουθεί το ίδιο markup. Οι κάρτες του `#support` μένουν ολόκληρες.
  - Το link download δείχνει στο asset του release.
  - **Κάρτα και έκδοση (1.41):** όταν ανεβαίνει το `Version:` ενός plugin, στο ίδιο PR αλλάζουν και η έκδοση στο meta της κάρτας, το link λήψης (`nox-<code>-<version>/<slug>.zip`) και τα δύο `{p}_download` (EN/EL), ώστε το site να δείχνει την έκδοση που βγαίνει με το merge. Το workflow `.github/workflows/site-check.yml` (`.github/scripts/check-site-cards.py`) το ελέγχει σε κάθε PR που αγγίζει το site ή τα plugins· βίντεο που λείπει βγαίνει μόνο ως προειδοποίηση.
  - **Βίντεο (1.27):**
    - Κάθε κάρτα plugin έχει πάνω δεξιά το pill «▶ Video» (`.card-video`, `data-video="<id>"`, `data-video-title`, key `video_btn`). Το hub έχει το «▶ Tour of the Noxpress hub» (`.video-cta`, key `video_hub`) δίπλα στο κουμπί του Ko-fi.
    - Όλα ανοίγουν ένα κοινό `<dialog id="vidDialog">` με `<video controls playsinline preload="none">`: τίποτα δεν κατεβαίνει πριν από το κλικ, το κλείσιμο σταματά το βίντεο και αφαιρεί την πηγή, το full screen είναι το κουμπί του ίδιου του player. Χωρίς JS, το link ανοίγει το MP4.
    - Αρχεία στο `media/videos/`: `<id>.mp4` (720p H.264, faststart, AAC, περίπου 2 MB) και `<id>.webp` (poster 1280px, η κάρτα τίτλου). Η έκδοση για το Ko-fi (1080p, περίπου 4 MB) μένει στα project files και δεν μπαίνει ποτέ στο repo.
    - Περιεχόμενο: μόνο αγγλικά, 40–58 δευτερόλεπτα, κάρτα τίτλου, πραγματικές οθόνες από τοπικό demo κατάστημα (ποτέ από site πελάτη), λεζάντες πάνω στην εικόνα χωρίς φωνή, κάρτα τέλους με noxpress.tech και Ko-fi. Μουσική: το «NULL JSFX demo loop» (MIT) από το repo null-jsfx.
    - Νέο plugin παίρνει βίντεο με τον ίδιο τρόπο. Όταν αλλάζει αισθητά ένα plugin, το βίντεό του ξαναγράφεται. Το kit εγγραφής (Playwright, ffmpeg) μένει εκτός repo, στα project files (`notes/videos/kit/`).
  - Badge beta μέχρι να γίνει stable.
  - Κάτω από το tagline, η γραμμή «More than X downloads» (§17).
  - Footer σε δύο γραμμές: πρώτα η γραμμή του brand (`footer`), από κάτω τα credits (`credits`, EN/EL): «Designed with ♥ by Christos Koulaxizis. Assisted by Lumo. Audited by Claude.», με links σε koulaxizis.gr, lumo.proton.me και claude.ai. Η καρδιά είναι `♥` στο `--accent-bright`.
- **Εντολές WP-CLI** (νέο, 1.24): `wp <slug> <ομάδα> <εντολή>` (π.χ. `wp theme-patcher care resolve <id>`), φορτώνονται μόνο όταν υπάρχει η σταθερά `WP_CLI`, και είναι μόνο ανάγνωσης εκτός αν ζητηθεί ρητά εγγραφή. Μεγάλες λίστες (`--all`) διαβάζονται σε σελίδες και δέχονται `--format` (`WP_CLI\Utils\format_items`).
- **Έλεγχοι πριν από κάθε release:**
  - `php -l` σε όλα τα αρχεία και PHPCompatibilityWP με `testVersion 7.4-`.
  - Script που συγκρίνει τα msgids του κώδικα με το EN dict (καμία έλλειψη, κανένα αχρησιμοποίητο, κανένα διπλότυπο).
  - Για plugins με frontend: E2E σε τοπικό WordPress με Storefront (byte-identical output όταν δεν υπάρχει τίποτα να διορθωθεί), ένα block theme και ένα συνθετικό test theme. Τα tests μένουν εκτός repo.

## §14 Κανόνες frontend (νέο, από το Theme Patcher)

Για plugins που επεμβαίνουν σε ό,τι βλέπει ο επισκέπτης:

1. **Προεπιλογή «κανένα αποτέλεσμα».** Αμέσως μετά την ενεργοποίηση δεν αλλάζει τίποτα μέχρι ρητή ρύθμιση.
2. **Λειτουργία δοκιμής:** οι αλλαγές φαίνονται μόνο σε χρήστες με `manage_woocommerce`.
3. **Σταθερά έκτακτης ανάγκης** `{P}_DISABLE` στο `wp-config.php`. Σταματά όλα τα frontend hooks.
4. **Ποτέ σε admin, AJAX, REST, feed, embed, cron, CLI ή XML-RPC**, εκτός αν αυτός είναι ο σκοπός.
   - Εξαίρεση: ό,τι αλλάζει το καλάθι, το mini-cart ή το ταμείο τρέχει **και σε AJAX**, γιατί το WooCommerce τα ανανεώνει με AJAX (`wc-ajax` fragments, `update_order_review`). Ποτέ σε σελίδες admin, REST (ούτε στο Store API), feed, cron, CLI ή XML-RPC. Η εξαίρεση γράφεται στο docblock του bootstrap (π.χ. ετικέτες τιμής του Theme Patcher).
   - Εξαίρεση για τα Cart / Checkout blocks (νέο, από το Shipping Notes): ένα plugin μπορεί να προσθέσει δεδομένα **μόνο για ανάγνωση** στην απάντηση καλαθιού του Store API (`woocommerce_store_api_register_endpoint_data` στο `CartSchema`), γιατί τα blocks ανανεώνονται μόνο από εκεί. Υπολογίζονται μόνο μέσα σε εκείνο το callback, δεν αλλάζουν τίποτα και δεν προσθέτουν δικά τους endpoints.
   - Εξαίρεση για λίστες προϊόντων του Store API (νέο, από το Hide Catalog): ένα plugin που αλλάζει **ποια** προϊόντα δείχνει ο κατάλογος μπορεί να φιλτράρει και τη λίστα προϊόντων του Store API (`GET /wc/store/v1/products`, block All Products) και τα cross-sells του καλαθιού στα αιτήματα του Store API, αλλιώς τα blocks θα έδειχναν ό,τι κρύβει το υπόλοιπο κατάστημα. Μόνο ανάγνωση, μόνο προϊόντα, με σημαία που ανάβει στο `rest_request_before_callbacks` για τα routes `/wc/store/…` και σβήνει στο `rest_request_after_callbacks`. Κανένα άλλο REST.
   - Εξαίρεση για λειτουργίες του επισκέπτη (νέο, 1.35, από το Wish Shelf): ένα plugin του οποίου ο σκοπός είναι μια ενέργεια του επισκέπτη χωρίς φόρμα (π.χ. η καρδιά της λίστας επιθυμιών) μπορεί να έχει **δικά του** `wc-ajax` endpoints (`wc_ajax_{p}_…`, μέσω `WC_AJAX::get_endpoint`, όχι `admin-ajax.php`), με τον ίδιο έλεγχο λειτουργίας και αιτήματος με το front end. Endpoint που γράφει δέχεται μόνο POST με nonce (κανόνας 28)· endpoint μόνο ανάγνωσης (GET) επιστρέφει HTML από δημόσια δεδομένα και δεν γράφει τίποτα, ούτε cookie. Ποτέ REST, ποτέ endpoint για άλλους χρήστες. Η εξαίρεση γράφεται στο docblock του bootstrap.
5. **Καμία εγγραφή στη βάση από αίτηση επισκέπτη.** Διαγνωστικά μόνο σε αιτήσεις admin με nonce.
6. **Απομόνωση σφαλμάτων.** Κάθε callback τυλίγεται σε `try/catch( \Throwable )`. Σε σφάλμα το plugin σταματά για το υπόλοιπο request και επιστρέφει το αρχικό output.
7. **Output buffering** μόνο στο ελάχιστο απαραίτητο εύρος. Μέσα σε handler δεν καλείται καμία συνάρτηση `ob_*`. Ένα buffer κλείνει μόνο αν είναι το ανώτερο και είναι δικό μας.
8. **Κανένα αρχείο θέματος ή άλλου plugin** δεν αλλάζει ποτέ. Η απενεργοποίηση επαναφέρει αμέσως το site.
9. **CSS και JS** φορτώνονται μόνο όταν η σελίδα χρειάζεται πραγματικά το αποτέλεσμα. Το CSS τυπώνεται μία φορά στο `<head>`, scoped με prefix.
10. **Υπολογισμοί με queries** (π.χ. ποια εικόνα δείχνει μια κατηγορία) γίνονται στο admin ή σε hooks αποθήκευσης (`save_post_*`, `edited_*`) και αποθηκεύονται. Η προβολή σελίδας μόνο διαβάζει.
11. **Αλλαγές σε όλο το HTML της σελίδας** γίνονται μόνο όταν υπάρχει ρύθμιση που τις χρειάζεται: ένα wrapper template (`template_include`) τρέχει το πρότυπο του θέματος μέσα σε buffer με pure-string handler.
12. **Με ενεργή την προστασία ρυθμίσεων, ο κώδικας του θέματος δεν γράφει στη βάση από το front end:** η προστασία ακυρώνει την εγγραφή μέσω `pre_update_option_*` χωρίς να αλλάζει την αποθηκευμένη τιμή. Ο Customizer, το admin, το AJAX και το REST δεν μπλοκάρονται ποτέ.
13. **CSS που αντέχει το θέμα** (νέο, από το Shop Filters). Κανόνες θέματος με id (π.χ. `#sidebar ul li { display: flex }`, `#sidebar span { float: right; background: … }`) νικούν κάθε class selector. Γι' αυτό:
    - Ένα reset στην αρχή του CSS, scoped στο root του plugin (`.{p}-root div, a, span, …`), μηδενίζει με `!important` float, margin, padding, border, background, σκιές, typography και text-transform. Οι κανόνες του plugin ακολουθούν με μεγαλύτερη ειδικότητα και `!important`.
    - Τα πεδία φόρμας (inputs, buttons) κρατούν την εμφάνιση του θέματος, εκτός από το μέγεθος.
    - Markup χωρίς `ul / li`, χωρίς checkbox inputs (κουτιά από CSS), χωρίς icon fonts (inline SVG), και `<details>` για ό,τι ανοιγοκλείνει.
    - Δοκιμή σε συνθετικό θέμα με εχθρικούς κανόνες (§13).
14. **Μετρήσεις που εξαρτώνται από το αίτημα** (π.χ. μετρητές φίλτρων) δεν μπορούν να αποθηκευτούν από πριν (κανόνας 10). Υπολογίζονται στην προβολή με queries μόνο ανάγνωσης, κρατιούνται στην object cache με κλειδί το «last changed» των posts / terms, και δεν γράφουν τίποτα στη βάση. Η σελίδα του plugin δείχνει αν υπάρχει μόνιμη object cache.
15. **Φόρμες επισκεπτών που καταγράφουν δήλωση** (νέο, από το Easy Withdrawal). Όταν ο σκοπός του plugin είναι να καταγράψει κάτι που στέλνει ο επισκέπτης (π.χ. δήλωση υπαναχώρησης), η εγγραφή στη βάση επιτρέπεται ως εξαίρεση στον κανόνα 5, με αυτούς τους όρους:
    - Γράφει μόνο το τελικό, ρητό βήμα («Επιβεβαίωση»). Τα ενδιάμεσα βήματα μεταφέρονται σε υπογεγραμμένο token (HMAC με `wp_salt`, με λήξη) και δεν γράφουν τίποτα.
    - Nonce για συνδεδεμένους χρήστες, honeypot, όριο αποτυχημένων προσπαθειών ανά IP (η IP μόνο ως hash), αποθήκευση μόνο με CRUD (HPOS και legacy).
    - Η σελίδα της φόρμας δεν μπαίνει ποτέ σε cache: `DONOTCACHEPAGE` και `nocache_headers()` πριν από το output.
    - Μετά την εγγραφή γίνεται redirect (PRG), ώστε ένα refresh να μη στέλνει ξανά τη φόρμα, και τα διπλά submit μέσα σε λίγα λεπτά αγνοούνται.
16. **Κουμπιά στο front end** (νέο, από το Easy Withdrawal): `class="button"` μαζί με `wc_wp_theme_get_element_class_name( 'button' )`, ώστε τα block themes (TT25) να δίνουν στα κουμπιά το δικό τους στυλ, όπως στις φόρμες του WooCommerce.
17. **Κείμενα που βλέπει ο επισκέπτης** (νέο, από τις ετικέτες τιμής του Theme Patcher):
    - Είναι **ρυθμίσεις του καταστήματος**, όχι msgids. Ο §9 και το `rs_lang` αφορούν μόνο το admin.
    - Κάθε κείμενο έχει ελληνική και αγγλική τιμή. Η γλώσσα επιλέγεται στο plugin: Αυτόματα (από το locale του site, `get_locale()`), Ελληνικά ή English. Κενό αγγλικό κείμενο δείχνει το ελληνικό.
    - Απλό κείμενο με όριο μήκους, `sanitize_text_field` στην αποθήκευση και `esc_html` στην έξοδο. Ένα φίλτρο (`{p}_…_text`) επιτρέπει αλλαγές από κώδικα (π.χ. WPML).
    - Ρυθμίσεις που δεν εξαρτώνται από το θέμα (π.χ. ετικέτες τιμής) αποθηκεύονται έξω από τα μπλοκ ανά θέμα και ισχύουν και σε block themes, όταν περνούν από φίλτρα του WooCommerce που τρέχουν σε κάθε θέμα.
    - Τιμές, παραγγελίες, emails, τιμολόγια, feeds και schema κρατούν πάντα την πραγματική τιμή: το κείμενο είναι μόνο για την προβολή.
    - **Εξαίρεση HTML** (νέο, 1.24, από την καρτέλα «Φροντίδα και ασφάλεια» του Theme Patcher): μεγάλα κείμενα περιεχομένου (π.χ. οδηγίες φροντίδας) μπορεί να είναι HTML με `wp_kses_post` στην αποθήκευση και στην έξοδο, με όριο μήκους (κείμενο πάνω από το όριο απορρίπτεται, δεν κόβεται) και τον μικρό editor (`wp_editor` teeny, χωρίς πολυμέσα). Οι τίτλοι και οι σύντομες γραμμές μένουν απλό κείμενο.
    - **Placeholders** (`{product_name}`, `{material}` κ.λπ.) γεμίζουν στην έξοδο, με `esc_html` στην τιμή. Η πρόταση (ως την τελεία) που έχει placeholder χωρίς τιμή φεύγει ολόκληρη, άγνωστα placeholders φεύγουν, και πολλές τιμές ενώνονται με «, ».
    - **Ενδείξεις από φίλτρα του WooCommerce** (νέο, 1.30, από την ένδειξη έκπτωσης του Theme Patcher, π.χ. `woocommerce_sale_flash`): αλλάζει μόνο το κείμενο. Όταν το HTML του φίλτρου είναι ένα απλό στοιχείο, κρατιούνται το tag και οι κλάσεις του θέματος· αλλιώς μπαίνει το τυπικό markup του WooCommerce. Ένα μπλοκ που ο χρήστης έχει ρυθμίσει να δείχνει δικό του περιεχόμενο (π.χ. ποσοστό) δεν αλλάζει.
    - **Αντικατάσταση plugin τρίτου** (νέο, 1.30): κουμπί «Εισαγωγή» που διαβάζει μόνο το option του (δεν το αλλάζει ποτέ), υπενθύμιση απενεργοποίησης όσο είναι ενεργό, και priority μετά από το δικό του, ώστε η ρύθμισή μας να υπερισχύει όσο τρέχουν και τα δύο.
18. **Βαριές βιβλιοθήκες στο front end** (νέο, από το Book Preview): η σελίδα φορτώνει μόνο ένα μικρό script· η βιβλιοθήκη (π.χ. ο viewer PDF.js, ~1 MB) φορτώνεται με `import()` στην πρώτη ενέργεια του επισκέπτη (κλικ), από τον φάκελο `assets/vendor/` του plugin (§10). Αρχεία ES module σερβίρονται με κατάληξη `.js`, γιατί πολλοί servers στέλνουν το `.mjs` με λάθος MIME type. Αν η φόρτωση αποτύχει, ο επισκέπτης βλέπει μήνυμα και το υπόλοιπο της σελίδας δεν επηρεάζεται.

19. **Σελίδες δικού μας post type** (νέο, από το Featured Creators):
    - Κλασικά θέματα: template μέσω `template_include` με `get_header()` / `get_footer()` και τα συνηθισμένα wrappers `<div id="primary" class="content-area"><main id="main" class="site-main">`, ώστε να ισχύουν το grid προϊόντων και τα κενά του θέματος (Storefront, Astra). Ένα `single-<post_type>.php` του θέματος κερδίζει πάντα. Χωρίς sidebar, με body class του plugin για πλήρες πλάτος, και ρυθμιζόμενο κενό πάνω από τη σελίδα.
    - Block themes: `register_block_template()` (WP 6.7+) με ένα δυναμικό μπλοκ, ώστε το πρότυπο να αλλάζει στον Site Editor· σε παλαιότερα WP η σελίδα μπαίνει στο single template του θέματος μέσω `the_content`.
    - Προϊόντα με τις κάρτες του θέματος (`wc_setup_loop` + `content-product`), ποτέ με δικό μας markup κάρτας.
    - Όσο δεν τη βλέπει ο επισκέπτης (λειτουργία δοκιμής, ανενεργό, πρόχειρο, κωδικός), η σελίδα απαντά 404 και λείπει από τα sitemaps (WordPress και Rank Math).
    - Οθόνη επεξεργασίας με τον κλασικό editor (`use_block_editor_for_post_type`) όταν τα δεδομένα είναι κυρίως meta boxes· το `show_in_rest` μένει για τα μπλοκ και το REST.
20. **Structured data μαζί με SEO plugins** (νέο, από το Featured Creators): ποτέ δύο graphs για την ίδια σελίδα. Με Rank Math, οι κόμβοι μπαίνουν στο δικό του graph (`rank_math/json_ld`) και διορθώνεται ό,τι δεν ταιριάζει (π.χ. το Article που βάζει για νέο post type)· δικό μας `<script type="application/ld+json">` μόνο όταν το SEO plugin δεν τύπωσε graph στη σελίδα. Ιδιότητες που ο τύπος του schema.org δεν έχει (π.χ. `author` στο `Product`) μπαίνουν μόνο με πολλαπλό τύπο (`["Product", "Book"]`), από αντιστοίχιση που ορίζει ο χρήστης· αλλιώς το schema μένει όπως είναι.
21. **Μέθοδοι αποστολής και επιλογές του πελάτη στο ταμείο** (νέο, από το Store Pickup):
    - Μια μέθοδος αποστολής είναι κανονική κλάση `WC_Shipping_Method` με ζώνες (`shipping-zones`, `instance-settings`). Με το `{P}_DISABLE` δεν δηλώνεται καν, ώστε το WooCommerce να αγνοεί τις εγγραφές της στις ζώνες και το ταμείο να πέφτει στις άλλες μεθόδους.
    - Η λειτουργία δοκιμής κρύβεται στο `is_available()`. Ό,τι αλλάζει τη διαθεσιμότητα (ποιος βλέπει, κλασικό ταμείο ή Store API) μπαίνει στο πακέτο (`woocommerce_cart_shipping_packages`), γιατί το WooCommerce κρατά τις τιμές ανά hash πακέτου· η αποθήκευση ρυθμίσεων ακυρώνει την cache (`WC_Cache_Helper::get_transient_version( 'shipping', true )`).
    - Μια επιλογή του πελάτη κάτω από τη μέθοδο (π.χ. κατάστημα) τυπώνεται στο `woocommerce_after_shipping_rate` μόνο για την επιλεγμένη τιμή και μόνο στο ταμείο. Κρατιέται στις ανανεώσεις από το `post_data` του `update_order_review`, ποτέ από cookie ή session (κανόνας 5). Ελέγχεται στο `woocommerce_after_checkout_validation` με whitelist.
    - Αποθηκεύεται στη γραμμή αποστολής της παραγγελίας (`woocommerce_checkout_create_order_shipping_item`) ως στιγμιότυπο: κρυφό meta για τον κώδικα και αναγνώσιμο meta με ετικέτα για το admin, ώστε να διαβάζεται και χωρίς το plugin. Στη σελίδα ευχαριστίας, στον λογαριασμό και στα emails μπαίνει γραμμή στο `woocommerce_get_order_item_totals`.
    - Το Checkout block δεν έχει θέση για τέτοια επιλογή: εκεί η μέθοδος προσφέρεται μόνο όταν η επιλογή είναι μία, αλλιώς κρύβεται.
    - **Κανόνες ανά προϊόν** (νέο, 1.28, από το Store Pickup 1.1.0): όταν κάποια προϊόντα δεν επιτρέπουν μια μέθοδο, το σήμα είναι η **κλάση αποστολής** του WooCommerce (εγγενής, ισχύει σε παραλλαγές μέσω του γονικού και δουλεύει σε κάθε site), όχι meta άλλων plugins. Ο έλεγχος γίνεται στο `is_available()` με τα περιεχόμενα του πακέτου, ώστε να ισχύει και στο Store API. Ο κανόνας έχει δικό του διακόπτη, κλειστό από προεπιλογή, και φίλτρο για κώδικα.
    - Όταν ένας κανόνας κρύβει μια μέθοδο που η ζώνη προσφέρει, ο πελάτης βλέπει γιατί: μία γραμμή στα σύνολα (`woocommerce_review_order_after_shipping`, `woocommerce_cart_totals_after_shipping`), που ανανεώνεται με το AJAX του ταμείου χωρίς δικό της endpoint. Το κείμενο είναι κείμενο επισκέπτη (κανόνας 17).
    - Πληροφορίες για τον διαχειριστή μπαίνουν μόνο στο email «Νέα παραγγελία» (`woocommerce_email_order_details` με `$sent_to_admin` και `$email->id === 'new_order'`), HTML και απλό κείμενο, με διακόπτη.
    - **Shortcodes** που δείχνουν ρυθμίσεις (π.χ. λίστα καταστημάτων) δηλώνονται πάντα, και με το `{P}_DISABLE`, ώστε να μη φαίνονται ποτέ ως κείμενο. Δεν τυπώνουν τίποτα όσο το plugin είναι ανενεργό, στη λειτουργία δοκιμής φαίνονται μόνο στους διαχειριστές, και έχουν την ίδια έξοδο για όλους τους επισκέπτες (κανόνας 1, page caches).
    - **Μικτά καλάθια με συνεργάτη παραγωγής** (νέο, 1.32, από τα Shipping Notes 1.1.0 και Store Pickup 1.1.1): όταν ένα plugin συνεργάτη (π.χ. Printful) χωρίζει το καλάθι σε δικό του πακέτο, το σήμα είναι αυτό που βάζει το ίδιο στο πακέτο (`$package['printful']`), όχι meta ή κώδικας του plugin· η κλάση αποστολής μένει ως δεύτερο σήμα για sites χωρίς τέτοιο plugin, και τα δύο με φίλτρο. Τα plugins της σουίτας διαβάζουν το ίδιο σήμα, με ξεχωριστό διακόπτη το καθένα.
    - Οι κανόνες ισχύουν **ανά δέμα**: το δέμα με τα δικά μας προϊόντα κρατά τις μεθόδους του. Η εξήγηση «γιατί λείπει» φαίνεται μόνο όταν κανένα δέμα δεν παίρνει τη μέθοδο· όταν το καλάθι έχει και τα δύο, φαίνεται μία μόνο σημείωση για τα δύο δέματα, για να μη βλέπει ο πελάτης δύο μηνύματα.
    - Ονόματα δεμάτων: το WooCommerce 11 τα ζητά (`woocommerce_shipping_package_name`) την ώρα που φτιάχνει τα πακέτα, πριν από το `WC()->shipping()->get_packages()`. Γι' αυτό τα πακέτα σημειώνονται στο `woocommerce_cart_shipping_packages` με μεγάλη προτεραιότητα (μετά από το plugin του συνεργάτη) και το όνομα διαβάζει μόνο τα σημάδια του πακέτου που παίρνει.
    - Ετικέτες τιμών που έρχονται από τον συνεργάτη (π.χ. «Flat Rate (Estimated delivery: …)») μεταφράζονται **μόνο στην εμφάνιση** (`woocommerce_cart_shipping_method_full_label`, `woocommerce_order_shipping_method` εκτός REST). Η παραγγελία κρατά την αρχική ετικέτα, γιατί ο συνεργάτης μπορεί να τη διαβάζει όταν εισάγει την παραγγελία.
    - Η λίστα δεμάτων στην παραγγελία (σελίδα ευχαριστίας, λογαριασμός, emails του πελάτη) βγαίνει από meta που γράφεται στη γραμμή αποστολής στο ταμείο. Είναι μέρος της παραγγελίας και μένει μετά το uninstall (§12).
22. **Απόκρυψη από τον κατάλογο** (νέο, από το Hide Catalog):
    - Την ώρα του query, ποτέ με εγγραφή σε προϊόντα (όχι `product_visibility`, όχι meta): η απενεργοποίηση επαναφέρει αμέσως τον κατάλογο.
    - Μέσα από τα φίλτρα του WooCommerce όπου υπάρχουν (`woocommerce_product_query_tax_query`, `woocommerce_shortcode_products_query`, `woocommerce_related_products` κ.λπ.), ώστε αποτελέσματα και μετρητές (widgets φίλτρων, Shop Filters) να συμφωνούν.
    - Οι όροι που κρύβονται από λίστες φεύγουν στο `get_terms_args` (όχι στο φίλτρο `get_terms`, που σπάει με `fields` = ids/count), ποτέ όταν το query ζητά συγκεκριμένους όρους (`include`) ή τους όρους ενός αντικειμένου (`object_ids`), ώστε η object term cache να μένει πλήρης, ούτε στην πλήρη ανάγνωση με `get` = all ή `fields` = id=>parent: από αυτή το WordPress γράφει την ιεραρχία (`{taxonomy}_children`) για όλο το site, και χωρίς τους κρυφούς όρους οι υποκατηγορίες τους χάνονται από το `get_term_children()` μέχρι την επόμενη αποθήκευση κατηγορίας (1.53).
    - Ό,τι μπαίνει σε query με δικό του cache key (π.χ. τα παλιά product grid blocks του WooCommerce) μπαίνει στα query vars πριν υπολογιστεί το key, ώστε η cache να μη δίνει τα ίδια αποτελέσματα σε επισκέπτη και διαχειριστή σε λειτουργία δοκιμής.
    - Η σελίδα της ίδιας της κρυφής κατηγορίας δεν κρύβει τα προϊόντα της· ανοίγει ή κλείνει μόνο με ρητή ρύθμιση (noindex, 404, ανακατεύθυνση), και οι κλειστές σελίδες φεύγουν από τα sitemaps.
23. **Στοιχεία που οδηγούν τη φόρμα του WooCommerce και παράγωγα δεδομένα στο παρασκήνιο** (νέο, από το Variation Picker):
    - Ένα δικό μας στοιχείο επιλογής (π.χ. swatches) **δεν αντικαθιστά** το `<select>` του WooCommerce: το select μένει στη σελίδα, κρύβεται μόνο όταν τρέξει το JavaScript (κλάση στο wrapper) και το στοιχείο το οδηγεί με `change`. Χωρίς JavaScript ή μετά από σφάλμα, ο επισκέπτης βλέπει το κανονικό dropdown και άλλα plugins που ακούν το select δουλεύουν όπως πριν.
    - Η διαθεσιμότητα διαβάζεται από τα events του `wc_variation_form` (`woocommerce_update_variation_values`, `found_variation`, `reset_data`), όχι από δικό μας AJAX. Για τιμές που το WooCommerce αφαιρεί από το select (μη συμβατές με την τρέχουσα επιλογή), το στοιχείο καθαρίζει πρώτα τις άλλες επιλογές.
    - Δεδομένα που παράγονται από τα προϊόντα (π.χ. χάρτης διαθεσιμότητας) αποθηκεύονται ως meta και ξαναφτιάχνονται στην αποθήκευση και στις αλλαγές αποθέματος: αμέσως σε admin, REST, cron και CLI, και με μία εργασία του Action Scheduler (δική μας ομάδα) όταν η αλλαγή έρχεται από αίτημα επισκέπτη (π.χ. παραγγελία). Η πρώτη κατασκευή για όλο το κατάστημα τρέχει στο παρασκήνιο σε ομάδες. Τα παράγωγα δεδομένα σβήνονται πάντα στο uninstall.
    - Ένα plugin που κάνει την ίδια δουλειά με άλλο γνωστό plugin (π.χ. άλλα swatches) μένει σε λειτουργία δοκιμής όσο εκείνο είναι ενεργό, με ειδοποίηση στις ρυθμίσεις, ώστε ο επισκέπτης να μη δει τίποτα διπλό. Η εισαγωγή από εκείνο αντιγράφει μόνο όπου δεν υπάρχει ήδη δική μας τιμή και δεν αλλάζει τα δεδομένα του.
24. **Μαζικές αλλαγές σε υπάρχον περιεχόμενο** (νέο, από το Greeklish Permalinks):
    - Τίποτα υπάρχον δεν αλλάζει μόνο του, ούτε με την ενεργοποίηση. Ένα plugin που αλλάζει μόνο ό,τι **νέο** φτιάχνεται (π.χ. νέα slugs) μπορεί να ξεκινά με την ενεργοποίηση, αν αυτός είναι ο σκοπός του (εξαίρεση στον κανόνα 1, γραμμένη στο docblock)· ό,τι αγγίζει αρχεία είναι επιλογή, κλειστή από προεπιλογή.
    - Οι αλλαγές στα υπάρχοντα γίνονται μόνο από σελίδα admin, με τη σειρά: σάρωση (μόνο ανάγνωση), επιλογή ανά γραμμή, δοκιμή χωρίς εγγραφές, εφαρμογή με ρητή επιβεβαίωση και υπενθύμιση για backup.
    - Εφαρμογή σε μικρές παρτίδες με AJAX (nonce και capability σε κάθε βήμα) και πρόοδο στην οθόνη, με το αντίγραφο του §12 (μαζικές αλλαγές δεδομένων). Όταν κάθε στοιχείο εξαρτάται από τα προηγούμενα (π.χ. ένα όνομα που μόλις πιάστηκε), το αντίγραφο γράφεται **ανά στοιχείο**, πριν από την αλλαγή του. Αν δεν γραφτεί, το στοιχείο μένει ως έχει, η παρτίδα σταματά και η σελίδα αναιρεί αμέσως όλη την εκτέλεση. Μια εγγραφή που άλλαξε στο μεταξύ δεν αναιρείται, και η οθόνη το λέει.
    - Παλιές διευθύνσεις δεν σπάνε: `_wp_old_slug` του WordPress όπου υπάρχει, αλλιώς δικός μας πίνακας με 301 μόνο σε αιτήσεις που το WordPress ήδη απαντά με 404 (`template_redirect`, μόνο ανάγνωση, κανόνες 4–6).
    - Ονόματα αρχείων: μαζί όλα τα μεγέθη και οι παραλλαγές (scaled, webp / avif), χωρίς να πατηθεί υπάρχον αρχείο, ενημέρωση των συνδέσμων σε κείμενα και αρχεία για download· ό,τι βρίσκεται σε meta τρίτων (page builders) δεν αλλάζει, η οθόνη το δείχνει πριν από την εφαρμογή.
25. **Δικός μας τύπος προϊόντος και γραμμές παραγγελίας που φτιάχνει το plugin** (νέο, από το Product Bundles):
    - Ένας νέος τύπος προϊόντος είναι κλάση `WC_Product` με το `WC_Product_Data_Store_CPT` (φίλτρα `woocommerce_product_class` και `woocommerce_data_stores`), όνομα με prefix (π.χ. `nox_bundle`) και φόρμα στη σελίδα προϊόντος μέσω `woocommerce_{type}_add_to_cart`, που την καλούν και τα blocks του WooCommerce (Add to Cart with Options). Μετά από αλλαγή τύπου στον όρο `product_type` ακυρώνεται η cache του προϊόντος (`WC_Cache_Helper::invalidate_cache_group( 'product_' . $id )`).
    - Ό,τι πουλάει το προϊόν μπαίνει στο καλάθι και στην παραγγελία ως **κανονικές γραμμές των πραγματικών προϊόντων**, ώστε απόθεμα, ΦΠΑ, δικαιούχοι του RS και επιστροφές να δουλεύουν όπως πάντα. Οι γραμμές έχουν κρυφό meta για τον κώδικα και αναγνώσιμο meta με ετικέτα, ώστε να διαβάζονται και χωρίς το plugin. Μένουν μαζί: η ποσότητα κλειδώνει και μια γραμμή που φεύγει παίρνει μαζί της όλη την ομάδα, και στο Store API.
    - Μια έκπτωση του plugin γράφεται όπως ένα κουπόνι: subtotal της γραμμής η κανονική τιμή, total το μερίδιό της. Στο κλασικό ταμείο το `discount_total` της παραγγελίας ανεβαίνει κατά τη διαφορά (`woocommerce_checkout_create_order`). Ο επανυπολογισμός στο admin (`woocommerce_order_before_calculate_totals`, κουπόνια) δεν σβήνει την έκπτωση. Κουπόνια σε αυτές τις γραμμές μόνο με ρύθμιση.
    - Τα hooks του καλαθιού και της παραγγελίας τρέχουν σε κάθε λειτουργία, και με το `{P}_DISABLE` και στο Store API, γιατί κρατούν σωστό ένα καλάθι ή μια παραγγελία που ήδη έχει τέτοιες γραμμές (εξαίρεση στους κανόνες 3–4, γραμμένη στο docblock). Η λειτουργία δοκιμής και το `{P}_DISABLE` σταματούν μόνο την πώληση.
    - Με την απενεργοποίηση το WooCommerce θα φόρτωνε το προϊόν ως απλό και θα το πουλούσε χωρίς τα περιεχόμενά του: το plugin το κάνει «Εξαντλημένο» (παράγωγο δεδομένο) και το ξαναφτιάχνει στην ενεργοποίηση.
    - Η μετατροπή υπαρχόντων προϊόντων στον νέο τύπο ακολουθεί τον κανόνα 24 (ένα ένα, αντίγραφο πριν από την αλλαγή, αναίρεση).
26. **Υπολογισμοί με κρυφούς συντελεστές** (νέο, από το Publishing Quote): όταν ο επισκέπτης βλέπει μόνο αποτελέσματα (π.χ. ποσό ανά υπηρεσία και σύνολο), οι συντελεστές δεν φτάνουν ποτέ στον browser.
    - Ο υπολογισμός γίνεται μόνο στον server. Η σελίδα έχει μόνο τα ονόματα των επιλογών· ο browser στέλνει τις επιλογές σε δικό μας REST route (εξαίρεση στον κανόνα 4, γραμμένη στο docblock) και παίρνει πίσω μόνο μορφοποιημένα κείμενα, ποτέ συντελεστές, τύπους ή ανάλυση. Το REST route δεν γράφει τίποτα και στέλνει `nocache_headers()`, ώστε η σελίδα να μένει σε cache.
    - Οι είσοδοι περνούν από whitelist με όρια· ό,τι λείπει ή είναι εκτός ορίων παίρνει την προεπιλογή ή γυρίζει μήνυμα για το πεδίο.
    - Κάθε γραμμή στρογγυλεύεται χωριστά σε βήμα της ρύθμισης και το σύνολο είναι το άθροισμα των γραμμών. Κρυφό κόστος (π.χ. σελιδοποίηση) μοιράζεται μέσα στις γραμμές και φαίνεται ως «Περιλαμβάνεται», ώστε το σύνολο να μη δείχνει ποτέ ποσό που δεν εξηγείται.
    - Χωρίς JavaScript η φόρμα στέλνεται κανονικά και η σελίδα με το αποτέλεσμα δεν μπαίνει σε cache (`DONOTCACHEPAGE`).
    - Η πλήρης ανάλυση φαίνεται μόνο στο admin (πάγκος δοκιμής, αίτημα).
    - Ένα αίτημα «Ενδιαφέρομαι» ακολουθεί τον κανόνα 15 (nonce για συνδεδεμένους, honeypot, όριο ανά IP ως hash, διπλά submit, PRG) και ξαναϋπολογίζει το ποσό στον server: ό,τι στέλνει ο browser ως αποτέλεσμα δεν διαβάζεται ποτέ. Αποθηκεύεται ως δικό μας post type χωρίς δημόσια σελίδα, με λήξη από ρύθμιση, privacy exporter / eraser, και μένει μετά το uninstall ως αρχείο της επιχείρησης (§12), εκτός αν ο χρήστης τσεκάρει τη διαγραφή του.
27. **Κατάσταση προϊόντος που λήγει σε ημερομηνία, ενέργειες στις ανοιχτές παραγγελίες και emails στους πελάτες** (νέο, 1.33, από το Pre-Orders):
    - Μια κατάσταση με ημερομηνία λήξης (π.χ. προπαραγγελία ως την κυκλοφορία) ελέγχεται **σε κάθε ανάγνωση** με την ημερομηνία του site (`wp_timezone()`), ώστε το κατάστημα να είναι σωστό και χωρίς cron. Μία εργασία του Action Scheduler (δική μας ομάδα) την ημέρα της λήξης σβήνει την κατάσταση και ξανασώζει το προϊόν με CRUD, ώστε page caches, transients του WooCommerce και feeds να δουν την αλλαγή. Είναι εγγραφή από cron, όχι από αίτημα επισκέπτη (κανόνας 5), και γράφεται στο docblock.
    - Ένα παράγωγο option με την τελευταία ενεργή ημερομηνία του καταστήματος (π.χ. `por_horizon`) επιτρέπει στο front end να μην προσθέτει τίποτα (hooks, CSS, JS) όταν τίποτα δεν είναι ενεργό. Ξαναγράφεται μόνο στις αποθηκεύσεις (κανόνας 10).
    - Η κατάσταση στο καλάθι και στην παραγγελία ακολουθεί τον κανόνα 21: στιγμιότυπο στη γραμμή με αναγνώσιμο meta και κρυφό meta για τον κώδικα, και σημαία στην παραγγελία για φίλτρα στη λίστα (HPOS και legacy, μόνο με `wc_get_orders()` και meta_query· στο legacy μέσω του `woocommerce_order_data_store_cpt_get_orders_query`).
    - Ενέργειες σε πολλές παραγγελίες (σημείωση, νέα ημερομηνία, email) γίνονται στο παρασκήνιο με τον Action Scheduler, σε παρτίδες (π.χ. 20 παραγγελίες) με αύξουσα σειρά id, όπου κάθε παρτίδα βάζει την επόμενη. Μόνο σε ανοιχτές παραγγελίες (`processing`, `on-hold`), και κάθε γραμμή σημειώνεται, ώστε μια επανάληψη να μη στέλνει δεύτερο email.
    - Emails στους πελάτες είναι κλάσεις `WC_Email` (φίλτρο `woocommerce_email_classes`), **κλειστές από προεπιλογή**, με templates στο `templates/emails/` του plugin που το θέμα μπορεί να αντικαταστήσει. Γράφονται στη γλώσσα των κειμένων του επισκέπτη (κανόνας 17), με τα msgids του plugin μέσα σε `with_lang()`.
    - Ένα plugin που κάνει την ίδια δουλειά με άλλο ενεργό plugin (π.χ. άλλες προπαραγγελίες) μένει σε λειτουργία δοκιμής, όπως στον κανόνα 23, και δεν εισάγει ούτε αλλάζει τα δεδομένα του.

28. **Λίστες επισκεπτών και πελατών** (νέο, 1.35, από το Wish Shelf):
    - Ο επισκέπτης χωρίς σύνδεση κρατά τη λίστα **μόνο στον browser του** (`localStorage`, κλειδί ανά site): κανένα cookie, καμία εγγραφή στον server, καμία session του WooCommerce. Η σελίδα της λίστας του είναι ένα placeholder που μπαίνει σε cache, και τα προϊόντα έρχονται μετά από endpoint μόνο ανάγνωσης με τα ids (§14.4).
    - Ο συνδεδεμένος πελάτης γράφει **μόνο τη δική του** λίστα (εξαίρεση στον κανόνα 5, γραμμένη στο docblock): POST με nonce, whitelist ενεργειών και ids (δημοσιευμένα προϊόντα, χωρίς κωδικό· παραλλαγή μόνο του δικού της γονικού), όριο στοιχείων ανά λίστα και όριο αιτημάτων ανά λεπτό. Μετά τη σύνδεση η λίστα του browser ενώνεται μία φορά με του λογαριασμού και σβήνεται από τον browser μόνο όταν ο server την πήρε.
    - Το HTML ενός κουμπιού που εξαρτάται από τον επισκέπτη (π.χ. «στη λίστα») είναι **ίδιο για όλους**, ώστε οι page caches να δουλεύουν· την κατάσταση τη βάζει το JavaScript. Οι σελίδες με δεδομένα του συνδεδεμένου πελάτη (η δική του λίστα) και οι κοινές λίστες δεν μπαίνουν ποτέ σε cache (`DONOTCACHEPAGE`, `nocache_headers()`).
    - Block themes τρέχουν και τα κλασικά hooks των καρτών και της σελίδας προϊόντος (compatibility layer του WooCommerce): ένα στοιχείο που μπαίνει και από hook και από `render_block` τυπώνεται **μία** φορά ανά κάρτα ή προϊόν, δίπλα στο block όταν το πρότυπο το έχει.
    - Κοινοποίηση χωρίς scripts κοινωνικών δικτύων: αντιγραφή συνδέσμου και `navigator.share`. Ο σύνδεσμος του επισκέπτη έχει τα ids· του πελάτη ένα τυχαίο κλειδί (20 χαρακτήρες, σύγκριση με `hash_equals`, γιατί το collation της βάσης δεν ξεχωρίζει κεφαλαία), που φτιάχνεται μόνο με δική του ενέργεια και αλλάζει ή σταματά όποτε θέλει. Το όνομα του πελάτη δεν φαίνεται, και οι κοινές σελίδες έχουν noindex.
    - Οι φόρμες της λίστας (αφαίρεση, στο καλάθι) δουλεύουν και χωρίς JavaScript, με PRG· η προσθήκη στο καλάθι περνά από το `woocommerce_add_to_cart_validation`, και τα μηνύματα του WooCommerce τυπώνονται πάνω από τη λίστα όταν το θέμα δεν τα έχει ήδη τυπώσει. Ό,τι αλλάζει μόνο στον browser (λίστα επισκέπτη) το μαθαίνει το JavaScript από μια παράμετρο της ανακατεύθυνσης, που μετά σβήνεται από τη διεύθυνση (`history.replaceState`).
    - Μετρήσεις για το κατάστημα (π.χ. «αγοράστηκαν από λίστα») μπαίνουν ως σημάδι στη session του WooCommerce που ήδη υπάρχει για το καλάθι, περνούν στη γραμμή της παραγγελίας ως κρυφό meta και μετρούν μία φορά στην πληρωμή.
    - **Λίστες με όνομα** (νέο, 1.38, από το Wish Shelf 1.1.0): κλειστές από προεπιλογή. Κάθε προϊόν είναι σε **μία** λίστα του πελάτη, οπότε το κουμπί μένει απλό (πατημένο όταν το προϊόν είναι σε οποιαδήποτε λίστα) και το unique key του πίνακα δεν αλλάζει. Η βασική λίστα δεν έχει γραμμή στον πίνακα των λιστών. Κάθε ενέργεια σε λίστα ελέγχει ότι είναι του πελάτη· ένα άγνωστο id πέφτει στη βασική λίστα. Η λίστα της σελίδας είναι παράμετρος GET μόνο ανάγνωσης, και κάθε λίστα έχει δικό της κλειδί κοινοποίησης. Όταν κλείσουν, ο πελάτης βλέπει όλα του τα προϊόντα σε μία λίστα και τίποτα δεν σβήνεται.
    - Αλλαγή σχήματος σε ενημέρωση χωρίς ενεργοποίηση: έλεγχος της έκδοσης του σχήματος στο `init` (option με autoload), `dbDelta` μία φορά με κλείδωμα transient, πριν από οποιοδήποτε query στις νέες στήλες.
    - **Ειδοποιήσεις με email** (νέο, 1.38, από το Wish Shelf 1.1.0):
        - Τρεις διακόπτες, όλοι απαραίτητοι: το email (κανόνας 27, κλειστό από προεπιλογή), λειτουργία «live» (καμία αποστολή από λειτουργία δοκιμής), και **opt-in του πελάτη**, ατσέκαρτο κουτάκι στη δική του σελίδα, που αποθηκεύεται σε user meta.
        - Κάθε email έχει σύνδεσμο διακοπής που δουλεύει χωρίς σύνδεση και χωρίς cookie: user id με HMAC (`hash_hmac` με `wp_salt()`, σύγκριση με `hash_equals`). Μετά το κλικ, ένα μήνυμα στη σελίδα της λίστας από παράμετρο της ανακατεύθυνσης.
        - Έλεγχος μία φορά την ημέρα με recurring εργασία του Action Scheduler, σε παρτίδες (π.χ. 50 πελάτες) με αύξουσα σειρά id, όπου κάθε παρτίδα βάζει την επόμενη. Ένα email ανά πελάτη με όλα τα νέα του. Η κατάσταση κάθε στοιχείου (τιμή της τελευταίας ειδοποίησης, «ήταν εξαντλημένο») γράφεται **μόνο αφού φύγει το email**, ώστε τίποτα να μη σταλεί δύο φορές και μια αποτυχία να ξαναδοκιμαστεί.
        - Η εργασία προγραμματίζεται ή σβήνεται μόνο σε αποθηκεύσεις και το πολύ μία φορά την ώρα από το admin (transient), όχι σε κάθε αίτημα.
    - **Εισαγωγή από άλλο plugin λίστας** (νέο, 1.38): διαβάζει μόνο τους πίνακές του, με το σχήμα όπως το γράφει ο δικός του κώδικας (η πηγή και η ημερομηνία γράφονται στο docblock). Μόνο συνδεδεμένοι πελάτες, χωρίς διπλά, χωρίς αλλαγή στα δεδομένα του, με την ημερομηνία προσθήκης από τοπική ώρα σε GMT. Η προεπιλεγμένη λίστα του πηγαίνει στη βασική λίστα· οι υπόλοιπες γίνονται λίστες με το ίδιο όνομα όταν είναι ανοιχτές οι λίστες με όνομα, και μια δεύτερη εισαγωγή ξαναχρησιμοποιεί τη λίστα με το ίδιο όνομα.
29. **Χρέωση από επιλογή του πελάτη, σελίδα ευχαριστίας και κουπόνια που φτιάχνει το plugin** (νέο, 1.37, από το Checkout Extras):
    - Μια επιλογή του πελάτη στο ταμείο που προσθέτει χρέωση (π.χ. συσκευασία δώρου) διαβάζεται στο `woocommerce_cart_calculate_fees` από το `$_POST` του checkout ή από το `post_data` του `update_order_review`, ποτέ από session ή cookie (κανόνας 5). Ένα κρυφό πεδίο-σήμα ξεχωρίζει «δεν τσεκαρίστηκε» από «η φόρμα δεν φορτώθηκε ακόμα».
    - Στο Checkout block η επιλογή μπαίνει ως additional checkout field (`woocommerce_register_additional_checkout_field`), μόνο από το WooCommerce 9.9, που δέχεται εξαρτημένα πεδία (`hidden` / `required` με JSON schema, π.χ. η αφιέρωση μόνο όταν είναι τσεκαρισμένο το δώρο). Ένα fee εκεί θα χρειαζόταν εγγραφή στο session, οπότε επιλογή **με χρέωση** δεν δηλώνεται στο block· η σελίδα ρυθμίσεων το λέει όταν η σελίδα ταμείου είναι block. Η τιμή του πεδίου αντιγράφεται και σε δικό μας meta στο `woocommerce_store_api_checkout_order_processed`, ώστε κλασικό ταμείο και block να διαβάζονται ίδια.
    - Η σελίδα ευχαριστίας αλλάζει μόνο μέσα από τα hooks του WooCommerce (`woocommerce_before_thankyou`, `woocommerce_thankyou`, `woocommerce_thankyou_order_received_text`), που τα καλεί και το Order confirmation των block themes. Τα κουτιά τυπώνονται μία φορά ανά σελίδα, με id ώστε το CSS να αντέχει κανόνες θέματος με id (κανόνας 13). Προϊόντα με τις κάρτες του θέματος (`wc_setup_loop`, `content-product`). Τίποτα δεν γράφεται σε προβολή της σελίδας (κανόνας 5).
    - Ένα κουπόνι για τον πελάτη δημιουργείται στο `woocommerce_order_status_{status}` (προτεραιότητα 5), που τρέχει πριν από τα emails της παραγγελίας (`{from}_to_{to}`), ώστε το email να το δείχνει. Μόνο σε καταστάσεις που επιλέγει ο διαχειριστής (προεπιλογή: πληρωμένες), μία φορά ανά παραγγελία, με WC CRUD (`WC_Coupon`), μίας χρήσης, και σημείωση στην παραγγελία. Η λειτουργία δοκιμής δημιουργεί κουπόνια μόνο για παραγγελίες λογαριασμών διαχειριστή.
    - Υπενθυμίσεις και καθαρισμοί τρέχουν με το Action Scheduler (§12) και ξαναελέγχουν το αντικείμενο με CRUD πριν από κάθε ενέργεια (π.χ. δεν στέλνεται υπενθύμιση για κουπόνι που χρησιμοποιήθηκε). Τα emails προς τον πελάτη είναι κλάσεις `WC_Email`, ώστε θέμα, επικεφαλίδα και απενεργοποίηση να ρυθμίζονται στο WooCommerce › Ρυθμίσεις › Emails.

30. **Marker classes σε shortcodes του WooCommerce** (νέο, 1.43, από το Home Sections):
    - Ένα plugin που αλλάζει **ποια** προϊόντα δείχνει ένα shortcode (π.χ. `[products]`) το κάνει μόνο μέσα από το φίλτρο του ίδιου του WooCommerce (`woocommerce_shortcode_products_query`), προσθέτοντας συνθήκες στα query args: `tax_query` / `meta_query` (ποτέ μέσα σε λίστα με `relation` OR χωρίς τύλιγμα), `post__not_in`, και δικό του query var για ό,τι χρειάζεται `posts_where` (π.χ. λέξη στον τίτλο, με `esc_like` και `prepare`).
    - Ενεργοποιείται μόνο από ένα class που γράφει ο χρήστης στο shortcode (`{πρόθεμα}-…`, π.χ. `hs-exclude-{ID}`). Shortcode χωρίς δικό μας class δίνει byte-identical έξοδο, και ένα class που δεν είναι marker μένει απλό CSS class. Γι' αυτό **δεν** χρειάζεται λειτουργία δοκιμής (κανόνας 2)· στη θέση της, η σελίδα ρυθμίσεων έχει δοκιμή shortcode (τρέχει το query του WooCommerce και δείχνει τα προϊόντα και πώς διαβάστηκε κάθε class, χωρίς να αλλάζει τίποτα). Η απόκλιση γράφεται στο docblock του bootstrap.
    - Οι markers ισχύουν όπου αποδίδεται το shortcode (και σε προεπισκόπηση του editor), ώστε η προεπισκόπηση να δείχνει ό,τι ο επισκέπτης.
    - Η cache του shortcode κλειδώνει στα τελικά query args, οπότε κάθε ενότητα κρατά τη δική της cache και μια αλλαγή ρύθμισης φαίνεται αμέσως· η page cache καθαρίζεται στην αποθήκευση.
    - **Προπαραγγελίες άλλων plugins:** ένα query δεν μπορεί να καλέσει τα PHP APIs τους (§6), οπότε η εξαίρεση γίνεται με τα meta τους (π.χ. PRENA `_simple_preorder`, Pre-Orders `_por_on`) μέσα στο query, με φίλτρο που προσθέτει ή αφαιρεί keys. Είναι απόκλιση από τον §1.1 και γράφεται στο docblock του bootstrap.
    - Δεύτερο πρόθεμα (alias) από τις ρυθμίσεις, ώστε σελίδες με classes που υπάρχουν ήδη να μη χρειάζονται αλλαγή.

## §15 Τρόπος εργασίας (Chris ↔ Claude)

- **Γλώσσα:** απαντήσεις στα ελληνικά. Κώδικας, σχόλια και changelog στα αγγλικά.
- **Σειρά εργασίας:** πρώτα σχεδιασμός μέχρι να είναι ξεκάθαρη όλη η ιδέα, μετά κώδικας.
- **Παράδοση:** patches OLD → NEW, και ολόκληρο αρχείο όταν ένα σετ φτάνει τα 10+ blocks. Νέα plugins παραδίδονται ως ολόκληρα αρχεία σε PR.
- **Ερωτήσεις και προτάσεις:** οι ερωτήσεις έρχονται όλες μαζί σε αριθμημένη λίστα. Οι προτάσεις εξέλιξης είναι αριθμημένες και εγκρίνονται ή απορρίπτονται μία-μία.
- **Διαφωνία ή ρίσκο:** λέγεται ευθέως, πριν προχωρήσει η δουλειά.
- **Αρχεία:** δεν μαντεύουμε περιεχόμενο αρχείων. Ό,τι χρειάζεται ζητείται ρητά.

## §16 Noxpress Core: hub και ενημερώσεις (νέο, 1.2)

Κοινός κώδικας για όλη τη σουίτα, χωρίς χωριστό plugin και χωρίς WordPress.org.

- **Πού ζει:** `includes/noxpress-core/` σε **κάθε** plugin, byte-identical. Πηγή είναι το αντίγραφο του RS· το `.github/scripts/sync-core.sh` το αντιγράφει στα υπόλοιπα. Το release workflow σταματά αν διαφέρουν.
- **Φόρτωση:** το bootstrap κάθε plugin κάνει `require_once` το `loader.php` αμέσως μετά τις σταθερές. Κάθε αντίγραφο δηλώνει την έκδοσή του· στο `plugins_loaded` (προτεραιότητα 1) φορτώνεται **μόνο το νεότερο**, μία φορά (μοτίβο Action Scheduler). Ορίζει `NOXPRESS_CORE` (έκδοση) και `NOXPRESS_CORE_PATH`.
- **Αλλαγή στο Core:** ανεβαίνει το κλειδί έκδοσης στο `loader.php`, συγχρονίζονται όλα τα αντίγραφα και βγαίνει νέα έκδοση σε **όλα** τα plugins.
- **Εξαίρεση: αλλαγή μόνο στον κατάλογο** (νέο plugin στη σουίτα). Το κλειδί έκδοσης ανεβαίνει και τα αντίγραφα συγχρονίζονται, αλλά τα άλλα plugins **δεν** βγάζουν νέα έκδοση γι' αυτό: το νέο plugin φέρνει το νεότερο Core στα sites όπου εγκαθίσταται, και τα υπόλοιπα το παίρνουν με την επόμενη έκδοσή τους. Μέχρι τότε, ένα site χωρίς το νέο plugin απλώς δεν το βλέπει στο hub.
- **Κλάσεις:** `Noxpress_Core` (κατάλογος σουίτας, μενού §8, γλώσσα με domain `noxpress`), `Noxpress_Updater`, `Noxpress_Hub`. CSS με prefix `nx-` (`assets/hub.css`).
- **Κατάλογος:** RS, SP, SF, TP, Shop Filters (από το Core 1.0.2), Product Formats (από το Core 1.0.3), Easy Withdrawal (από το Core 1.0.4, με την ίδια εξαίρεση: χωρίς νέες εκδόσεις στα άλλα plugins), Shipping Notes (από το Core 1.1.1, με την ίδια εξαίρεση), Book Preview (από το Core 1.1.2, με την ίδια εξαίρεση), Featured Creators (από το Core 1.1.3, με την ίδια εξαίρεση), Store Pickup (από το Core 1.1.4, με την ίδια εξαίρεση), Hide Catalog (από το Core 1.1.5, με την ίδια εξαίρεση), Variation Picker (από το Core 1.1.6, με την ίδια εξαίρεση), Greeklish Permalinks (από το Core 1.1.7, με την ίδια εξαίρεση), orOS Connect (από το Core 1.1.8, με την ίδια εξαίρεση), Product Bundles (από το Core 1.1.9, με την ίδια εξαίρεση), Publishing Quote (από το Core 1.1.10, με την ίδια εξαίρεση), Pre-Orders (από το Core 1.1.11, με την ίδια εξαίρεση), Wish Shelf (από το Core 1.1.12, με την ίδια εξαίρεση), Checkout Extras (από το Core 1.1.13, με την ίδια εξαίρεση), Data Migrator (από το Core 1.1.14, με την ίδια εξαίρεση), Home Sections (από το Core 1.1.15, με την ίδια εξαίρεση), Book Feed (από το Core 1.1.16, με την ίδια εξαίρεση), Site Bridge (από το Core 1.1.17, με την ίδια εξαίρεση, §21).
- **`unlisted`** (νέο, 1.54): γραμμή του καταλόγου με `'unlisted' => true` παίρνει ενημερώσεις κανονικά, αλλά το hub τη δείχνει μόνο όπου το plugin είναι ήδη εγκατεστημένο (χωρίς «Εγκατάσταση» σε άλλα sites). Για plugins που δεν έχουν ακόμα κάρτα στο site. Νέο plugin = νέα γραμμή στο `Noxpress_Core::catalog()` και στο `AUTO` του workflow.
- **Hub (σελίδα `noxpress`):**
  - Πίνακας με όλη τη σουίτα: κατάσταση (ενεργό / ανενεργό / δεν είναι εγκατεστημένο), εγκατεστημένη και διαθέσιμη έκδοση, badge beta.
  - Ενέργειες του ίδιου του WordPress: Ενημέρωση (`update.php?action=upgrade-plugin`), Εγκατάσταση (`install-plugin`, μέσω `plugins_api`), Ενεργοποίηση, Αλλαγές (popup λεπτομερειών), διακόπτης αυτόματων ενημερώσεων.
  - Προβολή με `manage_woocommerce` (ή `activate_plugins` χωρίς WC). Κάθε ενέργεια φαίνεται μόνο σε όποιον έχει το αντίστοιχο capability. Με `DISALLOW_FILE_MODS` δεν φαίνεται καμία, και η σελίδα το λέει. Στο multisite οι εγκαταστάσεις και οι ενημερώσεις γίνονται από το network admin.
  - Ρυθμίσεις: κανάλι Stable/Beta και «Έλεγχος τώρα», POST με PRG (§11), capability `update_plugins`.
- **Ενημερώσεις:**
  - Πηγή: `https://noxpress.tech/updates.json`, που γράφει το workflow. Για κάθε plugin: `stable` (νεότερο χωρίς pre-release) και `beta` (νεότερο οποιοδήποτε), με `version`, `zip`, `sha256`, `signature`, `requires_wp`, `requires_php`, `tested_wp`, `published`, `changelog`.
  - Κανάλι: site option `noxpress_channel`, προεπιλογή **Stable**. Στο Beta ισχύει το νεότερο από beta και stable. Υποβάθμιση δεν προτείνεται ποτέ.
  - **Άλλο κανάλι:** όταν το κανάλι που δεν ακολουθεί το site έχει νεότερη έκδοση (στην πράξη: νεότερη beta σε site με Stable), το hub δείχνει badge «Νεότερη beta: X», πλαίσιο με «Μετάβαση στο Beta για όλα» και προειδοποίηση για live sites, και στη γραμμή του plugin «Εγκατάσταση beta» (με confirm), «Αλλαγές beta» και «Απόκρυψη για αυτή την έκδοση». Στο μενού Noxpress μπαίνει μετρητής (μόνο για `update_plugins`, μόνο εγκατεστημένα plugins, μόνο από την cache, χωρίς αίτημα δικτύου).
  - «Εγκατάσταση beta» = το συνηθισμένο `update.php` / `install-plugin` του WordPress με `noxpress_pick=beta`: μόνο σε εκείνο το αίτημα και μόνο για εκείνο το plugin, το Core δίνει τη beta στο `update_plugins` (κατά την ανάγνωση, δεν αποθηκεύεται) και στο `plugins_api`. Το κανάλι δεν αλλάζει, το nonce και το capability τα ελέγχει το WordPress, το πακέτο ελέγχεται κανονικά (sha256 + υπογραφή). Υποβάθμιση δεν προτείνεται ποτέ.
  - Απόκρυψη: user meta `noxpress_hidden` (slug → έκδοση), POST με PRG (§11). Η ειδοποίηση ξαναβγαίνει μόνο με νεότερη έκδοση.
  - Το Core γεμίζει το `update_plugins` transient στο `pre_set_site_transient_update_plugins` (σβήνει και κάθε ξένη εγγραφή για τα slugs μας). Οι αυτόματες ενημερώσεις μένουν στον διακόπτη του WordPress, κλειστές από προεπιλογή.
  - Λεπτομέρειες plugin (`plugins_api`, για το popup «Αλλαγές» και την εγκατάσταση): το Core απαντά στην προτεραιότητα 10 και ξαναβάζει τα δικά του στοιχεία στο τέλος του `plugins_api` και του `plugins_api_result` (`PHP_INT_MAX`), γιατί άλλα plugins μπορεί να τα αντικαταστήσουν (π.χ. με το «Plugin not found» του WordPress.org).
  - Αιτήματα μόνο με `wp_safe_remote_get`, timeout 10 s, cache 12 ώρες (1 ώρα μετά από αποτυχία) στο site transient `noxpress_manifest`. Ποτέ σε αίτηση επισκέπτη.
- **Ασφάλεια πακέτων:**
  - Δεκτά μόνο zip από `https://github.com/koulaxizis/noxpress/releases/download/nox-<code>-<version>/<slug>.zip`, με version σε μορφή semver.
  - Στο `upgrader_pre_download` το Core κατεβάζει το πακέτο, ελέγχει το `sha256` και την υπογραφή Ed25519 του μηνύματος `noxpress|<slug>|<version>|<sha256>` με το `Noxpress_Updater::PUBLIC_KEY`. Αποτυχία = ακύρωση εγκατάστασης.
  - Το ιδιωτικό κλειδί υπάρχει **μόνο** στο secret `NOXPRESS_SIGNING_KEY` του repo. Το workflow ελέγχει ότι ταιριάζει με το δημόσιο κλειδί του κώδικα. Αλλαγή κλειδιού = νέα έκδοση του Core σε όλα τα plugins, πριν αλλάξει το secret.
- **Δεδομένα (§12):** `noxpress_channel`, `noxpress_checked` (site options), `noxpress_manifest` (site transient), `noxpress_hub_msg_{uid}` (transient), `noxpress_hidden` (user meta). Τα σβήνει το `uninstall.php` όποιου plugin φύγει **τελευταίο** από τη σουίτα. Γι' αυτό το `uninstall.php` κάθε plugin έχει τη λίστα με τα slugs **όλης** της σουίτας, και των plugins προσωπικής χρήσης (§21)· νέο plugin μπαίνει σε όλες τις λίστες (νέο, 1.34).
- **Πρώτη εγκατάσταση:** εκδόσεις πριν από το Core (RS ≤ 1.7.0, SP ≤ 1.3.0, SF ≤ 1.1.0, TP 1.0.0) δεν έχουν updater. Η πρώτη έκδοση με Core ανεβαίνει με το χέρι μία φορά.

## §17 Μετρητής λήψεων στο site (νέο, 1.3)

Η γραμμή «More than X downloads» / «Πάνω από X λήψεις» κάτω από το tagline του noxpress.tech. Χωρίς analytics και χωρίς κλήση του επισκέπτη σε τρίτο server.

- **Πηγή:** το δημόσιο `download_count` που κρατά το GitHub για κάθε asset των releases `nox-*`, εκτός από τα plugins προσωπικής χρήσης (§21· σήμερα κανένα). Μετράει κάθε λήψη του zip: από το site, από τη σελίδα Releases και από τον updater (§16). Λήψεις, όχι εγκαταστάσεις.
- **Workflow** `.github/workflows/downloads.yml` με το `.github/scripts/count-downloads.py`: κάθε μέρα στις 03:00 UTC, μετά από κάθε «Release plugin» και χειροκίνητα. Γράφει το `downloads.json` στη ρίζα του main.
- **`downloads.json`:** `display` (ό,τι δείχνει το site), `total`, `offset`, `updated` και καθολικό `assets` ανά id asset. Μια μέτρηση δεν μειώνεται ποτέ, ούτε όταν σβηστεί release.
- **Στρογγυλοποίηση:** το μεγαλύτερο σκαλοπάτι κάτω από το σύνολο: 1, 5, 10, 25, 50, 100, 250, 500, 1.000, 2.500… Με 0 η γραμμή κρύβεται. Αριθμοί με `toLocaleString` (EN `1,000`, EL `1.000`).
- **Commits:** μόνο όταν αλλάζει το `display` ή εμφανίζεται νέο asset.
- **Εμφάνιση (νέο, 1.50):** ίδια με τη γραμμή του nulljsfx.tech, ώστε τα δύο sites να μένουν όμοια: `<p class="download-count" id="download-count" hidden>`, `font-size: 13px`, `color: var(--muted)`, `margin: 10px 0 14px`, αριθμός σε `<strong>` με `var(--text)` και `font-weight: 600`. Μένει κάτω από το tagline, γιατί το noxpress.tech έχει ένα κουμπί λήψης ανά plugin και η γραμμή δείχνει το σύνολο της σουίτας. Το script μένει inline στο `index.html`, όπως όλο το site. Μια αλλαγή στην εμφάνιση γίνεται και στα δύο repos.
- **Έλεγχος scripts (νέο, 1.50):** ένα συντακτικό λάθος σε ένα string EN/EL (π.χ. `'` χωρίς `\'`) σταματά όλο το `<script>` της σελίδας, μαζί και τον μετρητή. Το workflow «Site cards» περνά κάθε inline script του `index.html` και του `supporters/index.html` από `node --check` (`.github/scripts/check-site-js.py`).
- **Δικές μας λήψεις:** κανένα workflow δεν κατεβάζει zip χωρίς λόγο. Το `build-manifest.py` κατεβάζει ένα zip μόνο όταν το `updates.json` δεν έχει ήδη την έκδοση με sha256 ίδιο με το `digest` του GitHub. Όσες έγιναν πριν από αυτόν τον κανόνα αφαιρούνται με το `OFFSET` του workflow.

## §18 Δωρεάν για πάντα, πρόσθετα και στήριξη (νέο, 1.18)

Απόφαση του Chris (2026-10-13, σχεδιασμός στο `notes/freemium/01-sxediasmos.md`). Η ίδια δέσμευση φαίνεται στο site, στην κάρτα «Free forever».

- **Ό,τι κυκλοφόρησε δωρεάν μένει δωρεάν** και MIT. Καμία λειτουργία ενός plugin που έχει κυκλοφορήσει δεν μετακινείται ποτέ σε πληρωμένη έκδοση.
- **Πάντα δωρεάν:** το Easy Withdrawal ολόκληρο (νομική υποχρέωση των καταστημάτων), οι διορθώσεις συμβατότητας και ασφαλείας, το Noxpress Core, ο updater και το hub (§16).
- **Μέσα στα δωρεάν plugins:** κανένα κλειδί άδειας, κανένας έλεγχος που κλειδώνει κώδικα, καμία διαφήμιση ή επίμονη ειδοποίηση. Το μόνο σημείο στήριξης είναι το footer (§7).
- **Πληρωμένα πρόσθετα (μελλοντικά):** μόνο **νέες** λειτουργίες, ως **ξεχωριστό plugin** που απαιτεί το δωρεάν (`Requires Plugins`), σε ιδιωτικό repo. Όνομα «<Plugin> Pro» (π.χ. «Revenue Splitter Pro»), ποτέ με πρόθεμα «Noxpress» (§10). Βγαίνει προς πώληση μόνο αφού το δωρεάν plugin που χρειάζεται έχει γίνει stable (§13). Πληρωμές μέσω πλατφόρμας merchant of record.
- **Πότε ανοίγει το θέμα:** όταν περάσουμε ~100 ενεργά sites εκτός των δικών μας, ή όταν 3+ πελάτες ζητήσουν το ίδιο πληρωμένο feature. Μέτρο μέχρι τότε: οι λήψεις κάθε νέας έκδοσης (§17), χωρίς telemetry.
- **Στήριξη σήμερα:** Ko-fi (μία φορά, μηνιαία μέλη στο `/tiers`, υπηρεσίες στο `/commissions`) και το `.github/FUNDING.yml` του repo. Το site έχει την ενότητα `#support` με τις κάρτες `#free-forever`, `#services` και `#supporters`, με EN/EL strings `ff_*`, `sv_*`, `ms_*`.

## §19 Σελίδα Supporters στο site (νέο, 1.19)

Η σελίδα `/supporters/` του noxpress.tech (`supporters/index.html`), EN/EL με το ίδιο `nx_lang` της αρχικής. Δείχνει τους μηνιαίους supporters του Ko-fi, κοινούς για NULL JSFX, orOS και noxpress.

- **Μία πηγή:** `https://nulljsfx.tech/supporters.json`, μορφή `{"updated":"YYYY-MM-DD","supporters":[{"name":"…","tier":"Patron|Backer"}]}`. Η λίστα **δεν αντιγράφεται** ποτέ στο repo ή στη σελίδα. Αλλαγές στη λίστα γίνονται μόνο στο repo `null-jsfx`.
- **Fetch από τον browser** (cross-origin, χωρίς cookies). Το nulljsfx.tech είναι GitHub Pages, που στέλνει `Access-Control-Allow-Origin: *`. Αν φύγει ποτέ από το GitHub Pages, ο νέος host πρέπει να στέλνει το ίδιο header για το `supporters.json`.
- **Αποτυχία** (δίκτυο, HTTP σφάλμα, χρόνος πάνω από 8 δευτερόλεπτα, λάθος μορφή): η σελίδα δείχνει σύνδεσμο προς `https://nulljsfx.tech/supporters/`. Χωρίς JS το ίδιο, με `<noscript>`.
- **Ασφάλεια:** τα ονόματα γράφονται μόνο με `textContent`, ποτέ με `innerHTML`. Κρατιούνται μόνο εγγραφές με `name` κείμενο (έως 80 χαρακτήρες) και `tier` ακριβώς `Patron` ή `Backer`· οτιδήποτε άλλο αγνοείται.
- **Σειρά:** πρώτα οι Patrons, μετά οι Backers, με τη σειρά του αρχείου μέσα σε κάθε tier. Τα ονόματα των tiers μένουν όπως στο Ko-fi και στα ελληνικά.
- **Άδεια λίστα:** «The list is brand new. Your name could be the first one here.» / «Η λίστα μόλις ξεκίνησε. Το όνομά σου μπορεί να είναι το πρώτο εδώ.»
- **Κάλεσμα:** ένα κουμπί «Become a monthly supporter» προς `https://ko-fi.com/koulaxizis/tiers` και η σημείωση ότι Backers και Patrons απαντούν στο μήνυμα καλωσορίσματος του Ko-fi με το όνομα που θέλουν. Ισχύει ο §18: τίποτα δεν κλειδώνει, κανένα nag, στήριξη μόνο μέσω Ko-fi.
- **Footer του site:** σε κάθε σελίδα, η γραμμή `Ko-fi · Supporters` (`ft_supporters`) κάτω από τη γραμμή του noxpress. Νέα σελίδα του site παίρνει το ίδιο footer.

## §20 Plugins με modules (νέο, 1.23)

Από το orOS Connect. Ισχύει για plugins που συνδέουν το site με ένα εξωτερικό σύστημα (π.χ. το orOS) με πολλές ανεξάρτητες λειτουργίες.

- **Ένα plugin ανά εξωτερικό σύστημα,** όχι ένα ανά λειτουργία: μία σελίδα ρυθμίσεων, μία σύνδεση, ένα κλειδί, μία θέση στο μενού. Οι λειτουργίες είναι **modules** μέσα στο plugin. Λειτουργίες που δεν αφορούν το σύστημα δεν μπαίνουν ποτέ εκεί, ούτε ως module.
- **Όλα τα modules κλειστά μετά την ενεργοποίηση.** Ο χρήστης τα ανοίγει ένα-ένα στην καρτέλα «Modules».
- **Κλειστό module = κανένας κώδικας.** Το bootstrap φορτώνει τη γλώσσα, τις ρυθμίσεις, το Core και τη διαχείριση· το αρχείο κάθε module γίνεται `require` μόνο όταν το module είναι ανοιχτό. Με όλα κλειστά, το frontend είναι byte-identical με το site χωρίς το plugin.
- **Εργασίες στο παρασκήνιο που έμειναν στην ουρά** όταν κλείσει ένα module τρέχουν κανονικά (το hook τους δηλώνεται πάντα) και απλώς σταματούν με εγγραφή στο log, ώστε να μη μείνει ποτέ ορφανή εργασία ή σφάλμα του Action Scheduler.
- **Modules που δεν υπάρχουν ακόμα** φαίνονται ως κάρτες «Σύντομα», χωρίς διακόπτη και χωρίς κώδικα.
- **Νέο module = minor έκδοση** του plugin (§13), με γραμμή στο changelog και στην κάρτα του site.
- **WooCommerce ανά module:** το plugin ακολουθεί την εξαίρεση «μόνο για WordPress» (§1.2) όταν κάποιο module δουλεύει χωρίς WooCommerce. Τα κομμάτια που χρειάζονται το WooCommerce φορτώνονται μόνο όταν υπάρχει. Capability: `manage_woocommerce` με WooCommerce, `manage_options` χωρίς αυτό.
- **Ρυθμίσεις:** ένα option `{p}_settings` με όλα τα modules (`mods`), κοινή λειτουργία δοκιμή / live / ανενεργό και το `{P}_DISABLE` που σταματά κάθε module.
- **Φόρμες επισκέπτη προς την εξωτερική υπηρεσία:** όταν η υπηρεσία δέχεται δημόσια φόρμα (π.χ. η εγγραφή του orOS Newsletter στο relay), η φόρμα στέλνει **κατευθείαν από τον browser** στη διεύθυνσή της, όπως το HTML που δίνει η ίδια η υπηρεσία: καμία εγγραφή στη βάση, κανένα αίτημα στον server του site, σωστή συμπεριφορά με page caches. Το site κρατά μόνο τη διεύθυνση, που ελέγχεται με αυστηρό pattern (μόνο `https`, όχι IP ή localhost).
- **Αποστολές από τον server** (π.χ. εγγραφή από το ταμείο): μόνο με `wp_safe_remote_post`, χωρίς ανακατευθύνσεις, timeout έως 15 s, ποτέ μέσα στο αίτημα του επισκέπτη· στην ουρά (§12) με λίγες προσπάθειες σε αυξανόμενα διαστήματα, σημείωση στην παραγγελία και log χωρίς προσωπικά δεδομένα (χωρίς emails).
- **Κλειδιά προς την υπηρεσία** δεν μπαίνουν ποτέ στην εξαγωγή ρυθμίσεων· η εισαγωγή κρατά το τρέχον κλειδί.
- **Δεδομένα που τραβά η υπηρεσία** (νέο, 1.34, από τις Προσφορές του orOS Connect): όταν η υπηρεσία δεν δέχεται αποστολές από τον server (π.χ. το orOS ζει στον browser), το site κρατά τα δεδομένα και η υπηρεσία τα τραβά από ένα δικό μας REST namespace (`{p}/v1`), μόνο όσο είναι ανοιχτή:
    - Αυθεντικοποίηση με ό,τι ήδη έχει η υπηρεσία: τα κλειδιά WooCommerce REST (το namespace δηλώνεται αίτημα του WooCommerce REST με το φίλτρο `woocommerce_rest_is_request_to_rest_api`, οπότε κλειδί «Ανάγνωση» μόνο διαβάζει και η εγγραφή θέλει «Ανάγνωση/Εγγραφή»), ή χωρίς WooCommerce ένα Application Password. Κανένα δικό μας κλειδί ή token. Κάθε route ελέγχει και το capability του plugin.
    - Η υπηρεσία μαρκάρει ό,τι πήρε (π.χ. κατάσταση «Στο orOS») με ρητό POST μετά την εισαγωγή, όχι το site τη στιγμή που τα δίνει, ώστε μια διακοπή να μη χάνει τίποτα. Τα δεδομένα μένουν στο plugin-πηγή (π.χ. Publishing Quote) και φαίνονται εκεί όσο η υπηρεσία δεν τα έχει πάρει.
    - Απαντήσεις χωρίς cache (`nocache`), ποσά σε ακέραια λεπτά, μηνύματα σφάλματος στα αγγλικά. Το `{P}_DISABLE` απαντά 403. Το admin δείχνει τη διεύθυνση, τι περιμένει και πότε τα πήρε τελευταία φορά η υπηρεσία (ένα option, χωρίς προσωπικά δεδομένα).

## §21 Απομακρυσμένη πρόσβαση και plugins προσωπικής χρήσης (νέο, 1.29)

Από το Site Bridge: ο Claude διαχειρίζεται WordPress / WooCommerce sites μέσω MCP, από τους servers του Chris, χωρίς υπηρεσία στη μέση.

- **Προσωπική χρήση:** ένα plugin για τα δικά μας sites δεν μπαίνει στον κατάλογο του Core (§16), στο `index.html` ή στον μετρητή λήψεων (§17). Βγαίνει κανονικά ως release (κωδικός στα `AUTO` και `PERSONAL` του workflow, §13) και ενημερώνεται με το χέρι. Έχει αντίγραφο του Core μόνο για το μενού (§8), και το `uninstall.php` του ακολουθεί τον κανόνα «τελευταίο plugin της σουίτας» (§16).
- **Από προσωπικό σε δημόσιο** (νέο, 1.54, από το Site Bridge 1.3.0). Ένα zip στα releases είναι ήδη δημόσιο, άρα το πρώτο βήμα είναι πάντα οι ενημερώσεις:
  1. Βγαίνει από το `PERSONAL`, μπαίνει στο `updates.json` και στον κατάλογο του Core ως `unlisted` (§16). Τα sites που το έχουν το ενημερώνουν με το χέρι μία φορά, μετά από το hub.
  2. Security review του κώδικα απομακρυσμένης πρόσβασης (OAuth, υπογραφές, SQL), με τις διορθώσεις σε δικό του PR.
  3. Κάρτα στο site (beta, με προειδοποίηση), βγαίνει το `unlisted` και μπαίνει στον μετρητή λήψεων (§17). Τα τρία βήματα μπορούν να γίνουν στο ίδιο PR, αρκεί το review να προηγείται της κάρτας.
- **Κείμενα για ξένα sites:** το Overview και το readme λένε ότι το plugin δίνει απομακρυσμένη πρόσβαση διαχειριστή και ότι «δουλεύει με το Claude», χωρίς σχέση με την Anthropic. Υποστήριξη μόνο από τα GitHub Issues, χωρίς εγγύηση. Η προεπιλογή για zip είναι μόνο τα releases του noxpress και το wordpress.org.
- **Ρόλοι:** ένα plugin, δύο ρόλοι. Το **Hub** δέχεται τη σύνδεση του Claude και προωθεί τις κλήσεις· το **Site** τις εκτελεί. Και οι δύο κλειστοί μετά την ενεργοποίηση. Με τον ρόλο Site, το Hub βρίσκεται στα δικά του sites χωρίς σύζευξη.
- **Σύνδεση του Claude:** OAuth 2.1 με PKCE (S256), Client ID Metadata Document (CIMD) και clients μόνο από λίστα προθεμάτων (προεπιλογή `https://claude.ai/`, `https://claude.com/`). Dynamic Client Registration κλειστό από προεπιλογή. Access token 1 ώρα, refresh 30 ημέρες με rotation και ανίχνευση επαναχρήσης (επαναχρήση μετά από 60 s τερματίζει τη σύνδεση), έγκριση 90 ημέρες. Αποθηκεύονται μόνο hashes των tokens. Σελίδα έγκρισης ανά scope (read / write / admin) και ανά site.
- **Hub → Site:** κάθε αίτημα και κάθε απάντηση υπογράφονται με Ed25519 (sodium), με timestamp (±300 s) και nonce μίας χρήσης. Το ιδιωτικό κλειδί σφραγίζεται με τα salts του `wp-config.php`. Σύζευξη με κωδικό μίας χρήσης, 15 λεπτών. Κανένα password ή application password δεν φεύγει από το site.
- **Το site αποφασίζει:** επίπεδο ανά site (κλειστό / ανάγνωση / εγγραφή / διαχείριση, προεπιλογή ανάγνωση), παράθυρο εγγραφής 15 / 30 / 60 λεπτών ή 3 / 5 ωρών που ανοίγει ο ιδιοκτήτης (το Hub μόνο αν το site το επιτρέπει, κλειστό από προεπιλογή), εργαλεία ως επιλεγμένος διαχειριστής ώστε να ισχύουν τα δικαιώματα και τα hooks του WordPress. Sites πελατών: μόνο ανάγνωση.
- **Ποτέ:** εγγραφή αρχείων, SQL εκτός από SELECT / SHOW / DESCRIBE / EXPLAIN / WITH (και ανάγνωση `option_value` / `meta_value` μόνο σε ένα απλό SELECT, με το όνομα ως απλή στήλη του ίδιου πίνακα, ώστε η μάσκα κατά όνομα να μην ξεγελιέται, νέο 1.54), ανάγνωση των schemas `mysql` / `performance_schema` / `sys`, αρχείων συμπίεσης και αντιγράφων (`.zip`, `.bak` κ.λπ.), αλλαγή χρηστών, κωδικών, ρυθμίσεων του core ή options του ίδιου του plugin, ανάγνωση `wp-config.php`, `.env`, κλειδιών, βάσεων, backups και `.git`. Εγκατάσταση plugin από zip μόνο από λίστα προθεμάτων URL, με URL χωρίς `.` / `..`, `%`, `\`, `@`, query ή fragment (αλλιώς ο HTTP client «βγαίνει» από το πρόθεμα, 1.54). Το plugin δεν απενεργοποιεί ποτέ τον εαυτό του.
- **Μυστικά και προσωπικά δεδομένα:** μυστικά (passwords, keys, tokens, salts) γυρίζουν πάντα ως `***`. Μάσκα σε email, τηλέφωνα, διευθύνσεις, ΤΚ και IP, ανοιχτή από προεπιλογή. Στις τιμές meta / options η τιμή κρίνεται από το κλειδί της, οπότε χωρίς το κλειδί (ή με alias / συνάρτηση) κρύβεται.
- **Ιστορικό:** κάθε κλήση γράφεται στο Hub και στο site (εργαλείο, ορίσματα χωρίς μυστικά, αποτέλεσμα, χρόνος, μέγεθος), με φίλτρα και εξαγωγή CSV (§5). Email στον διαχειριστή μετά από κάθε κλήση διαχείρισης.
- **Όρια και φρένα:** όριο κλήσεων ανά σύνδεση (120 / λεπτό, 10.000 / ημέρα), «Ανάκληση όλων», και η σταθερά `{P}_DISABLE` που κλείνει κάθε endpoint.
- **Μηνύματα προς τον Claude** (αποτελέσματα και άρνηση εργαλείων) στα αγγλικά, με το τι μπορεί να αλλάξει ο ιδιοκτήτης· το admin ακολουθεί τον §9.
- **Uninstall:** σβήνει τα πάντα (ρυθμίσεις, κλειδιά, συζεύξεις, συνδέσεις, ιστορικό): καμία σύνδεση δεν ζει περισσότερο από το plugin.
