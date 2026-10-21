package tech.noxpress.sitebridge

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import org.json.JSONObject
import java.time.OffsetDateTime

/** What the Book Feed tab can ask of the activity. */
class BfActions(
    val load: (String) -> Unit,
    val sync: () -> Unit,
    val stop: () -> Unit,
    val runRule: (String) -> Unit,
    val saveRule: (JSONObject) -> Unit,
    val deleteRule: (String) -> Unit,
    val publishers: (String) -> Unit,
    val lookup: (String) -> Unit,
    val scan: () -> Unit,
    val importIsbn: (String) -> Unit,
    val notify: (Boolean) -> Unit,
    val preview: (JSONObject) -> Unit,
)

/** What a rule would bring on its next run (book-feed/rule-preview); nothing is imported. */
@Composable
private fun PreviewBox(p: JSONObject) {
    Column(Modifier.padding(top = 8.dp)) {
        Text(
            stringResource(
                R.string.bf_pv_sum,
                p.optInt("total"), p.optInt("checked"), p.optInt("in_store"), p.optInt("new"), p.optInt("will"), p.optInt("max"),
            ),
            color = Nx.text,
            fontSize = 13.sp,
        )
        val since = p.optLong("since")
        if (since > 0) Text(stringResource(R.string.bf_pv_since, Ui.dateTime(since)), color = Nx.muted, fontSize = 12.sp)
        if (p.optBoolean("more")) Text(stringResource(R.string.bf_pv_more), color = Nx.muted, fontSize = 12.sp)
        val titles = p.optJSONArray("titles")
        if (titles == null || titles.length() == 0) {
            Text(stringResource(R.string.bf_pv_none), color = Nx.text, fontSize = 13.sp)
            return@Column
        }
        Spacer(Modifier.height(4.dp))
        for (i in 0 until titles.length()) {
            val t = titles.getJSONObject(i)
            val people = t.optJSONArray("people")
            val line = listOf(
                if (people == null) "" else (0 until people.length()).joinToString(", ") { people.optString(it) },
                t.optInt("year").takeIf { it > 0 }?.toString().orEmpty(),
                t.optString("pstat"),
                t.optString("isbn"),
            ).filter { it.isNotEmpty() }.joinToString(" · ")
            Text(t.optString("title"), color = Nx.heading, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp))
            if (line.isNotEmpty()) Text(line, color = Nx.muted, fontSize = 12.sp)
        }
    }
}

/** An ISO 8601 time from the site, as local dd/MM HH:mm, or "". */
private fun iso(v: String): String = try {
    if (v.isEmpty() || v == "null") "" else Ui.dateTime(OffsetDateTime.parse(v).toEpochSecond())
} catch (e: Exception) {
    v
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun BookFeedScreen(ui: UiState, bf: BfActions) {
    val sites = ui.sites.filter { "book-feed" in it.modules }
    var editing by remember { mutableStateOf<JSONObject?>(null) }
    LaunchedEffect(ui.bfSite) {
        if (ui.bfSite.isNotEmpty() && ui.bfStatus == null) bf.load(ui.bfSite)
    }
    LazyColumn(Modifier.fillMaxSize()) {
        if (sites.size > 1) {
            item {
                FlowRow(Modifier.padding(horizontal = 16.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    sites.forEach { s ->
                        FilterChip(selected = ui.bfSite == s.id, onClick = { bf.load(s.id) }, label = { Text(s.label) }, colors = chipColors())
                    }
                }
            }
        }
        item { StatusZone(ui, bf) }
        item {
            Row(Modifier.padding(start = 16.dp, end = 16.dp, top = 14.dp, bottom = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.bf_rules), color = Nx.heading, fontSize = 17.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                TextButton(onClick = { editing = JSONObject() }, enabled = !ui.busy && ui.bfStatus != null) {
                    Text(stringResource(R.string.bf_new_rule), color = Nx.link)
                }
            }
        }
        if (ui.bfRules.isEmpty() && ui.bfStatus != null) {
            item { Zone { Text(stringResource(R.string.bf_no_rules), color = Nx.muted) } }
        }
        items(ui.bfRules, key = { it.optString("id") }) { r -> RuleCard(r, ui.busy, bf, onEdit = { editing = r }) }
        item { IsbnZone(ui, bf) }
        item {
            Zone {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.bf_notify), color = Nx.heading, fontWeight = FontWeight.SemiBold)
                        Text(stringResource(R.string.bf_notify_help), color = Nx.muted, fontSize = 13.sp)
                    }
                    Spacer(Modifier.width(8.dp))
                    Switch(checked = ui.bfNotify, onCheckedChange = { bf.notify(it) })
                }
            }
        }
        item { Spacer(Modifier.height(24.dp)) }
    }
    editing?.let { r ->
        RuleDialog(ui, bf, r, onDone = { editing = null })
    }
}

