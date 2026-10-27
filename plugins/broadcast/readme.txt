=== Broadcast ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: viber, channel, announcements, youtube, mailerlite
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Announces your site's events on a Viber Channel: new products, a newsletter that went out, new YouTube videos and posts you prepare. A queue with approval, one template per source, no duplicates, a full history.

== Description ==

Broadcast routes events from sources to destinations through one queue. Every source and every destination is a module you switch on one by one; a module that is off loads no code.

**Sources**

* New products (WooCommerce): every product that becomes published, checked a few minutes later so fixes made right after publishing are in the post. Category filter (in / out, with subcategories), product types, only with an image. Other publishers' titles imported by Book Feed and categories hidden by Hide Catalog are left out by default. Pre-orders from Pre-Orders show their release date.
* Newsletter: MailerLite's «campaign.sent» webhook, accepted only with a valid signature, posts the campaign's name and a link you choose. orOS Newsletter will use the same route.
* YouTube: new videos of up to five channels from their public feeds, no API key. Shorts are left out by default (the channel's «long videos» list), with a switch to include them and a separate switch for live streams.
* Post queue: posts prepared by a routine (for example Claude through Site Bridge, with the broadcast/queue-add ability) or by you in the admin. They wait until you press «Published», on a screen that works on a phone.
* Weekly Book Feed digest (off by default): one message a week with the new titles of other publishers, instead of one post per title.

**Destination**

* Viber Channel through the Channels Post API: paste the channel's token (Developer Tools), press Connect, and the plugin registers its webhook and reads the channel and its superadmin. Pictures with a caption, text, or a bare link.
* Telegram Channel and Facebook Page show as «coming soon».

**Safe by design**

* Test mode by default: the queue fills as usual and the History shows exactly what would be sent, but nothing leaves the site.
* Approval for every source at first; after 14 days the plugin asks whether to make them automatic (it never switches on its own). The post queue always waits for «Published».
* No duplicates: every item is posted once per destination, a webhook delivered twice is ignored, and the same link is not posted to the same place twice within 7 days.
* A brake per source (5 an hour, 20 a day by default): a bulk publish waits for approval instead of flooding the channel.
* The first time a source runs, what already exists is only noted: nothing old is posted.
* Posting happens in the background (Action Scheduler or WP-Cron), with three retries, and never during a visitor's request. Nothing runs on the front end.
* The Viber token and the webhook secret are sealed with the salts of wp-config.php (or set as constants), never exported and never shown in the history.
* `define( 'BRC_DISABLE', true );` in wp-config.php stops every source, every post and every webhook.
* History with filters and a CSV export (Excel Greek or standard), kept 90 days by default.
* Settings export / import (without the secrets).
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**For developers**

* Abilities (WordPress 6.9+): `broadcast/queue-add` ( text, url, image_url or attachment_id, ref ), `broadcast/queue-list`, `broadcast/queue-send` ( id, text ), `broadcast/status`.
* `brc_message` filter ( array $message, string $source, string $key ): change a message before it is queued; an empty array drops it.
* `brc_item_sent` action ( int $id, array $item ): after each post.
* REST `POST /wp-json/broadcast/v1/hook/newsletter/mailerlite` (signed) and `POST /wp-json/broadcast/v1/viber` (Viber callbacks, signed).
* Constants `BRC_VIBER_TOKEN` and `BRC_MAILERLITE_SECRET` in wp-config.php take precedence over the saved secrets.

== Installation ==

1. Upload the `broadcast` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New).
2. Activate the plugin and open **Noxpress → Broadcast**.
3. In **Modules**, switch on Viber Channel and the sources you want. The mode stays «Test» until you change it.
4. In **Viber**, paste the channel token (Viber → channel info → Settings → Developer Tools) and press **Connect to Viber**.
5. In **Sources**, set the categories, the MailerLite webhook (address and secret) and the YouTube channels. Adjust the texts in **Templates**.
6. Watch the **Queue** and the **History** in test mode, then switch the mode to live.

== Frequently Asked Questions ==

= Does it post my old products or videos? =

No. A product is posted only when it becomes published while the module is on, and the first YouTube check only notes the videos that are already there.

= Why does the newsletter post not have the subject? =

MailerLite's webhook sends the campaign's name, not its subject, and no public web-version link. The post uses the name and the fixed link you set (for example your newsletter archive).

= How are Shorts left out without an API key? =

The plugin reads the feed of the channel's «long videos» list, where YouTube does not include Shorts. If that list cannot be read for a channel, it reads all uploads and asks youtube.com for each new video whether it is a Short; a video it cannot tell waits for your approval.

= What happens on uninstall? =

The webhook at Viber is removed, and the queue and history table, the settings, the sealed secrets, the transients and the background jobs are deleted. What was already posted stays in your channel. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.2 =
* Fix: «Connect to Viber» could fail with "set_webhook: status 1": the webhook route answered Viber's check call with 403 when it was not signed as expected. The route now answers every call with 200 and reads or stores nothing from an unsigned one (signed calls are still noted). When Viber reports status 1 again, the message says whether any call from Viber reached the site, which points to a firewall or Cloudflare rule.

= 1.0.1 =
* Fix: a YouTube @handle was not found on servers in the EU, where youtube.com answers with its cookie consent page; the lookup now skips that page. When a handle still cannot be read, the message says where to find the Channel ID (UC…).

= 1.0.0 =
* Initial release, with modules switched on one by one.
* Sources: new WooCommerce products (categories, types, image; Book Feed titles and Hide Catalog categories out by default; Pre-Orders dates), MailerLite «campaign.sent» webhook with signature check, YouTube channels without an API key and without Shorts by default, a post queue for routines and manual posts sent on «Published», and a weekly Book Feed digest.
* Destination: Viber Channel (Channels Post API) with one-click connect, webhook with signature check, pictures with captions and text.
* Queue with approval, a 14-day trial notice, dedupe, a brake per source, retries in the background, history with CSV export, templates per source.
* Test / live / off mode, BRC_DISABLE, sealed secrets, settings export / import, four abilities. Works without WooCommerce. Noxpress hub and updates from noxpress.tech (Noxpress Core 1.2.2).
