#!/usr/bin/env python3
"""Builds updates.json for Noxpress Core (Bible §16).

For every plugin code given on the command line it picks, from the GitHub
releases of this repository:
  - "stable": the newest release that is not a pre-release,
  - "beta":   the newest release of any kind,
downloads the plugin zip, and records its sha256, the requirements from
the plugin header and readme, and the changelog of that version.

Signatures (Ed25519):
  - "sig2" (Core 1.3.0+): noxpress2|<slug>|<channel>|<version>|<sha256>|<serial>|<expires>
    where serial is the build time and expires = serial + 14 days, both at
    the top of the file. The release workflow runs every week, so a list
    is always valid for at least 7 more days.
  - "signature" (older Core): noxpress|<slug>|<version>|<sha256>.
  - "sig3" (Core 1.4.0+): noxpress3|<signed>, where "signed" is the whole
    list (plugins, serial, expires) as compact JSON. Core 1.4.0 reads only
    the "signed" copy, so every release and field is covered.

Environment:
  GITHUB_REPOSITORY      owner/repo
  NOXPRESS_SIGNING_KEY   path to the Ed25519 private key (PEM)
  GH_TOKEN               token for the gh CLI

A zip is downloaded only when the current manifest has no entry for that
version whose sha256 matches the asset's GitHub digest. Every download adds
to the public download count shown on the site (Bible §17), so unchanged
zips are never fetched again.

Usage: build-manifest.py <output.json> <code>...
Only the Python standard library, gh and openssl are used.
"""

import base64
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import time
import zipfile

SLUGS = {
    'rs': 'revenue-splitter',
    'sp': 'store-pulse',
    'sf': 'smart-formatter',
    'tp': 'theme-patcher',
    'sh': 'shop-filters',
    'pf': 'product-formats',
    'ew': 'easy-withdrawal',
    'bp': 'book-preview',
    'sn': 'shipping-notes',
    'cr': 'featured-creators',
    'pk': 'store-pickup',
    'hc': 'hide-catalog',
    'vp': 'variation-picker',
    'gl': 'greeklish-permalinks',
    'oc': 'oros-connect',
    'pb': 'product-bundles',
    'pq': 'publishing-quote',
    'po': 'pre-orders',
    'ws': 'wish-shelf',
    'ce': 'checkout-extras',
    'nm': 'data-migrator',
    'hs': 'home-sections',
    'bf': 'book-feed',
    'lo': 'live-operator',
    'ta': 'trade-accounts',
    'fp': 'fast-pages',
    'br': 'broadcast',
    'ml': 'magic-login',
    'ti': 'trade-feed-import',
    'li': 'lean-images',
    'rd': 'redirects',
    'ss': 'search-signals',
    'pl': 'production-ledger',
    'pt': 'product-tabs',
    'fm': 'forms',
    'fd': 'product-feed',
    'iv': 'invoice-details',
    'lg': 'login-guard',
    'lv': 'live-search',
    'al': 'activity-log',
    'mr': 'mail-relay',
    'bs': 'back-in-stock',
    'ev': 'events',
    'sb': 'site-bridge',
}
UPDATER = 'plugins/revenue-splitter/includes/noxpress-core/class-noxpress-updater.php'
SEMVER = re.compile(r'^\d+\.\d+\.\d+$')
VALID_DAYS = 14


def run(*args, data=None):
    return subprocess.run(args, check=True, capture_output=True, input=data).stdout


def vkey(version):
    return tuple(int(p) for p in version.split('.'))


def releases(repo):
    out = run('gh', 'api', f'repos/{repo}/releases', '--paginate', '--jq', '.[]')
    dec = json.JSONDecoder()
    text = out.decode()
    items, pos = [], 0
    while pos < len(text):
        while pos < len(text) and text[pos].isspace():
            pos += 1
        if pos >= len(text):
            break
        obj, pos = dec.raw_decode(text, pos)
        items.append(obj)
    return items


def public_key_of(key_file):
    der = run('openssl', 'pkey', '-in', key_file, '-pubout', '-outform', 'DER')
    return base64.b64encode(der[-32:]).decode()


