package tech.noxpress.liveoperator

import android.net.Uri
import com.google.androidbrowserhelper.trusted.LauncherActivity

/**
 * The Trusted Web Activity launcher of androidbrowserhelper, opening the
 * connected site's Live Operator page (2.0.0: the site is kept by [Setup],
 * which is the app's entry and the only one that starts this). The page
 * gets ?app=<version> unless a store that updates the app installed it.
 */
class Launcher : LauncherActivity() {

    override fun getLaunchingUrl(): Uri {
        val page = Site.page(this) ?: return Uri.parse(HELP)
        val version = if (Installer.fromStore(this)) null else BuildConfig.VERSION_NAME
        return Uri.parse(Site.launchUrl(page, version))
    }

    private companion object {
        const val HELP = "https://noxpress.tech/#live-operator-app"
    }
}
