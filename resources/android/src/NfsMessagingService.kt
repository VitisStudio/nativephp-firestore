package com.vitisstudio.plugins.firestore

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * Receives FCM callbacks: a rotated token, and messages that arrive while the
 * app is in the foreground (Android shows background ones itself).
 */
class NfsMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        NfsBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\MessagingTokenRefreshed", mapOf("token" to token))
    }

    override fun onMessageReceived(message: RemoteMessage) {
        NfsBridge.dispatch(
            "VitisStudio\\NativeFirestore\\Events\\NotificationReceived",
            mapOf(
                "title" to message.notification?.title,
                "body" to message.notification?.body,
                "data" to message.data,
            ),
        )
    }
}
