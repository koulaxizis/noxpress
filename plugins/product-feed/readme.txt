=== Product Feed ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: product feed, google merchant center, skroutz, bestprice, facebook catalog
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Product feeds for Google Merchant Center, Skroutz, BestPrice and the Meta (Facebook / Instagram) catalog, plus a general CSV: one static file per feed with a fixed URL, rebuilt in the background, with ISBN / GTIN, brand, pre-orders and a check before anything is published.

== Description ==

Price comparison and shopping services read a file with your products. Product Feed writes that file from WooCommerce's own data and keeps it fresh, without an account, a licence or a call to any outside service.

**Channels**

* **Google Merchant Center:** RSS 2.0 with the g: namespace. One item per product, or per variation with item_group_id. Availability in_stock / out_of_stock / preorder / backorder with availability_date, price and sale price with their dates, brand, GTIN (identifier_exists «no» when there is none), product_type from your category path and an optional Google product category per category.
* **Skroutz:** the Skroutz XML (mywebstore). Price with VAT, VAT rate, availability from the four accepted texts (one per feed, per category, and one for pre-orders / back-orders), manufacturer, MPN (the ISBN for books), EAN, quantity, weight, colour, sizes and size variations. Items without stock never go in: Skroutz has no «out of stock» value.
* **BestPrice:** the BestPrice XML (store / products / product). Check the field names against the XML guide in your BestPrice merchant panel before you submit the feed; the admin page says so.
* **Meta catalog (Facebook / Instagram):** RSS 2.0 XML with Meta's values (in stock / out of stock, condition, quantity to sell).
* **CSV:** the same fields in UTF-8 with BOM, comma and decimal point, with the formula guard on every text cell.

**Data from your shop**

* GTIN / ISBN from WooCommerce's own GTIN field (as Search Signals and Trade Accounts), or a meta key of your choice; ISBN check digits are verified.
* Brand from WooCommerce brands (e.g. the publisher of a book), an attribute or a fixed text.
* Real prices with VAT for the store's base location, never a display text of another plugin.
* Pre-orders from Pre-Orders (preorder with the release date), creators from Featured Creators (CSV author column), categories hidden by Hide Catalog left out (switch per feed), Book Feed imports in or out per feed (they go in even when Hide Catalog hides them from the shop).
* Downloadable and virtual products are left out by default: Google does not accept digital books in Shopping ads.

**Building**

* In the background with WooCommerce's Action Scheduler, 100 products per batch. The new file is written next to the old one and replaces it in one step, so a service never reads half a feed; a failed build keeps the previous file.
* Every 1, 2, 3, 6, 12 or 24 hours, «Refresh now», and optionally 10 minutes after a change of price, stock or a product (one rebuild for a whole bulk edit).
* Files in uploads/product-feed/<random folder>/: the web server serves them without loading WordPress. A new random folder is one click away.
* «Also write to this path»: a copy at another path inside uploads, e.g. the old file of another feed plugin, so a service keeps reading the same URL. Set it only after the other plugin is switched off.

**Check before you publish**

* For every build: how many products went in, how many stayed out and why, warnings per kind (no GTIN, wrong check digit, no brand, no description, long title, image without https…) and the first 300 products with an issue, each a link to its edit screen.
* «Leave out products with a serious error» (no price, image or title), so the service does not reject the feed.
* Preview of the first 20 items, or of one product, built on the spot with the current settings, nothing written.

**Moving from CTX Feed**

* «Import» reads the CTX Feed (WebAppick) feeds once and makes one feed each with the same filters, excluded tags and UTM values. CTX Feed is not changed. The message names the old file path, to set as «Also write to this path» once CTX Feed is off.

**Safe by design**

* No front end. A stock change during a checkout only queues one debounced rebuild (at most one per 10 minutes).
* Every change needs `manage_woocommerce`, a nonce and server-side validation; paths are whitelisted and never leave uploads/.
* `define( 'PFD_DISABLE', true );` in wp-config.php stops every build; existing files stay.
* Settings backup as JSON (the feeds and their settings, not the folder name).
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `pfd_get_feeds(): array`: the feeds of the site (name, channel, url, active, finished, count).
* Action `pfd_feed_built` ( string $id, array $status ): after every finished build.
* Listens to `noxpress_products_changed`.

== Installation ==

1. Install from the Noxpress hub (Noxpress → Overview) or upload the zip in Plugins → Add New → Upload.
2. Activate. WooCommerce is required.
3. Noxpress → Product Feed → New feed, pick the channel, save, then «Refresh now».
4. Copy the feed URL into Merchant Center, Skroutz, BestPrice or Meta Commerce Manager.

== Frequently Asked Questions ==

= Why are my ebooks not in the Google feed? =

Google does not accept digital books in Shopping ads. Downloadable and virtual products stay out unless you tick «Include downloadable and virtual products».

= Can the new feed keep the URL my old plugin used? =

Yes: switch the old plugin off, then put its file path (relative to uploads) in «Also write to this path». The copy is replaced in one step, like the feed itself.

== Changelog ==

= 1.0.1 =
* Core 1.4.0: the Noxpress hub can install suite plugins that were released after this copy of Core (read from the signed version list; a plugin counts as ours only with our Update URI).

= 1.0.0 =
* New: feeds for Google Merchant Center, Skroutz, BestPrice, the Meta catalog and a general CSV.
* New: background builds in batches with atomic replace, schedule, «Refresh now» and a debounced rebuild after changes.
* New: GTIN / ISBN, brand, pre-orders, Hide Catalog and Book Feed cooperation; digital products out by default.
* New: build report, preview, legacy path copy, CTX Feed import, settings backup.
