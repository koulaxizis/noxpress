=== Revenue Splitter ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, revenue split, royalties, vat, reports
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.17.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Split WooCommerce revenue between beneficiaries, with per-product VAT removal, a non-sales ledger, monthly email reports and a key-based author portal.

== Description ==

Revenue Splitter is built for publishers and shops that owe a share of each sale to authors, partners or other beneficiaries.

* **Per-product VAT removal** – a VAT rate per product (or a global default) is extracted from the line gross ("from inside": gross × rate / (100 + rate)).
* **Beneficiary splits** – global default split plus an optional per-product override; percentages must sum to 100%. Amounts are reconciled to the cent (largest remainder).
* **Dashboard** – period presets or custom range, multi-product and beneficiary filters, per-product table (full price / discounted / free copies, average discount, coupons, stock), VAT by rate, per-channel table, beneficiary accounting (period and lifetime balance) and a 6/12-month trend chart. Exports to CSV, XLS, HTML and JSON.
* **Refunds** – refunds are netted out per line item; amount-only refunds (without line items) are distributed proportionally across the order's product lines.
* **Ledger** – non-sales income (positive or negative corrections) and payouts per beneficiary, each with a mandatory reason; "settle all" for a date range.
* **Sales channels & free copies** – when a configured free-copy coupon is applied at the classic checkout, the customer must pick a sales channel (or give a reason when no channels are configured). The WooCommerce Checkout block is not supported for this field; a warning is shown to admins.
* **Author portal** – shortcode `[rs_portal]` (alias `[author_portal]`). Beneficiaries sign in with a personal key (stored as sha256), see their own sales, shares, balance and history, download a CSV, toggle the monthly report and request a new key by email.
* **Monthly email report** – opt-in per beneficiary, sent in the first days of each month for the previous month (WP-Cron).
* **Backup & restore** – export/import the full plugin state as JSON.
* **WP-CLI** – `wp rs report`, `wp rs ledger-add`, `wp rs ledger-list`, `wp rs ledger-delete`, `wp rs balance`, `wp rs backup`.
* **Abilities** – on WordPress 6.9+, the reports, balances and monthly emails are also WordPress abilities (`revenue-splitter/report`, `balances`, `monthly-status`, `monthly-preview`, `send-test`, `monthly-send`) for AI and remote tools such as Site Bridge, with the same capability. They never return email addresses, portal keys or ledger notes.
* **REST for orOS** – two read-only routes under the WooCommerce REST API, `GET /wp-json/wc/v3/rs/balances` (per beneficiary: sales share, other income, payments, remaining) and `GET /wp-json/wc/v3/rs/report` (products sold in a period, this year by default, with splits and current stock; `name` keeps one beneficiary's titles). They take the shop's WooCommerce REST keys (a read key is enough) or an Application Password, need `manage_woocommerce`, answer in integer cents and never return email addresses, portal keys or ledger notes. orOS's CRM shows them on a partner's card.
* **Contract expiries (orOS)** – the orOS CRM sends the whole list of creators' contracts to `POST /wp-json/rs/v1/contracts` (a full, idempotent replacement, at most 2000; each row is checked, and a bad row is skipped with a reason; optional `renewals` with the renewal day, the old and the new end day; the beneficiary must be one Revenue Splitter knows). The Author Portal shows each beneficiary only their own contracts: title, format, signing and end date, days left and a colour status (in force, ending soon at 90 days or fewer, ended, terminated), worked out from today's date. A "let's talk / renew" button stores a request that orOS pulls (`GET /wp-json/rs/v1/contract-requests`, then `POST …/<id>`); no email is sent. The routes take a narrow contracts key made in **Noxpress → RS Contracts** (shown once, stored as sha256, revocable), which opens nothing else, so orOS keeps a read-only WooCommerce key. The same page shows when the last list came, what was skipped and the requests, and can restore the previous list. Ability `revenue-splitter/contracts`.
* **Creator's titles and page (Author Portal)** – a creator's titles are the products whose own split names them, plus, with Featured Creators, the products whose author attribute belongs to the creator page linked to them in **Noxpress → RS Creators** (only the roles you mark as author roles count; illustrators do not, by default). For each title the portal shows downloads of the digital files (in the period and in total), how many got the file, the last download and a 12-month chart; sample opens (Book Preview 1.2.0+), page views (Koko Analytics), wish lists (Wish Shelf), pre-orders (Pre-Orders), ratings and the latest reviews; a promotion kit (link, cover, short text to copy); and the monthly statements already sent, as PDF. Numbers only, never a buyer's name, email or order, and shop staff are left out. The creator never edits the page: a «change or addition» form (bio text, up to 6 links, a note, one JPG/PNG/WebP photo up to 5 MB) and an «author copies» form send a request to **RS Creators**, which shows the changes side by side and emails the site's admin address. Ability `revenue-splitter/downloads` (read-only).
* **Bilingual UI** – Greek and English, chosen per user (or following the WordPress locale).
* **Noxpress hub** – the "Noxpress" menu opens the Noxpress hub: one page for the whole suite with status, installed and available version, and update, install, activate, changelog and auto-update links.
* **Updates** – updates come from noxpress.tech (GitHub releases), not WordPress.org, through WordPress's own update screens. Stable or Beta channel (Stable by default, in the hub); every package is checked with sha256 and an Ed25519 signature before it is installed.

HPOS (custom order tables) compatible.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `revenue-splitter` folder to `/wp-content/plugins/` and activate the plugin.
3. Go to **Noxpress → RS Settings** and set the default VAT rate and the global beneficiaries (`Name|Percent`, one per line).
4. Optionally override the split and VAT rate per product in the "Revenue Splitter — Beneficiaries" box on the product edit screen.
5. To give beneficiaries access, add `[rs_portal]` to a page and create their keys under **Noxpress → RS Portal**.

== Frequently Asked Questions ==

= Does the free-copy channel/reason field work with the Checkout block? =

No. It uses the classic checkout hooks, so it only appears with the `[woocommerce_checkout]` shortcode checkout. Admins see a warning when the checkout page uses the block.

= Do WP-CLI commands need a user? =

Without `--user` the commands run as a trusted shell context. If you pass `--user`, that account needs the `manage_woocommerce` capability.

= What does uninstall remove? =

Only this plugin's own options, transients, cron event, product/order meta and order item meta. The shared `rs_lang` user meta is kept when Store Pulse or Smart Formatter is still installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.17.1 =
* Fix: an order line from Trade Accounts «Amount received» (item meta `_tra_part`) counts its money also when it pays only part of a copy (quantity 0), so every euro a bookstore pays reaches the creators. Such a line adds no copies.

= 1.17.0 =
* New: a third Ledger entry type, «Deduction» («Κράτηση»): money kept from what a beneficiary is owed, for example their share in a production they co-fund. Always a positive amount, subtracted from the balance, with a required reason and, optionally, the product it is for. A deduction has no sales channel.
* New: a deduction larger than the payable balance is allowed. The form asks first, and after saving a notice shows the balance below zero, which the next sales cover. A statement whose closing balance is below zero waits for review, as before.
* New: deductions everywhere the balance shows: a «Deductions» column on the dashboard's beneficiary accounting, «Deduction» rows (with the product's title) in the Author Portal history, a «Deductions» line in the portal CSV, a «Deductions» list in the monthly report email, and a «Deductions» line and section in the monthly statements (email and PDF). «Pay all (period)» subtracts them too.
* New: wp rs ledger-add --type=expense [--product=<id>], wp rs ledger-list --type=expense, a «Deductions» line in wp rs balance; ability revenue-splitter/ledger-add accepts type expense and product_id; balances, statements-list and the orOS route wc/v3/rs/balances return deductions.
* Developer: the balance formula lives in one place, RS_Ledger::net() (other income − deductions − payments). RS_Ledger::sum( ..., 'income' ) still returns income minus deductions, so older add-ons (e.g. Store Pulse before 1.8.5) keep showing the right balance; pass true as the fifth argument for the other income alone.
* Note: going back to an older version drops deduction entries from the Ledger on its next change. Export the state first if you ever downgrade.

