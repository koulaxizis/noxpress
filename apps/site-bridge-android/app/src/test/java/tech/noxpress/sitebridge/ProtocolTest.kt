package tech.noxpress.sitebridge

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import java.security.spec.X509EncodedKeySpec
import java.util.Base64

class ProtocolTest {

    // Made the way SBR_App::new_code() makes it (unpadded base64url JSON).
    private val code = "sba1.eyJ1IjoiaHR0cHM6Ly9odWIuZXhhbXBsZS93cC1qc29uL3NpdGUtYnJpZGdlL3YxLyIsInQiOiJhYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5ejAxMjM0NTY3ODkwMTIzNCIsIngiOjE3NjEwMzAwMDAsIm4iOiLOk867zrHPgc-MzrvPhc66zr_OuSJ9"

    @Test
    fun decodesAPairingCode() {
        val c = Protocol.decodeCode("  $code\n")
        assertNotNull(c)
        assertEquals("https://hub.example/wp-json/site-bridge/v1/", c!!.base)
        assertEquals("abcdefghijklmnopqrstuvwxyz012345678901234", c.token)
        assertEquals(1761030000L, c.expires)
        assertEquals("Γλαρόλυκοι", c.hubName)
    }

    @Test
    fun refusesOtherCodes() {
        assertNull(Protocol.decodeCode("sbr1.eyJ1IjoiIn0"))
        assertNull(Protocol.decodeCode("sba1.@@@"))
        assertNull(Protocol.decodeCode("hello"))
        // http only in debug builds, and only to the Site Bridge namespace.
        val http = "sba1." + Base64.getUrlEncoder().withoutPadding()
            .encodeToString("""{"u":"http://10.0.2.2:8089/wp-json/site-bridge/v1/","t":"0123456789abcdefghij","x":1}""".toByteArray())
        assertNull(Protocol.decodeCode(http))
        assertNotNull(Protocol.decodeCode(http, allowHttp = true))
        val other = "sba1." + Base64.getUrlEncoder().withoutPadding()
            .encodeToString("""{"u":"https://evil.example/api/","t":"0123456789abcdefghij","x":1}""".toByteArray())
        assertNull(Protocol.decodeCode(other))
    }

    @Test
    fun buildsTheSignedLine() {
        // Same line SBR_App::auth() rebuilds from WP_REST_Request::get_route().
        val m = Protocol.message("GET", "app/log?limit=50&site=x", "0123456789abcdef", 1761030000L, "nonce_nonce_nonce", ByteArray(0))
        assertEquals(
            "SBA1|GET|/site-bridge/v1/app/log|0123456789abcdef|1761030000|nonce_nonce_nonce|e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            m,
        )
        assertEquals(
            "SBA1|PAIR|tok|" + Protocol.sha256Hex("{}".toByteArray()),
            Protocol.pairMessage("tok", "{}".toByteArray()),
        )
    }

    @Test
    fun noncesMatchTheHubPattern() {
        repeat(50) { assertTrue(Regex("^[A-Za-z0-9_-]{16,64}$").matches(Protocol.nonce())) }
    }

    @Test
    fun keyAndSignatureFormatsAreWhatTheHubReads() {
        // The Keystore gives the same formats: X.509 SubjectPublicKeyInfo DER and DER ECDSA.
        val g = KeyPairGenerator.getInstance("EC")
        g.initialize(ECGenParameterSpec("secp256r1"))
        val kp = g.generateKeyPair()
        val pub = Protocol.b64(kp.public.encoded)
        val s = Signature.getInstance("SHA256withECDSA")
        s.initSign(kp.private)
        s.update("line".toByteArray())
        val sig = Protocol.b64(s.sign())
        val v = Signature.getInstance("SHA256withECDSA")
        v.initVerify(KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(Base64.getDecoder().decode(pub))))
        v.update("line".toByteArray())
        assertTrue(v.verify(Base64.getDecoder().decode(sig)))
    }

    @Test
    fun countdown() {
        assertEquals("4:59", Protocol.countdown(299))
        assertEquals("1:05:09", Protocol.countdown(3909))
        assertEquals("0:00", Protocol.countdown(-5))
    }
}
