#!/usr/bin/env python3
"""Checks that every plugin card on noxpress.tech matches the plugin (Bible §13).

For each plugins/<slug>/ with a card (<article class="card" id="<slug>">):
  - the version in the card meta, the download link (nox-<code>-<version>/<slug>.zip)
    and both {code}_download strings (EN / EL) equal the plugin header's Version:
  - the card has its "▶ Video" pill and media/videos/<slug>.mp4 + .webp exist (a warning
    only: a new plugin may get its video in a later PR).
A plugin without a card must be listed in --personal (Bible §21).

For each Android app with a card (#<slug>-app, Bible §13) the card meta equals the
app's versionName, and a download link (nox-<tag>-<version>/<asset>) and both
{tag}_download strings, when the card has them, name that version too.

Usage: check-site-cards.py [--personal "<slug> ..."]
Prints one line per problem and exits 1 if there is any (warnings do not fail). Standard library only.
"""

import argparse
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def header_version(slug):
    path = os.path.join(ROOT, 'plugins', slug, slug + '.php')
    with open(path, encoding='utf-8') as f:
        m = re.search(r'^\s*\*\s*Version:\s*(\S+)', f.read(), re.M)
    return m.group(1) if m else None


APPS = {
    # card id: (app directory, tag code)
    'site-bridge-app': ('site-bridge-android', 'sba'),
    'live-operator-app': ('live-operator-android', 'loa'),
}


def app_version(folder):
    path = os.path.join(ROOT, 'apps', folder, 'app', 'build.gradle.kts')
    with open(path, encoding='utf-8') as f:
        m = re.search(r'^\s*versionName\s*=\s*"([^"]+)"', f.read(), re.M)
    return m.group(1) if m else None


def check_apps(html, problems):
    for card_id, (folder, code) in sorted(APPS.items()):
        start = html.find('<article class="card" id="%s">' % card_id)
        if start < 0:
            continue
        card = html[start:html.index('</article>', start)]
        ver = app_version(folder)
        meta = re.search(r'<div class="meta">v([0-9.]+)', card)
        if not meta or meta.group(1) != ver:
            problems.append('%s: card meta says %s, app is %s' % (card_id, meta.group(1) if meta else '?', ver))
        link = re.search(r'/releases/download/nox-%s-([0-9.]+)/' % code, card)
        if link:
            if link.group(1) != ver:
                problems.append('%s: download link is for %s, app is %s' % (card_id, link.group(1), ver))
            for word in ('Download', 'Λήψη'):
                if "%s_download: '%s v%s (.apk)'" % (code, word, ver) not in html:
                    problems.append('%s: %s_download (%s) is not v%s' % (card_id, code, word, ver))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--personal', default='', help='space-separated slugs without a card')
    args = ap.parse_args()
    personal = set(args.personal.split())
    with open(os.path.join(ROOT, 'index.html'), encoding='utf-8') as f:
        html = f.read()
    problems = []
    warnings = []
    for slug in sorted(os.listdir(os.path.join(ROOT, 'plugins'))):
        if not os.path.isfile(os.path.join(ROOT, 'plugins', slug, slug + '.php')):
            continue
        start = html.find('<article class="card" id="%s">' % slug)
        if start < 0:
            if slug not in personal:
                problems.append('%s: no card on the site' % slug)
            continue
        card = html[start:html.index('</article>', start)]
        ver = header_version(slug)
        meta = re.search(r'<div class="meta">v([0-9.]+)', card)
        link = re.search(r'/releases/download/nox-([a-z]{2})-([0-9.]+)/%s\.zip' % re.escape(slug), card)
        if not meta or meta.group(1) != ver:
            problems.append('%s: card meta says %s, plugin is %s' % (slug, meta.group(1) if meta else '?', ver))
        if not link or link.group(2) != ver:
            problems.append('%s: download link is for %s, plugin is %s' % (slug, link.group(2) if link else '?', ver))
        if link:
            code = link.group(1)
            for word in ('Download', 'Λήψη'):
                if "%s_download: '%s v%s (.zip)'" % (code, word, ver) not in html:
                    problems.append('%s: %s_download (%s) is not v%s' % (slug, code, word, ver))
        if 'data-video="%s"' % slug not in card:
            warnings.append('%s: card has no video pill' % slug)
        for ext in ('mp4', 'webp'):
            if not os.path.isfile(os.path.join(ROOT, 'media', 'videos', '%s.%s' % (slug, ext))):
                warnings.append('%s: media/videos/%s.%s is missing' % (slug, slug, ext))
    check_apps(html, problems)
    for w in warnings:
        print('warning: ' + w)
    for p in problems:
        print(p)
    if not problems:
        print('site cards: ok')
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
