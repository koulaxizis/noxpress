package tech.noxpress.sitebridge

import android.annotation.SuppressLint
import android.app.PendingIntent
import android.content.Intent
import android.os.Build
import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

/**
 * Quick Settings tile for one site (picked in the app's Settings).
 * Closed → tap opens the app, which asks for the fingerprint and opens the
 * window with the length picked last (a tile cannot show the prompt).
 * Open → tap closes it at once (closing needs no fingerprint).
 */
class BridgeTile : TileService() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private lateinit var store: Store

    override fun onCreate() {
        super.onCreate()
        store = Store(this)
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    override fun onStartListening() {
        super.onStartListening()
        draw()
        if (!store.paired || store.tileSite.isEmpty()) return
        scope.launch {
            try {
                val site = Api(store).sites().first.firstOrNull { it.id == store.tileSite }
                store.tileUntil = site?.windowUntil ?: 0
            } catch (e: Exception) {
                // Offline: keep the last known state.
            }
            draw()
        }
    }

    override fun onClick() {
        super.onClick()
        if (!store.paired || store.tileSite.isEmpty()) {
            launchApp(null)
            return
        }
        if (store.tileUntil > store.now()) {
            scope.launch {
                try {
                    val site = Api(store).window(store.tileSite, 0, null)
                    store.tileUntil = site.windowUntil
                } catch (e: Exception) {
                    launchApp(null)
                }
                draw()
            }
        } else {
            launchApp(MainActivity.ACTION_TILE_OPEN)
        }
    }

    @SuppressLint("StartActivityAndCollapseDeprecated")
    private fun launchApp(action: String?) {
        val i = Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        if (action != null) i.action = action
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startActivityAndCollapse(PendingIntent.getActivity(this, 0, i, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT))
        } else {
            @Suppress("DEPRECATION")
            startActivityAndCollapse(i)
        }
    }

    private fun draw() {
        val t = qsTile ?: return
        val open = store.tileUntil > store.now()
        t.label = store.tileLabel.ifEmpty { getString(R.string.app_name) }
        // Never "unavailable": a tap must still open the app to pick a site.
        t.state = if (open) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            t.subtitle = when {
                !store.paired || store.tileSite.isEmpty() -> getString(R.string.tile_pick)
                open -> getString(R.string.tile_open_sub, Ui.time(store.tileUntil))
                else -> getString(R.string.closed)
            }
        }
        t.updateTile()
    }
}
