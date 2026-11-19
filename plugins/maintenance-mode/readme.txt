=== Maintenance Mode ===
Contributors: koulaxizis
Donate link: https://noxpress.tech/support/
Tags: maintenance, coming soon, under construction, 503, launch page
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: MIT
License URI: https://opensource.org/licenses/MIT

A «Coming soon» or «Maintenance» page for visitors while you, your client and your office keep seeing the real site. The right status code for search engines, a schedule, page cache purging, and logins, payments and the REST API never cut off.

== Description ==

You are building a site for a client, or a live shop needs an hour of work. Visitors should see a calm, fast page; you should see the real site; the client should see it too, without an account; and nothing that happens behind the scenes (payments finishing, webhooks, apps) should break.

Nothing changes after activation: the switch starts open.

**Two modes**

* **Maintenance**, for a site that is already live: HTTP 503 with Retry-After (until the end time, or the hours you set), so search engines simply come back later and no rankings are lost.
* **Coming soon**, for a site that has not launched: HTTP 200 with noindex, robots.txt with «Disallow: /», feeds closed, and Search Signals (1.1.0+) closes its sitemaps and llms.txt.

**Who sees the site**

* Logged-in users with the roles you tick. Whoever can manage the site always passes, so you can never lock yourself out.
* Visitors from the IP addresses or ranges (CIDR) of a list. Read only from the connection (or Cloudflare's header when the request really comes from Cloudflare), never from headers anyone can send.
* Anyone with a **preview link**: up to 20 links with a label and an expiry (1–90 days, 14 by default). The link sets a signed cookie and then removes itself from the address bar. Revoke a link and it stops at once. Nothing secret is stored: the link is a signature made with your site's salts.

**Never cut off**

wp-admin and the login page, admin-ajax, WP-Cron, the REST API (payments, WooCommerce, Site Bridge, Broadcast, apps), WooCommerce's wc-api callbacks and the order-received / order-pay pages, so a payment in progress finishes. Also /.well-known/ (certificates, app links), robots.txt and the favicon. A page with the Easy Withdrawal form stays open too: the right of withdrawal is a legal duty. An optional switch closes only the wp/v2 REST routes (posts, pages, users) to visitors who are not logged in.

**Page cache, done right**

A page cache answers before WordPress loads, so without care visitors would keep seeing the old site, and a client with a preview link could write the unfinished site into the cache for everyone. Maintenance Mode:

* purges the page cache on every save, at the start and at the end (Fast Pages, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache);
* keeps every page out of caches while the site is closed;
* tells Fast Pages that its preview cookie means «personal page».

A CDN that keeps whole pages (for example Cloudflare with «Cache Everything») is outside the site: purge it there.

**The page**

Its own small template, never the theme: no external fonts or requests, about 10 KB, JavaScript only for the optional countdown. Logo (or the site icon), title and text in Greek and English, background colour or image, text and accent colours, countdown to the end time, email, phone and up to six links. No form and no email collection. A preview on the settings page shows exactly what visitors see.

**Schedule**

Optional start and end times, in the site's time zone. They are compared on every visit, so the site opens on time even when WP-Cron does not run. A red «Maintenance on» badge in the admin bar keeps you from forgetting it.

**Import**

Title, text, logo, colours, mode and countdown from SeedProd Coming Soon 4.x–5.x and WP Maintenance 2.x. Their settings are only read, never changed. SeedProd 6+ builds its page in its own builder and cannot be imported.

**For developers**

* `mmd_status()` — state (off / scheduled / on / ended), active, mode, start, end.
* Filter `noxpress_site_closed` — what the current request meets: '' (the site), coming_soon or maintenance.
* Filter `mmd_allow_request( $allow, $mode )` — let a request through.
* Filters `mmd_page_text( $t, $mode, $lang )` and `mmd_page_html( $html, $mmd )`; a `maintenance-mode.php` file in the (child) theme replaces the template.
* Abilities (WordPress 6.9+) `maintenance-mode/status` and `maintenance-mode/set`, with the same capability (manage_options), for Site Bridge.
* Header `X-Maintenance-Mode` on the page.
* `define( 'MMD_DISABLE', true );` in wp-config.php opens the site to everyone, whatever the settings say.

Part of the free Noxpress suite (https://noxpress.tech). It works on any WordPress site, with or without WooCommerce, and updates from the Noxpress hub.

== Installation ==

1. Install from the Noxpress hub (Noxpress → Overview), or upload the zip in Plugins → Add New.
2. Activate. Nothing changes yet.
3. Noxpress → Maintenance Mode: write your texts in «Page», check the preview, choose the mode and close the site in «Status».

== Frequently Asked Questions ==

= I closed the site but visitors still see it =

A CDN in front of the site may keep old pages: purge it there. If a page cache plugin is not one of those listed above, purge it by hand once.

= My client cannot see the site =

Create a preview link in «Access» and send it. Customers with an account see the maintenance page, unless you tick their role.

= Will Google drop my shop during maintenance? =

No: «Maintenance» answers 503 with Retry-After, which tells search engines the break is temporary. Keep it short (hours or a few days); for a site that is not public yet, use «Coming soon».

== Changelog ==

= 1.0.3 =
* Support links (the footer pill and the Donate link) point to noxpress.tech/support/ instead of Ko-fi, with the label «♡ Support Noxpress». Noxpress Core 1.6.0: the same link in the hub, and Visitor Language in the suite catalogue.

= 1.0.2 =
* Noxpress Core 1.5.0: a short «Noxpress» menu with your favourites (★) or your 5 most recent plugins, one row per plugin with tabs for its other pages, hub categories and search, every suite page in the command palette (Ctrl/Cmd+K) and a «Full menu» switch per user.

= 1.0.1 =
* Noxpress Core 1.4.16: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.0.0 =
* First release (beta): two modes (Maintenance 503 + Retry-After, Coming soon 200 + noindex), roles, IP list and preview links, schedule without cron, page cache purge and no caching while closed, own template with Greek and English texts, countdown and contact, import from SeedProd Coming Soon and WP Maintenance, abilities for Site Bridge, admin bar badge and Site Health.
