import Foundation
import FirebaseCore
import FirebaseFirestore
import FirebaseAuth

// Shared machinery for the Firestore / FirebaseAuth bridge functions.
//
// Threading: NativePHP invokes bridge functions on its PHP thread, never the
// main thread, so functions may block until the Firebase SDK calls back.
// Firestore callbacks are delivered on a private queue; Auth callbacks arrive
// on the main queue. Events to PHP are always sent from the main thread.
//
// Crash safety: the iOS Firebase SDKs report misuse with Objective-C
// exceptions, which Swift cannot catch. Everything that could raise one
// (paths, field names, query shapes, sentinel placement, FirebaseApp options)
// is validated here first and reported to PHP as a normal error instead.

// MARK: - Errors

struct NFSError: Error {
    let code: String
    let message: String

    static func invalid(_ message: String) -> NFSError {
        NFSError(code: "invalid-argument", message: message)
    }

    static func notConfigured(_ message: String) -> NFSError {
        NFSError(code: "not-configured", message: message)
    }

    static func from(_ error: Error) -> NFSError {
        if let known = error as? NFSError {
            return known
        }

        let nsError = error as NSError

        if nsError.domain == FirestoreErrorDomain {
            return NFSError(code: NFSError.firestoreCode(nsError.code), message: nsError.localizedDescription)
        }

        if nsError.domain == "FIRAuthErrorDomain" {
            let name = (nsError.userInfo["FIRAuthErrorUserInfoNameKey"] as? String) ?? "ERROR_UNKNOWN"
            let code = name.replacingOccurrences(of: "ERROR_", with: "").lowercased().replacingOccurrences(of: "_", with: "-")
            return NFSError(code: code, message: nsError.localizedDescription)
        }

        return NFSError(code: "unknown", message: nsError.localizedDescription)
    }

    static func firestoreCode(_ raw: Int) -> String {
        let names = [
            1: "cancelled", 2: "unknown", 3: "invalid-argument", 4: "deadline-exceeded",
            5: "not-found", 6: "already-exists", 7: "permission-denied", 8: "resource-exhausted",
            9: "failed-precondition", 10: "aborted", 11: "out-of-range", 12: "unimplemented",
            13: "internal", 14: "unavailable", 15: "data-loss", 16: "unauthenticated",
        ]
        return names[raw] ?? "unknown"
    }
}

// MARK: - Bridge envelope

enum NFSBridge {
    /// Decode the JSON "payload" string, run the body, and JSON-encode its
    /// result into "result". Errors become NativePHP error envelopes.
    static func handle(_ parameters: [String: Any], _ body: ([String: Any]) throws -> [String: Any]) -> [String: Any] {
        do {
            let payload = try decodePayload(parameters)
            let result = try body(payload)
            let data = try JSONSerialization.data(withJSONObject: result, options: [])
            return BridgeResponse.success(data: ["result": String(data: data, encoding: .utf8) ?? "{}"])
        } catch {
            let failure = NFSError.from(error)
            NSLog("[NativeFirestore] %@: %@", failure.code, failure.message)
            return BridgeResponse.error(code: failure.code, message: failure.message)
        }
    }

    private static func decodePayload(_ parameters: [String: Any]) throws -> [String: Any] {
        guard let raw = parameters["payload"] as? String else {
            return parameters
        }

        guard let data = raw.data(using: .utf8),
              let object = try JSONSerialization.jsonObject(with: data, options: [.fragmentsAllowed]) as? [String: Any] else {
            throw NFSError.invalid("Bridge payload is not a JSON object.")
        }

        return object
    }

    /// Deliver an event to PHP (NativeComponent #[On] handlers / Livewire).
    static func dispatch(_ event: String, _ payload: [String: Any]) {
        let send = {
            var converted: [String: Any?] = [:]
            for (key, value) in payload {
                converted[key] = value
            }
            LaravelBridge.shared.send?(event, converted)
        }

        if Thread.isMainThread {
            send()
        } else {
            DispatchQueue.main.async(execute: send)
        }
    }
}

// MARK: - Blocking waits

/// Bridges the SDKs' callbacks back to the synchronous bridge call.
final class NFSWaiter<T> {
    private let semaphore = DispatchSemaphore(value: 0)
    private let lock = NSLock()
    private var outcome: Result<T, Error>?

    func resolve(_ result: Result<T, Error>) {
        lock.lock()
        guard outcome == nil else {
            lock.unlock()
            return
        }
        outcome = result
        lock.unlock()
        semaphore.signal()
    }

    func succeed(_ value: T) { resolve(.success(value)) }

    func fail(_ error: Error) { resolve(.failure(error)) }

    func wait(_ timeout: TimeInterval, _ what: String) throws -> T {
        if Thread.isMainThread {
            // Never expected (PHP runs off the main thread), but spinning the
            // run loop keeps main-queue callbacks deliverable instead of deadlocking.
            let deadline = Date().addingTimeInterval(timeout)
            while current() == nil && Date() < deadline {
                RunLoop.current.run(mode: .default, before: Date().addingTimeInterval(0.01))
            }
        } else {
            _ = semaphore.wait(timeout: .now() + timeout)
        }

        guard let result = current() else {
            throw NFSError(code: "deadline-exceeded", message: "\(what) did not complete within \(Int(timeout))s.")
        }

        return try result.get()
    }

    private func current() -> Result<T, Error>? {
        lock.lock()
        defer { lock.unlock() }
        return outcome
    }
}

// MARK: - Runtime (FirebaseApp + Firestore instance)

final class NFSRuntime {
    static let shared = NFSRuntime()

    /// Firestore delivers every callback here, so blocking the PHP thread
    /// never starves them.
    let callbackQueue = DispatchQueue(label: "com.vitisstudio.nativefirestore.callbacks", qos: .userInitiated)

    private let lock = NSRecursiveLock()
    private var firestore: Firestore?
    private var configurationSource = ""
    private var emulatorEnabled = false

    private(set) var timeout: TimeInterval = 15
    private(set) var serverTimestamps: ServerTimestampBehavior = .none

    private static let iosAppIdPattern = "^1:[0-9]+:ios:[0-9a-fA-F]+$"
    private static let demoAppId = "1:123456789000:ios:0000000000000000"
    private static let demoApiKey = "AIzaSyDemoKeyForTheFirebaseEmulators000"

    var isConfigured: Bool {
        lock.lock()
        defer { lock.unlock() }
        return firestore != nil
    }

