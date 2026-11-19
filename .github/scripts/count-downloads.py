#!/usr/bin/env python3
"""Counts the downloads of this repository's release assets for the site's
"X downloads" line (Bible §17).

GitHub keeps a public download_count for every release asset. Each run adds
the current counts to a ledger kept in the output file, by asset id, never
lowering a count, so the total does not drop when a release or an asset is
deleted. The site shows "display", the exact total (Chris 2026-11-14; until
then it was rounded down to a step of 1, 5, 10, 25, 50, 100…).

The file is rewritten only when "display" changes or an asset appears that
the ledger does not have yet, so the bot commits at most once per run.

Environment:
  GITHUB_REPOSITORY   owner/repo
  GH_TOKEN            token for the gh CLI

Android (Bible §17, APPS-DL): with --android URL the counts per app and
channel from the Koulaxizis Apps download counter (stats.json) for the
packages matching --android-pkgs are copied into "android" ({package:
{channel: n}}), so the site still reads only this file. If the counter cannot
be read, the previous "android" stays.

Usage: count-downloads.py <output.json> [--tags REGEX] [--offset N]
                          [--android URL --android-pkgs PREFIXES]
  --tags    count only releases whose tag matches (default: all)
  --offset  downloads to subtract, e.g. ones made by our own workflows
  --android-pkgs  comma list; an entry ending in "." is a prefix
Prints "changed" or "unchanged". Only the Python standard library and gh.
"""

import argparse
import datetime
import json
import os
import re
import subprocess
import urllib.request


def releases(repo):
    out = subprocess.run(
        ['gh', 'api', f'repos/{repo}/releases', '--paginate', '--jq', '.[]'],
        check=True, capture_output=True,
    ).stdout.decode()
    dec = json.JSONDecoder()
    items, pos = [], 0
    while pos < len(out):
        while pos < len(out) and out[pos].isspace():
            pos += 1
        if pos >= len(out):
            break
        obj, pos = dec.raw_decode(out, pos)
        items.append(obj)
    return items


def android(url, pkgs, old):
    """{package: {channel: n}} from the counter's stats.json, or old on failure."""
    want = [p.strip() for p in pkgs.split(',') if p.strip()]

    def match(pkg):
        return any(pkg == p or (p.endswith('.') and pkg.startswith(p)) for p in want)

    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'noxpress-count-downloads'})
        with urllib.request.urlopen(req, timeout=20) as r:
            stats = json.load(r)
        apps = stats['apps'] if stats.get('v') == 1 else None
    except Exception as e:  # the counter is optional: keep what we had
        print(f'android: counter not read ({e})', flush=True)
        return old
    if not isinstance(apps, dict):
        return old
    out = {}
    for pkg in sorted(apps):
        if not match(pkg) or not isinstance(apps[pkg], dict):
            continue
        row = {}
        for ch, v in sorted(apps[pkg].items()):
            n = v.get('n') if isinstance(v, dict) else v
            if isinstance(n, int) and n >= 0:
                row[ch] = n
        if row:
            out[pkg] = row
    # counts never go down here either
    for pkg, row in (old or {}).items():
        for ch, n in row.items():
            if n > out.get(pkg, {}).get(ch, 0):
                out.setdefault(pkg, {})[ch] = n
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('output')
    ap.add_argument('--tags', default='')
    ap.add_argument('--offset', type=int, default=0)
    ap.add_argument('--android', default='')
    ap.add_argument('--android-pkgs', default='')
    args = ap.parse_args()
    tags = re.compile(args.tags) if args.tags else None

    old = {}
    if os.path.exists(args.output):
        with open(args.output, encoding='utf-8') as fh:
            old = json.load(fh)
    ledger = {k: dict(v) for k, v in old.get('assets', {}).items()}
    known = set(ledger)

    for r in releases(os.environ['GITHUB_REPOSITORY']):
        if r.get('draft'):
            continue
        tag = r.get('tag_name', '')
        if tags and not tags.search(tag):
            continue
        for a in r.get('assets', []):
            key = str(a['id'])
            count = int(a.get('download_count') or 0)
            prev = ledger.get(key, {}).get('count', 0)
            ledger[key] = {'file': f"{tag}/{a.get('name', '')}", 'count': max(prev, count)}

    total = max(0, sum(v['count'] for v in ledger.values()) - args.offset)
    shown = total

    droid = old.get('android')
    if args.android:
        droid = android(args.android, args.android_pkgs, droid)

    if old and shown == old.get('display') and set(ledger) <= known and droid == old.get('android'):
        print('unchanged')
        return

    data = {
        'display': shown,
        'total': total,
        'offset': args.offset,
        'updated': datetime.date.today().isoformat(),
        'assets': dict(sorted(ledger.items(), key=lambda kv: int(kv[0]))),
    }
    if droid:
        data['android'] = droid
    with open(args.output, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, indent=2, ensure_ascii=False)
        fh.write('\n')
    print('changed')


if __name__ == '__main__':
    main()