= 1.16.1 =
* Fix: the portal tables (per product, VAT by rate, transaction history) scroll sideways inside their box on narrow themes instead of overflowing the page; the monthly chart does the same.
* Fix: the beneficiary ↔ creator page mapping is read also when a remote tool (such as Site Bridge) stored it as an array instead of JSON text.

= 1.16.0 =
* New: «Your titles» in the Author Portal. For each of the creator's titles: downloads of the digital files in the period and in total, how many orders got the file (free or paid), the last download and the last 12 months as a chart. Downloads by shop staff do not count; variations count under their product.
* New: interest and reviews per title, from the suite and from Koko Analytics when they are active: page views, sample opens (Book Preview 1.2.0+), wish lists (Wish Shelf), pre-orders and release day (Pre-Orders), the rating and the latest reviews (first name and initial only).
* New: «Your page» in the portal: a link to the creator's page and a request for a change or addition (bio text, up to 6 links, a note, one JPG/PNG/WebP photo up to 5 MB, re-encoded, stored outside public reach). The creator never edits the page; the request goes to the shop.
* New: promotion kit per title (link, cover and a short text to copy), a request for author copies (title, quantity, note) and «Your statements» (the monthly statements already sent, as PDF, each creator only their own).
* New: Noxpress → RS Creators: open requests with the bio changes side by side, the links and the photo, Done or Decline with an optional reply the creator sees in the portal; the beneficiary ↔ creator page mapping (with suggestions by name); which roles count as author. A new request emails the site's admin address.
* New: ability revenue-splitter/downloads (read-only, numbers only).
* Safety: requests are rate-limited (3 per beneficiary per day, 10 per IP per hour, at most 3 open author-copies requests); photos are served to shop managers only and deleted 30 days after the request closes; closed requests are kept for 12 months.

