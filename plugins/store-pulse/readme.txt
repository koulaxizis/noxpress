=== Store Pulse ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, dashboard, orders, stock, sales report
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Your WooCommerce store at a glance: pending and completed orders, refunds, cancellations, low/out-of-stock products and, with Revenue Splitter, publisher profit and amounts owed.

== Description ==

Store Pulse adds a compact dashboard and a WordPress home-screen widget ("Quick View") for WooCommerce store managers.

* Pending orders (pending, processing, on hold) and how many of them are older than 7 days.
* Completed orders in a configurable period (by completion date), unique customers, average order value and the top 10 best-selling products. Amounts use line items only (shipping and fees are excluded) minus refunds.
* Refunds (count and amount) and cancelled orders in their own configurable periods.
* Low-stock and out-of-stock products, including variations that manage their own stock. Counts are complete; the lists show up to 50 products.
* Sales by product (Noxpress → SP Sales): units and amounts per product, or per variation, for any period (presets, this or last month or year, all time, or your own dates), counted by completed, paid or created date. Choose the order statuses, all products, some categories (subcategories included) or specific products, whether free lines count and whether refunds are subtracted, the columns (ID, name, SKU, categories, units, before discount, discount, sales, VAT, net, refunded units and amount, orders, stock) and the sort order. See the first 50 rows on screen with totals, or download everything as CSV: "Excel, Greek" (semicolon and decimal comma) or standard (comma and decimal point), UTF-8 with BOM. Amounts include VAT; shipping and fees never count. With Revenue Splitter, VAT uses its rate for each product, so the figures match Revenue Splitter; without it, the tax WooCommerce stored on each order line. Your last choices are remembered per user.
* With the Revenue Splitter plugin active: the publisher's share and net amount for the period, and what is owed to every other beneficiary. Store Pulse only reads Revenue Splitter's public APIs and never recalculates splits, VAT or shipping.
* Abilities (WordPress 6.9+): the dashboard, the stock lists and the sales report are also read-only WordPress abilities (`store-pulse/overview`, `store-pulse/stock`, `store-pulse/sales`) for AI and remote tools such as Site Bridge, with the same capability. Customers are only ever a count.

Part of the Noxpress plugin family. All Noxpress plugins share a single "Noxpress" admin menu; its first page is the Noxpress hub (status, versions and updates of the whole suite), and Store Pulse adds its pages under it. The pages are always available at `admin.php?page=sp-dashboard`, `admin.php?page=sp-sales` and `admin.php?page=sp-settings`.

Updates: updates come from noxpress.tech (GitHub releases), not WordPress.org, through WordPress's own update screens. Stable or Beta channel (Stable by default, in the hub); every package is checked with sha256 and an Ed25519 signature before it is installed.

The interface is available in Greek and English. The language is chosen per user in the Revenue Splitter settings; without Revenue Splitter it follows the user's WordPress profile language.

Results are cached for 5 minutes. The cache is refreshed when settings are saved, when Revenue Splitter data changes (`rs_invalidate_cache`) and when Smart Formatter changes products (`noxpress_products_changed`).

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `store-pulse` folder to `/wp-content/plugins/` or install the zip from Plugins → Add New.
3. Activate the plugin.
4. Open Noxpress → SP Settings to choose the periods, the low-stock threshold, the publisher (requires Revenue Splitter) and the cards shown in the Quick View widget.

== Frequently Asked Questions ==

= Do I need Revenue Splitter? =

No. Every card works without it except the money cards (publisher profit and amounts owed), which show a notice instead.

= Is it compatible with HPOS (custom order tables)? =

Yes. All order queries use the WooCommerce order API.

= What is removed on uninstall? =

Only Store Pulse's own options, cached transients and each user's last choices on the SP Sales page. Revenue Splitter data and the shared language setting are left untouched.

= Why can the sales figures differ from Revenue Splitter's report? =

