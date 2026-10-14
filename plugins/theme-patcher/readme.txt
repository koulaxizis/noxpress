=== Theme Patcher ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, theme compatibility, product cards, price labels, gpsr
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Fixes classic WooCommerce themes without a child theme and without touching theme files: product cards, texts, category tiles, theme settings, accessibility and price labels.

== Description ==

Many classic themes draw product cards, category tiles and texts with their own code. The price and the add-to-cart button go missing, English texts are hardcoded, settings are rewritten on every page view, full-size images slow the home page down. Theme Patcher fixes all of this at runtime, per theme, without a child theme and without editing a single theme file. Every feature has its own switch.

**Product cards**

* **Areas:** shop / categories / product search, related products, up-sells, cross-sells, and any theme file that runs its own product query (home page sections, template parts…). Each area is configured on its own.
* **Inject mode:** adds a slot inside the theme's own card, right after the element that holds the product title or image: sale badge, rating, price (with the old price struck through on sale) and add-to-cart button, each on or off. It uses WooCommerce's own loop templates, so taxes, variable / external / out-of-stock products and AJAX add to cart work as usual.
* **Sale badge:** the discount percentage for every product type; for variable products, the largest discount among the variations. The theme's own sale tag can be hidden so two badges never show.
* **Card hooks for other plugins:** prints what other plugins add to the standard card (colour swatches, wishlist buttons…) without duplicating WooCommerce's own price, rating and button.
* **Card title:** maximum number of lines and font size.
* **Card image size:** e.g. `woocommerce_thumbnail` instead of a full-size image.
* **Replace mode:** draws the area with WooCommerce's own template — standard cards with every hook. It also fixes theme templates with broken loops.
* **Standard cards are never touched:** cards that already fire WooCommerce's card hooks are detected and skipped.
* **Per-area styling:** price, button and badge colours, price colour on card hover, alignment, spacing and custom CSS, printed once in `<head>`.

**Texts and page**

* **Text table** "theme text → my text": exact replacements for the whole site, shop pages, the home page or product pages. The replacement can be your own text (simple HTML allowed, e.g. a footer link) or the WooCommerce page title (a fixed `<h1>Shop</h1>` becomes the right title). Hidden texts are covered too.
* **Remove elements:** e.g. the theme's credit link (`span.credit_link`) or empty boxes only (`span.product-sale-tag:empty`).
* **Same-tab links:** links to the site itself no longer open a new tab; external links keep theirs.
* **Alt text:** category images get the category name, media library images their alt text or title.
* **Accessible names:** icon-only links and buttons get an `aria-label` from their icon or address (Facebook, Instagram, cart, search, menu, next / previous, back to top…).
* **Page CSS** printed in `<head>`.

**Theme settings**

* **Settings guard:** page views can no longer write the theme's settings. Themes that call `set_theme_mod()` inside templates stop writing to the database on every view and stop overwriting what you saved in the Customizer. The Customizer and the admin are never blocked.
* **Fixed values** for settings the theme resets in its templates (e.g. a slider delay).
* **Local files:** theme lines that read the theme's own files over HTTP (`file_get_contents( get_template_directory_uri() … )`) read them from disk instead — the site stops calling itself.
* The Theme tab lists the theme files that write settings or read files over HTTP.

**Categories**

* **Image fallback:** categories without an image show the image of one of their products (most recent or best selling). The choice is computed in the admin and when products or categories are saved, never on a page view; categories with their own image are never changed.
* **Category lists of the theme:** for theme files that print category tiles, choose which categories show and in what order, and hide empty ones.
* **Category image size:** a smaller image instead of the full-size file the theme prints.

**Checks**

* Warns on the Widgets screen when WooCommerce block filters are used in widget areas of a classic theme, and points to the classic filter widgets.

**Price labels**

* **Price 0:** "Free" (your own text, Greek and English) instead of 0,00 € on the product page, shop and category lists, related products, up-sells, cross-sells, widgets and the product blocks. A variable product changes only when all its variations cost 0; a sale price of 0 shows the text only.
* **Cart, mini cart and checkout:** the price and subtotal of each free line show the same text. The order subtotal and total stay numbers.
* **Products without a price** (they cannot be bought, e.g. music to listen to): a label where the price would be, per category ("Free listening", "Free viewing"…), with subcategories, the first matching rule winning, and a general text for the rest.
* **Language:** automatic (site language), Greek or English.
* Store-wide, with any theme (block themes too). Orders, emails, the thank-you page, My Account, invoices, product feeds and the price schema keep the real price. The Cart and Checkout blocks draw prices with JavaScript and keep 0,00 €; the Prices tab says so when they are in use.

**Care & safety tab**

