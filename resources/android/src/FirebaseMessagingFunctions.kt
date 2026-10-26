package com.vitisstudio.plugins.firestore

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.google.firebase.messaging.FirebaseMessaging
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Bridge functions for "FirebaseMessaging.*". Android delivers notifications
 * itself when the app is in the background; a tap opens the launcher activity
 * with the message's data as intent extras, which TakeOpenedNotification
 * reads once.
 */
object FirebaseMessagingFunctions {

    private const val REQUEST_CODE = 0x4E46

    private val consumedIntents = java.util.Collections.newSetFromMap(java.util.WeakHashMap<android.content.Intent, Boolean>())

    private fun permission(activity: FragmentActivity): String = when {
        NotificationManagerCompat.from(activity).areNotificationsEnabled() -> "granted"
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(activity, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED &&
            !ActivityCompat.shouldShowRequestPermissionRationale(activity, Manifest.permission.POST_NOTIFICATIONS) -> "not_determined"
        else -> "denied"
    }

    private fun messaging(): FirebaseMessaging {
        NfsRuntime.auth() // throws not-configured before Firestore.Configure
        return FirebaseMessaging.getInstance()
    }

    /**
     * Shows the system prompt on Android 13+ and returns the status as it
     * stands now: the answer arrives after this returns, so callers check
     * PermissionStatus again on resume.
     */
    class RequestPermission(private val activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
                    ContextCompat.checkSelfPermission(activity, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
                ) {
                    activity.runOnUiThread {
                        ActivityCompat.requestPermissions(activity, arrayOf(Manifest.permission.POST_NOTIFICATIONS), REQUEST_CODE)
                    }
                }
                mapOf("status" to permission(activity))
            }
    }

    class PermissionStatus(private val activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { mapOf("status" to permission(activity)) }
    }

    class GetToken(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                mapOf("token" to NfsRuntime.await(messaging().token, "Fetching the FCM token"))
            }
    }

    class DeleteToken(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                NfsRuntime.await(messaging().deleteToken(), "Deleting the FCM token")
                mapOf("deleted" to true)
            }
    }

    class TakeOpenedNotification(private val activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                val intent = activity.intent
                val extras = intent?.extras
                if (intent == null || extras == null || !extras.containsKey("google.message_id") || !consumedIntents.add(intent)) {
                    mapOf("notification" to null)
                } else {
                    val data = extras.keySet()
                        .filterNot { it.startsWith("google.") || it.startsWith("gcm.") || it == "from" || it == "collapse_key" }
                        .associateWith { extras.get(it)?.toString() }
                    val notification = mapOf("title" to null, "body" to null, "data" to data)
                    NfsBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\NotificationOpened", notification)
                    mapOf("notification" to notification)
                }
            }
    }

    /** Launchers own the badge on Android; nothing to set. */
    class SetBadgeCount(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload -> mapOf("count" to ((payload["count"] as? Number)?.toLong() ?: 0L)) }
    }
}
