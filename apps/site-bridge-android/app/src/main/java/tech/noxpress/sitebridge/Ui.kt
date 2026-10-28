package tech.noxpress.sitebridge

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Snackbar
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/** Noxpress dark admin palette (Bible §2). */
object Nx {
    val bg = Color(0xFF101218)
    val panel = Color(0xFF14161D)
    val panel2 = Color(0xFF1D2029)
    val border = Color(0xFF2E3240)
    val text = Color(0xFFEAE8FA)
    val heading = Color(0xFFF6F4FF)
    val muted = Color(0xFFB7B2D6)
    val link = Color(0xFFBEB1FF)
    val accent = Color(0xFF6D4AFF)
    val ok = Color(0xFF7EE0A1)
    val warn = Color(0xFFF5C97E)
    val danger = Color(0xFFFF9A9A)
}

object Ui {
    private val hm = DateTimeFormatter.ofPattern("HH:mm")
    private val dt = DateTimeFormatter.ofPattern("dd/MM HH:mm")

    fun time(unix: Long): String = hm.format(Instant.ofEpochSecond(unix).atZone(ZoneId.systemDefault()))
    fun dateTime(unix: Long): String = dt.format(Instant.ofEpochSecond(unix).atZone(ZoneId.systemDefault()))
}

@Composable
fun duration(m: Int): String =
    if (m < 60) stringResource(R.string.dur_minutes, m) else stringResource(R.string.dur_hours, m / 60)

/** "New version" with a download link, under the header (1.4.0). */
@Composable
private fun UpdateBar(f: Updates.Found) {
    val open = LocalUriHandler.current
    Row(
        Modifier
            .fillMaxWidth()
            .background(Nx.panel2)
            .padding(horizontal = 16.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(stringResource(R.string.update_available, f.version), color = Nx.text, fontSize = 13.sp, modifier = Modifier.weight(1f))
        TextButton(onClick = { runCatching { open.openUri(f.url) } }) { Text(stringResource(R.string.update_get), color = Nx.link) }
    }
}

@Composable
fun SiteBridgeApp(
    ui: UiState,
    store: Store,
    onScan: () -> Unit,
    onPaste: (String) -> Unit,
    onRefresh: () -> Unit,
    onPick: (Int) -> Unit,
    onOpen: (Site) -> Unit,
    onClose: (Site) -> Unit,
    onLog: (String) -> Unit,
    onTile: (Site?) -> Unit,
    onDisconnect: () -> Unit,
    onDismiss: () -> Unit,
    bf: BfActions,
    br: BrActions,
) {
    val scheme = darkColorScheme(
        primary = Nx.accent,
        onPrimary = Color.White,
        background = Nx.bg,
        surface = Nx.panel,
        onSurface = Nx.text,
        onBackground = Nx.text,
        surfaceVariant = Nx.panel2,
        onSurfaceVariant = Nx.muted,
        outline = Nx.border,
        error = Nx.danger,
    )
    MaterialTheme(colorScheme = scheme) {
        Box(
            Modifier
                .fillMaxSize()
                .background(Nx.bg)
                .safeDrawingPadding(),
        ) {
            Column(Modifier.fillMaxSize()) {
                Header(store.hub.takeIf { ui.paired }, ui.busy)
                ui.update?.let { UpdateBar(it) }
                if (!ui.paired) {
                    PairScreen(onScan, onPaste)
                } else {
                    // The Book Feed tab shows only when a site of the Hub has Book Feed 1.11.0+.
                    val tabs = buildList {
                        add("sites" to R.string.tab_sites)
                        if (ui.sites.any { "book-feed" in it.modules }) add("bf" to R.string.tab_bf)
                        // Broadcast: Site Bridge 1.11.0 and Broadcast 1.1.0 (queue module on) on the site.
                        if (ui.sites.any { "broadcast" in it.modules }) add("br" to R.string.tab_br)
                        add("log" to R.string.tab_log)
                        add("settings" to R.string.tab_settings)
                    }
                    val at = tabs.indexOfFirst { it.first == ui.tab }.coerceAtLeast(0)
                    TabRow(selectedTabIndex = at, containerColor = Nx.panel, contentColor = Nx.heading) {
                        tabs.forEach { (key, label) ->
                            Tab(
                                selected = tabs[at].first == key,
                                onClick = {
                                    ui.tab = key
                                    if (key == "log") onLog(ui.logSite)
                                    if (key == "bf") bf.load(ui.bfSite)
                                    if (key == "br") br.load(ui.brSite)
                                },
                                text = { Text(stringResource(label), maxLines = 1) },
                            )
                        }
                    }
                    when (tabs[at].first) {
                        "sites" -> SitesScreen(ui, store, onRefresh, onPick, onOpen, onClose)
                        "bf" -> BookFeedScreen(ui, bf)
                        "br" -> BroadcastScreen(ui, br)
                        "log" -> LogScreen(ui, onLog)
                        else -> SettingsScreen(ui, store, onTile, onDisconnect)
                    }
                }
            }
            ui.message?.let { msg ->
                Snackbar(
                    modifier = Modifier
                        .align(Alignment.BottomCenter)
                        .padding(16.dp),
                    containerColor = Nx.panel2,
                    contentColor = Nx.text,
                    action = { TextButton(onClick = onDismiss) { Text("OK", color = Nx.link) } },
                ) { Text(msg) }
            }
        }
    }
}

@Composable
private fun Header(hub: String?, busy: Boolean) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.app_name), color = Nx.heading, fontSize = 22.sp, fontWeight = FontWeight.SemiBold)
            if (!hub.isNullOrEmpty()) Text(hub, color = Nx.muted, fontSize = 13.sp)
        }
        if (busy) CircularProgressIndicator(Modifier.size(22.dp), color = Nx.link, strokeWidth = 2.dp)
    }
}

