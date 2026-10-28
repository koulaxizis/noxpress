package tech.noxpress.sitebridge

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.time.OffsetDateTime

/** What the Broadcast tab can ask of the activity (1.6.0). */
class BrActions(
    val load: (String) -> Unit,
    /** Sends an item now; a non-null text replaces the post's text first. */
    val send: (Int, String?) -> Unit,
    val reject: (Int) -> Unit,
    val notify: (Boolean) -> Unit,
)

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun BroadcastScreen(ui: UiState, br: BrActions) {
    val sites = ui.sites.filter { "broadcast" in it.modules }
    var editing by remember { mutableStateOf<JSONObject?>(null) }
    var rejecting by remember { mutableStateOf<JSONObject?>(null) }
    LaunchedEffect(ui.brSite) {
        if (ui.brSite.isNotEmpty() && ui.brStatus == null) br.load(ui.brSite)
    }
    LazyColumn(Modifier.fillMaxSize()) {
        if (sites.size > 1) {
            item {
                FlowRow(Modifier.padding(horizontal = 16.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    sites.forEach { s ->
                        FilterChip(
                            selected = ui.brSite == s.id,
                            onClick = { br.load(s.id) },
                            label = { Text(s.label) },
                            colors = FilterChipDefaults.filterChipColors(selectedContainerColor = Nx.accent, selectedLabelColor = Color.White, labelColor = Nx.text),
                        )
                    }
                }
            }
        }
        item { ModeZone(ui, br) }
        if (ui.brStatus != null && ui.brItems.isEmpty()) {
            item { Zone { Text(stringResource(R.string.br_empty), color = Nx.muted) } }
        }
        items(ui.brItems, key = { it.optInt("id") }) { row ->
            ItemCard(row, ui.busy, onSend = { br.send(row.optInt("id"), null) }, onEdit = { editing = row }, onReject = { rejecting = row })
        }
        item {
            Zone {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.br_notify), color = Nx.heading, fontWeight = FontWeight.SemiBold)
                        Text(stringResource(R.string.br_notify_help), color = Nx.muted, fontSize = 13.sp)
                    }
                    Spacer(Modifier.width(8.dp))
                    Switch(checked = ui.brNotify, onCheckedChange = { br.notify(it) })
                }
            }
        }
        item { Spacer(Modifier.height(24.dp)) }
    }
    editing?.let { item ->
        var text by remember(item) { mutableStateOf(item.optString("text")) }
        AlertDialog(
            onDismissRequest = { editing = null },
            containerColor = Nx.panel2,
            title = { Text(stringResource(R.string.br_edit_title)) },
            text = {
                OutlinedTextField(
                    value = text,
                    onValueChange = { text = it },
                    minLines = 5,
                    modifier = Modifier.fillMaxWidth(),
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    editing = null
                    br.send(item.optInt("id"), text)
                }, enabled = text.isNotBlank()) { Text(stringResource(R.string.br_send), color = Nx.link) }
            },
            dismissButton = { TextButton(onClick = { editing = null }) { Text(stringResource(R.string.cancel)) } },
        )
    }
    rejecting?.let { item ->
        AlertDialog(
            onDismissRequest = { rejecting = null },
            containerColor = Nx.panel2,
            text = { Text(stringResource(R.string.br_reject_confirm, item.optString("title").ifEmpty { "#" + item.optInt("id") })) },
            confirmButton = {
                TextButton(onClick = {
                    rejecting = null
                    br.reject(item.optInt("id"))
                }) { Text(stringResource(R.string.br_reject), color = Nx.danger) }
            },
            dismissButton = { TextButton(onClick = { rejecting = null }) { Text(stringResource(R.string.cancel)) } },
        )
    }
}