    /// Initialise FirebaseApp (unless the app or another plugin already did)
    /// and apply Firestore/Auth settings once per launch.
    func configure(_ payload: [String: Any]) throws -> [String: Any] {
        lock.lock()
        defer { lock.unlock() }

        if firestore != nil {
            return status(alreadyConfigured: true)
        }

        if let seconds = payload["timeout"] as? NSNumber, seconds.doubleValue > 0 {
            timeout = seconds.doubleValue
        }

        switch payload["serverTimestamps"] as? String {
        case "estimate": serverTimestamps = .estimate
        case "previous": serverTimestamps = .previous
        default: serverTimestamps = .none
        }

        if FirebaseApp.app() == nil {
            let (options, source) = try resolveOptions(payload["options"] as? [String: Any] ?? [:])
            FirebaseApp.configure(options: options)
            configurationSource = source
        } else {
            configurationSource = "existing-app"
        }

        guard let app = FirebaseApp.app() else {
            throw NFSError.notConfigured("FirebaseApp could not be initialised.")
        }

        let database = (payload["database"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? "(default)"
        let instance = database == "(default)" ? Firestore.firestore(app: app) : Firestore.firestore(app: app, database: database)

        let settings = instance.settings
        settings.dispatchQueue = callbackQueue

        let persistence = payload["persistence"] as? [String: Any] ?? [:]
        if (persistence["enabled"] as? Bool) ?? true {
            let requested = (persistence["cacheSizeBytes"] as? NSNumber)?.int64Value ?? 104_857_600
            let size = requested < 0 ? FirestoreCacheSizeUnlimited : max(requested, 1_048_576)
            settings.cacheSettings = PersistentCacheSettings(sizeBytes: NSNumber(value: size))
        } else {
            settings.cacheSettings = MemoryCacheSettings()
        }

        let emulator = payload["emulator"] as? [String: Any] ?? [:]
        emulatorEnabled = (emulator["enabled"] as? Bool) ?? false

        if emulatorEnabled {
            let host = NFSRuntime.host(emulator["firestoreHost"])
            let port = (emulator["firestorePort"] as? NSNumber)?.intValue ?? 8080
            settings.host = "\(host):\(port)"
            settings.isSSLEnabled = false

            let authHost = NFSRuntime.host(emulator["authHost"])
            let authPort = (emulator["authPort"] as? NSNumber)?.intValue ?? 9099
            Auth.auth(app: app).useEmulator(withHost: authHost, port: authPort)
        }

        instance.settings = settings
        firestore = instance

        NSLog("[NativeFirestore] configured (project %@, source %@, emulator %@)", app.options.projectID ?? "?", configurationSource, emulatorEnabled ? "on" : "off")

        return status(alreadyConfigured: false)
    }

    func db() throws -> Firestore {
        lock.lock()
        defer { lock.unlock() }

        guard let firestore else {
            throw NFSError.notConfigured("Firestore is not configured yet. Call Firestore.Configure first.")
        }

        return firestore
    }

    func auth() throws -> Auth {
        guard let app = FirebaseApp.app(), isConfigured else {
            throw NFSError.notConfigured("Firebase is not configured yet. Call Firestore.Configure first.")
        }

        return Auth.auth(app: app)
    }

    func status(alreadyConfigured: Bool = false) -> [String: Any] {
        let options = FirebaseApp.app()?.options
        return [
            "configured": firestore != nil,
            "alreadyConfigured": alreadyConfigured,
            "projectId": options?.projectID ?? NSNull(),
            "source": configurationSource,
            "emulator": emulatorEnabled,
            "platform": "ios",
        ]
    }

    private static func host(_ value: Any?) -> String {
        if let host = value as? String, !host.isEmpty {
            return host
        }
        // The iOS simulator shares the Mac's network stack.
        return "127.0.0.1"
    }

    private func resolveOptions(_ explicit: [String: Any]) throws -> (FirebaseOptions, String) {
        let string = { (key: String) -> String? in
            guard let value = explicit[key] as? String, !value.isEmpty else { return nil }
            return value
        }

        if let projectId = string("projectId") {
            let isDemo = projectId.hasPrefix("demo-")
            guard let appId = string("iosAppId") ?? (isDemo ? NFSRuntime.demoAppId : nil),
                  let apiKey = string("apiKey") ?? (isDemo ? NFSRuntime.demoApiKey : nil) else {
                throw NFSError.notConfigured("FIREBASE_PROJECT_ID is set but FIREBASE_IOS_APP_ID / FIREBASE_API_KEY are missing (only demo-* projects may omit them).")
            }

            try NFSRuntime.validateAppId(appId, origin: "FIREBASE_IOS_APP_ID")

            let senderId = string("senderId") ?? appId.split(separator: ":").dropFirst().first.map(String.init) ?? "0"
            let options = FirebaseOptions(googleAppID: appId, gcmSenderID: senderId)
            options.apiKey = apiKey
            options.projectID = projectId
            if let bucket = string("storageBucket") {
                options.storageBucket = bucket
            }

            return (options, isDemo ? "demo-options" : "options")
        }

        for name in ["NativeFirestore-GoogleService-Info", "GoogleService-Info"] {
            guard let path = Bundle.main.path(forResource: name, ofType: "plist") else { continue }

            guard let options = FirebaseOptions(contentsOfFile: path) else {
                throw NFSError.notConfigured("\(name).plist could not be read as Firebase options.")
            }

            try NFSRuntime.validateAppId(options.googleAppID, origin: "\(name).plist")

            return (options, "\(name).plist")
        }

        throw NFSError.notConfigured(
            "Firebase is not configured: put GoogleService-Info.plist in the project root (copied by the plugin's build hook) "
            + "or set FIREBASE_PROJECT_ID (a demo-* project id needs nothing else, for the Emulator Suite)."
        )
    }

    /// FirebaseApp raises an uncatchable exception for a malformed app id.
    private static func validateAppId(_ appId: String, origin: String) throws {
        guard appId.range(of: iosAppIdPattern, options: .regularExpression) != nil else {
            throw NFSError.notConfigured("\(origin) has an invalid iOS app id (\(appId)); expected 1:<number>:ios:<hex>.")
        }
    }
}

// MARK: - Paths & field validation

enum NFSPaths {
    static func segments(_ path: String) throws -> [String] {
        let trimmed = path.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        let parts = trimmed.split(separator: "/", omittingEmptySubsequences: false).map(String.init)

        guard !trimmed.isEmpty, !parts.contains(where: { $0.isEmpty || $0 == "." || $0 == ".." }) else {
            throw NFSError.invalid("Invalid Firestore path [\(path)].")
        }

        return parts
    }

    static func isDocumentPath(_ path: String) -> Bool {
        path.split(separator: "/", omittingEmptySubsequences: false).count % 2 == 0
    }

    static func document(_ path: String, _ db: Firestore) throws -> DocumentReference {
        let parts = try segments(path)
        guard parts.count % 2 == 0 else {
            throw NFSError.invalid("[\(path)] is not a document path.")
        }
        return db.document(parts.joined(separator: "/"))
    }

    static func collection(_ path: String, _ db: Firestore) throws -> CollectionReference {
        let parts = try segments(path)
        guard parts.count % 2 == 1 else {
            throw NFSError.invalid("[\(path)] is not a collection path.")
        }
        return db.collection(parts.joined(separator: "/"))
    }

    static func fieldPath(_ field: String) throws -> FieldPath {
        if field == "__name__" {
            return FieldPath.documentID()
        }
        return FieldPath(try fieldSegments(field))
    }

    /// Dotted field path segments; rejects what FieldPath would raise on.
    static func fieldSegments(_ field: String) throws -> [String] {
        let parts = field.split(separator: ".", omittingEmptySubsequences: false).map(String.init)
        guard !field.isEmpty, !parts.contains(where: { $0.isEmpty }) else {
            throw NFSError.invalid("Invalid field path [\(field)].")
        }
        if field.rangeOfCharacter(from: CharacterSet(charactersIn: "~*/[]")) != nil {
            throw NFSError.invalid("Field path [\(field)] contains a reserved character (~ * / [ ]).")
        }
        return parts
    }

    /// Firestore reserves field names that begin and end with "__"; the SDK raises on them in writes.
    static func guardWritableName(_ name: String) throws {
        if name.count >= 2 && name.hasPrefix("__") && name.hasSuffix("__") {
            throw NFSError.invalid("Field name [\(name)] is reserved: Firestore field names cannot begin and end with \"__\".")
        }
    }
}

// MARK: - Value codec

/// Where a decoded value sits, which decides the sentinels Firestore allows.
enum NFSWriteContext {
    case set          // set() without merge: no delete()
    case merge        // set() with merge / mergeFields: delete() anywhere in maps
    case update       // update(): delete() only at top level
    case query        // query operands: no sentinels at all

    var isWrite: Bool { self != .query }
}

/// The range FIRTimestamp accepts (0001-01-01T00:00:00Z ... 9999-12-31T23:59:59Z); it raises outside it.
let NFSTimestampSecondsRange: ClosedRange<Int64> = -62_135_596_800...253_402_300_799

enum NFSCodec {
    // PHP wire value -> Firestore value. `inArray` means the direct parent is an
    // array (nested arrays are illegal); `underArray` means any ancestor is one
    // (sentinels are illegal), which survives descending into maps.
    static func decode(_ value: Any, _ db: Firestore, context: NFSWriteContext, depth: Int = 0, inArray: Bool = false, underArray: Bool = false) throws -> Any {
        guard depth < 20 else {
            throw NFSError.invalid("Data is nested too deeply (Firestore allows 20 levels).")
        }

        if value is NSNull {
            return NSNull()
        }

        if let number = value as? NSNumber {
            return numeric(number)
        }

        if let string = value as? String {
            return string
        }

        if let list = value as? [Any] {
            if inArray {
                throw NFSError.invalid("Firestore does not support arrays directly inside arrays.")
            }
            return try list.map { try decode($0, db, context: context, depth: depth + 1, inArray: true, underArray: true) }
        }

        guard let map = value as? [String: Any] else {
            throw NFSError.invalid("Unsupported value \(type(of: value)).")
        }

        if let type = map["__type"] as? String {
            return try decodeTagged(type, map, db, context: context, depth: depth, underArray: underArray || inArray)
        }

        var result: [String: Any] = [:]
        for (key, child) in map {
            guard !key.isEmpty else {
                throw NFSError.invalid("Document field names cannot be empty.")
            }
            if context.isWrite {
                try NFSPaths.guardWritableName(key)
            }
            result[key] = try decode(child, db, context: context, depth: depth + 1, underArray: underArray || inArray)
        }
        return result
    }

    static func decodeMap(_ value: Any?, _ db: Firestore, context: NFSWriteContext) throws -> [String: Any] {
        guard let map = value as? [String: Any] else {
            if let list = value as? [Any], list.isEmpty { return [:] }
            throw NFSError.invalid("Document data must be a map.")
        }
        guard let decoded = try decode(map, db, context: context) as? [String: Any] else {
            throw NFSError.invalid("Document data must be a map.")
        }
        return decoded
    }

    static func numeric(_ number: NSNumber) -> Any {
        if CFGetTypeID(number as CFTypeRef) == CFBooleanGetTypeID() {
            return number.boolValue
        }
        let type = String(cString: number.objCType)
        if type == "d" || type == "f" {
            return number.doubleValue
        }
        return number.int64Value
    }

    private static func decodeTagged(_ type: String, _ map: [String: Any], _ db: Firestore, context: NFSWriteContext, depth: Int, underArray: Bool) throws -> Any {
        switch type {
        case "timestamp":
            guard let secondsNumber = map["seconds"] as? NSNumber,
                  secondsNumber.doubleValue >= Double(NFSTimestampSecondsRange.lowerBound),
                  secondsNumber.doubleValue <= Double(NFSTimestampSecondsRange.upperBound),
                  NFSTimestampSecondsRange.contains(secondsNumber.int64Value) else {
                throw NFSError.invalid("Timestamp seconds must fall between 0001-01-01T00:00:00Z and 9999-12-31T23:59:59Z.")
            }
            let nanos = (map["nanoseconds"] as? NSNumber)?.int64Value ?? 0
            guard nanos >= 0 && nanos <= 999_999_999 else {
                throw NFSError.invalid("Timestamp nanoseconds out of range.")
            }
            return Timestamp(seconds: secondsNumber.int64Value, nanoseconds: Int32(nanos))

        case "geopoint":
            let latitude = (map["latitude"] as? NSNumber)?.doubleValue ?? 0
            let longitude = (map["longitude"] as? NSNumber)?.doubleValue ?? 0
            guard (-90...90).contains(latitude), (-180...180).contains(longitude) else {
                throw NFSError.invalid("GeoPoint coordinates out of range.")
            }
            return GeoPoint(latitude: latitude, longitude: longitude)

        case "reference":
            return try NFSPaths.document((map["path"] as? String) ?? "", db)

        case "bytes":
            guard let data = Data(base64Encoded: (map["base64"] as? String) ?? "") else {
                throw NFSError.invalid("Invalid base64 for a Bytes value.")
            }
            return data

        case "double":
            switch map["value"] as? String {
            case "NaN": return Double.nan
            case "Infinity": return Double.infinity
            case "-Infinity": return -Double.infinity
            default: return (map["value"] as? NSNumber)?.doubleValue ?? Double.nan
            }

        case "fieldvalue":
            return try decodeSentinel(map, db, context: context, depth: depth, underArray: underArray)

        default:
            var result: [String: Any] = [:]
            for (key, child) in map {
                if context.isWrite {
                    try NFSPaths.guardWritableName(key)
                }
                result[key] = try decode(child, db, context: context, depth: depth + 1, underArray: underArray)
            }
            return result
        }
    }

    private static func decodeSentinel(_ map: [String: Any], _ db: Firestore, context: NFSWriteContext, depth: Int, underArray: Bool) throws -> Any {
        let op = (map["op"] as? String) ?? ""

        if context == .query {
            throw NFSError.invalid("FieldValue.\(op)() cannot be used in a query.")
        }
        if underArray {
            throw NFSError.invalid("FieldValue.\(op)() cannot be used inside an array.")
        }

        switch op {
        case "serverTimestamp":
            return FieldValue.serverTimestamp()

        case "increment":
            guard let by = map["value"] as? NSNumber else {
                throw NFSError.invalid("FieldValue.increment() needs a number.")
            }
            let decoded = numeric(by)
            if let double = decoded as? Double {
                return FieldValue.increment(double)
            }
            return FieldValue.increment((decoded as? Int64) ?? by.int64Value)

        case "arrayUnion", "arrayRemove":
            let elements = try ((map["value"] as? [Any]) ?? []).map { try decode($0, db, context: .query, depth: depth + 1, inArray: true, underArray: true) }
            return op == "arrayUnion" ? FieldValue.arrayUnion(elements) : FieldValue.arrayRemove(elements)

        case "delete":
            switch context {
            case .merge:
                return FieldValue.delete()
            case .update where depth <= 1:
                return FieldValue.delete()
            case .update:
                throw NFSError.invalid("FieldValue.delete() can only appear at the top level of update() data.")
            default:
                throw NFSError.invalid("FieldValue.delete() can only be used with update() or set() with merge.")
            }

        default:
            throw NFSError.invalid("Unknown field value [\(op)].")
        }
    }

    // Firestore value -> PHP wire value (JSON-safe)
    static func encode(_ value: Any?) -> Any {
        guard let value, !(value is NSNull) else {
            return NSNull()
        }

        if let timestamp = value as? Timestamp {
            return ["__type": "timestamp", "seconds": timestamp.seconds, "nanoseconds": timestamp.nanoseconds]
        }
        if let point = value as? GeoPoint {
            return ["__type": "geopoint", "latitude": point.latitude, "longitude": point.longitude]
        }
        if let reference = value as? DocumentReference {
            return ["__type": "reference", "path": reference.path]
        }
        if let data = value as? Data {
            return ["__type": "bytes", "base64": data.base64EncodedString()]
        }
        if let number = value as? NSNumber {
            let decoded = numeric(number)
            if let double = decoded as? Double {
                if double.isNaN { return ["__type": "double", "value": "NaN"] }
                if double.isInfinite { return ["__type": "double", "value": double > 0 ? "Infinity" : "-Infinity"] }
                return double
            }
            return decoded
        }
        if let string = value as? String {
            return string
        }
        if let list = value as? [Any] {
            return list.map { encode($0) }
        }
        if let map = value as? [String: Any] {
            let encoded = map.mapValues { encode($0) }
            // PHP decodes an empty map, or one keyed exactly "0".."n-1", as a
            // list, so those travel tagged and stay maps on the PHP side.
            return isListLike(map) ? ["__type": "map", "value": encoded] as [String: Any] : encoded
        }

        return String(describing: value)
    }

    static func isListLike(_ map: [String: Any]) -> Bool {
        Set(map.keys) == Set((0..<map.count).map { String($0) })
    }

    /// Document data: always a map, so the top level is never tagged.
    static func encodeMap(_ map: [String: Any]?) -> Any {
        guard let map else { return NSNull() }
        // An empty map must stay a JSON object.
        return map.isEmpty ? [String: Any]() : map.mapValues { encode($0) }
    }

    static func document(_ snapshot: DocumentSnapshot) -> [String: Any] {
        let behavior = NFSRuntime.shared.serverTimestamps
        return [
            "id": snapshot.documentID,
            "path": snapshot.reference.path,
            "exists": snapshot.exists,
            "data": snapshot.exists ? encodeMap(snapshot.data(with: behavior)) : NSNull(),
            "metadata": metadata(snapshot.metadata).merging(["pendingServerTimestamps": pendingServerTimestamps(snapshot)]) { $1 },
        ]
    }

    /// Fields holding a server timestamp the backend has not set yet: they
    /// read as null without an estimate and as a timestamp with one. PHP
    /// needs these to refuse such a snapshot as a query cursor.
    static func pendingServerTimestamps(_ snapshot: DocumentSnapshot) -> [String] {
        guard snapshot.exists, snapshot.metadata.hasPendingWrites,
              let estimated = snapshot.data(with: .estimate), let raw = snapshot.data(with: .none) else {
            return []
        }

        var pending: [String] = []
        func walk(_ estimated: [String: Any], _ raw: [String: Any], _ prefix: String) {
            for (key, value) in estimated {
                let path = prefix.isEmpty ? key : "\(prefix).\(key)"
                if value is Timestamp, raw[key] is NSNull {
                    pending.append(path)
                } else if let child = value as? [String: Any], let rawChild = raw[key] as? [String: Any] {
                    walk(child, rawChild, path)
                }
            }
        }
        walk(estimated, raw, "")
        return pending.sorted()
    }

    static func query(_ snapshot: QuerySnapshot) -> [String: Any] {
        let changes: [[String: Any]] = snapshot.documentChanges.map { change in
            let type: String
            switch change.type {
            case .added: type = "added"
            case .modified: type = "modified"
            case .removed: type = "removed"
            @unknown default: type = "modified"
            }
            return [
                "type": type,
                "document": document(change.document),
                "oldIndex": change.type == .added ? -1 : Int(change.oldIndex),
                "newIndex": change.type == .removed ? -1 : Int(change.newIndex),
            ]
        }

        return [
            "documents": snapshot.documents.map { document($0) },
            "changes": changes,
            "metadata": metadata(snapshot.metadata),
        ]
    }

    static func metadata(_ metadata: SnapshotMetadata) -> [String: Any] {
        ["fromCache": metadata.isFromCache, "hasPendingWrites": metadata.hasPendingWrites]
    }
}

// MARK: - Queries

enum NFSQueryBuilder {
    private static let maxDisjunctions = 30
    private static let maxNotInValues = 10

    /// The SDK checks each clause as it is added and reports a bad one with an
    /// Objective-C exception, which Swift cannot catch. So the whole query
    /// spec (filters including nested OR/AND, ordering, limit and cursors) is
    /// decoded and validated first, and nothing touches the SDK query until
    /// all of it has passed. PHP validates too, but the JavaScript bridge
    /// wrappers reach this code directly.
    static func build(_ wire: [String: Any], _ db: Firestore) throws -> FirebaseFirestore.Query {
        let path = (wire["path"] as? String) ?? ""
        let isGroup = (wire["collectionGroup"] as? Bool) ?? false
        let collectionPath: String? = isGroup ? nil : try NFSPaths.segments(path).joined(separator: "/")

        if isGroup {
            guard !path.isEmpty, !path.contains("/") else {
                throw NFSError.invalid("Invalid collection group id [\(path)].")
            }
        } else if let collectionPath, NFSPaths.isDocumentPath(collectionPath) {
            throw NFSError.invalid("[\(path)] is not a collection path.")
        }

        guard let rawFilters = (wire["filters"] ?? []) as? [[String: Any]] else {
            throw NFSError.invalid("Query filters must be a list.")
        }
        var stats = FilterStats()
        let filters = try rawFilters.map { try buildFilter($0, db, collectionPath: collectionPath, stats: &stats) }
        try stats.validate()
        // The top-level filters are an implicit AND.
        let form = normalForm(["composite": "and", "filters": rawFilters])
        if form.terms > maxDisjunctions {
            throw NFSError.invalid("A query can have at most \(maxDisjunctions) disjunctions once its OR, in and array-contains-any filters are expanded.")
        }
        if form.arrayContains > 1 {
            throw NFSError.invalid("Each disjunction of a query can use at most one array-contains or array-contains-any filter.")
        }

        guard let rawOrder = (wire["orderBy"] ?? []) as? [[String: Any]] else {
            throw NFSError.invalid("Query orderBy must be a list.")
        }
        let explicit = try rawOrder.map { order -> OrderClause in
            let field = (order["field"] as? String) ?? ""
            let direction = (order["direction"] as? String) ?? "asc"
            guard direction == "asc" || direction == "desc" else {
                throw NFSError.invalid("Order direction must be asc or desc.")
            }
            return OrderClause(field: field, path: try NFSPaths.fieldPath(field), descending: direction == "desc")
        }

        var limit: (count: Int, last: Bool)?
        if let raw = wire["limit"], !(raw is NSNull) {
            guard let number = raw as? NSNumber, number.doubleValue >= 1, number.doubleValue <= Double(Int32.max) else {
                throw NFSError.invalid("A query limit must be a whole number from 1 to \(Int32.max).")
            }
            let isLast = (wire["limitType"] as? String) == "last"
            if isLast && explicit.isEmpty {
                throw NFSError.invalid("limitToLast() requires an orderBy() clause.")
            }
            limit = (number.intValue, isLast)
        }

        let normalized = try normalizedOrder(explicit, inequalityFields: stats.inequalityFields)
        let start = try (wire["startAt"] as? [String: Any]).map {
            try cursorBound($0, explicit: explicit, normalized: normalized, db: db, collectionPath: collectionPath)
        }
        let end = try (wire["endAt"] as? [String: Any]).map {
            try cursorBound($0, explicit: explicit, normalized: normalized, db: db, collectionPath: collectionPath)
        }

        // A snapshot cursor positions on the full normalized ordering, but
        // value cursors may only cover explicit orderBy clauses, so the
        // implicit ones are spelled out. The SDK normalizes the query to this
        // same ordering anyway, so results and indexes are unchanged.
        let ordering = (start?.isSnapshot ?? false) || (end?.isSnapshot ?? false) ? normalized : explicit

        // Everything is valid: build the SDK query.
        var query: FirebaseFirestore.Query = isGroup ? db.collectionGroup(path) : db.collection(collectionPath ?? path)
        for filter in filters {
            query = query.whereFilter(filter)
        }
        for order in ordering {
            query = query.order(by: order.path, descending: order.descending)
        }
        if let limit {
            query = limit.last ? query.limit(toLast: limit.count) : query.limit(to: limit.count)
        }
        if let start {
            query = start.inclusive ? query.start(at: start.values) : query.start(after: start.values)
        }
        if let end {
            query = end.inclusive ? query.end(at: end.values) : query.end(before: end.values)
        }

        return query
    }

    private struct OrderClause {
        let field: String
        let path: FieldPath
        let descending: Bool
    }

    private struct CursorBound {
        let values: [Any]
        let inclusive: Bool
        let isSnapshot: Bool
    }

    /// The ordering the SDK really applies: explicit clauses, then inequality
    /// fields not already ordered (in field-path order), then the document
    /// id, the implicit ones following the last explicit direction.
    private static func normalizedOrder(_ explicit: [OrderClause], inequalityFields: [String]) throws -> [OrderClause] {
        let ordered = Set(explicit.map { $0.field })
        let descending = explicit.last?.descending ?? false
        let implicit = Array(Set(inequalityFields))
            .filter { $0 != "__name__" && !ordered.contains($0) }
            .sorted { $0.split(separator: ".", omittingEmptySubsequences: false).lexicographicallyPrecedes($1.split(separator: ".", omittingEmptySubsequences: false)) }

        var result = explicit
        for field in implicit {
            result.append(OrderClause(field: field, path: try NFSPaths.fieldPath(field), descending: descending))
        }
        if !ordered.contains("__name__") {
            result.append(OrderClause(field: "__name__", path: FieldPath.documentID(), descending: descending))
        }
        return result
    }

    /// The SDK itself only refuses (with an uncatchable exception) a second
    /// != or not-in, or not-in beside !=, in or array-contains-any, anywhere
    /// in the query. The rest are server limits judged per disjunction of the
    /// query's disjunctive normal form (see normalForm), plus no not-in beside
    /// an OR; checking them here keeps the offline cache, the server and the
    /// PHP fake in agreement.
    private struct FilterStats {
        var disjunctive = 0
        var notIn = 0
        var notEqual = 0
        var hasOr = false
        var inequalityFields: [String] = []

        func validate() throws {
            if notIn > 1 {
                throw NFSError.invalid("A query can use at most one not-in filter.")
            }
            if notEqual > 1 {
                throw NFSError.invalid("A query can use at most one != filter.")
            }
            if notIn > 0 && (notEqual > 0 || disjunctive > 0 || hasOr) {
                throw NFSError.invalid("not-in cannot be combined with !=, in, array-contains-any or OR filters.")
            }
        }
    }

    /// Terms in a filter's disjunctive normal form (capped just past the
    /// limit) and the most array-contains filters any one term holds. in and
    /// array-contains-any expand into one == / array-contains per value.
    private static func normalForm(_ wire: [String: Any]) -> (terms: Int, arrayContains: Int) {
        if let composite = wire["composite"] as? String {
            let isOr = composite == "or"
            var terms = isOr ? 0 : 1
            var arrayContains = 0
            for child in (wire["filters"] as? [[String: Any]]) ?? [] {
                let form = normalForm(child)
                terms = min(isOr ? terms + form.terms : terms * form.terms, maxDisjunctions + 1)
                // An AND term takes one term from every child; an OR term is one child's term.
                arrayContains = isOr ? max(arrayContains, form.arrayContains) : arrayContains + form.arrayContains
            }
            return (terms, arrayContains)
        }

        let op = (wire["op"] as? String) ?? ""
        let count = ["in", "array-contains-any"].contains(op) ? max(1, (wire["value"] as? [Any])?.count ?? 1) : 1
        return (min(count, maxDisjunctions + 1), ["array-contains", "array-contains-any"].contains(op) ? 1 : 0)
    }

    private static func buildFilter(_ wire: [String: Any], _ db: Firestore, collectionPath: String?, stats: inout FilterStats) throws -> Filter {
        if let composite = wire["composite"] as? String {
            let children = try ((wire["filters"] as? [[String: Any]]) ?? []).map {
                try buildFilter($0, db, collectionPath: collectionPath, stats: &stats)
            }
            guard !children.isEmpty else { throw NFSError.invalid("An \(composite) filter needs at least one filter.") }
            if composite == "or" {
                stats.hasOr = true
                return Filter.orFilter(children)
            }
            return Filter.andFilter(children)
        }

        let field = (wire["field"] as? String) ?? ""
        let op = (wire["op"] as? String) ?? ""
        let path = try NFSPaths.fieldPath(field)
        let raw = wire["value"] ?? NSNull()
        let isDocumentId = field == "__name__"

        func operand(_ value: Any) throws -> Any {
            isDocumentId
                ? try documentIdOperand(value, db, collectionPath: collectionPath)
                : try NFSCodec.decode(value, db, context: .query)
        }

        func list() throws -> [Any] {
            guard let values = raw as? [Any], !values.isEmpty else {
                throw NFSError.invalid("The \(op) operator needs a non-empty list.")
            }
            let limit = op == "not-in" ? maxNotInValues : maxDisjunctions
            guard values.count <= limit else {
                throw NFSError.invalid("The \(op) operator supports at most \(limit) values.")
            }
            return try values.map { try operand($0) }
        }

        // The SDK raises uncatchable NSExceptions for these, so reject them here.
        if isDocumentId && (op == "array-contains" || op == "array-contains-any") {
            throw NFSError.invalid("The \(op) operator cannot be used on the document id.")
        }
        if op == "array-contains-any", let values = raw as? [Any], values.contains(where: { $0 is [Any] }) {
            throw NFSError.invalid("array-contains-any values cannot be arrays (Firestore does not support nested arrays).")
        }
        if ["!=", "<", "<=", ">", ">=", "not-in"].contains(op) {
            stats.inequalityFields.append(field)
        }

        let isListOperator = ["in", "not-in", "array-contains-any"].contains(op)
        var value: Any = NSNull()
        if !isListOperator {
            value = try operand(raw)
            let isNullOrNaN = value is NSNull || ((value as? Double)?.isNaN ?? false)
            // array-contains null/NaN is valid Firestore (matches arrays holding null/NaN);
            // only range comparisons are meaningless for them.
            if isNullOrNaN && !["==", "!=", "array-contains"].contains(op) {
                throw NFSError.invalid("null and NaN only support ==, != and array-contains comparisons.")
            }
        }

        switch op {
        case "==": return Filter.whereField(path, isEqualTo: value)
        case "!=":
            stats.notEqual += 1
            return Filter.whereField(path, isNotEqualTo: value)
        case "<": return Filter.whereField(path, isLessThan: value)
        case "<=": return Filter.whereField(path, isLessThanOrEqualTo: value)
        case ">": return Filter.whereField(path, isGreaterThan: value)
        case ">=": return Filter.whereField(path, isGreaterOrEqualTo: value)
        case "array-contains":
            return Filter.whereField(path, arrayContains: value)
        case "array-contains-any":
            stats.disjunctive += 1
            return Filter.whereField(path, arrayContainsAny: try list())
        case "in":
            stats.disjunctive += 1
            return Filter.whereField(path, in: try list())
        case "not-in":
            stats.notIn += 1
            return Filter.whereField(path, notIn: try list())
        default:
            throw NFSError.invalid("Unsupported operator [\(op)].")
        }
    }

    /// __name__ operands: a plain id for collection queries, a full document
    /// path for collection-group queries, or a reference.
    private static func documentIdOperand(_ value: Any, _ db: Firestore, collectionPath: String?) throws -> Any {
        if let map = value as? [String: Any], (map["__type"] as? String) == "reference" {
            return try NFSPaths.document((map["path"] as? String) ?? "", db)
        }
        guard let id = value as? String, !id.isEmpty else {
            throw NFSError.invalid("Document id filters need a string id or a reference.")
        }
        if let collectionPath {
            guard !id.contains("/") else {
                throw NFSError.invalid("Use a plain document id (no slashes) when filtering a collection by document id.")
            }
            return try NFSPaths.document("\(collectionPath)/\(id)", db)
        }
        return try NFSPaths.document(id, db)
    }

    /// Cursor values for a document-id ordering must be strings: a plain id
    /// for collection queries, a full document path for collection groups.
    private static func documentIdCursorValue(_ value: Any, collectionPath: String?) throws -> String {
        var candidate = value as? String
        if let map = value as? [String: Any], (map["__type"] as? String) == "reference", let path = map["path"] as? String {
            candidate = collectionPath == nil ? path : path.split(separator: "/").last.map(String.init)
        }
        guard let id = candidate, !id.isEmpty else {
            throw NFSError.invalid("Document id cursor values must be strings.")
        }
        if collectionPath != nil {
            guard !id.contains("/") else { throw NFSError.invalid("Use a plain document id as a cursor value for a collection query.") }
        } else {
            let parts = try NFSPaths.segments(id)
            guard parts.count % 2 == 0 else { throw NFSError.invalid("Collection group cursors on document id need a full document path.") }
        }
        return id
    }

    /// A value cursor lines up with the explicit orderBy clauses. A snapshot
    /// cursor ({document, values}) carries the snapshot's own value for every
    /// normalized order field, ending with its document: building the bound
    /// from those (instead of re-reading the document) keeps the cursor where
    /// the snapshot was even if the document has changed or been deleted.
    private static func cursorBound(_ cursor: [String: Any], explicit: [OrderClause], normalized: [OrderClause], db: Firestore, collectionPath: String?) throws -> CursorBound {
        let inclusive = (cursor["inclusive"] as? Bool) ?? true
        guard let rawValues = cursor["values"] as? [Any] else {
            throw NFSError.invalid(cursor["document"] != nil
                ? "A document cursor needs the snapshot's value for each ordered field (the PHP API sends these)."
                : "Cursor values must be a list.")
        }

        if let document = cursor["document"] {
            guard let documentPath = document as? String else {
                throw NFSError.invalid("Cursor document must be a path.")
            }
            let reference = try NFSPaths.document(documentPath, db)
            guard rawValues.count == normalized.count else {
                throw NFSError.invalid("The cursor for document [\(reference.path)] needs one value per ordered field (\(normalized.map { $0.field }.joined(separator: ", "))).")
            }

            var values: [Any] = []
            for (index, order) in normalized.enumerated() {
                if order.field == "__name__" {
                    guard let map = rawValues[index] as? [String: Any], (map["__type"] as? String) == "reference",
                          let slot = map["path"] as? String, (try? NFSPaths.segments(slot).joined(separator: "/")) == reference.path else {
                        throw NFSError.invalid("The cursor for document [\(reference.path)] must end in a reference to it.")
                    }
                    if let collectionPath, reference.parent.path != collectionPath {
                        throw NFSError.invalid("The cursor document [\(reference.path)] is not in [\(collectionPath)].")
                    }
                    values.append(try documentIdCursorValue(map, collectionPath: collectionPath))
                } else {
                    values.append(try NFSCodec.decode(rawValues[index], db, context: .query))
                }
            }
            return CursorBound(values: values, inclusive: inclusive, isSnapshot: true)
        }

        guard !rawValues.isEmpty, rawValues.count <= explicit.count else {
            throw NFSError.invalid("Cursor values need a matching orderBy() clause for each value.")
        }

        var values: [Any] = []
        for (index, value) in rawValues.enumerated() {
            if explicit[index].field == "__name__" {
                values.append(try documentIdCursorValue(value, collectionPath: collectionPath))
            } else {
                values.append(try NFSCodec.decode(value, db, context: .query))
            }
        }
        return CursorBound(values: values, inclusive: inclusive, isSnapshot: false)
    }
}

// MARK: - Writes

enum NFSWrite {
    case set(DocumentReference, [String: Any], merge: Bool, mergeFields: [String]?)
    case update(DocumentReference, [String: Any])
    case delete(DocumentReference)

    var path: String {
        switch self {
        case .set(let ref, _, _, _), .update(let ref, _), .delete(let ref):
            return ref.path
        }
    }

    static func decodeAll(_ raw: Any?, _ db: Firestore) throws -> [NFSWrite] {
        guard let list = raw as? [[String: Any]] else {
            throw NFSError.invalid("Writes must be a list.")
        }
        guard list.count <= 500 else {
            throw NFSError.invalid("At most 500 writes can be committed together.")
        }
        return try list.map { try decode($0, db) }
    }

    static func decode(_ wire: [String: Any], _ db: Firestore) throws -> NFSWrite {
        let ref = try NFSPaths.document((wire["path"] as? String) ?? "", db)

        switch wire["type"] as? String {
        case "set":
            let mergeFields = wire["mergeFields"] as? [String]
            let merge = (wire["merge"] as? Bool) ?? false
            let isMerge = merge || mergeFields != nil
            let data = try NFSCodec.decodeMap(wire["data"], db, context: isMerge ? .merge : .set)

            if let mergeFields {
                guard !mergeFields.isEmpty else { throw NFSError.invalid("mergeFields cannot be empty.") }
                for field in mergeFields {
                    let segments = try NFSPaths.fieldSegments(field)
                    guard contains(data, segments) else {
                        throw NFSError.invalid("Merge field [\(field)] is not in the data.")
                    }
                }
            }

            return .set(ref, data, merge: merge, mergeFields: mergeFields)

        case "update":
            guard let raw = wire["data"] as? [String: Any], !raw.isEmpty else {
                throw NFSError.invalid("update() needs at least one field.")
            }
            var data: [String: Any] = [:]
            for (field, value) in raw {
                for segment in try NFSPaths.fieldSegments(field) {
                    try NFSPaths.guardWritableName(segment)
                }
                data[field] = try NFSCodec.decode(value, db, context: .update, depth: 1)
            }
            return .update(ref, data)

        case "delete":
            return .delete(ref)

        default:
            throw NFSError.invalid("Unknown write type.")
        }
    }

    private static func contains(_ data: [String: Any], _ segments: [String]) -> Bool {
        var current: Any = data
        for segment in segments {
            guard let map = current as? [String: Any], let next = map[segment] else { return false }
            current = next
        }
        return true
    }

    func apply(to batch: WriteBatch) {
        switch self {
        case .set(let ref, let data, let merge, let mergeFields):
            if let mergeFields {
                batch.setData(data, forDocument: ref, mergeFields: mergeFields)
            } else {
                batch.setData(data, forDocument: ref, merge: merge)
            }
        case .update(let ref, let data):
            batch.updateData(data, forDocument: ref)
        case .delete(let ref):
            batch.deleteDocument(ref)
        }
    }

    func apply(to transaction: FirebaseFirestore.Transaction) {
        switch self {
        case .set(let ref, let data, let merge, let mergeFields):
            if let mergeFields {
                transaction.setData(data, forDocument: ref, mergeFields: mergeFields)
            } else {
                transaction.setData(data, forDocument: ref, merge: merge)
            }
        case .update(let ref, let data):
            transaction.updateData(data, forDocument: ref)
        case .delete(let ref):
            transaction.deleteDocument(ref)
        }
    }
}

// MARK: - Operations

enum NFSOperations {
    static func source(_ value: Any?) -> FirestoreSource {
        switch value as? String {
        case "server": return .server
        case "cache": return .cache
        default: return .default
        }
    }

    static func fetch(_ ref: DocumentReference, source: FirestoreSource) throws -> DocumentSnapshot {
        let waiter = NFSWaiter<DocumentSnapshot>()
        ref.getDocument(source: source) { snapshot, error in
            if let snapshot {
                waiter.succeed(snapshot)
            } else {
                waiter.fail(error ?? NFSError(code: "unknown", message: "No snapshot returned."))
            }
        }
        return try waiter.wait(NFSRuntime.shared.timeout, "Reading \(ref.path)")
    }

    static func commit(_ writes: [NFSWrite], waitForServer: Bool, _ db: Firestore) throws -> [String: Any] {
        let batch = db.batch()
        writes.forEach { $0.apply(to: batch) }
        let paths = writes.map { $0.path }

        if !waitForServer {
            // Firestore applies the batch to its local cache synchronously;
            // the completion only fires once the backend answers (possibly
            // much later, when back online). Report late failures as events.
            batch.commit { error in
                guard let error else { return }
                let failure = NFSError.from(error)
                NFSBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\WriteFailed", [
                    "paths": paths,
                    "code": failure.code,
                    "message": failure.message,
                ])
            }
            return ["committed": writes.count, "acknowledged": false]
        }

        let waiter = NFSWaiter<Bool>()
        batch.commit { error in
            if let error { waiter.fail(error) } else { waiter.succeed(true) }
        }
        _ = try waiter.wait(NFSRuntime.shared.timeout, "Committing \(writes.count) write(s)")
        return ["committed": writes.count, "acknowledged": true]
    }

