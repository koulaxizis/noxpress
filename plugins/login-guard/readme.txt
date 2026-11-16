=== Login Guard ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: login, security, brute force, xml-rpc, user enumeration
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Limits on failed logins per IP and per account, with lockouts and an unlock link by e-mail, XML-RPC logins switched off, protection against user enumeration and an e-mail when an administrator logs in from a new device.

== Description ==

Every WordPress site gets password guesses all day: on wp-login.php, on the WooCommerce My Account form, through XML-RPC and through Application Passwords on the REST API. WordPress itself never says «stop». Login Guard counts the failures, locks the guessers out for a while and stops the site from telling them which user names exist.

Nothing a visitor sees changes after activation: the plugin starts switched off. In «Test» it counts failures and writes to its log what it would have locked, without locking anybody; only «Live» locks.

**Login limits**

* Per IP: 5 failed logins in 15 minutes lock that address for 15 minutes. 4 lockouts in 24 hours lock it for 24 hours.
* Per account: 20 failures in an hour, from any number of addresses, lock the account for 15 minutes. The real owner can lift that lock with a link by e-mail (below), so an attacker cannot keep you out of your own site.
* Counted before the password is checked, on every way in: wp-login.php, the WooCommerce login, XML-RPC and Application Passwords (which answer 429 while locked).
* Every number is a setting. An allow list (single addresses or ranges, up to /8 for IPv4 and /32 for IPv6) is never locked, and the address you save the settings from is trusted for 24 hours.
* Lost password: at most 5 requests an hour per address and 3 per account, and the same answer whether the account exists or not, on WordPress and WooCommerce.

**Unlock by e-mail**

* A locked-out user asks for a link on the login page. The e-mail goes only to an account that exists and is locked; the page always gives the same neutral answer.
* The link works once, for 15 minutes, and only in the browser that asked for it. Tokens are kept only as a keyed hash. A password reset cancels it.

**XML-RPC**

* Default: no method that needs a login answers, system.multicall (hundreds of guesses in one request) is gone, and pingbacks are off. Plugins that use XML-RPC without a login keep working.
* Or close xmlrpc.php completely (403), or leave it as WordPress has it.

**User enumeration**

* One neutral error for a wrong user name, a wrong e-mail and a wrong password, on the login forms and on Application Passwords.
* /wp-json/wp/v2/users answers 404 to visitors who are not logged in (the editor, Application Passwords and logged-in tools keep working).
* /?author=N answers 404 to visitors instead of redirecting to /author/<login>/. Author archives can be closed too (off by default, because blogs use them).
* No users sitemap, and no author name or link in oEmbed answers.

**E-mails**

* When an administrator logs in from a device not seen in 30 days, an e-mail goes to that administrator, with the time, the browser and the system (never the IP) and a link to the profile. The first login after activation only records the device.
* When many lockouts happen in one hour (10 by default), one e-mail a hour at most goes to the site address.
* E-mails are plain text in the recipient's language.

**Log and privacy**

* The log keeps 30 days and at most 5,000 rows: the event, the time, a truncated IP (203.0.113.x) and a keyed hash, never a full address or a password. The privacy text for your policy page is in the Tools tab and in Settings → Privacy.
* Counters and lockouts live in transients that expire by themselves.

**Also**

* The overview: lockouts and failures of the last 24 hours, active lockouts with «Unlock» and «Unlock all», and a warning when the server sees a private IP for every visitor (a proxy in front of the site that the plugin does not trust).
* The IP comes only from the server's REMOTE_ADDR, or from CF-Connecting-IP when the request really comes from Cloudflare (Noxpress Core). Other headers are ignored, because any visitor can send them.
* WP-CLI: `wp login-guard status`, `wp login-guard unlock --ip=… | --user=… | --all`, `wp login-guard mode off|test|live`.
* Ability login-guard/status (read-only, administrators) for AI tools and Site Bridge.
* JSON export and import of the settings; visitor texts in Greek and English in the settings.
* Greek and English admin.

== Installation ==

1. Upload the login-guard folder to /wp-content/plugins/ or install the zip from Plugins → Add New → Upload.
2. Activate it. It starts switched off.
3. Open Noxpress → Login Guard → Settings and choose «Test». Check the overview and the log for a few days, then choose «Live».

== Frequently Asked Questions ==

= I locked myself out. What now? =

Wait for the lockout to end (15 minutes by default), or use «Unlock by e-mail» on the login page. If you cannot wait: add `define( 'LGD_DISABLE', true );` to wp-config.php, which switches every check off until you remove it; or run `wp login-guard unlock --all`; or rename the plugin folder over FTP. The Tools tab lists these doors.

= Does it work with Magic Login? =

Yes. A Magic Login link never goes through the password check, so it is not counted or locked, and Magic Login keeps its own limits for link requests. Locked users can still log in with a link.

= Does it work behind Cloudflare or another proxy? =

Behind Cloudflare, yes: the visitor's IP is read from CF-Connecting-IP only when the request comes from a Cloudflare address. Behind another proxy, every visitor looks like the proxy; the overview warns you, and the filter `lgd_trusted_ip` lets a developer set the rule for that proxy.

= Will it lock out my office when several people share one IP? =

Only after 5 failures in 15 minutes. Add the office's address to the allow list and it is never locked.

= Is there two-factor authentication? =

Not in 1.0. It is planned for 1.1 (authenticator app and recovery codes for administrators).

= What happens on uninstall? =

The log table and the options are removed, unless you ticked «Keep settings and log». Counters, lockouts, unlock tokens and the known devices are always removed. The shared Noxpress options stay while another Noxpress plugin is installed.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.0.0 =
* Initial release.
* Failed-login limits per IP (with a longer lockout for repeat offenders) and per account, on wp-login.php, WooCommerce, XML-RPC and Application Passwords; allow list; off after activation, then test mode.
* Unlock by e-mail, bound to the browser, once, for 15 minutes; limits and neutral answers on lost password (WordPress and WooCommerce).
* XML-RPC: no logins and no multicall by default, or closed, or open; pingbacks off.
* User enumeration: neutral login errors, /wp/v2/users, ?author=N, author archives, users sitemap, oEmbed author.
* E-mails for a new administrator device and for an attack surge.
* Log with truncated IPs and keyed hashes for 30 days; privacy text.
* WP-CLI commands, ability login-guard/status, LGD_DISABLE, JSON export and import; Greek and English admin.
* Noxpress hub and updates from noxpress.tech (Noxpress Core 1.4.1, with the shared Noxpress_Safe::client_ip()).
