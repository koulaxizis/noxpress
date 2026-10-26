=== Featured Creators ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, authors, creators, contributors, author page
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

One page for every creator of your catalog (authors, illustrators, translators, musicians…), filled automatically with their products by role from your WooCommerce attributes, and their name turned into a link across the store.

== Description ==

**Creator pages**

* A "Creators" post type under the Noxpress menu: photo (featured image), roles line, biography (the content), links, and one list of interviews, videos and reviews, each with date, outlet, title and URL.
* Links to websites, music services and social networks get the service name and an icon from the address (Spotify, Apple Music, Bandcamp, YouTube, Instagram, Facebook, TikTok, X, Bluesky, Goodreads and more). Videos are links, not embeds, so no third-party cookies.
* The page: header with photo, name, roles and links; the biography; one section per role in the order you set (e.g. "Books as author", "Books as illustrator"); a separate "Bundles" group; then the interviews, videos and reviews, newest first.
* Products are shown with the theme's own WooCommerce cards. Only published products visible in the catalog; out-of-stock products are hidden when WooCommerce hides them.
* With Product Formats active, the formats of one work show as one card with "In 3 formats".
* Classic themes (Storefront, Astra and others) get a template with the theme's header, footer and container, with configurable space above the page. Block themes (Twenty Twenty-Five) get a "Creator page" template you can change in the Site Editor (WordPress 6.7+), or the page inside the theme's own single template on older versions. A theme can also ship its own `single-nox_creator.php`.

**Matching**

* You choose which global attributes (`pa_…`) are creator roles, and their order.
* A role can match without a section of its own on the creator page (e.g. typesetting with sixty products): it still counts for the roles line, the links and the schema.
* A creator gets every term with the same slug in every role automatically (the key defaults to the page slug).
* Manual fixes on the creator screen: exclude a term (homonyms), add extra terms from any role (pseudonyms, other spellings) with a search box.
* A term belongs to one creator only: manual beats automatic, and two equal claims are a conflict, shown in the admin, until you resolve it.
* Owners and counts are computed when something changes (creators, terms, product terms), never on visitor requests.

**Across the store**

* Names in the "Additional information" tab become links to the creator page, for public creators only. All other rows stay exactly as WooCommerce prints them.
* A creator box under the product description (or after the tabs): photo, role, two lines of biography and a link, for the roles you tick, up to a maximum number of cards.
* A creator tab on the product, for the roles you tick: photo, short biography, links and the three newest interviews, videos or reviews, with a link to the creator page. The title follows the creator and the role: «Ο συγγραφέας», «Η εικονογράφος», «Συγγραφέας» (no article), «Οι συγγραφείς» for several authors, «Οι δημιουργοί» for mixed roles.
* An index of creators with a role filter (plain links that also work with page caches, and without reload with JavaScript) and A–Z order by a "sort name" (e.g. surname first). Greek and Latin names are sorted with accents ignored.
* Shortcodes `[nox_creators]` (or `[nox_creators role="pa_…"]`) and `[nox_creator_box]` (or `[nox_creator_box id="123"]`), and the blocks "Creators", "Creator box" and "Creator page".

**Admin**

* Settings tabs: Roles, Pages and products, Texts, SEO, Coverage, Import, Tools.
* Coverage: how many products have a role, products without a role, people with products but no creator page, published creators without products, conflicts.
* Import: draft creators from the terms of the roles, one person per slug, in batches with a progress bar. Running it again never creates duplicates.
* Creator list with photo, roles, products and key columns.

**SEO**

* Creator pages: ProfilePage with a Person (name, photo, description, roles as jobTitle, links as sameAs).
* With Rank Math active, the data goes into Rank Math's own graph: its WebPage becomes ProfilePage and the Article it would add for the page is removed. Title, description and sitemap stay with Rank Math. Without Rank Math the plugin prints its own JSON-LD.
* Products: schema.org Product has no author, so you map product categories to a work type (Book, Audiobook, MusicAlbum, Movie). The product is then declared as both, with each role as its property (author, illustrator, translator, readBy, director, byArtist…); a property the type does not have becomes contributor. Works with Rank Math's product markup and with WooCommerce's own.
* Creator pages are in the sitemaps only when the plugin is live.

**Safe by design**

