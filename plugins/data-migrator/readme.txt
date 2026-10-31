=== Data Migrator ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: backup, restore, migration, clone, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Full backup of a WordPress / WooCommerce site and restore on the same or another server, at the same or another address, with no manual fixes afterwards. No external services.

== Description ==

**Backup**

* Full (database and every file of the site), database only, files only, or a custom choice: database, uploads, themes, plugins, mu-plugins, drop-ins, languages, WordPress core, root files.
* One ZIP file, or parts of 100 MB to 2 GB for hosts with upload or file size limits. ZIP64 for archives over 4 GB.
* Runs in short steps, so it works on shared hosting with a 30-second limit and continues after a reload or a lost connection.
* Database dump in pages by primary key, one statement per line, with binary, BIT, spatial and generated columns handled; tables of other applications in the same database only when you tick them.
* Caches, other backup plugins' folders, logs and its own folder stay out by default; your own exclusion patterns in DM Settings.
* A manifest inside each archive: site address, table prefix, versions of PHP, WordPress, the database server and WooCommerce, the active plugins and theme. A CRC for every file inside the ZIP and a sha256 for every part.
* Optional check of the WordPress core files against the official checksums (api.wordpress.org), and a warning for unknown PHP files in the site's root.

**Scheduled backups**

* Up to three schedules: database only or full, every day, week or month, at a time in the site's time zone (a quiet hour, 03:00 by default).
* They run in the background, without an open browser: short steps of at most 10 seconds, one after the other, through WP-Cron or the Action Scheduler of WooCommerce. A visit-free site needs a real cron job; the page says so when a run is late.
* They never take the site down: one job at a time (a manual backup or a restore makes the schedule wait), a free-space check before the archive is written, and a hard stop that deletes the unfinished backup when the free disk space falls under 256 MB.
* Retention: the last N backups of each schedule and a size limit for all scheduled backups together (5 GB by default). Manual backups are never deleted, and a failed run never removes the last good backup.
* The backups stay in the same private folder with the others: download them from the list with one click. If the folder turns out to be downloadable, the schedules pause.
* An email to the site's administrator when a run fails or is skipped (or after every run). Text and a link to the admin only, never the backup.

**Encryption (optional, off by default)**

* Turn it on in DM Settings to encrypt every new backup, scheduled ones included, with a password: AES-256 in the WinZip format, so the archive also opens with the password in 7-Zip, WinZip or macOS Archive Utility. Each file has its own salt and an HMAC check.
* The password is stored sealed with the keys of wp-config.php, or set as `define( 'NM_BACKUP_PASSWORD', '…' );` in wp-config.php. A "Generate password" button makes a strong one. At least 12 characters.
* **If the password is lost, the encrypted backups cannot be opened in any way.** Keep it in a password manager, away from the site.
* The restore, the analysis and the installer ask for the password of an encrypted backup (the stored one is tried first). A backup made on another site registers without it and asks for it at the analysis.
* What is not hidden: the names and sizes of the files inside the ZIP, and a small plain header (`nm/encrypted.json`: backup id, date, address, parts). The SQL dump is in plain text in the private work folder for the few seconds until it is in the archive.
* It never falls back to an unencrypted backup: with encryption on and the password missing or unreadable (e.g. the keys in wp-config.php changed), or without PHP's OpenSSL extension, the backup does not start and a scheduled run emails the administrator.
* Cost: about 3 ms of extra CPU per file inside the archive (10,000 files take about half a minute more), plus the encryption of the data itself.

**Restore and migration**