    static func runQuery(_ query: FirebaseFirestore.Query, source: FirestoreSource) throws -> [String: Any] {
        let waiter = NFSWaiter<QuerySnapshot>()
        query.getDocuments(source: source) { snapshot, error in
            if let snapshot {
                waiter.succeed(snapshot)
            } else {
                waiter.fail(error ?? NFSError(code: "unknown", message: "No snapshot returned."))
            }
        }
        return NFSCodec.query(try waiter.wait(NFSRuntime.shared.timeout, "Running query"))
    }

    static func count(_ query: FirebaseFirestore.Query) throws -> [String: Any] {
        let waiter = NFSWaiter<NSNumber>()
        query.count.getAggregation(source: .server) { snapshot, error in
            if let snapshot {
                waiter.succeed(snapshot.count)
            } else {
                waiter.fail(error ?? NFSError(code: "unknown", message: "No aggregation returned."))
            }
        }
        return ["count": try waiter.wait(NFSRuntime.shared.timeout, "Counting").int64Value]
    }

    static func awaitCompletion(_ what: String, _ start: (@escaping (Error?) -> Void) -> Void) throws {
        let waiter = NFSWaiter<Bool>()
        start { error in
            if let error { waiter.fail(error) } else { waiter.succeed(true) }
        }
        _ = try waiter.wait(NFSRuntime.shared.timeout, what)
    }
}

