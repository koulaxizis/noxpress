=== Smart Formatter ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, formatting, bulk edit, product description, typography
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Bulk-format WooCommerce product texts (bold, italic, quotes, parentheses, numbers, whitespace) with preview, dry run, undo snapshots and profiles.

== Description ==

Smart Formatter applies formatting rules to WooCommerce product texts in bulk, safely:

* **Targets:** all published products, hand-picked products, or products in selected categories / tags.
* **Fields:** short description, long description, purchase note, custom (non-global) attribute values, and the descriptions of the selected categories / tags themselves.
* **Rules:** strip inline formatting (b, strong, i, em, u, s, strike, del, ins, mark), normalize whitespace, quoted text bold / italic («», “”, ""), parentheses italic, numbers bold, all text bold / italic.
* **Safe by design:** HTML tags and attributes, shortcodes, entities, HTML comments and the contents of `<pre>`, `<code>`, `<script>`, `<style>` and `<textarea>` are never touched. Any combination of rules produces well-formed HTML, and applying the same rules twice changes nothing the second time.
* **Phrase exclusions:** global (Settings) and per run — excluded phrases are never altered.
* **Preview, dry run and batched apply** with a progress bar, so large catalogues do not time out.
* **Undo:** every apply run stores a snapshot of the changed values; restore a whole run or selected units from the history.
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

Yes. Every apply run saves the previous values of the units it changed. Use **History & Undo** on the tool page to restore all of them or only selected units. Very large runs keep the most recent units that fit the snapshot size cap; such snapshots are marked as partial and restore only the units they kept.

= Are global attributes formatted? =

No. Global attribute values are terms shared by the whole store, so only custom (per-product) attributes are formatted.

= What happens on uninstall? =

The plugin's own options and transients are removed. Product texts that were already formatted stay as they are.

== Changelog ==

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
