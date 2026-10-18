=== Pre-Orders ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, pre-order, preorder, release date, coming soon
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Pre-orders for WooCommerce products that are not released yet: a release date (or "Coming soon"), a badge and a "Pre-order" button, the date in the cart, on the order and in emails. On the release day the product becomes a normal product by itself.

== Description ==

**A pre-order is a normal product with a release date**

* Tick "Pre-order" on a simple product (General tab) or on any variation, and give a release date, or leave it empty for "Coming soon".
* Stock, prices, order statuses and payment are not changed: the product is sold as usual, with the pre-order shown around it. Out of stock products stay out of stock (the editor warns you).
* On the release date the product is a normal product again. This holds on every page view, so it is right even when WordPress's cron does not run.
* An optional text per product replaces the general one (`{date}` becomes the date).
* "Sale until release" fills WooCommerce's own sale dates, from today until the day before the release.
* Quick edit and bulk edit in the products list, a "Release" column and an "On pre-order" filter.

**In the store**

* A small "Pre-order" badge on product cards and on the product page (or only one of them, or none). Classic themes and block themes (from the Product Price block, so it never shows twice). Its colours follow the CSS variables `--por-badge-bg` and `--por-badge-fg`.
* The add to cart button says "Pre-order" (also in the Product Button block); on variable products it changes with the chosen variation.
* "Releases on 15/11/2026" or "Coming soon" as the availability text on the product page (also in the Stock Indicator block).
* Structured data: `availability` PreOrder and `availabilityStarts` on the product's Offer (WooCommerce's data, or Rank Math's when it prints the graph). Never a second graph.
* `[nox_preorders limit="12" columns="4"]` and the "Pre-orders" block: the products on pre-order with the theme's own product cards, the nearest release first.

**Cart, checkout and orders**

* Each pre-order line in the cart, mini-cart and checkout says "Pre-order: Releases on …" (classic pages and the Cart / Checkout blocks).
* A cart with pre-orders and available products: allowed with a note, allowed with a warning that suggests two orders, or not allowed (pre-orders in a separate order; this also holds in the Cart / Checkout blocks).
* At checkout the release date is saved on the order line as a readable row, so it shows in the admin, in emails, on the thank-you page and in My account, even without the plugin. Lines a shop manager adds by hand get it too.
* Orders list (HPOS and legacy): a "Pre-order" column with the date still waited for, and a "With pre-orders" filter.

**Release day and changed dates**

* At 00:01 of the release date an Action Scheduler job removes the pre-order from the product and saves it, so page caches (WP Rocket, LiteSpeed …), WooCommerce's caches and feeds see the change. It fires `por_released`.
* Then the open orders (processing, on hold) get a note, and optionally the "Released" email.
* When you change the date of a product with open pre-orders, the editor offers to update those orders with the new date, and optionally to send the "New release date" email. Done in the background, in batches.
* Both emails are WooCommerce emails (WooCommerce → Settings → Emails), off by default, in the store's email template, written in the plugin's text language.

**Plugin page**

* Upcoming releases: product, format (from Product Formats, when active), release date, open orders, units and revenue, a link to the orders of each product.
* A shipping list as CSV (all products or one): order, date, status, customer, email, phone, shipping address, product, SKU, units, amount, release. "Excel Greek" or "Standard" format.

**Works with the Noxpress suite**

* Shipping Notes: the release date feeds its delivery estimate (`shn_available_from`), so the estimate counts from the release day.
* Product Formats: the format column on the plugin page.

**Safe by design**

* Off by default, with a test mode that shows pre-orders to shop managers only.
* While another pre-order plugin is active, Pre-Orders stays in test mode, so the two never run together.
* `define( 'POR_DISABLE', true );` in wp-config.php stops every front-end hook; pre-order products then sell as normal products.
* No cookies, no external requests, no data about visitors. HPOS compatible.
* Editable Greek / English customer texts, own date format, settings export / import.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `por_get_release( $product )`: the pre-order of a product (`active`, `date` as `YYYY-MM-DD` or empty for "Coming soon", `text`, and `variations` for variable products), or `null`.
* `por_is_preorder` ( bool $on, WC_Product $product ): whether a simple product or a variation is on pre-order right now.
* `por_text` ( string $text, string $key, string $lang ): change any customer text.
* `por_released` ( int $product_id, string $date ): action on the release day, after the product became a normal product.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `pre-orders` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open a product, tick "Pre-order" in the General tab (or in a variation) and set the release date.
5. In **Noxpress → Pre-orders → Settings**, test with test mode, then switch to **live**.
6. Optional: turn on the "Released" and "New release date" emails in WooCommerce → Settings → Emails.

== Frequently Asked Questions ==

= Does the customer pay now? =

Yes. A pre-order is a normal order paid at checkout. Charging later, on the release day, needs a stored card at each payment gateway and is not part of the plugin.

= How do I limit the number of pre-orders? =

Use WooCommerce's stock management on the product: the plugin does not change stock.

= What happens if cron does not run? =

The store is still right: every page view checks the date. Only a page cache may keep the old page until it expires, and the "Released" emails wait for the next cron run.

= I use another pre-order plugin. How do I move? =

Activate Pre-Orders: it stays in test mode while the other plugin is active. Mark the products here, check them as a shop manager, then deactivate the other plugin. Its data is not changed or imported.

= What happens on uninstall? =

The settings, the products' pre-order data and the scheduled jobs are removed, and the products sell as normal products. The pre-order rows on past orders stay: they are part of the order record. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.0 =
* Initial release.
* Pre-order with a release date or "Coming soon" on simple products and variations, with an optional text per product; quick edit, bulk edit, list column and filter; "Sale until release".
* Badge, "Pre-order" button and availability text, for classic and block themes; PreOrder structured data (WooCommerce and Rank Math).
* Cart line note, three mixed-cart rules, release date saved on the order lines; orders list column and filter (HPOS and legacy).
* Release day job (Action Scheduler) with `por_released`; "Released" and "New release date" emails (off by default); background update of open orders when the date changes.
* Upcoming releases page with counts and CSV shipping list.
* `[nox_preorders]` and the "Pre-orders" block.
* Shipping Notes delivery estimate from the release date; Product Formats format column.
* Public function `por_get_release()`, filters `por_is_preorder` and `por_text`.
* Editable Greek / English texts, date format, settings export / import.
* Test mode, rival plugin rule, POR_DISABLE, HPOS compatible.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.10).
