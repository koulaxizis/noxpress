=== Mail Relay ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: smtp, email, mail log, proton mail, deliverability
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Every email of your site through the account you choose: SMTP (with a ready setup for Proton Mail) or Brevo, Mailgun, SendGrid, Postmark and Amazon SES, with a backup connection and a sent log that shows which plugin sent each mail.

== Description ==

WordPress sends mail with the server's mail() function, which many mailboxes treat as spam or never receive. Mail Relay sends every mail of the site (WordPress, WooCommerce, every plugin) through a real mail account, and keeps a log so you can see what went out, what failed and why.

Nothing changes after activation: sending through Mail Relay stays off until you set up a connection, send a test that arrives, and turn it on.

**Connections**

* SMTP, with ready setups for Proton Mail (smtp.protonmail.ch, STARTTLS, with the SMTP token Proton gives you per address), Gmail / Google Workspace (app password), Microsoft 365 and your hosting's mail server, or any other server.
* HTTP APIs: Brevo, Mailgun (US or EU), SendGrid, Postmark, Amazon SES (v2, Signature Version 4). Each endpoint is fixed in the code.
* A backup connection: when the primary fails, the same mail is tried at once through the backup, and the caller of wp_mail() gets the final result.
* WordPress's own wp_mail() and PHPMailer stay in charge: the plugin does not replace wp_mail(), so headers, attachments, HTML and text parts are exactly what WordPress builds.
* While FluentSMTP, WP Mail SMTP, Post SMTP or another mail plugin is active, sending through Mail Relay cannot be turned on, so mail never goes out twice or from the wrong account.
* The From address can be forced on every mail (Proton Mail and the APIs refuse other senders); the original From of a plugin becomes Reply-To.

**Passwords and keys**

* A constant in wp-config.php wins: MRL_PRIMARY_SECRET, MRL_BACKUP_SECRET.
* Otherwise the secret is sealed with AES-256-GCM under a key made from your wp-config.php salts. Without real salts or OpenSSL it is not stored at all and the page asks for the constant.
* Never shown again (an empty field keeps the current one), never in the settings backup, the log, an error message or PHPMailer's debug output.
* Deleted by itself when the server, the user or the provider changes, so a password typed for one server never goes to another.
* A password is only sent over SSL or STARTTLS, with the certificate checked. The SMTP server must be a public address; a local relay needs MRL_ALLOW_LOCAL_SMTP and no password.

**Sent log**

* Date, status (sent, failed, held), connection, recipients, subject, source (plugin, theme, WordPress or the WooCommerce email, e.g. woocommerce:customer_processing_order), attachment names and sizes, error.
* The content of mails is not kept, unless you turn it on. Then it is kept sealed for 7 days, for viewing and resending, and never for sensitive mails: password reset, new user, email change and privacy confirmations, recovery mode, any mail with a key=, token=, nonce= or login link, and whatever the noxpress_mail_sensitive filter marks. Attachments are never kept.
* Rows are deleted after 30 days (1–90). Tools → Export / Erase Personal Data find and delete the rows of an address.
* Only administrators (manage_options) see the log and the settings.
* CSV export of the log (without content).

**When mail fails**

* A banner in the admin while there are failures of the last 24 hours you have not seen.
* At most one summary email an hour (counts and the last error only), through the primary, the backup and, last, the server's mail().
* Site Bridge reads the state and the log through two read-only abilities, with masked recipients and never the content.

**Staging guard**

When the address of the site changes (a staging copy, a move with Data Migrator), sending pauses and mails are logged as «held», so a test site never mails real customers. One click confirms the new address.

**Tools**

* Test email through the primary or the backup.
* SPF, DKIM and DMARC check of the From domain (read only).
* Import of the non-secret settings from FluentSMTP (server, port, encryption, user, sender). Passwords and keys are typed again.
* Settings backup to JSON, without secrets.

**Developers**

* noxpress_mail_sensitive( bool $sensitive, array $atts ): mark a mail as sensitive (no content kept, no resend).
* mrl_mail_source( string $source, string $file ): the source written in the log.
* Abilities (WordPress 6.9+): mail-relay/status, mail-relay/log.
* MRL_DISABLE in wp-config.php: WordPress sends as without the plugin.

What is not in 1.0.0: OAuth sign-in for Gmail and Microsoft 365 (their SMTP with an app password works), a send queue, DKIM signing by the site itself.

== Installation ==

1. Install and activate. Nothing changes yet.
2. Noxpress → Mail Relay → Connection: the sender and the primary connection (for Proton Mail: the preset, your address as user and the SMTP token as password).
3. Tools → Test email. When it arrives, deactivate your old mail plugin, then Connection → Turn on.

== Changelog ==

= 1.0.0 =
* First release (beta): SMTP with presets (Proton Mail, Gmail, Microsoft 365, hosting mail), Brevo, Mailgun, SendGrid, Postmark and Amazon SES, backup connection, sent log with source, sealed content for 7 days (off by default, never for sensitive mails), resend, CSV, privacy exporter and eraser, failure banner and hourly summary, staging guard, SPF / DKIM / DMARC check, import from FluentSMTP, settings backup without secrets, Site Bridge abilities.
