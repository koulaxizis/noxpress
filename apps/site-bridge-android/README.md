# Site Bridge — Android app

Opens and closes the **write window** of the sites of a Site Bridge Hub from your phone, and shows the Hub's log. Nothing else. Design: Noxpress Bible §21 and §13.

Works with Claude. Not made by or affiliated with Anthropic.

## Requirements

* Android 9 or newer.
* A Hub with Site Bridge 1.6.0+ over HTTPS, with **Noxpress → Site Bridge → App** switched on.
* On each site whose window the app should open: **Access** → "The Hub may open this site's write window", and level write or admin. Sites at read never show in the app.

## Install and pair

1. Download `site-bridge.apk` from the latest `nox-sba-*` release and install it (allow installs from your browser or file manager once). Check the SHA-256 in the release notes if you like.
2. On the Hub: **App** → **New code for a phone**.
3. In the app: **Scan QR** (or paste the code). The code works for 15 minutes, once.

To remove a phone, revoke it in the Hub's App tab, or use **Disconnect** in the app's Settings.

## How it signs

On pairing the app makes two ECDSA P-256 keys in the Android Keystore and sends only their public parts:

* **read** — status, log and closing a window; no prompt.
* **act** — opening a window. The Keystore signs with it only right after a fingerprint (or, from Android 11, the screen lock), once per signature. Adding a fingerprint cancels it, and the app asks to pair again.

Each request carries `X-SBA-Device`, `X-SBA-Ts`, `X-SBA-Nonce`, `X-SBA-Key` and `X-SBA-Sig`, the signature of

```
SBA1|<METHOD>|/site-bridge/v1/<path>|<device>|<unix time>|<nonce>|<sha256 hex of body>
```

The Hub accepts ±300 s and each nonce once. See `plugins/site-bridge/includes/class-app.php`.

## Build

```
./gradlew testDebugUnitTest lintDebug assembleDebug
```

Pull requests get the debug APK as an artifact of the "Android app" workflow. Release: raise `versionName` / `versionCode` in `app/build.gradle.kts`, add a `## <version>` section here in `CHANGELOG.md` and merge; the workflow publishes `nox-sba-<version>` with the signed APK. The signing key comes from the repository secrets (see `.github/workflows/android.yml`).

The debug build accepts plain http to `10.0.2.2`, `127.0.0.1` and `localhost` (a local test WordPress from the emulator). The release build is HTTPS only.

## Licence

MIT, like the rest of noxpress.
