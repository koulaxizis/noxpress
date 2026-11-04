=== orOS Connect ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: oros, newsletter, signup form, woocommerce, offers
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
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
* Checkout sign-ups can skip the confirmation email (the default) or get one too (double opt-in). Skipping it needs a shop key: the same key goes into your list in orOS (Subscribe link → Shop key), which checks each signed sign-up. Without a key, everyone gets the confirmation email.
* Popup (1.3.0, off by default): the same form in a popup, after 20 seconds or half the page (both adjustable), at most once every 30 days per browser and never again after a sign-up. Never on the cart, the checkout, the customer's account or a page that already has the form. The browser remembers it in localStorage: no cookie, nothing on the server, page caches are fine. Its own title and text in Greek and English (empty = the form's), for example to promise the welcome coupon that orOS sends with the welcome email. `?orc_popup=1` opens it at once for testing.

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

* `orc_text` filter ( string $text, string $key, string $lang ): change a visitor text (keys title, intro, email, name, button, note, checkbox, pop_title, pop_intro, close).
* `orc_popup_show` filter ( bool $show ): hide the popup on a page.
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

Without the same shop key here and in your orOS list, orOS cannot tell a checkout sign-up from the public form, so it confirms everyone. Set the key on both sides (orOS from November 2026), or turn on "Confirmation email for checkout sign-ups too" if you want everyone to confirm.

= What happens on uninstall? =

The settings, the send log, queued sends, the last offers import and the transients are removed. The Publishing Quote requests belong to Publishing Quote and stay. The newsletter consent stays in the orders, as the shop's record, and goes with each order. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.3.0 =
* Newsletter: the form in a popup too (off by default). Opens after 20 seconds or half the page, at most once every 30 days per browser and never after a sign-up; not on the cart, the checkout, the account pages or a page that has the form. localStorage only, no cookie; the same HTML for every visitor. Its own title, text and close label in Greek and English; filter `orc_popup_show`; `?orc_popup=1` to test.
* Newsletter: the shop key is now checked by orOS (Subscribe link → Shop key), so signed checkout sign-ups join without a confirmation email; texts updated.
* Privacy policy text mentions the popup's localStorage entry.

= 1.2.1 =
* Noxpress Core 1.2.8: a use-at-your-own-risk notice on the Noxpress hub page, and a Disclaimer section in this readme.

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
