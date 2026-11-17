=== Live Search ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, search, live search, ajax search, isbn
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: MIT
License URI: https://opensource.org/licenses/MIT

Product search with suggestions as the visitor types: title, ISBN / GTIN / SKU, authors and the attributes you choose, without accents, with greeklish and tolerant of small typos. The theme's own search forms stay as they are; they simply find more.

== Description ==

**Finds what people mean**

* Title, ISBN, GTIN and SKU (an ISBN also in its other form, 10 ↔ 13 digits, with or without hyphens), the people of the book (authors, translators… from any global attribute you choose), categories, tags, brands / publishers and, on the results page, the short description.
* Accents, capitals and final sigma do not matter. Greeklish finds Greek and Greek finds greeklish ("h eirini" finds «Η Ειρήνη»), with a phonetic key that also forgives ι / η / υ / ει / οι and ο / ω.
* One wrong, missing, extra or swapped letter is forgiven in words of five letters or more.
* Synonyms: one group per line ("audiobook = ηχοβιβλίο"), each word also finds the others.
* An exact ISBN opens the product directly, both in the suggestions (Enter) and on the results page.

**Fast, and kind to the page cache**

* The suggestions come from one static file (uploads/live-search/index-<hash>.json) with the public details of the findable products and guest prices. It downloads once, only when the visitor taps a search field, and every key then answers at once with no request to the server.
* The file's name changes with its content, and the previous file stays 13 hours, so cached pages (Fast Pages or any page cache) keep working.
* Above 5,000 products (or by choice) the suggestions come from a REST route instead, cached 10 minutes per phrase.
* The index is its own table, updated when a product changes, every hour for anything else, and in full every night. Visitor requests never write to the database.

**The results page finds the same**

* WooCommerce's own product search, with the theme's template, gets the same matching and the same order: best match first, out of stock products last (or not shown).

**Suggestions that look like the shop**

* Any existing search form of the theme is enhanced in the browser (or only the product search forms); nothing in the theme is replaced. [lvs_search] adds a form anywhere.
* Small image, title with the matched words in bold, authors, price (trade accounts of Trade Accounts see no price), out of stock and pre-order badges, the formats of one work as one suggestion (Product Formats), creator pages (Featured Creators) and a link to all results.
* Keyboard and screen readers: the WAI-ARIA combobox pattern, 44 px targets.
* Texts for the visitor in Greek and English.

**Statistics without people**

* What visitors search for and what found nothing, in a private file: only the phrase, the number of results and the time. No IP, browser or user is ever recorded; shop managers and bots are not counted; kept 90 days.
* A phrase that found nothing can become a synonym with one click.

**Switching from FiboSearch**

* Import → From FiboSearch carries over its settings (letters before suggestions, number, placeholder, texts, image, price, out of stock, SKU, synonyms). FiboSearch is only read; deactivate it yourself once you are convinced.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `live-search` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub).
3. Activate the plugin. The index is built in the background (or press Rebuild now in **Noxpress → Search → Index**).
4. Set the mode to **Test**: only shop managers see the suggestions and the new results page. Try a few phrases.
5. Switch to **Live for everyone**, then deactivate FiboSearch or any other live search plugin.

== Frequently Asked Questions ==

= Does it work with my theme? =

It does not replace the theme's search forms; it adds the suggestions to them in the browser, so they keep the theme's look. Classic themes (get_product_search_form(), widgets) and block themes (the Search and Product Search blocks) work the same way.

= Is the index file public? =

Yes, by design: it holds only what any visitor already sees on the shop pages (title, authors, codes, guest prices, image, link) of published products that are not hidden from search. Hidden, private, draft and password protected products, and those Hide Catalog hides from search, are never in it.

= What happens on uninstall? =

The index table, the index files, the statistics, the settings and the scheduled events are removed. Products are never changed. FiboSearch's settings are never touched. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==
= 1.0.3 =
* Noxpress Core 1.5.0: a short «Noxpress» menu with your favourites (★) or your 5 most recent plugins, one row per plugin with tabs for its other pages, hub categories and search, every suite page in the command palette (Ctrl/Cmd+K) and a «Full menu» switch per user.

= 1.0.2 =
* Noxpress Core 1.4.16: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.0.1 =
* Fix: the first full index now starts right after activation (and on the next hourly run if it never ran), instead of waiting for the nightly pass.

= 1.0.0 =
* Initial release.
* Suggestions as the visitor types, from a static index file in the browser or, for big catalogues, from the lvs/v1/suggest route.
* Matching on title, ISBN / GTIN / SKU (10 ↔ 13), people attributes, categories, tags, brands and the short description, without accents, with greeklish, a phonetic key, one-letter typos and synonyms.
* The WooCommerce results page with the same matching and order; an exact ISBN opens the product.
* One suggestion per work with its formats (Product Formats), creator pages (Featured Creators), no price for trade accounts (Trade Accounts), pre-order badge (Pre-Orders), Hide Catalog's search rules.
* Search statistics in a private file without IP or user, with synonyms from phrases that found nothing.
* Import from FiboSearch, settings backup, test mode, LVS_DISABLE, abilities live-search/test, /stats and /status.
