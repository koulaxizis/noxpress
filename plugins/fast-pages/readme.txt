=== Fast Pages ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: cache, page cache, performance, minify, lazy load
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

A page cache for visitors with smart purging and background preload, CSS / JS minify, lazy load and click-to-play YouTube. No account, no external service, and Claude can run it through Site Bridge.

== Description ==

Fast Pages keeps a ready copy of every public page and gives it to visitors before WordPress loads. It replaces the parts of WP Rocket that a typical WordPress or WooCommerce site uses, with defaults that are safe for shops.

**Page cache**

* A drop-in (`wp-content/advanced-cache.php`) answers visitors from `wp-content/cache/fast-pages/` before WordPress loads. Works on Apache, Nginx and LiteSpeed, with no `.htaccess` rules.
* Never cached: logged-in users, the cart, the checkout and the account pages, visitors with a cart or a pending comment, searches, feeds, 404s, addresses with parameters, and any page that sends a cookie or asks not to be cached (`DONOTCACHEPAGE`). Tracking parameters (utm_*, fbclid, gclid…) are ignored.
* A page lives 10 hours by default, so forms never keep an old nonce. One cache for all devices, or a separate one for mobile when the theme needs it.
* Every answer says what happened in the `X-Fast-Pages` header: HIT, MISS or BYPASS with the reason.

**Smart purging and preload**

* Saving a post, page or product purges that page, the home page, its archives and its terms. A stock change (also from an order) purges the product. Theme, menu, widget and Customizer changes purge everything.
* Other plugins ask for a purge with `do_action( 'noxpress_purge_page_cache' )` (everything) or `do_action( 'noxpress_purge_page_cache', array( $url ) )`.
* Background preload from the sitemap (Rank Math, Yoast or WordPress), 10 pages per minute by default, with Action Scheduler when available. Purged pages are refilled first.

**Optimizations (off until you turn them on)**

* Minify of the site's CSS and JavaScript files: one smaller copy per file, no combining, so nothing breaks the order of scripts.
* Lazy load for images, iframes and inline background images; the first images of the page load right away.
* YouTube as an image with a play button: the player and its cookies load only on click, from youtube-nocookie.com.
* Missing width / height on local images (less layout shift).
* Preload on hover through WordPress's speculative loading (6.8+), with the cart, the checkout and the account always left out.

**Moving from WP Rocket**

* Tools → Import from WP Rocket reads WP Rocket's settings (never changes them) and copies minify, lazy load, YouTube, dimensions, preload and exclusions, with a report of what was left out.
* While WP Rocket (or another page cache) is active, Fast Pages waits and says so.

**Claude through Site Bridge**

* Abilities (WordPress 6.9+): `fast-pages/status`, `fast-pages/settings-get`, `fast-pages/test-url` (read), `fast-pages/purge`, `fast-pages/preload`, `fast-pages/settings-set` (write) and `fast-pages/db-cleanup` (destructive). Turning the page cache on or off stays in WP admin.
* Site Bridge's cache flush also purges the page cache (Site Bridge 1.9.0).

**Safe by design**

* Nothing changes on activation. «Turn on page cache» asks first, writes the drop-in and the `WP_CACHE` line, and starts in test mode: cached pages answer only `?fpg-test=1` until you leave test mode.
* `wp-config.php` is changed only after the result is checked to be valid PHP, and it is restored if the write fails. Deactivation removes the drop-in, the line it added and the cache.
* `define( 'FPG_DISABLE', true );` in wp-config.php stops the drop-in, the cache writes and the optimizations; the site then works as without the plugin.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

== Installation ==

1. Install and activate the plugin. If WP Rocket is active, open **Noxpress → Fast Pages → Tools** and import its settings first, then deactivate WP Rocket.
2. On the **Overview** tab, tick the agreement and press «Turn on page cache». If wp-config.php cannot be written, the page shows the line to add by hand.
3. Check a few pages with `?fpg-test=1` (the admin bar has «This page as cached»), then press «Leave test mode».
4. Turn on the optimizations you want on the **Optimization** tab and check the site again.

== Frequently Asked Questions ==

= Does it work with Cloudflare or a host cache? =

Yes. Fast Pages answers before WordPress; a CDN in front of it keeps working as before. Two page caches inside WordPress do not work together, so Fast Pages waits while another one is active.

= My host has its own cache plugin (SiteGround, LiteSpeed). =

Use one page cache. Keep the host's object cache (Memcached, Redis): it works together with Fast Pages.

= What happens on uninstall? =

The drop-in (only if it is ours), the `WP_CACHE` line (only if Fast Pages added it), the cache folder, the settings and the scheduled events are removed. WP Rocket's settings are never touched. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.1 =
* Fix: a page lives at most 12 hours in the cache. The 24 h, 48 h and "until the next purge" choices are gone (a saved one becomes 12 h): WordPress nonces in older pages stopped forms and buttons from working.
* Fix: a site whose drop-in names another copy of the plugin (a staging copy, a moved site, a symlinked plugins folder) no longer stops with "Cannot declare class FPG_Key". The drop-in now loads the plugin from the site's own plugins folder first, and a 1.0.0 drop-in is rewritten on update.
* New: the Author Portal cookie of Revenue Splitter (rs_ptok) means "personal page", like a login. Other plugins add their own session cookies with the filter noxpress_private_cookies.

= 1.0.0 =
* First release (beta): page cache with a drop-in and test mode, smart purging, background preload from the sitemap, CSS / JS minify, lazy load for images, iframes and backgrounds, click-to-play YouTube, image dimensions, hover preload, import from WP Rocket, database cleanup, and seven abilities for Site Bridge.
