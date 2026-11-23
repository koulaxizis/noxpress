=== Discount Rules ===
Contributors: koulaxizis
Donate link: https://noxpress.tech/support/
Tags: woocommerce, discount, quantity discount, buy x get y, bulk pricing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Dated discount rules for WooCommerce: product sales, quantity tiers, "buy 3 pay 2" and a discount on the cart total. Each cart line gets one rule, the best one for the customer, with an order cap, per-category limits and the EU "lowest price of 30 days" line.

== Description ==

**Four kinds of rule**

* **Product sale**: a lower price (percent or fixed amount) on all products, chosen products, categories, tags or brands, with the normal price struck through in the shop.
* **Quantity tiers**: e.g. 2+ units −5%, 5+ units −10%, with a table on the product page. Up to 6 tiers.
* **Buy X pay Y**: e.g. 3 for 2, the cheapest units are free; it repeats (6 units = 2 free). Counted per product or across all the rule's products ("any 3 books of the category").
* **Cart total**: one "Discount" line in the totals once the cart reaches an amount.
* Every rule has optional start and end dates and times (site time), who it is for (everyone, guests, logged-in customers), a cart minimum and its own Greek / English label. Rules start and end by themselves, and page caches are purged at that moment.

**Rules never stack on a line**

* Each cart line gets one rule: the one that saves the customer the most. The cart rule then applies to the rest.
* An order cap (50% of the subtotal by default) that all discounts together never pass: it catches typing mistakes.
* Optional maximum discount per category (e.g. new books up to 10% for fixed book prices); these lines get no cart discount.
* Customer coupons do not apply to lines with a rule, except a 100% coupon.
* Never on gift cards (Gift Cards), trade accounts (Trade Accounts) or bundle lines (Product Bundles); optionally never on other publishers' titles (Book Feed, Trade Feed Import) or pre-orders (Pre-Orders); the `dsr_eligible` filter for anything else.

**Cart, checkout and orders**

* Classic cart and checkout, and the Cart / Checkout blocks (Store API).
* Each line shows "Offer: 3 for 2" in the cart, the order, the emails and My account. The order line keeps the price before the rule as its subtotal, so the order shows the discount like a coupon does, and a shop manager's recalculation keeps it.
* The cart rule is a read-only discount line that the customer cannot remove or type as a code.
* "Add 1 more for the offer …" in the classic cart when one unit is missing.

**Lowest price of 30 days (Omnibus)**

* Under every price a "Product sale" rule lowers and, by default on new installs, under WooCommerce's own sale prices too: "Lowest price of the last 30 days: …" (EU Directive 2019/2161, art. 9ι of Greek law 2251/1994).
* The two special cases of art. 9ι: a product on the market for less than 30 days shows its lowest price since launch, and progressive reductions within 60 days show the price before the first reduction.
* The plugin keeps its own price history from the day it is activated, in one small table: a job every night (Action Scheduler, in batches) and on every product save, never on a visitor request. While a product has no history, the line does not show.

**Plugin page**

* Rules list with status (active, scheduled, expired, off), orders and discount given; enable, disable, copy, delete.
* A test cart: choose products and quantities and see what the cart would get, as a guest or a customer, at any date, without making a cart.
* History of the last 200 changes to rules and settings (who, when, what).
* Export / import of rules and settings (JSON); an import sets the mode to off so you check it first.

**Safe by design**

