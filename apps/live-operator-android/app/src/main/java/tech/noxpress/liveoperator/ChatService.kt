package tech.noxpress.liveoperator

import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.PowerManager
import androidx.core.app.NotificationManagerCompat
import com.google.androidbrowserhelper.trusted.DelegationService

/**
 * The browser hands the site's notifications to this service (1.3.0).
 * Unlike the library's own, it puts them on a high-importance channel
 * ("Chat", heads-up with sound) and lights the screen for a few seconds
 * when it is off, so a new request or message is seen at once. Do Not
 * Disturb is respected: then only the notification shows, as before.
 */
class ChatService : DelegationService() {

    @SuppressLint("MissingPermission")
    override fun onNotifyNotificationWithChannel(
        platformTag: String,
        platformId: Int,
        notification: Notification,
        channelName: String,
    ): Boolean {
        val nm = getSystemService(NotificationManager::class.java)
        if (!enabled(nm)) return false
        val b = Notification.Builder.recoverBuilder(this, notification)
            .setChannelId(CHANNEL)
            .setCategory(Notification.CATEGORY_MESSAGE)
        if (notification.groupAlertBehavior == Notification.GROUP_ALERT_SUMMARY) {
            b.setGroupAlertBehavior(Notification.GROUP_ALERT_ALL)
        }
        val n = b.build()
        try {
            nm.notify(platformTag, platformId, n)
        } catch (e: SecurityException) {
            return false // The permission was taken back between the check and now.
        }
        wake(this, nm)
        return true
    }

    override fun onAreNotificationsEnabled(channelName: String): Boolean =
        enabled(getSystemService(NotificationManager::class.java))

    /** Creates the channel (no-op once made); false when notifications or the channel are off. */
    private fun enabled(nm: NotificationManager): Boolean {
        if (!NotificationManagerCompat.from(this).areNotificationsEnabled()) return false
        nm.createNotificationChannel(
            NotificationChannel(CHANNEL, getString(R.string.chat_channel), NotificationManager.IMPORTANCE_HIGH).apply {
                enableVibration(true)
                lockscreenVisibility = Notification.VISIBILITY_PRIVATE
            },
        )
        // The person can still turn the channel off in the system settings.
        return nm.getNotificationChannel(CHANNEL)?.importance != NotificationManager.IMPORTANCE_NONE
    }

    companion object {
        private const val CHANNEL = "chat"
        private const val WAKE_MS = 4_000L

        /** Turns the screen on for [WAKE_MS] when it is off and nothing silences the phone. */
        @Suppress("DEPRECATION") // ACQUIRE_CAUSES_WAKEUP still works; the replacement is only for activities.
        fun wake(context: Context, nm: NotificationManager) {
            if (nm.currentInterruptionFilter != NotificationManager.INTERRUPTION_FILTER_ALL) return
            val pm = context.getSystemService(PowerManager::class.java) ?: return
            if (pm.isInteractive) return
            try {
                pm.newWakeLock(
                    PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP,
                    "LiveOperator:chat",
                ).acquire(WAKE_MS)
            } catch (e: SecurityException) {
                // A future Android that refuses the wake: the notification is already shown.
            }
        }
    }
}
