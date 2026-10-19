=== Book Feed ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, books, isbn, bookpoint, product import
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Bring titles of other publishers from a book catalogue (Bookpoint / OSDEL web service) into WooCommerce: search and import, rules per publisher, a daily sync of price and availability, and locks for whatever you edit by hand.

== Description ==

**Search and import**

* Search the catalogue by title, contributor, ISBN, publisher (with autocomplete), years and kind.
* Each result shows whether the title is already in the store, or whether a product with the same ISBN exists. That product can be linked with one click instead of being duplicated.
* Import the ticked titles as drafts or published, into a category you choose or the one mapped in the settings.
* Every title is fetched again from the catalogue on the server: nothing that the browser sends ends up on a product.

**What a product gets**

* Title (with the subtitle in the title, in an attribute or nowhere), description and short description, ISBN as GTIN and SKU, the cover (and optionally the back cover) downloaded into the media library, weight and dimensions, tax class from the VAT rate.
* Publisher, contributors per role, year, pages, language, series, binding and kind as attributes: store attributes (pa_, filterable in Shop Filters and widgets), attributes of the product only, or skipped. The publisher can also be a brand.
* Catalogue categories map to store categories, optionally with their parent categories; they can also become an attribute (e.g. a "Genre" attribute). Subjects and keywords (or the categories) can become product tags.
* Fit the products to the store's own structure: the order of the attributes on the product, renamed values ("Soft cover = Paperback", or dropped), and templates for the cover's file name and alt text (`{title}`, `{subtitle}`, `{authors}`, `{publisher}`, `{isbn}`).
* A product-only "display" mode makes an external product with a button to another site (`{isbn}`, `{isbn13}`, `{title}` in the address).

**Daily sync**

* Price and availability always follow the catalogue. Title, descriptions, cover, attributes, dimensions and tax class follow it too, until you edit them by hand: from then on that field is locked, and the history says so.
* A title that leaves the catalogue becomes out of stock (default) or a draft, and comes back when it returns.
* If the catalogue suddenly returns none of the store's titles, the sync stops without changing anything.
* Rules per publisher bring new titles in automatically, always as drafts.
* Runs in the background (Action Scheduler) in steps of up to 10 seconds, one at a time, with a safety action and stall detection. "Sync now" and a live status on every tab.

**Prices from the publishers' own sites**

* Catalogue prices are often old. For each publisher you list, the plugin reads the price from the book's page on the publisher's site (schema.org structured data, a WooCommerce Store API answer, Open Graph or microdata) and uses it instead, on import and on every sync. While the publisher's shop runs a sale, its list price is taken.
* A page that names another ISBN is ignored. A price below half or above double the catalogue's is not used. When the page cannot be read, a price read in the last 7 days still counts, else the catalogue's. Every change goes to the history; one look per title per day.
* "Read the price" in Tools tries one ISBN without changing anything.

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
