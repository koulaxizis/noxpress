package tech.noxpress.sitebridge

import android.content.Context
import android.content.SharedPreferences

/**
 * Plain settings (no secrets: the keys live in the Keystore).
 *  base       the Hub's REST base, ".../wp-json/site-bridge/v1/"
 *  device     the device id the Hub gave at pairing
 *  hub        the Hub's name
 *  offset     Hub clock minus phone clock, seconds
 *  minutes    the window length picked last
 *  tile_*     the site of the Quick Settings tile and its last known state
 */
class Store(context: Context) {

    private val p: SharedPreferences = context.getSharedPreferences("sba", Context.MODE_PRIVATE)

    var base: String
        get() = p.getString("base", "") ?: ""
        set(v) = p.edit().putString("base", v).apply()

    var device: String
        get() = p.getString("device", "") ?: ""
        set(v) = p.edit().putString("device", v).apply()

    var hub: String
        get() = p.getString("hub", "") ?: ""
        set(v) = p.edit().putString("hub", v).apply()

    var offset: Long
        get() = p.getLong("offset", 0L)
        set(v) = p.edit().putLong("offset", v).apply()

    var minutes: Int
        get() = p.getInt("minutes", 30)
        set(v) = p.edit().putInt("minutes", v).apply()

    var tileSite: String
        get() = p.getString("tile_site", "") ?: ""
        set(v) = p.edit().putString("tile_site", v).apply()

    var tileLabel: String
        get() = p.getString("tile_label", "") ?: ""
        set(v) = p.edit().putString("tile_label", v).apply()

    var tileUntil: Long
        get() = p.getLong("tile_until", 0L)
        set(v) = p.edit().putLong("tile_until", v).apply()

    val paired: Boolean get() = base.isNotEmpty() && device.isNotEmpty()

    fun now(): Long = System.currentTimeMillis() / 1000 + offset

    fun clear() {
        p.edit().clear().apply()
    }
}