* **Product tab "Care and safety"** on the product page: care instructions, product safety, the manufacturer and the EU responsible person (GPSR), plus a link to your terms. It works with any theme that uses WooCommerce's product tabs (classic themes, block themes and accordion layouts alike).
* **Care profiles:** five ready profiles (clothing, hats, mugs, small accessories, home) with Greek texts you can edit, an optional English version, and your own profiles. Texts may use simple HTML and the placeholders `{product_name}`, `{material}` and `{method}`; a sentence whose placeholder has no value is left out.
* **Which profile:** chosen on the product, else by the product type attribute (e.g. `pa_eidos`), else by SKU prefix, else no tab. External, grouped and virtual products (e.g. e-books, music) and products no profile matches (e.g. books) get no tab unless a profile is set on the product; "None" on a product hides the tab.
* **Manufacturers and EU responsible person:** a list of manufacturers with address and contact, a default manufacturer per profile, and the person responsible in the EU. Empty sections are not shown.
* **Product box:** profile, manufacturer and an extra safety note per product, also in the REST API (`_tp_care_profile`, `_tp_care_manufacturer`, `_tp_care_note`).
* **Preview** by product ID (which profile, why, and the HTML), and `wp theme-patcher care resolve <id>` or `--all` in WP-CLI.
* Off until enabled, with its own switch; follows the Theme Patcher switch and test mode. Product data stays on uninstall unless you tick "delete" in Settings.

**Tools and safety**

* **Theme detector:** a code scan (comments ignored) lists template overrides and product loops of the active theme, with what each one is missing and a suggested mode.
* **Page probe:** open any page with the current settings applied for you only and see, per product loop, how many cards were found and fixed, plus every page-level change (texts, removed elements, names, images, blocked setting writes).
* **Safe by design:** off until enabled; test mode shows changes to shop managers only; settings are stored per theme (switching themes switches settings); areas are suspended automatically when the theme files they depend on change (e.g. after a theme update) until an admin confirms; any error leaves the theme's HTML untouched; no database writes on visitor requests; block themes are left alone (except the price labels); `define( 'TP_DISABLE', true );` in wp-config.php stops every front-end hook.
* **Backup:** export / import all settings as JSON (strictly validated).
* Bilingual admin UI (Greek / English) following the Noxpress language choice, or the WordPress user locale.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu with Revenue Splitter, Store Pulse and Smart Formatter.
* Noxpress hub: the "Noxpress" menu opens the Noxpress hub: one page for the whole suite with status, installed and available version, and update, install, activate, changelog and auto-update links.
* Updates: updates come from noxpress.tech (GitHub releases), not WordPress.org, through WordPress's own update screens. Stable or Beta channel (Stable by default, in the hub); every package is checked with sha256 and an Ed25519 signature before it is installed.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `theme-patcher` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Theme Patcher**: check the theme detector, set the mode of each area, then go through the Texts, Page, Theme settings and Categories tabs. Test with the page probe.
5. Enable Theme Patcher in **Noxpress → TP Settings** (keep test mode on until you have checked the pages).

== Frequently Asked Questions ==

= Does it change my theme files or settings? =

No. Theme Patcher works at runtime through WordPress and WooCommerce hooks. Theme files are only read (by the detector). Theme settings, categories and products are never written; fixed values and category images apply on the front end only.

= Inject or replace? =

