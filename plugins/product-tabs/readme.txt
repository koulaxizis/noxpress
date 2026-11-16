=== Product Tabs ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, product tabs, custom tabs, size guide, product video
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Extra tabs on the WooCommerce product page: per product and shared by category, tag or product type, videos that load only on a click, one order for every tab, and an import from Custom Product Tabs for WooCommerce that leaves its data untouched.

== Description ==

**Tabs per product**

* A "Product tabs" box on the product screen: title, content in the classic editor (HTML and shortcodes stay as written), a position among the page's tabs, and a switch to hide a tab without deleting it.
* A tab can be a video: paste a YouTube or Vimeo address. Give it the role "Trailer" or "Presentation" and an empty title shows the role's title from the settings.
* With Product Formats, a tab can also show on the other formats of the same work (the print book's trailer on the ebook and the audiobook), unless that format has its own tab of the same role.

**Shared tabs**

* One tab for many products: on all of them, or on categories (subcategories included), tags or product types (simple, variable, downloadable, virtual…), with exceptions by product id.
* Greek and English title and text; an empty English text shows the Greek one.
* A product can hide a shared tab or show its own text instead.

**Videos that load on a click**

* The page shows a poster with a play button. YouTube (youtube-nocookie.com) or Vimeo (dnt=1) loads only when the visitor presses it, so product pages stay fast and send nothing to the video site before that.
* The poster image of a YouTube video comes from i.ytimg.com; turn that off for a plain dark poster.
* `[nox_video src="…" title="…"]` puts the same player on any page.

**One order for every tab**

* Position, new title (Greek / English) or hide, for WooCommerce's Description, Additional information and Reviews, and the tabs of Theme Patcher (Care & Safety), Featured Creators (Creators) and Book Preview.
* Tabs of other plugins stay as they are.

**Switching from Custom Product Tabs for WooCommerce**

* The import only reads that plugin's tabs (meta `wb_custom_tabs`) and never changes or deletes them. A preview counts products, tabs, videos and text tabs first.
* `[wb_cpt_youtube_embed_shortcode video_id="…"]` becomes a video tab; position 30 becomes the role "Trailer", 31 "Presentation" (the videos Home Sections shows). Any other content moves over as it is.
* While both plugins run, the old tabs are hidden on products already imported, so nothing shows twice. A second import adds nothing; "Undo import" removes only what the import brought.
* After the switch the old YouTube shortcode keeps working in pages and descriptions (it shows the click-to-load player).

**Safe by default**

* Off after activation. Test mode shows the tabs and the new order to shop managers only. `PTB_DISABLE` in wp-config.php stops every change at once.
* Product pages only; nothing is written on a visitor request; on any error the page keeps its original tabs.
* HTML is kept as written only for users who may post unfiltered HTML; everyone else's goes through WordPress's post filter.
* Abilities for Site Bridge (read only), bilingual admin (Greek / English) and the shared «Noxpress» menu.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `product-tabs` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub).
3. Activate the plugin.
4. Open **Noxpress → Tabs**, set the mode to test, and add tabs on a product or as shared tabs.
5. Coming from Custom Product Tabs for WooCommerce: **Import and backup → Import**, check a few products in test mode, switch to **On for everyone**, then deactivate the old plugin.

== Frequently Asked Questions ==

= Does it work with my theme? =

It adds tabs through WooCommerce's own `woocommerce_product_tabs` filter, so they appear wherever the theme shows WooCommerce's tabs, including themes that turn tabs into an accordion and block themes with the Product Details block.

= Do the shortcodes work while the mode is off? =

`[nox_video]` and the bridge for the old YouTube shortcode work in every mode, because they only replace something that would otherwise show as raw text. The tabs themselves follow the mode.

= Where does Home Sections find the videos? =

Home Sections 1.4.5 and later read the trailer and presentation videos from Product Tabs first (`ptb_get_video()`), and from Custom Product Tabs for WooCommerce only for products not imported yet.

= What happens on uninstall? =

The settings and the notices are removed. Tabs are shop content, so the product tabs and the shared tabs stay unless you tick "Delete all tabs" in the settings first. The data of Custom Product Tabs for WooCommerce is never touched. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==
= 1.0.2 =
* Noxpress Core 1.4.16: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.0.1 =
* Core 1.4.0: the Noxpress hub can install suite plugins that were released after this copy of Core (read from the signed version list; a plugin counts as ours only with our Update URI).

= 1.0.0 =
* Initial release.
* Tabs per product (text or video) with position, on / off and, with Product Formats, sharing with the other formats of the work.
* Shared tabs by all products, categories, tags and product types, with exceptions, Greek and English texts, and per-product hide or own text.
* Click-to-load YouTube (youtube-nocookie.com) and Vimeo player, optional YouTube poster image, [nox_video] shortcode.
* Order, titles and hiding for WooCommerce's tabs and the suite's (Theme Patcher, Featured Creators, Book Preview).
* Read-only import from Custom Product Tabs for WooCommerce with preview and undo, duplicate hiding while both run, and a bridge for [wb_cpt_youtube_embed_shortcode].
* ptb_get_video() and ptb_get_tabs() for other plugins; abilities product-tabs/list and product-tabs/import-preview.
* Test mode, PTB_DISABLE, page cache clearing, settings backup, uninstall that keeps the tabs unless you ask.