* Test mode after activation: creator pages, links, boxes, the index and the schema are visible to shop managers only; everyone else gets 404 on creator pages and nothing elsewhere.
* A creator page is public only when published and without a password.
* `define( 'CRT_DISABLE', true );` in wp-config.php stops every front-end hook.
* No cookies, no external requests, no data about visitors.
* Every visitor text in Greek and English, editable; the admin in Greek and English.
* HPOS compatible.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `crt_get_creators( int $product_id ): array` — the public creators of a product: `id`, `name`, `url`, `roles` (attribute taxonomies).
* REST: `/wp/v2/nox-creators` with `context=edit` reads and writes the creator's own meta (`_crt_roles_text`, `_crt_sort_name`, `_crt_gender`, `_crt_links`, `_crt_press`, `_crt_key`, `_crt_extra`, `_crt_exclude`; lists as JSON strings) for users who may edit the creator, with the same validation as the edit screen.
* `crt_text` ( string $text, string $key, string $lang ): change any visitor text. Role texts use the keys `h:pa_…` (section title), `l:pa_…` (role name) and `p:pa_…` (role name in the plural).
* `crt_creator_changed` ( int $creator_id, int[] $product_ids ): fired when the products of a creator change, e.g. for page caches. The plugin already calls `clean_post_cache()` for them.

== Installation ==

1. Make sure WooCommerce is installed and active, with your creators as global product attributes (Products → Attributes), e.g. "Author", "Illustrator", "Translator".
2. Upload the `featured-creators` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin. It starts in test mode.
4. Open **Noxpress → FC Settings → Roles**, tick the attributes that are roles, drag them into order and save.
5. On the **Import** tab create draft creators from the terms, then add photos, biographies and links and publish them.
6. Put `[nox_creators]` (or the "Creators" block) on a page for the index and choose it on the **Pages and products** tab.
7. Check the **Coverage** tab, look at the pages in test mode, then switch to **live**.

== Frequently Asked Questions ==

= How does a creator find their products? =

By the slug of the attribute terms. "Maria Papadopoulou" as an author and as an illustrator has the same slug in both attributes, so one creator with that key gets both. A creator whose name is written differently somewhere gets that term through "Extra terms".

= Two different people have the same name. =

Give each creator a different key, or exclude the term on one creator and add it as an extra term on the other.

= Can I keep my existing hand-made author pages? =

Create the creators from the terms and copy what you need. Since 1.1.0 the creator's meta can also be written through REST, so a script can fill creators from existing pages. A tool in the admin that converts hand-made pages and keeps or redirects their URLs is planned.

= The page sticks to my theme's header. =

Set "Space above the page" on the **Pages and products** tab, e.g. `2.5em` or `40px`.

= Does it work with page caches? =

Yes. The index filter uses plain links, and when the products of a creator change, the plugin cleans the post cache of the affected products and pages and fires `crt_creator_changed` for cache plugins.

= What happens on uninstall? =

Settings, notices and the term ownership meta are deleted. Creators are kept by default, because they are content you wrote; tick "Delete the creators" on the **Tools** tab before deleting the plugin to remove them too. Photos always stay in the media library. Products and attributes are never touched.

== Changelog ==

= 1.2.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.1.0 =
* New: creator tab on the product, for the roles ticked "In the tab" (Pages and products → On the product, with its WooCommerce priority). Photo, short biography, links, the three newest press items and a link to the creator page; only published creators; no tab when the product has none.
* New: tab title by the creator's gender (new field "Article in the tab title": «Ο», «Η» or none) and the role, or the role in the plural for several creators of the same role; five editable visitor texts.
* New: role name in the plural (Roles tab).
* New: "Section on the page" per role: a role can match and show in the roles line without listing its products on the creator page.
* New: the creator's own meta in REST (edit context, edit_post capability), validated like the edit screen.

= 1.0.0 =
* First release (beta).
* Creator post type with photo, roles line, biography, links and lists of interviews, videos and reviews.
* Matching by slug across the chosen role attributes, with exclusions, extra terms and conflict detection.
* Creator page for classic and block themes, with one section per role, a bundles group and the press lists; one card per work with Product Formats.
* Links in "Additional information", creator box on the product, index with role filter and A–Z order; shortcodes and blocks.
* Draft creators from the terms, coverage check, settings export and import.
* ProfilePage and Person schema (Rank Math graph or standalone JSON-LD), creators on products as author, illustrator and more.
* Test mode, CRT_DISABLE, Greek and English texts, clean uninstall.
