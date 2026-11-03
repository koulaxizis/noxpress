=== Search Signals ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: seo, schema, sitemap, open graph, llms.txt
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Titles, descriptions, robots, canonical, one JSON-LD graph, Open Graph, XML sitemaps and llms.txt: what a shop needs from an SEO plugin, without external calls, cookies or upsells. Imports from Rank Math and switches over in one click.

== Description ==

A large SEO plugin loads dashboards, analytics, AI writing and dozens of modules on every request. A shop needs a handful of signals to be right on every page: the title and the description, whether a page should be indexed, its canonical address, a clean structured-data graph for products, share cards for social networks and sitemaps that search engines can read. Search Signals does those, and nothing else.

Nothing a visitor sees changes after activation: every module is off, and the plugin starts in test mode, where only administrators see its output, until you choose «Live». While Rank Math, Yoast SEO, SEOPress, All in One SEO or The SEO Framework is active, it stays passive and prints nothing, so two SEO layers never run together.

**Titles and meta**

* Title and description templates per post type, taxonomy and archive (home, blog, shop, author, date, search, 404), with variables such as %title%, %sep%, %sitename%, %excerpt%, %term%, %page%, %wc_price%, %wc_brand% and %wc_sku%.
* Per post, page, product and term: own title, description, noindex / nofollow, canonical, share image and the primary category or brand, with a search-result preview and character counters.
* Robots through WordPress's own wp_robots: noindex for search, 404, empty terms and (optionally) archive pages 2, 3 …; drafts, private and password-protected pages are always noindex. max-image-preview:large on everything indexable.
* Canonical with pagination (rel prev / next on archives); none on noindex pages.

**Structured data**

* One JSON-LD graph per page: Organization (or Person) with logo, contact phones and profiles, WebSite with a SearchAction that searches products, WebPage (CollectionPage, SearchResultsPage, ItemPage), primary image, BreadcrumbList, BlogPosting / Article, and Product.
* The Product node is built by WooCommerce itself, so every plugin that extends woocommerce_structured_data_product or _offer keeps working (price, stock, pre-orders, reviews), plus brand, category, GTIN and ISBN for books. WooCommerce's own separate output is removed, so there is one graph.
* Other plugins add their nodes through the documented noxpress_seo_graph filter: Live Operator adds the opening hours, Featured Creators the author and artist pages.

**Social**

* Open Graph (og:title, og:description, og:image with size and alt, og:type, article and product tags) and Twitter summary_large_image cards. The image is the own share image, the featured image, the first image of the content, the default, or the logo.

**Sitemaps**

* /sitemap_index.xml and /{type}-sitemap{N}.xml, the same addresses Rank Math uses, so Search Console needs no change. Up to 2000 addresses per file (200 by default), with the product image and gallery.
* Anything that is noindex (per page or per type), hidden products, cart, checkout and account never go in. The core WordPress filters wp_sitemaps_posts_query_args and wp_sitemaps_taxonomies_query_args apply, so Hide Catalog and Featured Creators keep their exclusions.
* The Sitemap line in the virtual robots.txt; the core /wp-sitemap.xml is turned off and redirects to the index.
* Cached only in the object cache, keyed by WordPress's own last-changed values: a visitor request never writes to the database.

**llms.txt**

* /llms.txt: a plain Markdown list of the site for AI assistants: the newest pages, posts and products (300 per type by default) and the product categories, each with its description. Only what is indexable. Never emails, phones, user names or addresses. /llms-full.txt answers 410.
* If a static llms.txt or llms-full.txt sits in the web root (the server sends it before WordPress runs), the plugin shows it and, on your click only, moves it to a private backup folder in uploads, restorable with one click, then checks who answers /llms.txt. It never touches any other file.

**From Rank Math, in one click**

* Import: Rank Math's titles, descriptions, robots, separator, knowledge graph, phones, profiles, share images, sitemap settings and the per-post and per-term rank_math_* meta. A dry run shows first what will be written. Rank Math's data is only read, never changed. Undo restores the previous values (and keeps what you changed by hand since).
* Compare: for sample addresses of every type (or your own list), what the site prints now (e.g. Rank Math) next to what Search Signals will print, field by field, plus the sitemap index and the number of addresses.
* Switch over: in one request, Rank Math is deactivated (not deleted), Search Signals goes live and the page cache is cleared. Switch back does the reverse.