@Composable
private fun ModeZone(ui: UiState, br: BrActions) {
    val st = ui.brStatus
    Zone {
        Text(stringResource(R.string.br_waiting), color = Nx.heading, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(4.dp))
        when {
            st == null -> Text(stringResource(R.string.bf_loading), color = Nx.muted)
            st.optString("mode") == "test" -> Text(stringResource(R.string.br_mode_test), color = Nx.warn, fontSize = 13.sp)
            st.optString("mode") == "off" -> Text(stringResource(R.string.br_mode_off), color = Nx.danger, fontSize = 13.sp)
            else -> {
                val ch = st.optJSONObject("viber")?.optString("channel").orEmpty()
                Text(if (ch.isNotEmpty()) stringResource(R.string.br_mode_live_to, ch) else stringResource(R.string.br_mode_live), color = Nx.ok, fontSize = 13.sp)
            }
        }
        TextButton(onClick = { br.load(ui.brSite) }, enabled = !ui.busy) { Text(stringResource(R.string.refresh), color = Nx.link) }
    }
}

@Composable
private fun reasonText(r: String): String = when (r) {
    "approval" -> stringResource(R.string.br_r_approval)
    "brake" -> stringResource(R.string.br_r_brake)
    "publish" -> stringResource(R.string.br_r_publish)
    "unsure" -> stringResource(R.string.br_r_unsure)
    else -> r
}

@Composable
private fun sourceText(s: String): String = when (s) {
    "products" -> stringResource(R.string.br_s_products)
    "queue" -> stringResource(R.string.br_s_queue)
    "digest" -> stringResource(R.string.br_s_digest)
    else -> s.replaceFirstChar { it.uppercase() }
}

@Composable
private fun ItemCard(row: JSONObject, busy: Boolean, onSend: () -> Unit, onEdit: () -> Unit, onReject: () -> Unit) {
    Zone {
        val image = row.optString("image")
        if (image.startsWith("https://")) {
            RemoteImage(image)
            Spacer(Modifier.height(8.dp))
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(sourceText(row.optString("source")), color = Nx.link, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            Badge(reasonText(row.optString("reason")), Nx.warn)
        }
        val at = try {
            Ui.dateTime(OffsetDateTime.parse(row.optString("created")).toEpochSecond())
        } catch (e: Exception) {
            ""
        }
        if (at.isNotEmpty()) Text(at, color = Nx.muted, fontSize = 12.sp)
        Spacer(Modifier.height(6.dp))
        Text(row.optString("text").ifEmpty { row.optString("url") }, color = Nx.text, fontSize = 14.sp)
        Spacer(Modifier.height(10.dp))
        Button(
            onClick = onSend,
            enabled = !busy,
            modifier = Modifier.fillMaxWidth(),
            colors = ButtonDefaults.buttonColors(containerColor = Nx.accent),
        ) { Text(stringResource(R.string.br_send)) }
        Spacer(Modifier.height(6.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = onEdit, enabled = !busy, modifier = Modifier.weight(1f)) { Text(stringResource(R.string.bf_edit)) }
            OutlinedButton(onClick = onReject, enabled = !busy, modifier = Modifier.weight(1f)) { Text(stringResource(R.string.br_reject), color = Nx.danger) }
        }
    }
}

/** A post's picture, read once over https and scaled down; nothing when it fails. */
@Composable
private fun RemoteImage(url: String) {
    var bmp by remember(url) { mutableStateOf<Bitmap?>(null) }
    LaunchedEffect(url) {
        bmp = withContext(Dispatchers.IO) { runCatching { fetch(url) }.getOrNull() }
    }
    bmp?.let {
        Image(
            bitmap = it.asImageBitmap(),
            contentDescription = null,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .fillMaxWidth()
                .heightIn(max = 220.dp)
                .clip(RoundedCornerShape(8.dp)),
        )
    }
}

private fun fetch(url: String): Bitmap? {
    val c = URL(url).openConnection() as HttpURLConnection
    c.connectTimeout = 10000
    c.readTimeout = 15000
    c.instanceFollowRedirects = true
    try {
        if (c.responseCode != 200) return null
        val bytes = c.inputStream.use { s -> s.readBytes() }
        if (bytes.size > 8 * 1024 * 1024) return null
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        var sample = 1
        while (bounds.outWidth / sample > 1080) sample *= 2
        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
    } finally {
        c.disconnect()
    }
}
