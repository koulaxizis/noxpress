=== Book Preview ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, book preview, pdf, sample, ebook
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Let visitors read a sample of a book on its product page. The server builds a separate PDF with only the pages you choose; the full PDF never reaches the browser.

== Description ==

Book shops sell PDFs, but a buyer wants to leaf through a few pages first. Book Preview adds a "Read a sample" button to the product page. It opens a viewer with only the pages you picked, then a "Keep reading" card with a buy button.

**The sample is a separate file**

* In the product's "Book Preview" box, pick the PDF (one of the product's downloadable files, a variation's, or any PDF in the media library) and the pages, e.g. `1-12, 20, 35-40`. Empty = the first pages from the settings.
* "Create sample" builds a new PDF on the server with those pages only, in `uploads/noxpress-previews/`, under a name nobody can guess. The box shows its pages, size, date and a link to open it.
* The sample is rebuilt in the background when the PDF or the pages change (WooCommerce's Action Scheduler, or WP-Cron).
* Whatever app laid out the book (InDesign, Affinity, Scribus, LibreOffice, LaTeX, Word...), the result is the same: a valid PDF with the chosen pages, their fonts and images, and nothing from the other pages. Images and fonts the chosen pages do not draw are left out, so a sample of 10 pages from a 300-page book stays small.

**The PDF engine**

* Pure PHP, no extension needed. Reads PDF 1.0 to 2.0: classic and compressed cross-reference tables (PDF 1.5+ object streams), incremental updates, linearized files; repairs a damaged cross-reference by scanning the file.
* When a PDF uses something the engine does not read, and qpdf or Ghostscript is installed on the server, they are tried automatically (can be turned off). The settings page shows what the server has.
* Encrypted PDFs (DRM) are refused with a clear message. Every failure says what to do in plain words.

**On the product page**

* The "Read a sample" button below the short description or below the add to cart button, or only through the `[book_preview]` shortcode (`[book_preview id="123"]` for another product, `label="..."` for another text) and the "Book sample" block.
* Works with classic themes (WooCommerce hooks, Astra's own hooks) and block themes (the product excerpt, summary and add to cart blocks). The button keeps the theme's own button style.
* The viewer: full screen, page counter, zoom, keyboard and screen reader friendly, pages drawn only as they scroll into view (light on phones). Close with Esc, the X or a click outside the pages.
* Optional "Keep reading" card after the last page, with the cover, price and a buy button (scrolls to the add to cart form on the product page, adds to cart elsewhere). Not shown for products that cannot be bought.
* Visitor texts (button, card title, buy button) are settings, in Greek and English; the language follows the site or is set by hand.
* PDF.js (Mozilla) is bundled and loaded only on the first click: nothing extra on pages where nobody opens a sample.

**Bulk creation**

* For a category (with or without its subcategories): the first N pages of every book with a PDF, optionally skipping the first K (cover, blank pages, copyright). Only those without a sample, those that also need rebuilding, or all.
* Runs in small batches with a progress bar; it can stop and continue.
* A samples screen lists every product with a PDF, its sample state and errors, with category and state filters.

**Safe by design:** off until enabled; test mode shows the button to shop managers only; `define( 'BPV_DISABLE', true );` in wp-config.php stops every front-end hook. No database writes on visitor requests. Caches (WooCommerce, WP Rocket, LiteSpeed, W3 Total Cache) are purged for a product when its sample changes.

* Hooks: `bpv_text` filters each visitor text ( $text, $key, $lang ); `bpv_tool_path` sets the path of `qpdf` or `gs` when they are not in the server's PATH.
* Backup: export / import the settings as JSON.
* Bilingual admin UI (Greek / English) following the Noxpress language choice, or the WordPress user locale.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

== Privacy ==

No cookies, no tracking, no external calls: the viewer, its fonts and decoders are served from your own site. The visitor's browser downloads only the sample PDF.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `book-preview` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. Open a book's product page in the admin, pick the PDF and pages in the "Book Preview" box and press "Create sample". Or use **Noxpress → Samples → Bulk creation** for a whole category.
5. Enable the button in **Noxpress → BP Settings** (keep test mode on until you have checked a few product pages).

== Frequently Asked Questions ==

= Can a visitor get the full book? =

Not through Book Preview: the viewer only ever loads the sample file, which contains nothing from the other pages. The sample itself can be saved by anyone who opens it, like any page on the web, so choose pages you are happy to give away.

Keep the full PDF as a "Downloadable file" of the product (WooCommerce stores those in a protected folder). A PDF in the media library has a public address; the box warns about it. On nginx, WooCommerce's protection of that folder needs a server rule; the box warns about that too.

= Which PDFs does it read? =

Any PDF that is not encrypted, from any layout app. If the engine cannot read one and qpdf or Ghostscript is on the server, they are used instead. A file that none can read gets a clear message in the box.

= How big will a sample be? =

About the share of the book's pages, plus the fonts. Print PDFs with high-resolution images make heavier samples; the box warns above a size you set (10 MB by default). An e-book export of the same book is a better source.

= Does it change my PDF or my products? =

No. The source PDF is only read. The plugin stores its choices in product meta (`_bpv_source`, `_bpv_pages`, `_bpv_hidden`) and the sample details in `_bpv_sample`.

= What happens on uninstall? =

Everything the plugin created is removed: its options, product meta, background jobs and the sample files in `uploads/noxpress-previews/` (the folder too, when nothing else is in it). Your PDFs are never touched.

== Changelog ==

= 1.0.0 =
* Initial release.
* Own pure-PHP PDF engine (classic and compressed cross-references, object streams, incremental updates, repair), unused images and fonts left out; qpdf and Ghostscript as automatic fallbacks.
* Product box: PDF from downloadable files or the media library, page list with clear errors, create / delete, state, size and warnings.
* Background rebuild when the PDF or the pages change.
* Bulk creation per category with first N pages and skip K, in batches with a progress bar; samples screen with filters.
* "Read a sample" button below the short description, below add to cart, by shortcode or block; classic themes, Astra hooks and block themes.
* Full-screen PDF.js viewer (bundled, loaded on the first click) with lazy pages, zoom and an optional "Keep reading" card.
* Greek and English visitor texts, test mode, BPV_DISABLE, backup / import, server check.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.1).