@Composable
private fun chipColors() = FilterChipDefaults.filterChipColors(
    selectedContainerColor = Nx.accent,
    selectedLabelColor = Color.White,
    labelColor = Nx.text,
)

@Composable
private fun StatusZone(ui: UiState, bf: BfActions) {
    val st = ui.bfStatus
    Zone {
        Text(stringResource(R.string.bf_sync), color = Nx.heading, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(6.dp))
        if (st == null) {
            Text(stringResource(R.string.bf_loading), color = Nx.muted)
            return@Zone
        }
        if (!st.optBoolean("ready", true)) {
            Text(stringResource(R.string.bf_not_ready), color = Nx.danger, fontSize = 13.sp)
        }
        val job = st.optJSONObject("job")
        if (job != null) {
            val s = job.optJSONObject("stats") ?: JSONObject()
            Text(stringResource(R.string.bf_running, job.optString("phase"), job.optInt("steps")), color = Nx.warn, fontWeight = FontWeight.SemiBold)
            Text(stats(s), color = Nx.muted, fontSize = 13.sp)
        } else {
            val last = st.optJSONObject("last")
            if (last != null) {
                Text(stringResource(R.string.bf_last, iso(last.optString("at"))), color = Nx.text)
                Text(stats(last.optJSONObject("stats") ?: JSONObject()), color = Nx.muted, fontSize = 13.sp)
                val err = last.optString("error")
                if (err.isNotEmpty()) Text(err, color = Nx.danger, fontSize = 13.sp)
            } else {
                Text(stringResource(R.string.bf_never), color = Nx.muted)
            }
        }
        val daily = st.optString("daily")
        if (daily.isNotEmpty() && daily != "null") Text(stringResource(R.string.bf_next, iso(daily)), color = Nx.muted, fontSize = 13.sp)
        Spacer(Modifier.height(10.dp))
        if (job != null) {
            OutlinedButton(onClick = bf.stop, enabled = !ui.busy, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.bf_stop)) }
        } else {
            Button(
                onClick = bf.sync,
                enabled = !ui.busy && st.optBoolean("ready", true),
                modifier = Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(containerColor = Nx.accent),
            ) { Text(stringResource(R.string.bf_sync_now)) }
        }
        TextButton(onClick = { bf.load(ui.bfSite) }, enabled = !ui.busy) { Text(stringResource(R.string.refresh), color = Nx.link) }
    }
}

@Composable
private fun stats(s: JSONObject): String = stringResource(
    R.string.bf_stats,
    s.optInt("checked"),
    s.optInt("updated"),
    s.optInt("imported"),
    s.optInt("gone"),
    s.optInt("errors"),
)

@Composable
private fun RuleCard(r: JSONObject, busy: Boolean, bf: BfActions, onEdit: () -> Unit) {
    Zone {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(r.optString("label"), color = Nx.heading, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            Badge(
                stringResource(if (!r.optBoolean("on")) R.string.bf_off else if (r.optBoolean("due")) R.string.bf_due else R.string.bf_on),
                if (r.optBoolean("on")) Nx.ok else Nx.muted,
            )
        }
        val every = r.optInt("every", 1)
        val status = stringResource(if (r.optString("status") == "publish") R.string.bf_publish else R.string.bf_draft)
        Text(plural(R.plurals.bf_every, every) + " · " + status, color = Nx.muted, fontSize = 13.sp)
        val last = iso(r.optString("last"))
        Text(if (last.isEmpty()) stringResource(R.string.bf_rule_never) else stringResource(R.string.bf_rule_last, last), color = Nx.muted, fontSize = 13.sp)
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = { bf.runRule(r.optString("id")) }, enabled = !busy, modifier = Modifier.weight(1f)) { Text(stringResource(R.string.bf_run)) }
            OutlinedButton(onClick = onEdit, enabled = !busy, modifier = Modifier.weight(1f)) { Text(stringResource(R.string.bf_edit)) }
        }
    }
}

