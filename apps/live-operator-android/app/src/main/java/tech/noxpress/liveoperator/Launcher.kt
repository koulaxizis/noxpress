package tech.noxpress.liveoperator

import android.os.Bundle
import com.google.androidbrowserhelper.trusted.LauncherActivity

/**
 * The Trusted Web Activity launcher of androidbrowserhelper, plus the
 * new-version check (1.2.0): every start makes sure it is scheduled.
 */
class Launcher : LauncherActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (BuildConfig.UPDATES) UpdateNotice.schedule(applicationContext)
    }
}
