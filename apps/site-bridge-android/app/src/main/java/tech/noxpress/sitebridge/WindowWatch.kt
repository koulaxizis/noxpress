package tech.noxpress.sitebridge

import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.concurrent.TimeUnit

/**
 * Write-window notifications (1.8.0, Site Bridge 1.12.0 on the Hub). No
 * Firebase: the phone learns every change of every site's window from the
 * Hub's /app/sites (read key, no fingerprint):
 *  - a periodic check every 15 minutes (Android's shortest), for changes
 *    nobody planned: opened or closed from WP admin, the Hub, Claude, a
 *    pause, a schedule edit;
 *  - one extra check at the next planned change of any site (end of an
 *    open window, the schedule's next opening, the end of a pause), so the
 *    schedule's own changes are told on time.
 * The first look at a site only stores its state (no flood). Changes made
 * from this app are stored right away by MainActivity, so they are not told
 * back. The channel can be silenced in Android's settings.
 */
object WindowWatch {

    private const val CHANNEL = "write_window"
    private const val WORK = "wn_watch"
    private const val WORK_NEXT = "wn_next"
    private val dayHm = DateTimeFormatter.ofPattern("EEE HH:mm")
    private val day = DateTimeFormatter.ofPattern("yyyy-MM-dd")

    fun schedule(context: Context, on: Boolean) {
        val wm = WorkManager.getInstance(context)
        if (!on) {
            wm.cancelUniqueWork(WORK)
            wm.cancelUniqueWork(WORK_NEXT)
            return
        }
        val req = PeriodicWorkRequestBuilder<WindowWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        wm.enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP, req)
    }

    /**
     * One check shortly after the next planned change of any site, or none.
     * Called by that check too: REPLACE then stops a run that has already
     * done its work, and the new one stays.
     */
    fun planNext(context: Context, sites: List<Site>, now: Long) {
        val next = sites.flatMap { listOf(it.windowUntil, it.nextOpen, it.pausedUntil) }.filter { it > now }.minOrNull()
        val wm = WorkManager.getInstance(context)
        if (next == null) {
            wm.cancelUniqueWork(WORK_NEXT)
            return
        }
        val req = OneTimeWorkRequestBuilder<WindowWorker>()
            .setInitialDelay(next - now + 20, TimeUnit.SECONDS)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        wm.enqueueUniqueWork(WORK_NEXT, ExistingWorkPolicy.REPLACE, req)
    }

    /** What is compared between two looks at a site. */
    private fun state(s: Site, now: Long): String {
        val open = s.windowUntil > now
        return listOf(
            if (open) s.windowUntil else 0L,
            if (open) s.source else "",
            if (s.pausedUntil > now) s.pausedUntil else 0L,
            if (s.scheduleOn) 1 else 0,
            s.nextOpen,
        ).joinToString("|")
    }

    /** Stores the state of these sites without telling anything (changes from this app). */
    fun remember(store: Store, sites: List<Site>, now: Long) {
        sites.forEach { store.setWnState(it.id, state(it, now)) }
    }

    /** Tells every change since the last look, and stores the new states. */
    fun tell(context: Context, store: Store, sites: List<Site>, now: Long) {
        for (s in sites) {
            if (!s.reachable) continue
            val old = store.wnState(s.id)
            val cur = state(s, now)
            store.setWnState(s.id, cur)
            if (old.isEmpty() || old == cur) continue
            text(context, old, s, now)?.let { show(context, s, it) }
        }
    }

    /** The notice for one site, or null when only an old time passed. */
    private fun text(context: Context, old: String, s: Site, now: Long): String? {
        val p = old.split("|")
        val oldUntil = p.getOrNull(0)?.toLongOrNull() ?: 0L
        val oldPaused = p.getOrNull(2)?.toLongOrNull() ?: 0L
        val oldOn = p.getOrNull(3) == "1"
        val oldNext = p.getOrNull(4)?.toLongOrNull() ?: 0L
        val wasOpen = oldUntil > 0
        val open = s.windowUntil > now
        val sched = s.source == "schedule"
        val lines = mutableListOf<String>()
        when {
            !wasOpen && open -> lines.add(
                context.getString(if (sched) R.string.wn_opened_sched else R.string.wn_opened, Ui.time(s.windowUntil)),
            )
            wasOpen && !open -> {
                lines.add(context.getString(if (oldUntil <= now + 60) R.string.wn_ended else R.string.wn_closed))
                if (s.pausedUntil > now) lines.add(context.getString(R.string.wn_paused, Ui.time(s.pausedUntil)))
            }
            wasOpen && open && oldUntil != s.windowUntil -> lines.add(
                context.getString(if (sched) R.string.wn_changed_sched else R.string.wn_changed, Ui.time(s.windowUntil)),
            )
            !open && s.pausedUntil > now && oldPaused != s.pausedUntil -> lines.add(context.getString(R.string.wn_paused, Ui.time(s.pausedUntil)))
        }
        if (oldOn != s.scheduleOn) {
            lines.add(context.getString(if (s.scheduleOn) R.string.wn_sched_on else R.string.wn_sched_off))
        } else if (s.scheduleOn && lines.isEmpty() && oldNext > now && oldNext != s.nextOpen) {
            // The next opening moved before it came: the schedule was edited.
            lines.add(context.getString(R.string.wn_sched_edited))
        }
        if (lines.isEmpty()) return null
        if (s.scheduleOn && !open && s.nextOpen > now) lines.add(context.getString(R.string.wn_next, at(s.nextOpen)))
        return lines.joinToString("\n")
    }

    /** «14:00» today, «Tue 09:00» on another day. */
    fun at(unix: Long): String {
        val z = Instant.ofEpochSecond(unix).atZone(ZoneId.systemDefault())
        val today = Instant.now().atZone(ZoneId.systemDefault())
        return if (day.format(z) == day.format(today)) Ui.time(unix) else dayHm.format(z)
    }

    @SuppressLint("MissingPermission") // allowed() is the check; a permission taken back in between is caught.
    private fun show(context: Context, s: Site, body: String) {
        if (!Notify.allowed(context)) return
        val nm = context.getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CHANNEL, context.getString(R.string.wn_channel), NotificationManager.IMPORTANCE_HIGH))
        val id = ("wn" + s.id).hashCode()
        val open = PendingIntent.getActivity(
            context,
            id,
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val n = NotificationCompat.Builder(context, CHANNEL)
            .setSmallIcon(R.drawable.ic_tile)
            .setContentTitle(context.getString(R.string.wn_title, s.label))
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

class WindowWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val store = Store(applicationContext)
        if (!store.paired || !store.wnNotify || !Keys.exists()) return Result.success()
        return try {
            val (sites, _) = Api(store).sites()
            val now = store.now()
            WindowWatch.tell(applicationContext, store, sites, now)
            WindowWatch.planNext(applicationContext, sites, now)
            Result.success()
        } catch (e: HubError) {
            if (e.code == "unknown_device" || e.code == "app_off") Result.success() else Result.retry()
        } catch (e: Exception) {
            Result.retry()
        }
    }
}
