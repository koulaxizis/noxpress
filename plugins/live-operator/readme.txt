=== Live Operator ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: live chat, operator, push notifications, pwa, android
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

The live chat of PurioChat Pro on your phone: an online / offline switch kept on the server, a notification for every request and message, and replies from an app.

== Description ==

PurioChat Pro shows «talk to a human» only while an operator is present, and it counts as present only while its Live Chat page is open on a screen. On a phone that page sleeps within minutes. Live Operator moves that decision to a switch on the server and gives you an app to answer from.

**Online / offline from the phone**

* One tap makes you online; visitors see the human option even with the phone in your pocket and the screen off.
* Automatic offline after 1, 2, 4, 8 (default), 12 or 24 hours, with a notification when it happens. «Extend» starts the count again.
* Optional: an open PurioChat Live Chat page also counts as online (off by default, so a forgotten computer does not keep you online).

**Notifications for every request and message**

* Web Push sent by your own site: VAPID keys made on activation, messages encrypted end to end (RFC 8291 / 8292) with PHP's OpenSSL. No Firebase project, no Telegram, no third-party account.
* A notification for every new request and every visitor message. A conversation you claimed notifies only you.
* Optional message preview (on by default); off, the notification only says who wrote.

**Answer from the app**

* `/live-operator/` on your site: waiting, active, with the AI and recent conversations, with live waiting counters.
* Claim, take over from the AI, reply and return to the AI, through PurioChat's own operator routes: everything stays in PurioChat's inbox.
* Add it to the home screen, or use the Android app (below).

**Android app**

* `apps/live-operator-android` in the Noxpress repository: a Trusted Web Activity of `/live-operator/`, built and signed in GitHub Actions (release tag `nox-loa-<version>`, asset `live-operator.apk`).
* Paste the certificate fingerprint from the release notes into the plugin's settings: the site then serves `/.well-known/assetlinks.json` and the app opens full screen. Notifications show as the app's own.

**Safe by design**

* Administrators only (`manage_options`, the capability PurioChat's Live Chat asks for). The app page and its REST routes are never cached.
* The switch is off after activation: nothing changes until you enable it.
* Nothing of PurioChat is written: its presence is answered through WordPress's transient filters. `define( 'LOP_DISABLE', true );` in wp-config.php stops everything and PurioChat works exactly as without the plugin.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

== Installation ==

1. Install and activate the plugin. PurioChat Pro with Live Chat must be active.
2. In PurioChat › Live Chat, set the availability to «online». A waiting time of 300 seconds is recommended, so a request waits long enough for you to reach the phone.
3. Open **Noxpress → Live Operator**, tick «The app's switch decides when I am online» and save.
4. On the phone, open the app (or `https://your-site/live-operator/`), log in, tap «Turn on notifications» and then «Test».
5. For the Android app: install `live-operator.apk` from the latest `nox-loa-*` release and paste the certificate fingerprint from its notes into the settings.

== Frequently Asked Questions ==

= Does it need Telegram or Firebase? =

No. Notifications go from your site straight to the phone's push service (Google's for Chrome on Android), encrypted so that only your device can read them.

= My host strips the Authorization header. Does push still work? =

Yes. The Authorization header is sent by your site to the push service, not received by it.

= The Android app shows an address bar. =

The fingerprint is missing or wrong, or the server does not pass `/.well-known/assetlinks.json` to WordPress. The settings page links the file; if it gives a 404, upload it by hand with the same content.

= What happens on uninstall? =

The settings, the switch state, the push keys, the devices and the transients are removed. PurioChat Pro's data and settings are never touched. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.1.1 =
* Fix: a message with "<" (as in "<3") showed as "&lt;3" in the app and in the notification preview. PurioChat stores a lone "<" as an HTML entity; the app and the push now show the character again (still as plain text, never as HTML).
* The Android app 1.3.0 lights the screen and shows a heads-up for every chat notification.

= 1.1.0 =
* New: opened from the official Android app (1.1.0+), the page shows "New app version X. Download" when a newer nox-loa-* release exists. It looks at GitHub at most every 12 hours, from the phone, and stays silent on any error. In a browser, or in an app built for another site, nothing changes.

= 1.0.0 =
* Initial release.
* Server-side online / offline switch for PurioChat Pro's Live Chat, with automatic offline and a notification at the end.
* Web Push without third parties (VAPID, aes128gcm) for every new request and visitor message, with a test button.
* Operator app at /live-operator/ (installable), and Digital Asset Links for the companion Android app.
* LOP_DISABLE, bilingual admin, Noxpress hub and updates from noxpress.tech (Noxpress Core 1.1.18).
