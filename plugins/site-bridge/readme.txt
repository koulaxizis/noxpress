=== Site Bridge ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: mcp, claude, ai, remote management, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Claude manages your WordPress / WooCommerce sites over MCP, from your own servers. One Hub takes Claude's connection and forwards each call, signed, to your sites. Each site decides for itself what is allowed.

== Description ==

**One connection, many sites, no service in between**

* One plugin with two roles. The **Hub** is the MCP server Claude connects to (a custom connector, OAuth 2.1 with PKCE). A **Site** is any WordPress where Claude may work. One install can be both.
* Sites are paired with the Hub by a one-time code (valid for 15 minutes). From then on every Hub → Site request is signed with Ed25519, with a timestamp and a single-use nonce, and every answer is signed back. No passwords or application passwords leave a site.
* Every tool takes a `site` id from `list_sites`. Read tools also take `site: "all"` to ask every site in one call.

**Each site decides**

* Level per site: off, read, write or admin. New sites start at read.
* Write and admin tools only run while the site's **write window** is open (15, 30 or 60 minutes, opened by the site's owner in WP admin; the Hub may open it only when the site allows that, off by default).
* Tools run as an administrator chosen on the site, so WordPress's own permissions and hooks apply.
* Personal data (email, phone, address, postcode, IP) is masked by default; secrets (passwords, keys, tokens, salts) are always returned as `***`.
* Optional IP lists for the site endpoint and the MCP endpoint.
* An email to the admin address after every admin-class call.

**Tools**

* Read: `list_sites`, `site_info`, `plugins_list`, `themes_list`, `option_get`, `db_tables`, `db_describe`, `db_select` (SELECT / SHOW / DESCRIBE / EXPLAIN only, with guards, optionally through a read-only database user), `rest_get`, `page_html`, `files_list`, `file_read`, `files_search`, `upload_read`, `logs_tail`, `cron_list`, `site_health`, `abilities_list`, `core_update_check`.
* Write: `rest_write` (the site's own REST API, so plugins validate and hook as usual), `option_set`, `media_upload_from_url`, `cache_flush`, `ability_run` (WordPress Abilities API, class taken from the ability's annotations).
* Admin: `plugins_install` (zip only from allowed URL prefixes, by default github.com/koulaxizis/ and downloads.wordpress.org), `plugins_activate`, `plugins_deactivate`, `plugins_update`, `themes_update`, `noxpress_channel_set`.
* Never: writing files, SQL writes, user or password changes, reading wp-config.php, .env, keys or backups.

**Connections and audit**

* Access tokens last 1 hour, refresh tokens 30 days with rotation and reuse detection, and the approval 90 days. Approve per scope (read / write / admin) and per site.
* Claude identifies itself with a Client ID Metadata Document (CIMD). Dynamic client registration is off by default.
* 120 calls per minute and 10,000 per day per connection (settings).
* Every call is logged on the Hub and on the site, with arguments (secrets redacted), result, time and size. Filters and CSV export. The log is kept for 90 days (setting).
* Kill switch: revoke every connection at once, or `define( 'SBR_DISABLE', true );` in wp-config.php to close every endpoint.

**Personal use**

Site Bridge is built for its author's own sites. It is not listed in the Noxpress catalog and gets no automatic updates: install new release zips by hand. It shares the "Noxpress" admin menu with the other Noxpress plugins.

== Installation ==

1. Upload the `site-bridge` folder to `/wp-content/plugins/` (or install the ZIP from Plugins → Add New) on the Hub and on every site. The Hub and the sites need HTTPS and pretty permalinks.
2. Activate the plugin. In **Noxpress → Site Bridge → Overview** turn on the Hub role (one install) and the Site role (every site Claude may work on). Run **Check now** under Diagnostics.
3. On each site: **Access** → choose the level and the run-as administrator → **New pairing code**.
4. On the Hub: **Sites** → paste the code → **Pair**.
5. In Claude: Settings → Connectors → Add custom connector, name "Site Bridge", URL the MCP address shown in Overview. Leave the OAuth client fields empty. Press Connect, log in to the Hub and approve.

== Frequently Asked Questions ==

= Claude says the connection failed. =

Run **Check now** in Overview. The usual causes are a server that drops the Authorization header (ask the host to pass it to PHP, e.g. `CGIPassAuth On`), plain permalinks, or a server that answers /.well-known/ itself.

= A write tool is refused. =

The site's level must allow it and the site's write window must be open. Open it in **Access** on that site (or from the Hub when the site allows that).

= I changed the salts in wp-config.php. =

The secret key is sealed with the salts, so a new key pair is made and a notice asks you to pair the sites again.

= What happens on uninstall? =

Every setting, key, pairing, connection and the audit log are deleted, so no token or pairing outlives the plugin. The shared Noxpress options stay while another Noxpress plugin is installed.

== Changelog ==

= 1.0.0 =
* Initial release (beta).
* Hub and Site roles in one plugin; pairing with a one-time code; Ed25519-signed Hub → Site requests and answers with timestamp and nonce.
* MCP endpoint (Streamable HTTP, JSON answers) with 30 tools: read, write and admin classes; fleet reads with `site: "all"`.
* OAuth 2.1 authorization server: PKCE, CIMD, optional DCR, refresh rotation with reuse detection, 90-day approval, consent page per scope and site.
* Per-site level, write window, run-as administrator, PII masking, IP lists, admin email, read-only SQL guards and optional read-only database user.
* Audit log on Hub and site with filters and CSV export; rate limits; kill switch and SBR_DISABLE.
* Diagnostics for HTTPS, permalinks, the Authorization header, OAuth metadata, keys and file modifications.
* Greek / English admin (Noxpress Core 1.1.9 menu).