def sign(key_file, message):
    with tempfile.NamedTemporaryFile(delete=False) as msg:
        msg.write(message.encode())
    try:
        sig = run('openssl', 'pkeyutl', '-sign', '-inkey', key_file, '-rawin', '-in', msg.name)
    finally:
        os.unlink(msg.name)
    if len(sig) != 64:
        raise SystemExit('Unexpected Ed25519 signature length')
    return base64.b64encode(sig).decode()


def header(text, name):
    m = re.search(r'^[ \t/*#@]*' + re.escape(name) + r':\s*(.+?)\s*$', text, re.M | re.I)
    return m.group(1) if m else ''


def zip_info(path, slug, version):
    """Requirements and changelog from the zip, or None when the zip is not
    a valid package of this version (it is then left out of the manifest)."""
    try:
        with zipfile.ZipFile(path) as z:
            main = z.read(f'{slug}/{slug}.php').decode('utf-8', 'replace')
            try:
                readme = z.read(f'{slug}/readme.txt').decode('utf-8', 'replace')
            except KeyError:
                readme = ''
    except (zipfile.BadZipFile, KeyError):
        print(f'::warning::{slug} {version}: not a valid plugin zip, skipped')
        return None
    if header(main, 'Version') != version:
        print(f'::warning::{slug} {version}: header version differs from the tag, skipped')
        return None
    changelog = []
    inside = False
    for line in readme.splitlines():
        if line.strip() == f'= {version} =':
            inside = True
            continue
        if inside and (line.startswith('= ') or line.startswith('== ')):
            break
        if inside and line.strip():
            changelog.append(line.strip())
    return {
        'requires_wp': header(main, 'Requires at least'),
        'requires_php': header(main, 'Requires PHP'),
        'tested_wp': header(readme, 'Tested up to'),
        'changelog': '\n'.join(changelog),
    }


CORE = 'plugins/revenue-splitter/includes/noxpress-core/class-noxpress-core.php'


def php_str(s):
    """Body of a single-quoted PHP string."""
    return s.replace("\\'", "'").replace('\\\\', '\\')


def display_info(slug):
    """Name, Greek and English description and admin page of a plugin, for
    Cores whose catalog does not list it yet (Core 1.4.0). From the Core
    catalog of this checkout, else from the plugin header and readme."""
    info = {}
    try:
        with open(CORE, encoding='utf-8') as fh:
            core = fh.read()
    except OSError:
        core = ''
    q = r"'((?:[^'\\]|\\.)*)'"
    m = re.search(r"'" + re.escape(slug) + r"'\s*=>\s*array\(\s*'name'\s*=>\s*" + q + r",\s*'desc'\s*=>\s*__\(\s*" + q + r",\s*'noxpress'\s*\),\s*'page'\s*=>\s*" + q, core)
    if m:
        info['name'] = php_str(m.group(1))
        info['desc_el'] = php_str(m.group(2))
        info['page'] = php_str(m.group(3))
        t = re.search(r"\n\t\t" + re.escape("'" + m.group(2) + "'") + r"\s*=>\s*" + q + ",", core)
        if t:
            info['desc_en'] = php_str(t.group(1))
    main = f'plugins/{slug}/{slug}.php'
    if 'name' not in info and os.path.exists(main):
        with open(main, encoding='utf-8') as fh:
            info['name'] = header(fh.read(), 'Plugin Name')
    readme = f'plugins/{slug}/readme.txt'
    if 'desc_en' not in info and os.path.exists(readme):
        with open(readme, encoding='utf-8') as fh:
            parts = fh.read().split('\n\n')
        short = [p.strip() for p in parts[1:3] if p.strip() and not p.lstrip().startswith(('=', 'Contributors'))]
        if short:
            info['desc_en'] = short[0][:400]
    return {k: v for k, v in info.items() if v}


def asset_digest(rel, slug):
    """The sha256 GitHub reports for the plugin zip of a release, or ''."""
    for a in rel.get('assets', []):
        if a.get('name') == f'{slug}.zip':
            digest = a.get('digest') or ''
            return digest[len('sha256:'):] if digest.startswith('sha256:') else ''
    return ''


