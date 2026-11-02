# Live Operator app — changelog

## 2.0.1

* Fix: the app closed as soon as a site was connected, and then on every start. The connect screen started the site's page in its own task, so the web-app launcher restarted itself, saw its first copy still open and closed. It now starts in a new task.

## 2.0.0

* New: one app for every site. The official app no longer opens glarolykoi.net by itself: on the first start it shows a connect screen, filled in by «Connect the app» in the plugin's settings (Live Operator 1.4.0+, `liveoperator://connect?site=…`) or by typing the site's address. It checks the site's `/.well-known/assetlinks.json` before it connects, and asks before every change; long-press the icon → «Change site» to switch.
* New: in the Noxpress F-Droid repository (F-Droid, Droid-ify). When F-Droid, Droid-ify or Obtainium installed the app, its own new-version notification and the page's line stay quiet.
* Removed: the `liveOperatorUrl` / `liveOperatorName` build properties and the fixed App Links host; one build serves every site.
* After updating from 1.x, connect once (on glarolykoi: «Connect the app» in the settings); the login and notifications stay as they were.

## 1.3.1

* Fix: the "new version" check would miss a newer app. It read only the 50 newest releases of the repo, and the app's own releases are already further down among the plugin releases; it now reads 100 per page, up to 5 pages, until it finds them.

## 1.3.0

* New: a chat notification (new request, visitor message) lights the screen for a few seconds when it is off, and shows as a heads-up with sound on a new high-importance channel «Chat» / «Συνομιλίες». With Do Not Disturb on, only the notification shows, as before. The channel can be changed or turned off in the system's app notification settings.

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
