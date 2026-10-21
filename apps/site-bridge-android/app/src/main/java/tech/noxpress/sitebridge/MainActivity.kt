package tech.noxpress.sitebridge

import android.content.ComponentName
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.service.quicksettings.TileService
import androidx.activity.compose.setContent
import androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_STRONG
import androidx.biometric.BiometricManager.Authenticators.DEVICE_CREDENTIAL
import androidx.biometric.BiometricPrompt
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.lifecycleScope
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/** What the screens show. */
class UiState {
    var paired by mutableStateOf(false)
    var tab by mutableIntStateOf(0)
    var busy by mutableStateOf(false)
    var message by mutableStateOf<String?>(null)
    var sites by mutableStateOf<List<Site>>(emptyList())
    var minutes by mutableStateOf(listOf(15, 30, 60, 180, 300, 720))
    var picked by mutableIntStateOf(30)
    var log by mutableStateOf<List<LogRow>>(emptyList())
    var logSite by mutableStateOf("")
    var tileSite by mutableStateOf("")
}

class MainActivity : FragmentActivity() {

    companion object {
        /** Sent by the Quick Settings tile: open the tile's site after the fingerprint. */
        const val ACTION_TILE_OPEN = "tech.noxpress.sitebridge.TILE_OPEN"
    }

    private lateinit var store: Store
    private lateinit var api: Api
    private val ui = UiState()
    private var pendingTileOpen = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = Store(this)
        api = Api(store)
        ui.paired = store.paired && Keys.exists()
        ui.picked = store.minutes
        ui.tileSite = store.tileSite
        pendingTileOpen = intent?.action == ACTION_TILE_OPEN
        setContent {
            SiteBridgeApp(
                ui = ui,
                store = store,
                onScan = ::scan,
                onPaste = ::pairWith,
                onRefresh = ::refresh,
                onPick = { m -> ui.picked = m; store.minutes = m },
                onOpen = { s -> setWindow(s, ui.picked) },
                onClose = { s -> setWindow(s, 0) },
                onLog = ::loadLog,
                onTile = ::setTile,
                onDisconnect = ::disconnect,
                onDismiss = { ui.message = null },
            )
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (intent.action == ACTION_TILE_OPEN) {
            pendingTileOpen = true
            refresh()
        }
    }

    override fun onResume() {
        super.onResume()
        if (ui.paired) refresh()
    }

    /* ----- Pairing ----- */

    private fun scan() {
        val opts = GmsBarcodeScannerOptions.Builder().setBarcodeFormats(Barcode.FORMAT_QR_CODE).build()
        GmsBarcodeScanning.getClient(this, opts).startScan()
            .addOnSuccessListener { b -> b.rawValue?.let { pairWith(it) } }
            .addOnFailureListener { e -> ui.message = getString(R.string.err_scan, e.message ?: "") }
    }

    private fun pairWith(raw: String) {
        val code = Protocol.decodeCode(raw, allowHttp = BuildConfig.DEBUG)
        if (code == null) {
            ui.message = getString(R.string.bad_code)
            return
        }
        if (code.expires > 0 && code.expires < System.currentTimeMillis() / 1000 - 300) {
            ui.message = getString(R.string.code_expired)
            return
        }
        call {
            api.pair(code)
            ui.paired = true
            ui.tab = 0
            refreshNow()
        }
    }

    /* ----- Sites and window ----- */

    private fun refresh() {
        call { refreshNow() }
    }

    private suspend fun refreshNow() {
        val (sites, mins) = api.sites()
        ui.sites = sites
        if (mins.isNotEmpty()) {
            ui.minutes = mins
            if (ui.picked !in mins) ui.picked = if (30 in mins) 30 else mins.first()
        }
        sites.firstOrNull { it.id == store.tileSite }?.let { store.tileUntil = it.windowUntil }
        updateTile()
        if (pendingTileOpen) {
            pendingTileOpen = false
            sites.firstOrNull { it.id == store.tileSite && it.canOpen }?.let { setWindow(it, ui.picked) }
        }
    }

