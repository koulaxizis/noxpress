=== Trade Feed Import ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: bookstores, wholesale, import, sync, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

For bookshops: a publisher's titles in your own WooCommerce shop, from the publisher's Trade Feed (Trade Accounts). Paste the publisher's e-mail, press one button, and the books come in with cover, ISBN, authors, category and retail price; price, availability and pre-orders then update by themselves every day.

== Description ==

A publisher that runs Trade Accounts gives each bookshop a trade account and a Trade Feed: a catalogue with retail and trade prices, availability and pre-orders. Trade Feed Import is the bookshop's side of it. It is made for shop owners without technical knowledge: there is nothing to configure before the first import.

**Set up in three steps**

1. Paste the publisher's «Trade Feed access» e-mail as it came. The address, the username and the password are read from it and checked at once (or type the three fields by hand).
2. Choose where the books go (a new category with the publisher's name, or one you already have) and whether customers see them at once or as drafts first. Press «Bring all the titles».
3. Done: a summary and a link to the new products.

**What comes in**

* Simple products with title, description, short description, cover (downloaded once, reused when the same image is already in the library), ISBN as GTIN and SKU (when no other product has it), contributors and the other attributes (as global attributes when your shop has the same attribute, otherwise as product attributes), dimensions and weight (converted to your units), the publisher as a brand, your category and tax class.
* A book you already sell with the same ISBN is never duplicated: in the first import it is linked, so only its price, availability and pre-order follow the publisher, and its content stays yours.

**Every day**

* At the hour you choose (default 05:00, your time zone), in the background (Action Scheduler).
* The retail price follows the publisher (fixed book price). Your sale price is never touched.
* Availability follows the publisher, unless you count your own stock for the book: then it is never touched.
* Pre-orders: with the Noxpress Pre-Orders plugin the release date goes into its fields; without it the book is put on backorder and the date is kept.
* The trade price is kept on the product and, where WooCommerce's Cost of Goods field is on, written as the cost while the cost is still the one the plugin wrote.
* Title, descriptions, cover, attributes and dimensions follow the publisher only while they are what the plugin wrote. Change one by hand and it is locked: the sync leaves it as it is, and the product page and the Titles tab say so. «Take everything from the publisher again» unlocks.
* A title the publisher no longer offers becomes out of stock (or a draft, as you choose). A day when the publisher lists nothing at all changes nothing.
* New titles of the publisher: listed in the Titles tab to import with one click, or imported by themselves (setting, off by default).
* The publisher's rate limit is respected: after a 429 the job waits for its Retry-After.

**Safe by design**

* The password is kept sealed (AES-256-GCM with your site's keys) and is never shown, logged or exported. Calls are HTTPS only, with no redirects.
* `define( 'TFI_DISABLE', true );` in wp-config.php stops every call and the sync.
* Nothing on the front end: visitors see ordinary WooCommerce products.
* A history of the last 300 imports, updates, locks and syncs, without personal data.
* Settings export / import (without the password).
* Uninstall keeps your products unless you tick «delete the products it brought»; linked products are never deleted.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `tfi_get_source( int $product_id )`: where a product came from (publisher host, remote id, ISBN, last sync, linked, gone), or null.
* Fires `noxpress_products_changed( int[] $ids )` after each import or sync step that changed products.

== Installation ==

1. Upload the `trade-feed-import` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New). WooCommerce is required.
2. Activate the plugin and open **Noxpress → Trade Feed Import**.
3. Follow the three steps on the page.

== Frequently Asked Questions ==

= Where do I find the username and password? =

In the e-mail «Trade Feed access» the publisher sent you. If you do not have it, ask the publisher for a new password.

= Will the plugin change my own prices? =

The retail price of the publisher's books follows the publisher, because books have a fixed price. Your sale price, and every product that does not come from the publisher, are never touched.

= I changed a description. Will tomorrow's sync undo it? =

No. A field you change by hand is locked and stays as you wrote it.

== Changelog ==

= 1.0.1 =
* Fix: a publisher without pretty permalinks (feed address with ?rest_route=) now connects; the plugin tries the other address form by itself and keeps the one that works.
* Messages with a number now use the singular for one ("1 book", "1 title").
* The first step's button now reads «Connect to the publisher», and the «start again» link takes the plugin's link colour.
* Tested end to end on WordPress 7.1.4 with WooCommerce 11.2.1 against Trade Accounts 1.4.1: connection by pasting the e-mail, first import, linking by ISBN, daily sync of price, availability, pre-orders and cost, locks, titles that leave the feed.

= 1.0.0 =
* First release: a three-step set-up guide that reads the connection details from the publisher's e-mail, import of every title (or the ones you pick) as simple products with cover, ISBN, contributors, attributes, category, brand, tax class and retail price, linking of books the shop already sells, a daily background sync of price, availability, pre-orders and trade price with field locks for hand edits, a titles tab, a history, settings backup and TFI_DISABLE.
