# Site Bridge app — changelog

## 1.4.0

* New: the app looks on GitHub for a newer `nox-sba-*` release (when it comes to the front, at most every 12 hours) and shows "New version X" under the header with a "Download" link to the signed APK. Nothing is installed by itself and nothing about the phone or the Hub is sent; a failed look stays silent.
* Obtainium can follow the releases too (see the README).

## 1.3.0

* New: "Preview" in the rule dialog: what the rule as filled in would bring on its next run (matching titles, already in the store, new, to be imported, and the first new titles), without saving or importing anything. Needs Book Feed 1.12.0+ on the site and Site Bridge 1.7.1+ on the Hub. Uses the read key, no fingerprint.

## 1.2.1

* New: a rule's publishing status can be "Every status except this one" (e.g. every status but «Εξαντλημένο»), as in WP admin. Before, the app kept such a rule but could not make one.

## 1.2.0

* New: a Book Feed tab, shown when a site of the Hub has Book Feed 1.11.0+ and Site Bridge 1.7.0+. The sync's state with "Sync now" and "Stop"; the rules with "Run now", new rules and changes (publisher search, title words, contributor, kind, publishing status, years, every how many days, draft or published, on/off) and delete; an ISBN lookup by typing or by scanning the barcode, with "Import as a draft" for a title the store does not have.
* Anything that changes the site asks for the fingerprint. If the site's write window is closed and the site lets the Hub open it, the Hub opens it for 15 minutes first and the app says so.
* New: Book Feed notifications (off until you turn them on): about once an hour the app asks the Hub what happened on each site with Book Feed and shows new titles, sync errors and titles that left the catalogue. No Firebase: WorkManager, with the read key.

## 1.1.0

* New: "Renew for <length> from now" on a site whose window is open. It asks for the fingerprint like opening and starts the picked length from that moment (it replaces the time left, it does not add to it). Needs no Hub change.
* Fixed: "The phone's clock is wrong" when opening, with the phone's clock right. Most likely a GET answered from a cache in front of the Hub (e.g. a CDN) brought an old "now", and the app took it as the Hub's time. GETs now carry a throwaway parameter so no cache can answer them, opening retries once after a clock refusal (a second fingerprint), and the message shows the difference in seconds.
* Debug builds are signed with a fixed debug key from the repo, so a newer debug APK installs over the old one (each CI run used a new key).

## 1.0.0

* First release (beta). Pair the phone with a Site Bridge Hub (1.6.0+) by scanning the QR of the Hub's App tab, or by pasting its code.
* Sites: every site of the Hub with level write or admin, with the state of its write window and a live countdown. Pick a length (15 minutes to 12 hours), open the window after your fingerprint or screen lock, or close it early.
* Log: the latest 50 rows of the Hub's log, for all sites or one.
* Quick Settings tile for one site: a tap opens its window (through the app, for the fingerprint) or closes it.
* Two EC P-256 keys in the Android Keystore that never leave the phone; the key that opens windows signs only right after authentication. No password, token, analytics or Firebase.
* Greek and English, from the phone's language.
