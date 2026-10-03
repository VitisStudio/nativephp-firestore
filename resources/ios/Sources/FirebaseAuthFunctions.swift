import Foundation
import FirebaseAuth

/// Bridge functions for "FirebaseAuth.*". Auth shares the FirebaseApp that
/// Firestore.Configure set up, so Firestore requests carry the signed-in user.
enum FirebaseAuthFunctions {

    class SignInWithEmailAndPassword: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let auth = try NFSRuntime.shared.auth()
                let email = (payload["email"] as? String) ?? ""
                let password = (payload["password"] as? String) ?? ""
                return try NFSAuthSupport.authResult("Sign-in") { done in
                    auth.signIn(withEmail: email, password: password, completion: done)
                }
            }
        }
    }

    class CreateUserWithEmailAndPassword: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let auth = try NFSRuntime.shared.auth()
                let email = (payload["email"] as? String) ?? ""
                let password = (payload["password"] as? String) ?? ""
                return try NFSAuthSupport.authResult("Creating the account") { done in
                    auth.createUser(withEmail: email, password: password, completion: done)
                }
            }
        }
    }

    class SignInAnonymously: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                let auth = try NFSRuntime.shared.auth()
                return try NFSAuthSupport.authResult("Anonymous sign-in") { done in
                    auth.signInAnonymously(completion: done)
                }
            }
        }
    }

    class SignOut: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                try NFSRuntime.shared.auth().signOut()
                return ["signedOut": true]
            }
        }
    }

    class CurrentUser: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                ["user": NFSAuthSupport.encode(try NFSRuntime.shared.auth().currentUser)]
            }
        }
    }

    class GetIdToken: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                guard let user = try NFSRuntime.shared.auth().currentUser else {
                    return ["token": NSNull()]
                }
                let forceRefresh = (payload["forceRefresh"] as? Bool) ?? false
                let waiter = NFSWaiter<String>()
                user.getIDTokenForcingRefresh(forceRefresh) { token, error in
                    if let token {
                        waiter.succeed(token)
                    } else {
                        waiter.fail(error ?? NFSError(code: "unknown", message: "No ID token returned."))
                    }
                }
                return ["token": try waiter.wait(NFSRuntime.shared.timeout, "Fetching the ID token")]
            }
        }
    }

    class SendPasswordResetEmail: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let auth = try NFSRuntime.shared.auth()
                let email = (payload["email"] as? String) ?? ""
                try NFSOperations.awaitCompletion("Sending the password reset email") { done in
                    auth.sendPasswordReset(withEmail: email, completion: done)
                }
                return ["sent": true]
            }
        }
    }

    class Listen: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                NFSAuthListener.shared.start(try NFSRuntime.shared.auth())
                return ["listening": true]
            }
        }
    }

    class StopListening: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                NFSAuthListener.shared.stop(try NFSRuntime.shared.auth())
                return ["listening": false]
            }
        }
    }
}
