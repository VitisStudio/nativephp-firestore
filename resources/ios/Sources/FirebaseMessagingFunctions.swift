import Foundation
import UIKit
import UserNotifications
import FirebaseCore
import FirebaseMessaging

/// Push notifications through Firebase Cloud Messaging. NativePHP core only
/// forwards the APNs device token (as a NotificationCenter post); this hands it
/// to FCM, owns the notification-center delegate (banners in the foreground,
/// taps) and reports both to PHP.
///
/// `install()` is the plugin's iOS init function, so it runs in the app's init,
/// before launch finishes: a tap that cold-starts the app is not lost. PHP is
/// not running yet then, so the latest tap is also kept for
/// FirebaseMessaging.TakeOpenedNotification.
final class NFSMessaging: NSObject, UNUserNotificationCenterDelegate, MessagingDelegate {
    static let shared = NFSMessaging()

    private let lock = NSLock()
    private var apnsToken: Data?
    private var apnsError: Error?
    private var apnsWaiters: [NFSWaiter<Data>] = []
    private var opened: [String: Any]?
    private var attached = false

    static func install() {
        UNUserNotificationCenter.current().delegate = shared

        let center = NotificationCenter.default
        center.addObserver(forName: Notification.Name("NativePHP.didRegisterForRemoteNotifications"), object: nil, queue: nil) { note in
            if let token = note.userInfo?["deviceToken"] as? Data {
                shared.received(token: token)
            }
        }
        center.addObserver(forName: Notification.Name("NativePHP.didFailToRegisterForRemoteNotifications"), object: nil, queue: nil) { note in
            shared.failed(note.userInfo?["error"] as? Error ?? NFSError(code: "unavailable", message: "APNs registration failed."))
        }
    }

    /// Messaging needs the FirebaseApp that Firestore.Configure creates.
    func messaging() throws -> Messaging {
        guard FirebaseApp.app() != nil, NFSRuntime.shared.isConfigured else {
            throw NFSError.notConfigured("Firebase is not configured yet. Call Firestore.Configure first.")
        }

        let messaging = Messaging.messaging()

        lock.lock()
        let first = !attached
        attached = true
        let token = apnsToken
        lock.unlock()

        if first {
            messaging.delegate = self
        }
        if let token, messaging.apnsToken == nil {
            messaging.apnsToken = token
        }

        return messaging
    }

    /// The APNs token, asking iOS to register when there isn't one yet.
    func awaitApnsToken(_ timeout: TimeInterval) throws -> Data {
        let waiter = NFSWaiter<Data>()

        lock.lock()
        if let token = apnsToken {
            lock.unlock()
            return token
        }
        apnsError = nil
        apnsWaiters.append(waiter)
        lock.unlock()

        DispatchQueue.main.async { UIApplication.shared.registerForRemoteNotifications() }

        return try waiter.wait(timeout, "Registering with Apple Push Notification service")
    }

    func takeOpened() -> [String: Any]? {
        lock.lock()
        defer { lock.unlock() }
        let value = opened
        opened = nil
        return value
    }

    private func received(token: Data) {
        lock.lock()
        apnsToken = token
        let waiters = apnsWaiters
        apnsWaiters = []
        let isAttached = attached
        lock.unlock()

        if isAttached {
            Messaging.messaging().apnsToken = token
        }
        waiters.forEach { $0.succeed(token) }
    }

    private func failed(_ error: Error) {
        lock.lock()
        apnsError = error
        let waiters = apnsWaiters
        apnsWaiters = []
        lock.unlock()

        waiters.forEach { $0.fail(error) }
    }

    /// The custom keys of a notification (what the sender put in `data`).
    static func data(_ userInfo: [AnyHashable: Any]) -> [String: Any] {
        var data: [String: Any] = [:]
        for (key, value) in userInfo {
            guard let key = key as? String, key != "aps", !key.hasPrefix("gcm."), !key.hasPrefix("google.") else { continue }
            data[key] = NFSCodec.encode(value)
        }
        return data
    }

    // MARK: UNUserNotificationCenterDelegate