@Composable
internal fun Zone(content: @Composable () -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 6.dp)
            .background(Nx.panel, RoundedCornerShape(10.dp))
            .border(1.dp, Nx.border, RoundedCornerShape(10.dp))
            .padding(14.dp),
    ) { content() }
}

@Composable
private fun PairScreen(onScan: () -> Unit, onPaste: (String) -> Unit) {
    var code by remember { mutableStateOf("") }
    Column(Modifier.padding(top = 8.dp)) {
        Zone {
            Text(stringResource(R.string.pair_title), color = Nx.heading, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.height(6.dp))
            Text(stringResource(R.string.pair_help), color = Nx.muted, fontSize = 14.sp)
            Spacer(Modifier.height(14.dp))
            Button(onClick = onScan, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.scan)) }
            Spacer(Modifier.height(14.dp))
            OutlinedTextField(
                value = code,
                onValueChange = { code = it },
                label = { Text(stringResource(R.string.paste_label)) },
                modifier = Modifier.fillMaxWidth(),
                textStyle = MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii),
                minLines = 2,
            )
            Spacer(Modifier.height(8.dp))
            OutlinedButton(onClick = { onPaste(code) }, enabled = code.isNotBlank()) { Text(stringResource(R.string.connect)) }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SitesScreen(
    ui: UiState,
    store: Store,
    onRefresh: () -> Unit,
    onPick: (Int) -> Unit,
    onOpen: (Site) -> Unit,
    onClose: (Site) -> Unit,
) {
    var now by remember { mutableLongStateOf(store.now()) }
    LaunchedEffect(Unit) {
        while (true) {
            now = store.now()
            delay(1000)
        }
    }
    LazyColumn(Modifier.fillMaxSize()) {
        item {
            Zone {
                Text(stringResource(R.string.length), color = Nx.muted, fontSize = 13.sp)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ui.minutes.forEach { m ->
                        FilterChip(
                            selected = ui.picked == m,
                            onClick = { onPick(m) },
                            label = { Text(duration(m)) },
                            colors = FilterChipDefaults.filterChipColors(
                                selectedContainerColor = Nx.accent,
                                selectedLabelColor = Color.White,
                                labelColor = Nx.text,
                            ),
                        )
                    }
                }
            }
        }
        if (ui.sites.isEmpty() && !ui.busy) {
            item { Zone { Text(stringResource(R.string.no_sites), color = Nx.muted) } }
        }
        items(ui.sites, key = { it.id }) { s -> SiteCard(s, now, ui.busy, ui.picked, onOpen, onClose) }
        item {
            Row(Modifier.fillMaxWidth().padding(16.dp), horizontalArrangement = Arrangement.Center) {
                TextButton(onClick = onRefresh, enabled = !ui.busy) { Text(stringResource(R.string.refresh), color = Nx.link) }
            }
        }
    }
}

@Composable
private fun SiteCard(s: Site, now: Long, busy: Boolean, picked: Int, onOpen: (Site) -> Unit, onClose: (Site) -> Unit) {
    val open = s.windowUntil > now
    Zone {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(s.label, color = Nx.heading, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
                Text(s.url.removePrefix("https://").removeSuffix("/"), color = Nx.muted, fontSize = 13.sp)
            }
            Badge(stringResource(if (s.level == "admin") R.string.level_admin else R.string.level_write), Nx.muted)
        }
        Spacer(Modifier.height(10.dp))
        if (open) {
            Text(
                stringResource(R.string.open_until, Ui.time(s.windowUntil), Protocol.countdown(s.windowUntil - now)),
                color = Nx.warn,
                fontWeight = FontWeight.SemiBold,
            )
        } else {
            Text(stringResource(R.string.closed), color = Nx.ok, fontWeight = FontWeight.SemiBold)
        }
        if (!s.reachable) {
            Spacer(Modifier.height(6.dp))
            Text(stringResource(R.string.unreachable, s.error), color = Nx.danger, fontSize = 13.sp)
        }
        Spacer(Modifier.height(10.dp))
        when {
            !s.canOpen -> Text(stringResource(R.string.cannot_open), color = Nx.muted, fontSize = 13.sp)
            // Renewing starts the picked length from now (the site resets its window), so it can also shorten it.
            open -> Column {
                Button(
                    onClick = { onOpen(s) },
                    enabled = !busy,
                    modifier = Modifier.fillMaxWidth(),
                    colors = ButtonDefaults.buttonColors(containerColor = Nx.accent),
                ) { Text(stringResource(R.string.renew_btn, duration(picked))) }
                Spacer(Modifier.height(8.dp))
                OutlinedButton(onClick = { onClose(s) }, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.close_btn))
                }
            }
            else -> Button(
                onClick = { onOpen(s) },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(containerColor = Nx.accent),
            ) { Text(stringResource(R.string.open_btn, duration(picked))) }
        }
    }
}

