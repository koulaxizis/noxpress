=== Shop Filters ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, product filters, layered navigation, attributes, classic themes
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Lean WooCommerce product filters for classic themes: real category hierarchy, attribute filters with counts, a price slider, and value groups for messy attribute data.

== Description ==

Shop Filters is not another all-purpose filter plugin. It solves two things the usual ones do not:

* **Messy attribute data.** Imported attributes often have hundreds of values ("0-6M", "6 μηνών +", "12 μηνών-5 ετών", "Pink" and "Ροζ"…). Value groups put them under a few clean options (e.g. 88 ages in 6 ranges, 350 colours in 12) without touching the products: the filter shows the groups, the product page keeps showing the original value.
* **Themes that draw the product list with their own HTML.** Filtering changes WooCommerce's main product query, so it works with any theme that uses it, even when the theme prints its own product cards.

**Filters**

* **Category** with a real tree: open / close per parent, indent per level, any depth; each category links to its archive and keeps the other filters.
* **Attribute** (global attributes): list of options with product counts, OR inside a filter, AND between filters.
* **Price** with a two-handle slider and two number fields (WooCommerce's own `min_price` / `max_price`). No jQuery UI.
* Collapsible filters (open or closed by default), options without results hidden (or shown dimmed), active filters as removable tags, "Clear all".
* **Filter sets:** choose the filters and their order (drag and drop), and use a different set per product category (subcategories inherit it).

**Value groups**

* Groups per attribute; a value can belong to several groups (an age "0-12 years" fits every age range).
* Suggestions from ranges ("6-18M", "12 μηνών-5 ετών", "3 Ετών+" are read as months) and from keywords (accent and case insensitive: "grey, gray, γκρι, graphite"). Suggestions are shown in a matrix and applied only when you save.
* Values in no group: hidden from the filter, in an automatic "Other" group, or shown on their own. New values without a group are reported on the plugin page.
* Wrong values (e.g. a number in a brand attribute) can be ignored by the filter; the data stays as it is.

**Fast, clean, theme proof**

* Counts come from WooCommerce's attributes lookup table (kept up to date by WooCommerce on every product change), with the stock of each variation: "Pink" counts only products with a pink variation in stock. Without the table, product terms are used.
* No database writes on visitor requests; results are kept in the object cache when the site has one.
* Clean links that work without JavaScript and can be shared: `?shf_age=0-6m,1-3y`. Filter links are `rel="nofollow"`; filtered pages get `noindex, follow` and a canonical to the unfiltered page (works with Yoast SEO and Rank Math).
* Markup that survives theme rules: no `ul / li`, no checkbox inputs, no icon font; collapsible parts are `<details>`. One small CSS and one small JS file, only on product listing pages. No jQuery, no select2, no Font Awesome.
* Apply mode: immediate, with an "Apply" button, or immediate on desktop and with a button on narrow screens.

**Safe by design:** off until enabled; test mode shows the filters to shop managers only; `define( 'SHF_DISABLE', true );` in wp-config.php stops every front-end hook; products, terms and theme files are never changed.

* Classic widget "Noxpress: Φίλτρα" and shortcode `[shf_filters]` (optionally `set="set2"`).
* Backup: export / import settings, sets and groups as JSON (strictly validated).
* Bilingual admin UI (Greek / English) following the Noxpress language choice, or the WordPress user locale. Front-end texts follow the site language.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `shop-filters` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Shop Filters**: create a filter set, add the attribute filters and put them in order.
5. Optional: in the **Value groups** tab, create groups for attributes with many values, press "Suggest", check the matrix and save.
6. Add the widget "Noxpress: Φίλτρα" to the shop sidebar (Appearance → Widgets).
7. Enable the filters in **Noxpress → SHF Settings** (keep test mode on until you have checked the pages).

== Frequently Asked Questions ==

= Which attributes can be filtered? =

Global attributes (Products → Attributes). Attributes typed inside a single product are not shared terms and cannot be filtered.

= Do the groups change my products? =

No. Groups are stored in the plugin's own option and only change what the filter shows and how it queries. Product pages, attribute values and the WooCommerce data stay as they are.

= Does it work with AJAX? =

Version 1.0 loads the page for every change (with a jump to the product list). It needs no JavaScript and works with any theme that uses WooCommerce's main product query.

= Does it work with block themes? =

On block themes the widget and the shortcode work where a classic widget or a shortcode can be placed; WooCommerce's own filter blocks remain the native choice there.

= What happens on uninstall? =

The plugin's options (`shf_settings`, `shf_sets`, `shf_groups_*`, `widget_shf_filters`), its widgets in the sidebars and its transients are removed. Nothing else was ever changed.

== Changelog ==

= 1.0.0 =
* Initial release.
* Filters: category tree (any depth, open / close per parent), attributes with counts, price slider without jQuery UI; collapsible filters, hidden or dimmed empty options, active filter tags, "Clear all".
* Value groups per attribute, many-to-many, with suggestions from ranges and keywords, an ignore list and a choice for values in no group.
* Filter sets with drag-and-drop order and a set per product category.
* Counts from WooCommerce's attributes lookup table (stock per variation), fallback to product terms; object cache, no database writes on visitor requests.
* Clean links that work without JavaScript; noindex and canonical on filtered pages; nofollow filter links.
* Theme-proof markup and CSS; one small CSS and JS file, no external libraries.
* Apply mode (immediate, button, or button on narrow screens), test mode, SHF_DISABLE, backup / import, classic widget and shortcode.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.0.2).