// MARK: - Listeners

final class NFSListeners {
    static let shared = NFSListeners()

    private let lock = NSLock()
    private var registrations: [String: ListenerRegistration] = [:]

    func add(_ target: [String: Any], includeMetadataChanges: Bool, _ db: Firestore) throws -> String {
        let id = UUID().uuidString

        // Build everything that can fail before attaching, so a bad target
        // never leaves a half-registered listener behind.
        let registration: ListenerRegistration
        if (target["type"] as? String) == "document" {
            let ref = try NFSPaths.document((target["path"] as? String) ?? "", db)
            registration = ref.addSnapshotListener(includeMetadataChanges: includeMetadataChanges) { [weak self] snapshot, error in
                if let snapshot {
                    var payload = NFSCodec.document(snapshot)
                    let metadata = payload.removeValue(forKey: "metadata") as? [String: Any] ?? [:]
                    payload["listenerId"] = id
                    payload["fromCache"] = metadata["fromCache"] ?? false
                    payload["hasPendingWrites"] = metadata["hasPendingWrites"] ?? false
                    payload["pendingServerTimestamps"] = metadata["pendingServerTimestamps"] ?? []
                    NFSBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\DocumentChanged", payload)
                } else {
                    self?.fail(id, error)
                }
            }
        } else {
            let wire = (target["query"] as? [String: Any]) ?? [:]
            let query = try NFSQueryBuilder.build(wire, db)
            let path = (wire["path"] as? String) ?? ""
            registration = query.addSnapshotListener(includeMetadataChanges: includeMetadataChanges) { [weak self] snapshot, error in
                if let snapshot {
                    let encoded = NFSCodec.query(snapshot)
                    let metadata = encoded["metadata"] as? [String: Any] ?? [:]
                    NFSBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\QuerySnapshotChanged", [
                        "listenerId": id,
                        "path": path,
                        "documents": encoded["documents"] ?? [],
                        "changes": encoded["changes"] ?? [],
                        "fromCache": metadata["fromCache"] ?? false,
                        "hasPendingWrites": metadata["hasPendingWrites"] ?? false,
                    ])
                } else {
                    self?.fail(id, error)
                }
            }
        }

        lock.lock()
        registrations[id] = registration
        lock.unlock()

        return id
    }

    func remove(_ id: String) {
        lock.lock()
        let registration = registrations.removeValue(forKey: id)
        lock.unlock()
        registration?.remove()
    }

    func removeAll() -> Int {
        lock.lock()
        let all = registrations
        registrations.removeAll()
        lock.unlock()
        all.values.forEach { $0.remove() }
        return all.count
    }

    private func fail(_ id: String, _ error: Error?) {
        remove(id)
        let failure = NFSError.from(error ?? NFSError(code: "unknown", message: "Listener failed."))
        NFSBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\ListenerFailed", [
            "listenerId": id,
            "code": failure.code,
            "message": failure.message,
        ])
    }
}

