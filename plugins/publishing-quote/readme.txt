=== Publishing Quote ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: publishing, quote, calculator, books, authors
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A publishing cost calculator for prospective authors. The visitor sees the amount of each service and a total; your rates stay on the server.

== Description ==

For publishers, self-publishing services and print shops. The visitor picks what their book needs and gets a quote on the spot. With «I'm interested» they leave their contact details and the request reaches you, with what they chose and what they saw. The offer goes out only when you send it.

**What the visitor picks**

* Book size (e.g. 14×21, 17×24), manuscript pages (A4) or words, or the book pages when the text is already typeset.
* Editing, with the kinds you define (e.g. proofreading, language editing, full editing).
* Cover design (with an extra for hardcover).
* Interior illustration: number of drawings, black and white or colour, with discount tiers.
* Printing: print run, body paper, black and white or colour pages, binding (with page limits and hardcover), cover finishes (one basic, e.g. lamination, plus add-ons, e.g. flaps), with print run tiers.
* Extras you define: fixed (e.g. book presentation, ISBN, e-book), per item (e.g. t-shirts), per copy of the print run (e.g. bookmarks), per word (e.g. translation), or «on request» without an amount (e.g. ad campaign, shipping, bookstore distribution).

**What the visitor sees**

* One line per service with its final amount, and the total. Never the rates, the formula or a breakdown.
* Typesetting as «Included»: its cost is spread inside the services you choose, so the total is always the sum of the lines.
* An estimate of the book pages from the words and the size, rounded up to whole signatures.
* Prices with VAT (for individuals) or without VAT and the VAT per rate at the end (for businesses), a per-copy price when printing, and a «valid until» date.

**Your rates stay yours**

* The calculation runs only on the server. The page holds the option labels; the browser sends the choices and gets back formatted amounts.
* Every line is rounded on its own to a step you set (e.g. to the euro or to five euros), up or to the nearest, which also makes it harder to work the rates out by trying values.
* Minimum charges per service, VAT per service or per extra.

**Requests**

* «I'm interested» opens a short form (name, email, phone, book title, message, consent) with a hidden field against bots, a per-visitor limit and a guard against double sends.
* Every request comes to you by email (reply goes straight to the visitor) and is kept in Noxpress → Publishing Quote → Requests, with states (new, in contact, in orOS, closed), notes and CSV export.
* The visitor gets a «we got your request» email without amounts (optional).
* Requests are deleted after the months you set (24 by default), with the WordPress personal data exporter and eraser.

**Safe by design**

* Works on any WordPress site, with or without WooCommerce (with it, the currency and the number format come from the shop).
* Test mode by default: only managers see the calculator until you switch to live.
* `define( 'PBQ_DISABLE', true );` in wp-config.php stops the calculator, the REST routes and the form.
* Works without JavaScript too (the form posts and the page shows the result) and with page caches (the quote comes from the REST route, never from the cached page).
* A test bench in the admin with the full breakdown the visitor never sees.
* Greek and English texts for the visitor, every one editable; language automatic from the site or fixed.
* Suggested text for your privacy policy (Settings → Privacy). Settings export / import.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* REST: `POST /wp-json/pbq/v1/quote` ( { q: choices, lang } → amounts per service and total, without breakdown ) and `POST /wp-json/pbq/v1/request`.
* `pbq_get_request( $id )`, `pbq_get_requests( $args )`, `pbq_set_request_state( $id, $state, $note )`: the requests for other plugins (e.g. orOS Connect).
* `pbq_request_created` action ( int $id, array $request ) and `pbq_request_state` action ( int $id, string $state, string $old ).
* `pbq_text` filter ( string $text, string $key, string $lang ): change a visitor text.

== Installation ==

1. Upload the `publishing-quote` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
2. Activate the plugin and open **Noxpress → Publishing Quote**.
3. In **Services**, set the prices of editing, cover, illustration, printing and the hidden typesetting. In **Templates**, the sizes, papers, bindings and finishes. In **Extras**, switch on the extras you offer and set their prices.
4. Check the amounts in **Test**, where you see the full breakdown.
5. Add the «Publishing quote» block, the `[publishing_quote]` shortcode or the widget to a page; check it as a manager, then switch the mode to live.

== Frequently Asked Questions ==

= Can a visitor see my rates in the page source? =

No. The page has the option names only. The amounts come from the server already rounded, one per service.

= Where does the typesetting cost go? =

Into the services, in proportion to their amounts (or into one service you choose). The visitor sees «Typesetting: Included».

= What happens on uninstall? =

The settings, the transients and the cleanup event are removed. The requests stay, as your business record, unless you tick «Delete the requests on uninstall» in General. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.1.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.0.0 =
* Initial release.
* Server-side quote for editing, cover design, illustration, printing and extras (fixed, per item, per copy, per word, on request), with sizes, papers, bindings and finishes as templates, tiers, minimums, VAT per service and rounding per line.
* Hidden typesetting fee spread inside the services and shown as «Included»; prices with or without VAT; per-copy price and validity date.
* Block, shortcode and widget; works without JavaScript and with page caches.
* «I'm interested» requests with email to the owner, acknowledgement to the visitor, states, notes, CSV export, retention, privacy exporter and eraser, and an API for other plugins.
* Test / live / off mode, PBQ_DISABLE, test bench with the full breakdown, editable Greek and English texts, settings export / import.
* Works without WooCommerce. Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.10).
