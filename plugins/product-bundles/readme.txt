=== Product Bundles ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, product bundles, bundle discount, kits, gift sets
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Product bundles for WooCommerce: a "Bundle" product type made of other products, each with its own stock, VAT and sales, and a bundle discount that your reports see as a discount.

== Description ==

**A bundle is its products**

* A new product type, "Bundle", with its own page, images, category and URL. Inside it you pick products (simple or a specific variation) with a quantity each.
* For a variable product you can leave the size to the customer: the bundle page shows a choice of the variations you tick.
* In the cart and in the order every product inside is its own line, with its own stock, VAT rate, weight and refunds, and the meta "Bundle: …". The bundle's lines are added, changed and removed together; their quantities are locked to the bundle.
* The bundle's own price, stock status ("Out of stock" when a product inside runs out) and weight come from the products inside, and are kept up to date in the background when their prices or stock change.

**The discount is a discount**

* Discount per bundle as a percentage of the separate price, or as a final price.
* Each line's subtotal is the product's normal price and its total is its share of the bundle price, like a coupon. WooCommerce, Revenue Splitter and Store Pulse see the products at their own price and the bundle saving as a discount, so sales per product, per beneficiary and per channel stay correct.
* Coupons skip bundle lines unless you allow them (a 100 % coupon always applies).
* Optional limit of bundles to sell, "This bundle contains" list and "Separately … · You save …" line on the bundle page.

**Orders in the admin**

* A "Bundles" box on the order screen adds a bundle to a manual order, with its discount. Recalculating an order keeps the bundle discount.

**Turn an existing product into a bundle**

* Noxpress › Bundles › Convert turns a simple or variable product into a bundle in place: it keeps its id, URL, images, reviews and SEO. The products linked from its description are suggested as contents. A backup is written first and Undo restores the product as it was (a variable product's variations go to the trash and come back).

**Safe by design**

* Off by default, with a test mode in which only shop managers can buy bundles.
* While another bundles plugin is active (WooCommerce Product Bundles, WPC Product Bundles, Easy Product Bundles), live mode works as test mode.
* On deactivation every bundle becomes "Out of stock", so WooCommerce never sells it as a plain product; on activation they are rebuilt.
* `define( 'PBN_DISABLE', true );` in wp-config.php stops the sale of bundles and the bundle page form; orders that already hold bundle lines stay correct.
* Classic and block cart / checkout, HPOS compatible. No cookies, no external requests, no data about visitors.
* Editable Greek / English customer texts, export / import of the settings and the bundle definitions.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `pbn_get_item_bundle( $item )`: the bundle an order line came from (`bundle_id`, `name`, `group`, `bundles`, `per`, `separate`, `share`), or `null`.
* `pbn_text` ( string $text, string $key, string $lang ): change any customer text.
* Fires `noxpress_products_changed` after bundles are rebuilt.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `product-bundles` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Create a product and choose the type "Bundle", or convert an existing product in **Noxpress → Bundles → Convert**.
5. In **Noxpress → PB Settings**, test with test mode, then switch to **live**.

== Frequently Asked Questions ==

= Why does the cart show the products and not the bundle? =

Because each product keeps its own stock, VAT rate and sales. The lines carry "Bundle: …" and the customer cannot change their quantities or remove one of them alone; removing one removes the whole bundle.

= Does it work with block themes? =

Yes. The bundle form shows in the product page's "Add to Cart with Options" block (tested with Twenty Twenty-Five), and the Cart and Checkout blocks keep bundle lines together.

= What happens on uninstall? =

The settings, the derived data on bundles and the background jobs are removed. The bundle contents and discounts and the conversion backups stay, so a new install finds them, unless "Delete … when the plugin is deleted" is ticked. The bundle meta on order lines is order history and always stays. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.1 =
* Fixed: saving a bundle kept no record of the variations you ticked for a variable product, so the bundle page offered every variation of it. Bundles saved with 1.0.0 offer all variations until you tick them again and update the bundle.

= 1.0.0 =
* Initial release.
* Product type "Bundle" with simple products, variations and customer choice of variation.
* Every product inside as its own cart and order line, with locked quantities and the "Bundle" meta.
* Bundle discount as a % or as a final price, shown as a discount (line subtotal / total) in WooCommerce, Revenue Splitter and Store Pulse.
* Price, stock status and weight from the products inside, updated in the background (Action Scheduler).
* Coupon policy for bundle lines, limit of bundles to sell.
* "Bundles" box on the order screen; recalculation keeps the discount.
* Convert an existing product into a bundle, with backup and undo.
* Test mode, PBN_DISABLE, test mode while another bundles plugin is active, out of stock on deactivation.
* Classic and block cart / checkout, HPOS compatible.
* Editable Greek / English texts, export / import of settings and bundles.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.9).