// MARK: - Transactions

/// A Firestore transaction remote-controlled from PHP.
///
/// Firestore's transaction block runs on an SDK background thread and may be
/// invoked several times (contention retries). Each invocation parks on this
/// session and executes the commands PHP sends — reads, then one commit or
/// abort. When the SDK retries, PHP's pending commit returns "retry" and PHP
/// re-runs its callback against the next invocation.
final class NFSTransactionSession {
    enum Command {
        case get(DocumentReference, NFSWaiter<[String: Any]>)
        case commit([NFSWrite])
        case abort
    }

    let id = UUID().uuidString
    private let condition = NSCondition()
    private var commands: [Command] = []
    private(set) var attempt = 0
    private var finished: Result<Bool, Error>?

    func run(_ db: Firestore, maxAttempts: Int, commandTimeout: TimeInterval) {
        let options = TransactionOptions()
        options.maxAttempts = max(1, maxAttempts)

        db.runTransaction(with: options, block: { [self] transaction, errorPointer in
            condition.lock()
            attempt += 1
            commands.removeAll()
            condition.broadcast()
            condition.unlock()

            while true {
                guard let command = next(timeout: commandTimeout) else {
                    errorPointer?.pointee = NSError(domain: FirestoreErrorDomain, code: 4, userInfo: [
                        NSLocalizedDescriptionKey: "The app did not finish the transaction within \(Int(commandTimeout))s.",
                    ])
                    return nil
                }

                switch command {
                case .get(let ref, let reply):
                    do {
                        reply.succeed(NFSCodec.document(try transaction.getDocument(ref)))
                    } catch {
                        reply.fail(error)
                    }
                case .commit(let writes):
                    writes.forEach { $0.apply(to: transaction) }
                    return nil
                case .abort:
                    errorPointer?.pointee = NSError(domain: FirestoreErrorDomain, code: 10, userInfo: [
                        NSLocalizedDescriptionKey: "Transaction aborted by the app.",
                    ])
                    return nil
                }
            }
        }, completion: { [self] _, error in
            condition.lock()
            if let error {
                finished = .failure(error)
            } else {
                finished = .success(true)
            }
            condition.broadcast()
            condition.unlock()
        })
    }

