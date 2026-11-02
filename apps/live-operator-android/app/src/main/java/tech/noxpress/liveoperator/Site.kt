package tech.noxpress.liveoperator

import android.content.Context
import org.json.JSONArray
import java.net.URI
import java.net.URLDecoder

/**
 * The site the app is connected to (2.0.0): one app for every site with the
 * Live Operator plugin. The address of its /live-operator/ page is kept in
 * the app's preferences; the setup screen ([Setup]) sets it, from a typed
 * address or from the plugin's «Connect the app» link
 * (liveoperator://connect?site=<page>). Only https.
 */
object Site {

    const val PATH = "/live-operator/"
    const val PACKAGE = "tech.noxpress.liveoperator"
    private const val PREFS = "lo_site"
    private const val KEY = "page"

    fun page(context: Context): String? =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, null)

    fun save(context: Context, page: String) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(KEY, page).apply()
    }

    /**
     * A typed address → the https address of the Live Operator page, or null.
     * "example.com", "https://example.com/" and "example.com/shop" get /live-operator/
     * added; an address that already is the page (or ?lop_app=page) stays as it is.
     */
    fun fromInput(text: String): String? {
        var t = text.trim()
        if (t.isEmpty() || t.any { it.isWhitespace() }) return null
        if (!t.contains("://")) t = "https://$t"
        val u = try { URI(t) } catch (e: Exception) { return null }
        if (!"https".equals(u.scheme, ignoreCase = true)) return null
        val host = u.host ?: return null
        if (host.isEmpty() || u.rawUserInfo != null || !host.contains('.') && host != "localhost") return null
        val port = if (u.port > 0 && u.port != 443) ":${u.port}" else ""
        val origin = "https://${host.lowercase()}$port"
        val path = (u.rawPath ?: "").ifEmpty { "/" }
        val query = u.rawQuery
        if (query != null && query.split('&').contains("lop_app=page")) return "$origin$path?$query"
        if (path.trimEnd('/').endsWith(PATH.trimEnd('/'))) return origin + path.trimEnd('/') + "/"
        return origin + path.trimEnd('/') + PATH
    }

    /** liveoperator://connect?site=<page> → the page address, or null. */
    fun fromDeepLink(link: String): String? {
        val u = try { URI(link) } catch (e: Exception) { return null }
        if (!"liveoperator".equals(u.scheme, ignoreCase = true) || !"connect".equals(u.host, ignoreCase = true)) return null
        val site = (u.rawQuery ?: return null).split('&')
            .firstOrNull { it.startsWith("site=") }
            ?.substringAfter('=')
            ?.let { try { URLDecoder.decode(it, "UTF-8") } catch (e: Exception) { null } }
            ?: return null
        return fromInput(site)
    }

    fun host(page: String): String = try { URI(page).host ?: page } catch (e: Exception) { page }

    fun assetlinksUrl(page: String): String {
        val u = URI(page)
        val port = if (u.port > 0) ":${u.port}" else ""
        return "https://${u.host}$port/.well-known/assetlinks.json"
    }

    /** True when the site's assetlinks.json lists [pkg] as an Android app. */
    fun listsApp(json: String, pkg: String = PACKAGE): Boolean = try {
        val a = JSONArray(json)
        (0 until a.length()).any { i ->
            val t = a.optJSONObject(i)?.optJSONObject("target")
            t != null && t.optString("namespace") == "android_app" && t.optString("package_name") == pkg
        }
    } catch (e: Exception) {
        false
    }

    /**
     * The address the app opens. With [version] the page gets ?app=<version>,
     * so the plugin can tell about a newer app (left out when a store that
     * updates the app installed it).
     */
    fun launchUrl(page: String, version: String?): String {
        if (version == null) return page
        val hash = page.indexOf('#')
        val base = if (hash >= 0) page.substring(0, hash) else page
        val frag = if (hash >= 0) page.substring(hash) else ""
        val sep = if (base.contains('?')) "&" else "?"
        return "$base${sep}app=$version$frag"
    }
}
