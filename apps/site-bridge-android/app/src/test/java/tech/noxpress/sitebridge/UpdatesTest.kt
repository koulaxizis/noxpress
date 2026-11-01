package tech.noxpress.sitebridge

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class UpdatesTest {

    @Test
    fun comparesVersionsAsNumbers() {
        assertTrue(Updates.newer("1.10.0", "1.9.3"))
        assertTrue(Updates.newer("2.0.0", "1.99.99"))
        assertFalse(Updates.newer("1.3.0", "1.3.0"))
        assertFalse(Updates.newer("1.2.9", "1.3.0"))
        assertFalse(Updates.newer("1.4.0-rc", "1.3.0"))
    }

    @Test
    fun picksTheNewestAppReleaseAndItsApk() {
        val json = """[
          {"tag_name":"nox-bf-9.0.0","draft":false,"html_url":"x","assets":[]},
          {"tag_name":"nox-sba-1.4.0","draft":false,"html_url":"https://g/1.4.0","assets":[{"name":"site-bridge.apk.sha256","browser_download_url":"s"},{"name":"site-bridge.apk","browser_download_url":"https://g/1.4.0.apk"}]},
          {"tag_name":"nox-sba-1.5.0","draft":true,"html_url":"d","assets":[]},
          {"tag_name":"nox-sba-1.3.0","draft":false,"html_url":"o","assets":[]}
        ]"""
        val f = Updates.pick(json, "1.3.0")
        assertEquals("1.4.0", f?.version)
        assertEquals("https://g/1.4.0.apk", f?.url)
        assertNull(Updates.pick(json, "1.4.0"))
    }

    @Test
    fun findsThePageWithAppReleases() {
        val plugins = """[{"tag_name":"nox-bf-9.0.0","draft":false},{"tag_name":"nox-rs-1.0.0","draft":false}]"""
        val app = """[{"tag_name":"nox-bf-9.0.0","draft":false},{"tag_name":"nox-sba-1.0.0","draft":false}]"""
        val draft = """[{"tag_name":"nox-sba-2.0.0","draft":true}]"""
        assertFalse(Updates.hasApp(plugins))
        assertTrue(Updates.hasApp(app))
        assertFalse(Updates.hasApp(draft))
        assertFalse(Updates.hasApp("[]"))
    }
}