    /// Block until the first attempt is running (or the transaction failed to start).
    func waitUntilStarted(timeout: TimeInterval) throws {
        condition.lock()
        defer { condition.unlock() }
        let deadline = Date().addingTimeInterval(timeout)
        while attempt == 0 && finished == nil {
            if !condition.wait(until: deadline) { break }
        }
        if case .failure(let error)? = finished { throw error }
        if attempt == 0 { throw NFSError(code: "deadline-exceeded", message: "The transaction did not start.") }
    }

    func get(_ ref: DocumentReference, timeout: TimeInterval) throws -> [String: Any] {
        let reply = NFSWaiter<[String: Any]>()
        try submit(.get(ref, reply))
        return try reply.wait(timeout, "Transaction read of \(ref.path)")
    }

    /// Returns true when committed, false when Firestore started another attempt.
    func commit(_ writes: [NFSWrite], timeout: TimeInterval) throws -> Bool {
        condition.lock()
        let attemptAtCommit = attempt
        condition.unlock()

        try submit(.commit(writes))

        condition.lock()
        defer { condition.unlock() }
        let deadline = Date().addingTimeInterval(timeout)
        while finished == nil && attempt == attemptAtCommit {
            if !condition.wait(until: deadline) {
                throw NFSError(code: "deadline-exceeded", message: "The transaction commit did not complete within \(Int(timeout))s.")
            }
        }
        if let finished {
            _ = try finished.get()
            return true
        }
        return false
    }

