package tech.noxpress.liveoperator

import android.content.Context
import android.os.Build

/**
 * Who installed the app (2.0.0, as in the Site Bridge app 1.7.0). When an app store that follows the
 * Noxpress releases installed it (F-Droid, Droid-ify, Obtainium and their
 * variants), the store already tells about new versions, so the app's own
 * notice (the page's line and the notification) stays quiet. Installed from the APK in a
 * browser or file manager, the app tells as before.
 */
object Installer {

    /** Packages of the stores that update the app themselves. */
    val STORES = setOf(
        "org.fdroid.fdroid",
        "org.fdroid.basic",
        "org.fdroid.fdroid.privileged",
        "com.looker.droidify",
        "com.machiav3lli.fdroid",
        "dev.imranr.obtainium",
        "dev.imranr.obtainium.fdroid",
    )

    fun isStore(installer: String?): Boolean = installer != null && installer in STORES

    /** True when a store from [STORES] installed this app; false when unknown. */
    fun fromStore(context: Context): Boolean = isStore(installer(context))

    @Suppress("DEPRECATION")
    private fun installer(context: Context): String? = try {
        val pm = context.packageManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            pm.getInstallSourceInfo(context.packageName).installingPackageName
        } else {
            pm.getInstallerPackageName(context.packageName)
        }
    } catch (e: Exception) {
        null
    }
}
