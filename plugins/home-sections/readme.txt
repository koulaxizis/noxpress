=== Home Sections ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, products shortcode, homepage, exclude products, pre-orders
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Smarter WooCommerce [products] shortcodes for your homepage and any page: marker classes leave out products, categories or pre-orders, or keep only products with a word in their title.

== Description ==

WooCommerce's `[products]` shortcode is the easiest way to build homepage sections ("New books", "Latest T-shirt"), but it cannot leave out a category, pre-orders or the product a hero banner already shows. Home Sections adds that through the shortcode's own `class` attribute, so your pages stay plain shortcodes.

**Built-in markers (no setup)**

* `hs-exclude-{ID}`: leave out a product (repeatable), e.g. the one the hero already shows.
* `hs-exclude-cat-{slug}`: leave out a product category and its subcategories (repeatable).
* `hs-no-preorders`: leave out pre-orders (PRENA and Pre-Orders out of the box; filter `hms_preorder_meta` for others).
* `hs-title-{word}`: keep only products whose title contains the word, ignoring case (`hs-title-t-shirt` finds "T-Shirt").

Example:

`[products limit="4" orderby="date" order="DESC" class="hs-no-preorders hs-exclude-cat-magazines hs-exclude-123"]`

**Presets**

* A preset is a marker with a name of your own (`home-new-books`) that combines conditions: categories to leave out, no pre-orders, a phrase the title must contain (spaces allowed), product IDs to leave out.
* Change the preset once and every section that uses it follows.

**Alias prefix**

* An optional second prefix for the built-in markers. With `glk`, `glk-exclude-123` works like `hs-exclude-123`, so pages that already use such classes need no change.

**Shortcode test**

* Paste a `[products …]` shortcode in the settings page to see which products it returns and how each of its classes is read (a typo shows up as a plain CSS class).

**Safe by design**

* Nothing changes after activation: a marker acts only where a shortcode carries it, and shortcodes without one give exactly the same output.
* Nothing is written to products: the markers apply when the page loads. Deactivate the plugin and the sections list what they listed before.
* Works with WooCommerce's shortcode cache: each section keeps its own cached results.
* `define( 'HMS_DISABLE', true );` in wp-config.php stops every marker.
* Clears the page cache of WP Rocket, LiteSpeed Cache, W3 Total Cache and WP Super Cache when you save.
* No cookies, no external requests, no visitor data. HPOS compatible.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* Filter `hms_preorder_meta` ( array $meta ): the meta keys that mark a pre-order, as meta key => value. Default: `_simple_preorder` => `yes` (PRENA) and `_por_on` => `yes` (Pre-Orders).

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `home-sections` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub).
3. Activate the plugin.
4. Add marker classes to the `class` attribute of your `[products]` shortcodes, or create presets in **Noxpress → Home sections**.

== Frequently Asked Questions ==

= Does it work with the Product Collection block? =

Not in 1.0.0: only the `[products]` shortcode (and its `class` attribute).

= Which pre-order plugins are recognised? =

PRENA ("Pre-Orders for WooCommerce", meta `_simple_preorder`) and Pre-Orders from the Noxpress suite (meta `_por_on`). Add others with the `hms_preorder_meta` filter.

= Does the title marker care about accents or case? =

It uses the database's own comparison, which ignores case under the usual collations. Accents depend on the collation.

= What happens on uninstall? =

The settings and the transients are removed. Shortcodes keep their classes and simply list products without the markers. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.0 =
* Initial release.
* Built-in markers for the [products] shortcode: hs-exclude-{ID}, hs-exclude-cat-{slug}, hs-no-preorders (PRENA and Pre-Orders), hs-title-{word}.
* Presets: named markers that combine categories to leave out, no pre-orders, a title phrase and product IDs.
* Optional alias prefix for the built-in markers.
* Shortcode test on the settings page: the products a shortcode returns and how each class is read.
* HMS_DISABLE, page cache clearing on save, clean uninstall.
