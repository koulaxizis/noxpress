=== Checkout Extras ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, gift, thank you page, coupon, checkout
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A gift option with a message at checkout, a message and products on the thank-you page, and a unique thank-you coupon for the next order. Each part is turned on separately.

== Description ==

Three small things around the checkout, in one plugin. Each part has its own switch (off, test mode, on for everyone).

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
* Order meta: `_cke_gift`, `_cke_gift_message`, `_cke_gift_fee`, `_cke_coupon_code`, `_cke_redeemed`.

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

= What happens on uninstall? =

The settings, the transients and the background jobs are removed. The gift and the coupon code on past orders stay: they are part of the order record. The coupons themselves stay too, because customers hold the codes, unless you tick "Delete the thank-you coupons … when the plugin is deleted" in Tools. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

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
