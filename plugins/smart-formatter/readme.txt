=== Smart Formatter ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, formatting, bulk edit, product description, typography
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Bulk-format WooCommerce product texts (plain text, bold, italic, quotes, parentheses, numbers, whitespace) with preview, dry run, undo snapshots and profiles.

== Description ==

Smart Formatter applies formatting rules to WooCommerce product texts in bulk, safely:

* **Targets:** all published products, hand-picked products, or products in selected categories / tags.
* **Fields:** short description, long description, purchase note, custom (non-global) attribute values, and the descriptions of the selected categories / tags themselves.
* **Plain text:** removes every HTML tag and attribute (including the `data-path-to-node` attributes that text copied from AI tools carries), turns `&nbsp;` into plain spaces, removes zero-width characters and collapses blank lines. Two modes: keep line breaks (every paragraph, heading and list item on its own line, a blank line between paragraphs) or one paragraph. Shortcodes stay. The dry run counts the fields that lose a link, an image or a table.
* **Clean on save (optional, off by default):** the short description becomes plain text whenever a product is saved from its edit screen.
* **Rules:** strip inline formatting (b, strong, i, em, u, s, strike, del, ins, mark), normalize whitespace, quoted text bold / italic («», “”, ""), parentheses italic, numbers bold, all text bold / italic.
* **Safe by design** (formatting rules): HTML tags and attributes, shortcodes, entities, HTML comments and the contents of `<pre>`, `<code>`, `<script>`, `<style>` and `<textarea>` are never touched. Any combination of rules produces well-formed HTML, and applying the same rules twice changes nothing the second time.
* **Phrase exclusions:** global (Settings) and per run — excluded phrases are never altered.
* **Preview, dry run and batched apply** with a progress bar, so large catalogues do not time out.
* **Undo:** every apply run stores the previous values **before** it changes anything; restore a whole run or selected units from the history, or download a run's backup as JSON. A run whose backup would not fit in full does not start.
* **Profiles:** save and reload rule / field combinations.
* **Backup:** export / import profiles, exclusions and snapshot history as JSON (imports are strictly validated).
* Bilingual admin UI (Greek / English) following the Noxpress language choice, or the WordPress user locale.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu with the other Noxpress plugins, and fires `noxpress_products_changed` after it changes products.
* Noxpress hub: the "Noxpress" menu opens the Noxpress hub: one page for the whole suite with status, installed and available version, and update, install, activate, changelog and auto-update links.
* Updates: updates come from noxpress.tech (GitHub releases), not WordPress.org, through WordPress's own update screens. Stable or Beta channel (Stable by default, in the hub); every package is checked with sha256 and an Ed25519 signature before it is installed.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `smart-formatter` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Smart Formatter**.

== Frequently Asked Questions ==

= Can I undo a run? =

Yes. Every apply run saves the previous values of the units it changes, before it changes them. Use **History & Undo** on the tool page to restore all of them or only selected units, or **Download backup** to keep a copy (import it from SF Settings → Import state). A run's backup is capped at 4 MB: Apply first checks that the whole backup fits and does not start otherwise, so split very large jobs (for example by category). Snapshots from version 1.3.0 and earlier may be marked as partial; they restore only the units they kept.

= Should I use plain text or a display filter? =

Plain text changes the stored text once, with undo. A display-only filter would leave the old HTML in product feeds, structured data, page builders and the editor, so Smart Formatter does not offer one.

= Are global attributes formatted? =

No. Global attribute values are terms shared by the whole store, so only custom (per-product) attributes are formatted.

= What happens on uninstall? =

The plugin's own options and transients are removed. Product texts that were already formatted stay as they are.

== Changelog ==

= 1.5.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.4.0 =
* New: "Plain text" rule in two modes, keep line breaks or one paragraph. It removes every HTML tag and attribute (including `data-path-to-node` from text copied from AI tools), turns `&nbsp;` into spaces, removes zero-width characters and collapses blank lines; shortcodes stay. It works on every field; the dry run counts the fields that lose a link, an image or a table.
* New: optional "Clean on save" setting: the short description becomes plain text when a product is saved from its edit screen. Included in the state export / import.
* New: "Download backup" for every run in the history (JSON, importable from SF Settings).
* Fixed: the backup of a run is now written before the products change. Before, products were saved first, so a failed backup write left changes without undo.
* Fixed: a backup that would not fit (4 MB per run) was silently cut, dropping the oldest units. Apply now checks the size first and does not start; if a batch still does not fit, the run stops safely and everything changed so far restores in full.
* Fixed: a value that changed between the check and the write is skipped instead of being overwritten.
* Fixed: on narrow screens the history table scrolls on its own instead of widening the whole page.

