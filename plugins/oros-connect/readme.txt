=== orOS Connect ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: oros, newsletter, signup form, woocommerce, offers
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Connects your site to orOS (useoros.online). Modules you switch on one by one: newsletter sign-ups to orOS Newsletter, and Publishing Quote requests as draft offers in orOS.

== Description ==

orOS is a free operating system in your browser: its apps (Newsletter, Stock, CRM, Invoices and more) keep their data on your own devices. Nothing on a website can write into it. orOS Connect hands data to orOS's own channels, and orOS collects it when you open it.

Every module is off after activation. A module that is off loads no code.

**Newsletter module**

* A sign-up form for your list in orOS Newsletter, as a block («Newsletter (orOS)»), a shortcode (`[oros_newsletter]`) or a classic widget.
* Paste the "HTML for your site" that orOS gives (Newsletter → your list → Subscribe link); the plugin keeps only the sign-up address and checks it with one click.
* The form posts straight from the visitor's browser to the orOS relay, like orOS's own HTML: no script, a hidden field against bots, the visitor's language. Nothing is stored on your site, so page caches are fine. orOS sends the confirmation email; the visitor then sees the thank-you page you set in orOS.
* Texts in Greek and English (title, intro, fields, button, note), language automatic from the site or fixed.
* WooCommerce: an unticked "I want to receive the newsletter" checkbox at checkout, in the classic checkout and the Checkout block (WooCommerce 8.9+). When the order is paid, the billing email and name are sent to your orOS list in the background, with retries, an order note and a send log. The consent (time and text) stays in the order.
* Checkout sign-ups can skip the confirmation email (the default) or get one too (double opt-in). Skipping it needs a shop key that orOS will check; until orOS supports it, everyone gets the confirmation email.

**Offers module** (1.1.0)

* The «I'm interested» requests of Publishing Quote become draft offers in orOS: while orOS is open, it collects the new requests, makes a draft offer and a contact from each and marks the request «In orOS». The offer goes to the author only when you send it from orOS.
* Each offer carries the lines the author saw (typesetting already inside), the total, VAT and validity; the full breakdown goes in as an internal note that is never sent to the author.
* orOS connects with the WooCommerce REST keys it already has for the shop (a read key reads; marking needs read/write), or, without WooCommerce, with an Application Password of an administrator. Only managers can read the requests.
* Until orOS imports them, the requests stay in Publishing Quote → Requests and arrive by email: nothing is lost if orOS is closed.

**Coming next**

* Stock ledger: every stock change of the shop in orOS Stock, and changes from orOS as differences.
* Receipt link: the ΜΑΡΚ and the receipt PDF from orOS Invoices in the customer's account.
* Inbox: new orders, withdrawals and low stock in orOS Today.

**Safe by design**

* Works with or without WooCommerce (without it: the sign-up form only).
* Test mode by default: only managers see the form and the checkbox until you switch to live.
* `define( 'ORC_DISABLE', true );` in wp-config.php stops the forms, the checkbox, every send and the offers routes.
* Server requests only with `wp_safe_remote_post`, no redirects followed. No cookies, no tracking.
* Suggested text for your privacy policy (Settings → Privacy).
* Settings export / import (without the shop key).
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `orc_text` filter ( string $text, string $key, string $lang ): change a visitor text (keys title, intro, email, name, button, note, checkbox).
* REST `GET /wp-json/orc/v1/offers` (state=new|contact|oros|closed|all, limit, page), `GET /wp-json/orc/v1/offers/<id>` and `POST /wp-json/orc/v1/offers/<id>` ( { state, note } ), managers only.
* `orc_newsletter_sent` action ( int $order_id, string $result ): after each checkout sign-up attempt (`ok`, `bad`, `gone`, `busy`, `off`, `error`, `network`).

== Installation ==

1. Upload the `oros-connect` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
2. Activate the plugin and open **Noxpress → orOS Connect**.
3. In **Modules**, switch Newsletter on.
4. In orOS: Newsletter → your list → Subscribe link → turn the sign-up form on, set a thank-you page and copy the HTML.
5. In the **Newsletter** tab, paste it, press **Check connection** and adjust the texts. Turn on the checkout checkbox if you want it.
6. Add the block, the shortcode or the widget; check it as a manager, then switch the mode to live.

== Frequently Asked Questions ==

= Where are the subscribers kept? =

In orOS, on your own devices. The orOS relay keeps a new sign-up encrypted for your list until your orOS collects it, then deletes it. The plugin stores no subscriber list.

= Why do subscribers from the checkout get a confirmation email? =

Until orOS can check the shop key, it cannot tell a checkout sign-up from the public form, so it confirms everyone. Turn on "Confirmation email for checkout sign-ups too" if you want that to stay so.

= What happens on uninstall? =

The settings, the send log, queued sends, the last offers import and the transients are removed. The Publishing Quote requests belong to Publishing Quote and stay. The newsletter consent stays in the orders, as the shop's record, and goes with each order. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.2.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.1.1 =
* Fixed: the Offers status said "(1 requests)" for a single request; it now says "(1 request)" ("(1 αίτημα)" in Greek).

= 1.1.0 =
* New module «Offers»: Publishing Quote requests for orOS to import as draft offers (REST orc/v1/offers), with the shop's WooCommerce REST keys or an Application Password; waiting requests and the last import in the admin.
* Noxpress Core 1.1.11.

= 1.0.0 =
* Initial release, with modules switched on one by one.
* Newsletter module: sign-up form (block, shortcode, widget) that posts to the list's orOS sign-up address, connection check, Greek and English texts.
* Unticked checkout checkbox (classic and block checkout); paid orders sent to orOS in the background with retries, order notes and a send log; consent kept in the order; optional confirmation for checkout sign-ups and a shop key that signs them.
* Test / live / off mode, ORC_DISABLE, privacy policy text, settings export / import.
* Works without WooCommerce. Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.8).
