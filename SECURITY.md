# Security policy

Noxpress is a suite of free WordPress / WooCommerce plugins, two Android apps
(Site Bridge, Live Operator) and the site noxpress.tech, all in this
repository. Many of them handle shop data: orders, customers, payments,
author revenue and remote admin access. Thank you for helping keep them safe.

## Reporting a vulnerability

Please report privately, through GitHub:

**[Report a vulnerability](https://github.com/koulaxizis/noxpress/security/advisories/new)**
(Security tab → "Report a vulnerability").

- Do not open a public issue, pull request or discussion for a security
  problem, and do not post details anywhere public until a fix is released.
- There is no security e-mail address; private reports go through GitHub
  only.
- Include the plugin or app and its version, what an attacker needs (a
  visitor, a customer, a shop manager, a trade account, a network
  position), the steps, and the impact. A minimal proof on your own test
  site is welcome.
- You may write in English or Greek.

## What to expect

- A first reply within 7 days.
- An assessment and a plan within 14 days. Problems that expose customer or
  shop data are fixed first.
- The fix ships as a new release of the affected plugin or app, through the
  Noxpress Hub and the GitHub releases. Whether a public advisory follows,
  and whether you are credited (by name or handle, if you want), is agreed
  with you after the release.

## How users are told about a security fix

Shops that run Noxpress may have their own duties when a vulnerability
touches personal data, so they are told as soon as a fix exists:

- **The day the fix is released:** a new version of the affected plugin
  or app, whose changelog starts with a «Security» line saying what was
  affected, who could exploit it (a visitor, a customer, a shop manager)
  and which versions. The Noxpress Hub shows the update on every site;
  sites with automatic updates for the suite get it on their own.
- **The same day:** a note in the release notes on noxpress.tech and in the
  Updates of koulaxizis.gr, saying which plugin, which versions and
  whether personal data could have been read or changed. Details that
  would help an attacker wait until most sites have updated.
- **Within 14 days of the fix:** a GitHub security advisory with the full
  details and, where one applies, a CVE.

Noxpress collects no telemetry and has no list of the sites that run it,
so these public channels are the only way to reach shops. There is no
mailing list.

## If you run a shop: personal data breach in 72 hours

If a Noxpress vulnerability may have exposed your customers' data, the
duties under the GDPR are yours as the controller of that data (Noxpress
never receives it). In short:

1. **Contain (hour 0).** Update the affected plugin, or deactivate it
   (or use its emergency switch in `wp-config.php`, for example
   `ORC_DISABLE`). Change passwords and keys the advisory names (Site
   Bridge device keys, WooCommerce REST keys, application passwords).
2. **Find out (first 24 hours).** Check the advisory's «who could exploit
   it», your server logs and, if you run it, Activity Log and Site Bridge's
   audit log, for signs that the hole was used. Write down what you find
   and when you learned of it: the 72 hours run from then.
3. **Notify the authority (within 72 hours).** If the breach is likely to
   put people at risk, notify your data protection authority (in Greece
   the Hellenic Data Protection Authority, dpa.gr) within 72 hours of
   becoming aware, even with incomplete details, and complete them later
   (GDPR art. 33).
4. **Tell the people affected (without undue delay).** If the risk to them
   is high, tell the affected customers in plain words what happened,
   what data, what you did and what they can do (GDPR art. 34).
5. **Keep a record.** Note every breach and what you did, also the ones you
   did not have to notify (GDPR art. 33(5)).

This is a short guide, not legal advice. Ask your own lawyer or data
protection officer when in doubt.

## Scope

In scope: everything in this repository, i.e. the plugins under `plugins/`
(including the shared Noxpress Core: hub and updater), the apps under
`apps/`, the update manifest `updates.json`, the GitHub workflows and the
site noxpress.tech.

Out of scope: third-party plugins, themes and WordPress / WooCommerce core
themselves (report those to their authors), sites that merely run Noxpress
plugins, social engineering, denial of service and automated scanner output
without a demonstrated impact.

## Rules for testing

Test only on sites and devices you own or have permission to test. Never
test against live sites you do not own, including the sites of shops that
use Noxpress, and never access, change or keep other people's data.

## Supported versions

Only the newest release of each plugin and app receives security fixes.
All releases are currently beta. Update through the Noxpress Hub
(WordPress admin → Noxpress) or from the
[releases](https://github.com/koulaxizis/noxpress/releases).
