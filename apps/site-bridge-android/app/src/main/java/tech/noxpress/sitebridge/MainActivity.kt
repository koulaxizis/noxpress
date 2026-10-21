package tech.noxpress.sitebridge

import android.Manifest
import android.content.ComponentName
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.service.quicksettings.TileService
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import org.json.JSONObject
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/** What the screens show. */
class UiState {
    var paired by mutableStateOf(false)
    /** sites | bf | log | settings */
    var tab by mutableStateOf("sites")
    var busy by mutableStateOf(false)
    var message by mutableStateOf<String?>(null)
    var sites by mutableStateOf<List<Site>>(emptyList())
    var minutes by mutableStateOf(listOf(15, 30, 60, 180, 300, 720))
    var picked by mutableIntStateOf(30)
    var log by mutableStateOf<List<LogRow>>(emptyList())
    var logSite by mutableStateOf("")
    var tileSite by mutableStateOf("")

    // Book Feed tab (1.2.0)
    var bfSite by mutableStateOf("")
    var bfStatus by mutableStateOf<JSONObject?>(null)
    var bfRules by mutableStateOf<List<JSONObject>>(emptyList())
    var bfKinds by mutableStateOf<List<String>>(emptyList())
    var bfStatuses by mutableStateOf<List<String>>(emptyList())
    var bfPubs by mutableStateOf<List<Pair<String, String>>>(emptyList())
    var bfIsbn by mutableStateOf<JSONObject?>(null)
    var bfPreview by mutableStateOf<JSONObject?>(null)
    var bfNotify by mutableStateOf(false)
    var update by mutableStateOf<Updates.Found?>(null)
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

