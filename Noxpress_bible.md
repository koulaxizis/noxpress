# Noxpress Bible

Ενιαίο πρότυπο σχεδιασμού και κώδικα για τα plugins της σουίτας Noxpress (noxpress.tech).

- **Έκδοση:** 1.5 (2026-10-10). Νέο: οι σελίδες admin των plugins πιάνουν όλο το πλάτος (§3).
- **Προέλευση:** η v1.0 δεν υπήρχε στο repo. Η v1.1 ανασυντέθηκε από τον κώδικα των Revenue Splitter 1.7.0, Store Pulse 1.3.0 και Smart Formatter 1.1.0, από τις αναφορές «Bible §N» μέσα σε αυτόν, και από τις συμβάσεις του PR #1. Η αρίθμηση §2–§10 κρατά τη σημασία που ήδη έχει στον κώδικα.
- **Πρότυπη υλοποίηση:** Revenue Splitter. Το `admin.css` του είναι το «leading design».
- Όταν ένα plugin αποκλίνει από το Bible, η απόκλιση γράφεται στο docblock του bootstrap με αιτιολόγηση.

---

## §1 Αρχές

1. **Αυτονομία.** Κάθε plugin δουλεύει μόνο του. Η συνεργασία με τα άλλα γίνεται **μόνο** μέσω των δημόσιων hooks και APIs του §6, ποτέ με απευθείας ανάγνωση των options ή των πινάκων άλλου plugin.
2. **WooCommerce ως προϋπόθεση.** Χωρίς ενεργό WooCommerce το plugin φορτώνει μόνο τη γλώσσα (§9), το Noxpress Core (§16, ώστε το hub και οι ενημερώσεις να δουλεύουν πάντα) και ένα admin notice (σε χρήστες με `activate_plugins`). Κανένας κώδικας που εξαρτάται από το WC δεν φορτώνεται.
3. **HPOS.** Κάθε plugin δηλώνει συμβατότητα `custom_order_tables` στο `before_woocommerce_init`. Παραγγελίες διαβάζονται μόνο μέσω CRUD ή `wc_get_orders`.
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

## §6 Widget και συνεργασία

- **Dashboard widget** (όπου υπάρχει): `wp_add_dashboard_widget` με capability `manage_woocommerce` και compound scope στο CSS.
- **Συμβόλαιο συνεργασίας** (hooks που ακούνε ή πυροδοτούν τα plugins):
  - `rs_invalidate_cache`: ο RS το πυροδοτεί όταν αλλάζουν δεδομένα RS ή παραγγελίες. Το SP το ακούει.
  - `noxpress_products_changed( int[] $product_ids )`: το πυροδοτεί όποιο plugin αλλάζει δεδομένα προϊόντων (SF μετά από apply/restore). Το SP το ακούει.
  - Δημόσια APIs του RS που διαβάζει το SP: `RS_Reports::run`, `RS_Beneficiaries::collect_names`, `RS_Ledger::sum`.
- Plugin που **δεν** αλλάζει δεδομένα (π.χ. Theme Patcher, που αλλάζει μόνο την εμφάνιση) δεν πυροδοτεί τίποτα.

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
  - Core 5, RS 9, SP 20, SF 30, TP 40.
  - Νέα plugins παίρνουν το επόμενο +10.
  - Το Data Migrator δεν έχει ακόμα ενταχθεί.
- **Παλιές εκδόσεις** (πριν από το Core) έλεγχαν `empty( $GLOBALS['admin_page_hooks']['noxpress'] )` και έφτιαχναν το top-level μόνο αν έλειπε. Επειδή το Core τρέχει πρώτο, σε ανάμεικτους συνδυασμούς απλώς προσθέτουν τα submenus τους.
- **Υποσελίδες:** κάθε plugin έχει **πάντα** τα δικά του slugs (`{p}-…`), ώστε τα URLs να δουλεύουν σε κάθε συνδυασμό plugins. Η σελίδα ρυθμίσεων λέγεται «{Συντομογραφία} Ρυθμίσεις».
- **Assets:** φορτώνονται μόνο όταν το `$_GET['page']` είναι δικό μας slug. Τη σελίδα `noxpress` τη στιλάρει μόνο το Core.

## §9 Γλώσσα