VAT is the same (Store Pulse uses Revenue Splitter's rate for each product). The totals differ only when the choices differ: Revenue Splitter counts completed and processing orders by order date, from its "sales start" date, while SP Sales counts the statuses and the date you choose.

== Changelog ==

= 1.8.1 =
* Fix: the sales CSV passes every fputcsv() argument, so PHP 8.4 prints no deprecation notice into the file.
* Tested up to WordPress 7.1 and WooCommerce 11.2.

= 1.8.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.7.0 =
* New: WordPress abilities (6.9+) in the "noxpress" category, all read-only: `store-pulse/overview` (the dashboard cards of a period, with the money cards when Revenue Splitter is active), `store-pulse/stock` (the low-stock and out-of-stock lists) and `store-pulse/sales` (the Sales screen's rows per product and totals, with the same filters). For AI and remote tools such as Site Bridge; they need `manage_woocommerce`, are not in the REST API and never return customer details (customers are a count).

= 1.6.1 =
* Fixed: the publisher profit card says "Publisher profit (last month)" instead of "Publisher profit (period) (last month)".

= 1.6.0 =
* New: SP Sales page (Noxpress → SP Sales): sales per product or per variation for any period, with an on-screen preview and a CSV download. Filters for period, date type, order statuses, categories and products; options for variations, free lines, refunds and a totals row; choice of columns and sort order; CSV for Greek Excel (semicolon, decimal comma) or standard. Replaces third-party "sales by product" exporters.
* New: with Revenue Splitter, VAT uses Revenue Splitter's rate for each product.
* Changed: the dashboard's top sellers subtract refunds per order line (units included), so they match SP Sales. Before, a refund was spread over the whole order.
* Fixed: the dashboard's periods start and end at the exact local time. WooCommerce compared the old bounds by day only, so refunds and cancellations from late in the evening could land in the next day's figures.

= 1.5.0 =
* New: the Noxpress hub tells you when the other update channel has a newer version (in practice, a newer beta while the site follows Stable), with a counter on the Noxpress menu.
* New: "Install beta" for a single plugin, without moving the whole suite to Beta, or "Switch everything to Beta". Every package is still checked with sha256 and its signature.
* New: "Hide for this version" per user; the notice comes back with a newer version.
* Changed: Noxpress Core 1.1.0.

= 1.4.2 =
* New: Shop Filters joins the Noxpress suite: the hub lists it and can install it (Noxpress Core 1.0.2).
* Fix: uninstalling this plugin keeps the shared Noxpress data (update channel, hub notices) while Shop Filters is still installed.

= 1.4.1 =
* Fix: the hub's "Changelog" link and "Install" button no longer end in "Plugin not found" when another plugin on the site overrides plugin details. The suite's own details are now applied last (Noxpress Core 1.0.1).
* Changed: the Noxpress hub page uses the full width of the screen (Noxpress Core 1.0.1).

= 1.4.0 =
* New: Noxpress hub. The "Noxpress" menu now opens one page for the whole suite: status, installed and available version, with update, install, activate, changelog and auto-update links (each shown only to users who may use it).
* New: updates without WordPress.org. WordPress shows Noxpress updates like any other, from noxpress.tech. Stable or Beta channel (Stable by default); every package is checked with sha256 and an Ed25519 signature before it is installed.
* New: `Update URI` header, so WordPress.org can never offer a different plugin with the same slug as an update.
* Changed: the shared "Noxpress" menu is created by Noxpress Core (bundled in every suite plugin, the newest copy loads), no longer by this plugin. It also works without WooCommerce, so updates keep arriving.
* Note: sites on an earlier version need this version installed by hand once; later versions arrive as updates.

= 1.3.0 =
* Shared "Noxpress" menu: attaches at admin_menu priority 20, creates the menu only when it does not exist yet, and always registers `sp-dashboard` and `sp-settings` (the "Dashboard" link in the widget and on the Plugins screen no longer points to a missing page when Revenue Splitter is inactive).
* Stylesheet is now loaded by page slug, so the plugin pages are styled in every menu combination.
* Settings are saved before any output, so the save redirect works; an invalid period now produces a single error message.
* Stock: low/out-of-stock counts are true totals (only the lists are limited to 50), variations with their own stock are included and parent-managed stock is no longer counted twice.
* Pending, refund, cancelled and completed queries no longer load every order at once (counts or batched pages).
* Cache listens to `rs_invalidate_cache` (the listener was never registered before) and `noxpress_products_changed`.
* Quick View widget shows "No publisher selected" / "Unknown publisher" instead of "Requires Revenue Splitter" when Revenue Splitter is active.
* Escaping fixes (top-sellers heading, card sub-labels no longer double-escaped).
* English dictionary completed and cleaned up; the footer text is translatable and matches the other Noxpress plugins.
* Uninstall removes only Store Pulse's own transients (escaped LIKE pattern) and works from WP-CLI.
* Plugin header aligned with the Noxpress family; requires WooCommerce.
* Fixed: the sticky table header covered the first row of every table (stock lists and top sellers looked one row short).

= 1.2.5 =
* Previous release.
