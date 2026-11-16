=== Gift Cards ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: woocommerce, gift card, store credit, voucher, pdf
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Digital gift cards sold as a WooCommerce product, sent by email with a printable PDF on the date the buyer picks, and redeemed as a payment in the cart and at checkout, classic and block. The balance stays for the next order, and every movement is written to a ledger.

== Description ==

**The product**

* A new product type, "Gift card": fixed amounts (e.g. 10, 20, 30, 50), an optional own amount between a minimum and a maximum, or both. Always virtual; the tax status is set to "None" on the first save (gift cards are usually a payment, not a sale: ask your accountant).
* On the product page the customer chooses "For me" or "As a gift". A gift takes the recipient's name and email, a "From" name, a message of up to 300 characters and an optional delivery date up to 12 months ahead.
* Coupons do not apply to gift cards, and a gift card cannot be bought with a gift card (setting).

**Delivery**

* The card is created when the order becomes paid (processing and completed by default; cash on delivery only when completed, bank transfer and cheque only when you set the order to processing or completed) and is sent at 09:00 site time on the chosen date, or right after payment. Optional delay in hours, and manual approval for orders above an amount, against stolen payment cards.
* The recipient gets an email with the amount, the message, the code, how to use it and the expiry, with a printable PDF card (A5 landscape or A4) attached. The buyer of a gift gets a "sent to …" email without the code.
* The code is created at the moment of delivery and is stored only as a keyed hash (HMAC): it exists in the email and the PDF, and nowhere else, not even in the admin. A lost code is replaced with "New code and send": the old code stops working, the balance stays.
* Codes are 16 characters (80 bits) in groups of four, without letters that are easy to confuse.

**Redemption**

* A "Have a gift card?" field in the cart and at checkout, classic and block (Cart and Checkout blocks, through the Store API). More than one card per order (limit in settings).
* The card is a payment, not a discount: the order total, the product prices and the VAT stay exactly as they are; the amount the card pays is taken off what the customer still has to pay, and is shown as its own line in the totals, the emails and My account.
* The balance is taken when the order is placed, inside a database transaction with the card row locked, so two orders cannot spend the same balance. If the order fails or is cancelled, the amount goes back to the card. Unpaid orders that took a card are cancelled after 48 hours (setting) and the amount goes back.
* No coupon discounts a gift card, cart coupons included; a gift card line paid below its value waits for approval.
* A refunded or cancelled purchase voids its unused cards. A card already spent is disabled instead, and money that unpaid orders held on it goes back first.
* Refunds: WooCommerce refunds reach the part paid by other means; the gift card part is refunded to the same card from the "Gift cards" box on the order. A fully refunded order also gives the card part back (setting).
* Against code guessing: at most 5 attempts per 15 minutes per IP and per visitor (settings), and when the whole site sees too many wrong codes in an hour the field closes for an hour and the admin gets an email.

**The ledger**

* Every movement (issue, use, restore, refund, adjustment, void, expiry) is a row in an append-only ledger, in cents. The balance of a card always equals the sum of its ledger; this is checked on every movement and daily, and a card that fails the check is locked and the admin is told.
* Admin: list with search by last 4 characters, email, name or order; the card page with its history and actions (approve, send now, disable, enable, new code and send, adjust with a reason, void an unused card, unlock); a card issued by hand to an email; a summary of what the store owes (open balances) and the movements of a period.
* My account › Gift cards: the cards the customer bought (the balance only for cards bought for themselves) and the orders a card paid.
* Optional expiry in months, with a reminder email before it (no expiry by default).

**Everything else**

* All customer texts editable in Greek and English. Emails in WooCommerce › Settings › Emails (subject, heading, additional content); the card email itself cannot be switched off, because the code would be lost.
* Test mode (default): only shop managers can buy and redeem gift cards.
* `GFC_DISABLE` in wp-config.php stops selling, redeeming and sending without touching the balances.
* WP-CLI: `wp gift-cards list`, `summary`, `check` (read only).
* HPOS and Cart / Checkout blocks compatible. Noxpress hub and updates from noxpress.tech.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the `gift-cards` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
3. Activate the plugin. It starts in test mode.
4. Products → Add new, product type "Gift card": set the amounts.
5. Noxpress → Gift cards → Settings: delivery, approval, expiry, limits, PDF.
6. Buy and redeem a card with a shop manager account, then switch the mode to "On for everyone".

== Frequently Asked Questions ==

= Why is the card a payment and not a coupon? =

A coupon lowers the price, and with it the VAT of the sale. A gift card is money the customer already paid: the products keep their price and VAT, and the card pays part of the total, like any other payment method.

= The customer lost the code. Can I see it? =

No. The code is stored only as a keyed hash, so nobody who reads the database (or a backup) can use the cards. Open the card and press "New code and send": the holder gets a new code with the same balance and the old one stops working.

= Why can't I refund the gift card part with WooCommerce's Refund button? =

WooCommerce refunds up to the amount paid by the payment method, which does not include the card. Refund the card part from the "Gift cards" box on the order: it goes back to the same card and is written to its ledger.

= What happens on uninstall? =

The settings, the attempt counters, the transients and the background jobs are removed. The cards and their ledger stay by default: they are money the store owes its customers, and the codes work again if the plugin is installed again. To delete them too, tick the option in Tools and type ΔΙΑΓΡΑΦΗ. The order data and the gift card products always stay. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use. Ask your accountant how gift cards are recorded and taxed in your country.

== Changelog ==

= 1.0.1 =
* Noxpress Core 1.4.12: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.0.0 =
* Initial release (beta).
* Gift card product type with fixed and own amounts; "for me" or "as a gift" with recipient, message and delivery date.
* Delivery by email with a printable PDF, on a date, with optional delay and manual approval; buyer confirmation without the code; expiry reminder.
* Codes stored only as HMAC; new code and send.
* Redemption as a payment in the classic and block cart and checkout; restore on failed, cancelled and refunded orders; refund to the card from the order.
* Append-only ledger with a balance check on every movement and daily; admin list, card page, manual cards, summary; My account page.
* Limits against code guessing per IP, per visitor and site-wide.
* Public functions `gfc_is_gift_card_product()`, `gfc_order_cards()`; filter `gfc_text`; WP-CLI commands.