- **Πηγή:** τα msgids είναι **ελληνικά** και αποτελούν τη μοναδική πηγή αλήθειας. Τα αγγλικά βρίσκονται σε dict μέσα στην κλάση `{P}_Lang` και εφαρμόζονται με φίλτρο `gettext_{text-domain}`.
- **Επιλογή:**
  - Η επιλογή γλώσσας είναι **μία για όλη τη σουίτα**: user meta `rs_lang` (`el` | `en` | `''`).
  - **Μόνο ο RS** το γράφει. Τα υπόλοιπα plugins μόνο το διαβάζουν.
  - Χωρίς ρητή επιλογή ή χωρίς RS ισχύει το locale του χρήστη: `el*` → Ελληνικά, οτιδήποτε άλλο → English.
- **Πότε φορτώνεται:** η κλάση Lang φορτώνεται **πάντα**, ακόμα και χωρίς WC, ώστε να μεταφράζεται και το notice.
- **Κλειδιά του dict:** byte-identical με τα msgids του κώδικα. Δεν επιτρέπονται λατινικά γράμματα μέσα σε ελληνικές λέξεις ούτε διπλότυπα. Ελέγχεται με script πριν από κάθε release.
- **Δυναμικά labels:** τα labels από registries αποθηκεύονται ως msgids και μεταφράζονται στο render.

## §10 Σταθερές, header, αρχεία

- **Σταθερές:** `{P}_VERSION`, `{P}_FILE`, `{P}_PATH`, και προαιρετικά `{P}_URL`.
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

## §12 Δεδομένα και uninstall

- **Ονόματα:** τα options έχουν πάντα το prefix του plugin. Μεγάλα blobs αποθηκεύονται ως JSON με `autoload = false`, εκτός αν διαβάζονται σε κάθε request.
- **`uninstall.php`:**
  - Τρέχει έξω από το plugin, οπότε δεν καλεί καμία κλάση του. Χρησιμοποιεί μόνο core APIs και `$wpdb`.
  - Διαγράφει μια hardcoded λίστα options και τα transients με `LIKE` που έχει escaped `_`.
  - Στο multisite καθαρίζει κάθε site **μία** φορά.
  - Δεν αγγίζει δεδομένα άλλων plugins. Ειδικά το `rs_lang` το διαγράφει μόνο ο RS, και μόνο αν δεν υπάρχει άλλο plugin της σουίτας.
- **Παραγωγικά δεδομένα** (π.χ. κείμενα προϊόντων που άλλαξε το SF) **δεν** επαναφέρονται στο uninstall. Το readme το λέει ρητά.

## §13 Εκδόσεις, release, site

- **Αρίθμηση:**
  - Semver, με πρώτη έκδοση 1.0.0.
  - Τις εκδόσεις τις προτείνει ο Claude και τις αποφασίζει ο Chris.
  - Μικρή αλλαγή ανεβαίνει κατά patch. Πέρασμα με fix και feature ανεβαίνει κατά minor.
- **Τρία σημεία σε κάθε έκδοση:** η έκδοση αλλάζει μαζί στο header, στη σταθερά `{P}_VERSION` και στο `Stable tag` του readme.
- **Stable:** όταν ο Chris πει «stable», βγαίνει το τικ «pre-release» από το release στο GitHub. Το workflow ξαναφτιάχνει τότε το `updates.json` (§16) και τα sites στο κανάλι Stable βλέπουν την έκδοση.
- **Release workflow** (`.github/workflows/release.yml`):
  - Κωδικός ανά plugin: rs, sp, sf, nm, tp.
  - Tag `nox-<code>-<version>`, zip `<slug>.zip` με τον φάκελο στη ρίζα.
  - Βγαίνει ως pre-release (beta) μέχρι να δοκιμαστεί.
  - Τα plugins στη λίστα `AUTO` βγαίνουν αυτόματα με το merge στο main.
  - Πριν από το build ελέγχει ότι το secret `NOXPRESS_SIGNING_KEY` υπάρχει και ότι τα αντίγραφα του Core είναι ίδια.
  - Μετά, το job `manifest` γράφει το `updates.json` στη ρίζα του main (§16).
