=== Revenue Splitter ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, revenue split, royalties, vat, reports
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.7.0
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
* **Bilingual UI** – Greek and English, chosen per user (or following the WordPress locale).
* **Noxpress menu** – shares the "Noxpress" admin menu with Store Pulse and Smart Formatter; the Noxpress overview page lists which of these plugins are active.

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

== Changelog ==

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
