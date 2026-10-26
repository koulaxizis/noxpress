=== Greeklish Permalinks ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: greeklish, permalinks, slugs, greek, transliteration
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Greek to Latin in new permalinks and, if you want, in uploaded file names, with on-demand bulk conversion of existing ones, redirects and undo.

== Description ==

**New slugs in Latin**

* New slugs of posts, pages, products, categories, tags, product attributes and users are written in Latin when they are created: "Τα χέρια μου" becomes `ta-cheria-mou`.
* Existing slugs never change on their own. Old Greek addresses keep opening, Greek term slugs included.
* Two styles: simple (letter by letter) or phonetic (αυ / ευ → av / ev or af / ef, ου → ou, μπ / ντ / γκ → b / d / g at the start of a word).
* Monotonic and polytonic Greek, final sigma, diaeresis and combining accents.
* Optional removal of short words (και, το, της …) and your own whole-word rules (`Γλαρόλυκοι = glarolykoi`).
* Live test: type any text and see the slug and the file name with the settings on screen, before saving.

**File names (optional, off by default)**

* When enabled, uploaded files and images get Latin names (`Καλοκαίρι.jpg` → `kalokairi.jpg`), with the extension kept and an option for lower case.

**Bulk conversion on demand**

* A scan lists every existing Greek slug and file name with its new form: posts, pages, products and any public post type, categories, tags, product categories and attributes, and media files.
* Pick rows one by one or all of them, try a dry run that writes nothing, then apply with an explicit confirmation.
* Runs in small batches with a progress bar; a name already in use gets a `-2` suffix and no file is ever overwritten.
* Files are renamed with all their sizes, the scaled original and webp / avif copies. Links in post content, excerpts and downloadable product files are updated. Links found in other plugins' data (page builders) are listed before applying and left alone.
* Old addresses answer with a 301: posts through WordPress's own old-slug redirect, pages, terms and files through the plugin's redirect table. A server check on the bulk page tells whether missing files reach WordPress.
* The last run can be undone; anything edited after the conversion is left as it is and reported.
* Every change is written to the undo log before it happens. If that write fails, the run stops and everything it changed goes back. "Download backup" saves the last run as JSON, and Tools → Import loads it back for undo.

**Redirects for Greek links**

* A Greek address that leads to a 404 is converted the way new slugs are; when a post or a term lives at the Latin address, the visitor gets a 301 there.

**Coming from AutoConvert Greeklish Permalinks**

* On first activation the plugin keeps that plugin's style (diphthongs on → phonetic, off → simple). It never changes that plugin's settings.
* While that plugin is active it converts first, so a notice asks you to deactivate it.

**Safe by design**

* Works with or without WooCommerce.
* `define( 'GLP_DISABLE', true );` in wp-config.php stops the automatic conversions and the redirects.
* On the front end it only acts on requests WordPress already answers with a 404 (and on Greek term addresses), and only reads. No cookies, no external requests.
* Settings export / import.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `glp_convert( $text, $context )`: Greek to Latin with the site's settings. Context `slug`, `file` or `plain`.
* `glp_convert` filter ( string $latin, string $original, string $context ): change any conversion.
* Fires `noxpress_products_changed` after the bulk page changes products.

== Installation ==

1. Upload the `greeklish-permalinks` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
2. Activate the plugin. New slugs are converted from now on.
3. Open **Noxpress → Greeklish** to choose the style, rules and the file name option, and to try the live test.
4. To convert existing slugs or files, take a backup, then use the **Bulk conversion** tab: scan, dry run, apply.

== Frequently Asked Questions ==

= Will my existing links break? =

No. Nothing existing changes unless you run a bulk conversion, and a bulk conversion leaves a 301 from every old address.

= Do redirects for renamed files work on every server? =

Only when the web server sends requests for missing files to WordPress, which most do. The bulk page checks this before you rename files.

= What if a bulk run is interrupted? =

What changed before the interruption is in the undo log. Scan again: the remaining items show up and can be applied as a new run (undo then covers the newer run only).

= What happens on uninstall? =

The settings, the undo log and the transients are removed. The redirect table is removed too, unless you ticked "Keep the redirect table" in Tools. Slugs and files you converted stay converted, and WordPress's own old-slug redirects for posts stay. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.2.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.1.0 =
* Bulk conversion follows the bulk-change rule of Noxpress Bible §12: each change is written to the undo log before it happens; if that write fails, the item is left alone, the run stops and everything it changed goes back.
* "Download backup" saves the last run as JSON; Tools → Import checks it strictly and loads it back as the run to undo.
* Content and downloadable files change only while they still hold the value in the log; undo of downloadable files checks the same.

= 1.0.0 =
* Initial release.
* Latin slugs for new posts, pages, products, terms, attributes and users; simple and phonetic styles; monotonic and polytonic Greek; stop words and custom rules; live test.
* Optional Latin file names on upload (off by default).
* Bulk conversion on demand of existing slugs and files: scan, dry run, batches, file sizes and variants, link updates in content and downloads, 301 redirect table, undo of the last run.
* 301 for Greek addresses that lead to a 404; legacy Greek term slugs keep working.
* Keeps the style of AutoConvert Greeklish Permalinks on first activation, with a notice while it is active.
* Works without WooCommerce. GLP_DISABLE, settings export / import.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.7).