* Off by default, with a test mode where only shop managers see and get the discounts.
* `define( 'DSR_DISABLE', true );` in wp-config.php stops every rule; carts have their normal prices.
* Discounts are computed only on store pages, WooCommerce's AJAX and the Store API: never in other REST routes, cron or WP-CLI.
* No cookies, no external requests, no data about visitors. HPOS compatible.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `dsr_get_price( $product )`: a product's price from a "Product sale" rule now, for guests (feeds, schema): `price`, `regular`, `rule`, `name`, `to` (end timestamp or 0), or `null`.
* `dsr_get_item_rule( $item )`: an order line's rule (`rule`, `name`, `type`, `before`, `after` per unit, `discount` for the line), or `null`.
* `dsr_rules_changed` ( int[] $rule_ids ): action after every rule change and when a rule starts or ends.
* `dsr_eligible` ( bool $ok, WC_Product $product ): `false` = the product never gets a rule (e.g. gift cards).
* `dsr_line_discount` ( float $discount, int $rule_id, WC_Product $product, int $qty, float $price ): a cart line's discount, the last word (0 = none).
* `dsr_text` ( string $text, string $key, string $lang ): change any visitor text.
* WordPress 6.9+ Abilities (read only): `discount-rules/rules` and `discount-rules/preview`.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `discount-rules` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. In **Noxpress → Discount rules**, make a rule with "New rule" and try it in "Test cart".
5. In **Settings**, test with test mode as a shop manager, then switch to **live**.

== Frequently Asked Questions ==

= Two rules match the same product. Which one applies? =

The one that saves the customer the most on that line. Rules never stack on the same line.

= Does it change the product prices in the database? =

No. Rules are applied in the cart and on the store pages only. Turning the plugin off brings back the normal prices at once.

= Can a customer use a coupon too? =

Yes, on the lines without a rule (and a 100% coupon on every line). The cart rule and the coupon share the cart as usual.

= What happens on uninstall? =

The rules, settings, history, price table and scheduled jobs are removed. The rule rows on past orders stay: they are part of the order record. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.2.0 =
* Lowest price of 30 days, art. 9ι of Greek law 2251/1994: progressive reductions within 60 days now show the price before the first reduction, and a product on the market for less than 30 days its lowest price since launch. New installs show the line under WooCommerce's own sale prices too; existing settings stay as they are.

= 1.1.3 =
* Support links (the footer pill and the Donate link) point to noxpress.tech/support/ instead of Ko-fi, with the label «♡ Support Noxpress». Noxpress Core 1.6.0: the same link in the hub, and Visitor Language in the suite catalogue.

= 1.1.2 =
* Offer page fix: it lists the offer's products even when the shop page is set to show categories (WooCommerce → Products → Shop page display).
* With Rank Math, the offer page now gets noindex and the offer's label in the document title (Rank Math prints its own robots meta and title and ignores wp_robots).

= 1.1.1 =
* Noxpress Core 1.5.0: a short «Noxpress» menu with your favourites (★) or your 5 most recent plugins, one row per plugin with tabs for its other pages, hub categories and search, every suite page in the command palette (Ctrl/Cmd+K) and a «Full menu» switch per user.

= 1.1.0 =
* Quantity rules (tiers, buy X pay Y) show a ribbon with the rule's label over the product image, in product lists and on the product page, instead of a small label under the price.
* An explanation under the price («Put 3 in the cart and pay for only 2.», plus «All products in the offer count together…» when they do) and a link to the offer page.
* Offer page: the shop with ?dsr_offer=<rule id> lists only the products of that rule, with its label as title and the explanation on top (noindex). Nothing is stored on visitor requests.
* New visitor texts t_xy_help, t_xy_mix and t_more (Texts tab). The stylesheet loads on every front page while a rule is active, so lists from shortcodes on ordinary pages get the ribbon too.

= 1.0.0 =
* Initial release (beta).
* Four rule types (product sale, quantity tiers, buy X pay Y, cart total) with dates, audience, cart minimum, scope by products, categories, tags and brands, and exclusions.
* Best rule per line, order cap, per-category limits, coupons skip rule lines.
* Classic cart and checkout, Cart / Checkout blocks; order lines keep the price before the rule.
* Lowest price of 30 days (Omnibus) from an own price history.
* Rules list with stats, test cart, change history, export / import.
* Test mode, DSR_DISABLE, read-only Abilities, HPOS compatible.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.4.17).