    func abort(timeout: TimeInterval) {
        try? submit(.abort)
        condition.lock()
        let deadline = Date().addingTimeInterval(timeout)
        while finished == nil {
            if !condition.wait(until: deadline) { break }
        }
        condition.unlock()
    }

    private func submit(_ command: Command) throws {
        condition.lock()
        defer { condition.unlock() }
        if case .failure(let error)? = finished { throw error }
        if finished != nil { throw NFSError(code: "failed-precondition", message: "The transaction has already finished.") }
        commands.append(command)
        condition.broadcast()
    }

    private func next(timeout: TimeInterval) -> Command? {
        condition.lock()
        defer { condition.unlock() }
        let deadline = Date().addingTimeInterval(timeout)
        while commands.isEmpty {
            if !condition.wait(until: deadline) { return nil }
        }
        return commands.removeFirst()
    }
}

final class NFSTransactions {
    static let shared = NFSTransactions()

    private let lock = NSLock()
    private var sessions: [String: NFSTransactionSession] = [:]

    func begin(_ db: Firestore, maxAttempts: Int) throws -> String {
        let session = NFSTransactionSession()
        let timeout = NFSRuntime.shared.timeout

        lock.lock()
        sessions[session.id] = session
        lock.unlock()

        session.run(db, maxAttempts: maxAttempts, commandTimeout: max(timeout * 2, 30))

        do {
            try session.waitUntilStarted(timeout: timeout)
        } catch {
            end(session.id)
            throw error
        }

        return session.id
    }

