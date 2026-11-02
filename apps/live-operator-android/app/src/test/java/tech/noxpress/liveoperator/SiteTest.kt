package tech.noxpress.liveoperator

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class SiteTest {

    @Test
    fun typedAddressesBecomeThePage() {
        assertEquals("https://example.com/live-operator/", Site.fromInput("example.com"))
        assertEquals("https://example.com/live-operator/", Site.fromInput("  https://Example.com/ "))
        assertEquals("https://example.com/live-operator/", Site.fromInput("https://example.com/live-operator"))
        assertEquals("https://example.com/live-operator/", Site.fromInput("https://example.com/live-operator/"))
        assertEquals("https://example.com/shop/live-operator/", Site.fromInput("example.com/shop/"))
        assertEquals("https://example.com:8443/live-operator/", Site.fromInput("https://example.com:8443"))
        assertEquals("https://example.com/live-operator/", Site.fromInput("https://example.com:443/"))
        assertEquals("https://example.com/?lop_app=page", Site.fromInput("https://example.com/?lop_app=page"))
        assertEquals("https://example.com/live-operator/", Site.fromInput("https://example.com/#x"))
    }

    @Test
    fun badAddressesAreRefused() {
        assertNull(Site.fromInput(""))
        assertNull(Site.fromInput("http://example.com"))
        assertNull(Site.fromInput("ftp://example.com"))
        assertNull(Site.fromInput("javascript:alert(1)"))
        assertNull(Site.fromInput("https://user:pw@example.com"))
        assertNull(Site.fromInput("not a site"))
        assertNull(Site.fromInput("intranet"))
    }

    @Test
    fun theConnectLink() {
        assertEquals(
            "https://glarolykoi.net/live-operator/",
            Site.fromDeepLink("liveoperator://connect?site=https%3A%2F%2Fglarolykoi.net%2Flive-operator%2F"),
        )
        assertEquals("https://example.com/?lop_app=page", Site.fromDeepLink("liveoperator://connect?site=https%3A%2F%2Fexample.com%2F%3Flop_app%3Dpage"))
        assertNull(Site.fromDeepLink("liveoperator://connect?site=http%3A%2F%2Fexample.com%2F"))
        assertNull(Site.fromDeepLink("liveoperator://other?site=https%3A%2F%2Fexample.com%2F"))
        assertNull(Site.fromDeepLink("https://example.com/?site=https%3A%2F%2Fexample.com%2F"))
        assertNull(Site.fromDeepLink("liveoperator://connect"))
    }

    @Test
    fun assetlinks() {
        assertEquals("https://example.com/.well-known/assetlinks.json", Site.assetlinksUrl("https://example.com/shop/live-operator/"))
        assertEquals("https://example.com:8443/.well-known/assetlinks.json", Site.assetlinksUrl("https://example.com:8443/live-operator/"))
        val ok = """[{"relation":["delegate_permission/common.handle_all_urls"],"target":{"namespace":"android_app","package_name":"tech.noxpress.liveoperator","sha256_cert_fingerprints":["21:44"]}}]"""
        assertTrue(Site.listsApp(ok))
        assertFalse(Site.listsApp(ok.replace("tech.noxpress.liveoperator", "com.other")))
        assertFalse(Site.listsApp(ok.replace("android_app", "web")))
        assertFalse(Site.listsApp("<html>"))
        assertFalse(Site.listsApp("[]"))
    }

    @Test
    fun launchUrlTellsTheVersion() {
        assertEquals("https://example.com/live-operator/?app=2.0.0", Site.launchUrl("https://example.com/live-operator/", "2.0.0"))
        assertEquals("https://example.com/?lop_app=page&app=2.0.0", Site.launchUrl("https://example.com/?lop_app=page", "2.0.0"))
        assertEquals("https://example.com/live-operator/", Site.launchUrl("https://example.com/live-operator/", null))
    }
}
