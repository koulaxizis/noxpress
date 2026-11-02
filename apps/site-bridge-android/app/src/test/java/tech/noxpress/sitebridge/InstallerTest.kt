package tech.noxpress.sitebridge

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class InstallerTest {

    @Test
    fun storesThatUpdateTheAppAreQuiet() {
        assertTrue(Installer.isStore("org.fdroid.fdroid"))
        assertTrue(Installer.isStore("org.fdroid.basic"))
        assertTrue(Installer.isStore("com.looker.droidify"))
        assertTrue(Installer.isStore("dev.imranr.obtainium"))
    }

    @Test
    fun browsersFileManagersAndUnknownStillTell() {
        assertFalse(Installer.isStore(null))
        assertFalse(Installer.isStore(""))
        assertFalse(Installer.isStore("com.google.android.packageinstaller"))
        assertFalse(Installer.isStore("com.android.chrome"))
        assertFalse(Installer.isStore("com.android.vending"))
    }
}
