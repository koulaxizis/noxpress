#!/usr/bin/env python3
"""Builds the Noxpress F-Droid repository (Bible §21).

The public Android apps of this repository are published as a
self-hosted F-Droid repository, read by F-Droid, Droid-ify and Obtainium:
    https://koulaxizis.github.io/noxpress-fdroid/repo
.github/workflows/fdroid.yml runs it; the APKs are the signed ones of the
GitHub releases, copied as they are (never rebuilt or signed again).

An app is published only when apps/<app>/fdroid/store.json exists (public
apps only: an app built for one site stays out). store.json holds its
package, APK asset name, release tag prefix, category, anti-features and
the English and Greek store texts; icon.png (512 px) sits next to it, and
phone screenshots as <lang>-<n>.png|jpg (lang: en-US, el).

Per app the repository holds the newest stable release and, when newer,
the newest beta (pre-release). F-Droid suggests the stable one and offers
the beta as an "unstable update"; while no stable release exists, the
newest beta is the suggested one.

Commands (run from the repository root):
  plan                     print the state to publish: the chosen release
                           tags per app and a hash of the store files
  build <dir> <plan.json>  download the chosen APKs to <dir>/repo, named
                           <package>_<versionCode>_<sha256 8>.apk, and
                           write <dir>/metadata (texts, icon, changelogs)
  site <dir> <url> <fp>    write <dir>/site: the front page (add link,
                           QR code, apps) and README.md
  keep <published> <site>  keep the APKs of earlier publishes for 3 days
"""

import glob
import hashlib
import html
import json
import os
import re
import shutil
import sys
import time
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
REPO = os.environ.get("GITHUB_REPOSITORY", "koulaxizis/noxpress")
API = "https://api.github.com/repos/" + REPO
SOURCE = "https://github.com/" + REPO
SITE = "https://noxpress.tech/"
DONATE = "https://ko-fi.com/koulaxizis"
LOCALES = ("en-US", "el")
SEMVER = re.compile(r"^(\d+)\.(\d+)\.(\d+)$")

KEEP_SECONDS = 3 * 24 * 3600
KEEP_MAX_BYTES = 300 * 1024 * 1024


def apps():
    """Public apps: every apps/<app>/fdroid/store.json, sorted by folder."""
    out = []
    for path in sorted(glob.glob(os.path.join(ROOT, "apps", "*", "fdroid", "store.json"))):
        with open(path, encoding="utf-8") as f:
            store = json.load(f)
        store["dir"] = os.path.dirname(path)
        store["app"] = os.path.basename(os.path.dirname(store["dir"]))
        for key in ("package", "apk", "tag_prefix"):
            if not store.get(key):
                sys.exit("%s: missing %s" % (path, key))
        for loc in LOCALES:
            t = store.get(loc) or {}
            if not (t.get("name") and t.get("summary") and t.get("description")):
                sys.exit("%s: %s needs name, summary and description" % (path, loc))
            if len(t["summary"]) > 80:
                sys.exit("%s: %s summary is longer than 80 characters" % (path, loc))
        if not os.path.isfile(os.path.join(store["dir"], "icon.png")):
            sys.exit("%s: icon.png is missing" % store["dir"])
        out.append(store)
    return out


def get_json(url):
    req = urllib.request.Request(url, headers={"Accept": "application/vnd.github+json", "User-Agent": "noxpress-fdroid"})
    token = os.environ.get("GH_TOKEN")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.load(r)


def releases():
    """Every published release of the repository (the plugins' too)."""
    out = []
    for page in range(1, 21):
        batch = get_json("%s/releases?per_page=100&page=%d" % (API, page))
        out.extend(r for r in batch if not r.get("draft"))
        if len(batch) < 100:
            break
    return out


def choose(store, rels):
    """The newest stable release of an app and, when newer, the newest beta."""
    prefix = store["tag_prefix"] + "-"
    found = []
    for r in rels:
        tag = r.get("tag_name", "")
        m = SEMVER.match(tag[len(prefix):]) if tag.startswith(prefix) else None
        asset = next((a for a in r.get("assets", []) if a.get("name") == store["apk"]), None)
        if m and asset:
            found.append({
                "tag": tag,
                "version": tag[len(prefix):],
                "key": tuple(int(x) for x in m.groups()),
                "beta": bool(r.get("prerelease")),
                "url": asset["browser_download_url"],
            })
    stable = max((x for x in found if not x["beta"]), key=lambda x: x["key"], default=None)
    beta = max((x for x in found if x["beta"]), key=lambda x: x["key"], default=None)
    chosen = [x for x in (stable, beta) if x and (x is stable or not stable or x["key"] > stable["key"])]
    for x in chosen:
        del x["key"]
    return chosen


