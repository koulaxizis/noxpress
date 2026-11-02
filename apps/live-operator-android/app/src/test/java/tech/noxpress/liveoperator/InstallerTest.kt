package tech.noxpress.liveoperator

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class InstallerTest {

    @Test
    fun storesThatUpdateTheAppAreQuiet() {
        assertTrue(Installer.isStore("org.fdroid.fdroid"))
        assertTrue(Installer.isStore("com.looker.droidify"))
        assertTrue(Installer.isStore("dev.imranr.obtainium"))
    }

    @Test
    fun browsersFileManagersAndUnknownStillTell() {
        assertFalse(Installer.isStore(null))
        assertFalse(Installer.isStore("com.google.android.packageinstaller"))
        assertFalse(Installer.isStore("com.android.chrome"))
    }
}
