=== Data Migrator ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: backup, restore, migration, clone, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
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
* `wp data-migrator restore <id> [--new-url=<url>] [--local] [--bare] [--db-only|--files-only] [--blog-public=<0|1>] [--salts] [--dry-run] [--yes]`
* `wp data-migrator undo`, `wp data-migrator finalize`

== Installation ==

1. Upload the `data-migrator` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New) and activate it.
2. **Noxpress → Αντίγραφα** (Backups): choose what to back up and press Create. Download the file(s) when it ends.
3. To move the site: install the plugin on the new WordPress, **Noxpress → Επαναφορά** (Restore), upload the backup, press Analyse, check the plan and the new address, and start. Or, on an empty server, use the installer from the backup's row.

== Frequently Asked Questions ==

= Does it work without WooCommerce? =

Yes. It backs up and restores any single-site WordPress. The WooCommerce steps run only when WooCommerce is active.

= Multisite? =

Not in 1.0.0. On a network the plugin shows a notice and does nothing.

= Who can sign in after a restore? =

The users of the backup. The plan names the backup's administrators.

= Something is wrong after the restore. =

Press **Undo** on the Restore page (or `wp data-migrator undo`). The site goes back to how it was before the restore. When everything is fine, press **Finalize** to delete the undo data.

= What happens on uninstall? =

Settings, jobs, work files, logs and any undo data are deleted. The backups are deleted too, unless "Keep backups" is ticked in DM Settings. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.0 =
* Rewritten from scratch (beta).
* Resumable backup in short steps: streaming ZIP with ZIP64 and parts, paged SQL dump, manifest with checksums.
* Restore and migration with analysis first, serialized-safe URL and path replacement, table prefix change, temporary tables and an atomic swap, staged files, undo and finalize.
* Standalone restore runner with a one-time recovery code; installer for an empty server with a one-time key.
* Post-restore cache, rewrite and WooCommerce lookup table rebuild.
* Private storage folder with an HTTP self-test; WordPress core checksum check.
* WP-CLI commands; Greek / English admin (Noxpress menu).