def store_hash():
    """Hash of everything that changes the published texts or pages."""
    files = [os.path.abspath(__file__), os.path.join(ROOT, ".github", "workflows", "fdroid.yml"),
             os.path.join(ROOT, "apps", "fdroid", "icon.png")]
    for store in apps():
        files += sorted(glob.glob(os.path.join(store["dir"], "*")))
        files.append(os.path.join(ROOT, "apps", store["app"], "CHANGELOG.md"))
    h = hashlib.sha256()
    for path in files:
        if os.path.isfile(path):
            h.update(os.path.relpath(path, ROOT).encode() + b"\0")
            with open(path, "rb") as f:
                h.update(hashlib.sha256(f.read()).digest())
    return h.hexdigest()


def cmd_plan():
    rels = releases()
    plan = {"apps": {}, "store": store_hash()}
    for store in apps():
        chosen = choose(store, rels)
        if chosen:
            plan["apps"][store["package"]] = chosen
        else:
            print("::warning::%s: no release with %s yet" % (store["package"], store["apk"]), file=sys.stderr)
    print(json.dumps(plan, indent=2, sort_keys=True))


def changelog(store, version):
    """The CHANGELOG.md section of a version as plain text (F-Droid shows ~500 characters)."""
    path = os.path.join(ROOT, "apps", store["app"], "CHANGELOG.md")
    try:
        with open(path, encoding="utf-8") as f:
            text = f.read()
    except OSError:
        return ""
    m = re.search(r"^## " + re.escape(version) + r"\s*$(.*?)(?=^## |\Z)", text, re.M | re.S)
    if not m:
        return ""
    body = m.group(1).strip()
    body = re.sub(r"\*\*([^*]+)\*\*", r"\1", body)
    body = re.sub(r"`([^`]+)`", r"\1", body)
    body = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", body)
    body = re.sub(r"^\* ", "• ", body, flags=re.M)
    if len(body) > 480:
        body = body[:470].rsplit(" ", 1)[0] + " …"
    more = "%s/blob/main/apps/%s/CHANGELOG.md" % (SOURCE, store["app"])
    return body + "\n\n" + more


def apk_id(path):
    """(package, versionCode, versionName) of an APK, read by fdroidserver."""
    from fdroidserver import common  # the workflow's venv
    common.config = common.config or {}
    appid, code, name = common.get_apk_id(path)
    return appid, int(code), name


def download(url, dest):
    req = urllib.request.Request(url, headers={"User-Agent": "noxpress-fdroid"})
    for attempt in range(4):
        try:
            with urllib.request.urlopen(req, timeout=300) as r, open(dest, "wb") as f:
                shutil.copyfileobj(r, f)
            return
        except OSError as e:
            if attempt == 3:
                raise
            print("download failed (%s), retrying" % e, file=sys.stderr)
            time.sleep(2 ** (attempt + 1))


def metadata_yml(store, current_code):
    lines = [
        "Categories:",
        "  - " + store.get("category", "Internet"),
        "License: MIT",
        "AuthorName: Noxpress",
        "AuthorWebSite: " + SITE,
        "WebSite: " + store.get("web", SITE),
        "SourceCode: %s/tree/main/apps/%s" % (SOURCE, store["app"]),
        "IssueTracker: %s/issues" % SOURCE,
        "Changelog: %s/blob/main/apps/%s/CHANGELOG.md" % (SOURCE, store["app"]),
        "Donate: " + DONATE,
        "CurrentVersionCode: %d" % current_code,
    ]
    return "\n".join(lines) + "\n"


def anti_features_yml(store):
    """App-level AntiFeatures with their reasons (fdroidserver 2.3+ format)."""
    af = store.get("anti_features") or {}
    if not af:
        return ""
    out = ["AntiFeatures:"]
    for name, reasons in sorted(af.items()):
        out.append("  %s:" % name)
        for loc, text in sorted(reasons.items()):
            out.append("    %s: %s" % (loc, json.dumps(text, ensure_ascii=False)))
    return "\n".join(out) + "\n"


