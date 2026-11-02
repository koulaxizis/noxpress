#!/usr/bin/env python3
"""Our own F-Droid repository of the Noxpress Android apps (Bible §13).

.github/workflows/fdroid-repo.yml publishes it on GitHub Pages:
  https://koulaxizis.github.io/noxpress-fdroid/repo
F-Droid and Droid-ify add the repository; Obtainium can use it as a source too. The
repository holds the exact APKs of our GitHub releases (no second build, the same key), and
its index is signed with that same SBA_* key (Christos, 2026-11-02: no new keys).

The parts that need no Android SDK live here, so they can be tested:

  fetch <dir> [keep]   download the newest `keep` (default 3) releases of each app in APPS,
                       drafts left out, pre-releases included (the apps ship as beta), into
                       <dir>/repo/<package>_<versionName>_<sha256 first 8>.apk, checking each
                       APK against the .sha256 asset of its release. A new name for every
                       build, so a CDN or a half-finished download never mixes two builds.
  meta <dir>           write <dir>/metadata/<package>.yml and the store texts and icon from
                       apps/<app>/fastlane/metadata/android/<locale>/
  stamp <dir>          print a hash of what a publish contains (APK names, metadata, the
                       workflow and this script); the workflow publishes only when it changed

GH_TOKEN (optional) raises the GitHub API rate limit. Standard library only.
"""

import hashlib
import json
import os
import re
import shutil
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
REPO = 'koulaxizis/noxpress'

# Release tag prefix -> the app. Released identities (package names) never change.
# The Live Operator app (nox-loa) is not here: its official build opens only one site.
APPS = {
    'nox-sba': {
        'package': 'tech.noxpress.sitebridge',
        'folder': 'site-bridge-android',
        'asset': 'site-bridge.apk',
    },
}

COMMON = """License: MIT
AuthorName: Christos Koulaxizis
WebSite: https://noxpress.tech
SourceCode: https://github.com/koulaxizis/noxpress
IssueTracker: https://github.com/koulaxizis/noxpress/issues
Donate: https://ko-fi.com/koulaxizis
Categories:
  - Internet
  - System
"""

TEXTS = ('title.txt', 'short_description.txt', 'full_description.txt')
SEMVER = re.compile(r'^([0-9]+)\.([0-9]+)\.([0-9]+)$')


def api_get(url):
    req = urllib.request.Request(url, headers={'Accept': 'application/vnd.github+json', 'User-Agent': 'noxpress-fdroid'})
    token = os.environ.get('GH_TOKEN')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read()


def list_releases(pages=10):
    """All releases of the repository, newest first (it also holds every plugin release)."""
    out = []
    for page in range(1, pages + 1):
        batch = json.loads(api_get('https://api.github.com/repos/%s/releases?per_page=100&page=%d' % (REPO, page)))
        out.extend(batch)
        if len(batch) < 100:
            break
    return out


def pick(releases, prefix, keep):
    """The newest `keep` releases tagged <prefix>-<x.y.z>, by version, drafts left out."""
    found = []
    for r in releases:
        tag = r.get('tag_name', '')
        if r.get('draft') or not tag.startswith(prefix + '-'):
            continue
        m = SEMVER.match(tag[len(prefix) + 1:])
        if m:
            found.append((tuple(int(x) for x in m.groups()), r))
    found.sort(key=lambda x: x[0], reverse=True)
    return [r for _, r in found[:keep]]


def repo_name(package, version, data):
    if not re.fullmatch(r'[A-Za-z0-9_.]+', package) or not SEMVER.match(version):
        raise SystemExit('bad package or version: %r %r' % (package, version))
    return '%s_%s_%s.apk' % (package, version, hashlib.sha256(data).hexdigest()[:8])


