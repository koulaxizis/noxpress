=== Easy Withdrawal ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, withdrawal, right of withdrawal, eu directive 2023/2673, returns
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

The online withdrawal function for WooCommerce (EU Directive 2023/2673): a form for customers and guests, item and quantity selection, two-step confirmation, an email receipt and a request list in the admin.

== Description ==

From 19 June 2026, online shops that sell to EU consumers must offer a withdrawal function: clearly labelled, easy to find, available for the whole withdrawal period, with a confirmation step and an acknowledgement of receipt on a durable medium. Easy Withdrawal adds it to any WooCommerce store.

**For customers and guests**

* A form on any page with the shortcode `[nox_withdrawal]`, in four steps: find the order (order number + billing email, or a list of the customer's own orders), choose products and quantities, review the statement and press "Confirm withdrawal", read the receipt.
* The statement is recorded the moment it is confirmed, with date and time, and the receipt goes at once to the order's billing email. No email verification step that could push a last-day statement past the deadline, and no way to send email to third parties.
* Links that skip the lookup: in the customer's order emails, on the thank-you page (both with the order key) and in My Account (orders list and order view, with the status of earlier requests).
* Products that cannot be returned are shown dimmed with the reason.
* Partial withdrawals: per product and per quantity, several requests per order.

**Withdrawal period**

* Open from the moment the order is placed (processing, on hold, completed).
* Goods: closes the withdrawal days (14 by default) plus delivery days (7 by default) after the order is completed, because the period runs from delivery, which the store does not know. Pre-orders and late shipments never close early.
* Virtual items: the withdrawal days from payment.

**Exclusions**

* "Personalised" flag on each product.
* Excluded categories (with their subcategories) and products, for example sealed hygiene goods.
* Digital content: an optional consent checkbox at checkout (classic checkout and checkout block, WooCommerce 9.9+). Digital items are excluded only in orders where the customer gave the consent; without it they stay returnable, as the Directive requires.

**For the store**

* Noxpress → Withdrawals: list with status filter, search by order number, request id or email, paging and CSV export; a new-requests badge in the menu.
* Statuses that describe the return, not an approval: New, In progress, Completed, Disputed. A withdrawal is the consumer's right: the store records what happens next. "Completed" and "Disputed" (with a required note) email the customer.
* A refund button that opens the order with WooCommerce's refund form already filled in with the request's quantities. You check the amounts and shipping and refund as usual.
* Order notes on every statement and status change, and a metabox on the order screen.
* Three WooCommerce emails (receipt, store notice, status change) in WooCommerce → Settings → Emails, with the store's template; theme overrides in `woocommerce/emails/`.
* No custom order statuses: revenue reports (WooCommerce, Revenue Splitter, Store Pulse) stay correct.

**Safe by design**

* Test mode by default: the form, the buttons, the links and the checkout consent are visible to shop managers only until you switch to live.
* `define( 'EWD_DISABLE', true );` in wp-config.php stops every front-end hook.
* The form page is never cached (works with WP Rocket, LiteSpeed Cache and others). Steps travel in a signed token; nothing is written before the confirmation. Failed lookups are limited per IP (5 per 15 minutes, IP stored only as a hash).
* HPOS and the legacy order storage are both supported (CRUD only).
* Bilingual (Greek / English): the admin follows the Noxpress language choice, the form follows the page language (WPML, Polylang and TranslatePress included), each customer email is sent in the language the customer used.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

This plugin implements the technical requirements as we read them. It is not legal advice.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `easy-withdrawal` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → EWD Settings**: press "Create a page with the shortcode" (or choose a page that holds `[nox_withdrawal]`), write the return instructions and check the period and the exclusions.
5. Put a link to the page in the site's menu or footer.
6. Test with an order while in test mode, then switch to **live**.

== Frequently Asked Questions ==

= I used another withdrawal plugin. =

Deactivate it and replace its shortcode on the withdrawal page with `[nox_withdrawal]` (or create a new page from the settings).

= Does a guest need an account? =

No. Guests enter the order number and the billing email, or follow the link in their order email.

= Does the plugin refund automatically? =

No. Version 1.0 opens WooCommerce's refund form pre-filled; you confirm the refund.

= What happens on uninstall? =

The settings, the email settings, the transients and the "personalised" flags are removed. The requests and the digital-content consent stay in the order meta: they are your record of what customers declared and agreed to, and they go away with their orders.

== Changelog ==

= 1.0.0 =
* Initial release.
* Withdrawal form `[nox_withdrawal]` for customers and guests: order lookup, products and quantities, review with "Confirm withdrawal", receipt page.
* Immediate recording with date and time, receipt to the order's billing email, signed tokens between steps, rate limit, honeypot, no page cache.
* Links in My Account, customer order emails and the thank-you page; optional footer link.
* Withdrawal window from order creation until completion + delivery days + withdrawal days; virtual items from payment.
* Exclusions: personalised products, categories, products, digital content with checkout consent (classic and block checkout).
* Admin: request list with filters, search, paging and CSV; request detail with statuses, notes, history and refund pre-fill; order metabox and notes.
* WooCommerce emails: receipt, store notice, status change, each customer email in the customer's language.
* Test mode, EWD_DISABLE, HPOS compatible, Greek / English.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.0.4).