= 1.3.0 =
* New: the Noxpress hub tells you when the other update channel has a newer version (in practice, a newer beta while the site follows Stable), with a counter on the Noxpress menu.
* New: "Install beta" for a single plugin, without moving the whole suite to Beta, or "Switch everything to Beta". Every package is still checked with sha256 and its signature.
* New: "Hide for this version" per user; the notice comes back with a newer version.
* Changed: Noxpress Core 1.1.0.

= 1.2.2 =
* New: Shop Filters joins the Noxpress suite: the hub lists it and can install it (Noxpress Core 1.0.2).
* Fix: uninstalling this plugin keeps the shared Noxpress data (update channel, hub notices) while Shop Filters is still installed.

= 1.2.1 =
* Fixed: the Smart Formatter admin pages now use the full width of the screen, like the other Noxpress plugins (they stopped at 1180px).
* Fix: the hub's "Changelog" link and "Install" button no longer end in "Plugin not found" when another plugin on the site overrides plugin details. The suite's own details are now applied last (Noxpress Core 1.0.1).
* Changed: the Noxpress hub page uses the full width of the screen (Noxpress Core 1.0.1).

= 1.2.0 =
* New: Noxpress hub. The "Noxpress" menu now opens one page for the whole suite: status, installed and available version, with update, install, activate, changelog and auto-update links (each shown only to users who may use it).
* New: updates without WordPress.org. WordPress shows Noxpress updates like any other, from noxpress.tech. Stable or Beta channel (Stable by default); every package is checked with sha256 and an Ed25519 signature before it is installed.
* New: `Update URI` header, so WordPress.org can never offer a different plugin with the same slug as an update.
* Changed: the shared "Noxpress" menu is created by Noxpress Core (bundled in every suite plugin, the newest copy loads), no longer by this plugin. It also works without WooCommerce, so updates keep arriving.
* Note: sites on an earlier version need this version installed by hand once; later versions arrive as updates.

= 1.1.0 =
* Fixed: "Strip formatting" removed unrelated tags (`<ul>`, `<br>`, `<img>`, `<span>`, `<sup>`, `<small>`, `<blockquote>`…); it now matches exact tag names only.
* Fixed: `<pre>`, `<code>`, `<script>`, `<style>`, HTML comments and shortcodes are protected before any rule runs.
* Fixed: whitespace normalization removed the space before inline tags ("Hello <b>World</b>"); trailing spaces are now trimmed only at real line ends.
* Fixed: rules were not idempotent (`<strong><strong>5</strong></strong>`); text already inside the target tag is skipped.
* Fixed: overlapping rules (e.g. quotes + parentheses) could produce misnested tags; formatting is now rendered as one well-formed layer and never crosses tags or paragraph breaks. Verified with a fuzz test over all rule combinations.
* Fixed: numbers inside attribute values containing ">" and stray "<" characters were mis-tokenized.
* Fixed: category / tag targeting passed term IDs where WooCommerce expects slugs.
* Fixed: restore always reported success; it now returns the real outcome. Truncated snapshots restore the units they kept, clearly labelled as partial.
* Fixed: dry run processed the whole catalogue in one request; it now runs in batches and returns counts plus a small sample.
* Security: imported snapshot history is strictly validated (structure, existing products / terms, allowed fields) and sanitized with `wp_kses_post`.
* Fixed: JavaScript error on the settings page; all UI strings (including JS) are now translatable and covered by the English dictionary.
* Fixed: uninstall cleaned the main site twice on multisite and used an unescaped LIKE pattern.
* Added: shared "Noxpress" admin menu, WooCommerce-active check, HPOS compatibility declaration, `noxpress_products_changed` action, readme.txt, PHP 7.4 compatibility.
* Changed: admin styling aligned with Revenue Splitter.

= 1.0.0 =
* Initial release.
