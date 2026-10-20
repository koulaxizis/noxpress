=== Site Bridge ===
Contributors: koulaxizis
Donate link: https://ko-fi.com/koulaxizis
Tags: mcp, claude, ai, remote management, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
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
* Write and admin tools only run while the site's **write window** is open (15, 30 or 60 minutes, 3 or 5 hours, opened by the site's owner in WP admin; the Hub may open it only when the site allows that, off by default).
* Tools run as an administrator chosen on the site, so WordPress's own permissions and hooks apply.
* Personal data (email, phone, address, postcode, IP) is masked by default; secrets (passwords, keys, tokens, salts) are always returned as `***`.
* Optional IP lists for the site endpoint and the MCP endpoint.
* An email to the admin address after every admin-class call.

**Tools**

* Read: `list_sites`, `site_info`, `plugins_list`, `themes_list`, `option_get`, `db_tables`, `db_describe`, `db_select` (SELECT / SHOW / DESCRIBE / EXPLAIN only, with guards, optionally through a read-only database user), `rest_get`, `page_html`, `files_list`, `file_read`, `files_search`, `upload_read`, `logs_tail`, `cron_list`, `site_health`, `abilities_list`, `core_update_check`.
* Write: `rest_write` (the site's own REST API, so plugins validate and hook as usual), `option_set`, `media_upload_from_url`, `cache_flush`, `ability_run` (WordPress Abilities API, class taken from the ability's annotations).
* Admin: `plugins_install` (zip only from allowed URL prefixes, by default the noxpress GitHub releases and downloads.wordpress.org), `plugins_activate`, `plugins_deactivate`, `plugins_update`, `themes_update`, `noxpress_channel_set`.
* Never: writing files, SQL writes, user or password changes, reading wp-config.php, .env, keys or backups.

**Connections and audit**

* Access tokens last 1 hour, refresh tokens 30 days with rotation and reuse detection, and the approval 90 days. Approve per scope (read / write / admin) and per site.
* Claude identifies itself with a Client ID Metadata Document (CIMD). Dynamic client registration is off by default.
* 120 calls per minute and 10,000 per day per connection (settings).
* Every call is logged on the Hub and on the site, with arguments (secrets redacted), result, time and size. Filters and CSV export. The log is kept for 90 days (setting).
* Kill switch: revoke every connection at once, or `define( 'SBR_DISABLE', true );` in wp-config.php to close every endpoint.

**Before you install**

Site Bridge gives Claude remote administrator access to the sites you allow. Turn on only the roles you need, keep sites at read, and open the write window only while you work. It is provided as is, under the MIT license, with no warranty.

Works with Claude. Site Bridge is not a product of Anthropic and is not affiliated with Anthropic.

**Updates and support**

Updates arrive through the Noxpress hub like every other Noxpress plugin (beta channel until it is marked stable). It shares the "Noxpress" admin menu with the other Noxpress plugins. Support is through GitHub Issues at https://github.com/koulaxizis/noxpress/issues, with no guarantee of an answer.

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

= 1.3.0 =
* New: Site Bridge is no longer for personal use only. It updates through the Noxpress hub (updates.json) like every other Noxpress plugin. Sites on 1.2.0 install 1.3.0 by hand once.
* New: Overview says that the plugin gives remote administrator access and that it is not affiliated with Anthropic.
* New: a card on noxpress.tech, and Site Bridge in the Noxpress hub of every site (Noxpress Core 1.1.17).
* Security: db_select no longer shows a secret option or meta value under another row's name. A query that reads option_value or meta_value must be one plain SELECT (no UNION, WITH, subquery or derived table) with option_name / meta_key as plain columns of the same table. The mysql, performance_schema and sys schemas are refused.
* Security: plugins_install refuses zip URLs with dot segments, escapes, user info, query or fragment, so "/../" can no longer leave an allowed prefix.
* Security: file tools no longer serve archives and backup copies (.zip, .tar, .gz, .7z, .rar, .jpa, .bak, .old, .swp and the like).
* Security: the approval screen warns that an approval link sent by someone else connects their account. Pairing from the Hub no longer follows redirects or reaches private addresses (except local test installs).
* Change: new installs allow plugin zips only from the noxpress GitHub releases and downloads.wordpress.org (was every github.com/koulaxizis/ repository). Saved settings are kept.

= 1.2.0 =
* New: the Hub's Sites list opens a site's write window for 15, 30 or 60 minutes, 3 or 5 hours (was 30 minutes only). Sites still on 1.0.0 open 30 minutes for 3 and 5 hours; update them too.
* Fix: after opening or closing a remote site's window, the Sites list shows the new state at once (it waited for "Check").

= 1.1.0 =
* New: 3 and 5 hour write windows, next to 15, 30 and 60 minutes.

= 1.0.0 =
* Initial release (beta).
* Hub and Site roles in one plugin; pairing with a one-time code; Ed25519-signed Hub → Site requests and answers with timestamp and nonce.
* MCP endpoint (Streamable HTTP, JSON answers) with 30 tools: read, write and admin classes; fleet reads with `site: "all"`.
* OAuth 2.1 authorization server: PKCE, CIMD, optional DCR, refresh rotation with reuse detection, 90-day approval, consent page per scope and site.
* Per-site level, write window, run-as administrator, PII masking, IP lists, admin email, read-only SQL guards and optional read-only database user.
* Audit log on Hub and site with filters and CSV export; rate limits; kill switch and SBR_DISABLE.
* Diagnostics for HTTPS, permalinks, the Authorization header, OAuth metadata, keys and file modifications.
* Greek / English admin (Noxpress Core 1.1.9 menu).
