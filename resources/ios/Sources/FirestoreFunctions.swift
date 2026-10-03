import Foundation
import FirebaseFirestore

/// Bridge functions for "Firestore.*". Each one decodes its JSON payload,
/// runs against the shared runtime and returns JSON in "result"; see
/// FirestorePluginCore.swift for the machinery.
enum FirestoreFunctions {

    class Configure: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                try NFSRuntime.shared.configure(payload)
            }
        }
    }

    class Status: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                NFSRuntime.shared.status()
            }
        }
    }

    class GetDocument: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let db = try NFSRuntime.shared.db()
                let ref = try NFSPaths.document((payload["path"] as? String) ?? "", db)
                return NFSCodec.document(try NFSOperations.fetch(ref, source: NFSOperations.source(payload["source"])))
            }
        }
    }

    class RunQuery: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let db = try NFSRuntime.shared.db()
                let query = try NFSQueryBuilder.build((payload["query"] as? [String: Any]) ?? [:], db)
                return try NFSOperations.runQuery(query, source: NFSOperations.source(payload["source"]))
            }
        }
    }

    class Count: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let db = try NFSRuntime.shared.db()
                return try NFSOperations.count(try NFSQueryBuilder.build((payload["query"] as? [String: Any]) ?? [:], db))
            }
        }
    }

    class Commit: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let db = try NFSRuntime.shared.db()
                let writes = try NFSWrite.decodeAll(payload["writes"], db)
                return try NFSOperations.commit(writes, waitForServer: (payload["waitForServer"] as? Bool) ?? false, db)
            }
        }
    }

    class TransactionBegin: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let db = try NFSRuntime.shared.db()
                let maxAttempts = (payload["maxAttempts"] as? NSNumber)?.intValue ?? 5
                return ["transactionId": try NFSTransactions.shared.begin(db, maxAttempts: maxAttempts)]
            }
        }
    }

    class TransactionGet: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let db = try NFSRuntime.shared.db()
                let session = try NFSTransactions.shared.session((payload["transactionId"] as? String) ?? "")
                let ref = try NFSPaths.document((payload["path"] as? String) ?? "", db)
                return try session.get(ref, timeout: NFSRuntime.shared.timeout)
            }
        }
    }

    class TransactionCommit: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let db = try NFSRuntime.shared.db()
                let id = (payload["transactionId"] as? String) ?? ""
                let session = try NFSTransactions.shared.session(id)

                do {
                    let writes = try NFSWrite.decodeAll(payload["writes"] ?? [], db)
                    let committed = try session.commit(writes, timeout: NFSRuntime.shared.timeout)
                    if committed {
                        NFSTransactions.shared.end(id)
                    }
                    return ["outcome": committed ? "committed" : "retry", "attempt": session.attempt]
                } catch {
                    session.abort(timeout: 2)
                    NFSTransactions.shared.end(id)
                    throw error
                }
            }
        }
    }

    class TransactionAbort: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let id = (payload["transactionId"] as? String) ?? ""
                if let session = try? NFSTransactions.shared.session(id) {
                    session.abort(timeout: NFSRuntime.shared.timeout)
                }
                NFSTransactions.shared.end(id)
                return ["aborted": true]
            }
        }
    }

    class Listen: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let db = try NFSRuntime.shared.db()
                let id = try NFSListeners.shared.add(
                    (payload["target"] as? [String: Any]) ?? [:],
                    includeMetadataChanges: (payload["includeMetadataChanges"] as? Bool) ?? false,
                    db
                )
                return ["listenerId": id]
            }
        }
    }

    class StopListening: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                NFSListeners.shared.remove((payload["listenerId"] as? String) ?? "")
                return ["stopped": true]
            }
        }
    }

    class StopAllListeners: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                ["stopped": NFSListeners.shared.removeAll()]
            }
        }
    }

    class EnableNetwork: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                let db = try NFSRuntime.shared.db()
                try NFSOperations.awaitCompletion("Enabling the network") { done in db.enableNetwork(completion: done) }
                return ["network": true]
            }
        }
    }

    class DisableNetwork: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                let db = try NFSRuntime.shared.db()
                try NFSOperations.awaitCompletion("Disabling the network") { done in db.disableNetwork(completion: done) }
                return ["network": false]
            }
        }
    }

    class WaitForPendingWrites: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                let db = try NFSRuntime.shared.db()
                try NFSOperations.awaitCompletion("Waiting for pending writes") { done in db.waitForPendingWrites(completion: done) }
                return ["synced": true]
            }
        }
    }
}