    private val askNotify = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        setNotify(granted)
        if (!granted) ui.message = getString(R.string.bf_notify_denied)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = Store(this)
        api = Api(store)
        ui.paired = store.paired && Keys.exists()
        ui.picked = store.minutes
        ui.tileSite = store.tileSite
        ui.bfNotify = store.bfNotify && Notify.allowed(this)
        pendingTileOpen = intent?.action == ACTION_TILE_OPEN
        if (intent?.action == Notify.ACTION_BF) ui.tab = "bf"
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
                bf = BfActions(
                    load = ::bfLoad,
                    sync = { bfAct("book-feed/sync-start", JSONObject(), R.string.bf_confirm_sync) },
                    stop = { bfAct("book-feed/sync-cancel", JSONObject(), R.string.bf_confirm_stop) },
                    runRule = { id -> bfAct("book-feed/sync-start", JSONObject().put("rule", id), R.string.bf_confirm_run) },
                    saveRule = { r -> bfAct("book-feed/rule-save", r, R.string.bf_confirm_save) },
                    deleteRule = { id -> bfAct("book-feed/rule-delete", JSONObject().put("id", id), R.string.bf_confirm_delete) },
                    publishers = ::bfPublishers,
                    lookup = ::bfLookup,
                    scan = ::bfScan,
                    importIsbn = { isbn -> bfAct("book-feed/import", JSONObject().put("isbn", isbn), R.string.bf_confirm_import) { bfLookup(isbn) } },
                    notify = ::askNotifications,
                    preview = ::bfPreview,
                ),
            )
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (intent.action == ACTION_TILE_OPEN) {
            pendingTileOpen = true
            refresh()
        }
        if (intent.action == Notify.ACTION_BF && ui.paired) ui.tab = "bf"
    }

    override fun onResume() {
        super.onResume()
        if (ui.paired) refresh()
        checkUpdate()
    }

    /** A newer signed app on GitHub, looked up at most every 12 hours; never an error on screen. */
    private fun checkUpdate() {
        val current = BuildConfig.VERSION_NAME
        if (store.updVer.isNotEmpty() && Updates.newer(store.updVer, current)) ui.update = Updates.Found(store.updVer, store.updUrl)
        val now = System.currentTimeMillis() / 1000
        if (now - store.updAt < Updates.EVERY) return
        store.updAt = now
        lifecycleScope.launch {
            val f = withContext(Dispatchers.IO) { runCatching { Updates.check(current) }.getOrNull() }
            store.updVer = f?.version.orEmpty()
            store.updUrl = f?.url.orEmpty()
            ui.update = f
        }
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
            ui.tab = "sites"
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
        val bf = sites.filter { "book-feed" in it.modules }
        store.bfSites = bf.associate { it.id to it.label }
        if (bf.none { it.id == ui.bfSite }) {
            ui.bfSite = bf.firstOrNull()?.id ?: ""
            ui.bfStatus = null
            ui.bfRules = emptyList()
        }
        if (bf.isEmpty() && ui.tab == "bf") ui.tab = "sites"
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
    private suspend fun actSign(message: String): String = actSignAs(getString(R.string.prompt_title), message)

    private suspend fun actSignAs(title: String, message: String): String = suspendCancellableCoroutine { cont ->
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
            .setTitle(title)
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

    /* ----- Book Feed ----- */

    private fun bfLoad(site: String) {
        if (site.isEmpty()) return
        if (site != ui.bfSite) {
            ui.bfSite = site
            ui.bfStatus = null
            ui.bfRules = emptyList()
            ui.bfIsbn = null
        }
        call {
            ui.bfStatus = api.ability(site, "book-feed/status", JSONObject(), null).result
            ui.bfRules = list(api.ability(site, "book-feed/rules", JSONObject(), null).result, "rules")
            if (ui.bfStatuses.isEmpty()) {
                val o = api.ability(site, "book-feed/options", JSONObject(), null).result
                ui.bfKinds = strings(o, "kinds")
                ui.bfStatuses = strings(o, "statuses")
            }
        }
    }

    /** A Book Feed action that changes the site: fingerprint first, then a fresh status. */
    private fun bfAct(name: String, input: JSONObject, prompt: Int, after: (() -> Unit)? = null) {
        val site = ui.bfSite
        if (site.isEmpty()) return
        val label = ui.sites.firstOrNull { it.id == site }?.label ?: site
        call {
            val signer: suspend (String) -> String = { m -> actSignAs(getString(prompt), m) }
            val r = api.ability(site, name, input, signer)
            if (r.result.has("rules")) ui.bfRules = list(r.result, "rules")
            val done = when (name) {
                "book-feed/sync-start" -> if (r.result.optBoolean("started")) getString(R.string.bf_started) else r.result.optString("reason")
                "book-feed/sync-cancel" -> getString(R.string.bf_stopped)
                "book-feed/rule-save" -> getString(R.string.bf_saved)
                "book-feed/rule-delete" -> getString(R.string.bf_deleted)
                "book-feed/import" -> getString(R.string.bf_imported)
                else -> ""
            }
            ui.message = if (r.windowOpened > 0) done + "\n" + getString(R.string.bf_window_opened, label, Ui.time(r.windowOpened)) else done
            ui.bfStatus = api.ability(site, "book-feed/status", JSONObject(), null).result
            if (r.windowOpened > 0) refreshNow()
            after?.invoke()
        }
    }

    private fun bfPublishers(text: String) {
        val site = ui.bfSite
        if (site.isEmpty() || text.trim().length < 3) {
            ui.bfPubs = emptyList()
            return
        }
        call {
            val a = api.ability(site, "book-feed/publishers", JSONObject().put("text", text.trim()), null).result.optJSONArray("publishers")
            ui.bfPubs = if (a == null) emptyList() else (0 until a.length()).map { a.getJSONObject(it) }.map { it.optString("id") to it.optString("name") }
        }
    }

    /** A rule's preview (read key): what its next run would bring, importing nothing. */
    private fun bfPreview(rule: JSONObject) {
        val site = ui.bfSite
        if (site.isEmpty()) return
        val asked = rule.toString()
        // The dialog's own switches and the draft/publish choice do not narrow the feed.
        val input = JSONObject(asked).apply { remove("on"); remove("every"); remove("status"); remove("name") }
        call {
            val res = api.ability(site, "book-feed/rule-preview", input, null).result
            ui.bfPreview = JSONObject().put("for", asked).put("result", res)
        }
    }

    private fun bfLookup(isbn: String) {
        val site = ui.bfSite
        if (site.isEmpty()) return
        call { ui.bfIsbn = api.ability(site, "book-feed/isbn", JSONObject().put("isbn", isbn), null).result }
    }

    private fun bfScan() {
        val opts = GmsBarcodeScannerOptions.Builder().setBarcodeFormats(Barcode.FORMAT_EAN_13).build()
        GmsBarcodeScanning.getClient(this, opts).startScan()
            .addOnSuccessListener { b -> b.rawValue?.let { bfLookup(it) } }
            .addOnFailureListener { e -> ui.message = getString(R.string.err_scan, e.message ?: "") }
    }

    private fun askNotifications(on: Boolean) {
        if (on && Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            askNotify.launch(Manifest.permission.POST_NOTIFICATIONS)
            return
        }
        setNotify(on)
    }

    private fun setNotify(on: Boolean) {
        store.bfNotify = on
        ui.bfNotify = on
        Notify.schedule(this, on)
    }

    private fun list(o: JSONObject, key: String): List<JSONObject> {
        val a = o.optJSONArray(key) ?: return emptyList()
        return (0 until a.length()).mapNotNull { a.optJSONObject(it) }
    }

    private fun strings(o: JSONObject, key: String): List<String> {
        val a = o.optJSONArray(key) ?: return emptyList()
        return (0 until a.length()).map { a.optString(it) }.filter { it.isNotEmpty() }
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
            Notify.schedule(this@MainActivity, false)
            Keys.delete()
            store.clear()
            ui.paired = false
            ui.bfNotify = false
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
        "window_closed" -> getString(R.string.bf_window_closed)
        else -> e.message
    }
}
