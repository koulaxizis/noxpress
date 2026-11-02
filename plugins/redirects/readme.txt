=== Redirects ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: redirect, 301, 404, 410, broken links
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: MIT
License URI: https://opensource.org/licenses/MIT

301, 302, 307 and 410 for old addresses, only where your site answers 404, and a 404 log in a private file instead of the database, without IPs and without bots.

== Description ==

Addresses change: a product gets a better slug, a category moves, Greek slugs become greeklish, an old site had other pages. Every old link that now ends on a 404 loses a visitor and the place it had in search results. Redirects sends those addresses where they belong and shows you which ones are still broken.

Nothing a visitor sees changes after activation: the plugin starts in test mode, where rules answer only administrators, until you choose «Live». The 404 log starts at once, so you have data before you write a rule.

**Rules**

* Exact address, «starts with» (a whole path and everything under it) or regex (with $1…$9 in the target). Greek or encoded, with or without the final slash, upper or lower case: all the same address.
* 301, 302, 307, or 410 for products that are gone for good (the theme's 404 page, with status 410, so search engines forget them quickly).
* A target that is a post, product, page or term is kept by ID: if its slug changes again, the rule follows.
* A new 301 starts as 302 and after 7 days the page asks you to make it permanent: a wrong 301 stays in browsers and in Cloudflare for good, a 302 does not. You can turn this off.
* Rules run only when WordPress answers 404. A page that exists is never hidden by a rule, and a normal page view makes no database query for redirects. They run before WordPress's own old-slug and canonical guesses and before Greeklish Permalinks, so the rule you wrote wins.
* Checks on save: the old address belongs to this site and is never the home page, admin, login, REST API, cart, checkout or account; a regex must compile and may not catch any of those; loops are refused (A→A, A→B→A) and chains are flattened (A→B plus B→C becomes A→C, both ways); one exact rule per address. Up to 200 «starts with» and 50 regex rules.
* «Test an address»: write a URL and see what a visit would meet (a page that exists, which rule, WordPress's old slug, Greeklish Permalinks or 404) without opening it.

**404 log, in a file**

* One JSON line per 404 that nobody redirected, in uploads/noxpress-redirects-<random>/404-YYYY-MM.log, with an empty index.php, a deny .htaccess and web.config, and an HTTP check that warns when the folder can be downloaded (nginx ignores .htaccess).
* Only the path (never the query string, where emails and tokens live), the time, the host of the referrer (or «your own site», which means a broken internal link) and whether the visitor looked like a crawler. No IP address, no user agent, no cookie, nothing in the database.
* Paths that vulnerability scanners probe (.env, wp-config, graphql, actuator, livewire, *.php and more; an editable list) are not written at all.
* Limits: 60 lines a minute, 5 MB a month, 256 MB free disk; files older than 3 months are deleted by a daily job (all settings).
* Hits per rule (30 days) come from the same file, so no database row changes on a visitor request.
* The 404 tab groups the log by address, most hits first, with up to 3 suggested targets each and one-click «301 here», «410», «Another target» and «Ignore».

**Suggestions**

* WordPress's old slugs, Greeklish Permalinks' redirect table, the Greek address converted to greeklish, an upload with the same name in another month folder (or without «-1», a size suffix or «-scaled»), and slugs that contain the old one or are contained in it (author-name-book-title → book-title) or are closest to it, in the same post type or taxonomy. Never «the home page», which Google treats as a soft 404. Nothing is applied without a click.
* A published post, page or product that goes to the trash, and a category or tag whose address changes (WordPress keeps old slugs for posts, not for terms), show on the Rules tab with «301 to its category» / «301 to the new address», «410» or «No».

**Import, export, other plugins**

* Import from Rank Math, Redirection, Yoast SEO Premium, Simple 301 Redirects and CSV: scan, preview with a tick per row (new, already there, will not pass and why), then import. Their tables are only read.
* When another redirect system is active (Rank Math Redirections or 404 Monitor, Redirection, Yoast Premium, Simple 301 Redirects) the page says so: both work, but the other runs first.
* Export the rules as CSV; backup and restore settings, rules and ignored addresses as JSON.

**Safe by design**

* GET and HEAD only; never in admin, AJAX, REST, feeds, embeds, cron, CLI or XML-RPC. Every callback is wrapped in try/catch: on an error the normal 404 stays.
* External targets go through wp_safe_redirect with only that rule's host allowed; nothing is ever taken from a query parameter.
* Every redirect carries an `X-Redirects` header with the rule id («test» in test mode).
* `define( 'RDR_DISABLE', true );` in wp-config.php stops every rule and the log.
* WordPress Abilities API (WordPress 6.9+): `redirects/list`, `redirects/top-404`, `redirects/test-url` (read) and `redirects/add`, for Site Bridge.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**Not covered**

* Server-level redirects (.htaccess, nginx): faster, but a mistake there takes the whole site down, and the suite never writes outside its folders without a button.
* Redirects for addresses that exist (a page that should move while it still exists): unpublish or change it first, then the old address becomes a 404 and the rule applies.
* Files the web server answers itself before WordPress (some hosts send missing images straight to their own 404).

**For developers**

* `rdr_add_redirect( string $from, string $to, int $code = 301, string $source = '' ): int`: adds a rule with the same checks as the admin page; returns its id or 0.
* Filter `rdr_redirect_target` ( string $url, array $rule, string $path ): the target before the redirect; empty = no redirect.

== Installation ==

1. Upload the `redirects` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub).
2. Activate the plugin and open **Noxpress → Redirects**.
3. If another plugin has redirects, open **Import / Export**, scan it and import its rules.
4. Wait a few days and open the **404** tab: create rules from the suggestions.
5. While logged in, open an old address: you are redirected (test mode). Then choose **Live** in **Settings**.
6. Switch off the other plugin's redirect and 404 modules, so one system remains.

== Frequently Asked Questions ==

= Why only on 404? =

So a rule can never hide a page that exists, and so a normal page view costs nothing. If an old address still opens something, that something answers first.

= Why does a new 301 answer 302? =

Browsers and CDNs keep a 301 for good: a wrong one cannot be taken back from your visitors. A 302 is not kept. After 7 days without trouble the page asks you to make it 301, with one button. You can turn this off in Settings.

= Does it work with Rank Math? =

Yes. Rank Math's redirects run earlier (on the wp hook), so where both have a rule, Rank Math's wins. Import its rules here and switch its Redirections and 404 Monitor modules off; that also stops a database write on every 404.

= Does it work with page caches? =

Yes. Redirects and 404s are not stored by page caches (Fast Pages, WP Rocket and others keep only 200 answers). When a rule is made for a trashed post, the suite's page-cache purge runs for its address.

= What happens on uninstall? =

The rules table, the options and the log files are removed (only files that match the plugin's names; the folder only if it is empty), unless you ticked «Keep the rules and the log». The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.0.2 =
* Noxpress Core 1.2.8: a use-at-your-own-risk notice on the Noxpress hub page, and a Disclaimer section in this readme.

= 1.0.1 =
* The 404 tab and redirects/top-404 leave out addresses that a rule now answers: after «301 here» the address no longer stays in the list, and the totals count only what is still broken.

= 1.0.0 =
* Initial release.
* Exact, «starts with» and regex rules with 301 / 302 / 307 / 410, only on 404, before WordPress's guesses and Greeklish Permalinks; targets kept by post or term ID.
* Checks for protected addresses, loops, chains and duplicates; «Test an address».
* New 301 rules start as 302 and are suggested for 301 after 7 days; test mode for administrators first.
* 404 log and rule hits in a private file (no IP, user agent or query string), scanner filter, limits, retention, HTTP self-test.
* Target suggestions (old slugs, Greeklish Permalinks, similar slugs and files) with one-click rules; trashed posts and changed term addresses as suggestions.
* Import from Rank Math, Redirection, Yoast SEO Premium, Simple 301 Redirects and CSV; CSV export; JSON backup and restore.
* Abilities redirects/list, top-404, test-url and add; RDR_DISABLE; Greek and English admin.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.2.7).