- **Site** (`index.html`):
  - Μία κάρτα ανά plugin, με EN/EL strings `data-i18n` (`{p}_desc`, `{p}_li*`, `{p}_download`).
  - Το link download δείχνει στο asset του release.
  - Badge beta μέχρι να γίνει stable.
  - Κάτω από το tagline, η γραμμή «More than X downloads» (§17).
  - Footer σε δύο γραμμές: πρώτα η γραμμή του brand (`footer`), από κάτω τα credits (`credits`, EN/EL): «Designed with ♥ by Christos Koulaxizis. Assisted by Lumo. Audited by Claude.», με links σε koulaxizis.gr, lumo.proton.me και claude.ai. Η καρδιά είναι `♥` στο `--accent-bright`.
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
5. **Καμία εγγραφή στη βάση από αίτηση επισκέπτη.** Διαγνωστικά μόνο σε αιτήσεις admin με nonce.
6. **Απομόνωση σφαλμάτων.** Κάθε callback τυλίγεται σε `try/catch( \Throwable )`. Σε σφάλμα το plugin σταματά για το υπόλοιπο request και επιστρέφει το αρχικό output.
7. **Output buffering** μόνο στο ελάχιστο απαραίτητο εύρος. Μέσα σε handler δεν καλείται καμία συνάρτηση `ob_*`. Ένα buffer κλείνει μόνο αν είναι το ανώτερο και είναι δικό μας.
8. **Κανένα αρχείο θέματος ή άλλου plugin** δεν αλλάζει ποτέ. Η απενεργοποίηση επαναφέρει αμέσως το site.
9. **CSS και JS** φορτώνονται μόνο όταν η σελίδα χρειάζεται πραγματικά το αποτέλεσμα. Το CSS τυπώνεται μία φορά στο `<head>`, scoped με prefix.
10. **Υπολογισμοί με queries** (π.χ. ποια εικόνα δείχνει μια κατηγορία) γίνονται στο admin ή σε hooks αποθήκευσης (`save_post_*`, `edited_*`) και αποθηκεύονται. Η προβολή σελίδας μόνο διαβάζει.
11. **Αλλαγές σε όλο το HTML της σελίδας** γίνονται μόνο όταν υπάρχει ρύθμιση που τις χρειάζεται: ένα wrapper template (`template_include`) τρέχει το πρότυπο του θέματος μέσα σε buffer με pure-string handler.
12. **Με ενεργή την προστασία ρυθμίσεων, ο κώδικας του θέματος δεν γράφει στη βάση από το front end:** η προστασία ακυρώνει την εγγραφή μέσω `pre_update_option_*` χωρίς να αλλάζει την αποθηκευμένη τιμή. Ο Customizer, το admin, το AJAX και το REST δεν μπλοκάρονται ποτέ.

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
- **Κλάσεις:** `Noxpress_Core` (κατάλογος σουίτας, μενού §8, γλώσσα με domain `noxpress`), `Noxpress_Updater`, `Noxpress_Hub`. CSS με prefix `nx-` (`assets/hub.css`).
- **Κατάλογος:** RS, SP, SF, TP. Το Data Migrator μπαίνει όταν διορθωθεί. Νέο plugin = νέα γραμμή στο `Noxpress_Core::catalog()` και στο `AUTO` του workflow.
- **Hub (σελίδα `noxpress`):**
  - Πίνακας με όλη τη σουίτα: κατάσταση (ενεργό / ανενεργό / δεν είναι εγκατεστημένο), εγκατεστημένη και διαθέσιμη έκδοση, badge beta.
  - Ενέργειες του ίδιου του WordPress: Ενημέρωση (`update.php?action=upgrade-plugin`), Εγκατάσταση (`install-plugin`, μέσω `plugins_api`), Ενεργοποίηση, Αλλαγές (popup λεπτομερειών), διακόπτης αυτόματων ενημερώσεων.
  - Προβολή με `manage_woocommerce` (ή `activate_plugins` χωρίς WC). Κάθε ενέργεια φαίνεται μόνο σε όποιον έχει το αντίστοιχο capability. Με `DISALLOW_FILE_MODS` δεν φαίνεται καμία, και η σελίδα το λέει. Στο multisite οι εγκαταστάσεις και οι ενημερώσεις γίνονται από το network admin.
  - Ρυθμίσεις: κανάλι Stable/Beta και «Έλεγχος τώρα», POST με PRG (§11), capability `update_plugins`.
