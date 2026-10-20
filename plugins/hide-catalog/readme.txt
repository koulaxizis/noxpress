=== Hide Catalog ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, hide category, hide products, shop page, catalog visibility
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Hide product categories and their products from the shop, other listings, search, related products, shortcodes and blocks, one rule per category, without changing any product.

== Description ==

**One rule per category**

* Choose a product category and, if you like, its subcategories.
* Hide its products, each switch on its own, from:
  * the shop page,
  * other product listings (other categories, tags, attributes, brands),
  * search (product search and the WordPress search),
  * related products, upsells and cross-sells,
  * product shortcodes (`[products]` and friends) and blocks (Product Collection, the older product grid blocks, All Products).
* A product that is also in a visible category is hidden too — or, if you prefer, only when all its categories are hidden.
* A preview under each rule shows how many published products it covers and how many of them are also in another category.

**The category itself**

* Hide it from category lists (widgets, blocks, the shop's category display, Shop Filters), from a product's categories and from breadcrumbs.
* Its page stays open (default), or becomes noindex, a 404 page, or a redirect to the shop. Closed pages leave the WordPress and Rank Math sitemaps.
* On its own page a hidden category's products are always shown, so you can link to it from a menu or a campaign.
* One click hides WooCommerce's default category ("Uncategorized"), which cannot be deleted.

**Counts that agree**

* The rules go through WooCommerce's own catalog query, so the price and attribute filter widgets and Shop Filters count exactly what visitors see — no extra setup.

**Safe by design**

* Nothing is written to products: every rule applies when the page loads. Deactivate the plugin and the catalog is back at once.
* Off by default, with a test mode that applies the rules to shop managers only.
* `define( 'HIC_DISABLE', true );` in wp-config.php stops every rule.
* Clears the page cache of WP Rocket, LiteSpeed Cache, W3 Total Cache and WP Super Cache when you save (optional).
* Moves the setting of "Hide Categories Or Products On Shop Page" over with one click.
* No cookies, no external requests, no visitor data. HPOS compatible.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `hic_hidden_terms( $context )`: the hidden category ids for the current request; `$context` is `shop`, `listings`, `search`, `related`, `blocks` or `terms`. Empty when the rules do not apply to the request.
* Filter `hic_hidden_terms` ( int[] $ids, string $context ) on that result.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `hide-catalog` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Hide catalog**, add a rule and tick where its products should disappear.
5. Check the shop in test mode, then switch to **On for everyone**.

== Frequently Asked Questions ==

= How is this different from WooCommerce's "Catalog visibility"? =

WooCommerce sets visibility per product and never hides a category itself. Hide Catalog works per category, also hides the category from lists, and changes no product data.

= Does it work with FiboSearch and other search plugins? =

Search plugins with their own index (such as FiboSearch) do not read WordPress's search query, so the search hiding does not reach their results yet. The settings page says so when FiboSearch is active.

= Are the products still reachable? =

Yes. A hidden product's own page stays open, and so does the category page unless you close it.

= Why does a category hidden from lists also leave the sitemap? =

The sitemaps build their category lists the same way the rest of the site does. A category you hide from lists is usually one you do not want indexed either.

= What happens on uninstall? =

The settings and the transients are removed. Nothing else exists: products, categories and orders were never changed. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.1 =
* Fix: with a rule that hides a category from lists, a visitor's page could rebuild WordPress's stored category hierarchy without that category, so its subcategories vanished from get_term_children() site-wide (an empty subcategory page, rules with subcategories missing them). The full read behind that hierarchy is no longer filtered.

= 1.0.0 =
* Initial release.
* Rules per product category, with or without subcategories: hide products from the shop, other listings, search, related products / upsells / cross-sells, and product shortcodes and blocks (Product Collection, product grid blocks, Store API product list).
* "Only when all its categories are hidden" option, with a preview of affected products per rule.
* Hide the category itself from category lists, product categories and breadcrumbs; one-click rule for the default category.
* Category page: open, noindex (WordPress, Yoast, Rank Math), 404 or redirect to the shop; closed pages leave the core and Rank Math sitemaps.
* Import from "Hide Categories Or Products On Shop Page"; notice when FiboSearch is active.
* Test mode, HIC_DISABLE, page cache clearing on save, settings backup, clean uninstall.
