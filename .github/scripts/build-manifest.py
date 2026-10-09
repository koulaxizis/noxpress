#!/usr/bin/env python3
"""Builds updates.json for Noxpress Core (Bible §16).

For every plugin code given on the command line it picks, from the GitHub
releases of this repository:
  - "stable": the newest release that is not a pre-release,
  - "beta":   the newest release of any kind,
downloads the plugin zip, and records its sha256, an Ed25519 signature of
"noxpress|<slug>|<version>|<sha256>", the requirements from the plugin
header and readme, and the changelog of that version.

Environment:
  GITHUB_REPOSITORY      owner/repo
  NOXPRESS_SIGNING_KEY   path to the Ed25519 private key (PEM)
  GH_TOKEN               token for the gh CLI

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
import zipfile

SLUGS = {
    'rs': 'revenue-splitter',
    'sp': 'store-pulse',
    'sf': 'smart-formatter',
    'tp': 'theme-patcher',
    'nm': 'data-migrator',
}
UPDATER = 'plugins/revenue-splitter/includes/noxpress-core/class-noxpress-updater.php'
SEMVER = re.compile(r'^\d+\.\d+\.\d+$')


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
    manifest = {'plugins': {}}

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
                    'signature': sign(key_file, f'noxpress|{slug}|{version}|{sha}'),
                    'published': (rel.get('published_at') or '')[:10],
                    **info,
                }
            if entry:
                manifest['plugins'][slug] = entry

    with open(out_file, 'w', encoding='utf-8') as fh:
        json.dump(manifest, fh, indent=2, ensure_ascii=False, sort_keys=True)
        fh.write('\n')


if __name__ == '__main__':
    main()
