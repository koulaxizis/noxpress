=== orOS Connect ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: oros, newsletter, signup form, woocommerce, checkout
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Connects your site to orOS (useoros.online). Modules you switch on one by one; the first one sends new newsletter subscribers to your list in orOS Newsletter.

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

**Coming next**

* Stock ledger: every stock change of the shop in orOS Stock, and changes from orOS as differences.
* Receipt link: the ΜΑΡΚ and the receipt PDF from orOS Invoices in the customer's account.
* Inbox: new orders, withdrawals and low stock in orOS Today.

**Safe by design**

* Works with or without WooCommerce (without it: the sign-up form only).
* Test mode by default: only managers see the form and the checkbox until you switch to live.
* `define( 'ORC_DISABLE', true );` in wp-config.php stops the forms, the checkbox and every send.
* Server requests only with `wp_safe_remote_post`, no redirects followed. No cookies, no tracking.
* Suggested text for your privacy policy (Settings → Privacy).
* Settings export / import (without the shop key).
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `orc_text` filter ( string $text, string $key, string $lang ): change a visitor text (keys title, intro, email, name, button, note, checkbox).
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

The settings, the send log, queued sends and the transients are removed. The newsletter consent stays in the orders, as the shop's record, and goes with each order. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.0 =
* Initial release, with modules switched on one by one.
* Newsletter module: sign-up form (block, shortcode, widget) that posts to the list's orOS sign-up address, connection check, Greek and English texts.
* Unticked checkout checkbox (classic and block checkout); paid orders sent to orOS in the background with retries, order notes and a send log; consent kept in the order; optional confirmation for checkout sign-ups and a shop key that signs them.
* Test / live / off mode, ORC_DISABLE, privacy policy text, settings export / import.
* Works without WooCommerce. Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.8).
