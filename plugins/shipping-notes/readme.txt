=== Shipping Notes ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, free shipping, progress bar, delivery time, printful
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Two messages for your customers: how much is left for free shipping (with a progress bar) in the cart, mini-cart and checkout, and how many business days delivery takes on the product page.

== Description ==

**Free shipping progress**

* "€15.00 left for free shipping" with a progress bar, and "You've got free shipping!" once the cart qualifies. An optional message for an empty cart.
* The threshold comes from WooCommerce itself: the "Free shipping" method of the customer's shipping zone (minimum order amount, or "minimum amount or coupon"). You can also set a fixed amount.
* The amount is counted exactly like WooCommerce counts it: coupons, tax display and the method's "apply minimum before coupon discount" option. The "won" state is confirmed by WooCommerce's own method, so the message never promises what checkout will not give.
* Hidden for carts that need no shipping (virtual or downloadable products only).
* Classic cart, mini-cart and checkout, and the Cart and Checkout blocks. Refreshes with WooCommerce's own cart fragments and the Store API; the plugin adds no AJAX endpoints of its own.

**Delivery time**

* "Delivery in 2–4 business days" or a list per shipping method ("BOX NOW: 1–2 business days", "ACS: 2–3 business days") on the product page.
* Delivery days per shipping method of one zone, plus preparation days per store, per product category (subcategories included) or per product, for example production time. Separate preparation days for products on backorder.
* Pre-orders: an "available from" date on the product. Delivery counts from that date.
* Business-day calendar: dispatch days, a cut-off time for same-day dispatch, Saturday delivery per method, and a holiday list. One button adds the Greek public holidays, including the movable ones of Orthodox Easter, computed locally.
* Optional dates instead of days ("Delivery Tue 13/10 – Thu 15/10") and an "Order within 3 h 20 min to ship today" line. Dates and the countdown are computed in the visitor's browser in the store's timezone, so pages served from a page cache stay correct.

**Parcels (mixed carts)**

* When a plugin such as Printful splits the cart into a parcel from the production partner and a parcel from your store, WooCommerce shows two "Shipping" rows with two charges and no explanation. Shipping Notes names them ("Parcel 1 · Made to order (from our production partner)", "Parcel 2 · From our store") and shows a short "two parcels" message above the shipping rows of the classic cart and checkout.
* The partner's rate labels in Greek: "Flat Rate (Estimated delivery: Oct 20–22)" becomes "Τυπική αποστολή (Εκτιμώμενη παράδοση: 20–22 Οκτ)", also across months ("30 Οκτ – 2 Νοε"). The word list is editable. Only what the customer sees changes: the order keeps the partner's own label, so the partner's order import is not affected.
* The order lists its parcels, each with its method and cost, on the thank-you page, in My account and in the customer emails (HTML and plain text). Each shipping line keeps its parcel name, visible in the admin order screen.
* Optional line under the price of partner products ("Printed to order · ships separately from the other products").
* Partner parcels are recognised from the Printful plugin's own parcel, or from the WooCommerce shipping classes you choose. Off by default, with its own test mode.

**Placement**

* Shortcodes `[nox_free_shipping]` and `[nox_delivery]` (or `[nox_delivery id="123"]` for a specific product).
* Blocks "Free shipping (Shipping Notes)" and "Delivery time (Shipping Notes)".
* Works with classic themes (Storefront, Astra and others) and block themes (Twenty Twenty-Five).

**Texts**

* Every customer text is editable in Greek and English, with placeholders such as `{remaining}`, `{days}`, `{method}` and `{dates}`. The language follows the page language or a fixed choice.

**Safe by design**

* Off by default, with a test mode that shows the messages to shop managers only.
* `define( 'SHN_DISABLE', true );` in wp-config.php stops every front-end hook.
* No cookies, no external requests, no data about visitors.
* HPOS compatible, Cart and Checkout blocks compatible.
* A preview tab that shows the messages for any amount, zone or product, and settings export / import.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `shn_threshold` ( float $threshold, WC_Shipping_Zone $zone ): change the free shipping threshold.
* `shn_available_from` ( string $date, WC_Product $product ): a release date (`YYYY-MM-DD`) for pre-order plugins.
* `shn_text` ( string $text, string $key, string $lang ): change any customer text.
* `shn_partner_package` ( bool $partner, array $package ), `shn_partner_rate` ( bool $partner, string $method_id ) and `shn_partner_product` ( bool $partner, WC_Product $product ): which parcels, rates and products belong to the partner.
* The cart data is also available to Store API clients under the `shipping-notes` extension key (read-only).

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `shipping-notes` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Shipping**. On the "Free shipping" tab check the threshold the plugin found in your zones and choose where the bar appears.
5. On the "Delivery time" tab enter the business days of each shipping method, the preparation days and the cut-off time.
6. Check the "Preview" tab, test with test mode, then switch to **live**.

== Frequently Asked Questions ==

= Where does the free shipping threshold come from? =

From the "Free shipping" method of the customer's shipping zone in WooCommerce → Settings → Shipping. If a zone has several, the lowest minimum counts. Methods that need a coupon only are skipped. You can set a fixed amount instead.

= Which zone is used before the customer enters an address? =

The zone WooCommerce matches for the customer's address, which before checkout is usually the store's base address or the geolocated country.

= Why does the product page use one zone? =

So that a page cache shows the same, correct text to every visitor. Choose the zone in the "Delivery time" tab.

= Does it slow down the cart? =

No. The message rides along WooCommerce's own fragment and Store API responses.

= What happens on uninstall? =

The settings, the transients and the preparation days and release dates on products and categories are removed. The parcel name saved on the shipping lines of past orders stays: it is part of the order record. The shared Noxpress options stay while another Noxpress plugin is installed.

= Does it work with the Checkout block? =

The free shipping bar does. The parcel names, the "two parcels" message and the Greek partner labels are for the classic cart and checkout (`[woocommerce_cart]`, `[woocommerce_checkout]`); the order list on the thank-you page and in the emails works with both.

== Changelog ==

= 1.1.1 =
* Changed: with Pre-Orders active, the product's "Available from" field gives way to the Pre-Orders release date (a short line in the Shipping tab). A date already saved stays and can still be edited or cleared.
* Fixed: saving a product without the "Available from" field on the form no longer clears a saved date.

= 1.1.0 =
* New: Parcels tab for carts split into several shipping packages (e.g. by the Printful plugin): parcel names, a "two parcels" message with a coloured left border, the partner's rate labels and dates in Greek (display only), the parcel list in the order (thank-you page, My account, customer emails) and an optional product-page line. Off by default.
* New: filters `shn_partner_package`, `shn_partner_rate`, `shn_partner_product`.

= 1.0.0 =
* Initial release.
* Free shipping progress bar in the classic cart, mini-cart and checkout and in the Cart and Checkout blocks; threshold from the WooCommerce shipping zones or fixed.
* Delivery time on the product page per shipping method, with preparation days per store, category and product, backorders, pre-orders, cut-off time, Saturday delivery and holidays (Greek holidays button).
* Optional dates and cut-off countdown computed in the browser for page-cache safety.
* Shortcodes `[nox_free_shipping]`, `[nox_delivery]` and two blocks.
* Editable Greek / English texts, preview tab, settings export / import.
* Test mode, SHN_DISABLE, HPOS and Cart / Checkout blocks compatible.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.1).
