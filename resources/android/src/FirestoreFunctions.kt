package com.vitisstudio.plugins.firestore

import androidx.fragment.app.FragmentActivity
import com.google.firebase.firestore.AggregateSource
import com.nativephp.mobile.bridge.BridgeFunction

/**
 * Bridge functions for "Firestore.*". Each decodes its JSON payload, runs
 * against the shared runtime and returns JSON in "result"; see
 * FirestorePluginCore.kt for the machinery.
 */
object FirestoreFunctions {

    class Configure(private val activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload -> NfsRuntime.configure(activity.applicationContext, payload) }
    }

    class Status(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { NfsRuntime.status() }
    }

    class GetDocument(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val db = NfsRuntime.db()
                val ref = NfsPaths.document(payload["path"] as? String ?: "", db)
                NfsCodec.document(NfsRuntime.await(ref.get(NfsOperations.source(payload["source"])), "Reading ${ref.path}"))
            }
    }

    class RunQuery(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        @Suppress("UNCHECKED_CAST")
        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val db = NfsRuntime.db()
                val query = NfsQueryBuilder.build(payload["query"] as? Map<String, Any?> ?: emptyMap(), db)
                NfsCodec.query(NfsRuntime.await(query.get(NfsOperations.source(payload["source"])), "Running query"))
            }
    }

    class Count(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        @Suppress("UNCHECKED_CAST")
        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val db = NfsRuntime.db()
                val query = NfsQueryBuilder.build(payload["query"] as? Map<String, Any?> ?: emptyMap(), db)
                val snapshot = NfsRuntime.await(query.count().get(AggregateSource.SERVER), "Counting")
                mapOf("count" to snapshot.count)
            }
    }

    class Commit(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val db = NfsRuntime.db()
                val writes = NfsWrite.decodeAll(payload["writes"], db)
                NfsOperations.commit(writes, payload["waitForServer"] as? Boolean ?: false, db)
            }
    }

    class TransactionBegin(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val maxAttempts = (payload["maxAttempts"] as? Number)?.toInt() ?: 5
                mapOf("transactionId" to NfsTransactions.begin(NfsRuntime.db(), maxAttempts))
            }
    }

    class TransactionGet(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val db = NfsRuntime.db()
                val session = NfsTransactions.session(payload["transactionId"] as? String ?: "")
                session.get(NfsPaths.document(payload["path"] as? String ?: "", db), NfsRuntime.timeoutMillis)
            }
    }

    class TransactionCommit(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val db = NfsRuntime.db()
                val id = payload["transactionId"] as? String ?: ""
                val session = NfsTransactions.session(id)

                try {
                    val writes = NfsWrite.decodeAll(payload["writes"] ?: emptyList<Any?>(), db)
                    val committed = session.commit(writes, NfsRuntime.timeoutMillis)
                    if (committed) NfsTransactions.end(id)
                    mapOf("outcome" to if (committed) "committed" else "retry", "attempt" to session.attempt)
                } catch (error: Exception) {
                    session.abort(2_000)
                    NfsTransactions.end(id)
                    throw error
                }
            }
    }

    class TransactionAbort(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val id = payload["transactionId"] as? String ?: ""
                NfsTransactions.find(id)?.abort(NfsRuntime.timeoutMillis)
                NfsTransactions.end(id)
                mapOf("aborted" to true)
            }
    }

    class Listen(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        @Suppress("UNCHECKED_CAST")
        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                val id = NfsListeners.add(
                    payload["target"] as? Map<String, Any?> ?: emptyMap(),
                    payload["includeMetadataChanges"] as? Boolean ?: false,
                    NfsRuntime.db(),
                )
                mapOf("listenerId" to id)
            }
    }

    class StopListening(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { payload ->
                NfsListeners.remove(payload["listenerId"] as? String ?: "")
                mapOf("stopped" to true)
            }
    }

    class StopAllListeners(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) { mapOf("stopped" to NfsListeners.removeAll()) }
    }

    class EnableNetwork(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                NfsRuntime.await(NfsRuntime.db().enableNetwork(), "Enabling the network")
                mapOf("network" to true)
            }
    }

    class DisableNetwork(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                NfsRuntime.await(NfsRuntime.db().disableNetwork(), "Disabling the network")
                mapOf("network" to false)
            }
    }

    class WaitForPendingWrites(activity: FragmentActivity) : BridgeFunction {
        init { NfsRuntime.attach(activity) }

        override fun execute(parameters: Map<String, Any>): Map<String, Any> =
            NfsBridge.handle(parameters) {
                NfsRuntime.await(NfsRuntime.db().waitForPendingWrites(), "Waiting for pending writes")
                mapOf("synced" to true)
            }
    }
}