**Safe by design**

* No external request, no cookie, no tracking, no admin notices on other screens. The only HTTP request is Compare and the llms.txt check, after a click: a GET to this site itself.
* Never in admin, AJAX, REST, feeds, embeds, cron, CLI or XML-RPC. Every callback is wrapped in try/catch: on an error the plugin stops for the rest of the request and the page renders as WordPress would.
* `define( 'SSG_DISABLE', true );` in wp-config.php stops every output (the core sitemaps stay off).
* Modules that are off load none of their code.
* WordPress Abilities API (WordPress 6.9+): `search-signals/status`, `get-meta`, `json-ld`, `sitemap-status`, `compare` (read) and `set-meta`, for Site Bridge.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**Not covered**

* Redirects and the 404 log: use the Redirects plugin, which imports Rank Math's redirections.
* Keyword scores, content analysis, AI writing, Search Console analytics, local SEO pages, news and video sitemaps.
* Visible breadcrumbs (the theme or WooCommerce prints those; Search Signals adds them to the schema).

**For developers**

* `noxpress_seo_is_active(): bool`: whether Search Signals prints the SEO tags of this request. Plugins with their own canonical or JSON-LD skip theirs when it is true.
* Filter `noxpress_seo_graph` ( array $nodes, SSG_Context $context ): the JSON-LD nodes, keyed, the same shape as rank_math/json_ld.
* Filters `noxpress_seo_title`, `noxpress_seo_description`, `noxpress_seo_robots` ( [ 'noindex' => bool, 'nofollow' => bool ] ), `noxpress_seo_canonical` ( $value, SSG_Context $context ).
* Filter `noxpress_seo_sitemap_entry` ( array $entry, string $type, $object ): one sitemap address; false leaves it out.
* Filter `noxpress_seo_llms_sections` ( array $sections ): the sections of llms.txt.
* Action `noxpress_seo_active`: once per request, when the head output is on.

== Installation ==

1. Upload the `search-signals` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub).
2. Activate the plugin and open **Noxpress → Search Signals**.
3. In **Overview**, turn on the modules you want. If Rank Math is active, Search Signals stays passive.
4. Open **Import**, run the dry run, then import.
5. Open **Compare** and check the samples and the sitemaps. Move Rank Math's redirects with the Redirects plugin, and move a static llms.txt in the **llms.txt** tab.
6. Click **Switch over**. After one or two weeks without trouble in Search Console you can delete Rank Math along with its data.

Without another SEO plugin: turn the modules on, check your pages while logged in (test mode), then choose **Live**.

== Frequently Asked Questions ==

= Will my titles and rankings change? =

Not by surprise. Import brings Rank Math's templates and per-page values, and Compare shows every difference before anything goes live. The sitemaps keep the same addresses.

= Does it work with page caches? =

Yes. Test mode marks the pages it serves to administrators as not cacheable, and Switch over, Switch back and set-meta purge the suite's page caches (Fast Pages and others).

= Why is /llms-full.txt 410? =

A full copy of every page is heavy to build and easy to abuse, and AI assistants read the pages themselves. The 410 tells crawlers that it is gone for good.

= What happens on uninstall? =

The options and the temporary data are removed, and the per-page titles, descriptions and robots too, unless you ticked «Keep». A backup folder that still holds a moved llms.txt stays, because that file is yours. Rank Math's data is never touched. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.0.0 =
* Initial release.
* Title and description templates with per-object overrides, robots through wp_robots, canonical and pagination.
* One JSON-LD graph (Organization, WebSite with product SearchAction, WebPage, BreadcrumbList, Article, Product from WooCommerce) with the noxpress_seo_graph filter; Open Graph and Twitter cards.
* Sitemaps at Rank Math's addresses (/sitemap_index.xml, /{type}-sitemap{N}.xml) with images and the robots.txt line; /llms.txt without personal data, /llms-full.txt 410; static llms files moved to a backup on a click, with restore.
* Import from Rank Math with dry run and undo; Compare with the live page and the sitemaps; Switch over and Switch back in one click.
* Test mode, passive while another SEO plugin is active, SSG_DISABLE; no external calls and no cookies.
* Abilities search-signals/status, get-meta, set-meta, json-ld, sitemap-status and compare; Greek and English admin.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.2.9).
