#!/usr/bin/env python3
"""Checks the personal-data rule (Bible §12 «Προσωπικά δεδομένα»).

Every plugin that stores personal data of customers, visitors or users
registers what REQUIRED lists for it:
  export  a wp_privacy_personal_data_exporters callback
  erase   a wp_privacy_personal_data_erasers callback
  policy  wp_add_privacy_policy_content() text
(the declared retention is checked by review, not here).

A plugin listed in PENDING is still being completed: what it lacks is a
warning, not an error. Any other plugin that looks like it stores personal
data (own table with an email / IP / name column, setcookie(), user meta
or order meta with email / name / ip in the key) and is not in REQUIRED
or NONE gets a warning, so a new plugin is classified by a person.

Usage: check-privacy.py
Prints one line per problem and exits 1 on an error (warnings do not fail).
Standard library only.
"""

import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PLUGINS = os.path.join(ROOT, 'plugins')

ALL = ('export', 'erase', 'policy')

# slug: what it must have (Bible §12 table).
REQUIRED = {
    'activity-log': ALL,
    'back-in-stock': ALL,
    'checkout-extras': ALL,
    'easy-withdrawal': ALL,
    'ebook-delivery': ALL,
    'events': ALL,
    'forms': ALL,
    'gift-cards': ALL,
    'invoice-details': ALL,
    'login-guard': ALL,
    'magic-login': ALL,
    'mail-relay': ALL,
    'oros-connect': ALL,
    'publishing-quote': ALL,
    'revenue-splitter': ALL,
    'trade-accounts': ALL,
    'wish-shelf': ALL,
    # Only administrators' or no stored data: the policy text is enough.
    'live-operator': ('policy',),
    'maintenance-mode': ('policy',),
    'site-bridge': ('policy',),
    'visitor-language': ('policy',),
}

# Being completed in open work (compliance audit 2026-11): warnings only.
PENDING = {
    'checkout-extras', 'live-operator', 'maintenance-mode', 'oros-connect',
    'revenue-splitter', 'site-bridge', 'visitor-language',
}

# Checked: no personal data of customers, visitors or users is stored.
NONE = {
    'book-feed', 'book-preview', 'broadcast', 'data-migrator', 'discount-rules',
    'fast-pages', 'featured-creators', 'greeklish-permalinks', 'hide-catalog',
    'home-sections', 'lean-images', 'live-search', 'pre-orders',
    'product-bundles', 'product-feed', 'product-formats', 'product-tabs',
    'production-ledger', 'redirects', 'search-signals', 'shipping-notes',
    'shop-filters', 'smart-formatter', 'store-pickup', 'store-pulse',
    'theme-patcher', 'trade-feed-import', 'variation-picker',
}

MARKERS = {
    'export': re.compile(r"wp_privacy_personal_data_exporters"),
    'erase': re.compile(r"wp_privacy_personal_data_erasers"),
    'policy': re.compile(r"wp_add_privacy_policy_content\s*\("),
}

LOOKS_PERSONAL = [
    re.compile(r"CREATE TABLE[^;]*\b\w*(email|_ip|\bip|name)\b\s+varchar", re.S | re.I),
    re.compile(r"\bsetcookie\s*\("),
    re.compile(r"update_(user|post)_meta\s*\([^;]*['\"][^'\"]*(email|_ip|phone)[^'\"]*['\"]", re.I),
]


def sources(slug):
    out = []
    base = os.path.join(PLUGINS, slug)
    for dirpath, dirnames, files in os.walk(base):
        dirnames[:] = [d for d in dirnames if d not in ('noxpress-core', 'vendor', 'node_modules')]
        for f in files:
            if f.endswith('.php') and f != 'uninstall.php':
                with open(os.path.join(dirpath, f), encoding='utf-8') as fh:
                    out.append(fh.read())
    return '\n'.join(out)


def main():
    errors = warnings = 0
    slugs = sorted(d for d in os.listdir(PLUGINS) if os.path.isfile(os.path.join(PLUGINS, d, d + '.php')))
    for slug in slugs:
        src = sources(slug)
        if slug in REQUIRED:
            missing = [k for k in REQUIRED[slug] if not MARKERS[k].search(src)]
            if missing:
                if slug in PENDING:
                    warnings += 1
                    print('warning: %s: pending, lacks %s' % (slug, ', '.join(missing)))
                else:
                    errors += 1
                    print('%s: stores personal data and lacks %s (Bible §12 «Προσωπικά δεδομένα»)' % (slug, ', '.join(missing)))
            elif slug in PENDING:
                warnings += 1
                print('warning: %s: complete, remove it from PENDING' % slug)
        elif slug not in NONE:
            if any(p.search(src) for p in LOOKS_PERSONAL):
                warnings += 1
                print('warning: %s: may store personal data; add it to REQUIRED or NONE in check-privacy.py' % slug)
            else:
                warnings += 1
                print('warning: %s: not classified; add it to REQUIRED or NONE in check-privacy.py' % slug)
    for slug in set(REQUIRED) | NONE:
        if slug not in slugs:
            warnings += 1
            print('warning: %s: listed but no such plugin' % slug)
    print('privacy: %s (%d warnings)' % ('FAIL' if errors else 'ok', warnings))
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