= 1.15.0 =
* New: monthly creator statements (Noxpress → RS Statements). For every beneficiary with a balance or any movement in the month: the opening balance, the share per title (copies, %, amount), the Ledger's other income and payments, and the closing balance. It goes out as a short email with the statement as a PDF attached. No opt-in is needed; beneficiaries can be left out in the settings.
* New: two sending modes. «Review» (default): every statement waits until you press Send (one by one, or the selected ones). «Automatic»: the daily check makes and sends the previous month's statements on the 1st, except statements with a warning, beneficiaries without an email and December statements, which always wait. A statement made by hand is never sent by itself, and turning the feature on never sends an older month.
* New: checks that hold a statement: the VAT RS removes does not fit the product (no tax in WooCommerce, ISBN → 6%, other GTIN → 24%), more copies of one title in a month than a set limit (default 50), a negative balance, no email.
* New: yearly settlement. December's statement is «Year-end settlement» and can be marked «Paid», which adds a Ledger payment of its closing balance (same checks as the Ledger form).
* New: a small built-in PDF writer (no external service or library) with DejaVu Sans (Latin and Greek; licence in assets/fonts). Each PDF embeds only the glyphs it uses.
* New: abilities revenue-splitter/statements-list and revenue-splitter/statement-preview (read-only).
* Fix: the monthly report's «Balance payable» (and the monthly-preview ability's remaining) is now the balance at the end of the month, not on the day the report is sent.

= 1.14.3 =
* Changed: the «Sales» column in the dashboard widget and in the dashboard's beneficiary accounts is now «Share (excl. VAT)», because it shows each beneficiary's share of net sales, not the sales. No amount changes.

= 1.14.2 =
* New: optional "renewals" on each contract, [ { on, prev_to, to } ] (the day of the renewal, the end day before it and the new end day; all YYYY-MM-DD, at most 50, a bad entry skips the row with bad_renewed). Stored and returned by GET only when sent. The Author Portal and RS Contracts show each renewal as «Renewed on … (was ending …, now ends …)»; without it they show "renewed" as before. "renewed" is unchanged, and unknown keys are still ignored.

= 1.14.1 =
* Changed: the contracts routes moved from wc/v3/rs/… to rs/v1/… (contracts, contract-requests) and take their own narrow key instead of a WooCommerce Read/Write key. RS Contracts makes the key (shown once, stored as sha256), shows when it was last used, and can replace or revoke it. orOS sends it as "Authorization: Bearer rsc_…" (or X-RS-Key); after 10 failed keys from one IP in 15 minutes, wrong keys get 429 (the right key always works). The key opens these four routes only. Nothing used the 1.14.0 routes yet.

