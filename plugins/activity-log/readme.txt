=== Activity Log ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: activity log, audit log, history, security, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Who changed what and when: products, prices, orders, coupons, pages, users, plugins and settings, with the source of every change. No secrets and no customer details.

== Description ==

When several people work on a site (you, an agency, Claude through Site Bridge, WooCommerce itself with its cron and queue), one day a price is wrong, a product is gone or a plugin is off, and nobody knows who did it. Activity Log writes every change, not every page view: when, who, from where and what changed, with the old and the new value.

**What it records**

* Products: created, changed (name, SKU, prices, sale dates, stock, stock status, visibility, categories, tags; descriptions, images and attributes as «changed»), published, unpublished, trashed, restored, deleted. Through the WooCommerce CRUD hooks, so the admin, quick and bulk edit, the REST API, WP-CLI and other plugins all show up.
* Orders: status changes with who moved them (a staff member, the customer, a payment gateway or cron), refunds, changes from the admin, trash and delete. New checkout orders only if you turn them on.
* Coupons: created, changed (amount, type, expiry, limits), deleted.
* Pages, posts and public post types: published, changed while published, unpublished, trashed, restored, deleted. Drafts, revisions and autosaves are left out.
* Users: logins and logouts of staff, failed logins, new users, deleted users, role changes, e-mail and password changes (only that they changed), password resets, application passwords. Customer logins only if you turn them on.
* Plugins, themes and WordPress: installed, activated, deactivated, updated (from → to), deleted, theme switched, the file editor used.
* Settings: WordPress core settings, every WooCommerce setting and the settings of the Noxpress plugins, with old → new, plus any option you add.
* Media uploaded and deleted; menus (off by default).
* Events of other Noxpress plugins (Easy Withdrawal, Publishing Quote, Trade Accounts, Featured Creators, Pre-Orders, Production Ledger, Broadcast) and of Login Guard (locks, unlocks, 2FA failures).

**Where it came from**

Every row says the source: admin, AJAX, REST API, WP-CLI, cron, background queue, checkout, the site, or Site Bridge (Claude). With Site Bridge 1.13.0 or newer, a change Claude made through Site Bridge is marked as such and points to the line of Site Bridge's own audit log, instead of looking like the administrator did it by hand.

**Privacy first**

* Passwords, keys, tokens, salts and any value whose name looks secret are never stored: the row says only that it changed.
* Customers and orders are numbers («Customer #57», «#1234»). Names, e-mails, phones and addresses of customers are never stored.
* A failed login keeps the user id when the name belongs to an account, otherwise «unknown user name», never the text that was typed (often a password typed into the wrong field). At most 30 rows an hour.
* IP addresses masked by default (IPv4 without the last part, IPv6 /48), or full, or none. Behind Cloudflare, CF-Connecting-IP is read only from Cloudflare's own addresses.
* Kept 90 days and at most 200 000 rows by default; a daily job deletes the rest in batches.
* WordPress «Export / Erase personal data» work: erasing keeps the event and removes who did it.

**Tools**

* Filters by date, person, category, source and importance, and a search.
* A «History» box on every product and order, and a dashboard widget with the last important changes.
* CSV of the filtered rows.
* E-mail for critical changes: a new administrator, a role raised to administrator, a plugin installed, activated or deleted, a theme switched, the site address or admin e-mail changed, the file editor used. At most one e-mail every 15 minutes with everything together.
* A read-only ability (WordPress Abilities API) for Site Bridge and the regular audit: «what changed since the last check».

**Limits, honestly**

* An administrator with database access can delete rows: this is a history, not a tamper-proof ledger.
* The rows of one request are written once at its end; if that request dies with a fatal error, its rows are lost.

**For developers**

* `acl_log( string $action, array $args )` writes an event from your plugin.
* Filters `acl_watched_options`, `acl_skip_event`, `acl_categories`.
* `define( 'ACL_DISABLE', true );` in wp-config.php stops all recording.

Part of the free Noxpress suite (noxpress.tech): shared «Noxpress» menu, updates through the Noxpress Hub, English and Greek.

== Installation ==

1. Upload the `activity-log` folder to `/wp-content/plugins/` or install the zip from the Noxpress Hub.
2. Activate it. Recording starts at once with the safe defaults.
3. Open Noxpress → Activity Log (Ιστορικό αλλαγών) to see the history and the settings.

== Frequently Asked Questions ==

= Does it slow the site down? =

Page views write nothing. A save in the admin adds one comparison and one database insert at the end of the request.

= Who can see the history? =

Only administrators (manage_options).

= What happens when I delete the plugin? =

Its table, its settings and its scheduled jobs are deleted.

== Changelog ==

= 1.0.1 =
* Noxpress Core 1.4.11: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.0.0 =
* First release (beta): products, orders, coupons, content, users, plugins, themes, core, settings, media and suite events; actor and source on every row; secrets and customer details never stored; masked IPs; 90-day retention with a row limit; critical-change e-mail; CSV; «History» box on products and orders; dashboard widget; privacy exporter and eraser; read-only ability activity-log/list; Site Bridge and Login Guard hooks.