    /// In the foreground: still show the banner, and tell PHP so it can refresh.
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        let content = notification.request.content
        NFSBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\NotificationReceived", [
            "title": content.title,
            "body": content.body,
            "data": NFSMessaging.data(content.userInfo),
        ])
        completionHandler([.banner, .list, .sound, .badge])
    }

    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let content = response.notification.request.content
        let payload: [String: Any] = [
            "title": content.title,
            "body": content.body,
            "data": NFSMessaging.data(content.userInfo),
        ]

        lock.lock()
        opened = payload
        lock.unlock()

        NFSBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\NotificationOpened", payload)
        completionHandler()
    }

    // MARK: MessagingDelegate

    func messaging(_ messaging: Messaging, didReceiveRegistrationToken fcmToken: String?) {
        guard let fcmToken, !fcmToken.isEmpty else { return }
        NFSBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\MessagingTokenRefreshed", ["token": fcmToken])
    }
}

/// Bridge functions for "FirebaseMessaging.*".
enum FirebaseMessagingFunctions {

    static func permission(_ status: UNAuthorizationStatus) -> String {
        switch status {
        case .authorized: return "granted"
        case .denied: return "denied"
        case .provisional: return "provisional"
        case .ephemeral: return "ephemeral"
        case .notDetermined: return "not_determined"
        @unknown default: return "not_determined"
        }
    }

    static func currentPermission() throws -> String {
        let waiter = NFSWaiter<UNAuthorizationStatus>()
        UNUserNotificationCenter.current().getNotificationSettings { waiter.succeed($0.authorizationStatus) }
        return permission(try waiter.wait(NFSRuntime.shared.timeout, "Reading notification settings"))
    }

    /// Ask once (iOS shows the prompt only the first time), then register with APNs when allowed.
    class RequestPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                let waiter = NFSWaiter<Bool>()
                UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { granted, error in
                    if let error {
                        waiter.fail(error)
                    } else {
                        waiter.succeed(granted)
                    }
                }
                // The prompt waits on the person, not the network.
                let granted = try waiter.wait(max(NFSRuntime.shared.timeout, 300), "Asking for notification permission")

                if granted {
                    DispatchQueue.main.async { UIApplication.shared.registerForRemoteNotifications() }
                }

                return ["status": try FirebaseMessagingFunctions.currentPermission()]
            }
        }
    }

    class PermissionStatus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                ["status": try FirebaseMessagingFunctions.currentPermission()]
            }
        }
    }

    /// The FCM registration token for this install. Needs the APNs token first.
    class GetToken: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                let messaging = try NFSMessaging.shared.messaging()

                if messaging.apnsToken == nil {
                    messaging.apnsToken = try NFSMessaging.shared.awaitApnsToken(NFSRuntime.shared.timeout)
                }

                let waiter = NFSWaiter<String>()
                messaging.token { token, error in
                    if let token, !token.isEmpty {
                        waiter.succeed(token)
                    } else {
                        waiter.fail(error ?? NFSError(code: "unavailable", message: "No FCM token returned."))
                    }
                }

                return ["token": try waiter.wait(NFSRuntime.shared.timeout, "Fetching the FCM token")]
            }
        }
    }

    class DeleteToken: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                let messaging = try NFSMessaging.shared.messaging()
                try NFSOperations.awaitCompletion("Deleting the FCM token") { done in
                    messaging.deleteToken(completion: done)
                }
                return ["deleted": true]
            }
        }
    }

    /// The notification whose tap opened the app (once), for cold starts.
    class TakeOpenedNotification: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { _ in
                ["notification": NFSMessaging.shared.takeOpened() ?? NSNull()]
            }
        }
    }

    class SetBadgeCount: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            NFSBridge.handle(parameters) { payload in
                let count = max(0, (payload["count"] as? NSNumber)?.intValue ?? 0)
                let waiter = NFSWaiter<Bool>()
                if #available(iOS 16.0, *) {
                    UNUserNotificationCenter.current().setBadgeCount(count) { error in
                        if let error { waiter.fail(error) } else { waiter.succeed(true) }
                    }
                } else {
                    DispatchQueue.main.async {
                        UIApplication.shared.applicationIconBadgeNumber = count
                        waiter.succeed(true)
                    }
                }
                _ = try waiter.wait(NFSRuntime.shared.timeout, "Setting the badge count")
                return ["count": count]
            }
        }
    }
}
