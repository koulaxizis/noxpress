=== Invoice Details ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, invoice, vat number, vies, greece
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Receipt or invoice at checkout (classic and block): company name, VAT number, tax office and activity, a check of the number, and the details on the order, in the emails and in an invoice list with CSV.

== Description ==

Customers choose a **receipt or an invoice** at checkout. For an invoice they give the company name, the VAT number and, for a Greek billing address, the tax office (ΔΟΥ) and the business activity. The details are checked, stored on the order and handed to your invoicing provider. The plugin does not issue documents and does not talk to myDATA: that stays with your invoicing provider.

**At checkout**

* Classic checkout: «Receipt / Invoice» radios after the billing form; the invoice fields show only for an invoice, the Greek ones only for a Greek billing address.
* Checkout block (WooCommerce 9.9+): a «I need an invoice» checkbox and the same fields as additional checkout fields, shown and required only when ticked.
* The company name is WooCommerce's own Company field (every invoicing provider reads it). When the store hides that field, the plugin shows its own, for invoices only.
* Tax office and activity: required, optional or hidden. Head-office address: optional.
* Returning customers find their details filled in; they can change them in My account.
* Another plugin can ask for an invoice (filter `ivd_require_invoice`), e.g. for trade orders.

**Checking the number**

* Greek ΑΦΜ: the check digit is tested locally, without any network. VIES is not used as a gate for Greek numbers, because it only knows businesses registered for intra-EU trade.
* VAT numbers of other EU countries: checked in the European Commission's VIES service when the order is placed (public service, no key). When VIES does not answer, the order always goes through with a note and can be checked later with one click. A number VIES does not know is accepted with a note, or refused, as you choose.

**On the order**

* Rows in the order totals: thank-you page, My account and every order email.
* A «Invoice details» box on the admin order screen: edit the details, see the last check (with the VIES name, address and request number), «Check in VIES».
* An «I» column and an «Invoices only» filter in the orders list (HPOS and legacy).
* «Invoices» page: the invoice orders of a period with net, VAT and total after refunds, and a CSV export («Excel Greek» with ; and decimal comma, or standard CSV).

**Switching from another plugin**

* Orders made with «Timologia for WooCommerce» or «Timologia for WooCommerce OBS» keep showing their details, appear in the list and the filter, and can be edited.
* Optionally the details are also written under the meta names of one of those plugins, so an invoicing provider that reads them keeps working.

**Safety**

* Test mode after activation: only shop managers see the choice until you switch it on for everyone.
* `define( 'IVD_DISABLE', true );` in wp-config.php stops every checkout hook at once; orders keep showing their details.
* No cookie, no session, no public endpoint. The only external request is VIES, with the country and the number only.
* WordPress privacy tools: the invoice details are in the order export and erasure, the customer's saved details have their own exporter and eraser, and a paragraph is suggested for the privacy policy.

**For developers**

* `ivd_get( $order )`: the invoice details of an order (array) or null.
* `ivd_is_invoice( $order )`: true for an invoice.
* Filter `ivd_require_invoice( bool $required, WC_Cart|null $cart )`.
* Filter `ivd_text( string $text, string $key, string $lang )` for the customer texts.
* Filter `ivd_vies_response( $pre, string $country, string $number )` to answer VIES in tests.
* Ability `invoice-details/get-order` (WordPress 6.9+, read only).

== Installation ==

1. Upload the `invoice-details` folder to `/wp-content/plugins/` or install the zip from Plugins › Add New, or from Noxpress › Dashboard.
2. Activate it. WooCommerce must be active.
3. Open Noxpress › Τιμολόγια › Settings, check the fields and the texts, place a test order as a shop manager, then switch the mode to «On for everyone».

== Frequently Asked Questions ==

= Does it issue invoices or send them to myDATA? =

No. It collects and checks the details your invoicing provider needs. The provider (or orOS Invoices) issues the document.

= Why is a Greek ΑΦΜ not checked in VIES? =

VIES only knows businesses registered for intra-EU trade. Many Greek businesses are not, so a correct number would look invalid. The check digit catches typing mistakes without any network. You can still look a Greek number up in VIES from the order screen, as information.

= What is kept when I delete the plugin? =

The details on the orders (they are store records) and, unless you tick the option, the customers' saved details. The settings are deleted.

== Disclaimer ==

The plugin helps collect invoice details; checking that an invoice is issued correctly and on time stays with the store and its accountant.

== Changelog ==

= 1.0.0 =
* First release: receipt / invoice choice in the classic checkout and the Checkout block, company name, VAT number, tax office, activity and head office, Greek check digit, VIES for other EU countries (an outage never stops an order), details on the order, in the emails and in My account, admin box with «Check in VIES», orders list column and filter, «Invoices» page with CSV, reading of Timologia / OBS orders and optional compatible meta, test mode, IVD_DISABLE, read-only ability, privacy export and erasure.