def cmd_build(outdir, plan_path):
    with open(plan_path, encoding="utf-8") as f:
        plan = json.load(f)
    repo = os.path.join(outdir, "repo")
    meta = os.path.join(outdir, "metadata")
    os.makedirs(repo, exist_ok=True)
    os.makedirs(meta, exist_ok=True)
    stores = {s["package"]: s for s in apps()}
    for pkg, chosen in sorted(plan["apps"].items()):
        store = stores.get(pkg)
        if not store:
            sys.exit("%s is in the plan but has no store.json" % pkg)
        codes = {}
        for rel in chosen:
            tmp = os.path.join(outdir, "download.apk")
            download(rel["url"], tmp)
            appid, code, name = apk_id(tmp)
            if appid != pkg or name != rel["version"]:
                sys.exit("::error::%s: APK is %s %s, expected %s %s" % (rel["tag"], appid, name, pkg, rel["version"]))
            with open(tmp, "rb") as f:
                digest = hashlib.sha256(f.read()).hexdigest()[:8]
            # One file name per build: a fixed name changing under the same
            # URL makes the Pages CDN or a resumed download serve old bytes
            # ("Hash not matching").
            os.replace(tmp, os.path.join(repo, "%s_%d_%s.apk" % (pkg, code, digest)))
            codes[rel["tag"]] = (code, name, rel["beta"])
            print("%s %s → versionCode %d%s" % (pkg, name, code, " (beta)" if rel["beta"] else ""))
        stable = [c for c, n, b in codes.values() if not b]
        current = max(stable) if stable else max(c for c, n, b in codes.values())
        yml = metadata_yml(store, current)
        af = anti_features_yml(store)
        if af:
            yml += af
        with open(os.path.join(meta, pkg + ".yml"), "w", encoding="utf-8") as f:
            f.write(yml)
        for loc in LOCALES:
            d = os.path.join(meta, pkg, loc)
            os.makedirs(os.path.join(d, "changelogs"), exist_ok=True)
            t = store[loc]
            for key in ("name", "summary", "description"):
                with open(os.path.join(d, key + ".txt"), "w", encoding="utf-8") as f:
                    f.write(t[key].strip() + "\n")
            for code, name, beta in codes.values():
                text = changelog(store, name) if loc == "en-US" else ""
                if text:
                    with open(os.path.join(d, "changelogs", "%d.txt" % code), "w", encoding="utf-8") as f:
                        f.write(text + "\n")
            if loc == "en-US":
                shutil.copyfile(os.path.join(store["dir"], "icon.png"), os.path.join(d, "icon.png"))
            shots = sorted(
                (p for p in glob.glob(os.path.join(store["dir"], loc + "-*.*")) if re.search(r"-\d+\.(png|jpg)$", p)),
                key=lambda p: int(re.search(r"-(\d+)\.", p).group(1)))
            if shots:
                sd = os.path.join(d, "phoneScreenshots")
                os.makedirs(sd, exist_ok=True)
                for i, p in enumerate(shots, 1):
                    shutil.copyfile(p, os.path.join(sd, "%d%s" % (i, os.path.splitext(p)[1])))