- **Ενημερώσεις:**
  - Πηγή: `https://noxpress.tech/updates.json`, που γράφει το workflow. Για κάθε plugin: `stable` (νεότερο χωρίς pre-release) και `beta` (νεότερο οποιοδήποτε), με `version`, `zip`, `sha256`, `signature`, `requires_wp`, `requires_php`, `tested_wp`, `published`, `changelog`.
  - Κανάλι: site option `noxpress_channel`, προεπιλογή **Stable**. Στο Beta ισχύει το νεότερο από beta και stable. Υποβάθμιση δεν προτείνεται ποτέ.
  - Το Core γεμίζει το `update_plugins` transient στο `pre_set_site_transient_update_plugins` (σβήνει και κάθε ξένη εγγραφή για τα slugs μας). Οι αυτόματες ενημερώσεις μένουν στον διακόπτη του WordPress, κλειστές από προεπιλογή.
  - Αιτήματα μόνο με `wp_safe_remote_get`, timeout 10 s, cache 12 ώρες (1 ώρα μετά από αποτυχία) στο site transient `noxpress_manifest`. Ποτέ σε αίτηση επισκέπτη.
- **Ασφάλεια πακέτων:**
  - Δεκτά μόνο zip από `https://github.com/koulaxizis/noxpress/releases/download/nox-<code>-<version>/<slug>.zip`, με version σε μορφή semver.
  - Στο `upgrader_pre_download` το Core κατεβάζει το πακέτο, ελέγχει το `sha256` και την υπογραφή Ed25519 του μηνύματος `noxpress|<slug>|<version>|<sha256>` με το `Noxpress_Updater::PUBLIC_KEY`. Αποτυχία = ακύρωση εγκατάστασης.
  - Το ιδιωτικό κλειδί υπάρχει **μόνο** στο secret `NOXPRESS_SIGNING_KEY` του repo. Το workflow ελέγχει ότι ταιριάζει με το δημόσιο κλειδί του κώδικα. Αλλαγή κλειδιού = νέα έκδοση του Core σε όλα τα plugins, πριν αλλάξει το secret.
- **Δεδομένα (§12):** `noxpress_channel`, `noxpress_checked` (site options), `noxpress_manifest` (site transient), `noxpress_hub_msg_{uid}` (transient). Τα σβήνει το `uninstall.php` όποιου plugin φύγει **τελευταίο** από τη σουίτα.
- **Πρώτη εγκατάσταση:** εκδόσεις πριν από το Core (RS ≤ 1.7.0, SP ≤ 1.3.0, SF ≤ 1.1.0, TP 1.0.0) δεν έχουν updater. Η πρώτη έκδοση με Core ανεβαίνει με το χέρι μία φορά.

## §17 Μετρητής λήψεων στο site (νέο, 1.3)

Η γραμμή «More than X downloads» / «Πάνω από X λήψεις» κάτω από το tagline του noxpress.tech. Χωρίς analytics και χωρίς κλήση του επισκέπτη σε τρίτο server.

- **Πηγή:** το δημόσιο `download_count` που κρατά το GitHub για κάθε asset των releases `nox-*`. Μετράει κάθε λήψη του zip: από το site, από τη σελίδα Releases και από τον updater (§16). Λήψεις, όχι εγκαταστάσεις.
- **Workflow** `.github/workflows/downloads.yml` με το `.github/scripts/count-downloads.py`: κάθε μέρα στις 03:00 UTC, μετά από κάθε «Release plugin» και χειροκίνητα. Γράφει το `downloads.json` στη ρίζα του main.
- **`downloads.json`:** `display` (ό,τι δείχνει το site), `total`, `offset`, `updated` και καθολικό `assets` ανά id asset. Μια μέτρηση δεν μειώνεται ποτέ, ούτε όταν σβηστεί release.
- **Στρογγυλοποίηση:** το μεγαλύτερο σκαλοπάτι κάτω από το σύνολο: 1, 5, 10, 25, 50, 100, 250, 500, 1.000, 2.500… Με 0 η γραμμή κρύβεται. Αριθμοί με `toLocaleString` (EN `1,000`, EL `1.000`).
- **Commits:** μόνο όταν αλλάζει το `display` ή εμφανίζεται νέο asset.
- **Δικές μας λήψεις:** κανένα workflow δεν κατεβάζει zip χωρίς λόγο. Το `build-manifest.py` κατεβάζει ένα zip μόνο όταν το `updates.json` δεν έχει ήδη την έκδοση με sha256 ίδιο με το `digest` του GitHub. Όσες έγιναν πριν από αυτόν τον κανόνα αφαιρούνται με το `OFFSET` του workflow.
