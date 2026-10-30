=== Broadcast ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: viber, channel, announcements, youtube, instagram
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.3
License: MIT
License URI: https://opensource.org/licenses/MIT

Announces your site's events on a Viber Channel: new products, a newsletter that went out, new YouTube videos, new Instagram posts and posts you prepare. A queue with approval, one template per source, no duplicates, a full history.

== Description ==

Broadcast routes events from sources to destinations through one queue. Every source and every destination is a module you switch on one by one; a module that is off loads no code.

**Sources**

* New products (WooCommerce): every product that becomes published, checked a few minutes later so fixes made right after publishing are in the post. Category filter (in / out, with subcategories), product types, only with an image. Other publishers' titles imported by Book Feed and categories hidden by Hide Catalog are left out by default. Pre-orders from Pre-Orders show their release date.
* Newsletter: MailerLite's «campaign.sent» webhook, accepted only with a valid signature, posts the campaign's name and a link you choose. orOS Newsletter will use the same route.
* YouTube: new videos of up to five channels from their public feeds, no API key. Shorts are left out by default (the channel's «long videos» list), with a switch to include them and a separate switch for live streams.
* Instagram: new posts of your Business or Creator account through the Instagram API, with a token from your own Meta app (no app review for your own account). Checked every 30 minutes, so no webhook has to reach the site. Photos and albums by default, videos and Reels with a switch; hashtags at the end of the caption removed by default. The token renews itself every month.
* Post queue: posts prepared by a routine (for example Claude through Site Bridge, with the broadcast/queue-add ability; queue-list, queue-send and queue-reject let an app work the queue) or by you in the admin. They wait until you press «Published», on a screen that works on a phone.
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
* The Viber token, the webhook secret and the Instagram token are sealed with the salts of wp-config.php (or set as constants), never exported and never shown in the history.
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

= 1.1.3 =
* Fixed: Instagram pictures did not reach Viber or the Site Bridge app, so a post could go out as a bare link. Instagram's picture links are signed and often longer than 500 characters; they were cut at 500, which broke the signature. Links up to 2048 characters are now kept whole, and a longer one is dropped instead of cut.
* Changed: every check of Instagram also renews the picture link of waiting Instagram items, since Instagram's links expire.

= 1.1.2 =
* Fixed: an Instagram post could wait in the Queue with only its link and picture and no text, when Instagram gave the post without its caption at first (a caption added or edited after publishing). A waiting Instagram item without text now gets the caption on the next check, and at the latest just before it is posted. A text you wrote yourself is never replaced.

= 1.1.1 =
* Fixed: Instagram pictures showed as broken in the Queue, because Instagram's CDN does not let other sites show its images. The Queue now reads them on the server (only from Instagram's own hosts, only images) and asks for a fresh link once when the stored one has expired.
* Changed: the default Instagram template is «{text}» without the link to the Instagram post, since the Viber post already carries the picture and the caption. Saved templates stay as they are.

= 1.1.0 =
* New: module «Instagram» (source, off by default). New posts of your Instagram account (Business or Creator) through the Instagram API with Instagram Login and a token from your own Meta app, read every 30 minutes (15–120). Photos and albums by default; videos and Reels with a switch. Hashtags at the end of the caption are removed by default. A caption too long for a picture post is cut at a word so the picture and the link stay. The image is asked for again just before posting, since Instagram image links expire. The token is sealed and renewed every month; the admin warns 7 days before it expires.
* New: ability broadcast/queue-reject (drops a waiting item), for the Site Bridge app.
* Changed: the Viber connection no longer shows «0 subscribers» when Viber does not report the number.

= 1.0.4 =
* New: Viber → «Webhook on another site». When a firewall or CDN you do not control keeps Viber away from this site, set_webhook can point at any https address that answers 200 (e.g. Broadcast on another site). Posts still leave from this site; only the signed-callback note is lost.

= 1.0.3 =
* New: when Viber cannot reach the webhook at /wp-json/broadcast/v1/viber (status 1, no call reached the site), «Connect to Viber» tries the same route as /?rest_route=/broadcast/v1/viber, for hosts whose firewall or CDN keeps callers away from /wp-json/.

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
