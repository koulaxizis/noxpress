package tech.noxpress.liveoperator

import android.app.Activity
import android.content.Intent
import android.graphics.Color
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.text.InputType
import android.util.TypedValue
import android.view.Gravity
import android.view.inputmethod.EditorInfo
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * The app's entry (2.0.0). With a connected site it opens it at once
 * ([Launcher], the Trusted Web Activity). Without one, from the «Change
 * site» shortcut, or from the plugin's «Connect the app» link, it shows
 * the connect screen: the address of the site, checked against the site's
 * assetlinks.json before it is kept. A link only fills in the address; the
 * user always taps Connect, so a page elsewhere cannot switch the site.
 */
class Setup : Activity() {

    private lateinit var input: EditText
    private lateinit var status: TextView
    private lateinit var connect: Button
    private var anyway: String? = null
    private val main = Handler(Looper.getMainLooper())

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val link = intent?.takeIf { it.action == Intent.ACTION_VIEW }?.dataString
        val change = intent?.action == ACTION_CHANGE
        val current = Site.page(this)
        UpdateNotice.schedule(applicationContext)
        if (link == null && !change && current != null) {
            open()
            return
        }
        build(current)
        val fromLink = link?.let { Site.fromDeepLink(it) }
        when {
            fromLink != null -> {
                input.setText(fromLink)
                status.text = getString(R.string.setup_from_link, Site.host(fromLink))
            }
            link != null -> status.text = getString(R.string.setup_bad_link)
            current != null -> status.text = getString(R.string.setup_current, Site.host(current))
        }
    }

    private fun dp(v: Int): Int =
        TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v.toFloat(), resources.displayMetrics).toInt()

    private fun build(current: String?) {
        val text = Color.parseColor("#E4E2F5")
        val muted = Color.parseColor("#9B97B8")
        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(24), dp(48), dp(24), dp(24))
        }
        box.addView(TextView(this).apply {
            setText(R.string.app_name)
            setTextColor(text)
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 26f)
        })
        box.addView(TextView(this).apply {
            setText(R.string.setup_intro)
            setTextColor(muted)
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 15f)
            setPadding(0, dp(12), 0, dp(20))
        })
        input = EditText(this).apply {
            hint = getString(R.string.setup_hint)
            setTextColor(text)
            setHintTextColor(muted)
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
            imeOptions = EditorInfo.IME_ACTION_GO
            isSingleLine = true
            if (current != null) setText(current)
            setOnEditorActionListener { _, id, _ ->
                if (id == EditorInfo.IME_ACTION_GO) { check(); true } else false
            }
        }
        box.addView(input)
        connect = Button(this).apply {
            setText(R.string.setup_connect)
            setOnClickListener { check() }
        }
        box.addView(connect, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(12) })
        status = TextView(this).apply {
            setTextColor(muted)
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 14f)
            setPadding(0, dp(16), 0, 0)
            gravity = Gravity.START
        }
        box.addView(status)
        setContentView(ScrollView(this).apply { addView(box) })
    }

    /** Checks the typed address; a second tap after «not listed» connects anyway. */
    private fun check() {
        val page = Site.fromInput(input.text.toString())
        if (page == null) {
            status.text = getString(R.string.setup_invalid)
            return
        }
        if (anyway == page) {
            done(page)
            return
        }
        anyway = null
        connect.isEnabled = false
        connect.setText(R.string.setup_checking)
        status.text = ""
        thread {
            val result = try {
                val c = URL(Site.assetlinksUrl(page)).openConnection() as HttpURLConnection
                c.connectTimeout = 10_000
                c.readTimeout = 10_000
                c.instanceFollowRedirects = true
                try {
                    if (c.responseCode == 200) {
                        if (Site.listsApp(c.inputStream.bufferedReader().use { it.readText() })) LISTED else NOT_LISTED
                    } else {
                        NOT_LISTED
                    }
                } finally {
                    c.disconnect()
                }
            } catch (e: Exception) {
                UNREACHABLE
            }
            main.post { checked(page, result) }
        }
    }

    private fun checked(page: String, result: Int) {
        if (isFinishing) return
        connect.isEnabled = true
        connect.setText(R.string.setup_connect)
        when (result) {
            LISTED -> done(page)
            NOT_LISTED -> {
                anyway = page
                status.text = getString(R.string.setup_not_listed, Site.host(page))
                connect.setText(R.string.setup_connect_anyway)
            }
            else -> status.text = getString(R.string.setup_unreachable, Site.host(page))
        }
    }

    private fun done(page: String) {
        Site.save(this, page)
        open()
    }

    /**
     * Starts the Trusted Web Activity in a task of its own (2.0.1). Without
     * FLAG_ACTIVITY_NEW_TASK, androidbrowserhelper's LauncherActivity restarts
     * itself in a new task; the second instance then sees the first still
     * alive, takes it for a running TWA and closes, so the app shut at once.
     */
    private fun open() {
        startActivity(Intent(this, Launcher::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        finish()
    }

    companion object {
        const val ACTION_CHANGE = "tech.noxpress.liveoperator.CHANGE"
        private const val LISTED = 0
        private const val NOT_LISTED = 1
        private const val UNREACHABLE = 2
    }
}
