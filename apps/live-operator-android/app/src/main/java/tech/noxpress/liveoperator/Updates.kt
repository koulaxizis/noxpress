package tech.noxpress.liveoperator

import org.json.JSONArray
import java.net.HttpURLConnection
import java.net.URL

/**
 * A newer signed app on GitHub: the newest "nox-loa-*" release of the
 * noxpress repo (they are pre-releases, so not /releases/latest). Asked at
 * most every EVERY seconds, without a token (GitHub allows 60 an hour).
 */
object Updates {

    private const val API = "https://api.github.com/repos/koulaxizis/noxpress/releases?per_page=50"
    private const val PREFIX = "nox-loa-"
    const val EVERY = 12 * 3600L

    class Found(val version: String, val url: String)

    /** Network; call off the main thread. Null when nothing is newer than [current]. */
    fun check(current: String): Found? {
        val c = URL(API).openConnection() as HttpURLConnection
        try {
            c.connectTimeout = 15000
            c.readTimeout = 30000
            c.setRequestProperty("Accept", "application/vnd.github+json")
            if (c.responseCode != 200) return null
            return pick(c.inputStream.bufferedReader().use { it.readText() }, current)
        } finally {
            c.disconnect()
        }
    }

    /** The newest release above [current] in a GitHub releases list: its APK, or its page. */
    fun pick(json: String, current: String): Found? {
        val a = JSONArray(json)
        var best: Found? = null
        for (i in 0 until a.length()) {
            val r = a.getJSONObject(i)
            val tag = r.optString("tag_name")
            if (!tag.startsWith(PREFIX) || r.optBoolean("draft")) continue
            val v = tag.removePrefix(PREFIX)
            if (!newer(v, best?.version ?: current)) continue
            var url = r.optString("html_url")
            val assets = r.optJSONArray("assets")
            if (assets != null) {
                for (j in 0 until assets.length()) {
                    val x = assets.getJSONObject(j)
                    if (x.optString("name").endsWith(".apk")) {
                        url = x.optString("browser_download_url")
                        break
                    }
                }
            }
            best = Found(v, url)
        }
        return best
    }

    /** True when version [a] (e.g. "1.10.0") is above [b]; a malformed [a] never is. */
    fun newer(a: String, b: String): Boolean {
        val x = a.split('.').map { it.toIntOrNull() ?: return false }
        val y = b.split('.').map { it.toIntOrNull() ?: 0 }
        for (i in 0 until maxOf(x.size, y.size)) {
            val p = x.getOrElse(i) { 0 }
            val q = y.getOrElse(i) { 0 }
            if (p != q) return p > q
        }
        return false
    }
}
