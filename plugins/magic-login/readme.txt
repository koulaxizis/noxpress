=== Magic Login ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: passwordless, magic link, login, email, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Passwordless login for WooCommerce customers: one button in the login form emails a one-time link that expires in minutes. The password stays as an alternative.

== Description ==

Customers forget passwords, and a forgotten password at checkout is a lost order. Magic Login adds «Email me a login link» to WooCommerce's login form. The customer writes their email, presses the button and gets a link; one click on «Log in» and they are back where they were, the checkout with their cart included.

Nothing changes after activation: the mode is off until you choose test or live.

**How it works**

* The button sits in the login form of My Account and of the classic checkout (each can be switched off). It uses the email or username already typed in the form.
* The link is valid for 5–60 minutes (default 15) and works once. A new request cancels the previous link, and a password change or reset cancels any pending one.
* The link opens the My Account page with a «Log in» button and logs in nothing by itself. Mail scanners (Outlook Safe Links, Gmail) open links before the customer does; a link that logged in on opening would be used up by them.
* After the login the customer goes back to the page where they asked for the link, and stays logged in for 14 days like «Remember me» (a setting).
* The email is a WooCommerce email («Magic Login: login link» in WooCommerce → Settings → Emails): your email template, subject and heading, sent through wp_mail, so FluentSMTP or any SMTP plugin sends it. Switching the email off hides the button.

**Safe by design**

* Customers and trade accounts only. Staff accounts (edit_posts, manage_woocommerce or manage_options: administrators, shop managers, editors, authors) never log in by link, whatever a filter says: a stolen mailbox must not open the admin.
* 256-bit random tokens; only their HMAC (with the site's salt) is stored in user meta, with the expiry. Using a link is a compare-and-delete, so two quick clicks never make two logins.
* The same answer whether the email has an account or not. Limits per account (default 3 links in 15 minutes) and per IP address (default 20 requests an hour) count every request, existing account or not. IP addresses are never stored: only a salted hash, for an hour.
* The page the link opens is sent with no-cache, `Referrer-Policy: no-referrer` and noindex; the token travels in the body of the «Log in» POST, never in the form's address.
* Test mode: the button shows only on a login form opened with `?mgl-test` in the address, and links go only to the test accounts you list. A manager is logged in and never sees a login form, so this replaces the usual "managers only" preview.
* `define( 'MGL_DISABLE', true );` in wp-config.php stops every button and link.
* A log of the newest 200 events (time, user id, event: sent, used, expired, unknown email, staff, rate limit, email not sent), without emails or IP addresses, and counts for the last 30 days.
* Suggested text for your privacy policy (Settings → Privacy). Settings export / import.
* WordPress Abilities API (WordPress 6.9+): `magic-login/status` (read only; never a token, email or IP).
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

**Not covered**

* The Checkout block has no login form; customers on it log in from My Account.
* wp-login.php is left as it is.
* No account is ever created by a link request: an email without an account gets the neutral message and nothing else.

**For developers**

* Filter `mgl_user_allowed` ( bool $ok, WP_User $user ): staff stay excluded whatever it returns.
* Filter `mgl_text` ( string $text, string $key, string $lang ).
* Action `mgl_logged_in` ( int $user_id ), after WordPress's own `wp_login`.
* Templates `emails/mgl-link.php` and `emails/plain/mgl-link.php`, overridden by the theme in `yourtheme/woocommerce/emails/`.

== Installation ==

1. Upload the `magic-login` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub). WooCommerce is required.
2. Activate the plugin and open **Noxpress → Magic Login**.
3. Check the email in **WooCommerce → Settings → Emails → Magic Login: login link**.
4. Choose **Test**, list a test customer's email, and open My Account with `?mgl-test` in a private window. Ask for a link, open it, log in.
5. Switch the mode to **Live**.

== Frequently Asked Questions ==

= Can an administrator log in with a link? =

No. Accounts that can edit posts or manage the shop log in with their password only. A request for such an account gets the neutral message and no email.

= What if the customer opens the link on another device? =

It works: the link is not tied to a browser. The customer logs in on the device where they press «Log in».

= What if the email never arrives? =

The customer can ask again (within the limits) or log in with their password. The log shows «The email was not sent» when wp_mail refused it; check your SMTP plugin's log.

= What happens on uninstall? =

The settings, the log, the email's WooCommerce settings, pending links and rate-limit counters are removed. No user, password or order is touched. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.0 =
* Initial release.
* «Email me a login link» in the My Account and classic checkout login forms, with the email or username of the form.
* One-time links (256-bit, HMAC stored, 5–60 minutes), a confirmation page with a «Log in» button, back to the page of the request.
* Neutral answer, limits per account and per IP (hashed), staff never by link, links cancelled by a new link or a password change.
* WooCommerce email «Magic Login: login link» in the visitor's language (Greek / English), with HTML and plain-text templates.
* Off / test / live mode with test accounts, Greek and English visitor texts, log, privacy policy text, settings export / import, MGL_DISABLE, Abilities API status.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.2.3).