@Composable
private fun plural(id: Int, count: Int): String = LocalContext.current.resources.getQuantityString(id, count, count)

@Composable
private fun IsbnZone(ui: UiState, bf: BfActions) {
    var isbn by remember { mutableStateOf("") }
    Zone {
        Text(stringResource(R.string.bf_isbn_title), color = Nx.heading, fontSize = 17.sp, fontWeight = FontWeight.SemiBold)
        Text(stringResource(R.string.bf_isbn_help), color = Nx.muted, fontSize = 13.sp)
        Spacer(Modifier.height(8.dp))
        Button(onClick = bf.scan, enabled = !ui.busy && ui.bfSite.isNotEmpty(), modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.bf_scan)) }
        Spacer(Modifier.height(8.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(
                value = isbn,
                onValueChange = { isbn = it },
                label = { Text("ISBN") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                modifier = Modifier.weight(1f),
            )
            Spacer(Modifier.width(8.dp))
            OutlinedButton(onClick = { bf.lookup(isbn) }, enabled = !ui.busy && isbn.isNotBlank()) { Text(stringResource(R.string.bf_find)) }
        }
        ui.bfIsbn?.let { r -> IsbnResult(r, ui.busy, bf) }
    }
}

@Composable
private fun IsbnResult(r: JSONObject, busy: Boolean, bf: BfActions) {
    Spacer(Modifier.height(10.dp))
    val t = r.optJSONObject("title")
    if (t == null) {
        Text(stringResource(R.string.bf_isbn_none, r.optString("isbn")), color = Nx.warn)
    } else {
        Text(t.optString("title"), color = Nx.heading, fontWeight = FontWeight.SemiBold)
        val sub = t.optString("subtitle")
        if (sub.isNotEmpty()) Text(sub, color = Nx.text, fontSize = 13.sp)
        val people = t.optJSONArray("people")
        if (people != null && people.length() > 0) Text((0 until people.length()).joinToString(", ") { people.optString(it) }, color = Nx.text, fontSize = 13.sp)
        val line = listOf(
            t.optString("publisher"),
            t.optInt("year").takeIf { it > 0 }?.toString().orEmpty(),
            if (t.isNull("price")) "" else "%.2f €".format(t.optDouble("price")),
            t.optString("pstat"),
        ).filter { it.isNotEmpty() }.joinToString(" · ")
        Text(line, color = Nx.muted, fontSize = 13.sp)
    }
    val p = r.optJSONObject("product")
    Spacer(Modifier.height(6.dp))
    if (p != null) {
        val st = stringResource(if (p.optString("status") == "publish") R.string.bf_publish else R.string.bf_draft)
        Text(stringResource(R.string.bf_in_store, st, p.optString("price")), color = Nx.ok, fontSize = 13.sp)
        if (!p.optBoolean("feed")) Text(stringResource(R.string.bf_not_feed), color = Nx.muted, fontSize = 13.sp)
    } else if (t != null) {
        Text(stringResource(R.string.bf_not_in_store), color = Nx.muted, fontSize = 13.sp)
        Spacer(Modifier.height(6.dp))
        Button(
            onClick = { bf.importIsbn(r.optString("isbn")) },
            enabled = !busy,
            modifier = Modifier.fillMaxWidth(),
            colors = ButtonDefaults.buttonColors(containerColor = Nx.accent),
        ) { Text(stringResource(R.string.bf_import)) }
    }
}

