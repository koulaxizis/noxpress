=== Store Pickup ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, local pickup, store pickup, click and collect, shipping method
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Store pickup as a WooCommerce shipping method, free or with your own cost, with a choice of store, address and opening hours at checkout.

== Description ==

**One method, many stores**

* "Store pickup" is a normal WooCommerce shipping method: add it to any shipping zone, with its own name (Greek and English), cost (0 by default) and tax status.
* Your stores are set once in the plugin: name, address, opening hours and details (Greek and English, up to 5 lines), an optional map link, active or not, and their order.
* Each zone can offer all active stores or only some of them.

**At checkout**

* When the customer chooses store pickup, the list of stores appears right under the method, with address, hours and map link. With a single store it is shown without a choice.
* The order cannot be placed without a store. The choice survives WooCommerce's checkout refreshes.
* Optionally the shipping address is hidden while pickup is chosen and the billing address is used, as WooCommerce does for its own local pickup.
* Taxes follow WooCommerce's local pickup rule (the store's base address, if that setting is on).

**On the order**

* The chosen store (name, address, hours) is saved on the order's shipping line, so it shows on the admin order screen even without the plugin.
* A "Pickup store" row under "Shipping" on the thank-you page, in My account and in every order email.
* The shipping address is hidden on the order and in emails when it is not used.

**No pickup for some products**

* Products that never pass through your store (e.g. print-on-demand items shipped by the production partner) can turn pickup off. With the Printful plugin nothing else is needed: it puts its products in a parcel of their own, and that parcel gets no pickup. Otherwise give the products a WooCommerce shipping class (e.g. "Printful") and tick it in the rule. Variations follow the parent's class unless they have their own.
* The rule works per parcel: in a mixed cart, the parcel with your own products keeps pickup. When no parcel can be picked up, the cart and checkout totals say why, with your own text in Greek and English.
* Off by default.

**Store list on any page**

* `[pkp_stores]` prints the active stores with address, hours, details and map link, so a "Pickup options" page or the FAQ always match the settings. `[pkp_stores ids="…"]` shows only some (the Tools tab lists the ids).
* Prints nothing while the plugin is off; in test mode only shop managers see it.

**For the shop**

* A "Pickup at: <store>" line at the top of the admin New order email, so pickup orders stand out (can be turned off).

**Classic and block checkout**

* Built for the classic checkout (`[woocommerce_checkout]`). The Checkout block has no place for the store choice, so there the method is offered only when a single store is possible in the zone; that store goes on the order.

**Safe by design**

* Off by default, with a test mode that shows the method to shop managers only.
* `define( 'PKP_DISABLE', true );` in wp-config.php unregisters the method and stops every front-end hook; checkout falls back to your other methods.
* No cookies, no external requests, no data about visitors. HPOS compatible.
* Editable Greek / English customer texts and settings export / import.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `pkp_get_order_store( $order )`: the pickup store of an order (`id`, `name`, `address`, `details`, `map`) as saved at checkout, or `null`.
* `pkp_text` ( string $text, string $key, string $lang ): change any customer text.
* `pkp_pickup_allowed` ( bool $allowed, array $package ): whether a shipping package may get the pickup method.
* The method id is `nox_store_pickup`.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `store-pickup` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Pickup** and add your stores.
5. In **WooCommerce → Settings → Shipping**, add "Store pickup" to the zones where you offer it and set its cost (0 = free).
6. Back in **Noxpress → Pickup**, test with test mode, then switch to **live**.

== Frequently Asked Questions ==

= How do I keep pickup off for print-on-demand products? =

With the Printful plugin, tick "No pickup" and "Printful parcels" in Noxpress → Pickup: the parcel Printful makes gets no pickup, and the parcel with your own products keeps it. Without it, create a shipping class in WooCommerce → Settings → Shipping → Classes (e.g. "Printful"), set it on those products and tick that class too. When no parcel can be picked up, the cart shows the "No pickup" message (Texts tab).

= I used WooCommerce's "Local pickup" with one method per store. How do I move? =

Add "Store pickup" to each zone, enter your stores in Noxpress → Pickup, test with test mode, then switch to live and disable the old "Local pickup" methods. Past orders keep the method they were placed with.

= Why not WooCommerce's own "Local pickup"? =

The classic "Local pickup" has no store choice and no hours. WooCommerce's pickup locations work only in the Checkout block. Store Pickup brings the store choice to the classic checkout.

= Can the customer pay at the store? =

The plugin does not change payments. To allow cash on delivery only for pickup, use the "Enable for shipping methods" setting of WooCommerce's "Cash on delivery". Leave it off if pickup orders must be paid online.

= What happens on uninstall? =

The settings, the transients and the method's entries in your shipping zones are removed. The pickup store saved on past orders stays: it is part of the order record. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.2.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.1.1 =
* New: the "No pickup" rule also reads the parcel the Printful plugin makes ("Printful parcels", on by default), so print-on-demand items need no shipping class.
* Change: the rule works per parcel. In a mixed cart the parcel with your own products keeps pickup, and the "No pickup" message shows only when no parcel can be picked up. New default message text.

= 1.1.0 =
* New: "No pickup" rule: products of the chosen shipping classes (e.g. print on demand) remove the pickup method from the cart, with a message in the cart and checkout totals. Off by default. Filter `pkp_pickup_allowed`.
* New: `[pkp_stores]` shortcode with the active stores (address, hours, details, map), optionally `ids="…"`.
* New: "Pickup at: <store>" line at the top of the admin New order email (HTML and plain text), on by default.
* New: texts for the "No pickup" message and the admin line (Greek / English); the backup includes the new settings.

= 1.0.0 =
* Initial release.
* Shipping method "Store pickup" for WooCommerce shipping zones, with Greek / English name, cost and tax status, and a choice of stores per zone.
* Stores with name, address, Greek / English hours and details, map link, active flag and order.
* Store choice under the method in the classic checkout, with validation; a single store is chosen automatically.
* Store saved on the order's shipping line; "Pickup store" row on the thank-you page, in My account and in emails; public function `pkp_get_order_store()`.
* Optional shipping address hiding (checkout form, order, emails); taxes as WooCommerce local pickup.
* Checkout block: the method is offered only when a single store is possible.
* Editable Greek / English texts, settings export / import.
* Test mode, PKP_DISABLE, HPOS compatible.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.4).
