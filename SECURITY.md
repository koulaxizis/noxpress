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
