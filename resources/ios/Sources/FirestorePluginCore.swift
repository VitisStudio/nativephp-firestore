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

        if nsError.domain == AuthErrorDomain {
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
}

// MARK: - Value codec

/// Where a decoded value sits, which decides the sentinels Firestore allows.
enum NFSWriteContext {
    case set          // set() without merge: no delete()
    case merge        // set() with merge / mergeFields: delete() anywhere in maps
    case update       // update(): delete() only at top level
    case query        // query operands: no sentinels at all
}

enum NFSCodec {
    // PHP wire value -> Firestore value
    static func decode(_ value: Any, _ db: Firestore, context: NFSWriteContext, depth: Int = 0, inArray: Bool = false) throws -> Any {
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
            return try list.map { try decode($0, db, context: context, depth: depth + 1, inArray: true) }
        }

        guard let map = value as? [String: Any] else {
            throw NFSError.invalid("Unsupported value \(type(of: value)).")
        }

        if let type = map["__type"] as? String {
            return try decodeTagged(type, map, db, context: context, depth: depth, inArray: inArray)
        }

        var result: [String: Any] = [:]
        for (key, child) in map {
            guard !key.isEmpty else {
                throw NFSError.invalid("Document field names cannot be empty.")
            }
            result[key] = try decode(child, db, context: context, depth: depth + 1)
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

    private static func decodeTagged(_ type: String, _ map: [String: Any], _ db: Firestore, context: NFSWriteContext, depth: Int, inArray: Bool) throws -> Any {
        switch type {
        case "timestamp":
            let seconds = (map["seconds"] as? NSNumber)?.int64Value ?? 0
            let nanos = (map["nanoseconds"] as? NSNumber)?.int32Value ?? 0
            guard nanos >= 0 && nanos <= 999_999_999 else {
                throw NFSError.invalid("Timestamp nanoseconds out of range.")
            }
            return Timestamp(seconds: seconds, nanoseconds: nanos)

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
            return try decodeSentinel(map, db, context: context, depth: depth, inArray: inArray)

        default:
            var result: [String: Any] = [:]
            for (key, child) in map {
                result[key] = try decode(child, db, context: context, depth: depth + 1)
            }
            return result
        }
    }

    private static func decodeSentinel(_ map: [String: Any], _ db: Firestore, context: NFSWriteContext, depth: Int, inArray: Bool) throws -> Any {
        let op = (map["op"] as? String) ?? ""

        if context == .query {
            throw NFSError.invalid("FieldValue.\(op)() cannot be used in a query.")
        }
        if inArray {
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
            let elements = try ((map["value"] as? [Any]) ?? []).map { try decode($0, db, context: .query, depth: depth + 1, inArray: true) }
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
            return map.mapValues { encode($0) }
        }

        return String(describing: value)
    }

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
            "metadata": metadata(snapshot.metadata),
        ]
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
    private static let maxDisjunctionValues = 30

    static func build(_ wire: [String: Any], _ db: Firestore) throws -> FirebaseFirestore.Query {
        let path = (wire["path"] as? String) ?? ""
        let isGroup = (wire["collectionGroup"] as? Bool) ?? false

        var query: FirebaseFirestore.Query
        if isGroup {
            guard !path.isEmpty, !path.contains("/") else {
                throw NFSError.invalid("Invalid collection group id [\(path)].")
            }
            query = db.collectionGroup(path)
        } else {
            query = try NFSPaths.collection(path, db)
        }

        let filters = (wire["filters"] as? [[String: Any]]) ?? []
        var stats = FilterStats()
        for filter in filters {
            query = query.whereFilter(try buildFilter(filter, db, collectionPath: isGroup ? nil : path, stats: &stats))
        }
        try stats.validate()

        let orderBy = (wire["orderBy"] as? [[String: Any]]) ?? []
        for order in orderBy {
            let field = (order["field"] as? String) ?? ""
            query = query.order(by: try NFSPaths.fieldPath(field), descending: (order["direction"] as? String) == "desc")
        }

        if let limit = (wire["limit"] as? NSNumber)?.intValue {
            guard limit > 0 else { throw NFSError.invalid("A query limit must be at least 1.") }
            if (wire["limitType"] as? String) == "last" {
                guard !orderBy.isEmpty else { throw NFSError.invalid("limitToLast() requires an orderBy() clause.") }
                query = query.limit(toLast: limit)
            } else {
                query = query.limit(to: limit)
            }
        }

        let orderFields = orderBy.compactMap { $0["field"] as? String }
        if let start = wire["startAt"] as? [String: Any] {
            query = try applyCursor(query, start, edge: "start", orderFields: orderFields, db: db, collectionPath: isGroup ? nil : path)
        }
        if let end = wire["endAt"] as? [String: Any] {
            query = try applyCursor(query, end, edge: "end", orderFields: orderFields, db: db, collectionPath: isGroup ? nil : path)
        }

        return query
    }