= 1.14.0 =
* New: creators' contract expiries in the Author Portal. orOS sends the list of contracts (POST wc/v3/rs/contracts, full replacement, strict per-row checks, answer with saved and skipped rows); each beneficiary sees only their own under «Your contracts», with signing and end date (dd/mm/yyyy), days left and a coloured status, recomputed from today's date. Open-ended contracts show as «Open-ended».
* New: «I'd like to discuss it / renew» request per contract, with an optional note, rate-limited (one open request per contract, 3 per beneficiary per day, 10 per IP per hour). orOS pulls the requests (GET wc/v3/rs/contract-requests) and marks them (POST wc/v3/rs/contract-requests/<id>). No email is sent.
* New: Noxpress → RS Contracts (read-only): last list received, skipped rows with reasons, beneficiaries without a portal key, open requests (with Close), all contracts, and one-click restore of the previous list.
* New: ability revenue-splitter/contracts (read-only), and GET wc/v3/rs/contracts.

= 1.13.0 =
* New: abilities revenue-splitter/ledger-add and revenue-splitter/ledger-delete. A remote tool (e.g. Site Bridge) can add other income or payments to the Ledger, several at once, with the same validation as the Ledger form, all-or-nothing, a dry run and protection against adding the same entry twice; and delete entries by id to undo an add.

= 1.12.3 =
* Noxpress Core 1.2.8: a use-at-your-own-risk notice on the Noxpress hub page, and a Disclaimer section in this readme.

= 1.12.2 =
* Noxpress Core 1.2.5: the client portal keeps itself out of page caches through the shared Core helper (DONOTCACHEPAGE and no-store headers), the same as before.

= 1.12.1 =
* Fix: the Author Portal is never stored by a page cache (Fast Pages, WP Rocket, host caches). Its page, and every front page of a visitor with a portal session, now sends DONOTCACHEPAGE and no-cache headers; before, one beneficiary's dashboard could be cached and shown to every visitor.
* Fix: CSV exports pass every fputcsv() argument, so PHP 8.4 prints no deprecation notice into the file.
* Tested up to WordPress 7.1 and WooCommerce 11.2.

= 1.12.0 =
* New: Noxpress Core 1.2.0 adds Install all, Update all and Uninstall all to the Noxpress hub. Each opens a checklist to confirm and then works one plugin at a time through WordPress's own installer, with the usual sha256 and signature check. Install all follows the update channel and does not activate unless ticked; Uninstall all deactivates and deletes (each plugin's own uninstall runs), needs a typed word, and leaves Site Bridge and Data Migrator unticked by default.

= 1.11.0 =
* New: read-only REST routes for orOS, `GET /wp-json/wc/v3/rs/balances?name=` and `GET /wp-json/wc/v3/rs/report?date_start=&date_end=&name=`, with the same figures as the `balances` and `report` abilities, amounts in integer cents and each product's current stock. Authenticated by the shop's WooCommerce REST keys (read is enough) or an Application Password, with `manage_woocommerce`. Nothing is written or stored.

= 1.10.0 =
* New: WordPress abilities (6.9+) in the "noxpress" category: `revenue-splitter/report` (the sales report of a period), `revenue-splitter/balances` (lifetime sales, income, payments and what is owed per beneficiary), `revenue-splitter/monthly-status` (who gets the monthly report and whether last month's was sent), `revenue-splitter/monthly-preview` (one beneficiary's monthly report as figures), `revenue-splitter/send-test` (the test email, to the site's admin address) and `revenue-splitter/monthly-send` (last month's reports now; marked destructive, and a month already sent needs `resend`). They need `manage_woocommerce`, are not in the REST API, and never return email addresses, portal keys or ledger notes.
* Changed: the monthly email's figures and its sending are shared functions (`RS_Emails::monthly_data()`, `RS_Emails::send_monthly()`); the daily check and the email itself are unchanged.

= 1.9.3 =
* Fixed: saving a product's beneficiaries stored Greek names as "u0393u039b..." in `_rs_split`, because the JSON escapes lost their backslashes. Names are now stored as real UTF-8, and saving a product once repairs an older damaged value.
* Fixed: restoring a JSON backup no longer strips the backslashes from product overrides and free-copy reasons, so beneficiary names with Greek letters or quotes come back intact.

