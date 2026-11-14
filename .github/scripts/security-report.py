#!/usr/bin/env python3
"""Markdown summaries and high counts for security.yml (Bible §13).

Usage: security-report.py <mode> <report.json>
Modes: gitleaks, semgrep, osv, mobsf print a Markdown section;
gitleaks-high, semgrep-high, osv-high print the number of high findings.
Secrets are never printed: gitleaks runs with --redact and only file, line
and rule are shown.
"""
import json
import sys

HIGH_CVSS = 7.0


def load(path):
    try:
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return None


def gitleaks(data):
    return data if isinstance(data, list) else []


def semgrep(data):
    return (data or {}).get('results', []) if isinstance(data, dict) else []


def osv_groups(data):
    """(package, version, ids, max_severity) for every vulnerability group."""
    out = []
    for res in (data or {}).get('results', []) if isinstance(data, dict) else []:
        for pkg in res.get('packages', []):
            info = pkg.get('package', {})
            name = '%s (%s)' % (info.get('name', '?'), info.get('ecosystem', '?'))
            for grp in pkg.get('groups', []):
                try:
                    sev = float(grp.get('max_severity') or 0)
                except ValueError:
                    sev = 0.0
                out.append((name, info.get('version', '?'), grp.get('ids', []), sev))
    return out


def mobsf(data):
    out = []
    for rule, item in ((data or {}).get('results') or {}).items() if isinstance(data, dict) else []:
        meta = item.get('metadata', {})
        files = item.get('files') or []
        out.append((meta.get('severity', '?'), rule, meta.get('description', ''), len(files)))
    return out


def main():
    mode, path = sys.argv[1], sys.argv[2]
    data = load(path)

    if mode == 'gitleaks-high':
        print(len(gitleaks(data)))
    elif mode == 'semgrep-high':
        print(sum(1 for r in semgrep(data) if r.get('extra', {}).get('severity') == 'ERROR'))
    elif mode == 'osv-high':
        print(sum(1 for g in osv_groups(data) if g[3] >= HIGH_CVSS))

    elif mode == 'gitleaks':
        rows = gitleaks(data)
        print('### Secrets (gitleaks)\n')
        if data is None:
            print('No report (gitleaks did not run).')
        elif not rows:
            print('No secrets found.')
        else:
            print('**%d possible secret(s).** Rotate first, then remove:\n' % len(rows))
            for f in rows:
                print('- `%s:%s` %s (commit %s)' % (f.get('File'), f.get('StartLine'), f.get('RuleID'), str(f.get('Commit', ''))[:8]))

    elif mode == 'semgrep':
        rows = semgrep(data)
        print('### Code (semgrep, .github/semgrep)\n')
        if data is None:
            print('No report (semgrep did not run).')
            return
        for err in (data or {}).get('errors', [])[:5]:
            print('- ⚠️ semgrep error: %s' % str(err.get('message', ''))[:200].replace('\n', ' '))
        high = [r for r in rows if r.get('extra', {}).get('severity') == 'ERROR']
        rest = [r for r in rows if r not in high]
        print('**High:** %d · **Report only:** %d\n' % (len(high), len(rest)))
        for r in high:
            print('- 🔴 `%s:%s` %s' % (r['path'], r['start']['line'], r['check_id'].split('.')[-1]))
        if rest:
            counts = {}
            for r in rest:
                key = r['check_id'].split('.')[-1]
                counts.setdefault(key, []).append('%s:%s' % (r['path'], r['start']['line']))
            print('\n<details><summary>Report only</summary>\n')
            for key, places in sorted(counts.items()):
                print('- **%s** (%d): %s' % (key, len(places), ', '.join('`%s`' % p for p in places[:40])))
            print('\n</details>')

    elif mode == 'osv':
        groups = osv_groups(data)
        print('### Dependencies (osv-scanner)\n')
        if data is None:
            print('No report (no lockfile or vendored library found, or osv-scanner did not run).')
        elif not groups:
            print('No known vulnerabilities.')
        else:
            for name, ver, ids, sev in sorted(groups, key=lambda g: -g[3]):
                mark = '🔴' if sev >= HIGH_CVSS else '•'
                print('- %s %s %s: %s (CVSS %s)' % (mark, name, ver, ', '.join(ids), sev or '?'))

    elif mode == 'mobsf':
        rows = mobsf(data)
        print('### Android apps (mobsfscan, report only)\n')
        if data is None:
            print('No report (mobsfscan did not run).')
        elif not rows:
            print('No findings.')
        else:
            for sev, rule, desc, n in sorted(rows):
                print('- %s `%s` (%d file(s)): %s' % (sev, rule, n, desc[:160]))
    print()


if __name__ == '__main__':
    main()
