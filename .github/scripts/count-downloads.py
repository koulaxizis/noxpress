#!/usr/bin/env python3
"""Counts the downloads of this repository's release assets for the site's
"More than X downloads" line (Bible §17).

GitHub keeps a public download_count for every release asset. Each run adds
the current counts to a ledger kept in the output file, by asset id, never
lowering a count, so the total does not drop when a release or an asset is
deleted. The site shows "display": the largest step of 1, 5, 10, 25, 50,
100, 250, 500, 1,000, 2,500… below the total.

The file is rewritten only when "display" changes or an asset appears that
the ledger does not have yet, so the bot commits rarely.

Environment:
  GITHUB_REPOSITORY   owner/repo
  GH_TOKEN            token for the gh CLI

Usage: count-downloads.py <output.json> [--tags REGEX] [--offset N]
  --tags    count only releases whose tag matches (default: all)
  --offset  downloads to subtract, e.g. ones made by our own workflows
Prints "changed" or "unchanged". Only the Python standard library and gh.
"""

import argparse
import datetime
import json
import os
import re
import subprocess


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


def steps():
    yield 1
    yield 5
    base = 10
    while True:
        for m in (1, 2.5, 5):
            yield int(base * m)
        base *= 10


def display(total):
    """The largest step below total (0 when there is none)."""
    shown = 0
    for s in steps():
        if s >= total:
            return shown
        shown = s


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('output')
    ap.add_argument('--tags', default='')
    ap.add_argument('--offset', type=int, default=0)
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
    shown = display(total)

    if old and shown == old.get('display') and set(ledger) <= known:
        print('unchanged')
        return

    data = {
        'display': shown,
        'total': total,
        'offset': args.offset,
        'updated': datetime.date.today().isoformat(),
        'assets': dict(sorted(ledger.items(), key=lambda kv: int(kv[0]))),
    }
    with open(args.output, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, indent=2, ensure_ascii=False)
        fh.write('\n')
    print('changed')


if __name__ == '__main__':
    main()
