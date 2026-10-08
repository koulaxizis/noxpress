# Noxpress Bible

Ενιαίο πρότυπο σχεδιασμού και κώδικα για τα plugins της σουίτας Noxpress (noxpress.tech).

- **Έκδοση:** 1.1 (2026-10-08).
- **Προέλευση:** η v1.0 δεν υπήρχε στο repo. Η v1.1 ανασυντέθηκε από τον κώδικα των Revenue Splitter 1.7.0, Store Pulse 1.3.0 και Smart Formatter 1.1.0, από τις αναφορές «Bible §N» μέσα σε αυτόν, και από τις συμβάσεις του PR #1. Η αρίθμηση §2–§10 κρατά τη σημασία που ήδη έχει στον κώδικα.
- **Πρότυπη υλοποίηση:** Revenue Splitter. Το `admin.css` του είναι το «leading design».
- Όταν ένα plugin αποκλίνει από το Bible, η απόκλιση γράφεται στο docblock του bootstrap με αιτιολόγηση.

---

## §1 Αρχές

1. **Αυτονομία.** Κάθε plugin δουλεύει μόνο του. Η συνεργασία με τα άλλα γίνεται **μόνο** μέσω των δημόσιων hooks και APIs του §6, ποτέ με απευθείας ανάγνωση των options ή των πινάκων άλλου plugin.
2. **WooCommerce ως προϋπόθεση.** Χωρίς ενεργό WooCommerce το plugin φορτώνει μόνο τη γλώσσα (§9) και ένα admin notice (σε χρήστες με `activate_plugins`). Κανένας κώδικας που εξαρτάται από το WC δεν φορτώνεται.
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

- Κάθε σελίδα admin είναι `<div class="wrap {p}-wrap">` με `color-scheme: dark`, φόντο bg, περίγραμμα `#2b2e36`, `border-radius: 8px`, `padding: 32px 36px 28px`, `max-width: 1180px` και σκιά `0 1px 3px rgba(0,0,0,.35)`.
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
- **Προτεραιότητες `admin_menu`:**
  - RS 9, SP 20, SF 30, TP 40.
  - Νέα plugins παίρνουν το επόμενο +10.
  - Το Data Migrator δεν έχει ακόμα ενταχθεί.
- **Όποιο τρέξει πρώτο** (`empty( $GLOBALS['admin_page_hooks']['noxpress'] )`) δημιουργεί το top-level με τη δική του κύρια σελίδα ως landing, κρατά `owns_top = true` και αφαιρεί το διπλότυπο πρώτο submenu (`remove_submenu_page( 'noxpress', 'noxpress' )`).
- **Υποσελίδες:** κάθε plugin έχει **πάντα** τα δικά του slugs (`{p}-…`), ώστε τα URLs να δουλεύουν σε κάθε συνδυασμό plugins. Η σελίδα ρυθμίσεων λέγεται «{Συντομογραφία} Ρυθμίσεις».
- **Assets:** φορτώνονται μόνο όταν το `$_GET['page']` είναι δικό μας slug (ή `noxpress` όταν `owns_top`).

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
- **Release workflow** (`.github/workflows/release.yml`):
  - Κωδικός ανά plugin: rs, sp, sf, nm, tp.
  - Tag `nox-<code>-<version>`, zip `<slug>.zip` με τον φάκελο στη ρίζα.
  - Βγαίνει ως pre-release (beta) μέχρι να δοκιμαστεί.
  - Τα plugins στη λίστα `AUTO` βγαίνουν αυτόματα με το merge στο main.
- **Site** (`index.html`):
  - Μία κάρτα ανά plugin, με EN/EL strings `data-i18n` (`{p}_desc`, `{p}_li*`, `{p}_download`).
  - Το link download δείχνει στο asset του release.
  - Badge beta μέχρι να γίνει stable.
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
