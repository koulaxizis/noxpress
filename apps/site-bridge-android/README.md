# Site Bridge — Android app

Opens and closes the **write window** of the sites of a Site Bridge Hub from your phone, and shows the Hub's log. From 1.2.0 it has a **Book Feed** tab (sync, rules, ISBN lookup and import as a draft, notifications) on sites that have Book Feed 1.11.0+. Design: Noxpress Bible §21 and §13; notes/android-app/03-book-feed-app.md.

Works with Claude. Not made by or affiliated with Anthropic.

## Requirements

* Android 9 or newer.
* A Hub with Site Bridge 1.6.0+ (1.7.0+ for plugin tabs, on the Hub and on the site) over HTTPS, with **Noxpress → Site Bridge → App** switched on.
* On each site whose window the app should open: **Access** → "The Hub may open this site's write window", and level write or admin. Sites at read never show in the app.

## Install and pair

1. Download `site-bridge.apk` from the latest `nox-sba-*` release and install it (allow installs from your browser or file manager once). Check the SHA-256 in the release notes if you like.
2. On the Hub: **App** → **New code for a phone**.
3. In the app: **Scan QR** (or paste the code). The code works for 15 minutes, once.

### Updates

The app looks at the repository's releases when it comes to the front, at most every 12 hours, and shows **New version X — Download** under the header when a newer `nox-sba-*` release exists. It only opens the APK link; Android asks before installing. A signed release installs over the previous one only when both are signed with the same key (a debug build does not update a signed one).

From 1.5.0 the same check also runs in the background every 12 hours (WorkManager): a newer version brings one notification, whose tap or **Download** downloads the APK in the browser, also while the app is closed. Android 13+ asks for the notification permission once.

Or let [Obtainium](https://github.com/ImranR98/Obtainium) follow it: **Add app** → `https://github.com/koulaxizis/noxpress` → turn on **Include prereleases** and set **Filter release titles by regular expression** to `^Site Bridge app` (the repository also publishes plugins and the Live Operator app). The APK asset is `site-bridge.apk`.

To remove a phone, revoke it in the Hub's App tab, or use **Disconnect** in the app's Settings.

## How it signs

On pairing the app makes two ECDSA P-256 keys in the Android Keystore and sends only their public parts:

* **read** — status, log and closing a window; no prompt.
* **act** — opening or renewing a window, and every plugin action that changes a site (Book Feed: sync, stop, rules, import). The Keystore signs with it only right after a fingerprint (or, from Android 11, the screen lock), once per signature. Adding a fingerprint cancels it, and the app asks to pair again.

Each request carries `X-SBA-Device`, `X-SBA-Ts`, `X-SBA-Nonce`, `X-SBA-Key` and `X-SBA-Sig`, the signature of

```
SBA1|<METHOD>|/site-bridge/v1/<path>|<device>|<unix time>|<nonce>|<sha256 hex of body>
```

The Hub accepts ±300 s and each nonce once. See `plugins/site-bridge/includes/class-app.php`.

## Plugin tabs

`POST app/ability` runs one ability of a short allow-list (`SBR_App::MODULES`) on one site, through the Hub's `ability_run`, so the site applies its level and write window as for Claude. A tab shows only for sites whose status lists the plugin in `modules`. When an action needs the window and it is closed, the Hub opens it for 15 minutes first if the site allows it (`window_opened` in the answer).

Rule preview (1.3.0): `book-feed/rule-preview` with the read key; the dialog shows it only while its fields are the ones it was made for.

Notifications: WorkManager, about once an hour, `book-feed/notices` with the read key for each site with Book Feed; the first look only sets the starting time.

## Build

```
./gradlew testDebugUnitTest lintDebug assembleDebug
```

Pull requests get the debug APK as an artifact of the "Android app" workflow. Release: raise `versionName` / `versionCode` in `app/build.gradle.kts`, add a `## <version>` section here in `CHANGELOG.md` and merge; the workflow publishes `nox-sba-<version>` with the signed APK. The signing key comes from the repository secrets (see `.github/workflows/android.yml`).

The debug build accepts plain http to `10.0.2.2`, `127.0.0.1` and `localhost` (a local test WordPress from the emulator). The release build is HTTPS only.

## Licence

MIT, like the rest of noxpress.