PAGE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Noxpress F-Droid repository</title>
<meta name="description" content="The Noxpress Android apps for F-Droid, Droid-ify and Obtainium. Free and open source (MIT), free forever.">
<link rel="icon" href="repo/icons/icon.png">
<style>
:root { color-scheme: dark; --bg: #0d0f14; --surface: #14161d; --border: #262a35; --text: #e4e2f5; --muted: #9b97b8; --accent: #6d4aff; --accent-bright: #b3a4ff; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
header { display: flex; gap: 16px; align-items: center; }
header img { border-radius: 14px; }
h1 { margin: 0; font-size: 1.5rem; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-top: 18px; }
.add { display: flex; gap: 18px; flex-wrap: wrap; align-items: center; }
.add ol { margin: 0; padding-left: 1.2em; flex: 1 1 280px; }
.qr { background: #fff; padding: 8px; border-radius: 10px; }
.btn { display: inline-block; margin-top: 10px; background: var(--accent); color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 10px; font-weight: 600; }
code { word-break: break-all; font-size: .85em; color: var(--muted); }
ul.apps { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
ul.apps li { display: flex; gap: 12px; align-items: center; }
ul.apps img { border-radius: 12px; flex: none; }
small, .muted { color: var(--muted); }
a { color: var(--accent-bright); }
</style>
</head>
<body>
<main>
<header><img src="repo/icons/icon.png" alt="" width="64" height="64"><div><h1>Noxpress F-Droid repository</h1>
<div class="muted">Το αποθετήριο F-Droid του Noxpress · free and open source (MIT), free forever</div></div></header>
<section class="card add">
<img class="qr" src="repo/index.png" alt="QR code of the repository" width="180" height="180">
<ol>
<li>Install <a href="https://f-droid.org/">F-Droid</a>, <a href="https://droid-ify.com/">Droid-ify</a> or <a href="https://obtainium.imranr.dev/">Obtainium</a>.</li>
<li>On your phone, tap the button below, or scan the QR code with the app.</li>
<li>Install a Noxpress app; updates then come by themselves. New versions ship as beta first: turn on "unstable updates" to get them early.</li>
</ol>
<div style="flex-basis:100%">
<a class="btn" href="{add}">Add the Noxpress repository</a>
<p class="muted">Στο κινητό πάτα το κουμπί ή σκάναρε τον κωδικό QR από το F-Droid ή το Droid-ify. Μετά εγκατέστησε την εφαρμογή· οι ενημερώσεις έρχονται μόνες τους.</p>
<p>Address: <code>{repo}</code><br>Fingerprint (SHA-256): <code>{fp}</code></p>
</div>
</section>
<section class="card">
<ul class="apps">
{rows}
</ul>
</section>
<p class="muted">Noxpress: <a href="https://noxpress.tech/">noxpress.tech</a> · source: <a href="{source}">github.com/{name}</a> · support: <a href="https://ko-fi.com/koulaxizis">Ko-fi</a></p>
<p class="muted">Designed with &lt;3 by Christos Koulaxizis (<a href="https://koulaxizis.gr/">koulaxizis.gr</a>). Assisted by Lumo. Audited by Claude.</p>
</main>
</body>
</html>
"""


def cmd_site(outdir, repo_url, fp):
    fp = fp.replace(":", "").upper()
    if not re.fullmatch(r"[0-9A-F]{64}", fp):
        sys.exit("fingerprint must be 64 hex characters")
    site = os.path.join(outdir, "site")
    os.makedirs(site, exist_ok=True)
    rows = []
    for store in apps():
        if not os.path.isfile(os.path.join(outdir, "metadata", store["package"] + ".yml")):
            continue
        en, el = store["en-US"], store["el"]
        rows.append('<li><img src="repo/%s/en-US/icon.png" alt="" width="48" height="48"><div><b>%s</b><br><small>%s</small><br><small>%s</small></div></li>'
                    % (html.escape(store["package"]), html.escape(en["name"]), html.escape(en["summary"]), html.escape(el["summary"])))
    add = "https://fdroid.link/#%s?fingerprint=%s" % (repo_url, fp)
    page = PAGE
    for key, value in (("{add}", html.escape(add)), ("{repo}", html.escape(repo_url)), ("{fp}", fp),
                       ("{rows}", "\n".join(rows)), ("{source}", SOURCE), ("{name}", REPO)):
        page = page.replace(key, value)
    with open(os.path.join(site, "index.html"), "w", encoding="utf-8") as f:
        f.write(page)
    with open(os.path.join(site, "README.md"), "w", encoding="utf-8") as f:
        f.write("# Noxpress F-Droid repository\n\n"
                "The public Noxpress Android apps, kept up to date by F-Droid, Droid-ify or Obtainium.\n"
                "Built by [%s](%s) (.github/workflows/fdroid.yml); do not edit by hand.\n\n"
                "Repository: %s\n\nFingerprint (SHA-256): `%s`\n" % (REPO, SOURCE, repo_url, fp))


def keep_plan(prev, files, current, now):
    """APKs that left the index less than KEEP_SECONDS ago, newest first, up to KEEP_MAX_BYTES."""
    cands = []
    for name, size in files:
        if not name.endswith(".apk") or name in current:
            continue
        at = prev.get(name) if isinstance(prev.get(name), (int, float)) else now
        if now - at < KEEP_SECONDS:
            cands.append((at, name, size))
    cands.sort(key=lambda c: (-c[0], c[1]))
    out, total = {}, 0
    for at, name, size in cands:
        if total + size > KEEP_MAX_BYTES:
            break
        total += size
        out[name] = at
    return out


def cmd_keep(published, site):
    """A phone downloads with the index it fetched last, often hours old;
    the files that index names must not 404. Kept files are not in the
    index, only downloadable."""
    try:
        with open(os.path.join(published, "kept-apks.json"), encoding="utf-8") as f:
            prev = json.load(f)
    except (OSError, ValueError):
        prev = {}
    pub_repo = os.path.join(published, "repo")
    site_repo = os.path.join(site, "repo")
    files = []
    if os.path.isdir(pub_repo):
        files = [(n, os.path.getsize(os.path.join(pub_repo, n))) for n in os.listdir(pub_repo)]
    keep = keep_plan(prev, files, set(os.listdir(site_repo)), int(time.time()))
    for name in keep:
        shutil.copyfile(os.path.join(pub_repo, name), os.path.join(site_repo, name))
    with open(os.path.join(site, "kept-apks.json"), "w", encoding="utf-8") as f:
        json.dump(keep, f, indent=2, sort_keys=True)
        f.write("\n")
    print("%d earlier APKs kept" % len(keep))


def main(argv):
    cmds = {"plan": (cmd_plan, 0), "build": (cmd_build, 2), "site": (cmd_site, 3), "keep": (cmd_keep, 2)}
    if len(argv) < 2 or argv[1] not in cmds or len(argv) - 2 != cmds[argv[1]][1]:
        sys.exit(__doc__)
    cmds[argv[1]][0](*argv[2:])


if __name__ == "__main__":
    main(sys.argv)