    private struct FilterStats {
        var arrayContains = 0
        var disjunctive = 0
        var notIn = 0
        var notEqual = 0
        var hasOr = false

        func validate() throws {
            if arrayContains > 1 {
                throw NFSError.invalid("A query can use at most one array-contains or array-contains-any filter.")
            }
            if notIn > 1 {
                throw NFSError.invalid("A query can use at most one not-in filter.")
            }
            if notIn > 0 && (notEqual > 0 || disjunctive > 0 || hasOr) {
                throw NFSError.invalid("not-in cannot be combined with !=, in, array-contains-any or OR filters.")
            }
        }
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
            guard values.count <= maxDisjunctionValues else {
                throw NFSError.invalid("The \(op) operator supports at most \(maxDisjunctionValues) values.")
            }
            return try values.map { try operand($0) }
        }

        let isListOperator = ["in", "not-in", "array-contains-any"].contains(op)
        var value: Any = NSNull()
        if !isListOperator {
            value = try operand(raw)
            let isNullOrNaN = value is NSNull || ((value as? Double)?.isNaN ?? false)
            if isNullOrNaN && op != "==" && op != "!=" {
                throw NFSError.invalid("null and NaN only support == and != comparisons.")
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
        case ">=": return Filter.whereField(path, isGreaterThanOrEqualTo: value)
        case "array-contains":
            stats.arrayContains += 1
            return Filter.whereField(path, arrayContains: value)
        case "array-contains-any":
            stats.arrayContains += 1
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

    private static func applyCursor(_ query: FirebaseFirestore.Query, _ cursor: [String: Any], edge: String, orderFields: [String], db: Firestore, collectionPath: String?) throws -> FirebaseFirestore.Query {
        let inclusive = (cursor["inclusive"] as? Bool) ?? true

        if let path = cursor["document"] as? String {
            let snapshot = try NFSOperations.fetch(try NFSPaths.document(path, db), source: .default)
            guard snapshot.exists else {
                throw NFSError.invalid("Cursor document [\(path)] does not exist.")
            }
            if edge == "start" {
                return inclusive ? query.start(atDocument: snapshot) : query.start(afterDocument: snapshot)
            }
            return inclusive ? query.end(atDocument: snapshot) : query.end(beforeDocument: snapshot)
        }

        let rawValues = (cursor["values"] as? [Any]) ?? []
        guard !rawValues.isEmpty, rawValues.count <= orderFields.count else {
            throw NFSError.invalid("Cursor values need a matching orderBy() clause for each value.")
        }

        var values: [Any] = []
        for (index, value) in rawValues.enumerated() {
            if orderFields[index] == "__name__" {
                values.append(try documentIdCursorValue(value, collectionPath: collectionPath))
            } else {
                values.append(try NFSCodec.decode(value, db, context: .query))
            }
        }

        if edge == "start" {
            return inclusive ? query.start(at: values) : query.start(after: values)
        }
        return inclusive ? query.end(at: values) : query.end(before: values)
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
                _ = try NFSPaths.fieldSegments(field)
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
