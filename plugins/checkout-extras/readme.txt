=== Checkout Extras ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, gift, thank you page, coupon, vacation mode
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: MIT
License URI: https://opensource.org/licenses/MIT

A gift option with a message at checkout, a message and products on the thank-you page, a unique thank-you coupon for the next order, and a store vacation mode. Each part is turned on separately.

== Description ==

Four small things around the checkout, in one plugin. Each part has its own switch (off, test mode, on for everyone).

**"This is a gift" at checkout**

* A checkbox at checkout, in one of three positions (before the customer details, after the order notes, before the "Place order" button).
* An optional gift message: off, optional or required, with a length limit and a live character counter.
* An optional gift wrapping fee (fixed amount, taxable or not, with a tax class), added as a fee line on the order.
* Optionally only when the cart has a product that ships.
* On the order: a "Gift" row in the order totals (thank-you page, My account, emails), a meta box on the admin order screen, a 🎁 column in the orders list with a "Gifts only" filter, and a "🎁 Gift" line with the message at the top of the admin New order email.

**Thank-you page**

* Your own message (Greek and English), one for paid orders and one for orders awaiting payment, with placeholders such as `{first_name}` and `{order_number}`. It can take the place of WooCommerce's "Thank you. Your order has been received." line or sit in its own box above it.
* Optional products after the order details: cross-sells of the ordered products, related products or products on sale, shown with your theme's product cards. Products the customer just bought and out-of-stock products are left out.
* Works with the classic thank-you page and the Order confirmation of block themes, with no change to the layout.

**Thank-you coupon**

* A unique, single-use WooCommerce coupon for the customer's next order, created when the order reaches a status you choose (processing and completed by default). Never on unpaid, failed or cancelled orders.
* Rules: minimum order total, not on orders that already used a thank-you coupon, one active coupon per email, days between two coupons, first order only, no coupon for orders with products from chosen categories only.
* The coupon: percentage or fixed amount, free shipping, expiry in days, minimum and maximum spend, individual use, no sale items, a limit of items, product and category restrictions, restricted to the order's billing email, your own code prefix.
* Delivery: always on the thank-you page with a copy button, plus inside the order email WooCommerce already sends, or in a separate "Thank-you coupon" email, or not by email. Orders awaiting payment show "your coupon comes by email" instead.
* An optional reminder email a few days before an unused coupon expires.
* Statistics by period: coupons issued and used, revenue of the orders that used one, total discount, and the latest coupons with their state.
* A daily cleanup deletes expired unused thank-you coupons after the days you choose. Only coupons this plugin made are touched; the statistics stay, because they are read from the orders.

**Store vacation**

* The site stays open and indexable while buying stops: no buy button on the product pages, new items are refused by the cart, and the cart and checkout show an error and take no order. This holds in the classic checkout, the Cart and Checkout blocks and the Store API, also for a request sent by hand.
* Or "orders accepted, shipped after the vacation": buying stays open, a bar says when orders ship, the checkout asks for a required checkbox, and the order gets a "Shipping: after the vacation" row (thank-you page, account, emails, admin). With Shipping Notes the delivery estimate starts at the end date.
* Optional start and end (site time zone), decided on every request, so the shop opens exactly at the end; an optional warning bar some days before the start; "Close now" and "Open now" buttons; a reminder in the admin bar.
* A bar on every page, and a note in place of the buy button. Greek / English texts with `{return_date}` and `{start_date}`.
* Exemptions while closed: digital products (virtual and downloadable), Noxpress Pre-Orders products, chosen user roles (e.g. trade customers), and the `cke_vacation_allow` filter.
* Never closed: paying an existing order, payment gateway callbacks, orders from WP admin or the authenticated REST API, the account pages and downloads. Prices, structured data and feeds do not change. Items already in a cart stay there.
* Every change purges the page cache (Fast Pages, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache), and background jobs purge it again at the warning, the start and the end.

**Classic and block checkout**

* The classic checkout (`[woocommerce_checkout]`) gets every option.
* The Checkout block gets the gift checkbox and message as WooCommerce additional checkout fields (WooCommerce 9.9 or newer for the conditional message field). A fee cannot follow a field in the block without session writes, so with a gift wrapping fee the gift option is offered in the classic checkout only; the settings page warns you when your checkout is the block.

**Safe by design**

