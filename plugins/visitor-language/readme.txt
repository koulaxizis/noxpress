=== Visitor Language ===
Contributors: koulaxizis
Donate link: https://noxpress.tech/support/
Tags: english, language switcher, multilingual, woocommerce, hreflang
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: MIT
License URI: https://opensource.org/licenses/MIT

An English version of a Greek site at /en/, for visitors from abroad: WordPress, WooCommerce, cart, checkout, the theme and the Noxpress plugins in English, and English texts only for the products and pages you choose. No copies of pages, no translation service, and the Greek site stays exactly as it is.

== Description ==

A Greek shop gets visitors from abroad who cannot read the checkout. A full multilingual plugin means a copy of every page and product, and much more to keep up to date. Most of the words a visitor meets are not yours anyway: buttons, the cart, checkout fields, errors and e-mails come from WordPress, WooCommerce and the theme, and they are written in English to begin with.

Visitor Language puts the same site under /en/ in English:

* **The software speaks English on its own.** Under /en/ the request runs in en_US (or en_GB), so WordPress, WooCommerce, the theme and every Noxpress plugin show their English texts. Nothing to translate there.
* **You translate only what you want.** Every product, page and post gets an «English» box: name or title, short description, description. Empty fields stay Greek. Products without English show a small «This page is only available in Greek» note.
* **Short texts in one list.** Attribute names and values (Size, Colour), categories and tags, menu items, shipping and payment labels: one line each, «Ελληνικά = English».
* **Menus per language.** Every menu location of the theme can show another menu in English.
* **The links stay English.** Every link WordPress builds under /en/ keeps /en/: products, cart, checkout, account, AJAX, REST, and the classic and block checkout.
* **The switch.** A small EL / EN button at the bottom left, or the [vlg_switch] shortcode where you want it.
* **Search engines.** hreflang el / en / x-default on the pages that have English texts, and an English <title>, meta description and og / twitter texts (also with Rank Math or Yoast SEO).

Nothing changes after activation: the switch starts off and /en/ gives 404 until you turn it on.

**Page cache**

English answers are never stored by a page cache (DONOTCACHEPAGE and no-cache headers), so a cache that keys on the path can never serve the English page for the Greek URL or the other way round. Greek pages stay cached; saving the settings purges them, because they carry the switch.

**Orders placed in English**

* The order remembers that it was placed under /en/, and the admin order screen says so.
* Every customer e-mail of that order goes out in English, also when it is sent later: a payment confirmation from the provider, a status change in the admin, a resend.
* Admin e-mails always go out in the site language, also when an English checkout sends them.
* A payment provider that returns the buyer to a fixed URL set in its own dashboard lands on the Greek thank-you page; the buyer of an English order is moved on to the English one (only with that order's key).

**Known limits (beta)**

* Theme texts typed in the Customizer (for example the footer line) and texts of other plugins that are typed in Greek in their settings stay as typed.
* Plugins that load before this one and translate texts at load time may keep Greek texts in English pages.
* Product names are saved in the order in the language of the checkout, so the admin sees the English name of an English order.

**For developers**

* `vlg_lang()` — «el» or «en» for the current request.
* `vlg_url( $url, $lang )` — a link of the site in one language.
* Filter `vlg_post_types` — the content types with an «English» box.
* Post meta `_vlg_title`, `_vlg_excerpt`, `_vlg_content`, also in the REST API for users who may edit the post.
* `define( 'VLG_DISABLE', true );` in wp-config.php stops /en/ at once.

Part of the free [Noxpress](https://noxpress.tech) suite. No ads, no tracking, no paid version.

== Installation ==

1. Install from the Noxpress hub (Noxpress → Dashboard) or upload the zip in Plugins → Add New.
2. Activate, then Noxpress → Visitor Language: turn on «The English version answers at /en/».
3. Write English texts in the «English» box of the products and pages you want, and the short texts in «Short texts».

== Frequently Asked Questions ==

= Does it change the Greek site? =

No. Greek URLs answer exactly as before. The only additions there are the EL / EN button (if you choose it) and the hreflang links on pages with English texts.

= Does it need WooCommerce? =

No. With WooCommerce the product texts, attributes, shipping and payment labels are translated too.

= What happens on uninstall? =

The settings and every English text are deleted.

== Changelog ==

= 1.0.2 =
* New choice for the EL / EN switch: only on products with English texts (e.g. the ones shipped abroad). Filter vlg_show_switch( $show, $post_id ).
* The floating switch no longer sits on the theme's back-to-top button (Astra, bottom left).

= 1.0.1 =
* English pages also get an English <title>, meta description and social (og / twitter) texts from the English box: without an SEO plugin, with Rank Math and with Yoast SEO. The shop page and a static front page count too.

= 1.0.0 =
* First release (beta): /en/ prefix with en_US or en_GB, English texts per product, page and post, short-text list, menus per location, «only in Greek» note, EL / EN switch and shortcode, hreflang, no page cache for English answers.
* Orders placed in English: customer e-mails in English also when sent later, admin e-mails in the site language, the thank-you page of an English order moves to /en/ after a payment provider's fixed return URL, and the order screen shows the language.
