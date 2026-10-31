#!/usr/bin/env python3
"""Checks the version headers of every plugin (Bible §2).

For each plugins/<slug>/:
  - the main file's Version: equals readme.txt's Stable tag and the <PREFIX>_VERSION constant;
  - Requires PHP: 7.4;
  - readme.txt's Tested up to: is the same in every plugin;
  - a plugin with "WC requires at least:" also has "WC tested up to:", the same in every plugin;
  - every copy of includes/noxpress-core/ is identical.

Prints one line per problem and exits 1 if there is any. Standard library only.
"""

import filecmp
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PLUGINS = os.path.join(ROOT, 'plugins')


def header(text, name):
    m = re.search(r'^\s*\*\s*' + re.escape(name) + r':\s*(\S+)', text, re.M)
    return m.group(1) if m else None


def readme(text, name):
    m = re.search(r'^' + re.escape(name) + r':\s*(\S+)', text, re.M)
    return m.group(1) if m else None


def same_tree(a, b):
    cmp = filecmp.dircmp(a, b)
    if cmp.left_only or cmp.right_only or cmp.funny_files:
        return False
    _, mismatch, errors = filecmp.cmpfiles(a, b, cmp.common_files, shallow=False)
    if mismatch or errors:
        return False
    return all(same_tree(os.path.join(a, d), os.path.join(b, d)) for d in cmp.common_dirs)


def main():
    problems = []
    tested, wc_tested = {}, {}
    cores = []
    for slug in sorted(os.listdir(PLUGINS)):
        main_file = os.path.join(PLUGINS, slug, slug + '.php')
        readme_file = os.path.join(PLUGINS, slug, 'readme.txt')
        if not os.path.isfile(main_file):
            continue
        with open(main_file, encoding='utf-8') as f:
            php = f.read()
        txt = ''
        if os.path.isfile(readme_file):
            with open(readme_file, encoding='utf-8') as f:
                txt = f.read()
        else:
            problems.append(f'{slug}: readme.txt is missing')
        version = header(php, 'Version')
        stable = readme(txt, 'Stable tag')
        if version != stable:
            problems.append(f'{slug}: Version {version} but readme Stable tag {stable}')
        const = re.search(r"define\(\s*'[A-Z]+_VERSION',\s*'([^']+)'", php)
        if not const or const.group(1) != version:
            problems.append(f'{slug}: the _VERSION constant ({const.group(1) if const else "missing"}) is not {version}')
        if header(php, 'Requires PHP') != '7.4':
            problems.append(f'{slug}: Requires PHP is not 7.4')
        tested.setdefault(readme(txt, 'Tested up to'), []).append(slug)
        if header(php, 'WC requires at least'):
            wc_tested.setdefault(header(php, 'WC tested up to'), []).append(slug)
        core = os.path.join(PLUGINS, slug, 'includes', 'noxpress-core')
        if os.path.isdir(core):
            cores.append((slug, core))
    for label, values in (('readme Tested up to', tested), ('WC tested up to', wc_tested)):
        if len(values) > 1:
            parts = '; '.join(f'{k}: {", ".join(v)}' for k, v in sorted(values.items(), key=lambda kv: str(kv[0])))
            problems.append(f'{label} differs between plugins ({parts})')
    if cores:
        ref_slug, ref = cores[0]
        for slug, core in cores[1:]:
            if not same_tree(ref, core):
                problems.append(f'{slug}: includes/noxpress-core differs from {ref_slug} (run .github/scripts/sync-core.sh)')
    for p in problems:
        print(p)
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
