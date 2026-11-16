=== Back in Stock ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, back in stock, out of stock, notify me, waitlist
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A «Notify me when it is back» form on out-of-stock products and variations: email confirmation for guests, one email when the product is back in stock, batches in the background and demand per product. No newsletter, no outside service.

== Description ==

**The form**

* Shows under «Out of stock» on simple products, and on variable products only when the chosen variation is out of stock (without JavaScript, a list of the out-of-stock variations).
* Email and one consent box («I agree to receive one email about this product»), with a link to the privacy policy. No name, IP address or browser data is kept.
* The same HTML for every guest, so page caches keep working. Classic themes and block themes (after the Add to cart form block).
* No form for products on backorder, pre-orders (Pre-Orders), hidden categories (Hide Catalog) or titles that left the Book Feed feed.

**Nobody can sign up someone else**

* A guest gets a confirmation email first; the request counts only after the click, and expires after 48 hours. A logged-in customer using their own email needs no confirmation.
* The links in the emails open a small page with a button. Mail scanners (Outlook, Gmail) open links before people do, so opening a link changes nothing.
* Honeypot, a time check, a nonce for logged-in customers, a limit per IP address (only a hash, for one hour) and a limit of open requests per email. The answer is the same whether a request already existed or not.

**Sending**

* When the product or variation is back in stock (product screen, REST, import, orders, Book Feed: every change that goes through WooCommerce), a job waits 10 minutes, checks the stock again and sends one email per request in batches of 50, in the background with WooCommerce's Action Scheduler.
* A request is marked sent only once its email has left; a failure stays active and the daily job tries again.
* The email uses the shop's WooCommerce template, in the language of the request, with image, price, «View the product», other formats in stock (Product Formats) and a link to cancel every notification of that address. It goes through wp_mail, so FluentSMTP or any SMTP plugin sends it.
* With Wish Shelf 1.2.5 or later, a customer who has the product on their list and also asked here gets one email, not two.

**For the shop**

* Requests per product: active, awaiting confirmation, sent in the last 30 days, stock now, «Send now», and the addresses of one product with «Delete».
* «Out-of-stock demand» on the WordPress dashboard: the products most people are waiting for.
* Ability back-in-stock/demand for Site Bridge (read only, no email addresses).

**Privacy and safe by default**

* Test mode after activation: only shop managers see the form. Both emails are off until you switch them on in WooCommerce → Settings → Emails. `BIS_DISABLE` in wp-config.php stops every form at once.
* After sending, the address is erased; only a keyed hash stays for 30 days (counts, Wish Shelf). Requests still waiting expire after 180 days (setting).
* WordPress's personal data exporter and eraser, and a suggested text for the privacy policy.
* No newsletter box and no mailing-list service: one transactional email per request.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `back-in-stock` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub).
3. Activate the plugin. It starts in test mode.
4. In WooCommerce → Settings → Emails, switch on «Back in Stock: back in stock» and «Back in Stock: confirmation».
5. Open an out-of-stock product as a shop manager and try the form; then set **Noxpress → Back in stock → Settings → Mode** to live.

== Frequently Asked Questions ==

= What happens when a product goes to «on backorder»? =

It can be bought, so it gets no form, and requests wait until the stock status is «In stock».

= Does it work when the stock changes outside WooCommerce? =

The daily job (03:17 site time) checks every product with waiting requests and sends for those that are in stock. «Send now» on the requests screen does the same at once.

= What happens on uninstall? =

The table of requests, the settings, the email settings and the scheduled jobs are removed. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==
= 1.0.0 =
* Initial release.
* Form on out-of-stock products and variations, double opt-in for guests, confirm and cancel pages with a POST button.
* Background sending in batches with a delay and a second stock check, retries by the daily job.
* Requests per product, «Send now», dashboard widget, ability back-in-stock/demand.
* bis_covers() for Wish Shelf; Pre-Orders, Hide Catalog, Product Formats and Book Feed aware.
* Privacy exporter and eraser, test mode, BIS_DISABLE, settings backup.
