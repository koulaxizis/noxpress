package tech.noxpress.liveoperator

import android.Manifest
import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
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
import java.util.concurrent.TimeUnit

/**
 * A notification for a newer signed app (1.2.0), also while the app is
 * closed: WorkManager looks at the releases every 12 hours, and a newer
 * version is told once, with a tap that downloads its APK in the browser.
 * The permission is the one the app asks for the chat notifications.
 */
object UpdateNotice {

    private const val CHANNEL = "app_update"
    private const val WORK = "app_update"
    private const val ID = 0x10A
    private const val PREFS = "lo_update"

    fun schedule(context: Context) {
        val req = PeriodicWorkRequestBuilder<UpdateWorker>(12, TimeUnit.HOURS)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP, req)
    }

    private fun allowed(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    /** Tells [f] once per version; quiet when notifications are not allowed. */
    @SuppressLint("MissingPermission")
    fun show(context: Context, f: Updates.Found) {
        val p = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (p.getString("notified", "") == f.version || !allowed(context)) return
        val nm = context.getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(
            NotificationChannel(CHANNEL, context.getString(R.string.update_channel), NotificationManager.IMPORTANCE_DEFAULT),
        )
        val get = PendingIntent.getActivity(
            context,
            ID,
            Intent(Intent.ACTION_VIEW, Uri.parse(f.url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val n = NotificationCompat.Builder(context, CHANNEL)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(context.getString(R.string.update_available, f.version))
            .setContentText(context.getString(R.string.update_tap))
            .setContentIntent(get)
            .addAction(0, context.getString(R.string.update_get), get)
            .setAutoCancel(true)
            .build()
        try {
            NotificationManagerCompat.from(context).notify(ID, n)
            p.edit().putString("notified", f.version).apply()
        } catch (e: SecurityException) {
            // The permission was taken back between the check and now.
        }
    }
}

class UpdateWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val f = try {
            Updates.check(BuildConfig.VERSION_NAME)
        } catch (e: Exception) {
            return Result.success() // A failed look stays silent; the next one comes in 12 hours.
        }
        if (f != null) UpdateNotice.show(applicationContext, f)
        return Result.success()
    }
}
