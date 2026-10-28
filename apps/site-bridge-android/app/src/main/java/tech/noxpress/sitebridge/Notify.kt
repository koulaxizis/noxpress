package tech.noxpress.sitebridge

import android.Manifest
import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.util.concurrent.TimeUnit

/**
 * Book Feed and Broadcast notifications. No Firebase: WorkManager asks the
 * Hub about once an hour (read key, no fingerprint) for each site with the
 * plugin, and shows one notification per site:
 *  - Book Feed (book-feed/notices): titles came in, the sync failed or
 *    titles left the catalogue.
 *  - Broadcast (broadcast/queue-list, 1.6.0): new posts wait in the queue.
 */
object Notify {

    private const val CHANNEL = "book_feed"
    private const val CHANNEL_BR = "broadcast"
    private const val WORK = "bf_notices"
    private const val WORK_BR = "br_waiting"
    const val ACTION_BF = "tech.noxpress.sitebridge.OPEN_BOOK_FEED"
    const val ACTION_BR = "tech.noxpress.sitebridge.OPEN_BROADCAST"

    fun schedule(context: Context, on: Boolean) {
        val wm = WorkManager.getInstance(context)
        if (!on) {
            wm.cancelUniqueWork(WORK)
            return
        }
        val req = PeriodicWorkRequestBuilder<NoticesWorker>(1, TimeUnit.HOURS)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        wm.enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP, req)
    }

    fun scheduleBr(context: Context, on: Boolean) {
        val wm = WorkManager.getInstance(context)
        if (!on) {
            wm.cancelUniqueWork(WORK_BR)
            return
        }
        val req = PeriodicWorkRequestBuilder<WaitingWorker>(1, TimeUnit.HOURS)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        wm.enqueueUniquePeriodicWork(WORK_BR, ExistingPeriodicWorkPolicy.KEEP, req)
    }

    fun allowed(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    private fun channel(context: Context, br: Boolean) {
        val nm = context.getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(
            if (br) {
                NotificationChannel(CHANNEL_BR, context.getString(R.string.br_channel), NotificationManager.IMPORTANCE_DEFAULT)
            } else {
                NotificationChannel(CHANNEL, context.getString(R.string.bf_channel), NotificationManager.IMPORTANCE_DEFAULT)
            },
        )
    }

    /** The text for one site's notices, or null when there is nothing to say. */
    fun text(context: Context, n: JSONObject): String? {
        val lines = mutableListOf<String>()
        val imported = n.optInt("imported")
        if (imported > 0) {
            val titles = n.optJSONArray("titles")
            val names = if (titles == null) "" else (0 until titles.length()).joinToString(", ") { titles.optString(it) }
            lines.add(context.resources.getQuantityString(R.plurals.bf_n_imported, imported, imported) + if (names.isNotEmpty()) ": $names" else "")
        }
        val gone = n.optInt("gone")
        if (gone > 0) lines.add(context.resources.getQuantityString(R.plurals.bf_n_gone, gone, gone))
        val errors = n.optJSONArray("errors")
        if (errors != null && errors.length() > 0) lines.add(context.getString(R.string.bf_n_error, errors.optString(0)))
        return if (lines.isEmpty()) null else lines.joinToString("\n")
    }

    // allowed() is the check; a permission taken back in between is caught.
    @SuppressLint("MissingPermission")
    fun show(context: Context, id: Int, title: String, body: String, br: Boolean = false) {
        if (!allowed(context)) return
        channel(context, br)
        val open = PendingIntent.getActivity(
            context,
            id,
            Intent(context, MainActivity::class.java).setAction(if (br) ACTION_BR else ACTION_BF).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val n = NotificationCompat.Builder(context, if (br) CHANNEL_BR else CHANNEL)
            .setSmallIcon(R.drawable.ic_tile)
            .setContentTitle(title)
            .setContentText(body.lineSequence().first())
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setContentIntent(open)
            .setAutoCancel(true)
            .build()
        try {
            NotificationManagerCompat.from(context).notify(id, n)
        } catch (e: SecurityException) {
            // The permission was taken back between the check and now.
        }
    }
}

class NoticesWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val store = Store(applicationContext)
        if (!store.paired || !store.bfNotify || !Keys.exists()) return Result.success()
        val api = Api(store)
        for ((site, label) in store.bfSites) {
            try {
                val since = store.bfSince(site)
                val n = api.ability(site, "book-feed/notices", JSONObject().put("since", since), null).result
                val now = n.optLong("now")
                // The first look only sets the starting point: no flood of old news.
                if (since > 0) {
                    Notify.text(applicationContext, n)?.let { Notify.show(applicationContext, site.hashCode(), "Book Feed · $label", it) }
                }
                if (now > 0) store.setBfSince(site, now)
            } catch (e: HubError) {
                if (e.code == "unknown_device" || e.code == "app_off") return Result.success()
            } catch (e: Exception) {
                return Result.retry()
            }
        }
        return Result.success()
    }
}

/** Broadcast: new items waiting in the queue since the last look (ids only grow). */
class WaitingWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val store = Store(applicationContext)
        if (!store.paired || !store.brNotify || !Keys.exists()) return Result.success()
        val api = Api(store)
        for ((site, label) in store.brSites) {
            try {
                val a = api.ability(site, "broadcast/queue-list", JSONObject(), null).result.optJSONArray("items")
                val items = if (a == null) emptyList() else (0 until a.length()).mapNotNull { a.optJSONObject(it) }
                val seen = store.brSeen(site)
                val fresh = items.filter { it.optInt("id") > seen }
                // The first look only sets the starting point: no notice for what already waits.
                if (seen > 0 && fresh.isNotEmpty()) {
                    val names = fresh.take(3).joinToString(", ") { it.optString("title").ifEmpty { it.optString("source") } }
                    val body = applicationContext.resources.getQuantityString(R.plurals.br_n_waiting, fresh.size, fresh.size) + if (names.isNotEmpty()) ": $names" else ""
                    Notify.show(applicationContext, ("br" + site).hashCode(), "Broadcast · $label", body, br = true)
                }
                val top = items.maxOfOrNull { it.optInt("id") } ?: 0
                if (top > seen || seen == 0) store.setBrSeen(site, maxOf(top, seen, 1))
            } catch (e: HubError) {
                if (e.code == "unknown_device" || e.code == "app_off") return Result.success()
            } catch (e: Exception) {
                return Result.retry()
            }
        }
        return Result.success()
    }
}