= 1.9.2 =
* Fixed: the tables in the beneficiary portal are readable on light themes such as Storefront. The theme's table colours turned the cells white with light text.
* Fixed: the live split total under the product's beneficiaries is readable on the white product box.
* Fixed: the internal regular-price stamp (_rs_reg_unit) no longer shows under each item on the order screen.

= 1.9.1 =
* Fixed: report periods now start and end at the exact local time. WooCommerce compared the old period bounds by day only, so an order placed late in the evening of the last day of a period (for example 23:50 on 31 December in Athens) could be counted in the next period.

= 1.9.0 =
* New: the Noxpress hub tells you when the other update channel has a newer version (in practice, a newer beta while the site follows Stable), with a counter on the Noxpress menu.
* New: "Install beta" for a single plugin, without moving the whole suite to Beta, or "Switch everything to Beta". Every package is still checked with sha256 and its signature.
* New: "Hide for this version" per user; the notice comes back with a newer version.
* Changed: Noxpress Core 1.1.0.

= 1.8.2 =
* New: Shop Filters joins the Noxpress suite: the hub lists it and can install it (Noxpress Core 1.0.2).
* Fix: uninstalling Revenue Splitter keeps the shared Noxpress data (update channel, hub notices) and the language choice (rs_lang) while Shop Filters is still installed.

= 1.8.1 =
* Fix: the hub's "Changelog" link and "Install" button no longer end in "Plugin not found" when another plugin on the site overrides plugin details. The suite's own details are now applied last (Noxpress Core 1.0.1).
* Changed: the Noxpress hub page uses the full width of the screen (Noxpress Core 1.0.1).

= 1.8.0 =
* New: Noxpress hub. The "Noxpress" menu now opens one page for the whole suite: status, installed and available version, with update, install, activate, changelog and auto-update links (each shown only to users who may use it).
* New: updates without WordPress.org. WordPress shows Noxpress updates like any other, from noxpress.tech. Stable or Beta channel (Stable by default); every package is checked with sha256 and an Ed25519 signature before it is installed.
* New: `Update URI` header, so WordPress.org can never offer a different plugin with the same slug as an update.
* Removed: the old Noxpress overview page of Revenue Splitter (replaced by the hub).
* Changed: the shared "Noxpress" menu is created by Noxpress Core (bundled in every suite plugin, the newest copy loads), no longer by this plugin. It also works without WooCommerce, so updates keep arriving.
* Note: sites on an earlier version need this version installed by hand once; later versions arrive as updates.

= 1.7.0 =
* Fixed: WP-CLI was broken by stray JavaScript appended to the CLI class file; `wp rs report --ben` no longer calls a private method; CLI runs without `--user` are allowed, with `--user` the capability is enforced.
* Fixed: whitespace before `<?php` in the admin UI file (headers already sent).
* Fixed: state import now restores product overrides before the ledger, so ledger entries for override-only beneficiaries are accepted.
* Fixed: contradictory notices on product save (invalid email vs. split not saved).
* Fixed: amount-only refunds were ignored in reports; they are now netted out proportionally.
* Fixed: portal session cookie is handled on `init` instead of inside the shortcode output; currency symbols are decoded in the portal CSV and emails.
* Fixed: free-copy coupon codes with spaces or non-ASCII characters were mangled; they are now normalized and compared the same way WooCommerce does.
* Security: portal login rate limit is keyed on the (hashed) IP only; key resets have their own per-IP limit and a per-beneficiary limit (3 per hour) that a successful reset does not clear.
* Fixed: uninstall no longer deletes other plugin folders; it removes all own options (including email settings), escapes LIKE patterns, clears the cron event and keeps the shared `rs_lang` meta when Store Pulse or Smart Formatter is installed.
* Fixed: the monthly report cron is unscheduled on deactivation.
* Fixed: ledger changes now fire `rs_invalidate_cache`.
* Fixed: reports, portal and Store Pulse no longer show stale figures for up to 5 minutes after an order status change or refund (cache is invalidated on those events).
* Fixed: trend chart bars had zero width; discount-estimate flag is shown on the dashboard.
* Added: shared "Noxpress" menu (priority 9, position 57) with an overview listing the active Noxpress plugins; assets load by page slug.
* Cleanup: complete and exact English dictionary, removed a dead admin menu block, stray foreign-script words and inline styles; consistent version strings.

= 1.6.3 =
* Previous release.
