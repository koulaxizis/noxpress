package tech.noxpress.sitebridge

import android.os.Build
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

data class Site(
    val id: String,
    val label: String,
    val url: String,
    val self: Boolean,
    val level: String,
    val windowUntil: Long,
    val canOpen: Boolean,
    val reachable: Boolean,
    val error: String,
    /** Noxpress plugins the app can use on this site (Site Bridge 1.7.0+ on it), e.g. "book-feed". */
    val modules: List<String> = emptyList(),
)

/** An ability's answer, and the window the Hub opened first for it (0: none). */
class AbilityResult(val result: JSONObject, val windowOpened: Long)

data class LogRow(
    val ts: Long,
    val site: String,
    val tool: String,
    val status: String,
    val message: String,
    val client: String,
)

/** An answer of the Hub that is not "ok": code from the Hub ("clock", "need_act", ...) or "network". */
class HubError(val code: String, message: String) : Exception(message)

/**
 * Calls to the Hub. Every call except pairing is signed (see Protocol);
 * read calls with the read key, opening a window with the act key through
 * [actSigner], which shows the fingerprint prompt.
 */
class Api(private val store: Store) {

    suspend fun pair(code: Protocol.PairCode): String = withContext(Dispatchers.IO) {
        val (read, act) = Keys.create()
        val body = JSONObject()
            .put("t", code.token)
            .put("name", (Build.MANUFACTURER + " " + Build.MODEL).trim().take(60))
            .put("read", read)
            .put("act", act)
            .toString().toByteArray(Charsets.UTF_8)
        val sig = Keys.signRead(Protocol.pairMessage(code.token, body))
        val j = http("POST", code.base + "app/pair", body, mapOf("X-SBA-Sig" to sig))
        store.base = code.base
        store.device = j.getString("device")
        store.hub = j.optJSONObject("hub")?.optString("name").orEmpty().ifEmpty { code.hubName }
        store.offset = 0
        store.device
    }

    suspend fun sites(): Pair<List<Site>, List<Int>> {
        val j = signed("GET", "app/sites", null, null)
        val out = mutableListOf<Site>()
        val arr = j.optJSONArray("sites") ?: JSONArray()
        for (i in 0 until arr.length()) out.add(site(arr.getJSONObject(i)))
        val mins = mutableListOf<Int>()
        val m = j.optJSONArray("minutes") ?: JSONArray()
        for (i in 0 until m.length()) mins.add(m.getInt(i))
        return Pair(out, mins)
    }

    /** Opens (minutes > 0, needs [actSigner]) or closes (0) a site's window. */
    suspend fun window(siteId: String, minutes: Int, actSigner: (suspend (String) -> String)?): Site {
        val body = JSONObject().put("site", siteId).put("minutes", minutes)
        val j = signed("POST", "app/window", body, if (minutes > 0) actSigner else null)
        return site(j.getJSONObject("site"))
    }

    suspend fun log(siteId: String?): List<LogRow> {
        val q = if (siteId.isNullOrEmpty()) "?limit=50" else "?limit=50&site=$siteId"
        val j = signed("GET", "app/log$q", null, null)
        val arr = j.optJSONArray("rows") ?: JSONArray()
        return (0 until arr.length()).map {
            val r = arr.getJSONObject(it)
            LogRow(r.optLong("ts"), r.optString("site"), r.optString("tool"), r.optString("status"), r.optString("message"), r.optString("client"))
        }
    }

    /**
     * One allow-listed ability on one site, through the Hub. Read abilities
     * pass no [actSigner]; the others need it (fingerprint).
     */
    suspend fun ability(siteId: String, name: String, input: JSONObject, actSigner: (suspend (String) -> String)?): AbilityResult {
        val body = JSONObject().put("site", siteId).put("name", name).put("input", input)
        val j = signed("POST", "app/ability", body, actSigner)
        return AbilityResult(j.optJSONObject("result") ?: JSONObject(), j.optLong("window_opened"))
    }