* Upload a backup from any site (in small chunks, so the server's upload limit does not matter) or pick one already on the server.
* **Analysis first**, changing nothing: every file's CRC, the SQL, the PHP, WordPress and database versions, max_allowed_packet, disk space, and what will change.
* Change of address with every variant of the old URL replaced (http / https, www, protocol-relative, JSON-escaped, URL-encoded) and the old server paths, in serialized data too (lengths are recounted; a broken serialized value is left as it was).
* Change of table prefix, including the roles option and the user meta keys.
* The database is imported into temporary tables and swapped in at once; files are staged and moved into place. The site is in maintenance mode only for those last seconds.
* **Undo with one click**: the replaced tables and files are kept until you finalize. A failure while swapping undoes the restore by itself.
* After the restore: the object cache and the rewrite rules are rebuilt, and on WooCommerce the product lookup tables are regenerated.
* Search engine visibility (blog_public) chosen on the plan: a copy on a staging address stays hidden.
* The restore runs through its own runner, so it finishes even while the plugins and users of the site are being replaced. It needs a one-time recovery code shown when it starts.

**Empty server**

* A one-file installer (`nm-installer.php`) for a server with no WordPress: upload it with the backup's parts, open it in the browser, give the one-time key and the database details. It writes a new wp-config.php with fresh keys, restores the site at the new address and deletes itself and the archive at the end.
* The key is valid for 7 days; 5 wrong attempts lock the installer for 15 minutes.

**Safety**

* Backups are kept outside the web root when the host allows it; otherwise in a folder under uploads with a random name, a deny .htaccess and web.config, and an HTTP self-test that warns if the folder can be downloaded.
* Downloads only from the admin, for administrators, with a nonce. File names are checked against strict patterns on every read and delete.
* wp-config.php, .maintenance and the plugin's own folder are never taken from an archive.

**WP-CLI**

* `wp data-migrator backup [--scope=full|db|files] [--part-mb=<mb>] [--note=<text>]`
* `wp data-migrator import <file>...`, `list`, `verify <id>`, `delete <id>`, `check`
* `wp data-migrator restore <id> [--new-url=<url>] [--local] [--bare] [--db-only|--files-only] [--blog-public=<0|1>] [--salts] [--password=<password>] [--dry-run] [--yes]`
* `verify` and `restore` take `--password=<password>` for an encrypted backup; without it they use the stored password.
* `wp data-migrator undo`, `wp data-migrator finalize`
* `wp data-migrator schedule list`, `wp data-migrator schedule run <id>`

== Installation ==

1. Upload the `data-migrator` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New) and activate it.
2. **Noxpress → Αντίγραφα** (Backups): choose what to back up and press Create. Download the file(s) when it ends.
3. To move the site: install the plugin on the new WordPress, **Noxpress → Επαναφορά** (Restore), upload the backup, press Analyse, check the plan and the new address, and start. Or, on an empty server, use the installer from the backup's row.

== Frequently Asked Questions ==

= Does it work without WooCommerce? =

Yes. It backs up and restores any single-site WordPress. The WooCommerce steps run only when WooCommerce is active.

= The scheduled backup did not run on time. =

WP-Cron runs only when someone visits the site. On a quiet site ask the host for a real cron job that calls `wp-cron.php` every 5 minutes. On hosts that block requests from the site to itself, the backup still advances, one step per minute.

= I forgot the password of an encrypted backup. =

It cannot be recovered: there is no master key and no reset. Backups made with another password still open with theirs. To restore an encrypted backup on a site where the stored password is different, type the backup's own password on the Restore page.

= Multisite? =

Not in 1.0.0. On a network the plugin shows a notice and does nothing.

= Who can sign in after a restore? =

The users of the backup. The plan names the backup's administrators.

= Something is wrong after the restore. =

Press **Undo** on the Restore page (or `wp data-migrator undo`). The site goes back to how it was before the restore. When everything is fine, press **Finalize** to delete the undo data.

= What happens on uninstall? =

Settings, jobs, work files, logs and any undo data are deleted. The backups are deleted too, unless "Keep backups" is ticked in DM Settings. The stored encryption password is always deleted: kept encrypted backups need their password typed in. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.2.1 =
* Fix: uninstall keeps the suite's shared settings (update channel, version list, hub notices) while any Noxpress plugin is still installed. Its list missed the 12 newest plugins, so uninstalling Data Migrator could switch a site from Beta back to Stable.

= 1.2.0 =
* Optional backup encryption (beta), off by default: AES-256 (WinZip AE-2), readable by 7-Zip, WinZip and macOS, with a password stored sealed or set in wp-config.php (`NM_BACKUP_PASSWORD`), and a password generator.
* Encrypted backups in the analysis, the restore, the installer and WP-CLI (`--password`); uploaded encrypted backups register without the password.
* `wp data-migrator list` shows whether a backup is encrypted.

= 1.1.0 =
* Scheduled backups (beta): database only or full, daily, weekly or monthly, in the background, with retention per schedule, a size limit for all scheduled backups, and an email when a run fails.
* Backups stop and delete the unfinished archive when the free disk space falls under 256 MB; scheduled backups check the space before they write.
* WP-CLI: `wp data-migrator schedule list|run`.
* Fix: the Save button of DM Settings read "Storage" in English.

= 1.0.0 =
* Rewritten from scratch (beta).
* Resumable backup in short steps: streaming ZIP with ZIP64 and parts, paged SQL dump, manifest with checksums.
* Restore and migration with analysis first, serialized-safe URL and path replacement, table prefix change, temporary tables and an atomic swap, staged files, undo and finalize.
* Standalone restore runner with a one-time recovery code; installer for an empty server with a one-time key.
* Post-restore cache, rewrite and WooCommerce lookup table rebuild.
* Private storage folder with an HTTP self-test; WordPress core checksum check.
* WP-CLI commands; Greek / English admin (Noxpress menu).
