=== Book Feed ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, books, isbn, bookpoint, product import
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Bring titles of other publishers from a book catalogue (Bookpoint / OSDEL web service) into WooCommerce: search and import (also every result at once, in the background), rules that bring new titles every few days, a daily sync of price and availability, and locks for whatever you edit by hand.

== Description ==

**Search and import**

* Search the catalogue by title, contributor, ISBN, publisher (with autocomplete), years and kind.
* Each result shows whether the title is already in the store, or whether a product with the same ISBN exists. That product can be linked with one click instead of being duplicated.
* Import the ticked titles as drafts or published, into a category you choose or the one mapped in the settings.
* "Import all results" brings every title of a search, on every page, in the background: you can close the page, the progress and a stop button show on every tab. Titles already in the store are skipped, and a limit (1,000 by default, up to 5,000) keeps a search without filters from filling the store.
* "Save search as rule" turns the search into a rule that brings its new titles automatically.
* Every title is fetched again from the catalogue on the server: nothing that the browser sends ends up on a product.

**What a product gets**

* Title (with the subtitle in the title, in an attribute or nowhere), description and short description, ISBN as GTIN and SKU, the cover (and optionally the back cover) downloaded into the media library, weight and dimensions, tax class from the VAT rate.
* Publisher, contributors per role, year, pages, language, series, binding and kind as attributes: store attributes (pa_, filterable in Shop Filters and widgets), attributes of the product only, or skipped. The publisher can also be a brand.
* Catalogue categories map to store categories, optionally with their parent categories; they can also become an attribute (e.g. a "Genre" attribute). Each publisher can also get a subcategory of its own under a category you choose, with its brand logo as the image; [bkf_publishers] shows their logos in a small sliding strip (names="1" adds each name under its logo). Subjects and keywords (or the categories) can become product tags.
* Fit the products to the store's own structure: the order of the attributes on the product, renamed values ("Soft cover = Paperback", or dropped), and templates for the cover's file name and alt text (`{title}`, `{subtitle}`, `{authors}`, `{publisher}`, `{isbn}`).
* A product-only "display" mode makes an external product with a button to another site (`{isbn}`, `{isbn13}`, `{title}` in the address).

**Daily sync**

* Price and availability always follow the catalogue. Title, descriptions, cover, attributes, dimensions and tax class follow it too, until you edit them by hand: from then on that field is locked, and the history says so.
* A title that leaves the catalogue becomes out of stock (default) or a draft, and comes back when it returns.
* If the catalogue suddenly returns none of the store's titles, the sync stops without changing anything.
* Rules bring new titles in automatically: a publisher, title words or a contributor, with kind, publishing status and years, checked every X days (1–90), as drafts or published, with "Run now". One summary email when they bring something (can be turned off), and a history line per rule even when there is nothing new.
* Runs in the background (Action Scheduler) in steps of up to 10 seconds, one at a time, with a safety action and stall detection. "Sync now" and a live status on every tab.

**Prices from the publishers' own sites**

* Catalogue prices are often old. For each publisher you list, the plugin reads the price from the book's page on the publisher's site (schema.org structured data, a WooCommerce Store API answer, Open Graph or microdata) and uses it instead, on import and on every sync. While the publisher's shop runs a sale, its list price is taken.
* A page that names another ISBN is ignored. A price below half or above double the catalogue's is not used. When the page cannot be read, a price read in the last 7 days still counts, else the catalogue's. Every change goes to the history; one look per title per day.
* Publishers whose addresses do not carry the ISBN: put the book's page in the Book Feed box of the product; the price is read from there.
* Pages without structured data: the first number after words such as "Τιμή εκδότη" (setting, one per line) is taken, before any other price on the page, since the publisher's e-shop often shows its own discounted price. When the page also lists other books with the same words, only the price right after this book's title counts; if none can be told apart, no price is taken from the page.
* Covers: the catalogue gives one small image. When the publisher's page has a cover (schema.org image or og:image) with more pixels than the product's, it takes its place; a smaller one is deleted again and not downloaded every day (setting, on by default).
* "Read the price" in Tools tries one ISBN (and, optionally, a page address) without changing anything.

**Fixed book price**

