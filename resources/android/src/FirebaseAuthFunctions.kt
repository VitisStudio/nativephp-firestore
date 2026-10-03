package com.vitisstudio.plugins.firestore

import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Bridge functions for "FirebaseAuth.*". Auth shares the FirebaseApp that
 * Firestore.Configure set up, so Firestore requests carry the signed-in user.
 */
object FirebaseAuthFunctions {

    class SignInWithEmailAndPassword(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val result = NfsRuntime.await(
                    NfsRuntime.auth().signInWithEmailAndPassword(NfsAuthSupport.required(payload, "email"), NfsAuthSupport.required(payload, "password")),
                    "Sign-in",
                )
                mapOf("user" to NfsAuthSupport.encode(result.user))
            }
    }

    class CreateUserWithEmailAndPassword(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val result = NfsRuntime.await(
                    NfsRuntime.auth().createUserWithEmailAndPassword(NfsAuthSupport.required(payload, "email"), NfsAuthSupport.required(payload, "password")),
                    "Creating the account",
                )
                mapOf("user" to NfsAuthSupport.encode(result.user))
            }
    }

    class SignInAnonymously(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                val result = NfsRuntime.await(NfsRuntime.auth().signInAnonymously(), "Anonymous sign-in")
                mapOf("user" to NfsAuthSupport.encode(result.user))
            }
    }

    class SignOut(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                NfsRuntime.auth().signOut()
                mapOf("signedOut" to true)
            }
    }

    class CurrentUser(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { mapOf("user" to NfsAuthSupport.encode(NfsRuntime.auth().currentUser)) }
    }

    class GetIdToken(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val user = NfsRuntime.auth().currentUser
                if (user == null) {
                    mapOf("token" to null)
                } else {
                    val forceRefresh = payload["forceRefresh"] as? Boolean ?: false
                    mapOf("token" to NfsRuntime.await(user.getIdToken(forceRefresh), "Fetching the ID token").token)
                }
            }
    }

    class SendPasswordResetEmail(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                NfsRuntime.await(NfsRuntime.auth().sendPasswordResetEmail(NfsAuthSupport.required(payload, "email")), "Sending the password reset email")
                mapOf("sent" to true)
            }
    }

    class Listen(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                NfsAuthSupport.startListening(NfsRuntime.auth())
                mapOf("listening" to true)
            }
    }

    class StopListening(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                NfsAuthSupport.stopListening(NfsRuntime.auth())
                mapOf("listening" to false)
            }
    }
}
