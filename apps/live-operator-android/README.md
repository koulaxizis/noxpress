# Live Operator — Android app

The PurioChat Pro live chat of a site on your phone: the **Live Operator** plugin's page (`/live-operator/`) opened full screen as a [Trusted Web Activity](https://developer.chrome.com/docs/android/trusted-web-activity). The online / offline switch, the inbox, claim, reply and return to the AI all come from the plugin; the app adds the icon on the home screen, the full screen and the notifications as its own. Design: Noxpress Bible §21 (Live Operator) and §13.

No code of its own: `com.google.androidbrowserhelper` 2.5.0 (Google) does the launch, the verification and the notification delegation. No Firebase, no analytics.

## Requirements

* Android 9 or newer, with Chrome (or another browser that supports Trusted Web Activities). Without one, the page opens in a Custom Tab.
* The Live Operator plugin 1.0.0+ on the site, with the switch enabled, and a login as an administrator in that browser.

## Install

1. Download `live-operator.apk` from the latest `nox-loa-*` release and install it (allow installs from your browser or file manager once). Check the SHA-256 in the release notes if you like.
2. Copy the **signing certificate SHA-256** from the same notes into **Noxpress → Live Operator → Android app → SHA-256 certificate fingerprints** and save. The site then serves `/.well-known/assetlinks.json`, and the app opens without an address bar. (Without it the app works, with a thin address bar.)
3. Open the app, log in, tap **Turn on notifications** and **Test**.

## Updates

From 1.1.0 the app opens the page as `/live-operator/?app=<version>`. The plugin (1.1.0+) then looks at the repository's releases at most every 12 hours, from the phone, and shows **New app version X. Download** at the top when a newer `nox-loa-*` release exists. Android asks before installing, and a signed release installs over the previous one only with the same key. Builds for another site (below) leave the parameter out, so they never point to this app's releases.

From 1.2.0 the app also checks in the background every 12 hours (WorkManager), so a newer version brings one Android notification, whose tap or **Download** downloads the APK in the browser, also while the app is closed. It uses the notification permission the app asks for the chat notifications. Only in the official build.

Or let [Obtainium](https://github.com/ImranR98/Obtainium) follow it: **Add app** → `https://github.com/koulaxizis/noxpress` → turn on **Include prereleases** and set **Filter release titles by regular expression** to `^Live Operator app` (the repository also publishes plugins and the Site Bridge app). The APK asset is `live-operator.apk`.

## Another site

The site and the name are build properties:

```
./gradlew assembleRelease -PliveOperatorUrl=https://example.com/live-operator/ -PliveOperatorName="Example Live"
```

The package stays `tech.noxpress.liveoperator`, the plugin's default.

## Build and release

`.github/workflows/android-live-operator.yml`: on every PR a debug APK as an artifact of the run; on main, when `versionName` in `app/build.gradle.kts` has no `nox-loa-<version>` release yet, a signed `live-operator.apk` as a pre-release. Signed with the same key as the Site Bridge app (secrets `SBA_*`), so one fingerprint covers both. The debug APK uses the public `app/debug.keystore`; its fingerprint is printed in the run's summary, for testing a debug build.
