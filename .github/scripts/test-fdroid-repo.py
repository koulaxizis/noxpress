#!/usr/bin/env python3
"""Tests .github/scripts/fdroid-repo.py without the network: the store texts F-Droid gets,
which releases are picked, the .sha256 check, the APK names and the publish stamp."""

import hashlib
import importlib.util
import os
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location('fdroid_repo', os.path.join(HERE, 'fdroid-repo.py'))
fr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fr)

fails = 0


def check(cond, what):
    global fails
    print(('ok   ' if cond else 'FAIL ') + what)
    if not cond:
        fails += 1


def raises(fn):
    try:
        fn()
    except SystemExit:
        return True
    return False


# Store texts
with tempfile.TemporaryDirectory() as d:
    pkgs = fr.write_meta(d)
    check(pkgs == ['tech.noxpress.sitebridge'], 'Site Bridge only, package unchanged')
    for p in pkgs:
        with open(os.path.join(d, 'metadata', p + '.yml'), encoding='utf-8') as f:
            yml = f.read()
        check('License: MIT' in yml and 'https://ko-fi.com/koulaxizis' in yml, p + ': MIT and Ko-fi')
        for loc in ('en-US', 'el'):
            base = os.path.join(d, 'metadata', p, loc)
            for f in fr.TEXTS + ('images/icon.png',):
                path = os.path.join(base, f)
                check(os.path.isfile(path) and os.path.getsize(path) > 0, '%s/%s: %s' % (p, loc, f))
            with open(os.path.join(base, 'short_description.txt'), encoding='utf-8') as f:
                short = f.read().strip()
            check(len(short) <= 80, '%s/%s: summary %d <= 80 characters' % (p, loc, len(short)))
            check(short.lower().startswith('beta'), '%s/%s: says beta' % (p, loc))


# Releases: the newest 3 by version, drafts and other tags left out
def rel(tag, data=b'', draft=False, broken=False):
    sha = hashlib.sha256(data).hexdigest()
    return {'tag_name': tag, 'draft': draft, 'assets': [
        {'name': 'site-bridge.apk', 'browser_download_url': 'apk:' + tag},
        {'name': 'site-bridge.apk.sha256', 'browser_download_url': 'sha:' + tag},
    ]}, (data, (('0' * 64) if broken else sha) + '\n')


releases, blobs = [], {}
for tag, kw in (('nox-sba-1.5.0', {}), ('nox-sba-1.10.0', {}), ('nox-sba-1.6.1', {}),
                ('nox-sba-1.9.0', {'draft': True}), ('nox-sba-1.6.0', {}), ('nox-loa-1.3.1', {}),
                ('nox-sb-9.9.9', {}), ('nox-sba-2.0.0-rc1', {})):
    r, (data, sha) = rel(tag, tag.encode(), **kw)
    releases.append(r)
    blobs['apk:' + tag] = data
    blobs['sha:' + tag] = sha.encode()

picked = [r['tag_name'] for r in fr.pick(releases, 'nox-sba', 3)]
check(picked == ['nox-sba-1.10.0', 'nox-sba-1.6.1', 'nox-sba-1.6.0'], 'pick: newest 3 by version, no draft: %s' % picked)

with tempfile.TemporaryDirectory() as d:
    names = fr.fetch(d, 3, releases, blobs.__getitem__)
    check(len(names) == 3 and all(n.startswith('tech.noxpress.sitebridge_') for n in names), 'fetch: 3 APKs')
    check('tech.noxpress.sitebridge_1.10.0_%s.apk' % hashlib.sha256(b'nox-sba-1.10.0').hexdigest()[:8] in names,
          'name = <package>_<version>_<sha8>.apk')
    check(sorted(os.listdir(os.path.join(d, 'repo'))) == names, 'only those files in repo/')
    fr.write_meta(d)
    s1 = fr.stamp(d)
    check(s1 == fr.stamp(d), 'stamp is stable')
    with open(os.path.join(d, 'metadata', 'tech.noxpress.sitebridge', 'en-US', 'title.txt'), 'a') as f:
        f.write('x')
    s2 = fr.stamp(d)
    check(s2 != s1, 'stamp changes with the store texts')
    os.rename(os.path.join(d, 'repo', names[0]), os.path.join(d, 'repo', names[0].replace('_1.', '_7.')))
    check(fr.stamp(d) != s2, 'stamp changes with the APKs')

bad = [rel('nox-sba-1.6.1', b'x', broken=True)]
check(raises(lambda: fr.fetch(tempfile.mkdtemp(), 3, [bad[0][0]], lambda u: b'x' if u.startswith('apk') else bad[0][1][1].encode())),
      'an APK that does not match its .sha256 is refused')
check(raises(lambda: fr.fetch(tempfile.mkdtemp(), 3, [], blobs.__getitem__)), 'no release at all is an error')
check(raises(lambda: fr.repo_name('tech.noxpress.sitebridge', '1.6', b'')), 'a version that is not x.y.z is refused')

print('%d failure(s)' % fails)
sys.exit(1 if fails else 0)
