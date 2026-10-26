=== Trade Accounts ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: wholesale, trade, bookstores, api, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Trade accounts for bookstores and resellers. Modules you switch on one by one: trade prices with a preset discount on the products you choose, and the Trade Feed, an API that lets each account list those products in its own e-shop.

== Description ==

A publisher sells to readers and to bookstores. Trade Accounts gives each bookstore an account with a preset discount on your own titles, and a catalogue it can pull into its e-shop, with its own username and password. Clothes, accessories, other publishers' titles or digital products never show up, because only the categories and brands you choose are eligible.

Every module is off after activation. A module that is off loads no code.

**Eligible products**

* The categories you choose (with their subcategories) AND, if you choose any, the brands. Nothing is eligible until you choose a category, so a fresh install changes no price.
* Physical products only (on by default), never external or grouped products, never titles imported by Book Feed (other publishers), and never a product with «Not for trade» ticked in its General tab.

**Trade prices module**

* A «Χονδρική» (trade) role, made by the shop: from the Accounts tab (a new user, or an existing customer by e-mail; staff accounts are never changed) or by giving a customer the role in Users. No self sign-up.
* One discount for every account (default 40%), or an account's own. Taken off the regular retail price whatever sale is running (default), or off the price shown now.
* The account sees and pays the trade price everywhere: shop, product page, cart, mini-cart, classic checkout, Cart / Checkout blocks and the order. The regular price shows struck through, with an optional «Trade price» label (Greek and English text).
* Order lines are ordinary lines at the trade price: payments, shipping, VAT, stock, refunds and Revenue Splitter (the same shares of the lower price) work as in retail. Each line and the order carry the discount, readable in the order screen and e-mails.
* Optional invoice rule: a trade order needs the invoicing plugin's receipt type set to "invoice" (default: «Timologia for WooCommerce» by OBS, field and value in the settings); the checkout preselects it.
* Pages with trade prices are never page-cached. Admin screens, cron and the WooCommerce REST API always see the real prices.

**Trade Feed module**

* REST namespace `tra/v1`, JSON by default, XML with `output=xml`: `/account`, `/products`, `/products/<isbn>`, `/categories`.
* Authentication: HTTP Basic over HTTPS with the account's username and a WordPress Application Password made by the plugin («New password», shown once). One password per account; a new one revokes the old; switching the account's feed off or removing the account revokes it too.
* Filters: category (ids or slugs), ISBN / ISSN / GTIN / SKU lists (hyphens ignored), author (contributor attributes, by name or slug), attribute + term, availability (in stock, out of stock, on backorder, pre-order), modified since, search, order, fields, pages of up to 100.
* Each product: identifiers, title, link, publisher, categories, contributors by role, attributes, descriptions (HTML and plain text, shortcodes removed), dimensions and weight, retail, sale and trade price with the VAT rate, availability with pre-order and release date (PRENA or Pre-Orders), images and dates.
* A rate limit per account and hour (default 120) with X-RateLimit headers and 429 / Retry-After; the account's last call and a log of the last 200 calls, without IP addresses or credentials. Answers are never cached.
* Managers can call it too, to test, with the discount of the settings.

**Safe by design**

* Test mode by default: only managers see trade prices, as a preview, until you switch to live.
* `define( 'TRA_DISABLE', true );` in wp-config.php stops trade prices and closes the feed (403).
* Suggested text for your privacy policy (Settings → Privacy).
* Settings export / import (no accounts, discounts or passwords in the file).
* Read-only status in the WordPress Abilities API (`trade-accounts/status`, WordPress 6.9+).
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `tra_is_trade( int $user_id = 0 )`, `tra_is_eligible( $product )`, `tra_trade_price( $product, int $user_id = 0 )`.
* Filters `tra_eligible` ( bool $ok, WC_Product $product ), `tra_discount` ( float $percent, int $user_id ), `tra_feed_item` ( array $item, WC_Product $product ), `tra_text` ( string $text, string $key, string $lang ).

== Installation ==

1. Upload the `trade-accounts` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New). WooCommerce is required.
2. Activate the plugin and open **Noxpress → Trade Accounts**.
3. In **Settings**, set the discount and choose the eligible categories (and brands).
4. In **Modules**, switch on Trade prices and / or Trade Feed. Check the shop as a manager (test mode), then switch the mode to live.
5. In **Accounts**, create an account per bookstore. For the feed, press **New password** and send the bookstore the username, the password and the address shown.

== Frequently Asked Questions ==

= Can a bookstore sign up by itself? =

No. Accounts are made by the shop, so only bookstores you know get trade prices.

= Does a trade account see other products at retail price? =

Yes. Only eligible products get the trade price; everything else in the cart stays at its normal price, and shipping works as in retail.

= Why Application Passwords? =

They are WordPress's own credentials for APIs: stored hashed, revocable one by one, and separate from the account's login password. The site needs HTTPS for them, and a security plugin must not have turned them off.

= What happens on uninstall? =

The settings, the call log, the plugin's user and product meta, its Application Passwords and the «Χονδρική» role are removed; trade accounts stay as customers. The discount marks stay in the orders, as the shop's record. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.1.0 =
* New: no discount coupon in a cart with trade prices (setting, on by default), with a Greek / English message; a coupon applied earlier is dropped.
* New: no thank-you coupon after a trade order (setting, on by default): Thanko Thank You Page Customizer makes, shows and e-mails none, and Checkout Extras makes none.

= 1.0.0 =
* Initial release, with modules switched on one by one.
* Trade prices module: «Χονδρική» role made by the shop, default and per-account discount on the eligible categories and brands, trade prices in the shop, cart, Store API and order, line and order marks, optional invoice rule (OBS Timologia), no page cache.
* Trade Feed module: REST tra/v1 in JSON or XML with filters, Application Password per account (shown once, revocable), rate limit, call log without IP.
* Accounts tab, «Not for trade» product checkbox, test / live / off mode, TRA_DISABLE, privacy policy text, settings export / import, Abilities API status.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.19).