Inject keeps the theme's card design and adds the missing parts. Replace gives you WooCommerce's standard cards (styled by the theme's WooCommerce CSS, if any) and is the right choice when the theme's template is broken. Files with their own product query have no WooCommerce template to fall back to, so they support inject only.

= The slot does not show up in a card. =

Run the page probe: the "Not placed" column counts cards where the closing element after the anchor was not found. Change the anchor (title / image) or the element (e.g. `</h3>`, `</a>`, `</div>`) of the area.

= A text is not replaced. =

The search is exact, against the page HTML: copy the text from the page source (View source), not from the screen. `&` is usually written `&amp;` in the HTML.

= An area is "suspended". =

The theme files of that area changed after you configured it (usually a theme update). Check the page with the probe and confirm on the Theme Patcher page to turn the area back on.

= The price label does not show up for visitors. =

Check that Theme Patcher is enabled and test mode is off (TP Settings), then clear the page cache (e.g. WP Rocket): cached pages keep the old price until they are rebuilt.

= Does "Free" change the price in Google results? =

No. The label is only text for visitors. The structured data (schema) keeps the real price: 0 for free products, which search engines show as free, and no offer for products without a price.

= What happens on uninstall? =

The plugin's own options (`tp_settings`, `tp_cat_images`, `tp_care`) and transients are removed. The care profile, manufacturer and note saved on products stay, unless "Delete the care details from the products when the plugin is deleted" is ticked in Settings. Nothing else was ever changed.

= The care tab does not show up. =

Check that the tab is on (Care tab), Theme Patcher is enabled and test mode is off. Use the Preview with the product ID to see which profile it gets and why, then clear the page cache (e.g. WP Rocket).

== Changelog ==

= 1.4.0 =
* New: "Care and safety" product tab (GPSR): care instructions, product safety, manufacturer, EU responsible person and a terms link, from care profiles chosen per product, by product type attribute or by SKU prefix. Off until enabled.
* New: Care tab in the settings: profiles with an editor and Greek / English texts, placeholders `{product_name}`, `{material}`, `{method}`, manufacturers, EU responsible person, visitor texts, terms link and anchor, link colour, preview, restore defaults.
* New: product box (profile, manufacturer, safety note); the three product fields are in the REST API for users who can edit the product.
* New: `wp theme-patcher care resolve <id>` and `--all` (read only).
* New: filters `tp_care_tab_title`, `tp_care_resolved_profile`, `tp_care_manufacturer`, `tp_care_tab_html`.
* New: the backup includes the care settings; Settings can delete the care data from products on uninstall (off by default).

= 1.3.0 =
* New: the Noxpress hub tells you when the other update channel has a newer version (in practice, a newer beta while the site follows Stable), with a counter on the Noxpress menu.
* New: "Install beta" for a single plugin, without moving the whole suite to Beta, or "Switch everything to Beta". Every package is still checked with sha256 and its signature.
* New: "Hide for this version" per user; the notice comes back with a newer version.
* Changed: Noxpress Core 1.1.0.

= 1.2.0 =
* New: price labels (Prices tab). Products with price 0 show "Free" (your own text) instead of 0,00 € on product pages, lists, related products, up-sells, cross-sells, widgets and product blocks, and on each cart, mini cart and checkout line. The order subtotal and total stay numbers.
* New: labels for products without a price, per product category (with subcategories, first matching rule wins), plus a general text for the rest.
* New: label texts in Greek and English, with the language chosen in the plugin (automatic from the site language, Greek or English). Developers can change a text with the `tp_price_label_text` filter.
* Price labels work on block themes too, follow the master switch, test mode and `TP_DISABLE`, run on the cart's AJAX requests, and never change orders, emails, invoices, feeds or the price schema. They are included in the settings backup.
* Changed: on block themes the Theme Patcher page shows the Prices tab (the only feature that applies there).
* New: Product Formats and Easy Withdrawal join the Noxpress suite: the hub lists them and can install them (Noxpress Core 1.0.4).
* Fix: uninstalling this plugin keeps the shared Noxpress data while Product Formats or Easy Withdrawal is still installed.

= 1.1.2 =
* New: Shop Filters joins the Noxpress suite: the hub lists it and can install it (Noxpress Core 1.0.2).
* Fix: uninstalling this plugin keeps the shared Noxpress data (update channel, hub notices) while Shop Filters is still installed.

= 1.1.1 =
* Fixed: the Theme Patcher admin pages now use the full width of the screen, like the other Noxpress plugins (they stopped at 1180px).
* Fix: the hub's "Changelog" link and "Install" button no longer end in "Plugin not found" when another plugin on the site overrides plugin details. The suite's own details are now applied last (Noxpress Core 1.0.1).
* Changed: the Noxpress hub page uses the full width of the screen (Noxpress Core 1.0.1).

= 1.1.0 =
* New: Noxpress hub. The "Noxpress" menu now opens one page for the whole suite: status, installed and available version, with update, install, activate, changelog and auto-update links (each shown only to users who may use it).
* New: updates without WordPress.org. WordPress shows Noxpress updates like any other, from noxpress.tech. Stable or Beta channel (Stable by default); every package is checked with sha256 and an Ed25519 signature before it is installed.
* New: `Update URI` header, so WordPress.org can never offer a different plugin with the same slug as an update.
* Changed: the shared "Noxpress" menu is created by Noxpress Core (bundled in every suite plugin, the newest copy loads), no longer by this plugin. It also works without WooCommerce, so updates keep arriving.
* Note: sites on an earlier version need this version installed by hand once; later versions arrive as updates.

= 1.0.0 =
* Initial release.
* Product cards: inject and replace modes per area (shop, related, up-sells, cross-sells, theme files with their own product query); sale badge for every product type; card hooks for other plugins; title lines and size; card image size; per-area styling.
* Texts and page: text table with scopes and HTML replacements, element removal (with `:empty`), same-tab internal links, alt texts, accessible names for icon-only links and buttons, page CSS.
* Theme settings: settings guard against writes from page views, fixed setting values, theme files read from disk instead of HTTP.
* Categories: image fallback from a product, category lists with chosen order, smaller category images.
* Checks: block filter widgets on classic themes.
* Theme detector, page probe with page-level counters, test mode, per-theme settings with automatic suspension on theme file changes, TP_DISABLE emergency switch, JSON backup, Greek / English admin.
