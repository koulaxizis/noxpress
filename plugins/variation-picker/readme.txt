=== Variation Picker ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, variation swatches, color swatches, image swatches, size guide
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Swatches for WooCommerce variations: buttons, colors, two-tone colors, images, the variation's own image, radio lists, a size guide and swatches on shop cards.

== Description ==

**Six ways to show an attribute**

* Text buttons, color swatches (with an optional second color for two-tone values), image swatches, the variation's own image (automatic, nothing to upload), a radio list, or WooCommerce's dropdown.
* The type is set per attribute (Products › Attributes or Noxpress › Swatches) and can be changed per product in its "Swatches" tab. Attributes without a setting become text buttons (or stay dropdowns, your choice).
* Colors, images and short labels (e.g. "XL" for "Extra Large") are set once on each attribute value. Custom product attributes get them in the product's "Swatches" tab.
* Shape (square, rounded, circle), size, font size, border width and colors, with a live preview.

**Clear choices**

* Combinations that do not exist and out-of-stock values are blurred, crossed out or hidden (separately).
* A click on a value that does not match the other choices selects it and clears the rest, so a customer is never stuck.
* "Color: Black" next to the attribute name, a tooltip with the name (and image), optional price difference ("+3.00 €") and "Only N left".
* Optional: automatic selection when only one value is possible, a second click clears a choice, choices kept in the page address (a shared link opens the same variation).
* Keyboard and screen readers: each attribute is a radio group with arrow keys, and WooCommerce's own select stays in the page underneath.

**Size guide**

* Size guides with a Greek and English title, table and note, and an optional image. A product gets the first active guide of its categories (or another one, or none, in its "Swatches" tab). The guide opens in an accessible dialog next to the attribute you choose.

**Shop cards**

* Optional swatches on shop, category and related-product cards for one attribute (or the first one with colors or images), after the title, price or button, with a limit and "+N".
* Hover or focus swaps the card's image to the variation's image; a click opens the product with the value already chosen.

**Fast with many variations**

* An availability map per product (which combinations exist, their stock and images) is built when the product is saved or its stock changes, in the background with WooCommerce's Action Scheduler. Products with more variations than WooCommerce loads at once still show what is available, without extra requests.

**Moving from another plugin**

* Tools › Import reads colors, images, labels and attribute types from CartFlows Variation Swatches, Variation Swatches (GetWooPlugins) and WCBoost Variation Swatches, and copies them only where Variation Picker has no value yet. Their data is not changed.
* While another swatches plugin is active, Variation Picker stays in test mode so visitors never see two sets of swatches.

**Safe by design**

* Off by default, with a test mode that shows the swatches to shop managers only.
* WooCommerce's dropdown stays in the page and the swatches only drive it: without JavaScript, or if anything fails, the visitor gets the normal dropdown.
* `define( 'VPK_DISABLE', true );` in wp-config.php stops every front-end hook.
* No cookies, no external requests, no data about visitors. HPOS compatible.
* Editable Greek / English customer texts and settings export / import.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `vpk_get_term_swatch( $term )`: the look of an attribute value (`type`, `color`, `color2`, `image` URL, `label`), or `null`, e.g. for color dots in filters.
* `vpk_text` ( string $text, string $key, string $lang ): change any customer text.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `variation-picker` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. In **Products → Attributes**, set the swatch type of each attribute; open **Configure terms** to give values their colors or images. Or import them from your old plugin in **Noxpress → Swatches → Tools**.
5. In **Noxpress → Swatches**, test with test mode, then switch to **live**.

== Frequently Asked Questions ==

= Does it work with block themes? =

On the product page, yes: the swatches follow WooCommerce's classic add-to-cart form, which block themes use in the "Add to Cart with Options" block (tested with Twenty Twenty-Five). The newer "Add to Cart + Options" block, with its own variation selector, does not show them. On the shop, category and search pages of a block theme, WooCommerce runs the classic card hooks, so shop-card swatches show below the title; Product Collection blocks on other pages do not show them.

= Why is there no color dot in my cart? =

The dot goes next to the color where the cart lists the attribute values. WooCommerce puts the values of products with one or two attributes in the product name instead ("T-shirt - Black, M"), so there it has no place. The Cart and Checkout blocks do not show it either.

= What happens on uninstall? =

The settings, size guides, availability maps and background jobs are removed. The colors, images and labels on attribute values and the per-product settings stay, so a new install finds them, unless "Delete … when the plugin is deleted" is ticked in Tools. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.1.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.0.0 =
* Initial release.
* Swatch types per attribute and per product: text button, color (two-tone), image, variation image, radio list, dropdown.
* Value looks (color, second color, image, short label) on attribute terms and on custom product attributes.
* Shape, size, font size, border width and colors with a live preview.
* Blurred / crossed / hidden values for missing combinations and out of stock; smart selection; "Color: Black"; tooltip with name, image, price difference and low stock.
* Automatic selection, deselect on second click, choices in the page address.
* Size guides by category or per product, in an accessible dialog.
* Swatches on shop cards with image swap and pre-selected links.
* Availability maps built in the background (Action Scheduler) for products with many variations.
* Import from CartFlows Variation Swatches, Variation Swatches (GetWooPlugins) and WCBoost Variation Swatches.
* Color dot in the cart and mini-cart.
* Editable Greek / English texts, settings export / import.
* Test mode, VPK_DISABLE, HPOS compatible.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.6).
