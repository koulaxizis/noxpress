=== Home Sections ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, products shortcode, homepage, exclude products, book trailer
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Smarter WooCommerce [products] shortcodes for your homepage and any page, plus [hs_video]: the newest book trailer or presentation from your products' video tabs.

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

**Homepage video: [hs_video]**

* Shows the newest published, visible product that has a video in its tab (Custom Product Tabs for WooCommerce, meta `wb_custom_tabs`): the trailer tab (position 30) or the presentation tab (position 31). A new trailer reaches the homepage without editing the page.
* The video ID comes from `[wb_cpt_youtube_embed_shortcode video_id="…"]` in the tab, or from a `youtube.com/watch?v=…`, `youtu.be/…` or `/embed/…` URL.
* `[hs_video type="trailer"]` or `[hs_video type="presentation"]`. Optional: `id="123"` (a specific product), `exclude="1,2"`, `category="slug,slug"`, `preorders="no"` (leave pre-orders out), `heading="…"` (empty = no heading).
* Prints a heading, a poster button and a line with the product name, its authors and a link. No iframe is printed: a small script, loaded only on pages with the shortcode, swaps the poster for the YouTube player (youtube-nocookie.com) on click, and it works next to a page script that already does the same.
* Nothing found: nothing is printed.
* The texts (headings, link, player names) are store settings in Greek and English, with an automatic, Greek or English choice.
* The pick is cached for 12 hours in one transient and refreshed whenever a product or its tabs change.

**Shortcode test**

* Paste a `[products …]` shortcode in the settings page to see which products it returns and how each of its classes is read (a typo shows up as a plain CSS class), or an `[hs_video …]` shortcode to see which product and video it picks and the markup it prints.

**Safe by design**

* Nothing changes after activation: a marker acts only where a shortcode carries it, and shortcodes without one give exactly the same output.
* Nothing is written to products: the markers apply when the page loads. Deactivate the plugin and the sections list what they listed before.
* Works with WooCommerce's shortcode cache: each section keeps its own cached results.
* `define( 'HMS_DISABLE', true );` in wp-config.php stops every marker.
* Clears the page cache of WP Rocket, LiteSpeed Cache, W3 Total Cache and WP Super Cache when you save.
* No cookies, no external requests from the server, no visitor data. The YouTube player and its thumbnails load from YouTube's servers, the player only after a click. HPOS compatible.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* Filter `hms_preorder_meta` ( array $meta ): the meta keys that mark a pre-order, as meta key => value. Default: `_simple_preorder` => `yes` (PRENA) and `_por_on` => `yes` (Pre-Orders).
* Filter `hms_video_source` ( array $source ): `meta_key` (default `wb_custom_tabs`) and `positions`, the tab position per type (default `trailer` => 30, `presentation` => 31).
* Filter `hms_video_classes` ( array $classes ): CSS classes of the [hs_video] output: `heading` (default `wp-block-heading glk-h`), `button` (`glk-yt`), `play` (`glk-yt-play`), `meta` (`glk-yt-meta`).
* Filter `hms_video_people_taxonomy` ( string $taxonomy ): the taxonomy whose terms follow the product name (default `pa_syggrafeis`; empty for none).
* Filter `hms_video_text` ( string $text, string $key, string $lang ): every visitor text of [hs_video].
* Filter `hms_video_script` ( bool $load ): false skips the plugin's click-to-load script when the page ships its own.

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

= Does [hs_video] need Custom Product Tabs for WooCommerce? =

It reads that plugin's tab meta. For another source, point the `hms_video_source` filter at a meta key that holds the same list of tabs.

= Why did [hs_video] pick a pre-order? =

It picks the newest product. Add `preorders="no"`, `exclude="ID"`, or `id="ID"` for a fixed product, and check the result with the shortcode test.

= What happens on uninstall? =

The settings and the transients are removed. Shortcodes keep their classes and simply list products without the markers. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.1.0 =
* New: [hs_video] shortcode, the newest book trailer (tab position 30) or book presentation (position 31) from the products' video tabs (Custom Product Tabs for WooCommerce), with id, exclude, category, preorders and heading attributes.
* Poster markup only, no server-side iframe; a small click-to-load script (youtube-nocookie.com) loads only where the shortcode rendered and coexists with a page script that does the same.
* Visitor texts as settings in Greek and English, with an automatic, Greek or English choice.
* One 12-hour transient for the picks, cleared when a product or its tabs change.
* Shortcode test accepts [hs_video …]: the product, the video and the markup.
* Filters hms_video_source, hms_video_classes, hms_video_people_taxonomy, hms_video_text, hms_video_script.
* [products] markers unchanged: pages give byte-identical output.

= 1.0.0 =
* Initial release.
* Built-in markers for the [products] shortcode: hs-exclude-{ID}, hs-exclude-cat-{slug}, hs-no-preorders (PRENA and Pre-Orders), hs-title-{word}.
* Presets: named markers that combine categories to leave out, no pre-orders, a title phrase and product IDs.
* Optional alias prefix for the built-in markers.
* Shortcode test on the settings page: the products a shortcode returns and how each class is read.
* HMS_DISABLE, page cache clearing on save, clean uninstall.