* For titles with a fixed-price end date in the catalogue, saving a sale price with a larger discount than allowed shows a warning (the percentage from the catalogue's price type, else the setting, default 10 %). Nothing is blocked.

**Safe by design**

* The service password is stored sealed (AES-256-GCM with the keys in wp-config.php) and never shown, logged or exported; `define( 'BKF_PASSWORD', '…' );` works too. https is tried first.
* Unknown values in the catalogue (kinds, statuses, roles, categories, VAT rates) are listed in the settings for mapping; an availability value that is not mapped makes a title out of stock.
* History of the last 500 actions with CSV export (Excel Greek or standard). Settings backup without the password.
* Nothing on the front end: visitors see ordinary WooCommerce products. HPOS compatible.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `bkf_get_source( $product_id )`: where a product came from (`source`, `id`, `isbn`, `synced`, `linked`), or null.
* Fires `noxpress_products_changed( $ids )` after each import or sync step that changed products.
* WP-CLI: `wp book-feed status`, `wp book-feed search --title=…` (read only), `wp book-feed sync --yes`.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `book-feed` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open **Noxpress → Publishers' titles → BF Settings**, enter the user name and password you got from OSDEL and press "Save and test the connection".
5. Search, tick and import a few titles as drafts, check them, then add rules per publisher if you like.

== Frequently Asked Questions ==

= Do I need an account? =

Yes. The web service needs a user name and password from OSDEL (Bookpoint). Make sure your contract allows publishing descriptions and covers.

= What if I already sell a title? =

A product with the same ISBN (in GTIN, SKU or the plugin's own field) is never duplicated. "Link" marks it as a catalogue title without changing anything; "Update from the catalogue" on the product takes it over later, if you want.

= Will the sync overwrite my edits? =

No. Each field the plugin wrote has a fingerprint; when you change the field, the sync leaves it alone. "Unlock and update everything" on the product writes every field again.

= How do I set a shipping time just for these titles? =

Put them in their own category and set preparation days on that category in Shipping Notes.

= What happens on uninstall? =

The settings, the history, the password, the scheduled actions and the plugin's marks on products are removed. Products, images and categories stay, unless "Also delete the imported products" is ticked; then the products the plugin created are deleted (linked products and images always stay). The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.8.1 =
* Fixed: a rule run skipped the rest of a page of results when a step ran out of time, so a first run could import only part of a publisher's titles (165 found, 97 imported). The next step now reads the same page again; titles already imported are skipped.

= 1.8.0 =
* "Publishing status" filter in the search and in rules: any status, or one value of the feed, such as «Κυκλοφορεί», so out-of-print or forthcoming titles stay out. "Save search as rule" and "Import all results" keep it.

= 1.7.1 =
* [bkf_publishers] names="1" prints each publisher's name under its logo.

= 1.7.0 =
* "Import all results": every title of a search, on every page, imported in the background with the status and category you pick, under the same lock as the sync. A confirmation names the number of titles and warns when they will be published at once. Progress and a stop button on every tab; titles already in the store are skipped; a limit per run (setting, 1,000 by default, 100–5,000).
* "Save search as rule": a new rule with every filter of the search (title words, contributor, publisher, kind, years), the status and category you picked, checked every 7 days.
* Rules: any search, not only a publisher; "check every X days" (rules from earlier versions keep running daily); the status of the new titles (draft or published); "Run now" on each rule; the next run date on the card.
* After a run, one history line per rule ("new titles 0" included) and one summary email when rules brought new titles (setting, on by default; the site's admin address unless you give another).

= 1.6.0 =
* Shortcode [bkf_publishers]: the logos of the publisher subcategories that have titles, in a small strip that drifts slowly sideways, each linking to its subcategory. Greyscale until hovered; stops on hover and focus; stands still for visitors who ask for reduced motion. Attributes height, speed, parent.
* A brand logo now also reaches a publisher subcategory made by hand with the same name.

= 1.5.0 =
* A subcategory per publisher (setting, off by default): each publisher becomes a subcategory of the category you choose, with the logo of the brand of the same name as its image. Each title goes into it once: on import, or at the next sync for titles already in the store; a title taken out by hand is not put back.
* A category image you set yourself stays; while the subcategory still shows the copied logo, a new brand logo replaces it.

= 1.4.2 =
* Labelled publisher price: a small number between the title and the price (a series number) no longer hides it; a price, a year or an ISBN still does.
* When the publisher's page answers but has no price for the book, the catalogue price is used at once and the old publisher price is dropped. The last good price still covers a page that cannot be read.

= 1.4.1 =
* Labelled publisher price: on pages that also list other books (the author's other titles, "readers also bought"), the price after this book's title is taken, not the first one on the page; when the prices cannot be told apart, none is taken.
* Publisher cover: when the page links the same picture as a file of its own (a gallery link around a resized image), that full-size file is used.

= 1.4.0 =
* The cover from the publisher's page (schema.org image or og:image), only when it is larger than the catalogue's; setting, on by default.
* "Sync now" in the product box also keeps the page address typed there, without saving the product first.
* Weight and dimensions without trailing zeros ("600 g", not "600.000").

= 1.3.0 =
* A page on the publisher's site per product (Book Feed box), for publishers without an address per ISBN.
* Publisher price from the page's text after a label ("Τιμή εκδότη" by default, editable), read first.
* Contributor names as "Name Surname" instead of the catalogue's "Surname, Name" (setting).
* "Read the price" in Tools accepts a page address.

= 1.2.0 =
* Prices from the publishers' own sites: a page address per publisher ({isbn}, {isbn13}, {title}); the price found there replaces the catalogue's automatically, with plausibility checks and history lines.
* Publisher price on the product box, and "Read the price" in Tools.
* The connection test reports https correctly when the address itself is https.

= 1.1.0 =
* Order of the attributes on the product, by name; the default order now starts with the ISBN.
* Value renames for publisher, format, kind, categories, language, series and tags (an empty right side drops the value).
* Catalogue categories as an attribute (e.g. "Genre"), and the parent categories of a mapped category.
* Product tags on import from the catalogue's subjects and keywords, or from its categories, with a limit.
* Templates for the cover's file name and alt text.
* A contributor with a combined role ("Author, Illustration") counts for each role.
* The default address is https; the http warning shows only when the connection really uses http.
* Subjects and keywords seen in the catalogue are listed under the value renames.

= 1.0.0 =
* Initial release.
* Bookpoint / OSDEL Solr web service: search (title, contributor, ISBN, publisher with autocomplete, years, kind), https first, sealed password, redacted errors.
* Import into simple or external products: descriptions, ISBN (GTIN, SKU), cover and back cover into the media library, attributes per field and per contributor role, categories, tax class from the VAT rate, weight and dimensions.
* Same-ISBN detection with "Link", and "Update from the catalogue" / "Unlink" on the product.
* Daily background sync: price and availability always; other fields until edited by hand (field locks); titles that leave the catalogue go out of stock or to draft; mass-disappearance guard.
* Rules per publisher that import new titles as drafts.
* Fixed book price warning on save.
* Mapping tables built from the values seen in the catalogue.
* History with CSV export, settings backup, WP-CLI, clean uninstall.
