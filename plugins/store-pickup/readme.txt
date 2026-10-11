=== Store Pickup ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, local pickup, store pickup, click and collect, shipping method
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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
* The method id is `nox_store_pickup`.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `store-pickup` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Pickup** and add your stores.
5. In **WooCommerce → Settings → Shipping**, add "Store pickup" to the zones where you offer it and set its cost (0 = free).
6. Back in **Noxpress → Pickup**, test with test mode, then switch to **live**.

== Frequently Asked Questions ==

= Why not WooCommerce's own "Local pickup"? =

The classic "Local pickup" has no store choice and no hours. WooCommerce's pickup locations work only in the Checkout block. Store Pickup brings the store choice to the classic checkout.

= Can the customer pay at the store? =

The plugin does not change payments. To allow cash on delivery only for pickup, use the "Enable for shipping methods" setting of WooCommerce's "Cash on delivery". Leave it off if pickup orders must be paid online.

= What happens on uninstall? =

The settings, the transients and the method's entries in your shipping zones are removed. The pickup store saved on past orders stays: it is part of the order record. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

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