    private fun setWindow(site: Site, minutes: Int) {
        call {
            val fresh = api.window(site.id, minutes, if (minutes > 0) ::actSign else null)
            ui.sites = ui.sites.map { if (it.id == fresh.id) fresh else it }
            if (fresh.id == store.tileSite) store.tileUntil = fresh.windowUntil
            updateTile()
            ui.message = if (minutes > 0) getString(R.string.opened, Ui.time(fresh.windowUntil)) else getString(R.string.closed_msg)
        }
    }

    /** The act key's signature, after the fingerprint (or screen lock) prompt. */
    private suspend fun actSign(message: String): String = suspendCancellableCoroutine { cont ->
        val sig = try {
            Keys.actSignature()
        } catch (e: KeyPermanentlyInvalidatedException) {
            cont.resumeWithException(HubError("invalidated", getString(R.string.err_invalidated)))
            return@suspendCancellableCoroutine
        } catch (e: Exception) {
            cont.resumeWithException(HubError("key", e.message ?: "Key error"))
            return@suspendCancellableCoroutine
        }
        val prompt = BiometricPrompt(
            this,
            ContextCompat.getMainExecutor(this),
            object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                    val s = result.cryptoObject?.signature
                    if (!cont.isActive) return
                    if (s == null) {
                        cont.resumeWithException(HubError("key", "No signature"))
                        return
                    }
                    try {
                        cont.resume(Keys.finish(s, message))
                    } catch (e: Exception) {
                        cont.resumeWithException(HubError("key", e.message ?: "Key error"))
                    }
                }

                override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                    if (cont.isActive) cont.resumeWithException(HubError("cancel", errString.toString()))
                }
            },
        )
        val info = BiometricPrompt.PromptInfo.Builder()
            .setTitle(getString(R.string.prompt_title))
            .setSubtitle(getString(R.string.prompt_sub, store.hub))
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            info.setAllowedAuthenticators(BIOMETRIC_STRONG or DEVICE_CREDENTIAL)
        } else {
            info.setAllowedAuthenticators(BIOMETRIC_STRONG)
            info.setNegativeButtonText(getString(R.string.cancel))
        }
        prompt.authenticate(info.build(), BiometricPrompt.CryptoObject(sig))
        cont.invokeOnCancellation { prompt.cancelAuthentication() }
    }

    /* ----- Log, tile, disconnect ----- */

    private fun loadLog(site: String) {
        ui.logSite = site
        call { ui.log = api.log(site) }
    }

    private fun setTile(site: Site?) {
        store.tileSite = site?.id ?: ""
        store.tileLabel = site?.label ?: ""
        store.tileUntil = site?.windowUntil ?: 0
        ui.tileSite = store.tileSite
        updateTile()
    }

    private fun updateTile() {
        TileService.requestListeningState(this, ComponentName(this, BridgeTile::class.java))
    }

    private fun disconnect() {
        lifecycleScope.launch {
            ui.busy = true
            try {
                api.forget()
            } catch (e: Exception) {
                // The Hub may be unreachable or the device already revoked: forget locally anyway.
            }
            Keys.delete()
            store.clear()
            ui.paired = false
            ui.sites = emptyList()
            ui.log = emptyList()
            ui.tileSite = ""
            ui.busy = false
            updateTile()
        }
    }

    /** Runs a Hub call with the busy flag and turns errors into a message. */
    private fun call(block: suspend () -> Unit) {
        lifecycleScope.launch {
            ui.busy = true
            try {
                block()
            } catch (e: HubError) {
                ui.message = explain(e)
                if (e.code == "unknown_device" || e.code == "invalidated") {
                    Keys.delete()
                    store.clear()
                    ui.paired = false
                }
            } catch (e: Exception) {
                ui.message = e.message ?: e.toString()
            } finally {
                ui.busy = false
            }
        }
    }

    private fun explain(e: HubError): String? = when (e.code) {
        "cancel" -> null
        "network" -> getString(R.string.err_network, e.message ?: "")
        "clock" -> getString(R.string.err_clock, e.message ?: "?")
        "unknown_device" -> getString(R.string.err_unknown_device)
        "app_off" -> getString(R.string.err_app_off)
        "remote_window" -> getString(R.string.cannot_open)
        else -> e.message
    }
}
