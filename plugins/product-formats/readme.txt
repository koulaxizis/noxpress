=== Product Formats ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, product formats, ebook, audiobook, linked products
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Link the products that are the same work in another format (print, e-book, audiobook, film, music) and show an "Available formats" block with label, price and link on each product page.

== Description ==

Many shops sell one work as separate products, one per format: a printed book, its e-book, its audiobook, the film. Product Formats joins them into a **work** and shows the other formats where a buyer looks for them, next to the price. The products stay separate, each with its own price, stock, SKU and page.

**Works**

* One work per product, with a format and an optional subtitle (for two products of the same format, e.g. two editions).
* Built-in formats: Print, E-book, Audiobook, Film, Music. Add your own (e.g. Vinyl) with Greek and English labels, turn formats off, and drag them into the order the block uses.
* A "Formats" tab in the product data panel: pick the work (autocomplete from the 3rd letter, a new name creates a work), the format and the subtitle; links to the other formats.
* A works screen with search and paging: edit members, add products, merge two works, delete a work (the products are not touched).
* Drafts can belong to a work; they show in the block only once published.

**Suggestions**

* A scan of the catalogue groups products with the same slug without a format suffix (`-ebook`, `-audiobook`, `-film`, `-music`… editable per format) or the same title without bracketed notes (accent and case insensitive).
* The format comes from the slug suffix, or from the product category set per format.
* Existing upsells between the products raise the confidence. Each suggestion can be edited, accepted or rejected; nothing changes before you accept.

**On the product page**

* The "Available formats" block with each format's label, price (WooCommerce's own price HTML, sale prices included) and link; the current format is marked; optional "Out of stock" note.
* Position: below the price, below the add to cart button, above the description tabs, or only through the `[nox_formats]` shortcode (`[nox_formats id="123"]` for another product). Works with classic themes (WooCommerce hooks, Astra's own hooks) and block themes (the product price, add to cart and details blocks).
* Optional line "Also: E-book · Audiobook" below the price in product lists.
* Theme-proof markup and CSS: no `ul / li`, colours and fonts from the theme.

**Upsells that did the job before**

* Hide them on the front end (no change to the products), or
* Clean them up for good: a preview lists what goes and what stays (upsells to other products are kept), the cleanup runs in batches with a progress bar, and a snapshot of every changed product allows a full restore.

**Safe by design:** off until enabled; test mode shows the block to shop managers only; `define( 'PFM_DISABLE', true );` in wp-config.php stops every front-end hook. No database writes on visitor requests: the member list of each work is stored when a product is saved, and caches (WooCommerce, WP Rocket, LiteSpeed) are purged for every product of a work that changes.

* Public API for other plugins: `pfm_get_work( $product_id )` and the `pfm_formats` filter.
* Backup: export / import settings, formats and works as JSON (strictly validated; products are matched by ID and slug or SKU).
* Bilingual admin UI (Greek / English) following the Noxpress language choice, or the WordPress user locale. Front-end texts follow the site language.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `product-formats` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Formats → Suggestions**, press "Scan the catalogue", check the suggestions and accept them.
5. Check the formats tab (labels, order, slug suffixes, categories) if your shop uses other names.
6. Enable the block in **Noxpress → PFM Settings** (keep test mode on until you have checked the pages).
7. Optional: hide or clean up the upsells that linked the formats before (Upsells tab).

== Frequently Asked Questions ==

= Does it merge my products into one? =

No. Each format stays a separate product with its own price, stock and page. A work only links them.

= Where is the work stored? =

In a hidden product taxonomy (`pfm_work`, one term per work) and two product meta fields (`_pfm_format`, `_pfm_variant`). Nothing shows in the shop's URLs or menus.

= Which price does the block show? =

The product's own price HTML, as WooCommerce (and plugins that change it) prints it. A product without a price shows no price.

= What happens on uninstall? =

The plugin's options, works, product meta and transients are removed, including the upsell snapshots. **Upsells removed by the cleanup are not restored on uninstall**: restore them from the Upsells tab first if you want them back. Nothing else was ever changed.

== Changelog ==

= 1.0.0 =
* Initial release.
* Works: hidden taxonomy, one format and an optional subtitle per product, precomputed member list, cache purge for every product of a changed work.
* Formats: five built-in formats plus custom ones, Greek and English labels, slug suffixes and categories, drag-and-drop order.
* Suggestions from slugs, normalised titles and upsells, with confidence, editing, accept, reject and bulk accept.
* "Formats" tab in the product data panel and a works screen (search, edit, add, merge, delete).
* "Available formats" block with label, price and link: below the price, below add to cart, above the tabs, or by shortcode; classic themes, Astra hooks and block themes.
* Optional "Also: …" line in product lists; optional front-end hiding of upsells.
* Upsell cleanup with preview, batches, snapshots and restore.
* Test mode, PFM_DISABLE, backup / import, public API (`pfm_get_work`, `pfm_formats` filter).
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.0.3).