* Every part is off after activation, with a test mode that shows it to shop managers only (and issues coupons only for their orders).
* `define( 'CKE_DISABLE', true );` in wp-config.php stops every checkout and thank-you page hook and the coupon issue.
* No cookies, no session writes, no external requests, no data about visitors. HPOS compatible.
* Editable Greek / English customer texts and settings export / import.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `cke_get_gift( $order )`: the gift of an order (`gift`, `message`, `fee`), or `null` when the order is not a gift.
* `cke_coupon_args` ( array $args, WC_Order $order ): the coupon's properties before it is created; return `false` for no coupon.
* `cke_coupon_created` ( WC_Coupon $coupon, WC_Order $order ): fires after a thank-you coupon is created.
* `cke_text` ( string $text, string $key, string $lang ): change any customer text.
* `cke_vacation_allow` ( bool $allow, WC_Product $product ): return `true` to keep a product on sale while the shop is closed for the vacation.
* Abilities (WordPress 6.9+, capability `manage_woocommerce`): `checkout-extras/vacation-status` and `checkout-extras/vacation-set`.
* Order meta: `_cke_gift`, `_cke_gift_message`, `_cke_gift_fee`, `_cke_coupon_code`, `_cke_redeemed`, `_cke_vacation_until`.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `checkout-extras` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Checkout** and set up the parts you want, one tab each.
5. Try each part in test mode with a shop manager account, then switch it to **on for everyone**.

== Frequently Asked Questions ==

= I used "Gift Wrapping for WooCommerce". How do I move? =

Turn on the gift part with the same label and cost, test it, then switch it to live and deactivate the old plugin. Past orders keep what the old plugin saved on them; new orders get the Checkout Extras gift row.

= Why are coupons created only on paid orders? =

A coupon on a pending order is a coupon for an order that may never be paid. By default coupons are created on processing and completed orders. "On hold" (bank transfer, cash on delivery) is available but off.

= Why does the gift option not show in my Checkout block? =

You have set a gift wrapping fee. The block cannot add a fee from a field without writing to the session, so the option is shown there only without a cost. Use the classic checkout shortcode for a paid gift option.

= What does the store vacation close, exactly? =

New purchases by visitors. A customer can still pay an order that already exists (for example a payment link you sent), payment gateways can still confirm payments, and you can still create orders in WP admin. The pages stay online with their prices, so search engines see no change.

= Will my cache show the old buttons? =

The rule itself runs on every request and the cart and checkout are never cached, so no order gets through. Cached pages are purged on every change and at the start and end times; if a background job runs late, a cached page may show the old look for a short while.

= What happens on uninstall? =

The settings, the transients and the background jobs are removed. The gift and the coupon code on past orders stay: they are part of the order record. The coupons themselves stay too, because customers hold the codes, unless you tick "Delete the thank-you coupons … when the plugin is deleted" in Tools. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.2.2 =
* Core 1.4.8: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.2.1 =
* Core 1.4.0: the Noxpress hub can install suite plugins that were released after this copy of Core (read from the signed version list; a plugin counts as ours only with our Update URI).

= 1.2.0 =
* New: store vacation. The site stays open while buying stops (no buy button, cart and checkout refuse orders, also in the Cart / Checkout blocks and the Store API), or orders are accepted and shipped after the vacation (required checkbox at checkout, note on the order, Shipping Notes estimate from the end date). Optional start and end that open and close the shop by themselves, a warning bar before the start, Close now / Open now, exemptions for digital products, Pre-Orders products and chosen roles, page cache purge on every change and at the change times, an admin bar reminder, and the abilities checkout-extras/vacation-status and vacation-set. Off after the update.

= 1.1.2 =
* Security hardening (NXS-21): Noxpress Core 1.3.0. The update list is now signed together with each release's channel, a serial and an expiry date, so a site refuses an old, expired or altered list, a beta presented as stable, and a package older than the installed version; the hub says why. New shared security helpers. Update recommended.

= 1.1.1 =
* Noxpress Core 1.2.8: a use-at-your-own-risk notice on the Noxpress hub page, and a Disclaimer section in this readme.

= 1.1.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.0.0 =
* Initial release.
* "This is a gift" at checkout (classic and block), with an optional, required or no message, an optional wrapping fee, three positions and a shippable-only rule.
* Gift on the order: totals row, admin meta box, orders list column and "Gifts only" filter, admin New order email line; public function `cke_get_gift()`.
* Thank-you page message (paid / awaiting payment) with placeholders, in its own box or in place of WooCommerce's line, and cross-sell, related or sale products with the theme's cards.
* Thank-you coupon: issue rules, coupon properties, delivery in the order email or a separate WooCommerce email, copy button, reminder email, statistics, daily cleanup of expired unused coupons; filters `cke_coupon_args`, `cke_text`, action `cke_coupon_created`.
* Editable Greek / English texts, settings export / import.
* Test mode, CKE_DISABLE, HPOS compatible.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.13).
