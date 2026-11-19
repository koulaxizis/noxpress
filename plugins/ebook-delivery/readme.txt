=== Ebook Delivery ===
Contributors: koulaxizis
Donate link: https://noxpress.tech/support/
Tags: ebook, watermark, pdf, epub, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Buyer watermarks for the PDF and EPUB files you sell with WooCommerce, a «My library» page in the customer's account, and a tool that tells you which order a leaked file came from.

== Description ==

A PDF bought from your shop and posted on a forum looks exactly like every other copy. Ebook Delivery makes every downloaded copy personal: the buyer's name, the order number and a short code «ED-…» on every page, and more details hidden inside the file. People think twice before sharing a book with their name on it, and if one turns up somewhere, the code tells you whose it was.

Nothing changes after activation: the mode is off until you choose test or live.

**How it works**

* Downloads stay WooCommerce's. The plugin hooks into the download after WooCommerce has checked the link, the order and the e-mail, and before the file is sent; download limits, expiry, the download log and the «Downloadable product permissions» box of the order work as before.
* PDF: a small grey line at the bottom of every page («Αντίτυπο της Μαρίας Π. · Παραγγελία #1234 · ED-7K3QX9AB», your text), the code also as invisible text and in the document info, with the full name. The original is not rebuilt: the stamp is a standard incremental update appended to the file, so fonts, images, links, bookmarks and forms stay byte for byte. Rotated and landscape pages are handled.
* EPUB (2 and 3): an «Ex libris» page after the cover, a short line at the end of every chapter and the details in the package metadata. The file passes EPUBCheck as before.
* The line uses an embedded subset of Noto Sans (Latin, Greek, polytonic Greek, Cyrillic), so it renders the same in every reader.
* The e-mail address is never written into the file.
* Each copy is made on the first download (a few milliseconds for most books) and kept for 1–30 days (default 7) in a private folder, so the next downloads of the same purchase are instant. Then it is deleted.
* Encrypted or DRM files, damaged files, files over the size limit (default 300 MB) or on another server are sent as they are, never refused: the buyer always gets the book, and you see a note on the Status tab.
* Other downloadable files (MP3, ZIP, MOBI…) are sent as they are. A product can be left out with «No watermark» in its download options.

**My library**

* A page in My Account with the customer's books: cover, purchase date, one button per file, downloads left and expiry. It replaces WooCommerce's «Downloads» tab (a setting keeps both); the old address keeps working.
* A link to it in the order e-mails that have digital files.
* Clean file names: the buyer gets «Author - Title.pdf» instead of the name of the file on your server (a setting; the author comes from the product attribute you choose).

**Who shared it?**

* Enter the code printed in a file, or upload the file itself, and you see the order, the product, the buyer and the download count (the abilities for AI tools get the e-mail masked). Codes are not stored: each is an HMAC of the order, the product and the file with a secret key of your site, so a code from another shop never matches yours.

**Download limits**

* Defaults for new downloadable products (limit and expiry), and a bulk apply to all downloadable products with a dry run first and a backup of the previous values that can be restored. Purchases already made never change.

**Safe by design**

* Copies live in a folder with a random name inside uploads, with deny files for Apache, LiteSpeed and IIS, under names that are an HMAC; they are sent only through WooCommerce's «Force downloads». A self-test on the Status tab checks that the folder does not answer from a browser (on nginx add a deny rule).
* EPUB files are opened with size and name limits (no zip slip, no zip bomb) and their XML without external entities.
* Test mode: watermark and library only for shop managers, on their own test orders.
* `define( 'EBD_DISABLE', true );` in wp-config.php sends every file as it is and hides the library.
* Suggested text for your privacy policy (Settings → Privacy). Settings export / import.
* WordPress Abilities API (WordPress 6.9+): `ebook-delivery/status` and `ebook-delivery/lookup-code` (read only; the buyer's e-mail is masked).
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**Not covered**

* A watermark discourages sharing; it cannot stop someone who removes it on purpose. It is not DRM, and it never locks a book to a device.
* Files hosted on another server (a URL instead of a file in your uploads) are sent as they are.
* Very old PDFs whose structure is damaged are sent as they are (a note on the Status tab says which).

**For developers**

* Filter `ebd_should_stamp` ( bool $stamp, WC_Product $product, WC_Product_Download $download, WC_Order $order ).
* Filter `ebd_line_text` ( string $text, array $context ).
* Filter `ebd_download_name` ( string $name, WC_Product $product, string $ext ): the clean file name without extension.
* Filter `ebd_library_items` ( array $items, int $user_id ).
* Filter `ebd_library_endpoint` ( string $slug ), default `library`. Save the permalinks after a change.
* Filter `ebd_text` ( string $text, string $key, string $lang ).
* Action `ebd_stamped` ( string $path, array $context ), after every new copy.

== Installation ==

1. Upload the `ebook-delivery` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub). WooCommerce is required, and the PHP zip extension for EPUB files.
2. Activate the plugin and open **Noxpress → Ebook Delivery**.
3. On **Tools**, upload one of your PDFs or EPUBs to **Watermark test** and look at the copy.
4. Choose **Test**, place a test order for an ebook as a shop manager and download it from the order e-mail or **My library**.
5. Switch the mode to **Live**.

== Frequently Asked Questions ==

= Does the buyer's download get slower? =

The first download of a purchase makes the copy, which takes milliseconds for most books and about a second for very large ones; later downloads are served straight from the copy.

= Can a buyer remove the watermark? =

Someone determined can. The visible line, the hidden text, the metadata and the Ex libris page are there so that a casual share carries the buyer's name; the code still works if only one of them survives.

= What if I change a source file? =

A copy is tied to the source file's size and modification time, so a new file gets new copies. If you replace a file with one of exactly the same size and time, use **Delete all copies** on the Status tab.

= What happens on uninstall? =

The settings, the issue list, the secret key, the «No watermark» product flags and the private folder with its copies are removed. Download permissions, limits, the download log and orders are not touched, and customers keep downloading the original files. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Third-party ==

Noto Sans (subset), SIL Open Font License 1.1. See LICENSES.md.

== Changelog ==

= 1.0.2 =
* Noxpress Core 1.5.0: a short «Noxpress» menu with your favourites (★) or your 5 most recent plugins, one row per plugin with tabs for its other pages, hub categories and search, every suite page in the command palette (Ctrl/Cmd+K) and a «Full menu» switch per user.

= 1.0.1 =
* Noxpress Core 1.4.16: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.0.0 =
* Initial release.
* PDF watermark as an incremental update (visible line on every page, hidden code, document info), rotation aware, classic and stream cross-reference tables.
* EPUB 2/3 watermark: Ex libris page, line at the end of every chapter, package metadata; EPUBCheck clean.
* Embedded Noto Sans subset for Greek, polytonic Greek, Latin and Cyrillic.
* Cached copies (1–30 days) in a protected private folder, daily cleanup, issue list with fall back to the original file.
* «My library» in My Account, link in order e-mails, clean file names.
* Fingerprint code lookup by code or by file.
* Defaults for new products and bulk download limits with backup and restore.
* Off / test / live mode, Greek and English buyer texts, watermark test tool, privacy policy text, settings export / import, EBD_DISABLE, Abilities API.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.4.13).
