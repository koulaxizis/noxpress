=== Loop Fixer ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, product loop, price, add to cart, theme compatibility
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Restores price, add-to-cart button and rating in WooCommerce product cards of themes that draw their own cards — no child theme, no theme file changes.

== Description ==

Some classic themes draw product cards with their own code instead of WooCommerce's standard card (`content-product.php`). The price and the add-to-cart button go missing, and plugins that hook into the card show nothing. Loop Fixer fixes this per area of the site, without a child theme and without editing a single theme file.

* **Areas:** shop / categories / product search, related products, up-sells, cross-sells, and any theme file that runs its own product query (home page sections, template parts…).
* **Inject mode:** adds a slot with the price, the add-to-cart button and (optionally) the rating inside the theme's own card, right after the element that holds the product title or image. The theme's design stays; the slot uses WooCommerce's own loop templates, so sale prices, taxes, variable / external / out-of-stock products and AJAX add to cart work as usual.
* **Replace mode:** draws the area with WooCommerce's own template — standard cards with every hook. It also fixes theme templates with broken loops (e.g. related products that repeat the current product).
* **Standard cards are never touched:** cards that already fire WooCommerce's card hooks are detected and skipped.
* **Theme detector:** a code scan (comments ignored) lists template overrides and product loops of the active theme, with what each one is missing and a suggested mode.
* **Page probe:** open any page of the site with the current settings applied for you only and see, per product loop, how many cards were found, how many were already standard and how many received the slot.
* **Text fixes:** exact find and replace on shop pages, e.g. a fixed `<h1>Shop</h1>` becomes the right page title.
* **Per-area styling:** price and button colors, price color on card hover, alignment, spacing and custom CSS, scoped to the area's slot.
* **Safe by design:** off until enabled; test mode shows changes to shop managers only; settings are stored per theme (switching themes switches settings); areas are suspended automatically when the theme files they depend on change (e.g. after a theme update) until an admin confirms; any error leaves the theme's HTML untouched; no database writes on visitor requests; block themes are left alone; `define( 'LF_DISABLE', true );` in wp-config.php stops every front-end hook.
* **Backup:** export / import all settings as JSON (strictly validated).
* Bilingual admin UI (Greek / English) following the Noxpress language choice, or the WordPress user locale.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu with Revenue Splitter, Store Pulse and Smart Formatter.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `loop-fixer` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Loop Fixer**, check the theme detector, set the mode of each area and test with the page probe.
5. Enable Loop Fixer in **Noxpress → LF Settings** (keep test mode on until you have checked the pages).

== Frequently Asked Questions ==

= Does it change my theme files? =

No. Loop Fixer works at runtime through WordPress and WooCommerce hooks. Theme files are only read (by the detector).

= Inject or replace? =

Inject keeps the theme's card design and adds the missing parts. Replace gives you WooCommerce's standard cards (styled by the theme's WooCommerce CSS, if any) and is the right choice when the theme's template is broken. Files with their own product query have no WooCommerce template to fall back to, so they support inject only.

= The slot does not show up in a card. =

Run the page probe: the "Not placed" column counts cards where the closing element after the anchor was not found. Change the anchor (title / image) or the element (e.g. `</h3>`, `</a>`, `</div>`) of the area.

= An area is "suspended". =

The theme files of that area changed after you configured it (usually a theme update). Check the page with the probe and confirm on the Loop Fixer page to turn the area back on.

= What happens on uninstall? =

The plugin's own option and transients are removed. Nothing else was ever changed.

== Changelog ==

= 1.0.0 =
* Initial release: inject and replace modes per area (shop, related, up-sells, cross-sells, theme files with their own product query), theme detector, page probe, archive text fixes, per-area styling, test mode, per-theme settings with automatic suspension on theme file changes, LF_DISABLE emergency switch, JSON backup, Greek / English admin.
