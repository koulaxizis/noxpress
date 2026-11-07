=== Production Ledger ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: costs, profit, bookkeeping, woocommerce, publishers
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Costs and net result per WooCommerce product: production costs by category, shipping, consumables and payment fees spread over the month's orders, creator payouts, general expenses, break-even and CSV. Every amount net, with VAT in its own column.

== Description ==

A small publisher or label sells books, records and merch, and pays for printing, editing, design, mastering, ISBNs, couriers, boxes and card fees. WooCommerce shows what came in; Production Ledger shows what each product earned after everything it cost, and what is left after the general expenses of the business.

The unit is each WooCommerce product (a variation counts as its parent). Every amount is net; VAT sits in its own column, so the result never mixes money that belongs to the tax office.

**Entries**

* One entry per invoice line: date, product, category, supplier, invoice number, description, net, VAT rate and VAT (computed from the rate when left empty), quantity (e.g. parcels) and a link to the document.
* Categories: printing, editing/proofreading, design/illustration, sound/mastering, ISBN/licences, shipping, consumables, promotion, events, payment fees, creators. «Off-site revenue» (sales at events, bookshops paying by invoice) is a monthly line per product.
* Shared entries: a carrier's monthly invoice falls equally on each of its parcels that month, consumables on every shipped order, and a payment provider's fee invoice on the orders paid through it, by their value. A month without such orders shows the amount as «No orders to split over», never lost.
* General expenses (rent, software, accountant) in a separate ledger: they lower the final result, not a product.
* Deleting an entry is a soft delete with «Undo»; deleted entries can be listed and restored. Test entries never count and can be erased in one go.
* A start date: nothing before it counts and no entry can be dated earlier, so a half-entered past never distorts the numbers.
* Historical entries: costs from before the start date can still be kept, ticked «Historical», for statistics only. They never count in the report, totals, break-even, the orOS feed or the monthly CSV, and export in a file of their own.

**Report («Costs & Returns»)**

* A month or a year: revenue (retail and trade apart), shipping paid by customers, creator payouts, production costs, shipping ±, consumables and fees, net per product and the final result after general expenses.
* Orders count in the month they were paid, the same day the money reaches the bank (pre-orders too, without waiting for shipment), with WooCommerce's own paid statuses; refunds come off the line they refund (an amount-only refund is spread in proportion).
* Creator payouts come from Revenue Splitter's own report for the same orders. Tick which beneficiary names are the publisher itself: their share stays in the result, everyone else's is a cost. Without Revenue Splitter, payouts count only from «Creators» entries.
* Trade orders are recognised from the flag Trade Accounts writes on them.
* Break-even per product: how many more units must sell to cover everything spent on it since the start date.
* Sortable, searchable table; a click on a product lists its entries.

**Export**

* Two CSV files per month: every entry, and the summary per product with the general ledger and the totals. UTF-8 with BOM, «Excel Greek» (; and decimal comma) or «Standard» (, and decimal point), formula guard on text cells.

**Modules (off after activation)**

* **orOS Budget:** a read-only feed at `/wp-json/wc/v3/pl/records` that orOS Budget pulls with the shop's WooCommerce REST keys while it is open. Every sale, shipping charge and entry has a stable id, so the same record is never added twice, on any device; a deleted or unpaid one comes back as a deletion. Records carry the payment method, gateway transaction id, order number and invoice number for bank reconciliation, and each answer has the month totals to check against. Amounts in cents.
* **Printful:** with a read-only Printful API key, the cost of every completed Printful order (net and VAT) becomes «Printing» entries of the products in it, spread by Printful's own item prices. Queued in the background with retries; a second look-up updates the same entries. The key goes in `define( 'PL_PRINTFUL_KEY', '…' );` in wp-config.php, or in the settings, where it is stored encrypted (AES-256-GCM from the site's salts) and never shown again.

**Safe by design**

* No front end: nothing runs on a visitor request, and the Printful call never runs in the request that completed the order.
* Every change needs `manage_woocommerce`, a nonce and server-side validation; amounts are checked to the cent.
* `define( 'PL_DISABLE', true );` in wp-config.php stops both modules (the orOS feed answers 403).
* WordPress Abilities API (WordPress 6.9+): `production-ledger/report`, `entries` and `export-csv` (read), `add-entry` (1–100 entries, all or nothing, duplicate guard on supplier + invoice + product + category + net), `update-entry` and `delete-entry` (soft, restorable), for Site Bridge.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* `pl_get_report( string $from, string $to, int $product_id = 0 ): ?array`: the report for a date range (Y-m-d), or null.
* Action `pl_entries_changed` ( int[] $ids ): after every add, change, delete or restore of entries.

== Installation ==

1. Upload the `production-ledger` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub).
2. Activate it and open **Noxpress → Costs & Returns → Settings**.
3. Set the start date, tick the publisher's names among the Revenue Splitter beneficiaries, and check the carriers and payment groups.
4. Enter the month's invoices in **Entries** and **General expenses**.
5. For orOS Budget or Printful, switch the module on in Settings.

== Frequently Asked Questions ==

= Why net amounts? =

VAT you collect is owed to the tax office and VAT you pay is mostly deductible, so neither is the business's money. The VAT columns are there for the accountant and for reconciliation.

= Does it change orders or products? =

No. Orders, products and Revenue Splitter data are only read. The only order meta it writes is a small Printful look-up status (`_pl_pf`) when that module is on.

= What happens on uninstall? =

Settings, the stored Printful key, caches and queued look-ups are always removed. The entries are your books: the table stays unless you ticked «Delete all entries when the plugin is deleted». The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). It is not accounting or tax software and does not replace your accountant. Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.0.0 =
* Initial release.
* Cost entries per product and category, shared entries spread over the month's orders (carrier, consumables, payment provider), general expenses ledger, off-site revenue.
* Report per product for a month or a year: retail and trade revenue, creator payouts from Revenue Splitter, production costs, shipping ±, net, final result and break-even.
* CSV export (entries and summary) per Noxpress §5.
* Module orOS Budget: read-only records feed at wc/v3/pl/records with stable ids and reconciliation fields.
* Module Printful: completed Printful orders become «Printing» entries (read-only key, background look-ups with retries).
* Abilities report, entries, export-csv, add-entry, update-entry, delete-entry; PL_DISABLE; Greek and English admin.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.2.10).
