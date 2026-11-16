=== Forms ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: contact form, forms, anti-spam, contact form 7, gdpr
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Contact forms with a block and a shortcode, e-mail notifications, and spam protection without third-party services. A message is never lost when the e-mail fails. Imports Contact Form 7 forms and keeps their shortcode working.

== Description ==

**Forms in a few clicks**

* Start from a template (Contact, E-mail sign-up, Product question, Empty) and add, remove or move fields: text, e-mail, phone, address (URL), number, date, long text, list, one choice, many choices, consent, hidden value and a text note.
* Greek and English labels, placeholders, help texts and options; an empty English text shows the Greek one. The visitor's language comes from Polylang, WPML or the site language.
* Full or half width per field. Inputs and buttons keep the theme's look (the button gets the theme's button classes).
* Put a form on a page with the «Form» block or `[nx_form id="…"]`.

**Every message arrives, none is lost**

* Each message goes by e-mail to the addresses you choose (the site e-mail when empty), with «Reply» going to the visitor. The subject takes `{site}`, `{form}`, `{name}` and any field as `{key}`.
* The message is stored in WordPress only when the e-mail fails, so nothing is lost when the server cannot send. A form can also store every message, or none.
* Stored messages: list, search, filters, «Send the e-mail now», mark as read or spam, CSV export (with the filters on screen). They are deleted after 6 months, spam after 7 days (both can be changed).
* An optional short auto-reply with fixed text and the visitor's name only, at most once an hour per address.

**Spam protection without trackers**

* No reCAPTCHA, no cookies, no third-party requests. Layers that always run: a hidden field for bots, a signed time token (too fast or too old is refused), one use per token, a limit per visitor and per form (the IP is kept only as a short-lived HMAC), and a double-send guard.
* An invisible proof of work: the browser solves a small SHA-256 puzzle in a background worker while the visitor types (about a second on a phone). Bots that do not run JavaScript never get a token.
* Content rules per form: maximum links, WordPress's «Disallowed comment keys», your own words, only Greek or Latin letters, and a DNS check that the e-mail domain accepts mail.
* Bots get a silent «Thank you» and nothing is sent. A message stopped by a content rule shows the reason to the visitor and goes to the Spam folder.
* A daily counter of blocked attempts by reason (no content, no IP), on the settings page and in the dashboard widget.

**Works with page cache and without JavaScript**

* The form's markup is the same for every visitor, so cached pages work (Fast Pages and other page caches). The token comes from a small REST call on the first touch of the form.
* Without JavaScript the form still works: the visitor sees a «Confirm sending» step and presses «Send» once more.

**Product questions and orOS Newsletter**

* With WooCommerce, a hidden field «The product of the page» adds the product's name, SKU and link to the message: put the form on product pages for «Ask about this product».
* A form can sign up visitors to orOS Newsletter through orOS Connect (module Newsletter) when they tick the consent box. orOS always sends its confirmation e-mail (double opt-in), because a public form cannot prove who owns the address. Sign-ups wait in the background and retry when orOS does not answer.

**Switching from Contact Form 7**

* Tools → Import from Contact Form 7 reads its forms (fields, labels, required marks, choices, acceptance boxes, mail recipient and subject, success message) and never changes or deletes anything of Contact Form 7.
* The imported form starts inactive. A report lists what was not imported (file uploads, quizzes, CAPTCHA, the second mail).
* Once Contact Form 7 is deactivated, its `[contact-form-7 id="…"]` shortcodes in pages, widgets and theme settings show the imported form.

**Safe by default**

* Test mode after activation: only managers see the forms until you switch to live. `FRM_DISABLE` in wp-config.php stops the forms, the REST routes and the Contact Form 7 shortcode at once.
* Header injection is impossible: values in the subject and the Reply-To lose every line break, and the From address is never taken from the visitor.
* Privacy tools: messages are included in WordPress's personal data export and erasure, and a suggested text goes to the privacy policy guide.
* Read-only abilities for Site Bridge (`forms/list`, `forms/entries-summary`, `forms/entries` with personal data masked), bilingual admin (Greek / English) and the shared «Noxpress» menu.

== Installation ==

1. Upload the `forms` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New, or from the Noxpress hub). WooCommerce is optional.
2. Activate the plugin.
3. Open **Noxpress → Forms**, make a form from a template, and put it on a page with the «Form» block or its shortcode.
4. Send a test message while the mode is «Test» (you see the form, visitors do not), then switch to **Live** in Settings.
5. Coming from Contact Form 7: **Tools → Import from Contact Form 7**, check and activate each imported form, then deactivate Contact Form 7.

== Frequently Asked Questions ==

= Why does the visitor wait a second before sending? =

The form needs its token and, when the proof of work is on, the answer to the small puzzle. Both start when the visitor first clicks into the form, so by the time the message is written they are ready. The minimum time (3 seconds by default) stops bots that send at once.

= Messages do not arrive by e-mail. =

Many hosts do not send PHP mail reliably. The messages are kept under **Messages** with the badge «Not sent»; set up SMTP (or the suite's Mail Relay) and press «Send the e-mail now». The dashboard widget shows how many failed.

= Can I change the From address? =

Forms uses the site's sender (WordPress, your SMTP plugin or Mail Relay) and puts the visitor in Reply-To. The `frm_mail_args` filter changes the e-mail before it is sent.

= What happens on uninstall? =

The settings, the forms, the counters and the background jobs are removed. Stored messages are the owner's record, so they stay unless you tick «Delete the messages on uninstall» in Settings first. Contact Form 7 and its forms are never touched. The shared Noxpress options stay while another Noxpress plugin is installed.

== Developers ==

* Actions `frm_submission( $form_id, $data, $entry_id )` after every accepted message and `frm_entry_created( $entry_id, $form_id, $data )` when one is stored; `frm_oros_sent( $result )` after an orOS sign-up reached its end.
* Filters `frm_spam_check( $reason, $form, $values )` (return a reason, or `custom:Message` to show your own text), `frm_mail_args( $args, $form, $data )`, `frm_text( $text, $key, $lang )`.
* Functions `frm_get_form( $id )`, `frm_get_entry( $id )`, `frm_get_entries( $args )`.
* REST (public, for the forms on the site): `GET frm/v1/token?form=ID`, `POST frm/v1/submit`.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==
= 1.0.1 =
* Noxpress Core 1.4.11: every text cell of a CSV export is enclosed in quotes, so a spreadsheet that splits on another list separator (such as ';' in Greek Excel) keeps each cell whole.

= 1.0.0 =
* Initial release.
* Form builder with 13 field types, Greek and English texts, templates (Contact, E-mail sign-up, Product question, Empty), block and [nx_form] shortcode.
* E-mail notification with Reply-To and subject placeholders; messages stored only when the e-mail fails (or always / never per form); optional auto-reply with fixed text.
* Spam protection without third parties: honeypot, signed time token, invisible proof of work in a Web Worker, content rules, per-visitor limits, double-send guard, daily counter.
* Works with page cache and without JavaScript («Confirm sending» step).
* Messages admin with search, filters, resend, states and CSV; retention 6 months, spam 7 days; privacy export and erasure.
* Product question field (WooCommerce) and orOS Newsletter sign-ups through orOS Connect.
* Read-only import from Contact Form 7 with a report, and its shortcode kept working after the switch.
* Dashboard widget, abilities forms/list, forms/entries-summary, forms/entries; test mode, FRM_DISABLE, settings and forms backup.