def known_entry(previous, version, sha):
    """The current manifest's entry for this version and zip, if any."""
    if not sha:
        return None
    for e in previous.values():
        if isinstance(e, dict) and e.get('version') == version and e.get('sha256') == sha:
            return e
    return None


def main():
    out_file, codes = sys.argv[1], sys.argv[2:]
    repo = os.environ['GITHUB_REPOSITORY']
    key_file = os.environ['NOXPRESS_SIGNING_KEY']

    # The key must match the public key the plugins carry.
    with open(UPDATER, encoding='utf-8') as fh:
        m = re.search(r"const PUBLIC_KEY = '([^']+)'", fh.read())
    if not m or m.group(1) != public_key_of(key_file):
        raise SystemExit('NOXPRESS_SIGNING_KEY does not match Noxpress_Updater::PUBLIC_KEY')

    all_releases = [r for r in releases(repo) if not r.get('draft')]
    serial = int(time.time())
    expires = serial + VALID_DAYS * 86400
    manifest = {'plugins': {}, 'serial': serial, 'expires': expires}

    def sigs(slug, channel, version, sha):
        return {
            'signature': sign(key_file, f'noxpress|{slug}|{version}|{sha}'),
            'sig2': sign(key_file, f'noxpress2|{slug}|{channel}|{version}|{sha}|{serial}|{expires}'),
        }
    previous = {}
    if os.path.exists(out_file):
        with open(out_file, encoding='utf-8') as fh:
            previous = json.load(fh).get('plugins', {})

    with tempfile.TemporaryDirectory() as tmp:
        for code in codes:
            slug = SLUGS[code]
            found = []
            for r in all_releases:
                tag = r.get('tag_name', '')
                prefix = f'nox-{code}-'
                if not tag.startswith(prefix) or not SEMVER.match(tag[len(prefix):]):
                    continue
                if not any(a.get('name') == f'{slug}.zip' for a in r.get('assets', [])):
                    continue
                found.append((vkey(tag[len(prefix):]), tag[len(prefix):], r))
            if not found:
                continue
            found.sort(key=lambda x: x[0])
            picks = {'beta': found[-1]}
            stable = [f for f in found if not f[2].get('prerelease')]
            if stable:
                picks['stable'] = stable[-1]

            entry = {}
            for channel in ('stable', 'beta'):
                if channel not in picks:
                    continue
                _, version, rel = picks[channel]
                tag = rel['tag_name']
                reused = known_entry(previous.get(slug, {}), version, asset_digest(rel, slug))
                if reused:
                    entry[channel] = dict(
                        reused,
                        **sigs(slug, channel, version, reused['sha256']),
                        published=(rel.get('published_at') or '')[:10],
                    )
                    continue
                dest = os.path.join(tmp, tag)
                os.makedirs(dest, exist_ok=True)
                run('gh', 'release', 'download', tag, '--repo', repo, '--pattern', f'{slug}.zip', '--dir', dest, '--clobber')
                path = os.path.join(dest, f'{slug}.zip')
                info = zip_info(path, slug, version)
                if info is None:
                    continue
                with open(path, 'rb') as fh:
                    sha = hashlib.sha256(fh.read()).hexdigest()
                entry[channel] = {
                    'version': version,
                    'zip': f'https://github.com/{repo}/releases/download/{tag}/{slug}.zip',
                    'sha256': sha,
                    **sigs(slug, channel, version, sha),
                    'published': (rel.get('published_at') or '')[:10],
                    **info,
                }
            if entry:
                manifest['plugins'][slug] = dict(entry, **display_info(slug))

    # The whole list, signed as one (Core 1.4.0, "sig3"): a site then sees
    # every release and every field exactly as built here, so nobody can
    # leave a plugin out or change its requirements or changelog.
    signed = json.dumps(manifest, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
    manifest['signed'] = signed
    manifest['sig3'] = sign(key_file, 'noxpress3|' + signed)

    with open(out_file, 'w', encoding='utf-8') as fh:
        json.dump(manifest, fh, indent=2, ensure_ascii=False, sort_keys=True)
        fh.write('\n')


if __name__ == '__main__':
    main()
