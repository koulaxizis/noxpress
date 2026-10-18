=== Wish Shelf ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, wishlist, wish list, save for later, favorites
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A wishlist for WooCommerce: a heart on shop cards and product pages, guest lists without cookies, variations, price-drop notes, add all to cart, share links, a My Account tab and a report of what customers want.

== Description ==

**The heart**

* On shop, category and related-product cards (before or after the add-to-cart button, or over the image) and on the product page (before or after the add-to-cart form). Heart and text, heart only or text only.
* Classic and block themes: next to the "Product Button", "Product Image" and "Add to Cart" blocks, plus a "Wishlist button" block and `[nox_wishlist_button]` for anywhere else.
* On variable products the product page saves the variation the customer chose.
* A small notice after the click, with a "View wishlist" link.
* The button's HTML is the same for every visitor, so page caches keep working: the browser sets its state.

**Guests without cookies**

* A guest's list lives only in their browser (localStorage). Nothing is stored on the server and no cookie is set.
* When the guest signs in, the browser list joins their account list and is emptied from the browser.
* Or ask guests to sign in first.

**The list**

* A list page (`[nox_wishlist]` or the "Wishlist" block; one click creates it) and a "Wishlist" tab in My Account.
* Table (image, price, availability, date added, quantity, add to cart, remove; each column optional) or the theme's own product cards.
* "The price dropped by …" for signed-in customers.
* Add to cart per row, "Add all to cart", "Add selected to cart", "Empty wishlist". Products that need options open their page.
* Optionally remove products from the list once they go to the cart.
* A counter in a theme menu, as a block or with `[nox_wishlist_count]`.

**Sharing**

* Guests share a link with the products in the address. Signed-in customers create a private link with a random key, and can replace or stop it. The customer's name never shows.
* Copy link and the device's own share menu. No social network scripts. Shared pages are noindex.

**What customers want**

* A report of the products most often in lists, with stock, price and how many order lines were bought from a list; CSV export (Excel Greek or standard).
* A ♥ column in Products, sortable.

**Moving from another plugin**

* Tools › Import copies the signed-in customers' lists from WishSuite, without duplicates and without changing its data.
* While another wishlist plugin is active (WishSuite, YITH, TI, Addonify, WPC, WebToffee), Wish Shelf stays in test mode so visitors never see two buttons.

**Safe by design**

* Off by default, with a test mode that shows the wishlist to shop managers only.
* `define( 'WSH_DISABLE', true );` in wp-config.php stops every front-end hook.
* No cookies, no external requests. Signed-in changes go through a nonce, a whitelist, an item limit (200) and a rate limit. HPOS compatible.
* WordPress's personal data export / erase tools and a suggested privacy policy paragraph.
* Editable Greek / English customer texts and settings export / import.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `wsh_get_items( $user_id )`: a signed-in customer's list (`product_id`, `variation_id`, `added`).
* `wsh_product_count( $product_id )`: in how many customers' lists a product is.
* Actions `wsh_item_added` and `wsh_item_removed` ( int $user_id, int $product_id, int $variation_id ).
* Filters `wsh_text` ( string $text, string $key, string $lang ) and `wsh_max_items` ( int $max ).

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `wish-shelf` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin.
4. In **Noxpress → Wishlist → List**, create the list page (or pick a page with `[nox_wishlist]`).
5. Coming from WishSuite? Import the lists in **Tools**, then deactivate WishSuite.
6. Test with test mode, then switch to **live**.

== Frequently Asked Questions ==

= Why does the report count only signed-in customers? =

A guest's list never leaves their browser, so the store cannot see it. "Bought from a list" counts guests too, because it is marked when a product goes from a list to the cart.

= Does a guest keep the list on another device? =

No. The list is in that browser only. Signing in keeps it in the account, on every device.

= Does it work with page caches? =

Yes. The heart is the same HTML for everyone and the guest list page loads its products after the page. The list of a signed-in customer and shared lists are never cached.

== Changelog ==

= 1.0.0 =
* Initial release.
* Heart on shop cards (before / after the button, over the image) and on the product page, classic and block themes; button, list and counter blocks and shortcodes.
* Guest lists in localStorage (no cookies), merged into the account on sign-in, or sign-in required.
* Saved variations, price-drop note, add to cart per row / all / selected, remove after adding, empty list.
* Table or theme-card layout, My Account tab, menu counter.
* Share links (guest ids or a private random key) with copy and the device share menu, noindex.
* Report of the most wanted products with "bought from a list", CSV export, ♥ column in Products.
* Import from WishSuite; test mode while another wishlist plugin is active.
* Privacy export / erase, editable Greek / English texts, settings export / import.
* Test mode, WSH_DISABLE, HPOS compatible.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.12).
