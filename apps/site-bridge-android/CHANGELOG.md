# Site Bridge app — changelog

## 1.1.0

* New: "Renew for <length> from now" on a site whose window is open. It asks for the fingerprint like opening and starts the picked length from that moment (it replaces the time left, it does not add to it). Needs no Hub change.
* Debug builds are signed with a fixed debug key from the repo, so a newer debug APK installs over the old one (each CI run used a new key).

## 1.0.0

* First release (beta). Pair the phone with a Site Bridge Hub (1.6.0+) by scanning the QR of the Hub's App tab, or by pasting its code.
* Sites: every site of the Hub with level write or admin, with the state of its write window and a live countdown. Pick a length (15 minutes to 12 hours), open the window after your fingerprint or screen lock, or close it early.
* Log: the latest 50 rows of the Hub's log, for all sites or one.
* Quick Settings tile for one site: a tap opens its window (through the app, for the fingerprint) or closes it.
* Two EC P-256 keys in the Android Keystore that never leave the phone; the key that opens windows signs only right after authentication. No password, token, analytics or Firebase.
* Greek and English, from the phone's language.
