# Live Operator — Android app

The PurioChat Pro live chat of your site on your phone: the **Live Operator** plugin's page (`/live-operator/`) opened full screen as a [Trusted Web Activity](https://developer.chrome.com/docs/android/trusted-web-activity). The online / offline switch, the inbox, claim, reply and return to the AI all come from the plugin; the app adds the icon on the home screen, the full screen and the notifications as its own. Design: Noxpress Bible §21 (Live Operator) and §13.

Little code of its own: `com.google.androidbrowserhelper` 2.5.0 (Google) does the launch, the verification and the notification delegation. From 1.3.0 a chat notification is a heads-up on the «Chat» channel and lights the screen for a few seconds when it is off (not with Do Not Disturb). No Firebase, no analytics.

## Requirements

* Android 9 or newer, with Chrome (or another browser that supports Trusted Web Activities). Without one, the page opens in a Custom Tab.
* The Live Operator plugin on the site, with the switch enabled, and a login as an administrator in that browser. From plugin 1.4.0 the site lists the app in `/.well-known/assetlinks.json` by itself, so the app opens without an address bar.

## Install

One app for every site (2.0.0+). Install it one of these ways; all are the same APK signed with the same key, so one updates over the other:

* **F-Droid or Droid-ify:** add [the Noxpress F-Droid repository](https://noxpress.tech/#fdroid) and install **Live Operator**.
* **APK:** download `live-operator.apk` from the latest `nox-loa-*` release (allow installs from your browser or file manager once). Check the SHA-256 in the release notes if you like.
* **Obtainium:** **Add app** → `https://github.com/koulaxizis/noxpress` → turn on **Include prereleases** and set **Filter release titles by regular expression** to `^Live Operator app` (the repository also publishes plugins and the Site Bridge app). The APK asset is `live-operator.apk`.

## Connect it to your site

1. On the phone, open **Noxpress → Live Operator** in your site's admin and tap **Connect the app** (plugin 1.4.0+). The app opens its connect screen with the site filled in. Or open the app and type the site's address (`example.com`; https only).
2. Tap **Connect**. The app checks that the site lists it in `/.well-known/assetlinks.json` and then opens `/live-operator/`. If the site does not list it (plugin older than 1.4.0 without the fingerprint, or a server that does not pass `/.well-known/` to WordPress), it says so and **Connect anyway** opens the page with a thin address bar.
3. Log in, tap **Turn on notifications** and **Test**.

A link only fills in the address: the app always asks before it connects, so a page elsewhere cannot switch your site. To change the site, long-press the app's icon → **Change site**.

## Updates

With F-Droid, Droid-ify or Obtainium, updates come from the store, and the app's own notices stay quiet (it reads Android's installer record).

Installed from the APK, the app opens the page as `/live-operator/?app=<version>`, so the plugin (1.1.0+) looks at the repository's releases at most every 12 hours and shows **New app version X. Download** at the top when a newer `nox-loa-*` release exists. It also checks in the background every 12 hours (WorkManager) and brings one Android notification for a newer version, whose tap downloads the APK in the browser. Android asks before installing, and a signed release installs over the previous one only with the same key.

## Your own build

Not needed for any site since 2.0.0. If you build and sign the app yourself, add your certificate's SHA-256 under **Noxpress → Live Operator → Android app → Extra SHA-256 certificate fingerprints**, so the site lists your build too. The package stays `tech.noxpress.liveoperator`.

## Build and release

`.github/workflows/android-live-operator.yml`: on every PR a debug APK as an artifact of the run; on main, when `versionName` in `app/build.gradle.kts` has no `nox-loa-<version>` release yet, a signed `live-operator.apk` as a pre-release. Signed with the same key as the Site Bridge app (secrets `SBA_*`), so one fingerprint covers both. The debug APK uses the public `app/debug.keystore`; its fingerprint is printed in the run's summary, for testing a debug build.
