package tech.noxpress.sitebridge

import org.json.JSONObject
import java.security.MessageDigest
import java.security.SecureRandom
import java.util.Base64

/**
 * The SBA1 protocol of Site Bridge's app routes (plugin 1.6.0+,
 * includes/class-app.php). Pure functions, tested on the JVM.
 *
 * Request signature, SHA256withECDSA (DER, base64):
 *   SBA1|<METHOD>|<route>|<device id>|<unix time>|<nonce>|<sha256 hex of body>
 * Pairing proof (read key):
 *   SBA1|PAIR|<code token>|<sha256 hex of body>
 */
object Protocol {

    const val NAMESPACE = "/site-bridge/v1/"

    /** A pairing code from the Hub's App tab: "sba1." + base64url(JSON). */
    data class PairCode(val base: String, val token: String, val expires: Long, val hubName: String)

    /** Decodes a pairing code, or null when it is not one. */
    fun decodeCode(raw: String, allowHttp: Boolean = false): PairCode? {
        val code = raw.trim()
        if (!code.startsWith("sba1.")) return null
        return try {
            val json = JSONObject(String(Base64.getUrlDecoder().decode(code.substring(5)), Charsets.UTF_8))
            var base = json.getString("u")
            val token = json.getString("t")
            if (!base.endsWith("/")) base += "/"
            val okScheme = base.startsWith("https://") || (allowHttp && base.startsWith("http://"))
            if (!okScheme || !base.endsWith(NAMESPACE) || token.length < 16) null
            else PairCode(base, token, json.optLong("x", 0L), json.optString("n", ""))
        } catch (e: Exception) {
            null
        }
    }

    fun sha256Hex(body: ByteArray): String =
        MessageDigest.getInstance("SHA-256").digest(body).joinToString("") { "%02x".format(it) }

    /** The signed line of a request; path is relative to the namespace, e.g. "app/sites". */
    fun message(method: String, path: String, device: String, ts: Long, nonce: String, body: ByteArray): String =
        listOf("SBA1", method, NAMESPACE + path.substringBefore('?'), device, ts.toString(), nonce, sha256Hex(body))
            .joinToString("|")

    fun pairMessage(token: String, body: ByteArray): String = "SBA1|PAIR|$token|${sha256Hex(body)}"

    /** 24 random bytes, base64url without padding (32 characters). */
    fun nonce(): String {
        val b = ByteArray(24)
        SecureRandom().nextBytes(b)
        return Base64.getUrlEncoder().withoutPadding().encodeToString(b)
    }

    fun b64(bytes: ByteArray): String = Base64.getEncoder().encodeToString(bytes)

    /** Remaining time as "1:05:09" or "4:59". */
    fun countdown(seconds: Long): String {
        val s = seconds.coerceAtLeast(0)
        val h = s / 3600
        val m = (s % 3600) / 60
        val sec = s % 60
        return if (h > 0) "%d:%02d:%02d".format(h, m, sec) else "%d:%02d".format(m, sec)
    }
}