    func session(_ id: String) throws -> NFSTransactionSession {
        lock.lock()
        defer { lock.unlock() }
        guard let session = sessions[id] else {
            throw NFSError(code: "failed-precondition", message: "Unknown or finished transaction [\(id)].")
        }
        return session
    }

    func end(_ id: String) {
        lock.lock()
        sessions.removeValue(forKey: id)
        lock.unlock()
    }
}

// MARK: - Auth helpers

enum NFSAuthSupport {
    /// The Auth SDKs reject empty credentials (Android by throwing), so every
    /// platform reports them the same way before calling the SDK.
    static func required(_ payload: [String: Any], _ key: String) throws -> String {
        guard let value = payload[key] as? String, !value.isEmpty else {
            throw NFSError.invalid("FirebaseAuth needs a non-empty \(key).")
        }
        return value
    }

    static func encode(_ user: User?) -> Any {
        guard let user else { return NSNull() }
        return [
            "uid": user.uid,
            "email": user.email ?? NSNull(),
            "displayName": user.displayName ?? NSNull(),
            "photoUrl": user.photoURL?.absoluteString ?? NSNull(),
            "phoneNumber": user.phoneNumber ?? NSNull(),
            "emailVerified": user.isEmailVerified,
            "isAnonymous": user.isAnonymous,
            "providerId": user.providerID,
            "createdAt": user.metadata.creationDate.map { Int64($0.timeIntervalSince1970 * 1000) } ?? NSNull(),
            "lastSignInAt": user.metadata.lastSignInDate.map { Int64($0.timeIntervalSince1970 * 1000) } ?? NSNull(),
        ] as [String: Any]
    }

    static func authResult(_ what: String, _ start: (@escaping (AuthDataResult?, Error?) -> Void) -> Void) throws -> [String: Any] {
        let waiter = NFSWaiter<User>()
        start { result, error in
            if let user = result?.user {
                waiter.succeed(user)
            } else {
                waiter.fail(error ?? NFSError(code: "unknown", message: "\(what) returned no user."))
            }
        }
        return ["user": encode(try waiter.wait(NFSRuntime.shared.timeout, what))]
    }
}

final class NFSAuthListener {
    static let shared = NFSAuthListener()

    private var handle: AuthStateDidChangeListenerHandle?
    private let lock = NSLock()

    func start(_ auth: Auth) {
        lock.lock()
        defer { lock.unlock() }
        guard handle == nil else { return }

        handle = auth.addStateDidChangeListener { _, user in
            NFSBridge.dispatch("VitisStudio\\NativeFirestore\\Events\\AuthStateChanged", ["user": NFSAuthSupport.encode(user)])
        }
    }

    func stop(_ auth: Auth) {
        lock.lock()
        defer { lock.unlock() }
        if let handle {
            auth.removeStateDidChangeListener(handle)
        }
        handle = nil
    }
}
