=== Lean Images ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: webp, avif, images, performance, picture
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Local WebP (and optional AVIF) for your JPEG and PNG images, made on your own server in small background batches and served with <picture>. No account, no quota, no external service.

== Description ==

Lean Images makes a lighter copy of every image of the media library on your own server, with the image editor WordPress already uses (Imagick or GD), and serves it to the browsers that accept it. The original files never change.

**Conversion**

* JPEG and PNG, the full file and every size WordPress made. Each copy is saved next to its source as `name.png.webp` (and `name.png.avif`), the name most image plugins use, so existing WebP files (e.g. Imagify's) are recognised instead of being made again.
* A copy is kept only when it is at least 10% smaller than its source (adjustable); otherwise the original is served.
* Quality 82 for WebP and 60 for AVIF by default. AVIF is off by default: it is about 20% smaller than WebP but takes several times the CPU, which matters on shared hosting.

**Gentle on the server**

* Small batches in the background, one every 5 minutes (Action Scheduler when available, else WP-Cron): 10 images or 20 seconds by default, whichever comes first, and a stop before memory reaches 80% of the limit. One batch at a time.
* Optional night window (01:00–07:00 site time). A sample test on 20 images estimates the time and the disk space for the whole library before you start.
* New uploads wait for the next batch, so uploading is never slower. Editing or regenerating an image makes its copies again; deleting it deletes them.

**Serving**

* `<picture>` with a `<source>` per format and the original `<img>` inside, for the images that WordPress prints (themes, WooCommerce, blocks, content, featured images). Old browsers keep the original.
* The same HTML for every visitor, so page caches and Cloudflare stay correct: no `Accept`-header rewrite, no `Vary`, no `.htaccess`.
* Optional whole-page pass for images printed by hand (page builders, theme options).
* Never in admin, AJAX, REST, feeds or emails. Every copy is checked on disk before it is named, so a missing file never shows a broken image.

**Moving from Imagify**

* While Imagify serves its own `<picture>`, Lean Images waits and says so; its WebP files are adopted by the batches. Deactivate Imagify once the conversion is done.

**Claude through Site Bridge**

* Abilities (WordPress 6.9+): `lean-images/status` (read), `lean-images/run-batch`, `lean-images/pause` and `lean-images/resume`.
* The page cache is purged through Noxpress Core (Fast Pages, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache) at most once an hour while converting, and when the queue is empty.

**Safe by design**

* Nothing happens on activation: conversion starts with «Start», and test mode (on by default) shows the new images only to administrators until you leave it.
* `define( 'LIM_DISABLE', true );` in wp-config.php stops the batches and the `<picture>` output; the site then works as without the plugin.
* Tools → «Delete all the files we made» removes every copy; uninstalling the plugin does the same. The originals are never touched.
* Part of the Noxpress ecosystem: shares the "Noxpress" admin menu and the Noxpress hub; updates come from noxpress.tech (GitHub releases) through WordPress's own update screens, checked with sha256 and an Ed25519 signature.

== Installation ==

1. Install and activate the plugin. Open **Noxpress → Lean Images**: the Overview tab shows which image editor the server has and whether it writes WebP and AVIF.
2. Optional: Tools → «Test» on 20 images for an estimate of time and space.
3. Press «Start». Batches run every 5 minutes; check a few pages while logged in (test mode).
4. Press «Leave test mode» so every visitor gets the new images. If Imagify is active, deactivate it.

== Frequently Asked Questions ==

= Does it change my images? =

No. The originals stay as they are; Lean Images adds a copy next to each one and serves it with `<picture>`.

= How much disk space does it need? =

It depends on the originals. From PNG covers the WebP copies are often 10–25% of their size; from already compressed JPEG files, 30–60%. The sample test and the Overview tab show the real numbers.

= My host limits CPU. =

Lower the batch size or the time limit, or turn on «Only at night». AVIF stays off unless you turn it on.

== Disclaimer ==

Use at your own risk. This plugin is free software, provided "as is", without warranty of any kind (MIT licence). Test it on a staging copy first and keep a full backup of your site and database before you install, update or remove any plugin. To the fullest extent permitted by law, there is no liability for any loss of data, orders, revenue or any other damage arising from its use.

== Changelog ==

= 1.0.1 =
* Noxpress Core 1.2.8: a use-at-your-own-risk notice on the Noxpress hub page, and a Disclaimer section in this readme.

= 1.0.0 =
* First release: local WebP / AVIF conversion with Imagick or GD in background batches (Action Scheduler or WP-Cron) with a size, time and memory budget, a lock and an optional night window; `<picture>` output for attachment images, featured images and content, optional whole-page pass; test mode, `LIM_DISABLE`, sample test, retry, one image, orphans, delete all; media library column; Imagify detection and adoption of its WebP files; page cache purge through Noxpress Core; abilities for Site Bridge.
