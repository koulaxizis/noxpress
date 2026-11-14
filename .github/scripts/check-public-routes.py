#!/usr/bin/env python3
"""Every public REST route is on the capability map (Bible §22).

A line with 'permission_callback' => '__return_true' in a plugin must
  - carry a comment on the same line saying why it is public, and
  - be in a file that the "Δημόσια σημεία" table of Bible §22 names.
Prints a Markdown summary; with --count prints only the number of problems.
"""
import glob
import re
import sys

PUBLIC = re.compile(r"""['"]permission_callback['"]\s*=>\s*['"]__return_true['"]""")


def section_22(path='Noxpress_bible.md'):
    text = open(path, encoding='utf-8').read()
    start = text.find('\n## §22')
    if start < 0:
        return ''
    end = text.find('\n## §', start + 5)
    return text[start:] if end < 0 else text[start:end]


def problems():
    mapped = section_22()
    out = []
    for f in sorted(glob.glob('plugins/*/**/*.php', recursive=True)):
        if '/includes/noxpress-core/' in f:
            continue
        with open(f, encoding='utf-8', errors='replace') as fh:
            for n, line in enumerate(fh, 1):
                if not PUBLIC.search(line):
                    continue
                if '//' not in line.split('__return_true', 1)[1]:
                    out.append((f, n, 'public route without a comment saying why'))
                if f'`{f}`' not in mapped:
                    out.append((f, n, 'file not in the «Δημόσια σημεία» table of Bible §22'))
    return out


def main():
    found = problems()
    if '--count' in sys.argv:
        print(len(found))
        return
    print('### Public REST routes (Bible §22)\n')
    if not found:
        print('Every public route is on the map.\n')
        return
    for f, n, why in found:
        print(f'- 🔴 `{f}:{n}` {why}')
    print()


if __name__ == '__main__':
    main()