@Composable
internal fun Badge(text: String, color: Color) {
    Text(
        text,
        color = color,
        fontSize = 12.sp,
        modifier = Modifier
            .border(1.dp, Nx.border, RoundedCornerShape(20.dp))
            .padding(horizontal = 10.dp, vertical = 3.dp),
    )
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun LogScreen(ui: UiState, onLog: (String) -> Unit) {
    LazyColumn(Modifier.fillMaxSize()) {
        item {
            FlowRow(Modifier.padding(horizontal = 16.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(selected = ui.logSite.isEmpty(), onClick = { onLog("") }, label = { Text(stringResource(R.string.log_all)) })
                ui.sites.forEach { s ->
                    FilterChip(selected = ui.logSite == s.id, onClick = { onLog(s.id) }, label = { Text(s.label) })
                }
            }
        }
        if (ui.log.isEmpty() && !ui.busy) item { Zone { Text(stringResource(R.string.log_empty), color = Nx.muted) } }
        items(ui.log) { r ->
            Column(
                Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 2.dp)
                    .background(Nx.panel, RoundedCornerShape(8.dp))
                    .padding(10.dp),
            ) {
                Row {
                    Text(Ui.dateTime(r.ts), color = Nx.muted, fontSize = 12.sp, modifier = Modifier.weight(1f))
                    Text(
                        r.status,
                        fontSize = 12.sp,
                        color = when (r.status) {
                            "ok" -> Nx.ok
                            "denied" -> Nx.warn
                            else -> Nx.danger
                        },
                    )
                }
                Text(r.tool + if (r.site.isNotEmpty()) " · " + r.site else "", color = Nx.text, fontFamily = FontFamily.Monospace, fontSize = 13.sp)
                val extra = listOf(r.client, r.message).filter { it.isNotEmpty() }.joinToString(" · ")
                if (extra.isNotEmpty()) Text(extra, color = Nx.muted, fontSize = 12.sp)
            }
        }
        item { Spacer(Modifier.height(24.dp)) }
    }
}

@Composable
private fun SettingsScreen(ui: UiState, store: Store, onTile: (Site?) -> Unit, onDisconnect: () -> Unit) {
    var ask by remember { mutableStateOf(false) }
    LazyColumn(Modifier.fillMaxSize()) {
        item {
            Zone {
                Text(stringResource(R.string.settings_hub), color = Nx.muted, fontSize = 13.sp)
                Text(store.hub, color = Nx.heading)
                Text(store.base.substringBefore("/wp-json/"), color = Nx.muted, fontSize = 13.sp)
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.settings_device), color = Nx.muted, fontSize = 13.sp)
                Text(store.device, color = Nx.text, fontFamily = FontFamily.Monospace, fontSize = 13.sp)
            }
        }
        item {
            Zone {
                Text(stringResource(R.string.tile_site), color = Nx.heading, fontWeight = FontWeight.SemiBold)
                Text(stringResource(R.string.tile_help), color = Nx.muted, fontSize = 13.sp)
                Spacer(Modifier.height(6.dp))
                TileChoice(stringResource(R.string.tile_none), ui.tileSite.isEmpty()) { onTile(null) }
                ui.sites.filter { it.canOpen }.forEach { s -> TileChoice(s.label, ui.tileSite == s.id) { onTile(s) } }
            }
        }
        item {
            Zone {
                OutlinedButton(onClick = { ask = true }, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.disconnect), color = Nx.danger)
                }
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.version, BuildConfig.VERSION_NAME), color = Nx.muted, fontSize = 12.sp)
                Text(stringResource(R.string.not_anthropic), color = Nx.muted, fontSize = 12.sp)
            }
        }
    }
    if (ask) {
        AlertDialog(
            onDismissRequest = { ask = false },
            confirmButton = {
                TextButton(onClick = {
                    ask = false
                    onDisconnect()
                }) { Text(stringResource(R.string.disconnect), color = Nx.danger) }
            },
            dismissButton = { TextButton(onClick = { ask = false }) { Text(stringResource(R.string.cancel)) } },
            text = { Text(stringResource(R.string.disconnect_confirm)) },
            containerColor = Nx.panel2,
        )
    }
}

@Composable
private fun TileChoice(label: String, selected: Boolean, onClick: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        RadioButton(selected = selected, onClick = onClick)
        Text(label, color = Nx.text)
    }
}