    suspend fun forget() {
        signed("POST", "app/forget", JSONObject(), null)
    }

    private fun site(o: JSONObject) = Site(
        id = o.getString("id"),
        label = o.optString("label"),
        url = o.optString("url"),
        self = o.optBoolean("self"),
        level = o.optString("level"),
        windowUntil = o.optLong("window_until"),
        canOpen = o.optBoolean("can_open"),
        reachable = o.optBoolean("reachable", true),
        error = o.optString("error"),
        modules = o.optJSONArray("modules")?.let { a -> (0 until a.length()).map { a.optString(it) } } ?: emptyList(),
    )

    /**
     * One signed call. A "clock" refusal sets the offset from the Hub's
     * time and retries once; for the act key that means a second
     * fingerprint, which beats failing.
     * GETs carry a throwaway "_" parameter (not signed: the Hub signs the
     * route only), so a cache in front of the Hub can never answer with an
     * old body and an old "now".
     */
    private suspend fun signed(method: String, path: String, json: JSONObject?, actSigner: (suspend (String) -> String)?): JSONObject {
        val body = json?.toString()?.toByteArray(Charsets.UTF_8) ?: ByteArray(0)
        for (attempt in 0..1) {
            val ts = store.now()
            val nonce = Protocol.nonce()
            val msg = Protocol.message(method, path, store.device, ts, nonce, body)
            val url = store.base + path + if (method == "GET") (if ('?' in path) "&" else "?") + "_=" + Protocol.nonce() else ""
            val sig = if (actSigner != null) actSigner(msg) else withContext(Dispatchers.IO) { Keys.signRead(msg) }
            try {
                val j = withContext(Dispatchers.IO) {
                    http(
                        method,
                        url,
                        body,
                        mapOf(
                            "X-SBA-Device" to store.device,
                            "X-SBA-Ts" to ts.toString(),
                            "X-SBA-Nonce" to nonce,
                            "X-SBA-Key" to if (actSigner != null) "act" else "read",
                            "X-SBA-Sig" to sig,
                        ),
                    )
                }
                if (j.has("now")) store.offset = j.getLong("now") - System.currentTimeMillis() / 1000
                return j
            } catch (e: HubError) {
                if (e.code == "clock" && attempt == 0) continue
                throw e
            }
        }
        throw HubError("clock", "Clock")
    }

    private fun http(method: String, url: String, body: ByteArray, headers: Map<String, String>): JSONObject {
        val c = (URL(url).openConnection() as HttpURLConnection)
        try {
            c.requestMethod = method
            c.connectTimeout = 15000
            c.readTimeout = 60000
            c.instanceFollowRedirects = false
            c.setRequestProperty("Accept", "application/json")
            headers.forEach { (k, v) -> c.setRequestProperty(k, v) }
            if (method == "POST") {
                c.doOutput = true
                c.setRequestProperty("Content-Type", "application/json")
                c.outputStream.use { it.write(body) }
            }
            val code = c.responseCode
            val text = (if (code in 200..299) c.inputStream else c.errorStream)?.bufferedReader()?.use { it.readText() }.orEmpty()
            val j = try {
                JSONObject(text)
            } catch (e: Exception) {
                throw HubError("http", "HTTP $code")
            }
            if (code !in 200..299 || !j.optBoolean("ok")) {
                if (j.optString("error") == "clock" && j.has("now")) {
                    store.offset = j.getLong("now") - System.currentTimeMillis() / 1000
                    // How far off the request was, for the message (seconds, Hub minus request).
                    val sent = headers["X-SBA-Ts"]?.toLongOrNull()
                    if (sent != null) throw HubError("clock", (j.getLong("now") - sent).toString())
                }
                throw HubError(j.optString("error", "http"), j.optString("message", "HTTP $code"))
            }
            return j
        } catch (e: IOException) {
            throw HubError("network", e.message ?: "Network error")
        } finally {
            c.disconnect()
        }
    }
}
