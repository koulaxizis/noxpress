=== Events ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: events, calendar, tickets, check-in, event schema
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Events (book presentations, readings, signings, concerts, workshops, online events) with a date, venue, status and entry, linked to products and creators: an upcoming list with a shortcode and a block, an archive, «Add to calendar» (.ics and Google Calendar) and Event JSON-LD. WooCommerce is optional.

== Description ==

**The event**

* Start and end in the site time zone, a venue (name, address, city, postcode, country, map link) and/or an online link, entry (free or ticketed, with a price as text) and an external tickets or RSVP link.
* Status: scheduled, postponed, rescheduled (the old date is kept for the schema) or cancelled, shown as a line at the top of the page.
* Linked to WooCommerce products and to Featured Creators pages: the event shows on their pages in an «Upcoming events» box, only while it is upcoming.

**The pages**

* Each event has its own page (base /events/, can be changed) with a details box, and an archive of upcoming events with a link to the past ones. Each venue has its own page too.
* `[nx_events]` and the «Events» block list upcoming or past events anywhere, filtered by creator, product, venue or city.
* «Add to calendar»: a Google Calendar link and an .ics file per event; an optional .ics feed of every upcoming event.
* Event, LiteraryEvent, MusicEvent and the other schema.org event types, inside the Search Signals or Rank Math graph when one is active.

**Tickets (with WooCommerce)**

* A ticket is a shop product linked to the event: «Create a ticket» in the event box makes a hidden, virtual one, with stock equal to the seats. Ticket types (full, reduced) are variations.
* Sales open from an optional start date and close a set number of hours before the event (default 2), and stay closed for a cancelled or postponed event, on the page, in the cart and at the checkout.
* Each paid ticket gets its own code (ABCDE-FGHJK) and a QR code, under the order items, in the order e-mails, on a ticket page that works without an account and in the «Tickets» tab of My account. Refunds and cancellations cancel the extra tickets.
* Check-in from a phone: the role «Event cashier» opens only the door page, where the code is typed or read with the camera. A ticket can be used once.
* Attendee list with print and CSV, undo of a check-in, and «Notify the buyers» (an e-mail to every order with a valid ticket, off by default in WooCommerce → Emails).
* Optional attendee names per event (off by default), deleted a set number of days after the event, and in the privacy export and erasure.

**Rules**

* Test mode after activation: only people who edit posts see the event pages, lists and boxes.
* No cookie, no external request and no database write on a visitor request. Maps only as a link, never an embedded map.
* Past events leave the lists on their own (every list checks the current time) and page caches are purged after each event ends.
* EVT_DISABLE in wp-config.php stops every front-end hook.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. Without WooCommerce the events work the same; only the link to products is a list of ids.

= What happens on uninstall? =

The settings, the role «Event cashier» and the scheduled jobs are removed. The events, venues and tickets stay, unless «Delete the events and venues when the plugin is deleted» is on in Tools. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==
= 1.1.2 =
* Noxpress Core 1.5.0: a short «Noxpress» menu with your favourites (★) or your 5 most recent plugins, one row per plugin with tabs for its other pages, hub categories and search, every suite page in the command palette (Ctrl/Cmd+K) and a «Full menu» switch per user.

= 1.1.1 =
* Noxpress Core 1.4.16: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.1.0 =
* Tickets through WooCommerce: a product linked to the event, seats as stock, ticket types as variations, «Create a ticket» in the event box.
* Sales window (sales start, close before the start, closed when cancelled or postponed), checked on the page, in the cart, at the checkout and in the Store API.
* A code and a QR code per ticket, the ticket page, the «Tickets» tab in My account, codes in the order e-mails.
* Check-in page for the new role «Event cashier», with the camera; attendee list, print, CSV, undo.
* «Notify the buyers» e-mail (off by default), optional attendee names, privacy exporter and eraser.
* Offers in the Event schema from the ticket product. Ability events/tickets (counts only).
* Uninstall also removes the role, the scheduled jobs and, with «Delete the events», the tickets.

= 1.0.0 =
* Initial release.
* Events with date, venue, online link, status, entry and links to products and creators.
* Event and venue pages, upcoming / past archive, [nx_events] shortcode and «Events» block.
* «Upcoming events» boxes on product and Featured Creators pages.
* .ics per event, optional .ics feed, Google Calendar link.
* Event JSON-LD (in the Search Signals or Rank Math graph), sitemap rules.
* Page cache purges on save and after each event ends.
* Abilities events/list and events/create-draft, test mode, EVT_DISABLE, settings backup.
