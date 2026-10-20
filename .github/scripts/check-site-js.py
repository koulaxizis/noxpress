#!/usr/bin/env python3
"""Checks that the inline scripts of the site's pages parse (Bible §17).

One syntax error (e.g. an unescaped ' inside an EN/EL string) stops the whole
<script> block: the language toggle, the video dialog and the download counter
all stop working. Each inline <script> of the pages below goes through
`node --check`.

Usage: check-site-js.py
Prints one line per problem and exits 1 if there is any. Standard library and node.
"""

import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PAGES = ['index.html', 'supporters/index.html']


def main():
    bad = 0
    for page in PAGES:
        with open(os.path.join(ROOT, page), encoding='utf-8') as f:
            html = f.read()
        for m in re.finditer(r'<script>(.*?)</script>', html, re.S):
            line = html.count('\n', 0, m.start(1)) + 1
            with tempfile.NamedTemporaryFile('w', suffix='.js', delete=False, encoding='utf-8') as tmp:
                # Pad with newlines so node reports the page's own line numbers.
                tmp.write('\n' * (line - 1) + m.group(1))
            try:
                res = subprocess.run(['node', '--check', tmp.name], capture_output=True, text=True)
            finally:
                os.unlink(tmp.name)
            if res.returncode:
                bad += 1
                err = res.stderr.replace(tmp.name, page).strip().splitlines()
                print(f'{page}: script at line {line} does not parse')
                print('\n'.join('  ' + e for e in err[:5]))
    if bad:
        sys.exit(1)
    print('ok')


if __name__ == '__main__':
    main()
