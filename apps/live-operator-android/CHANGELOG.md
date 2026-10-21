# Live Operator app — changelog

## 1.2.0

* New: a notification for a newer signed app, also while the app is closed. WorkManager looks at the `nox-loa-*` releases every 12 hours; each new version is told once, and a tap (or "Download") downloads its APK in the browser. It uses the notification permission the app already asks for the chat notifications. Only in this official build.

## 1.1.0

* New: the app opens the page with its version, and the Live Operator plugin 1.1.0+ shows "New app version X. Download" at the top when a newer `nox-loa-*` release exists (it looks at GitHub at most every 12 hours). Only in this official build; a build for another site does not send its version.
* Obtainium can follow the releases too (see the README).

## 1.0.1

* The app is called «Live Operator» on the home screen (1.0.0 was published as «Γλαρόλυκοι Live»). Same signing key: installs over 1.0.0.

## 1.0.0

* First release: «Live Operator», the Live Operator page of glarolykoi.net (/live-operator/) full screen, as a Trusted Web Activity.
* Online / offline, the inbox, claim, take over from the AI, reply and return to the AI, all from the Live Operator plugin's page.
* The site's notifications (a new chat request, a visitor message, the end of the online time) show as the app's own, with the app's icon. No Firebase.
* Needs the Live Operator plugin 1.0.0+ on the site, Chrome (or another browser with Trusted Web Activity support) on the phone, and the signing certificate fingerprint of these notes in the plugin's settings.