/** New rule (empty object) or changes to one. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun RuleDialog(ui: UiState, bf: BfActions, r: JSONObject, onDone: () -> Unit) {
    val isNew = !r.has("id")
    var name by remember { mutableStateOf(r.optString("name")) }
    var pubId by remember { mutableStateOf(r.optString("pub_id")) }
    var pubName by remember { mutableStateOf(r.optString("pub_name")) }
    var pubFind by remember { mutableStateOf("") }
    var title by remember { mutableStateOf(r.optString("title")) }
    var author by remember { mutableStateOf(r.optString("author")) }
    var kind by remember { mutableStateOf(r.optString("kind")) }
    // A leading "-" in pstat means every status except that one (Book Feed 1.8.1).
    var pstat by remember { mutableStateOf(r.optString("pstat").removePrefix("-")) }
    var pstatExcept by remember { mutableStateOf(r.optString("pstat").startsWith("-")) }
    var yFrom by remember { mutableStateOf(r.optInt("year_from").takeIf { it > 0 }?.toString().orEmpty()) }
    var yTo by remember { mutableStateOf(r.optInt("year_to").takeIf { it > 0 }?.toString().orEmpty()) }
    var every by remember { mutableStateOf(r.optInt("every", 7).toString()) }
    var publish by remember { mutableStateOf(r.optString("status") == "publish") }
    var on by remember { mutableStateOf(if (isNew) true else r.optBoolean("on")) }
    var askDelete by remember { mutableStateOf(false) }
    val enough = pubId.isNotEmpty() || title.isNotBlank() || author.isNotBlank()
    fun fields(): JSONObject {
        val o = JSONObject()
            .put("name", name.trim())
            .put("pub_id", pubId)
            .put("pub_name", pubName)
            .put("title", title.trim())
            .put("author", author.trim())
            .put("kind", kind)
            .put("pstat", if (pstat.isNotEmpty() && pstatExcept) "-$pstat" else pstat)
            .put("year_from", yFrom.toIntOrNull() ?: 0)
            .put("year_to", yTo.toIntOrNull() ?: 0)
            .put("every", (every.toIntOrNull() ?: 7).coerceIn(1, 90))
            .put("status", if (publish) "publish" else "draft")
            .put("on", on)
        if (!isNew) o.put("id", r.optString("id"))
        return o
    }
    // A preview stays shown only while the fields are the ones it was made for.
    val pv = ui.bfPreview?.takeIf { it.optString("for") == fields().toString() }
    DisposableEffect(Unit) { onDispose { ui.bfPreview = null } }

    AlertDialog(
        onDismissRequest = onDone,
        containerColor = Nx.panel2,
        title = { Text(stringResource(if (isNew) R.string.bf_new_rule else R.string.bf_edit_rule)) },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                OutlinedTextField(value = name, onValueChange = { name = it }, label = { Text(stringResource(R.string.bf_f_name)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.bf_f_publisher), color = Nx.muted, fontSize = 13.sp)
                if (pubId.isNotEmpty()) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(pubName.ifEmpty { pubId }, color = Nx.heading, modifier = Modifier.weight(1f))
                        TextButton(onClick = {
                            pubId = ""
                            pubName = ""
                        }) { Text(stringResource(R.string.bf_remove), color = Nx.link) }
                    }
                } else {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        OutlinedTextField(
                            value = pubFind,
                            onValueChange = { pubFind = it },
                            label = { Text(stringResource(R.string.bf_f_pub_find)) },
                            singleLine = true,
                            modifier = Modifier.weight(1f),
                        )
                        Spacer(Modifier.width(8.dp))
                        OutlinedButton(onClick = { bf.publishers(pubFind) }, enabled = !ui.busy && pubFind.trim().length >= 3) { Text(stringResource(R.string.bf_find)) }
                    }
                    ui.bfPubs.forEach { (id, n) ->
                        TextButton(onClick = {
                            pubId = id
                            pubName = n
                        }) { Text(n, color = Nx.link) }
                    }
                }
                OutlinedTextField(value = title, onValueChange = { title = it }, label = { Text(stringResource(R.string.bf_f_title)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(value = author, onValueChange = { author = it }, label = { Text(stringResource(R.string.bf_f_author)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
                if (ui.bfKinds.isNotEmpty()) {
                    Spacer(Modifier.height(8.dp))
                    Text(stringResource(R.string.bf_f_kind), color = Nx.muted, fontSize = 13.sp)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        FilterChip(selected = kind.isEmpty(), onClick = { kind = "" }, label = { Text(stringResource(R.string.log_all)) }, colors = chipColors())
                        ui.bfKinds.forEach { k -> FilterChip(selected = kind == k, onClick = { kind = k }, label = { Text(k) }, colors = chipColors()) }
                    }
                }
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.bf_f_pstat), color = Nx.muted, fontSize = 13.sp)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    FilterChip(selected = pstat.isEmpty(), onClick = { pstat = "" }, label = { Text(stringResource(R.string.log_all)) }, colors = chipColors())
                    (ui.bfStatuses + listOfNotNull(pstat.takeIf { it.isNotEmpty() && it !in ui.bfStatuses })).forEach { v ->
                        FilterChip(selected = pstat == v, onClick = { pstat = v }, label = { Text(v) }, colors = chipColors())
                    }
                }
                if (pstat.isNotEmpty()) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        FilterChip(selected = !pstatExcept, onClick = { pstatExcept = false }, label = { Text(stringResource(R.string.bf_pstat_only)) }, colors = chipColors())
                        FilterChip(selected = pstatExcept, onClick = { pstatExcept = true }, label = { Text(stringResource(R.string.bf_pstat_except)) }, colors = chipColors())
                    }
                }
                Row {
                    OutlinedTextField(
                        value = yFrom,
                        onValueChange = { yFrom = it.filter(Char::isDigit).take(4) },
                        label = { Text(stringResource(R.string.bf_f_year_from)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.weight(1f),
                    )
                    Spacer(Modifier.width(8.dp))
                    OutlinedTextField(
                        value = yTo,
                        onValueChange = { yTo = it.filter(Char::isDigit).take(4) },
                        label = { Text(stringResource(R.string.bf_f_year_to)) },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.weight(1f),
                    )
                }
                OutlinedTextField(
                    value = every,
                    onValueChange = { every = it.filter(Char::isDigit).take(2) },
                    label = { Text(stringResource(R.string.bf_f_every)) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.bf_f_status), color = Nx.muted, fontSize = 13.sp)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    FilterChip(selected = !publish, onClick = { publish = false }, label = { Text(stringResource(R.string.bf_draft)) }, colors = chipColors())
                    FilterChip(selected = publish, onClick = { publish = true }, label = { Text(stringResource(R.string.bf_publish)) }, colors = chipColors())
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.bf_f_on), color = Nx.text, modifier = Modifier.weight(1f))
                    Switch(checked = on, onCheckedChange = { on = it })
                }
                if (isNew) Text(stringResource(R.string.bf_f_cat_note), color = Nx.muted, fontSize = 12.sp)
                if (!enough) Text(stringResource(R.string.bf_f_need), color = Nx.warn, fontSize = 12.sp)
                Spacer(Modifier.height(8.dp))
                OutlinedButton(onClick = { bf.preview(fields()) }, enabled = enough && !ui.busy, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.bf_preview))
                }
                if (pv != null) PreviewBox(pv.getJSONObject("result"))
                if (!isNew) {
                    TextButton(onClick = { askDelete = true }) { Text(stringResource(R.string.bf_delete), color = Nx.danger) }
                }
            }
        },
        confirmButton = {
            TextButton(
                enabled = enough && !ui.busy,
                onClick = {
                    bf.saveRule(fields())
                    onDone()
                },
            ) { Text(stringResource(R.string.bf_save), color = Nx.link) }
        },
        dismissButton = { TextButton(onClick = onDone) { Text(stringResource(R.string.cancel)) } },
    )
    if (askDelete) {
        AlertDialog(
            onDismissRequest = { askDelete = false },
            containerColor = Nx.panel2,
            text = { Text(stringResource(R.string.bf_delete_confirm, r.optString("label"))) },
            confirmButton = {
                TextButton(onClick = {
                    askDelete = false
                    bf.deleteRule(r.optString("id"))
                    onDone()
                }) { Text(stringResource(R.string.bf_delete), color = Nx.danger) }
            },
            dismissButton = { TextButton(onClick = { askDelete = false }) { Text(stringResource(R.string.cancel)) } },
        )
    }
}