def fetch(out, keep=3, releases=None, download=None):
    """Writes the APKs into <out>/repo and returns their names."""
    releases = list_releases() if releases is None else releases
    download = download or api_get
    repo = os.path.join(out, 'repo')
    os.makedirs(repo, exist_ok=True)
    names = []
    for prefix, app in APPS.items():
        chosen = pick(releases, prefix, keep)
        if not chosen:
            raise SystemExit('%s: no release found' % prefix)
        for r in chosen:
            assets = {a['name']: a['browser_download_url'] for a in r.get('assets', [])}
            apk, sha = app['asset'], app['asset'] + '.sha256'
            if apk not in assets or sha not in assets:
                raise SystemExit('%s: missing %s or %s' % (r['tag_name'], apk, sha))
            data = download(assets[apk])
            want = download(assets[sha]).decode().split()[0].lower()
            if hashlib.sha256(data).hexdigest() != want:
                raise SystemExit('%s: %s does not match its .sha256' % (r['tag_name'], apk))
            name = repo_name(app['package'], r['tag_name'][len(prefix) + 1:], data)
            with open(os.path.join(repo, name), 'wb') as f:
                f.write(data)
            names.append(name)
    return sorted(names)


def fastlane_dir(app):
    return os.path.join(ROOT, 'apps', app['folder'], 'fastlane', 'metadata', 'android')


def write_meta(out):
    """metadata/<package>.yml plus metadata/<package>/<locale>/ (title, short and full
    description, icon), the layout `fdroid update` reads for a repository of ready APKs."""
    meta = os.path.join(out, 'metadata')
    os.makedirs(meta, exist_ok=True)
    for prefix, app in APPS.items():
        src = fastlane_dir(app)
        locales = sorted(d for d in os.listdir(src) if os.path.isdir(os.path.join(src, d)))
        if 'en-US' not in locales:
            raise SystemExit('%s: no en-US store texts in %s' % (prefix, src))
        icon = os.path.join(src, 'en-US', 'images', 'icon.png')
        if not os.path.isfile(icon):
            raise SystemExit('%s: missing en-US/images/icon.png' % prefix)
        for loc in locales:
            dst = os.path.join(meta, app['package'], loc)
            os.makedirs(os.path.join(dst, 'images'), exist_ok=True)
            for f in TEXTS:
                p = os.path.join(src, loc, f)
                if not os.path.isfile(p):
                    raise SystemExit('%s/%s: missing %s' % (prefix, loc, f))
                with open(p, encoding='utf-8') as i:
                    text = i.read().strip()
                if f == 'short_description.txt' and len(text) > 80:
                    raise SystemExit('%s/%s: short description has %d characters (F-Droid allows 80)' % (prefix, loc, len(text)))
                if f == 'title.txt' and len(text) > 50:
                    raise SystemExit('%s/%s: title has %d characters (F-Droid allows 50)' % (prefix, loc, len(text)))
                with open(os.path.join(dst, f), 'w', encoding='utf-8') as o:
                    o.write(text + '\n')
            shutil.copyfile(icon, os.path.join(dst, 'images', 'icon.png'))
        with open(os.path.join(meta, app['package'] + '.yml'), 'w', encoding='utf-8') as o:
            o.write(COMMON)
    return sorted(a['package'] for a in APPS.values())


def stamp(out):
    """A hash of the APK names (they carry the APK hash), the metadata, the repository icon,
    the workflow and this script."""
    h = hashlib.sha256()
    files = [os.path.join(ROOT, '.github', 'workflows', 'fdroid-repo.yml'),
             os.path.join(ROOT, '.github', 'fdroid', 'icon.png'),
             os.path.abspath(__file__)]
    for base, _, fs in sorted(os.walk(os.path.join(out, 'metadata'))):
        files.extend(os.path.join(base, f) for f in sorted(fs))
    for name in sorted(os.listdir(os.path.join(out, 'repo'))):
        if name.endswith('.apk'):
            h.update(name.encode() + b'\n')
    for p in files:
        h.update(os.path.relpath(p, out if p.startswith(out) else ROOT).encode() + b'\n')
        with open(p, 'rb') as f:
            h.update(hashlib.sha256(f.read()).digest())
    return h.hexdigest()


def main(argv):
    if len(argv) in (2, 3) and argv[0] == 'fetch':
        print('\n'.join(fetch(argv[1], int(argv[2]) if len(argv) == 3 else 3)))
    elif len(argv) == 2 and argv[0] == 'meta':
        print('\n'.join(write_meta(argv[1])))
    elif len(argv) == 2 and argv[0] == 'stamp':
        print(stamp(argv[1]))
    else:
        print(__doc__)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
